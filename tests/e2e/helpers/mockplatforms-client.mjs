/**
 * HTTP client for the mockplatforms Docker service
 * (tests/docker/mockplatforms/server.mjs), used from Playwright specs to
 * assert on what TrackWP actually sent to GA4/Meta/Google Ads, and to
 * inject timeout/5xx/429 failures.
 */

function mockBaseURL() {
    return process.env.TRACKWP_MOCKPLATFORMS_URL || 'http://localhost:8090';
}

export async function getMockLog(destination) {
    const url = new URL('/__log', mockBaseURL());
    if (destination) {
        url.searchParams.set('destination', destination);
    }
    const res = await fetch(url);
    if (!res.ok) {
        throw new Error(`mockplatforms GET /__log returned ${res.status}`);
    }
    return res.json();
}

export async function clearMockLog() {
    const res = await fetch(new URL('/__log', mockBaseURL()), { method: 'DELETE' });
    if (!res.ok) {
        throw new Error(`mockplatforms DELETE /__log returned ${res.status}`);
    }
    return res.json();
}

/**
 * @param {'ga4'|'meta'|'google_ads'} destination
 * @param {{mode: 'ok'|'timeout'|'5xx'|'429', statusCode?: number, delayMs?: number, retryAfter?: number}} config
 */
export async function configureMockPlatform(destination, config) {
    const res = await fetch(new URL('/__config', mockBaseURL()), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ destination, ...config }),
    });
    if (!res.ok) {
        throw new Error(`mockplatforms PUT /__config returned ${res.status}`);
    }
    return res.json();
}

export async function resetMockConfig() {
    const res = await fetch(new URL('/__config', mockBaseURL()), { method: 'DELETE' });
    if (!res.ok) {
        throw new Error(`mockplatforms DELETE /__config returned ${res.status}`);
    }
    return res.json();
}

export async function waitForMockLogEntry(destination, predicate, { timeoutMs = 5000, intervalMs = 100 } = {}) {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        const entries = await getMockLog(destination);
        const match = entries.find(predicate);
        if (match) {
            return match;
        }
        if (Date.now() > deadline) {
            throw new Error(`No matching mockplatforms log entry for "${destination}" within ${timeoutMs}ms`);
        }
        await new Promise((r) => setTimeout(r, intervalMs));
    }
}
