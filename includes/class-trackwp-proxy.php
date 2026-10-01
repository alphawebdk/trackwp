<?php
defined('ABSPATH') || exit;

class TrackWP_Proxy {

    /**
     * Total wall-clock budget in seconds for platform dispatch in one public
     * request (K1). Each adapter receives what is left as its timeout.
     */
    const TIME_BUDGET = 4.0;

    /** Below this many seconds of remaining budget an adapter is not called. */
    const MIN_ADAPTER_TIMEOUT = 0.25;

    /** Statuses a destination result may carry (K1). */
    const RESULT_STATUSES = array( 'ok', 'failed', 'unknown', 'skipped', 'queued', 'duplicate' );

    /** Reasons a destination result may carry (K1 + R21 + TR4/KC8). */
    const RESULT_REASONS = array(
        'sent', 'batched', 'no_consent', 'not_configured', 'routed_off', 'event_disabled',
        'no_click_id', 'bot', 'client_only', 'stale_version', 'invalid_order_ref',
        'order_not_countable', 'already_claimed', 'timeout', 'transport', 'http_4xx',
        'http_5xx', 'http_429', 'partial_failure', 'claim_error', 'unverified_purchase',
        'ga4_via_gtm',
    );

    /** Destinations in dispatch order. */
    const DESTINATIONS = array( 'ga4', 'meta', 'google_ads' );

    /**
     * The single table of accepted event fields (K2).
     *
     * - type:     how the value is sanitised (see sanitize_field())
     * - category: none | analytics | marketing | any (see strip_by_category())
     * - key:      key in $event_data when it differs from the REST name
     * - required: REST-required
     *
     * An invalid value is DROPPED (sanitised to null), not rejected: a single
     * malformed optional field from a cached or third-party client must not
     * lose the whole event. Only `event` and `consent` are validated hard.
     */
    const EVENT_FIELDS = array(
        'event'              => array( 'type' => 'event_name', 'category' => 'none', 'required' => true ),
        'event_id'           => array( 'type' => 'pattern', 'pattern' => '/^evt_[a-f0-9]{16,64}$/', 'category' => 'none' ),
        'value'              => array( 'type' => 'float_nonneg', 'category' => 'none' ),
        'currency'           => array( 'type' => 'currency', 'category' => 'none' ),
        'page_url'           => array( 'type' => 'url', 'max' => 2048, 'category' => 'none' ),
        'page_title'         => array( 'type' => 'text', 'max' => 300, 'category' => 'none' ),
        'page_referrer'      => array( 'type' => 'url', 'max' => 2048, 'category' => 'analytics' ),
        'client_id'          => array( 'type' => 'pattern', 'pattern' => '/^\d+\.\d+$/', 'category' => 'analytics' ),
        'session_id'         => array( 'type' => 'pattern', 'pattern' => '/^\d{1,12}$/', 'category' => 'analytics' ),
        '_ga'                => array( 'type' => 'text', 'max' => 200, 'category' => 'analytics', 'key' => 'ga_cookie' ),
        'ga_session_cookie'  => array( 'type' => 'text', 'max' => 200, 'category' => 'analytics' ),
        'ga_session_cookies' => array( 'type' => 'ga_session_cookies', 'category' => 'analytics' ),
        'engaged_ms'         => array( 'type' => 'int_range', 'min' => 0, 'max' => 3600000, 'category' => 'analytics' ),
        'user_agent'         => array( 'type' => 'text', 'max' => 500, 'category' => 'any' ),
        'fbp'                => array( 'type' => 'text', 'max' => 200, 'category' => 'marketing' ),
        'fbc'                => array( 'type' => 'text', 'max' => 200, 'category' => 'marketing' ),
        'gclid'              => array( 'type' => 'pattern', 'pattern' => '/^[A-Za-z0-9_\-]{1,200}$/', 'category' => 'marketing' ),
        'gbraid'             => array( 'type' => 'pattern', 'pattern' => '/^[A-Za-z0-9_\-]{1,200}$/', 'category' => 'marketing' ),
        'wbraid'             => array( 'type' => 'pattern', 'pattern' => '/^[A-Za-z0-9_\-]{1,200}$/', 'category' => 'marketing' ),
        'enhanced'           => array( 'type' => 'enhanced', 'category' => 'marketing' ),
        'ecommerce'          => array( 'type' => 'ecommerce', 'category' => 'none' ),
        'form_id'            => array( 'type' => 'pattern', 'pattern' => '/^[A-Za-z0-9_\-:]{1,64}$/', 'category' => 'none' ),
        'form_name'          => array( 'type' => 'form_name', 'max' => 100, 'category' => 'none' ),
        'order_ref'          => array( 'type' => 'pattern', 'pattern' => '/^\d+\.[a-f0-9]{24}$/', 'category' => 'none' ),
        'consent'            => array( 'type' => 'consent', 'category' => 'none' ),
    );

    /**
     * Categories of $event_data keys that are derived on the server rather
     * than taken from a REST field of the same name. Used by the final filter.
     */
    const DERIVED_CATEGORIES = array(
        'gcl_au'      => 'marketing',
        'external_id' => 'marketing',
    );

    /** K2a: keys accepted inside `enhanced`. */
    const ENHANCED_KEYS = array(
        'email', 'phone', 'email_sha256', 'email_meta_sha256', 'phone_sha256',
        'phone_e164_sha256', 'first_name', 'last_name', 'city', 'zip', 'country',
    );

    /** K2a: pre-hashed keys, accepted only as 64 hex characters. */
    const ENHANCED_HASH_KEYS = array( 'email_sha256', 'email_meta_sha256', 'phone_sha256', 'phone_e164_sha256' );

