<?php

namespace App\Domain\Imports\Services;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Models\Propietario;
use App\Models\User;
use App\Support\ChileanRut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creación de cuentas durante la importación de propietarios.
 * Solo se crea una cuenta cuando la fila no trae user_id; nunca se
 * modifican ni se vinculan cuentas existentes por coincidencias.
 */
class OwnerImportAccountService
{
    public const DEFAULT_ROLE = 'propietario';

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function errors(array $data, ?Propietario $target): array
    {
        $errors = [];
        if ($target) {
            if (isset($data['user_id'])) {
                $errors[] = 'Los propietarios existentes no crean ni cambian cuentas por importación.';
            }

            return $errors;
        }
        if (isset($data['user_id'])) {
            if (! app(PropietarioEligibleUserService::class)->eligibleUsersQuery(User::query())->whereKey($data['user_id'])->exists()) {
                $errors[] = 'La cuenta debe tener rol propietario y no estar vinculada a otro perfil.';
            }

            return $errors;
        }
        if (! isset($data['email'])) {
            $errors[] = 'Sin user_id se requiere correo para crear la cuenta del propietario.';
        } elseif (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
            $errors[] = 'El correo ya tiene una cuenta; indique user_id si es la del propietario o use otro correo.';
        }
        foreach (['rut', 'apellido'] as $field) {
            if (! isset($data[$field])) {
                $errors[] = "Sin user_id se requiere {$field} para generar la contraseña inicial.";
            }
        }

        return $errors;
    }

    /**
     * Cuenta y perfil nuevos en una sola transacción por fila.
     * Si la creación falla no quedan registros huérfanos.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: Propietario, 2: string}
     */
    public function createAccount(array $data): array
    {
        $email = mb_strtolower((string) ($data['email'] ?? ''));
        $rut = ChileanRut::normalize((string) ($data['rut'] ?? ''));
        if ($rut === null) {
            throw ValidationException::withMessages(['row' => 'RUT inválido para generar la contraseña inicial.']);
        }
        $password = $this->initialPassword($rut, (string) ($data['apellido'] ?? ''));
        $nombre = trim((string) ($data['nombre'] ?? ''));

        return DB::transaction(function () use ($data, $email, $password, $nombre): array {
            $user = User::query()->create([
                'name' => $nombre !== '' ? $nombre : 'Propietario',
                'email' => $email,
                'password' => $password,
                'must_change_password' => true,
            ]);
            $user->assignRole(self::DEFAULT_ROLE);
            $propietario = Propietario::query()->create($this->ownerAttributes($data) + ['user_id' => $user->id]);
            Validator::make(['propietario_id' => $propietario->id], ['propietario_id' => ['required', 'integer', 'exists:propietarios,id']])->validate();
            $this->assertRoles($user);

            return [$user, $propietario, $password];
        });
    }

    /**
     * RUT normalizado sin puntos, con guion y DV mayúsculo, seguido del
     * apellido en minúsculas, sin tildes ni espacios. Ej: 12345678-5perez.
     */
    public function initialPassword(string $rut, string $apellido): string
    {
        $normalizedRut = str_replace('.', '', $rut);
        $apellido = Str::ascii(mb_strtolower($apellido));
        $apellido = preg_replace('/[^a-z0-9]/', '', str_replace(' ', '', $apellido)) ?? '';

        return $normalizedRut.$apellido;
    }

    /**
     * El resto de campos de perfil los completa el importador; aquí solo
     * se fija el correo y se ignoran campos que no pertenecen al modelo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ownerAttributes(array $data): array
    {
        $attributes = ['email' => $email = mb_strtolower((string) $data['email'])];
        foreach (['rut', 'nombre', 'apellido', 'telefono', 'direccion', 'region_id', 'comuna_id', 'nacionalidad', 'profesion', 'estado_civil'] as $field) {
            if (isset($data[$field])) {
                $attributes[$field] = $data[$field];
            }
        }

        return $attributes;
    }

    private function assertRoles(User $user): void
    {
        Validator::make(
            ['role' => $user->hasRole(self::DEFAULT_ROLE) ? self::DEFAULT_ROLE : ''],
            ['role' => ['required', Rule::in([self::DEFAULT_ROLE])]],
            ['role.required' => 'La cuenta nueva no recibió el rol propietario.'],
        )->validate();
    }
}
