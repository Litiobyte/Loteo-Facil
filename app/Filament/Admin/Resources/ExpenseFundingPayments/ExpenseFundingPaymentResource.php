<?php

namespace App\Filament\Admin\Resources\ExpenseFundingPayments;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ExpenseFundingPaymentService;
use App\Filament\Admin\Resources\ExpenseFundingPayments\Pages\ListExpenseFundingPayments;
use App\Filament\Admin\Resources\ExpenseFundingPayments\Pages\ViewExpenseFundingPayment;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ExpenseFundingPaymentResource extends Resource
{
    protected static ?string $model = ExpenseFundingPayment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-down';

    protected static ?string $navigationLabel = 'Egresos';

    protected static ?string $modelLabel = 'Egreso';

    protected static ?string $pluralModelLabel = 'Egresos';

    protected static string|UnitEnum|null $navigationGroup = 'Gastos';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del egreso')
                    ->columns(2)
                    ->schema([
                        Select::make('expense_id')
                            ->label('Gasto')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => static::getExpenseOptions())
                            ->validationMessages([
                                'required' => 'Debe seleccionar un gasto.',
                            ]),
                        DatePicker::make('payment_date')
                            ->label('Fecha de egreso')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->default(now()->toDateString()),
                        TextInput::make('amount')
                            ->label('Monto egreso')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('CLP'),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Resumen del egreso')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID'),
                        TextEntry::make('expense.title')
                            ->label('Gasto')
                            ->url(fn (ExpenseFundingPayment $record): string => ExpenseResource::getUrl('view', ['record' => $record->expense_id])),
                        TextEntry::make('payment_date')
                            ->label('Fecha de egreso')
                            ->date('d/m/Y'),
                        TextEntry::make('amount')
                            ->label('Monto egreso')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('is_void')
                            ->label('Anulado')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                            ->color(fn (bool $state): string => $state ? 'danger' : 'success'),
                        TextEntry::make('void_reason')
                            ->label('Motivo de anulación')
                            ->placeholder('-'),
                        TextEntry::make('creator.name')
                            ->label('Creado por')
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Actualizado')
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['expense', 'creator']))
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('expense.title')
                    ->label('Gasto')
                    ->url(fn (ExpenseFundingPayment $record): string => ExpenseResource::getUrl('view', ['record' => $record->expense_id]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('payment_date')
                    ->label('Fecha de egreso')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto egreso')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                IconColumn::make('is_void')
                    ->label('Anulado')
                    ->boolean(),
                TextColumn::make('void_reason')
                    ->label('Motivo anulación')
                    ->limit(40)
                    ->placeholder('-'),
                TextColumn::make('creator.name')
                    ->label('Creado por')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('expense_id')
                    ->label('Gasto')
                    ->relationship('expense', 'title')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_void')
                    ->label('Estado')
                    ->options([
                        '0' => 'Vigentes',
                        '1' => 'Anulados',
                    ]),
                Filter::make('payment_date_range')
                    ->label('Rango fecha egreso')
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
                                fn (Builder $query, string $date): Builder => $query->whereDate('payment_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('payment_date', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('edit_outflow')
                    ->label('Editar egreso')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
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
                    ])
                    ->fillForm(fn (ExpenseFundingPayment $record): array => [
                        'payment_date' => $record->payment_date?->toDateString() ?? now()->toDateString(),
                        'amount' => (float) $record->amount,
                        'notes' => $record->notes,
                    ])
                    ->action(function (ExpenseFundingPayment $record, array $data): void {
                        try {
                            app(ExpenseFundingPaymentService::class)->update(
                                payment: $record,
                                amount: (float) ($data['amount'] ?? 0),
                                paymentDate: (string) ($data['payment_date'] ?? now()->toDateString()),
                                notes: $data['notes'] ?? null,
                            );

                            Notification::make()
                                ->title('Egreso actualizado correctamente')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            throw $exception;
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo actualizar el egreso')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('void_outflow')
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
            ->headerActions([
                Action::make('register_outflow')
                    ->label('Registrar egreso')
                    ->icon('heroicon-o-arrow-trending-down')
                    ->color('primary')
                    ->form([
                        Select::make('expense_id')
                            ->label('Gasto')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => static::getExpenseOptions()),
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
                    ])
                    ->action(function (array $data): void {
                        try {
                            $expense = Expense::query()->findOrFail((int) ($data['expense_id'] ?? 0));

                            app(ExpenseFundingPaymentService::class)->register(
                                expense: $expense,
                                amount: (float) ($data['amount'] ?? 0),
                                paymentDate: (string) ($data['payment_date'] ?? now()->toDateString()),
                                notes: $data['notes'] ?? null,
                            );

                            Notification::make()
                                ->title('Egreso registrado correctamente')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            throw $exception;
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo registrar el egreso')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseFundingPayments::route('/'),
            'view' => ViewExpenseFundingPayment::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
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
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    protected static function getExpenseOptions(): array
    {
        return Expense::query()
            ->where('status', ExpenseStatus::Distributed->value)
            ->orderByDesc('expense_date')
            ->limit(200)
            ->get()
            ->mapWithKeys(function (Expense $expense): array {
                $pending = max(0, round((float) $expense->amount - (float) $expense->funded_amount, 2));

                return [
                    $expense->id => sprintf(
                        '#%d · %s · Saldo %s',
                        $expense->id,
                        $expense->title,
                        number_format($pending, 2, ',', '.').' CLP',
                    ),
                ];
            })
            ->all();
    }
}
