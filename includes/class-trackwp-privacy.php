<?php
/**
 * URL and PII scrubbing for everything that leaves the server (K6).
 *
 * Applied to page_url, page_title, page_referrer and form_name for every
 * destination and every log, regardless of consent. Consent does not make a
 * password-reset token or an e-mail address in a query string acceptable to
 * forward to an ad platform.
 *
 * Click-ID parameters (gclid, gbraid, wbraid, fbclid, msclkid) are NOT
 * removed here: they are only stripped when marketing consent is missing,
 * which the caller decides (see TrackWP_Proxy::strip_by_category(), which
 * passes CLICK_ID_PARAMS as extra parameters to clean_url()).
 *
 * @package TrackWP
 * @since 1.10.1
 */

defined('ABSPATH') || exit;

class TrackWP_Privacy {

    /** Replacement for e-mail-like path segments. */
    const REDACTED_SEGMENT = 'redacted';

    /** Replacement for e-mail-like text in titles and form names. */
    const REDACTED_TEXT = '[redacted]';

    /** Click-ID parameters, removed from URLs when marketing consent is missing. */
    const CLICK_ID_PARAMS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' );

    /** Fields of an event payload that are scrubbed by clean_event(). */
    const URL_FIELDS  = array( 'page_url', 'page_referrer' );
    const TEXT_FIELDS = array( 'page_title', 'form_name' );

    /** Anything that looks like an e-mail address. */
    const EMAIL_PATTERN = '/[^@\s]+@[^@\s]+\.[^@\s]+/';

