<?php

namespace App\Filament\Admin\Resources\ExpenseResource\RelationManagers;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Models\PartnerCharge;
use App\Support\ChargeSettlementPresentation;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Cobros generados';

    protected static ?string $recordTitleAttribute = 'description';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ChargeSettlementPresentation::withAmounts($query))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('propietario.nombre_completo')
                    ->label('Propietario')
                    ->searchable(['propietarios.nombre', 'propietarios.apellido'])
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Total aplicado (pagos y ajustes)')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('collection_applied_amount')
                    ->label('Pagado con recaudaciones')
                    ->money('CLP', locale: 'es_CL'),
                TextColumn::make('non_monetary_applied_amount')
                    ->label('Aplicado como ajuste no monetario')
                    ->money('CLP', locale: 'es_CL'),
                TextColumn::make('remaining_amount')
                    ->label('Saldo')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (PartnerCharge $record): string => ChargeSettlementPresentation::statusLabel($record))
                    ->color(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Pending => 'warning',
                        ChargeStatus::Partial => 'info',
                        ChargeStatus::Paid => 'success',
                        ChargeStatus::Cancelled => 'danger',
                        ChargeStatus::Consolidated => 'gray',
                    }),
                TextColumn::make('calculation_type')
                    ->label('Tipo de cálculo')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseDistributionType $state): string => match ($state) {
                        ExpenseDistributionType::EqualByPartner => 'Partes iguales',
                        ExpenseDistributionType::ProportionalByHectares => 'Proporcional',
                        ExpenseDistributionType::Manual => 'Manual',
                        ExpenseDistributionType::ProportionalByShares => 'Por acciones',
                        ExpenseDistributionType::ProportionalByEquivalentArea => 'Por superficie equivalente',
                    })
                    ->color('gray'),
                TextColumn::make('percentage_applied')
                    ->label('% Aplicado')
                    ->formatStateUsing(fn (?float $state): string => $state ? number_format($state, 2).'%' : '-')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('id', 'asc')
            ->recordUrl(fn ($record): string => route('filament.admin.resources.partner-charges.view', ['record' => $record]))
            ->recordActions([
                Action::make('view')
                    ->label('Ver')
                    ->url(fn ($record): string => route('filament.admin.resources.partner-charges.view', ['record' => $record])),
            ])
            ->heading('Cobros generados para este gasto')
            ->description('Listado de cobros distribuidos entre los propietarios activos al momento de la distribución.')
            ->emptyStateHeading('Sin cobros generados')
            ->emptyStateDescription('Este gasto aún no ha sido distribuido. Use la acción "Distribuir gasto" para generar los cobros.')
            ->paginated([10, 25, 50]);
    }
}
