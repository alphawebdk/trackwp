<?php
/**
 * 1.11.3: extra GA4 Measurement IDs.
 *
 * Settings are produced by the real TrackWP_Settings::sanitize_platforms()
 * and export_settings()/import_settings(); queue entries by the real
 * TrackWP_GA4::queue_event()/send_event(). HTTP is intercepted with
 * pre_http_request, as in tests/test-ga4.php.
 */
class Test_TrackWP_GA4_Extra_Ids extends WP_UnitTestCase {

    const PRIMARY = 'G-TEST1234';
    const EXTRA_A = 'G-EXTRAAAA1';
    const EXTRA_B = 'G-EXTRABBB2';

    /** @var array */
    private $requests = array();

    /** @var array Responses by measurement_id; default 204. */
    private $codes = array();

    public function set_up() {
        parent::set_up();
        $this->requests = array();
        $this->codes    = array();
        update_option( 'trackwp_advanced', array( 'default_phone_country' => 'DK' ) );
        delete_transient( 'trackwp_ga4_queue' );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        global $wp_settings_errors;
        $wp_settings_errors = array();
        add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
        wp_clear_scheduled_hook( 'trackwp_flush_ga4' );
        parent::tear_down();
    }

    public function intercept( $pre, $args, $url ) {
        $q = array();
        wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
        $this->requests[] = array( 'url' => $url, 'query' => $q, 'body' => json_decode( $args['body'], true ) );
        $mid  = isset( $q['measurement_id'] ) ? $q['measurement_id'] : '';
        $code = isset( $this->codes[ $mid ] ) ? $this->codes[ $mid ] : 204;
        return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
    }

    private function requests_for( $mid ) {
        return array_values( array_filter( $this->requests, function ( $r ) use ( $mid ) {
            return isset( $r['query']['measurement_id'] ) && $r['query']['measurement_id'] === $mid;
        } ) );
    }

    private static function event( $overrides = array() ) {
        return array_merge( array(
            'event'      => 'form_submit',
            'event_id'   => 'evt_' . str_repeat( 'b', 32 ),
            'client_id'  => '123.456',
            'session_id' => '1700000000',
            'page_url'   => 'https://example.com/kontakt',
            'page_title' => 'Kontakt',
            'consent'    => array( 'analytics' => true, 'marketing' => false, 'v' => 1, 'source' => 'payload', 'stale' => false ),
        ), $overrides );
    }

    /**
     * Save platforms through the real sanitizer (as options.php would).
     */
    private function save( $input ) {
        $settings = new TrackWP_Settings();
        $out      = $settings->sanitize_platforms( $input );
        update_option( 'trackwp_platforms', $out );
        return $out;
    }

    private function base_input( $extra ) {
        return array(
            'ga4_enabled'        => 1,
            'ga4_measurement_id' => self::PRIMARY,
            'ga4_api_secret'     => 'primary-secret',
            'ga4_gtag_enabled'   => 1,
            'ga4_extra_ids'      => $extra,
        );
    }

    private function configured( $with_b_secret = false, $batching = false ) {
        $this->save( $this->base_input( array(
            array( 'id' => self::EXTRA_A, 'api_secret' => 'secret-a' ),
            array( 'id' => self::EXTRA_B, 'api_secret' => $with_b_secret ? 'secret-b' : '' ),
        ) ) );
        update_option( 'trackwp_advanced', $batching ? array( 'batching_enabled' => 1 ) : array() );
    }

    // 1. Sanitation.
    public function test_sanitize_trims_uppercases_validates_dedups_and_caps() {
        $out = $this->save( $this->base_input( array(
            array( 'id' => '  g-extraaaa1 ', 'api_secret' => 'secret-a' ),
            array( 'id' => 'UA-12345', 'api_secret' => 'x' ),
            array( 'id' => strtolower( self::PRIMARY ), 'api_secret' => 'x' ),
            array( 'id' => 'G-NOTSAVED9', 'api_secret' => 'x' ),
        ) ) );
        $this->assertCount( 1, $out['ga4_extra_ids'], 'Only rows 0..2 are read; invalid and primary dropped' );
        $this->assertSame( self::EXTRA_A, $out['ga4_extra_ids'][0]['id'] );
        $this->assertSame( 'secret-a', TrackWP_Hash::decode( $out['ga4_extra_ids'][0]['api_secret'] ) );
        $codes = wp_list_pluck( get_settings_errors( 'trackwp_platforms' ), 'code' );
        $this->assertContains( 'ga4_extra_id_format_1', $codes );

        $out = $this->save( $this->base_input( array(
            array( 'id' => self::EXTRA_A ),
            array( 'id' => 'g-extraaaa1' ),
            array( 'id' => self::EXTRA_B ),
        ) ) );
        $this->assertSame( array( self::EXTRA_A, self::EXTRA_B ), wp_list_pluck( $out['ga4_extra_ids'], 'id' ) );
    }

