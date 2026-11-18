<?php

namespace App\Filament\Resources\Propietarios\RelationManagers;

use App\Filament\Admin\Resources\ShareHoldingResource;
use App\Models\CommunitySetting;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShareHoldingsRelationManager extends RelationManager
{
    protected static string $relationship = 'shareHoldings';

    protected static ?string $title = 'Acciones e historial';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CommunitySetting::current()->usesShares() && ShareHoldingResource::canAccess();
    }

    public function table(Table $table): Table
    {
        return ShareHoldingResource::readOnlyTable($table)->recordActions([ShareHoldingResource::historyAction()])->filters([]);
    }
}
