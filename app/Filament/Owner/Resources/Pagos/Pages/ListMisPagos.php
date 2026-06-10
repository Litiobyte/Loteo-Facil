<?php

namespace App\Filament\Owner\Resources\Pagos\Pages;

use App\Filament\Owner\Resources\Pagos\MisPagosResource;
use Filament\Resources\Pages\ListRecords;

class ListMisPagos extends ListRecords
{
    protected static string $resource = MisPagosResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
