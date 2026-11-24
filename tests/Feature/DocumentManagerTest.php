<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\DocumentService;
use App\Livewire\DocumentManager;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_upload_list_download_preview_replace_and_delete(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $other->user->assignRole('propietario');
        $type = DocumentType::create(['name' => 'Contrato', 'default_visibility' => 'owner']);
        $type->modules()->create(['module_key' => 'acciones']);
        $inactive = DocumentType::create(['name' => 'No disponible', 'is_active' => false]);
        $inactive->modules()->create(['module_key' => 'acciones']);
        $this->actingAs($admin);
        $component = Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones'])
            ->assertSee('Contrato')->assertDontSee('No disponible')
            ->set('typeId', $type->id)
            ->set('files', [UploadedFile::fake()->createWithContent('contrato.pdf', "%PDF-1.4\nfile")])
            ->call('upload')->assertHasNoErrors()->assertSee('contrato.pdf');
        $document = Document::firstOrFail();
        $url = route('documents.file', [$document, 'download']);
        $this->get($url)->assertOk();
        $this->get(route('documents.file', [$document, 'preview']))->assertOk();
        $this->actingAs($owner->user)->get($url)->assertOk();
        Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones'])
            ->assertSee('contrato.pdf')
            ->call('remove', $document->id)->assertForbidden();
        $this->actingAs($other->user)->get($url)->assertForbidden();
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($admin);
        $component->set('replacement', UploadedFile::fake()->createWithContent('nuevo.pdf', "%PDF-1.4\nnew"))
            ->call('replace', $document->id)->assertHasNoErrors()->assertSee('nuevo.pdf')
            ->call('remove', $document->id)->assertHasNoErrors()->assertDontSee('nuevo.pdf');
        $this->assertSoftDeleted($document);
    }

    public function test_wrong_owner_cannot_mount_and_invalid_files_are_rejected(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $other->user->assignRole('propietario');
        $this->actingAs($other->user);
        Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones'])->assertForbidden();

        $type = DocumentType::create(['name' => 'Contrato']);
        $type->modules()->create(['module_key' => 'acciones']);
        $this->actingAs($admin);
        Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones'])
            ->set('typeId', $type->id)
            ->set('files', [UploadedFile::fake()->createWithContent('fake.pdf', 'texto')])
            ->call('upload')->assertHasErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_single_required_and_type_limits_are_enforced(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Limitado', 'settings' => ['max_files' => 1]]);
        $type->modules()->create(['module_key' => 'acciones']);
        $inactive = DocumentType::create(['name' => 'Inactivo', 'is_active' => false]);
        $inactive->modules()->create(['module_key' => 'acciones']);
        $this->actingAs($admin);
        $component = Livewire::test(DocumentManager::class, [
            'entity' => $owner, 'moduleKey' => 'acciones', 'multiple' => false, 'required' => true,
        ])->call('validateRequired')->assertHasErrors('documents')
            ->set('typeId', $inactive->id)
            ->set('singleFile', UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\nfile"))
            ->call('upload')->assertHasErrors('type')
            ->set('typeId', $type->id)
            ->call('upload')->assertHasNoErrors()
            ->call('validateRequired')->assertHasNoErrors();
        $component->set('singleFile', UploadedFile::fake()->createWithContent('y.pdf', "%PDF-1.4\nfile"))
            ->call('upload')->assertHasErrors('file');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_type_maximum_is_enforced_for_multiple_uploads(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Limitado', 'settings' => ['max_files' => 1]]);
        $type->modules()->create(['module_key' => 'acciones']);
        $this->actingAs($admin);
        $component = Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones'])
            ->set('typeId', $type->id)
            ->set('files', [UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\nfile")])
            ->call('upload')->assertHasNoErrors();
        $component->set('files', [UploadedFile::fake()->createWithContent('y.pdf', "%PDF-1.4\nfile")])
            ->call('upload')->assertHasErrors('file');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_component_unlinks_shared_documents_and_rejects_other_entity_document_ids(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $first = Propietario::factory()->create();
        $second = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Comprobante']);
        $type->modules()->create(['module_key' => 'acciones']);
        $service = app(DocumentService::class);
        $document = $service->upload($admin, $first, $type, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nfile"));
        $otherDocument = $service->upload($admin, $second, $type, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\nfile"));
        Documentable::create(['document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $second->id]);
        $this->actingAs($admin);
        Livewire::test(DocumentManager::class, ['entity' => $first, 'moduleKey' => 'acciones'])
            ->call('remove', $otherDocument->id)->assertNotFound();
        Livewire::test(DocumentManager::class, ['entity' => $first, 'moduleKey' => 'acciones'])
            ->call('remove', $document->id)->assertHasNoErrors();
        $this->assertNotSoftDeleted($document);
        $this->assertDatabaseHas('documentables', ['document_id' => $document->id, 'documentable_id' => $second->id]);
        auth()->logout();
        Livewire::test(DocumentManager::class, ['entity' => $first, 'moduleKey' => 'acciones'])->assertForbidden();
    }

    public function test_entity_identity_is_locked_against_client_changes(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $this->actingAs($admin);
        $component = Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones']);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('entityId', $other->id);
    }

    public function test_owner_documents_are_authorized_and_paginated_without_per_document_link_queries(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $type = DocumentType::create(['name' => 'Documento']);
        $attributes = ['document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf',
            'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
            'uploaded_by' => $admin->id, 'visibility' => 'owner'];
        for ($index = 0; $index < 12; $index++) {
            $document = Document::create($attributes);
            Documentable::create(['document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $owner->id]);
        }
        $private = Document::create($attributes);
        Documentable::create(['document_id' => $private->id, 'documentable_type' => Propietario::class, 'documentable_id' => $owner->id]);
        Documentable::create(['document_id' => $private->id, 'documentable_type' => Propietario::class, 'documentable_id' => $other->id]);
        $this->actingAs($owner->user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DocumentManager::class, ['entity' => $owner, 'moduleKey' => 'acciones'])
            ->assertViewHas('documents', fn ($documents): bool => $documents->total() === 12 && $documents->count() === 10);
        $linkQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select * from "documentables"')
        );
        DB::disableQueryLog();
        $this->assertCount(0, $linkQueries);
    }
}