    /**
     * Register the REST route.
     */
    public function register_routes() {
        $advanced = get_option('trackwp_advanced', array());
        $slug = isset($advanced['endpoint_path']) ? $advanced['endpoint_path'] : 'event';
        $slug = TrackWP_Settings::sanitize_endpoint_slug($slug);

        register_rest_route('trackwp/v1', '/' . $slug, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'handle_event'),
            'permission_callback' => array($this, 'check_permission'),
            'args'                => self::rest_args(),
        ));

        // First-party cookie keepalive — renews ITP-durable cookies via HTTP Set-Cookie on each page load.
        register_rest_route('trackwp/v1', '/keepalive', array(
            'methods'             => array('GET', 'POST'),
            'callback'            => array($this, 'handle_keepalive'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // GDPR data subject access — returns consent state stored in user's cookies + any server log.
        register_rest_route( 'trackwp/v1', '/my-data', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_my_data_get' ),
                'permission_callback' => array( $this, 'check_origin_only' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'handle_my_data_delete' ),
                'permission_callback' => array( $this, 'check_origin_only' ),
            ),
        ) );
    }

    /**
     * REST args for the event route, generated from EVENT_FIELDS.
     *
     * No `type` is declared: WordPress would then run its own schema
     * coercion first (rest_sanitize_boolean turns "false" into false and "1"
     * into true), which is exactly what K3 forbids for `consent`. Every field
     * goes through sanitize_field() instead.
     *
     * @return array
     */
    public static function rest_args() {
        $args = array();
        foreach ( self::EVENT_FIELDS as $name => $spec ) {
            $arg = array(
                'required'          => ! empty( $spec['required'] ),
                'sanitize_callback' => function( $value ) use ( $name ) {
                    return TrackWP_Proxy::sanitize_field( $name, $value );
                },
            );
            if ( 'event' === $name ) {
                $arg['validate_callback'] = function( $value ) {
                    return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $value );
                };
            }
            if ( 'consent' === $name ) {
                // R2: pass-through. Sub-fields are read raw from the JSON body
                // and compared with === true (see handle_event()).
                $arg['validate_callback'] = function( $value ) {
                    return is_array( $value );
                };
            }
            $args[ $name ] = $arg;
        }
        // ga4_route (KC8/TR4): routing-only REST argument for the dataLayer
        // layer. It is deliberately NOT part of EVENT_FIELDS: it must never
        // reach $event_data, the trackwp_event_data filter or any platform
        // payload — only route() (below) ever reads it.
        $args['ga4_route'] = array(
            'required'          => false,
            'sanitize_callback' => function( $value ) {
                return ( is_string( $value ) && in_array( $value, array( 'gtm', 'server', 'off' ), true ) ) ? $value : null;
            },
        );
        return $args;
    }

    /**
     * Sanitise one EVENT_FIELDS value. Returns null when the value is invalid
     * so the field is simply absent downstream.
     *
     * @param string $name  Field name (key of EVENT_FIELDS).
     * @param mixed  $value Raw value.
     * @return mixed
     */
    public static function sanitize_field( $name, $value ) {
        if ( ! isset( self::EVENT_FIELDS[ $name ] ) ) {
            return null;
        }
        $spec = self::EVENT_FIELDS[ $name ];

        switch ( $spec['type'] ) {
            case 'consent':
                return is_array( $value ) ? $value : array();

            case 'event_name':
                return ( is_string( $value ) && preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $value ) ) ? $value : null;

            case 'pattern':
                if ( ! is_scalar( $value ) || is_bool( $value ) ) {
                    return null;
                }
                $value = trim( (string) $value );
                return preg_match( $spec['pattern'], $value ) ? $value : null;

            case 'float_nonneg':
                if ( ! is_numeric( $value ) ) {
                    return null;
                }
                $value = (float) $value;
                return ( $value >= 0 && is_finite( $value ) ) ? $value : null;

            case 'int_range':
                if ( ! is_numeric( $value ) ) {
                    return null;
                }
                $value = (int) $value;
                return ( $value >= $spec['min'] && $value <= $spec['max'] ) ? $value : null;

            case 'currency':
                if ( ! is_string( $value ) ) {
                    return null;
                }
                $value = strtoupper( trim( $value ) );
                return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : null;

            case 'url':
                if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $spec['max'] ) {
                    return null;
                }
                $url    = esc_url_raw( $value, array( 'http', 'https' ) );
                return '' === $url ? null : $url;

            case 'text':
                if ( ! is_scalar( $value ) || is_bool( $value ) ) {
                    return null;
                }
                $value = sanitize_text_field( (string) $value );
                if ( '' === $value ) {
                    return null;
                }
                return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $spec['max'] ) : substr( $value, 0, $spec['max'] );

            case 'form_name':
                if ( ! is_scalar( $value ) || is_bool( $value ) ) {
                    return null;
                }
                $value = TrackWP_Privacy::clean_title( sanitize_text_field( (string) $value ) );
                $value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $spec['max'] ) : substr( $value, 0, $spec['max'] );
                return '' === $value ? null : $value;

            case 'ga_session_cookies':
                if ( ! is_array( $value ) ) {
                    return array();
                }
                $out = array();
                foreach ( $value as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $id  = isset( $item['id'] ) && is_scalar( $item['id'] ) ? substr( sanitize_text_field( (string) $item['id'] ), 0, 64 ) : '';
                    $val = isset( $item['value'] ) && is_scalar( $item['value'] ) ? substr( sanitize_text_field( (string) $item['value'] ), 0, 200 ) : '';
                    if ( '' !== $id && '' !== $val ) {
                        $out[] = array( 'id' => $id, 'value' => $val );
                    }
                    if ( count( $out ) >= 10 ) {
                        break;
                    }
                }
                return $out;

            case 'enhanced':
                return self::sanitize_enhanced( $value );

            case 'ecommerce':
                return self::sanitize_ecommerce( $value );
        }

        return null;
    }

    /**
     * K2a schema for `enhanced`: key allowlist, pre-hashed keys only as 64 hex
     * characters under their own names, and a raw `email` that is 64 hex is
     * dropped (it must never be mistaken for a hash).
     *
     * @param mixed $raw
     * @return array
     */
    public static function sanitize_enhanced( $raw ) {
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( self::ENHANCED_KEYS as $key ) {
            if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) || is_bool( $raw[ $key ] ) ) {
                continue;
            }
            $value = trim( (string) $raw[ $key ] );
            if ( '' === $value || strlen( $value ) > 200 ) {
                continue;
            }
            $is_hex64 = (bool) preg_match( '/^[a-f0-9]{64}$/i', $value );
            if ( in_array( $key, self::ENHANCED_HASH_KEYS, true ) ) {
                if ( $is_hex64 ) {
                    $out[ $key ] = strtolower( $value );
                }
                continue;
            }
            if ( $is_hex64 ) {
                // A 64-hex value under a raw key is neither a usable raw
                // value nor a trustworthy hash.
                continue;
            }
            $out[ $key ] = sanitize_text_field( $value );
        }
        return $out;
    }

    /** Hard cap on ecommerce line items per event. */
    const MAX_ECOMMERCE_ITEMS = 50;

    /** Max length of a single ecommerce string field (GA4 caps param values at 100). */
    const MAX_ECOMMERCE_STR = 100;

    /**
     * Sanitise an ecommerce payload.
     *
     * This is the ONLY place ecommerce data is validated. The GA4 class passes
     * `items` straight into the Measurement Protocol body and the Meta class
     * maps it into `custom_data.contents`, so anything not filtered here would
     * reach Google and Meta verbatim.
     *
     * Rules:
     *  - unknown item keys are dropped (whitelist, not blacklist)
     *  - GA4 requires item_id or item_name on every item; items with neither
     *    are dropped rather than sent and silently rejected
     *  - numbers are cast, strings are sanitised and length-capped
     *  - at most MAX_ECOMMERCE_ITEMS items survive
     *
     * No unslashing happens here: REST JSON bodies are decoded straight from
     * the request and are never slashed by WordPress. Calling stripslashes()
     * on them would corrupt legitimate values.
     *
     * @param mixed $raw Raw ecommerce object from the request (or built in PHP).
     * @return array Empty array when there is nothing usable.
     */
    public static function sanitize_ecommerce( $raw ) {
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return array();
        }

        $out = array();

        // Whitelisted item fields => cast. GA4 item spec field names.
        $string_fields = array(
            'item_id', 'item_name', 'item_brand', 'item_category', 'item_category2',
            'item_category3', 'item_category4', 'item_category5', 'item_variant',
            'coupon', 'affiliation', 'item_list_id', 'item_list_name',
        );
        $float_fields = array( 'price', 'discount' );

        if ( ! empty( $raw['items'] ) && is_array( $raw['items'] ) ) {
            $items = array();
            foreach ( $raw['items'] as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $clean = array();
                foreach ( $string_fields as $field ) {
                    if ( isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
                        $value = substr( sanitize_text_field( (string) $item[ $field ] ), 0, self::MAX_ECOMMERCE_STR );
                        if ( '' !== $value ) {
                            $clean[ $field ] = $value;
                        }
                    }
                }
                foreach ( $float_fields as $field ) {
                    if ( isset( $item[ $field ] ) && is_numeric( $item[ $field ] ) ) {
                        $clean[ $field ] = round( (float) $item[ $field ], 6 );
                    }
                }
                // quantity is only kept when positive. A zero or negative
                // quantity is malformed input, and clamping it to 1 would
                // invent a sale that did not happen; omitting the key lets GA4
                // apply its own default of 1 instead.
                if ( isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ) {
                    $quantity = (int) $item['quantity'];
                    if ( $quantity > 0 ) {
                        $clean['quantity'] = $quantity;
                    }
                }

                // index is a list position, where 0 is legitimate.
                if ( isset( $item['index'] ) && is_numeric( $item['index'] ) ) {
                    $clean['index'] = max( 0, (int) $item['index'] );
                }

                // GA4 drops items without an identifier — do not send them.
                if ( empty( $clean['item_id'] ) && empty( $clean['item_name'] ) ) {
                    continue;
                }

                $items[] = $clean;
                if ( count( $items ) >= self::MAX_ECOMMERCE_ITEMS ) {
                    break;
                }
            }
            if ( ! empty( $items ) ) {
                $out['items'] = $items;
            }
        }

        if ( isset( $raw['transaction_id'] ) && is_scalar( $raw['transaction_id'] ) ) {
            $transaction_id = substr( sanitize_text_field( (string) $raw['transaction_id'] ), 0, 64 );
            if ( '' !== $transaction_id ) {
                $out['transaction_id'] = $transaction_id;
            }
        }

        if ( isset( $raw['coupon'] ) && is_scalar( $raw['coupon'] ) ) {
            $coupon = substr( sanitize_text_field( (string) $raw['coupon'] ), 0, self::MAX_ECOMMERCE_STR );
            if ( '' !== $coupon ) {
                $out['coupon'] = $coupon;
            }
        }

        // R15: order-level amounts. GA4 uses value_ga4 (+ tax + shipping);
        // Meta and Google Ads keep `value` (value_basis).
        foreach ( array( 'value_ga4', 'tax', 'shipping' ) as $field ) {
            if ( isset( $raw[ $field ] ) && is_numeric( $raw[ $field ] ) ) {
                $amount = (float) $raw[ $field ];
                if ( $amount >= 0 && is_finite( $amount ) ) {
                    $out[ $field ] = round( $amount, 6 );
                }
            }
        }

        if ( isset( $raw['order_ref'] ) && is_string( $raw['order_ref'] ) && preg_match( '/^\d+\.[a-f0-9]{24}$/', $raw['order_ref'] ) ) {
            $out['order_ref'] = $raw['order_ref'];
        }

        return $out;
    }

    /**
     * Permission check for the tracking endpoints: same-site origin plus a
     * per-IP rate limit of 20 requests per 2-second window.
     * No nonce: cached pages serve stale nonces, and the endpoint is public.
     */
    public function check_permission($request) {
        return TrackWP_Request_Guard::permission( 'event', 20 );
    }

    /**
     * Permission check for the GDPR endpoints: same-site origin plus a lower
     * rate limit (5 requests per 2-second window).
     */
    public function check_origin_only( $request ) {
        return TrackWP_Request_Guard::permission( 'gdpr', 5 );
    }

    /**
     * Final filter (K2 step 5, first half): drop every field the effective
     * consent does not allow, reduce page_url to origin + path without any
     * consent, and remove click-ID parameters from URLs without marketing.
     *
     * Runs AFTER the trackwp_event_data filter so an extension cannot
     * re-introduce identifiers.
     *
     * @param array $event_data
     * @param array $effective  Keys analytics, marketing (bool).
     * @return array
     */
    public static function strip_by_category( $event_data, $effective ) {
        if ( ! is_array( $event_data ) ) {
            return array();
        }
        $analytics = ! empty( $effective['analytics'] ) && true === $effective['analytics'];
        $marketing = ! empty( $effective['marketing'] ) && true === $effective['marketing'];

        $categories = self::DERIVED_CATEGORIES;
        foreach ( self::EVENT_FIELDS as $name => $spec ) {
            $categories[ isset( $spec['key'] ) ? $spec['key'] : $name ] = $spec['category'];
        }

        foreach ( array_keys( $event_data ) as $key ) {
            if ( ! isset( $categories[ $key ] ) ) {
                continue;
            }
            $category = $categories[ $key ];
            $allowed  = ( 'none' === $category )
                || ( 'analytics' === $category && $analytics )
                || ( 'marketing' === $category && $marketing )
                || ( 'any' === $category && ( $analytics || $marketing ) );
            if ( ! $allowed ) {
                unset( $event_data[ $key ] );
            }
        }

        if ( isset( $event_data['enhanced'] ) && ! TrackWP_Hash::customer_data_sharing_enabled() ) {
            unset( $event_data['enhanced'] );
        }

        if ( ! $marketing ) {
            foreach ( TrackWP_Privacy::URL_FIELDS as $field ) {
                if ( isset( $event_data[ $field ] ) ) {
                    $event_data[ $field ] = TrackWP_Privacy::clean_url( (string) $event_data[ $field ], TrackWP_Privacy::CLICK_ID_PARAMS );
                }
            }
        }
        if ( ! $analytics && ! $marketing && isset( $event_data['page_url'] ) ) {
            $event_data['page_url'] = TrackWP_Privacy::origin_and_path( (string) $event_data['page_url'] );
        }

        return $event_data;
    }

    /**
     * Read the raw consent object from the request (R2): JSON body first so
     * no REST coercion can have touched the sub-fields.
     *
     * @param WP_REST_Request $request
     * @return mixed Array, or null when absent.
     */
    private static function raw_consent( $request ) {
        $json = $request->get_json_params();
        if ( is_array( $json ) && array_key_exists( 'consent', $json ) ) {
            return $json['consent'];
        }
        return $request->get_param( 'consent' );
    }

    /**
     * Response helper: every answer from the public endpoints is no-store.
     *
     * @param array $data
     * @param int   $status
     * @return WP_REST_Response
     */
    private static function respond( $data, $status = 200 ) {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }

    /**
     * Build a K1 result.
     *
     * @return array
     */
    private static function result( $destination, $status, $reason, $http_code = 0, $attempts = 0, $detail = '' ) {
        return array(
            'destination' => $destination,
            'status'      => $status,
            'reason'      => $reason,
            'http_code'   => (int) $http_code,
            'attempts'    => (int) $attempts,
            'detail'      => substr( (string) $detail, 0, 500 ),
        );
    }

    /**
     * Normalise whatever an adapter returned into a K1 result. Adapters from
     * 1.10.0 return true / false / null (null = unknown outcome after a
     * timeout); those map to ok / failed / unknown.
     *
     * @param string $destination
     * @param mixed  $raw
     * @return array
     */
    public static function normalize_result( $destination, $raw ) {
        if ( is_array( $raw ) && isset( $raw['status'] ) ) {
            $status = in_array( $raw['status'], self::RESULT_STATUSES, true ) ? $raw['status'] : 'unknown';
            $reason = ( isset( $raw['reason'] ) && in_array( $raw['reason'], self::RESULT_REASONS, true ) ) ? $raw['reason'] : '';
            return self::result(
                $destination,
                $status,
                $reason,
                isset( $raw['http_code'] ) ? (int) $raw['http_code'] : 0,
                isset( $raw['attempts'] ) ? (int) $raw['attempts'] : 0,
                isset( $raw['detail'] ) && is_scalar( $raw['detail'] ) ? (string) $raw['detail'] : ''
            );
        }
        if ( true === $raw ) {
            return self::result( $destination, 'ok', 'sent', 0, 1 );
        }
        if ( null === $raw ) {
            return self::result( $destination, 'unknown', 'timeout', 0, 1 );
        }
        return self::result( $destination, 'failed', 'transport', 0, 1 );
    }

    /**
     * Handle incoming tracking event.
     *
     * Order (K2): schema -> effective consent (K3) -> build $event_data ->
     * trackwp_event_data filter -> final filter (strip_by_category +
     * TrackWP_Privacy::clean_event) -> routing -> purchase claim (R9) ->
     * dispatch within the time budget (K1).
     *
     * Always answers 200 (except for reserved names) with no-store.
     */
    public function handle_event($request) {
        $deadline   = microtime( true ) + self::TIME_BUDGET;
        $event_name = (string) $request->get_param('event');

        if ( TrackWP_Events::is_reserved_name( $event_name ) ) {
            return self::respond( array(
                'code'    => 'trackwp_reserved_event',
                'message' => __( 'Begivenhedsnavnet er reserveret af Google Analytics.', 'trackwp' ),
            ), 400 );
        }

        // --- 2. Effective consent (K3, R1, R2) ---------------------------
        $raw_consent = self::raw_consent( $request );
        $has_version = is_array( $raw_consent ) && array_key_exists( 'v', $raw_consent );
        $eff         = TrackWP_Consent::effective_consent( $raw_consent, $has_version );
        $effective   = array(
            'analytics' => isset( $eff['analytics'] ) && true === $eff['analytics'],
            'marketing' => isset( $eff['marketing'] ) && true === $eff['marketing'],
            'v'         => isset( $eff['v'] ) ? (int) $eff['v'] : 0,
            'source'    => isset( $eff['source'] ) ? (string) $eff['source'] : 'none',
            'stale'     => ! empty( $eff['stale'] ),
        );
        if ( $effective['stale'] ) {
            $effective['analytics'] = false;
            $effective['marketing'] = false;
        }
        $consent_flags = array( 'analytics' => $effective['analytics'], 'marketing' => $effective['marketing'] );

        // --- Event configuration ------------------------------------------
        $events_manager = new TrackWP_Events();
        $event_config   = $events_manager->find_event( $event_name );
        $known_event    = is_array( $event_config );
        $event_enabled  = ! $known_event || ! empty( $event_config['enabled'] );
        $stats_name     = $known_event ? $event_name : '_other';

        // The unscrubbed URL is kept locally, only for the fbclid fallback and
        // the legacy order-key lookup. It never enters $event_data.
        $raw_page_url = (string) $request->get_param( 'page_url' );

        $user_agent = (string) $request->get_param( 'user_agent' );
        if ( '' === $user_agent && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $user_agent = (string) self::sanitize_field( 'user_agent', wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        // --- Bots: logged and counted, never forwarded ----------------------
        if ( self::is_bot( $user_agent ) ) {
            $log_id = (string) $request->get_param( 'event_id' );
            TrackWP_Delivery_Log::record( $log_id, $event_name, 'received', 'skipped', $consent_flags, 0, 'bot' );
            TrackWP_Settings::record_event_hit( 'bot_skipped', $stats_name );
            $this->maybe_log( $event_name, $effective, array(), true );
            return self::respond( array( 'status' => 'ok', 'skipped' => 'bot' ) );
        }

        // --- 3. Build $event_data from EVENT_FIELDS -------------------------
        $event_data = $this->build_event_data( $request, $effective, $event_config, $user_agent, $raw_page_url );

        // --- Purchase authority (K5, R9, R11) --------------------------------
        $purchase = $this->resolve_purchase( $event_name, $event_data, $raw_page_url, $effective );
        if ( 'verified' === $purchase['mode'] ) {
            // The client's value, currency, ecommerce and enhanced are ignored.
            unset( $event_data['value'], $event_data['currency'], $event_data['ecommerce'], $event_data['enhanced'] );
            $event_data = array_merge( $event_data, $purchase['overrides'] );
        }

        // --- 4. Extension filter ---------------------------------------------
        $filtered = apply_filters( 'trackwp_event_data', $event_data, $effective );
        if ( is_array( $filtered ) ) {
            $event_data = $filtered;
        }
        // Identity of the event and the consent state are not the filter's to change.
        $event_data['event']    = $event_name;
        $event_data['event_id'] = $purchase['event_id_lock'] ? $purchase['event_id_lock'] : ( isset( $event_data['event_id'] ) && is_string( $event_data['event_id'] ) && preg_match( '/^evt_[a-f0-9]{16,64}$/', $event_data['event_id'] ) ? $event_data['event_id'] : TrackWP_Hash::generate_event_id() );
        $event_data['consent']  = $consent_flags;

        // --- 5. Final filter ---------------------------------------------------
        $event_data = self::strip_by_category( $event_data, $effective );
        $event_data = TrackWP_Privacy::clean_event( $event_data );

        // --- Cookies -------------------------------------------------------------
        $this->apply_cookies( $event_data, $effective );

        $log_id = $event_data['event_id'];
        TrackWP_Delivery_Log::record(
            $log_id,
            $event_name,
            'received',
            'ok',
            $consent_flags,
            0,
            'unverified' === $purchase['mode'] ? 'unverified_purchase' : ''
        );

        // --- 6. Routing ------------------------------------------------------------
        // ga4_route (KC8/TR4): the client's own routing claim for THIS push,
        // read straight from the REST param (already sanitised to
        // gtm|server|off or null). Never stored in $event_data.
        $ga4_route = $request->get_param( 'ga4_route' );
        $results   = $this->route( $event_name, $event_config, $event_enabled, $effective, $ga4_route );

        // Purchase gate: invalid order refs are never sent; a verified purchase
        // is claimed only when at least one destination will really be tried.
        $to_attempt = array_keys( array_filter( $results, function( $r ) { return null === $r; } ) );
        if ( ! empty( $to_attempt ) && 'skip' === $purchase['mode'] ) {
            foreach ( $to_attempt as $dest ) {
                $results[ $dest ] = self::result( $dest, 'skipped', $purchase['reason'] );
            }
            $to_attempt = array();
        }
        if ( ! empty( $to_attempt ) && 'verified' === $purchase['mode'] ) {
            $claim = TrackWP_Order_Claims::claim( $purchase['order_id'], 'purchase', 'browser' );
            if ( TrackWP_Order_Claims::CLAIMED !== $claim ) {
                $status = ( TrackWP_Order_Claims::DUPLICATE === $claim ) ? 'duplicate' : 'failed';
                $reason = ( TrackWP_Order_Claims::DUPLICATE === $claim ) ? 'already_claimed' : 'claim_error';
                foreach ( $to_attempt as $dest ) {
                    $results[ $dest ] = self::result( $dest, $status, $reason );
                }
                $to_attempt = array();
            }
        }

        // --- Dispatch within the budget ----------------------------------------------
        foreach ( $to_attempt as $dest ) {
            $remaining = $deadline - microtime( true );
            if ( $remaining < self::MIN_ADAPTER_TIMEOUT ) {
                $results[ $dest ] = self::result( $dest, 'failed', 'timeout', 0, 0, 'time budget exhausted before dispatch' );
                continue;
            }
            $results[ $dest ] = $this->dispatch( $dest, $event_data, $consent_flags, $remaining );
            if ( 'unverified' === $purchase['mode'] && '' === $results[ $dest ]['detail'] ) {
                $results[ $dest ]['detail'] = 'unverified_purchase';
            }
        }

        // --- Log and stats ---------------------------------------------------------------
        $forwarded = false;
        foreach ( $results as $dest => $result ) {
            if ( in_array( $result['status'], array( 'ok', 'queued' ), true ) ) {
                $forwarded = true;
            }
            // Destinations that are not configured on this site add nothing
            // to the delivery log but noise.
            if ( 'not_configured' === $result['reason'] ) {
                continue;
            }
            TrackWP_Delivery_Log::record( $log_id, $event_name, $dest, $result['status'], $consent_flags, $result['http_code'], $result['reason'] );
        }

        TrackWP_Settings::record_event_hit( 'events', $stats_name );
        if ( $forwarded ) {
            TrackWP_Settings::record_event_hit( 'forwarded', $stats_name );
        }

        $this->maybe_log( $event_name, $effective, $results, false );

        $data = array( 'status' => 'ok' );
        if ( $effective['stale'] ) {
            $data['consent']         = 'stale_version';
            $data['current_version'] = TrackWP_Consent::server_version();
        }
        return self::respond( $data );
    }

    /**
     * K2 step 3: build $event_data from EVENT_FIELDS, only with the
     * categories the effective consent allows, plus server-side cookie
     * fallbacks for the same categories.
     *
     * @param WP_REST_Request $request
     * @param array           $effective
     * @param array|null      $event_config
     * @param string          $user_agent
     * @param string          $raw_page_url
     * @return array
     */
    private function build_event_data( $request, $effective, $event_config, $user_agent, $raw_page_url ) {
        $event_data = array();
        foreach ( self::EVENT_FIELDS as $name => $spec ) {
            if ( 'consent' === $name ) {
                continue;
            }
            $value = $request->get_param( $name );
            if ( null === $value || '' === $value || array() === $value ) {
                continue;
            }
            $event_data[ isset( $spec['key'] ) ? $spec['key'] : $name ] = $value;
        }

        $event_data['value']    = isset( $event_data['value'] ) ? (float) $event_data['value'] : 0.0;
        $event_data['currency'] = isset( $event_data['currency'] ) ? $event_data['currency'] : 'DKK';
        if ( '' !== $user_agent ) {
            $event_data['user_agent'] = $user_agent;
        }
        $event_data['meta_event_name'] = ( is_array( $event_config ) && isset( $event_config['meta_event'] ) ) ? (string) $event_config['meta_event'] : '';

        if ( ! empty( $effective['analytics'] ) ) {
            if ( empty( $event_data['ga_cookie'] ) && ! empty( $_COOKIE['_ga'] ) ) {
                $event_data['ga_cookie'] = (string) self::sanitize_field( '_ga', wp_unslash( $_COOKIE['_ga'] ) );
            }
            if ( empty( $event_data['ga_session_cookie'] ) ) {
                foreach ( $_COOKIE as $ck_name => $ck_val ) {
                    if ( is_string( $ck_name ) && 0 === strpos( $ck_name, '_ga_' ) && is_string( $ck_val ) ) {
                        $event_data['ga_session_cookie'] = (string) self::sanitize_field( 'ga_session_cookie', wp_unslash( $ck_val ) );
                        break;
                    }
                }
            }
        }

        if ( ! empty( $effective['marketing'] ) ) {
            if ( ! empty( $_COOKIE['_gcl_au'] ) && is_string( $_COOKIE['_gcl_au'] ) ) {
                $event_data['gcl_au'] = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_au'] ) );
            }
            // gclid fallback from _gcl_aw (format GCL.<ts>.<gclid>).
            if ( empty( $event_data['gclid'] ) && empty( $event_data['gbraid'] ) && empty( $event_data['wbraid'] ) && ! empty( $_COOKIE['_gcl_aw'] ) && is_string( $_COOKIE['_gcl_aw'] ) ) {
                $parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) ) );
                if ( isset( $parts[2] ) ) {
                    $gclid = self::sanitize_field( 'gclid', $parts[2] );
                    if ( null !== $gclid ) {
                        $event_data['gclid'] = $gclid;
                    }
                }
            }
            foreach ( array( 'fbp' => '_fbp', 'fbc' => '_fbc' ) as $field => $cookie ) {
                if ( empty( $event_data[ $field ] ) && ! empty( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ) {
                    $clean = self::sanitize_field( $field, wp_unslash( $_COOKIE[ $cookie ] ) );
                    if ( null !== $clean ) {
                        $event_data[ $field ] = $clean;
                    }
                }
            }
            // fbc fallback: fbclid in the UNSCRUBBED page URL.
            if ( empty( $event_data['fbc'] ) ) {
                $fbclid = self::query_param( $raw_page_url, 'fbclid' );
                if ( '' !== $fbclid ) {
                    $fbc = TrackWP_Meta::fbc_from_fbclid( $fbclid );
                    if ( is_string( $fbc ) && '' !== $fbc ) {
                        $event_data['fbc'] = $fbc;
                        TrackWP_Cookies::set( '_fbc', $fbc, time() + TrackWP_Cookies::lifetime_days( '_fbc' ) * DAY_IN_SECONDS, 'registrable' );
                    }
                }
            }
        }

        if ( ! empty( $event_data['enhanced'] ) && is_array( $event_data['enhanced'] ) ) {
            $event_data['enhanced'] = TrackWP_Hash::normalize_enhanced( $event_data['enhanced'] );
        }

        // Only what the consent allows leaves this method; the final filter
        // repeats this after the extension hook.
        return self::strip_by_category( $event_data, $effective );
    }

    /**
     * Value of one query parameter in a URL, or ''.
     *
     * @param string $url
     * @param string $param
     * @return string
     */
    private static function query_param( $url, $param ) {
        $query = wp_parse_url( (string) $url, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            return '';
        }
        $vars = array();
        parse_str( $query, $vars );
        return ( isset( $vars[ $param ] ) && is_string( $vars[ $param ] ) ) ? $vars[ $param ] : '';
    }

    /**
     * Decide how a purchase is handled (K5, R9, R11), via
     * TrackWP_WooCommerce::authoritative_purchase(), which verifies the
     * order_ref (or, for a cached 1.10.0 client, the order key in the
     * UNSCRUBBED page URL plus a matching order number) and builds the
     * payload from the order. The claim itself is made here, after routing.
     *
     * Modes:
     *  - none:       not a WooCommerce purchase; ordinary event
     *  - verified:   the order was verified; overrides come from the order
     *  - skip:       did not verify (invalid_order_ref, order_not_countable);
     *                nothing is sent
     *  - unverified: a purchase without order_ref or order key (third party);
     *                sent as in 1.10.0 with the client payload, no claim
     *
     * @param string $event_name
     * @param array  $event_data   Already consent-filtered payload.
     * @param string $raw_page_url Unscrubbed page URL.
     * @param array  $effective    Effective consent (K3).
     * @return array
     */
    private function resolve_purchase( $event_name, $event_data, $raw_page_url, $effective ) {
        $out = array(
            'mode'          => 'none',
            'reason'        => '',
            'order_id'      => 0,
            'overrides'     => array(),
            'event_id_lock' => '',
        );
        if ( 'purchase' !== $event_name || ! function_exists( 'wc_get_order' ) || ! class_exists( 'TrackWP_WooCommerce' ) ) {
            return $out;
        }

        $authoritative = TrackWP_WooCommerce::authoritative_purchase(
            isset( $event_data['order_ref'] ) ? (string) $event_data['order_ref'] : '',
            array(
                'effective'      => array( 'analytics' => $effective['analytics'], 'marketing' => $effective['marketing'] ),
                'page_url'       => $raw_page_url,
                'transaction_id' => isset( $event_data['ecommerce']['transaction_id'] ) ? (string) $event_data['ecommerce']['transaction_id'] : '',
            )
        );
        $status = ( is_array( $authoritative ) && isset( $authoritative['status'] ) ) ? $authoritative['status'] : 'skip';

        if ( 'unverified' === $status ) {
            $out['mode']   = 'unverified';
            $out['reason'] = 'unverified_purchase';
            return $out;
        }
        if ( 'verified' !== $status || empty( $authoritative['order_id'] ) ) {
            $reason        = ( is_array( $authoritative ) && isset( $authoritative['reason'] ) ) ? $authoritative['reason'] : '';
            $out['mode']   = 'skip';
            $out['reason'] = in_array( $reason, array( 'invalid_order_ref', 'order_not_countable' ), true ) ? $reason : 'invalid_order_ref';
            return $out;
        }

        $overrides = isset( $authoritative['overrides'] ) && is_array( $authoritative['overrides'] ) ? $authoritative['overrides'] : array();
        // Server-built ecommerce still goes through the single allowlist.
        if ( isset( $overrides['ecommerce'] ) ) {
            $overrides['ecommerce'] = self::sanitize_ecommerce( $overrides['ecommerce'] );
        }
        // Raw K2a keys from the order are hashed exactly like client data.
        if ( ! empty( $overrides['enhanced'] ) && is_array( $overrides['enhanced'] ) ) {
            $overrides['enhanced'] = TrackWP_Hash::normalize_enhanced( self::sanitize_enhanced( $overrides['enhanced'] ) );
        }

        $out['mode']      = 'verified';
        $out['order_id']  = (int) $authoritative['order_id'];
        $out['overrides'] = $overrides;
        if ( isset( $overrides['event_id'] ) && is_string( $overrides['event_id'] ) && preg_match( '/^evt_[a-f0-9]{16,64}$/', $overrides['event_id'] ) ) {
            $out['event_id_lock'] = $overrides['event_id'];
        }
        return $out;
    }

    /**
     * Routing: a K1 result for every destination that will NOT be tried, and
     * null for every destination that should be tried.
     *
     * Reason precedence: not_configured, event_disabled, routed_off,
     * client_only, [ga4_route, GA4 only], stale_version / no_consent.
     *
     * @param string     $event_name
     * @param array|null $event_config
     * @param bool       $event_enabled
     * @param array      $effective
     * @param string|null $ga4_route  The client's routing claim for THIS push
     *   (KC8/TR4): 'gtm'|'server'|'off', or null when absent (old client, or
     *   an event that never went through pushDataLayer). Only ever used for
     *   the 'ga4' destination and only ever narrows it further.
     * @return array destination => array|null
     */
    private function route( $event_name, $event_config, $event_enabled, $effective, $ga4_route = null ) {
        $advanced        = get_option( 'trackwp_advanced', array() );
        $dedup_mode      = isset( $advanced['dedup_mode'] ) ? $advanced['dedup_mode'] : 'client_and_server';
        $server_dispatch = ( 'client_only' !== $dedup_mode );

        // Unknown events keep the legacy behaviour for GA4/Meta (send) but are
        // NEVER uploaded as Google Ads conversions — that must be explicit.
        $send_to = ( is_array( $event_config ) && isset( $event_config['send_to'] ) && is_array( $event_config['send_to'] ) ) ? $event_config['send_to'] : null;
        $routed  = array(
            'ga4'        => ( null === $send_to ) || ! empty( $send_to['ga4'] ),
            'meta'       => ( null === $send_to ) || ! empty( $send_to['meta'] ),
            'google_ads' => ( null !== $send_to ) && ! empty( $send_to['google_ads'] ),
        );

        $ga4  = new TrackWP_GA4();
        $meta = new TrackWP_Meta();
        $ads  = new TrackWP_Google_Ads();
        $configured = array(
            'ga4'        => $ga4->is_enabled(),
            'meta'       => $meta->is_enabled(),
            'google_ads' => $ads->is_capi_enabled(),
        );
        $needs = array( 'ga4' => 'analytics', 'meta' => 'marketing', 'google_ads' => 'marketing' );

        // KC8/TR4: the dataLayer layer's own GA4 routing decision, recomputed
        // server-side from the SAME rule (TrackWP_DataLayer::ga4_route()) so
        // the client's claim is checked, never trusted blindly. Only
        // consulted when the SITE is configured for the layer at all
        // (M2: configured_for_site(), NOT active_for_request() — a REST
        // request carries no reliable admin session for "test" mode) AND the
        // client actually sent a route; otherwise this stays null and GA4
        // falls back to the plain 1.11.0 consent check below.
        $server_ga4_route = null;
        if ( null !== $ga4_route && class_exists( 'TrackWP_DataLayer' ) && TrackWP_DataLayer::configured_for_site() ) {
            $dl_config        = TrackWP_DataLayer::client_config();
            $server_ga4_route = TrackWP_DataLayer::ga4_route( array(
                'datalayer_active' => true,
                'send_to_ga4'      => $routed['ga4'],
                'ga4_enabled'      => $dl_config['ga4Enabled'],
                'mp_configured'    => $configured['ga4'],
                'source'           => $dl_config['ga4Source'],
                'event'            => $event_name,
                'statistics'       => $effective['analytics'],
                'dedup_mode'       => $dedup_mode,
            ) );
        }

        $results = array();
        foreach ( self::DESTINATIONS as $dest ) {
            if ( ! $configured[ $dest ] ) {
                $results[ $dest ] = self::result( $dest, 'skipped', 'not_configured' );
            } elseif ( ! $event_enabled ) {
                $results[ $dest ] = self::result( $dest, 'skipped', 'event_disabled' );
            } elseif ( ! $routed[ $dest ] ) {
                $results[ $dest ] = self::result( $dest, 'skipped', 'routed_off' );
            } elseif ( ! $server_dispatch ) {
                $results[ $dest ] = self::result( $dest, 'skipped', 'client_only' );
            } elseif ( 'ga4' === $dest && null !== $server_ga4_route && 'server' !== $ga4_route ) {
                // The client explicitly claimed 'gtm' or 'off': trust it
                // directly (a client route can only narrow what the server
                // sends, never widen it, M2), no need to also agree.
                $results[ $dest ] = self::result( $dest, 'skipped', 'gtm' === $ga4_route ? 'ga4_via_gtm' : 'routed_off' );
            } elseif ( 'ga4' === $dest && null !== $server_ga4_route && 'server' === $server_ga4_route ) {
                // Client claimed 'server' and the server's own recomputation
                // agrees: the server never upgrades a 'gtm' route to
                // 'server', but here both sides independently agree.
                $results[ $dest ] = null;
            } elseif ( empty( $effective[ $needs[ $dest ] ] ) ) {
                // TR4: a 'server' claim that the server's own recomputation
                // does NOT confirm falls back to the ordinary consent-based
                // reason codes (no_consent/stale_version) — never a
                // fabricated ga4_via_gtm/routed_off for a route the client
                // never actually claimed.
                $results[ $dest ] = self::result( $dest, 'skipped', ! empty( $effective['stale'] ) ? 'stale_version' : 'no_consent' );
            } else {
                $results[ $dest ] = null;
            }
        }
        return $results;
    }

    /**
     * Call one adapter with the remaining time budget as its timeout.
     *
     * @param string $dest
     * @param array  $event_data
     * @param array  $consent_flags
     * @param float  $timeout Seconds.
     * @return array K1 result.
     */
    private function dispatch( $dest, $event_data, $consent_flags, $timeout ) {
        switch ( $dest ) {
            case 'ga4':
                $adapter = new TrackWP_GA4();
                return self::normalize_result( $dest, $adapter->send_event( $event_data, $timeout ) );
            case 'meta':
                $adapter = new TrackWP_Meta();
                return self::normalize_result( $dest, $adapter->send_event( $event_data, $timeout ) );
            case 'google_ads':
                $adapter = new TrackWP_Google_Ads();
                return self::normalize_result( $dest, $adapter->send_conversion( $event_data, $consent_flags, $timeout ) );
        }
        return self::result( $dest, 'skipped', 'not_configured' );
    }

    /**
     * Cookie side effects of an /event request, all from the EFFECTIVE consent:
     * expire tracking cookies of refused categories, renew marketing cookies
     * (Safari ITP), and write the first-party id cookie host-only (K7, R17).
     *
     * @param array $event_data
     * @param array $effective
     * @return void
     */
    private function apply_cookies( $event_data, $effective ) {
        TrackWP_Cookies::expire_tracking_cookies( array(
            'analytics' => $effective['analytics'],
            'marketing' => $effective['marketing'],
        ) );

        if ( $effective['marketing'] ) {
            $this->renew_marketing_cookies();
        }

        $this->handle_first_party_cookie( $event_data, $effective['analytics'] );
    }

    /**
     * Renew the vendor marketing cookies the browser presented, with their
     * declared lifetime on the registrable domain (where gtag/fbevents set
     * them; a host-only re-issue would create a duplicate).
     *
     * @return void
     */
    private function renew_marketing_cookies() {
        foreach ( $_COOKIE as $name => $value ) {
            if ( ! is_string( $name ) || ! is_string( $value ) ) {
                continue;
            }
            if ( '_fbp' !== $name && '_fbc' !== $name && 0 !== strpos( $name, '_gcl_' ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( $value ) );
            if ( '' === $value ) {
                continue;
            }
            TrackWP_Cookies::set( $name, $value, time() + TrackWP_Cookies::lifetime_days( $name ) * DAY_IN_SECONDS, 'registrable' );
        }
    }

    /**
     * Write (or migrate) the first-party id cookie host-only (K7, R17).
     * Without analytics consent nothing is written; expiry is handled by
     * TrackWP_Cookies::expire_tracking_cookies().
     *
     * @param array $event_data
     * @param bool  $analytics
     * @return void
     */
    private function handle_first_party_cookie( $event_data, $analytics ) {
        $advanced = get_option( 'trackwp_advanced', array() );
        if ( empty( $advanced['first_party_cookie_enabled'] ) || ! $analytics ) {
            return;
        }
        $cookie_name = TrackWP_Cookies::fp_cookie_name();

        $client_id = isset( $event_data['client_id'] ) ? (string) $event_data['client_id'] : '';
        if ( '' === $client_id ) {
            $client_id = self::existing_client_id( $cookie_name );
        }
        if ( '' === $client_id ) {
            $client_id = TrackWP_Hash::generate_client_id();
        }

        TrackWP_Cookies::rewrite_host_only( $cookie_name, $client_id, time() + TrackWP_Cookies::lifetime_days( $cookie_name ) * DAY_IN_SECONDS );
    }

    /**
     * A valid client id from the first-party cookie, or from _ga
     * (GA1.1.<random>.<ts> -> <random>.<ts>), or ''.
     *
     * @param string $cookie_name
     * @return string
     */
    private static function existing_client_id( $cookie_name ) {
        $cid = isset( $_COOKIE[ $cookie_name ] ) && is_string( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';
        if ( '' !== $cid && ( preg_match( '/^\d+\.\d+$/', $cid ) || 0 === strpos( $cid, 'twp_' ) ) ) {
            return $cid;
        }
        if ( ! empty( $_COOKIE['_ga'] ) && is_string( $_COOKIE['_ga'] ) ) {
            $parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) ) );
            if ( count( $parts ) >= 4 && preg_match( '/^\d+$/', $parts[2] ) && preg_match( '/^\d+$/', $parts[3] ) ) {
                return $parts[2] . '.' . $parts[3];
            }
        }
        return '';
    }

    /**
     * Renew first-party cookies via HTTP Set-Cookie so Safari ITP does not cap
     * them at 7 days. Consent comes from the consent cookie
     * (get_current_consent()), and this endpoint NEVER sets trackwp_consent (K4).
     *
     * - first-party id cookie: rewritten host-only (R17 migration) under statistics
     * - _ga / _ga_*: renewed on the registrable domain under statistics
     * - _fbp / _fbc / _gcl_*: renewed under marketing
     */
    public function handle_keepalive($request) {
        $consent  = TrackWP_Consent::get_current_consent();
        $advanced = get_option('trackwp_advanced', array());

        if ( ! empty( $consent['statistics'] ) ) {
            if ( ! empty( $advanced['first_party_cookie_enabled'] ) ) {
                $cookie_name = TrackWP_Cookies::fp_cookie_name();
                $cid         = self::existing_client_id( $cookie_name );
                if ( '' === $cid ) {
                    $cid = TrackWP_Hash::generate_client_id();
                }
                TrackWP_Cookies::rewrite_host_only( $cookie_name, $cid, time() + TrackWP_Cookies::lifetime_days( $cookie_name ) * DAY_IN_SECONDS );
            }

            // _ga lifetime as in 1.10.0: the consent cookie lifetime, capped at 400 days.
            $ga_expires = time() + min( 400, TrackWP_Cookies::lifetime_days( 'trackwp_consent' ) ) * DAY_IN_SECONDS;
            foreach ( $_COOKIE as $ck_name => $ck_val ) {
                if ( ! is_string( $ck_name ) || ! is_string( $ck_val ) ) {
                    continue;
                }
                if ( '_ga' === $ck_name || 0 === strpos( $ck_name, '_ga_' ) ) {
                    $val = sanitize_text_field( wp_unslash( $ck_val ) );
                    if ( '' !== $val ) {
                        TrackWP_Cookies::set( $ck_name, $val, $ga_expires, 'registrable' );
                    }
                }
            }
        }

        if ( ! empty( $consent['marketing'] ) ) {
            $this->renew_marketing_cookies();
        }

        return self::respond( array( 'status' => 'ok' ) );
    }

    /**
     * GDPR access — return user's tracked data.
     * Identifies user via _twp_cid cookie (sent automatically by browser).
     */
    public function handle_my_data_get( $request ) {
        $cookie_name = TrackWP_Cookies::fp_cookie_name();
        $client_id   = isset( $_COOKIE[ $cookie_name ] ) && is_string( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

        // R3: the consent cookie is only ever parsed by TrackWP_Consent. The
        // raw decoded content is shown (including a stale or outdated choice),
        // because this endpoint reports what is stored in the browser.
        $consent_state = TrackWP_Consent::decode_cookie();

        // The wording below must match reality: when the delivery log is on,
        // the site DOES keep something server-side (metadata only), and saying
        // otherwise would make this GDPR endpoint misleading.
        $notes = array();
        if ( class_exists( 'TrackWP_Delivery_Log' ) && TrackWP_Delivery_Log::is_enabled() ) {
            $notes[] = sprintf(
                /* translators: %d: retention in days */
                __( 'Tracking-events forwardes til Google/Meta. Der gemmes server-side udelukkende teknisk leveringsinformation i %d dage — begivenhedsnavn, et tilfældigt begivenheds-id, tidspunkt afrundet til minut, modtagerplatform og leveringsstatus. Der gemmes hverken IP, browseroplysninger, side-URL, formularindhold, e-mail, telefonnummer eller dit client_id, og posterne kan derfor ikke knyttes til dig.', 'trackwp' ),
                (int) TrackWP_Delivery_Log::retention_days()
            );
        } else {
            $notes[] = __( 'Tracking-events forwardes til Google/Meta og gemmes ikke server-side. Førsteparts-cookien indeholder kun et tilfældigt client_id.', 'trackwp' );
        }

        $data = array(
            'client_id'     => $client_id,
            'consent_state' => $consent_state,
            'notes'         => array_merge( $notes, array(
                __( 'Ved samtykke-afgivelse gemmes en revisionspost server-side med pseudonymiseret IP (envejshash), user-agent og tidspunkt — den kan ikke slås op via dette endpoint, da den ikke er knyttet til dit client_id.', 'trackwp' ),
                __( 'For at slette dit client_id: ryd cookies for dette site i din browser, eller kald DELETE /wp-json/trackwp/v1/my-data.', 'trackwp' ),
            ) ),
        );

        $response = new WP_REST_Response( $data, 200 );
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }

    /**
     * GDPR erasure — instruct browser to drop the first-party and consent
     * cookies, in every domain variant.
     */
    public function handle_my_data_delete( $request ) {
        TrackWP_Cookies::expire( TrackWP_Cookies::fp_cookie_name() );
        TrackWP_Cookies::expire( 'trackwp_consent' );

        return self::respond( array(
            'status'  => 'ok',
            'message' => __( 'Dine cookies er slettet. Genindlæs siden for at fortsætte uden tracking.', 'trackwp' ),
        ) );
    }

    /**
     * Return true if the User-Agent looks like a known bot or crawler.
     * Pattern list covers majority of well-behaved crawlers.
     */
    private static function is_bot( $user_agent ) {
        if ( empty( $user_agent ) ) {
            return false;
        }
        $patterns = array(
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'sogou', 'exabot', 'facebot', 'ia_archiver',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'blexbot',
            'rogerbot', 'screaming frog', 'serpstatbot', 'petalbot',
            'applebot', 'pingdom', 'gtmetrix', 'lighthouse', 'pagespeed',
            'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
            'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client',
            'crawler', 'spider', 'scraper',
        );
        $ua_lower = strtolower( $user_agent );
        foreach ( $patterns as $pattern ) {
            if ( strpos( $ua_lower, $pattern ) !== false ) {
                return true;
            }
        }
        // Generic 'bot' only with a boundary check — plain substring matching
        // hits real devices whose model names contain "bot" (false positives).
        // Supplement: 'bot/' (bot immediately followed by a slash) also counts,
        // so camel-case names like "MyBot/1.0" are caught.
        // Test cases:
        //   "CUBOT NOTE 7 Build/..."            => NO match ('u' before 'bot', no slash after)
        //   "DuckDuckGo-Favicons-Bot/1.0"       => match ('-' before, '/' after)
        //   "MyBot/1.0"                         => match ('bot/' — slash right after)
        //   "Googlebot/2.1"                     => caught by explicit 'googlebot' above
        if ( preg_match( '/(?<![a-z0-9])bot(?![a-z0-9])|bot\//', $ua_lower ) ) {
            return true;
        }
        return false;
    }

    /**
     * Debug logging. Only the event name, the consent state and the
     * per-destination statuses are written — never a URL, a click id or any
     * other identifier.
     *
     * @param string $event_name
     * @param array  $effective
     * @param array  $results
     * @param bool   $is_bot
     * @return void
     */
    private function maybe_log( $event_name, $effective, $results, $is_bot ) {
        $advanced = get_option('trackwp_advanced', array());
        if (empty($advanced['debug_log'])) return;

        $log_dir = WP_CONTENT_DIR . '/trackwp';
        TrackWP_Settings::ensure_log_dir( $log_dir );

        $statuses = array();
        foreach ( $results as $dest => $result ) {
            $statuses[] = $dest . '=' . $result['status'] . ( '' !== $result['reason'] ? '/' . $result['reason'] : '' );
        }

        $log_entry = sprintf(
            "[%s] Event: %s | Analytics: %s | Marketing: %s | Consent: %s%s | Results: %s%s\n",
            current_time('Y-m-d H:i:s'),
            sanitize_key( (string) $event_name ),
            ! empty( $effective['analytics'] ) ? 'granted' : 'denied',
            ! empty( $effective['marketing'] ) ? 'granted' : 'denied',
            isset( $effective['source'] ) ? sanitize_key( $effective['source'] ) : 'none',
            ! empty( $effective['stale'] ) ? ' (stale)' : '',
            empty( $statuses ) ? '-' : implode( ', ', $statuses ),
            $is_bot ? ' | BOT-SKIPPED' : ''
        );

        error_log($log_entry, 3, $log_dir . '/debug.log');
    }
}
