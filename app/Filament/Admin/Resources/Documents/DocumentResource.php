<?php

namespace App\Filament\Admin\Resources\Documents;

use App\Domain\Documents\Services\DocumentAuthorizationService;
use App\Filament\Admin\Resources\CollectionResource;
use App\Filament\Admin\Resources\Documents\Pages\ManageDocuments;
use App\Filament\Admin\Resources\ExpenseFundingPayments\ExpenseFundingPaymentResource;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Resources\Lotes\LoteResource;
use App\Models\Collection;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use UnitEnum;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?string $navigationLabel = 'Documentos';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'Documentos';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(DocumentAuthorizationService::class)->visibleQuery(auth()->user())
            ->with(['type.modules', 'uploader', 'links.documentable' => fn (MorphTo $morph) => $morph->morphWith([
                Collection::class => ['propietario'],
                PartnerCharge::class => ['propietario'],
                ShareHolding::class => ['propietario'],
                ShareMovement::class => ['holding.propietario'],
            ])]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('description')->label('Descripción')->maxLength(2000),
            DatePicker::make('document_date')->label('Fecha del documento')->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('original_name')->label('Nombre')->searchable()->sortable(),
            TextColumn::make('type.name')->label('Tipo')->sortable(),
            TextColumn::make('type.modules.module_key')->label('Módulos')->badge(),
            TextColumn::make('origin')->label('Registro relacionado')->state(fn (Document $record): string => $record->links->map(
                fn ($link): string => class_basename($link->documentable_type).' #'.$link->documentable_id
            )->implode(', '))->wrap(),
            TextColumn::make('owners')->label('Propietario relacionado')->state(fn (Document $record): string => $record->links->map(function ($link): ?string {
                $entity = $link->documentable;
                $owner = match (true) {
                    $entity instanceof Propietario => $entity,
                    $entity instanceof Collection, $entity instanceof PartnerCharge, $entity instanceof ShareHolding => $entity->propietario,
                    $entity instanceof ShareMovement => $entity->holding?->propietario,
                    default => null,
                };

                return $owner?->nombre_completo;
            })->filter()->unique()->implode(', '))->placeholder('-')->wrap(),
            TextColumn::make('visibility')->label('Visibilidad')->badge(),
            TextColumn::make('uploader.name')->label('Subido por'),
            TextColumn::make('document_date')->label('Fecha')->date('d/m/Y')->placeholder('-')->sortable(),
        ])->filters([
            SelectFilter::make('document_type_id')->label('Tipo')->options(fn (): array => DocumentType::query()->where('community_id', 1)->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('module')->label('Módulo')->options(['acciones' => 'Acciones', 'finanzas' => 'Finanzas', 'comunidad' => 'Comunidad', 'lotes' => 'Lotes'])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $value): Builder => $query->whereHas('type.modules', fn (Builder $module) => $module->where('module_key', $value)))),
            SelectFilter::make('visibility')->label('Visibilidad')->options(['admin' => 'Administración', 'owner' => 'Propietario', 'community' => 'Comunidad', 'custom' => 'Personalizada']),
            SelectFilter::make('entity')->label('Entidad')->options([
                Propietario::class => 'Propietario', ShareHolding::class => 'Participación', ShareMovement::class => 'Movimiento de acciones',
                Expense::class => 'Gasto', ExpenseFundingPayment::class => 'Egreso', Collection::class => 'Recaudación',
                Lote::class => 'Lote',
            ])->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                fn (Builder $query, string $value): Builder => $query->whereHas('links', fn (Builder $links) => $links->where('documentable_type', $value)))),
            SelectFilter::make('owner')->label('Propietario')->searchable()
                ->options(fn (): array => Propietario::query()->orderBy('nombre')->get()->mapWithKeys(fn (Propietario $owner): array => [$owner->id => $owner->nombre_completo])->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $ownerId): Builder => $query->whereHas('links', fn (Builder $links): Builder => $links
                    ->whereHasMorph('documentable', [Propietario::class], fn (Builder $entity) => $entity->whereKey($ownerId))
                    ->orWhereHasMorph('documentable', [Collection::class, PartnerCharge::class, ShareHolding::class], fn (Builder $entity) => $entity->where('propietario_id', $ownerId))
                    ->orWhereHasMorph('documentable', [ShareMovement::class], fn (Builder $entity) => $entity->whereIn('share_holding_id', ShareHolding::query()->where('propietario_id', $ownerId)->select('id')))))),
            Filter::make('document_date')->schema([
                DatePicker::make('from')->label('Desde'),
                DatePicker::make('until')->label('Hasta'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('document_date', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('document_date', '<=', $date))),
        ])->recordActions([
            Action::make('preview')->label('Vista previa')->url(fn (Document $record): string => route('documents.file', [$record, 'preview']))->openUrlInNewTab()
                ->visible(fn (Document $record): bool => auth()->user()->can('view', $record)),
            Action::make('download')->label('Descargar')->url(fn (Document $record): string => route('documents.file', [$record, 'download']))
                ->visible(fn (Document $record): bool => auth()->user()->can('view', $record)),
            Action::make('origin')->label('Ir al registro')->url(fn (Document $record): ?string => self::originUrl($record))
                ->visible(fn (Document $record): bool => self::originUrl($record) !== null),
            ViewAction::make(),
            EditAction::make(),
        ]);
    }

    public static function can(string|UnitEnum $action, ?Model $record = null): bool
    {
        return auth()->user()?->can($action, $record ?? static::getModel()) ?? false;
    }

    private static function originUrl(Document $document): ?string
    {
        $entity = $document->links->first()?->documentable;

        return match (true) {
            $entity instanceof Expense => ExpenseResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Collection => CollectionResource::getUrl('view', ['record' => $entity]),
            $entity instanceof ExpenseFundingPayment => ExpenseFundingPaymentResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Lote => LoteResource::getUrl('view', ['record' => $entity]),
            default => null,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocuments::route('/')];
    }
}
