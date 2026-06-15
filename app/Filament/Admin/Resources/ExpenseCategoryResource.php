<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ExpenseCategoryResource\Pages\ManageExpenseCategories;
use App\Models\ExpenseCategory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Categorías de Gastos';

    protected static ?string $modelLabel = 'Categoría de gasto';

    protected static ?string $pluralModelLabel = 'Categorías de gastos';

    protected static string|UnitEnum|null $navigationGroup = 'Gastos Comunes';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Textarea::make('description')
                    ->label('Descripción')
                    ->nullable()
                    ->rows(3)
                    ->maxLength(500),
                Toggle::make('is_active')
                    ->label('Activa')
                    ->default(true)
                    ->required(),
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
                TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(50)
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
                TextColumn::make('expenses_count')
                    ->label('Gastos')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->disabled(fn (ExpenseCategory $record): bool => $record->expenses()->exists())
                    ->tooltip(fn (ExpenseCategory $record): ?string => $record->expenses()->exists()
                        ? 'No se puede eliminar una categoría con gastos asociados.'
                        : null),
            ])
            ->checkIfRecordIsSelectableUsing(
                fn (ExpenseCategory $record): bool => $record->expenses()->doesntExist(),
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
        return $record instanceof ExpenseCategory
            && $record->expenses()->doesntExist();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseCategories::route('/'),
        ];
    }
}
