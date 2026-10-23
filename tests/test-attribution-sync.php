<?php
/**
 * Attribution meta vs. WooCommerce HPOS data sync.
 *
 * With woocommerce_custom_orders_table_data_sync_enabled = 'yes' an order is
 * stored twice: in wp_wc_orders_meta (HPOS) and in wp_postmeta (posts). The
 * authoritative store depends on the service:
 *   - phpunit               TRACKWP_TEST_HPOS=1  HPOS authoritative
 *   - phpunit-legacy-orders TRACKWP_TEST_HPOS=0  posts authoritative
 *
 * privacy_eraser() and prune_attribution() must remove _trackwp_attribution
 * from BOTH tables. These tests read both tables directly with $wpdb.
 *
 * @group orders
 */
class TrackWP_Attribution_Sync_Test extends WP_UnitTestCase {

	const SYNC_OPTION = 'woocommerce_custom_orders_table_data_sync_enabled';

	public function set_up() {
		parent::set_up();
		if ( getenv( 'TRACKWP_TEST_HPOS' ) === false ) {
			$this->markTestSkipped( 'TRACKWP_TEST_HPOS is not set; run via the phpunit services.' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed.' );
		}
		update_option( self::SYNC_OPTION, 'yes' );

		$this->assertSame(
			$this->hpos_expected(),
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'Authoritative order store matches TRACKWP_TEST_HPOS.'
		);
		if ( ! $this->table_exists( $this->hpos_meta_table() ) && method_exists( 'WC_Install', 'create_tables' ) ) {
			WC_Install::create_tables();
		}
		$this->assertTrue( $this->table_exists( $this->hpos_meta_table() ), 'wp_wc_orders_meta exists.' );
	}

	public function tear_down() {
		delete_option( self::SYNC_OPTION );
		// The HPOS table is created inside the test; do not let the cached
		// "found" outlive it and break later test classes.
		TrackWP_WooCommerce::reset_hpos_table_cache();
		unset( $_COOKIE['trackwp_consent'], $_COOKIE['_ga'], $_COOKIE['_fbp'] );
		parent::tear_down();
	}

	private function hpos_expected() {
		return getenv( 'TRACKWP_TEST_HPOS' ) !== '0';
	}

	private function mode() {
		return $this->hpos_expected() ? 'HPOS authoritative' : 'posts authoritative';
	}

	private function hpos_meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_orders_meta';
	}

	private function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/** Order with attribution written by the real producer (save_attribution). */
	private function make_order( $email ) {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->save();

		$cfg                        = get_option( 'trackwp_consent', array() );
		$_COOKIE['trackwp_consent'] = wp_json_encode( array(
			'v'          => isset( $cfg['consent_version'] ) ? (int) $cfg['consent_version'] : 1,
			'ts'         => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'id'         => wp_generate_uuid4(),
			'necessary'  => true,
			'statistics' => true,
			'marketing'  => true,
		) );
		$_COOKIE['_ga']             = 'GA1.1.111.222';
		$_COOKIE['_fbp']            = 'fb.1.1700000000000.123';
		( new TrackWP_WooCommerce( false ) )->save_attribution( $order->get_id() );
		$this->assertNotEmpty(
			wc_get_order( $order->get_id() )->get_meta( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION ),
			'Producer wrote attribution.'
		);
		return $order->get_id();
	}

	private function set_age_days( $order_id, $days ) {
		$order = wc_get_order( $order_id );
		$order->set_date_created( time() - $days * DAY_IN_SECONDS );
		$order->save();
	}

