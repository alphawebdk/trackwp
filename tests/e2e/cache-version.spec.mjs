/**
 * W5 (PLAN-1.10.1-v4 §4.2, K3, R4): v1 page cached, consent_version bumped
 * to 2, cached HTML served; the first /event answers stale_version, the
 * banner is shown, fbq('consent','revoke') runs and no server-side event
 * reaches a platform before a new choice.
 *
 * Needs the W0 test endpoint POST /wp-json/trackwp-test/v1/options
 * ({option_name: value}); the spec skips with a message when it is absent.
 */
import { test, expect } from '@playwright/test';
import { clearMockLog, getMockLog } from './helpers/mockplatforms-client.mjs';
import { cacheBase } from './helpers/cache.mjs';

const EVENTS = [{
    name: 'lead', enabled: true, value: 0, currency: 'DKK', meta_event: 'Lead',
    send_to: { ga4: true, meta: true, google_ads: false },
    triggers: [{ type: 'time_on_page', time_seconds: 1, conditions: [] }],
}];

async function setOptions(request, baseURL, options) {
    const res = await request.post(new URL('/wp-json/trackwp-test/v1/options', baseURL).toString(), { data: options });
    return res.ok();
}

async function spyPixel(page) {
    await page.addInitScript(() => {
        window.__fbq = [];
        window.fbq = function () { window.__fbq.push(Array.prototype.slice.call(arguments)); };
    });
}

test('cached v1 page after bump to v2 gives stale_version and no platform events', async ({ page, request, baseURL }) => {
    const ok = await setOptions(request, baseURL, {
        trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
        trackwp_events: EVENTS,
    });
    test.skip(!ok, 'W0 test endpoint /wp-json/trackwp-test/v1/options is missing');

    const cached = new URL('/?twp-cache-version=1', cacheBase()).toString();
    await spyPixel(page);
    await page.goto(cached);
    await page.goto(cached); // second request is served from the cache
    await page.locator('[data-action="accept-all"]').first().click();
    await expect.poll(async () => (await page.context().cookies()).some((c) => c.name === 'trackwp_consent')).toBe(true);

    expect(await setOptions(request, baseURL, { trackwp_consent: { enabled: true, consent_version: 2, require_active_consent: true } })).toBe(true);
    await clearMockLog();

    const stale = page.waitForResponse(async (r) => r.url().includes('/trackwp/v1/event') && (await r.json().catch(() => ({}))).consent === 'stale_version');
    await page.goto(cached); // cached HTML still carries version 1
    const res = await stale;
    expect((await res.json()).current_version).toBe(2);

    await expect(page.locator('#trackwp-consent-banner')).toBeVisible();
    expect(await page.evaluate(() => window.__fbq.some((a) => a[0] === 'consent' && a[1] === 'revoke'))).toBe(true);
    const cookie = (await page.context().cookies()).find((c) => c.name === 'trackwp_consent');
    expect(JSON.parse(decodeURIComponent(cookie.value)).stale).toBe(true);

    await page.waitForTimeout(2000);
    expect((await getMockLog('ga4')).length).toBe(0);
    expect((await getMockLog('meta')).length).toBe(0);

    // A new choice is written with current_version and releases the queue.
    await page.locator('[data-action="accept-all"]').first().click();
    const after = (await page.context().cookies()).find((c) => c.name === 'trackwp_consent');
    expect(JSON.parse(decodeURIComponent(after.value)).v).toBe(2);
});
