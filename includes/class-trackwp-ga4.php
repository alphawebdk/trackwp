<?php
defined('ABSPATH') || exit;

class TrackWP_GA4 {

    private $config;
    private $advanced;

    public function __construct() {
        $this->config   = get_option('trackwp_platforms', array());
        $this->advanced = get_option('trackwp_advanced', array());
    }

    /**
     * Check if GA4 is enabled with valid credentials.
     */
    public function is_enabled() {
        return !empty($this->config['ga4_enabled'])
            && !empty($this->config['ga4_measurement_id'])
            && !empty($this->config['ga4_api_secret']);
    }

    /**
     * Validate a GA4 client_id format.
     * Accepts either GA1.<n>.<n>.<n> or <n>.<n>.
     *
     * @param string $cid
     * @return bool
     */
    private function validate_client_id($cid) {
        if (!is_string($cid) || $cid === '') {
            return false;
        }
        if (preg_match('/^GA1\.\d+\.\d+\.\d+$/', $cid)) {
            return true;
        }
        if (preg_match('/^\d+\.\d+$/', $cid)) {
            return true;
        }
        return false;
    }

    /**
     * Generate a synthetic, MP-valid client_id (<random>.<timestamp>).
     *
     * @return string
     */
    private function generate_synthetic_client_id() {
        return wp_rand(100000000, 999999999) . '.' . time();
    }

    /**
     * Resolve a valid client_id for an event.
     *
     * Order: payload client_id if valid -> ga_cookie (_ga value, GA1.x.x.x
     * format) if valid -> synthetic valid id. GA4 silently drops events with
     * an invalid client_id, so a valid synthetic id beats a discarded event.
     *
     * @param array $event_data
     * @return string
     */
    private function resolve_client_id($event_data) {
        $cid = isset($event_data['client_id']) ? $event_data['client_id'] : '';
        if ($this->validate_client_id($cid)) {
            return $cid;
        }

        $ga_cookie = isset($event_data['ga_cookie']) ? $event_data['ga_cookie'] : '';
        if ($this->validate_client_id($ga_cookie)) {
            if (!empty($this->advanced['capi_debug_logging_enabled'])) {
                $this->log_capi_error('Invalid client_id format: ' . $cid . ' -- using ga_cookie fallback', '');
            }
            return $ga_cookie;
        }

        $synthetic = $this->generate_synthetic_client_id();
        if (!empty($this->advanced['capi_debug_logging_enabled'])) {
            $this->log_capi_error('Invalid client_id format: ' . $cid . ' -- using synthetic ' . $synthetic, '');
        }
        return $synthetic;
    }

    /**
     * Validate a GA4 Measurement ID format (G- followed by 4-12 alphanumerics,
     * matching the frontend validation).
     *
     * @param string $measurement_id
     * @return bool
     */
    private function is_valid_mp_id($measurement_id) {
        return is_string($measurement_id) && (bool) preg_match('/^G-[A-Z0-9]{4,12}$/', $measurement_id);
    }

