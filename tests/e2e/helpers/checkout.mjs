/**
 * WooCommerce checkout helpers for Playwright specs (owned by W4:
 * purchase-authority.spec.mjs, purchase-gateway.spec.mjs,
 * shared-cache.spec.mjs, orders-storage.spec.mjs, ajax-add-to-cart.spec.mjs).
 *
 * This is a SKELETON against the product seeded by
 * tests/docker/wp/entrypoint.sh (slug "trackwp-test-product") and against
 * the classic (non-blocks) checkout form field ids. Per NEXT-BUILD.md,
 * WooCommerce 11 on a block theme renders the checkout with the
 * Interactivity API and different markup/field ids in the blocks path; W4
 * owns this file and should extend/branch it once it decides which
 * checkout page (classic shortcode vs. blocks) the e2e shop uses.
 */

export const TEST_PRODUCT_SLUG = 'trackwp-test-product';

export async function addProductToCart(page, baseURL, slug = TEST_PRODUCT_SLUG) {
    await page.goto(new URL(`/product/${slug}/`, baseURL).toString());
    const posted = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/product/'));
    await page.click('button.single_add_to_cart_button, button[name="add-to-cart"]');
    await posted;
}

export async function goToCheckout(page, baseURL) {
    await page.goto(new URL('/checkout/', baseURL).toString());
}

export async function fillCheckoutForm(page, overrides = {}) {
    const values = {
        billing_first_name: 'Test',
        billing_last_name: 'Kunde',
        billing_address_1: 'Testvej 1',
        billing_city: 'Testby',
        billing_postcode: '1000',
        billing_phone: '12345678',
        billing_email: 'test@example.org',
        ...overrides,
    };

    if (await page.locator('.wp-block-woocommerce-checkout').count()) {
        // Block checkout: fields re-render on a country change, so pick it first.
        await page.locator('#email').waitFor({ state: 'visible' });
        const country = page.locator('select#billing-country');
        if (await country.count()) await country.selectOption('DK');
        const blockIds = {
            email: values.billing_email,
            'billing-first_name': values.billing_first_name,
            'billing-last_name': values.billing_last_name,
            'billing-address_1': values.billing_address_1,
            'billing-city': values.billing_city,
            'billing-postcode': values.billing_postcode,
            'billing-phone': values.billing_phone,
        };
        for (const [id, value] of Object.entries(blockIds)) {
            await page.locator(`#${id}`).fill(value);
        }
        return;
    }

    for (const [name, value] of Object.entries(values)) {
        const field = page.locator(`#${name}`);
        if (await field.count()) {
            await field.fill(value);
        }
    }
}

export async function placeOrder(page) {
    if (await page.locator('#place_order').count()) {
        await page.click('#place_order');
    } else {
        await page.click('.wc-block-components-checkout-place-order-button');
    }
    await page.waitForURL(/order-received/, { timeout: 20000 });
}

export async function getOrderIdFromReceivedPage(page) {
    const url = new URL(page.url());
    const match = /\/order-received\/(\d+)/.exec(url.pathname) || /order-received=(\d+)/.exec(url.search);
    return match ? Number(match[1]) : null;
}
