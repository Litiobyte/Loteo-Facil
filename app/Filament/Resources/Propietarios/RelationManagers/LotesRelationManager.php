<?php

namespace App\Filament\Resources\Propietarios\RelationManagers;

use App\Domain\Owners\Services\LoteEligibilityService;
use App\Domain\Owners\Services\LoteOwnershipAssignmentService;
use App\Models\Lote;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LotesRelationManager extends RelationManager
{
    protected static string $relationship = 'lotesActivos';

    protected static ?string $title = 'Lotes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Codigo')
                    ->searchable(),
                TextColumn::make('hectareas')
                    ->label('Hectareas')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('pivot.assigned_at')
                    ->label('Asignado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('pivot.unassigned_at')
                    ->label('Desasignado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                TextColumn::make('pivot.status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('asignarLote')
                    ->label('Asignar lote')
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->ownerRecord) ?? false)
                    ->form([
                        Select::make('lote_id')
                            ->label('Lote')
                            ->options(fn (): array => app(LoteEligibilityService::class)->assignableLoteOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $lote = Lote::query()->findOrFail($data['lote_id']);

                            app(LoteOwnershipAssignmentService::class)->assign(
                                propietario: $this->ownerRecord,
                                lote: $lote,
                            );

                            Notification::make()
                                ->title('Lote asignado correctamente')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo asignar el lote')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('desasignar')
                    ->label('Desasignar')
                    ->color('danger')
                    ->icon('heroicon-o-user-minus')
                    ->requiresConfirmation()
                    ->visible(fn (Lote $record): bool => $record->pivot?->status === 'active')
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->ownerRecord) ?? false)
                    ->action(function (Lote $record): void {
                        try {
                            app(LoteOwnershipAssignmentService::class)->unassign(
                                propietario: $this->ownerRecord,
                                lote: $record,
                            );

                            Notification::make()
                                ->title('Lote desasignado correctamente')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo desasignar')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
