<?php
defined('ABSPATH') || exit;

class TrackWP_Events {

    private $events = array();

    public function __construct() {
        $this->events = get_option('trackwp_events', self::get_defaults());
    }

    /**
     * Default events created on plugin activation.
     */
    public static function get_defaults() {
        return array(
            array(
                'enabled'      => true,
                'name'         => 'phone_click',
                'display_name' => 'Phone Click',
                'trigger_type' => 'css_click',
                'css_selector' => 'a[href^="tel:"]',
                'value'        => 0,
                'currency'     => 'DKK',
                'ads_label'    => '',
                'meta_event'   => 'Contact',
                'send_to'      => array( 'ga4' => true, 'google_ads' => true, 'meta' => true ),
            ),
            array(
                'enabled'      => true,
                'name'         => 'email_click',
                'display_name' => 'Email Click',
                'trigger_type' => 'css_click',
                'css_selector' => 'a[href^="mailto:"]',
                'value'        => 0,
                'currency'     => 'DKK',
                'ads_label'    => '',
                'meta_event'   => 'Contact',
                'send_to'      => array( 'ga4' => true, 'google_ads' => true, 'meta' => true ),
            ),
            array(
                'enabled'      => true,
                'name'         => 'form_submit',
                'display_name' => 'Form Submit',
                'trigger_type' => 'form_submit',
                'css_selector' => '',
                'value'        => 0,
                'currency'     => 'DKK',
                'ads_label'    => '',
                'meta_event'   => 'Lead',
                'send_to'      => array( 'ga4' => true, 'google_ads' => true, 'meta' => true ),
            ),
        );
    }

    /**
     * Get config for a specific event by name. Returns null if not found or disabled.
     */
    public function get_event_config($event_name) {
        foreach ($this->events as $event) {
            if ($event['name'] === $event_name && !empty($event['enabled'])) {
                return $event;
            }
        }
        return null;
    }

    /**
     * Get all events (for admin page).
     */
    public function get_all_events() {
        return $this->events;
    }

    /**
     * Get only enabled events (for frontend).
     */
    public function get_active_events() {
        return array_filter($this->events, function($event) {
            return !empty($event['enabled']);
        });
    }

    /**
     * Get JS-safe config for active events (used by wp_localize_script).
     * Only includes fields needed client-side.
     */
    public function get_client_config() {
        $config = array();
        foreach ($this->get_active_events() as $event) {
            $config[] = array(
                'name'         => $event['name'],
                'trigger_type' => $event['trigger_type'],
                'css_selector' => isset($event['css_selector']) ? $event['css_selector'] : '',
                'value'        => floatval($event['value']),
                'currency'     => $event['currency'],
                'ads_label'    => isset($event['ads_label']) ? $event['ads_label'] : '',
                'meta_event'   => isset($event['meta_event']) ? $event['meta_event'] : '',
                'send_to'      => isset($event['send_to']) ? $event['send_to'] : array(),
            );
        }
        return $config;
    }

    /**
     * Validate a single event config array.
     * Returns sanitized event or WP_Error.
     */
    public static function validate_event($event) {
        // Name: required, lowercase alphanumeric + underscores, starts with letter, max 40 chars (GA4 limit)
        if (empty($event['name']) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $event['name'])) {
            return new WP_Error('invalid_event_name', __('Begivenhedsnavnet skal starte med et bogstav og kun indeholde små bogstaver, tal og underscores (maks. 40 tegn).', 'trackwp'));
        }

        // Trigger type: must be in allowed list
        $allowed_triggers = array_keys(self::get_trigger_types());
        $trigger = isset($event['trigger_type']) ? $event['trigger_type'] : '';
        if (!in_array($trigger, $allowed_triggers, true)) {
            return new WP_Error('invalid_trigger', __('Ugyldig trigger-type.', 'trackwp'));
        }

