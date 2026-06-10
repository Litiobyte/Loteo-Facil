<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentApplicationService;
use App\Filament\Admin\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Admin\Resources\PaymentResource\Pages\EditPayment;
use App\Filament\Admin\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Admin\Resources\PaymentResource\Pages\ViewPayment;
use App\Filament\Admin\Resources\PaymentResource\RelationManagers\AllocationsRelationManager;
use App\Models\PartnerCharge;
use App\Models\Payment;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Pagos';

    protected static ?string $modelLabel = 'Pago';

    protected static ?string $pluralModelLabel = 'Pagos';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del Pago')
                    ->columns(2)
                    ->schema([
                        Select::make('propietario_id')
                            ->label('Propietario')
                            ->relationship('propietario', 'nombre')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->nombre_completo)
                            ->searchable(['nombre', 'apellido'])
                            ->preload()
                            ->required()
                            ->validationMessages([
                                'required' => 'Debe seleccionar un propietario.',
                            ]),
                        TextInput::make('amount')
                            ->label('Monto')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('CLP')
                            ->rule('gt:0')
                            ->helperText('Ingrese el monto total recibido para este pago.')
                            ->validationMessages([
                                'required' => 'El monto es obligatorio.',
                                'numeric' => 'El monto debe ser numérico.',
                                'gt' => 'El monto debe ser mayor a 0.',
                            ]),
                        DatePicker::make('payment_date')
                            ->label('Fecha de pago')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->required()
                            ->validationMessages([
                                'required' => 'La fecha de pago es obligatoria.',
                                'max' => 'La fecha de pago no puede ser futura.',
                            ]),
                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->native(false)
                            ->required()
                            ->options([
                                PaymentMethod::Efectivo->value => PaymentMethod::Efectivo->getLabel(),
                                PaymentMethod::Transferencia->value => PaymentMethod::Transferencia->getLabel(),
                                PaymentMethod::Cheque->value => PaymentMethod::Cheque->getLabel(),
                                PaymentMethod::Otro->value => PaymentMethod::Otro->getLabel(),
                            ])
                            ->validationMessages([
                                'required' => 'Debe seleccionar el método de pago.',
                            ]),
                        TextInput::make('reference')
                            ->label('Referencia')
                            ->maxLength(255)
                            ->helperText('Opcional: número de transferencia, cheque u otra referencia.'),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Aplicación del Pago')
                    ->visibleOn('edit')
                    ->columns(3)
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->native(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->options([
                                PaymentStatus::PendingApplication->value => 'Pendiente de aplicación',
                                PaymentStatus::PartiallyApplied->value => 'Parcialmente aplicado',
                                PaymentStatus::FullyApplied->value => 'Totalmente aplicado',
                                PaymentStatus::Cancelled->value => 'Cancelado',
                            ]),
                        TextInput::make('applied_amount')
                            ->label('Monto aplicado')
                            ->prefix('$')
                            ->suffix('CLP')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('unapplied_amount')
                            ->label('Monto no aplicado')
                            ->prefix('$')
                            ->suffix('CLP')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Resumen del Pago')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('propietario.nombre_completo')
                            ->label('Propietario'),
                        TextEntry::make('payment_date')
                            ->label('Fecha de pago')
                            ->date('d/m/Y'),
                        TextEntry::make('payment_method')
                            ->label('Método')
                            ->formatStateUsing(fn (PaymentMethod|string $state): string => $state instanceof PaymentMethod ? $state->getLabel() : PaymentMethod::from($state)->getLabel()),
                        TextEntry::make('amount')
                            ->label('Monto total')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('applied_amount')
                            ->label('Monto aplicado')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('unapplied_amount')
                            ->label('Monto no aplicado')
                            ->money('CLP', locale: 'es_CL'),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (PaymentStatus|string $state): string => static::getStatusLabel($state))
                            ->color(fn (PaymentStatus|string $state): string => static::getStatusColor($state)),
                        TextEntry::make('reference')
                            ->label('Referencia')
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('propietario')->withCount('allocations'))
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('propietario.nombre')
                    ->label('Propietario')
                    ->state(fn (Payment $record): string => $record->propietario?->nombre_completo ?? '-')
                    ->searchable(['propietario.nombre', 'propietario.apellido'])
                    ->sortable(['propietario.nombre', 'propietario.apellido']),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->money('CLP', locale: 'es_CL'),
                    ),
                TextColumn::make('payment_date')
                    ->label('Fecha de pago')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod|string $state): string => $state instanceof PaymentMethod ? $state->getLabel() : PaymentMethod::from($state)->getLabel())
                    ->color(fn (PaymentMethod|string $state): string => match ($state instanceof PaymentMethod ? $state : PaymentMethod::from($state)) {
                        PaymentMethod::Efectivo => 'success',
                        PaymentMethod::Transferencia => 'info',
                        PaymentMethod::Cheque => 'warning',
                        PaymentMethod::Otro => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus|string $state): string => static::getStatusLabel($state))
                    ->color(fn (PaymentStatus|string $state): string => static::getStatusColor($state)),
                TextColumn::make('applied_amount')
                    ->label('Monto aplicado')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('unapplied_amount')
                    ->label('Monto no aplicado')
                    ->money('CLP', locale: 'es_CL')
                    ->badge()
                    ->color(fn ($state): string => (float) $state > 0 ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('propietario_id')
                    ->label('Propietario')
                    ->relationship('propietario', 'nombre')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->nombre_completo)
                    ->searchable(['nombre', 'apellido'])
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->multiple()
                    ->options([
                        PaymentStatus::PendingApplication->value => 'Pendiente de aplicación',
                        PaymentStatus::PartiallyApplied->value => 'Parcialmente aplicado',
                        PaymentStatus::FullyApplied->value => 'Totalmente aplicado',
                        PaymentStatus::Cancelled->value => 'Cancelado',
                    ]),
                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->multiple()
                    ->options([
                        PaymentMethod::Efectivo->value => PaymentMethod::Efectivo->getLabel(),
                        PaymentMethod::Transferencia->value => PaymentMethod::Transferencia->getLabel(),
                        PaymentMethod::Cheque->value => PaymentMethod::Cheque->getLabel(),
                        PaymentMethod::Otro->value => PaymentMethod::Otro->getLabel(),
                    ]),
                Filter::make('payment_date_range')
                    ->label('Rango fecha pago')
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
                Filter::make('last_month_payments')
                    ->label('Pagos del mes pasado')
                    ->query(function (Builder $query): Builder {
                        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
                        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

                        return $query->whereBetween('payment_date', [$lastMonthStart, $lastMonthEnd]);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('apply_automatically')
                    ->label('Aplicar automáticamente')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->visible(fn (Payment $record): bool => static::canApplyPayment($record))
                    ->requiresConfirmation()
                    ->modalHeading('Aplicar pago automáticamente')
                    ->modalDescription(fn (Payment $record): string => static::buildAutomaticPreviewDescription($record))
                    ->modalSubmitActionLabel('Aplicar pago')
                    ->action(function (Payment $record): void {
                        try {
                            $allocationCount = static::applyAutomatically($record);

                            Notification::make()
                                ->title('Pago aplicado correctamente')
                                ->body("Se generaron {$allocationCount} asignaciones.")
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo aplicar el pago')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Error inesperado al aplicar el pago')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('apply_manually')
                    ->label('Aplicar manualmente')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (Payment $record): bool => static::canApplyPayment($record))
                    ->modalHeading('Aplicar pago manualmente')
                    ->modalDescription(fn (Payment $record): string => 'Saldo disponible para aplicar: '.number_format((float) $record->unapplied_amount, 2, ',', '.').' CLP')
                    ->modalSubmitActionLabel('Aplicar manualmente')
                    ->form([
                        Repeater::make('allocations')
                            ->label('Asignaciones')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->schema([
                                Select::make('charge_id')
                                    ->label('Cobro')
                                    ->required()
                                    ->searchable()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->options(fn (Payment $record): array => static::getAvailableChargeOptions($record))
                                    ->helperText('Solo se muestran cobros impagos del mismo propietario.'),
                                TextInput::make('amount')
                                    ->label('Monto a aplicar')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->prefix('$')
                                    ->suffix('CLP')
                                    ->validationMessages([
                                        'required' => 'Debe ingresar el monto a aplicar.',
                                        'min' => 'El monto debe ser mayor a 0.',
                                    ]),
                            ])
                            ->validationMessages([
                                'min' => 'Debe ingresar al menos una asignación.',
                            ]),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        try {
                            $allocationCount = static::applyManually($record, $data['allocations'] ?? []);

                            Notification::make()
                                ->title('Pago aplicado manualmente')
                                ->body("Se generaron {$allocationCount} asignaciones.")
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            throw $exception;
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('No se pudo aplicar manualmente')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Error inesperado al aplicar manualmente')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make()
                    ->visible(fn (Payment $record): bool => static::canEdit($record)),
                Action::make('cancel_payment')
                    ->label('Cancelar pago')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Payment $record): bool => $record->status !== PaymentStatus::Cancelled)
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar pago')
                    ->modalDescription('Esta acción marcará el pago como cancelado y bloqueará su edición.')
                    ->action(function (Payment $record): void {
                        static::cancelPayment($record);

                        Notification::make()
                            ->title('Pago cancelado')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (Payment $record): bool => static::canDelete($record)),
            ]);
    }

    /**
     * @param  array<int, array{charge_id?: int|string|null, amount?: float|int|string|null}>  $allocations
     */
    public static function applyManually(Payment $payment, array $allocations): int
    {
        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Debe ingresar al menos una asignación manual.',
            ]);
        }

        $normalized = [];
        $usedCharges = [];
        $requestedTotal = 0.0;

        foreach ($allocations as $index => $allocation) {
            $chargeId = (int) ($allocation['charge_id'] ?? 0);
            $amount = round((float) ($allocation['amount'] ?? 0), 2);

            if ($chargeId <= 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.charge_id" => 'Debe seleccionar un cobro válido.',
                ]);
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => 'El monto a aplicar debe ser mayor a 0.',
                ]);
            }

            if (in_array($chargeId, $usedCharges, true)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.charge_id" => 'No puede repetir el mismo cobro en más de una línea.',
                ]);
            }

            $usedCharges[] = $chargeId;
            $requestedTotal = round($requestedTotal + $amount, 2);

            $normalized[] = [
                'charge_id' => $chargeId,
                'amount' => $amount,
            ];
        }

        if ($requestedTotal > (float) $payment->unapplied_amount) {
            throw ValidationException::withMessages([
                'allocations' => 'La suma de asignaciones excede el saldo no aplicado del pago.',
            ]);
        }

        $service = app(PaymentApplicationService::class);
        $createdAllocations = $service->applyPaymentManually($payment, $normalized);

        return $createdAllocations->count();
    }

    public static function applyAutomatically(Payment $payment): int
    {
        $service = app(PaymentApplicationService::class);
        $createdAllocations = $service->applyPaymentAutomatically($payment);

        return $createdAllocations->count();
    }

    public static function cancelPayment(Payment $payment): void
    {
        $payment->update([
            'status' => PaymentStatus::Cancelled,
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
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
            && $record instanceof Payment
            && in_array($record->status, [PaymentStatus::PendingApplication, PaymentStatus::PartiallyApplied], true);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof Payment
            && ! $record->allocations()->exists();
    }

    public static function getRelations(): array
    {
        return [
            AllocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'create' => CreatePayment::route('/create'),
            'view' => ViewPayment::route('/{record}'),
            'edit' => EditPayment::route('/{record}/edit'),
        ];
    }

    protected static function canApplyPayment(Payment $payment): bool
    {
        return in_array($payment->status, [PaymentStatus::PendingApplication, PaymentStatus::PartiallyApplied], true);
    }

    /**
     * @return array<int, string>
     */
    protected static function getAvailableChargeOptions(Payment $payment): array
    {
        return PartnerCharge::query()
            ->where('propietario_id', $payment->propietario_id)
            ->unpaid()
            ->orderByRaw('due_date ASC NULLS LAST')
            ->orderBy('created_at', 'asc')
            ->get()
            ->mapWithKeys(function (PartnerCharge $charge): array {
                $description = $charge->description ?: "Cobro #{$charge->id}";
                $cleanDescription = Str::limit($description, 50);

                return [
                    $charge->id => sprintf(
                        '#%d · %s · Saldo %s',
                        $charge->id,
                        $cleanDescription,
                        number_format((float) $charge->remaining_amount, 2, ',', '.').' CLP',
                    ),
                ];
            })
            ->all();
    }

    protected static function buildAutomaticPreviewDescription(Payment $payment): string
    {
        $preview = app(PaymentApplicationService::class)->previewAutomaticAllocation($payment);

        if (($preview['allocations'] ?? []) === []) {
            return 'No hay cobros pendientes para aplicar automáticamente este pago.';
        }

        $rows = Collection::make($preview['allocations'])
            ->take(5)
            ->map(fn (array $allocation): string => sprintf(
                '• Cobro #%d: %s',
                (int) $allocation['charge_id'],
                number_format((float) $allocation['allocation_amount'], 2, ',', '.').' CLP',
            ))
            ->implode("\n");

        $extraCount = max(0, count($preview['allocations']) - 5);

        $summary = sprintf(
            "Se aplicarán %d asignaciones por un total de %s.\nSaldo remanente del pago: %s.",
            count($preview['allocations']),
            number_format((float) $preview['total_allocated'], 2, ',', '.').' CLP',
            number_format((float) $preview['remaining_credit'], 2, ',', '.').' CLP',
        );

        if ($extraCount > 0) {
            return $summary."\n\n".$rows."\n… y {$extraCount} asignaciones más.";
        }

        return $summary."\n\n".$rows;
    }

    protected static function getStatusLabel(PaymentStatus|string $state): string
    {
        $status = $state instanceof PaymentStatus ? $state : PaymentStatus::from($state);

        return match ($status) {
            PaymentStatus::PendingApplication => 'Pendiente de aplicación',
            PaymentStatus::PartiallyApplied => 'Parcialmente aplicado',
            PaymentStatus::FullyApplied => 'Totalmente aplicado',
            PaymentStatus::Cancelled => 'Cancelado',
        };
    }

    protected static function getStatusColor(PaymentStatus|string $state): string
    {
        $status = $state instanceof PaymentStatus ? $state : PaymentStatus::from($state);

        return match ($status) {
            PaymentStatus::PendingApplication => 'gray',
            PaymentStatus::PartiallyApplied => 'warning',
            PaymentStatus::FullyApplied => 'success',
            PaymentStatus::Cancelled => 'danger',
        };
    }

    public static function getStatusBadgeColor(PaymentStatus|string $state): string
    {
        return static::getStatusColor($state);
    }

    public static function getStatusBadgeLabel(PaymentStatus|string $state): string
    {
        return static::getStatusLabel($state);
    }
}
