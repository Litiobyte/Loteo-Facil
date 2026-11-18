<?php

namespace App\Filament\Owner\Pages;

use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\CommunitySetting;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $navigationLabel = 'Mi comunidad';

    protected static ?string $title = 'Mi comunidad';

    protected string $view = 'filament.owner.pages.dashboard';

    public string $movementFilter = 'all';

    #[Locked]
    public int $movementLimit = 5;

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function filterMovements(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'charge', 'payment', 'allocation'], true), 422);

        $this->movementFilter = $filter;
        $this->movementLimit = 5;
    }

    public function showMoreMovements(): void
    {
        $this->movementLimit += 10;
    }

    protected function getViewData(): array
    {
        $owner = Auth::user()?->propietario;
        $settings = CommunitySetting::query()->find(1);
        $statement = $owner ? app(PartnerStatementService::class)->generateStatement($owner) : null;
        $movements = $statement
            ? $statement['timeline']->filter(fn (array $movement): bool => $this->movementFilter === 'all' || $movement['type'] === $this->movementFilter)
            : collect();
        $pending = $statement
            ? $statement['charges']->filter(fn ($charge): bool => in_array($charge->status->value, ['pending', 'partial'], true) && (float) $charge->remaining_amount > 0)
            : collect();

        return [
            'owner' => $owner,
            'communityName' => CommunitySetting::displayName(),
            'usesShares' => $settings?->usesShares() ?? false,
            'usesLots' => $settings?->usesLots() ?? true,
            'summary' => $statement['summary'] ?? [],
            'lots' => $statement['lots'] ?? collect(),
            'shares' => $owner?->shareTotals() ?? ['quantity' => '0', 'square_meters' => '0'],
            'pendingCharges' => $pending->sortBy(fn ($charge): string => $charge->due_date?->toDateString() ?? '9999-12-31')->take(4),
            'pendingCount' => $pending->count(),
            'movements' => $movements->take($this->movementLimit),
            'movementCount' => $movements->count(),
        ];
    }
}
