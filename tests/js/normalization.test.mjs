/**
 * W5: the JS normalisation in assets/js/trackwp.js must produce the same
 * output as TrackWP_Hash (W3) for the shared vectors in
 * tests/fixtures/normalization-vectors.json (K8, R19).
 *
 * Expected vector file shape (every section optional):
 * {
 *   "google_email": [{ "input": "...", "expected": "..." }],
 *   "google_phone": [{ "input": "...", "country": "DK", "expected": "+45..." | null }],
 *   "meta_em":      [{ "input": "...", "expected": "..." }],
 *   "meta_ph":      [{ "input": "...", "country": "DK", "expected": "45..." | null }]
 * }
 * An optional "expected_sha256" per vector is checked against sha256 of the
 * JS output.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createSandbox, PLUGIN_ROOT } from './helpers/sandbox.mjs';

const VECTORS = path.join(PLUGIN_ROOT, 'tests', 'fixtures', 'normalization-vectors.json');

function normalizers(defaultPhoneCountry = 'DK') {
    const sb = createSandbox({
        scripts: ['trackwp.js'],
        config: { trackwp: { restUrl: 'https://example.org/wp-json/', defaultPhoneCountry, events: [] } },
    });
    return sb.window.trackwp.normalize;
}

const sha = (s) => crypto.createHash('sha256').update(s, 'utf8').digest('hex');

const SECTIONS = {
    google_email: (n, v) => n.googleEmail(v.input),
    google_phone: (n, v) => n.phoneE164(v.input, v.country),
    meta_em: (n, v) => n.metaEmail(v.input),
    meta_ph: (n, v) => n.metaPhone(v.input, v.country),
};

test('shared normalisation vectors (producer: W3 fixture)', (t) => {
    if (!fs.existsSync(VECTORS)) {
        t.skip(`missing ${VECTORS} (written by W3/W0); the shared-vector check did not run`);
        return;
    }
    const data = JSON.parse(fs.readFileSync(VECTORS, 'utf8'));
    const n = normalizers();
    let checked = 0;
    for (const [section, fn] of Object.entries(SECTIONS)) {
        for (const v of data[section] || []) {
            const out = fn(n, v) || null;
            assert.equal(out, v.expected ?? null, `${section}: ${JSON.stringify(v.input)} (${v.country || ''})`);
            if (v.expected_sha256 && out) {
                assert.equal(sha(out), v.expected_sha256, `${section} sha256: ${JSON.stringify(v.input)}`);
            }
            checked++;
        }
    }
    assert.ok(checked > 0, 'vector file contains no known sections');
    console.log(`# normalisation vectors checked: ${checked}`);
});

// Contract rules quoted from K8/R19 (supplement, not a fixture substitute).
test('K8/R19 contract rules', () => {
    const n = normalizers('SE');
    assert.equal(n.googleEmail('  Jens.Hansen+news@GoogleMail.com '), 'jenshansen@googlemail.com');
    assert.equal(n.googleEmail('Jens.Hansen+news@example.dk'), 'jens.hansen+news@example.dk');
    assert.equal(n.metaEmail('  Jens.Hansen+news@Gmail.com '), 'jens.hansen+news@gmail.com');
    assert.equal(n.phoneE164('+45 12 34 56 78'), '+4512345678');
    assert.equal(n.phoneE164('0045 12345678'), '+4512345678');
    assert.equal(n.phoneE164('070-123 45 67'), '+46701234567', 'SE trunk 0 removed once (default country)');
    assert.equal(n.phoneE164('12345678', 'DK'), '+4512345678');
    assert.equal(n.phoneE164('030 1234567', 'DE'), '+49301234567');
    assert.equal(n.phoneE164('12345678', 'US'), '', 'unknown country gives no hash');
    assert.equal(n.metaPhone('+45 12 34 56 78'), '4512345678');
});
