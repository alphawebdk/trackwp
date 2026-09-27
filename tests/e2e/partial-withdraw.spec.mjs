/**
 * W5 (PLAN-1.10.1-v4 §8, R6): both granted, then marketing off: statistics
 * stays, _ga lives, _fbp is expired, Pixel revoke runs and gtag gets ad_*
 * denied with analytics granted. The reverse (statistics off) is tested too.
 *
 * Needs the W0 test endpoint POST /wp-json/trackwp-test/v1/options.
 */
import { test, expect } from '@playwright/test';

async function setOptions(request, baseURL, options) {
    const res = await request.post(new URL('/wp-json/trackwp-test/v1/options', baseURL).toString(), { data: options });
    return res.ok();
}

async function setup(page, context, request, baseURL) {
    const ok = await setOptions(request, baseURL, {
        trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
    });
    test.skip(!ok, 'W0 test endpoint /wp-json/trackwp-test/v1/options is missing');
    await page.addInitScript(() => {
        window.__fbq = [];
        window.fbq = function () { window.__fbq.push(Array.prototype.slice.call(arguments)); };
    });
    await page.goto('/');
    const host = new URL(page.url()).hostname;
    await context.addCookies([
        { name: '_ga', value: 'GA1.1.123456789.1700000000', domain: host, path: '/' },
        { name: '_fbp', value: 'fb.1.1700000000000.123456789', domain: host, path: '/' },
    ]);
    await page.evaluate(() => window.trackwpConsent.setChoices({ statistics: true, marketing: true }));
    await page.waitForTimeout(500);
}

function lastConsentUpdate(page) {
    return page.evaluate(() => {
        var dl = window.dataLayer || [];
        for (var i = dl.length - 1; i >= 0; i--) {
            var a = dl[i];
            if (a && a[0] === 'consent' && a[1] === 'update') return a[2];
        }
        return null;
    });
}

test('marketing off keeps statistics', async ({ page, context, request, baseURL }) => {
    await setup(page, context, request, baseURL);
    const log = page.waitForRequest((r) => r.url().includes('/trackwp/v1/consent-log') && r.postDataJSON().marketing === false);
    await page.evaluate(() => window.trackwpConsent.setChoices({ statistics: true, marketing: false }));
    expect((await log).postDataJSON().event_type).toBe('update');
    await page.waitForTimeout(1000);

    const names = (await context.cookies()).map((c) => c.name);
    expect(names).toContain('_ga');
    expect(names).not.toContain('_fbp');
    expect(await page.evaluate(() => window.__fbq.some((a) => a[0] === 'consent' && a[1] === 'revoke'))).toBe(true);
    const upd = await lastConsentUpdate(page);
    expect(upd.analytics_storage).toBe('granted');
    expect(upd.ad_storage).toBe('denied');
    expect(upd.ad_user_data).toBe('denied');
    expect(upd.ad_personalization).toBe('denied');
});

test('statistics off keeps marketing', async ({ page, context, request, baseURL }) => {
    await setup(page, context, request, baseURL);
    const log = page.waitForRequest((r) => r.url().includes('/trackwp/v1/consent-log') && r.postDataJSON().statistics === false);
    await page.evaluate(() => window.trackwpConsent.setChoices({ statistics: false, marketing: true }));
    expect((await log).postDataJSON().event_type).toBe('update');
    await page.waitForTimeout(1000);

    const names = (await context.cookies()).map((c) => c.name);
    expect(names).not.toContain('_ga');
    expect(names).toContain('_fbp');
    expect(await page.evaluate(() => window.__fbq.some((a) => a[0] === 'consent' && a[1] === 'revoke'))).toBe(false);
    expect(await page.evaluate(() => sessionStorage.getItem('trackwp_sid'))).toBeNull();
    const upd = await lastConsentUpdate(page);
    expect(upd.analytics_storage).toBe('denied');
    expect(upd.ad_storage).toBe('granted');
});
