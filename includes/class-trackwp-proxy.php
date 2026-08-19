<?php
defined('ABSPATH') || exit;

class TrackWP_Proxy {

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
            'args' => array(
                'event' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'value' => array(
                    'default'           => 0,
                    'sanitize_callback' => function( $v ) { return floatval( $v ); },
                ),
                'currency' => array(
                    'default'           => 'DKK',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'page_url' => array(
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ),
                'page_title' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'client_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'event_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'consent' => array(
                    'required' => true,
                    'type'     => 'object',
                ),
                'session_id' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'user_agent' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'fbc' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'fbp' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'gclid' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                '_ga' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'ga_session_cookie' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'ga_session_cookies' => array(
                    'type'              => 'array',
                    'default'           => array(),
                    'sanitize_callback' => function( $v ) {
                        if ( ! is_array( $v ) ) {
                            return array();
                        }
                        $out = array();
                        foreach ( $v as $item ) {
                            if ( ! is_array( $item ) ) {
                                continue;
                            }
                            $id    = isset( $item['id'] ) ? sanitize_text_field( $item['id'] ) : '';
                            $value = isset( $item['value'] ) ? sanitize_text_field( $item['value'] ) : '';
                            if ( $id && $value ) {
                                $out[] = array( 'id' => $id, 'value' => $value );
                            }
                        }
                        return $out;
                    },
                ),
                'enhanced' => array(
                    'default' => array(),
                    'type'    => 'object',
                ),
                'form_id' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'form_name' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
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
     * Permission check: origin + rate limit.
     * No nonce: cached pages serve stale nonces, and the endpoint is public and non-mutating.
     */
    public function check_permission($request) {
        // Origin check — only allow from own domain
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $home = home_url();
        $home_host = wp_parse_url($home, PHP_URL_HOST);

        $origin_ok = false;
        if ($origin && wp_parse_url($origin, PHP_URL_HOST) === $home_host) {
            $origin_ok = true;
        }
        if (!$origin_ok && $referer && wp_parse_url($referer, PHP_URL_HOST) === $home_host) {
            $origin_ok = true;
        }
        if (!$origin_ok) {
            return new WP_Error('rest_forbidden', __('Cross-origin-forespørgsel afvist.', 'trackwp'), array('status' => 403));
        }

        // Rate limiting: 20 requests per 2-second fixed window per IP.
        // The window bucket is part of the key so the TTL is never extended
        // by subsequent requests (a steady stream would otherwise never reset the count).
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $bucket = (int) floor( time() / 2 );
        $ip_key = 'trackwp_rate_' . md5($ip) . '_' . $bucket;
        $count = (int) get_transient($ip_key);
        if ($count >= 20) {
            return new WP_Error('rate_limited', __('For mange forespørgsler.', 'trackwp'), array('status' => 429));
        }
        set_transient($ip_key, $count + 1, 5);

        return true;
    }

    /**
     * Permission check for GDPR endpoints — origin + rate-limit only, no nonce.
     */
    public function check_origin_only( $request ) {
        $origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );

        $ok = false;
        if ( $origin && wp_parse_url( $origin, PHP_URL_HOST ) === $home_host ) {
            $ok = true;
        }
        if ( ! $ok && $referer && wp_parse_url( $referer, PHP_URL_HOST ) === $home_host ) {
            $ok = true;
        }
        if ( ! $ok ) {
            return new WP_Error( 'rest_forbidden', __( 'Cross-origin-forespørgsel afvist.', 'trackwp' ), array( 'status' => 403 ) );
        }

        // Rate limit: 5 requests per 2-second window per IP for GDPR endpoint (lower than tracking).
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
        $key = 'trackwp_gdpr_' . md5( $ip );
        $rate = get_transient( $key );
        if ( $rate === false ) {
            set_transient( $key, array( 'count' => 1 ), 2 );
        } else {
            if ( $rate['count'] >= 5 ) {
                return new WP_Error( 'rate_limited', __( 'For mange forespørgsler.', 'trackwp' ), array( 'status' => 429 ) );
            }
            $rate['count']++;
            set_transient( $key, $rate, 2 );
        }
        return true;
    }

    /**
     * Handle incoming tracking event.
     * Routes to platforms based on consent, sets first-party cookie.
     * ALWAYS returns 200 — tracking must never block UX.
     */
    public function handle_event($request) {
        $event_name = $request->get_param('event');
        $consent = $request->get_param('consent');

        $analytics_consent = !empty($consent['analytics']);
        $marketing_consent = !empty($consent['marketing']);

        // Verify event is registered
        $events_manager = new TrackWP_Events();
        $event_config = $events_manager->get_event_config($event_name);

        // Allow the event even if not in config (for flexibility)
        // But use config for meta_event mapping if available
        $meta_event_name = '';
        if ($event_config) {
            $meta_event_name = isset($event_config['meta_event']) ? $event_config['meta_event'] : '';
        }

        // Per-event platform routing. Unknown events (not in config) keep the
        // legacy behavior for GA4/Meta (send) but are NEVER uploaded as Google
        // Ads conversions — a conversion upload must be an explicit choice.
        $send_to    = ( $event_config && isset( $event_config['send_to'] ) && is_array( $event_config['send_to'] ) ) ? $event_config['send_to'] : null;
        $to_ga4     = ( $send_to === null ) || ! empty( $send_to['ga4'] );
        $to_meta    = ( $send_to === null ) || ! empty( $send_to['meta'] );
        $to_ads     = ( $send_to !== null ) && ! empty( $send_to['google_ads'] );

        // === Cookie sources (server-side, in addition to payload) ===
        // _ga (GA client_id) — payload-prioritised, then cookie fallback.
        $ga_cookie_payload = $request->get_param('_ga');
        $ga_cookie         = $ga_cookie_payload !== '' ? $ga_cookie_payload : sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ?? '' ) );

