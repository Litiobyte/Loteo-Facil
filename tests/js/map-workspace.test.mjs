import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/filament/admin/pages/mapa-loteo.blade.php', import.meta.url), 'utf8');
const script = blade.match(/x-data="([\s\S]*?)"/)[1];

function workspace() {
    const properties = new Map();
    let resizeEvents = 0;
    let disconnected = false;
    const window = {
        innerHeight: 900,
        scrollY: 0,
        dispatchEvent: () => resizeEvents++,
    };
    const component = runInNewContext(`(${script})`, {
        window,
        Event: class {},
        requestAnimationFrame: callback => callback(),
        ResizeObserver: class {
            observe() {}
            disconnect() { disconnected = true; }
        },
    });
    component.$refs = { workspace: { getBoundingClientRect: () => ({ top: 300 }) } };
    component.$el = {
        style: {
            getPropertyValue: key => properties.get(key),
            setProperty: (key, value) => properties.set(key, value),
        },
    };
    component.$nextTick = callback => callback();
    return { component, window, properties, resizeEvents: () => resizeEvents, disconnected: () => disconnected };
}

test('workspace fits below the toolbar and only asks the map to resize when its height changes', () => {
    const { component, properties, resizeEvents } = workspace();
    component.init();
    assert.equal(properties.get('--map-workspace-height'), '576px');
    assert.equal(resizeEvents(), 1);
    component.fitWorkspace();
    assert.equal(resizeEvents(), 1);
});

test('short viewports keep a usable map and release the observer when leaving the page', () => {
    const { component, window, properties, disconnected } = workspace();
    component.init();
    window.innerHeight = 500;
    component.fitWorkspace();
    assert.equal(properties.get('--map-workspace-height'), '320px');
    component.destroy();
    assert.equal(disconnected(), true);
});
