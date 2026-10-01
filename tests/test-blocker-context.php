<?php
/**
 * T2: request context and buffer lifecycle (KB12, KB13 rendering side, S1,
 * S2, S12, S20).
 *
 * Producers: TrackWP_Blocker_Rules::compile() via the stored option, and
 * the real TrackWP_Blocker hooks/buffer.
 */

if (!class_exists('TrackWP_Blocker_Rules')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker-rules.php';
}
if (!class_exists('TrackWP_Blocker')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker.php';
}

class TrackWP_Blocker_Context_Test extends WP_UnitTestCase {

    private $saved = array();

    const RULES = array('host:stats.wp.com' => array('block' => true, 'category' => 'statistics', 'vendor' => 'jetpack'));

    public function set_up() {
        parent::set_up();
        $this->saved = array(
            'server'     => $_SERVER,
            'get'        => $_GET,
            'wp_version' => $GLOBALS['wp_version'],
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';
        self::set_static('buffer_started', false);
        self::set_static('instance', null);
    }

    public function tear_down() {
        $_SERVER               = $this->saved['server'];
        $_GET                  = $this->saved['get'];
        $GLOBALS['wp_version'] = $this->saved['wp_version'];
        self::set_static('buffer_started', false);
        self::set_static('instance', null);
        parent::tear_down();
    }

    private static function set_static($name, $value) {
        $r = new ReflectionProperty('TrackWP_Blocker', $name);
        $r->setAccessible(true);
        $r->setValue(null, $value);
    }

    private static function set_prop($obj, $name, $value) {
        $r = new ReflectionProperty('TrackWP_Blocker', $name);
        $r->setAccessible(true);
        $r->setValue($obj, $value);
    }

    private static function save($mode, array $extra = array()) {
        update_option('trackwp_blocker', array_merge(array('mode' => $mode, 'rules' => self::RULES, 'exceptions' => array('allow' => array(), 'paths' => array())), $extra));
        TrackWP_Blocker_Rules::rebuild();
        return get_option('trackwp_blocker');
    }

    /** Decide the request without starting a real buffer. */
    private static function decided() {
        self::set_static('buffer_started', true);
        $b = new TrackWP_Blocker();
        $b->on_template_redirect();
        return $b;
    }

    public function test_mode_off_registers_nothing_and_option_missing_is_inactive() {
        delete_option('trackwp_blocker');
        $b = new TrackWP_Blocker();
        $this->assertFalse(has_action('template_redirect', array($b, 'on_template_redirect')));
        $this->assertFalse(has_filter('script_loader_tag', array($b, 'filter_script_loader_tag')));
        $this->assertFalse(TrackWP_Blocker::active_for_request());

        self::save('off');
        $b = new TrackWP_Blocker();
        $this->assertFalse(has_action('template_redirect', array($b, 'on_template_redirect')));
        $this->assertFalse(has_filter('wp_inline_script_attributes', array($b, 'filter_inline_script_attributes')));
        $this->assertFalse(has_filter('rocket_delay_js_exclusions', array('TrackWP_Blocker', 'add_optimizer_exclusions')));
    }

    /** A cached active decision never outlives mode off or a WP below 6.5. */
    public function test_cached_active_instance_is_false_after_mode_off() {
        self::save('on');
        $b = self::decided();
        $this->assertTrue(TrackWP_Blocker::active_for_request());
        self::save('off');
        $this->assertFalse(TrackWP_Blocker::active_for_request(), 'mode off wins over the cached decision');
        self::save('on');
        $this->assertTrue(TrackWP_Blocker::active_for_request());
        $GLOBALS['wp_version'] = '6.4';
        $this->assertFalse(TrackWP_Blocker::active_for_request(), 'WP < 6.5 wins over the cached decision');
        $GLOBALS['wp_version'] = $this->saved['wp_version'];
        // A new decision starts from scratch.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $b->on_template_redirect();
        $this->assertFalse(TrackWP_Blocker::active_for_request());
    }

    public function test_mode_on_registers_hooks_and_optimizer_exclusions() {
        self::save('on');
        $b = new TrackWP_Blocker();
        $this->assertSame(9999, has_action('template_redirect', array($b, 'on_template_redirect')));
        $this->assertSame(10, has_filter('script_loader_tag', array($b, 'filter_script_loader_tag')));
        $this->assertSame(10, has_filter('wp_inline_script_attributes', array($b, 'filter_inline_script_attributes')));
        foreach (array('rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_defer_inline_exclusions', 'rocket_exclude_js', 'rocket_excluded_inline_js_content', 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc') as $f) {
            $list = apply_filters($f, array('existing'));
            $this->assertContains('existing', $list, $f);
            $this->assertContains('trackwpConsentReader', $list, $f);
            $this->assertContains('trackwpBlocker', $list, $f);
            $this->assertNotEmpty(preg_grep('#/plugins/[^/]+/$#', $list), $f);
        }
    }

    public function test_below_wp_65_is_locked() {
        self::save('on');
        delete_option(TrackWP_Blocker::STATUS_OPTION);
        $GLOBALS['wp_version'] = '6.4.3';
        $b = new TrackWP_Blocker();
        $this->assertFalse(has_action('template_redirect', array($b, 'on_template_redirect')));
        $this->assertFalse(TrackWP_Blocker::should_block_request(get_option('trackwp_blocker')));
        $this->assertSame('no_html_api', get_option(TrackWP_Blocker::STATUS_OPTION)['last_error']['code']);
    }

    public function test_kb12_matrix() {
        $opt = self::save('on');
        $this->assertTrue(TrackWP_Blocker::should_block_request($opt), 'baseline front-end GET');

        $cases = array(
            'ajax'   => array('wp_doing_ajax', '__return_true'),
            'cron'   => array('wp_doing_cron', '__return_true'),
            'filter' => array('trackwp_blocker_should_run', '__return_false'),
        );
        foreach ($cases as $label => $f) {
            add_filter($f[0], $f[1]);
            $this->assertFalse(TrackWP_Blocker::should_block_request($opt), $label);
            remove_filter($f[0], $f[1]);
        }

        foreach (array('POST', 'PUT', 'DELETE') as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            $this->assertFalse(TrackWP_Blocker::should_block_request($opt), $method);
        }
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->assertTrue(TrackWP_Blocker::should_block_request($opt), 'HEAD');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->go_to(home_url('/?feed=rss2'));
        $this->assertFalse(TrackWP_Blocker::should_block_request($opt), 'feed');
        $this->go_to(home_url('/?robots=1'));
        $this->assertFalse(TrackWP_Blocker::should_block_request($opt), 'robots');
        $post = self::factory()->post->create();
        $this->go_to(add_query_arg('embed', 'true', get_permalink($post)));
        $this->assertFalse(TrackWP_Blocker::should_block_request($opt), 'embed');
        $this->go_to(home_url('/'));
        set_query_var('sitemap', 'posts');
        $this->assertFalse(TrackWP_Blocker::should_block_request($opt), 'sitemap');
        set_query_var('sitemap', '');
        $this->assertTrue(TrackWP_Blocker::should_block_request($opt));

        set_current_screen('dashboard');
        $this->assertFalse(TrackWP_Blocker::should_block_request($opt), 'is_admin');
        set_current_screen('front');

        // S2: content type.
        $this->assertTrue(TrackWP_Blocker::response_is_html(array()));
        $this->assertTrue(TrackWP_Blocker::response_is_html(array('Content-Type: text/html; charset=UTF-8')));
        $this->assertFalse(TrackWP_Blocker::response_is_html(array('Content-Type: text/html', 'content-type: application/json')));
        $this->assertFalse(TrackWP_Blocker::response_is_html(array('Content-Type: text/xml')));
    }

    public function test_editor_flag_needs_capability() {
        $opt = self::save('on');
        foreach (array(array('elementor-preview', '1'), array('et_fb', '1'), array('bricks', 'run'), array('fl_builder', ''), array('ct_builder', 'true'), array('breakdance', 'builder')) as $flag) {
            $_GET = array($flag[0] => $flag[1]);
            wp_set_current_user(0);
            $this->assertTrue(TrackWP_Blocker::should_block_request($opt), $flag[0] . ' without capability still blocks');
            wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
            $this->assertFalse(TrackWP_Blocker::should_block_request($opt), $flag[0] . ' with edit_posts');
        }
        $_GET = array('bricks' => 'other');
        $this->assertTrue(TrackWP_Blocker::should_block_request($opt), 'bricks flag must be run');
    }

    public function test_test_mode_only_for_admins_and_not_cached() {
        $opt = self::save('test');
        wp_set_current_user(0);
        $this->assertFalse(TrackWP_Blocker::should_block_request($opt));
        $b = self::decided();
        $this->assertFalse(TrackWP_Blocker::active_for_request());
        $this->assertFalse($b->nocache);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $this->assertTrue(TrackWP_Blocker::should_block_request($opt));
        $b = self::decided();
        $this->assertTrue(TrackWP_Blocker::active_for_request());
        $this->assertTrue($b->nocache, 'nocache_headers() issued');
        $this->assertTrue(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE);
    }

    /** S20: exceptions.paths turns blocking off for script_loader_tag and the buffer. */
    public function test_exception_paths_disable_both_paths() {
        self::save('on', array('exceptions' => array('allow' => array(), 'paths' => array('/checkout/'))));
        $tag  = '<script src="https://stats.wp.com/e-202640.js" id="jetpack-stats-js"></script>';
        $html = '<html><body>' . $tag . '</body></html>';

        $_SERVER['REQUEST_URI'] = '/checkout/step-2/?x=1';
        $b = self::decided();
        $this->assertFalse(TrackWP_Blocker::active_for_request());
        $this->assertSame($tag, $b->filter_script_loader_tag($tag, 'jetpack-stats', 'https://stats.wp.com/e-202640.js'));
        $this->assertSame($html, $b->filter_html($html));

        $_SERVER['REQUEST_URI'] = '/shop/';
        $b = self::decided();
        $this->assertTrue(TrackWP_Blocker::active_for_request());
        $this->assertStringContainsString('type="text/plain"', $b->filter_script_loader_tag($tag, 'jetpack-stats', 'https://stats.wp.com/e-202640.js'));
        $this->assertStringContainsString('type="text/plain"', $b->filter_html($html));
    }

    /**
     * S1: our own non-flushable ob_start on WP 7.1 (no core
     * wp_template_enhancement_output_buffer filter); a flush in the middle of
     * a tracking script leaks nothing, and the buffer works even when core's
     * wp_should_output_buffer_template_for_enhancement is false.
     */
    public function test_own_buffer_survives_flush_and_core_opt_out() {
        self::save('on');
        add_filter('wp_should_output_buffer_template_for_enhancement', '__return_false');
        $b = new TrackWP_Blocker();

        ob_start();
        $outer = ob_get_level();
        $b->on_template_redirect();
        $this->assertSame($outer + 1, ob_get_level());
        $this->assertContains('TrackWP_Blocker::handle_buffer', ob_list_handlers());
        $this->assertFalse(has_filter('wp_template_enhancement_output_buffer', array($b, 'filter_html')));
        $this->assertFalse($b->start_buffer(), 'never two buffers');

        echo '<html><body><script id="jetpack-stats-js" src="https://stats.wp.com/e-';
        @ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a non-flushable buffer raises a notice by design.
        $status = ob_get_status(true);
        $this->assertSame(0, $status[$outer - 1]['buffer_used'], 'nothing reached the outer buffer on flush');
        echo '202640.js"></script></body></html>';
        ob_end_flush();
        $out = ob_get_clean();
        remove_filter('wp_should_output_buffer_template_for_enhancement', '__return_false');

        $this->assertStringContainsString('type="text/plain"', $out);
        $this->assertStringContainsString('data-twp-src="https://stats.wp.com/e-202640.js"', $out);
        $this->assertStringNotContainsString(' src="https://stats.wp.com', $out);
    }

    /** A clean (discarded) pass is returned untouched; only the final pass is rewritten. */
    public function test_handler_phases() {
        self::save('on');
        $b    = self::decided();
        $html = '<script src="https://stats.wp.com/e-1.js"></script>';
        $this->assertSame($html, $b->handle_buffer($html, PHP_OUTPUT_HANDLER_CLEAN | PHP_OUTPUT_HANDLER_FINAL));
        $this->assertSame($html, $b->handle_buffer($html, PHP_OUTPUT_HANDLER_WRITE));
        $this->assertStringContainsString('text/plain', $b->handle_buffer($html, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL));
    }

    private static function scanner_observe($value) {
        $r = new ReflectionProperty('TrackWP_Blocker_Scanner', 'observe');
        $r->setAccessible(true);
        $r->setValue(null, $value);
    }

    /** KB13/S12: an invalid token renders the page normally (fail closed), also in mode off. */
    public function test_invalid_scan_token_renders_normally_in_mode_off() {
        TrackWP_Blocker_Scanner::reset_request_state();
        self::save('off');
        $_SERVER['HTTP_X_TRACKWP_SCAN'] = str_repeat('ab', 16);
        $b = new TrackWP_Blocker();
        $this->assertSame(9999, has_action('template_redirect', array($b, 'on_template_redirect')), 'observe hook registered also in mode off');
        $this->assertFalse(has_filter('script_loader_tag', array($b, 'filter_script_loader_tag')));
        self::set_static('buffer_started', true);
        $b->on_template_redirect();
        $this->assertFalse(TrackWP_Blocker::observing());
        $html = '<html><body><script src="https://stats.wp.com/e-1.js"></script></body></html>';
        $this->assertSame($html, $b->filter_html($html));
        TrackWP_Blocker_Scanner::reset_request_state();
    }

    /**
     * S20: mode off (and on) with an accepted scan request gives the scanner's
     * map and no rewriting. The accepted state is set on the scanner (T4 owns
     * and tests the token itself).
     */
    public function test_observe_mode_adds_scan_map_without_rewriting() {
        $html = '<html><body><script id="jetpack-stats-js" src="https://stats.wp.com/e-202640.js"></script></body></html>';
        foreach (array('off', 'on') as $mode) {
            self::save($mode);
            self::set_static('buffer_started', true);
            self::scanner_observe(true);
            $b = new TrackWP_Blocker();
            $b->on_template_redirect();
            $this->assertTrue(TrackWP_Blocker::observing(), $mode);
            $this->assertFalse(TrackWP_Blocker::active_for_request(), $mode);
            $out = $b->filter_html($html);
            $this->assertStringNotContainsString('data-twp-', $out, $mode);
            $this->assertStringContainsString('<!--trackwp-scan-map:', $out, $mode);
            $this->assertSame($html, $b->filter_script_loader_tag($html, 'jetpack-stats', ''), $mode);
        }
        TrackWP_Blocker_Scanner::reset_request_state();
    }

    public function test_guard_js_carries_config() {
        self::save('on');
        $js = TrackWP_Blocker::guard_js();
        $dir = dirname(__DIR__) . '/assets/js/';
        if (!is_readable($dir . 'blocker-guard.min.js') && !is_readable($dir . 'blocker-guard.js')) {
            $this->assertSame('', $js);
            return;
        }
        $this->assertSame(1, preg_match('#^window\.trackwpBlockerConfig=(\{.*?\});\n#', $js, $m));
        $cfg = json_decode($m[1], true);
        $this->assertSame(array('v', 'home', 'urls', 'hosts', 'pixels', 'never'), array_keys($cfg));
        $this->assertSame(array('statistics', 'host:stats.wp.com'), $cfg['hosts']['stats.wp.com']);
        $this->assertStringNotContainsString('</script', $js);
        $this->assertStringNotContainsString('document.cookie', substr($js, 0, strlen($m[0])));
    }
}
