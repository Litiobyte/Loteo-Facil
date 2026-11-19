<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

#[Signature('app:create-super-admin')]
#[Description('Crea un superadministrador sin modificar usuarios existentes ni cargar datos de demostración')]
class CreateSuperAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Ejecuta este comando en una terminal interactiva; la contraseña se solicita oculta.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nombre'));
        $email = mb_strtolower(trim((string) $this->ask('Correo electrónico')));
        $identity = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']],
        );

        if ($identity->fails()) {
            $this->error($identity->errors()->first());

            return self::FAILURE;
        }

        $password = $this->secret('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas y números)', fallback: false);
        $confirmation = $this->secret('Confirmar contraseña', fallback: false);
        $credentials = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers(), 'confirmed']],
        );

        if ($credentials->fails()) {
            $this->error($credentials->errors()->first());

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password): void {
            foreach (['super_admin', 'admin', 'propietario'] as $role) {
                Role::findOrCreate($role, 'web');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->assignRole('super_admin');
        });

        $this->info('Superadministrador creado. Puedes ingresar al panel /admin.');

        return self::SUCCESS;
    }
}
