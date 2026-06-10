<?php

namespace App\Filament\Admin\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Admin\Resources\ExpenseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseCategories extends ManageRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
