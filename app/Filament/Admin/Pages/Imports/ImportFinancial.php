<?php

namespace App\Filament\Admin\Pages\Imports;

use App\Domain\Imports\Services\FinancialImportService;
use App\Domain\Imports\Services\ImportDefinition;
use App\Domain\Imports\Services\TabularFileReader;
use App\Models\CommunitySetting;
use App\Models\DataImport;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportFinancial extends ImportWizard
{
    protected static ?string $navigationLabel = 'Importar movimientos financieros';

    protected static ?string $title = 'Importar movimientos financieros históricos';

    protected static ?int $navigationSort = 4;

    #[Locked]
    public string $financialType = 'adjustments';

    public function mount(): void
    {
        parent::mount();
        if ($this->importId !== null) {
            $mapping = $this->getImport()->mapping ?? [];
            $this->financialType = match (true) {
                array_key_exists('collection_date', $mapping) => 'collections',
                array_key_exists('origin_date', $mapping) => 'charges',
                array_key_exists('expense_date', $mapping) => 'expenses',
                array_key_exists('cutoff_date', $mapping) => 'openings',
                default => 'adjustments',
            };
        }
    }

    public function selectFinancialType(string $type): void
    {
        abort_unless(static::canAccess() && $this->step === 1 && $this->importId === null, 403);
        abort_unless(in_array($type, ['adjustments', 'openings', 'expenses', 'charges', 'collections'], true), 422);
        $this->financialType = $type;
        $this->resetValidation();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    protected function importType(): string
    {
        return 'financial';
    }

    protected function getViewData(): array
    {
        $data = parent::getViewData();
        $import = $data['import'];
        $data['hasFailedRows'] = $import?->rows()->where('result', 'failed')->exists() ?? false;
        $data['hasConflictRows'] = $import?->rows()->where('result', 'conflict')->exists() ?? false;

        return $data;
    }

    protected function importFields(): array
    {
        if ($this->financialType === 'openings') {
            return app(ImportDefinition::class)->fields('financial_openings');
        }

        if ($this->financialType === 'collections') {
            return app(ImportDefinition::class)->fields('financial_collections');
        }

        if ($this->financialType === 'charges') {
            return app(ImportDefinition::class)->fields('financial_charges');
        }

        if ($this->financialType === 'expenses') {
            return app(ImportDefinition::class)->fields('financial_expenses');
        }

        return [
            'source' => 'Fuente', 'movement_type' => 'Tipo de movimiento', 'external_id' => 'Identificador externo',
            'propietario_id' => 'ID del propietario', 'amount' => 'Monto', 'effective_date' => 'Fecha efectiva (AAAA-MM-DD)',
            'charge_id' => 'ID del cargo (corrección / condonación)', 'reference' => 'Referencia', 'observations' => 'Observaciones',
        ];
    }

    protected function autoMap(array $headers): array
    {
        $mapping = array_fill_keys(array_keys($this->importFields()), '');
        foreach ($headers as $column => $header) {
            $field = trim($header);
            if (array_key_exists($field, $mapping) && $mapping[$field] === '') {
                $mapping[$field] = $column;
            }
        }

        return $mapping;
    }

    public function uploadFile(): void
    {
        abort_unless(static::canAccess() && $this->step === 1, 403);
        $this->validate([
            'communityId' => ['required', 'integer', 'in:1'],
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ]);
        $parsed = app(TabularFileReader::class)->read($this->file->getRealPath(), strtolower($this->file->getClientOriginalExtension()));
        $path = $this->file->store('financial-imports', 'local');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => 'No se pudo conservar el archivo original.']);
        }
        try {
            $import = DB::transaction(function () use ($parsed, $path): DataImport {
                $import = DataImport::query()->create([
                    'type' => 'financial', 'community_setting_id' => CommunitySetting::current()->id,
                    'user_id' => auth()->id(), 'path' => $path,
                    'original_name' => mb_substr(basename(str_replace('\\', '/', $this->file->getClientOriginalName())), 0, 255),
                    'headers' => $parsed['headers'], 'total' => count($parsed['rows']),
                ]);
                foreach ($parsed['rows'] as $number => $raw) {
                    $import->rows()->create(['number' => $number, 'raw' => $raw]);
                }

                return $import;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $this->importId = $import->id;
        $this->mapping = $this->autoMap($import->headers);
        $import->update(['mapping' => $this->mapping]);
        $this->file = null;
        $this->step = 2;
    }

    public function preview(): void
    {
        abort_unless($this->step === 2, 403);
        $import = $this->getImport();
        $this->resetValidation();
        DB::transaction(function () use ($import): void {
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless(in_array($import->status, ['uploaded', 'preview'], true), 403);
            $selected = [];
            foreach ($this->mapping as $field => $column) {
                if (! array_key_exists($field, $this->importFields()) || (! is_int($column) && ! is_string($column))
                    || ($column !== '' && (! ctype_digit((string) $column) || ! array_key_exists((int) $column, $import->headers)))) {
                    throw ValidationException::withMessages(['mapping' => 'Mapeo inválido.']);
                }
                if ($column !== '') {
                    $selected[$field] = (int) $column;
                }
            }
            $required = match ($this->financialType) {
                'collections' => ['source', 'movement_type', 'external_id', 'mode', 'propietario_id', 'amount', 'collection_date', 'collection_method'],
                'charges' => ['source', 'movement_type', 'external_id', 'mode', 'propietario_id', 'amount', 'origin_date'],
                'expenses' => ['source', 'movement_type', 'external_id', 'title', 'expense_category_id', 'expense_date', 'amount', 'distribution_type', 'mode'],
                'openings' => ['source', 'movement_type', 'external_id', 'propietario_id', 'cutoff_date', 'opening_debt', 'opening_credit', 'information_source'],
                default => ['source', 'movement_type', 'external_id', 'propietario_id', 'amount', 'effective_date'],
            };
            if (count(array_unique($selected)) !== count($selected)
                || array_diff($required, array_keys($selected)) !== []) {
                throw ValidationException::withMessages(['mapping' => 'Mapee todos los campos obligatorios sin reutilizar columnas.']);
            }
            foreach ($import->rows()->cursor() as $row) {
                $data = [];
                foreach ($selected as $field => $column) {
                    $value = trim((string) ($row->overrides[$field] ?? $row->raw[$column] ?? ''));
                    if ($value !== '') {
                        $data[$field] = $value;
                    }
                }
                foreach ($row->overrides ?? [] as $field => $value) {
                    if (array_key_exists($field, $this->importFields())) {
                        if (trim((string) $value) === '') {
                            unset($data[$field]);
                        } else {
                            $data[$field] = trim((string) $value);
                        }
                    }
                }
                $row->update(['data' => $data, 'result' => null, 'changes' => []]);
            }
            $preview = app(FinancialImportService::class)->preview($import, auth()->user());
            foreach ($preview['rows'] as $result) {
                $import->rows()->findOrFail($result['row_id'])->update([
                    'action' => match ($result['status']) {
                        'ready' => 'create', 'conflict' => 'conflict', default => 'error',
                    },
                    'errors' => $result['message'] === '' ? [] : [$result['message']],
                ]);
            }
            $import->update([
                'mapping' => $selected, 'status' => 'preview', 'created_count' => collect($preview['rows'])->where('status', 'ready')->count(),
                'error_count' => collect($preview['rows'])->whereIn('status', ['failed', 'conflict'])->count(),
            ]);
        });
        $this->confirmed = false;
        $this->resetPage();
        $this->step = 3;
    }

    public function saveRow(): void
    {
        abort_unless($this->step === 3 && $this->editingRowId !== null, 403);
        $import = $this->getImport();
        $this->validate(['editingData.*' => ['nullable', 'string']]);
        DB::transaction(function () use ($import): void {
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($import->status === 'preview', 403);
            $import->rows()->findOrFail($this->editingRowId)->update([
                'overrides' => array_intersect_key($this->editingData, $this->importFields()),
            ]);
            $this->cancelRowEdit();
            $this->step = 2;
            $this->preview();
        });
    }

    public function execute(): void
    {
        abort_unless($this->step === 4, 403);
        $import = $this->getImport();
        $this->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Debe confirmar explícitamente la ejecución.']);
        DB::transaction(function () use ($import): void {
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            if (in_array($import->status, ['completed', 'completed_with_errors'], true)) {
                return;
            }
            abort_unless($import->status === 'preview', 403);
            app(FinancialImportService::class)->execute($import, auth()->user());
            $this->refreshResults($import);
        });
    }

    public function retryFailed(): void
    {
        $import = $this->getImport();
        abort_unless($this->step === 4, 403);
        $this->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Confirme el reintento de las filas fallidas.']);
        DB::transaction(function () use ($import): void {
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless(in_array($import->status, ['completed', 'completed_with_errors'], true)
                && $import->rows()->where('result', 'failed')->exists(), 403);
            app(FinancialImportService::class)->execute($import, auth()->user(), true);
            $this->refreshResults($import);
        });
    }

    private function refreshResults(DataImport $import): void
    {
        $counts = $import->rows()->get(['result'])->countBy('result');
        $import->update([
            'created_count' => $counts['created'] ?? 0, 'skipped_count' => $counts['skipped'] ?? 0,
            'updated_count' => 0, 'error_count' => ($counts['failed'] ?? 0) + ($counts['conflict'] ?? 0),
            'status' => (($counts['failed'] ?? 0) + ($counts['conflict'] ?? 0)) > 0 ? 'completed_with_errors' : 'completed', 'executed_at' => now(),
        ]);
        $this->confirmed = false;
        $this->resetPage();
        Notification::make()->title($import->error_count ? 'Lote financiero con errores' : 'Importación financiera completada')
            ->body("Creados: {$import->created_count} · Omitidos: {$import->skipped_count} · Fallidos: ".($counts['failed'] ?? 0).' · Conflictos (revisión manual): '.($counts['conflict'] ?? 0))
            ->success($import->error_count === 0)->warning($import->error_count > 0)->send();
    }

    public function downloadTemplate(string $format): Response
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($format === 'csv', 422);

        $name = match ($this->financialType) {
            'collections' => 'plantilla-recaudaciones-historicas.csv',
            'charges' => 'plantilla-cobros-historicos.csv',
            'expenses' => 'plantilla-gastos-historicos.csv',
            'openings' => 'plantilla-saldos-iniciales.csv',
            default => 'plantilla-financial.csv',
        };

        return $this->csvDownload($name, [array_keys($this->importFields())]);
    }

    public function downloadReport(bool $errorsOnly = false): StreamedResponse
    {
        $import = $this->getImport();
        $rows = [['fila', 'estado', 'mensaje', 'datos']];
        foreach ($import->rows()->orderBy('number')->get() as $row) {
            if ($errorsOnly && ! in_array($row->result, ['failed', 'conflict'], true) && ! ($row->result === null && in_array($row->action, ['error', 'conflict'], true))) {
                continue;
            }
            $rows[] = [$row->number, $row->result ?? $row->action, $row->changes['result_message']['after'] ?? implode(' | ', $row->errors ?? []), json_encode($row->data, JSON_UNESCAPED_UNICODE)];
        }

        return $this->csvDownload("importacion-{$import->id}".($errorsOnly ? '-errores' : '').'.csv', $rows);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function csvDownload(string $name, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                $cells = array_map(static fn (mixed $value): string => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : (string) $value, $row);
                fputcsv($handle, $cells, ',', '"', '');
            }
            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
