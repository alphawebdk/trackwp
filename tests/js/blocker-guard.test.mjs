/**
 * T3: blocker guard (PLAN-1.11.0-v2 §3.2, KB10, §11 S3/S7/S8/S9/S10/S11/S15/S19).
 *
 * Runs the REAL assets/js/blocker-guard.js together with the REAL consent
 * reader (TrackWP_Consent::reader_js() via php, same pattern as
 * consent-gate.test.mjs). The consent cookie value comes from
 * tests/fixtures/consent-cookie.json (written by the real consent.js in
 * Chromium). Rules come from tests/js/blocker-rules-dump.php (T2,
 * TrackWP_Blocker_Rules::compile()) when it exists; until then a stand-in in
 * the KB7 shape is used and the suite logs that it did so.
 *
 * The W0 sandbox has no Node.prototype and no MutationObserver (verified:
 * tests/js/helpers/sandbox.mjs createElement returns a plain object), so a
 * minimal DOM stub lives here: Node.prototype.appendChild/insertBefore/
 * replaceChild, attributes, a MutationObserver delivered as a microtask, and
 * a fake script loader that logs fetch/exec/inline in the order a browser
 * would (async=false scripts execute in insertion order).
 */
import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { CookieJar, PLUGIN_ROOT } from './helpers/sandbox.mjs';

const HOME = 'https://example.org/';

// --- producers -------------------------------------------------------------
function realReaderJs() {
    const file = path.join(PLUGIN_ROOT, 'includes', 'class-trackwp-consent.php').replace(/\\/g, '/');
    const php = `
        define('ABSPATH', '/tmp/');
        function get_option($n, $d = false) { return $n === 'trackwp_consent' ? array('consent_version' => 1) : $d; }
        function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); }
        function esc_js($v) { return addslashes($v); }
        function add_action() {} function add_filter() {} function add_shortcode() {}
        require '${file}';
        echo TrackWP_Consent::reader_js();
    `;
    const res = spawnSync('php', ['-r', php], { encoding: 'utf8' });
    if (res.status !== 0 || !/trackwpConsentReader/.test(res.stdout || '')) {
        throw new Error(`reader_js() failed: ${res.stdout}${res.stderr}`);
    }
    return res.stdout.replace(/<\/?script[^>]*>/gi, '');
}
const READER = realReaderJs();
const COOKIE_FIXTURE = JSON.parse(fs.readFileSync(path.join(PLUGIN_ROOT, 'tests', 'fixtures', 'consent-cookie.json'), 'utf8'));

const DUMP = path.join(PLUGIN_ROOT, 'tests', 'js', 'blocker-rules-dump.php');
const VECTORS = path.join(PLUGIN_ROOT, 'tests', 'fixtures', 'blocker', 'url-vectors.json');
let rulesSource;
const SETTINGS = {
    mode: 'on',
    rules: {
        'url:connect.facebook.net/en_US/fbevents.js': { block: true, category: 'marketing', vendor: 'meta' },
        'url:sleeknotecustomerscripts.sleeknote.com/': { block: true, category: 'marketing', vendor: 'sleeknote' },
        'host:static.klaviyo.com': { block: true, category: 'marketing', vendor: 'klaviyo' },
        'host:stats.wp.com': { block: true, category: 'statistics', vendor: 'jetpack' },
        'host:leadinfo.net': { block: true, category: 'marketing', vendor: 'leadinfo' },
        'host:youtube-nocookie.com': { block: true, category: 'marketing', vendor: 'youtube' },
        'pixel:pixel.wp.com/g.gif': { block: true, category: 'statistics', vendor: 'jetpack' },
    },
    exceptions: { allow: ['url:static.klaviyo.com/onsite/allowed/'], paths: [] },
};
// T2's tests/js/blocker-rules-dump.php is the producer: it prints
// {client_rules: client_rules(compile(<option>)), matches: [{url, base, kind, result}]}
// with the real vendor_catalog(). Without argv it compiles match.settings
// from url-vectors.json; with argv it compiles the option given.
function dump(settings) {
    const args = settings ? [DUMP, JSON.stringify(settings)] : [DUMP];
    const res = spawnSync('php', args, { encoding: 'utf8' });
    if (res.status !== 0) throw new Error(`blocker-rules-dump.php failed: ${res.stdout}${res.stderr}`);
    return JSON.parse(res.stdout);
}
function loadConfig() {
    rulesSource = 'tests/js/blocker-rules-dump.php (client_rules(compile(SETTINGS)))';
    return dump(SETTINGS).client_rules;
}
const CONFIG = loadConfig();

