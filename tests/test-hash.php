<?php
/**
 * TrackWP_Hash normalization tests (W3).
 *
 * Vectors: tests/fixtures/normalization-vectors.json. The Meta vectors were
 * produced by running the real facebook-python-business-sdk normalize.py
 * (commit 0b12f070533df7eed7239e63b445327a2f7482bc); the same file is read
 * by tests/js/normalization.test.mjs (W5).
 */
class Test_TrackWP_Hash extends WP_UnitTestCase {

    private static function vectors() {
        $raw = file_get_contents( __DIR__ . '/fixtures/normalization-vectors.json' );
        return json_decode( $raw, true );
    }

    public function set_up() {
        parent::set_up();
        update_option( 'trackwp_advanced', array( 'default_phone_country' => 'DK' ) );
    }

    public function test_meta_vectors_match_sdk() {
        foreach ( self::vectors()['meta'] as $v ) {
            $label = $v['field'] . ' ' . wp_json_encode( $v['in'] );
            $this->assertSame( $v['normalized'], TrackWP_Hash::meta_normalize( $v['field'], $v['in'] ), $label );
            $this->assertSame( (string) $v['sha256'], TrackWP_Hash::meta_hash( $v['field'], $v['in'] ), $label );
        }
    }

    public function test_google_email_vectors() {
        foreach ( self::vectors()['google_email'] as $v ) {
            $this->assertSame( $v['normalized'], TrackWP_Hash::normalize_email( $v['in'] ), $v['in'] );
            $this->assertSame( $v['sha256'], TrackWP_Hash::email_sha256( $v['in'] ), $v['in'] );
        }
    }

    public function test_google_phone_vectors_and_meta_ph() {
        foreach ( self::vectors()['google_phone'] as $v ) {
            $label = $v['in'] . ' / ' . $v['country'];
            $this->assertSame( $v['e164'], TrackWP_Hash::normalize_phone_e164( $v['in'], $v['country'] ), $label );
            $this->assertSame( $v['sha256'], TrackWP_Hash::phone_e164_sha256( $v['in'], $v['country'] ), $label );
            $this->assertSame( (string) $v['meta_ph'], TrackWP_Hash::normalize_phone( $v['in'], $v['country'] ), $label );
            $this->assertSame( (string) $v['meta_ph_sha256'], TrackWP_Hash::phone_sha256( $v['in'], $v['country'] ), $label );
        }
    }

    public function test_google_name_vectors_keep_accents() {
        foreach ( self::vectors()['google_name'] as $v ) {
            $this->assertSame( $v['normalized'], TrackWP_Hash::normalize_name( $v['in'] ) );
            $this->assertSame( $v['sha256'], hash( 'sha256', TrackWP_Hash::normalize_name( $v['in'] ) ) );
        }
    }

    public function test_eight_digit_rule_removed_and_unknown_country_gives_no_hash() {
        update_option( 'trackwp_advanced', array( 'default_phone_country' => 'US' ) );
        $this->assertSame( '', TrackWP_Hash::normalize_phone_e164( '12345678' ) );
        $this->assertSame( '', TrackWP_Hash::phone_sha256( '12345678' ) );
    }

    public function test_default_phone_country_falls_back_to_woocommerce_then_dk() {
        update_option( 'trackwp_advanced', array() );
        update_option( 'woocommerce_default_country', 'SE:AB' );
        $this->assertSame( 'SE', TrackWP_Hash::default_phone_country() );
        $this->assertSame( '+46812345678', TrackWP_Hash::normalize_phone_e164( '08 123 456 78' ) );
        delete_option( 'woocommerce_default_country' );
        $this->assertSame( 'DK', TrackWP_Hash::default_phone_country() );
    }

    public function test_normalize_enhanced_k2a() {
        $hex = str_repeat( 'ab', 32 );
        $out = TrackWP_Hash::normalize_enhanced( array(
            'email'             => $hex,          // raw email that looks hashed: dropped
            'phone_e164_sha256' => strtoupper( $hex ),
            'email_sha256'      => 'not-a-hash',  // invalid hash key: dropped
            'state'             => 'NY',          // not a K2a key: dropped
            'gclid'             => 'abc',         // not a K2a key: dropped
            'first_name'        => ' Søren ',
            'country'           => 'dk',
        ) );
        $this->assertArrayNotHasKey( 'email_sha256', $out );
        $this->assertArrayNotHasKey( 'email_meta_sha256', $out );
        $this->assertArrayNotHasKey( 'state_sha256', $out );
        $this->assertArrayNotHasKey( 'gclid', $out );
        $this->assertArrayNotHasKey( 'gclid_sha256', $out );
        $this->assertSame( $hex, $out['phone_e164_sha256'] );
        $this->assertSame( hash( 'sha256', 'søren' ), $out['first_name_sha256'] );
        $this->assertSame( hash( 'sha256', 'dk' ), $out['country_sha256'] );
        foreach ( $out as $key => $value ) {
            $this->assertMatchesRegularExpression( '/_sha256$/', $key );
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $value );
        }
    }

    public function test_normalize_enhanced_raw_email_gives_google_and_meta_hashes() {
        $out = TrackWP_Hash::normalize_enhanced( array( 'email' => 'John.Doe+Tag@Gmail.com', 'phone' => '12 34 56 78' ) );
        $this->assertSame( hash( 'sha256', 'johndoe@gmail.com' ), $out['email_sha256'] );
        $this->assertSame( hash( 'sha256', 'john.doe+tag@gmail.com' ), $out['email_meta_sha256'] );
        $this->assertSame( hash( 'sha256', '+4512345678' ), $out['phone_e164_sha256'] );
        $this->assertSame( hash( 'sha256', '4512345678' ), $out['phone_sha256'] );
    }

    public function test_customer_data_sharing_default_on() {
        update_option( 'trackwp_advanced', array() );
        $this->assertTrue( TrackWP_Hash::customer_data_sharing_enabled() );
        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => 0 ) );
        $this->assertFalse( TrackWP_Hash::customer_data_sharing_enabled() );
    }

    public function test_customer_data_sharing_filter_false_overrides_option() {
        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => 1 ) );
        add_filter( 'trackwp_customer_data_sharing', '__return_false' );
        $this->assertFalse( TrackWP_Hash::customer_data_sharing_enabled() );
        remove_filter( 'trackwp_customer_data_sharing', '__return_false' );
        $this->assertTrue( TrackWP_Hash::customer_data_sharing_enabled() );
    }

    public function test_customer_data_sharing_filter_receives_option_value() {
        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => 0 ) );
        $seen = null;
        $cb   = function ( $enabled ) use ( &$seen ) {
            $seen = $enabled;
            return true;
        };
        add_filter( 'trackwp_customer_data_sharing', $cb );
        $this->assertTrue( TrackWP_Hash::customer_data_sharing_enabled() );
        remove_filter( 'trackwp_customer_data_sharing', $cb );
        $this->assertFalse( $seen );
    }
}
