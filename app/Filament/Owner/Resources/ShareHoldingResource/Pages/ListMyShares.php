<?php

namespace App\Filament\Owner\Resources\ShareHoldingResource\Pages;

use App\Filament\Owner\Resources\ShareHoldingResource;
use App\Support\ChileanNumber;
use Filament\Resources\Pages\ListRecords;

class ListMyShares extends ListRecords
{
    protected static string $resource = ShareHoldingResource::class;

    public function getSubheading(): ?string
    {
        $totals = auth()->user()?->propietario?->shareTotals();

        return $totals ? "Acciones vigentes: {$totals['quantity']} — Superficie equivalente: ".ChileanNumber::format($totals['square_meters']).' m²' : null;
    }
}
