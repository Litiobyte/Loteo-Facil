<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\EditCommunitySettings;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\ManageCommunitySettings;
use App\Filament\Forms\ChileanNumberInput;
use App\Models\CommunitySetting;
use App\Support\AdminTableColumns;
use App\Support\ChileanNumber;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CommunitySettingResource extends Resource
{
    protected static ?string $model = CommunitySetting::class;

    protected static ?string $navigationLabel = 'Comunidad';

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'Configuración de comunidad';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    /**
     * Configuración singleton con historial de cambios (ficha directa).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('community_name')->label('Nombre de la comunidad')->maxLength(120)->columnSpanFull(),
            FileUpload::make('logo_path')->label('Logotipo de la comunidad (opcional)')
                ->disk('documents')->directory('community-logos')->visibility('private')
                ->getUploadedFileUsing(function (string $file): ?array {
                    $settings = CommunitySetting::current();
                    if ($file !== $settings->logo_path || ! preg_match('/\Acommunity-logos\/[a-zA-Z0-9]{40}\.(png|jpg|jpeg|webp)\z/', $file)) {
                        return null;
                    }
                    $disk = Storage::disk('documents');
                    if (! $disk->exists($file)) {
                        return null;
                    }

                    return ['name' => basename($file), 'size' => $disk->size($file), 'type' => $disk->mimeType($file), 'url' => route('community.logo')];
                })
                ->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                ->maxSize(2048)->rules(['dimensions:max_width=4096,max_height=4096', 'extensions:png,jpg,jpeg,webp'])
                ->preventFilePathTampering()->storeFiles(false)
                ->helperText('PNG, JPG o WebP. Máximo 2 MB y 4096 × 4096 px. Quite la imagen y guarde para volver a la marca Loteo Fácil.')
                ->columnSpanFull(),
            Select::make('mode')->label('Modalidad')->options(['lots' => 'Solo lotes', 'shares' => 'Solo acciones', 'mixed' => 'Acciones y lotes'])->required(),
            ChileanNumberInput::make('square_meters_per_share')->surface()->label('m² por acción')->required()->minValue('0.0001'),
            TextInput::make('share_precision')->label('Decimales (0 a 4)')->numeric()->required()->minValue(0)->maxValue(4),
            TextInput::make('authorized_shares')->label('Total autorizado (opcional)')->numeric()->minValue(0),
            Textarea::make('reason')->label('Motivo del cambio')->required()->maxLength(1000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns(AdminTableColumns::contentSized([
            TextColumn::make('mode')->label('Modalidad')->searchable()->sortable()->toggleable(),
            TextColumn::make('square_meters_per_share')->label('m² por acción para nuevas adquisiciones')->formatStateUsing(fn (mixed $state): ?string => ChileanNumber::format($state))->sortable()->toggleable(),
            TextColumn::make('share_precision')->label('Decimales')->sortable()->toggleable(),
            TextColumn::make('authorized_shares')->label('Total autorizado')->sortable()->toggleable(),
        ]))->recordActions([
            Action::make('configure')->label('Configurar')->requiresConfirmation()
                ->modalDescription('Solo acciones oculta lotes y mapas. Mixto muestra ambos. Solo lotes oculta acciones. No se eliminan datos ni se recalculan cobros. La equivalencia aplica solo a nuevas adquisiciones.')
                ->fillForm(fn (CommunitySetting $record): array => $record->toArray())
                ->schema([
                    Select::make('mode')->label('Modalidad')->options(['lots' => 'Solo lotes', 'shares' => 'Solo acciones', 'mixed' => 'Acciones y lotes'])->required(),
                    ChileanNumberInput::make('square_meters_per_share')->surface()->label('m² por acción')->required()->minValue('0.0001'),
                    TextInput::make('share_precision')->label('Decimales (0 a 4)')->numeric()->required()->minValue(0)->maxValue(4),
                    TextInput::make('authorized_shares')->label('Total autorizado (opcional)')->numeric()->minValue(0),
                    Textarea::make('reason')->label('Motivo del cambio')->required()->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CommunitySettingsService::class)->update($data, $data['reason'], auth()->user());
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
            Action::make('history')->label('Historial')->modalSubmitAction(false)->modalContent(
                fn () => view('filament.shares.settings-history', ['revisions' => DB::table('community_setting_revisions')->orderByDesc('id')->get()])
            ),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => EditCommunitySettings::route('/'),
            'list' => ManageCommunitySettings::route('/list'),
        ];
    }
}
