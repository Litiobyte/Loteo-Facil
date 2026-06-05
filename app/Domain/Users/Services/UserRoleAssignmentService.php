<?php

namespace App\Domain\Users\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserRoleAssignmentService
{
    /**
     * @return array<int, string>
     */
    public function allowedRolesFor(?User $actor): array
    {
        if (! $actor) {
            return [];
        }

        if ($actor->hasRole('super_admin')) {
            return ['super_admin', 'admin', 'propietario'];
        }

        if ($actor->hasRole('admin')) {
            return ['propietario'];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    public function roleOptionsFor(?User $actor): array
    {
        $roles = $this->allowedRolesFor($actor);

        return array_combine($roles, $roles) ?: [];
    }

    public function assignRole(User $actor, User $target, string $role): void
    {
        $this->assertCanAssign($actor, $role);

        $target->syncRoles([$role]);
    }

    public function assertCanAssign(User $actor, string $role): void
    {
        if (! in_array($role, $this->allowedRolesFor($actor), true)) {
            throw new AuthorizationException('No tienes permisos para asignar este rol.');
        }
    }
}
