<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Admin\Resources\PartnerChargeResource;
use App\Models\PartnerCharge;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingPortfolioStatWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 14;

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $pendingBalance = (float) PartnerCharge::query()
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->sum('remaining_amount');

        return [
            Stat::make('Cartera pendiente', '$'.number_format($pendingBalance, 0, ',', '.'))
                ->description('Cobros pendientes o parciales')
                ->icon('heroicon-o-banknotes')
                ->color($pendingBalance > 0 ? 'warning' : 'success')
                ->url(PartnerChargeResource::getUrl('index', [], true, 'admin')),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
