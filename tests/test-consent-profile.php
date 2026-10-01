<?php
/**
 * TrackWP_Consent_Profile, banner templates and cookie scanner (W6).
 *
 * Producers used as fixtures: TrackWP_Consent_Profile::vendor_list() feeds the
 * scanner merge, templates/consent-banner.php renders the real markup, and
 * lifetimes are compared with TrackWP_Cookies::lifetime_days() when W1's class
 * is loaded.
 */

require_once dirname(__DIR__) . '/includes/class-trackwp-consent-profile.php';
require_once dirname(__DIR__) . '/includes/class-trackwp-cookie-scanner.php';

class TrackWP_Consent_Profile_Test extends WP_UnitTestCase {

    protected $cookie_backup;

    public function set_up() {
        parent::set_up();
        $this->cookie_backup = $_COOKIE;
        $_COOKIE = array();
        update_option('trackwp_platforms', array());
        update_option('trackwp_advanced', array('first_party_cookie_enabled' => true, 'cookie_lifetime_months' => 24));
        update_option('trackwp_consent', array('controller_name' => 'Eksempel ApS', 'cookie_lifetime_months' => 12));
        update_option('trackwp_cookie_declarations', array());
        update_option('trackwp_woocommerce', array());
    }

    public function tear_down() {
        $_COOKIE = $this->cookie_backup;
        parent::tear_down();
    }

    protected function platforms($ga4 = false, $ads = false, $meta = false, $extra = array()) {
        $p = array();
        if ($ga4) {
            $p += array('ga4_enabled' => true, 'ga4_measurement_id' => 'G-TEST123', 'ga4_gtag_enabled' => true);
        }
        if ($ads) {
            $p += array('google_ads_enabled' => true, 'google_ads_conversion_id' => 'AW-1234567890');
        }
        if ($meta) {
            $p += array('meta_enabled' => true, 'meta_pixel_id' => '123456789012345', 'meta_pixel_client_enabled' => true);
        }
        update_option('trackwp_platforms', array_merge($p, $extra));
    }

    protected function render_banner($style = 'dialog') {
        $consent = get_option('trackwp_consent', array());
        $consent['banner_style'] = $style;
        update_option('trackwp_consent', $consent);
        $config      = $consent;
        $privacy_url = '';
        ob_start();
        include dirname(__DIR__) . '/templates/consent-banner.php';
        return ob_get_clean();
    }

    public function test_only_ga4_gives_statistics_and_no_marketing() {
        $this->platforms(true);
        $this->assertSame(array('statistics'), TrackWP_Consent_Profile::active_categories());
        $this->assertTrue(TrackWP_Consent_Profile::requires_consent());
        $t = TrackWP_Consent_Profile::default_texts();
        $this->assertStringNotContainsString('annonce', $t['description']);
        $this->assertStringNotContainsString('kodet form', $t['description']);
        $this->assertStringContainsString('Eksempel ApS er dataansvarlig', $t['description']);
        $this->assertStringContainsString('Google', $t['sharing']);
        $this->assertSame(array('ga4'), array_keys(TrackWP_Consent_Profile::active_vendors()));
    }

    public function test_no_platforms_means_information_mode_without_sharing_claim() {
        $this->assertSame(array(), TrackWP_Consent_Profile::active_categories());
        $this->assertFalse(TrackWP_Consent_Profile::requires_consent());
        $t = TrackWP_Consent_Profile::default_texts();
        $this->assertSame('', $t['sharing']);
        $this->assertStringNotContainsString('deles', $t['description']);
        $html = $this->render_banner('cookiebot');
        $this->assertStringContainsString('data-mode="info"', $html);
        $this->assertStringNotContainsString('data-action="reject-all"', $html);
        $this->assertStringNotContainsString('data-action="accept-all"', $html);
        $this->assertStringContainsString('trackwp_consent', $html);
    }

    public function test_acm_sentence_only_with_google_tag_and_cookieless_pings() {
        $acm = TrackWP_Consent_Profile::acm_sentence();

        $this->platforms(true);
        $this->assertStringContainsString($acm, TrackWP_Consent_Profile::default_texts()['description']);

        update_option('trackwp_advanced', array('consent_mode_cookieless_pings' => false));
        $this->assertStringNotContainsString($acm, TrackWP_Consent_Profile::default_texts()['description']);

        // Server-side GA4 only: no Google tag in the page, no ACM sentence.
        update_option('trackwp_advanced', array());
        $this->platforms(true, false, false, array('ga4_gtag_enabled' => false));
        $this->assertStringNotContainsString($acm, TrackWP_Consent_Profile::default_texts()['description']);

        // GTM is a Google tag.
        $this->platforms(false, false, false, array('gtm_enabled' => true, 'gtm_container_id' => 'GTM-ABC123'));
        $this->assertStringContainsString($acm, TrackWP_Consent_Profile::default_texts()['description']);
    }

