<?php
/**
 * TrackWP_Proxy: EVENT_FIELDS (K2), strict consent booleans (K3/R2), final
 * filter after the extension hook, disabled and reserved events, K1 statuses,
 * delivery log and the forwarded stat.
 *
 * Requests go through the real REST server (rest_get_server()->dispatch) so
 * the route's own args, sanitizers and permission callback are exercised.
 * Outgoing platform calls are captured with pre_http_request; nothing leaves
 * the test container.
 *
 * Producers used for fixtures:
 * - consent cookie: tests/fixtures/consent-cookie.json, written by W0's
 *   gen-consent-cookie.mjs from the real consent.js (skipped when absent)
 * - event list: TrackWP_Events::get_defaults() / validate_event()
 * - delivery-log table: TrackWP_Delivery_Log::create_table()
 */

class TrackWP_Proxy_Fields_Test extends WP_UnitTestCase {

    /** @var array Captured outgoing HTTP requests: list of {url, body}. */
    private $http = array();

    /** @var int HTTP status the fake platforms answer with. */
    private $http_status = 204;

    public function set_up() {
        parent::set_up();

        $this->http        = array();
        $this->http_status = 204;
        add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );

        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => 'G-TEST12345',
            'ga4_api_secret'     => 'test-secret',
        ) );
        update_option( 'trackwp_advanced', array(
            'dedup_mode'           => 'client_and_server',
            'delivery_log_enabled' => true,
            'batching_enabled'     => false,
        ) );
        update_option( 'trackwp_consent', array( 'consent_version' => 1 ) );
        update_option( 'trackwp_events', TrackWP_Events::get_defaults() );
        delete_option( 'trackwp_stats' );

        // WP_UnitTestCase rewrites CREATE TABLE into CREATE TEMPORARY TABLE,
        // which SHOW TABLES (used by table_exists()) cannot see. If an earlier
        // test dropped the table, create it as a real table.
        remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
        remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
        TrackWP_Delivery_Log::create_table();
        add_filter( 'query', array( $this, '_create_temporary_tables' ) );
        add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
        TrackWP_Delivery_Log::clear();

        $_SERVER['HTTP_ORIGIN'] = home_url();
        // A fresh address per test keeps the per-IP rate limit out of the way.
        $_SERVER['REMOTE_ADDR'] = '10.' . wp_rand( 0, 255 ) . '.' . wp_rand( 0, 255 ) . '.' . wp_rand( 1, 254 );
        $_COOKIE = array();
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
        remove_all_filters( 'trackwp_event_data' );
        unset( $_SERVER['HTTP_ORIGIN'] );
        $_COOKIE = array();
        parent::tear_down();
    }

    public function capture_http( $pre, $args, $url ) {
        $this->http[] = array(
            'url'  => $url,
            'body' => isset( $args['body'] ) ? (string) $args['body'] : '',
        );
        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array( 'code' => $this->http_status, 'message' => 'mock' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private function ga4_requests() {
        return array_values( array_filter( $this->http, function( $r ) {
            return false !== strpos( $r['url'], 'google-analytics.com/mp/collect' );
        } ) );
    }

    /**
     * POST a JSON body to the event route.
     */
    private function post_event( array $body ) {
        $request = new WP_REST_Request( 'POST', '/trackwp/v1/event' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        return rest_get_server()->dispatch( $request );
    }

    private function base_body( array $extra = array() ) {
        return array_merge( array(
            'event'      => 'form_submit',
            'event_id'   => 'evt_' . str_repeat( 'a1', 16 ),
            'page_url'   => 'https://example.org/kontakt/',
            'page_title' => 'Kontakt',
            'client_id'  => '123456789.1700000000',
            'session_id' => '1700000000',
            'consent'    => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ), $extra );
    }

    private function log_rows( $destination ) {
        return array_values( array_filter( TrackWP_Delivery_Log::get_recent( 100 ), function( $row ) use ( $destination ) {
            return $row['destination'] === $destination;
        } ) );
    }

    // ------------------------------------------------------------------
    // K2 table
    // ------------------------------------------------------------------

    public function test_event_fields_table_matches_k2() {
        $expected = array(
            'event' => 'none', 'event_id' => 'none', 'value' => 'none', 'currency' => 'none',
            'page_url' => 'none', 'page_title' => 'none', 'page_referrer' => 'analytics',
            'client_id' => 'analytics', 'session_id' => 'analytics', '_ga' => 'analytics',
            'ga_session_cookies' => 'analytics', 'engaged_ms' => 'analytics', 'user_agent' => 'any',
            'fbp' => 'marketing', 'fbc' => 'marketing', 'gclid' => 'marketing', 'gbraid' => 'marketing',
            'wbraid' => 'marketing', 'enhanced' => 'marketing', 'ecommerce' => 'none',
            'form_id' => 'none', 'form_name' => 'none', 'order_ref' => 'none', 'consent' => 'none',
        );
        foreach ( $expected as $field => $category ) {
            $this->assertArrayHasKey( $field, TrackWP_Proxy::EVENT_FIELDS, $field );
            $this->assertSame( $category, TrackWP_Proxy::EVENT_FIELDS[ $field ]['category'], $field );
        }
    }

    public function test_field_sanitizers_drop_invalid_values() {
        $this->assertNull( TrackWP_Proxy::sanitize_field( 'event_id', 'evt_NOT-HEX' ) );
        $this->assertSame( 'evt_' . str_repeat( 'ab', 8 ), TrackWP_Proxy::sanitize_field( 'event_id', 'evt_' . str_repeat( 'ab', 8 ) ) );
        $this->assertNull( TrackWP_Proxy::sanitize_field( 'value', -5 ) );
        $this->assertSame( 'EUR', TrackWP_Proxy::sanitize_field( 'currency', 'eur' ) );
        $this->assertNull( TrackWP_Proxy::sanitize_field( 'gclid', 'abc def' ) );
        $this->assertNull( TrackWP_Proxy::sanitize_field( 'engaged_ms', 3600001 ) );
        $this->assertSame( 1500, TrackWP_Proxy::sanitize_field( 'engaged_ms', '1500' ) );
        $this->assertNull( TrackWP_Proxy::sanitize_field( 'form_id', 'https://example.org/send' ) );
        $this->assertSame( 'gf_3:1', TrackWP_Proxy::sanitize_field( 'form_id', 'gf_3:1' ) );
        $this->assertSame( 'Tilmeld [redacted]', TrackWP_Proxy::sanitize_field( 'form_name', 'Tilmeld jens@example.dk' ) );
        $this->assertNull( TrackWP_Proxy::sanitize_field( 'order_ref', '12.xyz' ) );
    }

    public function test_enhanced_schema_k2a() {
        $hash = hash( 'sha256', 'x' );
        $out  = TrackWP_Proxy::sanitize_enhanced( array(
            'email'        => $hash,          // raw email that looks like a hash: dropped
            'email_sha256' => 'not-a-hash',   // hash key without 64 hex: dropped
            'phone_sha256' => strtoupper( $hash ),
            'first_name'   => 'Jens',
            'password'     => 'secret',       // unknown key: dropped
        ) );
        $this->assertSame( array( 'phone_sha256' => $hash, 'first_name' => 'Jens' ), $out );
    }

    public function test_ecommerce_allowlist_has_order_amounts_r15() {
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'items'     => array( array( 'item_id' => 'A' ) ),
            'value_ga4' => '80.00',
            'tax'       => 20,
            'shipping'  => '39',
            'order_ref' => '12.' . str_repeat( 'ab', 12 ),
            'bogus'     => 1,
        ) );
        $this->assertSame( 80.0, $out['value_ga4'] );
        $this->assertSame( 20.0, $out['tax'] );
        $this->assertSame( 39.0, $out['shipping'] );
        $this->assertSame( '12.' . str_repeat( 'ab', 12 ), $out['order_ref'] );
        $this->assertArrayNotHasKey( 'bogus', $out );
    }

    // ------------------------------------------------------------------
    // Category filter
    // ------------------------------------------------------------------

    public function test_strip_by_category_without_consent() {
        $data = TrackWP_Proxy::strip_by_category( array(
            'event'      => 'x',
            'page_url'   => 'https://example.org/a/?utm_source=nl&gclid=G1#top',
            'client_id'  => '1.2',
            'user_agent' => 'UA',
            'fbp'        => 'fb.1.1.1',
            'gclid'      => 'G1',
            'gcl_au'     => '1.1',
            'form_id'    => 'f1',
        ), array( 'analytics' => false, 'marketing' => false ) );

        $this->assertSame( 'https://example.org/a/', $data['page_url'] );
        $this->assertSame( 'f1', $data['form_id'] );
        foreach ( array( 'client_id', 'user_agent', 'fbp', 'gclid', 'gcl_au' ) as $gone ) {
            $this->assertArrayNotHasKey( $gone, $data, $gone );
        }
    }

    public function test_strip_by_category_analytics_only_removes_click_ids_from_urls() {
        $data = TrackWP_Proxy::strip_by_category( array(
            'page_url'      => 'https://example.org/?utm_source=nl&gclid=G1&fbclid=F1',
            'page_referrer' => 'https://ref.example/?wbraid=W1&q=1',
            'client_id'     => '1.2',
            'fbc'           => 'fb.1.1.F1',
        ), array( 'analytics' => true, 'marketing' => false ) );

        $this->assertSame( 'https://example.org/?utm_source=nl', $data['page_url'] );
        $this->assertSame( 'https://ref.example/?q=1', $data['page_referrer'] );
        $this->assertSame( '1.2', $data['client_id'] );
        $this->assertArrayNotHasKey( 'fbc', $data );
    }

    public function test_filter_cannot_reintroduce_identifiers() {
        add_filter( 'trackwp_event_data', function( $data ) {
            $data['gclid']    = 'INJECTED';
            $data['fbp']      = 'fb.1.1.INJECTED';
            $data['enhanced'] = array( 'email_sha256' => hash( 'sha256', 'a@b.dk' ) );
            $data['page_url'] = 'https://example.org/tak/?email=jens%40example.dk&gclid=INJECTED&ok=1#frag';
            $data['consent']  = array( 'analytics' => true, 'marketing' => true );
            return $data;
        } );

        $this->post_event( $this->base_body() );

        $ga4 = $this->ga4_requests();
        $this->assertCount( 1, $ga4 );
        $this->assertStringNotContainsString( 'INJECTED', $ga4[0]['body'] );
        $this->assertStringNotContainsString( 'jens', $ga4[0]['body'] );
        $this->assertStringNotContainsString( hash( 'sha256', 'a@b.dk' ), $ga4[0]['body'] );
        $this->assertStringNotContainsString( 'frag', $ga4[0]['body'] );
    }

    // ------------------------------------------------------------------
    // K3 / R2 strict booleans
    // ------------------------------------------------------------------

    public function test_non_boolean_true_consent_values_send_nothing() {
        foreach ( array( 'true', 1, '1', 'false' ) as $fake ) {
            $this->post_event( $this->base_body( array(
                'consent' => array( 'analytics' => $fake, 'marketing' => $fake, 'v' => 1 ),
            ) ) );
        }
        $this->assertCount( 0, $this->ga4_requests() );

        $this->post_event( $this->base_body() );
        $this->assertCount( 1, $this->ga4_requests() );
    }

    public function test_stale_version_answers_current_version_with_no_store() {
        $response = $this->post_event( $this->base_body( array(
            'consent' => array( 'analytics' => true, 'marketing' => true, 'v' => 99 ),
        ) ) );

        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
        $data = $response->get_data();
        $this->assertSame( 'stale_version', $data['consent'] );
        $this->assertSame( 1, $data['current_version'] );
        $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
        $this->assertCount( 0, $this->ga4_requests() );
    }

    public function test_missing_version_falls_back_to_cookie_not_stale() {
        $fixture = dirname( __FILE__ ) . '/fixtures/consent-cookie.json';
        if ( ! file_exists( $fixture ) ) {
            $this->markTestSkipped( 'tests/fixtures/consent-cookie.json (W0 producer: gen-consent-cookie.mjs) is missing.' );
        }
        $fixture_data = json_decode( file_get_contents( $fixture ), true );
        $accept       = isset( $fixture_data['raw_cookie_value'] ) ? $fixture_data['raw_cookie_value'] : null;
        if ( ! is_string( $accept ) ) {
            $this->markTestSkipped( 'consent-cookie.json has no raw_cookie_value.' );
        }
        // The server version must match the version consent.js wrote.
        update_option( 'trackwp_consent', array( 'consent_version' => (int) $fixture_data['parsed']['v'] ) );
        // PHP exposes cookies URL-decoded; raw_cookie_value is the decoded JSON.
        $_COOKIE['trackwp_consent'] = $accept;

        // 1.10.0 client: consent without v.
        $response = $this->post_event( $this->base_body( array(
            'consent' => array( 'analytics' => true, 'marketing' => true ),
        ) ) );

        $this->assertArrayNotHasKey( 'consent', $response->get_data() );
        $this->assertCount( 1, $this->ga4_requests() );
    }

    // ------------------------------------------------------------------
    // Events: form fields, disabled, reserved
    // ------------------------------------------------------------------

    public function test_form_id_reaches_ga4_body() {
        $this->post_event( $this->base_body( array( 'form_id' => 'cf7_42', 'form_name' => 'Kontakt' ) ) );

        $ga4 = $this->ga4_requests();
        $this->assertCount( 1, $ga4 );
        $body = json_decode( $ga4[0]['body'], true );
        $this->assertSame( 'cf7_42', $body['events'][0]['params']['form_id'] );
    }

    public function test_disabled_event_is_skipped_and_nothing_sent() {
        $events = array();
        foreach ( TrackWP_Events::get_defaults() as $event ) {
            if ( 'form_submit' === $event['name'] ) {
                $event['enabled'] = false;
            }
            $events[] = $event;
        }
        update_option( 'trackwp_events', $events );

        $response = $this->post_event( $this->base_body() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 0, $this->http );
        $rows = $this->log_rows( 'ga4' );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'skipped', $rows[0]['status'] );
        $this->assertSame( 'event_disabled', $rows[0]['reason'] );
    }

    public function test_reserved_names_are_rejected() {
        foreach ( TrackWP_Events::RESERVED_PUBLIC_NAMES as $name ) {
            $response = $this->post_event( $this->base_body( array( 'event' => $name ) ) );
            $this->assertSame( 400, $response->get_status(), $name );

            $validated = TrackWP_Events::validate_event( array(
                'name'         => $name,
                'trigger_type' => 'url_match',
            ) );
            $this->assertWPError( $validated, $name );
        }
        $this->assertCount( 0, $this->http );
    }

    public function test_client_config_carries_meta_resolved_k8() {
        $events = array();
        foreach ( array(
            array( 'form_submit', '' ),              // '' -> event_map() -> Lead
            array( 'phone_click', 'Lead' ),          // explicit standard name wins
            array( 'nyhedsbrev', 'CustomEvent' ),    // unmapped -> custom event name
        ) as $pair ) {
            $events[] = TrackWP_Events::validate_event( array(
                'enabled'      => true,
                'name'         => $pair[0],
                'trigger_type' => 'url_match',
                'url_match'    => '/tak',
                'meta_event'   => $pair[1],
            ) );
        }
        update_option( 'trackwp_events', $events );

        $config   = ( new TrackWP_Events() )->get_client_config();
        $resolved = wp_list_pluck( $config, 'meta_resolved', 'name' );

        $this->assertSame( 'Lead', $resolved['form_submit'] );
        $this->assertSame( 'Lead', $resolved['phone_click'] );
        $this->assertSame( 'nyhedsbrev', $resolved['nyhedsbrev'] );
        foreach ( $config as $event ) {
            // Same rule as the server (producer: TrackWP_Meta::resolve_event_name).
            $this->assertSame( TrackWP_Meta::resolve_event_name( $event['name'], $event['meta_event'] ), $event['meta_resolved'] );
        }
    }

    // ------------------------------------------------------------------
    // K1 statuses, delivery log, stats
    // ------------------------------------------------------------------

    public function test_normalize_result_maps_legacy_and_k1_returns() {
        $this->assertSame( 'ok', TrackWP_Proxy::normalize_result( 'ga4', true )['status'] );
        $unknown = TrackWP_Proxy::normalize_result( 'ga4', null );
        $this->assertSame( array( 'unknown', 'timeout' ), array( $unknown['status'], $unknown['reason'] ) );
        $this->assertSame( 'failed', TrackWP_Proxy::normalize_result( 'meta', false )['status'] );

        $queued = TrackWP_Proxy::normalize_result( 'ga4', array( 'status' => 'queued', 'reason' => 'batched', 'http_code' => 0, 'attempts' => 0, 'detail' => '' ) );
        $this->assertSame( array( 'queued', 'batched' ), array( $queued['status'], $queued['reason'] ) );

        $bogus = TrackWP_Proxy::normalize_result( 'meta', array( 'status' => 'delivered', 'reason' => 'free text', 'detail' => str_repeat( 'x', 900 ) ) );
        $this->assertSame( 'unknown', $bogus['status'] );
        $this->assertSame( '', $bogus['reason'] );
        $this->assertSame( 500, strlen( $bogus['detail'] ) );
    }

    public function test_delivery_log_records_status_and_http_code() {
        $this->http_status = 500;
        $this->post_event( $this->base_body() );

        $rows = $this->log_rows( 'ga4' );
        $this->assertNotEmpty( $rows );
        $this->assertSame( 'failed', $rows[0]['status'] );
        $this->assertSame( 500, (int) $rows[0]['http_code'] );
        $this->assertCount( 1, $this->log_rows( 'received' ) );
    }

    public function test_delivery_log_accepts_all_k1_statuses() {
        foreach ( TrackWP_Proxy::RESULT_STATUSES as $status ) {
            TrackWP_Delivery_Log::record( 'evt_' . md5( $status ), 'form_submit', 'meta', $status, array(), 0, 'sent' );
        }
        $stored = wp_list_pluck( $this->log_rows( 'meta' ), 'status' );
        sort( $stored );
        $expected = TrackWP_Proxy::RESULT_STATUSES;
        sort( $expected );
        $this->assertSame( $expected, $stored );
    }

    public function test_forwarded_stat_counts_delivered_events_and_unknown_names_go_to_other() {
        $this->post_event( $this->base_body() );
        $this->post_event( $this->base_body( array( 'event' => 'not_configured_event' ) ) );

        $stats = get_option( 'trackwp_stats', array() );
        $today = $stats[ gmdate( 'Y-m-d' ) ];
        $this->assertSame( 2, (int) $today['events'] );
        $this->assertSame( 2, (int) $today['forwarded'] );
        $this->assertArrayHasKey( '_other', $today['by_event'] );
        $this->assertArrayNotHasKey( 'not_configured_event', $today['by_event'] );
    }

    public function test_no_forwarded_stat_without_consent() {
        $this->post_event( $this->base_body( array(
            'consent' => array( 'analytics' => false, 'marketing' => false, 'v' => 1 ),
        ) ) );
        $stats = get_option( 'trackwp_stats', array() );
        $today = $stats[ gmdate( 'Y-m-d' ) ];
        $this->assertSame( 1, (int) $today['events'] );
        $this->assertArrayNotHasKey( 'forwarded', $today );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'no_consent', $rows[0]['reason'] );
    }

    // ------------------------------------------------------------------
    // KC8/TR4: ga4_route (dataLayer routing argument)
    // ------------------------------------------------------------------

    private function enable_datalayer( $source = 'gtm', $mode = 'on' ) {
        update_option( 'trackwp_platforms', array(
            'ga4_enabled'           => true,
            'ga4_measurement_id'    => 'G-TEST12345',
            'ga4_api_secret'        => 'test-secret',
            'gtm_datalayer_events'  => $mode,
            'ga4_source'            => $source,
            // M2: configured_for_site() (used by route(), unlike
            // active_for_request()) also requires GTM to be active.
            'gtm_enabled'           => true,
            'gtm_container_id'      => 'GTM-ABC123',
        ) );
    }

    public function test_ga4_route_gtm_is_skipped_with_ga4_via_gtm_reason() {
        $this->enable_datalayer( 'gtm' );
        $this->post_event( $this->base_body( array(
            'ga4_route' => 'gtm',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 0, $this->ga4_requests() );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'skipped', $rows[0]['status'] );
        $this->assertSame( 'ga4_via_gtm', $rows[0]['reason'] );
    }

    public function test_ga4_route_off_is_skipped_with_routed_off_reason() {
        $this->enable_datalayer( 'gtm' );
        $this->post_event( $this->base_body( array(
            'ga4_route' => 'off',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 0, $this->ga4_requests() );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'routed_off', $rows[0]['reason'] );
    }

    /**
     * TR4 (M5 fix): a client 'server' claim that the server's own
     * recomputation does NOT confirm (here: site config is ga4_source=gtm,
     * so the server always recomputes 'gtm') falls back to the ORDINARY
     * consent-based reason codes (no_consent/stale_version) — never a
     * fabricated ga4_via_gtm/routed_off for a route the client never
     * actually claimed. Without analytics consent, that ordinary reason is
     * no_consent.
     */
    public function test_server_claim_mismatch_without_consent_uses_no_consent_reason() {
        $this->enable_datalayer( 'gtm' ); // site config: everything via GTM
        $this->post_event( $this->base_body( array(
            'ga4_route' => 'server', // a forged or stale client claim
            'consent'   => array( 'analytics' => false, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 0, $this->ga4_requests() );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'skipped', $rows[0]['status'] );
        $this->assertSame( 'no_consent', $rows[0]['reason'] );
    }

    /**
     * Same mismatch, but WITH analytics consent: TR4 says fall back to the
     * plain 1.11.0 consent check, which sends (no special-cased skip).
     */
    public function test_server_claim_mismatch_with_consent_dispatches_like_1_11_0() {
        $this->enable_datalayer( 'gtm' );
        $this->post_event( $this->base_body( array(
            'ga4_route' => 'server',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 1, $this->ga4_requests(), 'a server-claim mismatch with consent present falls back to the ordinary consent branch, which sends' );
    }

    /**
     * M2: the server must not silently ignore ga4_route in "test" mode just
     * because the REST request carries no reliable admin session — this is
     * the exact scenario the reviewer reproduced (double GA4 delivery).
     */
    public function test_ga4_route_applies_in_test_mode_even_without_admin_session() {
        $this->enable_datalayer( 'gtm', 'test' );
        // Deliberately no wp_set_current_user(): a real REST POST from a
        // visitor's browser is not an authenticated admin session either.
        $this->post_event( $this->base_body( array(
            'ga4_route' => 'gtm',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 0, $this->ga4_requests(), 'ga4_route must still be honoured in test mode without an admin session' );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'ga4_via_gtm', $rows[0]['reason'] );
    }

    public function test_ga4_route_server_dispatches_when_both_sides_agree() {
        $this->enable_datalayer( 'split' );
        $events   = TrackWP_Events::get_defaults();
        $events[] = array(
            'name' => 'purchase', 'enabled' => true, 'trigger_type' => 'custom',
            'send_to' => array( 'ga4' => true, 'meta' => false, 'google_ads' => false ),
            'value' => 0, 'currency' => 'DKK',
        );
        update_option( 'trackwp_events', $events );

        $this->post_event( $this->base_body( array(
            'event'     => 'purchase',
            'ga4_route' => 'server',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 1, $this->ga4_requests(), 'both client and server agree on server: MP must dispatch' );
    }

    /**
     * KC8/TR4: the client decided 'gtm' at push time (e.g. no statistics
     * consent then); the server, recomputing with split + purchase + MP +
     * statistics, would say 'server' -- but it must never upgrade the
     * client's 'gtm' claim, or GA4 would receive the purchase twice
     * (GTM tag from the dataLayer AND Measurement Protocol).
     */
    public function test_server_never_upgrades_client_gtm_to_server_when_server_would_say_server() {
        $this->enable_datalayer( 'split' );
        $events   = TrackWP_Events::get_defaults();
        $events[] = array(
            'name' => 'purchase', 'enabled' => true, 'trigger_type' => 'custom',
            'send_to' => array( 'ga4' => true, 'meta' => false, 'google_ads' => false ),
            'value' => 0, 'currency' => 'DKK',
        );
        update_option( 'trackwp_events', $events );

        $this->post_event( $this->base_body( array(
            'event'     => 'purchase',
            'ga4_route' => 'gtm',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 0, $this->ga4_requests(), 'client gtm must never be upgraded to an MP send' );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'skipped', $rows[0]['status'] );
        $this->assertSame( 'ga4_via_gtm', $rows[0]['reason'] );
    }

    public function test_missing_ga4_route_falls_back_to_1_11_0_behaviour() {
        $this->enable_datalayer( 'gtm' );
        // No ga4_route field at all (old cached JS, or an event pushDataLayer
        // never saw): plain consent-based routing, unaffected by KC8.
        $this->post_event( $this->base_body( array(
            'consent' => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 1, $this->ga4_requests() );
    }

    public function test_ga4_route_ignored_when_layer_not_active() {
        // dataLayer is off (gtm_datalayer_events absent/off): a ga4_route
        // value must never suppress GA4 delivery.
        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => 'G-TEST12345',
            'ga4_api_secret'     => 'test-secret',
        ) );
        $this->post_event( $this->base_body( array(
            'ga4_route' => 'gtm',
            'consent'   => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
        ) ) );

        $this->assertCount( 1, $this->ga4_requests(), 'ga4_route must be inert while the dataLayer layer is off' );
    }

    // ------------------------------------------------------------------
    // Purchase (R9): a bad order_ref sends nothing and claims nothing
    // ------------------------------------------------------------------

    public function test_invalid_order_ref_is_skipped_without_claim() {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TrackWP_Order_Claims' ) ) {
            $this->markTestSkipped( 'WooCommerce and TrackWP_Order_Claims are required.' );
        }
        $events   = TrackWP_Events::get_defaults();
        $events[] = TrackWP_Events::get_woocommerce_event_templates()['purchase'];
        update_option( 'trackwp_events', $events );

        $this->post_event( $this->base_body( array(
            'event'     => 'purchase',
            'order_ref' => '999999.' . str_repeat( '0', 24 ),
            'value'     => 1000,
        ) ) );

        $this->assertCount( 0, $this->ga4_requests() );
        $rows = $this->log_rows( 'ga4' );
        $this->assertSame( 'skipped', $rows[0]['status'] );
        $this->assertSame( 'invalid_order_ref', $rows[0]['reason'] );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( 999999, 'purchase' ) );
    }

    // ------------------------------------------------------------------
    // /my-data shows the consent cookie via TrackWP_Consent (R3)
    // ------------------------------------------------------------------

    /**
     * Producer: TrackWP_Consent::write_consent_cookie(), the server's own
     * writer of trackwp_consent; the value is taken from what it sent.
     */
    private function produced_consent_cookie( $version ) {
        TrackWP_Cookies::reset_sent();
        TrackWP_Consent::write_consent_cookie( $version, 1790000000000, '0b6f3c1e-8f4a-4d2b-9c7e-1a2b3c4d5e6f', true, false, false );
        foreach ( TrackWP_Cookies::sent_cookies() as $cookie ) {
            if ( 'trackwp_consent' === $cookie['name'] ) {
                return $cookie['value'];
            }
        }
        $this->fail( 'write_consent_cookie() sent no trackwp_consent cookie' );
    }

    private function get_my_data() {
        $request = new WP_REST_Request( 'GET', '/trackwp/v1/my-data' );
        return rest_get_server()->dispatch( $request );
    }

    public function test_my_data_shows_consent_cookie_decoded_by_consent_class() {
        $_COOKIE['trackwp_consent'] = $this->produced_consent_cookie( 1 );

        $response = $this->get_my_data();
        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
        $state = $response->get_data()['consent_state'];
        $this->assertSame( TrackWP_Consent::decode_cookie(), $state );
        $this->assertTrue( $state['statistics'] );
        $this->assertFalse( $state['marketing'] );
        $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
    }

    public function test_my_data_shows_outdated_choice_too() {
        // Written for version 1; the server is now on version 2, so the
        // choice is not valid consent, but it is still what the browser holds.
        $_COOKIE['trackwp_consent'] = $this->produced_consent_cookie( 1 );
        update_option( 'trackwp_consent', array( 'consent_version' => 2 ) );

        $state = $this->get_my_data()->get_data()['consent_state'];
        $this->assertSame( 1, $state['v'] );
        $this->assertFalse( TrackWP_Consent::get_current_consent()['has_choice'] );
    }

    public function test_my_data_without_cookie_has_null_state() {
        $this->assertNull( $this->get_my_data()->get_data()['consent_state'] );
    }

    // ------------------------------------------------------------------
    // Keepalive never sets the consent cookie (K4) and answers no-store
    // ------------------------------------------------------------------

    public function test_keepalive_is_no_store_and_never_sets_consent_cookie() {
        TrackWP_Cookies::reset_sent();
        $request  = new WP_REST_Request( 'POST', '/trackwp/v1/keepalive' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
        foreach ( TrackWP_Cookies::sent_cookies() as $cookie ) {
            $name = is_array( $cookie ) && isset( $cookie['name'] ) ? $cookie['name'] : '';
            $this->assertNotSame( 'trackwp_consent', $name );
        }
    }
}
