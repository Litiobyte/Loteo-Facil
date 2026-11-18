<div class="space-y-4">
    <div class="grid grid-cols-2 gap-2 text-sm">
        <div><span class="text-gray-500">Código:</span> <strong>{{ $lote->codigo }}</strong></div>
        <div><span class="text-gray-500">Estado:</span> {{ $lote->estado }}</div>
        <div><span class="text-gray-500">Superficie:</span> {{ \App\Support\ChileanNumber::format($lote->metros_cuadrados) }} m²</div>
        <div><span class="text-gray-500">Etapa:</span> {{ $lote->etapa?->nombre ?? '—' }}</div>
    </div>
    @include('filament.maps.lote-detail-map')
</div>