    public function test_ads_and_meta_mention_ad_targeting_and_coded_form() {
        $this->platforms(true, true, true);
        $this->assertSame(array('statistics', 'marketing'), TrackWP_Consent_Profile::active_categories());
        $d = TrackWP_Consent_Profile::default_texts()['description'];
        $this->assertStringContainsString('tilpasning og målretning af annoncer', $d);
        $this->assertStringContainsString('i kodet form, som stadig gør det muligt for modtageren at genkende dig', $d);
        $this->assertStringContainsString('Google og Meta', $d);

        update_option('trackwp_advanced', array('customer_data_sharing' => false));
        $this->assertStringNotContainsString('kodet form', TrackWP_Consent_Profile::default_texts()['description']);

        // Single source: TrackWP_Hash::customer_data_sharing_enabled(), incl. its filter.
        update_option('trackwp_advanced', array());
        add_filter('trackwp_customer_data_sharing', '__return_false');
        $this->assertFalse(TrackWP_Hash::customer_data_sharing_enabled());
        $this->assertStringNotContainsString('kodet form', TrackWP_Consent_Profile::default_texts()['description']);
        remove_filter('trackwp_customer_data_sharing', '__return_false');
        $this->assertStringContainsString('kodet form', TrackWP_Consent_Profile::default_texts()['description']);
    }

    public function test_gtm_without_vendors_uses_placeholder_and_warns() {
        $this->platforms(false, false, false, array('gtm_enabled' => true, 'gtm_container_id' => 'GTM-ABC123'));
        $vendors = TrackWP_Consent_Profile::active_vendors();
        $this->assertArrayHasKey(TrackWP_Consent_Profile::GTM_PLACEHOLDER_KEY, $vendors);
        $this->assertTrue($vendors[TrackWP_Consent_Profile::GTM_PLACEHOLDER_KEY]['placeholder']);
        $this->assertContains('gtm_vendors_missing', TrackWP_Consent_Profile::warnings());
        $this->assertSame(array('statistics', 'marketing'), TrackWP_Consent_Profile::active_categories());

        $c = get_option('trackwp_consent');
        // Canonical W7 structure: known catalog keys + custom entries.
        $c['gtm_vendors'] = array(
            'known'  => array('meta', 'hotjar', 'not_in_catalog'),
            'custom' => array(
                array('name' => 'Plausible', 'provider' => 'Plausible Insights OÜ', 'category' => 'statistics', 'cookies' => '', 'purpose' => 'Statistik', 'lifetime' => '', 'transfer' => ''),
                array('name' => 'Ugyldig', 'provider' => 'X', 'category' => 'necessary'),
            ),
        );
        update_option('trackwp_consent', $c);
        $vendors = TrackWP_Consent_Profile::active_vendors();
        $this->assertArrayNotHasKey(TrackWP_Consent_Profile::GTM_PLACEHOLDER_KEY, $vendors);
        $this->assertSame(array('meta', 'hotjar', 'gtm_plausible'), array_keys($vendors));
        $this->assertTrue($vendors['meta']['via_gtm']);
        $this->assertSame('statistics', $vendors['hotjar']['category']);
        $this->assertNotContains('gtm_vendors_missing', TrackWP_Consent_Profile::warnings());
        $this->assertStringContainsString('Meta, Contentsquare og Plausible Insights OÜ', TrackWP_Consent_Profile::default_texts()['sharing']);

        // Old flat list format is not the contract and yields the placeholder.
        $c['gtm_vendors'] = array('meta');
        update_option('trackwp_consent', $c);
        $this->assertArrayHasKey(TrackWP_Consent_Profile::GTM_PLACEHOLDER_KEY, TrackWP_Consent_Profile::active_vendors());
    }

    public function test_tiktok_never_gets_dpf_claim() {
        $gtm = array('gtm_enabled' => true, 'gtm_container_id' => 'GTM-ABC123');
        $this->platforms(false, false, false, $gtm);
        $c = get_option('trackwp_consent');
        $c['gtm_vendors'] = array('known' => array('tiktok'), 'custom' => array());
        update_option('trackwp_consent', $c);

        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $this->assertSame('scc', $catalog['tiktok']['transfer_basis']);
        $this->assertStringNotContainsString('Data Privacy Framework', $catalog['tiktok']['transfer']);
        $this->assertStringContainsString('standardkontraktbestemmelser', $catalog['tiktok']['transfer']);

        // TikTok alone: singular, outside EU/EEA, no DPF.
        $sharing = TrackWP_Consent_Profile::default_texts()['sharing'];
        $this->assertStringContainsString('samarbejdspartner TikTok, som kan behandle dem uden for EU/EØS', $sharing);
        $this->assertStringNotContainsString('Data Privacy Framework', $sharing);

        // TikTok with Google (DPF): mixed bases, no blanket DPF claim.
        $this->platforms(true, false, false, $gtm);
        $sharing = TrackWP_Consent_Profile::default_texts()['sharing'];
        $this->assertStringContainsString('Google og TikTok', $sharing);
        $this->assertStringContainsString('Nogle af dem kan behandle oplysningerne uden for EU/EØS. Se grundlaget for hver samarbejdspartner under »Tilpas valg«', $sharing);
        $this->assertStringNotContainsString('Data Privacy Framework', $sharing);
    }

