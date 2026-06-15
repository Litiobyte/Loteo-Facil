<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Lote;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LotsSummaryStatWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 15;

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $soldLotsCount = Lote::query()->where('estado', 'vendido')->count();
        $availableLotsCount = Lote::query()->where('estado', 'disponible')->count();
        $reservedLotsCount = Lote::query()->where('estado', 'reservado')->count();
        $totalLotsCount = $soldLotsCount + $availableLotsCount + $reservedLotsCount;

        return [
            Stat::make('Lotes', number_format($totalLotsCount, 0, ',', '.'))
                ->description(sprintf('Vendidos: %d | Disponibles: %d | Reservados: %d', $soldLotsCount, $availableLotsCount, $reservedLotsCount))
                ->icon('heroicon-o-map')
                ->color('info'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
