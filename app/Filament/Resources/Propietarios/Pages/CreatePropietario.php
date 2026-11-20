<?php

namespace App\Filament\Resources\Propietarios\Pages;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePropietario extends CreateRecord
{
    protected static string $resource = PropietarioResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $user = User::query()->find($data['user_id'] ?? null);
            if (! $user || ! app(PropietarioEligibleUserService::class)->eligibleUsersQuery(User::query())->whereKey($user)->exists()) {
                throw ValidationException::withMessages(['data.user_id' => 'Selecciona un usuario propietario disponible.']);
            }

            return parent::handleRecordCreation($data);
        });
    }
}
