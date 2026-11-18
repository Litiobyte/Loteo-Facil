<?php

namespace App\Policies;

use App\Models\ShareHolding;
use App\Models\User;

class ShareHoldingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'propietario']);
    }

    public function view(User $user, ShareHolding $holding): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin'])
            || ($user->hasRole('propietario') && $user->propietario?->id === $holding->propietario_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ShareHolding $holding): bool
    {
        return false;
    }

    public function delete(User $user, ShareHolding $holding): bool
    {
        return false;
    }
}
