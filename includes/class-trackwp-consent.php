<?php
defined('ABSPATH') || exit;

/**
 * Consent: banner rendering, the consent cookie reader (PHP and JS), the
 * effective-consent decision for server-side events (K3, R1-R3) and the
 * consent-log / withdraw endpoints (K4, R5-R8).
 *
 * Cookie `trackwp_consent` is URL-encoded JSON:
 *   {v, ts (ISO UTC), id (uuid4), necessary:true, statistics, marketing, personalisation, stale?}
 * A cookie that is missing, unparseable, marked stale or written for another
 * consent_version means "no choice", both in PHP (parse_cookie) and in JS
 * (reader_js), which are kept rule-for-rule identical.
 */
class TrackWP_Consent {

    /** Consent cookie name. */
    const COOKIE = 'trackwp_consent';

    /** Transient prefix for the newest ts_ms seen per consent_id (R7). */
    const TS_TRANSIENT_PREFIX = 'trackwp_cts_';

    /** Maximum accepted clock skew into the future for body ts_ms (5 minutes). */
    const MAX_FUTURE_SKEW_MS = 300000;

    /** Rate limit for the consent endpoints: requests per 2-second window per IP. */
    const RATE_LIMIT = 5;

    public function __construct() {
        add_action('wp_footer', array($this, 'render_banner'));
        add_action('wp_footer', array($this, 'render_consent_trigger'));
        add_action('rest_api_init', array($this, 'register_consent_log_route'));
        add_action('rest_api_init', array($this, 'register_consent_withdraw_route'));
        add_shortcode('trackwp_consent_link', array($this, 'shortcode_consent_link'));
    }

    /* ------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    /**
     * Render a floating "Cookie-indstillinger" trigger button in the footer.
     *
     * Site-owners can hide it via the `trackwp_show_consent_trigger` filter.
     */
    public function render_consent_trigger() {
        if (is_admin()) return;
        if (!apply_filters('trackwp_show_consent_trigger', true)) return;
        echo '<button type="button" class="trackwp-consent-trigger" aria-label="' . esc_attr__('Cookie-indstillinger', 'trackwp') . '">';
        echo '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="24" height="24">';
        echo '<path d="M12 2a10 10 0 1 0 10 10v-.5a2.5 2.5 0 0 1-3.78-2.86 2.5 2.5 0 0 1-2.86-3.78A2.5 2.5 0 0 1 11.5 2H12zM7.5 8a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm4 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm5-3a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>';
        echo '</svg>';
        echo '</button>';
    }

    /**
     * Shortcode [trackwp_consent_link] - renders a link that re-opens the consent banner.
     *
     * @param array $atts Shortcode attributes. Supports `text`.
     * @return string HTML anchor.
     */
    public function shortcode_consent_link($atts) {
        $atts = shortcode_atts(
            array('text' => __('Skift cookie-indstillinger', 'trackwp')),
            $atts,
            'trackwp_consent_link'
        );
        return '<a href="#trackwp-consent" class="trackwp-consent-open">' . esc_html($atts['text']) . '</a>';
    }

    public function render_banner() {
        if (is_admin()) return;
        $config = get_option('trackwp_consent', array());
        $privacy_url = '';
        if (!empty($config['privacy_page_id'])) {
            $privacy_url = get_permalink(absint($config['privacy_page_id']));
        }
        include TRACKWP_PLUGIN_DIR . 'templates/consent-banner.php';
    }

    /* ------------------------------------------------------------------
     * Cookie reading (PHP) and the JS reader
     * ------------------------------------------------------------------ */

    /**
     * The consent_version configured on the server.
     *
     * @return int
     */
    public static function server_version() {
        $config = get_option('trackwp_consent', array());
        return isset($config['consent_version']) ? max(1, (int) $config['consent_version']) : 1;
    }

