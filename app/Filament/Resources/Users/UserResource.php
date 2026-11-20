<?php

namespace App\Filament\Resources\Users;

use App\Domain\Users\Services\UserRoleAssignmentService;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Propietario;
use App\Models\User;
use App\Support\AdminTableColumns;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    public static function form(Schema $schema): Schema
    {
        $roleService = app(UserRoleAssignmentService::class);

        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Contrasena')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->maxLength(255),
                Select::make('role')
                    ->label('Rol')
                    ->required()
                    ->options(fn (): array => $roleService->roleOptionsFor(Auth::user()))
                    ->searchable()
                    ->preload(),
                Select::make('secondary_propietario_id')
                    ->label('Propietario asociado (acceso de solo lectura)')
                    ->helperText('Selecciona un propietario para dar acceso secundario de solo lectura con rol propietario. Deja vacío para no asociar o desvincular. Las cuentas principales no pueden ser secundarias.')
                    ->options(fn (): array => Propietario::query()->orderBy('nombre')->get()
                        ->mapWithKeys(fn (Propietario $owner): array => [$owner->id => $owner->nombre_completo.' ('.$owner->rut.')'])->all())
                    ->searchable()
                    ->preload()
                    ->visible(fn (?User $record): bool => $record === null || ! $record->propietario()->exists()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(AdminTableColumns::contentSized([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('owner_access')
                    ->label('Acceso propietario')
                    ->state(fn (User $record): string => $record->propietario
                        ? $record->propietario->nombre_completo.($record->secondary_propietario_id ? ' (solo lectura)' : ' (principal)')
                        : 'Sin asociar'),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $roles = $query->getModel()->roles();
                        $roleName = $roles->getRelated()->qualifyColumn('name');
                        $firstRole = $roles->getRelationExistenceQuery($roles->getRelated()->newQuery(), $query, [$roleName])
                            ->orderBy($roleName)
                            ->limit(1);

                        return $query->orderBy($firstRole, $direction);
                    })
                    ->toggleable()
                    ->badge(),
            ]))
            ->filters([
                SelectFilter::make('roles')
                    ->label('Rol')
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, User $record): array {
                        $data['role'] = $record->getRoleNames()->first();

                        return $data;
                    })
                    ->using(function (array $data, User $record): void {
                        $actor = Auth::user();
                        $roleService = app(UserRoleAssignmentService::class);
                        $role = $data['role'] ?? null;

                        if (! is_string($role)) {
                            throw ValidationException::withMessages(['role' => 'Debes seleccionar un rol valido.']);
                        }

                        DB::transaction(function () use ($data, $record, $actor, $roleService, $role): void {
                            $secondaryOwnerId = $data['secondary_propietario_id'] ?? null;
                            if (filled($secondaryOwnerId)) {
                                if ($role !== 'propietario' || $record->propietario()->exists() || ! Propietario::query()->whereKey($secondaryOwnerId)->exists()) {
                                    throw ValidationException::withMessages(['secondary_propietario_id' => 'Selecciona un propietario válido para un usuario sin cuenta principal y con rol propietario.']);
                                }
                            }

                            $record->update([
                                'name' => $data['name'],
                                'email' => $data['email'],
                            ]);

                            if (filled($data['password'] ?? null)) {
                                $record->update(['password' => $data['password']]);
                            }

                            $roleService->assignRole($actor, $record, $role);
                            $record->forceFill(['secondary_propietario_id' => $secondaryOwnerId ?: null])->save();
                        });
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['roles', 'propietario', 'secondaryPropietario']);

        $user = Auth::user();

        if ($user?->hasRole('admin')) {
            $query->whereHas('roles', function (Builder $roleQuery): void {
                $roleQuery->where('name', 'propietario');
            });
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