    // 2 + 3. Masked secret keeps the stored one per ID; a changed ID resets it.
    public function test_masked_secret_kept_per_id_and_reset_on_id_change() {
        $this->save( $this->base_input( array( array( 'id' => self::EXTRA_A, 'api_secret' => 'secret-a' ) ) ) );
        $stored = get_option( 'trackwp_platforms' );
        $enc_a  = $stored['ga4_extra_ids'][0]['api_secret'];

        // Same ID moved to row 1 with the masked placeholder: secret follows the ID.
        $out = $this->save( $this->base_input( array(
            array( 'id' => self::EXTRA_B, 'api_secret' => '' ),
            array( 'id' => self::EXTRA_A, 'api_secret' => '••••••••' ),
        ) ) );
        $this->assertSame( '', $out['ga4_extra_ids'][0]['api_secret'] );
        $this->assertSame( $enc_a, $out['ga4_extra_ids'][1]['api_secret'] );

        // Row 1 now holds a new ID with the mask: no secret carried over.
        $out = $this->save( $this->base_input( array(
            array( 'id' => self::EXTRA_B, 'api_secret' => '' ),
            array( 'id' => 'G-NEWID999', 'api_secret' => '••••••••' ),
        ) ) );
        $this->assertSame( '', $out['ga4_extra_ids'][1]['api_secret'] );
    }

    // 4. Clear flag.
    public function test_clear_flag_deletes_secret() {
        $this->save( $this->base_input( array( array( 'id' => self::EXTRA_A, 'api_secret' => 'secret-a' ) ) ) );
        $input = $this->base_input( array( array( 'id' => self::EXTRA_A, 'api_secret' => '••••••••' ) ) );
        $input['ga4_extra_secret_0_clear'] = '1';
        $out = $this->save( $input );
        $this->assertSame( '', $out['ga4_extra_ids'][0]['api_secret'] );
        $this->assertArrayNotHasKey( 'ga4_extra_secret_0_clear', $out );
    }

    // 5. Export/import.
    public function test_export_strips_extra_secrets_and_import_does_not_double_encode() {
        $this->save( $this->base_input( array( array( 'id' => self::EXTRA_A, 'api_secret' => 'secret-a' ) ) ) );
        $enc_a = get_option( 'trackwp_platforms' )['ga4_extra_ids'][0]['api_secret'];

        $plain = TrackWP_Settings::export_settings( false );
        $this->assertSame( self::EXTRA_A, $plain['platforms']['ga4_extra_ids'][0]['id'] );
        $this->assertArrayNotHasKey( 'api_secret', $plain['platforms']['ga4_extra_ids'][0] );

        $full = TrackWP_Settings::export_settings( true );
        $this->assertSame( $enc_a, $full['platforms']['ga4_extra_ids'][0]['api_secret'] );

        update_option( 'trackwp_platforms', array() );
        TrackWP_Settings::import_settings( $full );
        $after = get_option( 'trackwp_platforms' );
        $this->assertSame( 'secret-a', TrackWP_Hash::decode( $after['ga4_extra_ids'][0]['api_secret'] ) );
    }