// --- minimal DOM stub ------------------------------------------------------
let disposed = false;
after(() => { disposed = true; });
function makeDom(url, home, config = CONFIG, opts = {}) {
    const log = [];
    const observers = [];
    const listeners = new Map();
    const jar = new CookieJar();
    const JS = /^(|text\/javascript|application\/javascript|module)$/i;

    class Node {
        constructor() { this.childNodes = []; this.parentNode = null; }
        appendChild(n) { return insert(this, n, null, false); }
        insertBefore(n, ref) { return insert(this, n, ref, false); }
        removeChild(n) {
            const i = this.childNodes.indexOf(n);
            if (i >= 0) this.childNodes.splice(i, 1);
            n.parentNode = null;
            return n;
        }
        replaceChild(n, old) { insert(this, n, old, false); return this.removeChild(old); }
    }
    class Element extends Node {
        constructor(tag) {
            super();
            this.nodeType = 1;
            this.tagName = tag.toUpperCase();
            this._attrs = [];
            this._ev = {};
            this.async = true;
            this.text = '';
            this.started = false;
        }
        get attributes() { return this._attrs.slice(); }
        getAttribute(n) { const a = this._attrs.find((x) => x.name === n); return a ? a.value : null; }
        hasAttribute(n) { return this.getAttribute(n) !== null; }
        setAttribute(n, v) {
            const a = this._attrs.find((x) => x.name === n);
            if (a) a.value = String(v); else this._attrs.push({ name: n, value: String(v) });
            if (n === 'src' && this.tagName !== 'SCRIPT' && connected(this)) log.push(`frame:${v}`);
        }
        removeAttribute(n) { this._attrs = this._attrs.filter((x) => x.name !== n); }
        get src() { return this.getAttribute('src') || ''; }
        set src(v) { this.setAttribute('src', v); }
        get id() { return this.getAttribute('id') || ''; }
        addEventListener(t, fn) { (this._ev[t] = this._ev[t] || []).push(fn); }
        fire(t) {
            if (typeof this['on' + t] === 'function') this['on' + t]({ type: t });
            (this._ev[t] || []).forEach((fn) => fn({ type: t }));
        }
    }
    const doc = new Node();
    doc.nodeType = 9;
    doc.readyState = 'loading';
    doc.baseURI = url;
    const html = new Element('html');
    const head = new Element('head');
    const body = new Element('body');
    Object.assign(doc, {
        documentElement: html, head, body,
        createElement: (t) => new Element(t),
        addEventListener(t, fn) { (listeners.get(t) || listeners.set(t, []).get(t)).push(fn); },
        dispatchEvent(e) { (listeners.get(e.type) || []).slice().forEach((fn) => fn(e)); return true; },
        querySelectorAll(sel) {
            const names = [...sel.matchAll(/\[([\w-]+)\]/g)].map((m) => m[1]);
            const out = [];
            (function walk(n) {
                for (const c of n.childNodes) {
                    if (c.nodeType === 1 && names.every((a) => c.hasAttribute(a))) out.push(c);
                    walk(c);
                }
            })(html);
            return out;
        },
        getElementsByTagName(t) { return doc.querySelectorAll('').filter((e) => e.tagName === t.toUpperCase()); },
    });
    Object.defineProperty(doc, 'cookie', { get: () => jar.read('/'), set: (v) => jar.set(v) });
    doc.childNodes.push(html);
    html.parentNode = doc;
    html.childNodes.push(head, body);
    head.parentNode = html;
    body.parentNode = html;

    function connected(n) {
        for (let p = n; p; p = p.parentNode) if (p === doc) return true;
        return false;
    }
    let loadChain = Promise.resolve();
    function start(el) {
        if (el.tagName === 'SCRIPT') {
            if (el.started || !connected(el) || !JS.test(el.getAttribute('type') || '')) return;
            el.started = true;
            // Modern browser: nomodule scripts neither run nor fire load/error.
            if (el.getAttribute('nomodule') !== null) { log.push(`nomodule:${el.getAttribute('src')}`); return; }
            const src = el.getAttribute('src');
            if (!src) { log.push(`inline:${el.text}`); return; }
            log.push(`fetch:${src}`);
            const run = () => new Promise((r) => setTimeout(r, 5)).then(() => {
                if (/hang/.test(src)) return; // never fires load or error
                const ok = !/fail/.test(src);
                log.push(`${ok ? 'exec' : 'error'}:${src}`);
                el.fire(ok ? 'load' : 'error');
            });
            if (el.async === false) loadChain = loadChain.then(run); else run();
        } else if (el.tagName === 'IFRAME' || el.tagName === 'IMG') {
            const src = el.getAttribute('src');
            if (src && connected(el)) log.push(`frame:${src}`);
        }
    }
    function startTree(n, parser) {
        const go = () => start(n);
        if (parser) setTimeout(go, 0); else go();
        (n.childNodes || []).forEach((c) => startTree(c, parser));
    }
    function insert(parent, n, ref, parser) {
        if (n.parentNode) n.parentNode.removeChild(n);
        const i = ref ? parent.childNodes.indexOf(ref) : -1;
        if (i >= 0) parent.childNodes.splice(i, 0, n); else parent.childNodes.push(n);
        n.parentNode = parent;
        if (connected(n)) {
            observers.forEach((o) => {
                o.q.push({ addedNodes: [n] });
                if (!o.s) { o.s = true; queueMicrotask(() => { o.s = false; const q = o.q.splice(0); o.cb(q); }); }
            });
            startTree(n, parser);
        }
        return n;
    }
    class MutationObserver {
        constructor(cb) { this.cb = cb; this.q = []; this.s = false; }
        observe() { observers.push(this); }
    }
    const win = {
        document: doc, Node, MutationObserver, URL, console, clearTimeout,
        HTMLScriptElement: Object.assign(function HTMLScriptElement() {}, { prototype: { noModule: false } }),
        // Disposable: after all tests no guard timer can re-arm, so a
        // regression that loops on the 10 s cap fails by timeout, never hangs.
        setTimeout: (fn, ms, ...a) => (disposed ? 0 : (opts.setTimeout || setTimeout)(fn, ms, ...a)),
        location: new URL(url),
        trackwpBlockerConfig: { ...JSON.parse(JSON.stringify(config)), ...(home ? { home } : {}) },
    };
    win.window = win;
    const ctx = vm.createContext(win);
    vm.runInContext(READER, ctx);
    const guardFile = path.join(PLUGIN_ROOT, 'assets', 'js', 'blocker-guard.js');
    vm.runInContext(fs.readFileSync(guardFile, 'utf8'), ctx, { filename: guardFile });

    return {
        win: ctx, doc, head, body, log, jar,
        el(tag, attrs = {}, text = '') {
            const e = new Element(tag);
            for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
            e.text = text;
            return e;
        },
        // Parser insertion: bypasses the wrapped methods, scripts start a tick later.
        parse(parent, n) { return insert(parent, n, null, true); },
        consent(choices) {
            const value = { ...COOKIE_FIXTURE.parsed, statistics: false, marketing: false, personalisation: false, ...choices };
            jar.set(`trackwp_consent=${encodeURIComponent(JSON.stringify(value))};path=/`);
            doc.dispatchEvent({ type: 'trackwp:consent_updated', detail: value });
        },
        domReady() { doc.readyState = 'interactive'; doc.dispatchEvent({ type: 'DOMContentLoaded' }); },
    };
}
const tick = (ms = 40) => new Promise((r) => setTimeout(r, ms));
// A server-rewritten (KB9/S9) script tag.
function blockedScript(d, rule, cat, attrs = {}, text = '') {
    return d.el('script', { type: 'text/plain', 'data-twp-blocked': rule, 'data-twp-category': cat, 'data-no-optimize': '1', 'data-no-defer': '1', ...attrs }, text);
}

