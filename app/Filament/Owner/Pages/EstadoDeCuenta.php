<?php

namespace App\Filament\Owner\Pages;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Models\PartnerCharge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EstadoDeCuenta extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?string $navigationLabel = 'Estado de Cuenta';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.owner.pages.estado-de-cuenta';

    public ?string $desde = null;

    public ?string $hasta = null;

    public string $quickFilter = 'all';

    /** @var array<int, array{month: string, charges: float, collections: float, balance: float, trend: string}> */
    public array $monthComparison = [];

    public function mount(): void
    {
        $this->setQuickFilter('all');
        $this->monthComparison = $this->calculateMonthComparison();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->propietario !== null;
    }

    public function updated(string $name): void
    {
        if (! in_array($name, ['desde', 'hasta'], true)) {
            return;
        }

        $this->quickFilter = 'custom';
        $this->monthComparison = $this->calculateMonthComparison();
        $this->resetTable();
    }

    public function setQuickFilter(string $key): void
    {
        $this->quickFilter = $key;

        if ($key === 'this_month') {
            $this->desde = now()->startOfMonth()->toDateString();
            $this->hasta = now()->endOfMonth()->toDateString();

        } elseif ($key === 'last_three_months') {
            $this->desde = now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
            $this->hasta = now()->endOfMonth()->toDateString();

        } elseif ($key === 'last_six_months') {
            $this->desde = now()->subMonthsNoOverflow(5)->startOfMonth()->toDateString();
            $this->hasta = now()->endOfMonth()->toDateString();

        } elseif ($key === 'this_year') {
            $this->desde = now()->startOfYear()->toDateString();
            $this->hasta = now()->endOfYear()->toDateString();

        } else {
            $this->desde = null;
            $this->hasta = null;
        }

        $this->monthComparison = $this->calculateMonthComparison();
        $this->resetTable();
    }

    protected function getViewData(): array
    {
        $propietario = Auth::user()?->propietario;

        if (! $propietario) {
            return [
                'statement' => null,
                'debtByCategory' => collect(),
                'pendingCharges' => collect(),
                'resumen' => [],
                'desglose' => [],
                'timeline' => collect(),
                'monthComparison' => [],
            ];
        }

        $from = $this->desde ? Carbon::parse($this->desde)->startOfDay() : null;
        $to = $this->hasta ? Carbon::parse($this->hasta)->endOfDay() : null;

        $statement = app(PartnerStatementService::class)->generateStatement($propietario, $from, $to);
        $summary = $statement['summary'];

        $resumen = [
            'cobrado' => (float) $summary['total_charges'],
            'pagado' => (float) $summary['total_collections'],
            'aplicado' => (float) $summary['total_applied'],
            'pendiente' => (float) $summary['pending_balance'],
            'disponible' => (float) $summary['credit_balance'],
            'balance' => (float) $summary['net_balance'],
        ];

        $debtByCategory = $statement['charges']
            ->filter(fn ($charge): bool => (float) $charge->remaining_amount > 0)
            ->groupBy(fn ($charge): string => $charge->expense?->category?->name ?? 'Sin categoría')
            ->map(fn ($items): float => round((float) $items->sum('remaining_amount'), 2))
            ->filter(fn (float $amount): bool => $amount > 0)
            ->sortDesc();

        $desglose = $debtByCategory
            ->map(fn (float $amount, string $category): array => [
                'categoria' => $category,
                'monto' => $amount,
            ])
            ->values()
            ->all();

        $pendingCharges = $statement['charges']
            ->filter(fn ($charge): bool => in_array($charge->status->value, ['pending', 'partial'], true))
            ->sortBy('due_date')
            ->values();

        return [
            'statement' => $statement,
            'debtByCategory' => $debtByCategory,
            'pendingCharges' => $pendingCharges,
            'resumen' => $resumen,
            'desglose' => $desglose,
            'timeline' => $statement['timeline']->take(20),
            'monthComparison' => $this->monthComparison,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $propietarioId = Auth::user()?->propietario?->id;

                $query = PartnerCharge::query()
                    ->where('propietario_id', $propietarioId)
                    ->where('remaining_amount', '>', 0)
                    ->orderBy('due_date');

                $query
                    ->when(
                        $this->desde,
                        fn (Builder $query): Builder => $query->whereDate('due_date', '>=', Carbon::parse($this->desde)->toDateString())
                    )
                    ->when(
                        $this->hasta,
                        fn (Builder $query): Builder => $query->whereDate('due_date', '<=', Carbon::parse($this->hasta)->toDateString())
                    );

                return $query;
            })
            ->columns([
                TextColumn::make('description')
                    ->label('Concepto')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state ?: 'Cobro sin descripción'),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(function (ChargeStatus|string $state): string {
                        $status = $state instanceof ChargeStatus ? $state : ChargeStatus::from($state);

                        return match ($status) {
                            ChargeStatus::Pending => 'Pendiente',
                            ChargeStatus::Partial => 'Parcial',
                            ChargeStatus::Paid => 'Pagado',
                            ChargeStatus::Cancelled => 'Cancelado',
                        };
                    })
                    ->color(function (ChargeStatus|string $state): string {
                        $status = $state instanceof ChargeStatus ? $state : ChargeStatus::from($state);

                        return match ($status) {
                            ChargeStatus::Pending => 'danger',
                            ChargeStatus::Partial => 'warning',
                            ChargeStatus::Paid => 'success',
                            ChargeStatus::Cancelled => 'gray',
                        };
                    }),
            ])
            ->recordActions([
                Action::make('ver_detalle')
                    ->label('Ver detalle')
                    ->color('gray')
                    ->url(fn (PartnerCharge $record): string => MisCobrosResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('due_date', 'asc')
            ->paginationPageOptions([10])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay cobros en este período')
            ->emptyStateDescription('Prueba ajustando las fechas para ver otros cobros pendientes.');
    }

    /**
     * @return array<int, array{month: string, charges: float, collections: float, balance: float, trend: string}>
     */
    protected function calculateMonthComparison(): array
    {
        $propietario = Auth::user()?->propietario;

        if (! $propietario) {
            return [];
        }

        $comparison = [];
        $previousBalance = null;

        for ($i = 2; $i >= 0; $i--) {
            $monthStart = now()->startOfMonth()->subMonthsNoOverflow($i);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $statement = app(PartnerStatementService::class)->generateStatement($propietario, $monthStart, $monthEnd);
            $charges = round((float) $statement['charges']->sum('amount'), 2);
            $collections = round((float) $statement['collections']->sum('amount'), 2);
            $balance = round($charges - $collections, 2);

            $trend = 'neutral';

            if ($previousBalance !== null) {
                if ($balance > $previousBalance) {
                    $trend = 'up';
                } elseif ($balance < $previousBalance) {
                    $trend = 'down';
                }
            }

            $comparison[] = [
                'month' => $this->formatMonthLabel($monthStart),
                'charges' => $charges,
                'collections' => $collections,
                'balance' => $balance,
                'trend' => $trend,
            ];

            $previousBalance = $balance;
        }

        return $comparison;
    }

    protected function formatMonthLabel(Carbon $date): string
    {
        $months = [
            1 => 'Ene',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Abr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Ago',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dic',
        ];

        return $months[(int) $date->format('n')].' '.$date->format('Y');
    }
}
