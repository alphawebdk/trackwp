<?php
/**
 * TrackWP_GA4 Measurement Protocol adapter tests (W3).
 *
 * HTTP is intercepted with pre_http_request; bodies are what wp_remote_post
 * would have sent. Enhanced data is built with the producer
 * TrackWP_Hash::normalize_enhanced() (includes/class-trackwp-hash.php), the
 * same call the proxy makes.
 */
class Test_TrackWP_GA4 extends WP_UnitTestCase {

    /** @var array Captured requests: array('url' => string, 'body' => array) */
    private $requests = array();

    /** @var array Queue of responses (array or WP_Error); last one repeats. */
    private $responses = array();

    public function set_up() {
        parent::set_up();
        $this->requests  = array();
        $this->responses = array( self::response( 204 ) );
        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => 1,
            'ga4_measurement_id' => 'G-TEST1234',
            'ga4_api_secret'     => TrackWP_Hash::encode( 'secret' ),
        ) );
        update_option( 'trackwp_advanced', array( 'default_phone_country' => 'DK' ) );
        delete_transient( 'trackwp_ga4_queue' );
        delete_transient( 'trackwp_ga4_withdrawn' );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
        unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_COOKIE['trackwp_consent'] );
        wp_clear_scheduled_hook( 'trackwp_flush_ga4' );
        parent::tear_down();
    }

    public function intercept( $pre, $args, $url ) {
        $this->requests[] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ), 'timeout' => $args['timeout'] );
        return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
    }

    private static function response( $code, $headers = array() ) {
        return array(
            'headers'  => $headers,
            'body'     => '',
            'response' => array( 'code' => $code, 'message' => '' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private static function event( $overrides = array() ) {
        return array_merge( array(
            'event'         => 'form_submit',
            'event_id'      => 'evt_' . str_repeat( 'a', 32 ),
            'client_id'     => '123.456',
            'session_id'    => '1700000000',
            'page_url'      => 'https://example.com/kontakt',
            'page_title'    => 'Kontakt',
            'page_referrer' => 'https://www.google.com/',
            'engaged_ms'    => 5300,
            'user_agent'    => 'Mozilla/5.0 Test',
            'form_id'       => 'contact-1',
            'consent'       => array( 'analytics' => true, 'marketing' => true, 'v' => 1, 'source' => 'payload', 'stale' => false ),
        ), $overrides );
    }

    public function test_body_has_session_ip_ua_referrer_engagement_and_no_device() {
        $result = ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );

        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 'ga4', $result['destination'] );
        $body   = $this->requests[0]['body'];
        $params = $body['events'][0]['params'];
        $this->assertSame( '1700000000', $params['session_id'] );
        $this->assertArrayNotHasKey( 'ga_session_id', $params );
        $this->assertSame( '203.0.113.9', $body['ip_override'] );
        $this->assertSame( 'Mozilla/5.0 Test', $body['user_agent'] );
        $this->assertArrayNotHasKey( 'device', $body );
        $this->assertSame( 'https://www.google.com/', $params['page_referrer'] );
        $this->assertSame( 5300, $params['engagement_time_msec'] );
        $this->assertSame( 'contact-1', $params['form_id'] );
        $this->assertLessThanOrEqual( 4.0, $this->requests[0]['timeout'] );
    }

    public function test_engagement_omitted_when_zero_and_no_ip_without_analytics() {
        ( new TrackWP_GA4() )->send_event( self::event( array(
            'engaged_ms' => 0,
            'consent'    => array( 'analytics' => false, 'marketing' => true, 'v' => 1 ),
        ) ) );
        $body = $this->requests[0]['body'];
        $this->assertArrayNotHasKey( 'engagement_time_msec', $body['events'][0]['params'] );
        $this->assertArrayNotHasKey( 'ip_override', $body );
        $this->assertArrayNotHasKey( 'user_agent', $body );
    }

    public function test_string_true_consent_is_not_granted() {
        ( new TrackWP_GA4() )->send_event( self::event( array(
            'consent' => array( 'analytics' => 'true', 'marketing' => 1, 'v' => 1 ),
        ) ) );
        $body = $this->requests[0]['body'];
        $this->assertSame( 'DENIED', $body['consent']['ad_user_data'] );
        $this->assertArrayNotHasKey( 'ip_override', $body );
    }

    public function test_missing_consent_means_false_without_cookie_fallback() {
        $_COOKIE['trackwp_consent'] = rawurlencode( wp_json_encode( array( 'v' => 1, 'statistics' => true, 'marketing' => true, 'necessary' => true ) ) );
        $event = self::event();
        unset( $event['consent'] );
        ( new TrackWP_GA4() )->send_event( $event );
        unset( $_COOKIE['trackwp_consent'] );
        $body = $this->requests[0]['body'];
        $this->assertSame( 'DENIED', $body['consent']['ad_user_data'] );
        $this->assertArrayNotHasKey( 'ip_override', $body );
        $this->assertArrayNotHasKey( 'user_agent', $body );
    }

    public function test_value_ga4_with_tax_and_shipping_r15() {
        ( new TrackWP_GA4() )->send_event( self::event( array(
            'event'     => 'purchase',
            'value'     => 125.0,
            'currency'  => 'DKK',
            'ecommerce' => array( 'value_ga4' => 80.0, 'tax' => 20.0, 'shipping' => 25.0, 'transaction_id' => '1042' ),
        ) ) );
        $params = $this->requests[0]['body']['events'][0]['params'];
        $this->assertSame( 80.0, (float) $params['value'] );
        $this->assertSame( 20.0, (float) $params['tax'] );
        $this->assertSame( 25.0, (float) $params['shipping'] );
        $this->assertSame( '1042', $params['transaction_id'] );
    }

    public function test_user_data_only_with_customer_data_sharing() {
        $enhanced = TrackWP_Hash::normalize_enhanced( array( 'email' => 'Jane.Doe@gmail.com' ) );
        ( new TrackWP_GA4() )->send_event( self::event( array( 'enhanced' => $enhanced ) ) );
        $this->assertSame( hash( 'sha256', 'janedoe@gmail.com' ), $this->requests[0]['body']['user_data']['sha256_email_address'] );

        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => 0 ) );
        ( new TrackWP_GA4() )->send_event( self::event( array( 'enhanced' => $enhanced ) ) );
        $this->assertArrayNotHasKey( 'user_data', $this->requests[1]['body'] );
    }

    public function test_customer_data_sharing_filter_false_drops_user_data() {
        add_filter( 'trackwp_customer_data_sharing', '__return_false' );
        $enhanced = TrackWP_Hash::normalize_enhanced( array( 'email' => 'Jane.Doe@gmail.com' ) );
        ( new TrackWP_GA4() )->send_event( self::event( array( 'enhanced' => $enhanced ) ) );
        remove_filter( 'trackwp_customer_data_sharing', '__return_false' );
        $this->assertArrayNotHasKey( 'user_data', $this->requests[0]['body'] );
    }

    public function test_user_id_via_auth_cookie() {
        update_option( 'trackwp_advanced', array( 'ga4_user_id_enabled' => 1 ) );
        $uid = self::factory()->user->create();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' );

        ( new TrackWP_GA4() )->send_event( self::event() );
        $this->assertSame( hash( 'sha256', $uid . ':' . get_site_url() ), $this->requests[0]['body']['user_id'] );
    }

    public function test_timeout_is_unknown_without_retry() {
        $this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 2000 milliseconds' ) );
        $result = ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'unknown', $result['status'] );
        $this->assertSame( 'timeout', $result['reason'] );
        $this->assertCount( 1, $this->requests );
    }

    public function test_connection_error_retried_once() {
        $this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), self::response( 204 ) );
        $result = ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 2, $result['attempts'] );
    }

    public function test_5xx_max_two_attempts() {
        $this->responses = array( self::response( 503 ) );
        $result = ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'http_5xx', $result['reason'] );
        $this->assertCount( 2, $this->requests );
    }

    public function test_429_retry_after_within_budget_is_honoured() {
        $this->responses = array( self::response( 429, array( 'retry-after' => '1' ) ), self::response( 204 ) );
        $start  = microtime( true );
        $result = ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 2, $result['attempts'] );
        $this->assertGreaterThanOrEqual( 0.9, microtime( true ) - $start );
    }

    public function test_429_retry_after_beyond_budget_is_not_retried() {
        $this->responses = array( self::response( 429, array( 'retry-after' => '30' ) ) );
        $result = ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'http_429', $result['reason'] );
        $this->assertSame( 429, $result['http_code'] );
        $this->assertCount( 1, $this->requests );
    }

    public function test_batching_returns_queued_and_flush_sets_timestamp_micros() {
        update_option( 'trackwp_advanced', array( 'batching_enabled' => 1 ) );
        $ga4      = new TrackWP_GA4();
        $enhanced = TrackWP_Hash::normalize_enhanced( array( 'email' => 'a@example.com' ) );
        $result   = $ga4->send_event( self::event( array( 'enhanced' => $enhanced ) ) );
        $this->assertSame( 'queued', $result['status'] );
        $this->assertSame( 'batched', $result['reason'] );
        $this->assertCount( 0, $this->requests );

        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertArrayNotHasKey( 'enhanced', $queue[0] );
        $this->assertSame( '203.0.113.9', $queue[0]['_ip_override'] );

        $ga4->flush_queue();
        $this->assertCount( 1, $this->requests );
        $event = $this->requests[0]['body']['events'][0];
        $this->assertSame( $queue[0]['_queued_at_us'], $event['timestamp_micros'] );
        $this->assertSame( '203.0.113.9', $this->requests[0]['body']['ip_override'] );
    }

    public function test_batch_identity_r18_two_client_ids_give_two_requests() {
        $ga4 = new TrackWP_GA4();
        set_transient( 'trackwp_ga4_queue', array(
            self::queued( self::event( array( 'client_id' => '111.1' ) ) ),
            self::queued( self::event( array( 'client_id' => '222.2' ) ) ),
        ), HOUR_IN_SECONDS );
        $ga4->flush_queue();
        $this->assertCount( 2, $this->requests );
        $this->assertNotSame( $this->requests[0]['body']['client_id'], $this->requests[1]['body']['client_id'] );
    }

    public function test_batch_identity_r18_session_and_user_agent_split() {
        $ga4 = new TrackWP_GA4();
        set_transient( 'trackwp_ga4_queue', array(
            self::queued( self::event() ),
            self::queued( self::event() ),
            self::queued( self::event( array( 'session_id' => '1800000000' ) ) ),
            self::queued( self::event( array( 'user_agent' => 'Other UA' ) ) ),
        ), HOUR_IN_SECONDS );
        $ga4->flush_queue();
        $this->assertCount( 3, $this->requests );
        $this->assertCount( 2, $this->requests[0]['body']['events'] );
    }

    public function test_batch_split_at_25_events() {
        $ga4    = new TrackWP_GA4();
        $events = array();
        for ( $i = 0; $i < 30; $i++ ) {
            $events[] = self::queued( self::event() );
        }
        set_transient( 'trackwp_ga4_queue', $events, HOUR_IN_SECONDS );
        $ga4->flush_queue();
        $this->assertCount( 2, $this->requests );
        $this->assertCount( 25, $this->requests[0]['body']['events'] );
        $this->assertCount( 5, $this->requests[1]['body']['events'] );
    }

    public function test_failed_batch_requeued_within_72h_only() {
        $this->responses = array( self::response( 500 ) );
        $ga4   = new TrackWP_GA4();
        $fresh = self::queued( self::event(), time() - 71 * HOUR_IN_SECONDS );
        $old   = self::queued( self::event( array( 'client_id' => '999.9' ) ), time() - 73 * HOUR_IN_SECONDS );
        set_transient( 'trackwp_ga4_queue', array( $fresh, $old ), HOUR_IN_SECONDS );
        $ga4->flush_queue();

        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertCount( 1, $queue );
        $this->assertSame( '123.456', $queue[0]['client_id'] );
    }

    private const CID_A = '11111111-1111-4111-8111-111111111111';
    private const CID_B = '22222222-2222-4222-8222-222222222222';

    /**
     * Queue an event through the producer (queue_event) with a consent cookie
     * carrying $consent_id ('' = no cookie).
     */
    private static function queue_with_cookie( $consent_id, $event = null ) {
        unset( $_COOKIE['trackwp_consent'] );
        if ( $consent_id !== '' ) {
            $_COOKIE['trackwp_consent'] = wp_json_encode( array(
                'v' => TrackWP_Consent::server_version(), 'ts' => '2026-09-01T10:00:00.000Z', 'id' => $consent_id,
                'necessary' => true, 'statistics' => true, 'marketing' => true, 'personalisation' => false,
            ) );
        }
        ( new TrackWP_GA4() )->queue_event( $event === null ? self::event() : $event );
        unset( $_COOKIE['trackwp_consent'] );
    }

    public function test_snapshot_sets_consent_id_from_cookie() {
        self::queue_with_cookie( self::CID_A );
        self::queue_with_cookie( '' );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertSame( self::CID_A, $queue[0]['_consent_id'] );
        $this->assertSame( '', $queue[1]['_consent_id'] );
    }

    public function test_purge_withdrawal_removes_only_own_events() {
        self::queue_with_cookie( self::CID_A );
        self::queue_with_cookie( self::CID_B );
        self::queue_with_cookie( '' );
        self::queue_with_cookie( self::CID_A );

        $n = TrackWP_GA4::purge_queued_for_consent( self::CID_A, false, false );

        $this->assertSame( 2, $n );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertCount( 2, $queue );
        $this->assertSame( self::CID_B, $queue[0]['_consent_id'] );
        $this->assertSame( '', $queue[1]['_consent_id'] );
    }

    public function test_purge_marketing_downgrade_clears_user_data_and_keeps_event() {
        self::queue_with_cookie( self::CID_A );
        self::queue_with_cookie( self::CID_B );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $queue[0]['_user_data'] = array( 'sha256_email_address' => str_repeat( 'a', 64 ) );
        $queue[1]['_user_data'] = array( 'sha256_email_address' => str_repeat( 'b', 64 ) );
        set_transient( 'trackwp_ga4_queue', $queue, HOUR_IN_SECONDS );

        $n = TrackWP_GA4::purge_queued_for_consent( self::CID_A, true, false );

        $this->assertSame( 1, $n );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertCount( 2, $queue );
        $this->assertFalse( $queue[0]['_consent_marketing'] );
        $this->assertSame( array(), $queue[0]['_user_data'] );
        $this->assertTrue( $queue[1]['_consent_marketing'] );
        $this->assertNotEmpty( $queue[1]['_user_data'] );
    }

    public function test_purge_leaves_events_without_consent_id_and_empty_queue_alone() {
        $this->assertSame( 0, TrackWP_GA4::purge_queued_for_consent( self::CID_A, false, false ) );
        $this->assertFalse( get_transient( 'trackwp_ga4_queue' ) );

        self::queue_with_cookie( '' );
        $this->assertSame( 0, TrackWP_GA4::purge_queued_for_consent( self::CID_A, false, false ) );
        $this->assertSame( 0, TrackWP_GA4::purge_queued_for_consent( '', false, false ) );
        $this->assertCount( 1, get_transient( 'trackwp_ga4_queue' ) );
    }

    /**
     * Run a flush that fails with 503 while $during() runs inside the HTTP
     * call (the in-flight window), and return the re-queued events.
     */
    private function flush_failing_with( $during ) {
        $this->responses = array( self::response( 503 ) );
        $done            = false;
        $cb              = function ( $pre ) use ( $during, &$done ) {
            if ( ! $done ) {
                $done = true;
                $during();
            }
            return $pre;
        };
        add_filter( 'pre_http_request', $cb, 5 );
        ( new TrackWP_GA4() )->flush_queue();
        remove_filter( 'pre_http_request', $cb, 5 );
        $queue = get_transient( 'trackwp_ga4_queue' );
        return is_array( $queue ) ? $queue : array();
    }

    public function test_withdrawal_during_failed_flush_is_not_requeued() {
        self::queue_with_cookie( self::CID_A );
        self::queue_with_cookie( self::CID_B );
        $queue = $this->flush_failing_with( function () {
            TrackWP_GA4::purge_queued_for_consent( self::CID_A, false, false );
        } );
        $ids = array_column( $queue, '_consent_id' );
        $this->assertNotContains( self::CID_A, $ids );
        $this->assertContains( self::CID_B, $ids );
    }

    public function test_marketing_downgrade_during_failed_flush_is_applied_to_requeue() {
        self::queue_with_cookie( self::CID_A );
        $queue = $this->flush_failing_with( function () {
            TrackWP_GA4::purge_queued_for_consent( self::CID_A, true, false );
        } );
        $this->assertCount( 1, $queue );
        $this->assertSame( self::CID_A, $queue[0]['_consent_id'] );
        $this->assertFalse( $queue[0]['_consent_marketing'] );
        $this->assertSame( array(), $queue[0]['_user_data'] );
    }

    public function test_failed_flush_without_purge_requeues_event_unchanged() {
        self::queue_with_cookie( self::CID_A );
        $queue = $this->flush_failing_with( function () {} );
        $this->assertCount( 1, $queue );
        $this->assertSame( self::CID_A, $queue[0]['_consent_id'] );
        $this->assertTrue( $queue[0]['_consent_marketing'] );
    }

    public function test_withdrawal_survives_overlapping_flush() {
        self::queue_with_cookie( self::CID_A );
        $queue = $this->flush_failing_with( function () {
            TrackWP_GA4::purge_queued_for_consent( self::CID_A, false, false );
            self::queue_with_cookie( self::CID_B );
            ( new TrackWP_GA4() )->flush_queue();
        } );
        $ids = array_column( $queue, '_consent_id' );
        $this->assertNotContains( self::CID_A, $ids );
        $this->assertContains( self::CID_B, $ids );
    }

    public function test_withdrawal_record_spares_events_queued_after_it() {
        TrackWP_GA4::purge_queued_for_consent( self::CID_A, false, false );
        self::queue_with_cookie( self::CID_A );
        $queue = $this->flush_failing_with( function () {} );
        $this->assertCount( 1, $queue );
        $this->assertSame( self::CID_A, $queue[0]['_consent_id'] );
        $this->assertTrue( $queue[0]['_consent_marketing'] );
    }

    public function test_purge_asks_hyperdb_style_db_for_master_reads() {
        global $wpdb;
        $spy = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
            public $master_calls = 0;
            public function send_reads_to_masters() {
                $this->master_calls++;
            }
        };
        $spy->set_prefix( $wpdb->prefix );
        $original = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $spy;
        try {
            TrackWP_GA4::purge_queued_for_consent( 'x', false, false );
            delete_transient( 'trackwp_ga4_withdrawn' ); // spy connection autocommits; clean up there
        } finally {
            $GLOBALS['wpdb'] = $original;
        }
        $this->assertSame( 1, $spy->master_calls );
    }

    /**
     * Build a queued entry through the producer (queue_event) and return it.
     */
    private static function queued( $event, $queued_at = null ) {
        delete_transient( 'trackwp_ga4_queue' );
        ( new TrackWP_GA4() )->queue_event( $event );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $entry = $queue[0];
        delete_transient( 'trackwp_ga4_queue' );
        if ( $queued_at !== null ) {
            $entry['_queued_at']    = $queued_at;
            $entry['_queued_at_us'] = $queued_at * 1000000;
        }
        return $entry;
    }
}