	/** Row counts for the attribution meta key, read directly from both tables. */
	private function copies( $order_id ) {
		global $wpdb;
		$key  = TrackWP_WooCommerce::ORDER_META_ATTRIBUTION;
		$hpos = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
			$order_id,
			$key
		) );
		$post = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$order_id,
			$key
		) );
		return array( 'wc_orders_meta' => $hpos, 'postmeta' => $post );
	}

	private function assert_both_copies( $order_id ) {
		$c = $this->copies( $order_id );
		$this->assertSame( 1, $c['wc_orders_meta'], 'Precondition (' . $this->mode() . '): sync copies attribution to wp_wc_orders_meta.' );
		$this->assertSame( 1, $c['postmeta'], 'Precondition (' . $this->mode() . '): sync copies attribution to wp_postmeta.' );
	}

	private function assert_no_copies( $order_id, $method ) {
		$c = $this->copies( $order_id );
		$this->assertSame( 0, $c['wc_orders_meta'], $method . ' (' . $this->mode() . '): wp_wc_orders_meta still has _trackwp_attribution for order ' . $order_id . '.' );
		$this->assertSame( 0, $c['postmeta'], $method . ' (' . $this->mode() . '): wp_postmeta still has _trackwp_attribution for order ' . $order_id . '.' );
	}

	/** @group orders */
	public function test_privacy_eraser_removes_attribution_from_both_order_stores() {
		$order = $this->make_order( 'sync-erase@example.org' );
		$this->assert_both_copies( $order );

		$res = TrackWP_WooCommerce::privacy_eraser( 'sync-erase@example.org' );
		$this->assertTrue( $res['items_removed'] );
		$this->assert_no_copies( $order, 'privacy_eraser' );
	}

	/** @group orders */
	public function test_prune_removes_attribution_from_both_order_stores_after_90_days() {
		$order = $this->make_order( 'sync-prune@example.org' );
		$this->set_age_days( $order, 91 );
		$this->assert_both_copies( $order );

		$this->assertGreaterThanOrEqual( 1, TrackWP_WooCommerce::prune_attribution() );
		$this->assert_no_copies( $order, 'prune_attribution' );
	}

	/** @group orders */
	public function test_privacy_eraser_removes_stale_copy_when_sync_is_off() {
		$order = $this->make_order( 'sync-off-erase@example.org' );
		$this->assert_both_copies( $order );
		update_option( self::SYNC_OPTION, 'no' );

		$res = TrackWP_WooCommerce::privacy_eraser( 'sync-off-erase@example.org' );
		$this->assertTrue( $res['items_removed'] );
		$this->assert_no_copies( $order, 'privacy_eraser (sync off)' );
	}

	/** @group orders */
	public function test_prune_removes_stale_copy_when_sync_is_off() {
		$order = $this->make_order( 'sync-off-prune@example.org' );
		$this->set_age_days( $order, 91 );
		$this->assert_both_copies( $order );
		update_option( self::SYNC_OPTION, 'no' );

		$this->assertGreaterThanOrEqual( 1, TrackWP_WooCommerce::prune_attribution() );
		$this->assert_no_copies( $order, 'prune_attribution (sync off)' );
	}

	/** @group orders */
	public function test_quiet_delete_leaves_other_plugins_return_false_filter() {
		$filter = 'woocommerce_orders_table_datastore_should_save_after_meta_change';
		$order  = $this->make_order( 'sync-filter@example.org' );
		update_option( self::SYNC_OPTION, 'no' );
		add_filter( $filter, '__return_false', PHP_INT_MAX );

		TrackWP_WooCommerce::privacy_eraser( 'sync-filter@example.org' );
		$still = has_filter( $filter, '__return_false' );
		remove_filter( $filter, '__return_false', PHP_INT_MAX );

		$this->assertSame( PHP_INT_MAX, $still, 'Foreign __return_false filter survives the quiet delete.' );
		$this->assertSame( 0, $this->copies( $order )['wc_orders_meta'] + $this->copies( $order )['postmeta'] );
	}

	/** @group orders */
	public function test_prune_keeps_both_copies_of_orders_younger_than_90_days() {
		$order = $this->make_order( 'sync-recent@example.org' );
		$this->set_age_days( $order, 89 );
		$this->assert_both_copies( $order );

		TrackWP_WooCommerce::prune_attribution();
		$c = $this->copies( $order );
		$this->assertSame( 1, $c['wc_orders_meta'], 'Recent order keeps its wp_wc_orders_meta copy.' );
		$this->assertSame( 1, $c['postmeta'], 'Recent order keeps its wp_postmeta copy.' );
	}
}
