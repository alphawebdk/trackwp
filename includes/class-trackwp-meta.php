<?php
defined('ABSPATH') || exit;

class TrackWP_Meta {

    /**
     * Graph API version used when none is configured. Marketing API v25.0
     * (BESLUTNINGER-1.10.1 §6). The 1.10.1 migration lifts stored v21.0-v24.0.
     */
    const DEFAULT_API_VERSION = 'v25.0';

    /**
     * Graph API versions an admin may select. This list is the ONLY source
     * for the settings sanitizer (TrackWP_Settings::sanitize_platforms) and
     * the admin dropdown (templates/settings-page.php); any other stored
     * value falls back to DEFAULT_API_VERSION.
     *
     * Source: the Marketing API version table
     * (https://developers.facebook.com/docs/graph-api/changelog/versions),
     * checked 2026-09-27 (BESLUTNINGER-1.10.1 §6): v25.0 is the newest,
     * v24.0 expires 2026-10-06.
     *
     * v24.0 is deliberately NOT listed: it expires nine days after this
     * change, i.e. at or before the 1.10.1 release reaches sites, and
     * the 1.10.1 upgrade lifts stored v21.0-v24.0 to v25.0 anyway, so no
     * existing setting depends on it. Offering it would only let an admin
     * pin a version that Meta auto-upgrades days later. Add the next
     * version here when Meta releases it.
     */
    const SUPPORTED_API_VERSIONS = array('v25.0');

    /**
     * Maximum attempts per event (K1). Meta dedups on event_id, so a retry
     * after a timeout or 5xx cannot double count.
     */
    const MAX_ATTEMPTS = 2;

    /**
     * Default per-request budget in seconds when the caller passes none.
     */
    const DEFAULT_BUDGET = 4.0;

    private $config;
    private $platforms;
    private $advanced;

    public function __construct() {
        $this->config    = get_option('trackwp_platforms', array());
        $this->platforms = $this->config;
        $this->advanced  = get_option('trackwp_advanced', array());
    }

    /**
     * Check if Meta is enabled with valid credentials.
     */
    public function is_enabled() {
        return !empty($this->config['meta_enabled'])
            && !empty($this->config['meta_pixel_id'])
            && !empty($this->config['meta_access_token']);
    }

    /**
     * Automatic mapping from internal event names to Meta standard events.
     * Shared with the client via trackwpConfig.metaEventMap (K8).
     *
     * @return array internal name => Meta standard event
     */
    public static function event_map() {
        $map = array(
            'phone_click'    => 'Contact',
            'email_click'    => 'Contact',
            'form_submit'    => 'Lead',
            'purchase'       => 'Purchase',
            'add_to_cart'    => 'AddToCart',
            'view_item'      => 'ViewContent',
            'begin_checkout' => 'InitiateCheckout',
        );
        $filtered = apply_filters('trackwp_meta_event_map', $map);
        return is_array($filtered) ? $filtered : $map;
    }

    /**
     * Resolve the Meta event name for an internal event.
     *
     * 1. An explicit standard name (anything but '' and 'CustomEvent') wins.
     * 2. '' or 'CustomEvent' is looked up in event_map().
     * 3. Otherwise the internal name is sent as a custom event.
     *
     * The client applies the same rule via events[].meta_resolved.
     *
     * @param string $internal_name
     * @param string $meta_event_type Configured meta_event of the event.
     * @return string
     */
    public static function resolve_event_name($internal_name, $meta_event_type = '') {
        $internal_name   = (string) $internal_name;
        $meta_event_type = (string) $meta_event_type;
        if ($meta_event_type !== '' && $meta_event_type !== 'CustomEvent') {
            return $meta_event_type;
        }
        $map = self::event_map();
        if (isset($map[$internal_name]) && is_string($map[$internal_name]) && $map[$internal_name] !== '') {
            return $map[$internal_name];
        }
        return $internal_name;
    }

