<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverduePortfolioStatWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $overdueBalance = (float) Expense::query()
            ->where('status', ExpenseStatus::Distributed->value)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->toDateString())
            ->selectRaw('COALESCE(SUM(CASE WHEN amount - funded_amount > 0 THEN amount - funded_amount ELSE 0 END), 0) as overdue_balance')
            ->value('overdue_balance');

        return [
            Stat::make('Cartera vencida', '$'.number_format($overdueBalance, 0, ',', '.'))
                ->description('Gastos vencidos pendientes de egreso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($overdueBalance > 0 ? 'danger' : 'success')
                ->url(ExpenseResource::getUrl('index', [], true, 'admin')),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
