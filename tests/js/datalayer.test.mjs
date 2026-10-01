/**
 * W4: dataLayer push layer + GA4 routing (PLAN-1.11.1-v2 KC7, KC8, KC9, §9
 * TR1-TR8). Runs the REAL assets/js/trackwp.js in the W0 vm sandbox, against
 * config from the REAL producer TrackWP::frontend_config() /
 * TrackWP_DataLayer::client_config() (tests/js/client-config-dump.php).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createSandbox, PLUGIN_ROOT } from './helpers/sandbox.mjs';

const REST = 'https://example.org/wp-json/';
const DUMP = path.join(PLUGIN_ROOT, 'tests', 'js', 'client-config-dump.php');

const configCache = new Map();
function producerConfig(options) {
    const key = JSON.stringify(options);
    if (!configCache.has(key)) {
        const res = spawnSync('php', [DUMP, key], { encoding: 'utf8' });
        if (res.status !== 0) throw new Error(`client-config-dump.php failed: ${res.stdout}${res.stderr}`);
        const parsed = JSON.parse(res.stdout);
        assert.ok(parsed.frontend.dataLayer, 'producer must carry trackwpConfig.dataLayer (KC7)');
        configCache.set(key, parsed);
    }
    return JSON.parse(JSON.stringify(configCache.get(key)));
}

function dataLayerOptions(overrides = {}) {
    return {
        trackwp_platforms: {
            gtm_datalayer_events: 'on',
            ga4_source: 'gtm',
            ga4_enabled: true,
            ga4_measurement_id: 'G-TEST12345',
            ga4_api_secret: 'test-secret',
            ...overrides,
        },
    };
}

const PURCHASE_EVENT = {
    name: 'purchase',
    enabled: true,
    value: 0,
    currency: 'DKK',
    ads_label: 'LBL',
    meta_resolved: 'Purchase',
    send_to: { ga4: true, meta: true, google_ads: true },
    triggers: [],
};

const VIEW_ITEM_EVENT = {
    name: 'view_item',
    enabled: true,
    value: 0,
    currency: 'DKK',
    send_to: { ga4: true, meta: false, google_ads: false },
    triggers: [],
};

function boot({ url = 'https://example.org/', trackwpOptions = {}, extraOptions = {}, trackwp = {}, events = [PURCHASE_EVENT, VIEW_ITEM_EVENT] } = {}) {
    const prod = producerConfig({ ...dataLayerOptions(trackwpOptions), ...extraOptions });
    const sb = createSandbox({
        url,
        config: {
            consent: { ...prod.consent, restUrl: REST },
            trackwp: {
                ...prod.frontend,
                restUrl: REST,
                googleAds: { conversionId: 'AW-1', labels: [] },
                events,
                ...trackwp,
            },
        },
    });
    // Minimal K3 reader stand-in: only what these tests need (has/statistics/marketing).
    vm.runInContext(
        `window.trackwpConsentReader = { read: function () {
            var m = document.cookie.match(/(?:^|; )trackwp_consent=([^;]*)/);
            if (!m) return null;
            try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
        } };`,
        sb.window
    );
    const file = path.join(PLUGIN_ROOT, 'assets', 'js', 'trackwp.js');
    vm.runInContext(fs.readFileSync(file, 'utf8'), sb.window, { filename: file });
    return sb;
}

function setConsent(sb, { statistics = false, marketing = false } = {}) {
    sb.window.document.cookie =
        'trackwp_consent=' + encodeURIComponent(JSON.stringify({ v: 1, statistics, marketing, necessary: true, ts: new Date().toISOString(), id: 'c1' })) + '; path=/';
}

const tick = (ms = 20) => new Promise((r) => setTimeout(r, ms));

function calls(sb, pathPart) {
    return sb.spies.fetch
        .filter(([u]) => String(u).includes(pathPart))
        .map(([u, init]) => ({ url: String(u), body: init && init.body ? JSON.parse(init.body) : null }));
}

const ITEMS = [
    { item_id: 'SKU-1', item_name: 'Sok', item_sku: 'SKU-1', price: 49.5, quantity: 2 },
    { item_id: 'SKU-2', item_name: 'Hue', price: 100, quantity: 1 },
];

// === Class A: producer shape ===

test('class A: producer carries trackwpConfig.dataLayer with real booleans/strings', () => {
    const prod = producerConfig(dataLayerOptions());
    const dl = prod.frontend.dataLayer;
    assert.equal(typeof dl.enabled, 'boolean');
    assert.equal(typeof dl.ga4Enabled, 'boolean');
    assert.equal(typeof dl.mpConfigured, 'boolean');
    assert.equal(typeof dl.ga4Source, 'string');
    assert.equal(dl.enabled, true);
});

// === R before/after (D3) ===

test('KC7/D3: R is pushed before and after every event, so an event never inherits trackwp/user_data', async () => {
    const sb = boot();
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    const dl = JSON.parse(JSON.stringify(sb.window.dataLayer));
    assert.equal(dl.length, 3, 'reset, event, reset');
    assert.deepEqual(dl[0], { ecommerce: null, user_data: null, trackwp: null });
    assert.deepEqual(dl[2], { ecommerce: null, user_data: null, trackwp: null });
    assert.equal(dl[1].event, 'view_item');
});

// === Paths: transaction_id, value, conversion_value, ga4_route ===

test('KC7: ecommerce.value is sum(price*quantity), ecommerce.transaction_id and trackwp.conversion_value/ga4_route are set', async () => {
    const sb = boot();
    const route = sb.window.trackwp.pushDataLayer('purchase', {
        value: 199,
        currency: 'DKK',
        ecommerce: { currency: 'DKK', transaction_id: 'ORD-1', items: ITEMS, tax: 20, shipping: 39 },
    });
    assert.equal(route, 'gtm');
    const push = sb.window.dataLayer[1];
    assert.equal(push.ecommerce.transaction_id, 'ORD-1');
    assert.equal(push.ecommerce.value, 49.5 * 2 + 100 * 1);
    assert.equal(push.ecommerce.tax, 20);
    assert.equal(push.ecommerce.shipping, 39);
    assert.equal(push.trackwp.conversion_value, 199, 'Ads value (value_basis) is params.value, independent of GA4 ecommerce.value');
    assert.equal(push.trackwp.ga4_route, 'gtm');
    assert.equal(push.trackwp.source, 'trackwp');
    assert.match(push.trackwp.event_id, /^evt_[a-f0-9]{32}$/);
});

// === user_data: purchase + marketing only ===

test('KC7/D3: user_data is pushed only for purchase, only with marketing consent at push time, and only when present', async () => {
    const sb = boot();

    // No marketing consent: user_data stays out even though ec is given.
    sb.window.trackwp.pushDataLayer('purchase', { value: 1, ecommerce: { currency: 'DKK', items: ITEMS }, ec: { sha256_email_address: 'a'.repeat(64) } });
    assert.equal(sb.window.dataLayer[1].user_data, undefined);

    // Non-purchase event: never gets user_data, even with marketing consent and ec given.
    setConsent(sb, { statistics: true, marketing: true });
    sb.window.trackwp.pushDataLayer('view_item', { ecommerce: { currency: 'DKK', items: ITEMS }, ec: { sha256_email_address: 'a'.repeat(64) } });
    assert.equal(sb.window.dataLayer[sb.window.dataLayer.length - 2].user_data, undefined);

    // Purchase + marketing + ec present: user_data is pushed.
    sb.window.trackwp.pushDataLayer('purchase', { value: 1, ecommerce: { currency: 'DKK', items: ITEMS }, ec: { sha256_email_address: 'a'.repeat(64) } });
    const withUserData = JSON.parse(JSON.stringify(sb.window.dataLayer[sb.window.dataLayer.length - 2]));
    assert.deepEqual(withUserData.user_data, { sha256_email_address: 'a'.repeat(64) });

    // Purchase + marketing but no ec: no user_data key.
    sb.window.trackwp.pushDataLayer('purchase', { value: 1, ecommerce: { currency: 'DKK', items: ITEMS } });
    assert.equal(sb.window.dataLayer[sb.window.dataLayer.length - 2].user_data, undefined);
});

test('KC7: coupon only leaves RAM with at least one consent choice', async () => {
    const sb = boot();
    sb.window.trackwp.pushDataLayer('view_item', { ecommerce: { currency: 'DKK', items: ITEMS, coupon: 'SUMMER10' } });
    assert.equal(sb.window.dataLayer[1].ecommerce.coupon, undefined, 'no coupon before any consent choice');

    setConsent(sb, { statistics: true, marketing: false });
    sb.window.trackwp.pushDataLayer('view_item', { ecommerce: { currency: 'DKK', items: ITEMS, coupon: 'SUMMER10' } });
    assert.equal(sb.window.dataLayer[sb.window.dataLayer.length - 2].ecommerce.coupon, 'SUMMER10');
});

// === No raw PII ===

test('no @ and no raw phone number ever appear in a dataLayer push', async () => {
    const sb = boot();
    setConsent(sb, { statistics: true, marketing: true });
    sb.window.trackwp.pushDataLayer('purchase', {
        value: 1,
        ecommerce: { currency: 'DKK', items: ITEMS },
        enhanced: { email: 'jens@example.dk', phone: '+4512345678' },
    });
    const dump = JSON.stringify(sb.window.dataLayer);
    assert.doesNotMatch(dump, /@/);
    assert.doesNotMatch(dump, /\+4512345678/);
});

// === Push happens even when fetch fails (D2) ===

test('D2: the dataLayer push happens even when the proxy fetch rejects', async () => {
    const sb = boot();
    sb.window.fetch = () => Promise.reject(new Error('network down'));
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    assert.equal(sb.window.dataLayer.length, 3);
    assert.equal(sb.window.dataLayer[1].event, 'view_item');
});

// === No push when dataLayerDone ===

test('pushDataLayer is never called a second time for an event flushed with dataLayerDone', async () => {
    const sb = boot();
    // Direct low-level call with dataLayerDone semantics simulated via sendInternal
    // is exercised through the public sendEvent()/queue path below.
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    assert.equal(sb.window.dataLayer.length, 3, 'exactly one push cycle before any consent choice');

    setConsent(sb, { statistics: true, marketing: false });
    sb.window.dispatchEvent({ type: 'trackwp:consent_updated' });
    await tick();
    // The flush re-sends to the server but must NOT push to dataLayer again.
    assert.equal(sb.window.dataLayer.length, 3, 'flush must not push a second time');
    assert.equal(calls(sb, 'trackwp/v1/event').length, 1, 'the server POST does happen at flush');
});

test('the requeued (flushed) POST carries the route decided at push time', async () => {
    const sb = boot();
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    const routeAtPush = sb.window.dataLayer[1].trackwp.ga4_route;

    setConsent(sb, { statistics: true, marketing: false });
    sb.window.dispatchEvent({ type: 'trackwp:consent_updated' });
    await tick();
    const sent = calls(sb, 'trackwp/v1/event').pop();
    assert.equal(sent.body.ga4_route, routeAtPush);
    assert.equal(sent.body.event_id, sb.window.dataLayer[1].trackwp.event_id, 'same event_id reused at flush');
});

// === The real pre-consent queue (require_active_consent): D2 + TR8 ===

test('D2/TR8: with require_active_consent the push happens before consent, and the queued event keeps its event_id and route at flush', async () => {
    const sb = boot({ extraOptions: { trackwp_consent: { require_active_consent: true } } });
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    assert.equal(sb.window.dataLayer.length, 3, 'D2: the push does not wait for consent even when the POST is queued');
    assert.equal(sb.window.dataLayer[1].event, 'view_item');
    assert.equal(calls(sb, 'trackwp/v1/event').length, 0, 'the POST is queued until a choice exists');
    const pushedId = sb.window.dataLayer[1].trackwp.event_id;
    const pushedRoute = sb.window.dataLayer[1].trackwp.ga4_route;
    assert.match(pushedId, /^evt_[a-f0-9]{16,64}$/);

    setConsent(sb, { statistics: true, marketing: false });
    sb.window.dispatchEvent({ type: 'trackwp:consent_updated' });
    await tick();
    const sent = calls(sb, 'trackwp/v1/event');
    assert.equal(sent.length, 1, 'the queued event is flushed once');
    assert.equal(sent[0].body.event_id, pushedId, 'TR8: flush reuses the pushed event_id, never mints a new one');
    assert.equal(sent[0].body.ga4_route, pushedRoute);
    assert.equal(sb.window.dataLayer.length, 3, 'flush does not push a second time');
});

// === KC9 for the GA4 client event (dedup_mode client_only) ===

test('KC9/D5: with dedup_mode client_only and the layer active, no GA4 gtag event is fired', async () => {
    const sb = boot({ extraOptions: { trackwp_advanced: { dedup_mode: 'client_only' } } });
    setConsent(sb, { statistics: true, marketing: false });
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    assert.equal(sb.spies.gtag.filter((a) => a[0] === 'event').length, 0, 'GTM owns the GA4 event while the layer is active');
});

test('KC9: with dedup_mode client_only and no layer, the GA4 gtag event fires as in 1.11.0', async () => {
    const sb = boot({ trackwpOptions: { gtm_datalayer_events: 'off' }, extraOptions: { trackwp_advanced: { dedup_mode: 'client_only' } } });
    setConsent(sb, { statistics: true, marketing: false });
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    assert.ok(sb.spies.gtag.some((a) => a[0] === 'event' && a[1] === 'view_item'), 'GA4 client event fires without the layer');
});

// === Only active events push ===

test('an event absent from config.events is never pushed', () => {
    const sb = boot();
    const route = sb.window.trackwp.pushDataLayer('unknown_event', {});
    assert.equal(route, null);
    assert.equal(sb.window.dataLayer.length, 0);
});

// === Krav 2: pushDataLayer() never throws to its caller ===

test('Krav 2: an external caller with window.dataLayer = {} gets null, no exception, and sendEvent still posts', async () => {
    const sb = boot();
    sb.window.dataLayer = {}; // not an array: .push() does not exist

    assert.doesNotThrow(() => {
        const route = sb.window.trackwp.pushDataLayer('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
        assert.equal(route, null, 'a throwing push is swallowed and reported as "nothing pushed"');
    });

    // sendEvent() -> sendInternal() must not be affected either: the server
    // POST still happens even though the dataLayer push failed internally.
    sb.window.trackwp.sendEvent('view_item', { ecommerce: { currency: 'DKK', items: ITEMS } });
    await tick();
    assert.equal(calls(sb, 'trackwp/v1/event').length, 1);
});

// === KC9: gtag guard ===

test('KC9/D5: with the layer active, fireGoogleAdsConversion and fireGa4ClientEvent never call gtag', async () => {
    const sb = boot({ trackwpOptions: { ga4_source: 'gtm' } });
    setConsent(sb, { statistics: true, marketing: true });
    sb.window.trackwp.sendEvent('purchase', { value: 10, ecommerce: { currency: 'DKK', items: ITEMS, transaction_id: 'X1' } });
    await tick();
    assert.equal(sb.spies.gtag.length, 0, 'no gtag call at all while the layer is active');
});

test('KC9: without the layer, gtag is called as in 1.11.0', async () => {
    const sb = boot({ trackwpOptions: { gtm_datalayer_events: 'off' } });
    setConsent(sb, { statistics: true, marketing: true });
    sb.window.trackwp.sendEvent('purchase', { value: 10, ecommerce: { currency: 'DKK', items: ITEMS, transaction_id: 'X1' } });
    await tick();
    assert.ok(sb.spies.gtag.some((a) => a[0] === 'event' && a[1] === 'conversion'), 'Ads conversion fires as before without the layer');
});

// === KC8: ga4_route vectors shared between PHP and JS ===
//
// ga4Route() itself is an internal helper (not part of the public API, KC7),
// so each vector is exercised through the real public entry point,
// pushDataLayer(), with a sandbox configured to reproduce exactly the vector's
// inputs. This also proves the wiring inside pushDataLayer, not just the pure
// function in isolation.

const VECTORS = JSON.parse(fs.readFileSync(path.join(PLUGIN_ROOT, 'tests', 'fixtures', 'datalayer', 'ga4-route-vectors.json'), 'utf8'));

for (const c of VECTORS.cases) {
    test(`KC8/TR4 vector: ${c.name}`, () => {
        const input = c.input;
        const da = input.datalayer_active;

        if (da !== undefined && da !== true && da !== false) {
            // A non-boolean datalayer_active (e.g. the integer 0 vector) only
            // ever reaches PHP's ga4_route() directly (tests/test-datalayer.php):
            // the real JS entry point, pushDataLayer(), always calls the
            // internal ga4Route() with a literal boolean, so this specific
            // defensive input is unreachable through the public API. The
            // fixture is still shared: PHP tests this exact vector.
            assert.equal(c.expected, 'off', 'non-boolean datalayer_active vectors must expect off');
            return;
        }

        const eventName = input.event || 'x';
        const layerOn = da !== false;

        const sb = boot({
            trackwpOptions: {
                gtm_datalayer_events: layerOn ? 'on' : 'off',
                ga4_source: input.source || 'gtm',
                ga4_enabled: !!input.ga4_enabled,
                ga4_measurement_id: input.ga4_enabled ? 'G-TEST12345' : '',
                ga4_api_secret: input.mp_configured ? 'test-secret' : '',
            },
            trackwp: (input.dedup_mode !== undefined) ? { dedupMode: input.dedup_mode } : {},
            events: [{
                name: eventName, enabled: true, value: 0, currency: 'DKK',
                send_to: { ga4: !!input.send_to_ga4, meta: false, google_ads: false },
                triggers: [],
            }],
        });
        setConsent(sb, { statistics: !!input.statistics, marketing: false });

        const route = sb.window.trackwp.pushDataLayer(eventName, { value: 0 });

        if (!layerOn) {
            // pushDataLayer's own layer-active guard returns null before ever
            // reaching ga4Route(); nothing is pushed either way, which is the
            // same real-world outcome as the pure function's 'off'.
            assert.equal(route, null);
            assert.equal(sb.window.dataLayer.length, 0);
        } else {
            assert.equal(route, c.expected, JSON.stringify(input));
        }
    });
}
