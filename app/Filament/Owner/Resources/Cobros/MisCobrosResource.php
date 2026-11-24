<?php

namespace App\Filament\Owner\Resources\Cobros;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Filament\Owner\Resources\Cobros\Pages\ListMisCobros;
use App\Filament\Owner\Resources\Cobros\Pages\ViewCobro;
use App\Models\PartnerCharge;
use App\Support\ChargeSettlementPresentation;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MisCobrosResource extends Resource
{
    protected static ?string $model = PartnerCharge::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?string $navigationLabel = 'Mis cobros';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Cobro';

    protected static ?string $pluralModelLabel = 'Mis Cobros';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->unpaid()
            ->where('remaining_amount', '>', 0)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ViewCobro::buildInfolist($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->with(['expense.category', 'allocations.payment', 'sourceLinks.consolidatedCharge'])
                    ->orderByRaw(
                        "
                        CASE
                            WHEN due_date < ? AND status IN ('pending', 'partial') THEN 1
                            WHEN status IN ('pending', 'partial') THEN 2
                            ELSE 3
                        END
                        ",
                        [now()->toDateString()]
                    );
            })
            ->defaultSort('due_date', 'asc')
            ->columns([
                TextColumn::make('consolidation')
                    ->label('Consolidación')
                    ->badge()
                    ->color('gray')
                    ->state(fn (PartnerCharge $record): ?string => static::consolidationLabel($record)),
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (mixed $state): string => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('expense.category.name')
                    ->label('Categoría')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('expense.expense_date')
                    ->label('Fecha Gasto')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(Sum::make()->money('CLP', locale: 'es_CL')),
                TextColumn::make('paid_amount')
                    ->label('Total aplicado (pagos y ajustes)')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(Sum::make()->money('CLP', locale: 'es_CL')),
                TextColumn::make('collection_applied_amount')
                    ->label('Pagado con recaudaciones')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('non_monetary_applied_amount')
                    ->label('Aplicado como ajuste no monetario')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('remaining_amount')
                    ->state(fn (PartnerCharge $record): string => $record->status === ChargeStatus::Consolidated ? '0' : $record->remaining_amount)
                    ->label('Pendiente')
                    ->money('CLP', locale: 'es_CL')
                    ->badge()
                    ->color(fn (float|string|null $state): string => (float) $state > 0 ? 'danger' : 'success')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->money('CLP', locale: 'es_CL')
                            ->query(fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value]))
                    ),
                TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(function (PartnerCharge $record): string {
                        if ($record->due_date && $record->due_date->isPast() && in_array($record->status, [ChargeStatus::Pending, ChargeStatus::Partial], true)) {
                            return 'danger';
                        }

                        return 'gray';
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (PartnerCharge $record): string => ChargeSettlementPresentation::statusLabel($record))
                    ->color(fn (ChargeStatus|string $state): string => static::statusColor($state)),
                TextColumn::make('calculation_type')
                    ->label('Tipo Cálculo')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseDistributionType|string $state): string => static::calculationTypeLabel($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->multiple()
                    ->options([
                        ChargeStatus::Pending->value => 'Pendiente',
                        ChargeStatus::Partial->value => 'Parcial',
                        ChargeStatus::Paid->value => 'Saldado (pagos o ajustes)',
                        ChargeStatus::Cancelled->value => 'Cancelado',
                        ChargeStatus::Consolidated->value => 'Consolidado',
                    ]),
                Filter::make('due_date_range')
                    ->label('Rango vencimiento')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false),
                        DatePicker::make('to')->label('Hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('due_date', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('due_date', '<=', $date));
                    }),
                Filter::make('quick_overdue')
                    ->label('Vencidos')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('due_date', '<', now()->toDateString())
                        ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])),
                Filter::make('quick_this_month')
                    ->label('Este mes')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereBetween('due_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])),
                Filter::make('quick_next_month')
                    ->label('Próximo mes')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereBetween('due_date', [now()->addMonthNoOverflow()->startOfMonth()->toDateString(), now()->addMonthNoOverflow()->endOfMonth()->toDateString()])),
                SelectFilter::make('category')
                    ->label('Categoría')
                    ->relationship('expense.category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('calculation_type')
                    ->label('Tipo Cálculo')
                    ->options([
                        ExpenseDistributionType::EqualByPartner->value => ExpenseDistributionType::EqualByPartner->getLabel(),
                        ExpenseDistributionType::ProportionalByHectares->value => ExpenseDistributionType::ProportionalByHectares->getLabel(),
                        ExpenseDistributionType::Manual->value => ExpenseDistributionType::Manual->getLabel(),
                        ExpenseDistributionType::ProportionalByShares->value => ExpenseDistributionType::ProportionalByShares->getLabel(),
                        ExpenseDistributionType::ProportionalByEquivalentArea->value => ExpenseDistributionType::ProportionalByEquivalentArea->getLabel(),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Sin cobros registrados')
            ->emptyStateDescription('Cuando existan cobros asignados, aparecerán aquí con su detalle completo.');
    }

    public static function getEloquentQuery(): Builder
    {
        $propietarioId = Auth::user()?->propietario?->id;

        if (! $propietarioId) {
            return ChargeSettlementPresentation::withAmounts(parent::getEloquentQuery())->whereRaw('1 = 0');
        }

        return ChargeSettlementPresentation::withAmounts(parent::getEloquentQuery())->where('propietario_id', $propietarioId);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole('propietario') && Auth::user()?->propietario !== null;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof PartnerCharge
            && $record->propietario_id === Auth::user()?->propietario?->id;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMisCobros::route('/'),
            'view' => ViewCobro::route('/{record}'),
        ];
    }

    public static function statusLabel(ChargeStatus|string $state): string
    {
        $status = $state instanceof ChargeStatus ? $state : ChargeStatus::from($state);

        return match ($status) {
            ChargeStatus::Pending => 'Pendiente',
            ChargeStatus::Partial => 'Parcial',
            ChargeStatus::Paid => 'Saldado (pagos o ajustes)',
            ChargeStatus::Cancelled => 'Cancelado',
            ChargeStatus::Consolidated => 'Consolidado',
        };
    }

    public static function consolidationLabel(PartnerCharge $record): ?string
    {
        $target = $record->status === ChargeStatus::Consolidated
            ? $record->sourceLinks->first()?->consolidatedCharge
            : ($record->charge_type === 'balance_consolidation' ? $record : null);

        if (! $target || ! $target->consolidation_year || ! $target->consolidation_month) {
            return null;
        }

        $month = Carbon::create($target->consolidation_year, $target->consolidation_month, 1)->locale('es')->translatedFormat('F Y');

        return ($record->status === ChargeStatus::Consolidated ? 'Consolidado en ' : 'Saldo ').$month;
    }

    public static function statusColor(ChargeStatus|string $state): string
    {
        $status = $state instanceof ChargeStatus ? $state : ChargeStatus::from($state);

        return match ($status) {
            ChargeStatus::Pending => 'danger',
            ChargeStatus::Partial => 'warning',
            ChargeStatus::Paid => 'success',
            ChargeStatus::Cancelled => 'gray',
            ChargeStatus::Consolidated => 'gray',
        };
    }

    public static function calculationTypeLabel(ExpenseDistributionType|string $state): string
    {
        $type = $state instanceof ExpenseDistributionType ? $state : ExpenseDistributionType::from($state);

        return $type->getLabel();
    }
}
