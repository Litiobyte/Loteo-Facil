<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\SupplierResource\Pages\ManageSuppliers;
use App\Models\Supplier;
use App\Rules\ValidChileanRut;
use App\Support\AdminTableColumns;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Proveedores';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::fields());
    }

    public static function fields(bool $ignoreRecord = true): array
    {
        return [
            TextInput::make('name')->label('Nombre o razón social')->required()->maxLength(100)
                ->unique(table: 'suppliers', column: 'name', ignoreRecord: $ignoreRecord),
            TextInput::make('rut')->label('RUT')->required()->maxLength(12)->rule(new ValidChileanRut),
            TextInput::make('service')->label('Categoría o servicio')->required()->maxLength(150),
            TextInput::make('phone')->label('Teléfono o WhatsApp')->tel()->required()->maxLength(30),
            TextInput::make('email')->label('Correo electrónico')->email()->required()->maxLength(255),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('expenses'))
            ->columns(AdminTableColumns::contentSized([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('rut')->label('RUT')->searchable(),
                TextColumn::make('service')->label('Categoría o servicio')->searchable(),
                TextColumn::make('phone')->label('Teléfono o WhatsApp'),
                TextColumn::make('email')->label('Correo electrónico'),
                TextColumn::make('expenses_count')->label('Gastos')->sortable(),
            ]))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->requiresConfirmation()
                    ->disabled(fn (Supplier $record): bool => $record->expenses()->exists()),
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Supplier && $record->expenses()->doesntExist();
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
