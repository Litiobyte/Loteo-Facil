<?php

namespace App\Domain\Owners\Services;

use App\Models\Lote;
use Illuminate\Database\Eloquent\Builder;

class LoteEligibilityService
{
    public function assignableLotesQuery(): Builder
    {
        return Lote::query()
            ->where('estado', 'disponible')
            ->whereDoesntHave(
                'propietarios',
                fn (Builder $relation): Builder => $relation
                    ->where('lote_propietario.status', 'active')
            )
            ->orderBy('codigo');
    }

    /**
     * @return array<int, string>
     */
    public function assignableLoteOptions(): array
    {
        return $this->assignableLotesQuery()
            ->pluck('codigo', 'id')
            ->all();
    }
}
