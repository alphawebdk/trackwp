/**
 * T3: KB11 reload on withdrawal (PLAN-1.11.0-v2 §2 KB11, BESLUTNINGER-1.11.0
 * "Tilbagetrækning"). Runs the REAL assets/js/consent.js with the REAL
 * TrackWP_Consent::reader_js() and consent config from the real producer
 * TrackWP::consent_config() (tests/js/client-config-dump.php) in the W0 vm
 * sandbox. The cookie is always written by consent.js itself.
 *
 * With window.trackwpBlocker present, a category going true -> false must
 * call location.reload() at once, AFTER the synchronous cookie write and the
 * trackwp:consent_revoked event. Without the guard there is no reload.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createSandbox, PLUGIN_ROOT } from './helpers/sandbox.mjs';

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

function consentConfig() {
    const opts = { trackwp_consent: { require_active_consent: true, consent_version: 1, cookie_lifetime_months: 12 } };
    const res = spawnSync('php', [path.join(PLUGIN_ROOT, 'tests', 'js', 'client-config-dump.php'), JSON.stringify(opts)], { encoding: 'utf8' });
    if (res.status !== 0) throw new Error(`client-config-dump.php failed: ${res.stdout}${res.stderr}`);
    return JSON.parse(res.stdout).consent;
}
const CONSENT_CONFIG = consentConfig();

function boot({ guard }) {
    const sb = createSandbox({ url: 'https://example.org/side', config: { consent: { ...CONSENT_CONFIG, restUrl: 'https://example.org/wp-json/' } } });
    const w = sb.window;
    const trail = [];
    w.location.reload = () => {
        const c = sb.jar.get('trackwp_consent');
        trail.push({ step: 'reload', cookie: c ? JSON.parse(decodeURIComponent(c.value)) : null });
    };
    if (guard) w.trackwpBlocker = { v: 1, active: true, match: () => null, release: () => {} };
    vm.runInContext(READER, w);
    const file = path.join(PLUGIN_ROOT, 'assets', 'js', 'consent.js');
    vm.runInContext(fs.readFileSync(file, 'utf8'), w, { filename: file });
    w.document.addEventListener('trackwp:consent_revoked', (e) => trail.push({ step: 'revoked', detail: e.detail }));
    return { sb, w, trail, reloads: () => trail.filter((t) => t.step === 'reload') };
}

test('with guard: downgrade reloads at once, after cookie write and revoked event', () => {
    const { w, trail, reloads } = boot({ guard: true });
    w.trackwpConsent.setChoices({ statistics: true, marketing: true });
    assert.equal(reloads().length, 0, 'first grant does not reload');
    w.trackwpConsent.setChoices({ statistics: true, marketing: false });
    // Synchronous: reload already happened when setChoices returned.
    assert.equal(reloads().length, 1);
    const steps = trail.map((t) => t.step);
    assert.deepEqual(steps, ['revoked', 'reload']);
    assert.equal(trail[0].detail.marketing, true);
    assert.equal(trail[1].cookie.marketing, false, 'cookie already written when reload is called');
    assert.equal(trail[1].cookie.statistics, true);
    assert.equal(w.trackwpConsentReader.read().marketing, false);
});

test('with guard: withdraw() reloads', () => {
    const { w, trail, reloads } = boot({ guard: true });
    w.trackwpConsent.setChoices({ statistics: true, marketing: true, personalisation: true });
    w.trackwpConsent.withdraw();
    assert.equal(reloads().length, 1);
    assert.deepEqual(trail.map((t) => t.step), ['revoked', 'reload']);
    const c = trail[1].cookie;
    assert.deepEqual([c.statistics, c.marketing, c.personalisation], [false, false, false]);
});

test('with guard: first refusal and upgrades do not reload', () => {
    const { w, reloads } = boot({ guard: true });
    w.trackwpConsent.setChoices({ statistics: false, marketing: false });
    w.trackwpConsent.setChoices({ statistics: true, marketing: false });
    w.trackwpConsent.setChoices({ statistics: true, marketing: true });
    assert.equal(reloads().length, 0);
});

test('without guard: withdrawal behaves as 1.10.1 (no reload)', () => {
    const { w, trail, reloads } = boot({ guard: false });
    w.trackwpConsent.setChoices({ statistics: true, marketing: true });
    w.trackwpConsent.setChoices({ statistics: true, marketing: false });
    w.trackwpConsent.withdraw();
    assert.equal(reloads().length, 0);
    assert.equal(trail.filter((t) => t.step === 'revoked').length, 2, 'revoked still fires');
});
