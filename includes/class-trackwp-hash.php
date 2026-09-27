<?php
defined('ABSPATH') || exit;

/**
 * Encoding, hashing and normalization helpers.
 *
 * Two normalization families live here and must never be mixed:
 *
 * 1. Google (Enhanced Conversions, GA4 MP user_data, Ads API
 *    userIdentifiers), PLAN-1.10.1-v4 K8 + R19:
 *    - email: trim + lowercase; for gmail.com / googlemail.com also remove
 *      dots and a "+suffix" from the local part.
 *      https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web
 *    - phone: E.164 with a leading '+'. A leading '+' keeps the digits, a
 *      leading '00' is replaced by '+'. Otherwise the ISO country (address
 *      country, else default_phone_country, else the WooCommerce base
 *      country, else 'DK') selects calling code and trunk prefix from
 *      PHONE_COUNTRIES; the trunk prefix is stripped once. Unknown country
 *      yields no hash.
 *    - names: trim + lowercase, accents preserved.
 *
 * 2. Meta (Conversions API user_data), a precise port of
 *    facebook-python-business-sdk facebook_business/adobjects/serverside/normalize.py
 *    at commit 0b12f070533df7eed7239e63b445327a2f7482bc
 *    https://github.com/facebook/facebook-python-business-sdk/blob/0b12f070533df7eed7239e63b445327a2f7482bc/facebook_business/adobjects/serverside/normalize.py
 *    (the latest commit touching the file as of 2026-09-27; identical to main).
 *    See meta_normalize().
 *
 * Shared test vectors: tests/fixtures/normalization-vectors.json (also read
 * by tests/js/normalization.test.mjs).
 */
class TrackWP_Hash {

    /**
     * Google phone table: ISO-2 => array(calling code, trunk prefix|null).
     * PLAN-1.10.1-v4 K8. Filterable via trackwp_phone_countries.
     */
    const PHONE_COUNTRIES = array(
        'DK' => array('45', null),
        'NO' => array('47', null),
        'SE' => array('46', '0'),
        'FI' => array('358', '0'),
        'DE' => array('49', '0'),
        'GB' => array('44', '0'),
        'NL' => array('31', '0'),
        'FR' => array('33', '0'),
        'IS' => array('354', null),
    );

    /**
     * ISO 3166-1 alpha-2 codes, identical to the pycountry database the Meta
     * SDK validates `country` against (249 entries, pycountry 26.x).
     */
    const ISO_COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

    /**
     * Keys accepted in an `enhanced` payload (PLAN-1.10.1-v4 K2a).
     */
    const ENHANCED_RAW_KEYS    = array('email', 'phone', 'first_name', 'last_name', 'city', 'zip', 'country');
    const ENHANCED_HASHED_KEYS = array('email_sha256', 'email_meta_sha256', 'phone_sha256', 'phone_e164_sha256');

    /**
     * Encode a value for storage (simple obfuscation, not encryption).
     * Used for API secrets in wp_options.
     */
    public static function encode( $value ) {
        // Simple base64 encode. Not security — just prevents casual reading in DB.
        if ( empty( $value ) ) return '';
        return base64_encode( $value );
    }

    /**
     * Decode a stored value.
     */
    public static function decode( $value ) {
        if ( empty( $value ) ) return '';
        return base64_decode( $value );
    }

    /**
     * SHA-256 hash a value after trim + lowercase (Google generic rule).
     */
    public static function sha256( $value ) {
        if ( $value === null || $value === '' ) return '';
        $value = self::lower( self::utrim( (string) $value ) );
        if ( $value === '' ) return '';
        return hash( 'sha256', $value );
    }

