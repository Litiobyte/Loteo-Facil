<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Contador',
                'description' => 'Honorarios contables, balances y cumplimiento tributario.',
            ],
            [
                'name' => 'Trámites',
                'description' => 'Gestiones notariales, inscripciones y permisos.',
            ],
            [
                'name' => 'Impuestos',
                'description' => 'Contribuciones, tasas y obligaciones fiscales del proyecto.',
            ],
            [
                'name' => 'Legal',
                'description' => 'Asesoría legal, revisión documental y representación.',
            ],
            [
                'name' => 'Administración',
                'description' => 'Gastos administrativos generales del loteo.',
            ],
            [
                'name' => 'Mantención',
                'description' => 'Conservación de caminos, cercos e infraestructura común.',
            ],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::query()->firstOrCreate(
                ['name' => $category['name']],
                [
                    'description' => $category['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
