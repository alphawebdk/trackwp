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

    var triggerTypes, metaEventTypes, currencies, conditionSchema;
    var eventsData = [];

    // =====================================================================
    // Init
    // =====================================================================

    $(function () {
        triggerTypes   = JSON.parse($('#trackwp-trigger-types').text() || '{}');
        metaEventTypes = JSON.parse($('#trackwp-meta-event-types').text() || '{}');
        currencies     = JSON.parse($('#trackwp-currencies').text() || '{}');
        conditionSchema = JSON.parse($('#trackwp-conditions-schema').text() || '{}');

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

        // Normalise every event to the firing-trigger shape so the builder has
        // one thing to render. Configs saved before 1.9.0 carry only the flat
        // trigger_* fields — those become a single trigger with no conditions.
        $.each(eventsData, function (i, event) {
            if (!event.firing_triggers || !event.firing_triggers.length) {
                event.firing_triggers = [triggerFromLegacy(event)];
            }
        });

        renderEventTable();

        $('#trackwp-add-event').on('click', addEvent);

        // Serialization happens in initFormValidation()'s submit handler, which
        // must run validation first — a second handler here would only
        // duplicate the work.
    }

    function triggerFromLegacy(event) {
        return {
            type: event.trigger_type || 'css_click',
            css_selector: event.css_selector || '',
            url_match: event.url_match || '',
            scroll_depth: event.scroll_depth || 0,
            time_seconds: event.time_seconds || 0,
            js_event: event.js_event || '',
            conditions: []
        };
    }

    function blankTrigger() {
        return {
            type: 'css_click',
            css_selector: '',
            url_match: '',
            scroll_depth: 0,
            time_seconds: 0,
            js_event: '',
            conditions: []
        };
    }

    function renderEventTable() {
        var $tbody = $('#trackwp-events-tbody');
        $tbody.empty();

        $.each(eventsData, function (i, event) {
            $tbody.append(buildEventRow(i, event));
            $tbody.append(buildExpandPanel(i, event));
        });
    }

    // Short human summary of an event's triggers for the collapsed table row.
    function triggerSummary(event) {
        var triggers = event.firing_triggers || [];
        if (!triggers.length) return '—';
        var first = triggerTypes[triggers[0].type] || triggers[0].type;
        var extra = 0;
        for (var t = 0; t < triggers.length; t++) {
            extra += (triggers[t].conditions || []).length;
        }
        var parts = [first];
        if (triggers.length > 1) {
            parts.push('+' + (triggers.length - 1) + ' ' + (window.trackwpAdminConfig && trackwpAdminConfig.strings.moreTriggers || 'flere'));
        }
        if (extra) {
            parts.push(extra + ' ' + (window.trackwpAdminConfig && trackwpAdminConfig.strings.conditions || 'betingelser'));
        }
        return parts.join(' · ');
    }

    function buildEventRow(index, event) {
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

        // Triggers — read-only summary; an event can now have several, so a
        // single dropdown here would misrepresent the configuration. Editing
        // happens in the expand panel.
        $tr.append(
            $('<td>').addClass('trackwp-col-trigger').append(
                $('<span>').addClass('trackwp-trigger-summary').text(triggerSummary(event))
            )
        );

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
        $.each(currencies, function (code) {
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
                .text('▼')
                .attr('title', (window.trackwpAdminConfig && trackwpAdminConfig.strings.expand) || 'Udvid')
                .on('click', function () {
                    var $panel = $('#trackwp-expand-' + index);
                    $panel.toggle();
                    $(this).text($panel.is(':visible') ? '▲' : '▼');
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

    // =====================================================================
    // Firing triggers + conditions builder
    //
    // Follows Google Tag Manager's model, and its wording, so it reads the way
    // people already expect: the event fires when ANY trigger matches, and a
    // trigger matches when ALL of its conditions are true. No nested boolean
    // groups — GTM does not have them either, and they get unreadable fast.
    // =====================================================================

    function buildExpandPanel(index, event) {
        var $row = $('<tr>').attr('id', 'trackwp-expand-' + index)
            .addClass('trackwp-event-expand-row').hide();
        var $cell = $('<td>').attr('colspan', 9);
        var $wrap = $('<div>').addClass('trackwp-event-detail');

        var $triggers = $('<div>').addClass('trackwp-triggers');
        $wrap.append(
            $('<p>').addClass('description')
                .text((window.trackwpAdminConfig && trackwpAdminConfig.strings.anyTrigger) ||
                      'Begivenheden sendes når EN AF disse triggere matcher.')
        );
        $wrap.append($triggers);

        renderTriggers($triggers, index);

        var $addTrigger = $('<button>').attr('type', 'button').addClass('button')
            .text('+ ' + ((window.trackwpAdminConfig && trackwpAdminConfig.strings.addTrigger) || 'Tilføj alternativ trigger'))
            .on('click', function () {
                var triggers = eventsData[index].firing_triggers;
                if (triggers.length >= schemaMax('maxTriggers', 10)) return;
                triggers.push(blankTrigger());
                renderTriggers(triggersContainer(index), index);
                refreshSummary(index);
            });
        $wrap.append($('<p>').append($addTrigger));

        // Send To checkboxes
        var sendTo = event.send_to || {};
        var $sendTo = $('<p>');
        $sendTo.append($('<strong>').text((window.trackwpAdminConfig && trackwpAdminConfig.strings.sendTo) || 'Send til') + ' ');
        $.each([['ga4', 'GA4'], ['google_ads', 'Google Ads'], ['meta', 'Meta']], function (i, pair) {
            var key = pair[0];
            var $cb = $('<input>').attr({ type: 'checkbox' }).prop('checked', !!sendTo[key])
                .on('change', function () {
                    if (!eventsData[index].send_to) eventsData[index].send_to = {};
                    eventsData[index].send_to[key] = $(this).is(':checked');
                });
            $sendTo.append($('<label>').css('margin-right', '14px').append($cb).append(' ' + pair[1]));
        });
        $wrap.append($sendTo);

        $cell.append($wrap);
        $row.append($cell);
        return $row;
    }

    // The panel is re-rendered in place, so look the container up by index
    // rather than holding a stale jQuery reference.
    function triggersContainer(index) {
        return $('#trackwp-expand-' + index).find('.trackwp-triggers');
    }

    function schemaMax(key, fallback) {
        return (conditionSchema && conditionSchema[key]) ? conditionSchema[key] : fallback;
    }

    function refreshSummary(index) {
        $('#trackwp-events-tbody tr[data-index="' + index + '"] .trackwp-trigger-summary')
            .text(triggerSummary(eventsData[index]));
    }

    function renderTriggers($container, index) {
        $container.empty();
        var triggers = eventsData[index].firing_triggers;

        $.each(triggers, function (t) {
            if (t > 0) {
                $container.append($('<div>').addClass('trackwp-or-divider').text('OR'));
            }
            $container.append(buildTriggerCard(index, t));
        });
    }

    function buildTriggerCard(index, t) {
        var trigger = eventsData[index].firing_triggers[t];
        var $card = $('<div>').addClass('trackwp-trigger-card');

        // --- header: type + remove ---
        var $head = $('<div>').addClass('trackwp-trigger-head');
        $head.append($('<strong>').text(
            ((window.trackwpAdminConfig && trackwpAdminConfig.strings.trigger) || 'Trigger') + ' ' + (t + 1)
        ));

        var $typeSelect = $('<select>');
        $.each(triggerTypes, function (val, label) {
            $typeSelect.append($('<option>').val(val).text(label).prop('selected', val === trigger.type));
        });
        $typeSelect.on('change', function () {
            trigger.type = $(this).val();
            // Conditions referencing variables that no longer exist for this
            // trigger type would silently never match — drop them instead.
            trigger.conditions = $.grep(trigger.conditions || [], function (c) {
                return variableAllowed(c.variable, trigger.type);
            });
            renderTriggers(triggersContainer(index), index);
            refreshSummary(index);
        });
        $head.append($typeSelect);

        if (eventsData[index].firing_triggers.length > 1) {
            $head.append(
                $('<button>').attr('type', 'button').addClass('button-link trackwp-remove-trigger')
                    .html('&times;')
                    .attr('title', (window.trackwpAdminConfig && trackwpAdminConfig.strings.removeTrigger) || 'Fjern trigger')
                    .on('click', function () {
                        eventsData[index].firing_triggers.splice(t, 1);
                        renderTriggers(triggersContainer(index), index);
                        refreshSummary(index);
                    })
            );
        }
        $card.append($head);

        // --- type-specific configuration ---
        $card.append(buildTriggerConfig(index, t, trigger));

        // --- conditions ---
        $card.append(
            $('<p>').addClass('description').css('margin-top', '10px').text(
                (window.trackwpAdminConfig && trackwpAdminConfig.strings.allConditions) ||
                'Udløs kun når ALLE disse betingelser er sande:'
            )
        );

        var $conds = $('<div>').addClass('trackwp-conditions');
        $.each(trigger.conditions || [], function (c) {
            if (c > 0) {
                $conds.append($('<div>').addClass('trackwp-and-divider').text('AND'));
            }
            $conds.append(buildConditionRow(index, t, c));
        });
        if (!trigger.conditions || !trigger.conditions.length) {
            $conds.append(
                $('<p>').addClass('description').css('font-style', 'italic').text(
                    (window.trackwpAdminConfig && trackwpAdminConfig.strings.noConditions) ||
                    'Ingen betingelser — triggeren matcher altid.'
                )
            );
        }
        $card.append($conds);

        $card.append($('<p>').append(
            $('<button>').attr('type', 'button').addClass('button button-small')
                .text('+ ' + ((window.trackwpAdminConfig && trackwpAdminConfig.strings.addCondition) || 'Tilføj betingelse'))
                .on('click', function () {
                    if (!trigger.conditions) trigger.conditions = [];
                    if (trigger.conditions.length >= schemaMax('maxConditions', 10)) return;
                    var vars = variablesForTrigger(trigger.type);
                    var firstVar = vars.length ? vars[0] : 'page_url';
                    trigger.conditions.push({
                        variable: firstVar,
                        operator: defaultOperator(firstVar),
                        value: '',
                        param: ''
                    });
                    renderTriggers(triggersContainer(index), index);
                    refreshSummary(index);
                })
        ));

        return $card;
    }

    function buildTriggerConfig(index, t, trigger) {
        var $box = $('<div>').addClass('trackwp-trigger-config');

        function field(labelKey, fallbackLabel, prop, type, placeholder) {
            var $p = $('<p>');
            $p.append($('<label>').text(
                ((window.trackwpAdminConfig && trackwpAdminConfig.strings[labelKey]) || fallbackLabel) + ' '
            ));
            var $input = $('<input>').attr('type', type || 'text').val(trigger[prop] || (type === 'number' ? 0 : ''));
            if (placeholder) $input.attr('placeholder', placeholder);
            if (type === 'number') $input.attr('min', 0).addClass('small-text');
            else $input.addClass('regular-text');
            $input.on('input change', function () {
                trigger[prop] = (type === 'number') ? (parseInt($(this).val(), 10) || 0) : $(this).val();
                refreshSummary(index);
            });
            $p.append($input);
            return $p;
        }

        switch (trigger.type) {
            case 'css_click':
                $box.append(field('cssSelector', 'CSS-selector', 'css_selector', 'text', 'a[href^="tel:"]'));
                break;
            case 'form_submit':
            case 'file_download':
                $box.append(field('cssSelector', 'CSS-selector', 'css_selector', 'text', 'form.kontakt'));
                break;
            case 'url_match':
                $box.append(field('urlMatch', 'URL indeholder', 'url_match', 'text', '/tak-for-din-henvendelse'));
                break;
            case 'scroll_depth':
                $box.append(field('scrollDepth', 'Scrolldybde (%)', 'scroll_depth', 'number'));
                break;
            case 'time_on_page':
                $box.append(field('timeSeconds', 'Sekunder', 'time_seconds', 'number'));
                break;
            case 'js_event':
                $box.append(field('jsEvent', 'JavaScript-eventnavn', 'js_event', 'text', 'min_custom_event'));
                break;
        }
        return $box;
    }

    // --- schema helpers -------------------------------------------------

    function variablesForTrigger(triggerType) {
        if (!conditionSchema || !conditionSchema.variables) return [];
        var scopes = (conditionSchema.scopes && conditionSchema.scopes[triggerType]) || ['page'];
        var out = [];
        $.each(conditionSchema.variables, function (key, def) {
            if ($.inArray(def.scope, scopes) !== -1) out.push(key);
        });
        return out;
    }

    function variableAllowed(variable, triggerType) {
        return $.inArray(variable, variablesForTrigger(triggerType)) !== -1;
    }

    function variableDef(variable) {
        return (conditionSchema && conditionSchema.variables && conditionSchema.variables[variable]) || null;
    }

    function operatorsForVariable(variable) {
        var def = variableDef(variable);
        if (!def || !conditionSchema.operators) return {};
        return conditionSchema.operators[def.type] || {};
    }

    function defaultOperator(variable) {
        var ops = operatorsForVariable(variable);
        for (var k in ops) {
            if (Object.prototype.hasOwnProperty.call(ops, k)) return k;
        }
        return 'equals';
    }

    function operatorTakesValue(operator) {
        var valueless = (conditionSchema && conditionSchema.valueless) || ['exists', 'not_exists'];
        return $.inArray(operator, valueless) === -1;
    }

    function buildConditionRow(index, t, c) {
        var trigger = eventsData[index].firing_triggers[t];
        var cond = trigger.conditions[c];
        var $row = $('<div>').addClass('trackwp-condition-row');

        // Variable
        var $var = $('<select>').addClass('trackwp-cond-var');
        $.each(variablesForTrigger(trigger.type), function (i, key) {
            var def = variableDef(key);
            $var.append($('<option>').val(key).text(def ? def.label : key).prop('selected', key === cond.variable));
        });
        $var.on('change', function () {
            cond.variable = $(this).val();
            // Operators are per variable type — reset to a valid one.
            cond.operator = defaultOperator(cond.variable);
            renderTriggers(triggersContainer(index), index);
        });
        $row.append($var);

        // Query Parameter needs its own name field.
        var def = variableDef(cond.variable);
        if (def && def.param) {
            $row.append(
                $('<input>').attr({ type: 'text', placeholder: 'utm_campaign' })
                    .addClass('trackwp-cond-param')
                    .val(cond.param || '')
                    .on('input', function () { cond.param = $(this).val(); })
            );
        }

        // Operator
        var $op = $('<select>').addClass('trackwp-cond-op');
        $.each(operatorsForVariable(cond.variable), function (key, label) {
            $op.append($('<option>').val(key).text(label).prop('selected', key === cond.operator));
        });
        $op.on('change', function () {
            cond.operator = $(this).val();
            renderTriggers(triggersContainer(index), index);
        });
        $row.append($op);

        // Value (hidden for exists / does not exist)
        if (operatorTakesValue(cond.operator)) {
            $row.append(
                $('<input>').attr({ type: 'text' })
                    .addClass('trackwp-cond-value')
                    .val(cond.value || '')
                    .on('input', function () { cond.value = $(this).val(); })
            );
        }

        // Remove
        $row.append(
            $('<button>').attr('type', 'button').addClass('button-link trackwp-remove-condition')
                .html('&times;')
                .on('click', function () {
                    trigger.conditions.splice(c, 1);
                    renderTriggers(triggersContainer(index), index);
                    refreshSummary(index);
                })
        );

        return $row;
    }

    // Event names must be unique: duplicates bind twice on the frontend and
    // dispatch twice with different event_ids (real double counting), so the
    // server now rejects them. Seed a free name instead of always 'new_event'.
    function uniqueEventName(base) {
        var taken = {};
        $.each(eventsData, function (i, event) {
            if (event && event.name) taken[event.name] = true;
        });
        if (!taken[base]) return base;
        for (var n = 2; n < 1000; n++) {
            if (!taken[base + '_' + n]) return base + '_' + n;
        }
        return base;
    }

    function addEvent() {
        var name = uniqueEventName('new_event');
        eventsData.push({
            enabled: true,
            name: name,
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
            send_to: { ga4: true, google_ads: true, meta: true },
            firing_triggers: [blankTrigger()]
        });
        renderEventTable();

        // Open the new row's detail panel straight away: a click trigger needs
        // a CSS selector to validate, and that field lives inside the collapsed
        // panel — leaving it shut just produced an unexplained error on save.
        var index = eventsData.length - 1;
        var $panel = $('#trackwp-expand-' + index);
        var $row = $('#trackwp-events-tbody').find('tr[data-index="' + index + '"]');
        $panel.show();
        $row.find('.trackwp-col-actions .button').first().text('▲');

        if ($row.length && $row.offset()) {
            $('html, body').animate({ scrollTop: $row.offset().top - 100 }, 300, function () {
                $panel.find('.trackwp-trigger-config input').first().trigger('focus');
            });
        }
    }

    function syncEventsToJson() {
        // Keep the flat legacy fields in step with the first trigger — the
        // server treats them as a mirror, and anything still reading them
        // (older integrations) must not see stale values.
        $.each(eventsData, function (i, event) {
            var triggers = event.firing_triggers || [];
            if (!triggers.length) {
                triggers = [triggerFromLegacy(event)];
                event.firing_triggers = triggers;
            }
            var primary = triggers[0];
            event.trigger_type = primary.type;
            event.css_selector = primary.css_selector || '';
            event.url_match    = primary.url_match || '';
            event.scroll_depth = primary.scroll_depth || 0;
            event.time_seconds = primary.time_seconds || 0;
            event.js_event     = primary.js_event || '';
        });
        $('#trackwp-events-json').val(JSON.stringify(eventsData));
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
            var seen = {};

            $.each(eventsData, function (i, event) {
                // Event name: required, lowercase alphanumeric + underscores
                if (!event.name || !/^[a-z][a-z0-9_]{0,39}$/.test(event.name)) {
                    errors.push('Event #' + (i + 1) + ': Name must start with a lowercase letter, contain only a-z, 0-9, underscores, max 40 characters.');
                } else if (seen[event.name]) {
                    // Mirrors the server-side rule — duplicates fire twice.
                    errors.push('Event #' + (i + 1) + ': the name "' + event.name + '" is already used by event #' + seen[event.name] + '. Names must be unique.');
                } else {
                    seen[event.name] = i + 1;
                }

                // Every firing trigger must be usable on its own — an event
                // fires when ANY of them matches, so one broken trigger is a
                // silently dead branch rather than a harmless leftover.
                var triggers = event.firing_triggers || [];
                if (!triggers.length) {
                    errors.push('Event #' + (i + 1) + ' (' + event.name + '): no trigger configured.');
                }
                $.each(triggers, function (t, trg) {
                    var where = 'Event #' + (i + 1) + ' (' + event.name + '), trigger ' + (t + 1) + ': ';
                    if (trg.type === 'css_click' && !trg.css_selector) {
                        errors.push(where + 'a CSS selector is required for click triggers.');
                    }
                    if (trg.type === 'js_event' && !trg.js_event) {
                        errors.push(where + 'a JavaScript event name is required.');
                    }
                    $.each(trg.conditions || [], function (c, cond) {
                        var cWhere = where + 'condition ' + (c + 1) + ': ';
                        if (operatorTakesValue(cond.operator) && !String(cond.value || '').length) {
                            errors.push(cWhere + 'a value is required.');
                        }
                        var def = variableDef(cond.variable);
                        if (def && def.param && !String(cond.param || '').length) {
                            errors.push(cWhere + 'a parameter name is required.');
                        }
                    });
                });
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