// --- tests -----------------------------------------------------------------
test('producers in use', () => {
    console.log(`# reader: TrackWP_Consent::reader_js(); rules: ${rulesSource}`);
    const d = makeDom(HOME);
    assert.equal(d.win.trackwpBlocker.active, true);
    assert.equal(d.win.trackwpBlocker.v, 1);
    assert.equal(fs.readFileSync(path.join(PLUGIN_ROOT, 'assets', 'js', 'blocker-guard.js'), 'utf8').includes('document.cookie'), false);
});

test('Sleeknote via insertBefore: held without marketing, same element released with onload intact (S8)', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    const anchor = d.el('script', {}, 'first');
    d.parse(d.head, anchor);
    const s = d.win.document.createElement('script');
    s.src = '//sleeknotecustomerscripts.sleeknote.com/159932.js';
    let loaded = 0;
    s.onload = () => { loaded++; };
    assert.equal(anchor.parentNode.insertBefore(s, anchor), s);
    await tick();
    assert.equal(s.parentNode, null, 'not in the DOM before consent');
    assert.ok(!d.log.some((l) => l.includes('sleeknote')), 'no request before consent');
    assert.equal(s.getAttribute('data-twp-category'), 'marketing');

    d.consent({ statistics: true });
    await tick();
    assert.equal(s.parentNode, null, 'statistics consent does not release marketing');

    d.consent({ marketing: true });
    await tick();
    assert.equal(s.parentNode, d.head, 'the ORIGINAL element is inserted');
    assert.equal(d.head.childNodes.indexOf(s), d.head.childNodes.indexOf(anchor) - 1, 'at its insertBefore position');
    assert.equal(loaded, 1, 'onload set by the vendor fires');
    assert.equal(s.getAttribute('data-twp-blocked'), null);
    // The original keeps its own src attribute (protocol-relative); the stub logs it raw.
    assert.deepEqual(d.log.filter((l) => l.includes('sleeknote')), ['fetch://sleeknotecustomerscripts.sleeknote.com/159932.js', 'exec://sleeknotecustomerscripts.sleeknote.com/159932.js']);
});

