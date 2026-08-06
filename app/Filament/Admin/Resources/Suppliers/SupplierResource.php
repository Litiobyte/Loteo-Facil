<?php

namespace App\Filament\Admin\Resources\Suppliers;

use App\Filament\Admin\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Proveedores';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static string|UnitEnum|null $navigationGroup = 'Gastos';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('rut')
                    ->label('RUT')
                    ->required()
                    ->maxLength(20)
                    ->formatStateUsing(fn (?string $state): ?string => ChileanRut::normalize($state))
                    ->dehydrateStateUsing(fn (?string $state): ?string => ChileanRut::normalize($state))
                    ->rule(new ValidChileanRut)
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->label('Teléfono')
                    ->nullable()
                    ->maxLength(50),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->nullable()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('address')
                    ->label('Dirección')
                    ->nullable()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Observaciones')
                    ->nullable()
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('expenses'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rut')
                    ->label('RUT')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable(),
                TextColumn::make('expenses_count')
                    ->label('Nº Gastos')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->disabled(fn (Supplier $record): bool => $record->expenses()->exists())
                    ->tooltip(fn (Supplier $record): ?string => $record->expenses()->exists()
                        ? 'No se puede eliminar un proveedor con gastos asociados.'
                        : null),
            ])
            ->checkIfRecordIsSelectableUsing(
                fn (Supplier $record): bool => $record->expenses()->doesntExist(),
            )
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Supplier
            && $record->expenses()->doesntExist();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
