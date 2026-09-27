/**
 * W5 (PLAN-1.10.1-v4 §4.2 + R5/R7 extension): accept, submit a form with an
 * e-mail (hashing), withdraw at once while keepalive runs. No enhanced after
 * withdraw, the cookie ends as a rejection, and no keepalive Set-Cookie holds
 * trackwp_consent. Also: delayed accept POST, then withdraw, then the old
 * answer: the cookie still ends as a rejection (with timeout and two tabs).
 *
 * Needs the W0 test endpoint POST /wp-json/trackwp-test/v1/options.
 */
import { test, expect } from '@playwright/test';

const EVENTS = [{
    name: 'lead', enabled: true, value: 0, currency: 'DKK', meta_event: 'Lead',
    send_to: { ga4: true, meta: true, google_ads: true },
    triggers: [{ type: 'form_submit', css_selector: '', conditions: [] }],
}];

async function setOptions(request, baseURL, options) {
    const res = await request.post(new URL('/wp-json/trackwp-test/v1/options', baseURL).toString(), { data: options });
    return res.ok();
}

async function consentOf(context) {
    const c = (await context.cookies()).find((x) => x.name === 'trackwp_consent');
    return c ? JSON.parse(decodeURIComponent(c.value)) : null;
}

test.beforeEach(async ({ request, baseURL }) => {
    const ok = await setOptions(request, baseURL, {
        trackwp_consent: { enabled: true, consent_version: 1, require_active_consent: true },
        trackwp_advanced: { customer_data_sharing: true, first_party_cookie_enabled: true },
        trackwp_events: EVENTS,
    });
    test.skip(!ok, 'W0 test endpoint /wp-json/trackwp-test/v1/options is missing');
});

test('withdraw during hashing and keepalive', async ({ page }) => {
    const events = [];
    const keepaliveSetCookies = [];
    page.on('request', (r) => {
        if (r.url().includes('/trackwp/v1/event')) events.push({ t: Date.now(), body: r.postDataJSON() });
    });
    page.on('response', async (r) => {
        if (r.url().includes('/trackwp/v1/keepalive')) keepaliveSetCookies.push((await r.allHeaders())['set-cookie'] || '');
    });
    await page.goto('/');
    await page.locator('[data-action="accept-all"]').first().click();

    const withdrawnAt = await page.evaluate(() => {
        var form = document.createElement('form');
        form.innerHTML = '<input type="email" value="Jens.Hansen@example.com">';
        document.body.appendChild(form);
        window.trackwp.sendFormEvent({ form_id: 'race' }, form); // async hashing starts
        var t = Date.now();
        window.trackwpConsent.withdraw();
        return t;
    });
    await page.waitForTimeout(1500);

    const after = events.filter((e) => e.t >= withdrawnAt);
    expect(after.length).toBeGreaterThan(0);
    for (const e of after) {
        expect(e.body.enhanced).toBeUndefined();
        expect(e.body.consent.marketing).toBe(false);
    }
    const c = await consentOf(page.context());
    expect([c.statistics, c.marketing, c.personalisation]).toEqual([false, false, false]);
    for (const sc of keepaliveSetCookies) expect(sc).not.toContain('trackwp_consent');
});

for (const mode of ['delayed', 'timeout']) {
    test(`old accept answer (${mode}) cannot override a later withdraw`, async ({ page }) => {
        let first = true;
        await page.route('**/trackwp/v1/consent-log', async (route) => {
            if (first) {
                first = false;
                await new Promise((r) => setTimeout(r, mode === 'timeout' ? 6500 : 1500));
            }
            await route.continue();
        });
        await page.goto('/');
        await page.locator('[data-action="accept-all"]').first().click();
        await page.evaluate(() => window.trackwpConsent.withdraw());
        await page.waitForTimeout(mode === 'timeout' ? 9000 : 4000);
        const c = await consentOf(page.context());
        expect([c.statistics, c.marketing]).toEqual([false, false]);
    });
}

test('two tabs: the later withdraw wins', async ({ context }) => {
    const a = await context.newPage();
    const b = await context.newPage();
    await a.goto('/');
    await a.locator('[data-action="accept-all"]').first().click();
    await b.goto('/');
    await a.evaluate(() => window.trackwpConsent.setChoices({ statistics: true, marketing: true }));
    await b.evaluate(() => window.trackwpConsent.withdraw());
    await a.waitForTimeout(3000);
    const c = await consentOf(context);
    expect([c.statistics, c.marketing]).toEqual([false, false]);
});
