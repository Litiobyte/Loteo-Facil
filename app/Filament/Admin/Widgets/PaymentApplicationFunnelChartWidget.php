<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Payment;
use Filament\Widgets\ChartWidget;

class PaymentApplicationFunnelChartWidget extends ChartWidget
{
    protected static ?int $sort = 40;

    protected ?string $heading = 'Aplicacion de pagos';

    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 2,
    ];

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $baseQuery = Payment::query()
            ->where('status', '!=', PaymentStatus::Cancelled->value);

        $appliedAmount = (float) (clone $baseQuery)->sum('applied_amount');
        $unappliedAmount = (float) (clone $baseQuery)->sum('unapplied_amount');

        return [
            'datasets' => [
                [
                    'label' => 'Monto',
                    'data' => [
                        round($appliedAmount, 2),
                        round($unappliedAmount, 2),
                    ],
                    'backgroundColor' => [
                        '#16a34a',
                        '#d97706',
                    ],
                ],
            ],
            'labels' => ['Aplicado', 'No aplicado'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