    /**
     * Whether customer data (enhanced conversions, GA4 user_data, Meta CAPI
     * hashed user_data and external_id, Pixel advanced matching, gtag
     * user_data, Ads userIdentifiers) may be shared.
     *
     * This is the ONLY implementation of the rule (REVIEW-RETTELSER klasse C).
     * Every caller (proxy, platform adapters, WooCommerce ec/am, trackwp.php
     * localize, consent profile) must use it instead of reading the option.
     *
     * Base value: option trackwp_advanced.customer_data_sharing, default
     * enabled when the key is missing. Filter: trackwp_customer_data_sharing
     * (bool $enabled) lets a site force it off (or on).
     *
     * @return bool
     */
    public static function customer_data_sharing_enabled() {
        $advanced = get_option( 'trackwp_advanced', array() );
        $enabled  = true;
        if ( is_array( $advanced ) && array_key_exists( 'customer_data_sharing', $advanced ) ) {
            $enabled = ! empty( $advanced['customer_data_sharing'] );
        }
        return (bool) apply_filters( 'trackwp_customer_data_sharing', $enabled );
    }

    /**
     * Default ISO-2 country for phone numbers without an international
     * prefix (R19): trackwp_advanced.default_phone_country, else the
     * WooCommerce base country, else 'DK'.
     *
     * @return string
     */
    public static function default_phone_country() {
        $advanced = get_option( 'trackwp_advanced', array() );
        if ( is_array( $advanced ) && ! empty( $advanced['default_phone_country'] ) ) {
            $iso = strtoupper( (string) $advanced['default_phone_country'] );
            if ( preg_match( '/^[A-Z]{2}$/', $iso ) ) {
                return $iso;
            }
        }
        $wc = (string) get_option( 'woocommerce_default_country', '' );
        if ( $wc !== '' ) {
            $parts = explode( ':', $wc );
            $iso   = strtoupper( $parts[0] );
            if ( preg_match( '/^[A-Z]{2}$/', $iso ) ) {
                return $iso;
            }
        }
        return 'DK';
    }

    /**
     * Unicode-aware trim, mirroring Python's str.strip() (str.isspace()).
     */
    private static function utrim( $s ) {
        $out = preg_replace( '/^[\s\p{Z}\x{1C}-\x{1F}\x{85}]+|[\s\p{Z}\x{1C}-\x{1F}\x{85}]+$/u', '', (string) $s );
        return $out === null ? trim( (string) $s ) : $out;
    }

