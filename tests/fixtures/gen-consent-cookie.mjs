#!/usr/bin/env node
/**
 * Fixture generator: runs the REAL assets/js/consent.js in a headless
 * browser (not the tests/js/helpers/sandbox.mjs vm sandbox -- this is
 * about proving the vm sandbox's cookie handling agrees with a real
 * browser, so it must not reuse it) and captures the trackwp_consent
 * cookie value it writes for an "accept all" click. Writes
 * tests/fixtures/consent-cookie.json, which test-consent.php (W1) and
 * tests/js/*.test.mjs (W5) can load as a real-producer fixture instead of
 * a hand-built guess at the cookie's shape.
 *
 * Usage: node tests/fixtures/gen-consent-cookie.mjs
 *
 * Requires a Chromium binary reachable by Playwright's `chromium.launch()`
 * (`npx playwright install chromium` once @playwright/test is installed --
 * see tests/W0-NOTES.md for the devDependency W8 needs to add).
 *
 * This is a SKELETON against the CURRENT consent.js/banner markup: it
 * injects a bare `[data-action="accept-all"]` button, which is all
 * consent.js's applyConsent() path needs for accept-all (see
 * assets/js/consent.js `setupHandlers()`). If W1/W6 change the banner
 * markup or add fields the accept-all path reads (K3/K4), extend the
 * injected HTML below to match.
 */
import { chromium } from 'playwright-core';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = path.resolve(__dirname, '..', '..');
const CONSENT_JS = path.join(PLUGIN_ROOT, 'assets', 'js', 'consent.js');
const OUT_FILE = path.join(__dirname, 'consent-cookie.json');

const CONSENT_CONFIG = {
    cookieLifetimeMonths: 12,
    consentVersion: 1,
    log_consent: false,
    restUrl: '',
};

async function main() {
    if (!fs.existsSync(CONSENT_JS)) {
        throw new Error(`consent.js not found at ${CONSENT_JS}`);
    }

    const browser = await chromium.launch();
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        // about:blank has an opaque origin and no cookie jar (document.cookie
        // throws SecurityError), so serve the page from a real http origin.
        const ORIGIN = 'http://trackwp-fixture.test';
        const html =
            '<!doctype html><html><head>' +
            `<script>window.trackwpConsentConfig = ${JSON.stringify(CONSENT_CONFIG)};</script>` +
            '</head><body>' +
            '<div id="trackwp-consent-banner"><button type="button" data-action="accept-all">Accept</button></div>' +
            '<script src="/consent.js"></script>' +
            '</body></html>';
        await page.route(`${ORIGIN}/**`, (route) => {
            const url = new URL(route.request().url());
            if (url.pathname === '/consent.js') {
                return route.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(CONSENT_JS, 'utf8') });
            }
            return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
        });
        await page.goto(`${ORIGIN}/`);

        await page.click('[data-action="accept-all"]');
        await page.waitForTimeout(50);

        // Read from the browser's cookie jar (what a real server would receive).
        const jar = await context.cookies(ORIGIN);
        const entry = jar.find((c) => c.name === 'trackwp_consent');
        if (!entry) {
            throw new Error('consent.js did not set trackwp_consent after an accept-all click');
        }

        const raw = decodeURIComponent(entry.value);
        const parsed = JSON.parse(raw);

        const out = {
            generated_at: new Date().toISOString(),
            generated_by: 'tests/fixtures/gen-consent-cookie.mjs (real consent.js in headless Chromium)',
            consent_config: CONSENT_CONFIG,
            raw_cookie_value: raw,
            parsed,
        };

        fs.writeFileSync(OUT_FILE, JSON.stringify(out, null, 2) + '\n');
        console.log(`Wrote ${OUT_FILE}`);
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
