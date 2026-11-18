<?php

namespace App\Filament\Owner\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Único destino para cuentas con contraseña inicial (importación).
 * Al guardar se limpia must_change_password y se desbloquea el panel.
 */
class ChangePassword extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->label('Nueva contraseña')->required();
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()->label('Confirme la nueva contraseña')->required();
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()->label('Contraseña actual')->visible(static fn (): bool => true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        return ['password' => $data['password']];
    }

    /**
     * @param  Model  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate($record, #[SensitiveParameter] array $data): Model
    {
        if (isset($data['currentPassword']) && ! Hash::check((string) $data['currentPassword'], $record->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'La contraseña actual no es válida.']);
        }
        $record->update($data + ['must_change_password' => false]);

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        return Filament::getUrl();
    }
}
