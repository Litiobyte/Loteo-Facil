<?php

namespace App\Policies;

use App\Domain\Documents\Services\DocumentAuthorizationService;
use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function __construct(private DocumentAuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->canManage($user) || ($user->hasRole('propietario') && $user->propietario !== null);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->authorization->canView($user, $document);
    }

    public function create(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function update(User $user, Document $document): bool
    {
        return (int) $document->community_id === 1 && ! $document->trashed() && $this->authorization->canManage($user);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }
}
