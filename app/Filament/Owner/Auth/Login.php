<?php

namespace App\Filament\Owner\Auth;

use App\Models\Propietario;
use App\Models\User;
use App\Support\ChileanRut;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Login del portal propietario: acepta correo o RUT.
 * El RUT se normaliza y resuelve a través del perfil Propietario;
 * nunca se revela si el identificador existe (mismo error genérico).
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Correo o RUT')
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $identifier = trim((string) ($data['email'] ?? ''));
        $rut = ChileanRut::normalize($identifier);

        if ($rut === null || filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return ['email' => $identifier, 'password' => (string) $data['password']];
        }

        $userId = Propietario::query()->where('rut', $rut)->value('user_id');

        $userEmail = $userId !== null ? User::query()->whereKey($userId)->value('email') : null;

        // Un identificador imposible mantiene el error genérico sin revelar existencia.
        return ['email' => $userEmail ?? 'rut-'.substr(md5($rut), 0, 12).'@invalido.local', 'password' => (string) $data['password']];
    }
}
