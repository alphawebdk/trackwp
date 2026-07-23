(function(window, document) {
    'use strict';

    // Config from wp_localize_script
    var config = window.trackwpConfig || {};
    if (!config.restUrl) return;

    var events = config.events || [];
    var googleAds = config.googleAds || {};
    var debug = !!config.debug;
    var cookieName = config.cookieName || '_twp_cid';
    var endpointSlug = config.endpointSlug || 'event';
    var dedupMode = config.dedupMode || 'client_and_server';

    // Per-pageload client id used when we may not persist a cookie (see getClientId).
    var ephemeralClientId = null;

    // === HELPERS ===

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function trackwpGetCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m.pop()) : '';
    }

    function trackwpGetUrlParam(name) {
        try {
            return new URLSearchParams(window.location.search).get(name) || '';
        } catch (e) {
            return '';
        }
    }

    function normalizePhone(value) {
        if (!value) return '';
        return String(value).replace(/\D/g, '');
    }

    // Country-code-qualified digits for hashing (mirrored server-side in PHP —
    // TrackWP_Hash::normalize_enhanced() MUST produce the exact same output):
    // raw starts with '+' → digits as-is; digits start with '00' → strip the 00;
    // exactly 8 digits → assume DK and prefix '45'; otherwise digits as-is.
    function phoneCcDigits(value) {
        if (!value) return '';
        var raw = String(value).trim();
        var digits = raw.replace(/\D/g, '');
        if (!digits) return '';
        if (raw.charAt(0) === '+') return digits;
        if (digits.indexOf('00') === 0) return digits.slice(2);
        if (digits.length === 8) return '45' + digits;
        return digits;
    }

    function normalizeEmail(value) {
        if (!value) return '';
        value = String(value).toLowerCase().trim();
        var parts = value.split('@');
        if (parts.length !== 2) return value;
        var local = parts[0];
        var domain = parts[1];
        if (domain === 'gmail.com' || domain === 'googlemail.com') {
            local = local.split('+')[0].split('.').join('');
        }
        return local + '@' + domain;
    }

    // Meta-style email normalization: trim + lowercase ONLY (no gmail dot/plus strip).
    function normalizeEmailMeta(value) {
        if (!value) return '';
        return String(value).trim().toLowerCase();
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');
    }

    // === CLIENT ID ===

    function getClientId() {
        // 1. GA4 _ga cookie (preferred — lets server-side events stitch to gtag sessions)
        var ga = getCookie('_ga');
        if (ga) {
            var parts = ga.split('.');
            if (parts.length >= 3) {
                return parts.slice(2).join('.');
            }
        }

        // 2. Our first-party cookie — only if it holds a GA4-compatible numeric id.
        //    Legacy "twp_..." ids are ignored and replaced (GA4 MP can't build sessions from them).
        var cid = getCookie(cookieName);
        if (cid && /^\d+\.\d+$/.test(cid)) return cid;

        // Reuse the ephemeral id within this page load (a real cookie id above still wins).
        if (ephemeralClientId) return ephemeralClientId;

        // 3. Generate new GA4-compatible id: <random int32>.<unix timestamp>
        var newId = Math.floor(Math.random() * 0x7FFFFFFF) + '.' + Math.floor(Date.now() / 1000);
        // Never persist a client id without statistics consent (ePrivacy) —
        // fall back to a per-pageload ephemeral id.
        if (config.fpCookieEnabled !== false && getConsentState().statistics) {
            setCookie(cookieName, newId, (parseInt(config.fpCookieMonths, 10) || 24) * 30);
        } else {
            ephemeralClientId = newId;
        }
        return newId;
    }

    // === SESSION ID ===

    function getSessionId() {
        var key = 'trackwp_sid';
        var sid = null;
        try {
            sid = sessionStorage.getItem(key);
        } catch(e) {}
        // Ignore legacy "ses_..." ids — GA4 requires a numeric session_id.
        if (sid && !/^\d+$/.test(sid)) sid = null;
        if (!sid) {
            sid = String(Math.floor(Date.now() / 1000));
            try {
                sessionStorage.setItem(key, sid);
            } catch(e) {}
        }
        return sid;
    }

    // === ID GENERATORS ===

    function generateUUID() {
        try {
            return crypto.randomUUID().replace(/-/g, '').substring(0, 32);
        } catch(e) {
            // Fallback for older browsers
            var s = '';
            for (var i = 0; i < 32; i++) {
                s += Math.floor(Math.random() * 16).toString(16);
            }
            return s;
        }
    }

    function generateEventId() {
        return 'evt_' + generateUUID();
    }

    // === SHA-256 (for Enhanced Conversions) ===

    function hashValue(value) {
        if (!value) return Promise.resolve(null);

        if (window.crypto && window.crypto.subtle) {
            var encoder = new TextEncoder();
            var data = encoder.encode(value);
            return crypto.subtle.digest('SHA-256', data).then(function(buffer) {
                var arr = new Uint8Array(buffer);
                var hex = '';
                for (var i = 0; i < arr.length; i++) {
                    hex += ('0' + arr[i].toString(16)).slice(-2);
                }
                return hex;
            });
        }

        // No crypto.subtle — return null (caller falls back to raw value for server-side rehash)
        return Promise.resolve(null);
    }

    function hashUserData(data) {
        if (!data) return Promise.resolve({});

        var hasSubtle = !!(window.crypto && window.crypto.subtle);
        var promises = [];
        var keys = [];
        var rawFallback = {};

        if (data.email) {
            var normalizedEmail = normalizeEmail(data.email);
            if (normalizedEmail) {
                if (hasSubtle) {
                    // Google-normalized (gmail dot/plus strip) for Google Ads EC
                    keys.push('email_sha256');
                    promises.push(hashValue(normalizedEmail));
                    // Meta-normalized (trim+lowercase only) for Meta CAPI
                    keys.push('email_meta_sha256');
                    promises.push(hashValue(normalizeEmailMeta(data.email)));
                } else {
                    // Server rehashes via TrackWP_Hash::normalize_enhanced()
                    rawFallback.email = normalizedEmail;
                }
            }
        }
        if (data.phone) {
            var ccDigits = phoneCcDigits(data.phone);
            if (ccDigits) {
                if (hasSubtle) {
                    keys.push('phone_sha256');
                    promises.push(hashValue(ccDigits));
                    // E.164 variant ('+' prefixed) for platforms that hash with '+'
                    keys.push('phone_e164_sha256');
                    promises.push(hashValue('+' + ccDigits));
                } else {
                    // Server rehashes via TrackWP_Hash::normalize_enhanced()
                    rawFallback.phone = normalizePhone(data.phone);
                }
            }
        }

        return Promise.all(promises).then(function(results) {
            var hashed = {};
            for (var i = 0; i < keys.length; i++) {
                if (results[i]) {
                    hashed[keys[i]] = results[i];
                }
            }
            if (rawFallback.email) hashed.email = rawFallback.email;
            if (rawFallback.phone) hashed.phone = rawFallback.phone;
            return hashed;
        });
    }

    // === CONSENT STATE ===

    function getConsentState() {
        if (window.trackwpConsent && typeof window.trackwpConsent.getState === 'function') {
            return window.trackwpConsent.getState();
        }
        // consent.js may not have executed yet (both scripts load async) —
        // fall back to the JSON consent cookie it writes.
        var raw = getCookie('trackwp_consent');
        if (raw) {
            try {
                var data = JSON.parse(raw);
                if (data && typeof data === 'object') {
                    // Enforce consent version: a policy change invalidates old consent.
                    // consentVersion 0/undefined means "skip the version check".
                    var requiredVersion = parseInt(config.consentVersion, 10) || 0;
                    if (requiredVersion > 0 && data.v !== requiredVersion) {
                        return { necessary: true, statistics: false, marketing: false, personalisation: false };
                    }
                    return {
                        necessary: true,
                        statistics: !!data.statistics,
                        marketing: !!data.marketing,
                        personalisation: !!data.personalisation
                    };
                }
            } catch (e) {}
        }
        return { necessary: true, statistics: false, marketing: false, personalisation: false };
    }

    // === FIND EVENT CONFIG ===

    function findEventConfig(eventName) {
        for (var i = 0; i < events.length; i++) {
            if (events[i].name === eventName) {
                return events[i];
            }
        }
        return null;
    }

    // === GA4 SESSION COOKIE SCAN ===

    function collectGaSessionCookies() {
        var cookies = [];
        var raw = document.cookie || '';
        var re = /(?:^|;\s*)_ga_([A-Z0-9]+)=([^;]+)/g;
        var m;
        while ((m = re.exec(raw)) !== null) {
            try {
                cookies.push({ id: m[1], value: decodeURIComponent(m[2]) });
            } catch (e) {
                cookies.push({ id: m[1], value: m[2] });
            }
        }
        return cookies;
    }

    // === DISPATCH ===

    // Firefox <= 132 silently ignores fetch keepalive — detect support so
    // nav-sends can fall back to sendBeacon (survives page unload).
    var supportsKeepalive = false;
    try {
        supportsKeepalive = 'keepalive' in Request.prototype;
    } catch (e) {}

    function dispatchPayload(payload, isNav) {
        if (dedupMode === 'client_only') return;
        var url = config.restUrl + 'trackwp/v1/' + endpointSlug;
        if (isNav && !supportsKeepalive && navigator.sendBeacon) {
            try {
                // Blob with explicit type — server reads a JSON body either way.
                if (navigator.sendBeacon(url, new Blob([JSON.stringify(payload)], { type: 'application/json' }))) {
                    return;
                }
            } catch (e) {}
        }
        try {
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload),
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {}); // Silent fail — never block UX
        } catch (e) {}
    }

    function fireKeepalive() {
        // No consent cookie — nothing to renew.
        if (!getCookie('trackwp_consent')) return;
        // Throttle: at most one keepalive per hour per browser.
        try {
            var last = parseInt(localStorage.getItem('trackwp_ka_ts'), 10);
            if (last && (Date.now() - last) < 3600000) return;
            localStorage.setItem('trackwp_ka_ts', String(Date.now()));
        } catch (e) {} // private mode etc. — fall through and send
        try {
            fetch(config.restUrl + 'trackwp/v1/keepalive', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true
            }).catch(function() {});
        } catch (e) {}
    }

    function fireGoogleAdsConversion(eventConfig, payload, eventId, consent) {
        if (dedupMode === 'server_only') return;
        if (!consent.marketing) return;
        if (!googleAds.conversionId) return;
        if (!eventConfig || !eventConfig.ads_label) return;
        if (typeof window.gtag !== 'function') return;
        window.gtag('event', 'conversion', {
            'send_to': googleAds.conversionId + '/' + eventConfig.ads_label,
            'value': payload.value,
            'currency': payload.currency,
            'transaction_id': eventId
        });
    }

    var META_STANDARD_EVENTS = [
        'AddPaymentInfo', 'AddToCart', 'AddToWishlist', 'CompleteRegistration',
        'Contact', 'CustomizeProduct', 'Donate', 'FindLocation', 'InitiateCheckout',
        'Lead', 'Purchase', 'Schedule', 'Search', 'StartTrial', 'SubmitApplication',
        'Subscribe', 'ViewContent'
    ];

    function fireMetaPixel(eventConfig, payload, eventId, consent) {
        if (dedupMode === 'server_only') return;
        if (!consent.marketing) return;
        if (typeof window.fbq !== 'function') return;
        if (!eventConfig || !eventConfig.meta_event) return;
        var params = {};
        if (payload.value) {
            params.value = payload.value;
            params.currency = payload.currency;
        }
        // Same eventID as the server-side CAPI event — Meta dedups the pair.
        if (META_STANDARD_EVENTS.indexOf(eventConfig.meta_event) !== -1) {
            window.fbq('track', eventConfig.meta_event, params, { eventID: eventId });
        } else {
            // 'CustomEvent' is the admin's "custom" choice — the server-side CAPI
            // sends the internal event name, so use the same name here or Meta
            // can't dedup the pair (double counting).
            var customName = (eventConfig.meta_event === 'CustomEvent') ? payload.event : eventConfig.meta_event;
            window.fbq('trackCustom', customName, params, { eventID: eventId });
        }
    }

    // client_only mode: REST dispatch is skipped, so GA4 events must go via gtag.
    // In client_and_server/server_only modes GA4 custom events are server-side only.
    function fireGa4ClientEvent(eventName, payload, consent) {
        if (dedupMode !== 'client_only') return;
        if (!consent.statistics) return;
        if (!config.measurementId) return;
        if (typeof window.gtag !== 'function') return;
        var params = { send_to: config.measurementId };
        if (payload.value) { params.value = payload.value; params.currency = payload.currency; }
        window.gtag('event', eventName, params);
    }

    // === CORE: SEND EVENT ===

    function sendEvent(eventName, params, options) {
        // Require an active consent choice before sending ANYTHING: until the
        // trackwp_consent cookie exists the user hasn't made a choice yet. After
        // a choice — including rejection — the flow continues as normal: the
        // server uses rejected events for cookie cleanup and forwards nothing.
        if (config.requireActiveConsent && !getCookie('trackwp_consent')) {
            if (debug) console.log('[TrackWP] skipped (no active consent choice yet):', eventName);
            return;
        }

        params = params || {};
        options = options || {};

        var consent = getConsentState();
        var eventConfig = findEventConfig(eventName);

        // Use config values as defaults, params override
        var value = params.value !== undefined ? params.value : (eventConfig ? eventConfig.value : 0);
        var currency = params.currency || (eventConfig ? eventConfig.currency : 'DKK');

        var eventId = generateEventId();

        var payload = {
            event: eventName,
            value: parseFloat(value) || 0,
            currency: currency,
            page_url: window.location.href,
            page_title: document.title,
            client_id: getClientId(),
            event_id: eventId,
            session_id: getSessionId(),
            consent: {
                analytics: consent.statistics || false,
                marketing: consent.marketing || false
            },
            user_agent: navigator.userAgent,
            fbc: getCookie('_fbc') || '',
            fbp: getCookie('_fbp') || '',
            gclid: trackwpGetUrlParam('gclid') || (function() {
                var c = trackwpGetCookie('_gcl_aw');
                var p = c.split('.');
                return p.length >= 3 ? p[2] : '';
            })(),
            _ga: trackwpGetCookie('_ga'),
            ga_session_cookies: collectGaSessionCookies()
        };

        // Form data
        if (params.form_id) payload.form_id = params.form_id;
        if (params.form_name) payload.form_name = params.form_name;

        var hasEnhanced = !!(params.enhanced && (params.enhanced.email || params.enhanced.phone));
        var isNavLike = options.nav === true;

        // For nav-like clicks/submits (mailto/tel/outbound/downloads/HTML form
        // navigation) we MUST dispatch synchronously in the current task — the
        // browser may begin unload before microtasks/promises resolve. Skip
        // async hashing and send raw normalized values instead; the server
        // rehashes via TrackWP_Hash::normalize_enhanced().
        if (isNavLike) {
            if (hasEnhanced) {
                var rawEnhanced = {};
                // Send trim+lowercase raw email — the server derives BOTH the
                // Google hash (gmail rules) and the Meta hash from it;
                // pre-applying gmail munging here would corrupt the Meta hash.
                var navEmail = normalizeEmailMeta(params.enhanced.email);
                var navPhone = normalizePhone(params.enhanced.phone);
                if (navEmail) rawEnhanced.email = navEmail;
                if (navPhone) rawEnhanced.phone = navPhone;
                if (navEmail || navPhone) payload.enhanced = rawEnhanced;
            }
            dispatchPayload(payload, true);
            fireGa4ClientEvent(eventName, payload, consent);
            fireGoogleAdsConversion(eventConfig, payload, eventId, consent);
            fireMetaPixel(eventConfig, payload, eventId, consent);
            if (debug) {
                console.log('[TrackWP]', eventName, payload);
            }
            return;
        }

        if (!hasEnhanced) {
            // Synchronous dispatch — no async work needed.
            dispatchPayload(payload);
            fireGa4ClientEvent(eventName, payload, consent);
            fireGoogleAdsConversion(eventConfig, payload, eventId, consent);
            fireMetaPixel(eventConfig, payload, eventId, consent);
            if (debug) {
                console.log('[TrackWP]', eventName, payload);
            }
            return;
        }

        // Enhanced data — hash then send
        hashUserData(params.enhanced).then(function(hashed) {
            if (Object.keys(hashed).length > 0) {
                payload.enhanced = hashed;
            }
            dispatchPayload(payload);
            fireGa4ClientEvent(eventName, payload, consent);
            fireGoogleAdsConversion(eventConfig, payload, eventId, consent);
            fireMetaPixel(eventConfig, payload, eventId, consent);
            if (debug) {
                console.log('[TrackWP]', eventName, payload);
            }
        });
    }

    // === AUTO-DETECTION ===

    // Selectors that indicate the click will trigger navigation/unload.
    // These require synchronous dispatch (no async hashing before fetch).
    var NAV_LIKE_SELECTORS = [
        'a[href^="mailto:"]',
        'a[href^="tel:"]',
        'a[href^="sms:"]',
        'a[download]'
    ];
    var FILE_EXT_SELECTORS = [
        'a[href$=".pdf"]', 'a[href$=".doc"]', 'a[href$=".docx"]',
        'a[href$=".xls"]', 'a[href$=".xlsx"]', 'a[href$=".zip"]',
        'a[href$=".rar"]', 'a[href$=".csv"]', 'a[href$=".ppt"]',
        'a[href$=".pptx"]'
    ];

    function isNavLikeSelector(selector) {
        if (!selector) return false;
        for (var i = 0; i < NAV_LIKE_SELECTORS.length; i++) {
            if (selector.indexOf(NAV_LIKE_SELECTORS[i]) !== -1) return true;
        }
        return false;
    }

    function isOutboundLink(anchor) {
        if (!anchor || !anchor.href) return false;
        try {
            var url = new URL(anchor.href, window.location.href);
            if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
            return url.hostname && url.hostname !== window.location.hostname;
        } catch (e) {
            return false;
        }
    }

    function initAutoDetect() {
        var clickEvents = [];
        var downloadEvents = [];
        var customFormEvents = [];

        for (var i = 0; i < events.length; i++) {
            var evt = events[i];

            switch (evt.trigger_type) {
                case 'css_click':
                    if (evt.css_selector) clickEvents.push(evt);
                    break;
                case 'scroll_depth':
                    bindScrollEvent(evt);
                    break;
                case 'time_on_page':
                    bindTimeEvent(evt);
                    break;
                case 'url_match':
                    checkUrlMatch(evt);
                    break;
                case 'file_download':
                    downloadEvents.push(evt);
                    break;
                case 'form_submit':
                    // Generic 'form_submit' is handled by class-trackwp-forms.php.
                    // Custom-named form events with their own selector are bound here.
                    if (evt.name !== 'form_submit' && evt.css_selector) {
                        customFormEvents.push(evt);
                    }
                    break;
                case 'js_event':
                    if (evt.js_event) bindJsEvent(evt);
                    break;
            }
        }

        bindClickEvents(clickEvents);
        bindFileDownloads(downloadEvents);
        bindCustomFormEvents(customFormEvents);
    }

    // Custom JavaScript event trigger — listens for the configured event name
    // dispatched on document (e.g. document.dispatchEvent(new CustomEvent('my_event'))).
    function bindJsEvent(evt) {
        document.addEventListener(evt.js_event, function () {
            sendEvent(evt.name);
        });
    }

    // Custom-named form events (trigger form_submit + own selector). The generic
    // 'form_submit' event is handled by class-trackwp-forms.php.
    function bindCustomFormEvents(formEvents) {
        if (!formEvents.length) return;
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || typeof form.matches !== 'function') return;
            for (var i = 0; i < formEvents.length; i++) {
                var evt = formEvents[i];
                try {
                    if (form.matches(evt.css_selector)) {
                        sendEvent(evt.name, {}, { nav: true });
                    }
                } catch (err) { /* invalid selector — skip */ }
            }
        }, true);
    }

    function bindClickEvents(clickEvents) {
        if (!clickEvents.length) return;
        // Delegation on document — works for dynamically-injected links (AJAX/SPA).
        // Capture phase so we dispatch before any inline onclick handlers.
        // We do NOT preventDefault — mailto/tel/outbound must navigate as normal.
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            for (var i = 0; i < clickEvents.length; i++) {
                var ev = clickEvents[i];
                var match;
                try {
                    match = e.target.closest(ev.css_selector);
                } catch (err) {
                    continue;
                }
                if (!match) continue;
                var nav = isNavLikeSelector(ev.css_selector) || isOutboundLink(match);
                sendEvent(ev.name, {}, { nav: nav });
                // continue loop — one element may match multiple events
            }
        }, true);
    }

    function bindFileDownloads(downloadEvents) {
        if (!downloadEvents.length) return;
        var defaultSelector = FILE_EXT_SELECTORS.join(',');
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            for (var i = 0; i < downloadEvents.length; i++) {
                var evt = downloadEvents[i];
                // Events with their own selector only fire when it matches the
                // clicked element; others use the default file-extension list.
                var selector = evt.css_selector ? evt.css_selector : defaultSelector;
                var match;
                try {
                    match = e.target.closest(selector);
                } catch (err) {
                    continue; // invalid selector — skip this event
                }
                if (!match) continue;
                // File downloads always trigger navigation/unload — send synchronously.
                sendEvent(evt.name, {}, { nav: true });
            }
        }, true);
    }

    function bindScrollEvent(evt) {
        var depth = parseInt(evt.scroll_depth, 10) || 50;
        var fired = false;

        function checkScroll() {
            if (fired) return;
            var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            var docHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            if (docHeight <= 0) return;
            var percent = (scrollTop / docHeight) * 100;
            if (percent >= depth) {
                fired = true;
                sendEvent(evt.name);
                window.removeEventListener('scroll', checkScroll);
            }
        }

        window.addEventListener('scroll', checkScroll, { passive: true });
    }

    function bindTimeEvent(evt) {
        var seconds = parseInt(evt.time_seconds, 10) || 30;
        setTimeout(function() {
            sendEvent(evt.name);
        }, seconds * 1000);
    }

    function checkUrlMatch(evt) {
        if (!evt.url_match) return;
        if (window.location.href.indexOf(evt.url_match) !== -1) {
            sendEvent(evt.name);
        }
    }

    // === EXPOSE API ===

    window.trackwp = {
        sendEvent: sendEvent
    };

    // Signal readiness — this script loads async, so inline integrations
    // (class-trackwp-forms.php) may parse before window.trackwp exists.
    (function() {
        var readyEvent;
        try {
            readyEvent = new CustomEvent('trackwp:ready');
        } catch (e) {
            readyEvent = document.createEvent('CustomEvent');
            readyEvent.initCustomEvent('trackwp:ready', true, true, null);
        }
        document.dispatchEvent(readyEvent);
    })();

    // === INIT ===

    function trackwpInit() {
        initAutoDetect();
        if (getConsentState().statistics) {
            fireKeepalive();
        }
    }

    // Also renew cookies right after the user grants statistics consent in-page.
    document.addEventListener('trackwp:consent_updated', function(e) {
        if (e && e.detail && e.detail.statistics) {
            fireKeepalive();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackwpInit);
    } else {
        trackwpInit();
    }

})(window, document);
