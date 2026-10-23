/**
 * W5 (PLAN-1.10.1-v4 §8, R16, K6): an e-mail in the path, in an arbitrary
 * query parameter, in the title and in the referrer never appears in a
 * request to Google, neither before nor after accept. Google endpoints are
 * intercepted (fulfilled locally), so no real traffic leaves the test.
 *
 * Needs the W0 test endpoint POST /wp-json/trackwp-test/v1/options.
 */
import { test, expect } from '@playwright/test';
import { setOptions, resetTrackWP } from './helpers/test-endpoints.mjs';

const GOOGLE = /google-analytics\.com|googletagmanager\.com|doubleclick\.net|google\.com\/(ccm|pagead)|googleadservices\.com/;
const EMAIL_PATTERNS = [/jens(\.|%2E)?hansen(@|%40|%2540)example/i, /pii(@|%40|%2540)example/i, /ref(@|%40|%2540)example/i];

test('no e-mail reaches Google before or after accept', async ({ page }) => {
    await resetTrackWP();
    await setOptions({
        trackwp_platforms: { ga4_enabled: true, ga4_gtag_enabled: true, ga4_measurement_id: 'G-TEST123456' },
        trackwp_consent: { enabled: true, consent_version: 1 },
    });

    const seen = [];
    await page.route(GOOGLE, async (route) => {
        const r = route.request();
        seen.push(`${r.url()}\n${r.postData() || ''}`);
        if (/gtag\/js/.test(r.url())) {
            await route.fulfill({ status: 200, contentType: 'text/javascript', body: 'window.dataLayer=window.dataLayer||[];' });
        } else {
            await route.fulfill({ status: 204, body: '' });
        }
    });

    // Referrer with an e-mail, then a search page whose title contains the
    // term, with an e-mail in the path and in an arbitrary query parameter.
    await page.goto('/?note=ref%40example.com');
    const target = '/pii%40example.com/?s=jens.hansen%40example.com&foo=pii%40example.com#mail=pii@example.com';
    const nav = page.waitForURL(/\/pii%40example\.com\/\?s=/, { waitUntil: 'load' });
    await page.evaluate((href) => { window.location.href = href; }, target);
    await nav;

    const cfg = await page.evaluate(() => {
        var dl = window.dataLayer || [];
        for (var i = 0; i < dl.length; i++) {
            if (dl[i] && dl[i][0] === 'config' && /^G-/.test(dl[i][1])) return dl[i][2] || {};
        }
        return null;
    });
    expect(cfg, 'gtag config for GA4').not.toBeNull();
    for (const key of ['page_location', 'page_referrer', 'page_title']) {
        for (const re of EMAIL_PATTERNS) expect(String(cfg[key] || ''), key).not.toMatch(re);
    }

    await page.waitForTimeout(1000);
    const before = seen.length;
    await page.locator('[data-action="accept-all"]').first().click();
    await page.waitForTimeout(1500);

    for (const s of seen) {
        for (const re of EMAIL_PATTERNS) expect(s).not.toMatch(re);
    }
    console.log(`# google requests intercepted: ${before} before accept, ${seen.length - before} after`);
});
