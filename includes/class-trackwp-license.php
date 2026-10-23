<?php
/**
 * TrackWP license client.
 *
 * Talks to the license server (nordium.ai/trackwp) and decides, through the
 * single function tracking_allowed(), whether tracking may run on this site.
 * The decision rests on a signed token (Ed25519): only a token with a valid
 * signature, bound to this host, can change the state. Network errors, HTTP
 * errors and malformed answers never change the stored state.
 *
 * Token format (see license-server/src/token.js):
 * base64url(JSON) . base64url(signature over the ASCII of the first part),
 * payload {v, kid, lic, dom, st, iat, exp, lexp, max}.
 *
 * @package TrackWP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TrackWP_License {

    const API       = 'https://nordium.ai/trackwp/api/';
    const OPTION    = 'trackwp_license';
    const GRACE_OPT = 'trackwp_license_grace_until';
    const CRON_HOOK = 'trackwp_license_check';

    /** Clock slack when judging a token's exp (seconds). */
    const SKEW = 300;

    /**
     * Production public keys: kid => raw 32 byte Ed25519 key, base64.
     * k1 is the production key generated on the nordium server 2026-10-22.
     * An empty key never verifies anything. Test keys
     * are only ever added through the trackwp_license_public_keys filter.
     */
    const PUBLIC_KEYS = array(
        'k1' => 'nwP37wVutiHFWm5Tt5HiFyOivDJnRr57YOhDL1VOJ3o=',
    );

    const STATUSES = array( 'active', 'disabled', 'expired', 'unknown', 'limit' );

    /** @var bool|null Per-request cache of tracking_allowed(). */
    private static $cache = null;

    /** @var bool|null Forced answer, tests only. */
    private static $forced = null;

    // ------------------------------------------------------------------
    // Pure helpers
    // ------------------------------------------------------------------

    /**
     * Normalize a URL or host the way the server does: trim, lowercase, drop
     * scheme, userinfo, port, path, query, one trailing dot and one leading
     * "www.", punycode for IDN. Returns null for invalid input.
     *
     * @param mixed $url
     * @return string|null
     */
    public static function normalize_host( $url ) {
        if ( ! is_string( $url ) ) {
            return null;
        }
        $h = trim( $url );
        $h = function_exists( 'mb_strtolower' ) ? mb_strtolower( $h, 'UTF-8' ) : strtolower( $h );
        $h = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $h );
        $h = preg_split( '#[/?\#]#', $h, 2 )[0];
        $at = strrpos( $h, '@' );
        if ( false !== $at ) {
            $h = substr( $h, $at + 1 );
        }
        if ( '' !== $h && '[' === $h[0] ) {
            $end = strpos( $h, ']' );
            if ( false === $end ) {
                return null;
            }
            $ip = substr( $h, 1, $end - 1 );
            return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? $ip : null;
        }
        $h = preg_replace( '/:\d*$/', '', $h );
        if ( '.' === substr( $h, -1 ) ) {
            $h = substr( $h, 0, -1 );
        }
        if ( 0 === strpos( $h, 'www.' ) ) {
            $h = substr( $h, 4 );
        }
        if ( '' === $h ) {
            return null;
        }
        if ( preg_match( '/[^\x20-\x7e]/', $h ) ) {
            $h = self::to_ascii( $h );
            if ( null === $h ) {
                return null;
            }
        }
        if ( strlen( $h ) > 253 ) {
            return null;
        }
        foreach ( explode( '.', $h ) as $label ) {
            if ( preg_match( '/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $label ) !== 1 ) {
                return null;
            }
        }
        return $h;
    }

    /**
     * Punycode a host with intl when present, else with the Requests library
     * that ships with WordPress.
     *
     * @param string $host
     * @return string|null
     */
    private static function to_ascii( $host ) {
        $out = false;
        if ( function_exists( 'idn_to_ascii' ) ) {
            $variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0;
            $out     = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, $variant );
        } else {
            try {
                if ( class_exists( 'WpOrg\Requests\IdnaEncoder' ) ) {
                    $out = \WpOrg\Requests\IdnaEncoder::encode( $host );
                } elseif ( class_exists( 'Requests_IDNAEncoder' ) ) {
                    $out = Requests_IDNAEncoder::encode( $host );
                }
            } catch ( \Throwable $e ) {
                $out = false;
            }
        }
        return ( is_string( $out ) && '' !== $out ) ? strtolower( $out ) : null;
    }

    /**
     * Verify a license token.
     *
     * Returns array( ok, status, exp, lexp, reason ). ok is true only when the
     * signature is valid, the kid is known and dom equals $host. exp is NOT
     * judged here (a signed but old token is still genuine); the caller
     * compares exp with the clock.
     * reason (when not ok): format | kid | signature | host.
     *
     * @param mixed  $token
     * @param array  $keys  kid => raw 32 byte public key, base64.
     * @param string $host  Normalized host of this site.
     * @return array
     */
    public static function verify_token( $token, array $keys, $host ) {
        $fail = function ( $reason ) {
            return array(
                'ok'     => false,
                'status' => null,
                'exp'    => null,
                'lexp'   => null,
                'reason' => $reason,
            );
        };
        if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 4096 ) {
            return $fail( 'format' );
        }
        $parts = explode( '.', $token );
        if ( 2 !== count( $parts )
            || preg_match( '/^[A-Za-z0-9_-]+$/', $parts[0] ) !== 1
            || preg_match( '/^[A-Za-z0-9_-]+$/', $parts[1] ) !== 1 ) {
            return $fail( 'format' );
        }
        $json = self::b64u_decode( $parts[0] );
        $p    = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $p ) || ! isset( $p['kid'] ) || ! is_string( $p['kid'] ) ) {
            return $fail( 'format' );
        }
        if ( ! isset( $keys[ $p['kid'] ] ) || ! is_string( $keys[ $p['kid'] ] ) ) {
            return $fail( 'kid' );
        }
        $pub = base64_decode( $keys[ $p['kid'] ], true );
        if ( ! is_string( $pub ) || 32 !== strlen( $pub ) ) {
            return $fail( 'kid' );
        }
        $sig = self::b64u_decode( $parts[1] );
        if ( ! is_string( $sig ) || 64 !== strlen( $sig ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            return $fail( 'signature' );
        }
        try {
            $valid = sodium_crypto_sign_verify_detached( $sig, $parts[0], $pub );
        } catch ( \Throwable $e ) {
            $valid = false;
        }
        if ( ! $valid ) {
            return $fail( 'signature' );
        }
        if ( ! isset( $p['v'], $p['st'], $p['dom'], $p['exp'], $p['lexp'] )
            || 1 !== $p['v']
            || ! is_string( $p['st'] ) || ! in_array( $p['st'], self::STATUSES, true )
            || ! is_string( $p['dom'] ) || ! is_int( $p['exp'] ) || ! is_int( $p['lexp'] ) ) {
            return $fail( 'format' );
        }
        if ( ! is_string( $host ) || $p['dom'] !== $host ) {
            return $fail( 'host' );
        }
        return array(
            'ok'     => true,
            'status' => $p['st'],
            'exp'    => $p['exp'],
            'lexp'   => $p['lexp'],
            'reason' => '',
        );
    }

    private static function b64u_decode( $s ) {
        return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
    }

    /**
     * Public keys in use. Production list plus the filter (tests only).
     *
     * @return array
     */
    public static function public_keys() {
        $keys = apply_filters( 'trackwp_license_public_keys', self::PUBLIC_KEYS );
        return is_array( $keys ) ? $keys : self::PUBLIC_KEYS;
    }

    /**
     * Normalized host of this site.
     *
     * @return string
     */
    public static function host() {
        return (string) self::normalize_host( get_option( 'home' ) );
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    private static function stored() {
        $o = get_option( self::OPTION, array() );
        return is_array( $o ) ? $o : array();
    }

    private static function save( array $patch ) {
        $o = array_merge(
            array(
                'key'          => '',
                'token'        => '',
                'last_error'   => '',
                'last_attempt' => 0,
            ),
            self::stored(),
            $patch
        );
        update_option( self::OPTION, $o, true );
        self::$cache = null;
    }

    private static function license_key() {
        $o = self::stored();
        if ( empty( $o['key'] ) || ! is_string( $o['key'] ) ) {
            return '';
        }
        return trim( (string) TrackWP_Hash::decode( $o['key'] ) );
    }

    /**
     * Judge the stored token against the clock (ignores the test override).
     *
     * @param int|null $now
     * @return array status (string|null), exp, lexp, valid (signed and for this host), fresh (status active and not expired).
     */
    public static function state( $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $o   = self::stored();
        $r   = self::verify_token( isset( $o['token'] ) ? $o['token'] : '', self::public_keys(), self::host() );
        return array(
            'valid'  => $r['ok'],
            'status' => $r['status'],
            'exp'    => $r['exp'],
            'lexp'   => $r['lexp'],
            'fresh'  => $r['ok'] && 'active' === $r['status'] && $r['exp'] > $now - self::SKEW,
        );
    }

    /**
     * Grace period end (E1), 0 when none.
     *
     * @return int
     */
    public static function grace_until() {
        return (int) get_option( self::GRACE_OPT, 0 );
    }

    /**
     * Is the grace period (E1) in force? Only for a site without a stored
     * license key (or without a token). Once a key is stored, the signed
     * token alone decides, so a signed disabled/expired/unknown status stops
     * tracking even before the grace period has run out.
     *
     * @param int $now
     * @return bool
     */
    private static function grace_active( $now ) {
        if ( self::grace_until() <= $now ) {
            return false;
        }
        $o = self::stored();
        return empty( $o['key'] ) || empty( $o['token'] );
    }

    private static function evaluate( $now ) {
        return self::state( $now )['fresh'] || self::grace_active( $now );
    }

    /**
     * May tracking run on this site? Cached for the rest of the request.
     *
     * @param int|null $now Optional clock override (bypasses the cache).
     * @return bool
     */
    public static function tracking_allowed( $now = null ) {
        if ( null !== self::$forced ) {
            return self::$forced;
        }
        if ( null !== $now ) {
            return self::evaluate( (int) $now );
        }
        if ( null === self::$cache ) {
            self::$cache = self::evaluate( time() );
        }
        return self::$cache;
    }

    /**
     * Forget the per-request answer (call after changing the grace option).
     *
     * @return void
     */
    public static function reset_cache() {
        self::$cache = null;
    }

    /**
     * Test hook: true/false forces the answer of tracking_allowed(), null
     * removes the override and clears the cache. Used by test bootstrap and
     * the E2E mu-plugin only.
     *
     * @param bool|null $state
     * @return void
     */
    public static function force_state_for_tests( $state ) {
        self::$forced = null === $state ? null : (bool) $state;
        self::$cache  = null;
    }

    // ------------------------------------------------------------------
    // Server calls
    // ------------------------------------------------------------------

    /**
     * POST to the license server. Returns the parsed JSON body on HTTP 200 or
     * a WP_Error (transport error, any non-200, invalid JSON).
     */
    private static function post( $endpoint, $key, array $body ) {
        $res = wp_remote_post(
            self::API . $endpoint,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
            )
        );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( 200 !== $code ) {
            return new WP_Error( 'trackwp_license_http', 'HTTP ' . $code );
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'trackwp_license_json', 'Invalid response' );
        }
        return $data;
    }

    private static function request_token( $endpoint, $key ) {
        global $wp_version;
        $data = self::post(
            $endpoint,
            $key,
            array(
                'domain'         => self::host(),
                'plugin_version' => defined( 'TRACKWP_VERSION' ) ? TRACKWP_VERSION : '',
                'wp_version'     => isset( $wp_version ) ? $wp_version : '',
            )
        );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $token = isset( $data['token'] ) && is_string( $data['token'] ) ? $data['token'] : '';
        $r     = self::verify_token( $token, self::public_keys(), self::host() );
        if ( ! $r['ok'] ) {
            return new WP_Error( 'trackwp_license_token', 'Rejected token: ' . $r['reason'] );
        }
        return array(
            'token'  => $token,
            'status' => $r['status'],
        );
    }

    /**
     * Run $change (which writes state) and purge page caches when the
     * allowed/blocked decision flipped.
     */
    private static function with_transition( callable $change ) {
        $before = self::evaluate( time() );
        $change();
        self::$cache = null;
        $after       = self::evaluate( time() );
        if ( $before !== $after && class_exists( 'TrackWP' ) ) {
            TrackWP::instance()->purge_known_page_caches();
        }
    }

    private static function record_failure( WP_Error $e ) {
        $now = time();
        self::save(
            array(
                'last_error'   => $e->get_error_message(),
                'last_attempt' => $now,
            )
        );
        // Token about to run out: try again in an hour.
        $s = self::state( $now );
        if ( $s['valid'] && 'active' === $s['status'] && $s['exp'] < $now + 2 * DAY_IN_SECONDS
            && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( $now + HOUR_IN_SECONDS, self::CRON_HOOK );
        }
    }

    /**
     * Activate a license key for this domain.
     *
     * @param string $key
     * @return array|WP_Error array( status ) from the signed token.
     */
    public static function activate( $key ) {
        $key = trim( (string) $key );
        if ( '' === $key ) {
            return new WP_Error( 'trackwp_license_key', 'Missing key' );
        }
        $res = self::request_token( 'activate', $key );
        if ( is_wp_error( $res ) ) {
            self::record_failure( $res );
            return $res;
        }
        // Only a signed active answer is stored. A typo, a full quota or a
        // disabled key must not replace the previous state (or end grace).
        if ( 'active' !== $res['status'] ) {
            $e = new WP_Error(
                'trackwp_license_' . $res['status'],
                self::status_error_message( $res['status'] )
            );
            self::record_failure( $e );
            return $e;
        }
        self::with_transition(
            function () use ( $key, $res ) {
                self::save(
                    array(
                        'key'          => TrackWP_Hash::encode( $key ),
                        'token'        => $res['token'],
                        'last_error'   => '',
                        'last_attempt' => time(),
                    )
                );
                // Once a key has been active here, grace never applies again
                // (not even after "Frigiv dette domæne").
                delete_option( self::GRACE_OPT );
            }
        );
        return array( 'status' => $res['status'] );
    }

    private static function status_error_message( $status ) {
        switch ( $status ) {
            case 'limit':
                return __( 'Licensen er allerede brugt på det maksimale antal domæner.', 'trackwp' );
            case 'disabled':
                return __( 'Licensen er deaktiveret.', 'trackwp' );
            case 'expired':
                return __( 'Licensen er udløbet.', 'trackwp' );
            default:
                return __( 'Licensnøglen er ukendt.', 'trackwp' );
        }
    }

    /**
     * Refresh the token with the stored key (cron, admin, "Tjek nu").
     *
     * @return array|WP_Error
     */
    public static function check() {
        $key = self::license_key();
        if ( '' === $key ) {
            return new WP_Error( 'trackwp_license_key', 'No key stored' );
        }
        $res = self::request_token( 'check', $key );
        if ( is_wp_error( $res ) ) {
            self::record_failure( $res );
            return $res;
        }
        self::with_transition(
            function () use ( $res ) {
                self::save(
                    array(
                        'token'        => $res['token'],
                        'last_error'   => '',
                        'last_attempt' => time(),
                    )
                );
            }
        );
        return array( 'status' => $res['status'] );
    }

    /**
     * Release this domain on the server and forget key and token locally.
     * Local state is only cleared when the server confirmed.
     *
     * @return true|WP_Error
     */
    public static function deactivate() {
        $key = self::license_key();
        if ( '' === $key ) {
            return new WP_Error( 'trackwp_license_key', 'No key stored' );
        }
        $data = self::post( 'deactivate', $key, array( 'domain' => self::host() ) );
        if ( is_wp_error( $data ) ) {
            self::record_failure( $data );
            return $data;
        }
        if ( empty( $data['ok'] ) ) {
            $e = new WP_Error( 'trackwp_license_json', 'Not confirmed' );
            self::record_failure( $e );
            return $e;
        }
        self::with_transition(
            function () {
                delete_option( self::OPTION );
            }
        );
        return true;
    }

    // ------------------------------------------------------------------
    // Hooks, update checker, admin
    // ------------------------------------------------------------------

    public static function register_hooks() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_check' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_refresh' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
        add_action( 'admin_post_trackwp_license_activate', array( __CLASS__, 'handle_activate' ) );
        add_action( 'admin_post_trackwp_license_check', array( __CLASS__, 'handle_check' ) );
        add_action( 'admin_post_trackwp_license_deactivate', array( __CLASS__, 'handle_deactivate' ) );
    }

    public static function cron_check() {
        if ( '' !== self::license_key() ) {
            self::check();
        }
    }

    /**
     * Admin-only safety net: make sure the recurring check is scheduled and
     * refresh early when the token is close to expiry. Never on the frontend.
     */
    public static function maybe_refresh() {
        if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( '' === self::license_key() ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
        }
        $o = self::stored();
        $s = self::state();
        if ( $s['valid'] && $s['exp'] < time() + 3 * DAY_IN_SECONDS
            && time() - (int) ( isset( $o['last_attempt'] ) ? $o['last_attempt'] : 0 ) > HOUR_IN_SECONDS ) {
            self::check();
        }
    }

    /** Plugin Update Checker query-arg filter. */
    public static function puc_query_args( $args ) {
        $args           = is_array( $args ) ? $args : array();
        $args['domain'] = self::host();
        return $args;
    }

    /** Plugin Update Checker HTTP-arg filter: key in the Authorization header. */
    public static function puc_http_args( $opts ) {
        $opts = is_array( $opts ) ? $opts : array();
        $key  = self::license_key();
        if ( '' !== $key ) {
            if ( ! isset( $opts['headers'] ) || ! is_array( $opts['headers'] ) ) {
                $opts['headers'] = array();
            }
            $opts['headers']['Authorization'] = 'Bearer ' . $key;
        }
        return $opts;
    }

    public static function admin_menu() {
        add_submenu_page(
            'trackwp',
            __( 'Licens', 'trackwp' ),
            __( 'Licens', 'trackwp' ),
            'manage_options',
            'trackwp-license',
            array( __CLASS__, 'render_page' )
        );
    }

    private static function date( $ts ) {
        return wp_date( get_option( 'date_format' ), (int) $ts );
    }

    /**
     * Text for the current state, or '' when nothing needs saying.
     *
     * @return array array( class, text )
     */
    private static function message() {
        $s     = self::state();
        $grace = self::grace_until();
        $o     = self::stored();
        $err   = ! empty( $o['last_error'] );
        if ( $s['fresh'] ) {
            if ( $err ) {
                /* translators: %s: date */
                return array( 'warning', sprintf( __( 'Licensserveren kunne ikke nås. Tracking fortsætter til %s.', 'trackwp' ), self::date( $s['exp'] ) ) );
            }
            /* translators: %s: date */
            return array( 'success', sprintf( __( 'Licensen er aktiv. Udløber %s.', 'trackwp' ), self::date( $s['lexp'] ) ) );
        }
        if ( self::grace_active( time() ) ) {
            /* translators: %s: date */
            return array( 'warning', sprintf( __( 'TrackWP kræver nu en licens. Tracking fortsætter til %s.', 'trackwp' ), self::date( $grace ) ) );
        }
        if ( $s['valid'] && 'disabled' === $s['status'] ) {
            return array( 'error', __( 'Licensen er deaktiveret. Tracking er slået fra.', 'trackwp' ) );
        }
        if ( $s['valid'] && 'expired' === $s['status'] ) {
            return array( 'error', __( 'Licensen er udløbet. Tracking er slået fra.', 'trackwp' ) );
        }
        if ( $s['valid'] && 'limit' === $s['status'] ) {
            return array( 'error', __( 'Licensen er allerede brugt på det maksimale antal domæner.', 'trackwp' ) );
        }
        return array( 'error', __( 'TrackWP er ikke aktiveret. Tracking er slået fra, indtil du aktiverer en licensnøgle.', 'trackwp' ) );
    }

    public static function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( (string) $screen->id, 'trackwp' ) ) ) {
            return;
        }
        // The license page prints its own message; success is never a notice.
        if ( false !== strpos( (string) $screen->id, 'trackwp-license' ) ) {
            return;
        }
        $m = self::message();
        if ( 'success' === $m[0] ) {
            return;
        }
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr( $m[0] ),
            esc_html( $m[1] )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ) );
        }
        $m       = self::message();
        $has_key = '' !== self::license_key();
        $post    = esc_url( admin_url( 'admin-post.php' ) );
        echo '<div class="wrap"><h1>' . esc_html__( 'Licens', 'trackwp' ) . '</h1>';
        if ( isset( $_GET['trackwp_license_error'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['trackwp_license_error'] ) ) ) . '</p></div>';
        }
        printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $m[0] ), esc_html( $m[1] ) );
        echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
        wp_nonce_field( 'trackwp_license_activate' );
        echo '<input type="hidden" name="action" value="trackwp_license_activate">';
        echo '<p><label for="trackwp_license_key">' . esc_html__( 'Licensnøgle', 'trackwp' ) . '</label> ';
        echo '<input type="text" id="trackwp_license_key" name="license_key" class="regular-text" autocomplete="off"></p>';
        submit_button( __( 'Aktivér', 'trackwp' ), 'primary', 'submit', false );
        echo '</form>';
        if ( $has_key ) {
            foreach ( array(
                'trackwp_license_check'      => __( 'Tjek nu', 'trackwp' ),
                'trackwp_license_deactivate' => __( 'Frigiv dette domæne', 'trackwp' ),
            ) as $action => $label ) {
                echo '<form method="post" action="' . $post . '" style="display:inline-block;margin-top:12px;margin-right:8px">'; // phpcs:ignore WordPress.Security.EscapeOutput
                wp_nonce_field( $action );
                echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
                submit_button( $label, 'secondary', 'submit', false );
                echo '</form>';
            }
        }
        echo '</div>';
    }

    private static function admin_post_guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ), 403 );
        }
        check_admin_referer( $action );
    }

    private static function back( $result ) {
        $url = admin_url( 'admin.php?page=trackwp-license' );
        if ( is_wp_error( $result ) ) {
            $url = add_query_arg( 'trackwp_license_error', rawurlencode( $result->get_error_message() ), $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_activate() {
        self::admin_post_guard( 'trackwp_license_activate' );
        $key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
        self::back( self::activate( $key ) );
    }

    public static function handle_check() {
        self::admin_post_guard( 'trackwp_license_check' );
        self::back( self::check() );
    }

    public static function handle_deactivate() {
        self::admin_post_guard( 'trackwp_license_deactivate' );
        self::back( self::deactivate() );
    }
}
