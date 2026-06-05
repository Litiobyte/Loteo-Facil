<?php

namespace App\Filament\Resources\Propietarios;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Filament\Resources\Propietarios\Pages\CreatePropietario;
use App\Filament\Resources\Propietarios\Pages\EditPropietario;
use App\Filament\Resources\Propietarios\Pages\ListPropietarios;
use App\Filament\Resources\Propietarios\RelationManagers\LotesRelationManager;
use App\Models\Comuna;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PropietarioResource extends Resource
{
    protected static ?string $model = Propietario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Propietarios';

    protected static ?string $modelLabel = 'Propietario';

    protected static ?string $pluralModelLabel = 'Propietarios';

    public static function form(Schema $schema): Schema
    {
        $eligibleUserService = app(PropietarioEligibleUserService::class);

        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Usuario propietario')
                    ->relationship(
                        name: 'user',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query, ?Propietario $record = null): Builder => $eligibleUserService->eligibleUsersForFormQuery($query, $record)
                    )
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} ({$record->email})")
                    ->searchable(['name', 'email'])
                    ->preload()
                    ->required()
                    ->validationMessages([
                        'required' => 'Debes seleccionar un usuario con rol propietario.',
                    ])
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('apellido')
                    ->label('Apellido')
                    ->required()
                    ->maxLength(255),
                TextInput::make('rut')
                    ->label('RUT')
                    ->required()
                    ->maxLength(20)
                    ->formatStateUsing(fn (?string $state): ?string => ChileanRut::normalize($state))
                    ->dehydrateStateUsing(fn (?string $state): ?string => ChileanRut::normalize($state))
                    ->rule(new ValidChileanRut)
                    ->unique(ignoreRecord: true),
                TextInput::make('telefono')
                    ->label('Telefono')
                    ->maxLength(50),
                TextInput::make('direccion')
                    ->label('Direccion')
                    ->maxLength(255),
                Select::make('region_id')
                    ->label('Region')
                    ->options(fn (): array => Region::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(),
                Select::make('comuna_id')
                    ->label('Comuna')
                    ->options(function (callable $get): array {
                        $regionId = $get('region_id');

                        if (! $regionId) {
                            return [];
                        }

                        return Comuna::query()
                            ->where('region_id', $regionId)
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('nacionalidad')
                    ->label('Nacionalidad')
                    ->maxLength(120),
                TextInput::make('profesion')
                    ->label('Profesion')
                    ->maxLength(120),
                Select::make('estado_civil')
                    ->label('Estado civil')
                    ->options([
                        'Soltero' => 'Soltero',
                        'Casado' => 'Casado',
                        'Divorciado' => 'Divorciado',
                        'Viudo' => 'Viudo',
                        'Union civil' => 'Union civil',
                    ])
                    ->required(),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
            ]);
    }

    public static function eligibleUsersQuery(Builder $query): Builder
    {
        return app(PropietarioEligibleUserService::class)->eligibleUsersQuery($query);
    }

    public static function eligibleUsersForFormQuery(Builder $query, ?Propietario $record = null): Builder
    {
        return app(PropietarioEligibleUserService::class)->eligibleUsersForFormQuery($query, $record);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre_completo')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('rut')
                    ->label('RUT')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('lotes_asignados_count')
                    ->label('N lotes asignados')
                    ->badge()
                    ->sortable(),
                TextColumn::make('lotes_activos_m2_sum')
                    ->label('Hectareas totales')
                    ->formatStateUsing(fn ($state): string => number_format(((float) ($state ?? 0)) / 10000, 2, ',', '.').' ha')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar propietario')
                    ->modalDescription('Esta accion eliminara el registro del propietario. No elimina al usuario.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user'])
            ->withCount([
                'lotesActivos as lotes_asignados_count' => fn (Builder $query): Builder => $query
                    ->whereIn('estado', ['reservado', 'vendido']),
            ])
            ->withSum([
                'lotesActivos as lotes_activos_m2_sum' => fn (Builder $query): Builder => $query
                    ->whereIn('estado', ['reservado', 'vendido']),
            ], 'metros_cuadrados');
    }

    public static function getRelations(): array
    {
        return [
            LotesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPropietarios::route('/'),
            'create' => CreatePropietario::route('/create'),
            'edit' => EditPropietario::route('/{record}/edit'),
        ];
    }
}
