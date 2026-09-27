/**
 * Parity: TrackWP_Privacy::cleaner_js() (window.trackwpPrivacy) must give
 * exactly the same result as the PHP clean_url()/clean_title() for the K6
 * vectors in tests/test-privacy.php.
 *
 * Producer: the real PHP class, dumped by tests/js/privacy-cleaner-dump.php.
 * The test is skipped when no php binary is available.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const phpBinary = process.env.PHP_BINARY || 'php';

function loadDump() {
    try {
        const out = execFileSync(phpBinary, [path.join(here, 'privacy-cleaner-dump.php')], { encoding: 'utf8' });
        return JSON.parse(out);
    } catch (err) {
        if (err && err.code === 'ENOENT') {
            return null;
        }
        throw err;
    }
}

const dump = loadDump();

function cleaner() {
    const sandbox = { window: {} };
    vm.createContext(sandbox);
    vm.runInContext(dump.js, sandbox);
    return sandbox.window.trackwpPrivacy;
}

test('cleaner_js defines window.trackwpPrivacy', { skip: dump === null && 'php not available' }, () => {
    const P = cleaner();
    assert.equal(typeof P.cleanUrl, 'function');
    assert.equal(typeof P.cleanTitle, 'function');
});

test('cleanUrl matches PHP clean_url for every vector', { skip: dump === null && 'php not available' }, () => {
    const P = cleaner();
    for (const v of dump.urls) {
        assert.equal(v.php, v.expected, `PHP vector drifted: ${v.label}`);
        assert.equal(P.cleanUrl(v.input, v.drop), v.php, `JS/PHP differ: ${v.label}`);
    }
});

test('cleanTitle matches PHP clean_title', { skip: dump === null && 'php not available' }, () => {
    const P = cleaner();
    for (const t of dump.titles) {
        assert.equal(P.cleanTitle(t.input), t.php, t.input);
    }
});
