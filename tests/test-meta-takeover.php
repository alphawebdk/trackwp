<?php
/**
 * Meta takeover M1/M2 (PLAN-1.11.0-v2 KB15, §3.7, §5 test-meta-takeover,
 * §11 S17 and S20), extended for PLAN-1.11.1-v2 KC6/§9 TR6/TR8 (D1: takeover
 * without a token, dedup_server_only, fb4woo_tracking_off, status() shape).
 *
 * fb4woo tracker stub: the Docker phpunit service mounts only the plugin
 * repo, so Meta for WooCommerce itself is not available. The class
 * Test_TrackWP_FB4Woo_Tracker_Stub below copies VERBATIM the constructor
 * and is_pixel_enabled() of WC_Facebookcommerce_EventsTracker from
 * facebook-for-woocommerce 3.7.7 (facebook-commerce-events-tracker.php
 * lines 103-117 and 239-251, md5 ec790091434cc905e26fdd021c36d9c3; the
 * same code is in 3.7.6). Only param_builder_server_setup() and
 * add_hooks() are replaced with recorders, and the two collaborators the
 * constructor instantiates on the "enabled" path are empty stubs.
 * When TRACKWP_FB4WOO_TRACKER points at the real file, a test checks that
 * the copied constructor still matches the source.
 */

use WooCommerce\Facebook\Integrations\CostOfGoods\CostOfGoods;

