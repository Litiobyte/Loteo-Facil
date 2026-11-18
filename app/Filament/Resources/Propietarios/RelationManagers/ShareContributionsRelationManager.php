<?php

namespace App\Filament\Resources\Propietarios\RelationManagers;

use App\Domain\Owners\Services\ShareContributionService;
use App\Filament\Admin\Resources\ShareContributionResource;
use App\Models\CommunitySetting;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShareContributionsRelationManager extends RelationManager
{
    protected static string $relationship = 'shareContributions';

    protected static ?string $title = 'Aportes e historial económico';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ShareContributionResource::canAccess() && (CommunitySetting::current()->usesShares() || $ownerRecord->shareContributions()->exists());
    }

    public function table(Table $table): Table
    {
        $summary = app(ShareContributionService::class)->summary($this->getOwnerRecord());
        $shares = $this->getOwnerRecord()->shareTotals();
        $status = $summary['has_unknown_history'] ? ' — Historial económico incompleto' : '';

        return ShareContributionResource::table($table)->filters([])->description(
            'Acciones vigentes: '.$shares['quantity'].' — Total aportado registrado: $'.number_format((float) $summary['recorded_amount'], 0, ',', '.').$status
        );
    }
}