    /**
     * Query parameters that are always removed (case-insensitive).
     *
     * @return string[] Lower-case parameter names.
     */
    public static function url_param_denylist() {
        $list = apply_filters(
            'trackwp_url_param_denylist',
            array( 'key', 'email', 'e-mail', 'mail', 'token', 'password', 'pwd', 'nonce' )
        );
        if ( ! is_array( $list ) ) {
            return array();
        }
        $out = array();
        foreach ( $list as $name ) {
            if ( is_string( $name ) && '' !== $name ) {
                $out[] = strtolower( $name );
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Scrub a URL.
     *
     * - drops denylisted query parameters (case-insensitive) plus $extra_params
     * - drops any parameter whose URL-decoded value looks like an e-mail
     * - drops the fragment
     * - replaces e-mail-like path segments with "redacted"
     *
     * A string that cannot be parsed as a URL is returned as '' rather than
     * forwarded unscrubbed.
     *
     * @param string   $url          URL to scrub.
     * @param string[] $extra_params Additional parameter names to drop.
     * @return string
     */
    public static function clean_url( $url, $extra_params = array() ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( false === $parts || ! is_array( $parts ) ) {
            return '';
        }

        $deny = self::url_param_denylist();
        foreach ( (array) $extra_params as $name ) {
            if ( is_string( $name ) && '' !== $name ) {
                $deny[] = strtolower( $name );
            }
        }

        // Path: redact e-mail-like segments (raw or percent-encoded).
        $path = isset( $parts['path'] ) ? $parts['path'] : '';
        if ( '' !== $path ) {
            $segments = explode( '/', $path );
            foreach ( $segments as $i => $segment ) {
                if ( '' !== $segment && preg_match( self::EMAIL_PATTERN, rawurldecode( $segment ) ) ) {
                    $segments[ $i ] = self::REDACTED_SEGMENT;
                }
            }
            $path = implode( '/', $segments );
        }

        // Query: filter pair by pair so the original encoding of the kept
        // parameters is preserved byte for byte.
        $query = '';
        if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
            $kept = array();
            foreach ( explode( '&', $parts['query'] ) as $pair ) {
                if ( '' === $pair ) {
                    continue;
                }
                $eq    = strpos( $pair, '=' );
                $name  = false === $eq ? $pair : substr( $pair, 0, $eq );
                $value = false === $eq ? '' : substr( $pair, $eq + 1 );

                $decoded_name = strtolower( urldecode( $name ) );
                // PHP-style array params (email[]=...) are matched on their base name.
                $base_name = preg_replace( '/\[.*$/', '', $decoded_name );
                if ( in_array( $decoded_name, $deny, true ) || in_array( $base_name, $deny, true ) ) {
                    continue;
                }
                if ( '' !== $value && preg_match( self::EMAIL_PATTERN, urldecode( $value ) ) ) {
                    continue;
                }
                $kept[] = $pair;
            }
            $query = implode( '&', $kept );
        }

        $out = '';
        if ( isset( $parts['scheme'] ) ) {
            $out .= $parts['scheme'] . ':';
        }
        if ( isset( $parts['host'] ) ) {
            $out .= '//';
            // Userinfo is never kept: it is a credential, not page identity.
            $out .= $parts['host'];
            if ( isset( $parts['port'] ) ) {
                $out .= ':' . (int) $parts['port'];
            }
        }
        $out .= $path;
        if ( '' !== $query ) {
            $out .= '?' . $query;
        }
        // Fragment is always dropped.

        return $out;
    }

    /**
     * JavaScript mirror of clean_url() / clean_title(), without <script> tags.
     *
     * Defines window.trackwpPrivacy = {cleanUrl(url, dropClickIds), cleanTitle(t)}
     * with exactly the rules above: denylisted parameters (the same filtered
     * list), parameters whose decoded value looks like an e-mail, fragment,
     * e-mail-like path segments, userinfo; click-ID parameters only when
     * dropClickIds is truthy. Like the PHP version it works on the raw string
     * and keeps the kept parameters byte for byte (URLSearchParams would
     * re-encode them), so both sides give identical output for the same input.
     *
     * This is the ONLY JS copy of the cleaner; trackwp.php prints it in the
     * priority-1 head script and the client scripts use window.trackwpPrivacy.
     *
     * @return string
     */
    public static function cleaner_js() {
        $deny  = wp_json_encode( self::url_param_denylist() );
        $click = wp_json_encode( self::CLICK_ID_PARAMS );
        $js    = <<<'JS'
window.trackwpPrivacy=window.trackwpPrivacy||(function(){
var DENY=__DENY__,CLICK=__CLICK__,EM=/[^@\s]+@[^@\s]+\.[^@\s]+/,EMG=/[^@\s]+@[^@\s]+\.[^@\s]+/g;
function dec(s,plus){if(plus){s=s.replace(/\+/g,' ');}try{return decodeURIComponent(s);}catch(e){return s;}}
function cleanUrl(u,dropClick){
if(typeof u!=='string'||u===''){return '';}
var s=u,i=s.indexOf('#');if(i>-1){s=s.slice(0,i);}
var query='';i=s.indexOf('?');if(i>-1){query=s.slice(i+1);s=s.slice(0,i);}
var m=/^(?:([A-Za-z][A-Za-z0-9+.\-]*):)?(?:\/\/([^\/]*))?(.*)$/.exec(s);if(!m){return '';}
var out='';if(m[1]){out+=m[1]+':';}
if(m[2]!==undefined){var a=m[2],at=a.lastIndexOf('@');if(at>-1){a=a.slice(at+1);}out+='//'+a;}
var path=m[3]||'';if(path!==''){path=path.split('/').map(function(seg){return (seg!==''&&EM.test(dec(seg,false)))?'redacted':seg;}).join('/');}
out+=path;
var deny=DENY.slice();if(dropClick){deny=deny.concat(CLICK);}
if(query!==''){var kept=[],pairs=query.split('&');
for(var k=0;k<pairs.length;k++){var p=pairs[k];if(p===''){continue;}
var eq=p.indexOf('='),name=eq===-1?p:p.slice(0,eq),val=eq===-1?'':p.slice(eq+1);
var dn=dec(name,true).toLowerCase(),bn=dn.replace(/\[.*$/,'');
if(deny.indexOf(dn)>-1||deny.indexOf(bn)>-1){continue;}
if(val!==''&&EM.test(dec(val,true))){continue;}
kept.push(p);}
if(kept.length){out+='?'+kept.join('&');}}
return out;}
function cleanTitle(t){if(t===null||t===undefined||typeof t==='object'){return '';}return String(t).replace(EMG,'[redacted]');}
return {cleanUrl:cleanUrl,cleanTitle:cleanTitle};
})();
JS;
        return str_replace( array( '__DENY__', '__CLICK__' ), array( $deny, $click ), $js );
    }

    /**
     * Reduce a URL to origin + path (used when neither analytics nor
     * marketing consent is given). The path is scrubbed as in clean_url().
     *
     * @param string $url
     * @return string
     */
    public static function origin_and_path( $url ) {
        $clean = self::clean_url( $url );
        if ( '' === $clean ) {
            return '';
        }
        $q = strpos( $clean, '?' );
        return false === $q ? $clean : substr( $clean, 0, $q );
    }

    /**
     * Replace e-mail-like text with [redacted].
     *
     * @param string $title
     * @return string
     */
    public static function clean_title( $title ) {
        if ( ! is_scalar( $title ) ) {
            return '';
        }
        $out = preg_replace( self::EMAIL_PATTERN, self::REDACTED_TEXT, (string) $title );
        return null === $out ? '' : $out;
    }

    /**
     * Scrub the free-text and URL fields of an event payload in place.
     *
     * @param array $event_data
     * @return array
     */
    public static function clean_event( $event_data ) {
        if ( ! is_array( $event_data ) ) {
            return array();
        }
        foreach ( self::URL_FIELDS as $field ) {
            if ( isset( $event_data[ $field ] ) ) {
                $event_data[ $field ] = self::clean_url( (string) $event_data[ $field ] );
            }
        }
        foreach ( self::TEXT_FIELDS as $field ) {
            if ( isset( $event_data[ $field ] ) ) {
                $event_data[ $field ] = self::clean_title( $event_data[ $field ] );
            }
        }
        return $event_data;
    }
}