    /**
     * Parse a GA4 session cookie value and return the session_id.
     *
     * Supports both cookie formats:
     *  - GS1: GS1.1.<session_id>.<count>... (bare numeric third segment)
     *  - GS2: GS2.1.s<session_id>$o<n>$g<n>... (rolled out May 2025; the
     *    third dot-segment is a $-separated field list, session id after 's')
     *
     * @param string $cookie_value
     * @return string Empty string if not parseable.
     */
    private function parse_session_cookie_value($cookie_value) {
        if (!is_string($cookie_value) || $cookie_value === '') {
            return '';
        }
        $parts = explode('.', $cookie_value);
        if (!isset($parts[2]) || $parts[2] === '') {
            return '';
        }
        // GS1: third dot-segment is the bare numeric session id.
        if (ctype_digit($parts[2])) {
            return (string) $parts[2];
        }
        // GS2 (rolled out May 2025): third dot-segment is a $-separated field list,
        // e.g. "s1747323152$o28$g0$..." — the session id is the digits after 's'.
        if (preg_match('/^s(\d+)/', $parts[2], $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Derive a GA4 session_id from the event payload.
     *
     * Resolution order:
     *  1. payload.ga_session_cookies (array of {id, value}) -- match by stripped measurement_id;
     *     fall back to first entry if no match.
     *  2. payload.ga_session_cookie (legacy single-cookie string).
     *  3. payload.session_id (final fallback) -- only if purely numeric;
     *     the MP `session_id` param must be numeric, so non-numeric values
     *     (e.g. legacy "ses_<hex>" client format) are rejected.
     *
     * @param array $event_data
     * @return string
     */
    private function derive_session_id($event_data) {
        // 1) New contract: array of cookies.
        if (!empty($event_data['ga_session_cookies']) && is_array($event_data['ga_session_cookies'])) {
            $cookies = $event_data['ga_session_cookies'];
            $mp_id   = isset($this->config['ga4_measurement_id']) ? $this->config['ga4_measurement_id'] : '';

            if ($this->is_valid_mp_id($mp_id)) {
                $target = substr($mp_id, 2); // strip "G-" prefix
                foreach ($cookies as $entry) {
                    if (is_array($entry) && isset($entry['id'], $entry['value']) && $entry['id'] === $target) {
                        $sid = $this->parse_session_cookie_value($entry['value']);
                        if ($sid !== '') {
                            return $sid;
                        }
                    }
                }
            }

            // Fallback: first entry with a parseable value (miskonfigureret case).
            foreach ($cookies as $entry) {
                if (is_array($entry) && isset($entry['value'])) {
                    $sid = $this->parse_session_cookie_value($entry['value']);
                    if ($sid !== '') {
                        return $sid;
                    }
                }
            }
        }

        // 2) Backward compat: legacy single-cookie string.
        if (!empty($event_data['ga_session_cookie']) && is_string($event_data['ga_session_cookie'])) {
            $sid = $this->parse_session_cookie_value($event_data['ga_session_cookie']);
            if ($sid !== '') {
                return $sid;
            }
        }

        // 3) Final fallback: only accept purely numeric values (GA4 requirement).
        if (isset($event_data['session_id']) && ctype_digit((string) $event_data['session_id'])) {
            return (string) $event_data['session_id'];
        }
        return '';
    }

    /**
     * Append a line to the CAPI error log when debug logging is enabled.
     *
     * @param string $message
     * @param int|string $http_code
     * @return void
     */
    private function log_capi_error($message, $http_code = '') {
        if (empty($this->advanced['capi_debug_logging_enabled'])) {
            return;
        }
        $log_file = trailingslashit(WP_CONTENT_DIR) . 'trackwp/capi-errors.log';
        // ensure_log_dir also drops .htaccess/index.html protection into the dir.
        if (method_exists('TrackWP_Settings', 'ensure_log_dir')) {
            TrackWP_Settings::ensure_log_dir(dirname($log_file));
        } else {
            wp_mkdir_p(dirname($log_file));
        }
        $entry = sprintf(
            "[%s] [GA4] %s (http_code: %s)\n",
            gmdate('c'),
            $message,
            $http_code
        );
        error_log($entry, 3, $log_file);
    }

    /**
     * Max attempts per request (K1).
     */
    const MAX_ATTEMPTS = 2;

    /**
     * Default budgets in seconds: public request (K1) and cron flush per batch.
     */
    const DEFAULT_BUDGET = 4.0;
    const CRON_BUDGET    = 10.0;

    /**
     * GA4 MP limits: 25 events per request, body below 130 kB.
     * https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events#limitations
     */
    const BATCH_MAX_EVENTS = 25;
    const BATCH_MAX_BYTES  = 130000;

    /**
     * Failed batches are re-queued up to this age. GA4 accepts
     * timestamp_micros up to 72 hours in the past.
     */
    const REQUEUE_MAX_AGE = 259200;

    /**
     * Queue transient TTL (must outlive REQUEUE_MAX_AGE).
     */
    const QUEUE_TTL = 262800;

    /**
     * Build a K1 result array.
     */
    private static function result($status, $reason, $http_code = 0, $attempts = 0, $detail = '') {
        return array(
            'destination' => 'ga4',
            'status'      => $status,
            'reason'      => $reason,
            'http_code'   => (int) $http_code,
            'attempts'    => (int) $attempts,
            'detail'      => substr((string) $detail, 0, 500),
        );
    }

    /**
     * MP collect URL, or '' when the configuration is unusable.
     *
     * @return string
     */
    private function collect_url() {
        $measurement_id = isset($this->config['ga4_measurement_id']) ? $this->config['ga4_measurement_id'] : '';
        if (!$this->is_valid_mp_id($measurement_id)) {
            $this->log_capi_error('mp_skipped: measurement_id must be G- format', '');
            return '';
        }
        return add_query_arg(array(
            'measurement_id' => $measurement_id,
            'api_secret'     => TrackWP_Hash::decode($this->config['ga4_api_secret']),
        ), 'https://www.google-analytics.com/mp/collect');
    }

    /**
     * Dispatch one MP request inside the deadline.
     *
     * Blocking request (non-blocking cURL aborts before TLS completes).
     * GA4 MP has no server-side dedup, so a retry is only made when the
     * request provably did not count:
     * - HTTP 5xx and 429: retried (Retry-After honoured when it fits in the
     *   remaining budget, otherwise no retry).
     * - Connection errors (cURL 6/7, nothing was sent): one retry.
     * - Timeout (cURL 28) and other transport errors: `unknown`, no retry.
     * Max MAX_ATTEMPTS attempts.
     *
     * @param string $url
     * @param array  $body
     * @param float  $deadline microtime(true) deadline.
     * @return array K1 result.
     */
    private function dispatch($url, $body, $deadline) {
        $attempts = 0;
        $result   = self::result('failed', 'transport', 0, 0, 'budget_exhausted');
        $json     = wp_json_encode($body);

        while ($attempts < self::MAX_ATTEMPTS) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 0.2) {
                break;
            }
            $attempts++;
            $response = wp_remote_post($url, array(
                'timeout'  => $remaining,
                'blocking' => true,
                'headers'  => array('Content-Type' => 'application/json'),
                'body'     => $json,
            ));

            if (is_wp_error($response)) {
                $message = $response->get_error_message();
                $errno   = preg_match('/cURL error (\d+)/i', $message, $m) ? (int) $m[1] : (stripos($message, 'timed out') !== false ? 28 : 0);
                if ($errno === 6 || $errno === 7) {
                    $result = self::result('failed', 'transport', 0, $attempts, 'curl ' . $errno);
                    continue;
                }
                $result = ($errno === 28)
                    ? self::result('unknown', 'timeout', 0, $attempts, 'curl 28')
                    : self::result('unknown', 'transport', 0, $attempts, $errno ? 'curl ' . $errno : 'transport error');
                break;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                return self::result('ok', 'sent', $code, $attempts);
            }
            if ($code === 429 || $code >= 500) {
                $result = self::result('failed', $code === 429 ? 'http_429' : 'http_5xx', $code, $attempts, 'HTTP ' . $code);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    break;
                }
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                $wait        = (is_string($retry_after) && ctype_digit(trim($retry_after))) ? (float) trim($retry_after) : 0.2;
                if (microtime(true) + $wait + 0.2 >= $deadline) {
                    $result['detail'] = 'HTTP ' . $code . ' retry-after exceeds budget';
                    break;
                }
                usleep((int) ($wait * 1000000));
                continue;
            }
            $result = self::result('failed', 'http_4xx', $code, $attempts, 'HTTP ' . $code);
            break;
        }

        if ($result['status'] !== 'ok') {
            $this->log_capi_error($result['status'] . '/' . $result['reason'] . ' attempts=' . $result['attempts'] . ' ' . $result['detail'], $result['http_code']);
        }
        return $result;
    }

