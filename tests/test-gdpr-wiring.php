<?php
/**
 * 1.11.4 wiring of the data-subject features (Fund 5, 10, 11).
 *
 * Real producers: the filters are applied through WordPress (apply_filters /
 * do_action) so TrackWP::init_hooks() is what is tested; the attributed order
 * is written by TrackWP_WooCommerce::save_attribution(); the notice is rendered
 * by TrackWP_Settings::render_admin_notices(); the delivery-log table is made
 * by TrackWP_Delivery_Log::create_table().
 *
 * @group orders
 */
class TrackWP_Gdpr_Wiring_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
    }

    public function tear_down() {
        unset($_COOKIE['trackwp_consent'], $_COOKIE['_ga'], $_COOKIE['_fbp']);
        TrackWP_Delivery_Log::drop_table();
        wp_clear_scheduled_hook(TrackWP_Delivery_Log::CRON_HOOK);
        parent::tear_down();
    }

    public function test_exporter_and_eraser_are_registered_only_with_woocommerce() {
        $exporters = apply_filters('wp_privacy_personal_data_exporters', array());
        $erasers   = apply_filters('wp_privacy_personal_data_erasers', array());
        $woo       = class_exists('WooCommerce');

        $this->assertSame($woo, isset($exporters['trackwp-attribution']));
        $this->assertSame($woo, isset($erasers['trackwp-attribution']));
        if ($woo) {
            $this->assertSame(array('TrackWP_WooCommerce', 'privacy_exporter'), $exporters['trackwp-attribution']['callback']);
            $this->assertSame(array('TrackWP_WooCommerce', 'privacy_eraser'), $erasers['trackwp-attribution']['callback']);
            $this->assertNotSame('', $exporters['trackwp-attribution']['exporter_friendly_name']);
        }
    }

    public function test_privacy_policy_content_is_added_on_admin_init() {
        $this->assertNotFalse(has_action('admin_init', array(TrackWP::instance(), 'add_privacy_policy_content')));
        if (!class_exists('WP_Privacy_Policy_Content')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
        }
        // wp_add_privacy_policy_content() only accepts calls from the admin
        // and during admin_init, so run the registered callback that way.
        set_current_screen('dashboard');
        $GLOBALS['wp_current_filter'][] = 'admin_init';
        TrackWP::instance()->add_privacy_policy_content();
        array_pop($GLOBALS['wp_current_filter']);
        $found = false;
        foreach (WP_Privacy_Policy_Content::get_suggested_policy_text() as $entry) {
            if (isset($entry['plugin_name']) && 'TrackWP' === $entry['plugin_name']) {
                $found = true;
                $this->assertStringContainsString('90', $entry['policy_text']);
            }
        }
        $this->assertTrue($found, 'TrackWP policy text is registered.');
    }

    public function test_prune_attribution_is_hooked_on_the_daily_cron() {
        $this->assertNotFalse(has_action(TrackWP::CRON_PRUNE_CONSENT_LOG, array('TrackWP_WooCommerce', 'prune_attribution')));
    }

    public function test_daily_cron_removes_attribution_older_than_90_days() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not installed.');
        }
        $make = function ($days) {
            $order = wc_create_order();
            $order->set_billing_email('cron' . $days . '@example.org');
            $order->save();
            $cfg = get_option('trackwp_consent', array());
            $_COOKIE['trackwp_consent'] = wp_json_encode(array(
                'v'          => isset($cfg['consent_version']) ? (int) $cfg['consent_version'] : 1,
                'ts'         => gmdate('Y-m-d\TH:i:s.000\Z'),
                'id'         => wp_generate_uuid4(),
                'necessary'  => true,
                'statistics' => true,
                'marketing'  => true,
            ));
            $_COOKIE['_ga'] = 'GA1.1.111.222';
            (new TrackWP_WooCommerce(false))->save_attribution($order->get_id());
            $order = wc_get_order($order->get_id());
            $this->assertNotEmpty($order->get_meta(TrackWP_WooCommerce::ORDER_META_ATTRIBUTION), 'producer wrote attribution');
            $order->set_date_created(time() - $days * DAY_IN_SECONDS);
            $order->save();
            return $order->get_id();
        };
        $old    = $make(91);
        $recent = $make(89);

        do_action(TrackWP::CRON_PRUNE_CONSENT_LOG);

        $this->assertEmpty(wc_get_order($old)->get_meta(TrackWP_WooCommerce::ORDER_META_ATTRIBUTION));
        $this->assertNotEmpty(wc_get_order($recent)->get_meta(TrackWP_WooCommerce::ORDER_META_ATTRIBUTION));
    }

    public function test_delivery_log_cron_stays_scheduled_when_log_is_off_and_table_exists() {
        TrackWP_Delivery_Log::create_table();
        $this->assertTrue(TrackWP_Delivery_Log::table_exists());
        wp_clear_scheduled_hook(TrackWP_Delivery_Log::CRON_HOOK);

        // Settings save path (sanitize_advanced) with the log switched off.
        (new TrackWP_Settings())->sanitize_advanced(array('delivery_log_enabled' => ''));
        $this->assertNotFalse(wp_next_scheduled(TrackWP_Delivery_Log::CRON_HOOK), 'sanitize_advanced keeps the prune job.');

        // Sync path (update_option_* hooks, upgrade, activation).
        wp_clear_scheduled_hook(TrackWP_Delivery_Log::CRON_HOOK);
        update_option('trackwp_advanced', array('delivery_log_enabled' => false));
        TrackWP_Delivery_Log::sync_cron();
        $this->assertNotFalse(wp_next_scheduled(TrackWP_Delivery_Log::CRON_HOOK), 'sync_cron keeps the prune job.');
    }

    public function test_delivery_log_cron_is_cleared_when_log_is_off_and_no_table() {
        TrackWP_Delivery_Log::drop_table();
        $this->assertFalse(TrackWP_Delivery_Log::table_exists());
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', TrackWP_Delivery_Log::CRON_HOOK);

        update_option('trackwp_advanced', array('delivery_log_enabled' => false));
        TrackWP_Delivery_Log::sync_cron();
        $this->assertFalse(wp_next_scheduled(TrackWP_Delivery_Log::CRON_HOOK));

        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', TrackWP_Delivery_Log::CRON_HOOK);
        (new TrackWP_Settings())->sanitize_advanced(array('delivery_log_enabled' => ''));
        $this->assertFalse(wp_next_scheduled(TrackWP_Delivery_Log::CRON_HOOK));
    }

    public function test_prune_is_still_table_guarded() {
        TrackWP_Delivery_Log::drop_table();
        $this->assertSame(0, TrackWP_Delivery_Log::prune());
    }

    private function render_notices($consent) {
        update_option('trackwp_consent', $consent);
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        set_current_screen('toplevel_page_trackwp');
        ob_start();
        (new TrackWP_Settings())->render_admin_notices();
        return ob_get_clean();
    }

    public function test_warning_is_rendered_when_consent_log_is_off() {
        $html = $this->render_notices(array('controller_name' => 'Eksempel ApS', 'log_consent' => false));
        $this->assertStringContainsString('GDPR art. 7, stk. 1', $html);
        $this->assertStringContainsString('notice-warning', $html);
    }

    public function test_no_warning_when_consent_log_is_on() {
        $html = $this->render_notices(array('controller_name' => 'Eksempel ApS', 'log_consent' => true));
        $this->assertStringNotContainsString('GDPR art. 7, stk. 1', $html);
    }
}
