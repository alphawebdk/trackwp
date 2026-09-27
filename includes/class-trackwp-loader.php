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

    /** Maximum accepted collect body size in bytes (64 kB). */
    const MAX_BODY_BYTES = 65536;

    /** Collect rate limit: requests per 2-second window per client IP. */
    const COLLECT_RATE_LIMIT = 60;

    /**
     * Register the REST routes.
     * Does nothing unless the first-party loader is enabled in advanced settings.
     */
    public function register_routes() {
        $advanced = get_option('trackwp_advanced', array());
        if (empty($advanced['first_party_loader_enabled'])) {
            return;
        }

        // /loader returns raw JavaScript; this filter prints it instead of JSON.
        if (!has_filter('rest_pre_serve_request', array(__CLASS__, 'serve_raw_script'))) {
            add_filter('rest_pre_serve_request', array(__CLASS__, 'serve_raw_script'), 10, 4);
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
     * The response carries the script as its data and is printed raw by
     * serve_raw_script() (rest_pre_serve_request), so the REST API's JSON
     * envelope is skipped while the headers stay testable. The response is
     * public and cacheable for an hour; no-store must never be sent here.
     *
     * @return WP_REST_Response|WP_Error
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

        $response = new WP_REST_Response((string) $body, 200);
        $response->header('Content-Type', 'application/javascript; charset=utf-8');
        $response->header('Cache-Control', 'public, max-age=3600');
        $response->header('X-TrackWP-Raw', '1');
        return $response;
    }

    /**
     * rest_pre_serve_request: print the /loader script raw. WP_REST_Server
     * has already sent the response headers (Content-Type, Cache-Control)
     * when this filter runs.
     *
     * @param bool             $served  Whether the request was already served.
     * @param WP_HTTP_Response $result  Result.
     * @param WP_REST_Request  $request Request.
     * @param WP_REST_Server   $server  Server.
     * @return bool
     */
    public static function serve_raw_script($served, $result, $request, $server) {
        if ($served || !($result instanceof WP_HTTP_Response) || !($request instanceof WP_REST_Request)) {
            return $served;
        }
        if ('/trackwp/v1/loader' !== $request->get_route()) {
            return $served;
        }
        $headers = $result->get_headers();
        if (empty($headers['X-TrackWP-Raw']) || !is_string($result->get_data())) {
            return $served;
        }
        echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- raw JavaScript from googletagmanager.com.
        return true;
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
     * Permission check for the collect proxy: origin + rate limit, both via
     * TrackWP_Request_Guard (client_ip() honours advanced.trusted_proxies).
     * 60 requests per fixed 2-second window per IP, since page_view and
     * engagement pings fire far more often than conversion events.
     * No nonce: cached pages serve stale nonces, and the endpoint is non-mutating.
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public function check_collect_permission($request) {
        return TrackWP_Request_Guard::permission('collect', self::COLLECT_RATE_LIMIT);
    }

    /**
     * Relay guard for a collect hit.
     *
     * The hit must name this site's GA4 property: `tid` must be present in
     * the query string or in the body (gtag batches hits as newline-separated
     * query strings) and every tid found must equal the configured
     * Measurement ID. A missing or foreign tid would let third parties pump
     * hits through this site. Bodies over 64 kB are refused.
     *
     * @param string $query_string Raw query string.
     * @param string $body         Raw body.
     * @return true|WP_Error
     */
    public static function validate_collect($query_string, $body) {
        if (strlen((string) $body) > self::MAX_BODY_BYTES) {
            return new WP_Error('payload_too_large', __('Forespørgslen er for stor.', 'trackwp'), array('status' => 413));
        }

        $platforms = get_option('trackwp_platforms', array());
        $expected  = isset($platforms['ga4_measurement_id']) ? (string) $platforms['ga4_measurement_id'] : '';
        if (!preg_match('/^G-[A-Z0-9]{4,12}$/', $expected)) {
            return new WP_Error('rest_forbidden', __('Ukendt måle-id.', 'trackwp'), array('status' => 403));
        }

        $tids = array();
        $q    = array();
        wp_parse_str((string) $query_string, $q);
        if (isset($q['tid'])) {
            $tids[] = $q['tid'];
        }
        $body = (string) $body;
        if ('' !== $body) {
            foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                if ('' === trim($line)) {
                    continue;
                }
                $params = array();
                wp_parse_str($line, $params);
                if (isset($params['tid'])) {
                    $tids[] = $params['tid'];
                }
            }
        }

        if (empty($tids)) {
            return new WP_Error('rest_forbidden', __('Ukendt måle-id.', 'trackwp'), array('status' => 403));
        }
        foreach ($tids as $tid) {
            if (!is_string($tid) || $tid !== $expected) {
                return new WP_Error('rest_forbidden', __('Ukendt måle-id.', 'trackwp'), array('status' => 403));
            }
        }
        return true;
    }

    /**
     * Proxy a GA4 collect hit to google-analytics.com.
     *
     * The query string is passed through untouched (gcs/gcd and every other
     * Consent Mode parameter keep their exact values) and _uip is appended
     * from TrackWP_Request_Guard::client_ip(). The client's User-Agent is
     * forwarded so GA's device/browser reporting stays correct.
     *
     * Requests failing validate_collect() get 403/413. Once validated, the
     * answer is always 204: upstream errors never surface in the browser.
     * No Cache-Control: no-store is added (it must not hit /c).
     *
     * @param WP_REST_Request $request       Request.
     * @param string          $upstream_path Upstream path.
     * @return WP_REST_Response|WP_Error
     */
    public function proxy_collect($request, $upstream_path = '/g/collect') {
        $query_string = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : '';
        $body         = (string) $request->get_body();

        $valid = self::validate_collect($query_string, $body);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $url = 'https://www.google-analytics.com' . $upstream_path;
        if ('' !== $query_string) {
            $url .= '?' . $query_string;
        } else {
            $url .= '?';
        }
        $client_ip = TrackWP_Request_Guard::client_ip();
        if ('' !== $client_ip) {
            $url .= '&_uip=' . rawurlencode($client_ip);
        }

        $user_agent   = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        $content_type = isset($_SERVER['CONTENT_TYPE']) && '' !== $_SERVER['CONTENT_TYPE']
            ? (string) $_SERVER['CONTENT_TYPE']
            : 'text/plain';

        wp_remote_request($url, array(
            'method'   => $request->get_method(),
            'timeout'  => 2,
            'blocking' => true,
            'body'     => $body,
            'headers'  => array(
                'User-Agent'   => $user_agent,
                'Content-Type' => $content_type,
            ),
        ));

        // Contract (see docblock): a validated hit ALWAYS returns 2xx.
        return new WP_REST_Response(null, 204);
    }
}
