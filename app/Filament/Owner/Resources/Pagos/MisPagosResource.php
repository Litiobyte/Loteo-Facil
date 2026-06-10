<?php

namespace App\Filament\Owner\Resources\Pagos;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Filament\Owner\Resources\Pagos\Pages\ListMisPagos;
use App\Filament\Owner\Resources\Pagos\Pages\ViewPago;
use App\Models\Payment;
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

class MisPagosResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?string $navigationLabel = 'Mis Pagos';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Pago';

    protected static ?string $pluralModelLabel = 'Mis Pagos';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ViewPago::buildInfolist($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['allocations.charge']))
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (mixed $state): string => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                    ->sortable(),
                TextColumn::make('payment_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod|string $state): string => static::paymentMethodLabel($state))
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
                    ->formatStateUsing(function (Payment $record): string {
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
                    ->formatStateUsing(fn (PaymentStatus|string $state): string => static::statusLabel($state))
                    ->color(fn (PaymentStatus|string $state): string => static::statusColor($state)),
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
                        PaymentStatus::PendingApplication->value => 'Pendiente aplicación',
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
                    ->label('Rango de fechas')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false),
                        DatePicker::make('to')->label('Hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('payment_date', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('payment_date', '<=', $date));
                    }),
                Filter::make('quick_last_month')
                    ->label('Último mes')
                    ->query(fn (Builder $query): Builder => $query->whereDate('payment_date', '>=', now()->subMonthNoOverflow()->startOfDay()->toDateString())),
                Filter::make('quick_last_three_months')
                    ->label('Últimos 3 meses')
                    ->query(fn (Builder $query): Builder => $query->whereDate('payment_date', '>=', now()->subMonthsNoOverflow(3)->startOfDay()->toDateString())),
                Filter::make('quick_this_year')
                    ->label('Este año')
                    ->query(fn (Builder $query): Builder => $query->whereYear('payment_date', now()->year)),
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
        return $record instanceof Payment
            && $record->propietario_id === Auth::user()?->propietario?->id;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMisPagos::route('/'),
            'view' => ViewPago::route('/{record}'),
        ];
    }

    public static function paymentMethodLabel(PaymentMethod|string $state): string
    {
        $method = $state instanceof PaymentMethod ? $state : PaymentMethod::from($state);

        return $method->getLabel();
    }

    public static function statusLabel(PaymentStatus|string $state): string
    {
        $status = $state instanceof PaymentStatus ? $state : PaymentStatus::from($state);

        return match ($status) {
            PaymentStatus::PendingApplication => 'Pendiente aplicación',
            PaymentStatus::PartiallyApplied => 'Parcialmente aplicado',
            PaymentStatus::FullyApplied => 'Totalmente aplicado',
            PaymentStatus::Cancelled => 'Cancelado',
        };
    }

    public static function statusColor(PaymentStatus|string $state): string
    {
        $status = $state instanceof PaymentStatus ? $state : PaymentStatus::from($state);

        return match ($status) {
            PaymentStatus::PendingApplication => 'gray',
            PaymentStatus::PartiallyApplied => 'warning',
            PaymentStatus::FullyApplied => 'success',
            PaymentStatus::Cancelled => 'danger',
        };
    }
}
