@script
<script>
    if (!window.loteoMapViewerAssets) {
        window.loteoMapViewerAssets = true;
        const maplibreCss = document.createElement('link');
        maplibreCss.rel = 'stylesheet';
        maplibreCss.href = 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css';
        document.head.appendChild(maplibreCss);
        const maplibreJs = document.createElement('script');
        maplibreJs.src = 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js';
        document.head.appendChild(maplibreJs);
    }
    window.loteoMapViewer = (geojson, layers, satellite, streets, highlightLoteId, selectable, fullscreen = false, compact = false) => ({
            map: null,
            observer: null,
            retry: null,
            disposed: false,
            cleanups: [],
            compact,
            geojson,
            layers,
            mode: 'satellite',
            selected: null,
            highlightLoteId,
            selectable,
            fullscreen,
            visible: Object.fromEntries(layers.map(layer => [layer.id, Boolean(layer.is_visible)])),
            init() {
                const start = () => {
                    if (this.disposed) return;
                    if (!window.maplibregl) {
                        this.retry = window.setTimeout(start, 100);
                        return;
                    }
                    this.map = new maplibregl.Map({
                        container: this.$refs.map,
                        style: this.styleFor(satellite),
                        center: [-71, -33],
                        zoom: 8,
                        attributionControl: true,
                        locale: {
                            'FullscreenControl.Enter': 'Ver mapa en pantalla completa',
                            'FullscreenControl.Exit': 'Salir de pantalla completa',
                        },
                    });
                    if (this.compact) this.map.scrollZoom.disable();
                    else this.map.addControl(new maplibregl.NavigationControl(), 'top-right');
                    if (this.fullscreen) {
                        const control = new maplibregl.FullscreenControl({ container: this.$refs.map });
                        const resize = () => window.requestAnimationFrame(() => this.map?.resize());
                        control.on('fullscreenstart', resize);
                        control.on('fullscreenend', resize);
                        this.map.addControl(control, 'top-right');
                    }
                    this.map.on('load', () => this.addGeometries());
                    this.map.on('style.load', () => this.addGeometries());
                };
                if (this.compact && window.IntersectionObserver) {
                    this.observer = new window.IntersectionObserver(entries => {
                        if (!entries.some(entry => entry.isIntersecting)) return;
                        this.observer.disconnect();
                        start();
                    }, { rootMargin: '100px' });
                    this.observer.observe(this.$refs.map);
                } else start();
                this.$watch('mode', value => this.map?.setStyle(this.styleFor(value === 'satellite' ? satellite : streets)));
                if (!this.compact) {
                    this.cleanups.push(this.$wire.on('map-focus', ({ id }) => this.focus(id)));
                    this.cleanups.push(this.$wire.on('map-focus-lote', ({ loteId }) => this.focusLote(loteId)));
                }
            },
            destroy() {
                this.disposed = true;
                this.observer?.disconnect();
                if (this.retry !== null) window.clearTimeout(this.retry);
                this.cleanups.forEach(cleanup => { if (typeof cleanup === 'function') cleanup(); });
                this.map?.remove();
                this.map = null;
            },
            styleFor(tile) {
                return {
                    version: 8,
                    sources: { raster: { type: 'raster', tiles: [tile.url], tileSize: 256, attribution: tile.attribution } },
                    layers: [{ id: 'base', type: 'raster', source: 'raster' }],
                };
            },
            addGeometries() {
                if (!this.map || this.map.getSource('features')) return;
                this.map.addSource('features', { type: 'geojson', data: this.geojson });
                this.map.addLayer({
                    id: 'polygons', type: 'fill', source: 'features',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: { 'fill-color': ['coalesce', ['get', 'color'], '#f59e0b'], 'fill-opacity': ['coalesce', ['get', 'opacity'], 0.6] },
                });
                this.map.addLayer({
                    id: 'borders', type: 'line', source: 'features',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: { 'line-color': ['coalesce', ['get', 'color'], '#f59e0b'], 'line-width': 2 },
                });
                this.map.addLayer({
                    id: 'lines', type: 'line', source: 'features',
                    filter: ['==', ['geometry-type'], 'LineString'],
                    paint: { 'line-color': ['coalesce', ['get', 'color'], '#8b5cf6'], 'line-width': 4 },
                });
                this.map.addLayer({
                    id: 'points', type: 'circle', source: 'features',
                    filter: ['==', ['geometry-type'], 'Point'],
                    paint: { 'circle-radius': 6, 'circle-color': ['coalesce', ['get', 'color'], '#e11d48'] },
                });
                this.map.addLayer({
                    id: 'own-lots', type: 'line', source: 'features',
                    filter: ['==', ['get', 'is_own'], true],
                    paint: { 'line-color': '#22c55e', 'line-width': 5 },
                });
                this.map.addLayer({
                    id: 'highlight-fill', type: 'fill', source: 'features',
                    filter: ['==', ['get', 'lote_id'], -1],
                    paint: { 'fill-color': '#f97316', 'fill-opacity': 0.35 },
                });
                this.map.addLayer({
                    id: 'highlight-border', type: 'line', source: 'features',
                    filter: ['==', ['get', 'lote_id'], -1],
                    paint: { 'line-color': '#f97316', 'line-width': 6 },
                });
                this.map.addLayer({
                    id: 'highlight-point', type: 'circle', source: 'features',
                    filter: ['==', ['id'], -1],
                    paint: { 'circle-radius': 10, 'circle-color': '#f97316', 'circle-stroke-color': '#ffffff', 'circle-stroke-width': 3 },
                });
                this.map.on('click', event => {
                    const feature = this.map.queryRenderedFeatures(event.point, { layers: ['polygons', 'lines', 'points'] })[0];
                    if (feature) {
                        this.selected = Number(feature.id);
                        this.select(Number(feature.id));
                    }
                });
                this.applyVisibility();
                if (this.selected !== null) {
                    this.highlightFeature(this.selected);
                } else if (this.highlightLoteId !== null && this.highlightLoteId !== undefined) {
                    this.focusLote(this.highlightLoteId);
                } else {
                    this.fitAll();
                }
            },
            featureBounds(feature) {
                const coords = [];
                const visit = value => {
                    if (Array.isArray(value) && typeof value[0] === 'number') coords.push(value);
                    else if (Array.isArray(value)) value.forEach(visit);
                };
                visit(feature.geometry.coordinates);
                if (!coords.length) return null;
                const lons = coords.map(point => point[0]);
                const lats = coords.map(point => point[1]);
                const bounds = new maplibregl.LngLatBounds([Math.min(...lons), Math.min(...lats)], [Math.max(...lons), Math.max(...lats)]);
                return [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()];
            },
            fitAll() {
                if (!this.map) return;
                const bounds = this.geojson.features
                    .map(feature => this.featureBounds(feature))
                    .filter(Boolean)
                    .reduce((acc, box) => {
                        acc[0] = Math.min(acc[0], box[0]); acc[1] = Math.min(acc[1], box[1]);
                        acc[2] = Math.max(acc[2], box[2]); acc[3] = Math.max(acc[3], box[3]);
                        return acc;
                    }, [Infinity, Infinity, -Infinity, -Infinity]);
                if (bounds[0] !== Infinity) this.map.fitBounds([[bounds[0], bounds[1]], [bounds[2], bounds[3]]], { padding: 35, maxZoom: 17 });
            },
            highlightLote(loteId) {
                if (!this.map?.getLayer('highlight-fill')) return;
                const has = this.geojson.features.some(feature => Number(feature.properties?.lote_id) === Number(loteId));
                this.setHighlight(['==', ['get', 'lote_id'], has ? Number(loteId) : -1]);
            },
            setHighlight(filter) {
                if (!this.map?.getLayer('highlight-fill')) return;
                this.map.setFilter('highlight-fill', ['all', ['==', ['geometry-type'], 'Polygon'], filter]);
                this.map.setFilter('highlight-border', ['all', ['!=', ['geometry-type'], 'Point'], filter]);
                this.map.setFilter('highlight-point', ['all', ['==', ['geometry-type'], 'Point'], filter]);
            },
            highlightFeature(id) {
                this.selected = Number(id);
                this.setHighlight(['==', ['id'], this.selected]);
            },
            focusLote(loteId) {
                if (!this.map) return;
                const feature = this.geojson.features.find(feature => Number(feature.properties?.lote_id) === Number(loteId));
                if (!feature) return;
                this.highlightLote(loteId);
                const box = this.featureBounds(feature);
                if (box) this.map.fitBounds([[box[0], box[1]], [box[2], box[3]]], { padding: this.compact ? 25 : 80, maxZoom: 17, duration: this.compact ? 0 : 700 });
            },
            focus(id) {
                const feature = this.geojson.features.find(feature => Number(feature.id) === Number(id));
                if (!feature || !this.map) return;
                this.highlightFeature(id);
                const box = this.featureBounds(feature);
                if (box) {
                    const center = [(box[0] + box[2]) / 2, (box[1] + box[3]) / 2];
                    this.map.flyTo({ center, zoom: Math.max(this.map.getZoom(), 16), duration: 700 });
                }
                if (this.selectable) this.$wire.call('selectFeature', Number(id));
            },
            select(id) {
                this.highlightFeature(id);
                if (this.selectable) this.$wire.call('selectFeature', Number(id));
            },
            applyVisibility() {
                if (!this.map?.getSource('features')) return;
                const hidden = this.layers.filter(layer => !this.visible[layer.id]).map(layer => layer.id);
                const filter = ['!', ['in', ['get', 'layer_id'], ['literal', hidden]]];
                for (const id of ['polygons', 'borders', 'lines', 'points', 'own-lots']) {
                    if (this.map.getLayer(id)) {
                        const geometryFilter = id === 'polygons' || id === 'borders' ? ['==', ['geometry-type'], 'Polygon']
                            : id === 'lines' ? ['==', ['geometry-type'], 'LineString']
                            : id === 'points' ? ['==', ['geometry-type'], 'Point']
                            : ['==', ['get', 'is_own'], true];
                        this.map.setFilter(id, ['all', geometryFilter, filter]);
                    }
                }
            },
        });
    </script>
