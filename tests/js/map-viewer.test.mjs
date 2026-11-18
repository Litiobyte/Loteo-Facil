import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/filament/maps/viewer.blade.php', import.meta.url), 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1];

const stubDocument = { head: { appendChild() {} }, createElement: () => ({}), getElementById: () => null };

function viewer() {
    const stubDocument = {
        head: { appendChild() {} },
        createElement: () => ({}),
        getElementById: () => null,
    };
    const window = {};
    runInNewContext(script, { window, document: stubDocument });
    const features = [41, 98, 123].map((id, index) => ({
        type: 'Feature',
        id,
        geometry: { type: ['Polygon', 'LineString', 'Point'][index], coordinates: [] },
        properties: { lote_id: null },
    }));
    const component = window.loteoMapViewer({ type: 'FeatureCollection', features }, [], {}, {}, null, true);
    const layers = new Map();
    const sources = new Map();
    const calls = [];
    component.map = {
        getLayer: id => layers.get(id),
        getSource: id => sources.get(id),
        addSource: (id, source) => sources.set(id, source),
        addLayer: layer => layers.set(layer.id, layer),
        setFilter: (id, filter) => layers.get(id).filter = filter,
        on() {},
        flyTo() {},
        fitBounds() {},
        getZoom: () => 10,
    };
    component.$wire = { call: (...args) => calls.push(args) };
    component.featureBounds = () => [0, 0, 1, 1];
    component.fitAll = () => {};
    component.addGeometries();
    return { component, layers, sources, calls };
}

test('focusing an unassociated polygon highlights its persistent geometry ID', () => {
    const { component, layers, sources, calls } = viewer();
    component.focus(41);
    assert.equal(component.selected, 41);
    assert.equal(JSON.stringify(layers.get('highlight-fill').filter),
        JSON.stringify(['all', ['==', ['geometry-type'], 'Polygon'], ['==', ['id'], 41]]));
    assert.equal(sources.get('features').generateId, undefined);
    assert.equal(sources.get('features').data.features[0].id, 41);
    assert.deepEqual(calls[0], ['selectFeature', 41]);
});

test('selecting another element replaces the highlight for lines and points too', () => {
    const { component, layers } = viewer();
    component.focus(41);
    component.select(98);
    assert.equal(component.selected, 98);
    assert.equal(JSON.stringify(layers.get('highlight-border').filter.at(-1)), '["==",["id"],98]');
    component.select(123);
    assert.equal(JSON.stringify(layers.get('highlight-point').filter.at(-1)), '["==",["id"],123]');
});

test('switching basemap restores the selected geometry highlight', () => {
    const { component, layers, sources } = viewer();
    component.focus(41);
    layers.clear();
    sources.clear();
    component.addGeometries();
    assert.equal(JSON.stringify(layers.get('highlight-fill').filter.at(-1)), '["==",["id"],41]');
});

test('lot detail highlighting remains available and read-only viewers avoid server calls', () => {
    const { component, layers, calls } = viewer();
    component.geojson.features[0].properties.lote_id = 7;
    component.focusLote(7);
    assert.equal(JSON.stringify(layers.get('highlight-border').filter.at(-1)), '["==",["get","lote_id"],7]');
    component.selectable = false;
    component.select(41);
    assert.equal(calls.length, 0);
});

function initializeViewer(fullscreen) {
    const { component: existing, layers } = viewer();
    const controls = [];
    let resizes = 0;
    existing.map.addControl = control => controls.push(control);
    existing.map.resize = () => resizes++;
    const maplibregl = {
        Map: function () { return existing.map; },
        NavigationControl: class {},
        FullscreenControl: class {
            constructor(options) { this.options = options; this.events = {}; }
            on(name, callback) { this.events[name] = callback; }
        },
    };
    const window = { maplibregl, requestAnimationFrame: callback => callback() };
    runInNewContext(script, { window, maplibregl, document: stubDocument });
    const args = [existing.geojson, [], {}, {}, 7, false];
    if (fullscreen !== undefined) args.push(fullscreen);
    const component = window.loteoMapViewer(...args);
    component.$refs = { map: {} };
    component.$watch = () => {};
    component.$wire = { on() {} };
    component.init();
    return { component, controls, layers, resizes: () => resizes };
}

