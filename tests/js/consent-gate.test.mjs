/**
 * W5: consent gate for trackwp.js + consent.js (PLAN-1.10.1-v4 §4.1, K3,
 * K4, K6, K7, R4-R8). Runs the REAL assets/js/consent.js and
 * assets/js/trackwp.js together in the W0 vm sandbox. The consent cookie is
 * always produced by consent.js itself (window.trackwpConsent.setChoices /
 * withdraw), never hand-built.
 *
 * Reader: when PHP and TrackWP_Consent::reader_js() (W1, K3) are available,
 * the real reader is used. Otherwise a test-only stand-in with the K3
 * semantics is used and the suite logs that it did so.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createSandbox, PLUGIN_ROOT } from './helpers/sandbox.mjs';

const REST = 'https://example.org/wp-json/';

function realReaderJs(version) {
    const file = path.join(PLUGIN_ROOT, 'includes', 'class-trackwp-consent.php').replace(/\\/g, '/');
    const php = `
        define('ABSPATH', '/tmp/');
        function get_option($n, $d = false) { return $n === 'trackwp_consent' ? array('consent_version' => ${version}) : $d; }
        function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); }
        function esc_js($v) { return addslashes($v); }
        function add_action() {} function add_filter() {} function add_shortcode() {}
        require '${file}';
        if (!method_exists('TrackWP_Consent', 'reader_js')) { exit(3); }
        echo TrackWP_Consent::reader_js();
    `;
    const res = spawnSync('php', ['-r', php], { encoding: 'utf8' });
    if (res.status !== 0 || !res.stdout || !/trackwpConsentReader/.test(res.stdout)) return null;
    return res.stdout.replace(/<\/?script[^>]*>/gi, '');
}

// Test-only stand-in with the K3 reader contract: null for missing, invalid,
// stale or wrong-version cookies; version is read from this.version so that
// consent.js can update it after a stale_version answer (R4).
function standInReaderJs(version) {
    return `window.trackwpConsentReader = { version: ${version}, read: function () {
        var m = document.cookie.match(/(?:^|; )trackwp_consent=([^;]*)/);
        if (!m) return null;
        try { var d = JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
        if (!d || typeof d !== 'object' || d.stale === true || d.v !== this.version) return null;
        return d;
    } };`;
}

// Shared URL/title cleaner (W2, TrackWP_Privacy::cleaner_js()). No copy
// exists in the test: without the producer, trackwp.js's fallback is tested.
function realCleanerJs() {
    const file = path.join(PLUGIN_ROOT, 'includes', 'class-trackwp-privacy.php').replace(/\\/g, '/');
    const php = `
        define('ABSPATH', '/tmp/');
        function apply_filters($h, $v) { return $v; }
        function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); }
        function add_action() {} function add_filter() {}
        require '${file}';
        if (!method_exists('TrackWP_Privacy', 'cleaner_js')) { exit(3); }
        echo TrackWP_Privacy::cleaner_js();
    `;
    const res = spawnSync('php', ['-r', php], { encoding: 'utf8' });
    if (res.status !== 0 || !/trackwpPrivacy/.test(res.stdout || '')) return null;
    return res.stdout.replace(/<\/?script[^>]*>/gi, '');
}
const CLEANER = realCleanerJs();
const HAS_CLEANER = !!CLEANER;

let readerSource = null;
function readerJs(version) {
    const real = realReaderJs(version);
    if (real) {
        readerSource = 'TrackWP_Consent::reader_js()';
        return real;
    }
    readerSource = 'test stand-in (W1 reader_js not available)';
    return standInReaderJs(version);
}

const LEAD_EVENT = {
    name: 'lead',
    enabled: true,
    value: 0,
    currency: 'DKK',
    ads_label: 'LBL',
    meta_resolved: 'Lead',
    send_to: { ga4: true, meta: true, google_ads: true },
    triggers: [{ type: 'form_submit', css_selector: '', conditions: [] }],
};

// Client config from the REAL producers TrackWP::frontend_config() and
// consent_config() (review class A), via tests/js/client-config-dump.php.
const DUMP = path.join(PLUGIN_ROOT, 'tests', 'js', 'client-config-dump.php');
const configCache = new Map();
function defaultOptions(version, advanced = {}) {
    return {
        trackwp_advanced: { customer_data_sharing: true, first_party_cookie_enabled: true, ...advanced },
        trackwp_consent: { require_active_consent: true, consent_version: version, cookie_lifetime_months: 12 },
    };
}
function producerConfig(options) {
    const key = JSON.stringify(options);
    if (!configCache.has(key)) {
        const res = spawnSync('php', [DUMP, key], { encoding: 'utf8' });
        if (res.status !== 0) throw new Error(`client-config-dump.php failed: ${res.stdout}${res.stderr}`);
        configCache.set(key, JSON.parse(res.stdout));
    }
    return JSON.parse(JSON.stringify(configCache.get(key)));
}

// `trackwp` overrides test fixtures (events, Ads ID) or, in the strictness
// test, deliberately feeds non-boolean flag values.
function boot({ url = 'https://example.org/side?x=1', trackwp = {}, version = 1, advanced = {} } = {}) {
    const prod = producerConfig(defaultOptions(version, advanced));
    const sb = createSandbox({
        url,
        config: {
            consent: { ...prod.consent, restUrl: REST },
            trackwp: {
                ...prod.frontend,
                restUrl: REST,
                googleAds: { conversionId: 'AW-1', labels: [] },
                events: [LEAD_EVENT],
                ...trackwp,
            },
        },
    });
    vm.runInContext(readerJs(version), sb.window);
    if (CLEANER) vm.runInContext(CLEANER, sb.window);
    for (const name of ['consent.js', 'trackwp.js']) {
        const file = path.join(PLUGIN_ROOT, 'assets', 'js', name);
        vm.runInContext(fs.readFileSync(file, 'utf8'), sb.window, { filename: file });
    }
    return sb;
}

const tick = (ms = 30) => new Promise((r) => setTimeout(r, ms));

function calls(sb, pathPart) {
    return sb.spies.fetch
        .filter(([u]) => String(u).includes(pathPart))
        .map(([u, init]) => ({ url: String(u), body: init && init.body ? JSON.parse(init.body) : null }));
}

function consentCookie(sb) {
    const c = sb.jar.get('trackwp_consent');
    return c ? JSON.parse(decodeURIComponent(c.value)) : null;
}

function fakeForm(email) {
    return {
        id: 'contact',
        tagName: 'FORM',
        className: '',
        matches: () => true,
        getAttribute: (n) => (n === 'id' ? 'contact' : null),
        querySelector: (sel) => (sel === '[type="email"]' ? { value: email } : null),
    };
}

test('reader in use', () => {
    boot();
    console.log(`# consent reader: ${readerSource}`);
    console.log(`# url cleaner: ${HAS_CLEANER ? 'TrackWP_Privacy::cleaner_js()' : 'absent (fallback origin+path tested)'}`);
    assert.ok(readerSource);
});

test('K6: pre-consent queue holds only name/value/currency/form_id/form_name/trigger_type', async () => {
    const sb = boot();
    sb.window.trackwp.sendEvent('lead', {
        value: 5,
        currency: 'EUR',
        form_id: 'f1',
        form_name: 'Kontakt jens@example.com',
        ecommerce: { items: [{ item_id: 'x' }] },
        enhanced: { email: 'jens@example.com' },
    });
    await tick();
    assert.equal(calls(sb, 'trackwp/v1/event').length, 0, 'nothing sent before a choice');

    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    await tick();
    const sent = calls(sb, 'trackwp/v1/event');
    assert.equal(sent.length, 1);
    const body = sent[0].body;
    assert.equal(body.event, 'lead');
    assert.equal(body.value, 5);
    assert.equal(body.currency, 'EUR');
    assert.equal(body.form_id, 'f1');
    assert.equal(body.form_name, HAS_CLEANER ? 'Kontakt [redacted]' : undefined, 'e-mail never leaves in form_name');
    assert.equal(body.ecommerce, undefined, 'items never survive the queue');
    assert.equal(body.enhanced, undefined, 'enhanced never survives the queue');
    assert.match(body.event_id, /^evt_[a-f0-9]{32}$/, 'event_id is created at flush');
    assert.deepEqual(
        { a: body.consent.analytics, m: body.consent.marketing, v: body.consent.v },
        { a: true, m: true, v: 1 }
    );
    assert.equal(body.consent.id, consentCookie(sb).id);
});

test('K6: rejection empties the queue without sending', async () => {
    const sb = boot();
    sb.window.trackwp.sendEvent('lead', { value: 1 });
    sb.window.trackwp.sendEvent('lead', { value: 2 });
    sb.window.trackwpConsent.setChoices({ statistics: false, marketing: false });
    await tick();
    assert.equal(calls(sb, 'trackwp/v1/event').length, 0);
    const logs = calls(sb, 'trackwp/v1/consent-log');
    assert.equal(logs.length, 1);
    assert.equal(logs[0].body.event_type, 'set');
    assert.equal(consentCookie(sb).statistics, false);
});

test('K7: click IDs stay in RAM until marketing consent', async () => {
    const sb = boot({ url: 'https://example.org/?gclid=G_1&gbraid=B-2&fbclid=FB3' });
    assert.equal(sb.jar.get('_twp_click'), null);
    assert.equal(sb.jar.get('_fbc'), null);

    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: false });
    await tick();
    assert.equal(sb.jar.get('_twp_click'), null, 'statistics alone never writes click IDs');
    assert.equal(sb.jar.get('_fbc'), null);

    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    await tick();
    const click = JSON.parse(decodeURIComponent(sb.jar.get('_twp_click').value));
    assert.equal(click.gclid.v, 'G_1');
    assert.equal(click.gbraid.v, 'B-2');
    assert.equal(sb.jar.get('_twp_click').domain, null, '_twp_click is host-only');
    assert.match(decodeURIComponent(sb.jar.get('_fbc').value), /^fb\.1\.\d{13}\.FB3$/);

    sb.window.trackwp.sendEvent('lead');
    await tick();
    const body = calls(sb, 'trackwp/v1/event').pop().body;
    assert.equal(body.gclid, 'G_1');
    assert.equal(body.gbraid, 'B-2');
    if (HAS_CLEANER) assert.match(body.page_url, /gclid=G_1/, 'click IDs stay in page_url with marketing');
    else assert.equal(body.page_url, 'https://example.org/');

    sb.window.trackwpConsent.withdraw();
    await tick();
    assert.equal(sb.jar.get('_twp_click'), null, 'withdraw clears _twp_click');
});

test('K2: identifiers per category; trackwp_sid only with analytics', async () => {
    const sb = boot({ url: 'https://example.org/p?gclid=G1&email=a%40b.dk&utm_source=x#frag' });
    sb.window.trackwpConsent.setChoices({ statistics: false, marketing: true });
    sb.window.trackwp.sendEvent('lead');
    await tick();
    const body = calls(sb, 'trackwp/v1/event').pop().body;
    assert.equal(body.client_id, undefined);
    assert.equal(body.session_id, undefined);
    assert.equal(body.engaged_ms, undefined);
    assert.equal(sb.window.sessionStorage.getItem('trackwp_sid'), null);
    assert.equal(body.gclid, 'G1');
    assert.equal(body.page_url, HAS_CLEANER ? 'https://example.org/p?gclid=G1&utm_source=x' : 'https://example.org/p');

    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: false });
    sb.window.trackwp.sendEvent('lead');
    await tick();
    const body2 = calls(sb, 'trackwp/v1/event').pop().body;
    assert.match(body2.client_id, /^\d+\.\d+$/);
    assert.match(body2.session_id, /^\d{1,12}$/);
    assert.equal(sb.window.sessionStorage.getItem('trackwp_sid'), body2.session_id);
    assert.equal(body2.gclid, undefined);
    assert.equal(body2.page_url, HAS_CLEANER ? 'https://example.org/p?utm_source=x' : 'https://example.org/p', 'click IDs dropped without marketing');
});

test('K4/R6: withdraw during hashing cancels enhanced; one withdraw POST, no set', async () => {
    const sb = boot();
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    await tick();
    const before = calls(sb, 'trackwp/v1/consent-log').length;

    sb.window.trackwp.sendFormEvent({ form_id: 'contact', form_name: 'Kontakt' }, fakeForm('Jens@Example.com'));
    sb.window.trackwpConsent.withdraw(); // hashing still pending
    await tick(60);

    const ev = calls(sb, 'trackwp/v1/event').pop().body;
    assert.equal(ev.event, 'lead');
    assert.equal(ev.enhanced, undefined, 'no enhanced after withdraw');
    assert.equal(ev.consent.marketing, false);
    const userData = sb.spies.gtag.filter((a) => a[0] === 'set' && a[1] === 'user_data' && Object.keys(a[2]).length);
    assert.equal(userData.length, 0, 'no gtag user_data after withdraw');
    assert.ok(sb.spies.fbq.some((a) => a[0] === 'consent' && a[1] === 'revoke'));

    const logs = calls(sb, 'trackwp/v1/consent-log').slice(before);
    assert.equal(logs.length, 1);
    assert.equal(logs[0].body.event_type, 'withdraw');
    const cookie = consentCookie(sb);
    assert.deepEqual([cookie.statistics, cookie.marketing, cookie.personalisation], [false, false, false]);
    assert.deepEqual(Object.keys(cookie).sort(), ['id', 'marketing', 'necessary', 'personalisation', 'statistics', 'ts', 'v']);
});

test('K8: enhanced hashed with marketing; gtag user_data set before and cleared after conversion', async () => {
    const sb = boot();
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    sb.window.trackwp.sendFormEvent({ form_id: 'contact' }, fakeForm(' Jens.Hansen+x@Gmail.com '));
    await tick(60);
    const ev = calls(sb, 'trackwp/v1/event').pop().body;
    assert.match(ev.enhanced.email_sha256, /^[a-f0-9]{64}$/);
    assert.match(ev.enhanced.email_meta_sha256, /^[a-f0-9]{64}$/);
    assert.notEqual(ev.enhanced.email_sha256, ev.enhanced.email_meta_sha256);
    const seq = sb.spies.gtag.filter((a) => (a[0] === 'set' && a[1] === 'user_data') || (a[0] === 'event' && a[1] === 'conversion'));
    assert.equal(seq.length, 3);
    assert.equal(seq[0][2].sha256_email_address, ev.enhanced.email_sha256);
    assert.equal(seq[1][1], 'conversion');
    assert.equal(Object.keys(seq[2][2]).length, 0, 'user_data cleared to {}');
});

test('K8: customerDataSharing off sends no enhanced and no user_data', async () => {
    const sb = boot({ advanced: { customer_data_sharing: false } });
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    sb.window.trackwp.sendFormEvent({ form_id: 'contact' }, fakeForm('a@b.dk'));
    await tick(60);
    assert.equal(calls(sb, 'trackwp/v1/event').pop().body.enhanced, undefined);
    assert.equal(sb.spies.gtag.filter((a) => a[0] === 'set' && a[1] === 'user_data').length, 0);
});

test('R5/R7: consent requests are serialised and an old response cannot win', async () => {
    const sb = boot();
    const pending = [];
    const orig = sb.window.fetch;
    sb.window.fetch = (u, init) => {
        if (String(u).includes('consent-log')) {
            sb.spies.fetch.push([u, init]);
            return new Promise((resolve) => pending.push({ init, resolve }));
        }
        return orig(u, init);
    };
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    const acceptCookie = consentCookie(sb);
    sb.window.trackwpConsent.withdraw();
    assert.equal(pending.length, 1, 'withdraw waits for the accept request');

    // The delayed accept response arrives and its Set-Cookie restores the accept.
    sb.jar.set('trackwp_consent=' + encodeURIComponent(JSON.stringify(acceptCookie)) + '; path=/');
    pending[0].resolve({ ok: true, json: async () => ({ status: 'ok', consent_id: acceptCookie.id }) });
    await tick();
    assert.equal(consentCookie(sb).statistics, false, 'local withdraw rewritten over the stale Set-Cookie');
    assert.equal(pending.length, 2, 'withdraw sent after the first answer');
    assert.equal(JSON.parse(pending[1].init.body).event_type, 'withdraw');
    assert.ok(JSON.parse(pending[1].init.body).ts_ms >= Date.parse(acceptCookie.ts));
});

test('R6: downgrade keeps statistics, revokes Pixel, per-category Consent Mode', async () => {
    const sb = boot();
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    await tick();
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: false });
    await tick();
    const last = calls(sb, 'trackwp/v1/consent-log').pop().body;
    assert.equal(last.event_type, 'update');
    assert.equal(last.statistics, true);
    const upd = sb.spies.gtag.filter((a) => a[0] === 'consent' && a[1] === 'update').pop()[2];
    assert.equal(upd.analytics_storage, 'granted');
    assert.equal(upd.ad_storage, 'denied');
    assert.equal(upd.ad_user_data, 'denied');
    assert.deepEqual(sb.spies.fbq.filter((a) => a[0] === 'consent').map((a) => a[1]), ['grant', 'revoke']);
});

test('R4: stale_version answer invalidates the choice, queues, and a new choice uses current_version', async () => {
    const sb = boot();
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    await tick();
    const orig = sb.window.fetch;
    let staleOnce = true;
    sb.window.fetch = (u, init) => {
        if (String(u).includes('trackwp/v1/event') && staleOnce) {
            staleOnce = false;
            sb.spies.fetch.push([u, init]);
            return Promise.resolve({ ok: true, json: async () => ({ status: 'ok', consent: 'stale_version', current_version: 2 }) });
        }
        return orig(u, init);
    };
    sb.window.trackwp.sendEvent('lead', { value: 3 });
    await tick();
    assert.equal(consentCookie(sb).stale, true, 'cookie marked stale, content kept');
    assert.equal(sb.window.trackwpConsentReader.read(), null);
    assert.ok(sb.spies.fbq.some((a) => a[0] === 'consent' && a[1] === 'revoke'));
    const denied = sb.spies.gtag.filter((a) => a[0] === 'consent' && a[1] === 'update').pop()[2];
    assert.equal(denied.analytics_storage, 'denied');

    const sentBefore = calls(sb, 'trackwp/v1/event').length;
    sb.window.trackwp.sendEvent('lead', { value: 4 });
    await tick();
    assert.equal(calls(sb, 'trackwp/v1/event').length, sentBefore, 'no /event before a new choice');

    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: false });
    await tick();
    assert.equal(consentCookie(sb).v, 2);
    const flushed = calls(sb, 'trackwp/v1/event').slice(sentBefore).map((c) => c.body);
    assert.deepEqual(flushed.map((b) => b.value).sort(), [3, 4]);
    assert.ok(flushed.every((b) => b.consent.v === 2));
});

test('§2.1: passesPageConditions evaluates only triggers of the given type', () => {
    const sb = boot({
        url: 'https://example.org/checkout/',
        trackwp: {
            events: [
                { name: 'add_to_cart', triggers: [{ type: 'woocommerce', conditions: [{ variable: 'page_path', operator: 'contains', value: '/shop' }] }] },
                { name: 'begin_checkout', triggers: [{ type: 'woocommerce', conditions: [{ variable: 'page_path', operator: 'contains', value: '/checkout' }] }] },
                { name: 'free', triggers: [] },
            ],
        },
    });
    assert.equal(sb.window.trackwp.passesPageConditions('add_to_cart', 'woocommerce'), false);
    assert.equal(sb.window.trackwp.passesPageConditions('begin_checkout', 'woocommerce'), true);
    assert.equal(sb.window.trackwp.passesPageConditions('free', 'woocommerce'), true);
    assert.equal(sb.window.trackwp.passesPageConditions('unknown', 'woocommerce'), true);
});

// ---- Review class A: strict flags from the real producer ----

test('class A: producer config carries real booleans', () => {
    const cfg = producerConfig(defaultOptions(1, { customer_data_sharing: false, first_party_cookie_enabled: false }));
    for (const k of ['customerDataSharing', 'fpCookieEnabled', 'debugAllowed', 'requireActiveConsent']) {
        assert.equal(typeof cfg.frontend[k], 'boolean', k);
    }
    assert.equal(cfg.frontend.customerDataSharing, false);
    assert.equal(cfg.frontend.fpCookieEnabled, false);
});

test('class A: customerDataSharing false gives no user_data, no enhanced and no field reads', async () => {
    const sb = boot({ advanced: { customer_data_sharing: false } });
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    let reads = 0;
    const form = fakeForm('a@b.dk');
    const q = form.querySelector;
    form.querySelector = (s) => { reads++; return q(s); };
    sb.window.trackwp.sendFormEvent({ form_id: 'contact' }, form);
    sb.window.trackwp.sendEvent('lead', { enhanced: { email: 'a@b.dk' }, ec: { sha256_email_address: 'a'.repeat(64) } });
    await tick(60);
    const evs = calls(sb, 'trackwp/v1/event');
    assert.equal(evs.length, 2);
    for (const e of evs) assert.equal(e.body.enhanced, undefined);
    assert.equal(reads, 0, 'no form field is read');
    assert.equal(sb.spies.gtag.filter((a) => a[0] === 'set' && a[1] === 'user_data').length, 0);
    assert.ok(sb.spies.gtag.some((a) => a[0] === 'event' && a[1] === 'conversion'), 'the conversion itself still fires');
});

test('class A: fpCookieEnabled false sets no _twp_cid (true does)', async () => {
    const off = boot({ advanced: { first_party_cookie_enabled: false } });
    off.window.trackwpConsent.setChoices({ statistics: true, marketing: false });
    off.window.trackwp.sendEvent('lead');
    off.window.trackwp.sendEvent('lead', { value: 2 });
    await tick();
    assert.equal(off.jar.get('_twp_cid'), null);
    const bodies = calls(off, 'trackwp/v1/event').map((c) => c.body);
    assert.match(bodies[0].client_id, /^\d+\.\d+$/);
    assert.equal(bodies[0].client_id, bodies[1].client_id, 'ephemeral id reused within the page');

    const on = boot();
    on.window.trackwpConsent.setChoices({ statistics: true, marketing: false });
    on.window.trackwp.sendEvent('lead');
    await tick();
    assert.ok(on.jar.get('_twp_cid'));
});

test('class A: non-boolean flag values count as off', async () => {
    const sb = boot({ trackwp: { customerDataSharing: '1', fpCookieEnabled: 1, requireActiveConsent: 'true' } });
    sb.window.trackwp.sendEvent('lead');
    await tick();
    assert.equal(calls(sb, 'trackwp/v1/event').length, 1, 'requireActiveConsent "true" is off: no queue');
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    sb.window.trackwp.sendFormEvent({ form_id: 'c' }, fakeForm('a@b.dk'));
    await tick(60);
    assert.equal(calls(sb, 'trackwp/v1/event').pop().body.enhanced, undefined);
    assert.equal(sb.jar.get('_twp_cid'), null);
});

// ---- Review class F: keepalive race ----

function controlKeepalive(sb) {
    const orig = sb.window.fetch;
    const state = { resolve: null, resolvedAt: 0, log: [] };
    sb.window.fetch = (u, init) => {
        const url = String(u);
        state.log.push({ url, at: Date.now(), body: init && init.body ? JSON.parse(init.body) : null });
        if (url.includes('/keepalive')) {
            sb.spies.fetch.push([u, init]);
            return new Promise((res) => {
                state.resolve = () => {
                    // Set-Cookie renewals the server computed before the withdraw.
                    sb.jar.set('_ga=GA1.1.111.222; path=/');
                    sb.jar.set('_twp_cid=111.222; path=/');
                    sb.jar.set('_fbp=fb.1.1.2; path=/');
                    state.resolvedAt = Date.now();
                    res({ ok: true, json: async () => ({}) });
                };
            });
        }
        return orig(u, init);
    };
    return state;
}

const TRACKING = ['_ga', '_twp_cid', '_fbp', '_fbc', '_twp_click'];

test('class F: withdraw waits for a running keepalive and leaves no tracking cookies', async () => {
    const sb = boot({ url: 'https://example.org/?gclid=G1&fbclid=F1' });
    const ka = controlKeepalive(sb);
    sb.window.localStorage.setItem('trackwp_woo_purchases', '["1"]');
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    assert.ok(ka.resolve, 'keepalive running');
    assert.ok(sb.window.trackwp.pendingKeepalive, 'pendingKeepalive exposed');
    sb.window.trackwpConsent.withdraw();
    await tick(100);
    assert.equal(ka.log.filter((l) => l.body && l.body.event_type === 'withdraw').length, 0, 'withdraw waits for keepalive');
    ka.resolve();
    await tick(60);
    const w = ka.log.find((l) => l.body && l.body.event_type === 'withdraw');
    assert.ok(w && w.at >= ka.resolvedAt, 'withdraw sent after the keepalive answer');
    for (const n of TRACKING) assert.equal(sb.jar.get(n), null, n);
    assert.equal(sb.window.localStorage.getItem('trackwp_ka_ts'), null);
    assert.equal(sb.window.localStorage.getItem('trackwp_woo_purchases'), null);
});

test('class F: a keepalive answer landing after the withdraw answer leaves no tracking cookies', async () => {
    const sb = boot();
    const ka = controlKeepalive(sb);
    sb.window.trackwpConsent.setChoices({ statistics: true, marketing: true });
    sb.window.trackwpConsent.withdraw();
    await tick(3300);
    assert.ok(ka.log.some((l) => l.body && l.body.event_type === 'withdraw'), 'withdraw sent after the 3 s cap');
    ka.resolve();
    await tick(60);
    for (const n of TRACKING) assert.equal(sb.jar.get(n), null, n);
});
