/**
 * 1.11.3: extra GA4 Measurement IDs on the client. Config is built by the
 * REAL producer TrackWP::frontend_config() (trackwp.php) via
 * tests/js/client-config-dump.php, and the REAL assets/js/trackwp.js runs in
 * the vm sandbox with the gtag spy from helpers/sandbox.mjs.
 *
 * With extra IDs the GA4 client event goes to send_to [primary, extra...]
 * (G- IDs only, never AW-). Without them send_to stays a string. On the GTM
 * route the producer emits no extra IDs.
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
const PRIMARY = 'G-TEST1234';

const LEAD_EVENT = {
    name: 'lead',
    enabled: true,
    value: 0,
    currency: 'DKK',
    send_to: { ga4: true, meta: false, google_ads: false },
    triggers: [{ type: 'form_submit', css_selector: '', conditions: [] }],
};

function producerConfig(options) {
    const res = spawnSync('php', [DUMP, JSON.stringify(options)], { encoding: 'utf8' });
    if (res.status !== 0) throw new Error(`client-config-dump.php failed: ${res.stdout}${res.stderr}`);
    return JSON.parse(res.stdout);
}

function options({ extra = [], advanced = {}, platforms = {} } = {}) {
    return {
        trackwp_platforms: {
            ga4_enabled: true,
            ga4_gtag_enabled: true,
            ga4_measurement_id: PRIMARY,
            ga4_extra_ids: extra,
            google_ads_enabled: true,
            google_ads_conversion_id: 'AW-1234567890',
            ...platforms,
        },
        trackwp_advanced: { dedup_mode: 'client_only', ...advanced },
        trackwp_consent: { require_active_consent: true, consent_version: 1, cookie_lifetime_months: 12 },
    };
}

function boot(opts) {
    const prod = producerConfig(opts);
    const sb = createSandbox({
        url: 'https://example.org/',
        config: {
            consent: { ...prod.consent, restUrl: REST },
            trackwp: { ...prod.frontend, restUrl: REST, events: [LEAD_EVENT] },
        },
    });
    vm.runInContext(
        `window.trackwpConsentReader = { read: function () {
            var m = document.cookie.match(/(?:^|; )trackwp_consent=([^;]*)/);
            if (!m) return null;
            try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
        } };`,
        sb.window
    );
    sb.window.document.cookie =
        'trackwp_consent=' + encodeURIComponent(JSON.stringify({ v: 1, statistics: true, marketing: false, necessary: true, ts: new Date().toISOString(), id: 'c1' })) + '; path=/';
    const file = path.join(PLUGIN_ROOT, 'assets', 'js', 'trackwp.js');
    vm.runInContext(fs.readFileSync(file, 'utf8'), sb.window, { filename: file });
    return { sb, prod };
}

const tick = (ms = 20) => new Promise((r) => setTimeout(r, ms));

function ga4Events(sb) {
    return sb.spies.gtag.filter((a) => a[0] === 'event' && a[1] === 'lead');
}

test('producer emits ga4ExtraIds (normalised, no primary, no duplicates, max 3)', () => {
    const prod = producerConfig(options({ extra: [
        { id: ' g-extra111 ', api_secret: '' },
        { id: PRIMARY, api_secret: '' },
        { id: 'G-EXTRA111', api_secret: '' },
        { id: 'not-an-id', api_secret: '' },
        { id: 'G-EXTRA222', api_secret: '' },
        { id: 'G-EXTRA333', api_secret: '' },
        { id: 'G-EXTRA444', api_secret: '' },
    ] }));
    assert.deepEqual(prod.frontend.ga4ExtraIds, ['G-EXTRA111', 'G-EXTRA222', 'G-EXTRA333']);
});

test('with extra IDs: send_to is an array [primary, extra...] without AW', async () => {
    const { sb, prod } = boot(options({ extra: [{ id: 'G-EXTRA111', api_secret: '' }, { id: 'G-EXTRA222', api_secret: 'x' }] }));
    assert.deepEqual(prod.frontend.ga4ExtraIds, ['G-EXTRA111', 'G-EXTRA222']);
    sb.window.trackwp.sendEvent('lead', { value: 5, currency: 'DKK' });
    await tick();
    const ev = ga4Events(sb);
    assert.equal(ev.length, 1, 'one GA4 client event');
    assert.ok(Array.isArray(ev[0][2].send_to), 'send_to is an array');
    assert.deepEqual(Array.from(ev[0][2].send_to), [PRIMARY, 'G-EXTRA111', 'G-EXTRA222']);
    assert.ok(!Array.from(ev[0][2].send_to).some((id) => id.indexOf('AW-') === 0), 'no AW in GA4 send_to');
});

test('without extra IDs: send_to stays a string (no regression)', async () => {
    const { sb, prod } = boot(options());
    assert.deepEqual(prod.frontend.ga4ExtraIds, []);
    sb.window.trackwp.sendEvent('lead', { value: 5, currency: 'DKK' });
    await tick();
    const ev = ga4Events(sb);
    assert.equal(ev.length, 1);
    assert.equal(ev[0][2].send_to, PRIMARY);
});

test('uses_gtm: producer emits no extra IDs', () => {
    const prod = producerConfig(options({ extra: [{ id: 'G-EXTRA111', api_secret: '' }], advanced: { uses_gtm: true } }));
    assert.deepEqual(prod.frontend.ga4ExtraIds, []);
});

test('gtm_enabled or GA4 off or gtag off: producer emits no extra IDs', () => {
    const extra = [{ id: 'G-EXTRA111', api_secret: '' }];
    assert.deepEqual(producerConfig(options({ extra, platforms: { gtm_enabled: true, gtm_container_id: 'GTM-ABCD12' } })).frontend.ga4ExtraIds, []);
    assert.deepEqual(producerConfig(options({ extra, platforms: { ga4_enabled: false } })).frontend.ga4ExtraIds, []);
    assert.deepEqual(producerConfig(options({ extra, platforms: { ga4_gtag_enabled: false } })).frontend.ga4ExtraIds, []);
});

test('client filters a hand-fed invalid list (only valid G- IDs, no primary, no duplicates)', async () => {
    const prod = producerConfig(options());
    const sb = createSandbox({
        url: 'https://example.org/',
        config: {
            consent: { ...prod.consent, restUrl: REST },
            trackwp: { ...prod.frontend, restUrl: REST, events: [LEAD_EVENT], ga4ExtraIds: ['AW-1', PRIMARY, 'G-EXTRA111', 'G-EXTRA111', 7, 'g-lower1'] },
        },
    });
    vm.runInContext(
        `window.trackwpConsentReader = { read: function () { return { v: 1, statistics: true, marketing: false, necessary: true }; } };`,
        sb.window
    );
    const file = path.join(PLUGIN_ROOT, 'assets', 'js', 'trackwp.js');
    vm.runInContext(fs.readFileSync(file, 'utf8'), sb.window, { filename: file });
    sb.window.trackwp.sendEvent('lead', {});
    await tick();
    const ev = ga4Events(sb);
    assert.equal(ev.length, 1);
    assert.deepEqual(Array.from(ev[0][2].send_to), [PRIMARY, 'G-EXTRA111']);
});
