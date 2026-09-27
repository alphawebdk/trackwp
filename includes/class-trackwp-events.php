<?php
defined('ABSPATH') || exit;

class TrackWP_Events {

    /**
     * Event names GA4 reserves for its own automatically collected events.
     * They are rejected when an event is saved and when one arrives at the
     * public /event endpoint: forwarding them through the Measurement
     * Protocol would double-count gtag's own page_view/session data.
     */
    const RESERVED_PUBLIC_NAMES = array( 'page_view', 'session_start', 'first_visit', 'user_engagement' );

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
     * Get config for a specific event by name, whether it is enabled or not.
     *
     * The proxy needs to tell "configured but switched off" (skip, send
     * nothing) apart from "not configured at all" (legacy pass-through), which
     * get_event_config() cannot do.
     *
     * @param string $event_name
     * @return array|null
     */
    public function find_event($event_name) {
        if (!is_array($this->events)) {
            return null;
        }
        foreach ($this->events as $event) {
            if (is_array($event) && isset($event['name']) && $event['name'] === $event_name) {
                return $event;
            }
        }
        return null;
    }

    /**
     * Is the name one of GA4's reserved automatically collected events?
     *
     * @param string $event_name
     * @return bool
     */
    public static function is_reserved_name($event_name) {
        return in_array((string) $event_name, self::RESERVED_PUBLIC_NAMES, true);
    }

    /**
     * Get only enabled events (for frontend).
     */
    public function get_active_events() {
        if (!is_array($this->events)) {
            return array();
        }
        return array_values(array_filter($this->events, function($event) {
            return is_array($event) && !empty($event['enabled']);
        }));
    }

