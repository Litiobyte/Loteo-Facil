<?php

namespace App\Filament\Admin\Resources\PaymentResource\RelationManagers;

use App\Domain\Payments\Services\PaymentApplicationService;
use App\Models\PaymentAllocation;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    protected static ?string $title = 'Asignaciones del pago';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('charge.id')
                    ->label('Cobro')
                    ->formatStateUsing(fn ($state): string => $state ? '#'.$state : '-')
                    ->sortable(),
                TextColumn::make('charge.description')
                    ->label('Descripción cobro')
                    ->state(fn (PaymentAllocation $record): string => $record->charge?->description ?? "Cobro #{$record->partner_charge_id}")
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Monto asignado')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('allocated_at')
                    ->label('Asignado el')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('reverse_allocation')
                    ->label('Revertir')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revertir asignación')
                    ->modalDescription('Esta acción deshará la asignación y recalculará saldos del cobro y del pago.')
                    ->action(function (PaymentAllocation $record): void {
                        try {
                            app(PaymentApplicationService::class)->reverseAllocation($record);

                            Notification::make()
                                ->title('Asignación revertida correctamente')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo revertir la asignación')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Error inesperado al revertir')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('allocated_at', 'desc')
            ->heading('Asignaciones realizadas para este pago')
            ->description('Detalle de cobros que recibieron aplicación desde este pago.')
            ->emptyStateHeading('Sin asignaciones')
            ->emptyStateDescription('Este pago aún no tiene asignaciones aplicadas.')
            ->paginated([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('summary')
                ->label('Resumen')
                ->disabled()
                ->color('gray')
                ->tooltip(fn (): string => sprintf(
                    'Total: %s | Aplicado: %s | No aplicado: %s',
                    number_format((float) $this->ownerRecord->amount, 2, ',', '.').' CLP',
                    number_format((float) $this->ownerRecord->applied_amount, 2, ',', '.').' CLP',
                    number_format((float) $this->ownerRecord->unapplied_amount, 2, ',', '.').' CLP',
                )),
        ];
    }
}
