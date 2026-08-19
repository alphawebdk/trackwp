<?php
/**
 * Delivery log — records WHETHER an event reached each platform, never WHO sent it.
 *
 * Purpose is diagnostics and delivery verification: showing the site owner
 * which events fired, proving they were dispatched, spotting failures, and
 * catching double-counting (two rows with the same event_name in the same
 * minute but different event_ids).
 *
 * Two kinds of row are written per event:
 *   destination 'received'          — exactly one per incoming event
 *   destination ga4|meta|google_ads — one per forwarding attempt
 * The 'received' row is what makes the log answer "what fired?" rather than
 * only "what did we forward?" — without it the log looks empty on a site with
 * no platform configured, or in client_only mode, while events arrive normally.
 * It is also why the overview counts 'received' for the headline number: summing
 * the platform rows would multiply an event by the number of destinations.
 *
 * Deliberately NOT an analytics or identity store. The following are never
 * written here, because they would turn the log into a second personal-data
 * archive requiring its own legal basis, retention policy and DSAR handling:
 * IP, user agent, page URL / query strings, form content, email or phone
 * (hashed or not), client_id, gclid, _fbp / _fbc.
 *
 * What IS stored is pseudonymous delivery metadata with a short retention
 * (7 days by default, 30 max). The event_id is our own random per-event token —
 * it identifies an interaction, not a person, and is the field that makes
 * duplicate detection possible.
 *
 * @package TrackWP
 * @since 1.9.0
 */

defined('ABSPATH') || exit;

class TrackWP_Delivery_Log {

    /** Table name without the WPDB prefix. */
    const TABLE = 'trackwp_delivery_log';

    /** Default retention in days. */
    const DEFAULT_RETENTION_DAYS = 7;

    /** Hard upper bound on retention — this is a diagnostic log, not an archive. */
    const MAX_RETENTION_DAYS = 30;

    /** Cron hook that prunes expired rows. */
    const CRON_HOOK = 'trackwp_prune_delivery_log';

    /**
     * Fully-qualified table name.
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Is delivery logging switched on? Off by default — enabling it creates a
     * data store, so it must be a deliberate choice.
     *
     * @return bool
     */
    public static function is_enabled() {
        $advanced = get_option('trackwp_advanced', array());
        return ! empty($advanced['delivery_log_enabled']);
    }

    /**
     * Configured retention, clamped to [1, MAX_RETENTION_DAYS].
     *
     * @return int
     */
    public static function retention_days() {
        $advanced = get_option('trackwp_advanced', array());
        $days = isset($advanced['delivery_log_retention_days'])
            ? (int) $advanced['delivery_log_retention_days']
            : self::DEFAULT_RETENTION_DAYS;
        return max(1, min(self::MAX_RETENTION_DAYS, $days));
    }

    /**
     * Create or upgrade the table. Safe to call repeatedly (dbDelta).
     *
     * @return void
     */
    public static function create_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table_name();
        $collate = $wpdb->get_charset_collate();

