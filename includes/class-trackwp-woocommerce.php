<?php
/**
 * WooCommerce integration for TrackWP.
 *
 * Full e-commerce event tracking planned for v1.5:
 * view_item, add_to_cart, begin_checkout, purchase,
 * with dynamic order values and Enhanced Conversions.
 *
 * @since 1.5.0
 * @package TrackWP
 */

defined('ABSPATH') || exit;

class TrackWP_WooCommerce {

    public function __construct() {
        // WooCommerce integration planned for v1.5.
    }

    /**
     * Check if WooCommerce is active.
     */
    public function is_available() {
        return class_exists('WooCommerce');
    }
}
