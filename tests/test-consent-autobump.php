<?php
/**
 * Fund 6 + 8 (1.11.4): a vendor or category visitors were never asked about
 * raises consent_version automatically, and every change leaves a banner
 * snapshot for the current banner_hash.
 *
 * Real producers: TrackWP_Settings::maybe_auto_bump() is reached through the
 * real update_option_* hooks registered by TrackWP::init_hooks(), the baseline
 * comes from TrackWP_Consent_Profile::vendor_baseline(), and the scan option is
 * built from TrackWP_Consent_Profile::vendor_catalog() (same approach as
 * tests/test-consent-profile.php:blocker_scan).
 *
 * The WP test case turns CREATE TABLE into temporary tables, which SHOW TABLES
 * does not list; those query filters are removed (as in test-consent-log.php)
 * and the real tables are dropped after the class.
 */
class TrackWP_Consent_Autobump_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
        TrackWP_Consent_Log::create_tables();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . TrackWP_Consent_Log::texts_table_name());

        TrackWP_Settings::suspend_auto_bump(false);
        delete_option(TrackWP_Settings::OPTION_VENDOR_BASELINE);
        delete_option('trackwp_blocker');
        delete_option('trackwp_blocker_scan');
        delete_option('trackwp_cookie_declarations');
        update_option('trackwp_woocommerce', array());
        update_option('trackwp_advanced', array('first_party_cookie_enabled' => true, 'cookie_lifetime_months' => 24));
        update_option('trackwp_consent', array('controller_name' => 'Eksempel ApS', 'cookie_lifetime_months' => 12, 'reconsent_on_policy_change' => false));
        update_option('trackwp_platforms', array());
        delete_option(TrackWP_Settings::OPTION_VENDOR_BASELINE);
    }

    public static function tear_down_after_class() {
        // Leave the tables as the bootstrap did: later classes (and the
        // table_exists() cache) expect them to exist.
        TrackWP_Consent_Log::drop_tables();
        TrackWP_Consent_Log::create_tables();
        parent::tear_down_after_class();
    }

    private function version() {
        return TrackWP_Consent::server_version();
    }

    private function ga4_only() {
        return array('ga4_enabled' => true, 'ga4_measurement_id' => 'G-TEST123', 'ga4_gtag_enabled' => true);
    }

    private function with_meta() {
        return $this->ga4_only() + array('meta_enabled' => true, 'meta_pixel_id' => '123456789012345', 'meta_pixel_client_enabled' => true);
    }

    public function test_missing_baseline_is_initialised_without_bump() {
        $before = $this->version();
        $this->assertFalse(get_option(TrackWP_Settings::OPTION_VENDOR_BASELINE, false));

        update_option('trackwp_platforms', $this->with_meta());

        $this->assertSame($before, $this->version(), 'First write only records the baseline.');
        $baseline = get_option(TrackWP_Settings::OPTION_VENDOR_BASELINE);
        $this->assertSame(TrackWP_Consent_Profile::vendor_baseline(), $baseline);
        $this->assertContains('v:meta', $baseline);
    }

    public function test_adding_meta_bumps_once_and_snapshots_the_new_hash() {
        update_option('trackwp_platforms', $this->ga4_only());
        $before = $this->version();
        $hash_before = TrackWP_Consent_Profile::banner_hash();

        $writes = 0;
        $count  = function () use (&$writes) {
            $writes++;
        };
        add_action('update_option_trackwp_consent', $count);
        update_option('trackwp_platforms', $this->with_meta());
        remove_action('update_option_trackwp_consent', $count);

        $this->assertSame($before + 1, $this->version(), 'Exactly one bump.');
        $this->assertSame(1, $writes, 'The bump writes trackwp_consent once: no loop.');
        $this->assertContains('v:meta', get_option(TrackWP_Settings::OPTION_VENDOR_BASELINE));
        $this->assertSame(TrackWP_Consent_Profile::material_hash(), get_option(TrackWP::OPTION_MATERIAL_HASH), 'material_hash baseline follows, so the "platforms changed" notice is not left behind.');

        $hash_after = TrackWP_Consent_Profile::banner_hash();
        $this->assertNotSame($hash_before, $hash_after);
        $this->assertNotNull(TrackWP_Consent_Log::get_texts($hash_after), 'Snapshot exists for the new banner_hash.');
        $snap = json_decode(TrackWP_Consent_Log::get_texts($hash_after), true);
        $this->assertSame($before + 1, $snap['consent_version']);
    }

    public function test_removing_meta_does_not_bump_and_baseline_does_not_shrink() {
        update_option('trackwp_platforms', $this->ga4_only());
        update_option('trackwp_platforms', $this->with_meta());
        $before = $this->version();

        update_option('trackwp_platforms', $this->ga4_only());

        $this->assertSame($before, $this->version());
        $this->assertContains('v:meta', get_option(TrackWP_Settings::OPTION_VENDOR_BASELINE));
    }

    public function test_reactivating_meta_after_removal_does_not_bump() {
        update_option('trackwp_platforms', $this->ga4_only());
        update_option('trackwp_platforms', $this->with_meta());
        update_option('trackwp_platforms', $this->ga4_only());
        $before = $this->version();

        update_option('trackwp_platforms', $this->with_meta());

        $this->assertSame($before, $this->version(), 'The baseline only grows: no repeated waves.');
    }

    public function test_changing_vendor_text_does_not_bump() {
        update_option('trackwp_platforms', $this->with_meta());
        $before = $this->version();

        $consent = get_option('trackwp_consent');
        $consent['accept_text'] = 'Ja tak';
        $consent['controller_name'] = 'Nyt Navn ApS';
        update_option('trackwp_consent', $consent);

        $this->assertSame($before, $this->version());
    }

    public function test_independent_of_reconsent_on_policy_change() {
        $consent = get_option('trackwp_consent');
        $consent['reconsent_on_policy_change'] = false;
        update_option('trackwp_consent', $consent);
        update_option('trackwp_platforms', $this->ga4_only());
        $before = $this->version();

        update_option('trackwp_platforms', $this->with_meta());

        $this->assertSame($before + 1, $this->version());
    }

    public function test_blocker_scan_with_new_vendor_bumps() {
        update_option('trackwp_platforms', $this->ga4_only());
        $before  = $this->version();
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $key     = 'meta';
        $handles = $catalog[$key]['signatures']['handles'];
        $hosts   = $catalog[$key]['signatures']['hosts'];
        $rule_id = $handles ? 'handle:' . $handles[0] : 'host:' . $hosts[0];
        $items   = array(array('obs_id' => substr(sha1($rule_id), 0, 12), 'rule_id' => $rule_id, 'kind' => 'script', 'host' => '', 'path' => '', 'handle' => '', 'plugin' => '', 'deps' => array(), 'dependents' => array(), 'marker' => '', 'pages' => array('/'), 'vendor' => $key, 'category_guess' => $catalog[$key]['category'], 'status' => 'allowed', 'seen_blocked' => null));

        update_option('trackwp_blocker_scan', array('scanned_at' => 1, 'pages' => array('/'), 'items' => $items));

        $this->assertContains('v:meta', TrackWP_Consent_Profile::vendor_baseline());
        $this->assertSame($before + 1, $this->version());
        $this->assertContains('v:meta', get_option(TrackWP_Settings::OPTION_VENDOR_BASELINE));

        // The same finding written again (scanner flapping) is not a new wave.
        update_option('trackwp_blocker_scan', array('scanned_at' => 2, 'pages' => array('/'), 'items' => $items));
        $this->assertSame($before + 1, $this->version());
    }

    public function test_add_option_hook_is_covered_too() {
        update_option('trackwp_platforms', $this->ga4_only());
        $before = $this->version();
        delete_option('trackwp_cookie_declarations');
        $this->assertNotContains('c:marketing', TrackWP_Consent_Profile::vendor_baseline());

        add_option('trackwp_cookie_declarations', array(
            'marketing' => array(array('cookies' => '_x', 'type' => 'http', 'category' => 'marketing', 'purpose' => 'x', 'lifetime' => '1 dag')),
        ));

        $this->assertContains('c:marketing', TrackWP_Consent_Profile::vendor_baseline(), 'The declaration adds a category.');
        $this->assertSame($before + 1, $this->version());
    }

    public function test_upgrade_initialises_baseline_without_bump_even_when_it_adds_a_vendor() {
        update_option('trackwp_platforms', $this->with_meta());
        $before = $this->version();
        delete_option(TrackWP_Settings::OPTION_VENDOR_BASELINE);
        delete_option(TrackWP::OPTION_MATERIAL_HASH);
        update_option('trackwp_version', '1.11.3');

        TrackWP::instance()->maybe_upgrade();

        $this->assertSame($before, $this->version(), 'Upgrading must not bump.');
        $this->assertSame(TrackWP_Consent_Profile::vendor_baseline(), get_option(TrackWP_Settings::OPTION_VENDOR_BASELINE));
        $this->assertSame(TRACKWP_VERSION, get_option('trackwp_version'));

        // After the upgrade the auto-bump is live again.
        update_option('trackwp_platforms', $this->with_meta() + array('google_ads_enabled' => true, 'google_ads_conversion_id' => 'AW-1234567890'));
        $this->assertSame($before + 1, $this->version());
    }

    public function test_auto_bump_stores_a_message_that_renders_once() {
        update_option('trackwp_platforms', $this->ga4_only());
        $this->assertFalse(get_option(TrackWP_Settings::OPTION_AUTO_BUMP_NOTICE, false), 'No bump yet, no message.');

        update_option('trackwp_platforms', $this->with_meta());
        $stored = get_option(TrackWP_Settings::OPTION_AUTO_BUMP_NOTICE);
        $this->assertContains('v:meta', $stored['keys']);
        $this->assertGreaterThan(0, $stored['ts']);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        set_current_screen('toplevel_page_trackwp');
        ob_start();
        (new TrackWP_Settings())->render_admin_notices();
        $first = ob_get_clean();
        $this->assertStringContainsString('Samtykkeversionen blev hævet automatisk', $first);
        $this->assertStringContainsString('meta', $first);

        ob_start();
        (new TrackWP_Settings())->render_admin_notices();
        $second = ob_get_clean();
        $this->assertStringNotContainsString('Samtykkeversionen blev hævet automatisk', $second, 'Shown once only.');
        $this->assertFalse(get_option(TrackWP_Settings::OPTION_AUTO_BUMP_NOTICE, false));
    }

    public function test_notice_accumulates_keys_until_shown() {
        update_option('trackwp_platforms', $this->ga4_only());
        update_option('trackwp_platforms', $this->with_meta());
        $first_keys = get_option(TrackWP_Settings::OPTION_AUTO_BUMP_NOTICE)['keys'];
        $this->assertContains('v:meta', $first_keys);

        update_option('trackwp_platforms', $this->with_meta() + array('google_ads_enabled' => true, 'google_ads_conversion_id' => 'AW-1234567890'));
        $keys = get_option(TrackWP_Settings::OPTION_AUTO_BUMP_NOTICE)['keys'];
        $this->assertContains('v:meta', $keys, 'The first bump is not overwritten.');
        $this->assertGreaterThan(count($first_keys), count($keys), 'The second bump adds its own key.');
        $this->assertSame(array_values(array_unique($keys)), $keys, 'No duplicates.');

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        set_current_screen('toplevel_page_trackwp');
        ob_start();
        (new TrackWP_Settings())->render_admin_notices();
        $html = ob_get_clean();
        $this->assertStringContainsString('meta', $html);
        $new = array_values(array_diff($keys, $first_keys));
        $this->assertNotEmpty($new);
        $this->assertStringContainsString(preg_replace('/^[a-z]:/', '', $new[0]), $html);
        $this->assertFalse(get_option(TrackWP_Settings::OPTION_AUTO_BUMP_NOTICE, false), 'Removed after it was shown.');
    }

    public function test_new_necessary_vendor_does_not_bump_but_new_marketing_vendor_does() {
        update_option('trackwp_platforms', $this->ga4_only());
        $before = $this->version();

        $necessary = function ($vendors) {
            $vendors['shop_session'] = array('key' => 'shop_session', 'name' => 'Shop', 'category' => 'necessary', 'cookies' => 'x', 'placeholder' => false, 'via_gtm' => false, 'via_blocker' => false, 'blocked' => false, 'server_only' => false);
            return $vendors;
        };
        add_filter('trackwp_consent_profile_vendors', $necessary);
        update_option('trackwp_platforms', $this->ga4_only() + array('google_ads_enabled' => false));
        update_option('trackwp_consent', array_merge(get_option('trackwp_consent'), array('controller_name' => 'Ny Eksempel ApS')));
        $this->assertSame($before, $this->version(), 'Necessary vendor: no re-consent.');
        $this->assertNotContains('v:shop_session', TrackWP_Consent_Profile::vendor_baseline());
        remove_filter('trackwp_consent_profile_vendors', $necessary);

        update_option('trackwp_platforms', $this->with_meta());
        $this->assertSame($before + 1, $this->version(), 'Marketing vendor: bump.');
    }
}