if ( ! class_exists( 'WC_Facebookcommerce_Pixel', false ) ) {
    // phpcs:ignore Generic.Files.OneObjectStructurePerFile
    class WC_Facebookcommerce_Pixel {
        public function __construct( $user_info ) {}
        public static function init_external_js_hooks() {}
    }
}
if ( ! class_exists( CostOfGoods::class, false ) ) {
    eval( 'namespace WooCommerce\Facebook\Integrations\CostOfGoods; class CostOfGoods {}' );
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile
class Test_TrackWP_FB4Woo_Tracker_Stub {

    private $pixel;
    private $aam_settings;
    private $tracked_events;
    private $cogs_provider;
    private $is_pixel_enabled;

    public $calls = array();

    // --- verbatim from facebook-commerce-events-tracker.php:103-117 ---
		public function __construct( $user_info, $aam_settings ) {

			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			$this->pixel          = new \WC_Facebookcommerce_Pixel( $user_info );
			$this->aam_settings   = $aam_settings;
			$this->tracked_events = array();

			// Initialize external JS hooks early so script is enqueued before wp_enqueue_scripts fires.
			\WC_Facebookcommerce_Pixel::init_external_js_hooks();

			$this->param_builder_server_setup();
			$this->add_hooks();
			$this->cogs_provider = new CostOfGoods();
		}
    // --- end verbatim ---

    // --- verbatim from facebook-commerce-events-tracker.php:239-251 ---
		private function is_pixel_enabled() {

			if ( null === $this->is_pixel_enabled ) {

				/**
				 * Filters whether the Pixel should be enabled.
				 *
				 * @param bool $enabled default true
				 */
				$this->is_pixel_enabled = (bool) apply_filters( 'facebook_for_woocommerce_integration_pixel_enabled', true );
			}

			return $this->is_pixel_enabled;
		}
    // --- end verbatim ---

    public function param_builder_server_setup() {
        $this->calls[] = 'param_builder_server_setup';
    }

    private function add_hooks() {
        $this->calls[] = 'add_hooks';
    }
}

// Test seams for the WooCommerce-active check (WooCommerce cannot be
// unloaded inside a running test process).
// phpcs:ignore Generic.Files.OneObjectStructurePerFile
class Test_TrackWP_Meta_Takeover_No_Woo extends TrackWP_Meta_Takeover {
    protected static function woocommerce_active() {
        return false;
    }
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile
class Test_TrackWP_Meta_Takeover_With_Woo extends TrackWP_Meta_Takeover {
    protected static function woocommerce_active() {
        return true;
    }
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile
class Test_TrackWP_Meta_Takeover extends WP_UnitTestCase {

    const FILTER = 'facebook_for_woocommerce_integration_pixel_enabled';

    private $responses = array();

    public function set_up() {
        parent::set_up();
        update_option( 'trackwp_platforms', self::full_config() );
        update_option( 'trackwp_woocommerce', array( 'enabled' => true ) );
        delete_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );
        $this->responses = array();
        add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
        delete_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );
        delete_option( 'wc_facebook_pixel_id' );
        delete_option( 'wc_facebook_access_token' );
        parent::tear_down();
    }

    public function intercept( $pre, $args, $url ) {
        return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
    }

    private static function response( $code, $body ) {
        return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
    }

    private static function full_config( $overrides = array() ) {
        return array_merge( array(
            'meta_enabled'              => 1,
            'meta_pixel_id'             => '1234567890',
            'meta_pixel_client_enabled' => true,
            'meta_pixel_with_gtm'       => true,
            // Producer: the same encoder the settings sanitizer uses for the token.
            'meta_access_token'         => TrackWP_Hash::encode( 'EAAB-token' ),
        ), $overrides );
    }

    private static function send() {
        $meta = new TrackWP_Meta();
        return $meta->send_event( array(
            'event'      => 'form_submit',
            'event_id'   => 'evt_' . str_repeat( 'c', 32 ),
            'page_url'   => 'https://example.com/kontakt',
            'user_agent' => 'Mozilla/5.0 Test',
            'consent'    => array( 'analytics' => false, 'marketing' => true, 'v' => 1 ),
        ) );
    }

    private static function graph_error( $code, $type = 'OAuthException' ) {
        return wp_json_encode( array( 'error' => array(
            'message'    => 'Error validating access token: Session has expired.',
            'type'       => $type,
            'code'       => $code,
            'fbtrace_id' => 'AbC123',
        ) ) );
    }

    // --- KC6 conditions (D1: token is no longer one of them) ---------------

    public function test_filter_false_when_all_conditions_hold() {
        $this->assertTrue( TrackWP_Meta_Takeover::can_deliver() );
        $this->assertFalse( TrackWP_Meta_Takeover::filter_pixel_enabled( true ) );
        $this->assertFalse( apply_filters( self::FILTER, true ) );
    }

    public function provide_one_condition_missing() {
        return array(
            'M1 off'              => array( array( 'meta_pixel_with_gtm' => false ) ),
            'meta disabled'       => array( array( 'meta_enabled' => 0 ) ),
            'pixel id empty'      => array( array( 'meta_pixel_id' => '' ) ),
            'pixel id too short'  => array( array( 'meta_pixel_id' => '1234' ) ),
            'pixel id not digits' => array( array( 'meta_pixel_id' => '12345abc90' ) ),
            'client pixel off'    => array( array( 'meta_pixel_client_enabled' => false ) ),
        );
    }

    /**
     * @dataProvider provide_one_condition_missing
     */
    public function test_filter_passes_upstream_when_one_condition_missing( $override ) {
        update_option( 'trackwp_platforms', self::full_config( $override ) );
        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver() );
        $this->assertTrue( TrackWP_Meta_Takeover::filter_pixel_enabled( true ) );
        $this->assertTrue( apply_filters( self::FILTER, true ) );
        $this->assertFalse( Test_TrackWP_Meta_Takeover_No_Woo::can_deliver(), 'missing without WooCommerce too' );
    }

    // --- Review M2: woo_events is a condition when WooCommerce is active ----

    public function test_woo_events_off_blocks_takeover_when_woocommerce_active() {
        update_option( 'trackwp_woocommerce', array( 'enabled' => false ) );
        $this->assertFalse( Test_TrackWP_Meta_Takeover_With_Woo::can_deliver() );
        $this->assertTrue( Test_TrackWP_Meta_Takeover_With_Woo::filter_pixel_enabled( true ), 'fb4woo keeps tracking' );
        $this->assertSame( array( 'woo_events' ), Test_TrackWP_Meta_Takeover_With_Woo::status()['missing'] );
    }

    public function test_woo_events_on_allows_takeover_when_woocommerce_active() {
        $this->assertTrue( Test_TrackWP_Meta_Takeover_With_Woo::can_deliver() );
        $this->assertFalse( Test_TrackWP_Meta_Takeover_With_Woo::filter_pixel_enabled( true ) );
    }

    public function test_woo_events_not_required_without_woocommerce() {
        update_option( 'trackwp_woocommerce', array( 'enabled' => false ) );
        $this->assertTrue( Test_TrackWP_Meta_Takeover_No_Woo::can_deliver() );
        $this->assertFalse( Test_TrackWP_Meta_Takeover_No_Woo::filter_pixel_enabled( true ) );
        $this->assertSame( array(), Test_TrackWP_Meta_Takeover_No_Woo::status()['missing'] );
    }

    public function test_real_woocommerce_check_matches_plugin_integration() {
        // Unseamed class: uses the same check as TrackWP_WooCommerce.
        update_option( 'trackwp_woocommerce', array( 'enabled' => false ) );
        $woo = new TrackWP_WooCommerce( false );
        $this->assertSame( ! $woo->is_available(), TrackWP_Meta_Takeover::can_deliver() );
        $this->assertSame( $woo->is_available(), in_array( 'woo_events', TrackWP_Meta_Takeover::status()['missing'], true ) );
    }

    public function test_can_deliver_and_missing_always_agree() {
        $values = array(
            'meta_pixel_with_gtm'       => array( true, false ),
            'meta_enabled'              => array( 1, 0 ),
            'meta_pixel_id'             => array( '1234567890', 'x1' ),
            'meta_pixel_client_enabled' => array( true, false ),
            'meta_access_token'         => array( TrackWP_Hash::encode( 'EAAB-token' ), '' ),
        );
        $keys  = array_keys( $values );
        $count = 0;
        for ( $mask = 0; $mask < 64; $mask++ ) {
            $config = array();
            foreach ( $keys as $i => $key ) {
                $config[ $key ] = $values[ $key ][ ( $mask >> $i ) & 1 ];
            }
            update_option( 'trackwp_platforms', $config );
            update_option( 'trackwp_woocommerce', array( 'enabled' => (bool) ( ( $mask >> 5 ) & 1 ) ) );
            foreach ( array( 'Test_TrackWP_Meta_Takeover_With_Woo', 'Test_TrackWP_Meta_Takeover_No_Woo', 'TrackWP_Meta_Takeover' ) as $class ) {
                $status = $class::status();
                $this->assertSame( $status['missing'] === array(), $class::can_deliver(), "$class mask $mask" );
                $this->assertSame( $status['delivering'], $class::can_deliver(), "$class mask $mask" );
                $count++;
            }
        }
        $this->assertSame( 192, $count );
    }

    /**
     * D1/KC6: with client_and_server (the default), a missing token no
     * longer blocks the takeover (mask bit 4). Only with dedup_mode ===
     * server_only does the same missing token add 'dedup_server_only' and
     * block can_deliver().
     */
    public function test_dedup_mode_governs_whether_missing_token_blocks_delivery() {
        foreach ( array( 'client_and_server', 'client_only', 'server_only' ) as $mode ) {
            update_option( 'trackwp_advanced', array( 'dedup_mode' => $mode ) );
            update_option( 'trackwp_platforms', self::full_config( array( 'meta_access_token' => '' ) ) );
            $status = TrackWP_Meta_Takeover::status();
            if ( 'server_only' === $mode ) {
                $this->assertFalse( TrackWP_Meta_Takeover::can_deliver(), $mode );
                $this->assertContains( 'dedup_server_only', $status['missing'], $mode );
            } else {
                $this->assertTrue( TrackWP_Meta_Takeover::can_deliver(), $mode );
                $this->assertNotContains( 'dedup_server_only', $status['missing'], $mode );
            }

            // A token (own or fb4woo fallback) removes the block regardless of mode.
            update_option( 'trackwp_platforms', self::full_config() );
            $this->assertTrue( TrackWP_Meta_Takeover::can_deliver(), $mode . ' with token' );
        }
        delete_option( 'trackwp_advanced' );
    }

    // --- D1: takeover with Pixel only (rewritten from S17 "no token means
    // no delivery", which D1 replaces) -----------------------------------

    public function test_d1_missing_token_still_takes_over_pixel_only() {
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_access_token' => '' ) ) );
        $this->assertTrue( TrackWP_Meta_Takeover::can_deliver() );
        $this->assertFalse( TrackWP_Meta_Takeover::filter_pixel_enabled( true ) );
        $this->assertFalse( apply_filters( self::FILTER, true ) );
        $this->assertSame( array(), TrackWP_Meta_Takeover::status()['missing'] );

        $status = TrackWP_Meta_Takeover::status();
        $this->assertSame( 'pixel_only', $status['mode'] );
        $this->assertSame( 'no_token', $status['capi'] );
        $this->assertNull( $status['capi_token_source'] );
        $this->assertSame( 'trackwp', $status['meta_delivered_by'] );
    }

    public function test_d1_only_dedup_server_only_gap_stops_pixel_only_takeover() {
        update_option( 'trackwp_advanced', array( 'dedup_mode' => 'server_only' ) );
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_access_token' => '' ) ) );
        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver() );
        $this->assertTrue( TrackWP_Meta_Takeover::filter_pixel_enabled( true ), 'fb4woo keeps tracking' );
        $this->assertSame( array( 'dedup_server_only' ), TrackWP_Meta_Takeover::status()['missing'] );
    }

    public function test_s17_upstream_false_stays_false_when_delivering() {
        $this->assertFalse( TrackWP_Meta_Takeover::filter_pixel_enabled( false ) );
    }

    // --- KC6 emergency stop: fb4woo_tracking_off ----------------------------

    public function test_forced_off_suppresses_even_when_trackwp_could_deliver() {
        update_option( 'trackwp_platforms', self::full_config( array( 'fb4woo_tracking_off' => true ) ) );
        $this->assertTrue( TrackWP_Meta_Takeover::can_deliver(), 'can_deliver() is independent of the emergency stop' );
        $this->assertFalse( TrackWP_Meta_Takeover::filter_pixel_enabled( true ) );

        $status = TrackWP_Meta_Takeover::status();
        $this->assertTrue( $status['forced_off'] );
        $this->assertTrue( $status['fb4woo_suppressed'] );
    }

    public function test_forced_off_gives_none_when_trackwp_cannot_deliver_either() {
        update_option( 'trackwp_platforms', self::full_config( array( 'fb4woo_tracking_off' => true, 'meta_enabled' => 0 ) ) );
        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver() );
        $this->assertFalse( TrackWP_Meta_Takeover::filter_pixel_enabled( true ), 'emergency stop overrides upstream too' );

        $status = TrackWP_Meta_Takeover::status();
        $this->assertTrue( $status['forced_off'] );
        $this->assertTrue( $status['fb4woo_suppressed'] );
        $this->assertSame( 'none', $status['meta_delivered_by'] );
    }

    public function test_not_forced_off_and_not_delivering_falls_back_to_fb4woo() {
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_enabled' => 0 ) ) );
        $status = TrackWP_Meta_Takeover::status();
        $this->assertFalse( $status['forced_off'] );
        $this->assertFalse( $status['fb4woo_suppressed'] );
        $this->assertSame( $status['fb4woo_active'] ? 'fb4woo' : 'none', $status['meta_delivered_by'] );
    }

    public function test_s17_rejected_token_keeps_takeover_and_shows_error() {
        $this->responses = array( self::response( 401, self::graph_error( 190 ) ) );
        $result = self::send();

        $this->assertSame( 'http_4xx', $result['reason'] );
        $status = TrackWP_Meta_Takeover::status();
        $this->assertIsArray( $status['last_error'] );
        $this->assertSame( 190, $status['last_error']['code'] );
        $this->assertSame( 401, $status['last_error']['http_code'] );
        $this->assertSame( 'OAuthException', $status['last_error']['type'] );
        $this->assertTrue( $status['delivering'] );
        $this->assertSame( 'token_error', $status['capi'] );
        $this->assertSame( 'trackwp', $status['capi_token_source'] );
        $this->assertFalse( apply_filters( self::FILTER, true ), 'no automatic fallback to fb4woo' );
    }

    // --- has_filter before plugins_loaded ----------------------------------

    public function test_filter_registered_unconditionally_by_init_hooks() {
        $callback = array( 'TrackWP_Meta_Takeover', 'filter_pixel_enabled' );
        $this->assertNotFalse( has_filter( self::FILTER, $callback ), 'registered when trackwp.php loaded' );

        // Prove the registration comes from init_hooks() itself (which runs
        // in the constructor, i.e. when trackwp.php is included, before
        // plugins_loaded) and is not gated on any setting.
        update_option( 'trackwp_platforms', array() );
        remove_filter( self::FILTER, $callback );
        $this->assertFalse( has_filter( self::FILTER, $callback ) );
        $init = new ReflectionMethod( 'TrackWP', 'init_hooks' );
        $init->setAccessible( true );
        $init->invoke( TrackWP::instance() );
        $this->assertNotFalse( has_filter( self::FILTER, $callback ) );
    }

    // --- status() -------------------------------------------------------------

    public function test_status_all_ok() {
        $status = TrackWP_Meta_Takeover::status();
        $this->assertSame(
            array( 'delivering', 'mode', 'capi', 'capi_token_source', 'fb4woo_active', 'forced_off', 'fb4woo_suppressed', 'meta_delivered_by', 'missing', 'last_error' ),
            array_keys( $status )
        );
        $this->assertTrue( $status['delivering'] );
        $this->assertSame( array(), $status['missing'] );
        $this->assertNull( $status['last_error'] );
        $this->assertSame( 'pixel_capi', $status['mode'] );
        $this->assertSame( 'active', $status['capi'] );
        $this->assertSame( 'trackwp', $status['capi_token_source'] );
        $this->assertFalse( $status['forced_off'] );
        $this->assertTrue( $status['fb4woo_suppressed'] );
        $this->assertSame( 'trackwp', $status['meta_delivered_by'] );
        $this->assertSame( class_exists( 'WC_Facebook_Loader', false ) || function_exists( 'facebook_for_woocommerce' ), $status['fb4woo_active'] );
    }

    public function test_status_missing_lists_every_gap() {
        update_option( 'trackwp_platforms', array( 'meta_pixel_id' => 'abc' ) );
        update_option( 'trackwp_woocommerce', array( 'enabled' => false ) );
        $status = Test_TrackWP_Meta_Takeover_With_Woo::status();
        $this->assertSame( array( 'm1', 'meta_enabled', 'pixel_id', 'client_pixel', 'woo_events' ), $status['missing'] );
        $this->assertFalse( $status['delivering'] );
        $this->assertSame( 'no_token', $status['capi'] );
        $this->assertNull( $status['capi_token_source'] );
    }

    // --- Token error registration ------------------------------------------

    public function provide_token_errors() {
        return array(
            '190 on 400' => array( 400, 190 ),
            '190 on 401' => array( 401, 190 ),
            '10 on 403'  => array( 403, 10 ),
            '200 on 403' => array( 403, 200 ),
            '299 on 400' => array( 400, 299 ),
        );
    }

    /**
     * @dataProvider provide_token_errors
     */
    public function test_token_error_is_recorded( $http, $code ) {
        $this->responses = array( self::response( $http, self::graph_error( $code, 'GraphMethodException' ) ) );
        self::send();
        $error = get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );
        $this->assertIsArray( $error );
        $this->assertSame( $code, $error['code'] );
        $this->assertFalse( apply_filters( self::FILTER, true ) );
    }

    public function provide_non_token_errors() {
        return array(
            'code 100 on 400'  => array( 400, self::graph_error( 100 ) ),
            'code 300 on 403'  => array( 403, self::graph_error( 300 ) ),
            '190 on 500'       => array( 500, self::graph_error( 190 ) ),
            '190 on 429'       => array( 429, self::graph_error( 190 ) ),
            'no graph body'    => array( 401, 'Unauthorized' ),
        );
    }

    /**
     * @dataProvider provide_non_token_errors
     */
    public function test_other_errors_are_not_recorded( $http, $body ) {
        $this->responses = array( self::response( $http, $body ) );
        self::send();
        $this->assertFalse( get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION ) );
    }

    public function test_token_error_option_is_not_autoloaded() {
        global $wpdb;
        $this->responses = array( self::response( 401, self::graph_error( 190 ) ) );
        self::send();
        $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", TrackWP_Meta_Takeover::LAST_ERROR_OPTION ) );
        $this->assertContains( $autoload, array( 'no', 'off' ) );
    }

    public function test_token_error_written_at_most_once_per_hour() {
        $this->responses = array( self::response( 401, self::graph_error( 190 ) ) );
        self::send();
        $first = get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );

        $this->responses = array( self::response( 403, self::graph_error( 200 ) ) );
        self::send();
        $this->assertSame( $first, get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION ), 'throttled within the hour' );

        $aged         = $first;
        $aged['time'] = time() - HOUR_IN_SECONDS - 1;
        update_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION, $aged, false );
        self::send();
        $this->assertSame( 200, get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION )['code'], 'rewritten after an hour' );
    }

    public function test_successful_send_clears_token_error() {
        $this->responses = array( self::response( 401, self::graph_error( 190 ) ) );
        self::send();
        $this->assertIsArray( get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION ) );

        $this->responses = array( self::response( 200, '{"events_received":1}' ) );
        self::send();
        $this->assertFalse( get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION ) );
    }

    // --- meta_pixel_client_enabled absent from trackwp_platforms -------------

    /**
     * A trackwp_platforms row that has never stored meta_pixel_client_enabled
     * (the key is absent, not false). Producers: the stored option and the
     * real render_meta_pixel(), can_deliver(), status() and the fb4woo filter.
     * render_meta_pixel() and the takeover read the absent key as "off".
     */
    public function test_missing_meta_pixel_client_enabled_key_means_no_pixel_and_no_takeover() {
        // Control: with the key the pixel is printed and fb4woo is switched off.
        $this->assertNotSame( '', self::render_pixel(), 'control: key present prints the pixel' );
        $this->assertFalse( TrackWP_Meta_Takeover::filter_pixel_enabled( true ) );

        $config = self::full_config();
        unset( $config['meta_pixel_client_enabled'] );
        update_option( 'trackwp_platforms', $config );

        $this->assertSame( '', self::render_pixel(), 'absent key: no TrackWP pixel' );
        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver() );
        $this->assertTrue( TrackWP_Meta_Takeover::filter_pixel_enabled( true ), 'fb4woo keeps its own pixel' );
        $this->assertContains( 'client_pixel', TrackWP_Meta_Takeover::status()['missing'] );
    }

    private static function render_pixel() {
        ob_start();
        TrackWP::instance()->render_meta_pixel();
        return (string) ob_get_clean();
    }

    // --- TR6: fb4woo token fallback also counts as "a token" here ----------
    // (the accessor itself, TrackWP_Meta::access_token(), is unit-tested in
    // test-meta.php; here only its effect on can_deliver()/status() is
    // checked, with the real WP options as producer.)

    public function test_dedup_server_only_gap_closed_by_fb4woo_token_fallback() {
        update_option( 'trackwp_advanced', array( 'dedup_mode' => 'server_only' ) );
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_access_token' => '' ) ) );
        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver(), 'no token anywhere yet' );

        update_option( 'wc_facebook_pixel_id', '1234567890' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        $this->assertTrue( TrackWP_Meta_Takeover::can_deliver(), 'fb4woo fallback token closes the gap' );
        $status = TrackWP_Meta_Takeover::status();
        $this->assertSame( array(), $status['missing'] );
        $this->assertSame( 'fb4woo', $status['capi_token_source'] );
        $this->assertSame( 'active', $status['capi'] );

        delete_option( 'wc_facebook_pixel_id' );
        delete_option( 'wc_facebook_access_token' );
    }

    public function test_fb4woo_token_fallback_ignored_for_a_different_pixel() {
        update_option( 'trackwp_advanced', array( 'dedup_mode' => 'server_only' ) );
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_access_token' => '' ) ) );
        update_option( 'wc_facebook_pixel_id', '9999999999' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver(), 'fb4woo is connected to a different pixel' );
        $this->assertContains( 'dedup_server_only', TrackWP_Meta_Takeover::status()['missing'] );

        delete_option( 'wc_facebook_pixel_id' );
        delete_option( 'wc_facebook_access_token' );
    }

    // --- S20: fb4woo tracker constructor ------------------------------------

    public function test_s20_fb4woo_tracker_returns_before_param_builder_and_hooks() {
        $tracker = new Test_TrackWP_FB4Woo_Tracker_Stub( array(), null );
        $this->assertSame( array(), $tracker->calls, 'no param builder (no _fbp cookie) and no hooks' );
    }

    public function test_s20_fb4woo_tracker_runs_when_trackwp_cannot_deliver() {
        // D1: a missing token alone no longer blocks the takeover (Pixel
        // only, mode pixel_only). Use a real gap instead (meta disabled).
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_enabled' => 0 ) ) );
        $this->assertFalse( TrackWP_Meta_Takeover::can_deliver(), 'precondition: takeover withheld' );
        $tracker = new Test_TrackWP_FB4Woo_Tracker_Stub( array(), null );
        $this->assertSame( array( 'param_builder_server_setup', 'add_hooks' ), $tracker->calls );
    }

    public function test_s20_fb4woo_tracker_returns_when_trackwp_delivers_pixel_only() {
        // D1: no token, all other conditions met -> TrackWP still takes over
        // (Pixel only), so fb4woo's tracker must still be suppressed.
        update_option( 'trackwp_platforms', self::full_config( array( 'meta_access_token' => '' ) ) );
        $this->assertTrue( TrackWP_Meta_Takeover::can_deliver(), 'precondition: pixel-only takeover' );
        $tracker = new Test_TrackWP_FB4Woo_Tracker_Stub( array(), null );
        $this->assertSame( array(), $tracker->calls );
    }

    public function test_s20_stub_constructor_matches_fb4woo_source() {
        $path = getenv( 'TRACKWP_FB4WOO_TRACKER' );
        if ( ! $path || ! is_readable( $path ) ) {
            $this->markTestSkipped( 'Set TRACKWP_FB4WOO_TRACKER to facebook-commerce-events-tracker.php to compare the verbatim copy.' );
        }
        $pattern = '/public function __construct\( \$user_info, \$aam_settings \) \{.*?\n\t\t\}/s';
        $this->assertSame( 1, preg_match( $pattern, file_get_contents( $path ), $real ) );
        $this->assertSame( 1, preg_match( $pattern, file_get_contents( __FILE__ ), $copy ) );
        $this->assertSame( str_replace( "\r", '', $real[0] ), str_replace( "\r", '', $copy[0] ) );
    }
}
