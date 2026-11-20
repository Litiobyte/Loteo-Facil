<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Users\Services\UserRoleAssignmentService;
use App\Filament\Resources\Users\UserResource;
use App\Models\Propietario;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): User {
                    Gate::authorize('create', User::class);

                    $actor = Auth::user();
                    $roleService = app(UserRoleAssignmentService::class);
                    $role = $data['role'] ?? null;

                    if (! is_string($role)) {
                        throw ValidationException::withMessages(['role' => 'Debes seleccionar un rol valido.']);
                    }

                    $roleService->assertCanAssign($actor, $role);
                    $secondaryOwnerId = $data['secondary_propietario_id'] ?? null;

                    if (filled($secondaryOwnerId) && ($role !== 'propietario' || ! Propietario::query()->whereKey($secondaryOwnerId)->exists())) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionSchema()->getStatePath().'.secondary_propietario_id' => 'Selecciona un propietario válido para un usuario con rol propietario.',
                        ]);
                    }

                    return DB::transaction(function () use ($data, $actor, $roleService, $role, $secondaryOwnerId): User {
                        $user = User::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'password' => $data['password'],
                        ]);

                        $roleService->assignRole($actor, $user, $role);
                        $user->forceFill(['secondary_propietario_id' => $secondaryOwnerId ?: null])->save();

                        return $user;
                    });
                }),
        ];
    }
}
