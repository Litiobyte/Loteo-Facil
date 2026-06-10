<?php

namespace App\Filament\Owner\Widgets;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Models\PartnerCharge;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class NextPaymentEstimateWidget extends Widget
{
    protected static ?int $sort = 30;

    protected string $view = 'filament.owner.widgets.next-payment-estimate-widget';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $propietarioId = Auth::user()?->propietario?->id;

        if (! $propietarioId) {
            return [
                'hasCharges' => false,
            ];
        }

        $nextMonthStart = now()->addMonthNoOverflow()->startOfMonth();
        $nextMonthEnd = now()->addMonthNoOverflow()->endOfMonth();

        $baseQuery = PartnerCharge::query()
            ->where('propietario_id', $propietarioId)
            ->whereBetween('due_date', [$nextMonthStart->toDateString(), $nextMonthEnd->toDateString()])
            ->whereIn('status', [
                ChargeStatus::Pending->value,
                ChargeStatus::Partial->value,
            ])
            ->orderBy('due_date')
            ->orderBy('id');

        $count = (clone $baseQuery)->count();

        if ($count === 0) {
            return [
                'hasCharges' => false,
            ];
        }

        return [
            'hasCharges' => true,
            'charges' => (clone $baseQuery)
                ->limit(5)
                ->get(['id', 'description', 'remaining_amount', 'due_date']),
            'remainingCount' => max(0, $count - 5),
            'totalAmount' => (float) (clone $baseQuery)->sum('remaining_amount'),
            'nearestDueDate' => (clone $baseQuery)->value('due_date'),
            'chargesUrl' => MisCobrosResource::getUrl(
                'index',
                ['tableFilters[quick_next_month][isActive]' => true],
                true,
                'owner'
            ),
        ];
    }
}
