<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use Filament\Widgets\ChartWidget;

class OverdueAgingChartWidget extends ChartWidget
{
    protected static ?int $sort = 30;

    protected ?string $heading = 'Aging de cartera vencida';

    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 2,
    ];

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $overdueExpenses = Expense::query()
            ->whereNotNull('due_date')
            ->where('status', ExpenseStatus::Distributed->value)
            ->whereDate('due_date', '<=', now()->toDateString())
            ->get(['amount', 'funded_amount', 'due_date']);

        $buckets = [
            '1-30' => 0.0,
            '31-60' => 0.0,
            '61-90' => 0.0,
            '90+' => 0.0,
        ];

        foreach ($overdueExpenses as $expense) {
            if (! $expense->due_date) {
                continue;
            }

            if ($expense->due_date->isFuture()) {
                continue;
            }

            $daysOverdue = $expense->due_date->startOfDay()->diffInDays(now()->startOfDay());
            $remainingAmount = round(max((float) $expense->amount - (float) $expense->funded_amount, 0), 2);

            if ($remainingAmount <= 0) {
                continue;
            }

            if ($daysOverdue <= 30) {
                $buckets['1-30'] += $remainingAmount;
            } elseif ($daysOverdue <= 60) {
                $buckets['31-60'] += $remainingAmount;
            } elseif ($daysOverdue <= 90) {
                $buckets['61-90'] += $remainingAmount;
            } else {
                $buckets['90+'] += $remainingAmount;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Saldo vencido',
                    'data' => array_map(fn (float $amount): float => round($amount, 2), array_values($buckets)),
                    'backgroundColor' => [
                        '#fde68a',
                        '#fbbf24',
                        '#f97316',
                        '#dc2626',
                    ],
                ],
            ],
            'labels' => array_keys($buckets),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
