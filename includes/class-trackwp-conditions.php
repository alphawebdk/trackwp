<?php
/**
 * Firing triggers and conditions — schema, labels and validation.
 *
 * Deliberately mirrors Google Tag Manager's model so anyone who knows GTM
 * recognises it immediately:
 *
 *   An event fires when ANY of its firing triggers matches.
 *   A trigger matches when ALL of its conditions are true.
 *
 * That is logically OR-of-ANDs, but structured as triggers rather than as a
 * generic boolean tree. GTM does exactly this (a tag has several firing
 * triggers; "Some Clicks" adds AND-ed conditions inside one trigger), and it
 * keeps the UI understandable: no nested parentheses to reason about.
 *
 * This class is the single source of truth for which variables and operators
 * exist and which combinations are allowed. PHP validation, the admin builder
 * and the browser-side evaluator all read from here.
 *
 * @package TrackWP
 * @since 1.9.0
 */

defined('ABSPATH') || exit;

class TrackWP_Conditions {

    /** Upper bounds — keeps the UI readable and the click path cheap. */
    const MAX_TRIGGERS   = 10;
    const MAX_CONDITIONS = 10;

    /** Longest accepted condition value / selector. */
    const MAX_VALUE_LEN = 500;

    /**
     * Variable definitions.
     *
     * scope: page   — always available
     *        click  — only on element-click triggers
     *        form   — only on form-submit triggers
     * type:  string  — plain string comparison
     *        classes — whitespace-separated CSS class list (token matching)
     *        element — a DOM element (selector matching only)
     * param: true    — needs an extra name field (e.g. which query parameter)
     *
     * Labels follow GTM's wording so the terms are familiar.
     *
     * @return array
     */
    public static function get_variables() {
        return array(
            // --- Page ---
            'page_url'      => array( 'label' => __( 'Page URL', 'trackwp' ),      'scope' => 'page',  'type' => 'string' ),
            'page_hostname' => array( 'label' => __( 'Page Hostname', 'trackwp' ), 'scope' => 'page',  'type' => 'string' ),
            'page_path'     => array( 'label' => __( 'Page Path', 'trackwp' ),     'scope' => 'page',  'type' => 'string' ),
            'page_fragment' => array( 'label' => __( 'URL Fragment', 'trackwp' ),  'scope' => 'page',  'type' => 'string' ),
            'query_param'   => array( 'label' => __( 'Query Parameter', 'trackwp' ), 'scope' => 'page', 'type' => 'string', 'param' => true ),
            'page_title'    => array( 'label' => __( 'Page Title', 'trackwp' ),    'scope' => 'page',  'type' => 'string' ),
            'referrer'      => array( 'label' => __( 'Referrer', 'trackwp' ),      'scope' => 'page',  'type' => 'string' ),
            // --- Click ---
            'click_id'      => array( 'label' => __( 'Click ID', 'trackwp' ),      'scope' => 'click', 'type' => 'string' ),
            'click_classes' => array( 'label' => __( 'Click Classes', 'trackwp' ), 'scope' => 'click', 'type' => 'classes' ),
            'click_text'    => array( 'label' => __( 'Click Text', 'trackwp' ),    'scope' => 'click', 'type' => 'string' ),
            'click_url'     => array( 'label' => __( 'Click URL', 'trackwp' ),     'scope' => 'click', 'type' => 'string' ),
            'click_element' => array( 'label' => __( 'Click Element', 'trackwp' ), 'scope' => 'click', 'type' => 'element' ),
            // --- Form ---
            'form_id'       => array( 'label' => __( 'Form ID', 'trackwp' ),       'scope' => 'form',  'type' => 'string' ),
            'form_classes'  => array( 'label' => __( 'Form Classes', 'trackwp' ),  'scope' => 'form',  'type' => 'classes' ),
            'form_action'   => array( 'label' => __( 'Form Action', 'trackwp' ),   'scope' => 'form',  'type' => 'string' ),
            'form_element'  => array( 'label' => __( 'Form Element', 'trackwp' ),  'scope' => 'form',  'type' => 'element' ),
        );
    }

