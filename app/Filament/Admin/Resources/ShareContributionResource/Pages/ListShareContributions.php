<?php

namespace App\Filament\Admin\Resources\ShareContributionResource\Pages;

use App\Domain\Owners\Services\ShareContributionService;
use App\Filament\Admin\Resources\ShareContributionResource;
use App\Filament\Admin\Widgets\ShareContributionsStatWidget;
use App\Models\Propietario;
use App\Models\ShareHolding;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListShareContributions extends ListRecords
{
    protected static string $resource = ShareContributionResource::class;

    public function getSubheading(): ?string
    {
        return 'Aportes independientes de gastos comunes y caja. Las correcciones son diferencias, no nuevos pagos. Los importes antiguos no informados no se presumen cero.';
    }

    public function getTabs(): array
    {
        return [
            'resumen' => Tab::make('Resumen por socio'),
            'movimientos' => Tab::make('Historial de movimientos'),
        ];
    }

    /** @return array<class-string> */
    public function getHeaderWidgets(): array
    {
        return [ShareContributionsStatWidget::class];
    }

    public function table(Table $table): Table
    {
        return $this->activeTab === 'resumen'
            ? $this->summaryTable($table)
            : ShareContributionResource::historyTable($table);
    }

    private function summaryTable(Table $table): Table
    {
        $table->query(Propietario::query()
            ->where(fn (Builder $query): Builder => $query->whereHas('shareHoldings')->orWhereHas('shareContributions'))
            ->with(['shareHoldings', 'shareContributions']))
            ->defaultSort('nombre')->columns([
            TextColumn::make('nombre_completo')->label('Socio')->searchable(['nombre', 'apellido']),
            TextColumn::make('aporte_total')->label('Aporte total registrado')
                ->state(fn (Propietario $record): float => (float) $record->shareContributions->sum('amount'))
                ->money('CLP', locale: 'es_CL'),
            TextColumn::make('movimientos')->label('Aportes registrados')
                ->state(fn (Propietario $record): int => $record->shareContributions->count()),
            TextColumn::make('acciones')->label('Acciones')
                ->state(fn (Propietario $record): string => ShareHolding::displayQuantity((string) $record->shareHoldings->sum('quantity'))),
            TextColumn::make('estado_informacion')->label('Estado de información')->badge()
                ->state(fn (Propietario $record): string => app(ShareContributionService::class)->summary($record)['has_unknown_history'] ? 'desconocida' : 'completa')
                ->formatStateUsing(fn (string $state): string => $state === 'desconocida' ? 'Historial desconocido' : 'Historial conocido')
                ->color(fn (string $state): string => $state === 'desconocida' ? 'warning' : 'success'),
        ]);

        return $table->filters([
            SelectFilter::make('socio')->label('Socio')->options(
                fn (): array => Propietario::query()->orderBy('nombre')->get()
                    ->mapWithKeys(fn (Propietario $owner): array => [$owner->id => $owner->nombre_completo])->all()
            )->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereKey($data['value'])),
            SelectFilter::make('informacion')->label('Estado de información')
                ->options(['desconocida' => 'Historial desconocido', 'completa' => 'Historial completo'])
                ->query(function (Builder $query, array $data): Builder {
                    if (blank($data['value'] ?? null)) {
                        return $query;
                    }
                    $unknownIds = app(ShareContributionService::class)->ownerIdsWithUnknownHistory();

                    return $data['value'] === 'desconocida' ? $query->whereKey($unknownIds) : $query->whereNotIn('id', $unknownIds);
                }),
        ])->recordActions([]);
    }
}
