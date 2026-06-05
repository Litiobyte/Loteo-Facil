<?php

namespace App\Filament\Owner\Resources\Lotes\Pages;

use App\Filament\Owner\Resources\Lotes\LoteResource;
use Filament\Resources\Pages\ListRecords;

class ListMisLotes extends ListRecords
{
    protected static string $resource = LoteResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
