<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountingPeriods extends ListRecords
{
    protected static string $resource = AccountingPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
