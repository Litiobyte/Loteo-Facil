<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\PartnerChargeResource;
use App\Filament\Admin\Resources\PaymentResource;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\Propietario;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminCollectionsSummaryWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $pendingBalance = (float) PartnerCharge::query()
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->sum('remaining_amount');

        $overdueBalance = (float) Expense::query()
            ->where('status', ExpenseStatus::Distributed->value)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->toDateString())
            ->selectRaw('COALESCE(SUM(CASE WHEN amount - funded_amount > 0 THEN amount - funded_amount ELSE 0 END), 0) as overdue_balance')
            ->value('overdue_balance');

        $activeOwnerIds = Propietario::query()
            ->join('lote_propietario', 'lote_propietario.propietario_id', '=', 'propietarios.id')
            ->join('lotes', 'lotes.id', '=', 'lote_propietario.lote_id')
            ->where('lote_propietario.status', 'active')
            ->where('lotes.estado', 'vendido')
            ->select('propietarios.id')
            ->distinct()
            ->pluck('id');

        $activeOwnersCount = $activeOwnerIds->count();

        $morososCount = $activeOwnersCount > 0
            ? (int) PartnerCharge::query()
                ->whereIn('propietario_id', $activeOwnerIds)
                ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', now()->toDateString())
                ->distinct('propietario_id')
                ->count('propietario_id')
            : 0;

        $ownersOnTime = max(0, $activeOwnersCount - $morososCount);
        $morosityRate = $activeOwnersCount > 0
            ? round(($morososCount / $activeOwnersCount) * 100, 2)
            : 0.0;

        $unappliedPayments = (float) Payment::query()
            ->whereIn('status', [PaymentStatus::PendingApplication->value, PaymentStatus::PartiallyApplied->value])
            ->sum('unapplied_amount');

        $saldoCaja = app(CashBalanceService::class)->getAvailableCash();

        return [
            Stat::make('Cartera pendiente', '$'.number_format($pendingBalance, 0, ',', '.'))
                ->description('Cobros pendientes o parciales')
                ->icon('heroicon-o-banknotes')
                ->color($pendingBalance > 0 ? 'warning' : 'success')
                ->url(PartnerChargeResource::getUrl('index', [], true, 'admin')),
            Stat::make('Cartera vencida', '$'.number_format($overdueBalance, 0, ',', '.'))
                ->description('Gastos vencidos pendientes de egreso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($overdueBalance > 0 ? 'danger' : 'success')
                ->url(ExpenseResource::getUrl('index', [], true, 'admin')),
            Stat::make('Morosidad', sprintf('%d/%d (%s%%)', $morososCount, $activeOwnersCount, number_format($morosityRate, 2, ',', '.')))
                ->description(sprintf('Morosos/activos · Al día: %d', $ownersOnTime))
                ->icon('heroicon-o-chart-bar-square')
                ->color($morosityRate >= 40 ? 'danger' : ($morosityRate >= 20 ? 'warning' : 'success')),
            Stat::make('Pagos por aplicar', '$'.number_format($unappliedPayments, 0, ',', '.'))
                ->description('Saldo de pagos no aplicado')
                ->icon('heroicon-o-arrow-path')
                ->color($unappliedPayments > 0 ? 'info' : 'gray')
                ->url(PaymentResource::getUrl('index', [], true, 'admin')),
            Stat::make('Monto en caja', '$'.number_format($saldoCaja, 0, ',', '.'))
                ->description('Ingresado - pagado desde caja')
                ->icon('heroicon-o-wallet')
                ->color($saldoCaja >= 0 ? 'success' : 'danger'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
