<?php

namespace App\Filament\Owner\Widgets;

use App\Domain\Balances\Services\PartnerBalanceService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class FinancialSummaryWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 20;

    protected function getStats(): array
    {
        $propietario = Auth::user()?->propietario;

        if (! $propietario) {
            return [
                Stat::make('Total Adeudado', '$0')
                    ->description('Sin propietario asociado')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('gray'),
            ];
        }

        $summary = app(PartnerBalanceService::class)->getBalanceSummary($propietario);

        return [
            Stat::make('Total Adeudado', Number::currency((float) $summary['pending_balance'], 'CLP', locale: 'es_CL'))
                ->icon('heroicon-o-currency-dollar')
                ->color((float) $summary['pending_balance'] > 0 ? 'danger' : 'success'),
            Stat::make('Saldo a Favor', Number::currency((float) $summary['credit_balance'], 'CLP', locale: 'es_CL'))
                ->icon('heroicon-o-banknotes')
                ->color((float) $summary['credit_balance'] > 0 ? 'success' : 'gray'),
            Stat::make('Balance Neto', Number::currency((float) $summary['net_balance'], 'CLP', locale: 'es_CL'))
                ->icon('heroicon-o-chart-bar')
                ->color((float) $summary['net_balance'] < 0 ? 'danger' : 'success'),
            Stat::make('Mis Hectáreas', number_format((float) $summary['total_hectares'], 4, ',', '.').' ha')
                ->icon('heroicon-o-map')
                ->color('gray'),
        ];
    }
}
