/**
 * "Kun formular-plugins" (plugins_only) and Form ID candidates in
 * sendFormEvent. The events config comes from the REAL producer
 * TrackWP::frontend_config() (-> TrackWP_Events::get_client_config ->
 * TrackWP_Conditions::validate_triggers) via tests/js/client-config-dump.php,
 * fed with the stored option shape (firing_triggers).
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

function row(name, triggers) {
    return {
        name,
        enabled: true,
        trigger_type: triggers[0].type,
        firing_triggers: triggers,
        value: 0,
        currency: 'DKK',
        send_to: { ga4: true, meta: false, google_ads: false },
    };
}
function trig(extra = {}) {
    return { type: 'form_submit', css_selector: '', conditions: [], ...extra };
}
function cond(operator, value, variable = 'form_id') {
    return { variable, operator, value };
}

function producerEvents(rows) {
    const opts = JSON.stringify({ trackwp_events: rows });
    const res = spawnSync('php', [DUMP, opts], { encoding: 'utf8' });
    if (res.status !== 0) throw new Error(`client-config-dump.php failed: ${res.stdout}${res.stderr}`);
    return JSON.parse(res.stdout).frontend.events;
}

function boot(rows) {
    const events = producerEvents(rows);
    const sb = createSandbox({
        url: 'https://example.org/side',
        config: { trackwp: { restUrl: REST, events, googleAds: { conversionId: '', labels: [] } } },
    });
    const file = path.join(PLUGIN_ROOT, 'assets', 'js', 'trackwp.js');
    vm.runInContext(fs.readFileSync(file, 'utf8'), sb.window, { filename: file });
    return sb;
}

function send(sb, detail, form) {
    return sb.window.trackwp.sendFormEvent(detail, form);
}

function domForm(id) {
    return {
        id,
        tagName: 'FORM',
        matches: () => true,
        getAttribute: (n) => (n === 'id' ? id : null),
    };
}

test('producer carries plugins_only into the client config', () => {
    const ev = producerEvents([row('lead', [trig({ plugins_only: true })])]);
    assert.equal(ev[0].triggers[0].plugins_only, true);
});

test('a: plugins_only row ignores plain html forms, accepts cf7', () => {
    const sb = boot([row('lead', [trig({ plugins_only: true })])]);
    assert.equal(send(sb, { plugin: 'html', form_id: 'contact' }, domForm('contact')), 0);
    assert.equal(send(sb, { plugin: 'cf7', form_id: 'cf7_12' }, domForm('wpcf7-f12-p1-o1')), 1);
});

test('b: legacy row without the key still takes html forms', () => {
    const sb = boot([row('lead', [trig()])]);
    assert.equal(send(sb, { plugin: 'html', form_id: 'contact' }, domForm('contact')), 1);
});

test('c: plugins_only row plus legacy row: html form fires only the legacy one', () => {
    const sb = boot([
        row('lead_plugins', [trig({ plugins_only: true })]),
        row('lead_legacy', [trig()]),
    ]);
    assert.equal(send(sb, { plugin: 'html', form_id: 'contact' }, domForm('contact')), 1);
});

test('d: Form ID not_equals the plugin id excludes that form only', () => {
    const sb = boot([row('lead', [trig({ plugins_only: true, conditions: [cond('not_equals', 'cf7_12')] })])]);
    assert.equal(send(sb, { plugin: 'cf7', form_id: 'cf7_12' }, null), 0);
    assert.equal(send(sb, { plugin: 'cf7', form_id: 'cf7_13' }, null), 1);
});

test('e: Form ID equals gf_2 matches Gravity Forms with form=null', () => {
    const sb = boot([row('lead', [trig({ conditions: [cond('equals', 'gf_2')] })])]);
    assert.equal(send(sb, { plugin: 'gravityforms', form_id: 'gf_2' }, null), 1);
});

test('f: DOM-id conditions keep working when a plugin id is present', () => {
    const eq = boot([row('lead', [trig({ conditions: [cond('equals', 'wpforms-form-5')] })])]);
    assert.equal(send(eq, { plugin: 'wpforms', form_id: 'wpforms_5' }, domForm('wpforms-form-5')), 1);
    const ne = boot([row('lead', [trig({ conditions: [cond('not_equals', 'wpforms-form-5')] })])]);
    assert.equal(send(ne, { plugin: 'wpforms', form_id: 'wpforms_5' }, domForm('wpforms-form-5')), 0);
});

test('g: form_id exists on an html form without id stays false', () => {
    const sb = boot([row('lead', [trig({ conditions: [cond('exists', '')] })])]);
    assert.equal(send(sb, { plugin: 'html', form_id: '' }, domForm('')), 0);
});

test('cleanFormId keeps the cf7_12 format (plugin id reaches the condition intact)', () => {
    const sb = boot([row('lead', [trig({ conditions: [cond('equals', 'cf7_12')] })])]);
    assert.equal(send(sb, { plugin: 'cf7', form_id: 'cf7_12' }, null), 1);
});

// Gravity Forms removes the form on success: the listener sends form=null and
// the known DOM id (gform_N) in detail.dom_id.
function gravity(sb) {
    return send(sb, { plugin: 'gravityforms', form_id: 'gf_3', dom_id: 'gform_3' }, null);
}

test('h: Gravity null element: not_equals gform_3 excludes, not_equals gf_3 excludes', () => {
    assert.equal(gravity(boot([row('lead', [trig({ conditions: [cond('not_equals', 'gform_3')] })])])), 0);
    assert.equal(gravity(boot([row('lead', [trig({ conditions: [cond('not_equals', 'gf_3')] })])])), 0);
});

test('i: Gravity null element: equals gform_3 fires, equals gform_2 does not', () => {
    assert.equal(gravity(boot([row('lead', [trig({ conditions: [cond('equals', 'gform_3')] })])])), 1);
    assert.equal(gravity(boot([row('lead', [trig({ conditions: [cond('equals', 'gform_2')] })])])), 0);
});

test('j: plugin names sent by the producer (forms.php) are all accepted by plugins_only', () => {
    const src = fs.readFileSync(path.join(PLUGIN_ROOT, 'includes', 'class-trackwp-forms.php'), 'utf8');
    const names = [...src.matchAll(/send\([^;]*?,\s*'([a-z0-9]+)',\s*(?:true|false)/g)].map((m) => m[1]).filter((n) => n !== 'html');
    assert.deepEqual([...new Set(names)].sort(), ['cf7', 'fluentforms', 'gravityforms', 'sureforms', 'wpforms']);
    for (const plugin of new Set(names)) {
        const sb = boot([row('lead', [trig({ plugins_only: true })])]);
        assert.equal(send(sb, { plugin, form_id: plugin + '_1' }, null), 1, plugin);
    }
});