function compactViewer() {
    let observerCallback;
    let starts = 0;
    let disconnects = 0;
    let removals = 0;
    let scrollDisables = 0;
    const map = {
        scrollZoom: { disable() { scrollDisables++; } },
        on() {},
        remove() { removals++; },
    };
    const maplibregl = { Map: function () { starts++; return map; } };
    const window = {
        maplibregl,
        IntersectionObserver: class {
            constructor(callback) { observerCallback = callback; }
            observe() {}
            disconnect() { disconnects++; }
        },
    };
    runInNewContext(script, { window, maplibregl, document: stubDocument });
    const component = window.loteoMapViewer({ features: [] }, [], {}, {}, 7, false, false, true);
    component.$refs = { map: {} };
    component.$watch = () => {};
    component.$wire = { on() { assert.fail('Minimaps must not register server listeners'); } };
    component.init();
    return {
        component,
        intersect: value => observerCallback([{ isIntersecting: value }]),
        counts: () => ({ starts, disconnects, removals, scrollDisables }),
    };
}

test('compact maps start only near the viewport and disable scroll zoom without server listeners', () => {
    const { component, intersect, counts } = compactViewer();
    assert.equal(counts().starts, 0);
    intersect(false);
    assert.equal(counts().starts, 0);
    intersect(true);
    assert.deepEqual(counts(), { starts: 1, disconnects: 1, removals: 0, scrollDisables: 1 });
    component.destroy();
    assert.equal(counts().removals, 1);
    assert.equal(component.map, null);
});

test('removing a lazy card before intersection prevents map allocation', () => {
    const { component, intersect, counts } = compactViewer();
    component.destroy();
    intersect(true);
    assert.equal(counts().starts, 0);
});

test('destroy cancels pending library initialization and releases Livewire subscriptions', () => {
    const window = {
        setTimeout: () => 42,
        clearTimeout: id => assert.equal(id, 42),
    };
    runInNewContext(script, { window, document: stubDocument });
    const component = window.loteoMapViewer({ features: [] }, [], {}, {}, null, false);
    let cleanups = 0;
    component.$watch = () => {};
    component.$wire = { on: () => () => cleanups++ };
    component.init();
    assert.equal(component.retry, 42);
    component.destroy();
    assert.equal(cleanups, 2);
    assert.equal(component.disposed, true);
});

test('fullscreen is opt-in and leaves import viewers without an extra control', () => {
    const { controls } = initializeViewer();
    assert.equal(controls.length, 1);
});

test('detail fullscreen targets only the map and resizes on enter and exit without losing highlight or selection', () => {
    const { component, controls, layers, resizes } = initializeViewer(true);
    assert.equal(controls.length, 2);
    const fullscreen = controls[1];
    assert.equal(fullscreen.options.container, component.$refs.map);
    component.highlightFeature(41);
    const filter = JSON.stringify(layers.get('highlight-fill').filter);
    fullscreen.events.fullscreenstart();
    fullscreen.events.fullscreenend();
    assert.equal(resizes(), 2);
    assert.equal(component.selected, 41);
    assert.equal(component.highlightLoteId, 7);
    assert.equal(JSON.stringify(layers.get('highlight-fill').filter), filter);
});

test('Escape exits fullscreen without dismissing the owner modal, but normal Escape remains untouched', () => {
    const handler = blade.match(/@keydown\.escape\.window\.capture="([^"]+)"/)[1];
    let active = true;
    let exits = 0;
    let stopped = 0;
    const context = {
        fullscreen: true,
        $refs: {
            map: {
                querySelector: () => active ? { click() { exits++; active = false; } } : null,
            },
        },
        $event: { stopImmediatePropagation() { stopped++; } },
    };
    runInNewContext(handler, context);
    assert.equal(exits, 1);
    assert.equal(stopped, 1);
    runInNewContext(handler, context);
    assert.equal(exits, 1);
    assert.equal(stopped, 1);
});
