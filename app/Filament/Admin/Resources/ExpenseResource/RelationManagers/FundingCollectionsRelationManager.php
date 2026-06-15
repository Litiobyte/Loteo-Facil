<?php

namespace App\Filament\Admin\Resources\ExpenseResource\RelationManagers;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ExpenseFundingPaymentService;
use App\Models\ExpenseFundingPayment;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class FundingCollectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'fundingCollections';

    protected static ?string $title = 'Recaudaciones de gasto (egresos)';

    protected static ?string $recordTitleAttribute = 'id';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('payment_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto egreso')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(60)
                    ->wrap(),
                IconColumn::make('is_void')
                    ->label('Anulado')
                    ->boolean(),
                TextColumn::make('void_reason')
                    ->label('Motivo anulación')
                    ->limit(50)
                    ->placeholder('-'),
                TextColumn::make('creator.name')
                    ->label('Creado por')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Registrar egreso')
                    ->icon('heroicon-o-arrow-trending-down')
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === ExpenseStatus::Distributed)
                    ->form([
                        DatePicker::make('payment_date')
                            ->label('Fecha de egreso')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now()->toDateString())
                            ->maxDate(now()),
                        TextInput::make('amount')
                            ->label('Monto egreso')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('CLP'),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2),
                        Placeholder::make('cash_projection_alert')
                            ->label('Validación de caja')
                            ->content(function (callable $get): string {
                                $amount = round((float) ($get('amount') ?? 0), 2);
                                $projectedCash = app(CashBalanceService::class)->getProjectedCashAfterFunding($amount);

                                if ($amount <= 0) {
                                    return 'Ingrese un monto para ver la proyección de caja.';
                                }

                                if ($projectedCash < 0) {
                                    return 'ALERTA: La caja proyectada quedará negativa en $'.number_format(abs($projectedCash), 0, ',', '.').' CLP.';
                                }

                                return 'Caja proyectada después del egreso: $'.number_format($projectedCash, 0, ',', '.').' CLP.';
                            }),
                        Checkbox::make('confirm_negative_cash')
                            ->label('Estoy seguro de continuar aunque la caja quede negativa')
                            ->inline(false)
                            ->accepted(fn (callable $get): bool => app(CashBalanceService::class)->getProjectedCashAfterFunding((float) ($get('amount') ?? 0)) < 0)
                            ->visible(fn (callable $get): bool => app(CashBalanceService::class)->getProjectedCashAfterFunding((float) ($get('amount') ?? 0)) < 0)
                            ->validationMessages([
                                'accepted' => 'Debe confirmar explícitamente para registrar un egreso que deja caja negativa.',
                            ]),
                    ])
                    ->using(function (array $data): ExpenseFundingPayment {
                        $allowNegativeCash = (bool) ($data['confirm_negative_cash'] ?? false);

                        try {
                            return app(ExpenseFundingPaymentService::class)->register(
                                expense: $this->getOwnerRecord(),
                                amount: (float) ($data['amount'] ?? 0),
                                paymentDate: (string) ($data['payment_date'] ?? now()->toDateString()),
                                notes: $data['notes'] ?? null,
                                allowNegativeCash: $allowNegativeCash,
                            );
                        } catch (DomainException $exception) {
                            throw ValidationException::withMessages([
                                'amount' => $exception->getMessage(),
                            ]);
                        }
                    })
                    ->after(function (): void {
                        Notification::make()
                            ->title('Egreso registrado correctamente')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ExpenseFundingPayment $record): bool => ! $record->is_void)
                    ->form([
                        DatePicker::make('payment_date')
                            ->label('Fecha de egreso')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now()),
                        TextInput::make('amount')
                            ->label('Monto egreso')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('CLP'),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2),
                        Placeholder::make('cash_projection_alert')
                            ->label('Validación de caja')
                            ->content(function (callable $get): string {
                                $amount = round((float) ($get('amount') ?? 0), 2);
                                $projectedCash = app(CashBalanceService::class)->getProjectedCashAfterFunding($amount);

                                if ($amount <= 0) {
                                    return 'Ingrese un monto para ver la proyección de caja.';
                                }

                                if ($projectedCash < 0) {
                                    return 'ALERTA: La caja proyectada quedará negativa en $'.number_format(abs($projectedCash), 0, ',', '.').' CLP.';
                                }

                                return 'Caja proyectada después del egreso: $'.number_format($projectedCash, 0, ',', '.').' CLP.';
                            }),
                        Checkbox::make('confirm_negative_cash')
                            ->label('Estoy seguro de continuar aunque la caja quede negativa')
                            ->inline(false)
                            ->accepted(fn (callable $get): bool => app(CashBalanceService::class)->getProjectedCashAfterFunding((float) ($get('amount') ?? 0)) < 0)
                            ->visible(fn (callable $get): bool => app(CashBalanceService::class)->getProjectedCashAfterFunding((float) ($get('amount') ?? 0)) < 0)
                            ->validationMessages([
                                'accepted' => 'Debe confirmar explícitamente para editar un egreso que deja caja negativa.',
                            ]),
                    ])
                    ->using(function (ExpenseFundingPayment $record, array $data): ExpenseFundingPayment {
                        $allowNegativeCash = (bool) ($data['confirm_negative_cash'] ?? false);

                        try {
                            return app(ExpenseFundingPaymentService::class)->update(
                                payment: $record,
                                amount: (float) ($data['amount'] ?? 0),
                                paymentDate: (string) ($data['payment_date'] ?? now()->toDateString()),
                                notes: $data['notes'] ?? null,
                                allowNegativeCash: $allowNegativeCash,
                            );
                        } catch (DomainException $exception) {
                            throw ValidationException::withMessages([
                                'amount' => $exception->getMessage(),
                            ]);
                        }
                    })
                    ->after(function (): void {
                        Notification::make()
                            ->title('Egreso actualizado correctamente')
                            ->success()
                            ->send();
                    }),
                Action::make('void')
                    ->label('Anular')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ExpenseFundingPayment $record): bool => ! $record->is_void)
                    ->form([
                        Textarea::make('void_reason')
                            ->label('Motivo de anulación')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (ExpenseFundingPayment $record, array $data): void {
                        try {
                            app(ExpenseFundingPaymentService::class)->void(
                                payment: $record,
                                reason: (string) ($data['void_reason'] ?? ''),
                            );

                            Notification::make()
                                ->title('Egreso anulado correctamente')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            throw $exception;
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo anular el egreso')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('payment_date', 'desc')
            ->heading('Egresos registrados para este gasto')
            ->description('Permite corregir montos y anular egresos para mantener trazabilidad de caja.')
            ->emptyStateHeading('Sin egresos registrados')
            ->emptyStateDescription('Registre el primer egreso para este gasto.')
            ->paginated([10, 25, 50]);
    }
}
