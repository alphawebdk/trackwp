<?php
defined('ABSPATH') || exit;

/**
 * Meta takeover (PLAN-1.11.1-v2 KC6, §9 TR6/TR8; formerly PLAN-1.11.0-v2
 * KB15, §3.7, §11 S17).
 *
 * M1: `trackwp_platforms['meta_pixel_with_gtm']` lets TrackWP print the Meta
 * Pixel even when GTM is in use (gate lives in render_meta_pixel()).
 *
 * M2: when TrackWP can actually deliver Meta (Pixel, with or without CAPI),
 * the Meta for WooCommerce filter
 * `facebook_for_woocommerce_integration_pixel_enabled` is answered with
 * false, so fb4woo's events tracker returns from its constructor before
 * `param_builder_server_setup()` and `add_hooks()`
 * (facebook-commerce-events-tracker.php, fb4woo 3.7.7, lines 103-117 and
 * 239-251). fb4woo's catalog sync and product feed are not affected by this
 * filter (D11): it only gates the events tracker, never the sync/feed code.
 *
 * D1 (1.11.1): an access token is no longer a takeover condition. TrackWP
 * can take over with Pixel only; CAPI (TrackWP_Meta::send_event()) starts
 * automatically once a token becomes available, either TrackWP's own
 * (settings) or, as a fallback, Meta for WooCommerce's token for the SAME
 * pixel (TR6, TrackWP_Meta::access_token()). The only remaining gap this
 * can open is `dedup_server_only`: without a token and with
 * `trackwp_advanced.dedup_mode === 'server_only'`, Pixel alone would only
 * send PageView (assets/js/trackwp.js:686), so that combination is still
 * blocked.
 *
 * `fb4woo_tracking_off` (`trackwp_platforms`) is an explicit emergency stop
 * for fb4woo ONLY: when set, the filter is always answered false regardless
 * of can_deliver(), so fb4woo's events tracker/pixel/CAPI/_fbp stay off. It
 * does not touch TrackWP's own delivery in any way (TrackWP_Meta,
 * render_meta_pixel() etc. are governed solely by their own settings). So
 * when TrackWP can already deliver, the emergency stop changes nothing
 * (fb4woo was suppressed anyway); it only matters while TrackWP cannot
 * deliver, where it turns "fb4woo delivers instead" into "nobody delivers"
 * on purpose. status() reports this honestly via meta_delivered_by (KC6).
 *
 * The filter is registered unconditionally in TrackWP::init_hooks(), i.e.
 * when trackwp.php is loaded and before `plugins_loaded`, so it is in place
 * before fb4woo builds its tracker. When TrackWP cannot deliver at all
 * (missing_conditions() not empty) and the emergency stop is off, the
 * upstream value is returned unchanged, so fb4woo keeps tracking and no
 * data gap appears (S17). A token that is configured but later rejected by
 * the Graph API does NOT change can_deliver(): the takeover stays in place
 * (Pixel keeps sending) and the error is surfaced via status() (no
 * automatic fallback, BESLUTNINGER-1.11.0 "Tokenfejl", unchanged by D1).
 */
class TrackWP_Meta_Takeover {

    /**
     * Filter defined by Meta for WooCommerce.
     */
    const FB4WOO_FILTER = 'facebook_for_woocommerce_integration_pixel_enabled';

    /**
     * Option holding the last Graph token/permission error (not autoloaded).
     * Written by TrackWP_Meta::record_token_error().
     */
    const LAST_ERROR_OPTION = 'trackwp_meta_last_error';

    /**
     * Valid Meta Pixel (dataset) id (KB15).
     */
    const PIXEL_ID_PATTERN = '/^\d{5,20}$/';

    /**
     * True when TrackWP delivers Meta itself: every condition in
     * missing_conditions() holds (KB15 plus review M2).
     *
     * @return bool
     */
    public static function can_deliver() {
        return self::missing_conditions() === array();
    }

    /**
     * Callback for `facebook_for_woocommerce_integration_pixel_enabled`.
     *
     * False when TrackWP delivers Meta (Pixel, with or without CAPI, D1) OR
     * the emergency stop `fb4woo_tracking_off` is on (KC6); otherwise the
     * upstream value is returned unchanged (S17).
     *
     * @param mixed $enabled Upstream value.
     * @return mixed
     */
    public static function filter_pixel_enabled($enabled) {
        if (self::can_deliver() || self::forced_off()) {
            return false;
        }
        return $enabled;
    }

    /**
     * Takeover status for the admin (PLAN-1.11.1-v2 KC6, §9 TR8).
     *
     * @return array {
     *     @type bool        $delivering        can_deliver().
     *     @type string      $mode              'pixel_capi'|'pixel_only'.
     *     @type string      $capi              'active'|'no_token'|'token_error'.
     *     @type string|null $capi_token_source 'trackwp'|'fb4woo'|null (TR8).
     *     @type bool        $fb4woo_active     Meta for WooCommerce is loaded.
     *     @type bool        $forced_off        trackwp_platforms['fb4woo_tracking_off'].
     *     @type bool        $fb4woo_suppressed can_deliver() || forced_off.
     *     @type string      $meta_delivered_by 'trackwp'|'fb4woo'|'none'.
     *     @type string[]    $missing           missing_conditions().
     *     @type array|null  $last_error        Stored Graph token error, or null.
     * }
     */
    public static function status() {
        $missing      = self::missing_conditions();
        $last_error   = get_option(self::LAST_ERROR_OPTION, null);
        $delivering   = $missing === array();
        $forced_off   = self::forced_off();
        $token_source = TrackWP_Meta::access_token_source();
        $has_error    = is_array($last_error);

        $capi = 'no_token';
        if ($token_source !== '') {
            $capi = $has_error ? 'token_error' : 'active';
        }

        if ($delivering) {
            $meta_delivered_by = 'trackwp';
        } elseif ($forced_off) {
            $meta_delivered_by = 'none';
        } else {
            $meta_delivered_by = self::fb4woo_active() ? 'fb4woo' : 'none';
        }

        return array(
            'delivering'        => $delivering,
            'mode'              => $token_source !== '' ? 'pixel_capi' : 'pixel_only',
            'capi'              => $capi,
            'capi_token_source' => $token_source !== '' ? $token_source : null,
            'fb4woo_active'     => self::fb4woo_active(),
            'forced_off'        => $forced_off,
            'fb4woo_suppressed' => $delivering || $forced_off,
            'meta_delivered_by' => $meta_delivered_by,
            'missing'           => $missing,
            'last_error'        => $has_error ? $last_error : null,
        );
    }

