<?php

namespace App\Filament\Owner\Resources\Collections\Pages;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListMyCollections extends ListRecords
{
    protected static string $resource = MyCollectionsResource::class;

    protected string $view = 'filament.owner.pages.mis-pagos';

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
            ->content(view('filament.owner.pages.partials.mis-pagos-records'))
            ->searchPlaceholder('Buscar referencia o método…');
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'applied' => Tab::make('Aplicados')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where('status', '!=', CollectionStatus::Cancelled)->where('applied_amount', '>', 0),
            ),
            'available' => Tab::make('Sin aplicar')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where('status', '!=', CollectionStatus::Cancelled)->where('unapplied_amount', '>', 0),
            ),
        ];
    }

    protected function getViewData(): array
    {
        $owner = Auth::user()?->propietario;
        $balances = app(PartnerBalanceService::class);

        return [
            'paidTotal' => $owner ? $balances->getTotalCollections($owner) : 0,
            'appliedTotal' => $owner ? $balances->getTotalApplied($owner) : 0,
            'availableTotal' => $owner ? $balances->getCreditBalance($owner) : 0,
            'paymentCount' => MyCollectionsResource::getEloquentQuery()->count(),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(MyCollectionsResource::canViewAny(), 403);

        $query = $this->getFilteredSortedTableQuery();

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['ID', 'Fecha', 'Método', 'Referencia', 'Monto', 'Aplicado', 'Sin aplicar', 'Estado'], ';', '"', '');

            foreach ($query->lazy(200) as $payment) {
                $row = [
                    $payment->id, $payment->collection_date?->format('Y-m-d'),
                    MyCollectionsResource::paymentMethodLabel($payment->collection_method),
                    $payment->reference ?? '', $payment->amount, $payment->applied_amount,
                    $payment->unapplied_amount, MyCollectionsResource::statusLabel($payment->status),
                ];
                $row = array_map(
                    fn (mixed $value): mixed => is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value,
                    $row,
                );
                fputcsv($output, $row, ';', '"', '');
            }

            fclose($output);
        }, 'mis-pagos.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