        // dbDelta is whitespace- and case-sensitive: two spaces after PRIMARY
        // KEY, one field per line, KEY names lowercase.
        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  logged_at datetime NOT NULL,
  event_id varchar(64) NOT NULL DEFAULT '',
  event_name varchar(64) NOT NULL DEFAULT '',
  destination varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  http_code smallint(5) NOT NULL DEFAULT 0,
  consent_analytics tinyint(1) NOT NULL DEFAULT 0,
  consent_marketing tinyint(1) NOT NULL DEFAULT 0,
  consent_version smallint(5) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY logged_at (logged_at),
  KEY event_name (event_name),
  KEY event_id (event_id)
) {$collate};";

        dbDelta($sql);
        self::$table_exists = null; // re-check on next use
    }

    /**
     * Does the table exist? Cached per request.
     *
     * The option can be on while the table is missing (restored database,
     * manual drop, a failed dbDelta), so every read/write guards on this
     * rather than letting wp-admin throw database errors.
     *
     * @return bool
     */
    public static function table_exists() {
        if ( self::$table_exists !== null ) {
            return self::$table_exists;
        }
        global $wpdb;
        $table = self::table_name();
        $found = $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $table) );
        self::$table_exists = ( $found === $table );
        return self::$table_exists;
    }

    /** Per-request cache for table_exists(); null = not yet checked. */
    private static $table_exists = null;

    /**
     * Drop the table (uninstall).
     *
     * @return void
     */
    public static function drop_table() {
        global $wpdb;
        $table = self::table_name();
        // Table name is built from $wpdb->prefix + a class constant — no user input.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        self::$table_exists = null;
    }

    /**
     * Record one dispatch attempt.
     *
     * Silently does nothing when logging is off, so callers can invoke it
     * unconditionally on the hot path.
     *
     * @param string $event_id    Our per-event random id (evt_…).
     * @param string $event_name  Configured event name.
     * @param string $destination One of: received, ga4, meta, google_ads.
     * @param string $status      One of: ok, failed, unknown, skipped.
     * @param array  $consent     Keys: analytics, marketing (booleans).
     * @param int    $http_code   Optional upstream HTTP status.
     * @return void
     */
    public static function record($event_id, $event_name, $destination, $status, $consent = array(), $http_code = 0) {
        if ( ! self::is_enabled() || ! self::table_exists() ) {
            return;
        }

        // 'received' is not a platform: it is one row per incoming event, so the
        // log answers "what fired?" and not only "what did we forward?".
        // Without it the log looks empty on a site with no platform configured,
        // or in client_only mode, even while events are arriving normally.
        $allowed_destinations = array('received', 'ga4', 'meta', 'google_ads');
        $allowed_statuses     = array('ok', 'failed', 'unknown', 'skipped');
        if ( ! in_array($destination, $allowed_destinations, true) ) {
            return;
        }
        if ( ! in_array($status, $allowed_statuses, true) ) {
            $status = 'unknown';
        }

        $consent_cfg = get_option('trackwp_consent', array());

        global $wpdb;
        // Timestamp is rounded down to the minute: full precision is not needed
        // to spot a duplicate (two rows, same minute, same event name, different
        // event_id) and coarser timestamps are harder to correlate with an
        // individual visitor's session.
        $wpdb->insert(
            self::table_name(),
            array(
                'logged_at'         => gmdate('Y-m-d H:i:00'),
                'event_id'          => substr((string) $event_id, 0, 64),
                'event_name'        => substr(sanitize_key((string) $event_name), 0, 64),
                'destination'       => $destination,
                'status'            => $status,
                'http_code'         => (int) $http_code,
                'consent_analytics' => ! empty($consent['analytics']) ? 1 : 0,
                'consent_marketing' => ! empty($consent['marketing']) ? 1 : 0,
                'consent_version'   => isset($consent_cfg['consent_version']) ? (int) $consent_cfg['consent_version'] : 0,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d')
        );
    }

    /**
     * Delete rows older than the configured retention.
     *
     * @return int Rows removed.
     */
    public static function prune() {
        if ( ! self::table_exists() ) {
            return 0;
        }
        global $wpdb;
        $table  = self::table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::retention_days() * DAY_IN_SECONDS));
        $rows   = $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE logged_at < %s", $cutoff) );
        return is_numeric($rows) ? (int) $rows : 0;
    }

    /**
     * Empty the log completely.
     *
     * @return void
     */
    public static function clear() {
        if ( ! self::table_exists() ) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * Most recent rows, newest first (admin display).
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent($limit = 50) {
        if ( ! self::table_exists() ) {
            return array();
        }
        global $wpdb;
        $table = self::table_name();
        $limit = max(1, min(500, (int) $limit));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * Per-destination delivery summary over the retention window.
     *
     * @return array destination => array(status => count)
     */
    public static function summary() {
        if ( ! self::table_exists() ) {
            return array();
        }
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results(
            "SELECT destination, status, COUNT(*) AS n FROM {$table} GROUP BY destination, status",
            ARRAY_A
        );
        $out = array();
        if ( is_array($rows) ) {
            foreach ( $rows as $row ) {
                $out[ $row['destination'] ][ $row['status'] ] = (int) $row['n'];
            }
        }
        return $out;
    }

    /**
     * "What has actually been sent?" — one row per event name, with a
     * per-destination delivered/failed breakdown and when it was last seen.
     * This is the site owner's overview, not a debugging dump.
     *
     * Counts DISTINCT event_id per event so one visitor interaction counts
     * once per destination even if it produced several rows.
     *
     * @return array
     */
    public static function overview() {
        if ( ! self::table_exists() ) {
            return array();
        }
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results(
            "SELECT event_name, destination, status,
                    COUNT(DISTINCT event_id) AS hits,
                    MAX(logged_at) AS last_seen
             FROM {$table}
             GROUP BY event_name, destination, status
             ORDER BY event_name ASC",
            ARRAY_A
        );
        if ( ! is_array($rows) ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $row ) {
            $name = $row['event_name'];
            if ( ! isset($out[ $name ]) ) {
                $out[ $name ] = array(
                    'event_name'   => $name,
                    'received'     => 0,
                    'delivered'    => 0,
                    'last_seen'    => '',
                    'destinations' => array(),
                );
            }
            $dest   = $row['destination'];
            $hits   = (int) $row['hits'];
            $status = $row['status'];

            if ( $dest === 'received' ) {
                // The headline count: one row per incoming event. Summing the
                // per-platform rows instead would multiply an event by the
                // number of platforms it was forwarded to.
                if ( $status === 'ok' ) {
                    $out[ $name ]['received'] += $hits;
                }
            } else {
                if ( ! isset($out[ $name ]['destinations'][ $dest ]) ) {
                    $out[ $name ]['destinations'][ $dest ] = array('ok' => 0, 'failed' => 0, 'skipped' => 0, 'unknown' => 0);
                }
                $bucket = isset($out[ $name ]['destinations'][ $dest ][ $status ]) ? $status : 'unknown';
                $out[ $name ]['destinations'][ $dest ][ $bucket ] += $hits;
                if ( $status === 'ok' ) {
                    $out[ $name ]['delivered'] += $hits;
                }
            }

            if ( $row['last_seen'] > $out[ $name ]['last_seen'] ) {
                $out[ $name ]['last_seen'] = $row['last_seen'];
            }
        }

        // Busiest event first. Fall back to delivered for logs written before
        // 'received' rows existed, so old data still sorts sensibly.
        uasort( $out, function( $a, $b ) {
            $an = $a['received'] ? $a['received'] : $a['delivered'];
            $bn = $b['received'] ? $b['received'] : $b['delivered'];
            return $bn <=> $an;
        } );

        return $out;
    }

    /**
     * Events per day across the retention window (oldest first), for a
     * simple bar chart. Counts distinct event_ids so multi-destination
     * dispatch is not counted several times.
     *
     * @return array List of array('date' => Y-m-d, 'events' => int)
     */
    public static function per_day() {
        $days = self::retention_days();
        $out  = array();
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $out[ gmdate('Y-m-d', time() - ($i * DAY_IN_SECONDS)) ] = 0;
        }

        if ( ! self::table_exists() ) {
            return array_map(
                function( $date, $n ) { return array('date' => $date, 'events' => $n); },
                array_keys($out),
                $out
            );
        }

        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results(
            "SELECT DATE(logged_at) AS d, COUNT(DISTINCT event_id) AS n
             FROM {$table} GROUP BY DATE(logged_at)",
            ARRAY_A
        );
        if ( is_array($rows) ) {
            foreach ( $rows as $row ) {
                if ( isset($out[ $row['d'] ]) ) {
                    $out[ $row['d'] ] = (int) $row['n'];
                }
            }
        }

        return array_map(
            function( $date, $n ) { return array('date' => $date, 'events' => $n); },
            array_keys($out),
            $out
        );
    }

    /**
     * Event names that were logged more than once for the same destination
     * within the same minute — the signature of a double-fire.
     *
     * @param int $limit
     * @return array
     */
    public static function find_duplicates($limit = 20) {
        if ( ! self::table_exists() ) {
            return array();
        }
        global $wpdb;
        $table = self::table_name();
        $limit = max(1, min(100, (int) $limit));
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_name, destination, logged_at, COUNT(DISTINCT event_id) AS hits
                 FROM {$table}
                 GROUP BY event_name, destination, logged_at
                 HAVING hits > 1
                 ORDER BY logged_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * Total row count.
     *
     * @return int
     */
    public static function count() {
        if ( ! self::table_exists() ) {
            return 0;
        }
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Ensure the daily pruning cron is scheduled while logging is on, and
     * cleared when it is off.
     *
     * @return void
     */
    public static function sync_cron() {
        if ( self::is_enabled() ) {
            if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }
}
