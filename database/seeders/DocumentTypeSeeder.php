<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'acciones' => ['Documento CVE', 'Registro SII', 'Compraventa o cesión de acciones', 'Certificado de acciones', 'Otro documento de acciones'],
            'finanzas' => ['Comprobante de transferencia', 'Factura', 'Boleta', 'Comprobante de depósito', 'Recibo', 'Otro comprobante financiero'],
            'comunidad' => ['Libro de accionistas', 'Escrituras', 'Planos y topografía', 'Contribuciones', 'Actas', 'Reglamentos', 'Contratos', 'Otros documentos comunitarios'],
            'lotes' => ['Plano individual', 'Escritura', 'Certificado', 'Contrato', 'Otro documento de lote'],
        ];

        foreach ($types as $module => $names) {
            foreach ($names as $name) {
                $type = DocumentType::query()->firstOrCreate(
                    ['community_id' => 1, 'name' => $name],
                    ['default_visibility' => 'admin', 'is_active' => true],
                );
                $type->modules()->firstOrCreate(['module_key' => $module]);
            }
        }
    }
}
