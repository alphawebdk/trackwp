<?php
/**
 * Server-side backup purchase and GA4 refunds for WooCommerce.
 *
 * When an order reaches a paid status and the customer never reaches the
 * order-received page (for example MobilePay in the app), the purchase is sent
 * from the server a few minutes later, based on the consent and identifiers
 * saved at checkout (TrackWP_WooCommerce::save_attribution). The order-received
 * page and the backup share one atomic claim per order (TrackWP_Order_Claims,
 * scope "purchase"), and the page leaves a "browser_seen" marker so the backup
 * never sends for an order the browser already handled.
 *
 * Gateway callbacks and status hooks only SCHEDULE (no HTTP). The jobs read
 * nothing from the current request: no cookies, no REMOTE_ADDR, no current
 * user. Everything comes from the order and its snapshot.
 *
 * @package TrackWP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TrackWP_Purchase_Backup {

    const HOOK_PURCHASE  = 'trackwp_backup_purchase';
    const HOOK_REFUND    = 'trackwp_ga4_refund';
    const GROUP          = 'trackwp';
    const DEFAULT_DELAY  = 180;
    const REFUND_DELAY   = 10;
    /** GA4 Measurement Protocol accepts events up to 72 hours old; 71 h leaves margin. */
    const GA4_MAX_AGE    = 255600;
    /** A GA4 session id is only reused when the order was placed within this many seconds. */
    const SESSION_WINDOW = 1800;

    /**
     * Register the WooCommerce hooks. Nothing when WooCommerce is missing or
     * the shop integration is off. The job callbacks themselves are registered
     * in trackwp.php so they exist in Action Scheduler and cron requests.
     *
     * @return void
     */
    public static function register_hooks() {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TrackWP_WooCommerce' ) ) {
            return;
        }
        $settings = TrackWP_WooCommerce::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 10, 1 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refunded' ), 10, 2 );
    }

    /**
     * Paid statuses without the wc- prefix.
     *
     * @param WC_Order $order Order.
     * @return string[]
     */
    public static function backup_statuses( $order ) {
        $statuses = apply_filters( 'trackwp_backup_purchase_statuses', wc_get_is_paid_statuses(), $order );
        $out      = array();
        foreach ( (array) $statuses as $status ) {
            $status = (string) $status;
            if ( 0 === strpos( $status, 'wc-' ) ) {
                $status = substr( $status, 3 );
            }
            if ( '' !== $status ) {
                $out[] = $status;
            }
        }
        return $out;
    }

    /**
     * @param int      $order_id Order id.
     * @param string   $from     Old status.
     * @param string   $to       New status.
     * @param WC_Order $order    Order.
     * @return void
     */
    public static function on_status_changed( $order_id, $from, $to, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $statuses = self::backup_statuses( $order );
        $to       = 0 === strpos( (string) $to, 'wc-' ) ? substr( (string) $to, 3 ) : (string) $to;
        $from     = 0 === strpos( (string) $from, 'wc-' ) ? substr( (string) $from, 3 ) : (string) $from;
        if ( ! in_array( $to, $statuses, true ) || in_array( $from, $statuses, true ) ) {
            return;
        }
        self::maybe_schedule( $order, true );
    }

    /**
     * @param int $order_id Order id.
     * @return void
     */
    public static function on_payment_complete( $order_id ) {
        self::maybe_schedule( wc_get_order( $order_id ), false );
    }

    /**
     * Whether the integration and the purchase event are on for this order.
     *
     * @param mixed $order Order.
     * @return bool
     */
    private static function purchase_enabled( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        $settings = TrackWP_WooCommerce::get_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['event_purchase'] ) ) {
            return false;
        }
        return (bool) apply_filters( 'trackwp_backup_purchase_enabled', true, $order );
    }

    /**
     * Queue the backup job. No HTTP here.
     *
     * @param mixed $order    Order.
     * @param bool  $log_skip Log no_snapshot when the order has no usable snapshot.
     * @return void
     */
    public static function maybe_schedule( $order, $log_skip ) {
        if ( ! self::purchase_enabled( $order ) ) {
            return;
        }
        if ( ! in_array( (string) $order->get_status(), self::backup_statuses( $order ), true ) ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( null === TrackWP_WooCommerce::valid_snapshot( $order ) ) {
            if ( $log_skip ) {
                TrackWP_Delivery_Log::record( TrackWP_WooCommerce::event_id_for( $order_id ), 'purchase', 'received', 'skipped', array(), 0, 'no_snapshot' );
            }
            return;
        }

        $delay = (int) apply_filters( 'trackwp_backup_purchase_delay', self::DEFAULT_DELAY, $order );
        $delay = max( 30, min( 1800, $delay ) );
        self::schedule( self::HOOK_PURCHASE, array( $order_id ), time() + $delay );
    }

    /**
     * Schedule one single job once (Action Scheduler, else WP-Cron).
     *
     * @param string $hook Hook.
     * @param array  $args Arguments.
     * @param int    $when Unix time.
     * @return void
     */
    private static function schedule( $hook, array $args, $when ) {
        if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
            if ( ! as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
                as_schedule_single_action( $when, $hook, $args, self::GROUP );
            }
            return;
        }
        if ( false === wp_next_scheduled( $hook, $args ) ) {
            wp_schedule_single_event( $when, $hook, $args );
        }
    }

    /**
     * @param int $order_id  Order id.
     * @param int $refund_id Refund id.
     * @return void
     */
    public static function on_refunded( $order_id, $refund_id ) {
        $order = wc_get_order( $order_id );
        if ( ! self::purchase_enabled( $order ) ) {
            return;
        }
        if ( ! apply_filters( 'trackwp_ga4_refunds_enabled', true, $order ) ) {
            return;
        }
        self::schedule( self::HOOK_REFUND, array( (int) $order_id, (int) $refund_id ), time() + self::REFUND_DELAY );
    }

    /**
     * Consent as it stands now: the snapshot, narrowed by any later change or
     * withdrawal recorded in the consent log (when the log is in use).
     *
     * @param array $snap Valid snapshot.
     * @return array analytics (bool), marketing (bool), v (int).
     */
    public static function effective_consent( array $snap ) {
        $consent   = isset( $snap['consent'] ) && is_array( $snap['consent'] ) ? $snap['consent'] : array();
        $analytics = isset( $consent['analytics'] ) && true === $consent['analytics'];
        $marketing = isset( $consent['marketing'] ) && true === $consent['marketing'];

        $consent_id = isset( $consent['id'] ) && is_string( $consent['id'] ) ? $consent['id'] : '';
        if ( '' !== $consent_id && class_exists( 'TrackWP_Consent_Log' ) && TrackWP_Consent_Log::table_exists() ) {
            $captured = isset( $snap['captured_at'] ) ? strtotime( (string) $snap['captured_at'] ) : false;
            if ( false !== $captured ) {
                $latest = null;
                foreach ( TrackWP_Consent_Log::find_by_consent_id( $consent_id ) as $row ) {
                    $created = strtotime( (string) $row['created_at'] . ' UTC' );
                    if ( false !== $created && $created >= $captured ) {
                        $latest = $row;
                    }
                }
                if ( null !== $latest ) {
                    $analytics = $analytics && ! empty( $latest['statistics'] );
                    $marketing = $marketing && ! empty( $latest['marketing'] );
                }
            }
        }

        return array(
            'analytics' => $analytics,
            'marketing' => $marketing,
            'v'         => isset( $consent['v'] ) ? (int) $consent['v'] : 0,
        );
    }

    /**
     * GA4 user_id hash for the order's customer, same formula as the adapter.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    private static function user_id_hash( $order ) {
        $advanced    = get_option( 'trackwp_advanced', array() );
        $customer_id = (int) $order->get_customer_id();
        if ( is_array( $advanced ) && ! empty( $advanced['ga4_user_id_enabled'] ) && $customer_id > 0 ) {
            return hash( 'sha256', $customer_id . ':' . get_site_url() );
        }
        return '';
    }

    /**
     * Job: send the backup purchase for an order.
     *
     * @param int $order_id Order id.
     * @return void
     */
    public static function run_purchase( $order_id ) {
        $order_id = (int) $order_id;
        $order    = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! self::purchase_enabled( $order ) ) {
            return;
        }
        $event_id = TrackWP_WooCommerce::event_id_for( $order_id );
        $skip     = function ( $reason ) use ( $event_id ) {
            TrackWP_Delivery_Log::record( $event_id, 'purchase', 'received', 'skipped', array(), 0, $reason );
        };

        if ( ! in_array( (string) $order->get_status(), self::backup_statuses( $order ), true )
            || ! TrackWP_WooCommerce::is_countable_status( $order ) ) {
            $skip( 'order_not_countable' );
            return;
        }
        $snap = TrackWP_WooCommerce::valid_snapshot( $order );
        if ( null === $snap ) {
            $skip( 'no_snapshot' );
            return;
        }
        if ( TrackWP_Order_Claims::is_claimed( $order_id, 'browser_seen' ) ) {
            $skip( 'browser_seen' );
            return;
        }
        $eff = self::effective_consent( $snap );
        if ( ! $eff['analytics'] && ! $eff['marketing'] ) {
            $skip( 'no_consent' );
            return;
        }

        $paid       = $order->get_date_paid();
        $event_time = $paid ? (int) $paid->getTimestamp() : time();

        $analytics_ids = isset( $snap['analytics'] ) && is_array( $snap['analytics'] ) ? $snap['analytics'] : array();
        $pre_skips     = array();
        if ( $eff['analytics'] && empty( $analytics_ids['client_id'] ) ) {
            $pre_skips['ga4'] = 'no_client_id';
        } elseif ( time() - $event_time > self::GA4_MAX_AGE ) {
            $pre_skips['ga4'] = 'event_too_old';
        }

        $builder = new TrackWP_WooCommerce( false );
        $data    = $builder->build_purchase_data( $order, $eff['marketing'] && TrackWP_WooCommerce::customer_data_sharing() );
        unset( $data['order_ref'] );
        if ( isset( $data['ecommerce'] ) ) {
            $data['ecommerce'] = TrackWP_Proxy::sanitize_ecommerce( $data['ecommerce'] );
        }
        if ( ! empty( $data['enhanced'] ) && is_array( $data['enhanced'] ) ) {
            $data['enhanced'] = TrackWP_Hash::normalize_enhanced( TrackWP_Proxy::sanitize_enhanced( $data['enhanced'] ) );
        }

        $events_manager = new TrackWP_Events();
        $event_config   = $events_manager->find_event( 'purchase' );

        $event_data                    = $data;
        $event_data['event']           = 'purchase';
        $event_data['meta_event_name'] = ( is_array( $event_config ) && isset( $event_config['meta_event'] ) ) ? (string) $event_config['meta_event'] : '';
        $event_data['page_url']        = wc_get_checkout_url();
        $event_data['consent']         = array( 'analytics' => $eff['analytics'], 'marketing' => $eff['marketing'] );
        if ( ! empty( $snap['context']['ua'] ) && is_string( $snap['context']['ua'] ) ) {
            $event_data['user_agent'] = $snap['context']['ua'];
        }
        if ( $eff['analytics'] ) {
            if ( ! empty( $analytics_ids['client_id'] ) ) {
                $event_data['client_id'] = (string) $analytics_ids['client_id'];
            }
            $captured = isset( $snap['captured_at'] ) ? strtotime( (string) $snap['captured_at'] ) : false;
            if ( ! empty( $analytics_ids['session_id'] ) && false !== $captured
                && abs( $event_time - $captured ) <= self::SESSION_WINDOW ) {
                $event_data['session_id'] = (string) $analytics_ids['session_id'];
            }
        }
        if ( $eff['marketing'] && isset( $snap['marketing'] ) && is_array( $snap['marketing'] ) ) {
            foreach ( array( 'gclid', 'gbraid', 'wbraid', 'fbp', 'fbc' ) as $key ) {
                if ( ! empty( $snap['marketing'][ $key ] ) && is_string( $snap['marketing'][ $key ] ) ) {
                    $event_data[ $key ] = $snap['marketing'][ $key ];
                }
            }
        }

        $event_data = apply_filters( 'trackwp_event_data', $event_data, $eff );
        if ( ! is_array( $event_data ) ) {
            $skip( 'no_purchase' );
            return;
        }
        $event_data['event']    = 'purchase';
        $event_data['event_id'] = $event_id;
        $event_data['consent']  = array( 'analytics' => $eff['analytics'], 'marketing' => $eff['marketing'] );

        $event_data = TrackWP_Proxy::strip_by_category( $event_data, $eff );
        $event_data = TrackWP_Privacy::clean_event( $event_data );

        // Set last, so no filter or earlier step can read or replace it.
        $event_data['_server_ctx'] = array(
            'origin'       => 'backup',
            'event_time'   => $event_time,
            'consent_id'   => isset( $snap['consent']['id'] ) ? (string) $snap['consent']['id'] : '',
            'user_id_hash' => self::user_id_hash( $order ),
            // The customer's own checkout IP stored on the order, never the request IP.
            'customer_ip'  => (string) $order->get_customer_ip_address(),
        );

        ( new TrackWP_Proxy() )->dispatch_backup( $event_data, $eff, $order_id, $pre_skips );
    }

    /**
     * Job: send one GA4 refund (GA4 only; Meta and Google Ads are never called).
     *
     * @param int $order_id  Order id.
     * @param int $refund_id Refund id.
     * @return void
     */
    public static function run_refund( $order_id, $refund_id ) {
        $order_id  = (int) $order_id;
        $refund_id = (int) $refund_id;
        $order     = wc_get_order( $order_id );
        $refund    = wc_get_order( $refund_id );
        if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund || (int) $refund->get_parent_id() !== $order_id ) {
            return;
        }
        if ( ! self::purchase_enabled( $order ) || ! apply_filters( 'trackwp_ga4_refunds_enabled', true, $order ) ) {
            return;
        }
        $ga4 = new TrackWP_GA4();
        if ( ! $ga4->is_enabled() ) {
            return;
        }

        $event_id = TrackWP_WooCommerce::refund_event_id_for( $refund_id );
        $skip     = function ( $reason ) use ( $event_id ) {
            TrackWP_Delivery_Log::record( $event_id, 'refund', 'received', 'skipped', array(), 0, $reason );
        };

        $advanced = get_option( 'trackwp_advanced', array() );
        if ( is_array( $advanced ) && isset( $advanced['dedup_mode'] ) && 'client_only' === $advanced['dedup_mode'] ) {
            $skip( 'client_only' );
            return;
        }
        if ( ! TrackWP_Order_Claims::is_claimed( $order_id, 'purchase' ) && ! TrackWP_Order_Claims::is_claimed( $order_id, 'browser_seen' ) ) {
            $skip( 'no_purchase' );
            return;
        }
        // A refund only follows a purchase that was routed to GA4 (same rule as route()).
        $purchase_cfg = ( new TrackWP_Events() )->find_event( 'purchase' );
        $purchase_sto = ( is_array( $purchase_cfg ) && isset( $purchase_cfg['send_to'] ) && is_array( $purchase_cfg['send_to'] ) ) ? $purchase_cfg['send_to'] : null;
        if ( ( is_array( $purchase_cfg ) && empty( $purchase_cfg['enabled'] ) ) || ( null !== $purchase_sto && empty( $purchase_sto['ga4'] ) ) ) {
            $skip( 'no_purchase' );
            return;
        }
        $snap = TrackWP_WooCommerce::valid_snapshot( $order );
        if ( null === $snap ) {
            $skip( 'no_snapshot' );
            return;
        }
        $eff = self::effective_consent( $snap );
        if ( ! $eff['analytics'] ) {
            $skip( 'no_consent' );
            return;
        }
        if ( empty( $snap['analytics']['client_id'] ) ) {
            $skip( 'no_client_id' );
            return;
        }
        $created    = $refund->get_date_created();
        $event_time = $created ? (int) $created->getTimestamp() : time();
        if ( time() - $event_time > self::GA4_MAX_AGE ) {
            $skip( 'event_too_old' );
            return;
        }

        $claim = TrackWP_Order_Claims::claim( $refund_id, 'refund', 'server' );
        $flags = array( 'analytics' => true, 'marketing' => false );
        if ( TrackWP_Order_Claims::CLAIMED !== $claim ) {
            $is_dup = ( TrackWP_Order_Claims::DUPLICATE === $claim );
            TrackWP_Delivery_Log::record( $event_id, 'refund', 'ga4', $is_dup ? 'duplicate' : 'failed', $flags, 0, $is_dup ? 'already_claimed' : 'claim_error' );
            return;
        }

        $refund_data = ( new TrackWP_WooCommerce( false ) )->build_refund_data( $order, $refund );
        $event_data  = array(
            'event'       => 'refund',
            'event_id'    => $event_id,
            'value'       => $refund_data['value'],
            'currency'    => $refund_data['currency'],
            'ecommerce'   => TrackWP_Proxy::sanitize_ecommerce( $refund_data['ecommerce'] ),
            'client_id'   => (string) $snap['analytics']['client_id'],
            'page_url'    => wc_get_checkout_url(),
            'consent'     => $flags,
            '_server_ctx' => array(
                'origin'       => 'refund',
                'event_time'   => $event_time,
                'consent_id'   => isset( $snap['consent']['id'] ) ? (string) $snap['consent']['id'] : '',
                'user_id_hash' => self::user_id_hash( $order ),
            ),
        );

        $result = TrackWP_Proxy::normalize_result( 'ga4', $ga4->send_event( $event_data, TrackWP_Proxy::BACKUP_TIMEOUT ) );
        TrackWP_Delivery_Log::record( $event_id, 'refund', 'received', 'ok', $flags, 0, 'backup' );
        TrackWP_Delivery_Log::record( $event_id, 'refund', 'ga4', $result['status'], $flags, $result['http_code'], $result['reason'] );
    }
}