    public function test_vendor_catalog_covers_required_keys() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        foreach (array('ga4', 'google_ads', 'meta', 'linkedin', 'tiktok', 'microsoft_ads', 'hotjar') as $key) {
            $this->assertArrayHasKey($key, $catalog, $key);
            foreach (array('key', 'name', 'provider', 'recipient', 'category', 'cookies', 'purpose', 'lifetime', 'transfer') as $field) {
                $this->assertArrayHasKey($field, $catalog[$key], $key . '.' . $field);
            }
            $this->assertSame($key, $catalog[$key]['key']);
            $this->assertContains($catalog[$key]['category'], TrackWP_Consent_Profile::OPTIONAL_CATEGORIES);
        }
    }

    public function test_controller_name_is_material() {
        $this->platforms(true);
        $h1 = TrackWP_Consent_Profile::material_hash();
        $c = get_option('trackwp_consent');
        $c['controller_name'] = 'Anden Ejer A/S';
        update_option('trackwp_consent', $c);
        $this->assertNotSame($h1, TrackWP_Consent_Profile::material_hash());
    }

    public function test_storage_declarations_and_lifetimes() {
        $this->platforms(true, true, true);
        $decl = array();
        foreach (TrackWP_Consent_Profile::storage_declarations(array()) as $d) {
            $decl[$d['cookies']] = $d;
        }
        foreach (array('trackwp_consent', '_twp_cid', 'trackwp_sid', 'trackwp_ka_ts', '_twp_click', '_fbc') as $name) {
            $this->assertArrayHasKey($name, $decl, $name);
        }
        $this->assertArrayNotHasKey('_gid', $decl);
        $this->assertSame('necessary', $decl['trackwp_consent']['category']);
        $this->assertSame('sessionStorage', $decl['trackwp_sid']['type']);
        $this->assertSame('localStorage', $decl['trackwp_ka_ts']['type']);
        // trackwp.js throttles keepalive to once per hour (3600000 ms).
        $this->assertStringContainsString('én gang i timen', $decl['trackwp_ka_ts']['purpose']);
        $this->assertStringNotContainsString('døgn', $decl['trackwp_ka_ts']['purpose']);

        $cid_days = class_exists('TrackWP_Cookies') ? (int) TrackWP_Cookies::lifetime_days('_twp_cid') : 400;
        $this->assertSame($cid_days, TrackWP_Consent_Profile::cookie_days('_twp_cid'));
        $this->assertStringContainsString($cid_days . ' dage (TrackWP-konfigureret)', $decl['_twp_cid']['lifetime']);
        $consent_days = class_exists('TrackWP_Cookies') ? (int) TrackWP_Cookies::lifetime_days('trackwp_consent') : 360;
        $this->assertStringContainsString($consent_days . ' dage', $decl['trackwp_consent']['lifetime']);

        $with_gid = TrackWP_Consent_Profile::storage_declarations(array('_gid'));
        $gid = end($with_gid);
        $this->assertSame('_gid', $gid['cookies']);
        $this->assertSame('provider', $gid['lifetime_source']);
        $this->assertSame('Styret af udbyderen', $gid['lifetime']);
    }

    public function test_scanner_keeps_gid_next_to_declared_ga() {
        $this->platforms(true);
        $list = TrackWP_Cookie_Scanner::merge(TrackWP_Consent_Profile::vendor_list(), array('_ga', '_ga_ABC', '_gid', 'foo_unknown'));
        $stat_cookies = array();
        foreach ($list['statistics'] as $e) {
            $stat_cookies = array_merge($stat_cookies, array_map('trim', explode(',', $e['cookies'])));
        }
        $this->assertContains('_gid', $stat_cookies);
        $this->assertSame(1, count(array_keys($stat_cookies, '_ga', true)), '_ga declared once');
        $this->assertSame('foo_unknown', $list['unclassified'][0]['cookies']);
        foreach ($list['statistics'] as $e) {
            if ($e['cookies'] === '_gid') {
                $this->assertStringContainsString('Styret af udbyderen', $e['lifetime']);
            }
        }
    }

    public function test_custom_description_changes_banner_hash_but_not_material_hash() {
        $this->platforms(true);
        $c = get_option('trackwp_consent');
        $c['description_mode'] = 'custom';
        $c['description']      = 'Første tekst.';
        update_option('trackwp_consent', $c);
        $m1 = TrackWP_Consent_Profile::material_hash();
        $b1 = TrackWP_Consent_Profile::banner_hash();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $b1);
        $this->assertNotSame($m1, $b1);

        $c['description'] = 'Anden tekst.';
        update_option('trackwp_consent', $c);
        $this->assertSame($m1, TrackWP_Consent_Profile::material_hash());
        $this->assertNotSame($b1, TrackWP_Consent_Profile::banner_hash());

        // Button labels are part of what was shown as well.
        $b2 = TrackWP_Consent_Profile::banner_hash();
        $c['reject_text'] = 'Nej tak';
        update_option('trackwp_consent', $c);
        $this->assertSame($m1, TrackWP_Consent_Profile::material_hash());
        $this->assertNotSame($b2, TrackWP_Consent_Profile::banner_hash());
    }

    public function test_material_hash_changes_with_platforms_and_ignores_device() {
        $this->platforms(true);
        $h1 = TrackWP_Consent_Profile::material_hash();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $h1);
        $_COOKIE = array('_gid' => '1', 'foo' => '2');
        $this->assertSame($h1, TrackWP_Consent_Profile::material_hash());
        $this->platforms(true, false, true);
        $this->assertNotSame($h1, TrackWP_Consent_Profile::material_hash());
    }

    public function test_sharing_uses_singular_for_one_recipient() {
        $this->platforms(true);
        $sharing = TrackWP_Consent_Profile::default_texts()['sharing'];
        $this->assertStringContainsString('Oplysningerne deles med vores samarbejdspartner Google, som kan behandle dem i USA under EU-US Data Privacy Framework.', $sharing);
        $this->assertStringNotContainsString('samarbejdspartnere Google', $sharing);
        $this->assertStringNotContainsString('Nogle af dem', $sharing);

        $this->platforms(true, false, true);
        $this->assertStringContainsString('Oplysningerne deles med vores samarbejdspartnere Google og Meta, som kan behandle dem i USA', TrackWP_Consent_Profile::default_texts()['sharing']);
    }

    public function test_heading_and_buttons_come_from_admin_in_auto_mode() {
        $this->platforms(true);
        $t = TrackWP_Consent_Profile::banner_texts();
        $this->assertSame('auto', $t['mode']);
        $this->assertSame('Afvis valgfrie', $t['reject']);
        $this->assertSame('Accepter alle', $t['accept']);

        $c = get_option('trackwp_consent');
        $c['heading']        = 'Min overskrift';
        $c['description']    = 'Min egen tekst.';
        $c['accept_text']    = 'Ja tak';
        $c['reject_text']    = 'Nej tak';
        $c['customize_text'] = 'Vælg selv';
        $c['save_text']      = 'Gem';
        update_option('trackwp_consent', $c);
        $t = TrackWP_Consent_Profile::banner_texts();
        $this->assertSame('auto', $t['mode']);
        $this->assertSame('Min overskrift', $t['heading']);
        $this->assertSame('Ja tak', $t['accept']);
        $this->assertSame('Nej tak', $t['reject']);
        $this->assertSame('Vælg selv', $t['customize']);
        $this->assertSame('Gem', $t['save']);
        // Auto mode: dynamic description, not the admin description.
        $this->assertNotContains('Min egen tekst.', $t['blocks']);
        $this->assertStringContainsString('»Vælg selv«', $t['sharing']);
    }

    public function test_custom_description_only_in_custom_mode() {
        $this->platforms(true);
        $c = get_option('trackwp_consent');
        $c['heading']     = 'Min overskrift';
        $c['description'] = 'Min egen tekst.';
        $c['reject_text'] = 'Nej tak';
        update_option('trackwp_consent', $c);
        $this->assertNotContains('Min egen tekst.', TrackWP_Consent_Profile::banner_texts()['blocks']);

        $c['description_mode'] = 'custom';
        update_option('trackwp_consent', $c);
        $t = TrackWP_Consent_Profile::banner_texts();
        $this->assertSame('Min overskrift', $t['heading']);
        $this->assertSame('Nej tak', $t['reject']);
        $this->assertSame('Min egen tekst.', $t['blocks'][0]);
        $this->assertContains(TrackWP_Consent_Profile::acm_sentence(), $t['blocks']);
    }

    public function test_templates_reject_on_first_layer_and_only_active_categories() {
        $this->platforms(true);
        foreach (array('dialog', 'cookiebot', 'bottombar') as $style) {
            $html = $this->render_banner($style);
            $this->assertStringContainsString('data-action="reject-all"', $html, $style);
            $this->assertStringContainsString('Afvis valgfrie', $html, $style);
            $this->assertStringContainsString('data-mode="consent"', $html, $style);
            $this->assertStringContainsString('id="trackwp-consent-statistics"', $html, $style);
            $this->assertStringNotContainsString('id="trackwp-consent-marketing"', $html, $style);
            $this->assertStringNotContainsString('id="trackwp-consent-personalisation"', $html, $style);
            $this->assertStringContainsString('data-role="consent-status"', $html, $style);
            $this->assertStringContainsString('data-action="withdraw"', $html, $style);
            $this->assertStringContainsString('data-banner-hash="' . TrackWP_Consent_Profile::banner_hash() . '"', $html, $style);
            $this->assertDoesNotMatchRegularExpression('/anonymiser/i', $html, $style);
        }
        // Dialog level 2 declares necessary and unclassified cookies.
        $_COOKIE = array('foo_unknown' => '1');
        $html = $this->render_banner('dialog');
        $this->assertStringContainsString('data-category="necessary"', $html);
        $this->assertStringContainsString('data-category="unclassified"', $html);
        $this->assertStringContainsString('foo_unknown', $html);
    }

    public function test_no_anonymised_wording_anywhere_in_profile_texts() {
        $this->platforms(true, true, true, array('gtm_enabled' => true, 'gtm_container_id' => 'GTM-ABC123'));
        $blob = wp_json_encode(array(
            TrackWP_Consent_Profile::default_texts(),
            TrackWP_Consent_Profile::vendor_list(),
            TrackWP_Consent_Profile::category_labels(),
            TrackWP_Cookie_Scanner::known_cookies(),
        ), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/anonymiser/i', $blob);
    }

    /* ---------------- 1.11.0: blocker-driven declaration (T1, §3.6) ---------------- */

    /**
     * Minimal KB3 scan item for a catalog vendor. The vendor keys and handles are
     * taken from the producer vendor_catalog() (see tests/test-blocker-catalog.php
     * for items derived from the real live HTML).
     */
    protected function blocker_scan($vendors, $rules = array(), $custom = array()) {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $items   = array();
        foreach ($vendors as $key) {
            $handles = $catalog[$key]['signatures']['handles'];
            $hosts   = $catalog[$key]['signatures']['hosts'];
            $rule_id = $handles ? 'handle:' . $handles[0] : 'host:' . $hosts[0];
            $items[] = array('obs_id' => substr(sha1($rule_id), 0, 12), 'rule_id' => $rule_id, 'kind' => 'script', 'host' => '', 'path' => '', 'handle' => '', 'plugin' => '', 'deps' => array(), 'dependents' => array(), 'marker' => '', 'pages' => array('/'), 'vendor' => $key, 'category_guess' => $catalog[$key]['category'], 'status' => 'allowed', 'seen_blocked' => null);
            if (!isset($rules[$rule_id])) {
                $rules[$rule_id] = array('block' => false, 'category' => $catalog[$key]['category'], 'vendor' => $key);
            }
        }
        update_option('trackwp_blocker_scan', array('scanned_at' => 1, 'pages' => array('/'), 'items' => $items));
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => $rules, 'custom_vendors' => $custom, 'exceptions' => array('allow' => array(), 'paths' => array()), 'extra_paths' => array()));
    }

    public function test_blocker_vendor_is_declared_and_material_hash_changes() {
        $this->platforms(true);
        delete_option('trackwp_blocker');
        delete_option('trackwp_blocker_scan');
        $before = TrackWP_Consent_Profile::material_hash();
        $this->assertArrayNotHasKey('klaviyo', TrackWP_Consent_Profile::active_vendors());

        $this->blocker_scan(array('klaviyo'));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertArrayHasKey('klaviyo', $v);
        $this->assertTrue($v['klaviyo']['via_blocker']);
        $this->assertFalse($v['klaviyo']['blocked']);
        $this->assertFalse($v['ga4']['via_blocker']);
        $this->assertNotSame($before, TrackWP_Consent_Profile::material_hash());
        $this->assertContains('marketing', TrackWP_Consent_Profile::active_categories());
        $list = TrackWP_Consent_Profile::vendor_list();
        $this->assertContains('Klaviyo', wp_list_pluck($list['marketing'], 'name'));
        // Jetpack is SCC only: the banner then drops the blanket DPF sentence.
        $this->assertStringContainsString('under EU-US Data Privacy Framework', TrackWP_Consent_Profile::default_texts()['sharing']);
        $this->blocker_scan(array('klaviyo', 'jetpack'));
        $this->assertStringNotContainsString('under EU-US Data Privacy Framework', TrackWP_Consent_Profile::default_texts()['sharing']);
    }

    public function test_native_meta_wins_over_blocker_finding() {
        $this->platforms(false, false, true);
        $this->blocker_scan(array('meta'));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertFalse($v['meta']['via_blocker']);
        $this->assertSame('_fbp', $v['meta']['cookies']);
    }

    public function test_blocker_finding_fills_in_for_meta_without_browser_cookies() {
        // Native Meta as CAPI only declares no cookies, so fb4woo's pixel found by the scan is declared.
        $this->platforms(false, false, true, array('meta_pixel_client_enabled' => false));
        $this->blocker_scan(array('meta'));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertTrue($v['meta']['via_blocker']);
        $this->assertSame('_fbp, _fbc', $v['meta']['cookies']);
    }

    public function test_rule_category_and_block_state_are_used() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $rid     = 'handle:' . $catalog['wc_order_attribution']['signatures']['handles'][0];
        $this->blocker_scan(array('wc_order_attribution', 'jetpack'), array(
            $rid => array('block' => true, 'category' => 'statistics', 'vendor' => 'wc_order_attribution'),
        ));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertSame('statistics', $v['wc_order_attribution']['category']);
        $this->assertTrue($v['wc_order_attribution']['blocked']);
        $this->assertSame(array('jetpack'), TrackWP_Consent_Profile::unblocked_vendors());
        $this->assertContains('unblocked_vendors', TrackWP_Consent_Profile::warnings());
    }

    public function test_no_unblocked_warning_when_all_blocked() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $rid     = 'host:' . $catalog['leadinfo']['signatures']['hosts'][0];
        $this->blocker_scan(array('leadinfo'), array($rid => array('block' => true, 'category' => 'marketing', 'vendor' => 'leadinfo')));
        $this->assertSame(array(), TrackWP_Consent_Profile::unblocked_vendors());
        $this->assertNotContains('unblocked_vendors', TrackWP_Consent_Profile::warnings());
    }

    /* ---------------- 1.11.1 F1 (KC12): server-only findings ---------------- */

    public function test_server_side_scan_row_declares_vendor_without_blocking() {
        update_option('trackwp_blocker_scan', array('items' => array(
            array('rule_id' => '', 'kind' => 'server_side', 'vendor' => 'meta', 'category_guess' => 'marketing'),
        )));
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => array(), 'custom_vendors' => array(), 'exceptions' => array('allow' => array(), 'paths' => array()), 'extra_paths' => array()));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertTrue($v['meta']['via_blocker']);
        $this->assertFalse($v['meta']['blocked']);
        $this->assertTrue($v['meta']['server_only']);
        // server_only vendors cannot be blocked in the browser: they must not
        // trigger the "not blocked" warning meant for browser-blockable finds.
        $this->assertSame(array(), TrackWP_Consent_Profile::unblocked_vendors());
    }

    public function test_server_cookie_without_rule_id_declares_vendor_as_server_only() {
        update_option('trackwp_blocker_scan', array('items' => array(
            array('rule_id' => '', 'kind' => 'server_cookie', 'vendor' => 'leadinfo', 'category_guess' => 'marketing'),
        )));
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => array(), 'custom_vendors' => array(), 'exceptions' => array('allow' => array(), 'paths' => array()), 'extra_paths' => array()));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertTrue($v['leadinfo']['server_only']);
        $this->assertSame(array(), TrackWP_Consent_Profile::unblocked_vendors());
    }

    public function test_server_cookie_with_rule_id_is_a_normal_blockable_finding() {
        // A cookie: rule (KC2) turns a server_cookie row into a real browser
        // rule, so it must behave like any other blockable vendor.
        update_option('trackwp_blocker_scan', array('items' => array(
            array('rule_id' => 'cookie:partner-example', 'kind' => 'server_cookie', 'vendor' => 'leadinfo', 'category_guess' => 'marketing'),
        )));
        update_option('trackwp_blocker', array(
            'mode'  => 'off',
            'rules' => array('cookie:partner-example' => array('block' => true, 'category' => 'marketing', 'vendor' => 'leadinfo')),
            'custom_vendors' => array(),
            'exceptions'     => array('allow' => array(), 'paths' => array()),
            'extra_paths'    => array(),
        ));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertFalse($v['leadinfo']['server_only']);
        $this->assertTrue($v['leadinfo']['blocked']);
    }

    /**
     * TrackWP_Blocker_Scanner::server_cookie_items() is private; called via
     * reflection, same pattern as tests/test-blocker-scanner.php (W1).
     */
    protected static function scanner_server_cookie_items($names, array $compiled, $mode) {
        $m = new ReflectionMethod('TrackWP_Blocker_Scanner', 'server_cookie_items');
        $m->setAccessible(true);
        return $m->invoke(null, $names, $compiled, $mode);
    }

    public function test_server_cookie_items_from_real_scanner_never_creates_a_phantom_vendor() {
        // M3 regression: an unknown/necessary cookie (PHPSESSID) and a
        // catalog-known one (_fbp, Meta) must both feed blocker_vendors()
        // through the REAL scanner producer without ever inventing a vendor
        // from the scanner's display-only provider text (e.g. sanitize_key()
        // turning "Webserver (PHP)" into a phantom 'webserver-php' vendor).
        $compiled = TrackWP_Blocker_Rules::compile(array());
        $items    = self::scanner_server_cookie_items(array('PHPSESSID', '_fbp'), $compiled, 'off');
        update_option('trackwp_blocker_scan', array('items' => $items));
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => array(), 'custom_vendors' => array(), 'exceptions' => array('allow' => array(), 'paths' => array()), 'extra_paths' => array()));

        $v = TrackWP_Consent_Profile::active_vendors();
        // No key derived from a display label ("Webserver (PHP)", "Meta
        // Platforms Ireland Limited") is present.
        $this->assertArrayNotHasKey('webserver-php', $v);
        $this->assertArrayNotHasKey('meta-platforms-ireland-limited', $v);
        $this->assertArrayNotHasKey(sanitize_key('Webserver (PHP)'), $v);
        $this->assertArrayNotHasKey(sanitize_key('Meta Platforms Ireland Limited'), $v);
        // Only real catalog keys (or nothing) may appear via_blocker.
        foreach ($v as $key => $entry) {
            if (!empty($entry['via_blocker'])) {
                $this->assertTrue(strpos($key, 'blk_') === 0 || isset(TrackWP_Consent_Profile::vendor_catalog()[$key]), "phantom vendor key: $key");
            }
        }
    }

    public function test_vendor_found_via_script_and_server_side_is_not_server_only() {
        // A vendor with BOTH a browser-blockable row and a server_side row is
        // still blockable in the browser, so server_only must stay false.
        $this->blocker_scan(array('meta'));
        $scan = get_option('trackwp_blocker_scan');
        $scan['items'][] = array('rule_id' => '', 'kind' => 'server_side', 'vendor' => 'meta', 'category_guess' => 'marketing');
        update_option('trackwp_blocker_scan', $scan);
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertFalse($v['meta']['server_only']);
    }

    public function test_blocker_warning_vendors_separates_not_selected_and_server_side() {
        $this->blocker_scan(array('jetpack'));
        $scan = get_option('trackwp_blocker_scan');
        $scan['items'][] = array('rule_id' => '', 'kind' => 'server_side', 'vendor' => 'meta', 'category_guess' => 'marketing');
        update_option('trackwp_blocker_scan', $scan);
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $w = TrackWP_Consent_Profile::blocker_warning_vendors();
        $this->assertSame(array($catalog['jetpack']['name']), $w['not_selected']);
        $this->assertSame(array($catalog['meta']['name']), $w['server_side']);
    }

    protected function meta_scan_only($meta_platforms = array()) {
        update_option('trackwp_platforms', $meta_platforms);
        update_option('trackwp_blocker_scan', array('items' => array(
            array('rule_id' => '', 'kind' => 'server_side', 'vendor' => 'meta', 'category_guess' => 'marketing'),
        )));
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => array(), 'custom_vendors' => array(), 'exceptions' => array('allow' => array(), 'paths' => array()), 'extra_paths' => array()));
    }

    public function test_blocker_warning_vendors_keeps_meta_when_fb4woo_not_suppressed() {
        // No native Meta configured at all: TrackWP_Meta_Takeover::status()
        // (W5, KC6, real producer) is not suppressed, so the server-only
        // finding must still be warned about.
        $this->meta_scan_only();
        $status = TrackWP_Meta_Takeover::status();
        $this->assertFalse($status['fb4woo_suppressed']);
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $w = TrackWP_Consent_Profile::blocker_warning_vendors();
        $this->assertContains($catalog['meta']['name'], $w['server_side']);
    }

    public function test_blocker_warning_vendors_drops_meta_when_fb4woo_suppressed() {
        // TrackWP itself delivers Meta (can_deliver()=true, KC6): fb4woo's
        // own server delivery is suppressed, so warning about "cannot block
        // server-side" traffic that TrackWP already stopped would mislead.
        $this->meta_scan_only(array(
            'meta_pixel_with_gtm'       => true,
            'meta_enabled'              => true,
            'meta_pixel_id'             => '123456789012345',
            'meta_pixel_client_enabled' => true,
        ));
        update_option('trackwp_woocommerce', array('enabled' => true));
        $status = TrackWP_Meta_Takeover::status();
        $this->assertTrue($status['fb4woo_suppressed']);
        $w = TrackWP_Consent_Profile::blocker_warning_vendors();
        $this->assertNotContains(TrackWP_Consent_Profile::vendor_catalog()['meta']['name'], $w['server_side']);
    }

    public function test_unknown_vendor_key_and_empty_custom_fields_read_uafklaret() {
        $u = TrackWP_Consent_Profile::unresolved();
        update_option('trackwp_blocker_scan', array('items' => array(
            array('rule_id' => 'host:cdn.example-tracker.com', 'vendor' => 'exampletracker'),
            array('rule_id' => 'host:cdn.other.com', 'vendor' => null),
            array('rule_id' => 'host:cdn.third.com', 'vendor' => 'uafklaret'),
        )));
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => array(), 'custom_vendors' => array(
            array('name' => 'Min Tracker', 'category' => 'statistics'),
            array('name' => 'Ugyldig', 'category' => 'necessary_or_bad'),
        )));
        $v = TrackWP_Consent_Profile::active_vendors();
        $this->assertSame($u, $v['exampletracker']['provider']);
        $this->assertSame($u, $v['exampletracker']['cookies']);
        $this->assertArrayHasKey('blk_mintracker', $v);
        $this->assertTrue($v['blk_mintracker']['via_blocker']);
        $this->assertSame($u, $v['blk_mintracker']['cookies']);
        $this->assertSame($u, $v['blk_mintracker']['purpose']);
        $this->assertCount(2, array_filter($v, function ($x) { return !empty($x['via_blocker']); }));
    }

    public function test_normalize_custom_vendor_is_shared_with_gtm_vendors() {
        $entry = array('name' => 'Snap Pixel', 'provider' => 'Snap B.V.', 'category' => 'marketing', 'cookies' => '_scid', 'purpose' => 'Annoncer', 'transfer' => 'USA (DPF)');
        update_option('trackwp_consent', array('controller_name' => 'Eksempel ApS', 'gtm_vendors' => array('known' => array(), 'custom' => array($entry))));
        $gtm = TrackWP_Consent_Profile::gtm_vendors();
        $this->assertSame(TrackWP_Consent_Profile::normalize_custom_vendor($entry, 'gtm_'), $gtm[0]);
        $blk = TrackWP_Consent_Profile::normalize_custom_vendor($entry, 'blk_');
        $this->assertSame('blk_snappixel', $blk['key']);
        $this->assertSame('dpf', $blk['transfer_basis']);
        $this->assertNull(TrackWP_Consent_Profile::normalize_custom_vendor(array('name' => ''), 'blk_'));
    }

    /* ---------------- 1.11.1 F3 (KC14): transfer normalisation ---------------- */

    public function test_normalize_custom_vendor_empty_transfer_reads_unresolved_and_basis_other() {
        $entry = array('name' => 'Ny Tjeneste', 'category' => 'marketing', 'transfer' => '');
        $v = TrackWP_Consent_Profile::normalize_custom_vendor($entry, 'blk_');
        $this->assertSame(TrackWP_Consent_Profile::unresolved(), $v['transfer']);
        $this->assertSame('other', $v['transfer_basis']);
    }

    /** @dataProvider provider_none_transfer_spellings */
    public function test_normalize_custom_vendor_none_spellings_give_basis_none($input) {
        $entry = array('name' => 'Ny Tjeneste', 'category' => 'marketing', 'transfer' => $input);
        $v = TrackWP_Consent_Profile::normalize_custom_vendor($entry, 'blk_');
        // "Uændret" per KC14: only the inherent str()-trim applies, the
        // wording itself (case, spelling) is left exactly as entered.
        $this->assertSame(trim($input), $v['transfer']);
        $this->assertSame('none', $v['transfer_basis']);
    }

    public function provider_none_transfer_spellings() {
        return array(
            array('Ingen'),
            array('  ingen  '),
            array('Ingen overførsel'),
            array('NONE'),
            array('none'),
        );
    }

    public function test_normalize_custom_vendor_dpf_mention_gives_basis_dpf() {
        $entry = array('name' => 'Ny Tjeneste', 'category' => 'marketing', 'transfer' => 'USA under Data Privacy Framework');
        $v = TrackWP_Consent_Profile::normalize_custom_vendor($entry, 'blk_');
        $this->assertSame('USA under Data Privacy Framework', $v['transfer']);
        $this->assertSame('dpf', $v['transfer_basis']);
    }

    public function test_normalize_custom_vendor_other_free_text_gives_basis_other() {
        $entry = array('name' => 'Ny Tjeneste', 'category' => 'marketing', 'transfer' => 'Uden for EU, mekanisme uafklaret');
        $v = TrackWP_Consent_Profile::normalize_custom_vendor($entry, 'blk_');
        $this->assertSame('Uden for EU, mekanisme uafklaret', $v['transfer']);
        $this->assertSame('other', $v['transfer_basis']);
    }

    public function test_known_cookies_catalog_tokens_come_after_existing_and_only_when_scanned() {
        $before = TrackWP_Cookie_Scanner::scan(array('__kla_id'));
        $this->assertNotEmpty($before['unclassified']);
        $this->blocker_scan(array('klaviyo', 'wc_order_attribution', 'jetpack', 'meta'));
        $known   = TrackWP_Cookie_Scanner::known_cookies();
        $matches = wp_list_pluck($known, 'match');
        $this->assertLessThan(array_search('__kla_id', $matches, true), array_search('_fbc', $matches, true));
        // _fbp keeps its existing (first) descriptor.
        $this->assertSame('Meta Pixel', $known[array_search('_fbp', $matches, true)]['name']);
        $scan = TrackWP_Cookie_Scanner::scan(array('__kla_id', 'sbjs_first', 'tk_ai'));
        $this->assertEmpty($scan['unclassified']);
        $this->assertStringContainsString('tk_ai', implode(',', wp_list_pluck($scan['statistics'], 'cookies')));
        $this->assertStringContainsString('__kla_id', implode(',', wp_list_pluck($scan['marketing'], 'cookies')));
        $this->assertStringContainsString('sbjs_first', implode(',', wp_list_pluck($scan['marketing'], 'cookies')));
    }
}
