<?php
/**
 * Meta Graph API version survives a settings save (plan 1.10.1: v25.0,
 * BESLUTNINGER §6). The 1.10.1 upgrade lifts stored v21.0-v24.0 to v25.0;
 * saving the platform settings afterwards must not push it back.
 */

class TrackWP_Meta_Version_Settings_Test extends WP_UnitTestCase {

    public function test_saving_platforms_keeps_migrated_v25() {
        $settings = new TrackWP_Settings();
        $saved    = $settings->sanitize_platforms( array( 'meta_api_version' => 'v25.0' ) );
        $this->assertSame( 'v25.0', $saved['meta_api_version'] );
    }

    public function test_missing_or_invalid_version_falls_back_to_current_default() {
        $settings = new TrackWP_Settings();
        $this->assertSame( TrackWP_Meta::DEFAULT_API_VERSION, $settings->sanitize_platforms( array() )['meta_api_version'] );
        $this->assertSame( TrackWP_Meta::DEFAULT_API_VERSION, $settings->sanitize_platforms( array( 'meta_api_version' => 'v99.x' ) )['meta_api_version'] );
    }
}
