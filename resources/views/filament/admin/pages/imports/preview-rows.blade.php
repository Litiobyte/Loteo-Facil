@php
    $hasEditingRow = $editable && $rows->contains('id', $editingRowId);
    $previewColumns = array_filter($fields, static function (string $field) use ($import, $rows, $hasEditingRow): bool {
        $source = $import->mapping[$field] ?? null;

        return $hasEditingRow || ($source !== null && $source !== '')
            || $rows->contains(static fn ($row): bool => isset($row->data[$field]) && $row->data[$field] !== '');
    }, ARRAY_FILTER_USE_KEY);
@endphp

<div class="lf-import-preview-table-scroll lf-import-table-scroll" role="region" aria-label="Datos por columna; desplácese horizontalmente para ver todos los campos" tabindex="0">
    <table class="lf-import-preview-table">
        <caption>Detalle de operaciones, datos importados y errores por fila</caption>
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Operación / estado</th>
                <th scope="col">Mensajes / advertencias / errores</th>
                @foreach ($previewColumns as $field => $label)
                    <th scope="col" data-import-field="{{ $field }}">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php
                    $isEditing = $editable && $editingRowId === $row->id;
                    $rowState = $row->result ?? $row->action;
                    $rowPresentation = match ($rowState) {
                        'created' => ['color' => 'success', 'icon' => 'heroicon-o-check-circle', 'label' => 'Creado (created)'],
                        'skipped' => ['color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle', 'label' => 'Omitido (skipped)'],
                        'failed' => ['color' => 'danger', 'icon' => 'heroicon-o-x-circle', 'label' => 'Fallido (failed)'],
                        'conflict' => ['color' => 'danger', 'icon' => 'heroicon-o-exclamation-triangle', 'label' => 'Conflicto (conflict) · Revisión manual'],
                        'create' => ['color' => 'success', 'icon' => 'heroicon-o-check-circle', 'label' => 'Crear'],
                        'update' => ['color' => 'info', 'icon' => 'heroicon-o-arrow-path', 'label' => 'Actualizar'],
                        'skip' => ['color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle', 'label' => 'Omitir / advertencia'],
                        'error' => ['color' => 'danger', 'icon' => 'heroicon-o-x-circle', 'label' => 'Rechazar'],
                        default => ['color' => 'gray', 'icon' => 'heroicon-o-information-circle', 'label' => 'Sin validar'],
                    };
                @endphp
                <tr wire:key="import-row-{{ $row->id }}" data-import-row="{{ $row->id }}" @if ($isEditing) data-editing="true" @endif>
                    <th scope="row">{{ $row->number }}</th>
                    <td>
                        <x-filament::badge :color="$rowPresentation['color']" :icon="$rowPresentation['icon']">
                            {{ $rowPresentation['label'] }}
                        </x-filament::badge>
                    </td>
                    <td class="lf-import-preview-conflicts">
                        @if ($type === 'financial' && $row->result && count($row->errors ?? []) === 0)
                            <p>{{ $row->changes['result_message']['after'] ?? '' }}</p>
                        @endif
                        @if (count($row->errors ?? []) > 0)
                            <ul id="import-row-errors-{{ $row->id }}" aria-label="Errores de la fila {{ $row->number }}">
                                @foreach ($row->errors as $error)
                                    <li>
                                        <x-filament::icon icon="heroicon-o-x-circle" />
                                        <span>{{ $error }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($editable && ! $isEditing)
                                <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-o-pencil" wire:click="editRow({{ $row->id }})">Corregir datos</x-filament::button>
                            @endif
                        @elseif ($rowState === 'error')
                            <span>Fila rechazada. Consulte el reporte completo.</span>
                        @else
                            <span id="import-row-errors-{{ $row->id }}">
                                <x-filament::icon icon="heroicon-o-check-circle" />
                                Sin errores de validación
                            </span>
                        @endif
                        @if ($isEditing)
                            <div class="lf-import-preview-actions lf-import-row-actions" aria-label="Corregir fila {{ $row->number }}">
                                <p>Al guardar se revalida toda la previsualización.</p>
                                @error('import')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                                <x-filament::button type="button" size="sm" icon="heroicon-o-check" wire:click="saveRow" wire:loading.attr="disabled" wire:target="saveRow">Guardar y revalidar</x-filament::button>
                                <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-o-x-mark" wire:click="cancelRowEdit" wire:loading.attr="disabled" wire:target="saveRow">Cancelar</x-filament::button>
                                <span wire:loading wire:target="saveRow" role="status">Revalidando filas…</span>
                            </div>
                        @endif
                    </td>
                    @foreach ($previewColumns as $field => $label)
                        @php
                            $source = $import->mapping[$field] ?? null;
                            $value = $row->data !== null
                                ? ($row->data[$field] ?? null)
                                : ($row->overrides[$field] ?? (($source !== null && $source !== '') ? ($row->raw[$source] ?? null) : null));
                        @endphp
                        <td data-import-field="{{ $field }}">
                            @if ($isEditing)
                                @if ($type === 'owners' && in_array($field, ['region_id', 'comuna_id', 'estado_civil'], true))
                                    <div wire:key="correct-select-{{ $row->id }}-{{ $field }}" class="lf-import-inline-select" title="Ctrl+Enter: guardar; Ctrl+Escape: cancelar. Enter selecciona y Escape cierra el desplegable." wire:keydown.ctrl.enter.prevent="saveRow" wire:keydown.ctrl.escape.prevent="cancelRowEdit">
                                        {{ $this->inlineSelectForm->getComponent($field) }}
                                        @if (($editingSelections[$field] ?? null) === null && ($editingData[$field] ?? '') !== '')
                                            <p class="text-sm text-danger-600 dark:text-danger-400">Valor original sin selección válida: {{ $editingData[$field] }}</p>
                                        @endif
                                    </div>
                                @elseif ($type === 'financial' && $this->financialType === 'charges' && $field === 'mode')
                                    <label class="sr-only" for="correct-{{ $row->id }}-mode">{{ $label }} · Fila {{ $row->number }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select id="correct-{{ $row->id }}-mode" wire:model="editingData.mode">
                                            <option value="">Seleccione modalidad</option>
                                            <option value="linked">Vinculado (linked)</option>
                                            <option value="backing">Gasto de respaldo histórico (backing)</option>
                                            <option value="opening">Apertura (opening)</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                @elseif ($type === 'financial' && $this->financialType === 'collections' && $field === 'mode')
                                    <label class="sr-only" for="correct-{{ $row->id }}-mode">{{ $label }} · Fila {{ $row->number }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select id="correct-{{ $row->id }}-mode" wire:model="editingData.mode">
                                            <option value="">Seleccione modalidad</option>
                                            <option value="automatic">Automática (automatic)</option>
                                            <option value="manual">Manual (manual)</option>
                                            <option value="unapplied">Sin aplicación (unapplied)</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                @elseif ($type === 'financial' && $field === 'allocations')
                                    <label class="sr-only" for="correct-{{ $row->id }}-allocations">{{ $label }} · Fila {{ $row->number }}</label>
                                    <textarea id="correct-{{ $row->id }}-allocations" wire:model="editingData.allocations" rows="5" aria-describedby="import-row-errors-{{ $row->id }}"></textarea>
                                @elseif ($type === 'financial' && $field === 'charges')
                                    <label class="sr-only" for="correct-{{ $row->id }}-charges">{{ $label }} · Fila {{ $row->number }}</label>
                                    <textarea id="correct-{{ $row->id }}-charges" wire:model="editingData.charges" rows="5" aria-describedby="import-row-errors-{{ $row->id }}"></textarea>
                                @else
                                <label class="sr-only" for="correct-{{ $row->id }}-{{ $field }}">{{ $label }} · Fila {{ $row->number }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input
                                        id="correct-{{ $row->id }}-{{ $field }}"
                                        type="text"
                                        wire:model="editingData.{{ $field }}"
                                        wire:key="correct-{{ $row->id }}-{{ $field }}"
                                        wire:keydown.enter.prevent="saveRow"
                                        wire:keydown.escape.prevent="cancelRowEdit"
                                        aria-describedby="import-row-errors-{{ $row->id }}"
                                    />
                                </x-filament::input.wrapper>
                                @endif
                                @error('editingData.'.$field)<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                            @else
                                @if (isset($row->changes[$field]) && $row->action === 'update')
                                    <span class="lf-import-preview-value lf-import-preview-before" title="{{ $row->changes[$field]['before'] ?? '—' }}">{{ $row->changes[$field]['before'] ?? '—' }}</span>
                                    <span aria-label="cambia a">→</span>
                                @endif
                                <span class="lf-import-preview-value" title="{{ $value === null || $value === '' ? '—' : $value }}">{{ $value === null || $value === '' ? '—' : $value }}</span>
                            @endif
                        </td>
                    @endforeach
                    
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
