<?php

namespace App\Filament\Owner\Resources\Collections\Pages;

use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use Filament\Resources\Pages\ListRecords;

class ListMyCollections extends ListRecords
{
    protected static string $resource = MyCollectionsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
