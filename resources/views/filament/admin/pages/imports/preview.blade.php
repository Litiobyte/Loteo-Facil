<div class="lf-import-preview">
    <section class="lf-import-preview-card" aria-labelledby="import-validation-title">
        <header class="lf-import-preview-header">
            <div>
                <h2 id="import-validation-title"><x-filament::icon icon="heroicon-o-clipboard-document-check" aria-hidden="true" /> Validación de registros</h2>
                <p>Compruebe el resultado antes de guardar. Ningún dato se ha importado todavía.</p>
            </div>
            <span class="lf-import-preview-filename"><x-filament::icon icon="heroicon-o-document-text" aria-hidden="true" /> {{ $import->original_name }}</span>
        </header>
        <div class="lf-import-preview-body">
            <dl class="lf-import-preview-stats" aria-label="Resumen de validación">
                <div class="lf-import-preview-stat" data-validation-state="total">
                    <x-filament::icon icon="heroicon-o-document-text" aria-hidden="true" />
                    <dt>Registros analizados</dt><dd>{{ $import->total }}</dd>
                </div>
                <div class="lf-import-preview-stat" data-validation-state="valid">
                    <x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" />
                    <dt>Listos para importar</dt><dd>{{ $import->created_count + $import->updated_count }}</dd>
                </div>
                <div class="lf-import-preview-stat" data-validation-state="error">
                    <x-filament::icon icon="heroicon-o-x-circle" aria-hidden="true" />
                    <dt>Con errores</dt><dd>{{ $import->error_count }}</dd>
                </div>
                <div class="lf-import-preview-stat" data-validation-state="warning">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                    <dt>Omitidos / advertencias</dt><dd>{{ $import->skipped_count }}</dd>
                </div>
            </dl>
            <p class="lf-import-preview-operations">Operaciones previstas: <strong>{{ $import->created_count }} creaciones</strong> · <strong>{{ $import->updated_count }} actualizaciones</strong> · {{ $import->skipped_count }} omitidos · {{ $import->error_count }} rechazados.</p>
            <x-filament::callout
                :color="$import->error_count > 0 ? 'warning' : 'info'"
                :icon="$import->error_count > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'"
                :heading="$import->error_count > 0 ? 'Previsualización con observaciones — todavía sin cambios' : 'Validación completa — todavía sin cambios'"
                :description="$import->error_count > 0 ? 'Los errores impiden importar esas filas. Puede corregir los datos, volver al mapeo o continuar: solo se ejecutarán las filas válidas. Los registros omitidos no se importarán.' : 'Los datos están listos para revisar. Ningún registro ha sido creado ni actualizado. Los registros omitidos no se importarán.'"
            />
        </div>
    </section>

    <section class="lf-import-preview-card" aria-labelledby="import-rows-title">
        <header class="lf-import-preview-header">
            <div>
                <h2 id="import-rows-title"><x-filament::icon icon="heroicon-o-table-cells" aria-hidden="true" /> Detalle por fila</h2>
                <p>Consulte la operación, los cambios previstos y los conflictos de validación.</p>
            </div>
            <span class="lf-import-preview-filename">{{ $previewRows->total() }} de {{ $import->total }} registros</span>
        </header>
        <div class="lf-import-preview-filters">
            <div>
                <label for="import-status-filter">Filtrar por operación</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="import-status-filter" wire:model.live="statusFilter">
                        <option value="">Todas las operaciones</option>
                        <option value="create">Crear</option>
                        <option value="update">Actualizar</option>
                        <option value="skip">Omitir</option>
                        <option value="error">Error</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <label for="import-preview-search">Buscar registros</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="import-preview-search" type="search" wire:model.live.debounce.300ms="search" aria-describedby="import-search-help" />
                </x-filament::input.wrapper>
            </div>
            <p id="import-search-help">Busca en los valores originales, normalizados y corregidos, sin distinguir mayúsculas. No busca en mensajes de error. Estos filtros no cambian qué filas se importarán.</p>
        </div>
        @if ($previewRows->total() === 0)
            <p class="lf-import-preview-empty" role="status">No hay registros que coincidan con los filtros.</p>
        @endif
        <div class="lf-import-preview-rows">
            @include('filament.admin.pages.imports.preview-rows', ['rows' => $previewRows, 'editable' => $import->status === 'preview'])
        </div>
        <div class="lf-import-preview-pagination">
            <p role="status">Mostrando {{ $previewRows->firstItem() ?? 0 }}–{{ $previewRows->lastItem() ?? 0 }} de {{ $previewRows->total() }} registros</p>
            {{ $previewRows->onEachSide(1)->links('filament.admin.pages.imports.pagination') }}
        </div>
    </section>

    <footer class="lf-import-preview-card lf-import-preview-footer">
        <p><strong>{{ $import->error_count }} filas con errores</strong> no podrán importarse. Puede continuar para importar únicamente las filas válidas. Los registros omitidos no se importarán.</p>
        <div class="lf-import-preview-actions">
            <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-left" wire:click="remap">Anterior</x-filament::button>
            <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-down-tray" wire:click="downloadReport(false)">Descargar reporte completo</x-filament::button>
            <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-down-tray" wire:click="downloadReport(true)">Descargar errores</x-filament::button>
            <x-filament::button type="button" icon="heroicon-o-arrow-right" icon-position="after" wire:click="review" aria-describedby="import-review-help">Continuar</x-filament::button>
        </div>
        <span id="import-review-help" class="lf-import-preview-help">Ir a la confirmación final</span>
    </footer>
</div>