    /**
     * Get JS-safe config for active events (used by wp_localize_script).
     *
     * This is the single source of truth for what trackwp.js receives — in
     * particular `send_to`, which the client MUST honour so the Meta Pixel and
     * the Google Ads gtag conversion do not fire for events the admin routed
     * away from those platforms.
     *
     * @return array
     */
    public function get_client_config() {
        $config = array();
        foreach ($this->get_active_events() as $event) {
            $send_to = isset($event['send_to']) && is_array($event['send_to']) ? $event['send_to'] : null;

            // Always ship a firing-trigger list: options stored before 1.9.0
            // have none, and the browser evaluator only reads this key.
            $triggers = isset($event['firing_triggers']) && is_array($event['firing_triggers'])
                ? TrackWP_Conditions::validate_triggers($event['firing_triggers'])
                : array();
            if (empty($triggers)) {
                $triggers = TrackWP_Conditions::triggers_from_legacy_event($event);
            }

            $config[] = array(
                'name'         => isset($event['name']) ? (string) $event['name'] : '',
                'triggers'     => $triggers,
                'trigger_type' => isset($event['trigger_type']) ? (string) $event['trigger_type'] : '',
                'css_selector' => isset($event['css_selector']) ? (string) $event['css_selector'] : '',
                'url_match'    => isset($event['url_match']) ? (string) $event['url_match'] : '',
                'scroll_depth' => isset($event['scroll_depth']) ? (int) $event['scroll_depth'] : 0,
                'time_seconds' => isset($event['time_seconds']) ? (int) $event['time_seconds'] : 0,
                'js_event'     => isset($event['js_event']) ? (string) $event['js_event'] : '',
                'value'        => isset($event['value']) ? floatval($event['value']) : 0,
                'currency'     => isset($event['currency']) ? (string) $event['currency'] : 'DKK',
                'ads_label'    => isset($event['ads_label']) ? (string) $event['ads_label'] : '',
                'meta_event'   => isset($event['meta_event']) ? (string) $event['meta_event'] : '',
                // K8: the Meta event name the Pixel must use, resolved by the
                // same rule as CAPI so browser and server events deduplicate.
                'meta_resolved' => TrackWP_Meta::resolve_event_name(
                    isset($event['name']) ? (string) $event['name'] : '',
                    isset($event['meta_event']) ? (string) $event['meta_event'] : ''
                ),
                // null => legacy config without routing: client keeps the old
                // "send everywhere" behaviour (mirrors the server-side rule).
                'send_to'      => $send_to === null ? null : array(
                    'ga4'        => !empty($send_to['ga4']),
                    'google_ads' => !empty($send_to['google_ads']),
                    'meta'       => !empty($send_to['meta']),
                ),
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
        if (self::is_reserved_name($event['name'])) {
            return new WP_Error('reserved_event_name', sprintf(
                /* translators: %s: comma-separated list of reserved event names */
                __('Begivenhedsnavnet er reserveret af Google Analytics og kan ikke bruges: %s.', 'trackwp'),
                implode(', ', self::RESERVED_PUBLIC_NAMES)
            ));
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

        // Firing triggers (1.9.0). An event fires when ANY trigger matches;
        // a trigger matches when ALL its conditions are true (GTM's model).
        // Events saved before 1.9.0 have no list — derive one from the flat
        // fields so nothing is lost and both shapes keep working.
        if (isset($event['firing_triggers']) && is_array($event['firing_triggers'])) {
            $firing_triggers = TrackWP_Conditions::validate_triggers($event['firing_triggers']);
        } else {
            $firing_triggers = TrackWP_Conditions::triggers_from_legacy_event($event);
        }
        if (empty($firing_triggers)) {
            $firing_triggers = TrackWP_Conditions::triggers_from_legacy_event($event);
        }

        // Value must be numeric
        if (isset($event['value']) && $event['value'] !== '' && !is_numeric($event['value'])) {
            return new WP_Error('invalid_value', __('Konverteringsværdi skal være numerisk.', 'trackwp'));
        }

        // The flat trigger_* fields stay in sync with the FIRST firing trigger.
        // They are what pre-1.9.0 code (and the events table's summary row)
        // reads, so they must never drift from the authoritative list.
        $primary = $firing_triggers[0];

        $meta_event = isset($event['meta_event']) ? (string) $event['meta_event'] : '';
        if ( ! in_array($meta_event, array_keys(self::get_meta_event_types()), true) ) {
            $meta_event = '';
        }

        // Sanitize and return
        return array(
            'enabled'      => !empty($event['enabled']),
            'name'         => sanitize_key($event['name']),
            'display_name' => sanitize_text_field(isset($event['display_name']) ? $event['display_name'] : $event['name']),
            'trigger_type' => $primary['type'],
            'css_selector' => $primary['css_selector'],
            'url_match'    => $primary['url_match'],
            'scroll_depth' => $primary['scroll_depth'],
            'time_seconds' => $primary['time_seconds'],
            'js_event'     => $primary['js_event'],
            'firing_triggers' => $firing_triggers,
            'value'        => isset($event['value']) ? floatval($event['value']) : 0,
            'currency'     => sanitize_text_field(isset($event['currency']) ? strtoupper(substr($event['currency'], 0, 3)) : 'DKK'),
            'ads_label'    => sanitize_text_field(isset($event['ads_label']) ? $event['ads_label'] : ''),
            // The isset() guard only covered the test, not the value that was
            // returned — with meta_event absent the '' branch still read the
            // missing key and emitted an "Undefined array key" warning on PHP 8.
            'meta_event'   => $meta_event,
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
            // Fired programmatically by TrackWP_WooCommerce, never bound in the
            // browser: initAutoDetect() has no case for this type, so it binds
            // nothing. Page-scope conditions still apply, which lets an admin
            // restrict a shop event to certain URLs.
            'woocommerce'   => __('WooCommerce (styres på WooCommerce-fanen)', 'trackwp'),
        );
    }

    /**
     * Event names TrackWP_WooCommerce can emit, in funnel order.
     *
     * @return array
     */
    public static function get_woocommerce_event_names() {
        return array('view_item', 'add_to_cart', 'begin_checkout', 'purchase');
    }

    /**
     * Templates for the WooCommerce shop events.
     *
     * These are seeded into `trackwp_events` (not a separate option) so shop
     * events inherit the whole existing pipeline: per-event `send_to` routing,
     * the Meta event mapping, the Google Ads label, the delivery log and the
     * dedup mode. The WooCommerce tab configures only where the VALUE and the
     * item data come from.
     *
     * `value` stays 0: the real amount is passed per event as a `sendEvent`
     * param, which overrides the config default (see trackwp.js).
     *
     * @return array Keyed by event name.
     */
    public static function get_woocommerce_event_templates() {
        $base = array(
            'enabled'      => true,
            'trigger_type' => 'woocommerce',
            'css_selector' => '',
            'value'        => 0,
            'currency'     => 'DKK',
            'ads_label'    => '',
            'send_to'      => array('ga4' => true, 'google_ads' => false, 'meta' => true),
        );

        return array(
            'view_item' => array_merge($base, array(
                'name'         => 'view_item',
                'display_name' => 'View Item',
                'meta_event'   => 'ViewContent',
            )),
            'add_to_cart' => array_merge($base, array(
                'name'         => 'add_to_cart',
                'display_name' => 'Add To Cart',
                'meta_event'   => 'AddToCart',
            )),
            'begin_checkout' => array_merge($base, array(
                'name'         => 'begin_checkout',
                'display_name' => 'Begin Checkout',
                'meta_event'   => 'InitiateCheckout',
            )),
            // Purchase defaults to Google Ads ON: it is the one shop event a
            // store actually wants as a conversion. It still needs an
            // ads_label before the client-side gtag conversion fires.
            'purchase' => array_merge($base, array(
                'name'         => 'purchase',
                'display_name' => 'Purchase',
                'meta_event'   => 'Purchase',
                'send_to'      => array('ga4' => true, 'google_ads' => true, 'meta' => true),
            )),
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
