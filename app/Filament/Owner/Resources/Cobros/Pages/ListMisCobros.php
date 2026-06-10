<?php

namespace App\Filament\Owner\Resources\Cobros\Pages;

use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use Filament\Resources\Pages\ListRecords;

class ListMisCobros extends ListRecords
{
    protected static string $resource = MisCobrosResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
