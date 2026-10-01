<?php
/**
 * Tests for the 1.11.0 admin side of blocking (T5, PLAN-1.11.0-v2 §3.5, §5):
 * TrackWP_Settings::sanitize_blocker() (KB1, KB2, S13, S16), M1 in
 * sanitize_platforms(), the reserved "blocker" slug, the "Bed om nyt
 * samtykke" handler and the escaping of scan data in the admin partial.
 *
 * Producers used:
 * - TrackWP_Consent_Profile::vendor_catalog() (includes/class-trackwp-consent-profile.php:256)
 *   for vendor keys and categories.
 * - TrackWP_Consent_Profile::material_hash() for the bump baseline.
 * - TrackWP_Settings::sanitize_blocker() itself as producer of the stored
 *   option that the partial renders.
 */

class TrackWP_Blocker_Settings_Test extends WP_UnitTestCase {

    public function tear_down() {
        delete_option( 'trackwp_blocker' );
        delete_option( 'trackwp_blocker_scan' );
        delete_option( 'trackwp_consent' );
        delete_option( 'trackwp_consent_material_hash' );
        delete_option( 'trackwp_platforms' );
        delete_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );
        remove_all_filters( 'pre_http_request' );
        remove_all_filters( 'wp_redirect' );
        unset( $_REQUEST['_wpnonce'], $_GET['trackwp_bumped'] );
        parent::tear_down();
    }

    private function sanitize( $input ) {
        $settings = new TrackWP_Settings();
        return $settings->sanitize_blocker( $input );
    }

    private function catalog_vendor() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $this->assertArrayHasKey( 'meta', $catalog );
        return array( 'meta', $catalog['meta']['category'] );
    }

    public function test_defaults_are_mode_off_and_empty() {
        $out = $this->sanitize( 'not-an-array' );
        $this->assertSame( TrackWP_Settings::blocker_defaults(), $out );
        $this->assertSame( 'off', $out['mode'] );

        $out = $this->sanitize( array() );
        $this->assertSame( 'off', $out['mode'] );
        $this->assertSame( array( 'allow' => array(), 'paths' => array() ), $out['exceptions'] );
    }

    public function test_mode_whitelist() {
        $this->assertTrue( TrackWP_Blocker::html_api_available(), 'Test WP must be 6.5+.' );
        foreach ( array( 'off', 'test', 'on' ) as $mode ) {
            $this->assertSame( $mode, $this->sanitize( array( 'mode' => $mode ) )['mode'] );
        }
        foreach ( array( 'preview', 'enabled', '1', '', array( 'on' ) ) as $bad ) {
            $this->assertSame( 'off', $this->sanitize( array( 'mode' => $bad ) )['mode'], var_export( $bad, true ) );
        }
    }

    public function test_rule_id_validation() {
        $valid = array(
            'handle:sourcebuster-js',
            'url:static.klaviyo.com/onsite/js/',
            'host:cdn.leadinfo.net',
            'inline:sleeknoteScript',
            'pixel:www.facebook.com/tr',
        );
        foreach ( $valid as $id ) {
            $this->assertSame( $id, TrackWP_Settings::sanitize_blocker_rule_id( $id ), $id );
        }
        $invalid = array(
            'cdn.leadinfo.net',            // no prefix (S16)
            'script:foo.js',               // unknown prefix
            'handle:ab',                   // too short
            'host:evil.com?x=<script>',    // characters outside KB2
            'inline:short',                // marker below 8 characters
            'url:' . str_repeat( 'a', 201 ),
            array( 'host:foo.com' ),
        );
        foreach ( $invalid as $id ) {
            $this->assertSame( '', TrackWP_Settings::sanitize_blocker_rule_id( $id ), var_export( $id, true ) );
        }
    }

    public function test_rules_keep_valid_ids_and_whitelist_category_and_vendor() {
        list( $vendor, $vendor_category ) = $this->catalog_vendor();

        $out = $this->sanitize( array(
            'rules' => array(
                'host:connect.facebook.net' => array( 'block' => '1', 'category' => 'bogus', 'vendor' => $vendor ),
                'host:cdn.leadinfo.net'     => array( 'block' => '1', 'category' => 'statistics', 'vendor' => 'no_such_vendor' ),
                'handle:jquery-core'        => array( 'block' => '1', 'category' => 'necessary', 'vendor' => '' ),
                'handle:tp-js'              => array( 'block' => '0', 'category' => 'marketing' ),
                'bad id'                    => array( 'block' => '1', 'category' => 'marketing' ),
            ),
        ) );

        $this->assertSame(
            array( 'host:connect.facebook.net', 'host:cdn.leadinfo.net', 'handle:jquery-core', 'handle:tp-js' ),
            array_keys( $out['rules'] )
        );
        // Invalid category falls back to the catalog category of the vendor.
        $this->assertSame( array( 'block' => true, 'category' => $vendor_category, 'vendor' => $vendor ), $out['rules']['host:connect.facebook.net'] );
        // Unknown vendor becomes '' ("uafklaret").
        $this->assertSame( '', $out['rules']['host:cdn.leadinfo.net']['vendor'] );
        $this->assertSame( 'statistics', $out['rules']['host:cdn.leadinfo.net']['category'] );
        // Necessary is never blocked.
        $this->assertFalse( $out['rules']['handle:jquery-core']['block'] );
        $this->assertFalse( $out['rules']['handle:tp-js']['block'] );
    }

    public function test_new_inline_marker_allowlist() {
        $out = $this->sanitize( array( 'new_inline' => array( 'marker' => 'sleeknoteScript', 'category' => 'marketing' ) ) );
        $this->assertSame( array( 'block' => true, 'category' => 'marketing', 'vendor' => '' ), $out['rules']['inline:sleeknoteScript'] );

        foreach ( array( 'short', 'has space in it', 'quote"marker1', str_repeat( 'x', 65 ) ) as $bad ) {
            $out = $this->sanitize( array( 'new_inline' => array( 'marker' => $bad ) ) );
            $this->assertSame( array(), $out['rules'], "Marker '{$bad}'" );
        }
    }

    public function test_exceptions_allow_only_prefixed_rule_ids_s16() {
        $out = $this->sanitize( array(
            'exceptions' => array(
                'allow' => array(
                    array( 'type' => 'host', 'value' => 'https://CDN.Example.com:443/lib.js?v=1' ),
                    array( 'type' => 'url', 'value' => '//Static.Klaviyo.com/onsite/JS/x.js#frag' ),
                    array( 'type' => 'handle', 'value' => 'handle:tp-js' ),
                    array( 'type' => 'inline', 'value' => 'sleeknoteScript' ),
                    array( 'type' => 'pixel', 'value' => 'www.facebook.com/tr' ),
                    array( 'type' => 'host', 'value' => '' ),         // empty form row
                    array( 'type' => 'script', 'value' => 'foo.com' ), // unknown type
                    'host:allowed.example.com',                        // import form
                    'unprefixed.example.com',                          // rejected (S16)
                    'host:allowed.example.com',                        // duplicate
                ),
            ),
        ) );

        $this->assertSame(
            array(
                'host:cdn.example.com',
                'url:static.klaviyo.com/onsite/JS/x.js',
                'handle:tp-js',
                'inline:sleeknoteScript',
                'pixel:www.facebook.com/tr',
                'host:allowed.example.com',
            ),
            $out['exceptions']['allow']
        );
        foreach ( $out['exceptions']['allow'] as $id ) {
            $this->assertTrue( TrackWP_Blocker_Rules::is_valid_rule_id( $id ), $id );
        }
    }

    public function test_paths_accept_only_site_paths() {
        $out = $this->sanitize( array(
            'exceptions'  => array( 'paths' => "/kasse/?step=2\nhttps://evil.example/x\n//evil.example/x\nrelative/path\n/a/../b\n/kasse/\n/min-konto/" ),
            'extra_paths' => array( '/shop/', '/kontakt/#form', '/blog/', '/om-os/', '/femte/', 'http://x.dk/y' ),
        ) );

        $this->assertSame( array( '/kasse/', '/min-konto/' ), $out['exceptions']['paths'] );
        // S13: at most 4 extra paths (the front page is always page 1).
        $this->assertSame( 4, TrackWP_Settings::BLOCKER_MAX_EXTRA_PATHS );
        $this->assertSame( array( '/shop/', '/kontakt/', '/blog/', '/om-os/' ), $out['extra_paths'] );
    }

    /**
     * Review B1: the custom vendor id is the profile's own key
     * (TrackWP_Consent_Profile::normalize_custom_vendor($e, 'blk_')['key']).
     * Producer to consumer: saved via sanitize_blocker(), read back via
     * TrackWP_Consent_Profile::blocker_vendors() and active_vendors().
     */
    public function test_custom_vendor_key_matches_profile_and_is_declared_once() {
        $first = $this->sanitize( array(
            'custom_vendors' => array(
                array( 'name' => 'Partner Ads', 'provider' => 'Partner-ads ApS', 'category' => 'marketing' ),
                array( 'name' => '' ),
            ),
        ) );
        $this->assertCount( 1, $first['custom_vendors'] );
        $key = $first['custom_vendors'][0]['key'];
        $this->assertSame(
            TrackWP_Consent_Profile::normalize_custom_vendor( array( 'name' => 'Partner Ads', 'category' => 'marketing' ), 'blk_' )['key'],
            $key
        );

        // Second save from the form: the admin assigns the finding to the vendor and blocks it.
        $second = $this->sanitize( array(
            'custom_vendors' => $first['custom_vendors'],
            'rules'          => array( 'handle:partner-ads-woocommerce' => array( 'block' => '1', 'category' => 'marketing', 'vendor' => $key ) ),
        ) );
        $this->assertSame( $key, $second['rules']['handle:partner-ads-woocommerce']['vendor'] );
        update_option( 'trackwp_blocker', $second );
        update_option( 'trackwp_blocker_scan', array( 'items' => array(
            array( 'rule_id' => 'handle:partner-ads-woocommerce', 'kind' => 'script', 'handle' => 'partner-ads-woocommerce', 'vendor' => null, 'status' => 'allowed' ),
        ) ) );

        $declared = array_values( array_filter( TrackWP_Consent_Profile::blocker_vendors(), function ( $v ) {
            return isset( $v['name'] ) && 'Partner Ads' === $v['name'];
        } ) );
        $this->assertCount( 1, $declared );
        $this->assertTrue( $declared[0]['blocked'] );
        foreach ( array_keys( TrackWP_Consent_Profile::blocker_vendors() ) as $vendor_key ) {
            $this->assertDoesNotMatchRegularExpression( '/^blk_[0-9a-f]{10}$/', (string) $vendor_key );
        }

        $active = array_filter( TrackWP_Consent_Profile::active_vendors(), function ( $v ) {
            return is_array( $v ) && isset( $v['name'] ) && 'Partner Ads' === $v['name'];
        } );
        $this->assertCount( 1, $active );
    }

    /**
     * Review MINOR 2: the form sends the stored key along with the row. A
     * rename changes the key, and the rule follows to the new key.
     */
    public function test_renamed_custom_vendor_keeps_its_rules() {
        $saved = $this->sanitize( array(
            'custom_vendors' => array( array( 'name' => 'Partner Ads', 'category' => 'marketing' ) ),
            'rules'          => array(),
        ) );
        $old_key = $saved['custom_vendors'][0]['key'];
        $saved   = $this->sanitize( array(
            'custom_vendors' => $saved['custom_vendors'],
            'rules'          => array( 'handle:partner-ads-woocommerce' => array( 'block' => '1', 'category' => 'marketing', 'vendor' => $old_key ) ),
        ) );

        // What the form posts after the admin renamed the vendor: hidden key + new name.
        $row         = $saved['custom_vendors'][0];
        $row['name'] = 'Partner Ads DK';
        $out = $this->sanitize( array(
            'custom_vendors' => array( $row ),
            'rules'          => array( 'handle:partner-ads-woocommerce' => array( 'block' => '1', 'category' => 'marketing', 'vendor' => $old_key ) ),
        ) );

        $new_key = TrackWP_Consent_Profile::normalize_custom_vendor( array( 'name' => 'Partner Ads DK', 'category' => 'marketing' ), 'blk_' )['key'];
        $this->assertNotSame( $old_key, $new_key );
        $this->assertSame( $new_key, $out['custom_vendors'][0]['key'] );
        $this->assertSame( $new_key, $out['rules']['handle:partner-ads-woocommerce']['vendor'] );
        $this->assertTrue( $out['rules']['handle:partner-ads-woocommerce']['block'] );

        // The partial posts that key as a hidden field.
        update_option( 'trackwp_blocker', $out );
        $this->assertStringContainsString( 'name="trackwp_blocker[custom_vendors][0][key]" value="' . $new_key . '"', $this->render_partial() );
    }

    /**
     * Review MINOR 3: a second name that normalises to the same key is not
     * dropped silently; the admin gets a settings error naming it.
     */
    public function test_duplicate_custom_vendor_key_reports_error() {
        global $wp_settings_errors;
        $wp_settings_errors = array();

        $out = $this->sanitize( array(
            'custom_vendors' => array(
                array( 'name' => 'Partner Ads', 'category' => 'marketing' ),
                array( 'name' => 'PARTNER ADS', 'category' => 'marketing' ),
            ),
        ) );
        $this->assertCount( 1, $out['custom_vendors'] );
        $this->assertSame( 'Partner Ads', $out['custom_vendors'][0]['name'] );

        $errors = wp_list_filter( get_settings_errors( 'trackwp_blocker' ), array( 'code' => 'blocker_vendor_duplicate' ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'PARTNER ADS', reset( $errors )['message'] );
    }

    /**
     * Review MINOR 5: a stored Graph token error is dropped when a new
     * token is saved, and kept when the token field is empty or masked.
     */
    public function test_new_meta_token_clears_stored_token_error() {
        $settings = new TrackWP_Settings();
        update_option( 'trackwp_platforms', $settings->sanitize_platforms( array( 'meta_access_token' => 'EAAB-old' ) ) );
        $error = array( 'time' => time(), 'http_code' => 401, 'code' => 190, 'message' => 'expired' );

        foreach ( array( '', '••••••••' ) as $unchanged ) {
            update_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION, $error, false );
            $settings->sanitize_platforms( array( 'meta_access_token' => $unchanged ) );
            $this->assertSame( $error, get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION ), var_export( $unchanged, true ) );
        }

        $settings->sanitize_platforms( array( 'meta_access_token' => 'EAAB-new' ) );
        $this->assertFalse( get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION ) );
    }

    private function render_partial() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/template.php';
        $platforms = array();
        ob_start();
        include TRACKWP_PLUGIN_DIR . 'templates/partials/admin-blocker.php';
        return ob_get_clean();
    }

    private static function meta_config( $overrides = array() ) {
        return array_merge( array(
            'meta_enabled'              => 1,
            'meta_pixel_id'             => '1234567890',
            'meta_pixel_client_enabled' => true,
            'meta_pixel_with_gtm'       => true,
            'meta_access_token'         => TrackWP_Hash::encode( 'EAAB-token' ),
        ), $overrides );
    }

    /**
     * MINOR 1: the token-error notice reads the keys the real producer
     * (TrackWP_Meta::record_token_error(), via send_event() on a Graph 401/190)
     * stores: http_code, code, message and time.
     */
    public function test_meta_token_error_notice_reads_producer_keys() {
        update_option( 'trackwp_platforms', self::meta_config() );
        add_filter( 'pre_http_request', function () {
            return array(
                'headers'  => array(),
                'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190, 'fbtrace_id' => 'AbC123' ) ) ),
                'response' => array( 'code' => 401, 'message' => '' ),
                'cookies'  => array(),
                'filename' => null,
            );
        } );
        ( new TrackWP_Meta() )->send_event( array(
            'event'      => 'form_submit',
            'event_id'   => 'evt_' . str_repeat( 'c', 32 ),
            'page_url'   => 'https://example.com/kontakt',
            'user_agent' => 'Mozilla/5.0 Test',
            'consent'    => array( 'analytics' => false, 'marketing' => true, 'v' => 1 ),
        ) );
        $stored = get_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );
        $this->assertIsArray( $stored, 'Producer did not record the token error.' );

        $text = implode( ' ', TrackWP_Settings::meta_takeover_notices( TrackWP_Meta_Takeover::status() ) );
        $this->assertStringContainsString( 'HTTP 401', $text );
        $this->assertStringContainsString( '190', $text );
        $this->assertStringContainsString( 'Error validating access token', $text );
        $this->assertStringContainsString( wp_date( 'Y-m-d H:i', (int) $stored['time'] ), $text );
        $this->assertStringNotContainsString( 'ukendt fejl', $text );
    }

    /**
     * M2 text: for each single missing condition taken from the real
     * status(), the notice names it with a label (never the raw code), and
     * "sporer fortsat selv" / "forbliver slået fra" only appear when true.
     */
    public function test_meta_missing_notice_is_true_for_every_condition() {
        $cases = array(
            array( 'meta_enabled' => 0 ),
            array( 'meta_pixel_id' => '' ),
            array( 'meta_pixel_client_enabled' => false ),
            array( 'meta_access_token' => '' ),
        );
        $labels = TrackWP_Settings::meta_missing_labels();
        foreach ( $cases as $override ) {
            update_option( 'trackwp_platforms', self::meta_config( $override ) );
            $status = TrackWP_Meta_Takeover::status();
            $this->assertNotEmpty( $status['missing'] );
            foreach ( $status['missing'] as $code ) {
                $this->assertArrayHasKey( $code, $labels, "No label for missing code {$code}" );
            }
            foreach ( array( true, false ) as $fb4woo ) {
                $status['fb4woo_active'] = $fb4woo;
                $text = implode( ' ', TrackWP_Settings::meta_takeover_notices( $status ) );
                foreach ( $status['missing'] as $code ) {
                    $this->assertStringContainsString( $labels[ $code ], $text );
                }
                $this->assertSame( $fb4woo, false !== strpos( $text, 'sporer derfor fortsat selv' ) );
                $this->assertStringNotContainsString( 'forbliver slået fra', $text );
            }
        }

        // woo_events (review M2): real status() with WooCommerce events off.
        $this->assertArrayHasKey( 'woo_events', $labels );
        update_option( 'trackwp_platforms', self::meta_config() );
        update_option( 'trackwp_woocommerce', array( 'enabled' => false ) );
        $status = TrackWP_Meta_Takeover::status();
        delete_option( 'trackwp_woocommerce' );
        if ( in_array( 'woo_events', $status['missing'], true ) ) {
            $text = implode( ' ', TrackWP_Settings::meta_takeover_notices( $status ) );
            $this->assertStringContainsString( $labels['woo_events'], $text );
            $this->assertStringNotContainsString( 'woo_events', $text );
        }

        // Delivering with a rejected token: "fb4woo stays off" only when it is loaded.
        $error = array( 'time' => time(), 'http_code' => 401, 'code' => 190, 'message' => 'x' );
        $on    = TrackWP_Settings::meta_takeover_notices( array( 'delivering' => true, 'fb4woo_active' => true, 'missing' => array(), 'last_error' => $error ) );
        $this->assertCount( 1, $on );
        $this->assertStringContainsString( 'forbliver slået fra', $on[0] );
        $off = TrackWP_Settings::meta_takeover_notices( array( 'delivering' => true, 'fb4woo_active' => false, 'missing' => array(), 'last_error' => $error ) );
        $this->assertStringNotContainsString( 'Meta for WooCommerce', $off[0] );
        $this->assertSame( array(), TrackWP_Settings::meta_takeover_notices( array( 'delivering' => true, 'fb4woo_active' => true, 'missing' => array(), 'last_error' => null ) ) );
    }

    public function test_blocker_slug_is_reserved() {
        $this->assertSame( 'event', TrackWP_Settings::sanitize_endpoint_slug( 'blocker' ) );
        $this->assertSame( 'track', TrackWP_Settings::sanitize_endpoint_slug( 'track' ) );
    }

    public function test_meta_pixel_with_gtm_is_opt_in() {
        $settings = new TrackWP_Settings();
        $this->assertFalse( $settings->sanitize_platforms( array() )['meta_pixel_with_gtm'] );
        $this->assertFalse( $settings->sanitize_platforms( array( 'meta_pixel_with_gtm' => '0' ) )['meta_pixel_with_gtm'] );
        $this->assertTrue( $settings->sanitize_platforms( array( 'meta_pixel_with_gtm' => '1' ) )['meta_pixel_with_gtm'] );
    }

    public function test_bump_requires_capability() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'trackwp_bump_consent' );
        update_option( 'trackwp_consent', array( 'consent_version' => 3 ) );

        try {
            TrackWP_Settings::handle_bump_consent();
            $this->fail( 'Expected wp_die for a user without manage_options.' );
        } catch ( WPDieException $e ) {
            $this->assertSame( 3, (int) get_option( 'trackwp_consent' )['consent_version'] );
        }
    }

    public function test_bump_requires_nonce() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_REQUEST['_wpnonce'] = 'invalid';
        update_option( 'trackwp_consent', array( 'consent_version' => 3 ) );

        try {
            TrackWP_Settings::handle_bump_consent();
            $this->fail( 'Expected wp_die for an invalid nonce.' );
        } catch ( WPDieException $e ) {
            $this->assertSame( 3, (int) get_option( 'trackwp_consent' )['consent_version'] );
        }
    }

    public function test_bump_increments_version_and_updates_baseline() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'trackwp_bump_consent' );

        // Stored through the real sanitizer (as admin saves it), then an
        // outdated baseline.
        $settings = new TrackWP_Settings();
        update_option( 'trackwp_consent', $settings->sanitize_consent( array( 'controller_name' => 'Firma ApS' ) ) );
        $before = TrackWP_Consent::server_version();
        update_option( 'trackwp_consent_material_hash', 'outdated' );

        $redirect = null;
        add_filter( 'wp_redirect', function ( $location ) use ( &$redirect ) {
            $redirect = $location;
            throw new RuntimeException( 'redirect' );
        } );

        try {
            TrackWP_Settings::handle_bump_consent();
            $this->fail( 'Expected a redirect.' );
        } catch ( RuntimeException $e ) {
            $this->assertSame( 'redirect', $e->getMessage() );
        }

        $this->assertSame( $before + 1, TrackWP_Consent::server_version() );
        $this->assertSame( 'Firma ApS', get_option( 'trackwp_consent' )['controller_name'] );
        $this->assertSame( TrackWP_Consent_Profile::material_hash(), get_option( 'trackwp_consent_material_hash' ) );
        $this->assertStringContainsString( 'trackwp_bumped=' . ( $before + 1 ), $redirect );

        // A normal settings save afterwards keeps the bumped version.
        update_option( 'trackwp_consent', $settings->sanitize_consent( get_option( 'trackwp_consent' ) ) );
        $this->assertSame( $before + 1, TrackWP_Consent::server_version() );
    }

    /**
     * Scan data is attacker-influenced (it comes from fetched HTML), so the
     * partial must escape it everywhere. The scan option is hand-built here
     * because it only needs to carry hostile strings in every KB3 field that
     * the table prints.
     */
    public function test_partial_escapes_scan_data_and_has_hidden_zero_per_toggle() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/template.php';

        $xss = '<img src=x onerror=alert(1)>';
        update_option( 'trackwp_blocker', ( new TrackWP_Settings() )->sanitize_blocker( array(
            'mode'  => 'test',
            'rules' => array( 'host:cdn.leadinfo.net' => array( 'block' => '1', 'category' => 'marketing' ) ),
        ) ) );
        update_option( 'trackwp_blocker_scan', array(
            'scanned_at'   => time(),
            'pages'        => array( '/' . $xss ),
            'items'        => array(
                array(
                    'obs_id' => 'abc', 'rule_id' => 'host:cdn.leadinfo.net', 'kind' => 'script',
                    'host' => 'cdn.leadinfo.net', 'path' => '/ping.js' . $xss, 'handle' => $xss,
                    'plugin' => $xss, 'deps' => array(), 'dependents' => array( $xss ), 'marker' => '',
                    'pages' => array( '/' . $xss ), 'vendor' => null, 'category_guess' => 'marketing',
                    'status' => 'blocked', 'seen_blocked' => null,
                ),
            ),
            'meta_sources' => array( 'fbevents.js' . $xss, 'gtm-server-side' ),
            'health'       => array( 'checked_at' => time(), 'observed' => array( 'host:cdn.leadinfo.net' => true ) ),
        ) );

        $platforms = array();
        ob_start();
        include TRACKWP_PLUGIN_DIR . 'templates/partials/admin-blocker.php';
        $html = ob_get_clean();

        $this->assertStringNotContainsString( $xss, $html );
        $this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
        $this->assertStringContainsString( 'name="trackwp_blocker[rules][host:cdn.leadinfo.net][block]" value="0"', $html );
        $this->assertMatchesRegularExpression( '/<input type="checkbox" name="trackwp_blocker\[rules\]\[host:cdn\.leadinfo\.net\]\[block\]" value="1"[^>]*checked/s', $html );
        $this->assertStringContainsString( 'id="tab-blocker"', $html );
    }

    /**
     * 1.11.1 KC1/KC7/KC8: fb4woo_tracking_off, gtm_datalayer_events (forced
     * off without GTM) and ga4_source.
     */
    public function test_fb4woo_tracking_off_is_opt_in() {
        $settings = new TrackWP_Settings();
        $this->assertFalse( $settings->sanitize_platforms( array() )['fb4woo_tracking_off'] );
        $this->assertTrue( $settings->sanitize_platforms( array( 'fb4woo_tracking_off' => '1' ) )['fb4woo_tracking_off'] );
    }

    public function test_gtm_datalayer_events_whitelist_and_forced_off_without_gtm() {
        delete_option( 'trackwp_advanced' );
        $settings = new TrackWP_Settings();

        // No GTM at all: always forced to 'off', regardless of what was posted.
        foreach ( array( 'off', 'test', 'on', 'bogus' ) as $requested ) {
            $out = $settings->sanitize_platforms( array( 'gtm_datalayer_events' => $requested ) );
            $this->assertSame( 'off', $out['gtm_datalayer_events'], "requested={$requested}" );
        }

        // gtm_enabled=1 in the same submit makes the mode honoured.
        $out = $settings->sanitize_platforms( array( 'gtm_enabled' => '1', 'gtm_container_id' => 'GTM-ABCD123', 'gtm_datalayer_events' => 'on' ) );
        $this->assertSame( 'on', $out['gtm_datalayer_events'] );
        $out = $settings->sanitize_platforms( array( 'gtm_enabled' => '1', 'gtm_container_id' => 'GTM-ABCD123', 'gtm_datalayer_events' => 'test' ) );
        $this->assertSame( 'test', $out['gtm_datalayer_events'] );
        $out = $settings->sanitize_platforms( array( 'gtm_enabled' => '1', 'gtm_container_id' => 'GTM-ABCD123', 'gtm_datalayer_events' => 'bogus' ) );
        $this->assertSame( 'off', $out['gtm_datalayer_events'] );

        // trackwp_advanced.uses_gtm (a separate declaration that GTM is in
        // play, e.g. loaded by the theme) also unlocks the mode.
        update_option( 'trackwp_advanced', $settings->sanitize_advanced( array( 'uses_gtm' => '1' ) ) );
        $out = $settings->sanitize_platforms( array( 'gtm_datalayer_events' => 'on' ) );
        $this->assertSame( 'on', $out['gtm_datalayer_events'] );
        delete_option( 'trackwp_advanced' );
    }

    public function test_ga4_source_whitelist_defaults_to_gtm() {
        $settings = new TrackWP_Settings();
        $this->assertSame( 'gtm', $settings->sanitize_platforms( array() )['ga4_source'] );
        $this->assertSame( 'split', $settings->sanitize_platforms( array( 'ga4_source' => 'split' ) )['ga4_source'] );
        $this->assertSame( 'gtm', $settings->sanitize_platforms( array( 'ga4_source' => 'bogus' ) )['ga4_source'] );
    }

    /**
     * KC13/F2: an unresolved category (invalid input, no catalog vendor)
     * sanitizes to '' ("Uafklaret") and forces block=false, instead of the
     * old silent fallback to "marketing".
     */
    public function test_unresolved_category_is_uafklaret_and_never_blocked() {
        $out = $this->sanitize( array(
            'rules' => array(
                'handle:some-unknown-script' => array( 'block' => '1', 'category' => 'bogus', 'vendor' => 'no_such_vendor' ),
            ),
        ) );
        $this->assertSame( '', $out['rules']['handle:some-unknown-script']['category'] );
        $this->assertFalse( $out['rules']['handle:some-unknown-script']['block'] );
    }

    /**
     * TR7: nocache_params defaults to the generic click-id list, accepts a
     * newline/comma separated textarea value, and is capped/validated.
     */
    public function test_nocache_params_defaults_and_sanitizes() {
        $out = $this->sanitize( array() );
        $this->assertSame( TrackWP_Settings::default_nocache_params(), $out['nocache_params'] );
        $this->assertContains( 'gclid', $out['nocache_params'] );

        $out = $this->sanitize( array( 'nocache_params' => "pacid\nfbclid, bad!param\n" . str_repeat( 'x', 41 ) ) );
        $this->assertSame( array( 'pacid', 'fbclid' ), $out['nocache_params'] );

        $many = array();
        for ( $i = 0; $i < 40; $i++ ) {
            $many[] = 'param' . $i;
        }
        $out = $this->sanitize( array( 'nocache_params' => $many ) );
        $this->assertCount( TrackWP_Settings::BLOCKER_MAX_NOCACHE_PARAMS, $out['nocache_params'] );
    }

    /**
     * KC2/D6: a cookie: rule that hits TrackWP_Cookie_Gate::NEVER is rejected
     * by the sanitizer, both via [rules] and via the manual "Tilføj
     * server-cookie" field (new_cookie). Uses the real producer
     * (TrackWP_Cookie_Gate::NEVER, W1) — skipped until that class lands.
     */
    public function test_cookie_rule_hitting_never_is_rejected() {
        if ( ! class_exists( 'TrackWP_Cookie_Gate' ) || ! defined( 'TrackWP_Cookie_Gate::NEVER' ) ) {
            $this->markTestSkipped( 'TrackWP_Cookie_Gate (W1) not loaded yet.' );
        }
        $never = TrackWP_Cookie_Gate::NEVER;
        $this->assertNotEmpty( $never, 'NEVER must not be empty.' );
        $protected = (string) $never[0];
        $literal   = rtrim( $protected, '*' );
        $this->assertNotSame( '', $literal, 'A NEVER pattern must not be an all-wildcard.' );

        $out = $this->sanitize( array(
            'rules' => array( 'cookie:' . $literal => array( 'block' => '1', 'category' => 'marketing' ) ),
        ) );
        $this->assertArrayNotHasKey( 'cookie:' . $literal, $out['rules'] );

        $out = $this->sanitize( array( 'new_cookie' => array( 'name' => $literal, 'category' => 'marketing' ) ) );
        $this->assertArrayNotHasKey( 'cookie:' . $literal, $out['rules'] );
    }

    /**
     * KC5.3: the manual "Tilføj server-cookie" field creates a cookie: rule
     * with block=true, the chosen category and vendor.
     */
    public function test_new_cookie_field_creates_a_cookie_rule() {
        $out = $this->sanitize( array(
            'new_cookie' => array( 'name' => 'partner_ads_query', 'category' => 'marketing', 'vendor' => '' ),
        ) );
        $this->assertSame(
            array( 'block' => true, 'category' => 'marketing', 'vendor' => '' ),
            $out['rules']['cookie:partner_ads_query']
        );
    }

    /**
     * The WooCommerce tab's event checkboxes are rendered by a loop over the
     * real producer TrackWP_Events::get_woocommerce_event_names()
     * (includes/class-trackwp-events.php) together with $woo_event_labels
     * (templates/settings-page.php). This proves the 1.11.1 events
     * view_item_list and view_cart actually get a checkbox with the hidden
     * "0" fallback, not just a label array entry.
     */
    public function test_woocommerce_tab_renders_view_item_list_and_view_cart_checkboxes() {
        $this->assertTrue( class_exists( 'WooCommerce' ), 'Test env must have WooCommerce loaded.' );
        $this->assertContains( 'view_item_list', TrackWP_Events::get_woocommerce_event_names() );
        $this->assertContains( 'view_cart', TrackWP_Events::get_woocommerce_event_names() );

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/template.php';
        ob_start();
        include TRACKWP_PLUGIN_DIR . 'templates/settings-page.php';
        $html = ob_get_clean();

        foreach ( array( 'event_view_item_list', 'event_view_cart' ) as $woo_key ) {
            $this->assertStringContainsString(
                'name="trackwp_woocommerce[' . $woo_key . ']" value="0"',
                $html,
                "Missing hidden-zero fallback for {$woo_key}"
            );
            $this->assertMatchesRegularExpression(
                '/<input type="checkbox"\s+name="trackwp_woocommerce\[' . preg_quote( $woo_key, '/' ) . '\]"/s',
                $html,
                "Missing checkbox for {$woo_key}"
            );
        }
    }
}