    /**
     * Read a consent flag from the effective consent in event_data. The
     * proxy always sets $event_data['consent'] to the K3 result, so this is
     * the only source: a missing or non-true value means false. Queued
     * events carry their own snapshot (_consent_*), so the cron flush does
     * not need a cookie.
     *
     * @param array  $event_data
     * @param string $key
     * @return bool
     */
    private static function consent_flag($event_data, $key) {
        if (isset($event_data['consent']) && is_array($event_data['consent']) && array_key_exists($key, $event_data['consent'])) {
            return $event_data['consent'][$key] === true;
        }
        return false;
    }

    /**
     * Snapshot request-time context onto the event, so a queued event is
     * built exactly like a direct one (the cron flush has no cookies, no
     * user and no client IP). Idempotent.
     *
     * Keys: _consent_marketing, _consent_analytics, _user_id,
     * _user_properties, _user_data, _ip_override, _user_agent.
     * `enhanced` is replaced by the mapped _user_data (or dropped), so no
     * more customer data than needed is stored in the queue.
     *
     * @param array $event_data
     * @return array
     */
    private function snapshot($event_data) {
        if (!empty($event_data['_snapshot'])) {
            return $event_data;
        }
        $marketing = self::consent_flag($event_data, 'marketing');
        $analytics = self::consent_flag($event_data, 'analytics');

        $event_data['_consent_marketing'] = $marketing;
        $event_data['_consent_analytics'] = $analytics;

        $event_data['_user_data'] = array();
        if ($marketing && TrackWP_Hash::customer_data_sharing_enabled()
            && !empty($event_data['enhanced']) && is_array($event_data['enhanced'])) {
            $event_data['_user_data'] = $this->build_mp_user_data($event_data['enhanced']);
        }
        unset($event_data['enhanced']);

        $event_data['_user_id'] = '';
        if (!empty($this->advanced['ga4_user_id_enabled']) && class_exists('TrackWP_Request_Guard')) {
            $uid = (int) TrackWP_Request_Guard::current_user_id();
            if ($uid > 0) {
                $event_data['_user_id']         = hash('sha256', $uid . ':' . get_site_url());
                $event_data['_user_properties'] = array('logged_in' => array('value' => 'true'));
            }
        }

        $event_data['_ip_override'] = '';
        $event_data['_user_agent']  = '';
        if ($analytics) {
            $ip = class_exists('TrackWP_Request_Guard') ? (string) TrackWP_Request_Guard::client_ip() : '';
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $event_data['_ip_override'] = $ip;
            }
            if (!empty($event_data['user_agent']) && is_string($event_data['user_agent'])) {
                $event_data['_user_agent'] = substr($event_data['user_agent'], 0, 500);
            }
        }

