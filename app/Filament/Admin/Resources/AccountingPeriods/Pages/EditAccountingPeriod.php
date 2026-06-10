<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditAccountingPeriod extends EditRecord
{
    protected static string $resource = AccountingPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $year = (int) ($data['year'] ?? $this->record->year);
        $month = (int) ($data['month'] ?? $this->record->month);
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [
            ...$data,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
        ];
    }
}
