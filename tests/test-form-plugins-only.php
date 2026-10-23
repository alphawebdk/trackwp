<?php
/**
 * plugins_only on form_submit triggers: validation and the server-side
 * HTML-fallback gating in TrackWP_Forms.
 *
 * @group forms
 */
class TrackWP_Form_Plugins_Only_Test extends WP_UnitTestCase {

    public function tear_down() {
        delete_option( 'trackwp_events' );
        parent::tear_down();
    }

    private function trigger( $type, array $extra = array() ) {
        return array_merge( array( 'type' => $type, 'css_selector' => $type === 'css_click' ? 'a.x' : '' ), $extra );
    }

    private function listeners_html() {
        $forms = new TrackWP_Forms();
        ob_start();
        $forms->output_form_listeners();
        return ob_get_clean();
    }

    // Validation ------------------------------------------------------

    public function test_form_submit_keeps_true_flag() {
        foreach ( array( true, 'true', 1, '1' ) as $v ) {
            $out = TrackWP_Conditions::validate_trigger( $this->trigger( 'form_submit', array( 'plugins_only' => $v ) ) );
            $this->assertTrue( $out['plugins_only'], var_export( $v, true ) );
        }
    }

    public function test_flag_absent_when_false_or_missing() {
        foreach ( array( false, 'false', 0, '0', '', null ) as $v ) {
            $out = TrackWP_Conditions::validate_trigger( $this->trigger( 'form_submit', array( 'plugins_only' => $v ) ) );
            $this->assertArrayNotHasKey( 'plugins_only', $out, var_export( $v, true ) );
        }
        $out = TrackWP_Conditions::validate_trigger( $this->trigger( 'form_submit' ) );
        $this->assertArrayNotHasKey( 'plugins_only', $out );
    }

    public function test_flag_dropped_on_other_trigger_types() {
        $out = TrackWP_Conditions::validate_trigger( $this->trigger( 'css_click', array( 'plugins_only' => true ) ) );
        $this->assertArrayNotHasKey( 'plugins_only', $out );
    }

    public function test_flag_survives_validate_event_roundtrip() {
        $event = TrackWP_Events::validate_event( array(
            'name'            => 'lead_x',
            'trigger_type'    => 'form_submit',
            'firing_triggers' => array( $this->trigger( 'form_submit', array( 'plugins_only' => true ) ) ),
        ) );
        $this->assertNotWPError( $event );
        $this->assertTrue( $event['firing_triggers'][0]['plugins_only'] );
    }

    public function test_legacy_row_gets_no_flag() {
        $event = TrackWP_Events::validate_event( array( 'name' => 'old_lead', 'trigger_type' => 'form_submit' ) );
        $this->assertArrayNotHasKey( 'plugins_only', $event['firing_triggers'][0] );
    }

    // Helpers ---------------------------------------------------------

    public function test_needs_html_fallback_matrix() {
        $on  = array( 'enabled' => true, 'trigger_type' => 'form_submit', 'firing_triggers' => array( $this->trigger( 'form_submit', array( 'plugins_only' => true ) ) ) );
        $old = array( 'enabled' => true, 'trigger_type' => 'form_submit', 'firing_triggers' => array( $this->trigger( 'form_submit' ) ) );
        $flat = array( 'enabled' => true, 'trigger_type' => 'form_submit' );
        $off = $old;
        $off['enabled'] = false;
        $click = array( 'enabled' => true, 'trigger_type' => 'css_click', 'firing_triggers' => array( $this->trigger( 'css_click' ) ) );

        $this->assertTrue( TrackWP_Forms::has_form_submit_trigger( array( $on ) ) );
        $this->assertFalse( TrackWP_Forms::needs_html_fallback( array( $on ) ) );
        $this->assertTrue( TrackWP_Forms::needs_html_fallback( array( $old ) ) );
        $this->assertTrue( TrackWP_Forms::needs_html_fallback( array( $flat ) ) );
        $this->assertTrue( TrackWP_Forms::needs_html_fallback( array( $on, $old ) ) );
        $this->assertFalse( TrackWP_Forms::needs_html_fallback( array( $off ) ) );
        $this->assertFalse( TrackWP_Forms::has_form_submit_trigger( array( $off ) ) );
        $this->assertFalse( TrackWP_Forms::has_form_submit_trigger( array( $click ) ) );
        $this->assertFalse( TrackWP_Forms::needs_html_fallback( array( $click ) ) );
    }

    public function test_secondary_form_submit_trigger_is_seen() {
        $event = array(
            'enabled'         => true,
            'trigger_type'    => 'css_click',
            'firing_triggers' => array( $this->trigger( 'css_click' ), $this->trigger( 'form_submit' ) ),
        );
        $this->assertTrue( TrackWP_Forms::has_form_submit_trigger( array( $event ) ) );
        $this->assertTrue( TrackWP_Forms::needs_html_fallback( array( $event ) ) );
    }

    // Output ----------------------------------------------------------

    public function test_output_omits_html_fallback_when_all_plugins_only() {
        update_option( 'trackwp_events', array( TrackWP_Events::validate_event( array(
            'name'            => 'lead_x',
            'enabled'         => true,
            'trigger_type'    => 'form_submit',
            'firing_triggers' => array( $this->trigger( 'form_submit', array( 'plugins_only' => true ) ) ),
        ) ) ) );
        $html = $this->listeners_html();
        $this->assertStringContainsString( 'window.trackwp.sendFormEvent', $html );
        $this->assertStringNotContainsString( 'HTML fallback', $html );
    }

    public function test_output_keeps_html_fallback_for_old_row() {
        update_option( 'trackwp_events', array( TrackWP_Events::validate_event( array(
            'name'         => 'lead_x',
            'enabled'      => true,
            'trigger_type' => 'form_submit',
        ) ) ) );
        $this->assertStringContainsString( 'HTML fallback', $this->listeners_html() );
    }

    public function test_producer_plugin_names_match_client_form_plugins() {
        $php = file_get_contents( ( new ReflectionClass( 'TrackWP_Forms' ) )->getFileName() );
        preg_match_all( "/send\([^;]*?,\s*'([a-z0-9]+)',\s*(?:true|false)/", $php, $m );
        $producer = array_values( array_diff( array_unique( $m[1] ), array( 'html' ) ) );
        sort( $producer );

        $js = file_get_contents( dirname( __DIR__ ) . '/assets/js/trackwp.js' );
        $this->assertSame( 1, preg_match( "/var FORM_PLUGINS = \[([^\]]*)\]/", $js, $jm ) );
        preg_match_all( "/'([a-z0-9]+)'/", $jm[1], $cm );
        $client = $cm[1];
        sort( $client );

        $this->assertSame( array( 'cf7', 'fluentforms', 'gravityforms', 'sureforms', 'wpforms' ), $producer );
        $this->assertSame( $client, $producer );
    }

    public function test_output_empty_without_form_submit_trigger() {
        update_option( 'trackwp_events', TrackWP_Events::get_install_defaults() );
        // The seed has an enabled plugins_only lead row: listeners present, no fallback.
        $html = $this->listeners_html();
        $this->assertStringContainsString( 'sendFormEvent', $html );
        $this->assertStringNotContainsString( 'HTML fallback', $html );

        update_option( 'trackwp_events', array() );
        $this->assertSame( '', trim( $this->listeners_html() ) );
    }
}
