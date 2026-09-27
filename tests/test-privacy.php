<?php
/**
 * TrackWP_Privacy (K6): URL and title scrubbing, and parity between the PHP
 * cleaner and its JS mirror TrackWP_Privacy::cleaner_js().
 *
 * The parity test runs the real cleaner_js() output in node against the same
 * vectors as clean_url(); it is skipped only when no node binary is present.
 */

class TrackWP_Privacy_Test extends WP_UnitTestCase {

    /**
     * Vectors: input, dropClickIds, expected output.
     *
     * @return array
     */
    public static function url_vectors() {
        return array(
            'plain url untouched'          => array( 'https://example.org/shop/?utm_source=nl&page=2', false, 'https://example.org/shop/?utm_source=nl&page=2' ),
            'fragment dropped'             => array( 'https://example.org/a/#section', false, 'https://example.org/a/' ),
            'order key dropped'            => array( 'https://example.org/checkout/order-received/12/?key=wc_order_abc', false, 'https://example.org/checkout/order-received/12/' ),
            'denylist case-insensitive'    => array( 'https://example.org/?Token=x&PWD=y&ok=1', false, 'https://example.org/?ok=1' ),
            'e-mail param name'            => array( 'https://example.org/?e-mail=a&mail=b&email=c&keep=1', false, 'https://example.org/?keep=1' ),
            'e-mail value in any param'    => array( 'https://example.org/?ref=jens%40example.dk&x=1', false, 'https://example.org/?x=1' ),
            'e-mail value with plus'       => array( 'https://example.org/?u=jens+test%40example.dk', false, 'https://example.org/' ),
            'e-mail path segment'          => array( 'https://example.org/users/jens@example.dk/profile', false, 'https://example.org/users/redacted/profile' ),
            'encoded e-mail path segment'  => array( 'https://example.org/u/jens%40example.dk', false, 'https://example.org/u/redacted' ),
            'array param base name'        => array( 'https://example.org/?email[]=a&n=1', false, 'https://example.org/?n=1' ),
            'click ids kept by default'    => array( 'https://example.org/?gclid=G1&fbclid=F1', false, 'https://example.org/?gclid=G1&fbclid=F1' ),
            'click ids dropped on request' => array( 'https://example.org/?gclid=G1&gbraid=B&wbraid=W&fbclid=F1&msclkid=M&q=1', true, 'https://example.org/?q=1' ),
            'encoding of kept params kept' => array( 'https://example.org/?q=a%20b&r=%C3%A6', false, 'https://example.org/?q=a%20b&r=%C3%A6' ),
            'userinfo dropped'             => array( 'https://user:pass@example.org:8443/a?b=1', false, 'https://example.org:8443/a?b=1' ),
            'empty'                        => array( '', false, '' ),
        );
    }

    /**
     * @return array
     */
    public static function title_vectors() {
        return array(
            array( 'Tak for din ordre', 'Tak for din ordre' ),
            array( 'Kvittering til jens@example.dk', 'Kvittering til [redacted]' ),
            array( 'a@b.dk og c@d.se', '[redacted] og [redacted]' ),
        );
    }

    /**
     * @dataProvider url_vectors
     */
    public function test_clean_url_vectors( $input, $drop_click_ids, $expected ) {
        $extra = $drop_click_ids ? TrackWP_Privacy::CLICK_ID_PARAMS : array();
        $this->assertSame( $expected, TrackWP_Privacy::clean_url( $input, $extra ) );
    }

    public function test_clean_title_vectors() {
        foreach ( self::title_vectors() as $vector ) {
            $this->assertSame( $vector[1], TrackWP_Privacy::clean_title( $vector[0] ) );
        }
    }

