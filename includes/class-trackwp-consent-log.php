<?php
defined('ABSPATH') || exit;

/**
 * Consent log storage (contract K4, plan §2.4 W1).
 *
 * Tables:
 *  - {prefix}trackwp_consent_log: one row per consent action (set, update,
 *    withdraw) plus rows migrated from the 1.10.0 option `trackwp_consent_log`.
 *  - {prefix}trackwp_consent_texts: immutable snapshot of the banner content
 *    per banner_hash (INSERT IGNORE, never updated).
 *
 * Public static API used by trackwp.php (W8) and the admin (W7):
 *  - create_tables()         create/upgrade both tables (dbDelta, idempotent)
 *  - migrate_legacy_option() move the 1.10.0 option into the table (bool)
 *  - prune()                 delete rows older than the retention
 *  - sync_cron()             schedule/clear the daily prune (CRON_HOOK)
 *  - insert(), snapshot_texts(), count_rows(), find_by_consent_id(), get_rows()
 */
class TrackWP_Consent_Log {

    /** Daily prune cron hook. */
    const CRON_HOOK = 'trackwp_prune_consent_log';

    /**
     * Single-event hook for continuing a 'partial' migration. trackwp.php (W8)
     * registers it; uninstall.php clears it.
     */
    const MIGRATE_HOOK = 'trackwp_migrate_consent_log';

    /** Option holding the 1.10.0 log. */
    const LEGACY_OPTION = 'trackwp_consent_log';

    /** MySQL named lock for the migration. */
    const MIGRATION_LOCK = 'trackwp_consent_log_migrate';

    /** Rows per INSERT during migration. */
    const MIGRATION_CHUNK = 500;

    /** Default migration time budget in seconds (filter trackwp_consent_log_migration_budget). */
    const MIGRATION_BUDGET = 20;

    /** Retention defaults (months). */
    const DEFAULT_RETENTION_MONTHS = 24;
    const MIN_RETENTION_MONTHS     = 6;
    const MAX_RETENTION_MONTHS     = 60;

    /** Allowed event types. */
    const EVENT_TYPES = array('set', 'update', 'withdraw');

    /** Per-request table_exists() cache. */
    private static $exists = array();