test('S15: TrackWP own insertBefore(fbevents.js) passes after marketing accept', async () => {
    const d = makeDom(HOME);
    d.parse(d.head, d.el('script', {}, 'x'));
    d.consent({ marketing: true });
    // The standard Meta base code, as render_meta_pixel() emits it.
    const doc = d.win.document;
    const t = doc.createElement('script');
    t.async = true;
    t.src = 'https://connect.facebook.net/en_US/fbevents.js';
    const first = doc.getElementsByTagName('script')[0];
    first.parentNode.insertBefore(t, first);
    assert.equal(t.parentNode, d.head, 'inserted synchronously');
    assert.ok(d.log.includes('fetch:https://connect.facebook.net/en_US/fbevents.js'));
    assert.equal(t.getAttribute('data-twp-blocked'), null);
});

test('S7: document order, async=false, inline waits for preceding external scripts', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/e-1.js' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/fail-2.js' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, '_stq.push(1)'));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/s-3.js' }));
    d.domReady();
    await tick();
    assert.deepEqual(d.log, [], 'nothing runs without consent');
    d.consent({ statistics: true });
    await tick(100);
    assert.deepEqual(d.log, [
        'fetch:https://stats.wp.com/e-1.js',
        'fetch:https://stats.wp.com/fail-2.js',
        'exec:https://stats.wp.com/e-1.js',
        'error:https://stats.wp.com/fail-2.js',
        'inline:_stq.push(1)',
        'fetch:https://stats.wp.com/s-3.js',
        'exec:https://stats.wp.com/s-3.js',
    ]);
    const scripts = d.body.childNodes;
    assert.equal(scripts.length, 4, 'replaced in place, no duplicates');
    assert.ok(scripts.every((s) => s.getAttribute('data-twp-released') === '1' && s.getAttribute('data-twp-blocked') === null));
    assert.equal(scripts[0].async, false);
    assert.equal(scripts[3].async, false);
});

