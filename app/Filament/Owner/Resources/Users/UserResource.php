<?php

namespace App\Filament\Owner\Resources\Users;

use App\Filament\Owner\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Mi perfil';

    protected static string|UnitEnum|null $navigationGroup = 'Mis Datos';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Perfil';

    protected static ?string $pluralModelLabel = 'Perfiles';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre'),
                TextColumn::make('email')
                    ->label('Email'),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
            ])
            ->toolbarActions([
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('roles')
            ->whereKey(Auth::id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
