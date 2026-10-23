<?php
/**
 * First-activation seed (TrackWP_Events::get_install_defaults) and the
 * FindLocation Meta event type.
 *
 * @group events
 */
class TrackWP_Install_Defaults_Test extends WP_UnitTestCase {

    public function test_seed_has_five_rows_with_expected_names_and_state() {
        $rows = TrackWP_Events::get_install_defaults();
        $this->assertCount( 5, $rows );
        $state = array();
        foreach ( $rows as $row ) {
            $state[ $row['name'] ] = $row['enabled'];
        }
        $this->assertSame(
            array(
                'phone_click'    => true,
                'email_click'    => true,
                'generate_lead'  => true,
                'whatsapp_click' => false,
                'find_location'  => false,
            ),
            $state
        );
    }

    public function test_every_row_survives_validate_event_unchanged() {
        foreach ( TrackWP_Events::get_install_defaults() as $row ) {
            $valid = TrackWP_Events::validate_event( $row );
            $this->assertNotWPError( $valid, $row['name'] );
            $this->assertEquals( $row, $valid, 'Seed row drifts from validate_event shape: ' . $row['name'] );
        }
    }

    public function test_meta_events_and_find_location_type() {
        $this->assertArrayHasKey( 'FindLocation', TrackWP_Events::get_meta_event_types() );
        $meta = array();
        foreach ( TrackWP_Events::get_install_defaults() as $row ) {
            $meta[ $row['name'] ] = $row['meta_event'];
        }
        $this->assertSame( 'Contact', $meta['phone_click'] );
        $this->assertSame( 'Contact', $meta['email_click'] );
        $this->assertSame( 'Lead', $meta['generate_lead'] );
        $this->assertSame( 'Contact', $meta['whatsapp_click'] );
        $this->assertSame( 'FindLocation', $meta['find_location'] );
    }

    public function test_lead_row_is_plugins_only_form_submit() {
        $rows = array();
        foreach ( TrackWP_Events::get_install_defaults() as $row ) {
            $rows[ $row['name'] ] = $row;
        }
        $lead = $rows['generate_lead'];
        $this->assertSame( 'form_submit', $lead['trigger_type'] );
        $this->assertCount( 1, $lead['firing_triggers'] );
        $this->assertTrue( $lead['firing_triggers'][0]['plugins_only'] );
        $this->assertArrayNotHasKey( 'plugins_only', $rows['phone_click']['firing_triggers'][0] );
    }

    public function test_click_rows_use_comma_selectors() {
        $rows = array();
        foreach ( TrackWP_Events::get_install_defaults() as $row ) {
            $rows[ $row['name'] ] = $row;
        }
        $this->assertSame( 'a[href^="tel:"]', $rows['phone_click']['css_selector'] );
        $this->assertSame( 'a[href^="mailto:"]', $rows['email_click']['css_selector'] );
        $this->assertStringContainsString( 'wa.me', $rows['whatsapp_click']['css_selector'] );
        $this->assertStringContainsString( 'goo.gl/maps', $rows['find_location']['css_selector'] );
    }

    public function test_activate_seeds_the_install_defaults() {
        delete_option( 'trackwp_events' );
        TrackWP::instance()->activate();
        $this->assertSame( TrackWP_Events::get_install_defaults(), get_option( 'trackwp_events' ) );
    }

    public function test_runtime_fallback_is_unchanged() {
        $names = wp_list_pluck( TrackWP_Events::get_defaults(), 'name' );
        $this->assertNotContains( 'generate_lead', $names );
        $this->assertNotContains( 'find_location', $names );
    }
}
