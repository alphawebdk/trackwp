/**
 * Helpers for the nginx proxy_cache service (tests/docker/cache/nginx.conf),
 * used by cache-version.spec.mjs and shared-cache.spec.mjs (both owned by
 * W4/W5).
 */

function cacheBaseURL() {
    return process.env.TRACKWP_CACHE_BASE_URL || 'http://localhost:8081';
}

/**
 * Fetches a path through the cache and reports the X-TrackWP-Cache status
 * nginx.conf adds (HIT, MISS, BYPASS, EXPIRED, ...).
 */
export async function fetchThroughCache(path, init = {}) {
    const res = await fetch(new URL(path, cacheBaseURL()), init);
    return { res, cacheStatus: res.headers.get('x-trackwp-cache') };
}

export async function isCacheHit(path) {
    const { cacheStatus } = await fetchThroughCache(path);
    return cacheStatus === 'HIT';
}

/**
 * There is no purge endpoint configured (nginx's built-in proxy_cache has
 * none without the third-party ngx_cache_purge module, deliberately not
 * added to keep the cache image at plain `nginx:alpine`). Specs that need
 * to force a fresh fetch after e.g. a consent-version bump should either:
 *   - wait past `proxy_cache_valid` (10m, see nginx.conf), or
 *   - request a different query string (a distinct cache key), or
 *   - restart the cache container (`docker compose restart cache`), which
 *     drops the volume-backed cache directory's in-memory keys_zone.
 */
export function cacheBase() {
    return cacheBaseURL();
}