@endscript

<div wire:key="{{ $mapKey }}" x-data="loteoMapViewer(
    @js($featureCollection),
    @js($layerList->map(fn ($layer) => ['id' => $layer->id, 'name' => $layer->name, 'is_visible' => $layer->is_visible])->values()->all()),
    @js(['url' => config('map.satellite_url'), 'attribution' => config('map.satellite_attribution')]),
    @js(['url' => config('map.streets_url'), 'attribution' => config('map.streets_attribution')]),
    @js($highlightLoteId ?? null),
    @js($selectable ?? true),
    @js($fullscreen ?? false)@if ($compact ?? false), true @endif
)" @keydown.escape.window.capture="if (fullscreen && $refs.map.querySelector('.maplibregl-ctrl-shrink')) { $event.stopImmediatePropagation(); $refs.map.querySelector('.maplibregl-ctrl-shrink').click(); }">
    @unless ($compact ?? false)
    <div class="mb-3 flex flex-wrap items-center gap-3">
        <label>Fondo
            <select x-model="mode" class="rounded-lg border-gray-300 dark:bg-gray-900">
                <option value="satellite">Satélite</option>
                <option value="streets">Calles</option>
            </select>
        </label>
        <template x-for="layer in layers" :key="layer.id">
            <label class="flex items-center gap-1">
                <input type="checkbox" x-model="visible[layer.id]" @change="applyVisibility()">
                <span x-text="layer.name"></span>
            </label>
        </template>
    </div>
    @endunless
    <div x-ref="map" wire:ignore style="height: {{ $height ?? 480 }}px; width: 100%; border-radius: 0.5rem; background: #e5e7eb;"></div>
</div>
