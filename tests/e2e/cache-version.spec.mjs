/**
 * W5 (PLAN-1.10.1-v4 §4.2, K3, R4): v1 page cached, consent_version bumped
 * to 2, cached HTML served; the first /event answers stale_version, the
 * banner is shown, fbq('consent','revoke') runs and no server-side event
 * reaches a platform before a new choice.
 *
 * Needs the W0 test endpoint POST /wp-json/trackwp-test/v1/options
 * ({option_name: value}); the spec fails when it is absent.
 */
import dns from 'node:dns';
import { test, expect } from '@playwright/test';
import { clearMockLog, getMockLog } from './helpers/mockplatforms-client.mjs';
import { cacheBase } from './helpers/cache.mjs';
import { setOptions, resetTrackWP } from './helpers/test-endpoints.mjs';
import { stubExternal } from './helpers/stub-external.mjs';

const EVENTS = [{
    name: 'lead', enabled: true, value: 0, currency: 'DKK', meta_event: 'Lead',
    send_to: { ga4: true, meta: true, google_ads: false },
    firing_triggers: [{ type: 'time_on_page', time_seconds: 1, conditions: [] }],
}];

// A real CDN serves the site's own host. Map "wp" to the cache IP in the
// browser only, so cached HTML (restUrl http://wp/...) is same-origin and the
// Origin guard sees the home host. Node fetch (setOptions) still goes to wp.
const { address: CACHE_IP } = await dns.promises.lookup(new URL(cacheBase()).hostname);
test.use({ launchOptions: { args: [`--host-resolver-rules=MAP wp ${CACHE_IP}`] } });

async function spyPixel(page) {
    await page.addInitScript(() => {
        window.__fbq = [];
        window.fbq = function () { window.__fbq.push(Array.prototype.slice.call(arguments)); };
    });
}

test('cached v1 page after bump to v2 gives stale_version and no platform events', async ({ page, baseURL }) => {
    await resetTrackWP();
    const first = await setOptions({
        trackwp_platforms: { ga4_enabled: true, ga4_gtag_enabled: true, ga4_measurement_id: 'G-TEST123456' },
        trackwp_events: EVENTS,
        trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
    });
    expect(first.updated.trackwp_consent.consent_version, 'precondition: no auto-bump on setup').toBe(1);

    await stubExternal(page.context());
    const cached = new URL(`/?twp-cache-version=${Date.now()}`, baseURL).toString();
    await spyPixel(page);
    await page.goto(cached);
    const hit = await page.goto(cached); // second request is served from the cache
    expect(hit.headers()['x-trackwp-cache'], 'page is served from the nginx cache').toBe('HIT');
    await page.locator('[data-action="accept-all"]').first().click();
    await expect.poll(async () => (await page.context().cookies()).some((c) => c.name === 'trackwp_consent')).toBe(true);

    await setOptions({ trackwp_consent: { enabled: true, consent_version: 2, require_active_consent: true } });
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
