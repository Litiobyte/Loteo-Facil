<div class="mt-6">
    @if ($lote === null)
        <p class="text-sm text-gray-500">Selecciona un lote para verlo en el mapa.</p>
    @elseif ($featureCollection['features'] === [] && $status !== 'ok')
        <p class="text-sm text-gray-600">
            {{ match ($status) {
                'sin_mapa' => 'Aún no hay mapa publicado para la etapa de este lote.',
                'sin_asociacion' => 'Este lote todavía no tiene geometría asociada en el mapa publicado.',
                'duplicado' => 'Ubicación pendiente de revisión (varias geometrías candidatas).',
                default => 'Ubicación no disponible.',
            } }}
        </p>
    @else
        @include('filament.maps.viewer', [
            'featureCollection' => $featureCollection,
            'layerList' => $layerList,
            'mapKey' => 'lotes-split-'.$selectedEtapaId.'-'.$selectedLoteId,
            'highlightLoteId' => $selectedLoteId,
            'selectable' => false,
            'height' => 520,
        ])
    @endif
</div>
