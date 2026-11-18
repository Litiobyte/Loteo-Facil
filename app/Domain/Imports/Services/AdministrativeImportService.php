<?php

namespace App\Domain\Imports\Services;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Models\CommunitySetting;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdministrativeImportService
{
    public function __construct(private TabularFileReader $reader, private ImportDefinition $definition, private OwnerImportAccountService $ownerAccounts) {}

    public function authorize(User $actor, ?DataImport $import = null, bool $write = false): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        if ($import) {
            abort_unless($import->community_setting_id === CommunitySetting::current()->id, 403);
            if ($write) {
                abort_unless($import->user_id === $actor->id, 403);
            }
        }
    }

    public function start(UploadedFile $file, string $type, User $actor, ?int $etapaId = null): DataImport
    {
        $this->authorize($actor);
        $this->definition->fields($type);
        if ($type === 'shares') {
            if (! CommunitySetting::current()->usesShares()) {
                throw ValidationException::withMessages(['type' => 'La modalidad no admite acciones.']);
            }
        } else {
            Gate::forUser($actor)->authorize('create', $this->definition->model($type));
        }
        validator(['file' => $file, 'etapa_id' => $etapaId], [
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx'],
            'etapa_id' => $type === 'lots' ? ['required', 'integer', 'exists:etapas,id'] : ['nullable'],
        ])->validate();
        $parsed = $this->reader->read($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        $path = $file->store('administrative-imports', 'local');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => 'No se pudo conservar el archivo original. No se inició la importación.']);
        }
        try {
            return DB::transaction(function () use ($file, $type, $actor, $etapaId, $parsed, $path): DataImport {
                $import = DataImport::query()->create([
                    'type' => $type, 'community_setting_id' => CommunitySetting::current()->id,
                    'user_id' => $actor->id, 'etapa_id' => $type === 'lots' ? $etapaId : null,
                    'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'path' => $path, 'headers' => $parsed['headers'], 'total' => count($parsed['rows']),
                ]);
                foreach ($parsed['rows'] as $number => $raw) {
                    $import->rows()->create(['number' => $number, 'raw' => $raw]);
                }

                return $import;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /** @param array<string, int|string> $mapping */
    public function preview(DataImport $import, array $mapping, string $mode, User $actor): DataImport
    {
        $this->authorize($actor, $import, true);

        return DB::transaction(function () use ($import, $mapping, $mode, $actor): DataImport {
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($import->status, ['uploaded', 'preview'], true)) {
                throw ValidationException::withMessages(['import' => 'Esta importación ya fue ejecutada.']);
            }
            validator(['mode' => $mode], ['mode' => ['required', 'in:skip,update']])->validate();
            $fields = $this->definition->fields($import->type);
            $selected = [];
            foreach ($mapping as $field => $column) {
                if (! array_key_exists($field, $fields) || (! is_int($column) && ! is_string($column) && $column !== null) || ($column !== '' && $column !== null && (! ctype_digit((string) $column) || ! array_key_exists((int) $column, $import->headers)))) {
                    throw ValidationException::withMessages(['mapping' => 'Mapeo inválido.']);
                }
                if ($column !== '' && $column !== null) {
                    $selected[$field] = (int) $column;
                }
            }
            if (count(array_unique($selected)) !== count($selected) || ! isset($selected[$import->type === 'lots' ? 'codigo' : 'rut'])) {
                throw ValidationException::withMessages(['mapping' => 'Mapee RUT/código y no reutilice columnas.']);
            }
            $counts = [];
            foreach ($import->rows()->orderBy('id')->cursor() as $row) {
                $data = $this->applyOverrides($import->type, $row, $this->map($import->type, $row->raw, $selected));
                foreach ($this->definition->keys($import->type, $data) as $key) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
            $totals = ['created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'error_count' => 0];
            $import->rows()->chunkById(100, function ($rows) use ($import, $selected, $counts, $mode, $actor, &$totals): void {
                foreach ($rows as $row) {
                    $data = $this->applyOverrides($import->type, $row, $this->map($import->type, $row->raw, $selected));
                    if ($import->type === 'shares') {
                        $plan = app(ShareOpeningImportService::class)->inspect($data);
                        if (($counts['rut:'.mb_strtolower($data['rut'] ?? '')] ?? 0) > 1) {
                            $plan['errors'][] = 'RUT duplicado dentro del archivo.';
                            $plan['action'] = 'error';
                        }
                        $row->update($plan + ['data' => $data, 'result' => null]);
                        $totals[match ($plan['action']) {
                            'create' => 'created_count', 'skip' => 'skipped_count', default => 'error_count'
                        }]++;

                        continue;
                    }
                    [$target, $errors] = $this->definition->resolve($import->type, $data);
                    $errors = array_merge($errors, $this->definition->errors($import->type, $data, $target, $import->etapa_id));
                    if ($import->type === 'owners') {
                        $errors = array_merge($errors, $this->ownerAccounts->errors($data, $target));
                    }
                    foreach ($this->definition->keys($import->type, $data) as $key) {
                        if ($counts[$key] > 1) {
                            $errors[] = 'Identificador duplicado dentro del archivo: '.$key;
                        }
                    }
                    Gate::forUser($actor)->authorize($target ? 'update' : 'create', $target ?? $this->definition->model($import->type));
                    $changes = [];
                    foreach ($data as $field => $value) {
                        if (! $target || (string) $target->$field !== (string) $value) {
                            $changes[$field] = ['before' => $target?->$field, 'after' => $value];
                        }
                    }
                    $action = $errors !== [] ? 'error' : (! $target ? 'create' : ($mode === 'skip' || $changes === [] ? 'skip' : 'update'));
                    $totals[match ($action) {
                        'create' => 'created_count', 'update' => 'updated_count', 'skip' => 'skipped_count', default => 'error_count'
                    }]++;
                    $row->update([
                        'data' => $data, 'target_id' => $target?->id, 'fingerprint' => $target ? $this->definition->fingerprint($target) : null,
                        'action' => $action, 'changes' => $changes, 'errors' => $errors, 'result' => null,
                    ]);
                }
            });
            if ($import->type === 'shares') {
                $rejected = app(ShareOpeningImportService::class)->validateBatchCapacity($import);
                $totals['created_count'] -= $rejected;
                $totals['error_count'] += $rejected;
            }
            $import->update($totals + ['mapping' => $selected, 'duplicate_mode' => $mode, 'status' => 'preview']);

            return $import->refresh();
        });
    }

    /**
     * Corrige en el momento una fila ambigua durante la previsualización:
     * guarda la corrección, revalida todas las filas con el mapeo vigente
     * y devuelve la importación actualizada sin ejecutar nada.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function correctRow(DataImport $import, int $rowId, array $overrides, User $actor): DataImport
    {
        $this->authorize($actor, $import, true);

        return DB::transaction(function () use ($import, $rowId, $overrides, $actor): DataImport {
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($import->status !== 'preview') {
                throw ValidationException::withMessages(['import' => 'Solo se puede corregir filas durante la previsualización.']);
            }
            $row = $import->rows()->whereKey($rowId)->lockForUpdate()->firstOrFail();
            $fields = $this->definition->fields($import->type);
            $clean = [];
            foreach ($overrides as $field => $value) {
                if (array_key_exists($field, $fields)) {
                    $clean[$field] = is_string($value) ? trim($value) : $value;
                }
            }
            /** Solo se corrige lo que el usuario cambió respecto del valor mapeado. */
            $mapped = [];
            $mapping = is_array($import->mapping) ? $import->mapping : [];
            foreach ($mapping as $field => $column) {
                if (is_string($column) || is_int($column)) {
                    $mapped[$field] = $row->data[$field] ?? ($row->raw[$column] ?? '');
                }
            }
            $clean = array_filter($clean, fn ($value, string $field): bool => ! array_key_exists($field, $mapped) || (string) $value !== (string) $mapped[$field], ARRAY_FILTER_USE_BOTH);
            $row->update(['overrides' => $clean]);

            return $this->preview($import, $import->mapping ?? [], $import->duplicate_mode ?? 'skip', $actor);
        });
    }

    /**
     * Aplica las correcciones guardadas sobre los datos mapeados y
     * re-normaliza (RUT/correo) para que la validación use el valor final.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyOverrides(string $type, DataImportRow $row, array $data): array
    {
        foreach ($row->overrides ?? [] as $field => $value) {
            if ((string) $value === '') {
                unset($data[$field]);

                continue;
            }
            $data[$field] = $value;
        }

        return $this->definition->normalize($type, $data);
    }

    /** @param array<int, string> $raw
     * @param  array<string, int>  $mapping
     * @return array<string, mixed>
     */
    private function map(string $type, array $raw, array $mapping): array
    {
        $data = [];
        foreach ($mapping as $field => $column) {
            $value = trim($raw[$column] ?? '');
            if ($value !== '') {
                $data[$field] = $value;
            }
        }

        return $this->definition->normalize($type, $data);
    }

    public function execute(DataImport $import, User $actor, bool $confirmed): DataImport
    {
        $this->authorize($actor, $import, true);
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Debe confirmar explícitamente la ejecución.']);
        }

        return DB::transaction(function () use ($import, $actor): DataImport {
            /** Serializa también creaciones sin registro previo entre importaciones de esta instalación. */
            CommunitySetting::query()->whereKey($import->community_setting_id)->lockForUpdate()->firstOrFail();
            $import = DataImport::query()->lockForUpdate()->findOrFail($import->id);
            if (in_array($import->status, ['completed', 'completed_with_errors'], true)) {
                return $import;
            }
            if ($import->status !== 'preview') {
                throw ValidationException::withMessages(['import' => 'Primero debe validar y previsualizar.']);
            }
            $totals = ['created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'error_count' => 0];
            $import->rows()->chunkById(100, function ($rows) use ($import, $actor, &$totals): void {
                foreach ($rows as $row) {
                    $result = $row->action;
                    if (in_array($result, ['create', 'update'], true) || ($import->type === 'shares' && $result === 'skip')) {
                        try {
                            DB::transaction(function () use ($row, $import, $actor): void {
                                if ($import->type === 'shares') {
                                    app(ShareOpeningImportService::class)->executeRow($row, $actor);

                                    return;
                                }
                                if ($import->type === 'owners') {
                                    $resolved = $this->definition->resolveLocations($row->data);
                                    if ($resolved['errors'] !== []) {
                                        throw ValidationException::withMessages(['row' => $resolved['errors']]);
                                    }
                                    $row->data = $resolved['data'];
                                }
                                [$target, $errors] = $this->definition->resolve($import->type, $row->data);
                                if ($target) {
                                    $target = $target->newQuery()->lockForUpdate()->findOrFail($target->id);
                                }
                                $errors = array_merge($errors, $this->definition->errors($import->type, $row->data, $target, $import->etapa_id));
                                if ($import->type === 'owners') {
                                    $errors = array_merge($errors, $this->ownerAccounts->errors($row->data, $target));
                                }
                                if ($target?->id !== $row->target_id || ($target && $this->definition->fingerprint($target) !== $row->fingerprint)) {
                                    $errors[] = 'El registro cambió desde la previsualización. Vuelva a importar.';
                                }
                                if ($errors !== []) {
                                    throw ValidationException::withMessages(['row' => $errors]);
                                }
                                Gate::forUser($actor)->authorize($target ? 'update' : 'create', $target ?? $this->definition->model($import->type));
                                if ($target) {
                                    $target->fill($row->data)->save();
                                } else {
                                    $data = $row->data;
                                    if ($import->type === 'lots') {
                                        $data += ['etapa_id' => $import->etapa_id, 'estado' => 'disponible'];
                                        $created = Lote::query()->create($data);
                                        $row->target_id = $created->id;
                                    } elseif (isset($data['user_id'])) {
                                        User::query()->lockForUpdate()->findOrFail($data['user_id']);
                                        if (! app(PropietarioEligibleUserService::class)->eligibleUsersQuery(User::query())->whereKey($data['user_id'])->exists()) {
                                            throw ValidationException::withMessages(['row' => 'La cuenta dejó de estar disponible.']);
                                        }
                                        $created = Propietario::query()->create($data);
                                        $row->target_id = $created->id;
                                    } else {
                                        [$account, $owner] = $this->ownerAccounts->createAccount($data);
                                        $row->target_id = $owner->id;
                                    }
                                }
                            });
                        } catch (\Throwable $e) {
                            $result = 'error';
                            $row->errors = $e instanceof ValidationException ? $e->validator->errors()->all() : ['No se pudo guardar la fila. Compruebe permisos, referencias y unicidad.'];
                            if (! $e instanceof ValidationException) {
                                report($e);
                            }
                        }
                    }
                    $row->result = $result;
                    $row->save();
                    $totals[match ($result) {
                        'create' => 'created_count', 'update' => 'updated_count', 'skip' => 'skipped_count', default => 'error_count'
                    }]++;
                }
            });
            $import->update($totals + ['status' => $totals['error_count'] ? 'completed_with_errors' : 'completed', 'executed_at' => now()]);

            return $import->refresh();
        });
    }
}
