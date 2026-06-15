<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\InteractsWithOwnerDelinquencyMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OwnersOnTimeStatWidget extends StatsOverviewWidget
{
    use InteractsWithOwnerDelinquencyMetrics;

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $metrics = $this->getOwnerDelinquencyMetrics();

        return [
            Stat::make('Propietarios al dia', number_format($metrics['owners_on_time_count'], 0, ',', '.'))
                ->description(sprintf('Propietarios activos con cobros pagados (%d activos)', $metrics['active_owners_count']))
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
