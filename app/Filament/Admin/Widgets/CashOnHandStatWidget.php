<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Balances\Services\CashBalanceService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashOnHandStatWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 13;

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $saldoCaja = app(CashBalanceService::class)->getAvailableCash();

        return [
            Stat::make('Monto en caja', '$'.number_format($saldoCaja, 0, ',', '.'))
                ->description('Saldo disponible en la caja')
                ->icon('heroicon-o-wallet')
                ->color($saldoCaja >= 0 ? 'success' : 'danger'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
