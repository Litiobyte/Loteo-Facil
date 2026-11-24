<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_download_authorizes_real_owner_and_administrator_only(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $other->user->assignRole('propietario');
        $admin = User::factory()->create()->assignRole('admin');
        $type = DocumentType::create(['name' => 'Archivo']);
        $path = 'communities/1/documents/00000000-0000-4000-8000-000000000000.pdf';
        Storage::disk('documents')->put($path, '%PDF-1.4');
        $document = Document::create([
            'document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => basename($path),
            'disk' => 'documents', 'file_path' => $path, 'mime_type' => 'application/pdf',
            'file_size' => 8, 'uploaded_by' => $admin->id, 'visibility' => 'owner',
        ]);
        Documentable::create(['document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $owner->id]);
        $url = route('documents.file', [$document, 'download']);

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($other->user)->get($url)->assertForbidden();
        $this->actingAs($owner->user)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($admin)->get(route('documents.file', [$document, 'preview']))->assertOk();

        Documentable::create(['document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $other->id]);
        $this->actingAs($owner->user)->get($url)->assertForbidden();
        $document->update(['visibility' => 'custom']);
        $this->actingAs($other->user)->get($url)->assertForbidden();
        $document->forceFill(['community_id' => 2])->save();
        $this->actingAs($admin)->get($url)->assertForbidden();
    }

    public function test_invalid_storage_path_is_never_read(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $type = DocumentType::create(['name' => 'Archivo']);
        $document = Document::create([
            'document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf',
            'disk' => 'documents', 'file_path' => '../a.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 8, 'uploaded_by' => $admin->id,
        ]);
        $this->actingAs($admin)->get(route('documents.file', [$document, 'download']))->assertNotFound();
    }
}
