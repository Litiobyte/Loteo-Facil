<?php

namespace App\Policies;

use App\Models\DocumentType;
use App\Models\User;

class DocumentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, DocumentType $type): bool
    {
        return $this->viewAny($user) && (int) $type->community_id === 1;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DocumentType $type): bool
    {
        return $this->view($user, $type);
    }

    public function delete(User $user, DocumentType $type): bool
    {
        return $this->view($user, $type) && $type->documents()->withTrashed()->doesntExist();
    }
}
