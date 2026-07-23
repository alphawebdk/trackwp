<?php
defined('ABSPATH') || exit;

class TrackWP_Meta {

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
     * Send event via Meta Conversions API.
     * Blocking request with a short timeout (see dispatch_with_retry).
     *
     * @param array $event_data Keys: event, value, currency, page_url, event_id, user_agent, fbc, fbp, enhanced, meta_event_name
     * @return bool
     */
    public function send_event($event_data) {
        if (!$this->is_enabled()) return false;

        $pixel_id = $this->config['meta_pixel_id'];
        $access_token = TrackWP_Hash::decode($this->config['meta_access_token']);

        $api_version = !empty($this->platforms['meta_api_version']) ? $this->platforms['meta_api_version'] : 'v21.0';
        $api_version = apply_filters('trackwp_meta_api_version', $api_version);

        $url = 'https://graph.facebook.com/' . $api_version . '/' . $pixel_id . '/events';

        // Map internal event name to Meta event
        $meta_event = $this->map_event_name(
            isset($event_data['event']) ? $event_data['event'] : '',
            isset($event_data['meta_event_name']) ? $event_data['meta_event_name'] : ''
        );

        $user_data = array(
            'client_ip_address' => $this->get_client_ip(),
            'client_user_agent' => isset($event_data['user_agent']) ? $event_data['user_agent'] : '',
        );

        // external_id for logged-in users (hashed user_id + site_url, per Meta spec: array)
        if (is_user_logged_in()) {
            $external_id_hash = hash('sha256', get_current_user_id() . ':' . get_site_url());
            $user_data['external_id'] = array($external_id_hash);
        }

        // Facebook cookies for attribution
        if (!empty($event_data['fbc'])) {
            $user_data['fbc'] = $event_data['fbc'];
        }
        if (!empty($event_data['fbp'])) {
            $user_data['fbp'] = $event_data['fbp'];
        }

        // Enhanced conversions (hashed PII)
        if (!empty($event_data['enhanced'])) {
            $enhanced = $event_data['enhanced'];
            // em: prefer the Meta-normalized hash (trim+lowercase only, no
            // Gmail munging); fall back to the Google-normalized hash.
            if (!empty($enhanced['email_meta_sha256'])) {
                $user_data['em'] = array($enhanced['email_meta_sha256']);
            } elseif (!empty($enhanced['email_sha256'])) {
                $user_data['em'] = array($enhanced['email_sha256']);
            }
            if (!empty($enhanced['phone_sha256']))      $user_data['ph'] = array($enhanced['phone_sha256']);
            if (!empty($enhanced['first_name_sha256'])) $user_data['fn'] = array($enhanced['first_name_sha256']);
            if (!empty($enhanced['last_name_sha256']))  $user_data['ln'] = array($enhanced['last_name_sha256']);
            if (!empty($enhanced['zip_sha256']))        $user_data['zp'] = array($enhanced['zip_sha256']);
            if (!empty($enhanced['city_sha256']))       $user_data['ct'] = array($enhanced['city_sha256']);
            if (!empty($enhanced['country_sha256']))    $user_data['country'] = array($enhanced['country_sha256']);
        }

        $event_entry = array(
            'event_name'       => $meta_event,
            'event_time'       => time(),
            'event_id'         => isset($event_data['event_id']) ? $event_data['event_id'] : '',
            'event_source_url' => isset($event_data['page_url']) ? $event_data['page_url'] : '',
            'action_source'    => 'website',
            'user_data'        => $user_data,
        );

        // Limited Data Use (Consent Mode v2): if marketing consent is denied, flag LDU.
        // Prefer the POSTed payload consent (matches what dispatch gated on);
        // fall back to the server-side cookie lookup when the key is absent.
        $marketing = null;
        if (isset($event_data['consent']) && is_array($event_data['consent']) && array_key_exists('marketing', $event_data['consent'])) {
            $marketing = !empty($event_data['consent']['marketing']);
        } elseif (class_exists('TrackWP_Consent')) {
            $consent   = TrackWP_Consent::get_current_consent();
            $marketing = !empty($consent['marketing']);
        }
        if ($marketing === false) {
            $event_entry['data_processing_options']         = array('LDU');
            $event_entry['data_processing_options_country'] = 0;
            $event_entry['data_processing_options_state']   = 0;
        }

        // Custom data (value, currency)
        if (!empty($event_data['value'])) {
            $event_entry['custom_data'] = array(
                'value'    => floatval($event_data['value']),
                'currency' => isset($event_data['currency']) ? $event_data['currency'] : 'DKK',
            );
        }

        // Ecommerce items
        if (!empty($event_data['ecommerce']['items'])) {
            $contents = array();
            foreach ($event_data['ecommerce']['items'] as $item) {
                $contents[] = array(
                    'id'       => isset($item['item_id']) ? $item['item_id'] : '',
                    'quantity' => isset($item['quantity']) ? intval($item['quantity']) : 1,
                    'item_price' => isset($item['price']) ? floatval($item['price']) : 0,
                );
            }
            if (!isset($event_entry['custom_data'])) {
                $event_entry['custom_data'] = array();
            }
            $event_entry['custom_data']['contents'] = $contents;
            $event_entry['custom_data']['content_type'] = 'product';
        }

        $body = array(
            'data'         => array($event_entry),
            'access_token' => $access_token,
        );

        // Optional test event code (Meta Events Manager > Test Events).
        if (!empty($this->platforms['meta_test_event_code'])) {
            $body['test_event_code'] = sanitize_text_field($this->platforms['meta_test_event_code']);
        }

        return $this->dispatch_with_retry($url, $body, 'meta');
    }

