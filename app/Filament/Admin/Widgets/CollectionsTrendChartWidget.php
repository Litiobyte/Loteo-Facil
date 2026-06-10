<?php

namespace App\Filament\Admin\Widgets;

use App\Models\PartnerCharge;
use App\Models\PaymentAllocation;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class CollectionsTrendChartWidget extends ChartWidget
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Cobros vs recuperacion (ultimos 6 meses)';

    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 2,
    ];

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $months = $this->getLastSixMonths();

        $emittedByMonth = PartnerCharge::query()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [
                $months[0]['start']->copy()->startOfDay(),
                $months[5]['end']->copy()->endOfDay(),
            ])
            ->selectRaw("strftime('%Y-%m', due_date) as month_key")
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('month_key')
            ->pluck('total_amount', 'month_key');

        $recoveredByMonth = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereBetween('payments.payment_date', [
                $months[0]['start']->copy()->startOfDay(),
                $months[5]['end']->copy()->endOfDay(),
            ])
            ->selectRaw("strftime('%Y-%m', payments.payment_date) as month_key")
            ->selectRaw('COALESCE(SUM(payment_allocations.amount), 0) as total_amount')
            ->groupBy('month_key')
            ->pluck('total_amount', 'month_key');

        $labels = [];
        $emittedSeries = [];
        $recoveredSeries = [];

        foreach ($months as $month) {
            $monthKey = $month['key'];
            $labels[] = $month['label'];
            $emittedSeries[] = round((float) ($emittedByMonth[$monthKey] ?? 0), 2);
            $recoveredSeries[] = round((float) ($recoveredByMonth[$monthKey] ?? 0), 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Cobros emitidos',
                    'data' => $emittedSeries,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Cobros recuperados',
                    'data' => $recoveredSeries,
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<int, array{key: string, label: string, start: Carbon, end: Carbon}>
     */
    private function getLastSixMonths(): array
    {
        $start = now()->subMonths(5)->startOfMonth();
        $months = [];

        for ($i = 0; $i < 6; $i++) {
            $current = $start->copy()->addMonths($i);

            $months[] = [
                'key' => $current->format('Y-m'),
                'label' => $current->translatedFormat('M y'),
                'start' => $current->copy()->startOfMonth(),
                'end' => $current->copy()->endOfMonth(),
            ];
        }

        return $months;
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
