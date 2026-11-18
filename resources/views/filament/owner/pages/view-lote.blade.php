<x-filament-panels::page>
    <div class="lote-detail-columns">
        <div class="lote-detail-header">
            @include('filament.maps.lote-summary')
        </div>

        <div class="lote-detail-grid">
            <div class="lote-detail-left">
                <x-filament::section heading="Ficha del lote" icon="heroicon-o-document-text">
                    <x-slot name="description">Datos del terreno · Solo lectura</x-slot>

                    <dl class="lote-detail-summary">
                        <div>
                            <dt>Código</dt>
                            <dd>{{ $lote->codigo }}</dd>
                        </div>
                        <div>
                            <dt>Etapa</dt>
                            <dd>{{ $lote->etapa?->nombre ?? 'Sin etapa asignada' }}</dd>
                        </div>
                        <div>
                            <dt>Superficie</dt>
                            <dd>{{ \App\Support\ChileanNumber::format($lote->metros_cuadrados) }} m²</dd>
                        </div>
                        <div>
                            <dt>Hectáreas</dt>
                            <dd>{{ number_format((float) $lote->hectareas, 2, ',', '.') }} ha</dd>
                        </div>
                    </dl>

                    <div class="lote-detail-actions">
                        <div>
                            <p class="lote-detail-muted">Notas</p>
                            <p class="lote-detail-notes">{{ filled($lote->notas) ? $lote->notas : 'Sin notas registradas.' }}</p>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            <div class="lote-detail-right">
                @include('filament.maps.lote-map-section')
            </div>
        </div>
    </div>
</x-filament-panels::page>