        // _ga_<container> session cookie — payload-prioritised, then scan $_COOKIE.
        $ga_session_cookie = sanitize_text_field( $request->get_param('ga_session_cookie') );
        if ( '' === $ga_session_cookie ) {
            foreach ( $_COOKIE as $ck_name => $ck_val ) {
                if ( is_string( $ck_name ) && strpos( $ck_name, '_ga_' ) === 0 ) {
                    $ga_session_cookie = sanitize_text_field( wp_unslash( $ck_val ) );
                    break;
                }
            }
        }

        // _gcl_au (Google Ads first-party).
        $gcl_au = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_au'] ?? '' ) );

        // gclid — payload first, then parse _gcl_aw cookie (format: GCL.<ts>.<gclid>).
        $gclid = sanitize_text_field( $request->get_param('gclid') );
        if ( '' === $gclid && ! empty( $_COOKIE['_gcl_aw'] ) ) {
            $gcl_aw_raw  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
            $gcl_aw_parts = explode( '.', $gcl_aw_raw );
            if ( isset( $gcl_aw_parts[2] ) && $gcl_aw_parts[2] !== '' ) {
                $gclid = $gcl_aw_parts[2];
            }
        }

        // _fbp / _fbc — payload-prioritised, fallback to cookie.
        $fbp_payload = $request->get_param('fbp');
        $fbp         = $fbp_payload !== '' ? $fbp_payload : sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ?? '' ) );
        $fbc_payload = $request->get_param('fbc');
        $fbc         = $fbc_payload !== '' ? $fbc_payload : sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ?? '' ) );

        // Build event data array
        $event_data = array(
            'event'             => $event_name,
            'value'             => $request->get_param('value'),
            'currency'          => $request->get_param('currency'),
            'page_url'          => $request->get_param('page_url'),
            'page_title'        => $request->get_param('page_title'),
            'client_id'         => $request->get_param('client_id'),
            'event_id'          => $request->get_param('event_id'),
            'session_id'        => $request->get_param('session_id'),
            'user_agent'        => $request->get_param('user_agent') ?: (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
            'fbc'               => $fbc,
            'fbp'               => $fbp,
            'enhanced'          => $request->get_param('enhanced'),
            'meta_event_name'   => $meta_event_name,
            'ga_cookie'         => $ga_cookie,
            'ga_session_cookie' => $ga_session_cookie,
            'ga_session_cookies' => ( function() use ( $request ) {
                $v = $request->get_param( 'ga_session_cookies' );
                if ( ! is_array( $v ) ) {
                    return array();
                }
                $out = array();
                foreach ( $v as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    if ( ! empty( $item['id'] ) && ! empty( $item['value'] ) ) {
                        $out[] = array(
                            'id'    => (string) $item['id'],
                            'value' => (string) $item['value'],
                        );
                    }
                }
                return $out;
            } )(),
            'gcl_au'            => $gcl_au,
            'gclid'             => $gclid,
            // Payload consent state — platform classes (GA4/Meta) read this
            // instead of falling back to a server-side cookie lookup.
            'consent'           => array(
                'analytics' => $analytics_consent,
                'marketing' => $marketing_consent,
            ),
        );

        // Normalise enhanced payload (hash raw email/phone; keep existing 64-hex untouched).
        if ( class_exists( 'TrackWP_Hash' ) && method_exists( 'TrackWP_Hash', 'normalize_enhanced' ) ) {
            $event_data['enhanced'] = TrackWP_Hash::normalize_enhanced( $event_data['enhanced'] ?? array() );
        }

        // Restore 3rd-party cookies (Safari ITP renewal) BEFORE first-party cookie.
        // Consent-gated: only renew cookies where the matching category is granted;
        // otherwise expire them (compliance with GDPR/ePrivacy).
        $this->restore_third_party_cookies($consent);

        // First-party cookie handling
        $this->handle_first_party_cookie($event_data);

        // Get advanced config for consent mode settings
        $advanced = get_option('trackwp_advanced', array());

        // Dedup mode: in client_only mode, skip server-side GA4/Meta dispatch.
        // First-party cookie and logging always run regardless of mode.
        $dedup_mode = isset($advanced['dedup_mode']) ? $advanced['dedup_mode'] : 'client_and_server';
        $server_dispatch = ( $dedup_mode !== 'client_only' );

        // Delivery-log context: metadata only, never identifiers (see
        // TrackWP_Delivery_Log). Recording is a no-op while the log is off.
        $log_consent = array( 'analytics' => $analytics_consent, 'marketing' => $marketing_consent );
        $log_id      = isset( $event_data['event_id'] ) ? $event_data['event_id'] : '';

        // Bot/crawler filtering — short-circuit platform dispatch (but log the event).
        if ( self::is_bot( $event_data['user_agent'] ) ) {
            TrackWP_Delivery_Log::record( $log_id, $event_name, 'received', 'skipped', $log_consent );
            $this->maybe_log( $event_data, $analytics_consent, $marketing_consent );
            TrackWP_Settings::record_event_hit( 'bot_skipped', $event_name );
            return new WP_REST_Response( array( 'status' => 'ok', 'skipped' => 'bot' ), 200 );
        }

        // One row per incoming event, independent of how many platforms it is
        // forwarded to. This is what makes the log answer "what fired?" even on
        // a site with no platform configured or in client_only mode.
        TrackWP_Delivery_Log::record( $log_id, $event_name, 'received', 'ok', $log_consent );

        // === PLATFORM ROUTING ===

        if ( $server_dispatch ) {
            // GA4
            $ga4 = new TrackWP_GA4();
            if ($ga4->is_enabled() && $analytics_consent && $to_ga4) {
                $sent = $ga4->send_event($event_data);
                TrackWP_Delivery_Log::record( $log_id, $event_name, 'ga4', $sent ? 'ok' : 'failed', $log_consent );
            } elseif ( $ga4->is_enabled() ) {
                TrackWP_Delivery_Log::record( $log_id, $event_name, 'ga4', 'skipped', $log_consent );
            }

            // Meta — only with marketing consent
            $meta = new TrackWP_Meta();
            if ($marketing_consent && $to_meta && $meta->is_enabled()) {
                $sent = $meta->send_event($event_data);
                TrackWP_Delivery_Log::record( $log_id, $event_name, 'meta', $sent ? 'ok' : 'failed', $log_consent );
            } elseif ( $meta->is_enabled() ) {
                TrackWP_Delivery_Log::record( $log_id, $event_name, 'meta', 'skipped', $log_consent );
            }

            // Google Ads CAPI (server-side) — gated by the class's own is_capi_enabled() check.
            if ( $to_ads && class_exists( 'TrackWP_Google_Ads' ) ) {
                $ads_capi = new TrackWP_Google_Ads();
                if ( method_exists( $ads_capi, 'is_capi_enabled' ) && $ads_capi->is_capi_enabled() ) {
                    $sent = $ads_capi->send_conversion( $event_data, $consent );
                    TrackWP_Delivery_Log::record( $log_id, $event_name, 'google_ads', $sent ? 'ok' : 'failed', $log_consent );
                }
            }
        }

        // Google Ads (client-side gtag.js) is still rendered in the frontend in parallel with CAPI above.

        // Stats: count this as a real (non-bot) event (single option write).
        TrackWP_Settings::record_event_hit( 'events', $event_name );

        // Debug logging
        $this->maybe_log($event_data, $analytics_consent, $marketing_consent);

        return new WP_REST_Response(array('status' => 'ok'), 200);
    }

    /**
     * Renew first-party cookies via HTTP Set-Cookie so Safari ITP does not cap
     * them at 7 days (same-host HTTP-set cookies get full duration). Called by
     * trackwp.js on every page load. Consent-gated per category.
     *
     * - _twp_cid + _ga + _ga_*          : renewed when statistics consent is granted.
     * - _fbp / _fbc / _gcl_au / _gcl_aw : renewed when marketing consent is granted
     *                                     (90-day lifetime, matching the banner declaration).
     *
     * Chrome clamps any cookie to 400 days regardless of the value we send.
     */
    public function handle_keepalive($request) {
        $consent  = TrackWP_Consent::get_current_consent();
        $advanced = get_option('trackwp_advanced', array());

        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        // Registrable-domain form (leading dot) so we refresh the SAME
        // _ga cookie gtag set (domain-wide) instead of creating a host-only duplicate.
        $reg_domain = self::get_registrable_domain($host);

        // Lifetime: align with the consent cookie lifetime, capped at Chrome's 400-day max.
        $consent_cfg = get_option('trackwp_consent', array());
        $months      = !empty($consent_cfg['cookie_lifetime_months']) ? absint($consent_cfg['cookie_lifetime_months']) : 12;
        $lifetime    = min(400 * DAY_IN_SECONDS, $months * 30 * DAY_IN_SECONDS);
        $expires     = time() + $lifetime;

        // Vendor-documented lifetime — matches the consent banner declaration (90 days).
        $mk_expires = time() + 90 * DAY_IN_SECONDS;

        if (!empty($consent['statistics'])) {
            // First-party id cookie.
            if (!empty($advanced['first_party_cookie_enabled'])) {
                $cookie_name = !empty($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid';
                $cid = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
                if ('' === $cid && !empty($_COOKIE['_ga'])) {
                    // Reuse GA client id (strip GA1.1. prefix → <random>.<ts>).
                    $ga_parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE['_ga'])));
                    if (count($ga_parts) >= 4) {
                        $cid = $ga_parts[2] . '.' . $ga_parts[3];
                    }
                }
                if ('' === $cid) {
                    $cid = TrackWP_Hash::generate_client_id();
                }
                setcookie($cookie_name, $cid, array(
                    'expires'  => $expires,
                    'path'     => '/',
                    'domain'   => $host,
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ));
            }
            // Renew GA cookies (_ga and any _ga_* session cookie) on the registrable domain.
            foreach ($_COOKIE as $ck_name => $ck_val) {
                if (!is_string($ck_name)) {
                    continue;
                }
                if ($ck_name === '_ga' || strpos($ck_name, '_ga_') === 0) {
                    $val = sanitize_text_field(wp_unslash($ck_val));
                    if ('' === $val) {
                        continue;
                    }
                    setcookie($ck_name, $val, array(
                        'expires'  => $expires,
                        'path'     => '/',
                        'domain'   => $reg_domain,
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ));
                }
            }
        }

        if (!empty($consent['marketing'])) {
            foreach (array('_fbp', '_fbc', '_gcl_au', '_gcl_aw') as $mk) {
                if (empty($_COOKIE[$mk])) {
                    continue;
                }
                $val = sanitize_text_field(wp_unslash($_COOKIE[$mk]));
                if ('' === $val) {
                    continue;
                }
                // Registrable domain — these cookies are set domain-wide by
                // gtag/fbevents; a host-only re-issue would create a duplicate.
                setcookie($mk, $val, array(
                    'expires'  => $mk_expires,
                    'path'     => '/',
                    'domain'   => $reg_domain,
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ));
            }
        }

        $response = new WP_REST_Response(array('status' => 'ok'), 200);
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }

    /**
     * GDPR access — return user's tracked data.
     * Identifies user via _twp_cid cookie (sent automatically by browser).
     */
    public function handle_my_data_get( $request ) {
        $advanced    = get_option( 'trackwp_advanced', array() );
        $cookie_name = ! empty( $advanced['cookie_name'] ) ? $advanced['cookie_name'] : '_twp_cid';
        $client_id   = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( $_COOKIE[ $cookie_name ] ) : '';

        $consent_cookie = isset( $_COOKIE['trackwp_consent'] ) ? wp_unslash( $_COOKIE['trackwp_consent'] ) : '';
        $consent_state  = null;
        if ( $consent_cookie ) {
            $decoded = json_decode( $consent_cookie, true );
            if ( is_array( $decoded ) ) {
                $consent_state = $decoded;
            }
        }

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
     * GDPR erasure — instruct browser to drop the first-party cookie.
     */
    public function handle_my_data_delete( $request ) {
        $advanced    = get_option( 'trackwp_advanced', array() );
        $cookie_name = ! empty( $advanced['cookie_name'] ) ? $advanced['cookie_name'] : '_twp_cid';
        $domain      = wp_parse_url( home_url(), PHP_URL_HOST );

        // Expire the cookie.
        setcookie( $cookie_name, '', array(
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ) );
        setcookie( 'trackwp_consent', '', array(
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ) );

        return new WP_REST_Response( array(
            'status'  => 'ok',
            'message' => __( 'Dine cookies er slettet. Genindlæs siden for at fortsætte uden tracking.', 'trackwp' ),
        ), 200 );
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
     * Registrable-domain form of a host ('.example.dk') for domain-wide cookies.
     *
     * IPs and 'localhost' return '' (host-only cookie). With >= 3 labels the
     * first label (www, shop, ...) is dropped; with 2 labels the host is used
     * as-is. NOTE: multi-label public suffixes (co.uk-style TLDs) are not
     * handled — the plugin targets Danish sites (.dk), where two labels is
     * always the registrable domain.
     *
     * @param string $host Hostname (no scheme/port).
     * @return string Leading-dot domain, or '' for host-only.
     */
    private static function get_registrable_domain( $host ) {
        $host = strtolower( (string) $host );
        if ( '' === $host || 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return '';
        }
        $labels = explode( '.', $host );
        if ( count( $labels ) < 2 ) {
            return '';
        }
        if ( count( $labels ) >= 3 ) {
            array_shift( $labels );
        }
        return '.' . implode( '.', $labels );
    }

    /**
     * Restore (renew) third-party tracking cookies server-side so Safari ITP's
     * 7-day client-set-cookie cap is bypassed. We re-issue _fbp, _fbc,
     * _gcl_au and _gcl_aw with a 90-day lifetime whenever the browser presents
     * them on a request — but ONLY if the user has granted marketing consent.
     *
     * Without consent we actively expire the cookies (GDPR/ePrivacy: tracking
     * cookies set before consent must be removed, not renewed). The same
     * applies to the statistics cookies _ga / _ga_*: they are never renewed
     * here (gtag owns them; the keepalive endpoint renews them under
     * statistics consent), but they ARE expired when analytics consent is
     * missing or withdrawn.
     *
     * No values are altered for renewed cookies — we only refresh the expiry.
     *
     * @param array $consent Consent payload, expects keys: analytics, marketing.
     */
    private function restore_third_party_cookies( $consent ) {
        // Map each third-party cookie to the consent category that gates it.
        // _ga is intentionally excluded from renewal: gtag.js manages the _ga
        // cookie itself; re-issuing it host-only creates a duplicate cookie
        // next to gtag's domain-wide cookie and corrupts GA sessions.
        $cookie_map = array(
            '_fbp'    => 'marketing',
            '_gcl_au' => 'marketing',
            '_gcl_aw' => 'marketing',
            '_fbc'    => 'marketing',
        );

        // Vendor-documented lifetime — matches the consent banner declaration (90 days).
        $mk_expires     = time() + 90 * DAY_IN_SECONDS;
        $expires_delete = time() - 3600;

        // Registrable domain — gtag/fbevents set these cookies domain-wide, so a
        // host-only ('') re-issue/expiry on www./subdomain hosts never hits the
        // real cookie (renewal duplicates it, deletion misses it).
        $host       = wp_parse_url( home_url(), PHP_URL_HOST );
        $reg_domain = self::get_registrable_domain( $host );

        foreach ( $cookie_map as $name => $category ) {
            if ( empty( $_COOKIE[ $name ] ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
            if ( '' === $value ) {
                continue;
            }

            $granted = ! empty( $consent[ $category ] );

            if ( $granted ) {
                // Renew with the declared 90-day lifetime.
                setcookie( $name, $value, array(
                    'expires'  => $mk_expires,
                    'path'     => '/',
                    'domain'   => $reg_domain,
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ) );
            } else {
                // Consent missing/withdrawn — expire the cookie immediately.
                setcookie( $name, '', array(
                    'expires'  => $expires_delete,
                    'path'     => '/',
                    'domain'   => $reg_domain,
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ) );
                // Also expire the host-only variant so duplicates set by
                // earlier plugin versions (domain => '') are cleaned up too.
                if ( '' !== $reg_domain ) {
                    setcookie( $name, '', array(
                        'expires'  => $expires_delete,
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ) );
                }
            }
        }

        // Statistics cookies (_ga and _ga_*): NEVER renewed here — not even
        // with consent. gtag.js owns these cookies, and the keepalive endpoint
        // already renews them correctly under statistics consent. We only
        // expire them when analytics consent is missing or withdrawn.
        if ( empty( $consent['analytics'] ) ) {
            foreach ( $_COOKIE as $ck_name => $ck_val ) {
                if ( ! is_string( $ck_name ) ) {
                    continue;
                }
                if ( $ck_name !== '_ga' && strpos( $ck_name, '_ga_' ) !== 0 ) {
                    continue;
                }
                setcookie( $ck_name, '', array(
                    'expires'  => $expires_delete,
                    'path'     => '/',
                    'domain'   => $reg_domain,
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ) );
                // Also expire the host-only variant so duplicates set by
                // earlier plugin versions (domain => '') are cleaned up too.
                if ( '' !== $reg_domain ) {
                    setcookie( $ck_name, '', array(
                        'expires'  => $expires_delete,
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ) );
                }
            }
        }
    }

    /**
     * Handle first-party cookie — set/renew server-side.
     */
    private function handle_first_party_cookie($event_data) {
        $advanced = get_option('trackwp_advanced', array());
        if (empty($advanced['first_party_cookie_enabled'])) return;

        $cookie_name = !empty($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid';
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);

        // Consent-gate: do not set/renew first-party tracking cookie without
        // analytics (statistics) consent. If a cookie already exists, expire it.
        $consent = TrackWP_Consent::get_current_consent();
        if ( empty( $consent['statistics'] ) ) {
            if ( isset( $_COOKIE[ $cookie_name ] ) ) {
                setcookie(
                    $cookie_name,
                    '',
                    array(
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'domain'   => $domain,
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    )
                );
            }
            return;
        }

        $consent_cfg = get_option('trackwp_consent', array());
        $lifetime_months = !empty($consent_cfg['cookie_lifetime_months']) ? absint($consent_cfg['cookie_lifetime_months']) : 12;
        $expires = time() + min(400 * DAY_IN_SECONDS, $lifetime_months * 30 * DAY_IN_SECONDS);

        // Set or renew cookie. Accept GA4-style numeric IDs (<random>.<timestamp>)
        // and legacy 'twp_'-prefixed IDs; regenerate anything else.
        $client_id = isset($event_data['client_id']) ? $event_data['client_id'] : '';
        $valid = is_string($client_id) && '' !== $client_id
            && (preg_match('/^\d+\.\d+$/', $client_id) || strpos($client_id, 'twp_') === 0);
        if (!$valid) {
            $client_id = TrackWP_Hash::generate_client_id();
        }

        setcookie(
            $cookie_name,
            $client_id,
            array(
                'expires'  => $expires,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => is_ssl(),
                'httponly'  => false,
                'samesite' => 'Lax',
            )
        );
    }

    /**
     * Debug logging (no PII).
     */
    private function maybe_log($event_data, $analytics_consent, $marketing_consent) {
        $advanced = get_option('trackwp_advanced', array());
        if (empty($advanced['debug_log'])) return;

        $log_dir = WP_CONTENT_DIR . '/trackwp';
        TrackWP_Settings::ensure_log_dir( $log_dir );

        $is_bot = self::is_bot( $event_data['user_agent'] ?? '' );
        $log_entry = sprintf(
            "[%s] Event: %s | Analytics: %s | Marketing: %s | Platforms: %s%s\n",
            current_time('Y-m-d H:i:s'),
            sanitize_text_field($event_data['event']),
            $analytics_consent ? 'granted' : 'denied',
            $marketing_consent ? 'granted' : 'denied',
            implode(', ', $this->get_dispatched_platforms($analytics_consent, $marketing_consent)),
            $is_bot ? ' | BOT-SKIPPED' : ''
        );

        // Never log PII — only event metadata
        error_log($log_entry, 3, $log_dir . '/debug.log');
    }

    /**
     * Get list of platforms that would receive this event.
     */
    private function get_dispatched_platforms($analytics, $marketing) {
        $platforms = array();
        $ga4 = new TrackWP_GA4();
        $meta = new TrackWP_Meta();
        $ads = new TrackWP_Google_Ads();

        if ($ga4->is_enabled() && $analytics) $platforms[] = 'GA4';
        if ($marketing && $meta->is_enabled()) $platforms[] = 'Meta';
        if ($ads->is_enabled()) $platforms[] = 'GoogleAds(client)';

        return $platforms;
    }
}
