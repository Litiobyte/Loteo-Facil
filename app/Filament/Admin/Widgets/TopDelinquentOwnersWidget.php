<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Admin\Resources\PartnerChargeResource;
use App\Models\PartnerCharge;
use Filament\Widgets\Widget;

class TopDelinquentOwnersWidget extends Widget
{
    protected static ?int $sort = 21;

    protected string $view = 'filament.admin.widgets.top-delinquent-owners-widget';

    protected int|string|array $columnSpan = 4;

    protected ?string $pollingInterval = '60s';

    protected function getViewData(): array
    {
        $rows = PartnerCharge::query()
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->join('propietarios', 'propietarios.id', '=', 'partner_charges.propietario_id')
            ->groupBy('partner_charges.propietario_id', 'propietarios.nombre', 'propietarios.apellido')
            ->selectRaw('partner_charges.propietario_id as propietario_id')
            ->selectRaw("MAX(TRIM(propietarios.nombre || ' ' || propietarios.apellido)) as propietario_nombre")
            ->selectRaw('COUNT(partner_charges.id) as overdue_count')
            ->selectRaw('COALESCE(SUM(partner_charges.remaining_amount), 0) as overdue_total')
            ->orderByDesc('overdue_total')
            ->limit(10)
            ->get()
            ->map(function ($row): array {
                return [
                    'propietario_id' => (int) $row->propietario_id,
                    'propietario_nombre' => (string) $row->propietario_nombre,
                    'overdue_count' => (int) $row->overdue_count,
                    'overdue_total' => round((float) $row->overdue_total, 2),
                ];
            })
            ->all();

        return [
            'rows' => $rows,
            'chargesUrl' => PartnerChargeResource::getUrl('index', [], true, 'admin'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
