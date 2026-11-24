<?php

namespace App\Domain\Documents\Services;

use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentAuthorizationService
{
    public function canManage(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function visibleQuery(User $user): Builder
    {
        $query = Document::query()->where('community_id', 1);
        if ($this->canManage($user)) {
            return $query;
        }
        if (! $user->hasRole('propietario') || ! $user->propietario) {
            return $query->whereRaw('1 = 0');
        }
        $ownerId = $user->propietario->getKey();

        return $query->whereHas('links')->where(function (Builder $query) use ($ownerId): void {
            $query->where(function (Builder $community): void {
                $community->where('visibility', 'community')
                    ->whereDoesntHave('links', fn (Builder $links) => $links->whereNot(
                        fn (Builder $valid) => $valid->whereHasMorph('documentable', [CommunitySetting::class], fn (Builder $entity) => $entity->whereKey(1))
                    ));
            })->orWhere(function (Builder $owner) use ($ownerId): void {
                $owner->where('visibility', 'owner')
                    ->whereDoesntHave('links', fn (Builder $links) => $links->whereNot(
                        fn (Builder $valid) => $valid
                            ->whereHasMorph('documentable', [Propietario::class], fn (Builder $entity) => $entity->whereKey($ownerId))
                            ->orWhereHasMorph('documentable', [ShareHolding::class, Collection::class, PartnerCharge::class], fn (Builder $entity) => $entity->where('propietario_id', $ownerId))
                            ->orWhereHasMorph('documentable', [ShareMovement::class], fn (Builder $entity) => $entity->whereIn(
                                'share_holding_id', ShareHolding::query()->where('propietario_id', $ownerId)->select('id')
                            ))
                    ));
            });
        });
    }

    public function canAccessEntity(User $user, Model $entity): bool
    {
        if (DocumentService::moduleFor($entity::class) === null || ! $entity->exists) {
            return false;
        }
        if ($this->canManage($user)) {
            return true;
        }
        if (! $user->hasRole('propietario') || ! $user->propietario) {
            return false;
        }
        $ownerId = $user->propietario->getKey();

        return match (true) {
            $entity instanceof CommunitySetting => (int) $entity->getKey() === 1,
            $entity instanceof Propietario => $entity->getKey() === $ownerId,
            $entity instanceof ShareHolding, $entity instanceof Collection, $entity instanceof PartnerCharge => $entity->propietario_id === $ownerId,
            $entity instanceof ShareMovement => ShareHolding::query()->whereKey($entity->share_holding_id)->where('propietario_id', $ownerId)->exists(),
            default => false,
        };
    }

    public function canView(User $user, Document $document): bool
    {
        if ((int) $document->community_id !== 1 || $document->trashed()) {
            return false;
        }
        if ($this->canManage($user)) {
            return true;
        }
        if (! $user->hasRole('propietario') || ! $user->propietario) {
            return false;
        }

        $links = Documentable::query()->where('document_id', $document->getKey())->get();
        if ($links->isEmpty()) {
            return false;
        }
        if ($document->visibility === 'community') {
            return $links->every(fn (Documentable $link): bool => $link->documentable_type === CommunitySetting::class
                && (int) $link->documentable_id === 1 && $link->documentable !== null);
        }
        if ($document->visibility !== 'owner') {
            return false;
        }

        $ownerId = $user->propietario->getKey();

        return $links->every(function (Documentable $link) use ($ownerId): bool {
            $entity = $link->documentable;

            return match (true) {
                $entity instanceof Propietario => $entity->getKey() === $ownerId,
                $entity instanceof ShareHolding, $entity instanceof Collection, $entity instanceof PartnerCharge => $entity->propietario_id === $ownerId,
                $entity instanceof ShareMovement => ShareHolding::query()->whereKey($entity->share_holding_id)->where('propietario_id', $ownerId)->exists(),
                default => false,
            };
        });
    }
}