test('KB9/S10: attributes and module type are preserved on release', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    d.parse(d.body, blockedScript(d, 'host:static.klaviyo.com', 'marketing', {
        'data-twp-src': '//static.klaviyo.com/onsite/js/QNtDCQ/klaviyo.js', 'data-twp-type': 'module',
        id: 'kl-js', nonce: 'n1', integrity: 'sha384-x', crossorigin: 'anonymous', referrerpolicy: 'no-referrer', nomodule: '', async: '',
    }));
    d.consent({ marketing: true });
    await tick();
    const s = d.body.childNodes[0];
    assert.equal(s.getAttribute('type'), 'module');
    assert.equal(s.getAttribute('src'), '//static.klaviyo.com/onsite/js/QNtDCQ/klaviyo.js');
    assert.equal(s.nonce, 'n1', 'nonce via IDL property (M4)');
    for (const [k, v] of [['id', 'kl-js'], ['integrity', 'sha384-x'], ['crossorigin', 'anonymous'], ['referrerpolicy', 'no-referrer'], ['nomodule', '']]) {
        assert.equal(s.getAttribute(k), v, k);
    }
    assert.equal(s.getAttribute('async'), null, 'async=false for external scripts');
    assert.ok(!s.attributes.some((a) => a.name.startsWith('data-twp-') && a.name !== 'data-twp-released'));
});

test('element status: later finds in a released category are released too', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    d.domReady();
    d.consent({ marketing: true });
    await tick();
    // Server-rewritten markup arriving later (e.g. AJAX fragment): goes into the DOM inert, the observer releases it.
    const late = blockedScript(d, 'host:leadinfo.net', 'marketing', { 'data-twp-src': 'https://cdn.leadinfo.net/ping.js' });
    d.body.appendChild(late);
    await tick();
    assert.ok(d.log.includes('exec:https://cdn.leadinfo.net/ping.js'));
    // A new dynamic script after consent passes straight through.
    const dyn = d.el('script', { src: 'https://static.klaviyo.com/x.js' });
    d.body.appendChild(dyn);
    assert.equal(dyn.parentNode, d.body);
});

test('consent is re-read before release: event without real consent releases nothing', async () => {
    const d = makeDom(HOME);
    d.parse(d.body, blockedScript(d, 'host:leadinfo.net', 'marketing', { 'data-twp-src': 'https://cdn.leadinfo.net/ping.js' }));
    const held = d.el('script', { src: 'https://static.klaviyo.com/a.js' });
    d.body.appendChild(held);
    d.win.document.dispatchEvent({ type: 'trackwp:consent_updated', detail: { marketing: true } });
    d.win.trackwpBlocker.release('marketing');
    await tick();
    assert.deepEqual(d.log, []);
    assert.equal(held.parentNode, null);
});

test('div, non-matching and non-JS scripts are untouched; never and allow win (S11)', async () => {
    const d = makeDom(HOME);
    const div = d.el('div', { src: 'https://static.klaviyo.com/x.js' });
    assert.equal(d.body.appendChild(div).parentNode, d.body);
    const cases = [
        [{ src: 'https://example.org/wp-content/themes/a.js' }, true],
        [{ src: 'https://static.klaviyo.com/x.json', type: 'application/ld+json' }, true],
        [{ src: '/wp-content/plugins/trackwp/assets/js/trackwp.js' }, true],
        [{ src: 'https://www.google.com/recaptcha/api.js' }, true],
        [{ src: 'https://static.klaviyo.com/onsite/allowed/form.js' }, true],
        [{ src: 'https://static.klaviyo.com/onsite/js/x.js' }, false],
        [{ src: 'https://notstats.wp.com.evil.test/e.js' }, true],
        [{ src: 'https://a.stats.wp.com/e.js' }, false],
    ];
    for (const [attrs, passes] of cases) {
        const s = d.el('script', attrs);
        d.body.appendChild(s);
        assert.equal(s.parentNode === d.body, passes, JSON.stringify(attrs));
    }
    assert.equal(d.win.trackwpBlocker.match('https://static.klaviyo.com/onsite/allowed/form.js'), null, 'allowed sub-path of a blocked host');
    assert.equal(d.win.trackwpBlocker.match('https://static.klaviyo.com/onsite/js/x.js').category, 'marketing');
    assert.equal(d.win.trackwpBlocker.match('data:text/javascript,1'), null);
});

