<?php

namespace App\Filament\Resources\Lotes;

use App\Filament\Forms\ChileanNumberInput;
use App\Filament\Resources\Lotes\Pages\ManageLotes;
use App\Filament\Resources\Lotes\Pages\ViewLote;
use App\Models\CommunitySetting;
use App\Models\Etapa;
use App\Models\Lote;
use App\Support\AdminTableColumns;
use App\Support\ChileanNumber;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoteResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return CommunitySetting::current()->usesLots();
    }

    protected static ?string $model = Lote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Lotes';

    protected static ?string $modelLabel = 'Lote';

    protected static ?string $pluralModelLabel = 'Lotes';

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
                ChileanNumberInput::make('metros_cuadrados')
                    ->surface()
                    ->label('Superficie (m²)')
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
                ChileanNumberInput::make('valor_lote')
                    ->label('Valor lote')
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
            ->columns(AdminTableColumns::contentSized([
                TextColumn::make('codigo')
                    ->label('Codigo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->toggleable()
                    ->badge(),
                TextColumn::make('metros_cuadrados')
                    ->label('m²')
                    ->toggleable()
                    ->formatStateUsing(fn (mixed $state): ?string => ChileanNumber::format($state))
                    ->sortable(),
                TextColumn::make('hectareas')
                    ->label('Hectareas')
                    ->toggleable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' ha')
                    ->sortable(),
                TextColumn::make('etapa.nombre')
                    ->label('Etapa')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('valor_lote')
                    ->label('Valor lote')
                    ->toggleable()
                    ->money('CLP', locale: 'es_CL')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->toggleable()
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]))
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'disponible' => 'Disponible',
                        'vendido' => 'Vendido',
                        'reservado' => 'Reservado',
                    ]),
                SelectFilter::make('etapa')
                    ->label('Etapa')
                    ->relationship('etapa', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
                Action::make('ver_detalle')
                    ->label('Ver detalle')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (Lote $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('ver_en_mapa')
                    ->label('Ver en mapa')
                    ->icon('heroicon-o-map')
                    ->color('gray')
                    ->action(fn (Lote $record, ManageLotes $livewire): mixed => $livewire->verEnMapa($record)),
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
            'view' => ViewLote::route('/{record}'),
        ];
    }
}
