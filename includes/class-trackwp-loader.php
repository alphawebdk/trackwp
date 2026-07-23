<?php
defined('ABSPATH') || exit;

/**
 * First-party loader & collect proxy.
 *
 * Serves gtag.js from the site's own domain (bypasses domain-based script
 * blockers) and proxies GA4 /g/collect hits through the site's own domain.
 *
 * Note: path-based adblock rules (e.g. EasyPrivacy's /g/collect patterns)
 * can still match the proxied collect endpoint; the proxy bypasses
 * domain-based blocking, which covers the vast majority of blockers.
 */
class TrackWP_Loader {

    /**
     * Register the REST routes.
     * Does nothing unless the first-party loader is enabled in advanced settings.
     */
    public function register_routes() {
        $advanced = get_option('trackwp_advanced', array());
        if (empty($advanced['first_party_loader_enabled'])) {
            return;
        }

        // First-party gtag.js — public script, no origin check (script tags don't send Origin).
        register_rest_route('trackwp/v1', '/loader', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'serve_gtag_js'),
            'permission_callback' => '__return_true',
        ));

        // First-party GA4 collect proxy. Neutral paths (no "collect"/"/g/") so
        // path-based adblock rules (e.g. EasyPrivacy's /collect?v=) don't match;
        // the served gtag.js body is rewritten to POST here. The legacy
        // /c/g/collect route is kept as a fallback for when body rewriting
        // didn't apply (e.g. a future gtag.js layout change).
        $collect_routes = array(
            '/c/e'         => '/g/collect',
            '/c/se'        => '/g/s/collect',
            '/c/g/collect' => '/g/collect',
        );
        foreach ($collect_routes as $route => $upstream) {
            register_rest_route('trackwp/v1', $route, array(
                'methods'             => array('GET', 'POST'),
                'callback'            => function ($request) use ($upstream) {
                    return $this->proxy_collect($request, $upstream);
                },
                'permission_callback' => array($this, 'check_collect_permission'),
            ));
        }
    }

    /**
     * Serve gtag.js from our own domain.
     *
     * Fetches the script from googletagmanager.com and caches the body in a
     * transient for 12 hours. On fetch failure with no cache, serves a tiny
     * fallback that injects the script tag directly against Google
     * (graceful degradation).
     *
     * Echo + exit is intentional: we must serve raw JavaScript, not the
     * REST API's JSON envelope.
     */
    public function serve_gtag_js() {
        $platforms = get_option('trackwp_platforms', array());

        // Find the first valid tag id: GA4 measurement id, then Ads conversion id.
        $id = '';
        if (!empty($platforms['ga4_enabled'])) {
            $ga4_id = isset($platforms['ga4_measurement_id']) ? $platforms['ga4_measurement_id'] : '';
            if (preg_match('/^G-[A-Z0-9]{4,12}$/', $ga4_id)) {
                $id = $ga4_id;
            }
        }
        if ('' === $id) {
            $ads_id = isset($platforms['google_ads_conversion_id']) ? $platforms['google_ads_conversion_id'] : '';
            if (preg_match('/^AW-\d{6,12}$/', $ads_id)) {
                $id = $ads_id;
            }
        }
        if ('' === $id) {
            return new WP_Error('no_tag_id', __('Ingen gyldig tag-id konfigureret.', 'trackwp'), array('status' => 404));
        }

        $cache_key = 'trackwp_gtag_js_rw_' . md5($id);
        $body = get_transient($cache_key);
        if (false === $body) {
            $response = wp_remote_get('https://www.googletagmanager.com/gtag/js?id=' . $id, array('timeout' => 5));
            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $body = wp_remote_retrieve_body($response);
                $body = $this->rewrite_gtag_body($body);
                set_transient($cache_key, $body, 12 * HOUR_IN_SECONDS);
            } else {
                // Fetch failed and nothing cached — inject the script tag
                // directly against Google so tracking still works.
                $body = "var s=document.createElement('script');s.async=true;"
                    . "s.src='https://www.googletagmanager.com/gtag/js?id=" . $id . "';"
                    . "document.head.appendChild(s);";
            }
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $body;
        exit;
    }

    /**
     * Rewrite the GA4 collection path literals in the served gtag.js so the
     * browser POSTs to our neutral first-party proxy paths instead of
     * "/g/collect". Combined with the transport_url set in render_gtag_head
     * (…/trackwp/v1/c), gtag posts to …/trackwp/v1/c/e — a path that contains
     * neither "collect" nor "/g/", so generic path-based adblock filters
     * (EasyPrivacy /collect?v=) do not match. The proxy then forwards to the
     * real google-analytics.com endpoint.
     *
     * Longest patterns first (strtr already prefers longer keys). If the
     * literals are absent (gtag.js layout change), the body is returned
     * unchanged and the legacy /c/g/collect fallback route handles hits.
     *
     * @param string $body Raw gtag.js source.
     * @return string Rewritten source.
     */
    private function rewrite_gtag_body($body) {
        if (!is_string($body) || '' === $body) {
            return $body;
        }
        return strtr($body, array(
            '/g/s/collect' => '/se',
            '/g/collect'   => '/e',
        ));
    }

    /**
     * Permission check for the collect proxy: origin + rate limit.
     * Same origin/referer pattern as TrackWP_Proxy::check_permission, but with
     * a higher rate limit (60 requests per fixed 2-second window per IP) since
     * page_view + engagement pings fire far more often than conversion events.
     * No nonce: cached pages serve stale nonces, and the endpoint is non-mutating.
     */
    public function check_collect_permission($request) {
        // Origin check — only allow from own domain
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $home = home_url();
        $home_host = wp_parse_url($home, PHP_URL_HOST);

        $origin_ok = false;
        if ($origin && wp_parse_url($origin, PHP_URL_HOST) === $home_host) {
            $origin_ok = true;
        }
        if (!$origin_ok && $referer && wp_parse_url($referer, PHP_URL_HOST) === $home_host) {
            $origin_ok = true;
        }
        if (!$origin_ok) {
            return new WP_Error('rest_forbidden', __('Cross-origin-forespørgsel afvist.', 'trackwp'), array('status' => 403));
        }

        // Rate limiting: 60 requests per 2-second fixed window per IP.
        // The window bucket is part of the key so the TTL is never extended
        // by subsequent requests.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $bucket = (int) floor(time() / 2);
        $ip_key = 'trackwp_clrate_' . md5($ip) . '_' . $bucket;
        $count = (int) get_transient($ip_key);
        if ($count >= 60) {
            return new WP_Error('rate_limited', __('For mange forespørgsler.', 'trackwp'), array('status' => 429));
        }
        set_transient($ip_key, $count + 1, 5);

        return true;
    }

    /**
     * Proxy a GA4 collect hit to google-analytics.com.
     *
     * Passes the original query string through untouched and appends _uip
     * (best-effort geo/IP override from REMOTE_ADDR). The client's User-Agent
     * is forwarded so GA's device/browser reporting stays correct.
     *
     * ALWAYS returns 2xx — tracking must never produce console errors.
     *
     * Note: path-based adblock rules (e.g. EasyPrivacy's /g/collect patterns)
     * can still match this endpoint; the proxy bypasses domain-based blocking,
     * which is by far the most common kind.
     */
    public function proxy_collect($request, $upstream_path = '/g/collect') {
        $query_string = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $client_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        // Relay guard: only forward hits addressed to this site's own GA4
        // property. A foreign tid would let third parties pump hits through
        // this site. Missing tid is forwarded as-is (gtag always sends tid).
        // We still answer 2xx without relaying — never a console error.
        wp_parse_str($query_string, $q);
        if (isset($q['tid'])) {
            $platforms = get_option('trackwp_platforms', array());
            $expected  = isset($platforms['ga4_measurement_id']) ? $platforms['ga4_measurement_id'] : '';
            if ($q['tid'] !== $expected) {
                return new WP_REST_Response(null, 204);
            }
        }

        $url = 'https://www.google-analytics.com' . $upstream_path;
        if ('' !== $query_string) {
            $url .= '?' . $query_string;
        } else {
            $url .= '?';
        }
        $url .= '&_uip=' . rawurlencode($client_ip);

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $content_type = isset($_SERVER['CONTENT_TYPE']) && '' !== $_SERVER['CONTENT_TYPE']
            ? $_SERVER['CONTENT_TYPE']
            : 'text/plain';

        $response = wp_remote_request($url, array(
            'method'   => $request->get_method(),
            'timeout'  => 2,
            'blocking' => true,
            'body'     => $request->get_body(),
            'headers'  => array(
                'User-Agent'   => $user_agent,
                'Content-Type' => $content_type,
            ),
        ));

        // Contract (see docblock): ALWAYS return 2xx. Upstream errors and
        // 4xx/5xx codes must never surface as console errors in the browser.
        return new WP_REST_Response(null, 204);
    }
}