test('iframe: held without consent, released with src', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    const f = d.el('iframe', { src: 'https://www.youtube-nocookie.com/embed/x' });
    d.body.appendChild(f);
    assert.equal(f.parentNode, null);
    d.consent({ marketing: true });
    await tick();
    assert.equal(f.parentNode, d.body);
    assert.ok(d.log.includes('frame:https://www.youtube-nocookie.com/embed/x'));
});

test('MutationObserver backup neutralizes parser-inserted scripts and pixels are released (S9)', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    const s = d.el('script', { src: 'https://cdn.leadinfo.net/ping.js' });
    d.parse(d.body, s);
    const img = d.el('img', { 'data-twp-blocked': 'pixel:pixel.wp.com/g.gif', 'data-twp-category': 'statistics', 'data-twp-src': 'https://pixel.wp.com/g.gif?x=1' });
    d.parse(d.body, img);
    await tick();
    assert.deepEqual(d.log, [], 'no request');
    assert.equal(s.getAttribute('type'), 'text/plain');
    assert.equal(s.getAttribute('data-twp-src'), 'https://cdn.leadinfo.net/ping.js');
    d.consent({ marketing: true, statistics: true });
    await tick();
    assert.ok(d.log.includes('exec:https://cdn.leadinfo.net/ping.js'));
    assert.equal(img.getAttribute('src'), 'https://pixel.wp.com/g.gif?x=1');
});

test('S19: repeated and nested release inserts every node exactly once', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/e-1.js' }));
    const held = d.el('script', { src: 'https://stats.wp.com/e-2.js' });
    d.body.appendChild(held);
    d.jar.set(`trackwp_consent=${encodeURIComponent(JSON.stringify({ ...COOKIE_FIXTURE.parsed, marketing: false }))};path=/`);
    // A release that re-enters release() synchronously (e.g. a listener fired while inserting).
    const B = d.win.trackwpBlocker;
    const orig = B.release;
    d.win.document.addEventListener('trackwp:consent_updated', () => orig('statistics'));
    d.domReady();
    d.win.document.dispatchEvent({ type: 'trackwp:consent_updated' });
    orig('statistics');
    await tick(80);
    assert.equal(d.log.filter((l) => l === 'fetch:https://stats.wp.com/e-1.js').length, 1);
    assert.equal(d.log.filter((l) => l === 'fetch:https://stats.wp.com/e-2.js').length, 1);
    assert.equal(d.body.childNodes.length, 2);
});

test('M4: nonce hidden by CSP (empty attribute) is copied via the IDL property', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    const old = blockedScript(d, 'host:static.klaviyo.com', 'marketing', { 'data-twp-src': 'https://static.klaviyo.com/n.js', nonce: '' });
    old.nonce = 'csp-n2';
    d.parse(d.body, old);
    d.domReady();
    d.consent({ marketing: true });
    await tick();
    const s = d.body.childNodes[0];
    assert.notEqual(s, old);
    assert.equal(s.nonce, 'csp-n2');
    assert.ok(d.log.includes('exec:https://static.klaviyo.com/n.js'));
});

test('M3/g1b: consent withdrawn before external A loads: inline B never runs, held C stays held for a later grant', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    const C = d.el('script', { src: 'https://stats.wp.com/e-3.js' });
    d.body.appendChild(C);
    assert.equal(C.parentNode, null, 'C held');
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/e-1.js' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'B()'));
    d.domReady();
    d.consent({ statistics: true });
    // Withdrawn in the cookie before A's load (no event: the reader is the only truth).
    d.jar.set(`trackwp_consent=${encodeURIComponent(JSON.stringify({ ...COOKIE_FIXTURE.parsed, statistics: false, marketing: false, personalisation: false }))};path=/`);
    await tick();
    assert.deepEqual(d.log, ['fetch:https://stats.wp.com/e-1.js', 'exec:https://stats.wp.com/e-1.js'], 'B and C did not run');
    assert.equal(C.parentNode, null, 'C not inserted');
    assert.equal(C.getAttribute('data-twp-category'), 'statistics', 'C still marked as held');
    d.consent({ statistics: true });
    await tick();
    assert.deepEqual(d.log.slice(2), ['inline:B()', 'fetch:https://stats.wp.com/e-3.js', 'exec:https://stats.wp.com/e-3.js'], 'later grant releases B and C');
    assert.equal(C.parentNode, d.body);
});

