<?php

namespace App\Filament\Admin\Resources\ExpenseFundingPayments\Pages;

use App\Filament\Admin\Resources\ExpenseFundingPayments\ExpenseFundingPaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExpenseFundingPayment extends ViewRecord
{
    protected static string $resource = ExpenseFundingPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
