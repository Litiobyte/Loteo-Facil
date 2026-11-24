<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ListPartnerCharges;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ViewPartnerCharge;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\PartnerCharge;
use App\Support\AdminTableColumns;
use App\Support\ChargeSettlementPresentation;
use App\Support\ChileanNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class PartnerChargeResource extends Resource
{
    protected static ?string $model = PartnerCharge::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Cobros';

    protected static ?string $modelLabel = 'Cobro';

    protected static ?string $pluralModelLabel = 'Cobros';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del Cobro')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID del cobro'),
                        TextEntry::make('consolidation')
                            ->label('Consolidación')
                            ->badge()->color('gray')
                            ->state(fn (PartnerCharge $record): ?string => static::consolidationLabel($record)),
                        TextEntry::make('propietario.nombre_completo')
                            ->label('Propietario')
                            ->url(fn (PartnerCharge $record): string => PropietarioResource::getUrl('edit', ['record' => $record->propietario_id])),
                        TextEntry::make('expense.title')
                            ->label('Gasto relacionado')
                            ->url(fn (PartnerCharge $record): ?string => $record->expense_id ? ExpenseResource::getUrl('view', ['record' => $record->expense_id]) : null),
                        TextEntry::make('amount')
                            ->label('Monto del cobro')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('paid_amount')
                            ->label('Total aplicado (pagos y ajustes)')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('collection_applied_amount')
                            ->label('Pagado con recaudaciones')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('non_monetary_applied_amount')
                            ->label('Aplicado como ajuste no monetario')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('remaining_amount')
                            ->state(fn (PartnerCharge $record): string => $record->status === ChargeStatus::Consolidated ? '0' : $record->remaining_amount)
                            ->label('Saldo pendiente')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (PartnerCharge $record): string => ChargeSettlementPresentation::statusLabel($record))
                            ->color(fn (ChargeStatus|string|null $state): string => static::getStatusColor($state)),
                        TextEntry::make('due_date')
                            ->label('Fecha de vencimiento')
                            ->date('d/m/Y'),
                        TextEntry::make('description')
                            ->label('Descripción')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Detalles de Distribución')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('calculation_type')
                            ->label('Tipo de cálculo')
                            ->badge()
                            ->formatStateUsing(fn (ExpenseDistributionType|string|null $state): string => static::getCalculationTypeLabel($state))
                            ->color(fn (ExpenseDistributionType|string|null $state): string => static::getCalculationTypeColor($state)),
                        TextEntry::make('partner_hectares_at_moment')
                            ->label('Hectáreas del propietario al momento')
                            ->numeric(decimalPlaces: 4),
                        TextEntry::make('total_hectares_at_moment')
                            ->label('Total de hectáreas al momento')
                            ->numeric(decimalPlaces: 4),
                        TextEntry::make('percentage_applied')
                            ->label('% aplicado')
                            ->suffix('%')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('calculation_notes')
                            ->label('Notas de cálculo')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('distribution_snapshot.unit')->label('Unidad de distribución')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.base')->label('Base individual congelada')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.total_base')->label('Total elegible congelado')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.quantity')->label('Acciones al emitir')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.equivalent_square_meters')->label('Superficie equivalente al emitir (m²)')->formatStateUsing(fn (mixed $state): ?string => ChileanNumber::format($state))->placeholder('-'),
                    ]),
                Section::make('Recaudaciones aplicados')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('allocations_resume')
                            ->label('Asignaciones de pago')
                            ->state(fn (PartnerCharge $record): string => static::buildAllocationSummary($record))
                            ->placeholder('Sin pagos aplicados aún.')
                            ->html(),
                        TextEntry::make('remaining_amount_summary')
                            ->label('Saldo pendiente actual')
                            ->state(fn (PartnerCharge $record): string => '$'.number_format($record->status === ChargeStatus::Consolidated ? 0 : (float) $record->remaining_amount, 0, ',', '.')),
                    ]),
                Section::make('Auditoría')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Creado el')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Actualizado el')
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['propietario', 'expense', 'sourceLinks.consolidatedCharge']))
            ->defaultSort('created_at', 'desc')
            ->columns(AdminTableColumns::contentSized([
                TextColumn::make('consolidation')
                    ->label('Consolidación')
                    ->badge()
                    ->color('gray')
                    ->state(fn (PartnerCharge $record): ?string => static::consolidationLabel($record)),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('propietario.nombre')
                    ->label('Propietario')
                    ->state(fn (PartnerCharge $record): string => $record->propietario?->nombre_completo ?? '-')
                    ->url(fn (PartnerCharge $record): string => PropietarioResource::getUrl('edit', ['record' => $record->propietario_id]))
                    ->searchable(['propietario.nombre', 'propietario.apellido'])
                    ->sortable(),
                TextColumn::make('expense.title')
                    ->label('Gasto relacionado')
                    ->url(fn (PartnerCharge $record): ?string => $record->expense_id ? ExpenseResource::getUrl('view', ['record' => $record->expense_id]) : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto del cobro')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->money('CLP', locale: 'es_CL'),
                    ),
                TextColumn::make('paid_amount')
                    ->label('Total aplicado (pagos y ajustes)')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->money('CLP', locale: 'es_CL'),
                    ),
                TextColumn::make('collection_applied_amount')
                    ->label('Pagado con recaudaciones')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('non_monetary_applied_amount')
                    ->label('Aplicado como ajuste no monetario')
                    ->money('CLP', locale: 'es_CL')
                    ->color(fn (mixed $state): string => (float) $state > 0 ? 'info' : 'gray')
                    ->sortable(),
                TextColumn::make('remaining_amount')
                    ->state(fn (PartnerCharge $record): string => $record->status === ChargeStatus::Consolidated ? '0' : $record->remaining_amount)
                    ->label('Saldo pendiente')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->money('CLP', locale: 'es_CL')
                            ->query(fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])),
                    ),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (PartnerCharge $record): string => ChargeSettlementPresentation::statusLabel($record))
                    ->color(fn (ChargeStatus|string|null $state): string => static::getStatusColor($state)),
                TextColumn::make('calculation_type')
                    ->label('Tipo de cálculo')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseDistributionType|string|null $state): string => static::getCalculationTypeLabel($state))
                    ->color(fn (ExpenseDistributionType|string|null $state): string => static::getCalculationTypeColor($state)),
                TextColumn::make('percentage_applied')
                    ->label('% aplicado')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%'),
                TextColumn::make('due_date')
                    ->label('Fecha de vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->toggleable()
                    ->label('Fecha de creación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]))
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        ChargeStatus::Pending->value => 'Pendiente',
                        ChargeStatus::Partial->value => 'Parcial',
                        ChargeStatus::Paid->value => 'Saldado (pagos o ajustes)',
                        ChargeStatus::Consolidated->value => 'Consolidado',
                        'unpaid' => 'Impagos',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === 'unpaid') {
                            return $query->unpaid();
                        }

                        if (in_array($value, [ChargeStatus::Pending->value, ChargeStatus::Partial->value, ChargeStatus::Paid->value], true)) {
                            return $query->where('status', $value);
                        }

                        return $query;
                    }),
                SelectFilter::make('propietario_id')
                    ->label('Propietario')
                    ->relationship('propietario', 'nombre')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->nombre_completo)
                    ->searchable(['nombre', 'apellido'])
                    ->preload(),
                SelectFilter::make('expense_id')
                    ->label('Gasto')
                    ->relationship('expense', 'title')
                    ->searchable()
                    ->preload(),
                Filter::make('due_date_range')
                    ->label('Rango de vencimiento')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('due_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('due_date', '<=', $date),
                            );
                    }),
                SelectFilter::make('calculation_type')
                    ->label('Tipo de cálculo')
                    ->options([
                        ExpenseDistributionType::EqualByPartner->value => 'Partes iguales',
                        ExpenseDistributionType::ProportionalByHectares->value => 'Proporcional por hectáreas',
                        ExpenseDistributionType::ProportionalByShares->value => 'Proporcional por acciones',
                        ExpenseDistributionType::ProportionalByEquivalentArea->value => 'Proporcional por superficie equivalente',
                        ExpenseDistributionType::Manual->value => 'Manual',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('view_applied_collections')
                    ->label('Ver pagos aplicados')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Recaudaciones aplicados al cobro')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalWidth('4xl')
                    ->modalContent(fn (PartnerCharge $record) => view('filament.admin.partner-charge.actions.view-applied-collections', [
                        'allocations' => $record->allocations()
                            ->with('payment')
                            ->orderByDesc('allocated_at')
                            ->get(),
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('export_selected_csv')
                        ->label('Exportar seleccionados (CSV)')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (EloquentCollection $records): StreamedResponse => static::exportSelectedToCsv($records)),
                ]),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'propietario.nombre',
            'propietario.apellido',
            'expense.title',
            'description',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var PartnerCharge $record */
        return [
            'Propietario' => $record->propietario?->nombre_completo ?? '-',
            'Gasto' => $record->expense?->title ?? '-',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var PartnerCharge $record */
        return sprintf('Cobro #%d', $record->id);
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        /** @var PartnerCharge $record */
        return static::getUrl('view', ['record' => $record]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return ChargeSettlementPresentation::withAmounts(parent::getEloquentQuery());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerCharges::route('/'),
            'view' => ViewPartnerCharge::route('/{record}'),
        ];
    }

    protected static function getStatusLabel(ChargeStatus|string|null $state): string
    {
        $status = $state instanceof ChargeStatus ? $state : ChargeStatus::from((string) $state);

        return match ($status) {
            ChargeStatus::Pending => 'Pendiente',
            ChargeStatus::Partial => 'Parcial',
            ChargeStatus::Paid => 'Saldado (pagos o ajustes)',
            ChargeStatus::Cancelled => 'Cancelado',
            ChargeStatus::Consolidated => 'Consolidado',
        };
    }

    protected static function consolidationLabel(PartnerCharge $record): ?string
    {
        $target = $record->status === ChargeStatus::Consolidated
            ? $record->sourceLinks->first()?->consolidatedCharge
            : ($record->charge_type === 'balance_consolidation' ? $record : null);
        if (! $target?->consolidation_year || ! $target->consolidation_month) {
            return null;
        }

        return ($record->status === ChargeStatus::Consolidated ? 'Consolidado en ' : 'Saldo ').Carbon::create($target->consolidation_year, $target->consolidation_month, 1)->locale('es')->translatedFormat('F Y');
    }

    protected static function getStatusColor(ChargeStatus|string|null $state): string
    {
        $status = $state instanceof ChargeStatus ? $state : ChargeStatus::from((string) $state);

        return match ($status) {
            ChargeStatus::Pending => 'warning',
            ChargeStatus::Partial => 'info',
            ChargeStatus::Paid => 'success',
            ChargeStatus::Cancelled => 'danger',
            ChargeStatus::Consolidated => 'gray',
        };
    }

    protected static function getCalculationTypeLabel(ExpenseDistributionType|string|null $state): string
    {
        $type = $state instanceof ExpenseDistributionType ? $state : ExpenseDistributionType::from((string) $state);

        return match ($type) {
            ExpenseDistributionType::EqualByPartner => 'Partes iguales',
            ExpenseDistributionType::ProportionalByHectares => 'Proporcional por hectáreas',
            ExpenseDistributionType::Manual => 'Manual',
            ExpenseDistributionType::ProportionalByShares => 'Proporcional por acciones',
            ExpenseDistributionType::ProportionalByEquivalentArea => 'Proporcional por superficie equivalente',
        };
    }

    protected static function getCalculationTypeColor(ExpenseDistributionType|string|null $state): string
    {
        $type = $state instanceof ExpenseDistributionType ? $state : ExpenseDistributionType::from((string) $state);

        return match ($type) {
            ExpenseDistributionType::EqualByPartner => 'info',
            ExpenseDistributionType::ProportionalByHectares => 'warning',
            ExpenseDistributionType::Manual => 'gray',
            ExpenseDistributionType::ProportionalByShares, ExpenseDistributionType::ProportionalByEquivalentArea => 'success',
        };
    }

    protected static function exportSelectedToCsv(EloquentCollection $records): StreamedResponse
    {
        $fileName = sprintf('partner-charges-%s.csv', now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($records): void {
            $handle = fopen('php://output', 'w');

            if (! $handle) {
                return;
            }

            fputcsv($handle, [
                'ID',
                'Propietario',
                'Gasto',
                'Monto',
                'Total aplicado (pagos y ajustes)',
                'Pagado con recaudaciones',
                'Aplicado como ajuste no monetario',
                'Saldo pendiente',
                'Estado',
                'Tipo de cálculo',
                '% aplicado',
                'Vencimiento',
                'Creado',
            ]);

            foreach ($records as $record) {
                if (! $record instanceof PartnerCharge) {
                    continue;
                }

                fputcsv($handle, [
                    $record->id,
                    $record->propietario?->nombre_completo,
                    $record->expense?->title,
                    (float) $record->amount,
                    (float) $record->paid_amount,
                    (float) $record->collection_applied_amount,
                    (float) $record->non_monetary_applied_amount,
                    (float) $record->remaining_amount,
                    ChargeSettlementPresentation::statusLabel($record),
                    static::getCalculationTypeLabel($record->calculation_type),
                    $record->percentage_applied,
                    $record->due_date?->format('Y-m-d'),
                    $record->created_at?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected static function buildAllocationSummary(PartnerCharge $record): string
    {
        $allocations = $record->allocations()
            ->with('payment')
            ->orderByDesc('allocated_at')
            ->limit(10)
            ->get();

        if ($allocations->isEmpty()) {
            return 'Sin pagos aplicados aún.';
        }

        $rows = $allocations
            ->map(function ($allocation): string {
                $paymentDate = $allocation->payment?->collection_date?->format('d/m/Y') ?? '-';
                $paymentMethod = $allocation->payment?->collection_method;
                $methodLabel = $paymentMethod instanceof CollectionMethod
                    ? $paymentMethod->getLabel()
                    : ($paymentMethod ? CollectionMethod::from((string) $paymentMethod)->getLabel() : '-');

                return sprintf(
                    '<li><strong>%s</strong> · %s · $%s · aplicado %s</li>',
                    e($paymentDate),
                    e($methodLabel),
                    e(number_format((float) $allocation->amount, 0, ',', '.')),
                    e($allocation->allocated_at?->format('d/m/Y H:i') ?? '-'),
                );
            })
            ->implode('');

        return '<ul class="list-disc pl-5 space-y-1">'.$rows.'</ul>';
    }
}
