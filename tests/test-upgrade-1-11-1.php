<?php
/**
 * 1.11.1 migration block in TrackWP::maybe_upgrade() (W7): KC1 option keys,
 * the D1 one-time Meta-takeover notice, and KC15's versioned upgrade notice
 * replacing the old fixed-version flag.
 *
 * @group upgrade
 */
class TrackWP_Upgrade_1_11_1_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option(TrackWP::OPTION_UPGRADE_NOTICE);
        delete_option(TrackWP::OPTION_META_TAKEOVER_NOTICE);
        delete_option('trackwp_upgrade_notice_1_10_1');
        wp_clear_scheduled_hook(TrackWP::CRON_PRUNE_CONSENT_LOG);
    }

    /**
     * A 1.11.0 site: has the 1.11.0 keys, not yet the 1.11.1 ones.
     */
    private function seed_1_11_0(array $platform_overrides = array(), array $woo_overrides = array()) {
        update_option('trackwp_platforms', $platform_overrides + array(
            'meta_enabled'              => true,
            'meta_pixel_client_enabled' => true,
            'meta_pixel_id'             => '123456789012345',
            'meta_pixel_with_gtm'       => false,
        ));
        update_option('trackwp_woocommerce', $woo_overrides + array(
            'enabled' => true,
        ));
        update_option('trackwp_consent', array('consent_version' => 2));
        update_option('trackwp_advanced', array());
        update_option('trackwp_version', '1.11.0');
        update_option('trackwp_upgrade_notice_1_10_1', 1, false);
    }

    public function test_backfills_kc1_keys_without_touching_existing_values() {
        $this->seed_1_11_0();

        TrackWP::instance()->maybe_upgrade();

        $this->assertSame(TRACKWP_VERSION, get_option('trackwp_version'));

        $platforms = get_option('trackwp_platforms');
        $this->assertArrayHasKey('fb4woo_tracking_off', $platforms);
        $this->assertFalse($platforms['fb4woo_tracking_off']);
        $this->assertArrayHasKey('gtm_datalayer_events', $platforms);
        $this->assertSame('off', $platforms['gtm_datalayer_events']);
        $this->assertArrayHasKey('ga4_source', $platforms);
        $this->assertSame('gtm', $platforms['ga4_source']);
        // Untouched pre-existing values.
        $this->assertSame('123456789012345', $platforms['meta_pixel_id']);

        $woo = get_option('trackwp_woocommerce');
        // D13: remove_from_cart is cut from 1.11.1.
        foreach ( array('event_view_item_list', 'event_view_cart') as $key ) {
            $this->assertArrayHasKey($key, $woo, $key);
            $this->assertFalse($woo[ $key ], $key);
        }
        $this->assertArrayNotHasKey('event_remove_from_cart', $woo);
        $this->assertTrue($woo['enabled'], 'Existing Woo settings must be kept.');
    }

    public function test_existing_kc1_values_are_kept() {
        $this->seed_1_11_0(array(
            'fb4woo_tracking_off'  => true,
            'gtm_datalayer_events' => 'on',
            'ga4_source'           => 'split',
        ));
        update_option('trackwp_woocommerce', array(
            'enabled'                 => true,
            'event_view_item_list'    => true,
            'event_view_cart'         => true,
        ));

        TrackWP::instance()->maybe_upgrade();

        $platforms = get_option('trackwp_platforms');
        $this->assertTrue($platforms['fb4woo_tracking_off']);
        $this->assertSame('on', $platforms['gtm_datalayer_events']);
        $this->assertSame('split', $platforms['ga4_source']);

        $woo = get_option('trackwp_woocommerce');
        $this->assertTrue($woo['event_view_item_list']);
        $this->assertTrue($woo['event_view_cart']);
    }

    public function test_kc15_notice_replaces_old_fixed_version_flag() {
        $this->seed_1_11_0();
        $this->assertNotEmpty(get_option('trackwp_upgrade_notice_1_10_1'), 'Precondition: old flag present.');

        TrackWP::instance()->maybe_upgrade();

        $this->assertFalse(get_option('trackwp_upgrade_notice_1_10_1'), 'Old fixed-version flag must be gone.');
        $this->assertSame('1.11.1', get_option(TrackWP::OPTION_UPGRADE_NOTICE));
    }

    public function test_fresh_install_gets_no_notices() {
        $this->seed_1_11_0();
        update_option('trackwp_version', '1.0.0'); // What activate() stores.
        delete_option('trackwp_upgrade_notice_1_10_1');

        TrackWP::instance()->maybe_upgrade();

        $this->assertFalse(get_option(TrackWP::OPTION_UPGRADE_NOTICE));
        $this->assertFalse(get_option(TrackWP::OPTION_META_TAKEOVER_NOTICE));
        $this->assertSame(TRACKWP_VERSION, get_option('trackwp_version'));
    }

    /**
     * D1: a site whose options already satisfied the pre-1.11.1 rule (has a
     * valid token) does not get the one-time Meta-takeover notice, because
     * delivery does not flip — it was already on.
     */
    public function test_no_meta_takeover_notice_when_already_delivering_with_token() {
        $this->seed_1_11_0(array(
            'meta_pixel_with_gtm' => true,
            'meta_access_token'   => TrackWP_Hash::encode('EAAtest-valid-token'),
        ), array('event_view_item_list' => false));

        TrackWP::instance()->maybe_upgrade();

        $this->assertFalse(get_option(TrackWP::OPTION_META_TAKEOVER_NOTICE));
    }

    /**
     * D1: a site with everything except a token now starts delivering
     * (pixel_only). The one-time notice must appear, using the real
     * producer TrackWP_Meta_Takeover::status() to confirm the flip.
     */
    public function test_meta_takeover_notice_when_delivery_flips_on_without_token() {
        $this->seed_1_11_0(array(
            'meta_pixel_with_gtm' => true,
            // No meta_access_token: pre-1.11.1 this meant no delivery.
        ));

        TrackWP::instance()->maybe_upgrade();

        $this->assertNotEmpty(get_option(TrackWP::OPTION_META_TAKEOVER_NOTICE));
        $this->assertTrue(TrackWP_Meta_Takeover::status()['delivering'], 'Real producer confirms the flip actually happened.');
    }

    /**
     * A site that was missing an unrelated condition (client pixel off)
     * stays non-delivering after the upgrade: no notice.
     */
    public function test_no_meta_takeover_notice_when_still_not_delivering() {
        $this->seed_1_11_0(array(
            'meta_pixel_with_gtm'       => true,
            'meta_pixel_client_enabled' => false,
        ));

        TrackWP::instance()->maybe_upgrade();

        $this->assertFalse(get_option(TrackWP::OPTION_META_TAKEOVER_NOTICE));
        $this->assertFalse(TrackWP_Meta_Takeover::status()['delivering']);
    }

    public function test_second_run_is_a_no_op() {
        $this->seed_1_11_0(array('meta_pixel_with_gtm' => true));
        TrackWP::instance()->maybe_upgrade();
        $platforms_after_first = get_option('trackwp_platforms');
        $notice_after_first    = get_option(TrackWP::OPTION_META_TAKEOVER_NOTICE);

        // Simulate the notice having been dismissed, then run again — a
        // no-op upgrade must not resurrect it.
        delete_option(TrackWP::OPTION_META_TAKEOVER_NOTICE);
        TrackWP::instance()->maybe_upgrade();

        $this->assertSame($platforms_after_first, get_option('trackwp_platforms'));
        $this->assertFalse(get_option(TrackWP::OPTION_META_TAKEOVER_NOTICE), 'Dismissed notice must not reappear on a no-op run.');
        $this->assertNotEmpty($notice_after_first, 'Precondition: the first run did set it.');
    }
}
