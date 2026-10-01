<?php
/**
 * TrackWP_DataLayer: the dataLayer layer that pushes e-commerce events before
 * consent, under Advanced Consent Mode (D2, D3, KC7), plus the GA4 routing
 * decision shared with trackwp.js (D4, KC8).
 *
 * Ownership (PLAN-1.11.1-v2 §3): W4.
 */

defined('ABSPATH') || exit;

class TrackWP_DataLayer {

    /**
     * Is the dataLayer layer active for THIS request (KC7)?
     *
     * 'on' is active for everyone. 'test' is active only for the current
     * user when they can manage_options (D9 Preview) — never for anonymous
     * visitors, so a live site is not silently instrumented during a test.
     * 'off' (or any other/missing value) is never active.
     *
     * @return bool
     */
    public static function active_for_request() {
        $platforms = get_option('trackwp_platforms', array());
        $mode      = isset($platforms['gtm_datalayer_events']) ? $platforms['gtm_datalayer_events'] : 'off';

        if ('on' === $mode) {
            return true;
        }
        if ('test' === $mode) {
            return function_exists('current_user_can') && current_user_can('manage_options');
        }
        return false;
    }

    /**
     * Is the dataLayer layer configured for THIS SITE at all (M2)?
     *
     * Used server-side (TrackWP_Proxy::route()) to decide whether a client's
     * ga4_route claim is even worth checking. Deliberately NOT the same test
     * as active_for_request(): a REST request has no nonce/session context
     * (TrackWP_Proxy::check_permission() is same-origin only), so
     * current_user_can() there does not reliably reflect the admin who saw
     * "test" mode in their own browser. Trusting a client's gtm/off route is
     * safe because it can only ever NARROW what the server sends (never turn
     * a genuinely off/gtm route into a server dispatch) — see route().
     *
     * @return bool
     */
    public static function configured_for_site() {
        $platforms = get_option('trackwp_platforms', array());
        $advanced  = get_option('trackwp_advanced', array());
        $mode      = isset($platforms['gtm_datalayer_events']) ? $platforms['gtm_datalayer_events'] : 'off';

        if ('off' === $mode) {
            return false;
        }
        return ! empty($platforms['gtm_enabled']) || ! empty($advanced['uses_gtm']);
    }

    /**
     * Producer of trackwpConfig.dataLayer (KC7), read by trackwp.js.
     *
     * @return array {enabled, ga4Source, ga4Enabled, mpConfigured}
     */
    public static function client_config() {
        $platforms = get_option('trackwp_platforms', array());

        $ga4_source = (isset($platforms['ga4_source']) && 'split' === $platforms['ga4_source']) ? 'split' : 'gtm';

        // The measurement id must be set even when GTM sends the events (KC8):
        // it is what the admin UI checks to call GA4 "configured" at all.
        $ga4_enabled = ! empty($platforms['ga4_enabled']) && ! empty($platforms['ga4_measurement_id']);

        $mp_configured = class_exists('TrackWP_GA4') && (new TrackWP_GA4())->is_enabled();

        return array(
            'enabled'      => self::active_for_request(),
            'ga4Source'    => $ga4_source,
            'ga4Enabled'   => $ga4_enabled,
            'mpConfigured' => $mp_configured,
        );
    }

    /**
     * The GA4 routing decision (D4, KC8). One function, mirrored exactly by
     * ga4Route() in trackwp.js; both are tested against the same vector file
     * (tests/fixtures/datalayer/ga4-route-vectors.json).
     *
     * @param array $in {
     *   @type bool   datalayer_active Defensive: false always yields 'off'.
     *   @type bool   ga4_enabled
     *   @type bool   mp_configured
     *   @type bool   send_to_ga4
     *   @type string source            'gtm'|'split'
     *   @type string event             Event name.
     *   @type bool   statistics        Consent at push time.
     *   @type string dedup_mode
     * }
     * @return string 'gtm'|'server'|'off'
     */
    public static function ga4_route($in) {
        $in = is_array($in) ? $in : array();

        // Krav 3 (parity with JS ga4Route()): strict boolean. A missing key
        // defaults to active, but a present key must be the literal boolean
        // true — a truthy non-boolean (1, '1', 'yes') never counts as active.
        $datalayer_active = ! array_key_exists('datalayer_active', $in) || true === $in['datalayer_active'];
        $send_to_ga4       = ! empty($in['send_to_ga4']);
        $ga4_enabled       = ! empty($in['ga4_enabled']);

        if (! $datalayer_active || ! $send_to_ga4 || ! $ga4_enabled) {
            return 'off';
        }

        $source      = isset($in['source']) ? (string) $in['source'] : 'gtm';
        $event       = isset($in['event']) ? (string) $in['event'] : '';
        $mp          = ! empty($in['mp_configured']);
        $statistics  = ! empty($in['statistics']);
        $dedup_mode  = isset($in['dedup_mode']) ? (string) $in['dedup_mode'] : '';

        if ('split' === $source && 'purchase' === $event && $mp && $statistics && 'client_only' !== $dedup_mode) {
            return 'server';
        }

        return 'gtm';
    }
}
