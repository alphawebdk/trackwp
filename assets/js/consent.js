(function() {
    'use strict';

    // Consent Mode v2 defaults are emitted by PHP (render_consent_mode_defaults
    // on wp_head pri 1) so they run BEFORE GTM. Here we only ensure window.gtag
    // exists as defence-in-depth before we call gtag('consent','update',...).
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
        window.gtag = function() { window.dataLayer.push(arguments); };
    }

    // === CONFIG ===
    var config = window.trackwpConsentConfig || {};
    var cookieName = 'trackwp_consent';
    var cookieLifetimeDays = (parseInt(config.cookieLifetimeMonths, 10) || 12) * 30;
    var consentVersion = parseInt(config.consentVersion, 10) || 1;
    var logConsent = config.log_consent || false;
    var restUrl = config.restUrl || '';

    // === STATE ===
    var currentChoices = { necessary: true, statistics: false, marketing: false, personalisation: false };
    var tabsInitialized = []; // Track which roots have had tabs wired up (avoid double-binding)
    var hideBannerTimer = null; // Pending display:none from hideBanner (cleared on re-show)

    // === COOKIE HELPERS ===
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() +
            ';path=/;SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');
    }

    // === CONSENT COOKIE ===
    function readConsent() {
        var raw = getCookie(cookieName);
        if (!raw) return null;
        try {
            var data = JSON.parse(raw);
            if (data && data.v === consentVersion) {
                return data;
            }
            return null; // version mismatch — re-ask
        } catch(e) {
            return null;
        }
    }

    function saveConsent(choices) {
        var data = {
            v: consentVersion,
            ts: new Date().toISOString(),
            necessary: true,
            statistics: !!choices.statistics,
            marketing: !!choices.marketing,
            personalisation: !!choices.personalisation
        };
        setCookie(cookieName, JSON.stringify(data), cookieLifetimeDays);
        currentChoices = {
            necessary: true,
            statistics: data.statistics,
            marketing: data.marketing,
            personalisation: data.personalisation
        };
    }

    // === CONSENT MODE V2 UPDATE ===
    function updateConsentMode(choices) {
        gtag('consent', 'update', {
            'analytics_storage': choices.statistics ? 'granted' : 'denied',
            'ad_storage': choices.marketing ? 'granted' : 'denied',
            'ad_user_data': choices.marketing ? 'granted' : 'denied',
            'ad_personalization': choices.marketing ? 'granted' : 'denied',
            'functionality_storage': choices.personalisation ? 'granted' : 'denied',
            'personalization_storage': choices.personalisation ? 'granted' : 'denied',
            'security_storage': 'granted'
        });

        // Fire custom event for trackwp.js
        var event;
        try {
            event = new CustomEvent('trackwp:consent_updated', { detail: choices });
        } catch(e) {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent('trackwp:consent_updated', true, true, choices);
        }
        document.dispatchEvent(event);
    }

    // === LOG CONSENT ===
    function logConsentChoice(choices, eventType) {
        if (!logConsent || !restUrl) return;
        try {
            var body = {
                statistics: !!choices.statistics,
                marketing: !!choices.marketing,
                personalisation: !!choices.personalisation
            };
            if (eventType) body.event_type = eventType;
            fetch(restUrl + 'trackwp/v1/consent-log', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                keepalive: true
            }).catch(function() {});
        } catch(e) {}
    }

    // === STYLE DETECTION ===
    function getBannerStyle() {
        var root = document.getElementById('trackwp-consent-banner');
        return root ? (root.getAttribute('data-style') || 'dialog') : 'dialog';
    }

    // === TABS (cookiebot + bottombar-drawer) ===
    function setupTabs(root) {
        if (!root) return;
        var tabs = root.querySelectorAll('.trackwp-consent__tab');
        if (!tabs || !tabs.length) return; // dialog-style has no tabs

        // Avoid double-binding on the same root
        for (var i = 0; i < tabsInitialized.length; i++) {
            if (tabsInitialized[i] === root) return;
        }
        tabsInitialized.push(root);

        var panels = root.querySelectorAll('[data-panel]');

        function activate(tab) {
            var target = tab.getAttribute('data-tab');
            for (var t = 0; t < tabs.length; t++) {
                var isActive = (tabs[t] === tab);
                tabs[t].setAttribute('aria-selected', isActive ? 'true' : 'false');
                if (isActive) {
                    tabs[t].classList.add('is-active');
                } else {
                    tabs[t].classList.remove('is-active');
                }
            }
            for (var p = 0; p < panels.length; p++) {
                var panel = panels[p];
                if (panel.getAttribute('data-panel') === target) {
                    panel.classList.add('is-active');
                    panel.removeAttribute('hidden');
                } else {
                    panel.classList.remove('is-active');
                    panel.setAttribute('hidden', '');
                }
            }
        }

        for (var k = 0; k < tabs.length; k++) {
            (function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    activate(tab);
                });
            })(tabs[k]);
        }
    }

    // === DRAWER HELPERS (bottombar) ===
    function getDrawer() {
        return document.getElementById('trackwp-consent-drawer');
    }

    function getOverlay() {
        return document.getElementById('trackwp-consent-overlay');
    }

    function getBanner() {
        return document.getElementById('trackwp-consent-banner');
    }

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

    function openDrawer() {
        var drawer = getDrawer();
        var banner = getBanner();
        if (!drawer) return;

        // Hide the bar while drawer is open
        if (banner) banner.style.display = 'none';

        // Show drawer + overlay
        drawer.removeAttribute('hidden');
        drawer.classList.add('is-open');
        showOverlay();

        // Init tabs in drawer
        setupTabs(drawer);

        // Pre-fill checkboxes in drawer from currentChoices
        prefillInputs(drawer);

        // Focus first interactive element
        var focusable = drawer.querySelector('.trackwp-consent__tab.is-active, .trackwp-consent__tab, input:not([disabled])');
        if (focusable && typeof focusable.focus === 'function') {
            try { focusable.focus(); } catch(e) {}
        }
    }

    function closeDrawer() {
        var drawer = getDrawer();
        var banner = getBanner();
        if (drawer) {
            drawer.classList.remove('is-open');
            drawer.setAttribute('hidden', '');
        }
        hideOverlay();
        // Re-show the bar so the user can still see the bottom prompt
        if (banner) banner.style.display = '';
    }

    function isDrawerOpen() {
        var drawer = getDrawer();
        if (!drawer) return false;
        return !drawer.hasAttribute('hidden');
    }

    // === PRE-FILL CHECKBOXES ===
    function prefillInputs(context) {
        if (!context) return;
        var statsEl = context.querySelector('#trackwp-consent-statistics');
        var mktEl = context.querySelector('#trackwp-consent-marketing');
        var personEl = context.querySelector('#trackwp-consent-personalisation');
        if (statsEl) statsEl.checked = !!currentChoices.statistics;
        if (mktEl) mktEl.checked = !!currentChoices.marketing;
        if (personEl) personEl.checked = !!currentChoices.personalisation;
    }

    // === BANNER UI ===
    function showBanner() {
        var banner = getBanner();
        var overlay = getOverlay();
        if (!banner) return;

        var style = getBannerStyle();

        // Cancel a pending hideBanner display:none so it can't hide a
        // banner that was just re-opened within the fade-out window.
        if (hideBannerTimer) {
            clearTimeout(hideBannerTimer);
            hideBannerTimer = null;
        }

        banner.style.display = '';

        // Bottombar: bar only, no overlay (overlay belongs to drawer)
        // Cookiebot / dialog: show overlay too
        if (style === 'bottombar') {
            hideOverlay();
        } else {
            if (overlay) {
                overlay.removeAttribute('hidden');
                overlay.style.display = '';
            }
        }

        // Dialog-style Level 1/Level 2 reset (legacy markup with main/categories/detail blocks)
        var mainActions = document.getElementById('trackwp-consent-actions-main');
        var categories = document.getElementById('trackwp-consent-categories');
        var detailActions = document.getElementById('trackwp-consent-actions-detail');
        if (mainActions) mainActions.style.display = '';
        if (categories) categories.style.display = 'none';
        if (detailActions) detailActions.style.display = 'none';

        // Cookiebot-style: init tabs on the banner root (categories are inline; no level-toggle)
        if (style === 'cookiebot') {
            setupTabs(banner);
        }

        // Pre-fill checkboxes with current consent state on the visible root.
        // For bottombar the categories live in the drawer (not the bar), so only
        // prefill bar inputs if any exist there.
        prefillInputs(banner);

        // Add animation class
        setTimeout(function() {
            banner.classList.add('trackwp-consent--visible');
        }, 10);
    }

    function hideBanner() {
        var banner = getBanner();
        var overlay = getOverlay();
        var drawer = getDrawer();

        if (banner) {
            banner.classList.remove('trackwp-consent--visible');
            if (hideBannerTimer) clearTimeout(hideBannerTimer);
            hideBannerTimer = setTimeout(function() {
                hideBannerTimer = null;
                banner.style.display = 'none';
            }, 300);
        }
        if (drawer) {
            drawer.classList.remove('is-open');
            drawer.setAttribute('hidden', '');
        }
        if (overlay) {
            overlay.setAttribute('hidden', '');
            overlay.style.display = 'none';
        }
    }

    function showCustomize() {
        var mainActions = document.getElementById('trackwp-consent-actions-main');
        var categories = document.getElementById('trackwp-consent-categories');
        var detailActions = document.getElementById('trackwp-consent-actions-detail');
        if (mainActions) mainActions.style.display = 'none';
        if (categories) categories.style.display = '';
        if (detailActions) detailActions.style.display = '';
    }

    // === APPLY CONSENT ===
    function applyConsent(choices) {
        saveConsent(choices);
        updateConsentMode(choices);
        logConsentChoice(choices);
        hideBanner();
    }

    // === INIT ===
    function init() {
        // Check existing consent
        var saved = readConsent();
        if (saved) {
            currentChoices = {
                necessary: true,
                statistics: !!saved.statistics,
                marketing: !!saved.marketing,
                personalisation: !!saved.personalisation
            };
            updateConsentMode(currentChoices);
            return; // Don't show banner
        }

        // No valid consent — show banner on DOMContentLoaded or immediately
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showBanner);
        } else {
            showBanner();
        }
    }

    // === BUTTON HANDLERS ===
    function setupHandlers() {
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            var btn = e.target.closest('[data-action]');
            if (!btn) return;

            var action = btn.getAttribute('data-action');
            var style = getBannerStyle();
            var drawer = getDrawer();

            if (action === 'accept-all') {
                applyConsent({ statistics: true, marketing: true, personalisation: true });
            } else if (action === 'reject-all') {
                applyConsent({ statistics: false, marketing: false, personalisation: false });
            } else if (action === 'customize') {
                if (style === 'bottombar' && drawer) {
                    openDrawer();
                } else {
                    showCustomize();
                }
            } else if (action === 'save') {
                // For bottombar with drawer open, read inputs from drawer; otherwise from banner root.
                var formContext = (drawer && isDrawerOpen()) ? drawer : getBanner();
                if (!formContext) formContext = document;

                var statsEl = formContext.querySelector('#trackwp-consent-statistics');
                var mktEl = formContext.querySelector('#trackwp-consent-marketing');
                var personEl = formContext.querySelector('#trackwp-consent-personalisation');
                applyConsent({
                    statistics: statsEl ? statsEl.checked : false,
                    marketing: mktEl ? mktEl.checked : false,
                    personalisation: personEl ? personEl.checked : false
                });
            }
        });

        // Re-open banner via footer link / floating trigger
        document.addEventListener('click', function(e) {
            if (!e.target || typeof e.target.closest !== 'function') return;
            var trigger = e.target.closest('.trackwp-consent-trigger, .trackwp-consent-open, a[href="#trackwp-consent"]');
            if (!trigger) return;
            e.preventDefault();
            showBanner();
        });

        // Overlay click closes drawer (bottombar)
        document.addEventListener('click', function(e) {
            var overlay = getOverlay();
            if (!overlay) return;
            if (e.target !== overlay) return;
            if (isDrawerOpen()) {
                closeDrawer();
            }
        });

        // ESC closes drawer
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape' && e.keyCode !== 27) return;
            if (isDrawerOpen()) {
                closeDrawer();
            }
        });
    }

    // === EXPOSE API ===
    window.trackwpConsent = {
        getState: function() {
            return {
                necessary: true,
                statistics: currentChoices.statistics,
                marketing: currentChoices.marketing,
                personalisation: currentChoices.personalisation
            };
        },
        showBanner: showBanner
    };

    // Run
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupHandlers);
    } else {
        setupHandlers();
    }
    init();

})();
