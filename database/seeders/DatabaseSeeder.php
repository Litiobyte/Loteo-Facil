<?php

namespace Database\Seeders;

use App\Models\User;
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
        // Primero crear los roles
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        // Datos de referencia geográfica y de infraestructura
        $this->call([
            RegionSeeder::class,
            EtapaSeeder::class,
        ]);

        // Único usuario inicial del sistema
        $superAdmin = User::query()->firstOrCreate([
            'email' => 'superadmin@loteofacil.cl',
        ], [
            'name' => 'Super Admin Loteo Facil',
            'password' => 'password',
        ]);

        $superAdmin->assignRole('super_admin');
    }
}
