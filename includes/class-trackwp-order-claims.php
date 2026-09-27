<?php
/**
 * Atomic per-order claims (PLAN-1.10.1-v4 K5 and R9).
 *
 * A purchase must be forwarded to the platforms at most once per order, no
 * matter how many tabs, reloads or cached copies of the order-received page
 * send it. A check-then-set on order meta or an option cannot guarantee that:
 * two requests can both read "not sent" before either writes. The guarantee
 * here comes from the database instead -- a PRIMARY KEY (order_id, scope) and
 * INSERT IGNORE, where only the request whose INSERT actually added the row
 * (rows_affected === 1) owns the claim.
 *
 * add_option()/update_option() are deliberately NOT used: add_option() runs
 * INSERT ... ON DUPLICATE KEY UPDATE, so every caller "succeeds".
 *
 * The scope column exists so 1.11.0 can claim per destination
 * (scope = ga4 | meta | google_ads) in the same table without a migration.
 *
 * @since 1.10.1
 * @package TrackWP
 */

defined('ABSPATH') || exit;

class TrackWP_Order_Claims {

    /** Table name without the $wpdb prefix. */
    const TABLE = 'trackwp_order_claims';

    /** Option holding the installed schema version. */
    const DB_VERSION_OPTION = 'trackwp_order_claims_db';

    /** Current schema version. */
    const DB_VERSION = '1';

    /** claim() outcomes. */
    const CLAIMED   = 'claimed';
    const DUPLICATE = 'duplicate';
    const ERROR     = 'error';

    /**
     * Fully prefixed table name.
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create or upgrade the table. Idempotent (dbDelta).
     *
     * Called by the plugin's activation and 1.10.1 upgrade routine, and by
     * maybe_install() when the stored schema version is behind.
     *
     * @return bool Whether the table exists afterwards.
     */
    public static function create_table() {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // dbDelta is whitespace sensitive: two spaces after PRIMARY KEY, one
        // field per line.
        $sql = "CREATE TABLE {$table} (
  order_id bigint(20) unsigned NOT NULL,
  scope varchar(20) NOT NULL,
  claimed_at datetime NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (order_id,scope),
  KEY claimed_at (claimed_at)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $exists = self::table_exists();
        if ( $exists ) {
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
        }
        return $exists;
    }

    /**
     * Install when the stored schema version is behind. Cheap enough for
     * admin_init: one autoloaded-off option read.
     *
     * @return void
     */
    public static function maybe_install() {
        if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Does the table exist?
     *
     * @return bool
     */
    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return $found === $table;
    }

    /**
     * Atomically claim an order for a scope.
     *
     * Three outcomes (R9):
     * - 'claimed'   this request inserted the row and owns the send
     * - 'duplicate' the row already existed, the query ran cleanly
     * - 'error'     anything else (missing table, DB error); the caller must
     *               report failed/claim_error and send nothing
     *
     * Suppressing DB errors keeps a missing table from printing SQL to a
     * public REST response; last_error is still populated and inspected.
     *
     * @param int    $order_id Order id (> 0).
     * @param string $scope    Claim scope, 'purchase' in 1.10.1.
     * @param string $source   Who claimed: 'browser' in 1.10.1.
     * @return string One of the class constants CLAIMED, DUPLICATE, ERROR.
     */
    public static function claim( $order_id, $scope = 'purchase', $source = 'browser' ) {
        global $wpdb;

        $order_id = (int) $order_id;
        $scope    = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $scope ) ), 0, 20 );
        $source   = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $source ) ), 0, 20 );
        if ( $order_id <= 0 || '' === $scope ) {
            return self::ERROR;
        }

        $table      = self::table_name();
        $suppressed = $wpdb->suppress_errors( true );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a constant.
        $result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (order_id, scope, claimed_at, source) VALUES (%d, %s, UTC_TIMESTAMP(), %s)", $order_id, $scope, $source ) );

        $error    = (string) $wpdb->last_error;
        $affected = (int) $wpdb->rows_affected;
        $wpdb->suppress_errors( $suppressed );

        if ( false !== $result && 1 === $affected && '' === $error ) {
            return self::CLAIMED;
        }
        if ( false !== $result && 0 === $affected && '' === $error ) {
            return self::DUPLICATE;
        }
        return self::ERROR;
    }

    /**
     * Has the order been claimed for a scope? Read-only; never use this to
     * decide whether to send -- only claim() is atomic.
     *
     * @param int    $order_id Order id.
     * @param string $scope    Scope.
     * @return bool
     */
    public static function is_claimed( $order_id, $scope = 'purchase' ) {
        global $wpdb;
        $table      = self::table_name();
        $suppressed = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a constant.
        $found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE order_id = %d AND scope = %s", (int) $order_id, (string) $scope ) );
        $wpdb->suppress_errors( $suppressed );
        return '1' === (string) $found;
    }
}
