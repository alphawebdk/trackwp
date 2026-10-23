/**
 * External scripts and beacons are fulfilled locally; no traffic leaves the test.
 * target: a Page or BrowserContext.
 */
export async function stubExternal(target) {
    await target.route(/connect\.facebook\.net|facebook\.com\/tr|google-analytics\.com|googletagmanager\.com|doubleclick\.net|google\.com\/(ccm|pagead)|googleadservices\.com/, async (route) => {
        if (/\.js(\?|$)|gtag\/js/.test(route.request().url())) {
            await route.fulfill({ status: 200, contentType: 'text/javascript', body: 'window.dataLayer=window.dataLayer||[];' });
        } else {
            await route.fulfill({ status: 204, body: '' });
        }
    });
}
