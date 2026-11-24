<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentManagementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_tables_and_single_community_defaults_exist(): void
    {
        foreach (['documents', 'document_types', 'document_type_modules', 'documentables'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $id = DB::table('document_types')->insertGetId(['name' => 'Factura']);
        $this->assertDatabaseHas('document_types', ['id' => $id, 'community_id' => 1]);
    }

    public function test_module_assignments_cannot_be_duplicated(): void
    {
        $id = DB::table('document_types')->insertGetId(['name' => 'Factura']);
        DB::table('document_type_modules')->insert(['document_type_id' => $id, 'module_key' => 'finanzas']);
        $this->expectException(QueryException::class);
        DB::table('document_type_modules')->insert(['document_type_id' => $id, 'module_key' => 'finanzas']);
    }
}
