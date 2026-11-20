<?php

namespace App\Filament\Resources\Propietarios\RelationManagers;

use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AssociatedUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'secondaryUsers';

    protected static ?string $title = 'Usuarios asociados';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PropietarioResource::canView($ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()
                ->where(fn (Builder $query): Builder => $query
                    ->whereKey($this->getOwnerRecord()->user_id)
                    ->orWhere('secondary_propietario_id', $this->getOwnerRecord()->getKey())))
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('association_type')
                    ->label('Tipo')
                    ->state(fn (User $record): string => $record->getKey() === $this->getOwnerRecord()->user_id ? 'Principal' : 'Secundario')
                    ->badge(),
            ])
            ->recordUrl(null)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
