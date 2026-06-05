<?php

namespace App\Filament\Resources\Propietarios\Pages;

use App\Filament\Resources\Propietarios\PropietarioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPropietario extends EditRecord
{
    protected static string $resource = PropietarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
