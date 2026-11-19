<?php

namespace App\Filament\Owner\Resources\Cobros\Pages;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListMisCobros extends ListRecords
{
    protected static string $resource = MisCobrosResource::class;

    protected string $view = 'filament.owner.pages.mis-cobros';

    public function getHeading(): string
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function table(Table $table): Table
    {
        return $table
            ->content(view('filament.owner.pages.partials.mis-cobros-records'))
            ->searchPlaceholder('Buscar por concepto o ID');
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'pending' => Tab::make('Pendientes')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->unpaid()->where('remaining_amount', '>', 0)),
            'consolidated' => Tab::make('Consolidados')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ChargeStatus::Consolidated)),
        ];
    }

    protected function getViewData(): array
    {
        $query = MisCobrosResource::getEloquentQuery();
        $owner = Auth::user()?->propietario;

        return [
            'pendingBalance' => $owner ? app(PartnerBalanceService::class)->getPendingBalance($owner) : 0,
            'pendingCount' => (clone $query)->unpaid()->where('remaining_amount', '>', 0)->count(),
            'issuedTotal' => (clone $query)->sum('amount'),
            'paidTotal' => (clone $query)->sum('paid_amount'),
            'chargeCount' => (clone $query)->count(),
            'hasConsolidated' => (clone $query)->where('status', ChargeStatus::Consolidated)->exists(),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(MisCobrosResource::canViewAny(), 403);

        $query = $this->getFilteredSortedTableQuery();

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['ID', 'Concepto', 'Categoría', 'Vencimiento', 'Monto emitido', 'Pago aplicado', 'Saldo pendiente', 'Estado'], ';', '"', '');

            foreach ($query->lazy(200) as $charge) {
                $row = [
                    $charge->id,
                    $charge->description ?? $charge->expense?->title ?? 'Cobro',
                    $charge->expense?->category?->name ?? '',
                    $charge->due_date?->format('Y-m-d') ?? '',
                    $charge->amount,
                    $charge->paid_amount,
                    $charge->status === ChargeStatus::Consolidated ? 0 : $charge->remaining_amount,
                    MisCobrosResource::statusLabel($charge->status),
                ];
                $row = array_map(
                    fn (mixed $value): mixed => is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value,
                    $row,
                );
                fputcsv($output, $row, ';', '"', '');
            }

            fclose($output);
        }, 'mis-cobros.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
