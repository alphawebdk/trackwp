<?php
/**
 * Test-only mu-plugin: redirects outbound TrackWP platform HTTP calls (GA4
 * Measurement Protocol, Meta CAPI, Google Ads) to the mockplatforms Docker
 * service, so Playwright specs can assert on exactly what TrackWP sent
 * without real GA4/Meta/Ads credentials or outbound network access.
 *
 * Only active when TRACKWP_MOCKPLATFORMS_URL is defined (set via
 * WORDPRESS_CONFIG_EXTRA for the wp/wp62 services in
 * tests/docker/compose.yml). Never present in the shipped plugin build.
 */

if ( ! defined( 'TRACKWP_MOCKPLATFORMS_URL' ) ) {
    // Fail closed: tests must never reach real platforms. Without a mock URL,
    // block every request to the platform hosts instead of letting it through.
    add_filter(
        'pre_http_request',
        function ( $pre, $parsed_args, $url ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            $blocked = array(
                'www.google-analytics.com',
                'region1.google-analytics.com',
                'graph.facebook.com',
                'googleads.googleapis.com',
            );
            if ( is_string( $host ) && in_array( strtolower( $host ), $blocked, true ) ) {
                return new WP_Error( 'trackwp_test_blocked', 'Blocked: tests must not reach real platform ' . $host );
            }
            return $pre;
        },
        10,
        3
    );
    return;
}

/**
 * @param false|array|WP_Error $preempt    Short-circuit value, passed through untouched.
 * @param array                $parsed_args HTTP request args.
 * @param string               $url         Destination URL.
 * @return false|array|WP_Error
 */
function trackwp_test_mock_platform_redirect( $preempt, $parsed_args, $url ) {
    static $routes = array(
        '#^https://www\.google-analytics\.com/mp/collect#'       => '/ga4/mp/collect',
        '#^https://www\.google-analytics\.com/debug/mp/collect#' => '/ga4/debug/mp/collect',
        '#^https://graph\.facebook\.com/[^/]+/[^/]+/events#'     => '/meta/capi',
        '#^https://googleads\.googleapis\.com/[^/]+/customers/#' => '/google-ads/upload',
    );

    foreach ( $routes as $pattern => $path ) {
        if ( ! preg_match( $pattern, $url ) ) {
            continue;
        }

        $mock_url = rtrim( TRACKWP_MOCKPLATFORMS_URL, '/' ) . $path . '?original_url=' . rawurlencode( $url );

        // Unhook while we re-issue the request against the mock, so we
        // don't recurse when wp_remote_request() fires pre_http_request again.
        remove_filter( 'pre_http_request', 'trackwp_test_mock_platform_redirect', 10 );
        $response = wp_remote_request( $mock_url, $parsed_args );
        add_filter( 'pre_http_request', 'trackwp_test_mock_platform_redirect', 10, 3 );

        return $response;
    }

    return $preempt;
}
add_filter( 'pre_http_request', 'trackwp_test_mock_platform_redirect', 10, 3 );