    // 6. Runtime gate.
    public function test_extra_destinations_require_ga4_enabled_and_valid_primary() {
        $p = array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => self::PRIMARY,
            'ga4_extra_ids'      => array( array( 'id' => self::EXTRA_A, 'api_secret' => '' ) ),
        );
        $this->assertSame( array( self::EXTRA_A ), wp_list_pluck( TrackWP_GA4::extra_destinations( $p ), 'id' ) );
        $this->assertSame( array(), TrackWP_GA4::extra_destinations( array_merge( $p, array( 'ga4_enabled' => false ) ) ) );
        $this->assertSame( array(), TrackWP_GA4::extra_destinations( array_merge( $p, array( 'ga4_measurement_id' => 'UA-1' ) ) ) );
        $this->assertSame( array(), TrackWP_GA4::allowed_measurement_ids( array_merge( $p, array( 'ga4_measurement_id' => 'UA-1' ) ) ) );
        $this->assertSame( array( self::PRIMARY ), TrackWP_GA4::allowed_measurement_ids( array_merge( $p, array( 'ga4_enabled' => false ) ) ) );
    }

    // 7 + 8. Fan-out: primary inline, A via queue with its own secret, B never.
    public function test_mp_fan_out_primary_inline_extra_via_queue_with_own_secret() {
        $this->configured( false );
        $ga4    = new TrackWP_GA4();
        $result = $ga4->send_event( self::event( array(
            'ga_session_cookies' => array(
                array( 'id' => substr( self::PRIMARY, 2 ), 'value' => 'GS2.1.s1111111111$o1$g0' ),
                array( 'id' => substr( self::EXTRA_A, 2 ), 'value' => 'GS2.1.s2222222222$o1$g0' ),
            ),
        ) ), 4.0 );

        $this->assertSame( 'ok', $result['status'] );
        $this->assertCount( 1, $this->requests, 'Only the primary is sent inline' );
        $this->assertSame( self::PRIMARY, $this->requests[0]['query']['measurement_id'] );
        $this->assertSame( 'primary-secret', $this->requests[0]['query']['api_secret'] );
        $this->assertSame( '1111111111', $this->requests[0]['body']['events'][0]['params']['session_id'] );

        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertCount( 1, $queue );
        $this->assertSame( array( self::EXTRA_A ), $queue[0]['_destinations'] );

        $this->requests = array();
        $ga4->flush_queue();
        $this->assertCount( 1, $this->requests );
        $this->assertSame( self::EXTRA_A, $this->requests[0]['query']['measurement_id'] );
        $this->assertSame( 'secret-a', $this->requests[0]['query']['api_secret'] );
        $this->assertStringNotContainsString( 'primary-secret', $this->requests[0]['url'] );
        $this->assertSame( '2222222222', $this->requests[0]['body']['events'][0]['params']['session_id'] );
        $this->assertCount( 0, $this->requests_for( self::EXTRA_B ) );
        $this->assertEmpty( get_transient( 'trackwp_ga4_queue' ) );
    }

    public function test_no_extra_ids_keeps_queue_empty_and_single_request() {
        $this->save( $this->base_input( array() ) );
        update_option( 'trackwp_advanced', array() );
        ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertCount( 1, $this->requests );
        $this->assertFalse( get_transient( 'trackwp_ga4_queue' ) );
    }

    // 9. Retry only for the failed destination.
    public function test_retry_requeues_only_failed_destination() {
        $this->configured( false, true );
        $ga4 = new TrackWP_GA4();
        $ga4->send_event( self::event() );
        $this->codes = array( self::PRIMARY => 500, self::EXTRA_A => 204 );
        $ga4->flush_queue();

        $this->assertCount( 1, $this->requests_for( self::EXTRA_A ) );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertCount( 1, $queue );
        $this->assertSame( array( self::PRIMARY ), $queue[0]['_destinations'] );
    }

    // 10. Batching: one entry carrying both destinations.
    public function test_batching_queues_once_with_all_destinations() {
        $this->configured( false, true );
        $result = ( new TrackWP_GA4() )->send_event( self::event() );
        $this->assertSame( 'queued', $result['status'] );
        $this->assertCount( 0, $this->requests );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertCount( 1, $queue );
        $this->assertSame( array( self::PRIMARY, self::EXTRA_A ), $queue[0]['_destinations'] );
    }

    private static function capi_log_path() {
        return trailingslashit( WP_CONTENT_DIR ) . 'trackwp/capi-errors.log';
    }

    private static function capi_log() {
        $f = self::capi_log_path();
        return file_exists( $f ) ? (string) file_get_contents( $f ) : '';
    }

    private function enable_debug_log( $batching = false ) {
        $adv = array( 'capi_debug_logging_enabled' => 1 );
        if ( $batching ) {
            $adv['batching_enabled'] = 1;
        }
        update_option( 'trackwp_advanced', $adv );
        if ( file_exists( self::capi_log_path() ) ) {
            unlink( self::capi_log_path() );
        }
    }

    // Fix 1 MINOR 2: an entry for an ID that is neither the current primary
    // nor a configured extra (primary changed after queueing) is dropped
    // with a log line that does not call it "extra".
    public function test_queue_entry_for_unconfigured_destination_is_dropped_with_clear_log() {
        $this->configured( false, true );
        $this->enable_debug_log( true );
        ( new TrackWP_GA4() )->send_event( self::event() );
        $this->assertSame( array( self::PRIMARY, self::EXTRA_A ), get_transient( 'trackwp_ga4_queue' )[0]['_destinations'] );

        // Primary changed to the former extra A's neighbour; A removed.
        $this->save( array(
            'ga4_enabled'        => 1,
            'ga4_measurement_id' => 'G-NEWPRIM1',
            'ga4_api_secret'     => 'new-primary-secret',
            'ga4_extra_ids'      => array(),
        ) );
        ( new TrackWP_GA4() )->flush_queue();

        $this->assertCount( 0, $this->requests, 'Nothing is sent to IDs that are no longer configured' );
        $log = self::capi_log();
        $this->assertStringContainsString( 'mp_skipped ' . self::PRIMARY . ': destination no longer configured', $log );
        $this->assertStringContainsString( 'mp_skipped ' . self::EXTRA_A . ': destination no longer configured', $log );
        $this->assertStringNotContainsString( 'mp_skipped extra', $log );
        $this->assertEmpty( get_transient( 'trackwp_ga4_queue' ), 'Dropped, not re-queued' );
    }

    // Fix 1 MINOR 2: the extra ID's secret is cleared between queue and flush.
    public function test_extra_secret_cleared_between_queue_and_flush_sends_nothing_to_it() {
        $this->configured( false );
        $this->enable_debug_log();
        ( new TrackWP_GA4() )->send_event( self::event(), 4.0 );
        $this->assertCount( 1, $this->requests );
        $this->assertSame( array( self::EXTRA_A ), get_transient( 'trackwp_ga4_queue' )[0]['_destinations'] );

        $input = $this->base_input( array(
            array( 'id' => self::EXTRA_A, 'api_secret' => '••••••••' ),
            array( 'id' => self::EXTRA_B, 'api_secret' => '' ),
        ) );
        $input['ga4_extra_secret_0_clear'] = '1';
        $saved = $this->save( $input );
        $this->assertSame( '', $saved['ga4_extra_ids'][0]['api_secret'] );

        $this->requests = array();
        ( new TrackWP_GA4() )->flush_queue();
        $this->assertCount( 0, $this->requests, 'No request to A without its own secret, primary secret never used' );
        $this->assertStringContainsString( 'mp_skipped extra ' . self::EXTRA_A . ': no api_secret', self::capi_log() );
        $this->assertEmpty( get_transient( 'trackwp_ga4_queue' ) );
    }

    // Fix 1 MINOR 3: a primary that render_gtag_head() would not configure
    // (lowercase) activates no extra IDs anywhere.
    public function test_lowercase_primary_activates_no_extra_ids() {
        $p = array(
            'ga4_enabled'        => true,
            'ga4_gtag_enabled'   => true,
            'ga4_measurement_id' => strtolower( self::PRIMARY ),
            'ga4_extra_ids'      => array( array( 'id' => self::EXTRA_A, 'api_secret' => TrackWP_Hash::encode( 'secret-a' ) ) ),
        );
        update_option( 'trackwp_platforms', $p );
        update_option( 'trackwp_advanced', array() );

        $this->assertSame( array(), TrackWP_GA4::extra_destinations( $p ) );
        $this->assertSame( array(), TrackWP_GA4::allowed_measurement_ids( $p ) );
        $cfg = TrackWP::frontend_config();
        $this->assertSame( array(), $cfg['ga4ExtraIds'] );

        ob_start();
        TrackWP::instance()->render_gtag_head();
        $head = (string) ob_get_clean();
        $this->assertStringNotContainsString( strtolower( self::PRIMARY ), $head );
        $this->assertStringNotContainsString( self::EXTRA_A, $head );
    }

    public function test_legacy_queue_entry_without_destinations_goes_to_primary_only() {
        $this->configured( true );
        $ga4 = new TrackWP_GA4();
        $ga4->queue_event( self::event() );
        $queue = get_transient( 'trackwp_ga4_queue' );
        unset( $queue[0]['_destinations'] );
        set_transient( 'trackwp_ga4_queue', $queue, HOUR_IN_SECONDS );
        $ga4->flush_queue();
        $this->assertCount( 1, $this->requests );
        $this->assertSame( self::PRIMARY, $this->requests[0]['query']['measurement_id'] );
    }
}
