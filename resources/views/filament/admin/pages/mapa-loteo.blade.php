<x-filament-panels::page>
    @include('filament.admin.pages.partials.map-workspace-styles')

    <div
        class="map-import-page"
        x-data="{
            tab: 'review',
            resizeObserver: null,
            init() {
                this.$nextTick(() => {
                    this.resizeObserver = new ResizeObserver(() => this.fitWorkspace());
                    this.resizeObserver.observe(this.$el);
                    this.fitWorkspace();
                });
            },
            destroy() {
                this.resizeObserver?.disconnect();
            },
            fitWorkspace() {
                const top = this.$refs.workspace.getBoundingClientRect().top + window.scrollY;
                const height = `${Math.max(320, window.innerHeight - top - 24)}px`;
                if (this.$el.style.getPropertyValue('--map-workspace-height') === height) return;
                this.$el.style.setProperty('--map-workspace-height', height);
                requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
            },
        }"
        x-on:resize.window.debounce.100ms="fitWorkspace()"
    >
        @if ($errors->has('decisions.*'))
            <div role="alert" class="rounded-lg border border-red-400 p-4 text-sm text-red-600">
                <p>Revisa las asociaciones y capas seleccionadas antes de guardar.</p>
                @foreach ($errors->get('decisions.*') as $messages)
                    @foreach ($messages as $message) <p>{{ $message }}</p> @endforeach
                @endforeach
            </div>
        @endif

        <x-filament::section heading="Importar mapa" compact>
            <div class="map-import-toolbar">
                <div>
                    <label for="map-etapa" class="map-field-label">Proyecto / etapa</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="map-etapa" wire:model.live="etapaId">
                            @foreach ($etapas as $etapa)
                                <option value="{{ $etapa->id }}">Etapa {{ $etapa->numero }} — {{ $etapa->nombre }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <form wire:submit="import" class="map-import-upload">
                    <div>
                        <label for="map-file" class="map-field-label">Archivo KMZ, KML o GeoJSON</label>
                        <input id="map-file" type="file" wire:model="file" accept=".kmz,.kml,.geojson,.json" class="map-file-input">
                    </div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="import,file">Importar y revisar</x-filament::button>
                </form>
            </div>
            @error('file') <p role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('etapaId') <p role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            <p role="status" wire:loading wire:target="import,file">Procesando archivo…</p>
        </x-filament::section>

        <div class="map-workspace" x-ref="workspace">
            <x-filament::section heading="Mapa" compact class="map-workspace-map">
                @include('filament.maps.viewer', [
                    'featureCollection' => $featureCollection,
                    'layerList' => $layerList,
                    'mapKey' => 'admin-'.($etapaId ?? 'none').'-'.($importId ?? 'published'),
                ])
            </x-filament::section>

            <aside class="map-workspace-sidebar" aria-label="Elementos y configuración del mapa">
                <x-filament::tabs label="Gestión del mapa" contained>
                    <x-filament::tabs.item id="map-tab-review" alpine-active="tab === 'review'" x-on:click="tab = 'review'" x-bind:aria-selected="tab === 'review'" aria-controls="map-panel-review">Revisión</x-filament::tabs.item>
                    <x-filament::tabs.item id="map-tab-layers" alpine-active="tab === 'layers'" x-on:click="tab = 'layers'" x-bind:aria-selected="tab === 'layers'" aria-controls="map-panel-layers" :badge="$layerList->count()">Capas</x-filament::tabs.item>
                    <x-filament::tabs.item id="map-tab-history" alpine-active="tab === 'history'" x-on:click="tab = 'history'" x-bind:aria-selected="tab === 'history'" aria-controls="map-panel-history">Historial</x-filament::tabs.item>
                </x-filament::tabs>

                <div class="map-panel-scroll" tabindex="0" aria-label="Panel del mapa con desplazamiento independiente">
                    @if ($selectedFeatureInfo)
                        <x-filament::section heading="Elemento seleccionado" compact class="map-selected-card">
                            <strong>{{ $selectedFeatureInfo['codigo'] ?? $selectedFeatureInfo['name'] ?? 'Elemento' }}</strong>
                            <dl class="map-feature-details">
                                @if (isset($selectedFeatureInfo['metros_cuadrados']))
                                    <div><dt>Superficie</dt><dd>{{ \App\Support\ChileanNumber::format($selectedFeatureInfo['metros_cuadrados']) }} m²</dd></div>
                                @endif
                                @if (isset($selectedFeatureInfo['estado']))
                                    <div><dt>Estado</dt><dd>{{ $selectedFeatureInfo['estado'] }}</dd></div>
                                @endif
                                @if (isset($selectedFeatureInfo['valor_lote']))
                                    <div><dt>Valor</dt><dd>{{ $selectedFeatureInfo['valor_lote'] }}</dd></div>
                                @endif
                                @if (isset($selectedFeatureInfo['layer']))
                                    <div><dt>Capa</dt><dd>{{ $selectedFeatureInfo['layer'] }}</dd></div>
                                @endif
                                @if (! empty($selectedFeatureInfo['propietarios']))
                                    <div><dt>Propietarios</dt><dd>{{ implode(', ', $selectedFeatureInfo['propietarios']) }}</dd></div>
                                @endif
                            </dl>
                            @if (! empty($selectedFeatureInfo['detail_url']))
                                <x-filament::button tag="a" :href="$selectedFeatureInfo['detail_url']" color="gray" size="sm">Ver lote</x-filament::button>
                            @endif
                        </x-filament::section>
                    @endif

                    <div id="map-panel-review" role="tabpanel" aria-labelledby="map-tab-review" x-show="tab === 'review'">
                        <x-filament::section heading="Validación de importación" compact>
                            @if ($import)
                                <p class="map-panel-caption">{{ $import->filename }}</p>
                                <div class="map-review-stats">
                                    @foreach (['total' => 'Elementos', 'exact' => 'Exactas', 'probable' => 'Probables', 'ambiguous' => 'Ambiguas', 'none' => 'Sin asociación'] as $key => $label)
                                        <div class="map-stat-card">
                                            <strong>{{ $key === 'total' ? ($stats['total'] ?? 0) : ($stats['by_match'][$key] ?? 0) }}</strong>
                                            <span>{{ $label }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <label for="map-status-filter" class="map-field-label">Filtrar coincidencias</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="map-status-filter" wire:model.live="statusFilter">
                                        <option value="all">Todos</option>
                                        <option value="exact">Exactas</option>
                                        <option value="probable">Probables</option>
                                        <option value="ambiguous">Ambiguas</option>
                                        <option value="none">Sin coincidencia / otras</option>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                <div class="map-review-actions">
                                    <x-filament::button type="button" color="gray" size="sm" wire:click="confirmAllExact">Confirmar todas las exactas</x-filament::button>
                                    <x-filament::button type="button" color="gray" size="sm" wire:click="applyReview">Guardar revisión</x-filament::button>
                                    <x-filament::button type="button" size="sm" wire:click="publish" wire:confirm="¿Publicar esta versión del mapa?">Publicar mapa</x-filament::button>
                                </div>
                                <div class="map-review-table-wrap">
                                    <table class="map-review-table">
                                        <caption class="sr-only">Elementos importados y asociaciones con lotes</caption>
                                        <thead><tr><th scope="col">Elemento / coincidencia</th><th scope="col">Asociación</th></tr></thead>
                                        <tbody>
                                            @forelse ($reviewRows as $row)
                                                <tr wire:key="map-geometry-{{ $row['id'] }}" @class(['map-row-selected' => $selectedGeometryId === $row['id']])>
                                                    <td>
                                                        <button type="button" class="map-element-link" wire:click="highlightFeature({{ $row['id'] }})" aria-label="Ver {{ $row['name'] }} en el mapa">{{ $row['name'] }}</button>
                                                        <x-filament::badge :color="match ($row['match']) { 'exact' => 'success', 'probable' => 'info', 'ambiguous' => 'warning', default => 'gray' }">
                                                            {{ match ($row['match']) { 'exact' => 'Exacta', 'probable' => 'Probable', 'ambiguous' => 'Ambigua', 'none' => 'Sin asociación', 'manual' => 'Manual', default => $row['match'] } }}@if ($row['confidence']) · {{ $row['confidence'] }}%@endif
                                                        </x-filament::badge>
                                                        @foreach ($row['warnings'] as $warning) <p class="mt-2 text-xs text-amber-600">{{ $warning }}</p> @endforeach
                                                    </td>
                                                    <td>
                                                        <label for="map-layer-{{ $row['id'] }}" class="map-field-label">Capa</label>
                                                        <x-filament::input.wrapper>
                                                            <x-filament::input.select id="map-layer-{{ $row['id'] }}" wire:model="decisions.{{ $row['id'] }}.layer_id">
                                                                @foreach ($layerList as $layer) <option value="{{ $layer->id }}">{{ $layer->name }}</option> @endforeach
                                                            </x-filament::input.select>
                                                        </x-filament::input.wrapper>
                                                        <label for="map-lote-{{ $row['id'] }}" class="map-field-label">Lote</label>
                                                        <x-filament::input.wrapper>
                                                            <x-filament::input.select id="map-lote-{{ $row['id'] }}" wire:model="decisions.{{ $row['id'] }}.lote_id">
                                                                <option value="">Sin lote</option>
                                                                @foreach ($lotes as $lote) <option value="{{ $lote->id }}">{{ $lote->codigo }}</option> @endforeach
                                                            </x-filament::input.select>
                                                        </x-filament::input.wrapper>
                                                        <label class="map-checkbox-label"><input type="checkbox" wire:model="decisions.{{ $row['id'] }}.confirm"> Confirmado</label>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="2">No hay elementos para este filtro.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p>Importa un archivo o selecciona una versión del historial para revisar sus elementos junto al mapa.</p>
                            @endif
                        </x-filament::section>
                    </div>

                    <div id="map-panel-layers" role="tabpanel" aria-labelledby="map-tab-layers" x-show="tab === 'layers'" x-cloak>
                        <x-filament::section heading="Capas" compact>
                            <div class="map-layer-list">
                                @forelse ($layerList as $layer)
                                    <div class="map-layer-card" wire:key="layer-config-{{ $layer->id }}">
                                        <label for="map-layer-name-{{ $layer->id }}" class="map-field-label">Nombre de capa</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input id="map-layer-name-{{ $layer->id }}" type="text" wire:model="layerForm.{{ $layer->id }}.name" />
                                        </x-filament::input.wrapper>
                                        <div class="map-layer-options">
                                            <label>Color <input type="color" wire:model="layerForm.{{ $layer->id }}.color" aria-label="Color de capa {{ $layer->name }}"></label>
                                            <label>Opacidad
                                                <input type="number" min="0" max="100" wire:model="layerForm.{{ $layer->id }}.opacity" aria-label="Opacidad de capa {{ $layer->name }}">
                                            </label>
                                        </div>
                                        <div class="map-layer-options">
                                            <label class="map-checkbox-label"><input type="checkbox" wire:model="layerForm.{{ $layer->id }}.is_visible"> Visible</label>
                                            <label class="map-checkbox-label"><input type="checkbox" wire:model="layerForm.{{ $layer->id }}.is_public"> Pública</label>
                                        </div>
                                    </div>
                                @empty
                                    <p>No hay capas en esta etapa.</p>
                                @endforelse
                            </div>
                            @if ($layerList->isNotEmpty())
                                <div class="mt-4"><x-filament::button wire:click="saveLayers">Guardar capas</x-filament::button></div>
                            @endif
                        </x-filament::section>
                    </div>

                    <div id="map-panel-history" role="tabpanel" aria-labelledby="map-tab-history" x-show="tab === 'history'" x-cloak>
                        <x-filament::section heading="Historial de importaciones" compact>
                            <div class="map-history-list">
                                @forelse ($history as $past)
                                    <article class="map-layer-card" wire:key="map-history-{{ $past->id }}">
                                        <button type="button" class="map-element-link" wire:click="$set('importId', {{ $past->id }})" x-on:click="tab = 'review'">{{ $past->filename }}</button>
                                        <div class="map-layer-options">
                                            <x-filament::badge color="gray">{{ $past->status }}</x-filament::badge>
                                            <time datetime="{{ $past->created_at->toIso8601String() }}">{{ $past->created_at->format('d/m/Y H:i') }}</time>
                                        </div>
                                    </article>
                                @empty
                                    <p>Aún no hay importaciones.</p>
                                @endforelse
                            </div>
                        </x-filament::section>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