    /**
     * The emergency stop (KC6): `trackwp_platforms['fb4woo_tracking_off']`.
     * Only ever forces fb4woo's own pixel/CAPI/_fbp off via the filter; it
     * has no effect on TrackWP's own delivery. Combined with a can_deliver()
     * false, it means neither TrackWP nor fb4woo sends anything to Meta.
     *
     * @return bool
     */
    private static function forced_off() {
        $p = self::platforms();
        return !empty($p['fb4woo_tracking_off']);
    }

    /**
     * The single list of unmet takeover conditions, shared by can_deliver()
     * and status()['missing'] so the two can never disagree.
     *
     * Codes (KC6, D1): m1, meta_enabled, pixel_id, client_pixel, woo_events,
     * dedup_server_only. `token` is no longer a condition: TrackWP can take
     * over with Pixel only (D1). woo_events is required only when
     * WooCommerce is active, using the same checks as the rest of the
     * plugin (TrackWP_WooCommerce::is_available() and ::is_enabled(),
     * option trackwp_woocommerce['enabled']); otherwise fb4woo would be
     * switched off while TrackWP sends no Purchase/AddToCart (review M2).
     * dedup_server_only is added only when there is no token (TrackWP_Meta::
     * access_token(), TR6) AND trackwp_advanced.dedup_mode === 'server_only':
     * without CAPI, Pixel would then only send PageView
     * (assets/js/trackwp.js:686), so the takeover is withheld in that one
     * combination.
     *
     * @return string[]
     */
    private static function missing_conditions() {
        $p       = self::platforms();
        $missing = array();

        if (empty($p['meta_pixel_with_gtm'])) {
            $missing[] = 'm1';
        }
        if (empty($p['meta_enabled'])) {
            $missing[] = 'meta_enabled';
        }
        if (!self::has_valid_pixel_id($p)) {
            $missing[] = 'pixel_id';
        }
        if (empty($p['meta_pixel_client_enabled'])) {
            $missing[] = 'client_pixel';
        }
        if (static::woocommerce_active()) {
            $woo = new TrackWP_WooCommerce(false);
            if (!$woo->is_enabled()) {
                $missing[] = 'woo_events';
            }
        }
        if (self::dedup_mode_server_only() && !self::has_token($p)) {
            $missing[] = 'dedup_server_only';
        }
        return $missing;
    }

    /**
     * @return bool True when trackwp_advanced.dedup_mode === 'server_only'.
     */
    private static function dedup_mode_server_only() {
        $advanced = get_option('trackwp_advanced', array());
        $mode     = (is_array($advanced) && isset($advanced['dedup_mode'])) ? $advanced['dedup_mode'] : 'client_and_server';
        return $mode === 'server_only';
    }

    /**
     * Is WooCommerce active? Same check as the rest of the plugin
     * (TrackWP_WooCommerce::is_available()). Protected so a test subclass
     * can cover the "no WooCommerce" branch, which cannot be produced by
     * unloading WooCommerce in a running test process.
     *
     * @return bool
     */
    protected static function woocommerce_active() {
        $woo = new TrackWP_WooCommerce(false);
        return $woo->is_available();
    }

    /**
     * Is Meta for WooCommerce loaded? WC_Facebook_Loader is declared in the
     * plugin's main file (facebook-for-woocommerce.php:140) and
     * facebook_for_woocommerce() in class-wc-facebookcommerce.php:824.
     *
     * @return bool
     */
    public static function fb4woo_active() {
        return class_exists('WC_Facebook_Loader', false) || function_exists('facebook_for_woocommerce');
    }

    /**
     * @return array
     */
    private static function platforms() {
        $p = get_option('trackwp_platforms', array());
        return is_array($p) ? $p : array();
    }

    /**
     * @param array $p
     * @return bool
     */
    private static function has_valid_pixel_id($p) {
        return isset($p['meta_pixel_id']) && is_scalar($p['meta_pixel_id'])
            && preg_match(self::PIXEL_ID_PATTERN, (string) $p['meta_pixel_id']) === 1;
    }

    /**
     * TR6: is there a CAPI token from either source (TrackWP's own or, as a
     * fallback, Meta for WooCommerce's, for the same pixel)? The single
     * reader is TrackWP_Meta::access_token(), so this can never disagree
     * with what TrackWP_Meta::is_enabled()/send_event() actually use.
     *
     * @param array $p
     * @return bool
     */
    private static function has_token($p) {
        return TrackWP_Meta::access_token($p) !== '';
    }
}
