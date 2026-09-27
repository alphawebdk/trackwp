<?php
defined('ABSPATH') || exit;

class TrackWP_Google_Ads {

    /**
     * Platforms option (trackwp_platforms).
     *
     * @var array
     */
    private $platforms;

    /**
     * Advanced option (trackwp_advanced).
     *
     * @var array
     */
    private $advanced;

    /**
     * Backwards-compatible alias for $platforms.
     *
     * @var array
     */
    private $config;

    public function __construct() {
        $this->platforms = get_option('trackwp_platforms', array());
        $this->advanced  = get_option('trackwp_advanced', array());
        // Preserve previous property name for backwards compatibility.
        $this->config = $this->platforms;
    }

    /**
     * Check if Google Ads is enabled with valid conversion ID (client-side).
     */
    public function is_enabled() {
        return !empty($this->platforms['google_ads_enabled'])
            && !empty($this->platforms['google_ads_conversion_id']);
    }

    /**
     * Check if server-side Google Ads CAPI is configured.
     *
     * Requires the Google Ads master toggle (google_ads_enabled) to be on —
     * disabling the platform in admin must stop uploads even when the API
     * fields are still filled in. Also requires conversion ID, customer ID,
     * conversion action ID, a developer token, an OAuth2 client ID, client
     * secret and refresh token. The developer token, client secret and
     * refresh token are stored encoded and are decoded here to verify a
     * plaintext value exists.
     *
     * @return bool
     */
    public function is_capi_enabled() {
        if (empty($this->platforms['google_ads_enabled'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_conversion_id'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_customer_id'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_conversion_action_id'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_developer_token'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_oauth_client_id'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_oauth_client_secret'])) {
            return false;
        }
        if (empty($this->platforms['google_ads_oauth_refresh_token'])) {
            return false;
        }

        $developer_token = TrackWP_Hash::decode($this->platforms['google_ads_developer_token']);
        if (empty($developer_token)) {
            return false;
        }

        $client_secret = TrackWP_Hash::decode($this->platforms['google_ads_oauth_client_secret']);
        if (empty($client_secret)) {
            return false;
        }

        $refresh_token = TrackWP_Hash::decode($this->platforms['google_ads_oauth_refresh_token']);
        if (empty($refresh_token)) {
            return false;
        }

        return true;
    }

    /**
     * Get conversion ID.
     */
    public function get_conversion_id() {
        return isset($this->platforms['google_ads_conversion_id']) ? $this->platforms['google_ads_conversion_id'] : '';
    }

    /**
     * Get client-side config for wp_localize_script.
     * Returns conversion ID and per-event conversion labels.
     */
    public function get_client_config() {
        if (!$this->is_enabled()) {
            return array('conversionId' => '', 'conversionLabels' => array());
        }

        $events = get_option('trackwp_events', array());
        $labels = array();
        foreach ($events as $event) {
            if (!empty($event['enabled']) && !empty($event['ads_label']) && !empty($event['send_to']['google_ads'])) {
                $labels[$event['name']] = sanitize_text_field($event['ads_label']);
            }
        }

        return array(
            'conversionId'     => $this->get_conversion_id(),
            'conversionLabels' => $labels,
        );
    }

    /**
     * Google Ads API version (filter trackwp_google_ads_api_version).
     */
    const DEFAULT_API_VERSION = 'v25';

    /**
     * Default per-request budget in seconds when the caller passes none.
     */
    const DEFAULT_BUDGET = 4.0;

