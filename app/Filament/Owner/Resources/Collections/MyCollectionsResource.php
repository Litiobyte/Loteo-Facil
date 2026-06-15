<?php

namespace App\Filament\Owner\Resources\Collections;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Owner\Resources\Collections\Pages\ListMyCollections;
use App\Filament\Owner\Resources\Collections\Pages\ViewCollection;
use App\Models\Collection;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MyCollectionsResource extends Resource
{
    protected static ?string $model = Collection::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?string $navigationLabel = 'Mis Recaudaciones';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Recaudacion';

    protected static ?string $pluralModelLabel = 'Mis Recaudaciones';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ViewCollection::buildInfolist($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['allocations.charge']))
            ->defaultSort('collection_date', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (mixed $state): string => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                    ->sortable(),
                TextColumn::make('collection_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('collection_method')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn (CollectionMethod|string $state): string => static::paymentMethodLabel($state))
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(Sum::make()->money('CLP', locale: 'es_CL')),
                TextColumn::make('applied_amount')
                    ->label('Aplicado')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable()
                    ->summarize(Sum::make()->money('CLP', locale: 'es_CL')),
                TextColumn::make('unapplied_amount')
                    ->label('No Aplicado')
                    ->money('CLP', locale: 'es_CL')
                    ->badge()
                    ->color(fn (float|string|null $state): string => (float) $state > 0 ? 'success' : 'gray')
                    ->formatStateUsing(function (Collection $record): string {
                        if ((float) $record->unapplied_amount > 0) {
                            return number_format((float) $record->unapplied_amount, 2, ',', '.').' CLP (Saldo a favor)';
                        }

                        return number_format((float) $record->unapplied_amount, 2, ',', '.').' CLP';
                    })
                    ->sortable()
                    ->summarize(Sum::make()->money('CLP', locale: 'es_CL')),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (CollectionStatus|string $state): string => static::statusLabel($state))
                    ->color(fn (CollectionStatus|string $state): string => static::statusColor($state)),
                TextColumn::make('reference')
                    ->label('Referencia')
                    ->toggleable(),
                TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->multiple()
                    ->options([
                        CollectionStatus::PendingApplication->value => 'Pendiente aplicación',
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
                    ->label('Rango de fechas')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false),
                        DatePicker::make('to')->label('Hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('collection_date', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('collection_date', '<=', $date));
                    }),
                Filter::make('quick_last_month')
                    ->label('Último mes')
                    ->query(fn (Builder $query): Builder => $query->whereDate('collection_date', '>=', now()->subMonthNoOverflow()->startOfDay()->toDateString())),
                Filter::make('quick_last_three_months')
                    ->label('Últimos 3 meses')
                    ->query(fn (Builder $query): Builder => $query->whereDate('collection_date', '>=', now()->subMonthsNoOverflow(3)->startOfDay()->toDateString())),
                Filter::make('quick_this_year')
                    ->label('Este año')
                    ->query(fn (Builder $query): Builder => $query->whereYear('collection_date', now()->year)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Sin pagos registrados')
            ->emptyStateDescription('Cuando registremos pagos asociados a tus cobros, aparecerán aquí.');
    }

    public static function getEloquentQuery(): Builder
    {
        $propietarioId = Auth::user()?->propietario?->id;

        if (! $propietarioId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where('propietario_id', $propietarioId);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Collection
            && $record->propietario_id === Auth::user()?->propietario?->id;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyCollections::route('/'),
            'view' => ViewCollection::route('/{record}'),
        ];
    }

    public static function paymentMethodLabel(CollectionMethod|string $state): string
    {
        $method = $state instanceof CollectionMethod ? $state : CollectionMethod::from($state);

        return $method->getLabel();
    }

    public static function statusLabel(CollectionStatus|string $state): string
    {
        $status = $state instanceof CollectionStatus ? $state : CollectionStatus::from($state);

        return match ($status) {
            CollectionStatus::PendingApplication => 'Pendiente aplicación',
            CollectionStatus::PartiallyApplied => 'Parcialmente aplicado',
            CollectionStatus::FullyApplied => 'Totalmente aplicado',
            CollectionStatus::Cancelled => 'Cancelado',
        };
    }

    public static function statusColor(CollectionStatus|string $state): string
    {
        $status = $state instanceof CollectionStatus ? $state : CollectionStatus::from($state);

        return match ($status) {
            CollectionStatus::PendingApplication => 'gray',
            CollectionStatus::PartiallyApplied => 'warning',
            CollectionStatus::FullyApplied => 'success',
            CollectionStatus::Cancelled => 'danger',
        };
    }
}
