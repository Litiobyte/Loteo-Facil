<?php

namespace Database\Seeders;

use App\Models\Etapa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class CleanInstallSeeder extends Seeder
{
    /**
     * Instalación limpia: roles, datos de referencia y usuarios administradores,
     * sin lotes, propietarios, gastos ni cobros de demostración.
     */
    public function run(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $this->call(RegionSeeder::class);

        Etapa::query()->firstOrCreate(['numero' => 1], ['nombre' => 'Etapa 1']);

        foreach ([
            ['email' => 'superadmin@loteofacil.cl', 'name' => 'Super Admin Loteo Facil', 'role' => 'super_admin'],
        ] as $account) {
            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => 'password'],
            );
            $user->assignRole($account['role']);
        }
    }
}
