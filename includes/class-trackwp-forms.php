<?php
/**
 * Form plugin integrations for TrackWP.
 *
 * Detects Contact Form 7, WPForms, Fluent Forms, Gravity Forms, SureForms,
 * and provides a fallback for standard HTML forms.
 *
 * The integrations only report WHICH form was submitted
 * (window.trackwp.sendFormEvent). They never read field values: trackwp.js
 * decides per form_submit trigger whether an event fires, and reads enhanced
 * data synchronously and only with marketing consent (PLAN-1.10.1-v4 §2.1).
 *
 * @package TrackWP
 */

defined('ABSPATH') || exit;

class TrackWP_Forms {

    /** Maximum length of form_name (K2). */
    const FORM_NAME_MAX = 100;

    public function __construct() {
        add_action('wp_footer', array($this, 'output_form_listeners'), 20);
    }

    /**
     * Whether any enabled event has a trigger of type form_submit.
     *
     * The event name is irrelevant: a custom-named event ("lead", "booking")
     * with a form_submit trigger needs the integrations just as much.
     *
     * @param array|null $events Events option (defaults to trackwp_events).
     * @return bool
     */
    public static function has_form_submit_trigger($events = null) {
        return self::scan_form_submit_triggers($events, false);
    }

    /**
     * Whether any enabled event has a form_submit trigger that is NOT limited
     * to the form plugins, so the plain HTML-form fallback is needed.
     *
     * @param array|null $events Events option (defaults to trackwp_events).
     * @return bool
     */
    public static function needs_html_fallback($events = null) {
        return self::scan_form_submit_triggers($events, true);
    }