    /**
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'trackwp_consent_log';
    }

    /**
     * @return string
     */
    public static function texts_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'trackwp_consent_texts';
    }

    /**
     * Create or upgrade both tables. Safe to call repeatedly.
     *
     * @return bool True when both tables exist afterwards.
     */
    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $log     = self::table_name();
        $texts   = self::texts_table_name();
        $collate = $wpdb->get_charset_collate();

        // dbDelta is whitespace- and case-sensitive: two spaces after PRIMARY
        // KEY, one field per line, lowercase KEY names.
        dbDelta("CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  consent_id varchar(36) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  event_type varchar(10) NOT NULL DEFAULT 'set',
  statistics tinyint(1) NOT NULL DEFAULT 0,
  marketing tinyint(1) NOT NULL DEFAULT 0,
  personalisation tinyint(1) NOT NULL DEFAULT 0,
  consent_version int(10) unsigned NOT NULL DEFAULT 0,
  server_consent_version int(10) unsigned NOT NULL DEFAULT 0,
  banner_hash varchar(64) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  user_agent varchar(500) NOT NULL DEFAULT '',
  page_url varchar(2048) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT 'client',
  migration_key char(36) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY migration_key (migration_key),
  KEY consent_id (consent_id),
  KEY created_at (created_at)
) {$collate};");

        dbDelta("CREATE TABLE {$texts} (
  hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  content longtext NOT NULL,
  PRIMARY KEY  (hash)
) {$collate};");

        self::$exists = array();
        return self::table_exists() && self::table_exists($texts);
    }

    /**
     * Does a table exist? Cached per request.
     *
     * @param string|null $table Defaults to the log table.
     * @return bool
     */
    public static function table_exists($table = null) {
        global $wpdb;
        $table = null === $table ? self::table_name() : $table;
        if (!isset(self::$exists[ $table ])) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            self::$exists[ $table ] = ($found === $table);
        }
        return self::$exists[ $table ];
    }

    /**
     * Forget cached table_exists() results.
     *
     * @return void
     */
    public static function flush_cache() {
        self::$exists = array();
    }

    /**
     * Drop both tables (uninstall).
     *
     * @return void
     */
    public static function drop_tables() {
        global $wpdb;
        // Names are built from $wpdb->prefix and constants; no user input.
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table_name());
        $wpdb->query('DROP TABLE IF EXISTS ' . self::texts_table_name());
        self::$exists = array();
    }

    /**
     * Configured retention in months, clamped to 6-60 (default 24).
     *
     * @return int
     */
    public static function retention_months() {
        $consent = get_option('trackwp_consent', array());
        $months  = isset($consent['consent_log_retention_months'])
            ? (int) $consent['consent_log_retention_months']
            : self::DEFAULT_RETENTION_MONTHS;
        return max(self::MIN_RETENTION_MONTHS, min(self::MAX_RETENTION_MONTHS, $months));
    }

    /**
     * Insert one consent action.
     *
     * @param array $row Keys: consent_id, event_type, statistics, marketing,
     *                   personalisation, consent_version, server_consent_version,
     *                   banner_hash, ip_hash, user_agent, page_url, source,
     *                   created_at (optional, 'Y-m-d H:i:s' UTC).
     * @return int|false Insert ID, or false.
     */
    public static function insert($row) {
        if (!self::table_exists()) {
            return false;
        }
        global $wpdb;
        $data = self::normalize_row($row);
        unset($data['migration_key']);
        $ok = $wpdb->insert(
            self::table_name(),
            $data,
            array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Store the banner content for a banner_hash once. Existing snapshots are
     * never overwritten (INSERT IGNORE on the primary key).
     *
     * @param string $hash    64 hex chars.
     * @param string $content Serialized banner content (JSON).
     * @return bool True when a new snapshot was written.
     */
    public static function snapshot_texts($hash, $content) {
        $hash = strtolower((string) $hash);
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !self::table_exists(self::texts_table_name())) {
            return false;
        }
        global $wpdb;
        $table = self::texts_table_name();
        $res   = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (hash, created_at, content) VALUES (%s, %s, %s)",
            $hash,
            gmdate('Y-m-d H:i:s'),
            (string) $content
        ));
        return 1 === $res;
    }

    /**
     * Snapshot content for a hash, or null.
     *
     * @param string $hash Hash.
     * @return string|null
     */
    public static function get_texts($hash) {
        if (!self::table_exists(self::texts_table_name())) {
            return null;
        }
        global $wpdb;
        $table = self::texts_table_name();
        $val   = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$table} WHERE hash = %s", strtolower((string) $hash)));
        return null === $val ? null : (string) $val;
    }

    /**
     * Total number of rows.
     *
     * @return int
     */
    public static function count_rows() {
        if (!self::table_exists()) {
            return 0;
        }
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * All rows for one consent_id, oldest first.
     *
     * @param string $consent_id Consent ID.
     * @return array
     */
    public static function find_by_consent_id($consent_id) {
        if (!self::table_exists()) {
            return array();
        }
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE consent_id = %s ORDER BY created_at ASC, id ASC",
            (string) $consent_id
        ), ARRAY_A);
    }

    /**
     * Rows newest first, for listing and CSV export.
     *
     * @param int $offset Offset.
     * @param int $limit  Limit (1-5000).
     * @return array
     */
    public static function get_rows($offset = 0, $limit = 100) {
        if (!self::table_exists()) {
            return array();
        }
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            max(1, min(5000, (int) $limit)),
            max(0, (int) $offset)
        ), ARRAY_A);
    }

    /**
     * Delete rows older than the retention, in batches.
     *
     * @return int Rows deleted.
     */
    public static function prune() {
        if (!self::table_exists()) {
            return 0;
        }
        global $wpdb;
        $table   = self::table_name();
        $cutoff  = gmdate('Y-m-d H:i:s', strtotime('-' . self::retention_months() . ' months', time()));
        $deleted = 0;
        do {
            $n = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", $cutoff));
            $n = (false === $n) ? 0 : (int) $n;
            $deleted += $n;
        } while (5000 === $n);
        return $deleted;
    }

    /**
     * Schedule the daily prune. Runs regardless of log_consent, because rows
     * logged earlier still have to expire.
     *
     * @return void
     */
    public static function sync_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /** Status of the last migration run in this request (see run_migration()). */
    private static $last_migration_status = '';

    /**
     * Migrate the 1.10.0 option into the table.
     *
     * @param int|null $budget Seconds; defaults to the filtered MIGRATION_BUDGET.
     * @return bool True when finished and the option is gone (also when there
     *              was nothing to migrate). False otherwise; W8 then schedules
     *              MIGRATE_HOOK to try again. The reason is in
     *              last_migration_status().
     */
    public static function migrate_legacy_option($budget = null) {
        self::$last_migration_status = self::run_migration($budget);
        return in_array(self::$last_migration_status, array('done', 'nothing'), true);
    }

    /**
     * Status string of the last migrate_legacy_option() call.
     *
     * @return string
     */
    public static function last_migration_status() {
        return self::$last_migration_status;
    }

    /**
     * Migration worker.
     *
     * 1. GET_LOCK(name, 0); without the lock nothing happens ('locked').
     * 2. migration_key = the entry's consent_id (UUID), else
     *    md5(serialize(entry)) formatted as 8-4-4-4-12 (36 chars).
     * 3. INSERT IGNORE in chunks of 500 within a time budget ('partial' when
     *    the budget runs out; a rerun is safe because of the unique key).
     * 4. The option is deleted only when COUNT(rows with those keys) is at
     *    least the number of distinct keys among the valid entries.
     * 5. RELEASE_LOCK.
     *
     * @param int|null $budget Seconds; defaults to the filtered MIGRATION_BUDGET.
     * @return string 'nothing' | 'done' | 'partial' | 'locked' | 'no_table' | 'unverified' | 'error'
     */
    public static function run_migration($budget = null) {
        global $wpdb;

        $raw = get_option(self::LEGACY_OPTION, null);
        if (null === $raw) {
            return 'nothing';
        }
        if (!self::table_exists()) {
            return 'no_table';
        }

        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::MIGRATION_LOCK));
        if ('1' !== (string) $got) {
            return 'locked';
        }

        try {
            $budget = null === $budget
                ? (int) apply_filters('trackwp_consent_log_migration_budget', self::MIGRATION_BUDGET)
                : (int) $budget;
            $deadline = microtime(true) + max(1, $budget);

            $rows = array();
            if (is_array($raw)) {
                foreach ($raw as $entry) {
                    if (is_array($entry)) {
                        $rows[] = self::legacy_entry_to_row($entry);
                    }
                }
            }

            $keys = array();
            foreach ($rows as $row) {
                $keys[ $row['migration_key'] ] = true;
            }
            $keys = array_keys($keys);

            $table  = self::table_name();
            $chunks = array_chunk($rows, self::MIGRATION_CHUNK);
            foreach ($chunks as $chunk) {
                if (microtime(true) > $deadline) {
                    return 'partial';
                }
                $values = array();
                foreach ($chunk as $row) {
                    $values[] = $wpdb->prepare(
                        '(%s, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s)',
                        $row['consent_id'],
                        $row['created_at'],
                        $row['event_type'],
                        $row['statistics'],
                        $row['marketing'],
                        $row['personalisation'],
                        $row['consent_version'],
                        $row['server_consent_version'],
                        $row['banner_hash'],
                        $row['ip_hash'],
                        $row['user_agent'],
                        $row['page_url'],
                        $row['source'],
                        $row['migration_key']
                    );
                }
                $res = $wpdb->query(
                    "INSERT IGNORE INTO {$table} (consent_id, created_at, event_type, statistics, marketing, personalisation, consent_version, server_consent_version, banner_hash, ip_hash, user_agent, page_url, source, migration_key) VALUES "
                    . implode(', ', $values)
                );
                if (false === $res) {
                    return 'error';
                }
            }

            // Verified count before the option is removed.
            $found = 0;
            foreach (array_chunk($keys, self::MIGRATION_CHUNK) as $key_chunk) {
                $placeholders = implode(', ', array_fill(0, count($key_chunk), '%s'));
                $found += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE migration_key IN ({$placeholders})",
                    $key_chunk
                ));
            }
            if ($found < count($keys)) {
                return 'unverified';
            }

            delete_option(self::LEGACY_OPTION);
            return 'done';
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::MIGRATION_LOCK));
        }
    }

    /**
     * Stable migration key for a 1.10.0 entry.
     *
     * @param array $entry Legacy entry.
     * @return string 36 chars.
     */
    public static function migration_key($entry) {
        $id = isset($entry['consent_id']) ? strtolower((string) $entry['consent_id']) : '';
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $id)) {
            return $id;
        }
        $h = md5(serialize($entry));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /**
     * Map a 1.10.0 option entry (see append_consent_log_entry in 1.10.0) to a row.
     *
     * @param array $entry Legacy entry.
     * @return array Normalized row including migration_key.
     */
    public static function legacy_entry_to_row($entry) {
        $choices = array();
        if (isset($entry['consent_choices']) && is_string($entry['consent_choices'])) {
            $decoded = json_decode($entry['consent_choices'], true);
            $choices = is_array($decoded) ? $decoded : array();
        }
        $flag = function ($key) use ($entry, $choices) {
            if (array_key_exists($key, $choices)) {
                return !empty($choices[ $key ]);
            }
            return !empty($entry[ $key ]);
        };

        $ts      = isset($entry['timestamp']) ? strtotime((string) $entry['timestamp']) : false;
        $version = isset($entry['consent_version']) ? (int) $entry['consent_version'] : 0;

        $row = self::normalize_row(array(
            'consent_id'             => isset($entry['consent_id']) ? (string) $entry['consent_id'] : '',
            'created_at'             => false !== $ts ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s', 0),
            'event_type'             => isset($entry['event_type']) ? (string) $entry['event_type'] : 'set',
            'statistics'             => $flag('statistics'),
            'marketing'              => $flag('marketing'),
            'personalisation'        => $flag('personalisation'),
            'consent_version'        => $version,
            'server_consent_version' => $version,
            'banner_hash'            => '',
            'ip_hash'                => isset($entry['ip_hash']) ? (string) $entry['ip_hash'] : '',
            'user_agent'             => isset($entry['user_agent']) ? (string) $entry['user_agent'] : '',
            'page_url'               => isset($entry['page_url']) ? (string) $entry['page_url'] : '',
            'source'                 => 'migration',
        ));
        $row['migration_key'] = self::migration_key($entry);
        return $row;
    }

    /**
     * Clean a page URL for storage (K6). Uses TrackWP_Privacy::clean_url()
     * when available; otherwise keeps only scheme, host and path.
     *
     * @param string $url URL.
     * @return string
     */
    public static function clean_page_url($url) {
        $url = (string) $url;
        if ('' === $url) {
            return '';
        }
        if (class_exists('TrackWP_Privacy') && method_exists('TrackWP_Privacy', 'clean_url')) {
            $url = (string) TrackWP_Privacy::clean_url($url);
        } else {
            $parts = wp_parse_url($url);
            if (!is_array($parts)) {
                return '';
            }
            $url = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
                . (isset($parts['host']) ? $parts['host'] : '')
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . (isset($parts['path']) ? $parts['path'] : '');
        }
        return substr(esc_url_raw($url), 0, 2048);
    }

    /**
     * Normalize a row to column types and limits.
     *
     * @param array $row Raw row.
     * @return array
     */
    private static function normalize_row($row) {
        $event_type = isset($row['event_type']) ? (string) $row['event_type'] : 'set';
        if (!in_array($event_type, self::EVENT_TYPES, true)) {
            $event_type = 'set';
        }
        $banner_hash = isset($row['banner_hash']) ? strtolower((string) $row['banner_hash']) : '';
        if (!preg_match('/^[a-f0-9]{0,64}$/', $banner_hash)) {
            $banner_hash = '';
        }
        $ip_hash = isset($row['ip_hash']) ? strtolower((string) $row['ip_hash']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $ip_hash)) {
            $ip_hash = '';
        }
        $source = isset($row['source']) ? sanitize_key((string) $row['source']) : 'client';

        return array(
            'consent_id'             => substr(preg_replace('/[^A-Za-z0-9\-]/', '', isset($row['consent_id']) ? (string) $row['consent_id'] : ''), 0, 36),
            'created_at'             => isset($row['created_at']) ? (string) $row['created_at'] : gmdate('Y-m-d H:i:s'),
            'event_type'             => $event_type,
            'statistics'             => !empty($row['statistics']) ? 1 : 0,
            'marketing'              => !empty($row['marketing']) ? 1 : 0,
            'personalisation'        => !empty($row['personalisation']) ? 1 : 0,
            'consent_version'        => max(0, isset($row['consent_version']) ? (int) $row['consent_version'] : 0),
            'server_consent_version' => max(0, isset($row['server_consent_version']) ? (int) $row['server_consent_version'] : 0),
            'banner_hash'            => $banner_hash,
            'ip_hash'                => $ip_hash,
            'user_agent'             => substr(sanitize_text_field(isset($row['user_agent']) ? (string) $row['user_agent'] : ''), 0, 500),
            'page_url'               => self::clean_page_url(isset($row['page_url']) ? (string) $row['page_url'] : ''),
            'source'                 => substr('' !== $source ? $source : 'client', 0, 20),
            'migration_key'          => isset($row['migration_key']) ? (string) $row['migration_key'] : null,
        );
    }
}
