<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Owners\Services\ShareContributionService;
use App\Models\ShareContribution;
use App\Models\ShareHolding;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShareContributionsStatWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $contributionsCount = ShareContribution::query()->count();
        $contributionsTotal = (float) ShareContribution::query()->sum('amount');
        $sharesQuantity = ShareHolding::query()->sum('quantity');
        $ownersWithContributions = ShareContribution::query()->distinct()->count('propietario_id');
        $ownersWithoutInformation = count(app(ShareContributionService::class)->ownerIdsWithUnknownHistory());

        return [
            Stat::make('Aportes registrados', number_format($contributionsCount, 0, ',', '.'))
                ->description('$'.number_format($contributionsTotal, 0, ',', '.').' aportados')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Acciones registradas', ShareHolding::displayQuantity((string) $sharesQuantity))
                ->icon('heroicon-o-chart-pie')
                ->color('info'),
            Stat::make('Socios con aportes', number_format($ownersWithContributions, 0, ',', '.'))
                ->icon('heroicon-o-users')
                ->color('success'),
            Stat::make('Socios sin información', number_format($ownersWithoutInformation, 0, ',', '.'))
                ->description('Historial de aportes desconocido')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($ownersWithoutInformation > 0 ? 'warning' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }
}
