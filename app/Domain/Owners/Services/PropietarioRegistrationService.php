<?php

namespace App\Domain\Owners\Services;

use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PropietarioRegistrationService
{
    public function register(User $user): Propietario
    {
        if (! $user->hasRole('propietario')) {
            throw new DomainException('El usuario debe tener rol propietario para registrarse como propietario.');
        }

        return DB::transaction(fn (): Propietario => Propietario::firstOrCreate(
            ['user_id' => $user->id],
            [
                'nombre' => $user->name,
                'apellido' => 'Sin apellido',
                'rut' => sprintf('%08d-%d', $user->id, 0),
                'email' => $user->email,
                'telefono' => null,
                'direccion' => null,
                'region_id' => null,
                'comuna_id' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ]
        ));
    }
}
