<?php
/**
 * uninstall.php: options, transients, cron, order meta and (on multisite)
 * every site. Tables are dropped too; under WP_UnitTestCase the DROP is
 * turned into DROP TEMPORARY, so the real tables of other test classes stay.
 */
class TrackWP_Uninstall_Test extends WP_UnitTestCase {

    private function run_uninstall() {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'trackwp/trackwp.php');
        }
        include dirname(__DIR__) . '/uninstall.php';
    }

    private function seed_site() {
        update_option('trackwp_consent', array('consent_version' => 2));
        update_option('trackwp_some_future_option', 1);
        // 1.11.0 options and transients.
        update_option('trackwp_blocker', array('mode' => 'off'));
        update_option('trackwp_blocker_scan', array('items' => array()), false);
        update_option('trackwp_blocker_compiled', array('v' => 1));
        update_option('trackwp_blocker_status', array('last_ok' => 1), false);
        update_option('trackwp_meta_last_error', array('code' => 190), false);
        // 1.11.1: the versioned upgrade notice, its predecessor, and the D1
        // Meta-takeover one-time notice (KC15).
        update_option('trackwp_upgrade_notice', '1.11.1', false);
        update_option('trackwp_upgrade_notice_1_10_1', 1, false);
        update_option('trackwp_upgrade_notice_meta_takeover', 1, false);
        set_transient('trackwp_blk_scan_lock', 1, 120);
        set_transient('trackwp_blk_obs_' . hash('sha256', 'x'), array('user_id' => 1), 600);
        set_transient('trackwp_rl_consent_x_1', 3, 60);
        set_transient('trackwp_cts_abc', '1', 60);
        wp_schedule_event(time() + 60, 'daily', 'trackwp_prune_consent_log');
        wp_schedule_single_event(time() + 60, 'trackwp_migrate_consent_log');
        $post = self::factory()->post->create();
        add_post_meta($post, '_trackwp_attribution', array('x' => 1));
        add_post_meta($post, '_trackwp_purchase_sent', 1);
        return $post;
    }

    private function assert_site_clean($post) {
        wp_cache_flush();
        $this->assertFalse(get_option('trackwp_consent'));
        $this->assertFalse(get_option('trackwp_some_future_option'));
        $this->assertFalse(get_transient('trackwp_rl_consent_x_1'));
        $this->assertFalse(get_transient('trackwp_cts_abc'));
        foreach (array('trackwp_blocker', 'trackwp_blocker_scan', 'trackwp_blocker_compiled', 'trackwp_blocker_status', 'trackwp_meta_last_error') as $option) {
            $this->assertFalse(get_option($option), $option);
        }
        foreach (array('trackwp_upgrade_notice', 'trackwp_upgrade_notice_1_10_1', 'trackwp_upgrade_notice_meta_takeover') as $option) {
            $this->assertFalse(get_option($option), $option);
        }
        $this->assertFalse(get_transient('trackwp_blk_scan_lock'));
        $this->assertFalse(get_transient('trackwp_blk_obs_' . hash('sha256', 'x')));
        $this->assertFalse(wp_next_scheduled('trackwp_prune_consent_log'));
        $this->assertFalse(wp_next_scheduled('trackwp_migrate_consent_log'));
        $this->assertSame(array(), get_post_meta($post, '_trackwp_attribution'));
        $this->assertSame(array(), get_post_meta($post, '_trackwp_purchase_sent'));
    }

    public function test_uninstall_cleans_every_site() {
        if (function_exists('trackwp_uninstall_site')) {
            $this->markTestSkipped('uninstall.php already included in this process.');
        }
        $sites = array();
        if (is_multisite()) {
            foreach (array(0, 1) as $i) {
                $blog = 0 === $i ? get_current_blog_id() : self::factory()->blog->create();
                switch_to_blog($blog);
                $sites[ $blog ] = $this->seed_site();
                restore_current_blog();
            }
        } else {
            $sites[ get_current_blog_id() ] = $this->seed_site();
        }

        $this->run_uninstall();

        foreach ($sites as $blog => $post) {
            if (is_multisite()) {
                switch_to_blog($blog);
            }
            $this->assert_site_clean($post);
            if (is_multisite()) {
                restore_current_blog();
            }
        }
    }
}
