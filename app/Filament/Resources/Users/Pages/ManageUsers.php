<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Users\Services\UserRoleAssignmentService;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): User {
                    $actor = Auth::user();
                    $roleService = app(UserRoleAssignmentService::class);
                    $role = $data['role'] ?? null;

                    if (! is_string($role)) {
                        throw ValidationException::withMessages(['role' => 'Debes seleccionar un rol valido.']);
                    }

                    return DB::transaction(function () use ($data, $actor, $roleService, $role): User {
                        $user = User::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'password' => $data['password'],
                        ]);

                        $roleService->assignRole($actor, $user, $role);

                        return $user;
                    });
                }),
        ];
    }
}
