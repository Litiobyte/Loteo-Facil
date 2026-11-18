<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class EditAccountingPeriod extends EditRecord
{
    protected static string $resource = AccountingPeriodResource::class;

    protected static ?string $title = 'Editar cierre contable';

    public function getHeading(): string|Htmlable
    {
        return view('filament.admin.accounting-periods.heading', ['heading' => $this->getTitle()]);
    }

    public function getSubheading(): ?string
    {
        return 'Revisa los datos del período contable abierto antes de ejecutar su cierre.';
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            $this->getSaveFormAction(),
        ];
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->url(AccountingPeriodResource::getUrl('index'))
            ->color('gray');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Guardar cambios')->icon('heroicon-o-check');
    }

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
