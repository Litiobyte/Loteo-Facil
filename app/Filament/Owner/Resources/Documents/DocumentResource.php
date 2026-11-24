<?php

namespace App\Filament\Owner\Resources\Documents;

use App\Domain\Documents\Services\DocumentAuthorizationService;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use App\Filament\Owner\Resources\Documents\Pages\ManageDocuments;
use App\Models\Collection;
use App\Models\Document;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Mis Datos';

    protected static ?string $navigationLabel = 'Mis documentos';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'Mis documentos';

    public static function canAccess(): bool
    {
        return (auth()->user()?->hasRole('propietario') ?? false) && auth()->user()?->propietario !== null;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(DocumentAuthorizationService::class)->visibleQuery(auth()->user())
            ->with(['type', 'links.documentable']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('original_name')->label('Nombre')->searchable(),
            TextColumn::make('type.name')->label('Tipo'),
            TextColumn::make('document_date')->label('Fecha')->date('d/m/Y')->placeholder('-'),
        ])->recordActions([
            Action::make('preview')->label('Vista previa')->url(fn (Document $record): string => route('documents.file', [$record, 'preview']))->openUrlInNewTab(),
            Action::make('download')->label('Descargar')->url(fn (Document $record): string => route('documents.file', [$record, 'download'])),
            Action::make('origin')->label('Ir al pago')
                ->url(fn (Document $record): ?string => self::originUrl($record))
                ->visible(fn (Document $record): bool => self::originUrl($record) !== null),
        ]);
    }

    public static function can(string|UnitEnum $action, ?Model $record = null): bool
    {
        return $record instanceof Document
            ? auth()->user()?->can($action, $record) ?? false
            : static::canAccess();
    }

    private static function originUrl(Document $document): ?string
    {
        $entity = $document->links->first()?->documentable;

        return $entity instanceof Collection && $entity->propietario_id === auth()->user()?->propietario?->id
            ? MyCollectionsResource::getUrl('view', ['record' => $entity], panel: 'owner')
            : null;
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocuments::route('/')];
    }
}
