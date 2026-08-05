<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Expenses\Services\ExpenseFundingPaymentService;
use App\Filament\Admin\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Models\Expense;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Gastos';

    protected static ?string $modelLabel = 'Gasto';

    protected static ?string $pluralModelLabel = 'Gastos';

    protected static string|UnitEnum|null $navigationGroup = 'Gastos';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información General')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Título')
                            ->required()
                            ->maxLength(200),
                        Select::make('expense_category_id')
                            ->label('Categoría')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(100)
                                    ->unique(table: 'expense_categories', column: 'name'),
                                Textarea::make('description')
                                    ->label('Descripción')
                                    ->rows(3)
                                    ->maxLength(500),
                                Toggle::make('is_active')
                                    ->label('Activa')
                                    ->default(true)
                                    ->required(),
                            ]),
                        TextInput::make('amount')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->prefix('$')
                            ->suffix('CLP')
                            ->minValue(1)
                            ->step(0.01)
                            ->rule('gt:0'),
                        DatePicker::make('expense_date')
                            ->label('Fecha de gasto')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now()),
                        DatePicker::make('due_date')
                            ->label('Fecha de vencimiento')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('expense_date'),
                    ]),
                Section::make('Distribución')
                    ->schema([
                        Select::make('distribution_type')
                            ->label('Tipo de distribución')
                            ->required()
                            ->native(false)
                            ->options([
                                ExpenseDistributionType::EqualByPartner->value => 'Partes iguales entre propietarios',
                                ExpenseDistributionType::ProportionalByHectares->value => 'Proporcional por hectáreas',
                                ExpenseDistributionType::Manual->value => 'Asignación manual',
                            ])
                            ->helperText('Define cómo se distribuirá el gasto en Fase 3.'),
                    ]),
                Section::make('Detalles')
                    ->columns(2)
                    ->schema([
                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(3)
                            ->maxLength(500),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Estado')
                    ->visibleOn('edit')
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->native(false)
                            ->options([
                                ExpenseStatus::Registered->value => 'Registrado',
                                ExpenseStatus::Distributed->value => 'Distribuido',
                                ExpenseStatus::Paid->value => 'Pagado',
                                ExpenseStatus::Cancelled->value => 'Cancelado',
                            ])
                            ->disabled(fn (?Expense $record): bool => ! $record || in_array($record->status, [ExpenseStatus::Paid, ExpenseStatus::Cancelled], true)),
                        TextInput::make('funded_amount')
                            ->label('Pagado desde caja')
                            ->numeric()
                            ->prefix('$')
                            ->suffix('CLP')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('category')->withCount('charges'))
            ->defaultSort('expense_date', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->money('CLP', locale: 'es_CL'),
                    ),
                TextColumn::make('expense_date')
                    ->label('Fecha gasto')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('distribution_type')
                    ->label('Distribución')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseDistributionType|string|null $state): string => match ($state instanceof ExpenseDistributionType ? $state : ExpenseDistributionType::from((string) $state)) {
                        ExpenseDistributionType::EqualByPartner => 'Partes iguales',
                        ExpenseDistributionType::ProportionalByHectares => 'Por hectáreas',
                        ExpenseDistributionType::Manual => 'Manual',
                    })
                    ->color(fn (ExpenseDistributionType|string|null $state): string => match ($state instanceof ExpenseDistributionType ? $state : ExpenseDistributionType::from((string) $state)) {
                        ExpenseDistributionType::EqualByPartner => 'info',
                        ExpenseDistributionType::ProportionalByHectares => 'warning',
                        ExpenseDistributionType::Manual => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseStatus|string|null $state): string => match ($state instanceof ExpenseStatus ? $state : ExpenseStatus::from((string) $state)) {
                        ExpenseStatus::Registered => 'Registrado',
                        ExpenseStatus::Distributed => 'Distribuido',
                        ExpenseStatus::Paid => 'Pagado',
                        ExpenseStatus::Cancelled => 'Cancelado',
                    })
                    ->color(fn (ExpenseStatus|string|null $state): string => match ($state instanceof ExpenseStatus ? $state : ExpenseStatus::from((string) $state)) {
                        ExpenseStatus::Registered => 'info',
                        ExpenseStatus::Distributed => 'success',
                        ExpenseStatus::Paid => 'primary',
                        ExpenseStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('paid_at')
                    ->label('Pagado el')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('funded_amount')
                    ->label('Pagado desde caja')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('pending_funding')
                    ->label('Saldo por pagar')
                    ->state(fn (Expense $record): float => max(0, round((float) $record->amount - (float) $record->funded_amount, 2)))
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('charges_count')
                    ->label('Cobros generados')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state): string => $state > 0 ? "{$state} cobros" : 'Sin cobros')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('distributed_at')
                    ->label('Distribuido el')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recurrence_hint')
                    ->label('Recurrencia')
                    ->state(function (Expense $record): ?string {
                        $categoryName = mb_strtolower((string) $record->category?->name);
                        $notes = mb_strtolower((string) $record->notes);

                        $isLikelyRecurringCategory = in_array($categoryName, [
                            'contador',
                            'administración',
                            'administracion',
                            'software',
                        ], true);

                        $hasRecurringKeyword = str_contains($notes, 'mensual')
                            || str_contains($notes, 'recurrente');

                        if (! $isLikelyRecurringCategory && ! $hasRecurringKeyword) {
                            return null;
                        }

                        return 'Probable recurrente';
                    })
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('expense_category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->multiple()
                    ->options([
                        ExpenseStatus::Registered->value => 'Registrado',
                        ExpenseStatus::Distributed->value => 'Distribuido',
                        ExpenseStatus::Paid->value => 'Pagado',
                        ExpenseStatus::Cancelled->value => 'Cancelado',
                    ]),
                SelectFilter::make('distribution_type')
                    ->label('Distribución')
                    ->multiple()
                    ->options([
                        ExpenseDistributionType::EqualByPartner->value => 'Partes iguales entre propietarios',
                        ExpenseDistributionType::ProportionalByHectares->value => 'Proporcional por hectáreas',
                        ExpenseDistributionType::Manual->value => 'Asignación manual',
                    ]),
                Filter::make('expense_date_range')
                    ->label('Rango fecha gasto')
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
                                fn (Builder $query, string $date): Builder => $query->whereDate('expense_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('expense_date', '<=', $date),
                            );
                    }),
                Filter::make('last_month_expenses')
                    ->label('Gastos del mes pasado')
                    ->query(function (Builder $query): Builder {
                        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
                        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

                        return $query->whereBetween('expense_date', [$lastMonthStart, $lastMonthEnd]);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('duplicate_expense')
                    ->label('Duplicar gasto')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->authorize(fn (): bool => static::canAccess())
                    ->modalHeading('Duplicar gasto')
                    ->modalDescription('Crea un nuevo gasto en estado registrado copiando este registro.')
                    ->modalSubmitActionLabel('Duplicar')
                    ->requiresConfirmation()
                    ->form([
                        DatePicker::make('expense_date')
                            ->label('Nueva fecha de gasto')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(fn (Expense $record): string => Carbon::parse($record->expense_date)->addMonthNoOverflow()->toDateString()),
                        DatePicker::make('due_date')
                            ->label('Nueva fecha de vencimiento')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(fn (Expense $record): ?string => $record->due_date
                                ? Carbon::parse($record->due_date)->addMonthNoOverflow()->toDateString()
                                : null)
                            ->afterOrEqual('expense_date'),
                        TextInput::make('amount')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('CLP')
                            ->default(fn (Expense $record): float => (float) $record->amount),
                    ])
                    ->action(function (Expense $record, array $data): void {
                        try {
                            static::duplicateExpense($record, $data);

                            Notification::make()
                                ->title('Gasto duplicado exitosamente')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('No se pudo duplicar el gasto')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('distribute_expense')
                    ->label('Distribuir gasto')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->authorize(fn (): bool => static::canAccess())
                    ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Registered)
                    ->modalHeading('Distribuir gasto entre propietarios')
                    ->modalDescription(fn (Expense $record): string => "Se generarán cobros automáticos para este gasto de \${$record->amount} CLP según el método de distribución configurado: {$record->distribution_type->getLabel()}.")
                    ->modalSubmitActionLabel('Distribuir y generar cobros')
                    ->requiresConfirmation()
                    ->action(function (Expense $record): void {
                        try {
                            $service = app(ChargeGenerationService::class);
                            $charges = $service->generateChargesFromExpense($record);

                            Notification::make()
                                ->title('Gasto distribuido exitosamente')
                                ->body("Se generaron {$charges->count()} cobros para los propietarios activos.")
                                ->success()
                                ->send();
                        } catch (\DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo distribuir el gasto')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Error inesperado al distribuir')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('register_funding_payment')
                    ->label('Registrar pago de gasto')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->authorize(fn (): bool => static::canAccess())
                    ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Distributed)
                    ->modalHeading('Registrar pago desde caja')
                    ->modalDescription(function (Expense $record): string {
                        $pending = max(0, round((float) $record->amount - (float) $record->funded_amount, 2));
                        $availableCash = app(CashBalanceService::class)->getAvailableCash();

                        return 'Saldo pendiente: $'.number_format($pending, 0, ',', '.').' CLP | Caja disponible: $'.number_format($availableCash, 0, ',', '.').' CLP';
                    })
                    ->modalSubmitActionLabel('Registrar pago')
                    ->requiresConfirmation()
                    ->form([
                        TextInput::make('amount')
                            ->label('Monto a pagar desde caja')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('CLP'),
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

                                return 'Caja proyectada después del pago: $'.number_format($projectedCash, 0, ',', '.').' CLP.';
                            }),
                        Checkbox::make('confirm_negative_cash')
                            ->label('Estoy seguro de continuar aunque la caja quede negativa')
                            ->inline(false)
                            ->accepted(fn (callable $get): bool => app(CashBalanceService::class)->getProjectedCashAfterFunding((float) ($get('amount') ?? 0)) < 0)
                            ->visible(fn (callable $get): bool => app(CashBalanceService::class)->getProjectedCashAfterFunding((float) ($get('amount') ?? 0)) < 0)
                            ->validationMessages([
                                'accepted' => 'Debe confirmar explícitamente para registrar un pago que deja caja negativa.',
                            ]),
                    ])
                    ->action(function (Expense $record, array $data): void {
                        try {
                            $amount = (float) ($data['amount'] ?? 0);
                            $projectedCash = app(CashBalanceService::class)->getProjectedCashAfterFunding($amount);
                            $allowNegativeCash = (bool) ($data['confirm_negative_cash'] ?? false);

                            if ($projectedCash < 0 && ! $allowNegativeCash) {
                                throw ValidationException::withMessages([
                                    'confirm_negative_cash' => 'La caja quedará negativa. Debe confirmar para continuar.',
                                ]);
                            }

                            app(ExpenseFundingPaymentService::class)->register(
                                expense: $record,
                                amount: $amount,
                                paymentDate: now()->toDateString(),
                                notes: 'Registro desde acción rápida de gasto',
                                allowNegativeCash: $allowNegativeCash,
                            );

                            $updated = $record->fresh();

                            $remaining = max(0, round((float) $updated->amount - (float) $updated->funded_amount, 2));
                            $cashAfter = app(CashBalanceService::class)->getAvailableCash();

                            Notification::make()
                                ->title('Recaudacion registrado desde caja')
                                ->body('Pagado acumulado: $'.number_format((float) $updated->funded_amount, 0, ',', '.').' CLP. Saldo pendiente: $'.number_format($remaining, 0, ',', '.').' CLP. Caja disponible actual: $'.number_format($cashAfter, 0, ',', '.').' CLP.')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            throw $exception;
                        } catch (\DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo registrar el pago')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make()
                    ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Registered),
                Action::make('cancel_expense')
                    ->label('Cancelar gasto')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Registered)
                    ->action(function (Expense $record): void {
                        $record->update([
                            'status' => ExpenseStatus::Cancelled,
                        ]);
                    }),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Registered),
            ])
            ->checkIfRecordIsSelectableUsing(
                fn (Expense $record): bool => in_array($record->status, [
                    ExpenseStatus::Registered,
                    ExpenseStatus::Distributed,
                    ExpenseStatus::Paid,
                    ExpenseStatus::Cancelled,
                ], true),
            )
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('duplicate_selected_expenses')
                        ->label('Duplicar seleccionados')
                        ->icon('heroicon-o-squares-plus')
                        ->color('gray')
                        ->authorize(fn (): bool => static::canAccess())
                        ->modalHeading('Duplicar gastos seleccionados')
                        ->modalSubmitActionLabel('Duplicar gastos')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->form([
                            DatePicker::make('target_month')
                                ->label('Mes/Año destino')
                                ->required()
                                ->native(false)
                                ->displayFormat('m/Y')
                                ->default(now()->addMonthNoOverflow()->startOfMonth()->toDateString()),
                            Radio::make('amount_mode')
                                ->label('Ajuste de montos')
                                ->required()
                                ->default('keep')
                                ->options([
                                    'keep' => 'Mantener montos',
                                    'percentage' => 'Ajustar todos al mismo porcentaje',
                                ])
                                ->inline(false),
                            TextInput::make('percentage_adjustment')
                                ->label('Porcentaje de ajuste (%)')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->visible(fn (callable $get): bool => $get('amount_mode') === 'percentage')
                                ->required(fn (callable $get): bool => $get('amount_mode') === 'percentage'),
                            Placeholder::make('preview')
                                ->label('Preview')
                                ->content(function (array $data, EloquentCollection $records): string {
                                    $targetMonth = isset($data['target_month'])
                                        ? Carbon::parse((string) $data['target_month'])
                                        : now()->addMonthNoOverflow()->startOfMonth();

                                    return sprintf(
                                        'Se crearán %d gastos nuevos para %s.',
                                        $records->count(),
                                        $targetMonth->translatedFormat('m/Y'),
                                    );
                                }),
                        ])
                        ->action(function (EloquentCollection $records, array $data): void {
                            try {
                                $result = static::duplicateExpensesForMonth($records, $data);

                                if ($result['warnings'] > 0) {
                                    Notification::make()
                                        ->title('Advertencia de posibles duplicados')
                                        ->body(sprintf(
                                            'Se detectaron %d posibles duplicados por título en el mes destino. No se bloqueó la duplicación.',
                                            $result['warnings'],
                                        ))
                                        ->warning()
                                        ->send();
                                }

                                Notification::make()
                                    ->title(sprintf('%d gastos duplicados exitosamente', $result['created']))
                                    ->success()
                                    ->send();
                            } catch (\Throwable $exception) {
                                Notification::make()
                                    ->title('No se pudieron duplicar los gastos seleccionados')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    /**
     * @param  array{expense_date: string, due_date?: string|null, amount: numeric-string|float|int}  $data
     */
    public static function duplicateExpense(Expense $record, array $data): Expense
    {
        return Expense::query()->create([
            'expense_category_id' => $record->expense_category_id,
            'title' => $record->title,
            'description' => $record->description,
            'amount' => (float) $data['amount'],
            'expense_date' => Carbon::parse((string) $data['expense_date'])->toDateString(),
            'due_date' => isset($data['due_date']) && $data['due_date']
                ? Carbon::parse((string) $data['due_date'])->toDateString()
                : null,
            'distribution_type' => $record->distribution_type,
            'status' => ExpenseStatus::Registered,
            'created_by' => auth()->id(),
            'notes' => $record->notes,
        ]);
    }

    /**
     * @param  array{target_month: string, amount_mode: 'keep'|'percentage', percentage_adjustment?: numeric-string|float|int|null}  $data
     * @return array{created: int, warnings: int}
     */
    public static function duplicateExpensesForMonth(EloquentCollection $records, array $data): array
    {
        $targetMonth = Carbon::parse((string) $data['target_month'])->startOfMonth();
        $targetMonthEnd = $targetMonth->copy()->endOfMonth();

        $warnings = 0;
        $created = 0;

        DB::transaction(function () use ($records, $data, $targetMonth, $targetMonthEnd, &$warnings, &$created): void {
            foreach ($records as $record) {
                if (! $record instanceof Expense) {
                    continue;
                }

                $existingDuplicate = Expense::query()
                    ->where('title', $record->title)
                    ->whereBetween('expense_date', [$targetMonth, $targetMonthEnd])
                    ->exists();

                if ($existingDuplicate) {
                    $warnings++;
                }

                $expenseDate = $targetMonth->copy()->setDay(
                    min($record->expense_date->day, $targetMonth->copy()->daysInMonth),
                );

                $dueDate = null;

                if ($record->due_date) {
                    $dueDateOffset = $record->expense_date->diffInDays($record->due_date, false);
                    $dueDate = $expenseDate->copy()->addDays(max($dueDateOffset, 0))->toDateString();
                }

                $amount = (float) $record->amount;

                if (($data['amount_mode'] ?? 'keep') === 'percentage') {
                    $percentageAdjustment = (float) ($data['percentage_adjustment'] ?? 0);
                    $amount = round($amount * (1 + ($percentageAdjustment / 100)), 2);
                }

                static::duplicateExpense($record, [
                    'expense_date' => $expenseDate->toDateString(),
                    'due_date' => $dueDate,
                    'amount' => $amount,
                ]);

                $created++;
            }
        });

        return [
            'created' => $created,
            'warnings' => $warnings,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof Expense
            && $record->status === ExpenseStatus::Registered;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
