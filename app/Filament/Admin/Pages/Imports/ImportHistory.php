<?php

namespace App\Filament\Admin\Pages\Imports;

use App\Domain\Imports\Services\AdministrativeImportService;
use App\Domain\Imports\Services\ImportFileDownloads;
use App\Models\CommunitySetting;
use App\Models\DataImport;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class ImportHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Importaciones';

    protected static ?string $navigationLabel = 'Historial de importaciones';

    protected static ?string $title = 'Historial de importaciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.imports.history';

    #[Locked]
    public ?int $selectedImportId = null;

    public static function canAccess(): bool
    {
        return ImportOwners::canAccess();
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        return $table->query(DataImport::query()->with(['user', 'communitySetting', 'etapa'])->where('community_setting_id', CommunitySetting::current()->id)
            ->when(! ImportFinancial::canAccess(), fn ($query) => $query->where('type', '!=', 'financial')))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (string $state): string => match ($state) {
                    'owners' => 'Propietarios', 'shares' => 'Acciones', 'financial' => 'Financiera', default => 'Lotes',
                })->sortable(),
                TextColumn::make('original_name')->label('Archivo')->searchable()->wrap(),
                TextColumn::make('communitySetting.community_name')->label('Comunidad')->toggleable(),
                TextColumn::make('etapa.nombre')->label('Etapa')->placeholder('—')->toggleable(),
                TextColumn::make('user.name')->label('Ejecutada por')->searchable(),
                TextColumn::make('created_at')->label('Fecha de carga')->dateTime()->sortable(),
                TextColumn::make('executed_at')->label('Ejecución')->dateTime()->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('total')->label('Filas')->sortable(),
                TextColumn::make('created_count')->label('Creados')->toggleable(),
                TextColumn::make('updated_count')->label('Actualizados')->toggleable(),
                TextColumn::make('skipped_count')->label('Omitidos')->toggleable(),
                TextColumn::make('error_count')->label('Errores')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(['owners' => 'Propietarios', 'lots' => 'Lotes', 'shares' => 'Acciones'] + (ImportFinancial::canAccess() ? ['financial' => 'Financiera'] : [])),
                SelectFilter::make('status')->label('Estado')->options(collect(['uploaded', 'preview', 'completed', 'completed_with_errors'])->mapWithKeys(fn (string $state): array => [$state => self::statusLabel($state)])->all()),
            ])
            ->recordActions([
                Action::make('resume')->label('Abrir asistente')
                    ->visible(fn (DataImport $record): bool => $record->user_id === auth()->id())
                    ->url(fn (DataImport $record): string => (match ($record->type) {
                        'owners' => ImportOwners::class, 'shares' => ImportShares::class, 'financial' => ImportFinancial::class, default => ImportLots::class,
                    })::getUrl(['import' => $record->id])),
                Action::make('detail')->label('Detalle')->action(fn (DataImport $record) => $this->showDetails($record->id)),
                Action::make('report')->label('Reporte')->visible(fn (DataImport $record): bool => $record->type !== 'financial')
                    ->action(function (DataImport $record) {
                        abort_if($record->type === 'financial', 403);

                        return app(ImportFileDownloads::class)->report($record, auth()->user());
                    }),
                Action::make('original')->label('Archivo original')->action(function (DataImport $record) {
                    abort_unless($record->type !== 'financial' || ImportFinancial::canAccess(), 403);

                    return app(ImportFileDownloads::class)->original($record, auth()->user());
                }),
            ]);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'uploaded' => 'Archivo cargado', 'preview' => 'Previsualizada (sin ejecutar)',
            'completed' => 'Completada', 'completed_with_errors' => 'Completada con errores',
            default => $status,
        };
    }

    public function showDetails(int $id): void
    {
        $import = DataImport::query()->findOrFail($id);
        abort_unless($import->type !== 'financial' || ImportFinancial::canAccess(), 403);
        app(AdministrativeImportService::class)->authorize(auth()->user(), $import);
        $this->selectedImportId = $import->id;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $selected = $this->selectedImportId ? DataImport::query()->findOrFail($this->selectedImportId) : null;
        if ($selected) {
            abort_unless($selected->type !== 'financial' || ImportFinancial::canAccess(), 403);
            app(AdministrativeImportService::class)->authorize(auth()->user(), $selected);
        }

        return ['selectedImport' => $selected, 'detailRows' => $selected?->rows()->orderBy('number')->limit(100)->get()];
    }
}
