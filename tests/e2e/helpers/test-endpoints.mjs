/**
 * HTTP client for tests/docker/wp/mu-plugins/trackwp-test-endpoints.php,
 * the test-only REST namespace (`trackwp-test/v1`) Playwright specs use to
 * set up/tear down WordPress state without a wp-cli shell-out.
 */

function baseURL() {
    return process.env.TRACKWP_E2E_BASE_URL || 'http://localhost:8080';
}

function token() {
    return process.env.TRACKWP_TEST_TOKEN || '';
}

async function post(path, body) {
    const res = await fetch(new URL(path, baseURL()), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-TrackWP-Test-Token': token(),
        },
        body: JSON.stringify(body || {}),
    });
    if (!res.ok) {
        const text = await res.text();
        throw new Error(`${path} returned ${res.status}: ${text}`);
    }
    return res.json();
}

/**
 * @param {Record<string, unknown>} options Option name -> value.
 * @param {{merge?: boolean}} [opts]
 */
export async function setOptions(options, { merge = false } = {}) {
    return post('/wp-json/trackwp-test/v1/options', { ...options, _merge: merge });
}

/**
 * @param {{status?: string, line_items?: Array<object>, billing_email?: string}} [order]
 * @returns {Promise<{order_id: number, order_key: string, status: string, order_received_url: string}>}
 */
export async function createOrder(order = {}) {
    return post('/wp-json/trackwp-test/v1/orders', { status: 'pending', ...order });
}

/**
 * @param {number} orderId
 * @param {string} status
 */
export async function setOrderStatus(orderId, status) {
    return post(`/wp-json/trackwp-test/v1/orders/${orderId}/status`, { status });
}

/** Resets TrackWP options to their defaults and empties claims/consent-log tables. */
export async function resetTrackWP() {
    return post('/wp-json/trackwp-test/v1/reset', {});
}