    /**
     * Select the click identifiers for the ClickConversion.
     *
     * Combination rules (official sources, verified 2026-09-27; they replace
     * the plan's "only one of three", coordinator decision):
     * - gclid + gbraid: allowed and recommended. upload-offline guide:
     *   "Note: In some cases you may be able to associate both a gclid and a
     *   gbraid with a conversion. In such cases we recommend setting both the
     *   GCLID and GBRAID onto the conversion message in your import request."
     *   https://developers.google.com/google-ads/api/docs/conversions/upload-offline
     * - gbraid + wbraid: forbidden. ConversionUploadError GBRAID_WBRAID_BOTH_SET:
     *   "Can't use both gbraid and wbraid parameters. Use only 1 and try again."
     *   https://developers.google.com/google-ads/api/reference/rpc/v25/ConversionUploadErrorEnum.ConversionUploadError
     * - gclid + wbraid: not documented as allowed, so not combined.
     * - ClickConversion (v25) itself lists gclid, gbraid and wbraid as plain
     *   string fields without a oneof.
     * Result: gclid (+ gbraid when present); else gbraid; else wbraid.
     *
     * Sources: the payload fields gclid/gbraid/wbraid (marketing category,
     * K2) and, for gclid only, the conversion linker cookie _gcl_aw.
     * _gcl_gb/_gcl_ag are not parsed (undocumented, BESLUTNINGER §6).
     *
     * @param array $event_data
     * @return array field => value (empty when no click identifier exists)
     */
    private function extract_click_ids($event_data) {
        $found = array();
        foreach (array('gclid', 'gbraid', 'wbraid') as $field) {
            if (!empty($event_data[$field]) && is_string($event_data[$field])
                && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $event_data[$field])) {
                $found[$field] = $event_data[$field];
            }
        }
        if (!isset($found['gclid']) && !empty($_COOKIE['_gcl_aw'])) {
            $raw   = sanitize_text_field(wp_unslash($_COOKIE['_gcl_aw']));
            $parts = explode('.', $raw);
            // Expected format: GCL.<timestamp>.<gclid>
            if (isset($parts[2]) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $parts[2])) {
                $found['gclid'] = $parts[2];
            }
        }

        if (isset($found['gclid'])) {
            $out = array('gclid' => $found['gclid']);
            if (isset($found['gbraid'])) {
                $out['gbraid'] = $found['gbraid'];
            }
            return $out;
        }
        if (isset($found['gbraid'])) {
            return array('gbraid' => $found['gbraid']);
        }
        if (isset($found['wbraid'])) {
            return array('wbraid' => $found['wbraid']);
        }
        return array();
    }

    /**
     * Build a K1 result array.
     */
    private static function result($status, $reason, $http_code = 0, $attempts = 0, $detail = '') {
        return array(
            'destination' => 'google_ads',
            'status'      => $status,
            'reason'      => $reason,
            'http_code'   => (int) $http_code,
            'attempts'    => (int) $attempts,
            'detail'      => substr((string) $detail, 0, 500),
        );
    }

    /**
     * Remove click identifiers from a string destined for a log or detail.
     *
     * @param string $text
     * @param array  $click_ids field => value
     */
    private static function scrub($text, $click_ids) {
        $text = (string) $text;
        foreach ((array) $click_ids as $value) {
            if (is_string($value) && $value !== '') {
                $text = str_replace($value, '[click_id]', $text);
            }
        }
        return $text;
    }

    /**
     * Get an OAuth2 access token for the Google Ads API.
     *
     * Uses the stored refresh token to obtain an access token on demand and
     * caches it in a transient until shortly before it expires.
     *
     * @param float $timeout Seconds available for the token request.
     * @return string Access token, or empty string on failure.
     */
    private function get_access_token($timeout = 5) {
        $cached = get_transient('trackwp_gads_access_token');
        if (!empty($cached)) {
            return $cached;
        }

        $client_secret = TrackWP_Hash::decode($this->platforms['google_ads_oauth_client_secret']);
        $refresh_token = TrackWP_Hash::decode($this->platforms['google_ads_oauth_refresh_token']);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'blocking' => true,
            'timeout'  => max(0.2, (float) $timeout),
            'body'     => array(
                'client_id'     => $this->platforms['google_ads_oauth_client_id'],
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ),
        ));

        if (is_wp_error($response)) {
            $this->log('oauth_refresh_failed: ' . $response->get_error_message());
            return '';
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            $this->log('oauth_refresh_failed: no access_token in response (HTTP ' . wp_remote_retrieve_response_code($response) . ')');
            return '';
        }

        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 0;
        $ttl        = max(60, $expires_in - 300);
        set_transient('trackwp_gads_access_token', $data['access_token'], $ttl);

        return $data['access_token'];
    }

    /**
     * Send a server-side click conversion to Google Ads (uploadClickConversions).
     *
     * IMPORTANT — this path is closed for most developer tokens as of
     * 2026-06-15. Google allowlists ConversionUploadService by demonstrated
     * prior use (CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE otherwise). gtag
     * (AW-ID/label) remains the primary Ads track; this upload is optional.
     *
     * ClickConversion fields used, verified 2026-09-27 against
     * https://developers.google.com/google-ads/api/reference/rpc/v25/ClickConversion
     * ("gbraid: The URL parameter for clicks associated with app conversions",
     * "wbraid: The URL parameter for clicks associated with web conversions",
     * "consent: The consent setting for the event", "order_id: ... can only be
     * used for one conversion per conversion action") and
     * https://developers.google.com/google-ads/api/docs/conversions/upload-offline
     * ("It's highly recommended that you populate the consent field of the
     * ClickConversion object. If not set, it's possible that your conversions
     * won't be attributable."). Consent.ad_user_data is a ConsentStatus
     * (https://developers.google.com/google-ads/api/reference/rpc/v25/Consent).
     *
     * - orderId = ecommerce.transaction_id (order number), else event_id, so
     *   it dedups against the gtag conversion with the same transaction_id.
     * - Click identifiers per extract_click_ids(): gclid (+ gbraid), else
     *   gbraid, else wbraid; never gbraid together with wbraid.
     * - consent.adUserData = GRANTED (only sent with marketing consent).
     * - userIdentifiers only when customer_data_sharing is enabled.
     * - A click identifier is never written to a log or a result detail.
     * - One attempt (K1); a timeout is `unknown`.
     *
     * @param array      $event_data
     * @param array      $consent Effective consent (K3): analytics, marketing, ...
     * @param float|null $budget  Remaining request budget in seconds (K1).
     * @return array K1 result.
     */
    public function send_conversion($event_data, $consent, $budget = null) {
        if (!$this->is_capi_enabled()) {
            return self::result('skipped', 'not_configured');
        }

        if (!is_array($consent) || !isset($consent['marketing']) || $consent['marketing'] !== true) {
            $this->log('skip: no_consent');
            return self::result('skipped', 'no_consent');
        }

        $clicks = $this->extract_click_ids($event_data);
        if (empty($clicks)) {
            $this->log('skip: no_click_id');
            return self::result('skipped', 'no_click_id');
        }

        $deadline = microtime(true) + (($budget === null) ? self::DEFAULT_BUDGET : max(0.0, (float) $budget));

        $customer_id_clean = preg_replace('/\D/', '', $this->platforms['google_ads_customer_id']);
        $action_id         = preg_replace('/\D/', '', (string) $this->platforms['google_ads_conversion_action_id']);

        $ecommerce = (isset($event_data['ecommerce']) && is_array($event_data['ecommerce'])) ? $event_data['ecommerce'] : array();
        $order_id  = !empty($ecommerce['transaction_id'])
            ? (string) $ecommerce['transaction_id']
            : (isset($event_data['event_id']) ? (string) $event_data['event_id'] : '');

        $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', isset($event_data['currency']) ? (string) $event_data['currency'] : ''));
        if (strlen($currency) !== 3) {
            $currency = 'DKK';
        }

        $conversion = array(
            'conversionAction'   => "customers/{$customer_id_clean}/conversionActions/{$action_id}",
            'conversionDateTime' => gmdate('Y-m-d H:i:sP'),
            // R15: Ads uses value (value_basis), never value_ga4.
            'conversionValue'    => floatval(isset($event_data['value']) ? $event_data['value'] : 0),
            'currencyCode'       => $currency,
            'consent'            => array('adUserData' => 'GRANTED'),
        );
        foreach ($clicks as $field => $value) {
            $conversion[$field] = $value;
        }
        if ($order_id !== '') {
            $conversion['orderId'] = sanitize_text_field($order_id);
        }
        if (TrackWP_Hash::customer_data_sharing_enabled()) {
            $identifiers = $this->build_user_identifiers($event_data);
            if (!empty($identifiers)) {
                $conversion['userIdentifiers'] = $identifiers;
            }
        }

        $payload = array(
            'conversions'    => array($conversion),
            'partialFailure' => true,
        );

        $access_token = $this->get_access_token(max(0.2, $deadline - microtime(true)));
        if ($access_token === '') {
            $this->log('skip: no_access_token');
            return self::result('failed', 'transport', 0, 0, 'oauth_refresh_failed');
        }

        $remaining = $deadline - microtime(true);
        if ($remaining < 0.2) {
            return self::result('failed', 'transport', 0, 0, 'budget_exhausted');
        }

        $developer_token = TrackWP_Hash::decode($this->platforms['google_ads_developer_token']);

        $api_version = apply_filters('trackwp_google_ads_api_version', self::DEFAULT_API_VERSION);
        $url         = "https://googleads.googleapis.com/{$api_version}/customers/{$customer_id_clean}:uploadClickConversions";

        $headers = array(
            'Authorization'   => 'Bearer ' . $access_token,
            'developer-token' => $developer_token,
            'Content-Type'    => 'application/json',
        );

        // Optional MCC (manager account) support.
        $login_cid = apply_filters('trackwp_google_ads_login_customer_id', '');
        if (!empty($login_cid)) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $login_cid);
        }

        $response = wp_remote_post($url, array(
            'blocking' => true,
            'timeout'  => $remaining,
            'headers'  => $headers,
            'body'     => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $errno   = preg_match('/cURL error (\d+)/i', $message, $m) ? (int) $m[1] : (stripos($message, 'timed out') !== false ? 28 : 0);
            if ($errno === 28) {
                $result = self::result('unknown', 'timeout', 0, 1, 'curl 28');
            } elseif ($errno === 6 || $errno === 7) {
                $result = self::result('failed', 'transport', 0, 1, 'curl ' . $errno);
            } else {
                $result = self::result('unknown', 'transport', 0, 1, $errno ? 'curl ' . $errno : 'transport error');
            }
            $this->log('upload_' . $result['status'] . ': ' . $result['detail']);
            return $result;
        }

        $code     = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            // partialFailure => true: per-conversion errors come back in the
            // body with HTTP 200 -- those are failures, not success.
            $data = json_decode($raw_body, true);
            if (is_array($data) && !empty($data['partialFailureError'])) {
                $pf_message = isset($data['partialFailureError']['message'])
                    ? $data['partialFailureError']['message']
                    : wp_json_encode($data['partialFailureError']);
                $detail = self::scrub(substr((string) $pf_message, 0, 1000), $clicks);
                $this->log('upload_failed: partialFailureError: ' . $detail);
                return self::result('failed', 'partial_failure', $code, 1, $detail);
            }
            $this->log('uploaded (' . implode('+', array_keys($clicks)) . ')');
            return self::result('ok', 'sent', $code, 1);
        }

        if ($code === 429) {
            $reason = 'http_429';
        } elseif ($code >= 500) {
            $reason = 'http_5xx';
        } else {
            $reason = 'http_4xx';
        }
        $detail = self::scrub(substr($raw_body, 0, 1000), $clicks);
        $this->log('upload_failed: HTTP ' . $code . ' ' . $detail);
        return self::result('failed', $reason, $code, 1, $detail);
    }

    /**
     * Build the userIdentifiers array from hashed enhanced-conversion fields
     * (Google normalization: email_sha256, phone_e164_sha256).
     *
     * @param array $event_data
     * @return array
     */
    private function build_user_identifiers($event_data) {
        $identifiers = array();
        $enhanced    = (isset($event_data['enhanced']) && is_array($event_data['enhanced'])) ? $event_data['enhanced'] : array();

        if (!empty($enhanced['email_sha256'])) {
            $identifiers[] = array('hashedEmail' => $enhanced['email_sha256']);
        }

        // Google requires the E.164 hash ('+' + country code + digits).
        if (!empty($enhanced['phone_e164_sha256'])) {
            $identifiers[] = array('hashedPhoneNumber' => $enhanced['phone_e164_sha256']);
        }

        return $identifiers;
    }

    /**
     * Append a debug log line to the pending log file.
     *
     * Only writes when capi_debug_logging_enabled is true in trackwp_advanced.
     *
     * @param string $message
     * @return void
     */
    private function log($message) {
        if (empty($this->advanced['capi_debug_logging_enabled'])) {
            return;
        }

        $log_file = WP_CONTENT_DIR . '/trackwp/google-ads-pending.log';
        if (class_exists('TrackWP_Settings') && method_exists('TrackWP_Settings', 'ensure_log_dir')) {
            TrackWP_Settings::ensure_log_dir(dirname($log_file));
        } else {
            wp_mkdir_p(dirname($log_file));
        }
        $line = '[' . gmdate('c') . '] ' . $message . "\n";
        error_log($line, 3, $log_file);
    }
}