test('M5: returning visitor with consent: nothing released while loading, external before dependent inline across observer batches', { timeout: 5000 }, async () => {
    const d = makeDom(HOME);
    d.consent({ marketing: true }); // cookie present before the page parses
    d.parse(d.body, blockedScript(d, 'host:leadinfo.net', 'marketing', { 'data-twp-src': 'https://cdn.leadinfo.net/ping.js' }));
    await tick(); // separate MutationObserver batch
    d.parse(d.body, blockedScript(d, 'host:leadinfo.net', 'marketing', {}, 'leadinfo()'));
    await tick();
    assert.deepEqual(d.log, [], 'the observer does not release during loading');
    d.domReady();
    await tick();
    assert.deepEqual(d.log, ['fetch:https://cdn.leadinfo.net/ping.js', 'exec:https://cdn.leadinfo.net/ping.js', 'inline:leadinfo()']);
    // After DOMContentLoaded: ongoing release, still one chain across batches.
    d.body.appendChild(blockedScript(d, 'host:leadinfo.net', 'marketing', { 'data-twp-src': 'https://cdn.leadinfo.net/two.js' }));
    await new Promise((r) => setImmediate(r));
    d.body.appendChild(blockedScript(d, 'host:leadinfo.net', 'marketing', {}, 'two()'));
    await tick();
    assert.deepEqual(d.log.slice(3), ['fetch:https://cdn.leadinfo.net/two.js', 'exec:https://cdn.leadinfo.net/two.js', 'inline:two()']);
});

// MINOR 1: the 10 s cap is scaled to 60 ms by a timer spy; the spy counts how often the cap is armed.
function capSpy() {
    const caps = [];
    const st = (fn, ms, ...a) => {
        if (ms === 10000) { caps.push(ms); return setTimeout(fn, 60, ...a); }
        return setTimeout(fn, ms, ...a);
    };
    return { caps, st };
}

test('MINOR 1: a script without load/error delays the chain once (one 10 s cap), not per inline script', { timeout: 5000 }, async () => {
    const spy = capSpy();
    const d = makeDom(HOME, null, CONFIG, { setTimeout: spy.st });
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/hang.js' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'one()'));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'two()'));
    d.domReady();
    d.consent({ statistics: true });
    await tick(250);
    assert.deepEqual(d.log, ['fetch:https://stats.wp.com/hang.js', 'inline:one()', 'inline:two()']);
    assert.equal(spy.caps.length, 1, 'the cap is armed once');
});

test('MINOR 1: a nomodule script does not block the chain', { timeout: 5000 }, async () => {
    const spy = capSpy();
    const d = makeDom(HOME, null, CONFIG, { setTimeout: spy.st });
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/legacy.js', nomodule: '' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'after()'));
    d.domReady();
    d.consent({ statistics: true });
    await tick();
    assert.deepEqual(d.log, ['nomodule:https://stats.wp.com/legacy.js', 'inline:after()']);
    assert.equal(spy.caps.length, 0, 'no wait at all');
});

test('MINOR 1: an element removed before its release is not counted', { timeout: 5000 }, async () => {
    const spy = capSpy();
    const d = makeDom(HOME, null, CONFIG, { setTimeout: spy.st });
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/a.js' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'b()'));
    const R = blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/removed.js' });
    d.parse(d.body, R);
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'd()'));
    d.domReady();
    d.consent({ statistics: true }); // queue: a, b (waits for a), R, d
    d.body.removeChild(R); // removed while b waits for a
    await tick();
    assert.deepEqual(d.log, ['fetch:https://stats.wp.com/a.js', 'exec:https://stats.wp.com/a.js', 'inline:b()', 'inline:d()']);
    assert.equal(spy.caps.length, 1, 'only the wait for a was armed (and cleared by its load)');
});

