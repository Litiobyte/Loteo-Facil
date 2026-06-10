<?php

namespace App\Policies;

use App\Models\PartnerCharge;
use App\Models\User;

class PartnerChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function view(User $user, PartnerCharge $partnerCharge): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PartnerCharge $partnerCharge): bool
    {
        return false;
    }

    public function delete(User $user, PartnerCharge $partnerCharge): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