        $event_data['_snapshot'] = 1;
        return $event_data;
    }

    /**
     * Send an event via GA4 Measurement Protocol.
     *
     * @param array      $event_data Proxy event data (K2) incl. effective `consent`.
     * @param float|null $budget     Remaining request budget in seconds (K1).
     * @return array K1 result.
     */
    public function send_event($event_data, $budget = null) {
        if (!$this->is_enabled()) {
            return self::result('skipped', 'not_configured');
        }
        $url = $this->collect_url();
        if ($url === '') {
            return self::result('skipped', 'not_configured', 0, 0, 'invalid measurement_id');
        }

        if (!empty($this->advanced['batching_enabled'])) {
            $this->queue_event($event_data);
            return self::result('queued', 'batched');
        }

        $event_data = $this->snapshot($event_data);
        $client_id  = $this->resolve_client_id($event_data);
        $body       = $this->build_body($event_data, $client_id, false);

        $deadline = microtime(true) + (($budget === null) ? self::DEFAULT_BUDGET : max(0.0, (float) $budget));
        return $this->dispatch($url, $body, $deadline);
    }

    /**
     * Event names GA4 treats as monetary transactions.
     *
     * @return array
     */
    private static function transaction_events() {
        return array('purchase', 'refund');
    }

    /**
     * Normalise a currency to the ISO 4217 shape GA4 expects (3 letters).
     *
     * @param string $currency
     * @return string
     */
    private static function normalize_currency($currency) {
        $clean = preg_replace('/[^A-Za-z]/', '', (string) $currency);
        if (strlen((string) $clean) < 3) {
            return 'DKK';
        }
        return strtoupper(substr($clean, 0, 3));
    }

    /**
     * Build a single-event GA4 MP body from snapshotted event data.
     *
     * Request level: client_id, consent, user_data, user_id,
     * user_properties, ip_override and user_agent (analytics only). No
     * `device`: a partial device object makes GA4 ignore user_agent
     * (BESLUTNINGER §7.5).
     *
     * @param array  $event_data Output of snapshot().
     * @param string $client_id
     * @param bool   $with_timestamp Add timestamp_micros from _queued_at_us.
     * @return array
     */
    public function build_body($event_data, $client_id, $with_timestamp = false) {
        $event_data = $this->snapshot($event_data);
        $event_name = isset($event_data['event']) ? $event_data['event'] : '';

        $params = array(
            'page_location' => isset($event_data['page_url']) ? (string) $event_data['page_url'] : '',
            'page_title'    => isset($event_data['page_title']) ? (string) $event_data['page_title'] : '',
        );
        if (!empty($event_data['page_referrer']) && is_string($event_data['page_referrer'])) {
            $params['page_referrer'] = $event_data['page_referrer'];
        }

        // Measured engagement only (K8): omitted when 0 or missing.
        if (isset($event_data['engaged_ms']) && (int) $event_data['engaged_ms'] > 0) {
            $params['engagement_time_msec'] = min(3600000, (int) $event_data['engaged_ms']);
        }

        $ecommerce = (isset($event_data['ecommerce']) && is_array($event_data['ecommerce']))
            ? $event_data['ecommerce']
            : array();

        // R15: GA4 value = value_ga4 (net item value) when present, with tax
        // and shipping as separate params. GA4 requires value AND currency on
        // transaction events, also when the amount is 0.
        $is_transaction = in_array($event_name, self::transaction_events(), true);
        $has_ga4_value  = isset($ecommerce['value_ga4']) && is_numeric($ecommerce['value_ga4']);
        if ($has_ga4_value || !empty($event_data['value']) || $is_transaction) {
            $params['value']    = $has_ga4_value
                ? floatval($ecommerce['value_ga4'])
                : floatval(isset($event_data['value']) ? $event_data['value'] : 0);
            $params['currency'] = self::normalize_currency(isset($event_data['currency']) ? $event_data['currency'] : '');
        }
        foreach (array('tax', 'shipping') as $money_key) {
            if (isset($ecommerce[$money_key]) && is_numeric($ecommerce[$money_key])) {
                $params[$money_key] = floatval($ecommerce[$money_key]);
            }
        }

        $session_id = $this->derive_session_id($event_data);
        if ($session_id !== '') {
            $params['session_id'] = $session_id;
        }

        if (!empty($event_data['event_id'])) {
            $params['event_id'] = $event_data['event_id'];
        }
        foreach (array('form_id', 'form_name') as $form_key) {
            if (!empty($event_data[$form_key]) && is_string($event_data[$form_key])) {
                $params[$form_key] = $event_data[$form_key];
            }
        }

        // transaction_id is independent of items (GA4 purchase dedup).
        if (!empty($ecommerce['items'])) {
            $params['items'] = $ecommerce['items'];
        }
        if (!empty($ecommerce['transaction_id'])) {
            $params['transaction_id'] = $ecommerce['transaction_id'];
        }
        if (!empty($ecommerce['coupon'])) {
            $params['coupon'] = $ecommerce['coupon'];
        }

        $event = array(
            'name'   => $event_name,
            'params' => $params,
        );
        if ($with_timestamp && !empty($event_data['_queued_at_us'])) {
            $event['timestamp_micros'] = (int) $event_data['_queued_at_us'];
        }

        $body = array(
            'client_id' => $client_id,
            'events'    => array($event),
        );

        $marketing       = !empty($event_data['_consent_marketing']);
        $body['consent'] = array(
            'ad_user_data'       => $marketing ? 'GRANTED' : 'DENIED',
            'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
        );

        if ($marketing && !empty($event_data['_user_data'])) {
            $body['user_data'] = $event_data['_user_data'];
        }
        if (!empty($event_data['_user_id'])) {
            $body['user_id']         = $event_data['_user_id'];
            $body['user_properties'] = !empty($event_data['_user_properties'])
                ? $event_data['_user_properties']
                : array('logged_in' => array('value' => 'true'));
        }
        if (!empty($event_data['_ip_override'])) {
            $body['ip_override'] = $event_data['_ip_override'];
        }
        if (!empty($event_data['_user_agent'])) {
            $body['user_agent'] = $event_data['_user_agent'];
        }

        return $body;
    }

    /**
     * Map normalized enhanced-conversion hashes to GA4 MP user_data keys
     * (Google normalization only).
     *
     * @param array $enhanced Output of TrackWP_Hash::normalize_enhanced().
     * @return array Empty array when no mappable field exists.
     */
    private function build_mp_user_data($enhanced) {
        $user_data = array();

        if (!empty($enhanced['email_sha256'])) {
            $user_data['sha256_email_address'] = $enhanced['email_sha256'];
        }
        if (!empty($enhanced['phone_e164_sha256'])) {
            $user_data['sha256_phone_number'] = $enhanced['phone_e164_sha256'];
        }

        $address = array();
        if (!empty($enhanced['first_name_sha256'])) {
            $address['sha256_first_name'] = $enhanced['first_name_sha256'];
        }
        if (!empty($enhanced['last_name_sha256'])) {
            $address['sha256_last_name'] = $enhanced['last_name_sha256'];
        }
        if (!empty($address)) {
            $user_data['address'] = array($address);
        }

        return $user_data;
    }

    /**
     * Queue an event for batched dispatch (transient, flushed by cron).
     * The queue stays a transient without a DELETE claim in 1.10.1
     * (BESLUTNINGER §7.5); the outbox replaces it in 1.11.0.
     *
     * @param array $event_data
     * @return void
     */
    public function queue_event($event_data) {
        $event_data                  = $this->snapshot($event_data);
        $event_data['_queued_at']    = time();
        $event_data['_queued_at_us'] = (int) floor(microtime(true) * 1000000);

        $queue = get_transient('trackwp_ga4_queue');
        if (!is_array($queue)) {
            $queue = array();
        }
        $queue[] = $event_data;
        set_transient('trackwp_ga4_queue', $queue, self::QUEUE_TTL);

        // Flushing inline would exceed the public request budget (K1), so a
        // full queue is flushed by an immediate cron run instead.
        $delay = count($queue) >= self::BATCH_MAX_EVENTS ? 0 : 30;
        $next  = wp_next_scheduled('trackwp_flush_ga4');
        if (!$next || ($delay === 0 && $next > time())) {
            if ($next) {
                wp_unschedule_event($next, 'trackwp_flush_ga4');
            }
            wp_schedule_single_event(time() + $delay, 'trackwp_flush_ga4');
        }
    }

    /**
     * Request-level identity key for batching (R18): client_id, session_id,
     * user_id, user_properties, consent, user_data, ip_override and a hash
     * of user_agent. Events are only batched when all of these match.
     *
     * @param array $single Output of build_body() for one event.
     * @return string
     */
    public static function batch_key($single) {
        $params = isset($single['events'][0]['params']) ? $single['events'][0]['params'] : array();
        return md5(wp_json_encode(array(
            isset($single['client_id']) ? $single['client_id'] : '',
            isset($params['session_id']) ? $params['session_id'] : '',
            isset($single['user_id']) ? $single['user_id'] : '',
            isset($single['user_properties']) ? $single['user_properties'] : null,
            isset($single['consent']) ? $single['consent'] : null,
            isset($single['user_data']) ? $single['user_data'] : null,
            isset($single['ip_override']) ? $single['ip_override'] : '',
            isset($single['user_agent']) ? hash('sha256', $single['user_agent']) : '',
        )));
    }

    /**
     * Split queued events into MP request bodies (R18 key, max 25 events,
     * max 130 kB). Each returned item: array('body' => array, 'events' => array).
     *
     * @param array $queue
     * @return array
     */
    public function build_batches($queue) {
        $groups = array();
        foreach ($queue as $event_data) {
            if (!is_array($event_data)) {
                continue;
            }
            $cid    = $this->resolve_client_id($event_data);
            $single = $this->build_body($event_data, $cid, true);
            $key    = self::batch_key($single);
            if (!isset($groups[$key])) {
                $groups[$key] = array();
            }
            $groups[$key][] = array('single' => $single, 'event' => $event_data);
        }

        $batches = array();
        foreach ($groups as $items) {
            $current = null;
            foreach ($items as $item) {
                $candidate = $current;
                if ($candidate === null) {
                    $candidate = array('body' => $item['single'], 'events' => array($item['event']));
                } else {
                    $candidate['body']['events'][] = $item['single']['events'][0];
                    $candidate['events'][]         = $item['event'];
                }
                $too_many = count($candidate['body']['events']) > self::BATCH_MAX_EVENTS;
                $too_big  = strlen((string) wp_json_encode($candidate['body'])) > self::BATCH_MAX_BYTES;
                if ($current !== null && ($too_many || $too_big)) {
                    $batches[] = $current;
                    $current   = array('body' => $item['single'], 'events' => array($item['event']));
                } else {
                    $current = $candidate;
                }
            }
            if ($current !== null) {
                $batches[] = $current;
            }
        }
        return $batches;
    }

    /**
     * Flush the queued events (cron hook trackwp_flush_ga4).
     *
     * Config checks run BEFORE the transient is deleted, so a
     * misconfiguration does not discard events. Batches that failed with a
     * retryable outcome (5xx, 429, connection error) are re-queued up to
     * REQUEUE_MAX_AGE; `unknown` (timeout) is dropped to avoid double
     * counting; 4xx is dropped and logged.
     *
     * @return array List of K1 results, one per batch.
     */
    public function flush_queue() {
        $queue = get_transient('trackwp_ga4_queue');
        if (empty($queue) || !is_array($queue)) {
            delete_transient('trackwp_ga4_queue');
            return array();
        }
        if (!$this->is_enabled()) {
            return array();
        }
        $url = $this->collect_url();
        if ($url === '') {
            return array();
        }

        delete_transient('trackwp_ga4_queue');

        $results = array();
        $failed  = array();
        foreach ($this->build_batches($queue) as $batch) {
            $result    = $this->dispatch($url, $batch['body'], microtime(true) + self::CRON_BUDGET);
            $results[] = $result;
            $retryable = $result['status'] === 'failed'
                && in_array($result['reason'], array('http_5xx', 'http_429', 'transport'), true);
            if ($retryable) {
                $failed = array_merge($failed, $batch['events']);
            } elseif ($result['status'] === 'unknown') {
                $this->log_capi_error('batch outcome unknown (' . count($batch['events']) . ' events) -- dropped to avoid double counting', '');
            }
        }

        if (!empty($failed)) {
            $this->requeue_failed($failed);
        }
        return $results;
    }

    /**
     * Re-queue events from failed batches (max age 72 h) and re-schedule.
     *
     * @param array $events
     * @return void
     */
    private function requeue_failed($events) {
        $cutoff = time() - self::REQUEUE_MAX_AGE;
        $keep   = array();
        foreach ($events as $event_data) {
            $queued_at = isset($event_data['_queued_at']) ? (int) $event_data['_queued_at'] : 0;
            if ($queued_at >= $cutoff) {
                $keep[] = $event_data;
            }
        }
        if (empty($keep)) {
            return;
        }

        $queue = get_transient('trackwp_ga4_queue');
        if (!is_array($queue)) {
            $queue = array();
        }
        set_transient('trackwp_ga4_queue', array_merge($keep, $queue), self::QUEUE_TTL);

        if (!wp_next_scheduled('trackwp_flush_ga4')) {
            wp_schedule_single_event(time() + 60, 'trackwp_flush_ga4');
        }
    }
}
