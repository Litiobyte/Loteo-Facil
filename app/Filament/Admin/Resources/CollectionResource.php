<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Filament\Admin\Resources\CollectionResource\Pages\CreateCollection;
use App\Filament\Admin\Resources\CollectionResource\Pages\EditCollection;
use App\Filament\Admin\Resources\CollectionResource\Pages\ListCollections;
use App\Filament\Admin\Resources\CollectionResource\Pages\ViewCollection;
use App\Filament\Admin\Resources\CollectionResource\RelationManagers\AllocationsRelationManager;
use App\Models\Collection;
use App\Models\PartnerCharge;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class CollectionResource extends Resource
{
    protected static ?string $model = Collection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Recaudaciones';

    protected static ?string $modelLabel = 'Recaudacion';

    protected static ?string $pluralModelLabel = 'Recaudaciones';

    protected static string|UnitEnum|null $navigationGroup = 'Gastos Comunes';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del Recaudacion')
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
                        DatePicker::make('collection_date')
                            ->label('Fecha de pago')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->required()
                            ->validationMessages([
                                'required' => 'La fecha de pago es obligatoria.',
                                'max' => 'La fecha de pago no puede ser futura.',
                            ]),
                        Select::make('collection_method')
                            ->label('Método de pago')
                            ->native(false)
                            ->required()
                            ->options([
                                CollectionMethod::Efectivo->value => CollectionMethod::Efectivo->getLabel(),
                                CollectionMethod::Transferencia->value => CollectionMethod::Transferencia->getLabel(),
                                CollectionMethod::Cheque->value => CollectionMethod::Cheque->getLabel(),
                                CollectionMethod::Otro->value => CollectionMethod::Otro->getLabel(),
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
                Section::make('Aplicación del Recaudacion')
                    ->visibleOn('edit')
                    ->columns(3)
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->native(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->options([
                                CollectionStatus::PendingApplication->value => 'Pendiente de aplicación',
                                CollectionStatus::PartiallyApplied->value => 'Parcialmente aplicado',
                                CollectionStatus::FullyApplied->value => 'Totalmente aplicado',
                                CollectionStatus::Cancelled->value => 'Cancelado',
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
                Section::make('Resumen del Recaudacion')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('propietario.nombre_completo')
                            ->label('Propietario'),
                        TextEntry::make('collection_date')
                            ->label('Fecha de pago')
                            ->date('d/m/Y'),
                        TextEntry::make('collection_method')
                            ->label('Método')
                            ->formatStateUsing(fn (CollectionMethod|string $state): string => $state instanceof CollectionMethod ? $state->getLabel() : CollectionMethod::from($state)->getLabel()),
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
                            ->formatStateUsing(fn (CollectionStatus|string $state): string => static::getStatusLabel($state))
                            ->color(fn (CollectionStatus|string $state): string => static::getStatusColor($state)),
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
            ->defaultSort('collection_date', 'desc')
            ->columns([
                TextColumn::make('propietario.nombre')
                    ->label('Propietario')
                    ->state(fn (Collection $record): string => $record->propietario?->nombre_completo ?? '-')
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
                TextColumn::make('collection_date')
                    ->label('Fecha de pago')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('collection_method')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn (CollectionMethod|string $state): string => $state instanceof CollectionMethod ? $state->getLabel() : CollectionMethod::from($state)->getLabel())
                    ->color(fn (CollectionMethod|string $state): string => match ($state instanceof CollectionMethod ? $state : CollectionMethod::from($state)) {
                        CollectionMethod::Efectivo => 'success',
                        CollectionMethod::Transferencia => 'info',
                        CollectionMethod::Cheque => 'warning',
                        CollectionMethod::Otro => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (CollectionStatus|string $state): string => static::getStatusLabel($state))
                    ->color(fn (CollectionStatus|string $state): string => static::getStatusColor($state)),
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
                        CollectionStatus::PendingApplication->value => 'Pendiente de aplicación',
                        CollectionStatus::PartiallyApplied->value => 'Parcialmente aplicado',
                        CollectionStatus::FullyApplied->value => 'Totalmente aplicado',
                        CollectionStatus::Cancelled->value => 'Cancelado',
                    ]),
                SelectFilter::make('collection_method')
                    ->label('Método de pago')
                    ->multiple()
                    ->options([
                        CollectionMethod::Efectivo->value => CollectionMethod::Efectivo->getLabel(),
                        CollectionMethod::Transferencia->value => CollectionMethod::Transferencia->getLabel(),
                        CollectionMethod::Cheque->value => CollectionMethod::Cheque->getLabel(),
                        CollectionMethod::Otro->value => CollectionMethod::Otro->getLabel(),
                    ]),
                Filter::make('collection_date_range')
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
                                fn (Builder $query, string $date): Builder => $query->whereDate('collection_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('collection_date', '<=', $date),
                            );
                    }),
                Filter::make('last_month_collections')
                    ->label('Recaudaciones del mes pasado')
                    ->query(function (Builder $query): Builder {
                        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
                        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

                        return $query->whereBetween('collection_date', [$lastMonthStart, $lastMonthEnd]);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('apply_automatically')
                    ->label('Aplicar automáticamente')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->visible(fn (Collection $record): bool => static::canApplyCollection($record))
                    ->requiresConfirmation()
                    ->modalHeading('Aplicar pago automáticamente')
                    ->modalDescription(fn (Collection $record): string => static::buildAutomaticPreviewDescription($record))
                    ->modalSubmitActionLabel('Aplicar pago')
                    ->action(function (Collection $record): void {
                        try {
                            $allocationCount = static::applyAutomatically($record);

                            Notification::make()
                                ->title('Recaudacion aplicado correctamente')
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
                    ->visible(fn (Collection $record): bool => static::canApplyCollection($record))
                    ->modalHeading('Aplicar pago manualmente')
                    ->modalDescription(fn (Collection $record): string => 'Saldo disponible para aplicar: '.number_format((float) $record->unapplied_amount, 2, ',', '.').' CLP')
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
                                    ->options(fn (Collection $record): array => static::getAvailableChargeOptions($record))
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
                    ->action(function (Collection $record, array $data): void {
                        try {
                            $allocationCount = static::applyManually($record, $data['allocations'] ?? []);

                            Notification::make()
                                ->title('Recaudacion aplicado manualmente')
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
                    ->visible(fn (Collection $record): bool => static::canEdit($record)),
                Action::make('cancel_payment')
                    ->label('Cancelar pago')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Collection $record): bool => $record->status !== CollectionStatus::Cancelled)
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar pago')
                    ->modalDescription('Esta acción marcará el pago como cancelado y bloqueará su edición.')
                    ->action(function (Collection $record): void {
                        static::cancelCollection($record);

                        Notification::make()
                            ->title('Recaudacion cancelado')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (Collection $record): bool => static::canDelete($record)),
            ]);
    }

    /**
     * @param  array<int, array{charge_id?: int|string|null, amount?: float|int|string|null}>  $allocations
     */
    public static function applyManually(Collection $payment, array $allocations): int
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

        $service = app(CollectionApplicationService::class);
        $createdAllocations = $service->applyCollectionManually($payment, $normalized);

        return $createdAllocations->count();
    }

    public static function applyAutomatically(Collection $payment): int
    {
        $service = app(CollectionApplicationService::class);
        $createdAllocations = $service->applyCollectionAutomatically($payment);

        return $createdAllocations->count();
    }

    public static function cancelCollection(Collection $payment): void
    {
        $payment->update([
            'status' => CollectionStatus::Cancelled,
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
            && $record instanceof Collection
            && in_array($record->status, [CollectionStatus::PendingApplication, CollectionStatus::PartiallyApplied], true);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof Collection
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
            'index' => ListCollections::route('/'),
            'create' => CreateCollection::route('/create'),
            'view' => ViewCollection::route('/{record}'),
            'edit' => EditCollection::route('/{record}/edit'),
        ];
    }

    protected static function canApplyCollection(Collection $payment): bool
    {
        return in_array($payment->status, [CollectionStatus::PendingApplication, CollectionStatus::PartiallyApplied], true);
    }

    /**
     * @return array<int, string>
     */
    protected static function getAvailableChargeOptions(Collection $payment): array
    {
        return PartnerCharge::query()
            ->where('propietario_id', $payment->propietario_id)
            ->unpaid()
            ->orderByRaw('due_date IS NULL ASC, due_date ASC')
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

    protected static function buildAutomaticPreviewDescription(Collection $payment): string
    {
        $preview = app(CollectionApplicationService::class)->previewAutomaticAllocation($payment);

        if (($preview['allocations'] ?? []) === []) {
            return 'No hay cobros pendientes para aplicar automáticamente este pago.';
        }

        $rows = collect($preview['allocations'])
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

    protected static function getStatusLabel(CollectionStatus|string $state): string
    {
        $status = $state instanceof CollectionStatus ? $state : CollectionStatus::from($state);

        return match ($status) {
            CollectionStatus::PendingApplication => 'Pendiente de aplicación',
            CollectionStatus::PartiallyApplied => 'Parcialmente aplicado',
            CollectionStatus::FullyApplied => 'Totalmente aplicado',
            CollectionStatus::Cancelled => 'Cancelado',
        };
    }

    protected static function getStatusColor(CollectionStatus|string $state): string
    {
        $status = $state instanceof CollectionStatus ? $state : CollectionStatus::from($state);

        return match ($status) {
            CollectionStatus::PendingApplication => 'gray',
            CollectionStatus::PartiallyApplied => 'warning',
            CollectionStatus::FullyApplied => 'success',
            CollectionStatus::Cancelled => 'danger',
        };
    }

    public static function getStatusBadgeColor(CollectionStatus|string $state): string
    {
        return static::getStatusColor($state);
    }

    public static function getStatusBadgeLabel(CollectionStatus|string $state): string
    {
        return static::getStatusLabel($state);
    }
}