    public function test_denylist_filter_extends_list() {
        add_filter( 'trackwp_url_param_denylist', function( $list ) {
            $list[] = 'SessionID';
            return $list;
        } );
        $this->assertContains( 'sessionid', TrackWP_Privacy::url_param_denylist() );
        $this->assertSame( 'https://example.org/?a=1', TrackWP_Privacy::clean_url( 'https://example.org/?sessionid=9&a=1' ) );
        remove_all_filters( 'trackwp_url_param_denylist' );
    }

    public function test_clean_event_scrubs_url_and_text_fields_only() {
        $out = TrackWP_Privacy::clean_event( array(
            'page_url'      => 'https://example.org/?email=a%40b.dk#x',
            'page_referrer' => 'https://example.org/u/a@b.dk',
            'page_title'    => 'Hej a@b.dk',
            'form_name'     => 'Form til a@b.dk',
            'event'         => 'form_submit',
        ) );
        $this->assertSame( 'https://example.org/', $out['page_url'] );
        $this->assertSame( 'https://example.org/u/redacted', $out['page_referrer'] );
        $this->assertSame( 'Hej [redacted]', $out['page_title'] );
        $this->assertSame( 'Form til [redacted]', $out['form_name'] );
        $this->assertSame( 'form_submit', $out['event'] );
    }

    public function test_origin_and_path() {
        $this->assertSame( 'https://example.org/a/b', TrackWP_Privacy::origin_and_path( 'https://example.org/a/b?utm=1#x' ) );
    }

    public function test_cleaner_js_matches_php_in_node() {
        $node = $this->node_binary();
        if ( '' === $node ) {
            $this->markTestSkipped( 'node is not available in this environment.' );
        }

        $cases = array();
        foreach ( self::url_vectors() as $label => $vector ) {
            $cases[] = array( 'label' => $label, 'input' => $vector[0], 'drop' => $vector[1] );
        }
        $titles = wp_list_pluck( array_map( function( $v ) { return array( 'in' => $v[0] ); }, self::title_vectors() ), 'in' );

        $script = 'var window = {};' . "\n"
            . TrackWP_Privacy::cleaner_js() . "\n"
            . 'var P = window.trackwpPrivacy;' . "\n"
            . 'var cases = ' . wp_json_encode( $cases ) . ';' . "\n"
            . 'var titles = ' . wp_json_encode( $titles ) . ';' . "\n"
            . 'process.stdout.write(JSON.stringify({'
            . 'urls: cases.map(function(c){ return P.cleanUrl(c.input, c.drop); }),'
            . 'titles: titles.map(function(t){ return P.cleanTitle(t); })'
            . '}));';

        $file = wp_tempnam( 'trackwp-privacy' ) . '.js';
        file_put_contents( $file, $script );
        $output = array();
        $code   = 0;
        exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
        unlink( $file );

        $this->assertSame( 0, $code, implode( "\n", $output ) );
        $js = json_decode( implode( "\n", $output ), true );
        $this->assertIsArray( $js, implode( "\n", $output ) );

        foreach ( $cases as $i => $case ) {
            $extra = $case['drop'] ? TrackWP_Privacy::CLICK_ID_PARAMS : array();
            $this->assertSame( TrackWP_Privacy::clean_url( $case['input'], $extra ), $js['urls'][ $i ], 'JS/PHP differ: ' . $case['label'] );
        }
        foreach ( $titles as $i => $title ) {
            $this->assertSame( TrackWP_Privacy::clean_title( $title ), $js['titles'][ $i ] );
        }
    }

    private function node_binary() {
        $candidates = array( getenv( 'NODE_BINARY' ), '/usr/local/bin/node', '/usr/bin/node', 'node' );
        foreach ( $candidates as $candidate ) {
            if ( ! is_string( $candidate ) || '' === $candidate ) {
                continue;
            }
            $out  = array();
            $code = 1;
            exec( escapeshellarg( $candidate ) . ' --version 2>/dev/null', $out, $code );
            if ( 0 === $code ) {
                return $candidate;
            }
        }
        return '';
    }
}
