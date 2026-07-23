<?php
defined('ABSPATH') || exit;

class TrackWP_Hash {

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
     * SHA-256 hash a value. Used for Enhanced Conversions.
     * Normalizes input (trim, lowercase) before hashing.
     */
    public static function sha256( $value ) {
        if ( empty( $value ) ) return '';
        $value = trim( strtolower( $value ) );
        return hash( 'sha256', $value );
    }

    /**
     * Manual ASCII transliteration fallback when remove_accents() is unavailable.
     */
    private static function ascii_translit( $str ) {
        $map = array(
            'æ' => 'ae', 'Æ' => 'Ae',
            'ø' => 'oe', 'Ø' => 'Oe',
            'å' => 'aa', 'Å' => 'Aa',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
            'ç' => 'c', 'Ç' => 'C',
            'ß' => 'ss',
        );
        return strtr( $str, $map );
    }

    /**
     * Normalize a phone number to a digit string including country code (no '+').
     *
     * Shared contract with the client-side JS normalizer — keep in sync:
     * - Strip all non-digits from the raw input.
     * - If the raw (trimmed) input starts with '+' → digits already include the
     *   country code, use as-is.
     * - Else if the digits start with '00' (international prefix) → strip it.
     * - Else if exactly 8 digits → prepend '45' (Danish default country code).
     * - Otherwise use the digits as-is.
     *
     * The result is Meta CAPI `ph` format (digits incl. country code, no '+').
     * Prefix '+' for the Google E.164 form (see phone_e164_sha256()).
     */
    public static function normalize_phone( $raw ) {
        if ( empty( $raw ) ) return '';
        $trimmed = trim( (string) $raw );
        $digits  = preg_replace( '/\D/', '', $trimmed );
        if ( $digits === '' ) return '';
        if ( strpos( $trimmed, '+' ) === 0 ) {
            return $digits;
        }
        if ( strpos( $digits, '00' ) === 0 ) {
            return substr( $digits, 2 );
        }
        if ( strlen( $digits ) === 8 ) {
            return '45' . $digits;
        }
        return $digits;
    }

    /**
     * Normalize an email address (lowercase + trim + Gmail alias stripping).
     * Does NOT hash — use email_sha256() for the hashed form.
     */
    public static function normalize_email( $raw ) {
        if ( empty( $raw ) ) return '';
        $email = trim( strtolower( $raw ) );
        if ( strpos( $email, '@' ) === false ) {
            return $email;
        }
        list( $local, $domain ) = explode( '@', $email, 2 );
        if ( $domain === 'gmail.com' || $domain === 'googlemail.com' ) {
            $plus_stripped = strstr( $local, '+', true );
            if ( $plus_stripped !== false ) {
                $local = $plus_stripped;
            }
            $local = str_replace( '.', '', $local );
        }
        return $local . '@' . $domain;
    }

    /**
     * SHA-256 hash a phone number after normalization (digits incl. country
     * code, no '+'). Meta CAPI `ph` format.
     */
    public static function phone_sha256( $phone ) {
        if ( empty( $phone ) ) return '';
        $normalized = self::normalize_phone( $phone );
        if ( $normalized === '' ) return '';
        return hash( 'sha256', $normalized );
    }

    /**
     * SHA-256 hash a phone number in E.164 form ('+' + digits incl. country
     * code). Google Enhanced Conversions format.
     */
    public static function phone_e164_sha256( $phone ) {
        if ( empty( $phone ) ) return '';
        $normalized = self::normalize_phone( $phone );
        if ( $normalized === '' ) return '';
        return hash( 'sha256', '+' . $normalized );
    }

    /**
     * SHA-256 hash an email after Google normalization (trim + lowercase +
     * Gmail aliasing rules). Google Enhanced Conversions format.
     */
    public static function email_sha256( $email ) {
        if ( empty( $email ) ) return '';
        $normalized = self::normalize_email( $email );
        if ( $normalized === '' ) return '';
        return hash( 'sha256', $normalized );
    }

    /**
     * SHA-256 hash an email after Meta normalization (trim + lowercase ONLY,
     * no Gmail dot/plus stripping). Meta CAPI `em` format.
     */
    public static function email_meta_sha256( $email ) {
        if ( empty( $email ) ) return '';
        $normalized = trim( strtolower( $email ) );
        if ( $normalized === '' ) return '';
        return hash( 'sha256', $normalized );
    }

    /**
     * Normalize an arbitrary Enhanced Conversions payload to hashed-only keys.
     *
     * - Keys already ending in `_sha256` with a 64-char hex value pass through
     *   (incl. client-supplied email_meta_sha256 / phone_e164_sha256).
     * - `email` produces email_sha256 (Google normalization) and
     *   email_meta_sha256 (Meta normalization: trim + lowercase only).
     * - `phone` produces phone_sha256 (digits incl. country code, Meta format)
     *   and phone_e164_sha256 ('+' prefixed, Google E.164 format).
     * - first_name, last_name, city, state, country, zip get accent-stripped
     *   (where relevant) and SHA-256 hashed.
     * - Pre-hashed values (64-char hex) under a non-suffixed key are renamed.
     */
    public static function normalize_enhanced( array $enhanced ) {
        $result = array();
        $name_fields = array( 'first_name', 'last_name', 'city', 'state', 'country', 'zip' );

        foreach ( $enhanced as $key => $value ) {
            if ( $value === null || $value === '' ) {
                continue;
            }

            // Already a *_sha256 key with valid hex value — pass through.
            if ( substr( $key, -7 ) === '_sha256' && is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/i', $value ) ) {
                $result[ $key ] = $value;
                continue;
            }

            if ( $key === 'email' ) {
                $hashed = self::email_sha256( $value );
                if ( $hashed !== '' ) {
                    $result['email_sha256'] = $hashed;
                }
                $hashed_meta = self::email_meta_sha256( $value );
                if ( $hashed_meta !== '' ) {
                    $result['email_meta_sha256'] = $hashed_meta;
                }
                continue;
            }

            if ( $key === 'phone' ) {
                $hashed = self::phone_sha256( $value );
                if ( $hashed !== '' ) {
                    $result['phone_sha256'] = $hashed;
                }
                $hashed_e164 = self::phone_e164_sha256( $value );
                if ( $hashed_e164 !== '' ) {
                    $result['phone_e164_sha256'] = $hashed_e164;
                }
                continue;
            }

            if ( in_array( $key, $name_fields, true ) ) {
                $val = is_string( $value ) ? $value : (string) $value;
                if ( in_array( $key, array( 'first_name', 'last_name', 'city', 'state' ), true ) ) {
                    $val = function_exists( 'remove_accents' )
                        ? remove_accents( $val )
                        : self::ascii_translit( $val );
                }
                $hashed = self::sha256( $val );
                if ( $hashed !== '' ) {
                    $result[ $key . '_sha256' ] = $hashed;
                }
                continue;
            }

            // Value looks pre-hashed (64-char hex) but key lacks `_sha256` suffix — rename.
            if ( is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/i', $value ) ) {
                $result[ $key . '_sha256' ] = $value;
                continue;
            }

            // Anything else is dropped to guarantee only _sha256 keys are returned.
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
