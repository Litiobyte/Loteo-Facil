<?php

namespace App\Filament\Owner\Resources;

use App\Filament\Owner\Resources\ShareHoldingResource\Pages\ListMyShares;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShareHoldingResource extends \App\Filament\Admin\Resources\ShareHoldingResource
{
    protected static ?string $navigationLabel = 'Mi participación';

    protected static ?int $navigationSort = 1;

    protected static ?string $pluralModelLabel = 'Mi participación';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('propietario') && auth()->user()?->propietario !== null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('propietario_id', auth()->user()?->propietario?->id ?? 0);
    }

    public static function table(Table $table): Table
    {
        return static::readOnlyTable($table)->recordActions([static::historyAction()])->filters([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMyShares::route('/')];
    }
}
