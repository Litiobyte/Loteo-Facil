<?php

namespace App\Filament\Admin\Resources\ExpenseFundingPayments\Pages;

use App\Filament\Admin\Resources\ExpenseFundingPayments\ExpenseFundingPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListExpenseFundingPayments extends ListRecords
{
    protected static string $resource = ExpenseFundingPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
