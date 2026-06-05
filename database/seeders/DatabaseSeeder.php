<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Propietario;
use App\Models\Comuna;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RegionSeeder::class,
            EtapaSeeder::class,
        ]);

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $superAdmin = User::query()->firstOrCreate([
            'email' => 'superadmin@loteofacil.cl',
        ], [
            'name' => 'Super Admin Loteo Facil',
            'password' => 'password',
        ]);

        $superAdmin->assignRole('super_admin');

        $admin = User::query()->firstOrCreate([
            'email' => 'admin@loteofacil.cl',
        ], [
            'name' => 'Admin Loteo Facil',
            'password' => 'password',
        ]);

        $admin->assignRole('admin');

        $propietario = User::query()->firstOrCreate([
            'email' => 'propietario@loteofacil.cl',
        ], [
            'name' => 'Propietario Demo',
            'password' => 'password',
        ]);

        $propietario->assignRole('propietario');

        $comuna = Comuna::query()->first();

        if ($comuna) {
            Propietario::query()->firstOrCreate(
                ['user_id' => $propietario->id],
                [
                    'nombre' => 'Propietario',
                    'apellido' => 'Demo',
                    'rut' => '11.111.111-1',
                    'telefono' => '+56911111111',
                    'direccion' => 'Direccion Demo 123',
                    'region_id' => $comuna->region_id,
                    'comuna_id' => $comuna->id,
                    'nacionalidad' => 'Chilena',
                    'profesion' => 'Agricultor',
                    'estado_civil' => 'Soltero',
                    'email' => 'propietario@loteofacil.cl',
                ]
            );
        }
    }
}
