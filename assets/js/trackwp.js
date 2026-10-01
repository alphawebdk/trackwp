(function(window, document) {
    'use strict';

    // Config from wp_localize_script (K8).
    var config = window.trackwpConfig || {};
    if (!config.restUrl) return;

    var events = config.events || [];
    var googleAds = config.googleAds || {};
    var cookieName = config.cookieName || '_twp_cid';
    var endpointSlug = config.endpointSlug || 'event';
    var dedupMode = config.dedupMode || 'client_and_server';
    // Flags are strict (review class A): only a real boolean true enables,
    // anything else (missing, "", "1", 1) is off.
    var customerDataSharing = config.customerDataSharing === true;
    var fpCookieEnabled = config.fpCookieEnabled === true;
    var requireActiveConsent = config.requireActiveConsent === true;
    var defaultPhoneCountry = String(config.defaultPhoneCountry || 'DK').toUpperCase();
    var metaEventMap = config.metaEventMap || {};

    var CLICK_ID_RE = /^[A-Za-z0-9_\-]{1,200}$/;
    var EVENT_ID_RE = /^evt_[a-f0-9]{16,64}$/;
    var CLICK_TTL_MS = 90 * 86400000;

    // === HELPERS ===

    function safeDecode(value) {
        if (value === null || value === undefined) return '';
        var str = String(value);
        try {
            return decodeURIComponent(str.replace(/\+/g, ' '));
        } catch (e) {
            return str;
        }
    }

    function getCookie(name) {
        var parts = (document.cookie || '').split(';');
        for (var i = 0; i < parts.length; i++) {
            var p = parts[i].replace(/^\s+/, '');
            if (p.indexOf(name + '=') === 0) {
                return safeDecode(p.substring(name.length + 1));
            }
        }
        return '';
    }

    function getUrlParam(name) {
        try {
            return new URLSearchParams(window.location.search).get(name) || '';
        } catch (e) {
            return '';
        }
    }

    // Debug only when the site allows it AND the visitor asks for it.
    var debug = config.debugAllowed === true && getUrlParam('trackwp_debug') === '1';

    function log() {
        if (!debug || !window.console) return;
        try {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[TrackWP]');
            window.console.log.apply(window.console, args);
        } catch (e) {}
    }

    function fireEvent(name, detail) {
        var evt;
        try {
            evt = new window.CustomEvent(name, { detail: detail });
        } catch (e) {
            try {
                evt = document.createEvent('CustomEvent');
                evt.initCustomEvent(name, true, true, detail);
            } catch (e2) {
                evt = { type: name, detail: detail };
            }
        }
        document.dispatchEvent(evt);
    }

    // Host-only unless a domain is given (K7/R17: never Domain=<host>).
    function setCookie(name, value, days, domain) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() + ';path=/' +
            (domain ? ';domain=' + domain : '') + ';SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');
    }

    function expireCookie(name, domain) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/' +
            (domain ? ';domain=' + domain : '') + ';SameSite=Lax';
    }

    // === CONSENT (K3: the reader is the ONLY source) ===

    function readConsent() {
        var reader = window.trackwpConsentReader;
        var c = null;
        if (reader && typeof reader.read === 'function') {
            try { c = reader.read(); } catch (e) { c = null; }
        }
        if (!c || typeof c !== 'object') {
            return { has: false, statistics: false, marketing: false, personalisation: false, v: 0, id: '' };
        }
        return {
            has: true,
            statistics: c.statistics === true,
            marketing: c.marketing === true,
            personalisation: c.personalisation === true,
            v: parseInt(c.v, 10) || 0,
            id: typeof c.id === 'string' ? c.id.substring(0, 36) : ''
        };
    }

    // Bumped on every revocation; async work started under an older
    // generation (hashing) is dropped when it resolves.
    var consentGeneration = 0;

    // === URL / TITLE CLEANING (K6) ===
    // The ONE JS cleaner is window.trackwpPrivacy (TrackWP_Privacy::cleaner_js(),
    // printed inline on wp_head priority 1). No local copy. Without it,
    // page URLs are reduced to origin + path and titles are not sent.

    function privacy() {
        var p = window.trackwpPrivacy;
        return (p && typeof p.cleanUrl === 'function' && typeof p.cleanTitle === 'function') ? p : null;
    }

    function originAndPath(url) {
        var str = String(url || '');
        var cut = str.search(/[?#]/);
        return cut === -1 ? str : str.substring(0, cut);
    }

    /**
     * @param {string}  url
     * @param {boolean} keepClickIds  true only with marketing consent
     * @param {boolean} onlyPath      true without analytics and marketing
     */
    function cleanUrl(url, keepClickIds, onlyPath) {
        if (!url) return '';
        var p = privacy();
        if (!p) return originAndPath(url);
        var cleaned = '';
        try { cleaned = String(p.cleanUrl(String(url), !keepClickIds) || ''); } catch (e) { return originAndPath(url); }
        return onlyPath ? originAndPath(cleaned) : cleaned;
    }

    // Returns null when the text cannot be cleaned (no cleaner available).
    function cleanTitle(title) {
        var p = privacy();
        if (!p) return null;
        try { return String(p.cleanTitle(String(title || '')) || ''); } catch (e) { return null; }
    }

    // === ENGAGEMENT TIME (K8) ===

    function nowMs() {
        try {
            if (window.performance && typeof window.performance.now === 'function') return window.performance.now();
        } catch (e) {}
        return Date.now();
    }

    var engagedAccum = 0;
    var visibleSince = (document.visibilityState === 'hidden') ? null : nowMs();

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            if (visibleSince === null) visibleSince = nowMs();
        } else if (visibleSince !== null) {
            engagedAccum += nowMs() - visibleSince;
            visibleSince = null;
        }
    });

    function takeEngagedMs() {
        var total = engagedAccum;
        if (visibleSince !== null) {
            var n = nowMs();
            total += n - visibleSince;
            visibleSince = n;
        }
        engagedAccum = 0;
        total = Math.round(total);
        if (total < 0) total = 0;
        if (total > 3600000) total = 3600000;
        return total;
    }

    // === CLICK IDS (RAM until marketing, K7) ===

    var clickIds = {};
    var clickIdsPersisted = false;

    function captureClickIds() {
        var names = ['gclid', 'gbraid', 'wbraid', 'fbclid'];
        for (var i = 0; i < names.length; i++) {
            var v = getUrlParam(names[i]);
            if (v && CLICK_ID_RE.test(v)) clickIds[names[i]] = v;
        }
    }

    function readClickCookie() {
        var raw = getCookie('_twp_click');
        if (!raw) return {};
        try {
            var data = JSON.parse(raw);
            return (data && typeof data === 'object') ? data : {};
        } catch (e) {
            return {};
        }
    }

    function fbcFrom(fbclid, ms) {
        return 'fb.1.' + ms + '.' + fbclid;
    }

    function persistClickIds() {
        if (clickIdsPersisted) return;
        var c = readConsent();
        if (!c.marketing) return;
        var now = Date.now();
        var stored = readClickCookie();
        var changed = false;
        var keys = ['gclid', 'gbraid', 'wbraid'];
        for (var i = 0; i < keys.length; i++) {
            if (clickIds[keys[i]]) {
                stored[keys[i]] = { v: clickIds[keys[i]], ts: now };
                changed = true;
            }
        }
        if (changed) setCookie('_twp_click', JSON.stringify(stored), 90);
        if (clickIds.fbclid) {
            clickIds.fbc = fbcFrom(clickIds.fbclid, now);
            setCookie('_fbc', clickIds.fbc, 90, config.cookieDomain || '');
        }
        clickIdsPersisted = true;
    }

    function clickIdFor(name) {
        if (clickIds[name]) return clickIds[name];
        var stored = readClickCookie()[name];
        if (stored && stored.v && CLICK_ID_RE.test(stored.v) && (Date.now() - (parseInt(stored.ts, 10) || 0)) < CLICK_TTL_MS) {
            return String(stored.v);
        }
        if (name === 'gclid') {
            var p = getCookie('_gcl_aw').split('.');
            if (p.length >= 3 && CLICK_ID_RE.test(p[2])) return p[2];
        }
        return '';
    }

    // === CLIENT / SESSION ID (analytics only) ===

    var ephemeralClientId = null;

    function fpCookieDays() {
        var days = parseInt(config.fpCookieDays, 10);
        if (!(days > 0)) days = (parseInt(config.fpCookieMonths, 10) || 24) * 30;
        return Math.min(days, 400);
    }

    function getClientId() {
        var ga = getCookie('_ga');
        if (ga) {
            var parts = ga.split('.');
            if (parts.length >= 3 && /^\d+\.\d+$/.test(parts.slice(2).join('.'))) {
                return parts.slice(2).join('.');
            }
        }
        var cid = getCookie(cookieName);
        if (cid && /^\d+\.\d+$/.test(cid)) return cid;
        if (ephemeralClientId) return ephemeralClientId;
        var newId = Math.floor(Math.random() * 0x7FFFFFFF) + '.' + Math.floor(Date.now() / 1000);
        if (fpCookieEnabled) {
            setCookie(cookieName, newId, fpCookieDays());
        } else {
            ephemeralClientId = newId;
        }
        return newId;
    }

    function getSessionId() {
        var key = 'trackwp_sid';
        var sid = null;
        try { sid = window.sessionStorage.getItem(key); } catch (e) {}
        if (sid && !/^\d{1,12}$/.test(sid)) sid = null;
        if (!sid) {
            sid = String(Math.floor(Date.now() / 1000));
            try { window.sessionStorage.setItem(key, sid); } catch (e) {}
        }
        return sid;
    }

    function collectGaSessionCookies() {
        var cookies = [];
        var raw = document.cookie || '';
        var re = /(?:^|;\s*)_ga_([A-Z0-9]+)=([^;]+)/g;
        var m;
        while ((m = re.exec(raw)) !== null) {
            cookies.push({ id: m[1], value: safeDecode(m[2]) });
        }
        return cookies;
    }

    // === ID GENERATORS ===

    function generateEventId() {
        var s = '';
        try {
            s = window.crypto.randomUUID().replace(/-/g, '');
        } catch (e) {
            for (var i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
        }
        return 'evt_' + s.substring(0, 32);
    }

    // === NORMALISATION (K8, mirrors TrackWP_Hash; shared vectors R19) ===

    var PHONE_COUNTRIES = {
        DK: ['45', null], NO: ['47', null], SE: ['46', '0'], FI: ['358', '0'],
        DE: ['49', '0'], GB: ['44', '0'], NL: ['31', '0'], FR: ['33', '0'], IS: ['354', null]
    };

    function normGoogleEmail(value) {
        if (!value) return '';
        var email = String(value).replace(/^\s+|\s+$/g, '').toLowerCase();
        var at = email.lastIndexOf('@');
        if (at <= 0) return email;
        var local = email.substring(0, at);
        var domain = email.substring(at + 1);
        if (domain === 'gmail.com' || domain === 'googlemail.com') {
            local = local.split('+')[0].split('.').join('');
        }
        return local + '@' + domain;
    }

    function normMetaEmail(value) {
        if (!value) return '';
        return String(value).replace(/^\s+|\s+$/g, '').toLowerCase();
    }

    // E.164 with '+', or '' when the number cannot be qualified.
    function normPhoneE164(value, country) {
        if (!value) return '';
        var raw = String(value).replace(/^\s+|\s+$/g, '');
        var digits = raw.replace(/\D/g, '');
        if (!digits) return '';
        if (raw.charAt(0) === '+') return '+' + digits;
        if (digits.indexOf('00') === 0) {
            digits = digits.substring(2);
            return digits ? '+' + digits : '';
        }
        var entry = PHONE_COUNTRIES[String(country || defaultPhoneCountry).toUpperCase()];
        if (!entry) return '';
        if (entry[1] && digits.indexOf(entry[1]) === 0) digits = digits.substring(entry[1].length);
        return digits ? '+' + entry[0] + digits : '';
    }

    // Meta ph: the E.164 number without '+'.
    function normMetaPhone(value, country) {
        var e164 = normPhoneE164(value, country);
        return e164 ? e164.substring(1) : '';
    }

    // === SHA-256 ===

    function utf8Bytes(str) {
        if (typeof window.TextEncoder === 'function') return new window.TextEncoder().encode(str);
        var bin = unescape(encodeURIComponent(str));
        var out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    }

    function hashValue(value) {
        if (!value) return Promise.resolve(null);
        var subtle = window.crypto && window.crypto.subtle;
        if (!subtle) return Promise.resolve(null);
        return subtle.digest('SHA-256', utf8Bytes(value)).then(function(buffer) {
            var arr = new Uint8Array(buffer);
            var hex = '';
            for (var i = 0; i < arr.length; i++) hex += ('0' + arr[i].toString(16)).slice(-2);
            return hex;
        }, function() { return null; });
    }

    // K2a keys: email_sha256 (Google), email_meta_sha256 (Meta),
    // phone_e164_sha256 (Google, '+'), phone_sha256 (Meta, digits).
    function hashUserData(data) {
        var hasSubtle = !!(window.crypto && window.crypto.subtle);
        var keys = [];
        var promises = [];
        var out = {};
        var gEmail = normGoogleEmail(data.email);
        var e164 = normPhoneE164(data.phone);
        if (!hasSubtle) {
            // Server rehashes via TrackWP_Hash::normalize_enhanced().
            if (data.email) out.email = normMetaEmail(data.email);
            if (e164) out.phone = e164;
            return Promise.resolve(out);
        }
        if (gEmail) {
            keys.push('email_sha256'); promises.push(hashValue(gEmail));
            keys.push('email_meta_sha256'); promises.push(hashValue(normMetaEmail(data.email)));
        }
        if (e164) {
            keys.push('phone_e164_sha256'); promises.push(hashValue(e164));
            keys.push('phone_sha256'); promises.push(hashValue(e164.substring(1)));
        }
        return Promise.all(promises).then(function(results) {
            for (var i = 0; i < keys.length; i++) {
                if (results[i]) out[keys[i]] = results[i];
            }
            return out;
        });
    }

    // Enhanced input is read synchronously from the form at dispatch time,
    // and only with marketing consent and customer data sharing enabled.
    function readFormEnhanced(form) {
        if (!form || typeof form.querySelector !== 'function') return null;
        var emailEl = form.querySelector('[type="email"]');
        var phoneEl = form.querySelector('[type="tel"]');
        var email = emailEl && emailEl.value ? String(emailEl.value) : '';
        var phone = phoneEl && phoneEl.value ? String(phoneEl.value) : '';
        return (email || phone) ? { email: email, phone: phone } : null;
    }

    function enhancedAllowed(c) {
        return !!(c.marketing && customerDataSharing);
    }

    // === EVENT CONFIG ===

    function findEventConfig(eventName) {
        for (var i = 0; i < events.length; i++) {
            if (events[i].name === eventName) return events[i];
        }
        return null;
    }

    function sendsTo(eventConfig, platform) {
        if (!eventConfig) return false;
        var routing = eventConfig.send_to;
        if (!routing || typeof routing !== 'object') return true;
        return !!routing[platform];
    }

    // === DUPLICATE-DISPATCH GUARD ===

    var DEDUP_WINDOW_MS = 500;
    var recentDispatch = {};

    function isDuplicateDispatch(eventName, el) {
        var now = Date.now();
        var id = '';
        if (el && typeof el.getAttribute === 'function') id = el.getAttribute('href') || el.getAttribute('id') || '';
        if (!id && el && el.tagName) id = el.tagName + ':' + (el.className || '');
        var key = eventName + '|' + id;
        for (var k in recentDispatch) {
            if (Object.prototype.hasOwnProperty.call(recentDispatch, k) && (now - recentDispatch[k]) > DEDUP_WINDOW_MS) {
                delete recentDispatch[k];
            }
        }
        if (recentDispatch[key] !== undefined && (now - recentDispatch[key]) < DEDUP_WINDOW_MS) {
            log('duplicate suppressed:', eventName);
            return true;
        }
        recentDispatch[key] = now;
        return false;
    }

    // === PAYLOAD (K2 categories, filtered by a FRESH read at dispatch) ===

    function applyCategoryFilter(payload, c) {
        var analytics = c.statistics;
        var marketing = c.marketing;
        payload.consent = { analytics: analytics, marketing: marketing, v: c.has ? c.v : (parseInt(config.consentVersion, 10) || 0) };
        if (c.id) payload.consent.id = c.id;
        payload.page_url = cleanUrl(window.location.href, marketing, !analytics && !marketing);
        var title = cleanTitle(document.title);
        if (title !== null) payload.page_title = title; else delete payload.page_title;

        if (analytics || marketing) {
            payload.user_agent = window.navigator ? String(window.navigator.userAgent || '').substring(0, 500) : '';
        } else {
            delete payload.user_agent;
        }

        if (analytics) {
            payload.client_id = getClientId();
            payload.session_id = getSessionId();
            payload._ga = getCookie('_ga');
            payload.ga_session_cookies = collectGaSessionCookies();
            if (document.referrer) payload.page_referrer = cleanUrl(document.referrer, marketing, false);
        } else {
            delete payload.client_id;
            delete payload.session_id;
            delete payload._ga;
            delete payload.ga_session_cookies;
            delete payload.page_referrer;
            delete payload.engaged_ms;
        }

        if (marketing) {
            payload.fbp = getCookie('_fbp');
            payload.fbc = getCookie('_fbc') || clickIds.fbc || (clickIds.fbclid ? fbcFrom(clickIds.fbclid, Date.now()) : '');
            var gclid = clickIdFor('gclid');
            var gbraid = clickIdFor('gbraid');
            var wbraid = clickIdFor('wbraid');
            if (gclid) payload.gclid = gclid; else delete payload.gclid;
            if (gbraid) payload.gbraid = gbraid; else delete payload.gbraid;
            if (wbraid) payload.wbraid = wbraid; else delete payload.wbraid;
        } else {
            delete payload.fbp;
            delete payload.fbc;
            delete payload.gclid;
            delete payload.gbraid;
            delete payload.wbraid;
        }
        if (!enhancedAllowed(c)) delete payload.enhanced;
        return payload;
    }

    var supportsKeepalive = false;
    try { supportsKeepalive = 'keepalive' in window.Request.prototype; } catch (e) {}

    function dispatchPayload(payload, isNav, requeue) {
        if (dedupMode === 'client_only') return;
        var c = readConsent();
        applyCategoryFilter(payload, c);
        var url = config.restUrl + 'trackwp/v1/' + endpointSlug;
        var body = JSON.stringify(payload);
        if (isNav && !supportsKeepalive && window.navigator && window.navigator.sendBeacon) {
            try {
                if (window.navigator.sendBeacon(url, new window.Blob([body], { type: 'application/json' }))) return;
            } catch (e) {}
        }
        try {
            window.fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                keepalive: true,
                credentials: 'same-origin'
            }).then(function(res) {
                if (!res || typeof res.json !== 'function') return null;
                return res.json().catch(function() { return null; });
            }).then(function(json) {
                if (json && json.consent === 'stale_version') {
                    staleSeen = true;
                    // consent.js revokes (and clears the queue) first; the
                    // event is re-queued afterwards so it waits for a new choice.
                    fireEvent('trackwp:consent_stale', { current_version: json.current_version });
                    if (requeue) queueEvent(requeue.name, requeue.params, requeue.options, true);
                }
            }, function() {});
        } catch (e) {}
    }

    function fireKeepalive() {
        var c = readConsent();
        if (!c.statistics && !c.marketing) return;
        try {
            var last = parseInt(window.localStorage.getItem('trackwp_ka_ts'), 10);
            if (last && (Date.now() - last) < 3600000) return;
            window.localStorage.setItem('trackwp_ka_ts', String(Date.now()));
        } catch (e) {}
        var p;
        try {
            p = window.fetch(config.restUrl + 'trackwp/v1/keepalive', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true
            }).then(afterKeepalive, afterKeepalive);
        } catch (e) {
            return;
        }
        // consent.js waits for this (max 3 s) before a withdraw/downgrade, so
        // the expiry headers of the withdraw answer always land last (class F).
        pendingKeepalive = p;
        if (window.trackwp) window.trackwp.pendingKeepalive = p;
    }

    var pendingKeepalive = null;

    // A keepalive answer may still land after a withdraw and renew cookies
    // for a category that is now refused: expire those again.
    function afterKeepalive() {
        pendingKeepalive = null;
        if (window.trackwp) window.trackwp.pendingKeepalive = null;
        var c = readConsent();
        var api = window.trackwpConsent;
        if (api && typeof api.expireTrackingCookies === 'function' && (!c.statistics || !c.marketing)) {
            api.expireTrackingCookies({ statistics: !c.statistics, marketing: !c.marketing });
        }
    }

    // === CLIENT-SIDE TAGS ===

    var TRANSACTION_EVENTS = ['purchase', 'refund'];

    function isTransactionEvent(eventName) {
        return TRANSACTION_EVENTS.indexOf(eventName) !== -1;
    }

    function transactionIdFor(payload, eventId) {
        if (payload.ecommerce && payload.ecommerce.transaction_id) return String(payload.ecommerce.transaction_id);
        return eventId;
    }

    function metaContentsFrom(items) {
        var contents = [];
        var numItems = 0;
        for (var i = 0; i < items.length; i++) {
            var quantity = parseInt(items[i].quantity, 10);
            if (!(quantity > 0)) quantity = 1;
            contents.push({
                id: String(items[i].item_id || items[i].item_name || ''),
                quantity: quantity,
                item_price: parseFloat(items[i].price) || 0
            });
            numItems += quantity;
        }
        return { contents: contents, numItems: numItems };
    }

    // gtag user_data from pre-hashed values (sha256_* keys).
    function gtagUserData(enhanced) {
        if (!enhanced) return null;
        var ud = {};
        if (enhanced.email_sha256) ud.sha256_email_address = enhanced.email_sha256;
        if (enhanced.phone_e164_sha256) ud.sha256_phone_number = enhanced.phone_e164_sha256;
        for (var k in ud) {
            if (Object.prototype.hasOwnProperty.call(ud, k)) return ud;
        }
        return null;
    }

    // Independent of dedupMode (the gtag conversion is the Ads half; the
    // server upload dedups on transaction_id/orderId).
    // KC9/D5: when the dataLayer layer is active, GTM's own Ads tag owns the
    // conversion via the dataLayer push (pushDataLayer()); TrackWP must not
    // also fire a competing gtag conversion into the same dataLayer.
    function fireGoogleAdsConversion(eventConfig, payload, eventId, c, ec) {
        if (dataLayerActive()) return;
        if (!c.marketing) return;
        if (!googleAds.conversionId) return;
        if (!eventConfig || !eventConfig.ads_label) return;
        if (!sendsTo(eventConfig, 'google_ads')) return;
        if (typeof window.gtag !== 'function') return;
        var ud = null;
        if (enhancedAllowed(c)) {
            ud = (ec && typeof ec === 'object') ? ec : gtagUserData(payload.enhanced);
        }
        if (ud) window.gtag('set', 'user_data', ud);
        window.gtag('event', 'conversion', {
            'send_to': googleAds.conversionId + '/' + eventConfig.ads_label,
            'value': payload.value,
            'currency': payload.currency,
            'transaction_id': transactionIdFor(payload, eventId)
        });
        if (ud) window.gtag('set', 'user_data', {});
    }

    var META_STANDARD_EVENTS = [
        'AddPaymentInfo', 'AddToCart', 'AddToWishlist', 'CompleteRegistration',
        'Contact', 'CustomizeProduct', 'Donate', 'FindLocation', 'InitiateCheckout',
        'Lead', 'Purchase', 'Schedule', 'Search', 'StartTrial', 'SubmitApplication',
        'Subscribe', 'ViewContent'
    ];

    // Same rule as TrackWP_Meta::resolve_event_name(); the server ships the
    // resolved name as meta_resolved.
    function metaEventName(eventConfig, eventName) {
        if (eventConfig.meta_resolved) return String(eventConfig.meta_resolved);
        var explicit = eventConfig.meta_event;
        if (explicit && explicit !== 'CustomEvent') return explicit;
        if (metaEventMap[eventName]) return String(metaEventMap[eventName]);
        return eventName;
    }

    function fireMetaPixel(eventConfig, payload, eventId, c) {
        if (dedupMode === 'server_only') return;
        if (!c.marketing) return;
        if (typeof window.fbq !== 'function') return;
        if (!eventConfig || !(eventConfig.meta_resolved || eventConfig.meta_event)) return;
        if (!sendsTo(eventConfig, 'meta')) return;
        var params = {};
        if (payload.value || isTransactionEvent(payload.event)) {
            params.value = payload.value || 0;
            params.currency = payload.currency;
        }
        var name = metaEventName(eventConfig, payload.event);
        var ecommerce = payload.ecommerce;
        if (ecommerce && ecommerce.items && ecommerce.items.length) {
            var mapped = metaContentsFrom(ecommerce.items);
            params.contents = mapped.contents;
            params.content_type = 'product';
            if (name === 'InitiateCheckout') params.num_items = mapped.numItems;
        }
        if (ecommerce && ecommerce.transaction_id) params.order_id = String(ecommerce.transaction_id);
        if (payload.form_name) params.content_name = payload.form_name;
        if (META_STANDARD_EVENTS.indexOf(name) !== -1) {
            window.fbq('track', name, params, { eventID: eventId });
        } else {
            window.fbq('trackCustom', name, params, { eventID: eventId });
        }
    }

    // KC9/D5: same guard as fireGoogleAdsConversion — GTM's GA4 event tag
    // owns this via the dataLayer push when the layer is active.
    function fireGa4ClientEvent(eventName, payload, c, eventConfig) {
        if (dataLayerActive()) return;
        if (dedupMode !== 'client_only') return;
        if (!c.statistics) return;
        if (!config.measurementId) return;
        if (eventConfig && !sendsTo(eventConfig, 'ga4')) return;
        if (typeof window.gtag !== 'function') return;
        var params = { send_to: config.measurementId };
        if (payload.value || isTransactionEvent(eventName)) {
            params.value = payload.value || 0;
            params.currency = payload.currency;
        }
        var ecommerce = payload.ecommerce;
        if (ecommerce) {
            if (ecommerce.items && ecommerce.items.length) params.items = ecommerce.items;
            if (ecommerce.transaction_id) params.transaction_id = String(ecommerce.transaction_id);
            if (ecommerce.coupon) params.coupon = ecommerce.coupon;
        }
        if (payload.form_id) params.form_id = payload.form_id;
        if (payload.form_name) params.form_name = payload.form_name;
        window.gtag('event', eventName, params);
    }

    // === DATA LAYER (D2-D5, KC7-KC9) ===
    // Pushes GA4-shaped e-commerce events to window.dataLayer synchronously,
    // independent of consent and of the proxy request, under Advanced
    // Consent Mode. GTM/Consent Mode decide what actually leaves the browser.

    function dataLayerActive() {
        return !!(config.dataLayer && config.dataLayer.enabled === true);
    }

    // Mirrors TrackWP_DataLayer::ga4_route() (PHP) exactly; both are tested
    // against the same vector file (tests/fixtures/datalayer/ga4-route-vectors.json).
    function ga4Route(inp) {
        inp = inp || {};
        // Strict boolean, matching PHP's ga4_route(): missing key defaults to
        // active, but a present key must be the literal boolean true — a
        // falsy non-boolean (0, '', null) never counts as active.
        var datalayerActive = (inp.datalayer_active === undefined) ? true : (inp.datalayer_active === true);
        if (!datalayerActive || !inp.send_to_ga4 || !inp.ga4_enabled) return 'off';
        var source     = inp.source || 'gtm';
        var eventName  = inp.event || '';
        var mp         = !!inp.mp_configured;
        var statistics = !!inp.statistics;
        var dedup      = inp.dedup_mode || '';
        if (source === 'split' && eventName === 'purchase' && mp && statistics && dedup !== 'client_only') {
            return 'server';
        }
        return 'gtm';
    }

    var DL_ITEM_KEYS = [
        'item_id', 'item_name', 'item_sku', 'item_category', 'item_category2',
        'item_category3', 'item_category4', 'item_category5', 'item_variant',
        'item_list_id', 'item_list_name', 'index', 'price', 'discount', 'quantity'
    ];

    function dataLayerItems(items) {
        var out = [];
        for (var i = 0; i < items.length; i++) {
            var src = items[i] || {};
            var clean = {};
            for (var k = 0; k < DL_ITEM_KEYS.length; k++) {
                var key = DL_ITEM_KEYS[k];
                if (src[key] !== undefined && src[key] !== null && src[key] !== '') clean[key] = src[key];
            }
            out.push(clean);
        }
        return out;
    }

    // Builds the `ecommerce` branch of the push (KC7). Returns null when
    // there is nothing ecommerce-shaped to push at all (most events).
    function dataLayerEcommerce(ec, currency, c) {
        if (!ec || typeof ec !== 'object') return null;
        var out = {};
        out.currency = ec.currency || currency;
        if (ec.items && ec.items.length) {
            var items = dataLayerItems(ec.items);
            out.items = items;
            var sum = 0;
            for (var i = 0; i < items.length; i++) {
                var price = parseFloat(items[i].price);
                if (!isFinite(price)) price = 0;
                var qty = parseFloat(items[i].quantity);
                if (!(qty > 0)) qty = 1;
                sum += price * qty;
            }
            out.value = Math.round(sum * 100) / 100;
        }
        if (ec.transaction_id) out.transaction_id = String(ec.transaction_id);
        if (isFinite(parseFloat(ec.tax))) out.tax = parseFloat(ec.tax);
        if (isFinite(parseFloat(ec.shipping))) out.shipping = parseFloat(ec.shipping);
        if (isFinite(parseFloat(ec.discount))) out.discount = parseFloat(ec.discount);
        // D2/KC7: coupon only leaves RAM with at least one consent choice.
        if (ec.coupon && (c.statistics || c.marketing)) out.coupon = ec.coupon;
        if (ec.item_list_id) out.item_list_id = ec.item_list_id;
        if (ec.item_list_name) out.item_list_name = ec.item_list_name;
        return out;
    }

    /**
     * Public API (KC7): pushes one event to window.dataLayer, independent of
     * consent and of the proxy request, and returns the GA4 route decided
     * for it. Returns null when nothing was pushed (layer inactive, or the
     * event is not a configured/active one).
     *
     * @param {string} eventName
     * @param {object} [params]  Same shape as sendEvent(): value, currency,
     *   ecommerce, ec (pre-hashed gtag user_data, purchase only), event_id.
     * @param {object} [options] eventId (reused verbatim when valid).
     * @return {'gtm'|'server'|'off'|null}
     *
     * Never throws (coordinator fix, Krav 2): a malformed window.dataLayer,
     * or any other unexpected failure, is caught here, logged only when
     * debug is on, and answered with null — this is public API, third
     * parties may call it directly, and it must never break their code or
     * the caller's own server dispatch (see sendInternal()).
     */
    function pushDataLayer(eventName, params, options) {
        try {
            return pushDataLayerCore(eventName, params, options);
        } catch (e) {
            log('pushDataLayer failed:', e && e.message ? e.message : e);
            // The event push itself may be what threw (e.g. window.dataLayer
            // is not an array); the closing reset is still attempted, in its
            // own try, so a partially-applied push never leaves ecommerce/
            // user_data/trackwp set for the NEXT, unrelated dataLayer push.
            try {
                var layer = window.dataLayer = window.dataLayer || [];
                layer.push({ ecommerce: null, user_data: null, trackwp: null });
            } catch (e2) {}
            return null;
        }
    }

    function pushDataLayerCore(eventName, params, options) {
        if (!dataLayerActive()) return null;
        var eventConfig = findEventConfig(eventName);
        if (!eventConfig) return null;
        params = params || {};
        options = options || {};
        var c = readConsent();
        var dl = config.dataLayer;

        var route = ga4Route({
            datalayer_active: true,
            send_to_ga4: sendsTo(eventConfig, 'ga4'),
            ga4_enabled: dl.ga4Enabled === true,
            mp_configured: dl.mpConfigured === true,
            source: dl.ga4Source || 'gtm',
            event: eventName,
            statistics: c.statistics,
            dedup_mode: dedupMode
        });

        var eventId = (options.eventId && EVENT_ID_RE.test(options.eventId)) ? options.eventId
            : ((params.event_id && EVENT_ID_RE.test(params.event_id)) ? params.event_id : generateEventId());

        var value = params.value !== undefined ? (parseFloat(params.value) || 0) : (eventConfig.value || 0);
        var currency = params.currency || eventConfig.currency || 'DKK';

        var ecommerce = dataLayerEcommerce(params.ecommerce, currency, c);

        // D3: only the purchase tag may read user_data, and only with
        // marketing consent held at push time. Never raw PII (K2a keys only).
        var userData = null;
        if ('purchase' === eventName && c.marketing === true && params.ec && typeof params.ec === 'object') {
            var hasKeys = false;
            for (var uk in params.ec) { if (Object.prototype.hasOwnProperty.call(params.ec, uk)) { hasKeys = true; break; } }
            if (hasKeys) userData = params.ec;
        }

        var reset = { ecommerce: null, user_data: null, trackwp: null };
        var layer = window.dataLayer = window.dataLayer || [];
        layer.push(reset);
        var push = { event: eventName };
        if (ecommerce) push.ecommerce = ecommerce;
        if (userData) push.user_data = userData;
        push.trackwp = { source: 'trackwp', ga4_route: route, conversion_value: value, event_id: eventId };
        layer.push(push);
        layer.push(reset);

        log('dataLayer push:', eventName, route);
        return route;
    }

    // === PRE-CONSENT QUEUE (K6) ===
    // Only {name, value?, currency?, form_id?, form_name?, trigger_type}: no
    // items, DOM references, enhanced data, identifiers or timestamps.

    var pendingEvents = [];
    var MAX_PENDING_EVENTS = 20;

    function cleanFormId(v) {
        return String(v || '').replace(/[^A-Za-z0-9_\-:]/g, '').substring(0, 64);
    }

    // Without the shared cleaner a name containing '@' is dropped entirely.
    function cleanFormName(v) {
        var raw = String(v || '');
        var cleaned = cleanTitle(raw);
        if (cleaned === null) cleaned = raw.indexOf('@') === -1 ? raw : '';
        return cleaned.replace(/^\s+|\s+$/g, '').substring(0, 100);
    }

    function queueEvent(name, params, options, requeued, ga4Route_, eventId_) {
        if (pendingEvents.length >= MAX_PENDING_EVENTS) return;
        params = params || {};
        options = options || {};
        var entry = { name: String(name), trigger_type: String(options.trigger_type || 'custom') };
        if (params.value !== undefined && isFinite(parseFloat(params.value))) entry.value = parseFloat(params.value);
        if (params.currency && /^[A-Z]{3}$/.test(params.currency)) entry.currency = params.currency;
        if (params.form_id) entry.form_id = cleanFormId(params.form_id);
        if (params.form_name) entry.form_name = cleanFormName(params.form_name);
        if (requeued) entry.requeued = true;
        // KC7: ga4_route and dl are not identifiers, so K6 still holds. Only
        // stored when a dataLayer push actually happened for this event; the
        // event_id is the one already pushed, so a later flush reuses it
        // instead of minting a second id for the same dataLayer event (TR8).
        if (null !== ga4Route_ && undefined !== ga4Route_) {
            entry.ga4_route = ga4Route_;
            entry.dl = true;
            entry.event_id = eventId_;
        }
        pendingEvents.push(entry);
        log('queued (no valid consent choice):', name);
    }

    function flushPendingEvents() {
        if (!pendingEvents.length) return;
        var c = readConsent();
        if (!c.has) return;
        var queued = pendingEvents;
        pendingEvents = [];
        // Rejected: drop without sending.
        if (!c.statistics && !c.marketing) return;
        for (var i = 0; i < queued.length; i++) {
            var q = queued[i];
            var params = {};
            if (q.value !== undefined) params.value = q.value;
            if (q.currency) params.currency = q.currency;
            if (q.form_id) params.form_id = q.form_id;
            if (q.form_name) params.form_name = q.form_name;
            var flushOptions = { trigger_type: q.trigger_type, _requeued: !!q.requeued };
            if (q.dl) {
                flushOptions.dataLayerDone = true;
                flushOptions.ga4Route = q.ga4_route;
                flushOptions.eventId = q.event_id;
            }
            sendInternal(q.name, params, flushOptions, null);
        }
    }

    // Set once the server answered stale_version on this page: from then on
    // events wait for a new choice even without requireActiveConsent.
    var staleSeen = false;

    function mustQueue(c) {
        if (c.has) return false;
        return requireActiveConsent || staleSeen;
    }

    // === CORE: SEND EVENT ===

    /**
     * Public API (semantics for third parties unchanged in 1.10.1).
     *
     * @param {string} eventName
     * @param {object} [params] value, currency, form_id, form_name, ecommerce, enhanced {email, phone},
     *   event_id (used as payload event_id and Pixel eventID when it matches ^evt_[a-f0-9]{16,64}$),
     *   order_ref (copied to the payload top level),
     *   ec (pre-hashed gtag user_data; set before the Ads conversion and cleared to {} after,
     *   only with marketing consent and customerDataSharing).
     * @param {object} [options] nav (dispatch synchronously), trigger_type,
     *   serverOnly (true: only POST to the server, no gtag and no fbq; see trackwp.features.serverOnly).
     */
    function sendEvent(eventName, params, options) {
        sendInternal(eventName, params, options, null);
    }

    function sendInternal(eventName, params, options, form) {
        params = params || {};
        options = options || {};

        // TR8: event_id is minted ONCE for this event, before the dataLayer
        // push, and the very same id is reused for the push, gtag/fbq and the
        // server payload (and, via the queue entry, after a later flush).
        var eventId = (options.eventId && EVENT_ID_RE.test(options.eventId)) ? options.eventId
            : ((params.event_id && EVENT_ID_RE.test(params.event_id)) ? params.event_id : generateEventId());

        // D2/KC7: the dataLayer push is independent of consent and of the
        // proxy request, so it happens here, before mustQueue(). pushDataLayer()
        // itself never throws (Krav 2), so no guard is needed at this call
        // site; the server dispatch below always runs regardless.
        var route = null;
        if (options.dataLayerDone !== true) {
            route = pushDataLayer(eventName, params, { eventId: eventId });
        }

        var c = readConsent();
        if (mustQueue(c)) {
            queueEvent(eventName, params, options, false, route, eventId);
            return;
        }

        var eventConfig = findEventConfig(eventName);
        var value = params.value !== undefined ? params.value : (eventConfig ? eventConfig.value : 0);
        var currency = params.currency || (eventConfig ? eventConfig.currency : 'DKK');

        var payload = {
            event: eventName,
            event_id: eventId,
            value: parseFloat(value) || 0,
            currency: currency
        };
        var effectiveRoute = options.ga4Route || route;
        if (dataLayerActive() && effectiveRoute) payload.ga4_route = effectiveRoute;
        if (params.form_id) payload.form_id = cleanFormId(params.form_id);
        if (params.form_name) payload.form_name = cleanFormName(params.form_name);
        if (params.ecommerce && typeof params.ecommerce === 'object') payload.ecommerce = params.ecommerce;
        if (params.order_ref) payload.order_ref = String(params.order_ref);
        if (c.statistics) {
            var engaged = takeEngagedMs();
            if (engaged > 0) payload.engaged_ms = engaged;
        }

        var requeue = options._requeued ? null : { name: eventName, params: params, options: options };
        var enhancedInput = null;
        if (enhancedAllowed(c)) {
            enhancedInput = form ? readFormEnhanced(form) : null;
            if (!enhancedInput && params.enhanced && (params.enhanced.email || params.enhanced.phone)) {
                enhancedInput = { email: params.enhanced.email || '', phone: params.enhanced.phone || '' };
            }
        }

        function finish(p) {
            var fresh = readConsent();
            dispatchPayload(p, options.nav === true, requeue);
            if (options.serverOnly !== true) {
                fireGa4ClientEvent(eventName, p, fresh, eventConfig);
                fireGoogleAdsConversion(eventConfig, p, eventId, fresh, params.ec);
                fireMetaPixel(eventConfig, p, eventId, fresh);
            }
            log(eventName, p);
        }

        if (!enhancedInput) {
            finish(payload);
            return;
        }

        if (options.nav === true) {
            // Navigation may unload before a promise resolves: send normalised
            // raw values, the server hashes them (K2a).
            var raw = {};
            var em = normMetaEmail(enhancedInput.email);
            var ph = normPhoneE164(enhancedInput.phone);
            if (em) raw.email = em;
            if (ph) raw.phone = ph;
            if (em || ph) payload.enhanced = raw;
            finish(payload);
            return;
        }

        var generation = consentGeneration;
        hashUserData(enhancedInput).then(function(hashed) {
            if (generation !== consentGeneration) {
                log('hashing cancelled (consent changed):', eventName);
            } else if (hashed && Object.keys(hashed).length) {
                payload.enhanced = hashed;
            }
            finish(payload);
        }, function() {
            finish(payload);
        });
    }

    // === TRIGGERS & CONDITIONS ===

    var NAV_LIKE_SELECTORS = ['a[href^="mailto:"]', 'a[href^="tel:"]', 'a[href^="sms:"]', 'a[download]'];
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
            return !!url.hostname && url.hostname !== window.location.hostname;
        } catch (e) {
            return false;
        }
    }

    function triggersFor(evt) {
        if (evt.triggers && evt.triggers.length) return evt.triggers;
        if (!evt.trigger_type) return [];
        return [{
            type: evt.trigger_type,
            css_selector: evt.css_selector || '',
            url_match: evt.url_match || '',
            scroll_depth: evt.scroll_depth || 0,
            time_seconds: evt.time_seconds || 0,
            js_event: evt.js_event || '',
            conditions: []
        }];
    }

    function normalizeText(value) {
        if (!value) return '';
        return String(value).replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '').substring(0, 300);
    }

    function computeVariable(name, param, el) {
        switch (name) {
            case 'page_url':      return window.location.href;
            case 'page_hostname': return (window.location.hostname || '').toLowerCase();
            case 'page_path':     return window.location.pathname || '';
            case 'page_fragment': return (window.location.hash || '').replace(/^#/, '');
            case 'query_param':   return getUrlParam(param);
            case 'page_title':    return document.title || '';
            case 'referrer':      return document.referrer || '';
            case 'click_id':
            case 'form_id':       return el ? (el.id || '') : null;
            case 'click_classes':
            case 'form_classes':  return el ? (el.getAttribute && el.getAttribute('class') || '') : null;
            case 'click_text':    return el ? normalizeText(el.textContent) : null;
            case 'click_url':     return el ? (el.getAttribute && el.getAttribute('href') || '') : null;
            case 'form_action':   return el ? (el.getAttribute && el.getAttribute('action') || '') : null;
            case 'click_element':
            case 'form_element':  return el || null;
            default:              return null;
        }
    }

    function evaluateCondition(cond, el, cache) {
        var key = cond.variable + '|' + (cond.param || '');
        if (!Object.prototype.hasOwnProperty.call(cache, key)) cache[key] = computeVariable(cond.variable, cond.param, el);
        var value = cache[key];
        if (value === null || value === undefined) return false;
        var op = cond.operator;
        var target = cond.value || '';
        if (op === 'matches_selector' || op === 'not_matches_selector') {
            var matched = false;
            try {
                matched = !!(value && typeof value.matches === 'function' && value.matches(target));
            } catch (e) {
                matched = false;
            }
            return op === 'matches_selector' ? matched : !matched;
        }
        if (op === 'has_class' || op === 'not_has_class') {
            var tokens = String(value).split(/\s+/);
            var hasIt = false;
            for (var i = 0; i < tokens.length; i++) {
                if (tokens[i] === target) { hasIt = true; break; }
            }
            return op === 'has_class' ? hasIt : !hasIt;
        }
        var str = String(value);
        switch (op) {
            case 'exists':       return str !== '';
            case 'not_exists':   return str === '';
            case 'equals':       return str === target;
            case 'not_equals':   return str !== target;
            case 'contains':     return str.indexOf(target) !== -1;
            case 'not_contains': return str.indexOf(target) === -1;
            case 'starts_with':  return str.lastIndexOf(target, 0) === 0;
            case 'ends_with':    return target.length <= str.length && str.indexOf(target, str.length - target.length) !== -1;
            default:             return false;
        }
    }

    function triggerMatches(trg, el) {
        var conds = trg.conditions;
        if (!conds || !conds.length) return true;
        var cache = {};
        for (var i = 0; i < conds.length; i++) {
            if (!evaluateCondition(conds[i], el, cache)) {
                log('condition failed:', conds[i].variable, conds[i].operator, conds[i].value);
                return false;
            }
        }
        return true;
    }

    /**
     * Page-scoped trigger check for integrations (woocommerce.js, §2.1).
     * True when the event has no triggers at all, or when at least one of its
     * triggers of `triggerType` passes its conditions (evaluated without an
     * element, so element variables never match).
     */
    function passesPageConditions(eventName, triggerType) {
        var evt = findEventConfig(eventName);
        if (!evt) return true;
        var triggers = triggersFor(evt);
        if (!triggers.length) return true;
        for (var i = 0; i < triggers.length; i++) {
            if (triggers[i].type !== triggerType) continue;
            if (triggerMatches(triggers[i], null)) return true;
        }
        return false;
    }

    /**
     * Form integrations (class-trackwp-forms.php) call this after a successful
     * submission. Every form_submit trigger of every event is evaluated on its
     * own against the form (selector + conditions). The integration never
     * reads field values; enhanced data is read here, synchronously, at
     * dispatch time, and only with marketing consent.
     *
     * @param {{form_id?:string, form_name?:string, plugin?:string, nav?:boolean}} detail
     * @param {Element|null} form
     * @return {number} number of events sent or queued
     */
    function sendFormEvent(detail, form) {
        detail = detail || {};
        var sent = 0;
        for (var i = 0; i < events.length; i++) {
            var evt = events[i];
            var triggers = triggersFor(evt);
            for (var t = 0; t < triggers.length; t++) {
                var trg = triggers[t];
                if (trg.type !== 'form_submit') continue;
                if (trg.css_selector) {
                    if (!form || typeof form.matches !== 'function') continue;
                    try {
                        if (!form.matches(trg.css_selector)) continue;
                    } catch (err) {
                        continue;
                    }
                }
                if (!triggerMatches(trg, form || null)) continue;
                if (isDuplicateDispatch(evt.name, form)) break;
                sendInternal(evt.name, {
                    form_id: detail.form_id || (form && form.id) || '',
                    form_name: detail.form_name || ''
                }, { nav: detail.nav === true, trigger_type: 'form_submit' }, form || null);
                sent++;
                break; // one dispatch per event
            }
        }
        return sent;
    }

    function initAutoDetect() {
        var clickTriggers = [];
        var downloadTriggers = [];
        for (var i = 0; i < events.length; i++) {
            var evt = events[i];
            var triggers = triggersFor(evt);
            for (var t = 0; t < triggers.length; t++) {
                var trg = triggers[t];
                switch (trg.type) {
                    case 'css_click':
                        if (trg.css_selector) clickTriggers.push({ evt: evt, trg: trg });
                        break;
                    case 'scroll_depth':
                        bindScrollEvent(evt, trg);
                        break;
                    case 'time_on_page':
                        bindTimeEvent(evt, trg);
                        break;
                    case 'url_match':
                        urlTriggers.push({ evt: evt, trg: trg });
                        break;
                    case 'file_download':
                        downloadTriggers.push({ evt: evt, trg: trg });
                        break;
                    case 'js_event':
                        if (trg.js_event) bindJsEvent(evt, trg);
                        break;
                    // form_submit: class-trackwp-forms.php -> sendFormEvent().
                }
            }
        }
        bindClickEvents(clickTriggers);
        bindFileDownloads(downloadTriggers);
        evaluateUrlTriggers();
        watchUrlChanges();
    }

    function bindJsEvent(evt, trg) {
        document.addEventListener(trg.js_event, function(e) {
            if (isDuplicateDispatch(evt.name, e && e.target)) return;
            if (!triggerMatches(trg, null)) return;
            sendInternal(evt.name, {}, { trigger_type: 'js_event' }, null);
        });
    }

    function bindClickEvents(clickTriggers) {
        if (!clickTriggers.length) return;
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            for (var i = 0; i < clickTriggers.length; i++) {
                var evt = clickTriggers[i].evt;
                var trg = clickTriggers[i].trg;
                var match;
                try {
                    match = e.target.closest(trg.css_selector);
                } catch (err) {
                    continue;
                }
                if (!match) continue;
                if (!triggerMatches(trg, match)) continue;
                if (isDuplicateDispatch(evt.name, match)) continue;
                var nav = isNavLikeSelector(trg.css_selector) || isOutboundLink(match);
                sendInternal(evt.name, {}, { nav: nav, trigger_type: 'css_click' }, null);
            }
        }, true);
    }

    function bindFileDownloads(downloadTriggers) {
        if (!downloadTriggers.length) return;
        var defaultSelector = FILE_EXT_SELECTORS.join(',');
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            for (var i = 0; i < downloadTriggers.length; i++) {
                var evt = downloadTriggers[i].evt;
                var trg = downloadTriggers[i].trg;
                var match;
                try {
                    match = e.target.closest(trg.css_selector ? trg.css_selector : defaultSelector);
                } catch (err) {
                    continue;
                }
                if (!match) continue;
                if (!triggerMatches(trg, match)) continue;
                if (isDuplicateDispatch(evt.name, match)) continue;
                sendInternal(evt.name, {}, { nav: true, trigger_type: 'file_download' }, null);
            }
        }, true);
    }

    function bindScrollEvent(evt, trg) {
        var depth = parseInt(trg.scroll_depth, 10) || 50;
        var fired = false;
        function checkScroll() {
            if (fired) return;
            var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            var docHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            if (docHeight <= 0) return;
            if ((scrollTop / docHeight) * 100 >= depth) {
                fired = true;
                window.removeEventListener('scroll', checkScroll);
                if (!triggerMatches(trg, null)) return;
                sendInternal(evt.name, {}, { trigger_type: 'scroll_depth' }, null);
            }
        }
        window.addEventListener('scroll', checkScroll, { passive: true });
    }

    function bindTimeEvent(evt, trg) {
        var seconds = parseInt(trg.time_seconds, 10) || 30;
        setTimeout(function() {
            if (!triggerMatches(trg, null)) return;
            sendInternal(evt.name, {}, { trigger_type: 'time_on_page' }, null);
        }, seconds * 1000);
    }

    var urlTriggers = [];
    var firedUrlKeys = {};

    function evaluateUrlTriggers() {
        for (var i = 0; i < urlTriggers.length; i++) {
            var evt = urlTriggers[i].evt;
            var trg = urlTriggers[i].trg;
            var key = evt.name + '|' + window.location.href;
            if (firedUrlKeys[key]) continue;
            if (trg.url_match && window.location.href.indexOf(trg.url_match) === -1) continue;
            if (!triggerMatches(trg, null)) continue;
            firedUrlKeys[key] = true;
            sendInternal(evt.name, {}, { trigger_type: 'url_match' }, null);
        }
    }

    function watchUrlChanges() {
        if (!urlTriggers.length) return;
        function reEvaluate() {
            setTimeout(evaluateUrlTriggers, 0);
        }
        try {
            var methods = ['pushState', 'replaceState'];
            for (var i = 0; i < methods.length; i++) {
                (function(name) {
                    var original = window.history[name];
                    if (typeof original !== 'function') return;
                    window.history[name] = function() {
                        var result = original.apply(this, arguments);
                        reEvaluate();
                        return result;
                    };
                })(methods[i]);
            }
        } catch (e) {}
        window.addEventListener('popstate', reEvaluate);
        window.addEventListener('hashchange', reEvaluate);
    }

    // === CONSENT CHANGES ===

    document.addEventListener('trackwp:consent_updated', function() {
        var c = readConsent();
        if (c.marketing) persistClickIds();
        flushPendingEvents();
        if (c.statistics || c.marketing) fireKeepalive();
    });

    // Withdraw / downgrade (K4, R6): clear RAM per category and cancel hashing.
    document.addEventListener('trackwp:consent_revoked', function(e) {
        var d = (e && e.detail) || {};
        consentGeneration++;
        if (d.full) pendingEvents = [];
        if (d.marketing || d.full) {
            clickIds = {};
            clickIdsPersisted = false;
            expireCookie('_twp_click');
            expireCookie('_fbc', config.cookieDomain || '');
        }
        if (d.statistics || d.full) {
            ephemeralClientId = null;
            try { window.sessionStorage.removeItem('trackwp_sid'); } catch (err) {}
            engagedAccum = 0;
        }
    });

    // === EXPOSE API ===

    window.trackwp = {
        sendEvent: sendEvent,
        sendFormEvent: sendFormEvent,
        passesPageConditions: passesPageConditions,
        pushDataLayer: pushDataLayer,
        features: { serverOnly: true, dataLayer: true },
        pendingKeepalive: null,
        normalize: {
            googleEmail: normGoogleEmail,
            metaEmail: normMetaEmail,
            phoneE164: normPhoneE164,
            metaPhone: normMetaPhone
        }
    };

    fireEvent('trackwp:ready', null);

    // === INIT ===

    captureClickIds();
    if (readConsent().marketing) persistClickIds();

    function trackwpInit() {
        initAutoDetect();
        var c = readConsent();
        if (c.statistics || c.marketing) fireKeepalive();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackwpInit);
    } else {
        trackwpInit();
    }

})(window, document);
