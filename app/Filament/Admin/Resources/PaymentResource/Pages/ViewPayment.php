<?php

namespace App\Filament\Admin\Resources\PaymentResource\Pages;

use App\Domain\Payments\Services\PaymentApplicationService;
use App\Filament\Admin\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Payment $record): bool => PaymentResource::canEdit($record)),
            Action::make('reverse_last_allocation')
                ->label('Revertir última asignación')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (Payment $record): bool => $record->allocations()->exists())
                ->requiresConfirmation()
                ->modalHeading('Revertir última asignación')
                ->modalDescription('Se eliminará la última asignación aplicada y se recalcularán saldos de pago y cobro.')
                ->action(function (Payment $record): void {
                    try {
                        $allocation = $record->allocations()
                            ->latest('allocated_at')
                            ->latest('id')
                            ->first();

                        if (! $allocation instanceof PaymentAllocation) {
                            Notification::make()
                                ->title('No hay asignaciones para revertir')
                                ->warning()
                                ->send();

                            return;
                        }

                        app(PaymentApplicationService::class)->reverseAllocation($allocation);

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
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            PaymentResource\RelationManagers\AllocationsRelationManager::class,
        ];
    }
}
