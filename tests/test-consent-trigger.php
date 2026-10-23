<?php
/**
 * Tests for the floating consent trigger button: position setting.
 *
 * Producers: TrackWP_Consent::render_consent_trigger(),
 * TrackWP_Settings::sanitize_consent(), TrackWP_Consent_Profile::banner_hash()
 * and material_hash().
 */

class TrackWP_Consent_Trigger_Test extends WP_UnitTestCase {

    public function tear_down() {
        delete_option( 'trackwp_consent' );
        remove_all_filters( 'trackwp_show_consent_trigger' );
        parent::tear_down();
    }

    private function render() {
        ob_start();
        ( new TrackWP_Consent() )->render_consent_trigger();
        return ob_get_clean();
    }

    public function test_missing_key_renders_right() {
        update_option( 'trackwp_consent', array( 'consent_version' => 3 ) );
        $html = $this->render();
        $this->assertStringContainsString( 'trackwp-consent-trigger--right', $html );
        $this->assertStringNotContainsString( 'trackwp-consent-trigger--left', $html );
        $this->assertStringContainsString( 'class="trackwp-consent-trigger ', $html );
    }

    public function test_left_renders_left() {
        update_option( 'trackwp_consent', array( 'trigger_position' => 'left' ) );
        $html = $this->render();
        $this->assertStringContainsString( 'trackwp-consent-trigger--left', $html );
        $this->assertStringNotContainsString( 'trackwp-consent-trigger--right', $html );

        update_option( 'trackwp_consent', array( 'trigger_position' => 'top' ) );
        $html = $this->render();
        $this->assertStringContainsString( 'trackwp-consent-trigger--right', $html );
        $this->assertStringNotContainsString( 'trackwp-consent-trigger--left', $html );
    }

    public function test_filter_hides_trigger() {
        add_filter( 'trackwp_show_consent_trigger', '__return_false' );
        $this->assertSame( '', $this->render() );
    }

    public function test_trigger_position_does_not_change_hashes_or_version() {
        $settings = new TrackWP_Settings();
        update_option( 'trackwp_consent', $settings->sanitize_consent( array( 'controller_name' => 'X ApS' ) ) );
        $banner   = TrackWP_Consent_Profile::banner_hash();
        $material = TrackWP_Consent_Profile::material_hash();
        $version  = TrackWP_Consent::server_version();

        update_option( 'trackwp_consent', $settings->sanitize_consent( array(
            'controller_name'  => 'X ApS',
            'trigger_position' => 'left',
        ) ) );

        $this->assertSame( 'left', get_option( 'trackwp_consent' )['trigger_position'] );
        $this->assertSame( $banner, TrackWP_Consent_Profile::banner_hash() );
        $this->assertSame( $material, TrackWP_Consent_Profile::material_hash() );
        $this->assertSame( $version, TrackWP_Consent::server_version() );
    }
}
