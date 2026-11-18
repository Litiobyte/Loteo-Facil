<x-filament::section icon="heroicon-o-map" icon-color="primary">
    <x-slot name="heading">Ubicación en el mapa</x-slot>
    <x-slot name="description">Explora la ubicación del lote y su entorno dentro de la etapa.</x-slot>

    <div class="lote-detail-map">
        @include('filament.maps.lote-detail-map')
    </div>
</x-filament::section>
