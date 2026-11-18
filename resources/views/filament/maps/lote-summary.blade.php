@include('filament.maps.lote-detail-styles')

<x-filament::section icon="heroicon-o-home-modern">
    <x-slot name="heading">Lote {{ $lote->codigo }}</x-slot>
    <x-slot name="description">Información general del terreno</x-slot>

    <dl class="lote-detail-summary">
        <div>
            <dt>Estado</dt>
            <dd>
                <x-filament::badge :color="match ($lote->estado) {
                    'disponible' => 'success',
                    'reservado' => 'warning',
                    'vendido' => 'info',
                    default => 'gray',
                }">
                    {{ ucfirst($lote->estado) }}
                </x-filament::badge>
            </dd>
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
</x-filament::section>
