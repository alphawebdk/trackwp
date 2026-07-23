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
     *     GA4 requires a numeric ga_session_id, so non-numeric values
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
     * Dispatch a request to GA4 MP.
     *
     * The request is always blocking with a short (2s) timeout. Non-blocking
     * fire-and-forget is intentionally not used: with WP's cURL transport the
     * request is aborted before the TLS handshake completes, so it never
     * reaches Google. The REST endpoint is called via async fetch from the
     * browser, so a short blocking request does not affect the user experience.
     *
     * Retries: 3 attempts (backoff 200ms / 600ms) when $blocking is true
     * (cron/flush context) or capi_debug_logging_enabled is set; otherwise a
     * single attempt so the REST response is not delayed unnecessarily.
     *
     * @param string    $url
     * @param array     $body
     * @param string    $context
     * @param bool|null $blocking True enables retries (cron/flush context); null = retry only in debug mode.
     * @return bool True on 2xx; false on failure.
     */
    private function dispatch_with_retry($url, $body, $context = 'ga4', $blocking = null) {
        $retry      = ($blocking === true) || !empty($this->advanced['capi_debug_logging_enabled']);
        $attempts   = $retry ? 3 : 1;
        $delays     = array(200000, 600000); // microseconds between attempts.
        $last_error = '';
        $last_code  = '';

        for ($i = 0; $i < $attempts; $i++) {
            $response = wp_remote_post($url, array(
                'timeout'  => 2,
                'blocking' => true,
                'headers'  => array('Content-Type' => 'application/json'),
                'body'     => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                $last_code  = '';
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code < 500) {
                    return ($code >= 200 && $code < 300);
                }
                $last_error = 'HTTP ' . $code;
                $last_code  = $code;
            }

            if ($i < $attempts - 1 && isset($delays[$i])) {
                usleep($delays[$i]);
            }
        }

        $this->log_capi_error($last_error, $last_code);
        return false;
    }

    /**
     * Send event via GA4 Measurement Protocol.
     *
     * @param array $event_data Keys: event, value, currency, page_url, page_title, client_id, session_id, ga_session_cookie, enhanced, ecommerce, event_id
     * @return bool True if dispatched (or queued).
     */
    public function send_event($event_data) {
        if (!$this->is_enabled()) return false;

        if (!empty($this->advanced['batching_enabled'])) {
            $this->queue_event($event_data);
            return true;
        }

        $client_id = $this->resolve_client_id($event_data);

        $measurement_id = $this->config['ga4_measurement_id'];

        // Pre-check: GA4 MP only accepts G- format measurement IDs.
        if (!$this->is_valid_mp_id($measurement_id)) {
            $this->log_capi_error('mp_skipped: measurement_id must be G- format, got: ' . (is_string($measurement_id) ? $measurement_id : gettype($measurement_id)), '');
            return false;
        }

        $api_secret     = TrackWP_Hash::decode($this->config['ga4_api_secret']);

        $url = add_query_arg(array(
            'measurement_id' => $measurement_id,
            'api_secret'     => $api_secret,
        ), 'https://www.google-analytics.com/mp/collect');

        $body = $this->build_body($event_data, $client_id);

        return $this->dispatch_with_retry($url, $body, 'ga4');
    }

    /**
     * Build a single-event GA4 MP body from event data.
     *
     * @param array  $event_data
     * @param string $client_id
     * @return array
     */
    private function build_body($event_data, $client_id) {
        $params = array(
            'page_location'        => isset($event_data['page_url']) ? $event_data['page_url'] : '',
            'page_title'           => isset($event_data['page_title']) ? $event_data['page_title'] : '',
            'engagement_time_msec' => 100,
        );

        if (!empty($event_data['value'])) {
            $params['value']    = floatval($event_data['value']);
            $params['currency'] = isset($event_data['currency']) ? $event_data['currency'] : 'DKK';
        }

        $session_id = $this->derive_session_id($event_data);
        if ($session_id !== '') {
            $params['ga_session_id'] = $session_id;
        }

        // Event ID for server↔client dedup.
        if (!empty($event_data['event_id'])) {
            $params['event_id'] = $event_data['event_id'];
        }

        // Ecommerce items
        if (!empty($event_data['ecommerce']['items'])) {
            $params['items'] = $event_data['ecommerce']['items'];
            if (!empty($event_data['ecommerce']['transaction_id'])) {
                $params['transaction_id'] = $event_data['ecommerce']['transaction_id'];
            }
        }

        $body = array(
            'client_id' => $client_id,
            'events'    => array(
                array(
                    'name'   => isset($event_data['event']) ? $event_data['event'] : '',
                    'params' => $params,
                ),
            ),
        );

        // Consent Mode v2 signals.
        // Prefer the queue-time snapshot, then the POSTed payload consent,
        // and only fall back to the live cookie lookup (cron has no cookies).
        // Computed before user_data so hashed PII can be gated on it below.
        $marketing = null;
        if (array_key_exists('_consent_marketing', $event_data)) {
            $marketing = !empty($event_data['_consent_marketing']);
        } elseif (isset($event_data['consent']) && is_array($event_data['consent']) && array_key_exists('marketing', $event_data['consent'])) {
            $marketing = !empty($event_data['consent']['marketing']);
        } elseif (class_exists('TrackWP_Consent')) {
            $consent   = TrackWP_Consent::get_current_consent();
            $marketing = is_array($consent) && !empty($consent['marketing']);
        }
        if ($marketing !== null) {
            $body['consent'] = array(
                'ad_user_data'      => $marketing ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
            );
        }

        // Enhanced conversions user data (GA4 MP field names).
        // user_data (hashed PII) requires ad_user_data consent — omit entirely when marketing is denied.
        if ($marketing === true && !empty($event_data['enhanced']) && is_array($event_data['enhanced'])) {
            $user_data = $this->build_mp_user_data($event_data['enhanced']);
            if (!empty($user_data)) {
                $body['user_data'] = $user_data;
            }
        }

        // user_id + user_properties for logged-in users.
        // Prefer the queue-time snapshot (cron has no logged-in user).
        if (!empty($event_data['_user_id'])) {
            $body['user_id']         = $event_data['_user_id'];
            $body['user_properties'] = !empty($event_data['_user_properties'])
                ? $event_data['_user_properties']
                : array('logged_in' => array('value' => 'true'));
        } elseif (!empty($this->advanced['ga4_user_id_enabled']) && function_exists('is_user_logged_in') && is_user_logged_in()) {
            $user_id_hash         = hash('sha256', get_current_user_id() . ':' . get_site_url());
            $body['user_id']      = $user_id_hash;
            $body['user_properties'] = array(
                'logged_in' => array('value' => 'true'),
            );
        }

        return $body;
    }

    /**
     * Map normalized enhanced-conversion hashes to GA4 MP user_data keys.
     *
     * GA4 MP requires: sha256_email_address, sha256_phone_number (E.164-hash)
     * and address[] with sha256_first_name / sha256_last_name. City, zip and
     * country hashes from normalize_enhanced() are omitted: GA4 expects those
     * address fields in plaintext, and only hashed values are available.
     *
     * @param array $enhanced Output of TrackWP_Hash::normalize_enhanced().
     * @return array Empty array when no mappable field exists.
     */
    private function build_mp_user_data($enhanced) {
        $user_data = array();

        if (!empty($enhanced['email_sha256'])) {
            $user_data['sha256_email_address'] = $enhanced['email_sha256'];
        }

        // Google requires the E.164 hash; omit phone entirely if it is missing.
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
     * Queue an event for batched dispatch.
     *
     * Snapshots request-time context (consent + logged-in user) onto the
     * event: the queue is flushed from WP-Cron where no cookies/user exist,
     * so evaluating those at flush time would always yield DENIED / no user.
     *
     * @param array $event_data
     * @return void
     */
    public function queue_event($event_data) {
        // Consent snapshot: prefer the POSTed payload consent, fall back to
        // the live cookie lookup (still available at queue time).
        if (!array_key_exists('_consent_marketing', $event_data)) {
            if (isset($event_data['consent']) && is_array($event_data['consent']) && array_key_exists('marketing', $event_data['consent'])) {
                $event_data['_consent_marketing'] = !empty($event_data['consent']['marketing']);
            } elseif (class_exists('TrackWP_Consent')) {
                $consent = TrackWP_Consent::get_current_consent();
                $event_data['_consent_marketing'] = is_array($consent) && !empty($consent['marketing']);
            }
        }

        // user_id snapshot for logged-in users.
        if (empty($event_data['_user_id'])
            && !empty($this->advanced['ga4_user_id_enabled'])
            && function_exists('is_user_logged_in') && is_user_logged_in()) {
            $event_data['_user_id']         = hash('sha256', get_current_user_id() . ':' . get_site_url());
            $event_data['_user_properties'] = array('logged_in' => array('value' => 'true'));
        }

        $event_data['_queued_at'] = time();

        $queue = get_transient('trackwp_ga4_queue');
        if (!is_array($queue)) {
            $queue = array();
        }
        $queue[] = $event_data;

        if (count($queue) >= 25) {
            set_transient('trackwp_ga4_queue', $queue, HOUR_IN_SECONDS);
            $this->flush_queue();
            return;
        }

        set_transient('trackwp_ga4_queue', $queue, HOUR_IN_SECONDS);

        if (!wp_next_scheduled('trackwp_flush_ga4')) {
            wp_schedule_single_event(time() + 30, 'trackwp_flush_ga4');
        }
    }

    /**
     * Flush the queued events. Groups by client_id + consent/user snapshot
     * and dispatches up to 25 events per request. Hooked to the
     * 'trackwp_flush_ga4' cron event at bootstrap (see TrackWP::flush_ga4_queue).
     *
     * Config checks run BEFORE the queue transient is deleted so a
     * misconfiguration does not discard queued events. Failed batches are
     * re-queued (events older than 1 hour are dropped) and a new flush is
     * scheduled.
     *
     * @return void
     */
    public function flush_queue() {
        $queue = get_transient('trackwp_ga4_queue');

        if (empty($queue) || !is_array($queue)) {
            delete_transient('trackwp_ga4_queue');
            return;
        }

        if (!$this->is_enabled()) {
            return;
        }

        $measurement_id = $this->config['ga4_measurement_id'];

        // Pre-check: GA4 MP only accepts G- format measurement IDs.
        if (!$this->is_valid_mp_id($measurement_id)) {
            $this->log_capi_error('mp_skipped: measurement_id must be G- format, got: ' . (is_string($measurement_id) ? $measurement_id : gettype($measurement_id)), '');
            return;
        }

        delete_transient('trackwp_ga4_queue');

        $api_secret     = TrackWP_Hash::decode($this->config['ga4_api_secret']);

        $url = add_query_arg(array(
            'measurement_id' => $measurement_id,
            'api_secret'     => $api_secret,
        ), 'https://www.google-analytics.com/mp/collect');

        // Group events by resolved client_id (GA4 MP requires one client_id
        // per request) AND by consent snapshot + user_id: consent/user_data/
        // user_id are request-level fields, so mixing events with different
        // snapshots in one batch would apply the first event's values to all.
        $groups = array();
        foreach ($queue as $event_data) {
            $cid  = $this->resolve_client_id($event_data);
            $gkey = $cid . '|' . (isset($event_data['_consent_marketing']) ? (int) !empty($event_data['_consent_marketing']) : 'n') . '|' . (isset($event_data['_user_id']) ? $event_data['_user_id'] : '');
            if (!isset($groups[$gkey])) {
                $groups[$gkey] = array('cid' => $cid, 'events' => array());
            }
            $groups[$gkey]['events'][] = $event_data;
        }

        $failed = array();

        foreach ($groups as $group) {
            $cid    = $group['cid'];
            $events = $group['events'];
            // Chunk into batches of 25 events per request.
            $chunks = array_chunk($events, 25);
            foreach ($chunks as $chunk) {
                $body_events = array();
                $body_extras = array();
                foreach ($chunk as $event_data) {
                    $single = $this->build_body($event_data, $cid);
                    if (!empty($single['events'][0])) {
                        $body_events[] = $single['events'][0];
                    }
                    // Carry over per-request properties from the first event that defines them.
                    foreach (array('user_id', 'user_properties', 'consent', 'user_data') as $key) {
                        if (!isset($body_extras[$key]) && isset($single[$key])) {
                            $body_extras[$key] = $single[$key];
                        }
                    }
                }

                if (empty($body_events)) {
                    continue;
                }

                $body = array(
                    'client_id' => $cid,
                    'events'    => $body_events,
                );
                $body = array_merge($body, $body_extras);

                // Cron context: blocking + retries are safe -- they do not block end-user response.
                if (!$this->dispatch_with_retry($url, $body, 'ga4', true)) {
                    $failed = array_merge($failed, $chunk);
                }
            }
        }

        if (!empty($failed)) {
            $this->requeue_failed($failed);
        }
    }

    /**
     * Re-queue events from failed batches and re-schedule a flush.
     *
     * Events older than 1 hour (per their _queued_at snapshot) are dropped so
     * the queue cannot grow unbounded on persistent failure.
     *
     * @param array $events
     * @return void
     */
    private function requeue_failed($events) {
        $cutoff = time() - HOUR_IN_SECONDS;
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

        // Merge with any events queued while this flush was running.
        $queue = get_transient('trackwp_ga4_queue');
        if (!is_array($queue)) {
            $queue = array();
        }
        set_transient('trackwp_ga4_queue', array_merge($keep, $queue), HOUR_IN_SECONDS);

        if (!wp_next_scheduled('trackwp_flush_ga4')) {
            wp_schedule_single_event(time() + 60, 'trackwp_flush_ga4');
        }
    }
}
