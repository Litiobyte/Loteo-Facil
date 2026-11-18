<x-filament-panels::page>
    <div class="space-y-6">
        @if ($etapaId === null)
            <x-filament::section>Aún no hay mapas publicados.</x-filament::section>
        @else
            <x-filament::section heading="Mapa del Loteo">
                <label for="map-etapa">Etapa</label>
                <select id="map-etapa" wire:model.live="etapaId" class="mb-4 rounded-lg border-gray-300 dark:bg-gray-900">
                    @foreach ($etapas as $id) <option value="{{ $id }}">Etapa {{ $id }}</option> @endforeach
                </select>
                @include('filament.maps.viewer', [
                    'featureCollection' => $featureCollection,
                    'layerList' => $layerList,
                    'mapKey' => 'owner-'.$etapaId,
                ])
                @if ($selectedFeatureInfo)
                    <div class="mt-3 rounded-lg border p-3 dark:border-gray-700">
                        <strong>{{ $selectedFeatureInfo['codigo'] ?? $selectedFeatureInfo['name'] ?? 'Elemento' }}</strong>
                        @if (isset($selectedFeatureInfo['metros_cuadrados'])) <p>Superficie: {{ \App\Support\ChileanNumber::format($selectedFeatureInfo['metros_cuadrados']) }} m²</p> @endif
                        @if (! empty($selectedFeatureInfo['detail_url']))
                            <a class="text-primary-600 underline" href="{{ $selectedFeatureInfo['detail_url'] }}">Ver mi estado de cuenta</a>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
