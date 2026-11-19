<?php

namespace App\Filament\Owner\Resources\ShareHoldingResource\Pages;

use App\Filament\Owner\Resources\Lotes\LoteResource;
use App\Filament\Owner\Resources\ShareHoldingResource;
use App\Models\CommunitySetting;
use App\Models\ShareHolding;
use App\Support\ChileanNumber;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListMyShares extends ListRecords
{
    protected static string $resource = ShareHoldingResource::class;

    protected string $view = 'filament.owner.pages.mis-participacion';

    public function getSubheading(): ?string
    {
        $setting = CommunitySetting::current();

        $intro = $setting->usesShares() && $setting->usesLots()
            ? 'Consulta por separado tus acciones y tus terrenos asignados.'
            : ($setting->usesShares() ? 'Participación registrada mediante acciones, sin lotes asignados.' : 'Consulta los terrenos y superficies que tienes asignados.');

        return $intro.' '.$this->participationSummary();
    }

    /** @return array{count: int, square_meters: string} */
    public function lotSummary(): array
    {
        $row = LoteResource::getEloquentQuery()
            ->selectRaw('COUNT(*) as lot_count, COALESCE(SUM(lotes.metros_cuadrados), 0) as square_meters')
            ->first();

        return ['count' => (int) ($row?->lot_count ?? 0), 'square_meters' => (string) ($row?->square_meters ?? '0')];
    }

    protected function getViewData(): array
    {
        $setting = CommunitySetting::current();
        $totals = auth()->user()?->propietario?->shareTotals() ?? ['quantity' => '0', 'square_meters' => '0'];
        $lots = $setting->usesLots() ? LoteResource::getEloquentQuery()->with('etapa')->orderBy('codigo')->get() : collect();
        $lotSummary = $this->lotSummary();

        return [
            'usesShares' => $setting->usesShares(),
            'usesLots' => $setting->usesLots(),
            'communityName' => CommunitySetting::displayName(),
            'shares' => $totals,
            'lots' => $lots,
            'lotCount' => $lotSummary['count'],
            'lotSquareMeters' => $lotSummary['square_meters'],
            'lotHistory' => $setting->usesLots() ? $this->lotHistory() : collect(),
        ];
    }

    private function participationSummary(): string
    {
        $setting = CommunitySetting::current();
        $parts = [];

        if ($setting->usesShares()) {
            $totals = auth()->user()?->propietario?->shareTotals() ?? ['quantity' => '0', 'square_meters' => '0'];
            $parts[] = 'Acciones vigentes: '.ShareHolding::displayQuantity($totals['quantity']).' — Superficie equivalente: '.ChileanNumber::format($totals['square_meters']).' m²';
        }

        if ($setting->usesLots()) {
            $summary = $this->lotSummary();
            $parts[] = 'Lotes asignados: '.$summary['count'].' — Superficie: '.ChileanNumber::format($summary['square_meters']).' m²';
        }

        return implode(' · ', $parts);
    }

    /** @return Collection<int, object> */
    private function lotHistory(): Collection
    {
        $ownerId = auth()->user()?->propietario?->id;

        if ($ownerId === null) {
            return collect();
        }

        return DB::table('lote_propietario')
            ->join('lotes', 'lotes.id', '=', 'lote_propietario.lote_id')
            ->where('lote_propietario.propietario_id', $ownerId)
            ->orderByDesc('lote_propietario.assigned_at')
            ->get([
                'lotes.codigo',
                'lotes.metros_cuadrados',
                'lote_propietario.status',
                'lote_propietario.assigned_at',
                'lote_propietario.unassigned_at',
            ]);
    }
}