        // CSS selector required for css_click trigger
        if ($trigger === 'css_click' && empty($event['css_selector'])) {
            return new WP_Error('missing_selector', __('CSS-selector er påkrævet for klik-triggere.', 'trackwp'));
        }

        // Value must be numeric
        if (isset($event['value']) && $event['value'] !== '' && !is_numeric($event['value'])) {
            return new WP_Error('invalid_value', __('Konverteringsværdi skal være numerisk.', 'trackwp'));
        }

        // Sanitize and return
        return array(
            'enabled'      => !empty($event['enabled']),
            'name'         => sanitize_key($event['name']),
            'display_name' => sanitize_text_field(isset($event['display_name']) ? $event['display_name'] : $event['name']),
            'trigger_type' => sanitize_key($trigger),
            'css_selector' => sanitize_text_field(isset($event['css_selector']) ? $event['css_selector'] : ''),
            'url_match'    => isset($event['url_match']) ? esc_url_raw($event['url_match']) : '',
            'scroll_depth' => isset($event['scroll_depth']) ? absint($event['scroll_depth']) : 0,
            'time_seconds' => isset($event['time_seconds']) ? absint($event['time_seconds']) : 0,
            'js_event'     => sanitize_text_field(isset($event['js_event']) ? $event['js_event'] : ''),
            'value'        => isset($event['value']) ? floatval($event['value']) : 0,
            'currency'     => sanitize_text_field(isset($event['currency']) ? strtoupper(substr($event['currency'], 0, 3)) : 'DKK'),
            'ads_label'    => sanitize_text_field(isset($event['ads_label']) ? $event['ads_label'] : ''),
            'meta_event'   => in_array(isset($event['meta_event']) ? $event['meta_event'] : '', array_keys(self::get_meta_event_types()), true) ? $event['meta_event'] : '',
            'send_to'      => array(
                'ga4'        => !empty($event['send_to']['ga4']),
                'google_ads' => !empty($event['send_to']['google_ads']),
                'meta'       => !empty($event['send_to']['meta']),
            ),
        );
    }

    /**
     * Available trigger types for admin select.
     */
    public static function get_trigger_types() {
        return array(
            'css_click'     => __('Klik på CSS-selector', 'trackwp'),
            'form_submit'   => __('Formularindsendelse', 'trackwp'),
            'url_match'     => __('Sidevisning (URL match)', 'trackwp'),
            'scroll_depth'  => __('Scrolldybde', 'trackwp'),
            'time_on_page'  => __('Tid på siden', 'trackwp'),
            'js_event'      => __('JavaScript-event', 'trackwp'),
            'file_download' => __('Fildownload', 'trackwp'),
        );
    }

    /**
     * Standard Meta event types for admin select.
     */
    public static function get_meta_event_types() {
        return array(
            ''                     => __('— Ingen —', 'trackwp'),
            'Contact'              => 'Contact',
            'Lead'                 => 'Lead',
            'Purchase'             => 'Purchase',
            'AddToCart'            => 'AddToCart',
            'ViewContent'          => 'ViewContent',
            'InitiateCheckout'     => 'InitiateCheckout',
            'AddPaymentInfo'       => 'AddPaymentInfo',
            'CompleteRegistration' => 'CompleteRegistration',
            'Search'               => 'Search',
            'Subscribe'            => 'Subscribe',
            'CustomEvent'          => __('Tilpasset begivenhed', 'trackwp'),
        );
    }

    /**
     * Common currencies for admin select.
     */
    public static function get_currencies() {
        return array(
            'DKK' => 'DKK — Danish Krone',
            'EUR' => 'EUR — Euro',
            'USD' => 'USD — US Dollar',
            'GBP' => 'GBP — British Pound',
            'SEK' => 'SEK — Swedish Krona',
            'NOK' => 'NOK — Norwegian Krone',
            'CHF' => 'CHF — Swiss Franc',
            'PLN' => 'PLN — Polish Zloty',
            'CZK' => 'CZK — Czech Koruna',
        );
    }
}