    /**
     * UTF-8 lowercase.
     */
    private static function lower( $s ) {
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( (string) $s, 'UTF-8' );
        }
        // Without mbstring: ASCII plus Latin-1 Supplement and Latin
        // Extended-A (covers the Nordic/European letters we see in names).
        static $map = null;
        if ( $map === null ) {
            $map = array();
            for ( $cp = 0xC0; $cp <= 0xDE; $cp++ ) {
                if ( $cp !== 0xD7 ) {
                    $map[ self::utf8_chr( $cp ) ] = self::utf8_chr( $cp + 0x20 );
                }
            }
            for ( $cp = 0x100; $cp <= 0x17E; $cp++ ) {
                $is_upper = ( $cp >= 0x139 && $cp <= 0x148 ) || ( $cp >= 0x179 ) ? ( $cp % 2 === 1 ) : ( $cp % 2 === 0 );
                if ( $is_upper && $cp !== 0x130 && $cp !== 0x138 && $cp !== 0x149 && $cp !== 0x178 ) {
                    $map[ self::utf8_chr( $cp ) ] = self::utf8_chr( $cp + 1 );
                }
            }
            $map[ self::utf8_chr( 0x178 ) ] = self::utf8_chr( 0xFF );
        }
        return strtr( strtolower( (string) $s ), $map );
    }

    /**
     * Encode a code point (< 0x800) as UTF-8.
     */
    private static function utf8_chr( $cp ) {
        if ( $cp < 0x80 ) {
            return chr( $cp );
        }
        return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
    }

    /* ------------------------------------------------------------------
     * Google normalization
     * ------------------------------------------------------------------ */

    /**
     * Google email normalization (trim, lowercase, Gmail dot/+suffix rule).
     * Returns '' when the value has no '@'.
     */
    public static function normalize_email( $raw ) {
        if ( $raw === null || $raw === '' ) return '';
        $email = self::lower( self::utrim( (string) $raw ) );
        if ( strpos( $email, '@' ) === false ) {
            return '';
        }
        $at     = strrpos( $email, '@' );
        $local  = substr( $email, 0, $at );
        $domain = substr( $email, $at + 1 );
        if ( $local === '' || $domain === '' ) {
            return '';
        }
        if ( $domain === 'gmail.com' || $domain === 'googlemail.com' ) {
            $plus = strpos( $local, '+' );
            if ( $plus !== false ) {
                $local = substr( $local, 0, $plus );
            }
            $local = str_replace( '.', '', $local );
            if ( $local === '' ) {
                return '';
            }
        }
        return $local . '@' . $domain;
    }

    /**
     * Google E.164 phone normalization ('+' + digits), or '' when it cannot
     * be derived (no digits, unknown country, implausible length).
     *
     * @param string $raw
     * @param string $country ISO-2 of the address; '' uses default_phone_country().
     * @return string
     */
    public static function normalize_phone_e164( $raw, $country = null ) {
        if ( $raw === null || $raw === '' ) return '';
        $trimmed = self::utrim( (string) $raw );
        $digits  = preg_replace( '/\D/', '', $trimmed );
        if ( $digits === '' || $digits === null ) return '';

        if ( strpos( $trimmed, '+' ) === 0 ) {
            $intl = $digits;
        } elseif ( strpos( $digits, '00' ) === 0 ) {
            $intl = substr( $digits, 2 );
        } else {
            $iso = strtoupper( (string) $country );
            if ( ! preg_match( '/^[A-Z]{2}$/', $iso ) ) {
                $iso = self::default_phone_country();
            }
            $table = apply_filters( 'trackwp_phone_countries', self::PHONE_COUNTRIES );
            if ( ! isset( $table[ $iso ] ) || ! is_array( $table[ $iso ] ) ) {
                return '';
            }
            $cc    = (string) $table[ $iso ][0];
            $trunk = isset( $table[ $iso ][1] ) ? (string) $table[ $iso ][1] : '';
            if ( $trunk !== '' && strpos( $digits, $trunk ) === 0 ) {
                $digits = substr( $digits, strlen( $trunk ) );
            }
            if ( $digits === '' ) return '';
            $intl = $cc . $digits;
        }

        // E.164: no leading zero after '+', at most 15 digits.
        $len = strlen( $intl );
        if ( $len < 7 || $len > 15 || $intl[0] === '0' ) {
            return '';
        }
        return '+' . $intl;
    }

    /**
     * Google name normalization: trim + lowercase, accents preserved.
     */
    public static function normalize_name( $raw ) {
        if ( $raw === null || $raw === '' ) return '';
        return self::lower( self::utrim( (string) $raw ) );
    }

    /**
     * SHA-256 of a Google-normalized name (trim + lowercase, accents kept).
     * Used for sha256_first_name / sha256_last_name. '' for empty input.
     */
    public static function google_name_sha256( $name ) {
        $normalized = self::normalize_name( $name );
        return $normalized === '' ? '' : hash( 'sha256', $normalized );
    }

    /**
     * SHA-256 of the Google-normalized email. Google EC format.
     */
    public static function email_sha256( $email ) {
        $normalized = self::normalize_email( $email );
        return $normalized === '' ? '' : hash( 'sha256', $normalized );
    }

    /**
     * SHA-256 of the E.164 phone ('+' included). Google EC format.
     */
    public static function phone_e164_sha256( $phone, $country = null ) {
        $e164 = self::normalize_phone_e164( $phone, $country );
        return $e164 === '' ? '' : hash( 'sha256', $e164 );
    }

    /**
     * Meta `ph` digits (country code, no '+'): the Google E.164 value run
     * through the SDK `ph` normalization. The SDK expects the country code
     * to be present already; E.164 guarantees that.
     */
    public static function normalize_phone( $raw, $country = null ) {
        $e164 = self::normalize_phone_e164( $raw, $country );
        if ( $e164 === '' ) return '';
        $meta = self::meta_normalize( 'ph', $e164 );
        return $meta === null ? '' : $meta;
    }

    /**
     * SHA-256 of the Meta `ph` value.
     */
    public static function phone_sha256( $phone, $country = null ) {
        $digits = self::normalize_phone( $phone, $country );
        return $digits === '' ? '' : hash( 'sha256', $digits );
    }

    /**
     * SHA-256 of the Meta `em` value (SDK normalization: no Gmail rule).
     */
    public static function email_meta_sha256( $email ) {
        return self::meta_hash( 'em', $email );
    }

    /* ------------------------------------------------------------------
     * Meta normalization: port of normalize.py @ 0b12f070533d
     * ------------------------------------------------------------------ */

    /**
     * Normalize a Meta user_data field exactly like Normalize.normalize()
     * with hash_field=false. Where the SDK raises (invalid email, country,
     * currency, phone), null is returned. An input that already looks like
     * an MD5/SHA-256 hex digest after lower()+strip() is returned as-is,
     * like the SDK does.
     *
     * Supported fields: em, ph, fn, ln, ct, st, zp, country, currency, ge,
     * external_id (the last ones only get lower()+strip(), as in the SDK).
     *
     * @param string $field
     * @param mixed  $data
     * @return string|null
     */
    public static function meta_normalize( $field, $data ) {
        if ( $data === null ) return null;
        $data = (string) $data;
        if ( $data === '' ) return null;

        $n = self::lower( self::utrim( $data ) );
        if ( self::meta_is_already_hashed( $n ) ) {
            return $n;
        }

        $ws = '\s\p{Z}\x{1C}-\x{1F}\x{85}';
        switch ( $field ) {
            case 'em':
                // email_pattern = r".+@.+\..+" with re.match (anchored at start).
                if ( ! preg_match( '/^.+@.+\..+/u', $n ) ) {
                    return null;
                }
                break;

            case 'ct':
            case 'st':
                // location_excluded_chars = r"[0-9.\s\-()]"
                $n = preg_replace( '/[0-9.' . $ws . '\-()]/u', '', $n );
                break;

            case 'zp':
                $n     = preg_replace( '/[' . $ws . ']/u', '', $n );
                $parts = explode( '-', $n );
                $n     = $parts[0];
                break;

            case 'country':
                $n = preg_replace( '/[^a-z]/', '', $n );
                if ( strlen( $n ) !== 2 || strpos( ' ' . self::ISO_COUNTRIES . ' ', ' ' . strtoupper( $n ) . ' ' ) === false ) {
                    return null;
                }
                break;

            case 'currency':
                $n = preg_replace( '/[^a-z]/', '', $n );
                if ( strlen( $n ) !== 3 ) {
                    return null;
                }
                break;

            case 'ph':
                $n = preg_replace( '/[' . $ws . '\-()]/u', '', $n );
                $n = preg_replace( '/^\+?0{0,2}/', '', $n );
                // get_international_number()
                $n = preg_replace( '/^\+?0{0,2}/', '', $n );
                if ( strpos( $n, '0' ) === 0 ) {
                    return null;
                }
                if ( ! preg_match( '/^\d{1,4}\(?\d{2,3}\)?\d{4,}/u', $n, $m ) ) {
                    return null;
                }
                $n = $m[0];
                break;
        }

        if ( $n === null || $n === '' ) {
            return null;
        }
        return $n;
    }

    /**
     * Normalize.normalize_field(): normalized + SHA-256 (an already hashed
     * input is passed through unchanged, like the SDK). '' when invalid.
     */
    public static function meta_hash( $field, $data ) {
        $n = self::meta_normalize( $field, $data );
        if ( $n === null ) return '';
        if ( self::meta_is_already_hashed( $n ) ) return $n;
        return hash( 'sha256', $n );
    }

    /**
     * Normalize.is_already_hashed(): MD5 or SHA-256 lowercase hex.
     */
    private static function meta_is_already_hashed( $s ) {
        return (bool) preg_match( '/^[a-f0-9]{32}$|^[a-f0-9]{64}$/', (string) $s );
    }

    /* ------------------------------------------------------------------
     * Enhanced payload (K2a)
     * ------------------------------------------------------------------ */

    /**
     * Normalize an `enhanced` payload to hashed-only keys (K2a).
     *
     * - Only the K2a keys are accepted; everything else is dropped.
     * - Hash keys (email_sha256, email_meta_sha256, phone_sha256,
     *   phone_e164_sha256) are accepted only as 64 hex chars and only under
     *   their own names. They never overwrite a value derived from raw data.
     * - A raw field whose value looks like a hex digest (MD5/SHA-256) is
     *   dropped; it is never interpreted as a hash.
     * - email  -> email_sha256 (Google) + email_meta_sha256 (Meta em)
     * - phone  -> phone_e164_sha256 (Google) + phone_sha256 (Meta ph);
     *             the country is the payload's `country` or the default.
     * - first_name/last_name -> *_sha256 (trim + lowercase; identical for
     *             Google and the Meta SDK fn/ln rule)
     * - city -> city_sha256 (Meta ct), zip -> zip_sha256 (Meta zp),
     *   country -> country_sha256 (Meta country, ISO-validated)
     *
     * @param array $enhanced
     * @return array
     */
    public static function normalize_enhanced( array $enhanced ) {
        $result = array();
        $raw    = array();

        foreach ( self::ENHANCED_RAW_KEYS as $key ) {
            if ( ! isset( $enhanced[ $key ] ) || ! is_scalar( $enhanced[ $key ] ) ) {
                continue;
            }
            $value = (string) $enhanced[ $key ];
            if ( $value === '' ) {
                continue;
            }
            if ( preg_match( '/^[a-f0-9]{32}$|^[a-f0-9]{64}$/', self::lower( self::utrim( $value ) ) ) ) {
                continue; // Looks pre-hashed under a raw key: drop (K2a).
            }
            $raw[ $key ] = $value;
        }

        $country = '';
        if ( isset( $raw['country'] ) ) {
            $iso = strtoupper( self::utrim( $raw['country'] ) );
            if ( preg_match( '/^[A-Z]{2}$/', $iso ) ) {
                $country = $iso;
            }
        }

        if ( isset( $raw['email'] ) ) {
            $g = self::email_sha256( $raw['email'] );
            $m = self::email_meta_sha256( $raw['email'] );
            if ( $g !== '' ) $result['email_sha256'] = $g;
            if ( $m !== '' ) $result['email_meta_sha256'] = $m;
        }
        if ( isset( $raw['phone'] ) ) {
            $g = self::phone_e164_sha256( $raw['phone'], $country );
            $m = self::phone_sha256( $raw['phone'], $country );
            if ( $g !== '' ) $result['phone_e164_sha256'] = $g;
            if ( $m !== '' ) $result['phone_sha256'] = $m;
        }
        foreach ( array( 'first_name' => 'fn', 'last_name' => 'ln', 'city' => 'ct', 'zip' => 'zp', 'country' => 'country' ) as $key => $field ) {
            if ( isset( $raw[ $key ] ) ) {
                $h = self::meta_hash( $field, $raw[ $key ] );
                if ( $h !== '' ) $result[ $key . '_sha256' ] = $h;
            }
        }

        foreach ( self::ENHANCED_HASHED_KEYS as $key ) {
            if ( isset( $result[ $key ] ) || ! isset( $enhanced[ $key ] ) || ! is_string( $enhanced[ $key ] ) ) {
                continue;
            }
            if ( preg_match( '/^[a-f0-9]{64}$/i', $enhanced[ $key ] ) ) {
                $result[ $key ] = strtolower( $enhanced[ $key ] );
            }
        }

        return $result;
    }

    /**
     * Generate a unique client ID for first-party tracking.
     * GA4 Measurement Protocol-compatible random.timestamp format.
     */
    public static function generate_client_id() {
        return (string) wp_rand( 1000000000, 2147483647 ) . '.' . time();
    }

    /**
     * Generate a unique event ID for deduplication.
     */
    public static function generate_event_id() {
        return 'evt_' . bin2hex( random_bytes( 16 ) );
    }
}
