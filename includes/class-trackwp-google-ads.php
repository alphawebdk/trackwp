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
     * Extract a GCLID from the event payload or fall back to the
     * Google Ads conversion linker cookie (_gcl_aw).
     *
     * @param array $event_data
     * @return string|null
     */
    private function extract_gclid($event_data) {
        if (!empty($event_data['enhanced']['gclid'])) {
            return $event_data['enhanced']['gclid'];
        }

        if (!empty($event_data['gclid'])) {
            return $event_data['gclid'];
        }

        if (!empty($_COOKIE['_gcl_aw'])) {
            $raw   = sanitize_text_field(wp_unslash($_COOKIE['_gcl_aw']));
            $parts = explode('.', $raw);
            // Expected format: GCL.<timestamp>.<gclid>
            if (isset($parts[2]) && $parts[2] !== '') {
                return $parts[2];
            }
        }

        return null;
    }

    /**
     * Get an OAuth2 access token for the Google Ads API.
     *
     * Uses the stored refresh token to obtain an access token on demand and
     * caches it in a transient until shortly before it expires.
     *
     * @return string Access token, or empty string on failure.
     */
    private function get_access_token() {
        $cached = get_transient('trackwp_gads_access_token');
        if (!empty($cached)) {
            return $cached;
        }

        $client_secret = TrackWP_Hash::decode($this->platforms['google_ads_oauth_client_secret']);
        $refresh_token = TrackWP_Hash::decode($this->platforms['google_ads_oauth_refresh_token']);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'blocking' => true,
            'timeout'  => 5,
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
     * Send a server-side conversion to Google Ads (CAPI).
     *
     * Dispatches an uploadClickConversions request to the Google Ads REST
     * API using an OAuth2 access token refreshed on demand.
     *
     * IMPORTANT — this path is closed for most developer tokens as of
     * 2026-06-15. Google now allowlists ConversionUploadService by
     * *demonstrated prior use*: a developer token that did not import offline
     * conversions between December 2025 and May 2026 is rejected with
     * CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE. Token age alone does not
     * qualify — an old but unused token is not covered.
     *
     * TrackWP has never had CAPI credentials filled in, so its token is
     * almost certainly NOT allowlisted. Verify before relying on this method;
     * the migration path is the Data Manager API (events.ingest), which needs
     * no developer token but is a different dispatcher, not a config switch.
     *
     * The Google Ads API version is filterable via
     * trackwp_google_ads_api_version.
     *
     * @param array $event_data
     * @param array $consent
     * @return bool
     */
    public function send_conversion($event_data, $consent) {
        if (!$this->is_capi_enabled()) {
            $this->log('skip: capi not configured');
            return false;
        }

        if (empty($consent['marketing'])) {
            $this->log('skip: no_consent');
            return false;
        }

        $gclid = $this->extract_gclid($event_data);
        if ($gclid === null) {
            $this->log('skip: no_gclid');
            return false;
        }

        $customer_id_clean = preg_replace('/\D/', '', $this->platforms['google_ads_customer_id']);
        $action_id         = $this->platforms['google_ads_conversion_action_id'];

        $payload = array(
            'conversions' => array(array(
                'gclid'              => $gclid,
                'conversionAction'   => "customers/{$customer_id_clean}/conversionActions/{$action_id}",
                'conversionDateTime' => gmdate('Y-m-d H:i:sP'),
                'conversionValue'    => floatval(isset($event_data['value']) ? $event_data['value'] : 0),
                'currencyCode'       => sanitize_text_field(isset($event_data['currency']) ? $event_data['currency'] : 'DKK'),
                'orderId'            => sanitize_text_field(isset($event_data['event_id']) ? $event_data['event_id'] : ''),
                'userIdentifiers'    => $this->build_user_identifiers($event_data),
            )),
            'partialFailure' => true,
        );

        $access_token = $this->get_access_token();
        if ($access_token === '') {
            $this->log('skip: no_access_token');
            return false;
        }

        $developer_token = TrackWP_Hash::decode($this->platforms['google_ads_developer_token']);

        $api_version = apply_filters('trackwp_google_ads_api_version', 'v21');
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
            'timeout'  => 5,
            'headers'  => $headers,
            'body'     => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            $this->log('upload_failed: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            // partialFailure => true means per-conversion errors come back in
            // the body with HTTP 200 -- treat those as failures, not success.
            $data = json_decode($raw_body, true);
            if (is_array($data) && !empty($data['partialFailureError'])) {
                $pf_message = isset($data['partialFailureError']['message'])
                    ? $data['partialFailureError']['message']
                    : wp_json_encode($data['partialFailureError']);
                $this->log('upload_failed: partialFailureError: ' . substr((string) $pf_message, 0, 500));
                return false;
            }
            $this->log('uploaded: ' . $gclid);
            return true;
        }

        $this->log('upload_failed: HTTP ' . $code . ' ' . substr($raw_body, 0, 500));
        return false;
    }

    /**
     * Build the userIdentifiers array from hashed enhanced-conversion fields.
     *
     * @param array $event_data
     * @return array
     */
    private function build_user_identifiers($event_data) {
        $identifiers = array();

        if (!empty($event_data['enhanced']['email_sha256'])) {
            $identifiers[] = array('hashedEmail' => $event_data['enhanced']['email_sha256']);
        }

        // Google requires the E.164 hash ('+' + country code + digits).
        // Omit the phone identifier entirely if only the non-E.164 hash exists.
        if (!empty($event_data['enhanced']['phone_e164_sha256'])) {
            $identifiers[] = array('hashedPhoneNumber' => $event_data['enhanced']['phone_e164_sha256']);
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
        TrackWP_Settings::ensure_log_dir(dirname($log_file));
        $line = '[' . gmdate('c') . '] ' . $message . "\n";
        error_log($line, 3, $log_file);
    }
}
