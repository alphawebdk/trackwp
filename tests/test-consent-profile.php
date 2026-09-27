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
}
