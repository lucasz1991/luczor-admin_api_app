import test from 'node:test';
import assert from 'node:assert/strict';
import { createTabs } from '../../resources/js/ui-components.js';

function fixture(options = {}) {
    const saved = new Map();
    globalThis.sessionStorage = { getItem: key => saved.get(key), setItem: (key, value) => saved.set(key, value) };
    globalThis.location = { pathname: '/example', hash: '' };
    const focused = [];
    const elements = new Map(['one', 'two', 'three'].map(name => [`test-tab-${name}`, { focus: () => focused.push(name), scrollIntoView() {} }]));
    globalThis.document = { getElementById: id => elements.get(id) };
    const handlers = new Map();
    const root = { addEventListener: (type, fn) => handlers.set(type, fn), removeEventListener: type => handlers.delete(type), querySelector: () => null, querySelectorAll: () => [], contains: () => true };
    const tabs = createTabs({ id: 'test', initial: 'one', names: ['one','two','three'], ...options });
    tabs.$root = root;
    tabs.$nextTick = fn => fn();
    return { tabs, saved, focused, root, handlers, elements };
}

test('arrow and boundary keys wrap focus and ignore unknown tabs', () => {
    const { tabs, focused } = fixture();
    tabs.init(); tabs.moveTab(-1); tabs.moveTab(1); tabs.moveToBoundary(true);
    assert.deepEqual(focused, ['three', 'one', 'three']);
    tabs.selectTab('untrusted');
    assert.equal(tabs.openTab, 'three');
});
test('validation selection overrides previous session and hash', () => {
    const { tabs, saved } = fixture({ initial: 'two', forceActive: true });
    saved.set('luczor.tabs:/example:test', 'three');
    location.hash = '#test-one';
    tabs.init();
    assert.equal(tabs.openTab, 'two');
});
test('missing stored tabs fall back while valid hash links open their panel', () => {
    const { tabs, saved } = fixture();
    saved.set('luczor.tabs:/example:test', 'deleted');
    location.hash = '#test-three';
    tabs.init();
    assert.equal(tabs.openTab, 'three');
});
test('hidden required fields open synchronously and first invalid field wins the batch', async () => {
    const { tabs, root, handlers } = fixture();
    const first = {}, second = {};
    const panels = [[first, 'two'], [second, 'three']].map(([field, name]) => ({ contains: target => target === field, closest: () => root, dataset: { uiTabPanel: name }, style: { display: 'none' }, inert: true, removeAttribute() {} }));
    root.querySelectorAll = () => panels;
    tabs.init();
    handlers.get('invalid')({ target: first });
    await Promise.resolve();
    handlers.get('invalid')({ target: second });
    assert.equal(tabs.openTab, 'two');
    assert.equal(panels[0].style.display, '');
    assert.equal(panels[0].inert, false);
    await new Promise(resolve => setTimeout(resolve, 0));
    handlers.get('invalid')({ target: second });
    assert.equal(tabs.openTab, 'three');
    tabs.destroy();
    assert.equal(handlers.has('invalid'), false);
});
test('panels in independent tabs do not change each other', () => {
    const { tabs, root } = fixture();
    root.querySelectorAll = () => [{ contains: () => true, closest: () => ({}) }];
    tabs.openContainingPanel({});
    assert.equal(tabs.openTab, 'one');
});
