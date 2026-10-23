/**
 * Server-side backup purchase without a payment gateway: the cheque gateway
 * leaves the order on-hold, the test sets it to processing, and the
 * trackwp_backup_purchase job (run through the test endpoint) must send the
 * purchase exactly once per platform, and only when the customer never
 * reached the order-received page and consented. The mockplatforms log is
 * the source of truth for what GA4 and Meta received.
 *
 * Needs the test endpoints POST /wp-json/trackwp-test/v1/{options,reset,
 * orders/<id>/status,backup/run} (tests/docker/wp/mu-plugins/trackwp-test-endpoints.php).
 */
import { test, expect } from '@playwright/test';
import { addProductToCart, goToCheckout, fillCheckoutForm, placeOrder } from './helpers/checkout.mjs';
import { setOptions, resetTrackWP, setOrderStatus, runBackupPurchase } from './helpers/test-endpoints.mjs';
import { clearMockLog, getMockLog } from './helpers/mockplatforms-client.mjs';
import { stubExternal } from './helpers/stub-external.mjs';

const b64 = (s) => Buffer.from(s, 'utf8').toString('base64');

async function configure() {
    await resetTrackWP();
    await setOptions({
        trackwp_platforms: {
            ga4_enabled: true,
            ga4_gtag_enabled: true,
            ga4_measurement_id: 'G-TEST123456',
            ga4_api_secret: b64('e2e-secret'),
            meta_enabled: true,
            meta_pixel_id: '123456789012345',
            meta_pixel_client_enabled: true,
            meta_access_token: b64('EAAe2e-token'),
        },
        trackwp_woocommerce: { enabled: true },
        trackwp_advanced: { customer_data_sharing: true, first_party_cookie_enabled: true, batching_enabled: false },
        woocommerce_cheque_settings: { enabled: 'yes' },
        woocommerce_default_country: 'DK',
        trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
    });
    await clearMockLog();
}

/** Places a cheque order. With blockReceived the browser never reaches order-received. */
async function checkout(page, baseURL, { choice, blockReceived }) {
    let blockedUrl = null;
    if (blockReceived) {
        await page.route('**/order-received/**', (route) => {
            blockedUrl = route.request().url();
            return route.abort();
        });
        await page.route('**/*order-received=*', (route) => {
            blockedUrl = route.request().url();
            return route.abort();
        });
    }
    await page.goto('/');
    await page.evaluate((c) => window.trackwpConsent.setChoices(c), choice);
    await addProductToCart(page, baseURL);
    await goToCheckout(page, baseURL);
    await fillCheckoutForm(page);
    if (blockReceived) {
        if (await page.locator('#place_order').count()) {
            await page.click('#place_order');
        } else {
            await page.click('.wc-block-components-checkout-place-order-button');
        }
        await expect.poll(() => blockedUrl, { timeout: 20000, message: 'order-received navigation was attempted and blocked' }).not.toBeNull();
        const m = /\/order-received\/(\d+)/.exec(blockedUrl) || /order-received=(\d+)/.exec(blockedUrl);
        expect(m, `order id in ${blockedUrl}`).not.toBeNull();
        return Number(m[1]);
    }
    await placeOrder(page);
    const m = /\/order-received\/(\d+)/.exec(page.url()) || /order-received=(\d+)/.exec(page.url());
    expect(m, `order id in ${page.url()}`).not.toBeNull();
    return Number(m[1]);
}

function ga4Purchases(entries) {
    return entries.flatMap((e) => ((e.body && e.body.events) || []).filter((ev) => ev.name === 'purchase'));
}

function metaPurchases(entries) {
    return entries.flatMap((e) => ((e.body && e.body.data) || []).filter((ev) => ev.event_name === 'Purchase'));
}

test.describe('server-side backup purchase', () => {
    test.setTimeout(60000);
    test.beforeEach(async ({ page }) => {
        await stubExternal(page.context());
        await configure();
    });

    test('customer never reaches the receipt page: backup sends one purchase per platform', async ({ page, baseURL }) => {
        const orderId = await checkout(page, baseURL, { choice: { statistics: true, marketing: true }, blockReceived: true });
        await page.waitForTimeout(1500);
        expect(ga4Purchases(await getMockLog('ga4')), 'browser sent no GA4 purchase').toHaveLength(0);
        expect(metaPurchases(await getMockLog('meta')), 'browser sent no Meta purchase').toHaveLength(0);

        const status = await setOrderStatus(orderId, 'processing');
        expect(status.status).toBe('processing');

        const run = await runBackupPurchase(orderId);
        expect(run.was_scheduled, 'backup job queued when the order became paid').toBe(true);

        const ga4 = ga4Purchases(await getMockLog('ga4'));
        const meta = metaPurchases(await getMockLog('meta'));
        expect(ga4).toHaveLength(1);
        expect(meta).toHaveLength(1);
        expect(String(ga4[0].params.transaction_id)).toBe(String(orderId));
        expect(meta[0].event_id).toMatch(/^evt_[0-9a-f]{32}$/);
        expect(meta[0].action_source).toBe('website');
    });

    test('customer reaches the receipt page: backup adds no second purchase', async ({ page, baseURL }) => {
        const orderId = await checkout(page, baseURL, { choice: { statistics: true, marketing: true }, blockReceived: false });
        await page.waitForTimeout(2500);
        const ga4Before = ga4Purchases(await getMockLog('ga4'));
        const metaBefore = metaPurchases(await getMockLog('meta'));
        expect(ga4Before, 'receipt page sent one GA4 purchase').toHaveLength(1);
        expect(metaBefore, 'receipt page sent one Meta purchase').toHaveLength(1);
        expect(String(ga4Before[0].params.transaction_id)).toBe(String(orderId));

        await setOrderStatus(orderId, 'processing');
        await runBackupPurchase(orderId);

        const ga4 = ga4Purchases(await getMockLog('ga4'));
        const meta = metaPurchases(await getMockLog('meta'));
        expect(ga4).toHaveLength(1);
        expect(meta).toHaveLength(1);
        expect(meta[0].event_id).toBe(metaBefore[0].event_id);
        expect(String(ga4[0].params.transaction_id)).toBe(String(orderId));
    });

    test('no consent: backup sends nothing', async ({ page, baseURL }) => {
        const orderId = await checkout(page, baseURL, { choice: { statistics: false, marketing: false }, blockReceived: true });
        await page.waitForTimeout(1500);

        await setOrderStatus(orderId, 'processing');
        await runBackupPurchase(orderId);

        expect(await getMockLog('ga4')).toHaveLength(0);
        expect(await getMockLog('meta')).toHaveLength(0);
    });
});
