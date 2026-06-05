<?php

namespace App\Filament\Owner\Resources\Lotes;

use App\Filament\Owner\Resources\Lotes\Pages\ListMisLotes;
use App\Models\Lote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class LoteResource extends Resource
{
    protected static ?string $model = Lote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Mis lotes';

    protected static ?string $modelLabel = 'Lote';

    protected static ?string $pluralModelLabel = 'Mis lotes';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
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
                    ->numeric(0),
                TextColumn::make('hectareas')
                    ->label('Hectareas')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' ha'),
                TextColumn::make('etapa.nombre')
                    ->label('Etapa'),
                TextColumn::make('valor_lote')
                    ->label('Valor lote')
                    ->money('CLP', locale: 'es_CL'),
                TextColumn::make('notas')
                    ->label('Notas')
                    ->limit(40),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                // Read-only.
            ])
            ->toolbarActions([
                // Read-only.
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $propietarioId = Auth::user()?->propietario?->id;

        if (! $propietarioId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereIn('lotes.id', function ($query) use ($propietarioId): void {
                $query->select('lote_id')
                    ->from('lote_propietario')
                    ->where('propietario_id', $propietarioId)
                    ->where('status', 'active');
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMisLotes::route('/'),
        ];
    }
}