    /**
     * Dispatch request.
     *
     * The request is always blocking with a short (2s) timeout. Non-blocking
     * fire-and-forget is intentionally not used: with WP's cURL transport the
     * request is aborted before the TLS handshake completes, so it never
     * reaches Meta. The REST endpoint is called via async fetch from the
     * browser, so a short blocking request does not affect the user experience.
     *
     * Retries: Meta CAPI dedupes on event_id, so retrying is safe. 2 attempts
     * by default (backoff 200ms); 3 attempts (200ms / 600ms) when
     * capi_debug_logging_enabled is set. Retries on WP_Error or HTTP >= 500;
     * final failure is logged when debug logging is enabled.
     *
     * @param string $url
     * @param array  $body
     * @param string $context
     * @return bool True on 2xx response; false otherwise.
     */
    private function dispatch_with_retry($url, $body, $context = 'meta') {
        $debug = !empty($this->advanced['capi_debug_logging_enabled']);

        $max_attempts = $debug ? 3 : 2; // event_id dedup makes retries safe
        $backoffs_us  = array(200000, 600000); // microseconds: 200ms, 600ms
        $last_error   = '';
        $last_code    = 0;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = wp_remote_post($url, array(
                'timeout'  => 2,
                'blocking' => true,
                'headers'  => array('Content-Type' => 'application/json'),
                'body'     => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                $last_code  = 0;
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    return true;
                }
                if ($code < 500) {
                    // Non-retryable client error -- stop and log below.
                    $last_error = wp_remote_retrieve_response_message($response);
                    if (empty($last_error)) {
                        $last_error = 'HTTP ' . $code;
                    }
                    $last_code = $code;
                    break;
                }
                $last_error = wp_remote_retrieve_response_message($response);
                if (empty($last_error)) {
                    $last_error = 'HTTP ' . $code;
                }
                $last_code = $code;
            }

            if ($attempt < $max_attempts) {
                $sleep = isset($backoffs_us[$attempt - 1]) ? $backoffs_us[$attempt - 1] : 600000;
                usleep($sleep);
            }
        }

        // Final failure -- log if enabled.
        if (!empty($this->advanced['capi_debug_logging_enabled'])) {
            $log_file = WP_CONTENT_DIR . '/trackwp/capi-errors.log';
            TrackWP_Settings::ensure_log_dir(dirname($log_file));

            $label = ($context === 'meta') ? 'Meta' : $context;
            $entry = sprintf(
                "[%s] [%s] %s (http_code: %d)\n",
                gmdate('c'),
                $label,
                $last_error,
                $last_code
            );
            error_log($entry, 3, $log_file);
        }

        return false;
    }

    /**
     * Map internal event name to Meta standard event.
     */
    private function map_event_name($internal_name, $meta_event_type) {
        // If explicit Meta event type is set, use it
        if (!empty($meta_event_type) && $meta_event_type !== 'CustomEvent') {
            return $meta_event_type;
        }

        // Auto-map common names
        $map = array(
            'phone_click'  => 'Contact',
            'email_click'  => 'Contact',
            'form_submit'  => 'Lead',
            'purchase'     => 'Purchase',
            'add_to_cart'  => 'AddToCart',
            'view_item'    => 'ViewContent',
            'begin_checkout' => 'InitiateCheckout',
        );

        if (isset($map[$internal_name])) {
            return $map[$internal_name];
        }

        // Custom event -- return as-is
        return $internal_name;
    }

    /**
     * Get client IP, supporting proxies and Cloudflare.
     */
    private function get_client_ip() {
        $headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For can contain multiple IPs -- take the first
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