    /**
     * Shared scan for the two public helpers above.
     *
     * @param array|null $events   Events option (defaults to trackwp_events).
     * @param bool       $open_only Only count triggers without plugins_only.
     * @return bool
     */
    private static function scan_form_submit_triggers($events, $open_only) {
        if (!is_array($events)) {
            $events = get_option('trackwp_events', array());
        }
        if (!is_array($events)) {
            return false;
        }
        foreach ($events as $event) {
            if (!is_array($event) || empty($event['enabled'])) {
                continue;
            }
            foreach (self::triggers_of($event) as $trigger) {
                if (!is_array($trigger) || !isset($trigger['type']) || 'form_submit' !== $trigger['type']) {
                    continue;
                }
                if (!$open_only || empty($trigger['plugins_only'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The firing triggers of a stored event: firing_triggers, else triggers,
     * else the single flat trigger_type, else none.
     *
     * @param array $event
     * @return array
     */
    private static function triggers_of($event) {
        if (!empty($event['firing_triggers']) && is_array($event['firing_triggers'])) {
            return $event['firing_triggers'];
        }
        if (!empty($event['triggers']) && is_array($event['triggers'])) {
            return $event['triggers'];
        }
        if (!empty($event['trigger_type'])) {
            return array(array('type' => $event['trigger_type']));
        }
        return array();
    }

    /**
     * Output inline JS for form tracking.
     * Priority 20 ensures this runs after consent and tracking scripts.
     */
    public function output_form_listeners() {
        if (is_admin()) return;
        $events = get_option('trackwp_events', array());
        if (!self::has_form_submit_trigger($events)) return;

        ?>
<script>
(function() {
    'use strict';

    // trackwp.js loads deferred and may not have executed yet: bind
    // immediately if the API exists, otherwise wait for 'trackwp:ready'.
    var initialized = false;
    var NAME_MAX = <?php echo (int) self::FORM_NAME_MAX; ?>;

    // A label for the form, never a field value.
    function formName(form, fallback) {
        var name = '';
        if (form && typeof form.getAttribute === 'function') {
            name = form.getAttribute('data-form-name') || form.getAttribute('aria-label') ||
                form.getAttribute('name') || '';
        }
        name = String(name || fallback || '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        return name.substring(0, NAME_MAX);
    }

    function send(formId, form, name, plugin, nav, domId) {
        window.trackwp.sendFormEvent({
            form_id: String(formId || ''),
            form_name: formName(form, name),
            plugin: plugin,
            nav: !!nav,
            dom_id: domId || ''
        }, form || null);
    }

    function init() {
    if (initialized) return;
    if (!window.trackwp || typeof window.trackwp.sendFormEvent !== 'function') return;
    initialized = true;

    <?php if (defined('WPCF7_VERSION')) : ?>
    // Contact Form 7: wpcf7mailsent is dispatched on the form's wrapper.
    document.addEventListener('wpcf7mailsent', function(e) {
        var detail = e.detail || {};
        var form = (e.target && typeof e.target.querySelector === 'function')
            ? (e.target.tagName === 'FORM' ? e.target : e.target.querySelector('form')) : null;
        send('cf7_' + (detail.contactFormId || ''), form, '', 'cf7', false);
    });
    <?php endif; ?>

    <?php if (defined('WPFORMS_VERSION')) : ?>
    // WPForms
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('wpformsAjaxSubmitSuccess', function(event, response) {
            var formId = (response && response.data) ? response.data.form_id : '';
            var form = (event && event.target && event.target.tagName === 'FORM') ? event.target
                : document.querySelector('#wpforms-form-' + String(formId).replace(/[^0-9]/g, ''));
            send('wpforms_' + formId, form, '', 'wpforms', false);
        });
    }
    <?php endif; ?>

    <?php if (defined('FLUENTFORM_VERSION')) : ?>
    // Fluent Forms
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('fluentform_submission_success', function(event, response, form) {
            var el = (form && form.length) ? form[0] : (form && form.tagName ? form : null);
            var formId = form && typeof form.data === 'function' ? form.data('form_id') : '';
            send('fluent_' + (formId || ''), el, '', 'fluentforms', false);
        });
    }
    <?php endif; ?>

    <?php if (class_exists('GFCommon')) : ?>
    // Gravity Forms: the form element is gone after the confirmation is
    // rendered, so the form is looked up by id if it still exists.
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('gform_confirmation_loaded', function(event, formId) {
            var id = String(formId || '').replace(/[^0-9]/g, '');
            send('gf_' + id, document.getElementById('gform_' + id), '', 'gravityforms', false, 'gform_' + id);
        });
    }
    <?php endif; ?>

    <?php if (defined('SRFM_VER')) : ?>
    // SureForms: event verified against assets/build/formSubmit.js in plugin v2.10.1.
    // Dispatched as: new CustomEvent('srfm_form_submission_success', {detail:{formId:'srfm-form-<id>'}});
    document.addEventListener('srfm_form_submission_success', function(e) {
        var detail = e.detail || {};
        var domId = String(detail.formId || '');
        var rawId = domId.replace(/^srfm-form-/, '').replace(/[^A-Za-z0-9_\-]/g, '');
        var form = (domId && document.getElementById(domId)) ||
            document.querySelector('.srfm-form[form-id="' + rawId + '"]');
        send('srfm_' + rawId, form, '', 'sureforms', false, domId);
    });
    <?php endif; ?>

    <?php if (self::needs_html_fallback($events)) : ?>
    // HTML fallback: any form not handled above.
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || typeof form.closest !== 'function') return;
        if (form.closest('.wpcf7-form') ||
            form.closest('.wpforms-form') ||
            form.closest('.fluentform') ||
            form.closest('.gform_wrapper') ||
            form.closest('.srfm-form')) {
            return;
        }
        // Suppress only a re-dispatch of the SAME submit within 1 s.
        var now = Date.now();
        var last = parseInt(form.getAttribute('data-trackwp-last-submit') || '0', 10);
        if (last && (now - last) < 1000) return;
        form.setAttribute('data-trackwp-last-submit', String(now));

        var action = form.getAttribute('action') || '';
        var formId = form.id || action.split('?')[0].split('#')[0] || 'html_form';
        // A non-AJAX submit navigates: dispatch synchronously.
        send(formId, form, '', 'html', true);
    }, true);
    <?php endif; ?>

    } // end init()

    if (window.trackwp && typeof window.trackwp.sendFormEvent === 'function') {
        init();
    } else {
        document.addEventListener('trackwp:ready', init);
    }

})();
</script>
        <?php
    }

    /**
     * Get list of detected form plugins (for admin info).
     */
    public function get_active_form_plugins() {
        $plugins = array();
        if (defined('WPCF7_VERSION'))      $plugins[] = 'Contact Form 7';
        if (defined('WPFORMS_VERSION'))    $plugins[] = 'WPForms';
        if (defined('FLUENTFORM_VERSION')) $plugins[] = 'Fluent Forms';
        if (class_exists('GFCommon'))      $plugins[] = 'Gravity Forms';
        if (defined('SRFM_VER'))           $plugins[] = 'SureForms';
        return $plugins;
    }
}
