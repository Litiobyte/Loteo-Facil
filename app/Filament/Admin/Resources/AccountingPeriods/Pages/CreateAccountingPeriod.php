<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateAccountingPeriod extends CreateRecord
{
    protected static string $resource = AccountingPeriodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $year = (int) ($data['year'] ?? now()->format('Y'));
        $month = (int) ($data['month'] ?? now()->format('m'));
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [
            ...$data,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
        ];
    }
}
