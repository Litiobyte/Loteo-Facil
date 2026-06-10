<?php

namespace App\Filament\Admin\Resources\ExpenseResource\RelationManagers;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Cobros generados';

    protected static ?string $recordTitleAttribute = 'description';

    public function table(Table $table): Table
    {
        return $table
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
                    ->money('CLP')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Pagado')
                    ->money('CLP')
                    ->sortable(),
                TextColumn::make('remaining_amount')
                    ->label('Saldo')
                    ->money('CLP')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Pending => 'Pendiente',
                        ChargeStatus::Partial => 'Parcial',
                        ChargeStatus::Paid => 'Pagado',
                        ChargeStatus::Cancelled => 'Cancelado',
                    })
                    ->color(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Pending => 'warning',
                        ChargeStatus::Partial => 'info',
                        ChargeStatus::Paid => 'success',
                        ChargeStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('calculation_type')
                    ->label('Tipo de cálculo')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseDistributionType $state): string => match ($state) {
                        ExpenseDistributionType::EqualByPartner => 'Partes iguales',
                        ExpenseDistributionType::ProportionalByHectares => 'Proporcional',
                        ExpenseDistributionType::Manual => 'Manual',
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
