<?php

namespace App\Filament\Admin\Pages\Imports;

use App\Domain\Imports\Services\AdministrativeImportService;
use App\Domain\Imports\Services\ImportDefinition;
use App\Domain\Imports\Services\ImportFileDownloads;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\Comuna;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Etapa;
use App\Models\Region;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class ImportWizard extends Page
{
    use WithFileUploads;
    use WithPagination;

    protected static string|\UnitEnum|null $navigationGroup = 'Importaciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected string $view = 'filament.admin.pages.imports.wizard';

    #[Locked]
    public ?int $importId = null;

    #[Locked]
    public int $step = 1;

    public $file = null;

    public int $communityId = 1;

    public ?int $etapaId = null;

    /** @var array<string, int|string> */
    public array $mapping = [];

    public string $duplicateMode = 'skip';

    public bool $confirmed = false;

    public string $statusFilter = '';

    public string $search = '';

    #[Locked]
    public ?int $editingRowId = null;

    /** @var array<string, string> */
    public array $editingData = [];

    /** @var array<string, string|null> */
    public array $editingSelections = [];

    abstract protected function importType(): string;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $id = request()->integer('import');
        if (! $id) {
            return;
        }
        $this->importId = $id;
        $import = $this->getImport();
        $this->mapping = $import->mapping ?? $this->autoMap($import->headers);
        $this->duplicateMode = $import->duplicate_mode;
        $this->etapaId = $import->etapa_id;
        $this->step = match ($import->status) {
            'uploaded' => 2, 'preview' => 3, default => 4,
        };
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public function getImport(): ?DataImport
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->importId) {
            return null;
        }
        $import = DataImport::query()->findOrFail($this->importId);
        app(AdministrativeImportService::class)->authorize(auth()->user(), $import, true);
        abort_unless($import->type === $this->importType(), 403);

        return $import;
    }

    public function uploadFile(): void
    {
        abort_unless(static::canAccess() && $this->step === 1, 403);
        $this->validate([
            'communityId' => ['required', 'integer', 'in:1'],
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ]);
        $import = app(AdministrativeImportService::class)->start($this->file, $this->importType(), auth()->user(), $this->etapaId);
        $this->importId = $import->id;
        $this->mapping = $this->autoMap($import->headers);
        $this->file = null;
        $this->step = 2;
    }

    public function preview(): void
    {
        abort_unless($this->step === 2, 403);
        app(AdministrativeImportService::class)->preview($this->getImport(), $this->mapping, $this->duplicateMode, auth()->user());
        $this->confirmed = false;
        $this->resetPage();
        $this->step = 3;
    }

    public function remap(): void
    {
        $import = $this->getImport();
        abort_unless($import?->status === 'preview', 403);
        $this->confirmed = false;
        $this->cancelRowEdit();
        $this->step = 2;
    }

    public function editRow(int $rowId): void
    {
        $import = $this->getImport();
        abort_unless($import?->status === 'preview' && $this->step === 3, 403);
        $row = $import->rows()->findOrFail($rowId);
        $this->editingRowId = $row->id;
        $this->editingData = $this->editableFields($import, $row);
        $this->editingSelections = [];
        if ($import->type === 'owners') {
            $resolved = app(ImportDefinition::class)->resolveLocations($this->editingData)['data'];
            foreach (['region_id', 'comuna_id', 'estado_civil'] as $field) {
                $value = (string) ($resolved[$field] ?? '');
                $this->editingSelections[$field] = array_key_exists($value, $this->inlineSelectOptions($field)) ? $value : null;
            }
        }
    }

    public function saveRow(): void
    {
        abort_unless($this->step === 3 && $this->editingRowId !== null, 403);
        $import = $this->getImport();
        $row = $import->rows()->findOrFail($this->editingRowId);
        foreach ($this->editingSelections as $field => $value) {
            if ($value !== null && array_key_exists($value, $this->inlineSelectOptions($field))) {
                $this->editingData[$field] = (string) $value;
            }
        }
        $initial = $this->editableFields($import, $row);
        $corrections = array_filter($this->editingData, static function (mixed $value, string $field) use ($import, $row, $initial): bool {
            $source = $import->mapping[$field] ?? null;

            return ($source !== null && $source !== '')
                || array_key_exists($field, $row->overrides ?? [])
                || (string) $value !== ($initial[$field] ?? '');
        }, ARRAY_FILTER_USE_BOTH);
        $import = app(AdministrativeImportService::class)->correctRow($import, $this->editingRowId, $corrections, auth()->user());
        $this->cancelRowEdit();
        $this->resetPage();
        Notification::make()
            ->title($import->error_count ? 'Fila corregida, quedan observaciones' : 'Fila corregida')
            ->body("Previsualización revalidada: Creados: {$import->created_count} · Actualizados: {$import->updated_count} · Omitidos: {$import->skipped_count} · Errores: {$import->error_count}")
            ->success($import->error_count === 0)
            ->warning($import->error_count > 0)
            ->send();
    }

    public function cancelRowEdit(): void
    {
        $this->editingRowId = null;
        $this->editingData = [];
        $this->editingSelections = [];
    }

    /** @return array<int|string, string> */
    public function inlineSelectOptions(string $field): array
    {
        return match ($field) {
            'region_id' => Region::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->mapWithKeys(fn (Region $region): array => [$region->id => "{$region->nombre} (ID {$region->id})"])->all(),
            'comuna_id' => Comuna::query()
                ->when($this->editingSelections['region_id'] ?? null, fn ($query, $regionId) => $query->where('region_id', $regionId))
                ->orderBy('nombre')->get(['id', 'nombre', 'region_id'])
                ->mapWithKeys(fn (Comuna $comuna): array => [$comuna->id => "{$comuna->nombre} (ID {$comuna->id}, región {$comuna->region_id})"])->all(),
            'estado_civil' => PropietarioResource::maritalStatusOptions(),
            default => [],
        };
    }

    public function inlineSelectForm(Schema $schema): Schema
    {
        $fields = app(ImportDefinition::class)->fields('owners');

        return $schema->statePath('editingSelections')->components(
            array_map(fn (string $field): Select => Select::make($field)
                ->key($field)
                ->id('correct-'.$this->editingRowId.'-'.$field)
                ->label($fields[$field])
                ->hiddenLabel()
                ->placeholder('Seleccione '.$fields[$field])
                ->options(fn (): array => $this->inlineSelectOptions($field))
                ->searchable(fn (): bool => count($this->inlineSelectOptions($field)) > 4)
                ->live()
                ->extraInputAttributes(['aria-describedby' => 'import-row-errors-'.$this->editingRowId])
                ->afterStateUpdated(fn () => $this->syncInlineSelection($field)),
                ['region_id', 'comuna_id', 'estado_civil'])
        );
    }

    private function syncInlineSelection(string $field): void
    {
        $this->editingData[$field] = (string) ($this->editingSelections[$field] ?? '');
        if ($field !== 'region_id') {
            return;
        }

        $comunaId = $this->editingSelections['comuna_id'] ?? null;
        if ($comunaId !== null && array_key_exists($comunaId, $this->inlineSelectOptions('comuna_id'))) {
            return;
        }

        $this->editingSelections['comuna_id'] = null;
        $this->editingData['comuna_id'] = '';
    }

    /** @return array<string, string> */
    private function editableFields(DataImport $import, DataImportRow $row): array
    {
        $mapping = $import->mapping ?? [];
        $values = [];
        foreach ($this->importFields() as $field => $label) {
            $column = $mapping[$field] ?? null;
            $values[$field] = (string) ($row->overrides[$field] ?? $row->data[$field] ?? (($column !== null && $column !== '') ? ($row->raw[$column] ?? '') : ''));
        }

        return $values;
    }

    public function review(): void
    {
        abort_unless($this->step === 3 && $this->getImport()?->status === 'preview', 403);
        if ($this->statusFilter !== '' || trim($this->search) !== '') {
            $this->resetPage();
        }
        $this->step = 4;
    }

    public function updatedStatusFilter(): void
    {
        if (! in_array($this->statusFilter, ['', 'create', 'update', 'skip', 'error'], true)) {
            $this->statusFilter = '';
        }
        $this->cancelRowEdit();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->cancelRowEdit();
        $this->resetPage();
    }

    private function previewRows(DataImport $import): LengthAwarePaginator
    {
        $query = $import->rows()->orderBy('number');
        if ($this->step !== 3) {
            return $query->paginate(10);
        }

        if (in_array($this->statusFilter, ['create', 'update', 'skip', 'error'], true)) {
            $query->where('action', $this->statusFilter);
        }

        $search = trim($this->search);
        if ($search === '') {
            return $query->paginate(10);
        }

        // Search decoded values, not JSON keys/escapes, consistently on SQLite and MySQL.
        $rows = $query->get()->filter(static function (DataImportRow $row) use ($search): bool {
            foreach (Arr::flatten([$row->raw ?? [], $row->data ?? [], $row->overrides ?? []]) as $value) {
                if (is_scalar($value) && mb_stripos((string) $value, $search) !== false) {
                    return true;
                }
            }

            return false;
        })->values();
        $page = $this->getPage();

        return new LengthAwarePaginator($rows->forPage($page, 10)->values(), $rows->count(), 10, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function execute(): void
    {
        abort_unless($this->step === 4, 403);
        $this->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Debe confirmar explícitamente la ejecución.']);
        $import = app(AdministrativeImportService::class)->execute($this->getImport(), auth()->user(), $this->confirmed);
        Notification::make()
            ->title($import->error_count ? 'Importación completada con errores' : 'Importación completada')
            ->body("Creados: {$import->created_count} · Actualizados: {$import->updated_count} · Omitidos: {$import->skipped_count} · Errores: {$import->error_count}")
            ->success($import->error_count === 0)
            ->warning($import->error_count > 0)
            ->persistent()
            ->send();
    }

    public function downloadTemplate(string $format): Response
    {
        return app(ImportFileDownloads::class)->template($this->importType(), $format, auth()->user());
    }

    public function downloadAccounts(): StreamedResponse
    {
        abort_unless($this->importType() === 'owners', 403);

        return app(ImportFileDownloads::class)->eligibleAccounts(auth()->user());
    }

    public function downloadReport(bool $errorsOnly = false): StreamedResponse
    {
        return app(ImportFileDownloads::class)->report($this->getImport(), auth()->user(), $errorsOnly);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $import = $this->getImport();

        return [
            'import' => $import, 'type' => $this->importType(),
            'fields' => $this->importFields(),
            'sample' => $import?->rows()->orderBy('number')->limit(5)->get(),
            'previewRows' => $import && $this->step >= 3 ? $this->previewRows($import) : null,
            'stages' => $this->importType() === 'lots' ? Etapa::query()->orderBy('numero')->get() : collect(),
        ];
    }

    /** @return array<string, string> */
    protected function importFields(): array
    {
        return app(ImportDefinition::class)->fields($this->importType());
    }

    /** @param array<int, string> $headers
     * @return array<string, int|string>
     */
    protected function autoMap(array $headers): array
    {
        return app(ImportDefinition::class)->autoMap($this->importType(), $headers);
    }
}
