<?php
/**
 * 1.10.1 migration block in TrackWP::maybe_upgrade() (W8).
 *
 * The starting options use the keys 1.10.0's activate() wrote
 * (trackwp.php 1.10.0, lines 165-271) with typical owner edits. The
 * material_hash baseline is compared with its real producer,
 * TrackWP_Consent_Profile::material_hash().
 *
 * @group upgrade
 */
class TrackWP_Upgrade_1_10_1_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option(TrackWP::OPTION_MATERIAL_HASH);
        delete_option(TrackWP::OPTION_UPGRADE_NOTICE);
        wp_clear_scheduled_hook(TrackWP::CRON_PRUNE_CONSENT_LOG);
    }

    private function seed_1_10_0(array $consent_overrides = array(), array $platform_overrides = array()) {
        update_option('trackwp_platforms', $platform_overrides + array(
            'meta_enabled'     => true,
            'meta_pixel_id'    => '123456789012345',
            'meta_api_version' => 'v21.0',
        ));
        update_option('trackwp_consent', $consent_overrides + array(
            'heading'                => 'Vi bruger cookies',
            'description'            => 'Vi bruger cookies til at forbedre din oplevelse og analysere trafik. Vælg dine præferencer nedenfor.',
            'accept_text'            => 'Accepter alle',
            'reject_text'            => 'Afvis alle',
            'show_reject_button'     => false,
            'cookie_lifetime_months' => 36,
            'consent_version'        => 4,
        ));
        update_option('trackwp_advanced', array(
            'cookie_lifetime_months' => 60,
            'debug_console'          => false,
        ));
        update_option('trackwp_woocommerce', array(
            'enabled'       => true,
            'count_on_hold' => true,
        ));
        update_option('trackwp_version', '1.10.0');
    }

    public function test_migrates_1_10_0_options() {
        $this->seed_1_10_0();
        $fired_before = did_action('trackwp_upgraded_1_10_1');

        TrackWP::instance()->maybe_upgrade();

        $this->assertSame('1.10.1', get_option('trackwp_version'));

        $platforms = get_option('trackwp_platforms');
        $this->assertSame('v25.0', $platforms['meta_api_version']);

        $consent = get_option('trackwp_consent');
        $this->assertSame(__('Afvis valgfrie', 'trackwp'), $consent['reject_text']);
        $this->assertSame('auto', $consent['description_mode']);
        $this->assertArrayNotHasKey('show_reject_button', $consent);
        $this->assertSame(12, $consent['cookie_lifetime_months']);
        $this->assertSame(4, $consent['consent_version'], 'Upgrade must not bump consent_version.');

        $advanced = get_option('trackwp_advanced');
        $this->assertSame(24, $advanced['cookie_lifetime_months']);

        $this->assertArrayNotHasKey('count_on_hold', get_option('trackwp_woocommerce'));

        $this->assertSame(TrackWP_Consent_Profile::material_hash(), get_option(TrackWP::OPTION_MATERIAL_HASH));
        $this->assertNotEmpty(get_option(TrackWP::OPTION_UPGRADE_NOTICE));
        $this->assertSame($fired_before + 1, did_action('trackwp_upgraded_1_10_1'));
        $this->assertNotFalse(wp_next_scheduled(TrackWP::CRON_PRUNE_CONSENT_LOG));
    }

    public function test_keeps_owner_texts_and_newer_meta_version() {
        $this->seed_1_10_0(
            array('reject_text' => 'Nej tak', 'description' => 'Vores egen tekst.'),
            array('meta_api_version' => 'v26.0')
        );
        TrackWP::instance()->maybe_upgrade();

        $consent = get_option('trackwp_consent');
        $this->assertSame('Nej tak', $consent['reject_text']);
        $this->assertSame('custom', $consent['description_mode']);
        $this->assertSame('v26.0', get_option('trackwp_platforms')['meta_api_version']);
    }

    public function test_kun_noedvendige_becomes_afvis_valgfrie() {
        $this->seed_1_10_0(array('reject_text' => 'Kun nødvendige'));
        TrackWP::instance()->maybe_upgrade();
        $this->assertSame(__('Afvis valgfrie', 'trackwp'), get_option('trackwp_consent')['reject_text']);
    }

    public function test_existing_baseline_is_not_overwritten() {
        $this->seed_1_10_0();
        update_option(TrackWP::OPTION_MATERIAL_HASH, 'previous-baseline');
        TrackWP::instance()->maybe_upgrade();
        $this->assertSame('previous-baseline', get_option(TrackWP::OPTION_MATERIAL_HASH));
    }

    public function test_fresh_install_gets_no_cdn_notice_and_no_upgrade_action() {
        $this->seed_1_10_0();
        update_option('trackwp_version', '1.0.0'); // What activate() stores.
        $fired_before = did_action('trackwp_upgraded_1_10_1');

        TrackWP::instance()->maybe_upgrade();

        $this->assertFalse(get_option(TrackWP::OPTION_UPGRADE_NOTICE));
        $this->assertSame($fired_before, did_action('trackwp_upgraded_1_10_1'));
        $this->assertSame('1.10.1', get_option('trackwp_version'));
    }

    public function test_second_run_is_a_no_op() {
        $this->seed_1_10_0();
        TrackWP::instance()->maybe_upgrade();
        $consent_after_first = get_option('trackwp_consent');
        $fired = did_action('trackwp_upgraded_1_10_1');

        TrackWP::instance()->maybe_upgrade();

        $this->assertSame($consent_after_first, get_option('trackwp_consent'));
        $this->assertSame($fired, did_action('trackwp_upgraded_1_10_1'));
    }

    public function test_upgrade_sets_no_visitor_cookie() {
        $this->seed_1_10_0();
        $cookies_before = $_COOKIE;
        TrackWP::instance()->maybe_upgrade();
        $this->assertSame($cookies_before, $_COOKIE);
        if ( function_exists('xdebug_get_headers') ) {
            foreach ( xdebug_get_headers() as $h ) {
                $this->assertStringStartsNotWith('Set-Cookie', $h, 'R17: upgrade must not set cookies.');
            }
        }
    }
}