    /**
     * Operator definitions, keyed by the variable type they apply to.
     *
     * RegEx is deliberately absent in this version: an admin-entered pattern
     * is hard to debug, differs between PHP's PCRE and JavaScript's RegExp,
     * and opens a catastrophic-backtracking foot-gun. The operators below
     * cover the overwhelming majority of real triggers.
     *
     * @return array
     */
    public static function get_operators() {
        return array(
            'string' => array(
                'equals'        => __( 'equals', 'trackwp' ),
                'not_equals'    => __( 'does not equal', 'trackwp' ),
                'contains'      => __( 'contains', 'trackwp' ),
                'not_contains'  => __( 'does not contain', 'trackwp' ),
                'starts_with'   => __( 'starts with', 'trackwp' ),
                'ends_with'     => __( 'ends with', 'trackwp' ),
                'exists'        => __( 'exists', 'trackwp' ),
                'not_exists'    => __( 'does not exist', 'trackwp' ),
            ),
            'classes' => array(
                'has_class'     => __( 'has CSS class', 'trackwp' ),
                'not_has_class' => __( 'does not have CSS class', 'trackwp' ),
                'exists'        => __( 'exists', 'trackwp' ),
                'not_exists'    => __( 'does not exist', 'trackwp' ),
            ),
            'element' => array(
                'matches_selector'     => __( 'matches CSS selector', 'trackwp' ),
                'not_matches_selector' => __( 'does not match CSS selector', 'trackwp' ),
            ),
        );
    }

    /** Operators that take no value field. */
    public static function valueless_operators() {
        return array( 'exists', 'not_exists' );
    }

    /**
     * Which variable scopes a trigger type can use.
     *
     * Element variables must not be offered on scroll/time/URL triggers:
     * there is no element in scope, and a condition like
     * "Click ID does not equal X" would otherwise be trivially true on a timer.
     *
     * @param string $trigger_type
     * @return array List of scopes.
     */
    public static function scopes_for_trigger( $trigger_type ) {
        switch ( $trigger_type ) {
            case 'css_click':
            case 'file_download':
                return array( 'page', 'click' );
            case 'form_submit':
                return array( 'page', 'form' );
            default: // url_match, scroll_depth, time_on_page, js_event
                return array( 'page' );
        }
    }

    /**
     * Variables available for a trigger type.
     *
     * @param string $trigger_type
     * @return array
     */
    public static function variables_for_trigger( $trigger_type ) {
        $scopes = self::scopes_for_trigger( $trigger_type );
        $out    = array();
        foreach ( self::get_variables() as $key => $def ) {
            if ( in_array( $def['scope'], $scopes, true ) ) {
                $out[ $key ] = $def;
            }
        }
        return $out;
    }

    /**
     * Validate and normalise a single condition.
     *
     * Returns null when the condition is unusable, so the caller can drop it
     * rather than store a half-valid rule that silently never matches.
     *
     * @param array  $condition
     * @param string $trigger_type
     * @return array|null
     */
    public static function validate_condition( $condition, $trigger_type ) {
        if ( ! is_array( $condition ) ) {
            return null;
        }

        $variables = self::variables_for_trigger( $trigger_type );
        $variable  = isset( $condition['variable'] ) ? sanitize_key( $condition['variable'] ) : '';
        if ( ! isset( $variables[ $variable ] ) ) {
            return null;
        }

        $type      = $variables[ $variable ]['type'];
        $operators = self::get_operators();
        $operator  = isset( $condition['operator'] ) ? sanitize_key( $condition['operator'] ) : '';
        if ( ! isset( $operators[ $type ][ $operator ] ) ) {
            return null;
        }

        // Values are stored raw (logical form) — never sanitize_text_field()'d,
        // which would mangle selectors and meaningful whitespace. Escaping
        // happens at output time instead.
        $value = isset( $condition['value'] ) && is_scalar( $condition['value'] ) ? (string) $condition['value'] : '';
        $value = substr( trim( $value ), 0, self::MAX_VALUE_LEN );

        $needs_value = ! in_array( $operator, self::valueless_operators(), true );
        if ( $needs_value && $value === '' ) {
            return null; // A comparison with nothing to compare against is dead config.
        }

        $out = array(
            'variable' => $variable,
            'operator' => $operator,
            'value'    => $needs_value ? $value : '',
        );

        // Query Parameter needs the parameter name alongside the value.
        if ( ! empty( $variables[ $variable ]['param'] ) ) {
            $param = isset( $condition['param'] ) ? (string) $condition['param'] : '';
            $param = substr( trim( $param ), 0, 100 );
            // Query-parameter names: conservative charset, no encoding games.
            $param = preg_replace( '/[^A-Za-z0-9_\-\.\[\]]/', '', $param );
            if ( $param === '' ) {
                return null;
            }
            $out['param'] = $param;
        }

        return $out;
    }

