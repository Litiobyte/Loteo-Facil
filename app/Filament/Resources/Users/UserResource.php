<?php

namespace App\Filament\Resources\Users;

use App\Domain\Users\Services\UserRoleAssignmentService;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 30;

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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge(),
            ])
            ->filters([
                //
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
                            $record->update([
                                'name' => $data['name'],
                                'email' => $data['email'],
                            ]);

                            if (filled($data['password'] ?? null)) {
                                $record->update(['password' => $data['password']]);
                            }

                            $roleService->assignRole($actor, $record, $role);
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
        $query = parent::getEloquentQuery()->with('roles');

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
