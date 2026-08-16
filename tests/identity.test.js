/**
 * Tests de view/frontend/web/js/identity.js, sin Magento ni navegador.
 *
 *   node tests/identity.test.js
 *
 * Lo que se prueba es el orden de carga, que es donde esto se rompe de verdad:
 * el loader del widget es `async`, así que identity.js puede correr antes o
 * después de que exista `window.Mindo`, y los dos caminos tienen que terminar
 * en la misma llamada.
 *
 * @copyright Mindo Software
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SOURCE = fs.readFileSync(
    path.join(__dirname, '..', 'view', 'frontend', 'web', 'js', 'identity.js'),
    'utf8'
);

let passed = 0;
const failed = [];

function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ok   ${name}`);
    } catch (e) {
        failed.push(`${name} — ${e.message}`);
        console.log(`  FAIL ${name}\n       ${e.message}`);
    }
}

function assertDeep(expected, actual, what) {
    const a = JSON.stringify(expected);
    const b = JSON.stringify(actual);
    if (a !== b) {
        throw new Error(`${what || ''}: esperaba ${a}, llegó ${b}`);
    }
}

/** Observable estilo knockout: se invoca para leer, `.subscribe` para escuchar. */
function observable(initial) {
    let value = initial;
    const subs = [];
    const self = function () {
        return value;
    };
    self.subscribe = (cb) => subs.push(cb);
    self.set = (next) => {
        value = next;
        subs.forEach((cb) => cb(next));
    };

    return self;
}

/**
 * Carga identity.js con un `window` y un customer-data fabricados.
 * Devuelve el sandbox para inspeccionar `window.Mindo`.
 */
function load(sectionData, mindoInitial) {
    const section = observable(sectionData);
    const sandbox = {
        window: { Mindo: mindoInitial },
        console,
        define: (deps, factory) => {
            sandbox.__component = factory({ get: () => section });
        }
    };
    sandbox.section = section;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);
    sandbox.__component();

    return sandbox;
}

/** Réplica de cómo widget.js drena la cola cuando monta. */
function drain(mindo, calls) {
    const queued = mindo.q || [];
    const api = {
        setUser: (v) => calls.push(['setUser', v]),
        clearUser: () => calls.push(['clearUser']),
        q: []
    };
    queued.forEach((call) => {
        if (api[call[0]]) {
            api[call[0]].apply(api, call.slice(1));
        }
    });

    return api;
}

console.log('\nidentity.js\n');

test('identity.js antes que widget.js: encola y el drain la ejecuta', () => {
    const sandbox = load({ identity: 'jwt-1' }, { q: [] });
    assertDeep([['setUser', 'jwt-1']], sandbox.window.Mindo.q, 'cola');

    const calls = [];
    drain(sandbox.window.Mindo, calls);
    assertDeep([['setUser', 'jwt-1']], calls, 'llamadas tras el drain');
});

test('widget.js ya cargado: llama directo, sin encolar', () => {
    const calls = [];
    const mindo = {
        q: [],
        setUser: (v) => calls.push(['setUser', v]),
        clearUser: () => calls.push(['clearUser'])
    };
    load({ identity: 'jwt-2' }, mindo);

    assertDeep([['setUser', 'jwt-2']], calls, 'llamadas');
    assertDeep([], mindo.q, 'la cola tiene que quedar vacía');
});

test('sin window.Mindo previo: lo crea con su cola', () => {
    const sandbox = load({ identity: 'jwt-3' }, undefined);
    assertDeep([['setUser', 'jwt-3']], sandbox.window.Mindo.q);
});

test('section todavía sin cargar ({}): no llama a nada', () => {
    const calls = [];
    const mindo = { q: [], setUser: (v) => calls.push(['setUser', v]), clearUser: () => calls.push(['clearUser']) };
    load({}, mindo);

    assertDeep([], calls, 'no debería llamar antes de que la section cargue');
});

test('visitante anónimo (identity: null): clearUser', () => {
    const calls = [];
    const mindo = { q: [], setUser: (v) => calls.push(['setUser', v]), clearUser: () => calls.push(['clearUser']) };
    load({ identity: null }, mindo);

    assertDeep([['clearUser']], calls);
});

test('login después de cargar la página: dispara setUser', () => {
    const calls = [];
    const mindo = { q: [], setUser: (v) => calls.push(['setUser', v]), clearUser: () => calls.push(['clearUser']) };
    const sandbox = load({ identity: null }, mindo);

    sandbox.section.set({ identity: 'jwt-login' });
    assertDeep([['clearUser'], ['setUser', 'jwt-login']], calls);
});

test('logout: dispara clearUser', () => {
    const calls = [];
    const mindo = { q: [], setUser: (v) => calls.push(['setUser', v]), clearUser: () => calls.push(['clearUser']) };
    const sandbox = load({ identity: 'jwt-x' }, mindo);

    sandbox.section.set({ identity: null });
    assertDeep([['setUser', 'jwt-x'], ['clearUser']], calls);
});

test('el mismo JWT dos veces no se reenvía', () => {
    const calls = [];
    const mindo = { q: [], setUser: (v) => calls.push(['setUser', v]), clearUser: () => calls.push(['clearUser']) };
    const sandbox = load({ identity: 'jwt-igual' }, mindo);

    sandbox.section.set({ identity: 'jwt-igual' });
    sandbox.section.set({ identity: 'jwt-igual' });
    assertDeep([['setUser', 'jwt-igual']], calls, 'tendría que haber una sola llamada');
});

test('cambio de cliente en el mismo browser: reenvía el nuevo JWT', () => {
    const calls = [];
    const mindo = { q: [], setUser: (v) => calls.push(['setUser', v]), clearUser: () => calls.push(['clearUser']) };
    const sandbox = load({ identity: 'jwt-a' }, mindo);

    sandbox.section.set({ identity: null });
    sandbox.section.set({ identity: 'jwt-b' });
    assertDeep([['setUser', 'jwt-a'], ['clearUser'], ['setUser', 'jwt-b']], calls);
});

console.log('');
if (failed.length === 0) {
    console.log(`TODO OK — ${passed} tests\n`);
} else {
    console.log(`${passed} ok, ${failed.length} FALLARON:\n  - ${failed.join('\n  - ')}\n`);
    process.exit(1);
}