    /**
     * Validate and normalise a single firing trigger.
     *
     * @param array $trigger
     * @return array|null
     */
    public static function validate_trigger( $trigger ) {
        if ( ! is_array( $trigger ) ) {
            return null;
        }

        $allowed_types = array_keys( TrackWP_Events::get_trigger_types() );
        $type          = isset( $trigger['type'] ) ? sanitize_key( $trigger['type'] ) : '';
        if ( ! in_array( $type, $allowed_types, true ) ) {
            return null;
        }

        $out = array(
            'type'         => $type,
            'css_selector' => isset( $trigger['css_selector'] ) && is_scalar( $trigger['css_selector'] )
                ? substr( trim( (string) $trigger['css_selector'] ), 0, self::MAX_VALUE_LEN ) : '',
            'url_match'    => isset( $trigger['url_match'] ) && is_scalar( $trigger['url_match'] )
                ? substr( trim( (string) $trigger['url_match'] ), 0, self::MAX_VALUE_LEN ) : '',
            'scroll_depth' => isset( $trigger['scroll_depth'] ) ? min( 100, absint( $trigger['scroll_depth'] ) ) : 0,
            'time_seconds' => isset( $trigger['time_seconds'] ) ? min( 86400, absint( $trigger['time_seconds'] ) ) : 0,
            'js_event'     => isset( $trigger['js_event'] ) && is_scalar( $trigger['js_event'] )
                ? substr( trim( (string) $trigger['js_event'] ), 0, 100 ) : '',
            'conditions'   => array(),
        );

        // A click trigger without a selector would bind to every click.
        if ( $type === 'css_click' && $out['css_selector'] === '' ) {
            return null;
        }

        if ( ! empty( $trigger['conditions'] ) && is_array( $trigger['conditions'] ) ) {
            $count = 0;
            foreach ( $trigger['conditions'] as $condition ) {
                if ( $count >= self::MAX_CONDITIONS ) {
                    break;
                }
                $valid = self::validate_condition( $condition, $type );
                if ( $valid !== null ) {
                    $out['conditions'][] = $valid;
                    $count++;
                }
            }
        }

        return $out;
    }

    /**
     * Validate a whole firing-trigger list.
     *
     * Rebuilds every entry from scratch from known keys — unknown input keys
     * are dropped rather than merged, which also makes prototype-pollution
     * style payloads ("__proto__", "constructor") inert.
     *
     * @param mixed $triggers
     * @return array
     */
    public static function validate_triggers( $triggers ) {
        if ( ! is_array( $triggers ) ) {
            return array();
        }
        $out = array();
        foreach ( $triggers as $trigger ) {
            if ( count( $out ) >= self::MAX_TRIGGERS ) {
                break;
            }
            $valid = self::validate_trigger( $trigger );
            if ( $valid !== null ) {
                $out[] = $valid;
            }
        }
        return $out;
    }

    /**
     * Build a firing-trigger list from a pre-1.9.0 event's flat fields.
     *
     * Events created before firing triggers existed carry exactly one implicit
     * trigger with no conditions, so upgrading is lossless.
     *
     * @param array $event
     * @return array
     */
    public static function triggers_from_legacy_event( $event ) {
        $trigger = array(
            'type'         => isset( $event['trigger_type'] ) ? $event['trigger_type'] : 'css_click',
            'css_selector' => isset( $event['css_selector'] ) ? $event['css_selector'] : '',
            'url_match'    => isset( $event['url_match'] ) ? $event['url_match'] : '',
            'scroll_depth' => isset( $event['scroll_depth'] ) ? $event['scroll_depth'] : 0,
            'time_seconds' => isset( $event['time_seconds'] ) ? $event['time_seconds'] : 0,
            'js_event'     => isset( $event['js_event'] ) ? $event['js_event'] : '',
            'conditions'   => array(),
        );
        $valid = self::validate_trigger( $trigger );
        return $valid === null ? array() : array( $valid );
    }

    /**
     * Schema handed to the admin builder as JSON.
     *
     * @return array
     */
    public static function admin_schema() {
        $variables = array();
        foreach ( self::get_variables() as $key => $def ) {
            $variables[ $key ] = array(
                'label' => $def['label'],
                'scope' => $def['scope'],
                'type'  => $def['type'],
                'param' => ! empty( $def['param'] ),
            );
        }

        $scopes = array();
        foreach ( array_keys( TrackWP_Events::get_trigger_types() ) as $type ) {
            $scopes[ $type ] = self::scopes_for_trigger( $type );
        }

        return array(
            'variables'     => $variables,
            'operators'     => self::get_operators(),
            'valueless'     => self::valueless_operators(),
            'scopes'        => $scopes,
            'maxTriggers'   => self::MAX_TRIGGERS,
            'maxConditions' => self::MAX_CONDITIONS,
        );
    }
}
