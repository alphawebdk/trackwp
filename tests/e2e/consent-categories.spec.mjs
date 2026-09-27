/**
 * W5 (PLAN-1.10.1-v4 §4.2, K2, K7): statistics only, marketing only and
 * both, each followed by a checkout. Every /event body only carries the
 * fields K2 allows for the given consent, and only the right cookies exist.
 *
 * Needs the W0 test endpoint POST /wp-json/trackwp-test/v1/options.
 */
import { test, expect } from '@playwright/test';
import { addProductToCart, goToCheckout, fillCheckoutForm, placeOrder } from './helpers/checkout.mjs';

const ANALYTICS_FIELDS = ['client_id', 'session_id', '_ga', 'ga_session_cookies', 'engaged_ms', 'page_referrer'];
const MARKETING_FIELDS = ['fbp', 'fbc', 'gclid', 'gbraid', 'wbraid', 'enhanced'];

async function setOptions(request, baseURL, options) {
    const res = await request.post(new URL('/wp-json/trackwp-test/v1/options', baseURL).toString(), { data: options });
    return res.ok();
}

const CASES = [
    { name: 'statistics only', choice: { statistics: true, marketing: false } },
    { name: 'marketing only', choice: { statistics: false, marketing: true } },
    { name: 'both', choice: { statistics: true, marketing: true } },
];

for (const c of CASES) {
    test(`${c.name}: fields and cookies follow K2/K7`, async ({ page, request, baseURL }) => {
        const ok = await setOptions(request, baseURL, {
            trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
            trackwp_advanced: { customer_data_sharing: true, first_party_cookie_enabled: true },
        });
        test.skip(!ok, 'W0 test endpoint /wp-json/trackwp-test/v1/options is missing');

        const bodies = [];
        page.on('request', (r) => {
            if (r.url().includes('/trackwp/v1/event')) bodies.push(r.postDataJSON());
        });
        await page.goto('/?gclid=TEST_GCLID_1&fbclid=TEST_FBCLID_1');
        await page.evaluate((choice) => window.trackwpConsent.setChoices(choice), c.choice);
        await addProductToCart(page, baseURL);
        await goToCheckout(page, baseURL);
        await fillCheckoutForm(page);
        await placeOrder(page);
        await page.waitForTimeout(1500);

        expect(bodies.length).toBeGreaterThan(0);
        for (const b of bodies) {
            expect(b.consent.analytics).toBe(c.choice.statistics);
            expect(b.consent.marketing).toBe(c.choice.marketing);
            for (const f of ANALYTICS_FIELDS) {
                if (!c.choice.statistics) expect(b[f], `${f} without analytics`).toBeUndefined();
            }
            for (const f of MARKETING_FIELDS) {
                if (!c.choice.marketing) expect(b[f], `${f} without marketing`).toBeUndefined();
            }
            if (!c.choice.marketing) expect(String(b.page_url)).not.toMatch(/gclid|fbclid/);
            expect(String(b.page_url)).not.toMatch(/key=wc_order_/);
        }

        const names = (await page.context().cookies()).map((x) => x.name);
        expect(names.includes('_twp_click')).toBe(c.choice.marketing);
        expect(names.includes('_fbc')).toBe(c.choice.marketing);
        expect(names.includes('_twp_cid') || names.includes('_ga')).toBe(c.choice.statistics);
        const sid = await page.evaluate(() => sessionStorage.getItem('trackwp_sid'));
        expect(sid !== null).toBe(c.choice.statistics);
    });
}
