<?php
/**
 * Sample test — verifies plugin constants and core class are loaded.
 */

class TrackWP_Sample_Test extends WP_UnitTestCase {

    public function test_plugin_version_constant() {
        $this->assertTrue( defined( 'TRACKWP_VERSION' ) );
        $this->assertSame( '1.1.0', TRACKWP_VERSION );
    }

    public function test_main_class_exists() {
        $this->assertTrue( class_exists( 'TrackWP' ) );
    }

    public function test_settings_class_exists() {
        $this->assertTrue( class_exists( 'TrackWP_Settings' ) );
    }

    public function test_endpoint_slug_sanitizer() {
        $this->assertSame( 'event', TrackWP_Settings::sanitize_endpoint_slug( '' ) );
        $this->assertSame( 'event', TrackWP_Settings::sanitize_endpoint_slug( 'consent-log' ) );
        $this->assertSame( 'metrics', TrackWP_Settings::sanitize_endpoint_slug( 'metrics' ) );
        $this->assertSame( 'my-event', TrackWP_Settings::sanitize_endpoint_slug( 'My Event!@#' ) );
    }

    public function test_default_platforms_have_gtm_keys() {
        $defaults = get_option( 'trackwp_platforms', array() );
        $this->assertArrayHasKey( 'gtm_enabled', $defaults );
        $this->assertArrayHasKey( 'gtm_container_id', $defaults );
        $this->assertFalse( (bool) $defaults['gtm_enabled'] );
    }
}