    /**
     * Build an fbc value from a raw fbclid: fb.1.<creation ms>.<fbclid>.
     * The fbclid is kept unchanged (case sensitive), per
     * https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc/
     *
     * @param string   $fbclid
     * @param int|null $ms Creation time in milliseconds; null = now.
     * @return string '' when the fbclid is unusable.
     */
    public static function fbc_from_fbclid($fbclid, $ms = null) {
        if (!is_string($fbclid) || $fbclid === '' || strlen($fbclid) > 500) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $fbclid)) {
            return '';
        }
        if ($ms === null) {
            $ms = (int) floor(microtime(true) * 1000);
        }
        return 'fb.1.' . (int) $ms . '.' . $fbclid;
    }

    /**
     * Send an event via the Meta Conversions API.
     *
     * @param array      $event_data Keys: event, value, currency, page_url, event_id,
     *                               user_agent, fbc, fbp, enhanced, meta_event_name,
     *                               external_id (verified purchase only), form_name,
     *                               ecommerce, consent (effective consent, K3).
     * @param float|null $budget     Remaining request budget in seconds (K1).
     * @return array K1 result.
     */
    public function send_event($event_data, $budget = null) {
        if (!$this->is_enabled()) {
            return self::result('skipped', 'not_configured');
        }
        if (!self::consent_flag($event_data, 'marketing')) {
            return self::result('skipped', 'no_consent');
        }

        $pixel_id     = $this->config['meta_pixel_id'];
        $access_token = TrackWP_Hash::decode($this->config['meta_access_token']);

        $api_version = !empty($this->platforms['meta_api_version']) ? $this->platforms['meta_api_version'] : self::DEFAULT_API_VERSION;
        $api_version = apply_filters('trackwp_meta_api_version', $api_version);

        $url  = 'https://graph.facebook.com/' . $api_version . '/' . $pixel_id . '/events';
        $body = $this->build_body($event_data);
        $body['access_token'] = $access_token;

        $deadline = microtime(true) + (($budget === null) ? self::DEFAULT_BUDGET : max(0.0, (float) $budget));
        return $this->dispatch($url, $body, $deadline);
    }

    /**
     * Build the CAPI request body (without access_token).
     *
     * @param array $event_data
     * @return array
     */
    public function build_body($event_data) {
        $internal_event = isset($event_data['event']) ? (string) $event_data['event'] : '';
        $meta_event     = self::resolve_event_name(
            $internal_event,
            isset($event_data['meta_event_name']) ? $event_data['meta_event_name'] : ''
        );

        $user_data = array(
            'client_ip_address' => self::client_ip(),
            'client_user_agent' => isset($event_data['user_agent']) ? (string) $event_data['user_agent'] : '',
        );
        if ($user_data['client_ip_address'] === '') {
            unset($user_data['client_ip_address']);
        }

        // Facebook browser identifiers (marketing category, already gated).
        if (!empty($event_data['fbc'])) {
            $user_data['fbc'] = (string) $event_data['fbc'];
        }
        if (!empty($event_data['fbp'])) {
            $user_data['fbp'] = (string) $event_data['fbp'];
        }

        // Customer data (hashed PII + external_id) only when sharing is on.
        if (TrackWP_Hash::customer_data_sharing_enabled()) {
            $external_id = self::resolve_external_id($event_data);
            if ($external_id !== '') {
                $user_data['external_id'] = array($external_id);
            }

            if (!empty($event_data['enhanced']) && is_array($event_data['enhanced'])) {
                $enhanced = $event_data['enhanced'];
                // em: Meta-normalized hash only (SDK rule, no Gmail munging).
                // The Google hash is a different value and is never used here.
                $map = array(
                    'email_meta_sha256' => 'em',
                    'phone_sha256'      => 'ph',
                    'first_name_sha256' => 'fn',
                    'last_name_sha256'  => 'ln',
                    'zip_sha256'        => 'zp',
                    'city_sha256'       => 'ct',
                    'country_sha256'    => 'country',
                );
                foreach ($map as $key => $meta_key) {
                    if (!empty($enhanced[$key]) && is_string($enhanced[$key]) && preg_match('/^[a-f0-9]{64}$/', $enhanced[$key])) {
                        $user_data[$meta_key] = array($enhanced[$key]);
                    }
                }
            }
        }

        $event_entry = array(
            'event_name'       => $meta_event,
            'event_time'       => time(),
            'event_id'         => isset($event_data['event_id']) ? (string) $event_data['event_id'] : '',
            'event_source_url' => isset($event_data['page_url']) ? (string) $event_data['page_url'] : '',
            'action_source'    => 'website',
            'user_data'        => $user_data,
        );

        // Value (R15: Meta uses value = value_basis, never value_ga4).
        // Meta requires BOTH value and currency on Purchase, also for 0.
        $is_transaction = in_array($internal_event, array('purchase', 'refund'), true);
        if (!empty($event_data['value']) || $is_transaction) {
            $event_entry['custom_data'] = array(
                'value'    => floatval(isset($event_data['value']) ? $event_data['value'] : 0),
                'currency' => self::normalize_currency(isset($event_data['currency']) ? $event_data['currency'] : ''),
            );
        }

        if (!empty($event_data['form_name']) && is_string($event_data['form_name'])) {
            if (!isset($event_entry['custom_data'])) {
                $event_entry['custom_data'] = array();
            }
            $event_entry['custom_data']['content_name'] = $event_data['form_name'];
        }

        // Ecommerce. Already sanitised by TrackWP_Proxy::sanitize_ecommerce().
        $ecommerce = (isset($event_data['ecommerce']) && is_array($event_data['ecommerce']))
            ? $event_data['ecommerce']
            : array();

        if (!empty($ecommerce['items']) && is_array($ecommerce['items'])) {
            $contents  = array();
            $num_items = 0;
            foreach ($ecommerce['items'] as $item) {
                $quantity   = isset($item['quantity']) ? max(1, intval($item['quantity'])) : 1;
                $contents[] = array(
                    // Meta's contents spec: id, quantity, item_price.
                    'id'         => isset($item['item_id']) ? (string) $item['item_id'] : (isset($item['item_name']) ? (string) $item['item_name'] : ''),
                    'quantity'   => $quantity,
                    'item_price' => isset($item['price']) ? floatval($item['price']) : 0,
                );
                $num_items += $quantity;
            }
            if (!isset($event_entry['custom_data'])) {
                $event_entry['custom_data'] = array();
            }
            $event_entry['custom_data']['contents']     = $contents;
            $event_entry['custom_data']['content_type'] = 'product';

            // num_items is documented for InitiateCheckout only.
            if ($meta_event === 'InitiateCheckout') {
                $event_entry['custom_data']['num_items'] = $num_items;
            }
        }

        // order_id: second dedup key alongside event_id.
        if (!empty($ecommerce['transaction_id'])) {
            if (!isset($event_entry['custom_data'])) {
                $event_entry['custom_data'] = array();
            }
            $event_entry['custom_data']['order_id'] = (string) $ecommerce['transaction_id'];
        }

        // No data_processing_options (LDU): it is a US state-privacy flag and
        // CAPI is only called with marketing consent (1.10.1).
        $body = array(
            'data' => array($event_entry),
        );

        // Optional test event code (Meta Events Manager > Test Events).
        if (!empty($this->platforms['meta_test_event_code'])) {
            $body['test_event_code'] = sanitize_text_field($this->platforms['meta_test_event_code']);
        }

        return $body;
    }

    /**
     * external_id order (PLAN-1.10.1-v4 W3):
     * 1. event_data.external_id from a verified purchase (customer_id > 0),
     *    already hashed by TrackWP_WooCommerce (64 hex).
     * 2. Logged-in user via TrackWP_Request_Guard::current_user_id():
     *    sha256(uid . ':' . site_url).
     * 3. none.
     *
     * @param array $event_data
     * @return string
     */
    public static function resolve_external_id($event_data) {
        if (!empty($event_data['external_id']) && is_string($event_data['external_id'])
            && preg_match('/^[a-f0-9]{64}$/', $event_data['external_id'])) {
            return $event_data['external_id'];
        }
        $uid = class_exists('TrackWP_Request_Guard') ? (int) TrackWP_Request_Guard::current_user_id() : 0;
        if ($uid > 0) {
            return hash('sha256', $uid . ':' . get_site_url());
        }
        return '';
    }

    /**
     * Client IP via the shared request guard (trusted-proxy aware).
     *
     * @return string
     */
    private static function client_ip() {
        if (class_exists('TrackWP_Request_Guard')) {
            $ip = (string) TrackWP_Request_Guard::client_ip();
            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Read a consent flag from the effective consent in event_data. The
     * proxy always sets $event_data['consent'] to the K3 result, so this is
     * the only source: a missing or non-true value means false (strict
     * `=== true`). There is no second parse path via the cookie.
     *
     * @param array  $event_data
     * @param string $key 'analytics' | 'marketing'
     * @return bool
     */
    private static function consent_flag($event_data, $key) {
        if (isset($event_data['consent']) && is_array($event_data['consent']) && array_key_exists($key, $event_data['consent'])) {
            return $event_data['consent'][$key] === true;
        }
        return false;
    }

    /**
     * Normalise a currency to the ISO 4217 shape Meta expects (3 letters).
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
     * Build a K1 result array.
     */
    private static function result($status, $reason, $http_code = 0, $attempts = 0, $detail = '') {
        return array(
            'destination' => 'meta',
            'status'      => $status,
            'reason'      => $reason,
            'http_code'   => (int) $http_code,
            'attempts'    => (int) $attempts,
            'detail'      => substr((string) $detail, 0, 500),
        );
    }

    /**
     * cURL error number from a WP_Error message, 0 if none.
     */
    private static function curl_errno($error) {
        if (preg_match('/cURL error (\d+)/i', $error->get_error_message(), $m)) {
            return (int) $m[1];
        }
        return stripos($error->get_error_message(), 'timed out') !== false ? 28 : 0;
    }

    /**
     * Dispatch with retry inside the deadline.
     *
     * Blocking request (non-blocking cURL aborts before TLS completes).
     * Retries (max 2 attempts): timeout, connection errors and HTTP 5xx.
     * Meta dedups on event_id, so a retry cannot double count. A final
     * timeout is `unknown` (the event may have arrived), never `failed`.
     *
     * @param string $url
     * @param array  $body
     * @param float  $deadline microtime(true) deadline.
     * @return array K1 result.
     */
    private function dispatch($url, $body, $deadline) {
        $attempts = 0;
        $result   = self::result('failed', 'transport', 0, 0, 'budget_exhausted');

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
                'body'     => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                $errno = self::curl_errno($response);
                if ($errno === 28) {
                    $result = self::result('unknown', 'timeout', 0, $attempts, 'curl 28');
                } elseif ($errno === 6 || $errno === 7) {
                    $result = self::result('failed', 'transport', 0, $attempts, 'curl ' . $errno);
                } else {
                    $result = self::result('unknown', 'transport', 0, $attempts, $errno ? 'curl ' . $errno : 'transport error');
                }
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                return self::result('ok', 'sent', $code, $attempts);
            }

            $detail = self::error_detail(wp_remote_retrieve_body($response));
            if ($code === 429) {
                $result = self::result('failed', 'http_429', $code, $attempts, $detail);
                break;
            }
            if ($code >= 500) {
                $result = self::result('failed', 'http_5xx', $code, $attempts, $detail);
                continue;
            }
            $result = self::result('failed', 'http_4xx', $code, $attempts, $detail);
            break;
        }

        if ($result['status'] !== 'ok') {
            $this->log($result);
        }
        return $result;
    }

    /**
     * PII-free error summary from a Graph API error body.
     */
    private static function error_detail($raw_body) {
        $data = json_decode((string) $raw_body, true);
        if (!is_array($data) || empty($data['error']) || !is_array($data['error'])) {
            return '';
        }
        $e     = $data['error'];
        $parts = array();
        foreach (array('type', 'code', 'error_subcode', 'fbtrace_id') as $k) {
            if (isset($e[$k]) && is_scalar($e[$k])) {
                $parts[] = $k . '=' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $e[$k]);
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Append a failed result to the CAPI error log when debug logging is on.
     */
    private function log($result) {
        if (empty($this->advanced['capi_debug_logging_enabled'])) {
            return;
        }
        $log_file = WP_CONTENT_DIR . '/trackwp/capi-errors.log';
        if (class_exists('TrackWP_Settings') && method_exists('TrackWP_Settings', 'ensure_log_dir')) {
            TrackWP_Settings::ensure_log_dir(dirname($log_file));
        } else {
            wp_mkdir_p(dirname($log_file));
        }
        error_log(sprintf(
            "[%s] [Meta] %s/%s attempts=%d %s (http_code: %d)\n",
            gmdate('c'),
            $result['status'],
            $result['reason'],
            $result['attempts'],
            $result['detail'],
            $result['http_code']
        ), 3, $log_file);
    }
}
