<?php

namespace Database\Seeders;

use App\Models\Lote;
use App\Models\Propietario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PropietarioSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Generar 20 propietarios con usuarios asociados
        $propietarios = Propietario::factory()
            ->count(20)
            ->create()
            ->each(function (Propietario $propietario): void {
                $propietario->user->assignRole('propietario');
            });

        // Obtener lotes vendidos y reservados para asignar a propietarios
        $lotesDisponibles = Lote::query()
            ->whereIn('estado', ['vendido', 'reservado'])
            ->get();

        if ($lotesDisponibles->isEmpty()) {
            return;
        }

        // Asignar lotes a aproximadamente 60-70% de los propietarios
        $propietariosConLotes = $propietarios->random(
            (int) ($propietarios->count() * 0.65)
        );

        $lotesYaAsignados = collect();

        foreach ($propietariosConLotes as $propietario) {
            // Cada propietario puede tener entre 1 y 3 lotes
            $cantidadLotes = fake()->numberBetween(1, 3);

            // Obtener lotes aleatorios que aún no han sido asignados
            $lotesDisponiblesParaEste = $lotesDisponibles
                ->whereNotIn('id', $lotesYaAsignados->toArray());

            if ($lotesDisponiblesParaEste->isEmpty()) {
                continue;
            }

            $lotesParaAsignar = $lotesDisponiblesParaEste
                ->random(min($cantidadLotes, $lotesDisponiblesParaEste->count()));

            // Asegurar que $lotesParaAsignar sea siempre una colección
            if (! is_iterable($lotesParaAsignar)) {
                $lotesParaAsignar = collect([$lotesParaAsignar]);
            }

            foreach ($lotesParaAsignar as $lote) {
                // Asignar lote con fecha aleatoria en los últimos 2 años
                $propietario->lotes()->attach($lote->id, [
                    'assigned_at' => fake()->dateTimeBetween('-2 years', 'now'),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Marcar este lote como asignado
                $lotesYaAsignados->push($lote->id);
            }
        }
    }
}
