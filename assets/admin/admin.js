/**
 * TrackWP Admin JavaScript
 *
 * Handles tab switching, event CRUD, color pickers, consent preview,
 * password masking, and form validation.
 *
 * @package TrackWP
 */

(function ($) {
    'use strict';

    var triggerTypes, metaEventTypes, currencies;
    var eventsData = [];

    // =====================================================================
    // Init
    // =====================================================================

    $(function () {
        triggerTypes   = JSON.parse($('#trackwp-trigger-types').text() || '{}');
        metaEventTypes = JSON.parse($('#trackwp-meta-event-types').text() || '{}');
        currencies     = JSON.parse($('#trackwp-currencies').text() || '{}');

        initTabs();
        initEvents();
        initColorPickers();
        initConsentPreview();
        initPasswordFields();
        initFormValidation();
        initGa4IdWarning();
    });

    // =====================================================================
    // GA4 Measurement ID warning (G- vs GT-/GTM-)
    // =====================================================================

    function initGa4IdWarning() {
        var input = document.getElementById('ga4_measurement_id');
        var warn  = document.getElementById('trackwp-ga4-id-warning');
        if (!input || !warn) return;
        var text = warn.querySelector('.trackwp-warning-text');

        function update() {
            var v = (input.value || '').trim().toUpperCase();
            if (!v) { warn.style.display = 'none'; return; }
            if (v.indexOf('G-') === 0) { warn.style.display = 'none'; return; }
            var detected = 'ukendt format';
            if (v.indexOf('GTM-') === 0) {
                detected = 'GTM-container ID';
            } else if (v.indexOf('GT-') === 0) {
                detected = 'Google Tag ID';
            }
            text.textContent = 'Du har indtastet et ' + detected + '. Server-side Measurement Protocol kraever en GA4 Measurement ID i G-XXXXXXXXXX format. Klient-side tracking fungerer stadig hvis Google Tag indeholder en GA4-destination, men server-side CAPI til GA4 vil ikke virke.';
            warn.style.display = 'block';
        }

        input.addEventListener('input', update);
        update(); // run on load
    }

    // =====================================================================
    // Tab Switching
    // =====================================================================

    function initTabs() {
        var $tabs = $('.trackwp-tabs .nav-tab');
        var $panels = $('.trackwp-tab-content');

        // Restore from URL hash or localStorage
        var savedTab = window.location.hash
            ? window.location.hash.substring(1)
            : localStorage.getItem('trackwp_active_tab');

        if (savedTab && $('[data-tab="' + savedTab + '"]').length) {
            switchTab(savedTab);
        }

        $tabs.on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            switchTab(tab);
        });

        function switchTab(tab) {
            $tabs.removeClass('nav-tab-active');
            $panels.removeClass('active');
            $('[data-tab="' + tab + '"].nav-tab').addClass('nav-tab-active');
            $('#tab-' + tab).addClass('active');
            localStorage.setItem('trackwp_active_tab', tab);
            window.history.replaceState(null, null, '#' + tab);
        }
    }

    // =====================================================================
    // Event Table CRUD
    // =====================================================================

    function initEvents() {
        var $json = $('#trackwp-events-json');
        if (!$json.length) return;

        try {
            eventsData = JSON.parse($json.val()) || [];
        } catch (e) {
            eventsData = [];
        }

        renderEventTable();

        $('#trackwp-add-event').on('click', addEvent);

        // Serialize back to JSON on form submit
        $('#trackwp-events-form').on('submit', function () {
            syncEventsToJson();
        });
    }

    function renderEventTable() {
        var $tbody = $('#trackwp-events-tbody');
        $tbody.empty();

        $.each(eventsData, function (i, event) {
            $tbody.append(buildEventRow(i, event));
            $tbody.append(buildExpandPanel(i, event));
        });
    }

    function buildEventRow(index, event) {
        var triggerLabel = triggerTypes[event.trigger_type] || event.trigger_type;
        var metaLabel = metaEventTypes[event.meta_event] || event.meta_event || '';

        var $tr = $('<tr>').attr('data-index', index);

        // Enabled
        $tr.append(
            $('<td>').addClass('trackwp-col-enabled').append(
                $('<input>').attr({ type: 'checkbox', 'data-field': 'enabled' })
                    .prop('checked', !!event.enabled)
                    .on('change', function () {
                        eventsData[index].enabled = $(this).is(':checked');
                    })
            )
        );

        // Event Name
        $tr.append(
            $('<td>').addClass('trackwp-col-name').append(
                $('<input>').attr({ type: 'text', 'data-field': 'name' })
                    .val(event.name || '')
                    .on('input', function () {
                        eventsData[index].name = $(this).val();
                    })
            )
        );

        // Display Name
        $tr.append(
            $('<td>').addClass('trackwp-col-display').append(
                $('<input>').attr({ type: 'text', 'data-field': 'display_name' })
                    .val(event.display_name || '')
                    .on('input', function () {
                        eventsData[index].display_name = $(this).val();
                    })
            )
        );

        // Trigger Type
        var $triggerSelect = $('<select>').attr('data-field', 'trigger_type');
        $.each(triggerTypes, function (val, label) {
            $triggerSelect.append(
                $('<option>').val(val).text(label)
                    .prop('selected', val === event.trigger_type)
            );
        });
        $triggerSelect.on('change', function () {
            eventsData[index].trigger_type = $(this).val();
            updateTriggerFields(index);
        });
        $tr.append($('<td>').addClass('trackwp-col-trigger').append($triggerSelect));

        // Value
        $tr.append(
            $('<td>').addClass('trackwp-col-value').append(
                $('<input>').attr({ type: 'number', step: '0.01', min: '0', 'data-field': 'value' })
                    .val(event.value || 0)
                    .on('input', function () {
                        eventsData[index].value = parseFloat($(this).val()) || 0;
                    })
            )
        );

        // Currency
        var $currSelect = $('<select>').attr('data-field', 'currency');
        $.each(currencies, function (code, label) {
            $currSelect.append(
                $('<option>').val(code).text(code)
                    .prop('selected', code === event.currency)
            );
        });
        $currSelect.on('change', function () {
            eventsData[index].currency = $(this).val();
        });
        $tr.append($('<td>').addClass('trackwp-col-currency').append($currSelect));

        // Google Ads Label
        $tr.append(
            $('<td>').addClass('trackwp-col-ads').append(
                $('<input>').attr({ type: 'text', 'data-field': 'ads_label' })
                    .val(event.ads_label || '')
                    .on('input', function () {
                        eventsData[index].ads_label = $(this).val();
                    })
            )
        );

        // Meta Event
        var $metaSelect = $('<select>').attr('data-field', 'meta_event');
        $.each(metaEventTypes, function (val, label) {
            $metaSelect.append(
                $('<option>').val(val).text(label)
                    .prop('selected', val === event.meta_event)
            );
        });
        $metaSelect.on('change', function () {
            eventsData[index].meta_event = $(this).val();
        });
        $tr.append($('<td>').addClass('trackwp-col-meta').append($metaSelect));

        // Actions
        var $actions = $('<td>').addClass('trackwp-col-actions');
        $actions.append(
            $('<button>').attr('type', 'button').addClass('button')
                .text('\u25BC')
                .attr('title', (window.trackwpAdminConfig && trackwpAdminConfig.strings.expand) || 'Udvid')
                .on('click', function () {
                    var $panel = $('#trackwp-expand-' + index);
                    $panel.toggle();
                    $(this).text($panel.is(':visible') ? '\u25B2' : '\u25BC');
                })
        );
        $actions.append(
            $('<button>').attr('type', 'button').addClass('button')
                .html('&times;')
                .attr('title', (window.trackwpAdminConfig && trackwpAdminConfig.strings.delete) || 'Slet')
                .on('click', function () {
                    if (confirm((window.trackwpAdminConfig && trackwpAdminConfig.strings.deleteEvent) || 'Slet denne begivenhed?')) {
                        eventsData.splice(index, 1);
                        renderEventTable();
                    }
                })
        );
        $tr.append($actions);

        return $tr;
    }

    function buildExpandPanel(index, event) {
        var triggerType = event.trigger_type || 'css_click';

        var html = '<tr id="trackwp-expand-' + index + '" class="trackwp-event-expand-row" style="display:none;">';
        html += '<td colspan="9"><div class="trackwp-event-detail"><table class="form-table">';

        // CSS Selector
        html += triggerField(index, 'css_selector', 'CSS Selector', 'text', event.css_selector || '', triggerType, ['css_click', 'form_submit', 'file_download']);

        // URL Match
        html += triggerField(index, 'url_match', 'URL Match', 'text', event.url_match || '', triggerType, ['url_match']);

        // Scroll Depth
        html += triggerField(index, 'scroll_depth', 'Scroll Depth (%)', 'number', event.scroll_depth || 0, triggerType, ['scroll_depth']);

        // Time on Page
        html += triggerField(index, 'time_seconds', 'Seconds', 'number', event.time_seconds || 0, triggerType, ['time_on_page']);

        // JS Event
        html += triggerField(index, 'js_event', 'Event Name', 'text', event.js_event || '', triggerType, ['js_event']);

        // Send To checkboxes
        var sendTo = event.send_to || {};
        html += '<tr><th scope="row">Send To</th><td>';
        html += '<label><input type="checkbox" data-field="send_to_ga4" ' + (sendTo.ga4 ? 'checked' : '') + ' data-index="' + index + '" /> GA4</label>&nbsp;&nbsp;';
        html += '<label><input type="checkbox" data-field="send_to_google_ads" ' + (sendTo.google_ads ? 'checked' : '') + ' data-index="' + index + '" /> Google Ads</label>&nbsp;&nbsp;';
        html += '<label><input type="checkbox" data-field="send_to_meta" ' + (sendTo.meta ? 'checked' : '') + ' data-index="' + index + '" /> Meta</label>';
        html += '</td></tr>';

        html += '</table></div></td></tr>';

        var $panel = $(html);

        // Bind send_to change events
        $panel.find('[data-field="send_to_ga4"]').on('change', function () {
            if (!eventsData[index].send_to) eventsData[index].send_to = {};
            eventsData[index].send_to.ga4 = $(this).is(':checked');
        });
        $panel.find('[data-field="send_to_google_ads"]').on('change', function () {
            if (!eventsData[index].send_to) eventsData[index].send_to = {};
            eventsData[index].send_to.google_ads = $(this).is(':checked');
        });
        $panel.find('[data-field="send_to_meta"]').on('change', function () {
            if (!eventsData[index].send_to) eventsData[index].send_to = {};
            eventsData[index].send_to.meta = $(this).is(':checked');
        });

        // Bind trigger-specific field changes
        $panel.find('[data-trigger-field]').on('input change', function () {
            var field = $(this).data('trigger-field');
            var val = $(this).val();
            if ($(this).attr('type') === 'number') {
                val = parseInt(val, 10) || 0;
            }
            eventsData[index][field] = val;
        });

        return $panel;
    }

    function triggerField(index, fieldName, label, inputType, value, currentTrigger, showForTriggers) {
        var isActive = showForTriggers.indexOf(currentTrigger) !== -1;
        var cls = 'trackwp-trigger-field' + (isActive ? ' active' : '');
        var html = '<tr class="' + cls + '" data-trigger-show="' + showForTriggers.join(',') + '">';
        html += '<th scope="row">' + label + '</th>';
        html += '<td><input type="' + inputType + '" data-trigger-field="' + fieldName + '" ';
        html += 'value="' + escAttr(String(value)) + '" data-index="' + index + '" ';
        if (inputType === 'number') html += 'min="0" ';
        html += '/></td></tr>';
        return html;
    }

    function updateTriggerFields(index) {
        var trigger = eventsData[index].trigger_type;
        var $panel = $('#trackwp-expand-' + index);
        $panel.find('.trackwp-trigger-field').each(function () {
            var showFor = $(this).data('trigger-show').split(',');
            $(this).toggleClass('active', showFor.indexOf(trigger) !== -1);
        });
    }

    function addEvent() {
        eventsData.push({
            enabled: true,
            name: 'new_event',
            display_name: 'New Event',
            trigger_type: 'css_click',
            css_selector: '',
            url_match: '',
            scroll_depth: 0,
            time_seconds: 0,
            js_event: '',
            value: 0,
            currency: 'DKK',
            ads_label: '',
            meta_event: '',
            send_to: { ga4: true, google_ads: true, meta: true }
        });
        renderEventTable();

        // Scroll to new row
        var $tbody = $('#trackwp-events-tbody');
        $('html, body').animate({
            scrollTop: $tbody.find('tr:last').offset().top - 100
        }, 300);
    }

    function syncEventsToJson() {
        $('#trackwp-events-json').val(JSON.stringify(eventsData));
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // =====================================================================
    // Color Pickers
    // =====================================================================

    function initColorPickers() {
        if ($.fn.wpColorPicker) {
            $('.trackwp-color-field').wpColorPicker({
                change: debounce(function () {
                    updateConsentPreview();
                }, 200),
                clear: function () {
                    updateConsentPreview();
                }
            });
        }
    }

    // =====================================================================
    // Consent Preview
    // =====================================================================

    function initConsentPreview() {
        var $preview = $('#trackwp-consent-preview');
        if (!$preview.length) return;

        // Update on text input changes (debounced)
        var textFields = [
            '#consent_heading',
            '#consent_description',
            '#consent_accept_text',
            '#consent_reject_text',
            '#consent_customize_text',
            '#consent_border_radius'
        ];

        $(textFields.join(',')).on('input', debounce(updateConsentPreview, 200));
        $('[name="trackwp_consent[show_reject_button]"]').on('change', updateConsentPreview);
        $('#trackwp_banner_style').on('change', function () {
            updateConsentPreview();
        });

        // Initial render
        updateConsentPreview();
    }

    function updateConsentPreview() {
        var $preview = $('#trackwp-consent-preview');
        if (!$preview.length) return;

        var style = $('#trackwp_banner_style').val() || 'dialog';
        $preview
            .attr('data-preview-style', style)
            .removeClass('trackwp-consent-preview--style-cookiebot trackwp-consent-preview--style-dialog trackwp-consent-preview--style-bottombar')
            .addClass('trackwp-consent-preview--style-' + style);

        $preview.find('.trackwp-preview-tabs').toggle(style === 'cookiebot');
        $preview.find('.trackwp-preview-categories').toggle(style !== 'bottombar');

        var bgColor         = getColorValue('#consent_bg_color') || '#274A45';
        var textColor       = getColorValue('#consent_text_color') || '#ffffff';
        var accentColor     = getColorValue('#consent_accent_color') || '#30D3C0';
        var buttonTextColor = getColorValue('#consent_button_text_color') || '#274A45';
        var borderRadius    = parseInt($('#consent_border_radius').val(), 10) || 8;
        var heading         = $('#consent_heading').val() || 'We use cookies';
        var description     = $('#consent_description').val() || '';
        var acceptText      = $('#consent_accept_text').val() || 'Accept all';
        var rejectText      = $('#consent_reject_text').val() || 'Reject all';
        var customizeText   = $('#consent_customize_text').val() || 'Customize';
        var showReject      = $('[name="trackwp_consent[show_reject_button]"]').is(':checked');

        var $banner = $preview.find('.trackwp-preview-banner');
        $banner.css({
            backgroundColor: bgColor,
            color: textColor,
            borderRadius: borderRadius + 'px'
        });

        $preview.find('.trackwp-preview-heading').text(heading);
        $preview.find('.trackwp-preview-description').text(description);

        var $accept = $preview.find('.trackwp-preview-accept');
        $accept.text(acceptText).css({
            backgroundColor: accentColor,
            color: buttonTextColor,
            borderRadius: Math.max(borderRadius - 4, 2) + 'px'
        });

        var $reject = $preview.find('.trackwp-preview-reject');
        $reject.text(rejectText).css({
            backgroundColor: 'transparent',
            color: textColor,
            border: '1px solid ' + textColor,
            borderRadius: Math.max(borderRadius - 4, 2) + 'px'
        });
        $reject.toggle(showReject);

        var $customize = $preview.find('.trackwp-preview-customize');
        $customize.text(customizeText).css({ color: accentColor });
    }

    function getColorValue(selector) {
        var $input = $(selector);
        // wp-color-picker stores value in a hidden input
        if ($input.closest('.wp-picker-container').length) {
            return $input.wpColorPicker('color');
        }
        return $input.val();
    }

    // =====================================================================
    // Password Field Masking
    // =====================================================================

    function initPasswordFields() {
        $('.trackwp-clear-secret').on('click', function () {
            var targetId = $(this).data('target');
            var $input = $('#' + targetId);
            $input.val('').attr('type', 'text').focus();
            // Et tomt secret-felt betyder "uændret" i sanitizeren, så sæt et
            // clear-flag der fortæller serveren at værdien skal slettes.
            // (Indtaster brugeren en ny værdi, vinder den over flaget server-side.)
            var clearName = 'trackwp_platforms[' + targetId + '_clear]';
            if (!$input.siblings('input[name="' + clearName + '"]').length) {
                $('<input>', { type: 'hidden', name: clearName, value: '1' }).insertAfter($input);
            }
            $(this).remove();
        });
    }

    // =====================================================================
    // Form Validation
    // =====================================================================

    function initFormValidation() {
        $('#trackwp-events-form').on('submit', function (e) {
            var errors = [];

            $.each(eventsData, function (i, event) {
                // Event name: required, lowercase alphanumeric + underscores
                if (!event.name || !/^[a-z][a-z0-9_]{0,39}$/.test(event.name)) {
                    errors.push('Event #' + (i + 1) + ': Name must start with a lowercase letter, contain only a-z, 0-9, underscores, max 40 characters.');
                }

                // CSS selector required for css_click
                if (event.trigger_type === 'css_click' && !event.css_selector) {
                    errors.push('Event #' + (i + 1) + ' (' + event.name + '): CSS selector is required for click triggers.');
                }
            });

            if (errors.length) {
                e.preventDefault();
                alert(((window.trackwpAdminConfig && trackwpAdminConfig.strings.fixErrors) || 'Ret venligst følgende fejl:') + '\n\n' + errors.join('\n'));
                return false;
            }

            syncEventsToJson();
        });
    }

    // =====================================================================
    // Utilities
    // =====================================================================

    function debounce(fn, delay) {
        var timer;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

})(jQuery);
