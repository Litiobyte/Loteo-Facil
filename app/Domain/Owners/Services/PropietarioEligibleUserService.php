<?php

namespace App\Domain\Owners\Services;

use App\Models\Propietario;
use Illuminate\Database\Eloquent\Builder;

class PropietarioEligibleUserService
{
    public function eligibleUsersQuery(Builder $query): Builder
    {
        return $query
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', 'propietario'))
            ->whereDoesntHave('propietario');
    }

    public function eligibleUsersForFormQuery(Builder $query, ?Propietario $record = null): Builder
    {
        return $query
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', 'propietario'))
            ->where(function (Builder $users) use ($record): void {
                $users->whereDoesntHave('propietario');

                if ($record?->user_id) {
                    $users->orWhere('id', $record->user_id);
                }
            });
    }
}
