<?php

namespace App\Filament\Owner\Widgets;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Models\PartnerCharge;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class OverdueAlertsWidget extends Widget
{
    protected static ?int $sort = 15;

    protected string $view = 'filament.owner.widgets.overdue-alerts-widget';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $propietarioId = Auth::user()?->propietario?->id;

        if (! $propietarioId) {
            return [
                'hasAlert' => false,
            ];
        }

        $overdueQuery = PartnerCharge::query()
            ->where('propietario_id', $propietarioId)
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('status', [
                ChargeStatus::Pending->value,
                ChargeStatus::Partial->value,
            ]);

        $count = (clone $overdueQuery)->count();

        if ($count === 0) {
            return [
                'hasAlert' => false,
            ];
        }

        return [
            'hasAlert' => true,
            'count' => $count,
            'totalAmount' => (float) (clone $overdueQuery)->sum('remaining_amount'),
            'oldestDueDate' => (clone $overdueQuery)->orderBy('due_date')->value('due_date'),
            'overdueUrl' => MisCobrosResource::getUrl(
                'index',
                ['tableFilters[quick_overdue][isActive]' => true],
                true,
                'owner'
            ),
        ];
    }
}
