<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class CreateAccountingPeriod extends CreateRecord
{
    protected static string $resource = AccountingPeriodResource::class;

    protected static ?string $title = 'Crear cierre contable';

    public function getHeading(): string|Htmlable
    {
        return view('filament.admin.accounting-periods.heading', ['heading' => $this->getTitle()]);
    }

    public function getSubheading(): ?string
    {
        return 'Define el período, estado y folio del cierre contable.';
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCreateFormAction(),
        ];
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Crear y crear otro');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Crear cierre')->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->url(AccountingPeriodResource::getUrl('index'))
            ->color('gray');
    }

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
