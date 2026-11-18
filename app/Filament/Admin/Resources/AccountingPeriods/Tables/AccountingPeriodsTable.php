<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Tables;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Support\AdminTableColumns;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountingPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('snapshot'))
            ->columns(AdminTableColumns::contentSized([
                TextColumn::make('consolidations')
                    ->label('Consolidaciones')
                    ->state(fn (AccountingPeriod $record): int => count($record->snapshot?->metadata['consolidated'] ?? []))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('year')
                    ->label('Año')
                    ->sortable(),
                TextColumn::make('month')
                    ->label('Mes')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (AccountingPeriodStatus|string $state): string => ($state instanceof AccountingPeriodStatus ? $state : AccountingPeriodStatus::from($state))->getLabel())
                    ->color(fn (AccountingPeriodStatus|string $state): string => ($state instanceof AccountingPeriodStatus ? $state : AccountingPeriodStatus::from($state)) === AccountingPeriodStatus::Closed ? 'success' : 'gray'),
                TextColumn::make('close_folio')
                    ->label('Folio')
                    ->toggleable()
                    ->sortable()
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('closed_at')
                    ->label('Cerrado el')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('reopened_at')
                    ->label('Reabierto el')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ]))
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        AccountingPeriodStatus::Open->value => AccountingPeriodStatus::Open->getLabel(),
                        AccountingPeriodStatus::Closed->value => AccountingPeriodStatus::Closed->getLabel(),
                    ]),
            ])
            ->recordActions([
                Action::make('close_period')
                    ->authorize(fn (): bool => AccountingPeriodResource::canAccess())
                    ->label('Cerrar mes')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (AccountingPeriod $record): bool => $record->status === AccountingPeriodStatus::Open)
                    ->requiresConfirmation()
                    ->modalDescription(fn (AccountingPeriod $record): string => static::closingPreview($record))
                    ->action(function (AccountingPeriod $record): void {
                        try {
                            $closed = app(MonthlyClosingService::class)->closePeriod($record->year, $record->month, auth()->id());
                            $metadata = $closed->snapshot?->metadata ?? [];

                            Notification::make()
                                ->title('Período cerrado')
                                ->body(sprintf('Gastos procesados: %d. Consolidaciones: %d. Excluidos sin vencimiento: %d. Excluidos no vencidos: %d.', count($metadata['processed_expenses'] ?? []), count($metadata['consolidated'] ?? []), count($metadata['excluded_null_due_date'] ?? []), count($metadata['excluded_not_overdue'] ?? [])))
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo cerrar el período')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('reopen_period')
                    ->authorize(fn (): bool => AccountingPeriodResource::canAccess())
                    ->requiresConfirmation()
                    ->label('Reabrir')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn (AccountingPeriod $record): bool => $record->status === AccountingPeriodStatus::Closed)
                    ->form([
                        Textarea::make('reason')
                            ->label('Motivo de reapertura')
                            ->required()
                            ->rows(3)
                            ->maxLength(500),
                    ])
                    ->action(function (AccountingPeriod $record, array $data): void {
                        try {
                            app(MonthlyClosingService::class)->reopenPeriod($record, (string) ($data['reason'] ?? ''), auth()->id());

                            Notification::make()
                                ->title('Período reabierto')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo reabrir el período')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('export_csv')
                    ->authorize(fn (): bool => AccountingPeriodResource::canAccess())
                    ->label('Exportar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (AccountingPeriod $record): bool => $record->status === AccountingPeriodStatus::Closed)
                    ->action(fn (AccountingPeriod $record) => app(MonthlyClosingService::class)->exportClosedPeriodCsv($record)),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('year', 'desc');
    }

    public static function closingPreview(AccountingPeriod $period): string
    {
        $cutoff = $period->period_end->toDateString();
        $expenses = Expense::query()->registered()->whereDate('due_date', '<', $cutoff)->count();
        $owners = PartnerCharge::query()->unpaid()->whereDate('due_date', '<', $cutoff)
            ->with('propietario')->get()->groupBy('propietario_id')
            ->map(fn ($charges): string => ($charges->first()->propietario?->nombre_completo ?? 'Propietario').' (#'.$charges->first()->propietario_id.'): '.$charges->count().' cobros, $'.number_format((float) $charges->sum('remaining_amount'), 0, ',', '.'))
            ->implode(' | ');

        return "Vista previa: {$expenses} gastos registrados vencidos. Cobros impagos vencidos por propietario: ".($owners ?: 'ninguno').'. Los cobros generados durante el cierre también se procesarán; el resultado definitivo se calcula al confirmar.';
    }
}
