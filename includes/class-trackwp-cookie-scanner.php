<?php
defined('ABSPATH') || exit;

/**
 * Detects cookies actually present on the site and completes the consent
 * banner's cookie declaration — including strictly-necessary cookies
 * (PHPSESSID, breakdance_*, trackwp_consent, WordPress/WooCommerce) that
 * Danish/EU guidance requires to be disclosed. Hardcoded vendor defaults
 * (GA4/Ads/Meta) still cover cookies that are only set AFTER consent and
 * therefore are not yet present on a first visit.
 */
class TrackWP_Cookie_Scanner {

    public function __construct() {
        add_filter('trackwp_consent_vendor_list', array($this, 'merge_into_vendor_list'), 10, 1);
    }

    /**
     * Known-cookie classification map. 'match' is an exact name or a
     * 'prefix*' wildcard. category ∈ necessary|statistics|marketing|personalisation.
     */
    public static function known_cookies() {
        // The plugin's own first-party cookie name is configurable.
        $advanced   = get_option('trackwp_advanced', array());
        $twp_cookie = !empty($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid';
        return array(
            array('match' => 'PHPSESSID', 'category' => 'necessary', 'name' => 'PHPSESSID', 'provider' => 'Webserver (PHP)', 'purpose' => 'Bevarer session-tilstand mellem sidevisninger', 'lifetime' => 'Session'),
            array('match' => 'trackwp_consent', 'category' => 'necessary', 'name' => 'Cookie-samtykke', 'provider' => 'TrackWP', 'purpose' => 'Husker dit cookie-samtykke', 'lifetime' => '12 måneder'),
            array('match' => 'breakdance_*', 'category' => 'necessary', 'name' => 'Breakdance', 'provider' => 'Breakdance (sidebygger)', 'purpose' => 'Intern måling for sidebyggeren', 'lifetime' => 'Session'),
            array('match' => 'wordpress_*', 'category' => 'necessary', 'name' => 'WordPress login', 'provider' => 'WordPress', 'purpose' => 'Login og sessionshåndtering', 'lifetime' => 'Session'),
            array('match' => 'wp-settings-*', 'category' => 'necessary', 'name' => 'WordPress-indstillinger', 'provider' => 'WordPress', 'purpose' => 'Husker brugerindstillinger i wp-admin', 'lifetime' => '1 år'),
            array('match' => 'wp_lang', 'category' => 'necessary', 'name' => 'Sprogvalg', 'provider' => 'WordPress', 'purpose' => 'Husker valgt sprog', 'lifetime' => 'Session'),
            array('match' => 'woocommerce_*', 'category' => 'necessary', 'name' => 'WooCommerce', 'provider' => 'WooCommerce', 'purpose' => 'Indkøbskurv og checkout', 'lifetime' => 'Session'),
            array('match' => 'wp_woocommerce_session_*', 'category' => 'necessary', 'name' => 'WooCommerce session', 'provider' => 'WooCommerce', 'purpose' => 'Knytter kurv til besøgende', 'lifetime' => '2 dage'),
            array('match' => $twp_cookie, 'category' => 'statistics', 'name' => 'TrackWP førsteparts-id', 'provider' => 'TrackWP', 'purpose' => 'Genkender tilbagevendende besøgende til statistik', 'lifetime' => 'Op til 12 måneder'),
            array('match' => '_ga', 'category' => 'statistics', 'name' => 'Google Analytics', 'provider' => 'Google LLC, USA', 'purpose' => 'Skelner mellem besøgende', 'lifetime' => 'Op til 13 måneder', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_ga_*', 'category' => 'statistics', 'name' => 'Google Analytics (session)', 'provider' => 'Google LLC, USA', 'purpose' => 'Bevarer sessionstilstand (GA4)', 'lifetime' => 'Op til 13 måneder', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_gid', 'category' => 'statistics', 'name' => 'Google Analytics', 'provider' => 'Google LLC, USA', 'purpose' => 'Skelner mellem besøgende', 'lifetime' => '24 timer', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_gcl_au', 'category' => 'marketing', 'name' => 'Google Ads', 'provider' => 'Google LLC, USA', 'purpose' => 'Conversion-linker for annoncer', 'lifetime' => '90 dage', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_gcl_aw', 'category' => 'marketing', 'name' => 'Google Ads', 'provider' => 'Google LLC, USA', 'purpose' => 'Gemmer klik-id (gclid) for conversion-tracking', 'lifetime' => '90 dage', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_gcl_*', 'category' => 'marketing', 'name' => 'Google Ads', 'provider' => 'Google LLC, USA', 'purpose' => 'Conversion-tracking', 'lifetime' => '90 dage', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_fbp', 'category' => 'marketing', 'name' => 'Meta Pixel', 'provider' => 'Meta Platforms, USA', 'purpose' => 'Conversion-tracking og remarketing', 'lifetime' => '90 dage', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
            array('match' => '_fbc', 'category' => 'marketing', 'name' => 'Meta Pixel', 'provider' => 'Meta Platforms, USA', 'purpose' => 'Gemmer annonce-klik-id (fbclid)', 'lifetime' => '90 dage', 'transfer' => 'USA (EU-US Data Privacy Framework)'),
        );
    }

    /** Match a cookie name against a 'prefix*' or exact pattern. */
    protected static function name_matches($cookie_name, $pattern) {
        if (substr($pattern, -1) === '*') {
            return strpos($cookie_name, substr($pattern, 0, -1)) === 0;
        }
        return $cookie_name === $pattern;
    }

    /**
     * Scan $_COOKIE and return cookies grouped by category as vendor entries
     * (name, provider, cookies, purpose, lifetime, transfer). Unknown cookies
     * are listed under 'unclassified' so nothing on the device is hidden —
     * without wrongly presenting them as strictly necessary.
     */
    public static function scan() {
        $known   = self::known_cookies();
        $grouped = array('necessary' => array(), 'statistics' => array(), 'marketing' => array(), 'personalisation' => array(), 'unclassified' => array());
        $descriptors = array();

        $names = array_keys(is_array($_COOKIE) ? $_COOKIE : array());
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
                $hit = array('category' => 'unclassified', 'name' => $name, 'provider' => 'Ukendt', 'purpose' => 'Ikke klassificeret — gennemgå i TrackWP-indstillinger', 'lifetime' => 'Ukendt');
            }
            $cat  = $hit['category'];
            $dkey = $cat . '|' . $hit['provider'] . '|' . $hit['purpose'];
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
     * Cookies that are effectively always present on this stack and must be
     * declared regardless of whether the current request happened to send
     * them (a first-visit request has no PHPSESSID/trackwp_consent yet, but
     * the declaration must still list them). Same grouped structure as scan().
     *
     * @return array
     */
    public static function baseline_declarations() {
        return array(
            'necessary' => array(
                array(
                    'name'     => 'Cookie-samtykke',
                    'provider' => 'TrackWP',
                    'cookies'  => 'trackwp_consent',
                    'purpose'  => 'Husker dit cookie-samtykke',
                    'lifetime' => '12 måneder',
                    'transfer' => '',
                ),
                array(
                    'name'     => 'PHPSESSID',
                    'provider' => 'Webserver (PHP)',
                    'cookies'  => 'PHPSESSID',
                    'purpose'  => 'Bevarer session-tilstand mellem sidevisninger',
                    'lifetime' => 'Session',
                    'transfer' => '',
                ),
            ),
        );
    }

    /**
     * Merge scanned + admin-custom cookies into the banner vendor list,
     * keeping the existing GA4/Ads/Meta defaults and de-duplicating by
     * cookie name (existing wildcard tokens like "_ga_*" are honored).
     */
    public function merge_into_vendor_list($vendor_list) {
        if (!is_array($vendor_list)) {
            $vendor_list = array();
        }
        $cats = array('necessary', 'statistics', 'marketing', 'personalisation', 'unclassified');
        foreach ($cats as $c) {
            if (!isset($vendor_list[$c]) || !is_array($vendor_list[$c])) {
                $vendor_list[$c] = array();
            }
        }

        $baseline = self::baseline_declarations();
        $scanned  = self::scan();
        $custom   = self::custom_declarations();

        foreach ($cats as $c) {
            // Cookie-name tokens already declared in this category.
            $existing_tokens = array();
            foreach ($vendor_list[$c] as $v) {
                if (!empty($v['cookies'])) {
                    foreach (explode(',', $v['cookies']) as $cn) {
                        $existing_tokens[] = trim($cn);
                    }
                }
            }

            // 1) baseline always-present cookies, then 2) scanned cookies —
            //    both de-duplicated against existing defaults and each other.
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
                $entry_cookies = array_map('trim', explode(',', $entry['cookies']));
                $overlap = false;
                foreach ($entry_cookies as $cn) {
                    foreach ($existing_tokens as $tok) {
                        if (self::token_covers($tok, $cn)) {
                            $overlap = true;
                            break 2;
                        }
                    }
                }
                if (!$overlap) {
                    $vendor_list[$c][] = $entry;
                    foreach ($entry_cookies as $cn) {
                        $existing_tokens[] = $cn;
                    }
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
