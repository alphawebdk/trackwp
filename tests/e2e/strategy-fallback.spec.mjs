/**
 * strategy-fallback (W8): runs against the wp62 service (WordPress 6.2);
 * skipped on WordPress 6.3 and newer:
 *   docker compose -f tests/docker/compose.yml run --rm e2e-wp62 \
 *     npx playwright test tests/e2e/strategy-fallback.spec.mjs
 *
 * On WP < 6.3 wp_enqueue_script()'s 5th argument is the $in_footer bool, so
 * TrackWP passes the bool instead of the {strategy:'defer'} $args array
 * (BESLUTNINGER §6). An array there would be truthy and silently move
 * consent.js to the footer. This spec proves consent.js is in <head>, that
 * the banner works, and that an event is sent after accepting.
 *
 * The site is the stock wp62 install from tests/docker/wp/entrypoint.sh with
 * TrackWP activated, but every platform is off by default there, so the banner
 * would be info-only. After the WP < 6.3 check the spec resets TrackWP and
 * enables GA4 and Meta plus an active-consent banner (consent option written
 * last, see auto-bump), then reloads.
 */
import { test, expect } from '@playwright/test';
import { setOptions, resetTrackWP } from './helpers/test-endpoints.mjs';
import { stubExternal } from './helpers/stub-external.mjs';

test.describe('strategy fallback on WP 6.2', () => {
    test('consent.js is in <head>, works, and an event is sent after accept', async ({ page, context }) => {
        await context.clearCookies();
        await page.goto('/');

        // Only meaningful on WP < 6.3; skipped on newer services (e2e runs WP 7.x).
        const generator = await page.locator('meta[name="generator"][content^="WordPress "]').getAttribute('content');
        test.skip(!/^WordPress 6\.[0-2](\.|$)/.test(generator || ''), `needs WordPress < 6.3 (got "${generator}"); run via the e2e-wp62 service`);

        // Platforms and an active-consent banner, set only now so a skipped run
        // (WP >= 6.3) leaves that service's options alone.
        await resetTrackWP();
        await setOptions({
            trackwp_platforms: { ga4_enabled: true, ga4_gtag_enabled: true, ga4_measurement_id: 'G-TEST123456', meta_enabled: true, meta_pixel_id: '123456789012345', meta_pixel_client_enabled: true },
            trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
        });
        await stubExternal(context);
        await context.clearCookies();
        await page.goto('/');

        // consent.js is a classic <script> in <head> (no strategy on 6.2).
        const headConsent = page.locator('head script[src*="assets/js/consent"]');
        await expect(headConsent).toHaveCount(1);
        await expect(page.locator('body script[src*="assets/js/consent"]')).toHaveCount(0);

        // The one inline reader from wp_head@1 exists and reports "no choice".
        expect(await page.evaluate(() => !!window.trackwpConsentReader)).toBe(true);
        expect(await page.evaluate(() => window.trackwpConsentReader.read())).toBeNull();

        // consent.js ran: the banner is shown and accepting stores the choice.
        const accept = page.locator('[data-action="accept-all"]').first();
        await expect(accept).toBeVisible();
        await accept.click();

        const choice = await page.evaluate(() => window.trackwpConsentReader.read());
        expect(choice).not.toBeNull();
        expect(choice.statistics).toBe(true);
        expect(choice.marketing).toBe(true);
        const cookie = (await context.cookies()).find((c) => c.name === 'trackwp_consent');
        expect(cookie, 'trackwp_consent cookie after accept').toBeTruthy();

        // trackwp.js (footer) is loaded and sends an event with consent.
        await page.waitForFunction(() => window.trackwp && typeof window.trackwp.sendEvent === 'function');
        const eventRequest = page.waitForRequest((req) => {
            if (req.method() !== 'POST') {
                return false;
            }
            return decodeURIComponent(req.url()).includes('trackwp/v1/event');
        });
        await page.evaluate(() => window.trackwp.sendEvent('phone_click', {}));
        const req = await eventRequest;
        const body = req.postDataJSON();
        expect(body.event).toBe('phone_click');
        expect(body.consent).toBeTruthy();
        expect(body.consent.analytics).toBe(true);
        expect(body.consent.marketing).toBe(true);
        expect(Number.isInteger(body.consent.v)).toBe(true);

        const res = await req.response();
        expect(res, 'response to /event').toBeTruthy();
        expect(res.status()).toBeLessThan(500);
    });
});
