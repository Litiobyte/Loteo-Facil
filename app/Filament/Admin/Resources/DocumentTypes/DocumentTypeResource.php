<?php

namespace App\Filament\Admin\Resources\DocumentTypes;

use App\Filament\Admin\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Models\DocumentType;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Tipos de documentos';

    protected static ?string $modelLabel = 'Tipo de documento';

    protected static ?string $pluralModelLabel = 'Tipos de documentos';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('community_id', 1);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Textarea::make('description')->label('Descripción')->maxLength(2000),
                Select::make('default_visibility')->label('Visibilidad predeterminada')
                    ->options(['admin' => 'Administración', 'owner' => 'Propietario', 'community' => 'Comunidad'])
                    ->default('admin')->required(),
                Select::make('settings.allowed_mimes')->label('Formatos admitidos')
                    ->multiple()->options([
                        'application/pdf' => 'PDF',
                        'image/jpeg' => 'JPEG',
                        'image/png' => 'PNG',
                        'image/webp' => 'WebP',
                    ])->default(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->required(),
                TextInput::make('settings.max_files')->label('Máximo de archivos')->numeric()->integer()->minValue(1)->maxValue(1000)->default(10)->required(),
                TextInput::make('settings.max_size_kb')->label('Tamaño máximo por archivo (KB)')->numeric()->integer()->minValue(1)->maxValue(102400)->default(10240)->required(),
                Toggle::make('settings.required')->label('Obligatorio')->default(false),
                Toggle::make('is_active')->label('Activo')->default(true),
                Repeater::make('modules')->relationship()->label('Módulos permitidos')->schema([
                    Select::make('module_key')->label('Módulo')->options(self::modules())->required()->distinct(),
                ])->defaultItems(0)->minItems(1)->columns(1)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('default_visibility')->label('Visibilidad')->badge(),
                TextColumn::make('modules.module_key')->label('Módulos')->badge(),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Estado'),
                SelectFilter::make('module_key')->label('Módulo')->options(self::modules())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $module): Builder => $query->whereHas('modules', fn (Builder $modules): Builder => $modules->where('module_key', $module)),
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->requiresConfirmation()
                    ->authorize(fn (DocumentType $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->disabled(fn (DocumentType $record): bool => $record->documents()->withTrashed()->exists())
                    ->tooltip(fn (DocumentType $record): ?string => $record->documents()->withTrashed()->exists()
                        ? 'No se puede eliminar un tipo con documentos asociados.' : null),
            ]);
    }

    private static function modules(): array
    {
        return ['acciones' => 'Acciones', 'finanzas' => 'Finanzas', 'comunidad' => 'Comunidad', 'lotes' => 'Lotes'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentTypes::route('/'),
        ];
    }
}
