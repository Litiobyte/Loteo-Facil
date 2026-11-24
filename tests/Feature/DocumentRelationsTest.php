<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_morph_alias_resolves_owner(): void
    {
        $owner = Propietario::factory()->create();
        $user = User::factory()->create();
        $type = DocumentType::create(['name' => 'Certificado']);
        $document = Document::create([
            'document_type_id' => $type->id, 'original_name' => 'x.pdf', 'stored_name' => 'x.pdf',
            'disk' => 'documents', 'file_path' => 'x.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 10, 'uploaded_by' => $user->id,
        ]);
        DB::table('documentables')->insert([
            'document_id' => $document->id, 'documentable_type' => Propietario::class, 'documentable_id' => $owner->id,
        ]);
        $this->assertTrue(Documentable::firstOrFail()->documentable->is($owner));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
    }
}
