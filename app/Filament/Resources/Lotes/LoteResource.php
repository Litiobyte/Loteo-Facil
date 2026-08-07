<?php

namespace App\Filament\Resources\Lotes;

use App\Filament\Resources\Lotes\Pages\ManageLotes;
use App\Models\Etapa;
use App\Models\Lote;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LoteResource extends Resource
{
    protected static ?string $model = Lote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Lotes';

    protected static ?string $modelLabel = 'Lote';

    protected static ?string $pluralModelLabel = 'Lotes';

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('codigo')
                    ->label('Codigo')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('estado')
                    ->label('Estado')
                    ->options([
                        'disponible' => 'Disponible',
                        'vendido' => 'Vendido',
                        'reservado' => 'Reservado',
                    ])
                    ->required(),
                TextInput::make('metros_cuadrados')
                    ->label('Cantidad m2')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->rule('gt:0')
                    ->validationMessages([
                        'gt' => 'Los metros cuadrados deben ser mayores a 0.',
                    ]),
                Select::make('etapa_id')
                    ->label('Etapa')
                    ->options(fn (): array => Etapa::query()->orderBy('numero')->pluck('nombre', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('valor_lote')
                    ->label('Valor lote')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(3)
                    ->maxLength(1000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Codigo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('metros_cuadrados')
                    ->label('m2')
                    ->numeric(0)
                    ->sortable(),
                TextColumn::make('hectareas')
                    ->label('Hectareas')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' ha')
                    ->sortable(),
                TextColumn::make('etapa.nombre')
                    ->label('Etapa')
                    ->sortable(),
                TextColumn::make('valor_lote')
                    ->label('Valor lote')
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLotes::route('/'),
        ];
    }
}
