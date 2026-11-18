<div class="lf-import-table-scroll overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" role="region" aria-label="Detalle por fila; desplácese horizontalmente para ver todas las columnas" tabindex="0">
    <table class="w-full border-collapse bg-white text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-200" style="min-width: 56rem;">
        <caption class="sr-only">Detalle de operaciones, cambios y errores por fila</caption>
        <thead class="bg-gray-50 dark:bg-white/5">
            <tr>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Fila</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Operación / estado</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Datos / cambios previstos</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Conflictos / errores</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php
                    $rowState = $row->result ?? $row->action;
                    $rowPresentation = match ($rowState) {
                        'create' => ['color' => 'success', 'icon' => 'heroicon-o-check-circle', 'label' => 'Crear'],
                        'update' => ['color' => 'info', 'icon' => 'heroicon-o-arrow-path', 'label' => 'Actualizar'],
                        'skip' => ['color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle', 'label' => 'Omitir / advertencia'],
                        'error' => ['color' => 'danger', 'icon' => 'heroicon-o-x-circle', 'label' => 'Rechazar'],
                        default => ['color' => 'gray', 'icon' => 'heroicon-o-information-circle', 'label' => 'Sin validar'],
                    };
                @endphp
                <tr wire:key="import-row-{{ $row->id }}" class="border-t border-gray-200 align-top dark:border-white/10">
                    <th scope="row" class="px-4 py-4 text-left font-semibold text-gray-950 dark:text-white">{{ $row->number }}</th>
                    <td class="whitespace-nowrap px-4 py-4">
                        <x-filament::badge :color="$rowPresentation['color']" :icon="$rowPresentation['icon']">
                            {{ $rowPresentation['label'] }}
                        </x-filament::badge>
                    </td>
                    <td class="px-4 py-4 break-words">
                        <div class="grid gap-1">
                            @if ($row->data === null)
                                <span class="font-mono text-xs">{{ json_encode($row->raw, JSON_UNESCAPED_UNICODE) }}</span>
                            @endif
                            @foreach ($row->data ?? [] as $field => $value)
                                <div><strong class="font-semibold text-gray-950 dark:text-white">{{ $field }}:</strong>
                                    @if (isset($row->changes[$field]) && $row->action === 'update')
                                        <span class="text-gray-500 dark:text-gray-400">{{ $row->changes[$field]['before'] ?? '—' }}</span>
                                        <span aria-hidden="true">→</span>
                                    @endif
                                    {{ $value }}
                                </div>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-4 break-words">
                        @if (count($row->errors ?? []) > 0)
                            <ul class="grid gap-2" aria-label="Errores de la fila {{ $row->number }}">
                                @foreach ($row->errors ?? [] as $error)
                                    <li class="flex items-start gap-2 text-danger-600 dark:text-danger-400">
                                        <x-filament::icon icon="heroicon-o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{{ $error }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($editable ?? false)
                                <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-o-pencil" wire:click="editRow({{ $row->id }})" class="mt-2">Corregir datos</x-filament::button>
                            @endif
                        @elseif ($rowState === 'error')
                            <span class="text-danger-600 dark:text-danger-400">Fila rechazada. Consulte el reporte completo.</span>
                        @else
                            <span class="inline-flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4 text-success-500" />
                                Sin errores de validación
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
