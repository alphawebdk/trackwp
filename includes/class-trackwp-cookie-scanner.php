<?php
defined('ABSPATH') || exit;

/**
 * Detects cookies actually present on the device and completes the consent
 * banner's cookie declaration, including strictly-necessary cookies
 * (PHPSESSID, breakdance_*, WordPress/WooCommerce) that Danish/EU guidance
 * requires to be disclosed.
 *
 * The device-independent declaration (active vendors and TrackWP storage with
 * configured lifetimes) comes from TrackWP_Consent_Profile. This class only
 * adds what is found on the device and admin-defined declarations. Lifetimes
 * for TrackWP and provider cookies are taken from the profile.
 */
class TrackWP_Cookie_Scanner {

    public function __construct() {
        add_filter('trackwp_consent_vendor_list', array($this, 'merge_into_vendor_list'), 10, 1);
    }

    /** Make sure the profile class is available (it is also used standalone). */
    protected static function profile_loaded() {
        if (!class_exists('TrackWP_Consent_Profile')) {
            require_once __DIR__ . '/class-trackwp-consent-profile.php';
        }
    }

    /**
     * Known-cookie classification map. 'match' is an exact name or a
     * 'prefix*' wildcard. category ∈ necessary|statistics|marketing|personalisation.
     * More specific patterns must come before broader ones (first match wins).
     */
    public static function known_cookies() {
        self::profile_loaded();
        $advanced   = get_option('trackwp_advanced', array());
        $twp_cookie = !empty($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid';
        $site       = __('Dette website (førsteparts)', 'trackwp');
        $dpf        = __('USA (EU-US Data Privacy Framework)', 'trackwp');
        $session    = __('Session', 'trackwp');
        $p          = 'TrackWP_Consent_Profile';
        $known      = array(
            array('match' => 'PHPSESSID', 'category' => 'necessary', 'name' => 'PHPSESSID', 'provider' => __('Webserver (PHP)', 'trackwp'), 'purpose' => __('Bevarer session-tilstand mellem sidevisninger', 'trackwp'), 'lifetime' => $session),
            array('match' => 'trackwp_consent', 'category' => 'necessary', 'name' => __('Cookie-samtykke', 'trackwp'), 'provider' => $site, 'purpose' => __('Husker dit cookievalg, tidspunktet og dit samtykke-ID', 'trackwp'), 'lifetime' => $p::configured_lifetime_text('trackwp_consent')),
            array('match' => 'breakdance_*', 'category' => 'necessary', 'name' => 'Breakdance', 'provider' => __('Breakdance (sidebygger)', 'trackwp'), 'purpose' => __('Intern funktion i sidebyggeren', 'trackwp'), 'lifetime' => $session),
            array('match' => 'wordpress_*', 'category' => 'necessary', 'name' => __('WordPress-login', 'trackwp'), 'provider' => 'WordPress', 'purpose' => __('Login og sessionshåndtering', 'trackwp'), 'lifetime' => $session),
            array('match' => 'wp-settings-*', 'category' => 'necessary', 'name' => __('WordPress-indstillinger', 'trackwp'), 'provider' => 'WordPress', 'purpose' => __('Husker brugerindstillinger i wp-admin', 'trackwp'), 'lifetime' => __('1 år', 'trackwp')),
            array('match' => 'wp_lang', 'category' => 'necessary', 'name' => __('Sprogvalg', 'trackwp'), 'provider' => 'WordPress', 'purpose' => __('Husker valgt sprog', 'trackwp'), 'lifetime' => $session),
            array('match' => 'wp_woocommerce_session_*', 'category' => 'necessary', 'name' => __('WooCommerce-session', 'trackwp'), 'provider' => 'WooCommerce', 'purpose' => __('Knytter kurven til den besøgende', 'trackwp'), 'lifetime' => __('2 dage', 'trackwp')),
            array('match' => 'woocommerce_*', 'category' => 'necessary', 'name' => 'WooCommerce', 'provider' => 'WooCommerce', 'purpose' => __('Indkøbskurv og checkout', 'trackwp'), 'lifetime' => $session),
            array('match' => $twp_cookie, 'category' => 'statistics', 'name' => __('Førsteparts-id', 'trackwp'), 'provider' => $site, 'purpose' => __('Genkender din browser ved gentagne besøg, så besøg kan tælles korrekt', 'trackwp'), 'lifetime' => $p::configured_lifetime_text('_twp_cid')),
            array('match' => '_twp_click', 'category' => 'marketing', 'name' => __('Annonceklik', 'trackwp'), 'provider' => $site, 'purpose' => __('Gemmer klik-id fra Google-annoncer (gclid, gbraid, wbraid), så et køb kan knyttes til annoncen', 'trackwp'), 'lifetime' => $p::configured_lifetime_text('_twp_click')),
            array('match' => '_ga', 'category' => 'statistics', 'name' => 'Google Analytics', 'provider' => 'Google Ireland Limited', 'purpose' => __('Skelner mellem besøgende', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_ga'), 'transfer' => $dpf),
            array('match' => '_ga_*', 'category' => 'statistics', 'name' => __('Google Analytics (session)', 'trackwp'), 'provider' => 'Google Ireland Limited', 'purpose' => __('Bevarer sessionstilstand (GA4)', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_ga_*'), 'transfer' => $dpf),
            // Own purpose text so _gid is never folded into the _ga descriptor.
            array('match' => '_gid', 'category' => 'statistics', 'name' => 'Google Analytics', 'provider' => 'Google Ireland Limited', 'purpose' => __('Google Analytics-cookie fundet på enheden (ikke dokumenteret af Google som GA4-standardcookie)', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_gid'), 'transfer' => $dpf),
            array('match' => '_gcl_au', 'category' => 'marketing', 'name' => 'Google Ads', 'provider' => 'Google Ireland Limited', 'purpose' => __('Knytter annonceklik til konverteringer', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_gcl_au'), 'transfer' => $dpf),
            array('match' => '_gcl_aw', 'category' => 'marketing', 'name' => 'Google Ads', 'provider' => 'Google Ireland Limited', 'purpose' => __('Gemmer klik-id (gclid) til konverteringsmåling', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_gcl_aw'), 'transfer' => $dpf),
            array('match' => '_gcl_*', 'category' => 'marketing', 'name' => 'Google Ads', 'provider' => 'Google Ireland Limited', 'purpose' => __('Konverteringsmåling', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_gcl_*'), 'transfer' => $dpf),
            array('match' => '_fbp', 'category' => 'marketing', 'name' => 'Meta Pixel', 'provider' => 'Meta Platforms Ireland Limited', 'purpose' => __('Konverteringsmåling samt tilpasning og målretning af annoncer', 'trackwp'), 'lifetime' => $p::provider_lifetime_text('_fbp'), 'transfer' => $dpf),
            array('match' => '_fbc', 'category' => 'marketing', 'name' => __('Meta-annonceklik', 'trackwp'), 'provider' => $site, 'purpose' => __('Gemmer klik-id fra Meta-annoncer (fbclid), så et køb kan knyttes til annoncen', 'trackwp'), 'lifetime' => $p::configured_lifetime_text('_fbc')),
        );

        // 1.11.0 (§3.6): cookie tokens of the catalog vendors the blocker scan found
        // (e.g. __kla_id, sbjs_*, tk_*), with the declared category. Placed after the
        // existing entries so those keep winning (first match wins).
        $unresolved = $p::unresolved();
        foreach ($p::active_vendors() as $v) {
            if (empty($v['via_blocker']) || empty($v['cookies']) || $v['cookies'] === $unresolved) {
                continue;
            }
            foreach (array_map('trim', explode(',', $v['cookies'])) as $token) {
                if ($token === '' || $token === $unresolved) {
                    continue;
                }
                $known[] = array(
                    'match'    => $token,
                    'category' => $v['category'],
                    'name'     => $v['name'],
                    'provider' => $v['provider'],
                    'purpose'  => $v['purpose'],
                    'lifetime' => isset($v['lifetime']) ? $v['lifetime'] : '',
                    'transfer' => isset($v['transfer']) ? $v['transfer'] : '',
                    // 1.11.1 M3: the real catalog vendor key, so a caller can
                    // tell a cataloged vendor apart from a display-only match
                    // (e.g. TrackWP_Blocker_Scanner::server_cookie_items()).
                    'key'      => (isset($v['key']) && is_string($v['key'])) ? $v['key'] : '',
                );
            }
        }
        return $known;
    }

    /**
     * Match a cookie name against a 'prefix*' or exact pattern. Public
     * (1.11.1 KC3) so TrackWP_Cookie_Gate::name_matches() can delegate here
     * without a second implementation of the same rule.
     */
    public static function name_matches($cookie_name, $pattern) {
        if (substr($pattern, -1) === '*') {
            return strpos($cookie_name, substr($pattern, 0, -1)) === 0;
        }
        return $cookie_name === $pattern;
    }

    /**
     * Scan cookie names and return them grouped by category as vendor entries
     * (name, provider, cookies, purpose, lifetime, transfer). Unknown cookies
     * are listed under 'unclassified' so nothing on the device is hidden,
     * without wrongly presenting them as strictly necessary.
     *
     * @param string[]|null $names Cookie names; null = $_COOKIE.
     * @return array<string,array[]>
     */
    public static function scan($names = null) {
        $known   = self::known_cookies();
        $grouped = array('necessary' => array(), 'statistics' => array(), 'marketing' => array(), 'personalisation' => array(), 'unclassified' => array());
        $descriptors = array();

        if ($names === null) {
            $names = array_keys(is_array($_COOKIE) ? $_COOKIE : array());
        }
        foreach ($names as $raw_name) {
            $name = sanitize_text_field((string) $raw_name);
            if ($name === '') {
                continue;
            }
            $hit = null;
            foreach ($known as $k) {
                if (self::name_matches($name, $k['match'])) {
                    $hit = $k;
                    break;
                }
            }
            if ($hit === null) {
                $hit = array('category' => 'unclassified', 'name' => $name, 'provider' => __('Ukendt', 'trackwp'), 'purpose' => __('Ikke klassificeret endnu', 'trackwp'), 'lifetime' => __('Ukendt', 'trackwp'));
            }
            $cat  = $hit['category'];
            $dkey = $cat . '|' . $hit['provider'] . '|' . $hit['purpose'] . '|' . (isset($hit['lifetime']) ? $hit['lifetime'] : '');
            if (!isset($descriptors[$dkey])) {
                $descriptors[$dkey] = array(
                    'name'     => isset($hit['name']) ? $hit['name'] : $hit['provider'],
                    'provider' => $hit['provider'],
                    'cookies'  => array(),
                    'purpose'  => $hit['purpose'],
                    'lifetime' => isset($hit['lifetime']) ? $hit['lifetime'] : '',
                    'transfer' => isset($hit['transfer']) ? $hit['transfer'] : '',
                    '_cat'     => $cat,
                );
            }
            $descriptors[$dkey]['cookies'][] = $name;
        }

        foreach ($descriptors as $d) {
            $cat = $d['_cat'];
            unset($d['_cat']);
            $d['cookies'] = implode(', ', array_unique($d['cookies']));
            $grouped[$cat][] = $d;
        }
        return $grouped;
    }

    /** Admin-defined custom declarations (option), same grouped structure. */
    public static function custom_declarations() {
        $opt = get_option('trackwp_cookie_declarations', array());
        return is_array($opt) ? $opt : array();
    }

    /** True if $name is covered by $token, where $token may end in '*'. */
    protected static function token_covers($token, $name) {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        if (substr($token, -1) === '*') {
            return strpos($name, substr($token, 0, -1)) === 0;
        }
        return $token === $name;
    }

    /**
     * Cookies effectively always present on this stack that are not part of
     * the TrackWP profile (trackwp_consent is declared by the profile).
     *
     * @return array
     */
    public static function baseline_declarations() {
        return array(
            'necessary' => array(
                array(
                    'name'     => 'PHPSESSID',
                    'provider' => __('Webserver (PHP)', 'trackwp'),
                    'cookies'  => 'PHPSESSID',
                    'purpose'  => __('Bevarer session-tilstand mellem sidevisninger', 'trackwp'),
                    'lifetime' => __('Session', 'trackwp'),
                    'transfer' => '',
                ),
            ),
        );
    }

    /**
     * Merge baseline, scanned and admin-custom cookies into the banner vendor
     * list. De-duplication is per cookie name (wildcard tokens like "_ga_*"
     * are honored): an entry is only reduced by the names already declared,
     * so a cookie such as _gid is never dropped because a sibling is declared.
     *
     * @param array $vendor_list
     * @return array
     */
    public function merge_into_vendor_list($vendor_list) {
        return self::merge($vendor_list, null);
    }

    /**
     * @param array         $vendor_list
     * @param string[]|null $names Cookie names on the device; null = $_COOKIE.
     * @return array
     */
    public static function merge($vendor_list, $names = null) {
        if (!is_array($vendor_list)) {
            $vendor_list = array();
        }
        $cats = array('necessary', 'statistics', 'marketing', 'personalisation', 'unclassified');
        foreach ($cats as $c) {
            if (!isset($vendor_list[$c]) || !is_array($vendor_list[$c])) {
                $vendor_list[$c] = array();
            }
        }

        // Tokens already declared anywhere (a cookie is declared once, in one category).
        $existing_tokens = array();
        foreach ($cats as $c) {
            foreach ($vendor_list[$c] as $v) {
                if (!empty($v['cookies'])) {
                    foreach (explode(',', $v['cookies']) as $cn) {
                        $existing_tokens[] = trim($cn);
                    }
                }
            }
        }

        $baseline = self::baseline_declarations();
        $scanned  = self::scan($names);
        $custom   = self::custom_declarations();

        foreach ($cats as $c) {
            $to_add = array();
            if (!empty($baseline[$c]) && is_array($baseline[$c])) {
                $to_add = array_merge($to_add, $baseline[$c]);
            }
            if (!empty($scanned[$c]) && is_array($scanned[$c])) {
                $to_add = array_merge($to_add, $scanned[$c]);
            }
            foreach ($to_add as $entry) {
                if (!is_array($entry) || empty($entry['cookies'])) {
                    continue;
                }
                $keep = array();
                foreach (array_map('trim', explode(',', $entry['cookies'])) as $cn) {
                    $covered = false;
                    foreach ($existing_tokens as $tok) {
                        if (self::token_covers($tok, $cn)) {
                            $covered = true;
                            break;
                        }
                    }
                    if (!$covered) {
                        $keep[] = $cn;
                    }
                }
                if ($keep) {
                    $entry['cookies'] = implode(', ', $keep);
                    $vendor_list[$c][] = $entry;
                    $existing_tokens = array_merge($existing_tokens, $keep);
                }
            }

            if (!empty($custom[$c]) && is_array($custom[$c])) {
                foreach ($custom[$c] as $entry) {
                    if (is_array($entry)) {
                        $vendor_list[$c][] = $entry;
                    }
                }
            }
        }
        return $vendor_list;
    }
}
