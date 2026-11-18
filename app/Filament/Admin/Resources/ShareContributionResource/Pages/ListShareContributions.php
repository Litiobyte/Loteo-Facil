<?php

namespace App\Filament\Admin\Resources\ShareContributionResource\Pages;

use App\Filament\Admin\Resources\ShareContributionResource;
use Filament\Resources\Pages\ListRecords;

class ListShareContributions extends ListRecords
{
    protected static string $resource = ShareContributionResource::class;

    public function getSubheading(): ?string
    {
        return 'Aportes independientes de gastos comunes y caja. Las correcciones son diferencias, no nuevos pagos. Los importes antiguos no informados no se presumen cero.';
    }
}
