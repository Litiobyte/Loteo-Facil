<?php

namespace App\Filament\Resources\Propietarios\Pages;

use App\Filament\Resources\Propietarios\PropietarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPropietarios extends ListRecords
{
    protected static string $resource = PropietarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
