<?php

namespace App\Policies;

use App\Models\ShareContribution;
use App\Models\User;

class ShareContributionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'propietario']);
    }

    public function view(User $user, ShareContribution $contribution): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin'])
            || ($user->hasRole('propietario') && $user->propietario?->id === $contribution->propietario_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ShareContribution $contribution): bool
    {
        return false;
    }

    public function delete(User $user, ShareContribution $contribution): bool
    {
        return false;
    }
}
