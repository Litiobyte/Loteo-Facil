<?php

namespace App\Domain\Imports\Support;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Models\Etapa;
use App\Models\Propietario;
use App\Models\Region;

final class ImportTemplateOptions
{
    /**
     * @return array{
     *     region_columns: list<array{region: string, comunas: list<string>}>,
     *     option_columns: list<array{label: string, values: list<string>}>,
     *     dropdowns: list<array<string, mixed>>,
     * }
     */
    public function lotes(): array
    {
        $etapas = Etapa::query()
            ->orderBy('numero')
            ->pluck('nombre')
            ->all();

        return [
            'region_columns' => [],
            'option_columns' => $etapas === [] ? [] : [['label' => 'Etapas', 'values' => $etapas]],
            'dropdowns' => array_values(array_filter([
                ['column' => 1, 'type' => 'literal', 'values' => ['disponible', 'reservado', 'vendido']],
                $etapas === [] ? null : ['column' => 3, 'type' => 'ref', 'option' => 'Etapas'],
            ])),
        ];
    }

    /**
     * @return array{
     *     region_columns: list<array{region: string, comunas: list<string>}>,
     *     option_columns: list<array{label: string, values: list<string>}>,
     *     dropdowns: list<array<string, mixed>>,
     * }
     */
    public function propietarios(): array
    {
        $regions = Region::query()
            ->with('comunas:id,region_id,nombre')
            ->orderBy('nombre')
            ->get();

        $regionColumns = $regions
            ->map(fn (Region $region): array => [
                'region' => $region->nombre,
                'comunas' => $region->comunas->pluck('nombre')->sort()->values()->all(),
            ])
            ->all();

        return [
            'region_columns' => $regionColumns,
            'option_columns' => [],
            'dropdowns' => [
                ['column' => 6, 'type' => 'region'],
                ['column' => 10, 'type' => 'literal', 'values' => ['Soltero', 'Casado', 'Divorciado', 'Viudo', 'Union civil']],
            ],
        ];
    }

    /**
     * @return array{
     *     region_columns: list<array{region: string, comunas: list<string>}>,
     *     option_columns: list<array{label: string, values: list<string>}>,
     *     dropdowns: list<array<string, mixed>>,
     * }
     */
    public function recaudaciones(): array
    {
        $ruts = Propietario::query()
            ->orderBy('rut')
            ->pluck('rut')
            ->all();

        $dropdowns = [
            ['column' => 3, 'type' => 'literal', 'values' => array_column(CollectionMethod::cases(), 'value')],
        ];

        $optionColumns = [];

        if ($ruts !== []) {
            $optionColumns[] = ['label' => 'RUT Propietarios', 'values' => $ruts];
            $dropdowns[] = ['column' => 0, 'type' => 'ref', 'option' => 'RUT Propietarios'];
        }

        return [
            'region_columns' => [],
            'option_columns' => $optionColumns,
            'dropdowns' => $dropdowns,
        ];
    }
}
