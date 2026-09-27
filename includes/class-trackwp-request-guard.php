<?php
defined('ABSPATH') || exit;

/**
 * Shared guards for TrackWP's public REST endpoints.
 *
 * No nonces: the endpoints are called from pages that may be served from a
 * full-page cache, where nonces go stale. The origin check keeps other sites
 * from driving the endpoints from a visitor's browser; the rate limit caps
 * abuse per client IP.
 */
class TrackWP_Request_Guard {

    /**
     * Transient name prefix for rate-limit buckets:
     * trackwp_rl_<scope>_<md5(ip)>_<window>. Stored as _transient_trackwp_rl_*
     * and _transient_timeout_trackwp_rl_* in wp_options.
     */
    const RATE_LIMIT_PREFIX = 'trackwp_rl_';

    /**
     * Same-site check for browser requests.
     *
     * The Origin header decides when it is present (a present but foreign
     * Origin is rejected even if the Referer matches). Only when Origin is
     * absent is the Referer used. Neither present means rejected.
     *
     * @return bool
     */
    public static function check_origin() {
        $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ('' === $home_host) {
            return false;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        if ('' !== $origin) {
            // Opaque origins ("null") never match.
            return strtolower((string) wp_parse_url($origin, PHP_URL_HOST)) === $home_host;
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string) wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ('' !== $referer) {
            return strtolower((string) wp_parse_url($referer, PHP_URL_HOST)) === $home_host;
        }

        return false;
    }

    /**
     * Fixed-window rate limit per client IP.
     *
     * The window index is part of the transient key, so later requests never
     * extend the TTL of the current window.
     *
     * @param string $scope  Short limiter name ('event', 'consent', 'collect').
     * @param int    $max    Allowed requests per window.
     * @param int    $window Window length in seconds.
     * @return bool True when the request is allowed.
     */
    public static function rate_limit($scope, $max, $window = 2) {
        $window = max(1, (int) $window);
        $bucket = (int) floor(time() / $window);
        $key    = self::RATE_LIMIT_PREFIX . sanitize_key($scope) . '_' . md5(self::client_ip()) . '_' . $bucket;
        $count  = (int) get_transient($key);
        if ($count >= (int) $max) {
            return false;
        }
        set_transient($key, $count + 1, $window + 3);
        return true;
    }

    /**
     * permission_callback helper: origin check plus rate limit.
     *
     * @param string $scope Limiter name.
     * @param int    $max   Requests per 2-second window.
     * @return true|WP_Error
     */
    public static function permission($scope, $max) {
        if (!self::check_origin()) {
            return new WP_Error('rest_forbidden', __('Cross-origin-forespørgsel afvist.', 'trackwp'), array('status' => 403));
        }
        if (!self::rate_limit($scope, $max)) {
            return new WP_Error('rate_limited', __('For mange forespørgsler.', 'trackwp'), array('status' => 429));
        }
        return true;
    }

    /**
     * The visitor's IP address.
     *
     * REMOTE_ADDR is used unless it is one of trusted_proxies() (IPs or CIDR
     * ranges, IPv4 and IPv6, incl. Cloudflare when enabled). Only then are forwarding headers read:
     * CF-Connecting-IP when valid, otherwise the right-most X-Forwarded-For hop
     * that is not itself a trusted proxy. Headers from untrusted peers are
     * ignored, so a client cannot spoof its IP by sending X-Forwarded-For.
     *
     * @return string Valid IP, or '' when none is available.
     */
    public static function client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '';
        }

        $trusted = self::trusted_proxies();
        if (empty($trusted) || !self::ip_in_list($remote, $trusted)) {
            return $remote;
        }

        $cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])) : '';
        if ('' !== $cf && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : '';
        if ('' !== $xff) {
            $hops = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    // A malformed hop means the chain beyond it cannot be trusted.
                    break;
                }
                if (!self::ip_in_list($hop, $trusted)) {
                    return $hop;
                }
            }
        }

        return $remote;
    }

    /**
     * ID of the logged-in user, validated from the logged_in auth cookie.
     *
     * READ-ONLY: this must never be used to authorize anything. It exists so
     * public tracking endpoints (which run without a REST nonce, so WordPress
     * treats them as logged out) can attach a pseudonymous user reference.
     * wp_validate_auth_cookie() reads the cookie itself when passed ''.
     *
     * @return int User ID, or 0.
     */
    public static function current_user_id() {
        if (!function_exists('wp_validate_auth_cookie')) {
            return 0;
        }
        $uid = wp_validate_auth_cookie('', 'logged_in');
        return $uid ? (int) $uid : 0;
    }

    /**
     * Trusted proxies as a list of IP/CIDR strings.
     *
     * Single source: TrackWP_Settings::get_trusted_proxies(), which merges
     * advanced.trusted_proxies with the Cloudflare ranges when
     * advanced.trusted_proxies_cloudflare is on. No list is built here.
     *
     * @return string[]
     */
    public static function trusted_proxies() {
        if (!class_exists('TrackWP_Settings') || !method_exists('TrackWP_Settings', 'get_trusted_proxies')) {
            return array();
        }
        $out = array();
        foreach ((array) TrackWP_Settings::get_trusted_proxies() as $entry) {
            $entry = trim((string) $entry);
            if ('' !== $entry) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Is $ip inside any of the IPs/CIDR ranges in $list?
     *
     * @param string   $ip   IP address.
     * @param string[] $list IPs and CIDR ranges.
     * @return bool
     */
    public static function ip_in_list($ip, $list) {
        foreach ((array) $list as $range) {
            if (self::ip_in_cidr($ip, (string) $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * CIDR match for IPv4 and IPv6. A plain IP is treated as /32 or /128.
     *
     * @param string $ip    IP address.
     * @param string $range IP or CIDR.
     * @return bool
     */
    public static function ip_in_cidr($ip, $range) {
        $parts  = explode('/', trim($range), 2);
        $subnet = $parts[0];
        $ip_bin = @inet_pton($ip);
        $sn_bin = @inet_pton($subnet);
        if (false === $ip_bin || false === $sn_bin || strlen($ip_bin) !== strlen($sn_bin)) {
            return false;
        }
        $max_bits = strlen($ip_bin) * 8;
        $bits     = isset($parts[1]) && '' !== $parts[1] ? (int) $parts[1] : $max_bits;
        if ($bits < 0 || $bits > $max_bits || (isset($parts[1]) && !ctype_digit($parts[1]))) {
            return false;
        }
        $full_bytes = intdiv($bits, 8);
        if (substr($ip_bin, 0, $full_bytes) !== substr($sn_bin, 0, $full_bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ip_bin[ $full_bytes ]) & $mask) === (ord($sn_bin[ $full_bytes ]) & $mask);
    }
}
