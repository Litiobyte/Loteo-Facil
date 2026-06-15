<?php

namespace App\Filament\Admin\Resources\ExpenseResource\Pages;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\ExpenseResource\RelationManagers\FundingCollectionsRelationManager;
use App\Models\Expense;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Registered),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ExpenseResource\RelationManagers\ChargesRelationManager::class,
            FundingCollectionsRelationManager::class,
        ];
    }
}
