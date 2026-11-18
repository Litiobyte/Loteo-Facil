<x-filament-panels::page>
    @if (! $showCards)
        {{ $this->table }}
    @else
        @php
            $records = $this->getTableRecords();
            $features = $this->getCardFeatures($records->getCollection());
        @endphp
        <div class="flex flex-wrap items-center gap-4">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.500ms="tableSearch" placeholder="Buscar por código" aria-label="Buscar por código" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="tableSort" aria-label="Ordenar lotes">
                    <option value="">Orden predeterminado</option>
                    <option value="codigo:asc">Código ascendente</option>
                    <option value="codigo:desc">Código descendente</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <style>
            .owner-lot-cards { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.5rem; }
            .owner-lot-cards > * { min-width: 0; }
            @media (min-width: 640px) { .owner-lot-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
            @media (min-width: 1024px) { .owner-lot-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
            @media (min-width: 1280px) { .owner-lot-cards { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        </style>
        <div class="owner-lot-cards" data-owner-lot-cards>
            @forelse ($records as $lote)
                <x-filament::section wire:key="owner-lot-card-{{ $lote->id }}">
                    <x-slot name="heading">{{ $lote->codigo }}</x-slot>
                    <div class="space-y-4">
                        @if ($feature = $features->get($lote->id))
                            @include('filament.maps.viewer', [
                                'mapKey' => 'owner-lot-mini-'.$lote->id,
                                'featureCollection' => ['type' => 'FeatureCollection', 'features' => [$feature]],
                                'layerList' => collect(),
                                'highlightLoteId' => $lote->id,
                                'selectable' => false,
                                'fullscreen' => false,
                                'compact' => true,
                                'height' => 200,
                            ])
                        @else
                            <div class="flex items-center justify-center rounded-lg bg-gray-100 text-sm text-gray-500 dark:bg-gray-800" style="height: 200px">Ubicación pendiente</div>
                        @endif
                        <x-filament::badge>{{ $lote->estado }}</x-filament::badge>
                        <dl class="text-sm">
                            <dt class="text-gray-500">Superficie</dt>
                            <dd>{{ \App\Support\ChileanNumber::format($lote->metros_cuadrados) }} m² · {{ number_format((float) $lote->hectareas, 2, ',', '.') }} ha</dd>
                            <dt class="mt-2 text-gray-500">Etapa</dt>
                            <dd>{{ $lote->etapa?->nombre ?? 'Sin etapa' }}</dd>
                        </dl>
                        <x-filament::button tag="a" :href="\App\Filament\Owner\Resources\Lotes\LoteResource::getUrl('view', ['record' => $lote])" icon="heroicon-o-eye" color="info" size="sm">Ver detalle</x-filament::button>
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>No se encontraron lotes.</x-filament::section>
            @endforelse
        </div>
        <x-filament::pagination :paginator="$records" :page-options="$this->getTable()->getPaginationPageOptions()" />
    @endif
</x-filament-panels::page>
