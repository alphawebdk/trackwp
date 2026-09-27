(function(window, document) {
    'use strict';

    // Consent Mode v2 defaults, the consent reader (K3) and the restore from
    // the cookie are emitted inline by PHP on wp_head priority 1. Here we only
    // make sure window.gtag exists before we call gtag('consent','update',...).
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
        window.gtag = function() { window.dataLayer.push(arguments); };
    }

    // === CONFIG ===
    var config = window.trackwpConsentConfig || {};
    var COOKIE_NAME = 'trackwp_consent';
    var LOG_TIMEOUT_MS = 5000;
    var months = parseInt(config.cookieLifetimeMonths, 10) || 12;
    if (months < 1) months = 1;
    if (months > 12) months = 12;
    var cookieLifetimeDays = months * 30;
    // Replaced by current_version from a stale_version response (R4).
    var consentVersion = parseInt(config.consentVersion, 10) || 1;
    var restUrl = config.restUrl || '';
    var configBannerHash = config.bannerHash || '';
    var i18n = config.i18n || {};

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
        'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    // === STATE ===
    // The last choice written by THIS tab (full cookie object, R7). Null when
    // no choice was made here or the choice was invalidated as stale.
    var localChoice = null;
    var tabsInitialized = [];
    var hideBannerTimer = null;
    var lastFocused = null;
    var boundRoots = [];

    // === HELPERS ===
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

    function uuid4() {
        try {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
        } catch (e) {}
        var bytes = [];
        for (var i = 0; i < 16; i++) {
            bytes.push(Math.floor(Math.random() * 256));
        }
        try {
            if (window.crypto && window.crypto.getRandomValues) {
                var arr = new Uint8Array(16);
                window.crypto.getRandomValues(arr);
                bytes = Array.prototype.slice.call(arr);
            }
        } catch (e) {}
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = '';
        for (var b = 0; b < 16; b++) {
            hex += ('0' + bytes[b].toString(16)).slice(-2);
        }
        return hex.substr(0, 8) + '-' + hex.substr(8, 4) + '-' + hex.substr(12, 4) + '-' +
            hex.substr(16, 4) + '-' + hex.substr(20, 12);
    }

    function tsMs(obj) {
        if (!obj || !obj.ts) return 0;
        var t = Date.parse(obj.ts);
        return isNaN(t) ? 0 : t;
    }

    // === CONSENT READ (K3: the reader is the ONLY cookie parser) ===
    function readChoice() {
        var reader = window.trackwpConsentReader;
        if (!reader || typeof reader.read !== 'function') return null;
        try {
            var c = reader.read();
            return (c && typeof c === 'object') ? c : null;
        } catch (e) {
            return null;
        }
    }

    function choicesOf(obj) {
        return {
            necessary: true,
            statistics: !!(obj && obj.statistics === true),
            marketing: !!(obj && obj.marketing === true),
            personalisation: !!(obj && obj.personalisation === true)
        };
    }

    function currentChoices() {
        return choicesOf(readChoice());
    }

    // === CONSENT COOKIE WRITE ===
    // Host-only (no Domain attribute, K7/R17), Path=/, SameSite=Lax, Secure on https.
    function writeCookie(obj) {
        var expires = new Date((tsMs(obj) || Date.now()) + cookieLifetimeDays * 86400000);
        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(JSON.stringify(obj)) +
            ';expires=' + expires.toUTCString() + ';path=/;SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');
    }

    function buildCookie(choices, id, now) {
        // Explicit shape (R8) -- the same one the server writes back.
        return {
            v: consentVersion,
            ts: now.toISOString(),
            id: id,
            necessary: true,
            statistics: !!choices.statistics,
            marketing: !!choices.marketing,
            personalisation: !!choices.personalisation
        };
    }

    // The reader in cached HTML carries the version it was rendered with. After
    // a stale_version answer the new choice is written with current_version, so
    // the reader must compare against that version from now on.
    function syncReaderVersion() {
        var reader = window.trackwpConsentReader;
        if (reader && typeof reader === 'object' && reader.version !== consentVersion) {
            try { reader.version = consentVersion; } catch (e) {}
        }
    }

    // === CONSENT MODE / PIXEL ===
    function updateConsentMode(choices) {
        window.gtag('consent', 'update', {
            'analytics_storage': choices.statistics ? 'granted' : 'denied',
            'ad_storage': choices.marketing ? 'granted' : 'denied',
            'ad_user_data': choices.marketing ? 'granted' : 'denied',
            'ad_personalization': choices.marketing ? 'granted' : 'denied',
            'functionality_storage': choices.personalisation ? 'granted' : 'denied',
            'personalization_storage': choices.personalisation ? 'granted' : 'denied',
            'security_storage': 'granted'
        });
    }

    function pixel(action) {
        if (typeof window.fbq !== 'function') return;
        try { window.fbq('consent', action); } catch (e) {}
    }

    // === SERIALISED CONSENT QUEUE (K4, R5, R7) ===
    // Log and withdraw share ONE queue: the next request leaves only when the
    // previous one answered or hit the 5 s timeout.
    var requestQueue = [];
    var requestRunning = false;

    var KEEPALIVE_WAIT_MS = 3000;

    // === TRACKING-COOKIE EXPIRY (review class F) ===
    // Non-HttpOnly tracking cookies per category, expired in every domain
    // variant (host-only, and each parent domain with and without a dot).
    var STATISTICS_COOKIES = ['_ga', '_gid', '_gat', '_twp_cid'];
    var STATISTICS_PREFIXES = ['_ga_', '_gat_'];
    var MARKETING_COOKIES = ['_fbp', '_fbc', '_twp_click', '_gcl_au', '_gcl_aw', '_gcl_dc', '_gcl_gb', '_gcl_gs'];
    var MARKETING_PREFIXES = ['_gcl_'];

    function cookieNames() {
        var out = [];
        var parts = (document.cookie || '').split(';');
        for (var i = 0; i < parts.length; i++) {
            var eq = parts[i].indexOf('=');
            var name = (eq === -1 ? parts[i] : parts[i].substring(0, eq)).replace(/^\s+|\s+$/g, '');
            if (name) out.push(name);
        }
        return out;
    }

    function domainVariants() {
        var host = String(window.location.hostname || '');
        var out = [''];
        var labels = host.split('.');
        for (var i = 0; i < labels.length - 1; i++) {
            var d = labels.slice(i).join('.');
            out.push(d, '.' + d);
        }
        var tw = window.trackwpConfig || {};
        var extra = config.cookieDomain || tw.cookieDomain || '';
        if (extra && out.indexOf(extra) === -1) out.push(extra);
        return out;
    }

    function expireEverywhere(name, domains) {
        for (var i = 0; i < domains.length; i++) {
            document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;max-age=0;path=/' +
                (domains[i] ? ';domain=' + domains[i] : '') + ';SameSite=Lax';
        }
    }

    /**
     * @param {object}  categories  {statistics, marketing} to expire
     * @param {boolean} onlyPresent true: only names currently in document.cookie
     *                              (page-load clean-up; avoids blind writes)
     */
    function expireTrackingCookies(categories, onlyPresent) {
        categories = categories || {};
        var names = [];
        var present = cookieNames();
        function collect(list, prefixes) {
            var i;
            for (i = 0; i < list.length; i++) if (names.indexOf(list[i]) === -1) names.push(list[i]);
            for (i = 0; i < present.length; i++) {
                for (var p = 0; p < prefixes.length; p++) {
                    if (present[i].indexOf(prefixes[p]) === 0 && names.indexOf(present[i]) === -1) names.push(present[i]);
                }
            }
        }
        if (categories.statistics) {
            collect(STATISTICS_COOKIES, STATISTICS_PREFIXES);
            var tw = window.trackwpConfig || {};
            if (typeof tw.cookieName === 'string' && tw.cookieName && names.indexOf(tw.cookieName) === -1) names.push(tw.cookieName);
        }
        if (categories.marketing) collect(MARKETING_COOKIES, MARKETING_PREFIXES);
        if (onlyPresent) {
            var existing = [];
            for (var e = 0; e < names.length; e++) {
                if (present.indexOf(names[e]) !== -1) existing.push(names[e]);
            }
            names = existing;
        }
        if (!names.length) return;
        var domains = domainVariants();
        for (var n = 0; n < names.length; n++) expireEverywhere(names[n], domains);
    }

    function clearLocalState(next) {
        try {
            window.localStorage.removeItem('trackwp_ka_ts');
            if (!next.statistics && !next.marketing) window.localStorage.removeItem('trackwp_woo_purchases');
        } catch (e) {}
    }

    // Waits for trackwp.js's running keepalive (max 3 s) so the expiry
    // headers of a withdraw/downgrade answer always land after it.
    function waitForKeepalive(cb) {
        var tw = window.trackwp;
        var p = tw && tw.pendingKeepalive;
        if (!p || typeof p.then !== 'function') {
            cb();
            return;
        }
        var called = false;
        function go() {
            if (called) return;
            called = true;
            cb();
        }
        var t = setTimeout(go, KEEPALIVE_WAIT_MS);
        p.then(function() { clearTimeout(t); go(); }, function() { clearTimeout(t); go(); });
    }

    /**
     * @param {object} body     consent-log body
     * @param {object|null} revoked {statistics, marketing} to expire after the answer
     */
    function enqueueRequest(body, revoked) {
        if (!restUrl) return;
        requestQueue.push({ body: body, revoked: revoked || null });
        pumpQueue();
    }

    function pumpQueue() {
        if (requestRunning || !requestQueue.length) return;
        requestRunning = true;
        var job = requestQueue.shift();
        if (job.revoked) {
            waitingJob = job;
            waitForKeepalive(function() {
                waitingJob = null;
                if (job.sent) {
                    // Already flushed on pagehide (page restored from bfcache).
                    requestRunning = false;
                    pumpQueue();
                    return;
                }
                sendJob(job);
            });
        } else {
            sendJob(job);
        }
    }

    // A withdraw/downgrade job still waiting for the keepalive, if any.
    var waitingJob = null;

    // pagehide: jobs that have not left yet are sent at once, without
    // waiting for the keepalive, or they would be lost with the tab.
    function flushOnPagehide() {
        var jobs = [];
        if (waitingJob && !waitingJob.sent) jobs.push(waitingJob);
        for (var i = 0; i < requestQueue.length; i++) {
            if (!requestQueue[i].sent) jobs.push(requestQueue[i]);
        }
        requestQueue = [];
        if (!jobs.length || !restUrl) return;
        var url = restUrl + 'trackwp/v1/consent-log';
        for (var j = 0; j < jobs.length; j++) {
            var job = jobs[j];
            job.sent = true;
            var body = JSON.stringify(job.body);
            var beaconed = false;
            try {
                if (window.navigator && typeof window.navigator.sendBeacon === 'function' && typeof window.Blob === 'function') {
                    beaconed = window.navigator.sendBeacon(url, new window.Blob([body], { type: 'application/json' }));
                }
            } catch (e) {
                beaconed = false;
            }
            if (!beaconed) {
                try {
                    window.fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: body,
                        credentials: 'same-origin',
                        keepalive: true
                    }).catch(function() {});
                } catch (e) {}
            }
            if (job.revoked) expireTrackingCookies(job.revoked);
        }
    }

    window.addEventListener('pagehide', flushOnPagehide);

    function sendJob(job) {
        job.sent = true;
        var body = job.body;
        var finished = false;
        var controller = null;
        try {
            if (typeof window.AbortController === 'function') controller = new window.AbortController();
        } catch (e) {}

        function done(json) {
            if (finished) return;
            finished = true;
            if (json && json.consent === 'stale_version') {
                handleStale(json.current_version);
            }
            reconcile();
            if (job.revoked) expireTrackingCookies(job.revoked);
            requestRunning = false;
            pumpQueue();
        }

        var timer = setTimeout(function() {
            if (controller) {
                try { controller.abort(); } catch (e) {}
            }
            done(null);
        }, LOG_TIMEOUT_MS);

        try {
            var init = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin',
                keepalive: true
            };
            if (controller) init.signal = controller.signal;
            window.fetch(restUrl + 'trackwp/v1/consent-log', init).then(function(res) {
                if (!res || typeof res.json !== 'function') return null;
                return res.json().catch(function() { return null; });
            }).then(function(json) {
                clearTimeout(timer);
                done(json);
            }, function() {
                clearTimeout(timer);
                done(null);
            });
        } catch (e) {
            clearTimeout(timer);
            done(null);
        }
    }

    // R7: after every consent response the cookie must still hold this tab's
    // latest choice. A delayed Set-Cookie from an older request could have
    // overwritten it. A NEWER choice (from another tab) is left alone.
    function reconcile() {
        if (!localChoice) return;
        var stored = readChoice();
        if (stored && tsMs(stored) > tsMs(localChoice)) {
            localChoice = null;
            return;
        }
        if (!stored ||
            stored.id !== localChoice.id ||
            tsMs(stored) !== tsMs(localChoice) ||
            !!stored.statistics !== localChoice.statistics ||
            !!stored.marketing !== localChoice.marketing ||
            !!stored.personalisation !== localChoice.personalisation) {
            writeCookie(localChoice);
        }
    }

    // === APPLY A CHOICE (K4, R6) ===
    function applyChoice(choices, forceWithdraw) {
        var prevRaw = readChoice();
        var prev = prevRaw ? choicesOf(prevRaw) : null;
        var next = {
            necessary: true,
            statistics: !!choices.statistics,
            marketing: !!choices.marketing,
            personalisation: !!choices.personalisation
        };
        var allOff = !next.statistics && !next.marketing && !next.personalisation;
        var prevAny = !!(prev && (prev.statistics || prev.marketing || prev.personalisation));
        var fullWithdraw = !!forceWithdraw || (allOff && prevAny);
        if (forceWithdraw) {
            next.statistics = false;
            next.marketing = false;
            next.personalisation = false;
        }
        var revoked = {
            statistics: !!(prev && prev.statistics && !next.statistics),
            marketing: !!(prev && prev.marketing && !next.marketing),
            personalisation: !!(prev && prev.personalisation && !next.personalisation),
            full: fullWithdraw
        };
        var granted = {
            marketing: next.marketing && !(prev && prev.marketing)
        };

        // 1. Cookie first, synchronously (new ts, id reused or generated).
        var id = (localChoice && localChoice.id) || (prevRaw && prevRaw.id) || uuid4();
        var now = new Date();
        var obj = buildCookie(next, id, now);
        localChoice = obj;
        syncReaderVersion();
        writeCookie(obj);

        // 2. RAM clean-up in trackwp.js/woocommerce.js (per category, R6),
        // local storage and the client's own copy of the cookie expiry.
        var anyRevoked = revoked.statistics || revoked.marketing || revoked.personalisation || fullWithdraw;
        var expire = null;
        if (anyRevoked) {
            fireEvent('trackwp:consent_revoked', revoked);
            clearLocalState(next);
            expire = {
                statistics: fullWithdraw ? true : revoked.statistics,
                marketing: fullWithdraw ? true : revoked.marketing
            };
            expireTrackingCookies(expire);
        }

        // 3. Google per category, user_data cleared, Pixel revoke/grant.
        updateConsentMode(next);
        if (revoked.marketing || fullWithdraw) {
            window.gtag('set', 'user_data', {});
            pixel('revoke');
        } else if (granted.marketing) {
            pixel('grant');
        }

        fireEvent('trackwp:consent_updated', {
            necessary: true,
            statistics: next.statistics,
            marketing: next.marketing,
            personalisation: next.personalisation
        });

        // 4. Server: withdraw via POST (R5), otherwise set/update. Always sent.
        var eventType = fullWithdraw ? 'withdraw' : (prev ? 'update' : 'set');
        enqueueRequest({
            statistics: next.statistics,
            marketing: next.marketing,
            personalisation: next.personalisation,
            consent_id: id,
            consent_version: consentVersion,
            ts: obj.ts,
            ts_ms: now.getTime(),
            event_type: eventType,
            banner_hash: bannerHash()
        }, expire);

        renderStatus();
        hideBanner();
        return obj;
    }

    function withdraw() {
        return applyChoice({ statistics: false, marketing: false, personalisation: false }, true);
    }

    // === STALE (K3, R4) ===
    function handleStale(currentVersion) {
        var v = parseInt(currentVersion, 10);
        if (v > 0) consentVersion = v;
        var src = localChoice || readChoice();
        if (src) {
            var marked = {
                v: src.v,
                ts: src.ts,
                id: src.id,
                necessary: true,
                statistics: !!src.statistics,
                marketing: !!src.marketing,
                personalisation: !!src.personalisation,
                stale: true
            };
            writeCookie(marked);
        }
        localChoice = null;
        syncReaderVersion();
        fireEvent('trackwp:consent_revoked', { statistics: true, marketing: true, personalisation: true, full: true, stale: true });
        updateConsentMode({ statistics: false, marketing: false, personalisation: false });
        window.gtag('set', 'user_data', {});
        pixel('revoke');
        renderStatus();
        showBanner();
    }

    // === DOM LOOKUP ===
    function getBanner() { return document.getElementById('trackwp-consent-banner'); }
    function getDrawer() { return document.getElementById('trackwp-consent-drawer'); }
    function getOverlay() { return document.getElementById('trackwp-consent-overlay'); }

    // data-banner-hash on the banner root (W6) wins over config.
    function bannerHash() {
        var b = getBanner();
        return (b && b.getAttribute('data-banner-hash')) || configBannerHash;
    }

    // data-mode="info": no consent is required; the banner only informs.
    function isInfoMode() {
        var b = getBanner();
        return !!b && b.getAttribute('data-mode') === 'info';
    }

    // "Accept all" only grants categories that have a checkbox in the DOM.
    // With no category checkboxes at all (legacy markup) every category counts.
    function acceptAllChoices() {
        var ids = { statistics: 'trackwp-consent-statistics', marketing: 'trackwp-consent-marketing', personalisation: 'trackwp-consent-personalisation' };
        var out = {};
        var any = false;
        for (var k in ids) {
            if (!Object.prototype.hasOwnProperty.call(ids, k)) continue;
            out[k] = !!document.getElementById(ids[k]);
            if (out[k]) any = true;
        }
        return any ? out : { statistics: true, marketing: true, personalisation: true };
    }

    function getBannerStyle() {
        var root = getBanner();
        return root ? (root.getAttribute('data-style') || 'dialog') : 'dialog';
    }

    function isVisible(el) {
        return !!el && !el.hasAttribute('hidden') && el.style.display !== 'none';
    }

    // === STATUS BLOCK (filled from the cookie via the reader) ===
    // Markup hooks (owned by W6): [data-role="consent-status"] containing
    // [data-field="id"], [data-field="date"] and [data-field="status"];
    // a withdraw button with data-action="withdraw".
    function statusText(c) {
        if (!c) return i18n.statusNone || 'Intet valg';
        if (!c.statistics && !c.marketing && !c.personalisation) {
            return i18n.statusRejected || 'Kun nødvendige cookies';
        }
        var parts = [];
        if (c.statistics) parts.push(i18n.statistics || 'Statistik');
        if (c.marketing) parts.push(i18n.marketing || 'Marketing');
        if (c.personalisation) parts.push(i18n.personalisation || 'Præferencer');
        return (i18n.statusAccepted || 'Accepteret') + ': ' + parts.join(', ');
    }

    function formatDate(ts) {
        var t = Date.parse(ts || '');
        if (isNaN(t)) return '';
        try {
            return new Date(t).toLocaleString(document.documentElement.lang || 'da');
        } catch (e) {
            return new Date(t).toISOString();
        }
    }

    function renderStatus() {
        if (typeof document.querySelectorAll !== 'function') return;
        var stored = readChoice();
        var blocks = document.querySelectorAll('[data-role="consent-status"]') || [];
        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            var fields = { id: stored ? String(stored.id || '') : '', date: stored ? formatDate(stored.ts) : '', status: statusText(stored) };
            for (var key in fields) {
                if (!Object.prototype.hasOwnProperty.call(fields, key)) continue;
                var el = block.querySelector('[data-field="' + key + '"]');
                if (el) el.textContent = fields[key];
            }
            block.removeAttribute('hidden');
        }
        var buttons = document.querySelectorAll('[data-action="withdraw"]') || [];
        var canWithdraw = !!(stored && (stored.statistics || stored.marketing || stored.personalisation));
        for (var b = 0; b < buttons.length; b++) {
            if (canWithdraw) {
                buttons[b].removeAttribute('hidden');
                buttons[b].removeAttribute('disabled');
            } else {
                buttons[b].setAttribute('hidden', '');
            }
        }
    }

    // === TABS (ARIA tabs with arrow keys, roving tabindex) ===
    function setupTabs(root) {
        if (!root) return;
        var tabs = root.querySelectorAll('.trackwp-consent__tab');
        if (!tabs || !tabs.length) return;
        for (var i = 0; i < tabsInitialized.length; i++) {
            if (tabsInitialized[i] === root) return;
        }
        tabsInitialized.push(root);
        var panels = root.querySelectorAll('[data-panel]');

        function activate(tab, focus) {
            var target = tab.getAttribute('data-tab');
            for (var t = 0; t < tabs.length; t++) {
                var active = (tabs[t] === tab);
                tabs[t].setAttribute('aria-selected', active ? 'true' : 'false');
                tabs[t].setAttribute('tabindex', active ? '0' : '-1');
                if (active) tabs[t].classList.add('is-active'); else tabs[t].classList.remove('is-active');
            }
            for (var p = 0; p < panels.length; p++) {
                if (panels[p].getAttribute('data-panel') === target) {
                    panels[p].classList.add('is-active');
                    panels[p].removeAttribute('hidden');
                } else {
                    panels[p].classList.remove('is-active');
                    panels[p].setAttribute('hidden', '');
                }
            }
            if (focus) {
                try { tab.focus(); } catch (e) {}
            }
        }

        var initial = root.querySelector('.trackwp-consent__tab.is-active') || tabs[0];
        activate(initial, false);

        for (var k = 0; k < tabs.length; k++) {
            (function(tab, index) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    activate(tab, false);
                });
                tab.addEventListener('keydown', function(e) {
                    var next = null;
                    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabs[(index + 1) % tabs.length];
                    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabs[(index - 1 + tabs.length) % tabs.length];
                    else if (e.key === 'Home') next = tabs[0];
                    else if (e.key === 'End') next = tabs[tabs.length - 1];
                    if (!next) return;
                    e.preventDefault();
                    activate(next, true);
                });
            })(tabs[k], k);
        }
    }

    // === FOCUS MANAGEMENT ===
    function modalRoot() {
        var drawer = getDrawer();
        if (drawer && isVisible(drawer)) return drawer;
        var banner = getBanner();
        if (banner && isVisible(banner) && banner.getAttribute('aria-modal') === 'true') return banner;
        return null;
    }

    function focusables(root) {
        var list = root.querySelectorAll(FOCUSABLE);
        var out = [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].offsetParent !== null || list[i] === document.activeElement) out.push(list[i]);
        }
        return out;
    }

    function focusInto(root) {
        if (!root) return;
        var active = document.activeElement;
        if (active && root.contains(active)) return;
        if (!lastFocused && active && active !== document.body) lastFocused = active;
        var list = focusables(root);
        var target = list.length ? list[0] : root;
        if (target === root && !root.hasAttribute('tabindex')) root.setAttribute('tabindex', '-1');
        try { target.focus(); } catch (e) {}
    }

    function restoreFocus() {
        var el = lastFocused;
        lastFocused = null;
        if (el && typeof el.focus === 'function' && document.contains && document.contains(el)) {
            try { el.focus(); } catch (e) {}
        }
    }

    function onKeydown(e) {
        var root = modalRoot();
        if (!root) return;
        if (e.key === 'Escape' || e.keyCode === 27) {
            if (root === getDrawer()) {
                closeDrawer();
            } else if (readChoice() || isInfoMode()) {
                // Information mode, or re-opened with an existing choice, may close.
                hideBanner();
            }
            return;
        }
        if (e.key !== 'Tab' && e.keyCode !== 9) return;
        var list = focusables(root);
        if (!list.length) {
            e.preventDefault();
            return;
        }
        var first = list[0];
        var last = list[list.length - 1];
        var active = document.activeElement;
        if (e.shiftKey && (active === first || !root.contains(active))) {
            e.preventDefault();
            try { last.focus(); } catch (err) {}
        } else if (!e.shiftKey && (active === last || !root.contains(active))) {
            e.preventDefault();
            try { first.focus(); } catch (err) {}
        }
    }

    // === OVERLAY / DRAWER ===
    function showOverlay() {
        var overlay = getOverlay();
        if (!overlay) return;
        overlay.removeAttribute('hidden');
        overlay.style.display = '';
    }

    function hideOverlay() {
        var overlay = getOverlay();
        if (!overlay) return;
        overlay.setAttribute('hidden', '');
        overlay.style.display = 'none';
    }

    function setExpanded(value) {
        if (typeof document.querySelectorAll !== 'function') return;
        var btns = document.querySelectorAll('[data-action="customize"]') || [];
        for (var i = 0; i < btns.length; i++) btns[i].setAttribute('aria-expanded', value ? 'true' : 'false');
    }

    function openDrawer() {
        var drawer = getDrawer();
        var banner = getBanner();
        if (!drawer) return;
        if (!lastFocused && document.activeElement) lastFocused = document.activeElement;
        if (banner) banner.style.display = 'none';
        drawer.removeAttribute('hidden');
        drawer.removeAttribute('aria-hidden');
        drawer.classList.add('is-open');
        showOverlay();
        setupTabs(drawer);
        bindRoot(drawer);
        prefillInputs(drawer);
        setExpanded(true);
        focusInto(drawer);
    }

    function closeDrawer() {
        var drawer = getDrawer();
        var banner = getBanner();
        if (drawer) {
            drawer.classList.remove('is-open');
            drawer.setAttribute('hidden', '');
            drawer.setAttribute('aria-hidden', 'true');
        }
        hideOverlay();
        setExpanded(false);
        if (banner && banner.classList.contains('trackwp-consent--visible')) {
            banner.style.display = '';
            focusInto(banner);
        } else {
            restoreFocus();
        }
    }

    function isDrawerOpen() {
        var drawer = getDrawer();
        return !!drawer && !drawer.hasAttribute('hidden');
    }

    function prefillInputs(context) {
        if (!context) return;
        var c = currentChoices();
        var statsEl = context.querySelector('#trackwp-consent-statistics');
        var mktEl = context.querySelector('#trackwp-consent-marketing');
        var personEl = context.querySelector('#trackwp-consent-personalisation');
        if (statsEl) statsEl.checked = c.statistics;
        if (mktEl) mktEl.checked = c.marketing;
        if (personEl) personEl.checked = c.personalisation;
    }

    // === BANNER UI ===
    function showBanner() {
        var banner = getBanner();
        if (!banner) return;
        var style = getBannerStyle();
        if (hideBannerTimer) {
            clearTimeout(hideBannerTimer);
            hideBannerTimer = null;
        }
        if (!lastFocused && document.activeElement && document.activeElement !== document.body) {
            lastFocused = document.activeElement;
        }
        banner.style.display = '';
        banner.removeAttribute('hidden');
        banner.removeAttribute('aria-hidden');
        if (style === 'bottombar' || style === 'info' || isInfoMode()) {
            hideOverlay();
        } else {
            showOverlay();
        }
        var mainActions = document.getElementById('trackwp-consent-actions-main');
        var categories = document.getElementById('trackwp-consent-categories');
        var detailActions = document.getElementById('trackwp-consent-actions-detail');
        if (mainActions) mainActions.style.display = '';
        if (categories) categories.style.display = 'none';
        if (detailActions) detailActions.style.display = 'none';
        setExpanded(false);
        if (style === 'cookiebot') setupTabs(banner);
        bindRoot(banner);
        prefillInputs(banner);
        renderStatus();
        setTimeout(function() {
            banner.classList.add('trackwp-consent--visible');
            if (banner.getAttribute('aria-modal') === 'true') focusInto(banner);
        }, 10);
    }

    function hideBanner() {
        var banner = getBanner();
        var drawer = getDrawer();
        if (banner) {
            banner.classList.remove('trackwp-consent--visible');
            banner.setAttribute('aria-hidden', 'true');
            if (hideBannerTimer) clearTimeout(hideBannerTimer);
            hideBannerTimer = setTimeout(function() {
                hideBannerTimer = null;
                banner.style.display = 'none';
            }, 300);
        }
        if (drawer) {
            drawer.classList.remove('is-open');
            drawer.setAttribute('hidden', '');
            drawer.setAttribute('aria-hidden', 'true');
        }
        hideOverlay();
        setExpanded(false);
        restoreFocus();
    }

    function showCustomize() {
        var mainActions = document.getElementById('trackwp-consent-actions-main');
        var categories = document.getElementById('trackwp-consent-categories');
        var detailActions = document.getElementById('trackwp-consent-actions-detail');
        if (mainActions) mainActions.style.display = 'none';
        if (categories) categories.style.display = '';
        if (detailActions) detailActions.style.display = '';
        setExpanded(true);
        var first = categories ? categories.querySelector('input:not([disabled])') : null;
        if (first) {
            try { first.focus(); } catch (e) {}
        }
    }

    // === DELEGATION (scoped to banner/drawer roots) ===
    function onRootClick(e) {
        if (!e.target || typeof e.target.closest !== 'function') return;
        var btn = e.target.closest('[data-action]');
        if (!btn || !e.currentTarget.contains(btn)) return;
        var action = btn.getAttribute('data-action');
        var drawer = getDrawer();

        // Info mode never writes a consent cookie from the banner buttons.
        if (isInfoMode() && (action === 'accept-all' || action === 'reject-all' || action === 'save')) {
            e.preventDefault();
            hideBanner();
            return;
        }

        if (action === 'accept-all') {
            e.preventDefault();
            applyChoice(acceptAllChoices());
        } else if (action === 'reject-all') {
            e.preventDefault();
            applyChoice({ statistics: false, marketing: false, personalisation: false });
        } else if (action === 'withdraw') {
            e.preventDefault();
            withdraw();
        } else if (action === 'customize') {
            e.preventDefault();
            if (getBannerStyle() === 'bottombar' && drawer) openDrawer(); else showCustomize();
        } else if (action === 'close') {
            e.preventDefault();
            if (isDrawerOpen()) closeDrawer(); else hideBanner();
        } else if (action === 'save') {
            e.preventDefault();
            var ctx = e.currentTarget;
            var statsEl = ctx.querySelector('#trackwp-consent-statistics');
            var mktEl = ctx.querySelector('#trackwp-consent-marketing');
            var personEl = ctx.querySelector('#trackwp-consent-personalisation');
            applyChoice({
                statistics: statsEl ? statsEl.checked : false,
                marketing: mktEl ? mktEl.checked : false,
                personalisation: personEl ? personEl.checked : false
            });
        }
    }

    function bindRoot(root) {
        if (!root || typeof root.addEventListener !== 'function') return;
        for (var i = 0; i < boundRoots.length; i++) {
            if (boundRoots[i] === root) return;
        }
        boundRoots.push(root);
        root.addEventListener('click', onRootClick);
    }

    function setupHandlers() {
        bindRoot(getBanner());
        bindRoot(getDrawer());
        // Extra roots (e.g. a status block on the privacy page) opt in explicitly.
        if (typeof document.querySelectorAll === 'function') {
            var extra = document.querySelectorAll('[data-trackwp-consent-root]') || [];
            for (var i = 0; i < extra.length; i++) bindRoot(extra[i]);
        }

        // Re-open triggers: only these selectors, nothing else on the page.
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            var trigger = e.target.closest('.trackwp-consent-trigger, .trackwp-consent-open, a[href="#trackwp-consent"]');
            if (!trigger) return;
            e.preventDefault();
            lastFocused = trigger;
            showBanner();
        });

        var overlay = getOverlay();
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target !== overlay) return;
                if (isDrawerOpen()) closeDrawer();
            });
        }

        document.addEventListener('keydown', onKeydown);
        renderStatus();
    }

    // === INIT ===
    function init() {
        var saved = readChoice();
        if (saved) {
            // Remove leftovers (e.g. a late keepalive Set-Cookie after a
            // withdraw in a closed tab) for categories the valid choice
            // refuses. Once now and once after KEEPALIVE_WAIT_MS, for a
            // keepalive answer from the previous page that lands late.
            cleanupRefused();
            setTimeout(cleanupRefused, KEEPALIVE_WAIT_MS);
        }
        if (saved || isInfoMode()) {
            // Consent Mode was restored inline by PHP (R3); nothing to re-apply.
            return;
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showBanner);
        } else {
            showBanner();
        }
    }

    // Expires only cookies that exist, for categories the CURRENT valid
    // choice refuses (re-read each time: the choice may change meanwhile).
    function cleanupRefused() {
        var c = readChoice();
        if (!c) return;
        var refused = { statistics: c.statistics !== true, marketing: c.marketing !== true };
        if (refused.statistics || refused.marketing) expireTrackingCookies(refused, true);
    }

    // trackwp.js reports a stale_version answer from /event.
    document.addEventListener('trackwp:consent_stale', function(e) {
        var detail = (e && e.detail) || {};
        handleStale(detail.current_version);
    });

    // === EXPOSE API ===
    window.trackwpConsent = {
        getState: currentChoices,
        showBanner: showBanner,
        setChoices: function(choices) { return applyChoice(choices || {}, false); },
        expireTrackingCookies: expireTrackingCookies,
        withdraw: withdraw,
        markStale: handleStale
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupHandlers);
    } else {
        setupHandlers();
    }
    init();

})(window, document);
