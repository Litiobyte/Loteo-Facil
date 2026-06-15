<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\InteractsWithOwnerDelinquencyMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DelinquentOwnersStatWidget extends StatsOverviewWidget
{
    use InteractsWithOwnerDelinquencyMetrics;

    protected static ?int $sort = 12;

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $metrics = $this->getOwnerDelinquencyMetrics();

        return [
            Stat::make('Propietarios morosos', number_format($metrics['delinquent_owners_count'], 0, ',', '.'))
                ->description('Propietarios con cobros pendientes o parciales')
                ->icon('heroicon-o-user-minus')
                ->color($metrics['delinquent_owners_count'] > 0 ? 'danger' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
