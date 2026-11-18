<?php

namespace App\Policies;

use App\Models\CommunitySetting;
use App\Models\User;

class CommunitySettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, CommunitySetting $setting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CommunitySetting $setting): bool
    {
        return false;
    }

    public function delete(User $user, CommunitySetting $setting): bool
    {
        return false;
    }
}
