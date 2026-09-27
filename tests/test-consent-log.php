<?php
/**
 * TrackWP_Consent_Log: dbDelta idempotency, migration of the 1.10.0 option,
 * lock, verified count, prune and text snapshots.
 *
 * Real producer: the 1.10.0 TrackWP_Consent::append_consent_log_entry(),
 * loaded verbatim from tests/legacy/class-trackwp-consent-1.10.0.php.txt
 * (extracted unchanged from trackwp-1.10.0.zip, md5 ec902efb28078b8efdc4e45eef92551e;
 * only the class name is changed at load time to avoid a collision).
 *
 * The WP test case turns CREATE TABLE into temporary tables, which SHOW
 * TABLES does not list; those query filters are removed here and the real
 * tables are dropped after the class.
 */
class TrackWP_Consent_Log_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
        TrackWP_Consent_Log::create_tables();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . TrackWP_Consent_Log::table_name());
        $wpdb->query('TRUNCATE TABLE ' . TrackWP_Consent_Log::texts_table_name());
        delete_option('trackwp_consent_log');
    }

    public static function tear_down_after_class() {
        TrackWP_Consent_Log::drop_tables();
        parent::tear_down_after_class();
    }

    /**
     * Produce $n entries with the real 1.10.0 producer.
     */
    private function produce_legacy_entries($n) {
        if (!class_exists('TrackWP_Consent_Legacy_1100')) {
            $src = file_get_contents(dirname(__FILE__) . '/legacy/class-trackwp-consent-1.10.0.php.txt');
            $src = preg_replace('/^<\?php/', '', $src);
            $src = str_replace('class TrackWP_Consent {', 'class TrackWP_Consent_Legacy_1100 {', $src);
            eval($src); // phpcs:ignore Squiz.PHP.Eval -- loads the verbatim 1.10.0 producer.
        }
        $ref      = new ReflectionClass('TrackWP_Consent_Legacy_1100');
        $producer = $ref->newInstanceWithoutConstructor();
        $method   = $ref->getMethod('append_consent_log_entry');
        $method->setAccessible(true);
        $_SERVER['REMOTE_ADDR']     = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['HTTP_REFERER']    = home_url('/checkout/?email=a%40b.dk&x=1');
        // The producer does get_option + update_option per entry. Run it
        // against an in-memory buffer (pre_option / pre_update_option) so the
        // 1.10.0 code is exercised unchanged without 1,200 growing option
        // writes, then persist the result ONCE and read it back from the DB.
        $buffer  = get_option('trackwp_consent_log', array());
        $buffer  = is_array($buffer) ? $buffer : array();
        $read    = function () use (&$buffer) {
            return $buffer;
        };
        $capture = function ($new) use (&$buffer) {
            $buffer = $new;
            return $new;
        };
        $keep    = function ($new, $old) {
            return $old; // equal to the stored value -> update_option() writes nothing.
        };
        add_filter('pre_option_trackwp_consent_log', $read);
        add_filter('pre_update_option_trackwp_consent_log', $capture, 10, 1);
        add_filter('pre_update_option_trackwp_consent_log', $keep, 99, 2);
        for ($i = 0; $i < $n; $i++) {
            $method->invoke($producer, array('statistics' => 0 === $i % 2, 'marketing' => 0 === $i % 3), 0 === $i % 10 ? 'withdraw' : 'set');
        }
        remove_filter('pre_option_trackwp_consent_log', $read);
        remove_filter('pre_update_option_trackwp_consent_log', $capture, 10);
        remove_filter('pre_update_option_trackwp_consent_log', $keep, 99);

        update_option('trackwp_consent_log', $buffer, false);
        wp_cache_delete('trackwp_consent_log', 'options');
        wp_cache_delete('notoptions', 'options');
        $stored = get_option('trackwp_consent_log');
        $this->assertIsArray($stored);
        $this->assertCount(count($buffer), $stored, 'Fixture must be persisted before migrating');
        return $stored;
    }

    /**
     * Run the migration until it reports done (the budget may end a run with
     * 'partial' on a slow database), capped at 20 runs.
     */
    private function migrate_until_done() {
        for ($i = 0; $i < 20; $i++) {
            if (TrackWP_Consent_Log::migrate_legacy_option()) {
                return $i + 1;
            }
            $this->assertContains(TrackWP_Consent_Log::last_migration_status(), array('partial'), 'Only partial may repeat');
        }
        $this->fail('Migration did not finish within 20 runs: ' . TrackWP_Consent_Log::last_migration_status());
    }

    public function test_install_is_idempotent() {
        $this->assertTrue(TrackWP_Consent_Log::create_tables());
        $this->assertTrue(TrackWP_Consent_Log::create_tables());
        global $wpdb;
        $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . TrackWP_Consent_Log::table_name());
        foreach (array('id', 'consent_id', 'created_at', 'event_type', 'statistics', 'marketing', 'personalisation', 'consent_version', 'server_consent_version', 'banner_hash', 'ip_hash', 'user_agent', 'page_url', 'source', 'migration_key') as $col) {
            $this->assertContains($col, $cols);
        }
        $idx = $wpdb->get_results('SHOW INDEX FROM ' . TrackWP_Consent_Log::table_name() . " WHERE Key_name = 'migration_key'", ARRAY_A);
        $this->assertSame('0', (string) $idx[0]['Non_unique']);
    }

    public function test_migrates_1200_legacy_entries_and_deletes_option() {
        $entries = $this->produce_legacy_entries(1200);
        $this->assertCount(1200, $entries);

        $this->migrate_until_done();
        $this->assertSame(1200, TrackWP_Consent_Log::count_rows());
        $this->assertNull(get_option('trackwp_consent_log', null), 'Option removed only after verified count');

        $rows = TrackWP_Consent_Log::find_by_consent_id($entries[0]['consent_id']);
        $this->assertCount(1, $rows);
        $this->assertSame('withdraw', $rows[0]['event_type']);
        $this->assertSame('migration', $rows[0]['source']);
        $this->assertSame($entries[0]['consent_id'], $rows[0]['migration_key']);
        $this->assertStringNotContainsString('a@b.dk', $rows[0]['page_url']);
        $this->assertStringNotContainsString('a%40b.dk', $rows[0]['page_url']);
    }

    public function test_partial_then_resume_without_duplicates() {
        $this->produce_legacy_entries(1200);
        // Make the first chunk INSERT take longer than the 1 s budget, so the
        // run stops after chunk 1 with 'partial' (deterministic, no timing luck).
        $table = TrackWP_Consent_Log::table_name();
        $slow  = function ($q) use ($table) {
            static $done = false;
            if (!$done && 0 === strpos($q, "INSERT IGNORE INTO {$table}")) {
                $done = true;
                usleep(1100000);
            }
            return $q;
        };
        add_filter('query', $slow);
        $this->assertFalse(TrackWP_Consent_Log::migrate_legacy_option(1));
        remove_filter('query', $slow);
        $this->assertSame('partial', TrackWP_Consent_Log::last_migration_status());
        $this->assertSame(500, TrackWP_Consent_Log::count_rows());
        $this->assertIsArray(get_option('trackwp_consent_log'), 'Option kept after partial');

        $this->migrate_until_done();
        $this->assertSame(1200, TrackWP_Consent_Log::count_rows());
        global $wpdb;
        $this->assertSame(1200, (int) $wpdb->get_var("SELECT COUNT(DISTINCT migration_key) FROM {$table}"));
        $this->assertNull(get_option('trackwp_consent_log', null));
    }

    public function test_rerun_does_not_duplicate() {
        $entries = $this->produce_legacy_entries(50);
        $this->migrate_until_done();
        update_option('trackwp_consent_log', $entries, false);
        $this->migrate_until_done();
        $this->assertSame(50, TrackWP_Consent_Log::count_rows());
    }

    public function test_entry_without_uuid_gets_md5_key() {
        $entry = array('timestamp' => '2025-01-01T00:00:00+00:00', 'event_type' => 'set', 'statistics' => true);
        $key   = TrackWP_Consent_Log::migration_key($entry);
        $this->assertSame(36, strlen($key));
        $this->assertSame(md5(serialize($entry)), str_replace('-', '', $key));
    }

    public function test_locked_migration_does_nothing() {
        $this->produce_legacy_entries(5);
        $conn = $this->second_connection();
        $got  = $conn->query("SELECT GET_LOCK('trackwp_consent_log_migrate', 0)")->fetch_row();
        $this->assertSame('1', (string) $got[0]);
        try {
            $this->assertFalse(TrackWP_Consent_Log::migrate_legacy_option());
            $this->assertSame('locked', TrackWP_Consent_Log::last_migration_status());
            $this->assertSame(0, TrackWP_Consent_Log::count_rows());
            $this->assertIsArray(get_option('trackwp_consent_log'));
        } finally {
            $conn->query("SELECT RELEASE_LOCK('trackwp_consent_log_migrate')");
            $conn->close();
        }
        $this->assertTrue(TrackWP_Consent_Log::migrate_legacy_option());
    }

    public function test_nothing_to_migrate_is_true() {
        $this->assertTrue(TrackWP_Consent_Log::migrate_legacy_option());
        $this->assertSame('nothing', TrackWP_Consent_Log::last_migration_status());
    }

    public function test_prune_by_age_with_clamped_retention() {
        update_option('trackwp_consent', array('consent_log_retention_months' => 1)); // clamps to 6
        $this->assertSame(6, TrackWP_Consent_Log::retention_months());
        TrackWP_Consent_Log::insert(array('consent_id' => 'old', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-7 months'))));
        TrackWP_Consent_Log::insert(array('consent_id' => 'new', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-5 months'))));
        $this->assertSame(1, TrackWP_Consent_Log::prune());
        $this->assertSame(1, TrackWP_Consent_Log::count_rows());
        update_option('trackwp_consent', array('consent_log_retention_months' => 999));
        $this->assertSame(60, TrackWP_Consent_Log::retention_months());
    }

    public function test_snapshot_is_immutable() {
        $hash = hash('sha256', 'banner');
        $this->assertTrue(TrackWP_Consent_Log::snapshot_texts($hash, '{"a":1}'));
        $this->assertFalse(TrackWP_Consent_Log::snapshot_texts($hash, '{"a":2}'));
        $this->assertSame('{"a":1}', TrackWP_Consent_Log::get_texts($hash));
        $this->assertFalse(TrackWP_Consent_Log::snapshot_texts('not-a-hash', 'x'));
    }

    public function test_sync_cron_schedules_prune() {
        wp_clear_scheduled_hook(TrackWP_Consent_Log::CRON_HOOK);
        TrackWP_Consent_Log::sync_cron();
        $this->assertNotFalse(wp_next_scheduled(TrackWP_Consent_Log::CRON_HOOK));
    }

    public function test_endpoint_inserts_row_when_logging_on() {
        update_option('trackwp_consent', array('consent_version' => 1, 'log_consent' => true));
        $_SERVER['HTTP_ORIGIN']  = home_url();
        $_SERVER['HTTP_REFERER'] = home_url('/shop/?token=secret');
        $_SERVER['REMOTE_ADDR']  = '192.0.2.44';
        global $wp_rest_server;
        $wp_rest_server = null;
        $req = new WP_REST_Request('POST', '/trackwp/v1/consent-log');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(array(
            'statistics' => true, 'marketing' => false, 'personalisation' => false,
            'consent_id' => '11111111-2222-4333-8444-555555555555', 'consent_version' => 1,
            'ts_ms' => 1788256800000, 'event_type' => 'update', 'banner_hash' => str_repeat('b', 64),
        )));
        $res = rest_get_server()->dispatch($req);
        $this->assertSame(200, $res->get_status());
        $rows = TrackWP_Consent_Log::find_by_consent_id('11111111-2222-4333-8444-555555555555');
        $this->assertCount(1, $rows);
        $this->assertSame('update', $rows[0]['event_type']);
        $this->assertSame('1', (string) $rows[0]['statistics']);
        $this->assertSame(str_repeat('b', 64), $rows[0]['banner_hash']);
        $this->assertStringNotContainsString('secret', $rows[0]['page_url']);
        $this->assertSame(hash('sha256', '192.0.2.44' . wp_salt()), $rows[0]['ip_hash']);
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        $wp_rest_server = null;
    }

    private function second_connection() {
        $host = DB_HOST;
        $port = 3306;
        if (false !== strpos($host, ':')) {
            list($host, $p) = explode(':', $host, 2);
            $port = (int) $p;
        }
        $conn = new mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);
        $this->assertSame(0, $conn->connect_errno);
        return $conn;
    }
}