    /**
     * Decode the raw consent cookie WITHOUT validity checks. Used for the ts
     * comparison (R7) and by the status block; never for consent decisions.
     *
     * @param string|null $raw Cookie value; null reads $_COOKIE.
     * @return array|null
     */
    public static function decode_cookie($raw = null) {
        if (null === $raw) {
            if (!isset($_COOKIE[ self::COOKIE ]) || !is_string($_COOKIE[ self::COOKIE ])) {
                return null;
            }
            $raw = wp_unslash($_COOKIE[ self::COOKIE ]);
        }
        $raw = (string) $raw;
        if ('' === $raw || strlen($raw) > 4096) {
            return null;
        }
        // PHP already url-decodes $_COOKIE once; a raw header value (or a
        // double-encoded one) still contains '%' and is decoded here.
        $data = json_decode($raw, true);
        if (!is_array($data) && false !== strpos($raw, '%')) {
            $data = json_decode(rawurldecode($raw), true);
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Parse the consent cookie with the same rules as the JS reader (R3):
     * missing, invalid, stale:true or a v other than the server version all
     * mean "no choice" (null). Category flags count only when === true.
     *
     * @param string|null $raw Cookie value; null reads $_COOKIE.
     * @return array|null {v:int, ts:string, ts_ms:int|null, id:string, necessary:true,
     *                     statistics:bool, marketing:bool, personalisation:bool}
     */
    public static function parse_cookie($raw = null) {
        $data = self::decode_cookie($raw);
        if (null === $data) {
            return null;
        }
        if (!isset($data['v']) || !is_int($data['v']) || $data['v'] !== self::server_version()) {
            return null;
        }
        if (isset($data['stale']) && true === $data['stale']) {
            return null;
        }
        $ts = isset($data['ts']) && is_string($data['ts']) ? $data['ts'] : '';
        $id = isset($data['id']) && is_string($data['id']) && self::is_uuid($data['id']) ? strtolower($data['id']) : '';

        return array(
            'v'               => $data['v'],
            'ts'              => $ts,
            'ts_ms'           => self::ts_to_ms($ts),
            'id'              => $id,
            'necessary'       => true,
            'statistics'      => isset($data['statistics']) && true === $data['statistics'],
            'marketing'       => isset($data['marketing']) && true === $data['marketing'],
            'personalisation' => isset($data['personalisation']) && true === $data['personalisation'],
        );
    }

    /**
     * Current consent from the cookie (server-side check, used by keepalive).
     *
     * Keeps the 1.10.0 keys (necessary, statistics, marketing, personalisation)
     * and adds has_choice, id, v and ts. Stale or wrong-version cookies give
     * has_choice=false and all categories false (R3).
     *
     * @return array
     */
    public static function get_current_consent() {
        $parsed = self::parse_cookie();
        if (null === $parsed) {
            return array(
                'necessary'       => true,
                'statistics'      => false,
                'marketing'       => false,
                'personalisation' => false,
                'has_choice'      => false,
                'id'              => '',
                'v'               => 0,
                'ts'              => '',
            );
        }
        return array(
            'necessary'       => true,
            'statistics'      => $parsed['statistics'],
            'marketing'       => $parsed['marketing'],
            'personalisation' => $parsed['personalisation'],
            'has_choice'      => true,
            'id'              => $parsed['id'],
            'v'               => $parsed['v'],
            'ts'              => $parsed['ts'],
        );
    }

    /**
     * Effective consent for a server-side event (K3 with R1/R2).
     *
     * 1. consent has key 'v' (array_key_exists, R1) and v is the integer
     *    server version: analytics/marketing = (value === true). source=payload.
     * 2. consent has key 'v' and it is an integer other than the server
     *    version: all false, stale=true (caller answers stale_version).
     * 3. consent missing, not an array, or without 'v': cookie fallback via
     *    parse_cookie() (version-checked). source=cookie, or none without a
     *    valid cookie.
     * 4. Anything else (e.g. v present but not an integer): all false.
     * No AND with the cookie.
     *
     * @param mixed $payload_consent     Raw JSON-decoded consent (never sanitized).
     * @param bool  $payload_has_consent Whether the payload contained `consent`.
     * @return array{analytics:bool, marketing:bool, v:int, source:string, stale:bool}
     */
    public static function effective_consent($payload_consent, $payload_has_consent) {
        $server_v = self::server_version();
        $none     = array('analytics' => false, 'marketing' => false, 'v' => 0, 'source' => 'none', 'stale' => false);

        if ($payload_has_consent && is_array($payload_consent) && array_key_exists('v', $payload_consent)) {
            $v = $payload_consent['v'];
            if (!is_int($v)) {
                return $none;
            }
            if ($v !== $server_v) {
                return array('analytics' => false, 'marketing' => false, 'v' => $v, 'source' => 'payload', 'stale' => true);
            }
            return array(
                'analytics' => isset($payload_consent['analytics']) && true === $payload_consent['analytics'],
                'marketing' => isset($payload_consent['marketing']) && true === $payload_consent['marketing'],
                'v'         => $v,
                'source'    => 'payload',
                'stale'     => false,
            );
        }

        $cookie = self::parse_cookie();
        if (null === $cookie) {
            return $none;
        }
        return array(
            'analytics' => $cookie['statistics'],
            'marketing' => $cookie['marketing'],
            'v'         => $cookie['v'],
            'source'    => 'cookie',
            'stale'     => false,
        );
    }

    /**
     * The ONLY consent-cookie reader for the browser (K3, R3).
     *
     * Returns plain JS (no <script> tag) that defines
     * window.trackwpConsentReader = {version, name, raw(), read()}.
     *  - version: WRITABLE. consent.js sets it to current_version after a
     *            stale_version answer, so a new choice made on a cached page
     *            (whose inline version is old) is readable. read() always
     *            compares against window.trackwpConsentReader.version.
     *  - raw():  the decoded cookie object or null, without validity checks
     *            (consent.js uses it to keep the content when marking stale).
     *  - read(): null when missing, invalid, stale or another version;
     *            otherwise {v, ts, id, necessary, statistics, marketing, personalisation}
     *            with flags true only for JSON true.
     * ES5 only; safe to inline before any other script.
     *
     * @return string
     */
    public static function reader_js() {
        $v    = (int) self::server_version();
        $name = self::COOKIE;
        return 'window.trackwpConsentReader={version:' . $v . ',name:"' . $name . '",'
            . 'raw:function(){try{var m=document.cookie.match(/(?:^|;\s*)' . $name . '=([^;]*)/);if(!m||!m[1])return null;'
            . 'var s=m[1],d=null;try{d=JSON.parse(decodeURIComponent(s));}catch(e1){d=null;}'
            . 'if((!d||typeof d!=="object")&&s.indexOf("%")!==-1){try{d=JSON.parse(decodeURIComponent(decodeURIComponent(s)));}catch(e2){d=null;}}'
            . 'return d&&typeof d==="object"&&!(d instanceof Array)?d:null;}catch(e){return null;}},'
            . 'read:function(){var R=window.trackwpConsentReader,d=R.raw();if(!d||d.v!==R.version||d.stale===true)return null;'
            . 'return{v:d.v,ts:typeof d.ts==="string"?d.ts:"",id:typeof d.id==="string"?d.id:"",necessary:true,'
            . 'statistics:d.statistics===true,marketing:d.marketing===true,personalisation:d.personalisation===true};}};';
    }

    /* ------------------------------------------------------------------
     * REST: consent-log (set/update/withdraw) and DELETE /consent
     * ------------------------------------------------------------------ */

    /**
     * Permission check for the consent endpoints: origin + rate limit.
     * No nonce: cached pages serve stale nonces.
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public function check_consent_permission($request) {
        return TrackWP_Request_Guard::permission('consent', self::RATE_LIMIT);
    }

    /**
     * Pass-through sanitize: keeps raw JSON types so booleans are only true
     * when the client sent JSON true (rest_sanitize_boolean would accept
     * "true", "1" and 1).
     *
     * @param mixed $value Value.
     * @return mixed
     */
    public static function passthrough($value) {
        return $value;
    }

    public function register_consent_log_route() {
        $raw = array('sanitize_callback' => array(__CLASS__, 'passthrough'), 'validate_callback' => '__return_true');
        register_rest_route('trackwp/v1', '/consent-log', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_consent_log'),
            'permission_callback' => array($this, 'check_consent_permission'),
            'args'                => array(
                'statistics'      => $raw,
                'marketing'       => $raw,
                'personalisation' => $raw,
                'consent_id'      => $raw,
                'consent_version' => $raw,
                'ts'              => $raw,
                'ts_ms'           => $raw,
                'event_type'      => $raw,
                'banner_hash'     => $raw,
            ),
        ));
    }

    /**
     * DELETE /trackwp/v1/consent, kept for compatibility (R5). Accepts the
     * fields in the query string and/or the body; always a full withdraw.
     */
    public function register_consent_withdraw_route() {
        register_rest_route('trackwp/v1', '/consent', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'handle_consent_withdraw'),
            'permission_callback' => array($this, 'check_consent_permission'),
        ));
    }

    /**
     * POST /trackwp/v1/consent-log with event_type set|update|withdraw (K4, R5).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_consent_log($request) {
        $result = $this->process_consent_action($request, null);
        return $this->respond($result, 'ok');
    }

    /**
     * DELETE /trackwp/v1/consent: full withdraw. Does NOT delete the consent
     * cookie; it is rewritten as a rejection (same ts rule as consent-log).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_consent_withdraw($request) {
        $result = $this->process_consent_action($request, 'withdraw');
        return $this->respond($result, 'withdrawn');
    }

    /**
     * Shared implementation of a consent action.
     *
     * Steps: read raw fields -> count stats once -> ts rule (R7) -> when the
     * request is not older than the newest known choice: expire tracking
     * cookies for categories that are now false and Set-Cookie the choice
     * built from the body (R8) -> log row (when log_consent is on).
     *
     * @param WP_REST_Request $request      Request.
     * @param string|null     $forced_event 'withdraw' for DELETE, else null.
     * @return array {consent_id, cookie_written:bool, accepted:bool, stale:bool}
     */
    public function process_consent_action($request, $forced_event) {
        $fields = self::request_fields($request);
        $config = get_option('trackwp_consent', array());

        $event_type = $forced_event;
        if (null === $event_type) {
            $event_type = isset($fields['event_type']) && is_string($fields['event_type'])
                && in_array($fields['event_type'], TrackWP_Consent_Log::EVENT_TYPES, true)
                ? $fields['event_type'] : 'set';
        }

        if ('withdraw' === $event_type) {
            $statistics = $marketing = $personalisation = false;
        } else {
            $statistics      = isset($fields['statistics']) && true === $fields['statistics'];
            $marketing       = isset($fields['marketing']) && true === $fields['marketing'];
            $personalisation = isset($fields['personalisation']) && true === $fields['personalisation'];
        }

        // consent_id: body, then the existing cookie, then a new one.
        $consent_id = isset($fields['consent_id']) && is_string($fields['consent_id']) && self::is_uuid($fields['consent_id'])
            ? strtolower($fields['consent_id']) : '';
        $existing = self::decode_cookie();
        if ('' === $consent_id && is_array($existing) && isset($existing['id']) && is_string($existing['id']) && self::is_uuid($existing['id'])) {
            $consent_id = strtolower($existing['id']);
        }
        if ('' === $consent_id) {
            $consent_id = wp_generate_uuid4();
        }

        $consent_version = self::int_field($fields, 'consent_version');
        $server_version  = self::server_version();

        // ts in ms (R7): ts_ms, else ISO ts parsed with DateTime.
        $body_ts_ms = self::int_field($fields, 'ts_ms');
        if (null === $body_ts_ms && isset($fields['ts']) && is_string($fields['ts'])) {
            $body_ts_ms = self::ts_to_ms($fields['ts']);
        }
        $has_body_ts = null !== $body_ts_ms;
        $now_ms      = (int) floor(microtime(true) * 1000);
        $ts_ms       = $has_body_ts ? $body_ts_ms : $now_ms;
        // A client clock (or a forged body) far in the future would win every
        // later ts comparison and pin the choice; clamp to now + 5 minutes.
        // Older values are accepted unchanged.
        $ts_ms = min($ts_ms, $now_ms + self::MAX_FUTURE_SKEW_MS);

        // Stats exactly once per action.
        if (class_exists('TrackWP_Settings') && method_exists('TrackWP_Settings', 'record_stat')) {
            $accepted_any = $statistics || $marketing || $personalisation;
            TrackWP_Settings::record_stat($accepted_any && 'withdraw' !== $event_type ? 'consent_accept' : 'consent_reject');
        }

        // ts rule: body ts must be >= max(cookie ts, newest stored ts for this id).
        $cookie_ts_ms = (is_array($existing) && isset($existing['ts']) && is_string($existing['ts'])) ? self::ts_to_ms($existing['ts']) : null;
        $stored       = get_transient(self::TS_TRANSIENT_PREFIX . $consent_id);
        $stored_ms    = (false !== $stored && is_numeric($stored)) ? (int) $stored : null;
        $newest       = max(null === $cookie_ts_ms ? PHP_INT_MIN : $cookie_ts_ms, null === $stored_ms ? PHP_INT_MIN : $stored_ms);
        $accepted     = $ts_ms >= $newest;
        set_transient(self::TS_TRANSIENT_PREFIX . $consent_id, (string) max($ts_ms, null === $stored_ms ? $ts_ms : $stored_ms), HOUR_IN_SECONDS);

        $stale          = null !== $consent_version && $consent_version !== $server_version;
        $cookie_written = false;
        if ($accepted) {
            TrackWP_Cookies::expire_tracking_cookies(array('analytics' => $statistics, 'marketing' => $marketing));
            if ($has_body_ts && null !== $consent_version && !$stale) {
                $cookie_written = self::write_consent_cookie($consent_version, $ts_ms, $consent_id, $statistics, $marketing, $personalisation);
            }
        }

        if (!empty($config['log_consent'])) {
            $banner_hash = isset($fields['banner_hash']) && is_string($fields['banner_hash']) ? strtolower($fields['banner_hash']) : '';
            if (!preg_match('/^[a-f0-9]{64}$/', $banner_hash)) {
                $banner_hash = '';
            }
            $ip = TrackWP_Request_Guard::client_ip();
            TrackWP_Consent_Log::insert(array(
                'consent_id'             => $consent_id,
                'created_at'             => gmdate('Y-m-d H:i:s'),
                'event_type'             => $event_type,
                'statistics'             => $statistics,
                'marketing'              => $marketing,
                'personalisation'        => $personalisation,
                'consent_version'        => null === $consent_version ? 0 : $consent_version,
                'server_consent_version' => $server_version,
                'banner_hash'            => $banner_hash,
                'ip_hash'                => '' !== $ip ? hash('sha256', $ip . wp_salt()) : '',
                'user_agent'             => isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : '',
                'page_url'               => self::same_host_referer(),
                'source'                 => 'client',
            ));
            if ('' !== $banner_hash) {
                self::maybe_snapshot_texts($banner_hash);
            }
        }

        return array(
            'consent_id'     => $consent_id,
            'cookie_written' => $cookie_written,
            'accepted'       => $accepted,
            'stale'          => $stale,
        );
    }

    /**
     * Build and send the consent cookie from the request body (R8):
     * {v, ts, id, necessary:true, statistics, marketing, personalisation}.
     * Host-only, Path=/, SameSite=Lax, Secure on https, not HttpOnly,
     * expiring lifetime_days('trackwp_consent') after ts.
     *
     * @return bool
     */
    public static function write_consent_cookie($version, $ts_ms, $consent_id, $statistics, $marketing, $personalisation) {
        $value = wp_json_encode(array(
            'v'               => (int) $version,
            'ts'              => self::ms_to_iso($ts_ms),
            'id'              => (string) $consent_id,
            'necessary'       => true,
            'statistics'      => (bool) $statistics,
            'marketing'       => (bool) $marketing,
            'personalisation' => (bool) $personalisation,
        ));
        $expires = (int) floor($ts_ms / 1000) + TrackWP_Cookies::lifetime_days(self::COOKIE) * DAY_IN_SECONDS;
        return TrackWP_Cookies::set(self::COOKIE, $value, $expires, 'host');
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Parse an ISO-8601 timestamp to integer milliseconds (UTC).
     *
     * @param string $iso Timestamp.
     * @return int|null
     */
    public static function ts_to_ms($iso) {
        if (!is_string($iso) || '' === $iso || strlen($iso) > 40) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
        return $dt->getTimestamp() * 1000 + (int) $dt->format('v');
    }

    /**
     * Milliseconds to ISO-8601 UTC with milliseconds (the format of JS toISOString()).
     *
     * @param int $ms Milliseconds.
     * @return string
     */
    public static function ms_to_iso($ms) {
        $ms  = (int) $ms;
        $sec = intdiv($ms, 1000);
        $rem = $ms - $sec * 1000;
        if ($rem < 0) {
            $sec -= 1;
            $rem += 1000;
        }
        return gmdate('Y-m-d\TH:i:s', $sec) . '.' . str_pad((string) $rem, 3, '0', STR_PAD_LEFT) . 'Z';
    }

    /**
     * UUID (any version) check.
     *
     * @param string $id Candidate.
     * @return bool
     */
    public static function is_uuid($id) {
        return is_string($id) && (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id);
    }

    /**
     * Raw fields from query, form body and JSON body (JSON wins). Values are
     * NOT sanitized, so JSON booleans keep their type.
     *
     * @param WP_REST_Request $request Request.
     * @return array
     */
    private static function request_fields($request) {
        $query = $request->get_query_params();
        $body  = $request->get_body_params();
        $json  = $request->get_json_params();
        return array_merge(
            is_array($query) ? $query : array(),
            is_array($body) ? $body : array(),
            is_array($json) ? $json : array()
        );
    }

    /**
     * Integer field: JSON integer, or a digit string (query parameters).
     *
     * @param array  $fields Fields.
     * @param string $key    Key.
     * @return int|null
     */
    private static function int_field($fields, $key) {
        if (!isset($fields[ $key ])) {
            return null;
        }
        $v = $fields[ $key ];
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && '' !== $v && strlen($v) <= 18 && ctype_digit($v)) {
            return (int) $v;
        }
        return null;
    }

    /**
     * Same-host Referer (the page where the choice was made), cleaned (K6).
     *
     * @return string
     */
    private static function same_host_referer() {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
        if ('' === $referer || wp_parse_url($referer, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return '';
        }
        return TrackWP_Consent_Log::clean_page_url($referer);
    }

    /**
     * Store an immutable snapshot of the banner texts for banner_hash, but
     * only when the hash equals TrackWP_Consent_Profile::banner_hash() right
     * now (the hash of what is rendered today). A cached page with an older
     * hash cannot be reconstructed here, so nothing is stored for it.
     *
     * @param string $banner_hash Hash sent by the client.
     * @return void
     */
    private static function maybe_snapshot_texts($banner_hash) {
        if (!class_exists('TrackWP_Consent_Profile')
            || !is_callable(array('TrackWP_Consent_Profile', 'banner_hash'))
            || !is_callable(array('TrackWP_Consent_Profile', 'banner_texts'))) {
            return;
        }
        try {
            $current = (string) TrackWP_Consent_Profile::banner_hash();
            if ('' === $current || !hash_equals($current, $banner_hash)) {
                return;
            }
            $content = array(
                'consent_version' => self::server_version(),
                'banner_texts'    => TrackWP_Consent_Profile::banner_texts(),
            );
            TrackWP_Consent_Log::snapshot_texts($banner_hash, wp_json_encode($content));
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Response for both consent endpoints: no-store, {status, consent_id}.
     * A version other than the server's adds consent=stale_version and
     * current_version (R4 shape).
     *
     * @param array  $result Result of process_consent_action().
     * @param string $status Status string.
     * @return WP_REST_Response
     */
    private function respond($result, $status) {
        $data = array('status' => $status, 'consent_id' => $result['consent_id']);
        if (!empty($result['stale'])) {
            $data['consent']         = 'stale_version';
            $data['current_version'] = self::server_version();
        }
        $response = new WP_REST_Response($data, 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        return $response;
    }
}
