<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTypeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeding_is_idempotent_and_preserves_existing_settings(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $type = DocumentType::query()->where('name', 'Factura')->firstOrFail();
        $type->update(['is_active' => false]);
        $count = DocumentType::count();
        $this->seed(DocumentTypeSeeder::class);
        $this->assertSame($count, DocumentType::count());
        $this->assertFalse($type->fresh()->is_active);
        $this->assertDatabaseHas('document_type_modules', ['document_type_id' => $type->id, 'module_key' => 'finanzas']);
    }

    public function test_type_with_soft_deleted_documents_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->create(['name' => 'Factura']);
        $document = Document::query()->create([
            'document_type_id' => $type->id, 'original_name' => 'x.pdf',
            'stored_name' => 'x.pdf', 'disk' => 'documents', 'file_path' => 'x.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $user->id,
        ]);
        $document->delete();
        $this->expectException(DomainException::class);
        $type->delete();
    }
}
