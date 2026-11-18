<x-filament-panels::page class="lf-import-history">
    @include('filament.admin.pages.imports.styles')
    <div class="border p-4 text-sm leading-6" style="background-color: var(--lf-surface); border-color: var(--lf-border); border-radius: var(--lf-radius-md); color: var(--lf-text-muted);">
        <p>En cargas y previsualizaciones los contadores indican operaciones previstas, no cambios ejecutados. Los archivos se conservan en almacenamiento privado. No se permite eliminación ni reversión masiva.</p>
    </div>
    {{ $this->table }}
    @if ($selectedImport)
        <x-filament::section :heading="'Importación #'.$selectedImport->id.' — '.$selectedImport->original_name">
            <div class="grid gap-4">
                <p class="text-sm leading-6" style="color: var(--lf-text-muted);">Estado: {{ \App\Filament\Admin\Pages\Imports\ImportHistory::statusLabel($selectedImport->status) }}. Se muestran hasta 100 filas; descargue el reporte para consultar todas.</p>
                @include('filament.admin.pages.imports.rows', ['rows' => $detailRows])
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
