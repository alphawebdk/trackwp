<?php
/**
 * Form plugin integrations for TrackWP.
 *
 * Detects Contact Form 7, WPForms, Fluent Forms, Gravity Forms, SureForms,
 * and provides a fallback for standard HTML forms.
 *
 * @package TrackWP
 */

defined('ABSPATH') || exit;

class TrackWP_Forms {

    public function __construct() {
        add_action('wp_footer', array($this, 'output_form_listeners'), 20);
    }

    /**
     * Output inline JS for form tracking.
     * Priority 20 ensures this runs after consent and tracking scripts.
     */
    public function output_form_listeners() {
        if (is_admin()) return;

        // Check if form_submit event is active
        $events = get_option('trackwp_events', array());
        $form_active = false;
        foreach ($events as $event) {
            if (isset($event['name']) && $event['name'] === 'form_submit' && !empty($event['enabled'])) {
                $form_active = true;
                break;
            }
        }
        if (!$form_active) return;

        ?>
<script>
(function() {
    'use strict';

    // trackwp.js loads async and may not have executed yet — bind immediately
    // if the API exists, otherwise wait for its 'trackwp:ready' handshake.
    var initialized = false;

    function init() {
    if (initialized) return;
    initialized = true;

    <?php if (defined('WPCF7_VERSION')) : ?>
    // Contact Form 7
    document.addEventListener('wpcf7mailsent', function(e) {
        var inputs = (e.detail && e.detail.inputs) ? e.detail.inputs : [];
        var email = null, phone = null;
        for (var i = 0; i < inputs.length; i++) {
            if (/email|mail/i.test(inputs[i].name)) email = inputs[i].value;
            if (/tel|phone|telefon/i.test(inputs[i].name)) phone = inputs[i].value;
        }
        window.trackwp.sendEvent('form_submit', {
            form_id: 'cf7_' + (e.detail.contactFormId || ''),
            enhanced: { email: email, phone: phone } // JS will hash before POST
        });
    });
    <?php endif; ?>

    <?php if (defined('WPFORMS_VERSION')) : ?>
    // WPForms
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('wpformsAjaxSubmitSuccess', function(event, response) {
            var formId = (response && response.data) ? response.data.form_id : '';
            var form = document.querySelector('#wpforms-form-' + formId);
            var emailEl = form ? form.querySelector('[type="email"]') : null;
            var phoneEl = form ? form.querySelector('[type="tel"]') : null;
            window.trackwp.sendEvent('form_submit', {
                form_id: 'wpforms_' + formId,
                enhanced: {
                    email: emailEl ? emailEl.value : null, // JS will hash before POST
                    phone: phoneEl ? phoneEl.value : null
                }
            });
        });
    }
    <?php endif; ?>

    <?php if (defined('FLUENTFORM_VERSION')) : ?>
    // Fluent Forms
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('fluentform_submission_success', function(event, response, form) {
            var formId = form ? form.data('form_id') : '';
            var emailEl = form ? form.find('[type="email"]') : null;
            var phoneEl = form ? form.find('[type="tel"]') : null;
            window.trackwp.sendEvent('form_submit', {
                form_id: 'fluent_' + formId,
                enhanced: {
                    email: (emailEl && emailEl.length) ? emailEl.val() : null, // JS will hash before POST
                    phone: (phoneEl && phoneEl.length) ? phoneEl.val() : null
                }
            });
        });
    }
    <?php endif; ?>

    <?php if (class_exists('GFCommon')) : ?>
    // Gravity Forms
    var gfCache = {};
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('gform_pre_submission', function(event) {
            var forms = document.querySelectorAll('.gform_wrapper form');
            for (var i = 0; i < forms.length; i++) {
                var idInput = forms[i].querySelector('input[name="gform_submit"]');
                if (idInput) {
                    var emailEl = forms[i].querySelector('[type="email"]');
                    var phoneEl = forms[i].querySelector('[type="tel"]');
                    gfCache[idInput.value] = {
                        email: emailEl ? emailEl.value : null, // JS will hash before POST
                        phone: phoneEl ? phoneEl.value : null
                    };
                }
            }
        });
        jQuery(document).on('gform_confirmation_loaded', function(event, formId) {
            var cached = gfCache[formId] || {};
            window.trackwp.sendEvent('form_submit', {
                form_id: 'gf_' + formId,
                enhanced: cached
            });
            delete gfCache[formId];
        });
    }
    <?php endif; ?>

    <?php if (defined('SRFM_VER')) : ?>
    // SureForms — event verified against assets/build/formSubmit.js in plugin v2.10.1.
    // Dispatched as: new CustomEvent('srfm_form_submission_success', {detail:{formId:'srfm-form-<id>'}});
    document.addEventListener('srfm_form_submission_success', function(e) {
        var detail = e.detail || {};
        var domId = detail.formId || ''; // already prefixed e.g. "srfm-form-123"
        var rawId = domId.replace(/^srfm-form-/, '');
        // SureForms <form> has class `srfm-form` and attribute `form-id="<rawId>"`.
        var form = (domId && document.getElementById(domId)) ||
                   document.querySelector('.srfm-form[form-id="' + rawId + '"]') ||
                   document.querySelector('.srfm-form');
        var emailEl = form ? form.querySelector('[type="email"]') : null;
        var phoneEl = form ? form.querySelector('[type="tel"]') : null;
        window.trackwp.sendEvent('form_submit', {
            form_id: 'srfm_' + rawId,
            enhanced: {
                email: emailEl ? emailEl.value : null, // JS will hash before POST
                phone: phoneEl ? phoneEl.value : null
            }
        });
    });
    <?php endif; ?>

    // HTML Fallback — catches any form not handled above
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || form.dataset.trackwpHandled) return;

        // Skip forms already handled by specific plugins
        if (form.closest('.wpcf7-form') ||
            form.closest('.wpforms-form') ||
            form.closest('.fluentform') ||
            form.closest('.gform_wrapper') ||
            form.closest('.srfm-form')) {
            return;
        }

        form.dataset.trackwpHandled = '1';
        var emailEl = form.querySelector('[type="email"]');
        var phoneEl = form.querySelector('[type="tel"]');
        window.trackwp.sendEvent('form_submit', {
            form_id: form.id || form.getAttribute('action') || 'html_form',
            enhanced: {
                email: emailEl ? emailEl.value : null, // sent raw (nav) — server hashes
                phone: phoneEl ? phoneEl.value : null
            }
        }, { nav: true }); // non-AJAX submit navigates — dispatch synchronously
    }, true); // capture phase — fires before form navigation

    } // end init()

    if (window.trackwp && typeof window.trackwp.sendEvent === 'function') {
        init();
    } else {
        document.addEventListener('trackwp:ready', init, { once: true });
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
        if (defined('WPCF7_VERSION'))    $plugins[] = 'Contact Form 7';
        if (defined('WPFORMS_VERSION'))  $plugins[] = 'WPForms';
        if (defined('FLUENTFORM_VERSION')) $plugins[] = 'Fluent Forms';
        if (class_exists('GFCommon'))    $plugins[] = 'Gravity Forms';
        if (defined('SRFM_VER'))         $plugins[] = 'SureForms';
        return $plugins;
    }
}