test('MINOR 1: a late load from a script that already hit the cap does not release inline B before its own external Y', { timeout: 5000 }, async () => {
    const spy = capSpy();
    const d = makeDom(HOME, null, CONFIG, { setTimeout: spy.st });
    const bySrc = (u) => d.body.childNodes.find((e) => e.getAttribute('src') === u);
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/hang-x.js' }));
    d.parse(d.body, blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'a()'));
    d.domReady();
    d.consent({ statistics: true });
    await tick(150); // X never fires: the cap releases a()
    assert.deepEqual(d.log, ['fetch:https://stats.wp.com/hang-x.js', 'inline:a()']);
    assert.equal(spy.caps.length, 1);
    // Later: external Y and dependent inline B, released by the observer.
    d.body.appendChild(blockedScript(d, 'host:stats.wp.com', 'statistics', { 'data-twp-src': 'https://stats.wp.com/hang-y.js' }));
    d.body.appendChild(blockedScript(d, 'host:stats.wp.com', 'statistics', {}, 'b()'));
    await new Promise((r) => setImmediate(r));
    assert.deepEqual(d.log.slice(2), ['fetch:https://stats.wp.com/hang-y.js'], 'B waits for Y');
    bySrc('https://stats.wp.com/hang-x.js').fire('load'); // X's late load, from an expired generation
    assert.ok(!d.log.includes('inline:b()'), 'B must not run on the late load of X');
    bySrc('https://stats.wp.com/hang-y.js').fire('load');
    assert.deepEqual(d.log.slice(2), ['fetch:https://stats.wp.com/hang-y.js', 'inline:b()'], 'B runs after Y');
});

test('S11: guard match() equals PHP match() for every dumped vector and match case', () => {
    const out = dump(null);
    const home = JSON.parse(fs.readFileSync(VECTORS, 'utf8')).home;
    let n = 0;
    for (const m of out.matches) {
        if (m.kind !== 'script' && m.kind !== 'iframe') continue; // the guard only sees SCRIPT/IFRAME
        const d = makeDom(m.base || home, home, out.client_rules);
        const js = d.win.trackwpBlocker.match(m.url);
        assert.equal(js ? js.rule : null, m.result, JSON.stringify(m));
        n++;
    }
    console.log(`# S11 PHP/JS match cases compared: ${n}`);
    assert.ok(n > 0);
    // Own settings: allowed sub-path of a blocked host (exceptions.allow compiled into never).
    const d = makeDom(HOME);
    assert.equal(d.win.trackwpBlocker.match('https://static.klaviyo.com/onsite/allowed/form.js'), null);
    assert.equal(d.win.trackwpBlocker.match('https://static.klaviyo.com/onsite/js/x.js').rule, 'host:static.klaviyo.com');
});

test('S3 URL vectors (tests/fixtures/blocker/url-vectors.json)', { skip: !fs.existsSync(VECTORS) && 'url-vectors.json not present yet (T2/T0)' }, () => {
    const data = JSON.parse(fs.readFileSync(VECTORS, 'utf8'));
    const vectors = Array.isArray(data) ? data : data.vectors;
    assert.ok(vectors.length > 0);
    for (const v of vectors) {
        const d = makeDom(v.base || v.page || data.home || HOME, v.home || data.home || HOME);
        const n = d.win.trackwpBlocker.normalize(v.url ?? v.input);
        const expected = v.expected ?? v.normalized ?? null;
        assert.equal(n ? n.s : null, expected, JSON.stringify(v));
    }
});

test('release() without consent keeps held scripts held; a later grant still releases them', async () => {
    const d = makeDom(HOME);
    d.domReady();
    const held = d.el('script', { src: 'https://static.klaviyo.com/a.js' });
    d.body.appendChild(held);
    assert.equal(held.parentNode, null, 'held before consent');
    d.win.trackwpBlocker.release('marketing');
    await tick();
    assert.equal(held.parentNode, null, 'still held without consent');
    d.consent({ marketing: true });
    await tick();
    assert.equal(held.parentNode, d.body, 'released after the grant');
});
