@include('filament.maps.lote-detail-styles')
@php($info = app(\App\Domain\Maps\Services\MapPersistenceService::class)->loteFeature($lote->id, $adminView))
@if ($info['status'] === 'ok' && $info['feature'] !== null)
    @php($collection = app(\App\Domain\Maps\Services\MapPersistenceService::class)->publishedGeoJson((int) $info['etapa_id'], $adminView))
    @include('filament.maps.viewer', [
        'featureCollection' => $collection,
        'layerList' => $layerList,
        'mapKey' => 'lote-detail-'.$lote->id.'-'.$info['etapa_id'],
        'highlightLoteId' => $lote->id,
        'selectable' => false,
        'fullscreen' => true,
        'height' => 380,
    ])
    <p class="lote-detail-legend lote-detail-muted">El contorno naranja destaca el lote {{ $lote->codigo }}.</p>
@else
    <div class="lote-detail-empty" role="status">
        <x-filament::icon icon="heroicon-o-map" class="h-8 w-8 text-gray-400" />
        <p class="font-medium">Ubicación pendiente</p>
        <p class="lote-detail-muted">
        {{ match ($info['status']) {
            'sin_mapa' => 'Aún no hay mapa publicado para la etapa de este lote.',
            'sin_asociacion' => 'Este lote todavía no tiene geometría asociada en el mapa publicado.',
            'duplicado' => 'La ubicación de este lote está pendiente de revisión (hay varias geometrías candidatas).',
            'lote_inexistente' => 'Lote inexistente.',
            default => 'Ubicación no disponible.',
        } }}
        </p>
    </div>
@endif
