<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\DocumentService;
use App\Domain\Documents\Services\DocumentStorageService;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_admin_upload_and_soft_delete_preserve_file(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado', 'default_visibility' => 'owner']);
        $type->modules()->create(['module_key' => 'acciones']);
        $service = app(DocumentService::class);
        $document = $service->upload($admin, $owner, $type, UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nfile"));
        $this->assertSame('owner', $document->visibility);
        $this->assertDatabaseHas('documentables', ['document_id' => $document->id, 'documentable_id' => $owner->id]);
        Storage::disk('documents')->assertExists($document->file_path);
        $oldPath = $document->file_path;
        $document = $service->replace($admin, $document, UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nnew"));
        Storage::disk('documents')->assertMissing($oldPath);
        Storage::disk('documents')->assertExists($document->file_path);
        $this->assertSame('new.pdf', $document->original_name);
        $service->delete($admin, $document);
        $this->assertSoftDeleted($document);
        Storage::disk('documents')->assertExists($document->file_path);
    }

    public function test_failed_database_write_cleans_stored_file(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado']);
        $type->modules()->create(['module_key' => 'acciones']);
        Documentable::creating(static function (): void {
            throw new \RuntimeException('fallo simulado');
        });
        try {
            app(DocumentService::class)->upload($admin, $owner, $type, UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nfile"));
            $this->fail('Debe fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo simulado', $exception->getMessage());
        } finally {
            Documentable::flushEventListeners();
        }
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_non_admin_cannot_upload(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create();
        $owner = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado']);
        $this->expectException(AuthorizationException::class);
        app(DocumentService::class)->upload($user, $owner, $type, UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nfile"));
    }

    public function test_failed_old_file_deletion_does_not_invalidate_replacement(): void
    {
        Storage::fake('documents');
        Exceptions::fake();
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado']);
        $type->modules()->create(['module_key' => 'acciones']);
        $document = app(DocumentService::class)->upload(
            $admin, $owner, $type, UploadedFile::fake()->createWithContent('old.pdf', "%PDF-1.4\nold"),
        );
        $oldPath = $document->file_path;
        $storage = Mockery::mock(DocumentStorageService::class)->makePartial();
        $storage->shouldReceive('delete')->once()->with($oldPath)->andThrow(new \RuntimeException('No se pudo borrar'));
        $document = (new DocumentService($storage))->replace(
            $admin, $document, UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nnew"),
        );

        $this->assertSame($document->file_path, $document->fresh()->file_path);
        $this->assertNotSame($oldPath, $document->file_path);
        Storage::disk('documents')->assertExists($document->file_path);
        Storage::disk('documents')->assertExists($oldPath);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_unlink_preserves_shared_documents_and_soft_deletes_last_link(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $first = Propietario::factory()->create();
        $second = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado']);
        $type->modules()->create(['module_key' => 'acciones']);
        $service = app(DocumentService::class);
        $document = $service->upload($admin, $first, $type, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nfile"));
        Documentable::create(['document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $second->id]);
        $service->unlink($admin, $document, $first);
        $this->assertDatabaseMissing('documentables', ['document_id' => $document->id, 'documentable_id' => $first->id]);
        $this->assertNotSoftDeleted($document);
        $service->unlink($admin, $document, $second);
        $this->assertSoftDeleted($document);
        Storage::disk('documents')->assertExists($document->file_path);
    }

    public function test_unlink_cannot_broaden_access_to_an_owner_document(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $first = Propietario::factory()->create();
        $second = Propietario::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado', 'default_visibility' => 'owner']);
        $type->modules()->create(['module_key' => 'acciones']);
        $service = app(DocumentService::class);
        $document = $service->upload($admin, $first, $type, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nfile"));
        Documentable::create(['document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $second->id]);
        $this->expectException(ValidationException::class);
        $service->unlink($admin, $document, $first);
    }
}
