/**
 * TrackWP WooCommerce Integration
 *
 * Fires the shop funnel events through window.trackwp.sendEvent. All payloads
 * are built in PHP (see class-trackwp-woocommerce.php) and handed over in
 * window.trackwpWoo; this file only decides WHEN to send them.
 *
 * Consent: this file waits for the visitor's choice itself, through
 * window.trackwpConsentReader.read() and the trackwp:consent_updated event,
 * instead of relying on the generic pre-consent queue in trackwp.js. That
 * queue must not hold ecommerce items (PLAN-1.10.1-v4 K6), and the purchase
 * needs its signed order_ref and deterministic event_id intact. A rejection
 * sends nothing; a missing reader counts as "no choice yet".
 *
 * Page conditions: window.trackwp.passesPageConditions(name, 'woocommerce')
 * is asked before every event, so an admin's page conditions on a shop event
 * are honoured.
 *
 * The one thing PHP cannot pre-build is an AJAX add-to-cart, because no page
 * render happens. WooCommerce has two AJAX paths and they are handled
 * separately:
 *
 *   classic  jQuery 'added_to_cart' on document.body, triggered with
 *            (event, fragments, cart_hash, $button). PHP puts every line added
 *            in that request into fragments.trackwp_added; the old product-map
 *            lookup remains as the fallback.
 *   blocks   the Cart/Checkout blocks mutate the cart through the Store API,
 *            so responses from /wc/store/v1/cart/* are watched for quantity
 *            increases -- each one carries the full cart. The
 *            'wc-blocks_added_to_cart' DOM event is useless on its own (no
 *            product data), and window.wp.data is NOT available on a current
 *            install now that the blocks run on the Interactivity API.
 *
 * Both paths can be live on the same page (a classic AJAX add on a page that
 * also loads block assets fires the jQuery event AND invalidates the store),
 * so the classic path claims a short suppression window to stop the store
 * watcher counting the same add a second time.
 *
 * @since 2.0.0
 * @package TrackWP
 */
(function () {
    'use strict';

    var config = window.trackwpWoo || {};
    // Same rule as trackwp.js: debugAllowed from PHP (strict boolean) AND
    // ?trackwp_debug=1 in the URL.
    var debug = config.debugAllowed === true && debugParamSet();

    function debugParamSet() {
        try {
            var match = /[?&]trackwp_debug=([^&#]*)/.exec(window.location.search || '');
            return !!match && match[1] === '1';
        } catch (e) {
            return false;
        }
    }

    // How long the store watcher stays quiet after the classic path emitted.
    var SUPPRESS_MS = 3000;
    var suppressUntil = 0;

    function log() {
        if (!debug || typeof console === 'undefined' || !console.log) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[TrackWP/Woo]');
        console.log.apply(console, args);
    }

    // === Consent gate ===

    // The reader is the ONLY consent implementation (K3). Missing reader, an
    // invalid, stale or outdated cookie all read as null: no choice yet.
    function readChoice() {
        var reader = window.trackwpConsentReader;
        if (!reader || typeof reader.read !== 'function') return null;
        try {
            var choice = reader.read();
            return choice && typeof choice === 'object' ? choice : null;
        } catch (e) {
            return null;
        }
    }

    function allowsAny(choice) {
        return !!(choice && (choice.statistics === true || choice.marketing === true));
    }

    // Callbacks waiting for a choice. Bounded: a visitor who never answers the
    // banner must not grow this without limit.
    var waiting = [];
    var MAX_WAITING = 20;

    function whenConsented(fn) {
        var choice = readChoice();
        if (choice) {
            if (allowsAny(choice)) {
                fn(choice);
            } else {
                log('consent rejected, nothing sent');
            }
            return;
        }
        if (waiting.length < MAX_WAITING) {
            waiting.push(fn);
        }
    }

    function onConsentUpdated() {
        var choice = readChoice();
        if (!choice) return; // still no valid choice (e.g. stale): keep waiting
        var queued = waiting;
        waiting = [];
        if (!allowsAny(choice)) {
            log('consent rejected, dropped', queued.length, 'waiting event(s)');
            return;
        }
        for (var i = 0; i < queued.length; i++) {
            try {
                queued[i](choice);
            } catch (e) { /* one bad entry must not stop the rest */ }
        }
    }

    // === Page conditions ===

    function passesConditions(eventName) {
        var api = window.trackwp;
        if (!api || typeof api.passesPageConditions !== 'function') return true;
        try {
            return api.passesPageConditions(eventName, 'woocommerce') !== false;
        } catch (e) {
            return true;
        }
    }

    // === Sending ===

    function send(eventName, value, currency, items, extra, params, options) {
        if (!window.trackwp || typeof window.trackwp.sendEvent !== 'function') return false;
        if (!passesConditions(eventName)) {
            log('page conditions not met, skipped', eventName);
            return false;
        }

        var ecommerce = extra || {};
        if (items && items.length) {
            ecommerce.items = items;
        }

        params = params || {};
        params.value = value || 0;
        params.currency = currency || config.currency || 'DKK';
        // Only attach ecommerce when there is something in it: an empty object
        // would otherwise travel to the server on every event.
        if (hasAnyKey(ecommerce)) {
            params.ecommerce = ecommerce;
        }

        log('send', eventName, redactForLog(params), options || {});
        window.trackwp.sendEvent(eventName, params, options || {});
        return true;
    }

    // === Events PHP already resolved (view_item, begin_checkout, purchase,
    // and add_to_cart replayed from a non-AJAX add on the previous request) ===

    function fireImmediate() {
        var immediate = config.immediate;
        if (!immediate || !immediate.length) return;

        for (var i = 0; i < immediate.length; i++) {
            (function (entry) {
                if (!entry || !entry.event) return;
                whenConsented(function (choice) {
                    if (entry.event === 'purchase') {
                        firePurchase(entry, choice);
                        return;
                    }
                    var ecommerce = entry.ecommerce || {};
                    send(entry.event, entry.value, entry.currency, ecommerce.items, stripItems(ecommerce));
                });
            })(immediate[i]);
        }
    }

    // purchase: the server rebuilds the payload from the order and claims it
    // atomically, so it only needs the signed order_ref and the deterministic
    // event_id (shared with the Pixel, so Meta deduplicates Pixel and CAPI).
    //
    // A reload or back/forward navigation must not repeat the BROWSER tags
    // (gtag conversion, Pixel Purchase): the server claim cannot stop those.
    // The local store is checked BEFORE they fire and written only after the
    // send (R14), and only here, i.e. after consent.
    function firePurchase(entry, choice) {
        var ecommerce = entry.ecommerce || {};
        var transactionId = ecommerce.transaction_id;
        var alreadySent = !!(transactionId && alreadySentPurchase(transactionId));

        var params = {};
        if (entry.event_id) params.event_id = entry.event_id;
        if (entry.order_ref) params.order_ref = entry.order_ref;
        // Google Enhanced Conversions user_data, already hashed in PHP and
        // only present when customer data sharing is on. Marketing only.
        if (entry.ec && choice && choice.marketing === true) params.ec = entry.ec;

        var options = {};
        if (alreadySent) {
            // Let the server answer "duplicate" without re-firing the browser
            // tags, when trackwp.js supports it; otherwise send nothing.
            var features = window.trackwp && window.trackwp.features;
            if (!features || features.serverOnly !== true) {
                log('purchase skipped, already sent for order', transactionId);
                return;
            }
            options.serverOnly = true;
            log('purchase already sent for order', transactionId, '- server only');
        }

        var sent = send('purchase', entry.value, entry.currency, ecommerce.items, stripItems(ecommerce), params, options);
        if (sent && transactionId && !alreadySent) {
            rememberPurchase(transactionId);
        }
    }

    // Purchases already reported from this browser. Bounded, and every access
    // is wrapped: private mode and blocked site data make localStorage throw
    // rather than return null, and a tracking script must never break the page.
    var PURCHASE_STORE_KEY = 'trackwp_woo_purchases';
    var PURCHASE_STORE_MAX = 20;

    function readPurchaseStore() {
        try {
            var raw = window.localStorage.getItem(PURCHASE_STORE_KEY);
            if (!raw) return [];
            var parsed = JSON.parse(raw);
            return isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function alreadySentPurchase(transactionId) {
        var store = readPurchaseStore();
        for (var i = 0; i < store.length; i++) {
            if (String(store[i]) === String(transactionId)) return true;
        }
        return false;
    }

    function rememberPurchase(transactionId) {
        try {
            var store = readPurchaseStore();
            store.push(String(transactionId));
            if (store.length > PURCHASE_STORE_MAX) {
                store = store.slice(store.length - PURCHASE_STORE_MAX);
            }
            window.localStorage.setItem(PURCHASE_STORE_KEY, JSON.stringify(store));
        } catch (e) {
            // No storage available: the server-side claim still guarantees one
            // server-side purchase; only the browser tags can repeat.
        }
    }

    // Copy of an ecommerce object without `items`, so send() can re-attach the
    // items it was given without duplicating them.
    function stripItems(ecommerce) {
        var out = {};
        for (var key in ecommerce) {
            if (Object.prototype.hasOwnProperty.call(ecommerce, key) && key !== 'items') {
                out[key] = ecommerce[key];
            }
        }
        return out;
    }

    // === Classic AJAX add-to-cart ===

    function initClassicAddToCart() {
        if (config.addToCart !== true) return;
        if (typeof window.jQuery !== 'function') return;

        // WooCommerce triggers added_to_cart with [fragments, cart_hash,
        // $button]; jQuery puts the event object first.
        window.jQuery(document.body).on('added_to_cart', function (event, fragments, cartHash, $button) {
            var added = fragments && isArray(fragments.trackwp_added) ? fragments.trackwp_added : null;
            if (added && added.length) {
                suppressUntil = Date.now() + SUPPRESS_MS;
                for (var i = 0; i < added.length; i++) {
                    queueAdded(added[i]);
                }
                return;
            }

            // Fallback: no fragment (a theme or plugin that triggers the event
            // itself). Resolve the clicked button against the product map.
            var item = resolveFromButton($button);
            if (!item) {
                log('classic add_to_cart could not be resolved');
                return;
            }
            suppressUntil = Date.now() + SUPPRESS_MS;
            var value = round2((parseFloat(item.price) || 0) * item.quantity);
            whenConsented(function () {
                send('add_to_cart', value, config.currency, [item]);
            });
        });

        log('classic add-to-cart listener bound');
    }

    function queueAdded(entry) {
        if (!entry || !isArray(entry.items) || !entry.items.length) return;
        var value = parseFloat(entry.value);
        var currency = entry.currency || config.currency;
        whenConsented(function () {
            send('add_to_cart', isNaN(value) ? 0 : round2(value), currency, entry.items);
        });
    }

    // Resolve the clicked add-to-cart button against the product map PHP
    // emitted for this page. Variations are not resolvable this way: the loop
    // button carries the parent id only, which is why the fragment is primary.
    function resolveFromButton($button) {
        var products = config.products;
        if (!products || !$button || !$button.length) return null;

        var productId = $button.attr('data-product_id') || $button.data('product_id');
        if (!productId) return null;

        var template = products[String(productId)];
        if (!template) return null;

        var quantity = parseInt($button.attr('data-quantity'), 10);
        if (!(quantity > 0)) quantity = 1;

        // Copy so repeated adds do not mutate the shared map entry.
        var item = {};
        for (var key in template) {
            if (Object.prototype.hasOwnProperty.call(template, key)) {
                item[key] = template[key];
            }
        }
        item.quantity = quantity;
        return item;
    }

    // === Cart/Checkout blocks: watch the Store API for quantity increases ===
    //
    // The blocks mutate the cart through /wp-json/wc/store/v1/cart/*, and every
    // one of those responses is the FULL cart. Watching the responses is a far
    // more durable contract than reading a JS global: WooCommerce has moved the
    // Cart and Checkout blocks onto the Interactivity API, and on a current
    // install window.wp.data / wc.wcBlocksData are simply not defined.
    // Verified against WooCommerce 11 / WordPress 7.1.
    //
    // The baseline comes from PHP (config.cart), so the very first add is a real
    // delta instead of being swallowed as "the initial state".

    var cartBaseline = null;

    function initStoreApiWatcher() {
        if (config.addToCart !== true) return;
        if (typeof window.fetch !== 'function') return;

        cartBaseline = normaliseBaseline(config.cart);

        var originalFetch = window.fetch;
        window.fetch = function () {
            var promise = originalFetch.apply(this, arguments);
            var url = urlOf(arguments[0]);
            if (!isCartEndpoint(url)) {
                return promise;
            }
            // The caller must get the untouched response back; only a clone is
            // read, and any failure here is swallowed so tracking can never
            // break a checkout.
            return promise.then(function (response) {
                try {
                    if (response && response.ok && typeof response.clone === 'function') {
                        response.clone().json().then(handleCartResponse).catch(function () {});
                    }
                } catch (e) { /* ignore */ }
                return response;
            });
        };

        log('store-api watcher bound, baseline', cartBaseline);
    }

    function urlOf(input) {
        try {
            if (typeof input === 'string') return input;
            if (input && typeof input.url === 'string') return input.url;
        } catch (e) { /* ignore */ }
        return '';
    }

    function isCartEndpoint(url) {
        return !!url && url.indexOf('/wc/store/v1/cart') !== -1;
    }

    function normaliseBaseline(raw) {
        var out = {};
        if (!raw) return out;
        for (var key in raw) {
            if (!Object.prototype.hasOwnProperty.call(raw, key)) continue;
            var quantity = parseInt(raw[key], 10);
            out[key] = quantity > 0 ? quantity : 0;
        }
        return out;
    }

    function handleCartResponse(data) {
        if (!data || !isArray(data.items)) return;

        var minorUnit = minorUnitOf(data);
        var currency = (data.totals && data.totals.currency_code) || config.currency;

        var snapshot = {};
        var lines = {};
        for (var i = 0; i < data.items.length; i++) {
            var line = data.items[i];
            if (!line) continue;
            var key = line.key || String(line.id);
            var quantity = parseInt(line.quantity, 10);
            snapshot[key] = quantity > 0 ? quantity : 0;
            lines[key] = line;
        }

        if (cartBaseline === null) {
            cartBaseline = snapshot;
            return;
        }

        var items = [];
        var value = 0;
        for (var cartKey in snapshot) {
            if (!Object.prototype.hasOwnProperty.call(snapshot, cartKey)) continue;
            var previous = Object.prototype.hasOwnProperty.call(cartBaseline, cartKey) ? cartBaseline[cartKey] : 0;
            // Report the DELTA, not the new total: adding a second unit of
            // something already in the cart is an add_to_cart of 1, not of 2.
            var delta = snapshot[cartKey] - previous;
            if (delta <= 0) continue;

            var item = itemFromCartLine(lines[cartKey], minorUnit);
            if (!item) continue;
            item.quantity = delta;
            items.push(item);
            value += (item.price || 0) * delta;
        }

        cartBaseline = snapshot;

        if (!items.length) return;

        if (Date.now() < suppressUntil) {
            log('store-api change suppressed (classic path already counted it)');
            return;
        }

        var total = round2(value);
        whenConsented(function () {
            send('add_to_cart', total, currency, items);
        });
    }

    // Store API money values are integer strings in the currency's MINOR unit,
    // with the exponent in currency_minor_unit: "10000" with minor_unit 2 is
    // 100.00. Treating them as major units would inflate every value 100x.
    function minorUnitOf(data) {
        var raw = data.totals && data.totals.currency_minor_unit;
        var minorUnit = parseInt(raw, 10);
        if (isNaN(minorUnit) || minorUnit < 0) return 2;
        return minorUnit;
    }

    // Mirrors TrackWP_WooCommerce::add_price_and_discount() exactly: price is
    // the DISCOUNTED unit price (line total / quantity, tax per value basis),
    // discount the per-unit reduction from the line subtotal.
    function itemFromCartLine(line, minorUnit) {
        if (!line) return null;

        var quantity = parseInt(line.quantity, 10);
        if (!(quantity > 0)) quantity = 1;

        // Prefer the line totals: they carry per-line tax, which lets this path
        // apply the SAME value basis as the PHP-built payloads. prices.price
        // follows the shop's cart display setting instead.
        var totals = line.totals || {};
        var subtotal = money(totals.line_subtotal, minorUnit);
        var subtotalTax = money(totals.line_subtotal_tax, minorUnit);
        var lineTotal = money(totals.line_total, minorUnit);
        var lineTotalTax = money(totals.line_total_tax, minorUnit);

        var price;
        var discount = 0;

        if (subtotal !== null) {
            var includeTax = config.includeTax === true;
            var grossSubtotal = includeTax ? subtotal + (subtotalTax || 0) : subtotal;
            var grossTotal;
            if (lineTotal === null) {
                grossTotal = grossSubtotal;
            } else {
                grossTotal = includeTax ? lineTotal + (lineTotalTax || 0) : lineTotal;
            }
            price = round2(Math.max(0, grossTotal) / quantity);
            var reduction = grossSubtotal - grossTotal;
            if (reduction > 0) {
                discount = round2(reduction / quantity);
            }
        } else {
            // Older Store API responses without line totals.
            var fallback = money((line.prices || {}).price, minorUnit);
            price = fallback === null ? 0 : fallback;
        }

        // item_id must follow the same source as the PHP-built payloads, or GA4
        // sees the same product under two different ids.
        var itemId = String(line.id || '');
        if (config.itemIdSource === 'sku' && line.sku) {
            itemId = String(line.sku);
        }

        var item = {
            item_id: itemId,
            item_name: line.name || '',
            quantity: 1,
            price: price
        };
        if (discount > 0) {
            item.discount = discount;
        }

        // The Store API carries no product categories, so item_category only
        // appears on the PHP-built payloads.
        if (!item.item_id && !item.item_name) return null;
        return item;
    }

    // Store API money values are integer strings in the currency's MINOR unit.
    // Returns null when the field is absent, so callers can fall back.
    function money(raw, minorUnit) {
        if (raw === undefined || raw === null || raw === '') return null;
        var parsed = parseFloat(raw);
        if (isNaN(parsed)) return null;
        return parsed / Math.pow(10, minorUnit);
    }

    // === Utilities ===

    // Debug output never contains the Enhanced Conversions hashes or the
    // signed order reference.
    function redactForLog(params) {
        var out = {};
        for (var key in params) {
            if (!Object.prototype.hasOwnProperty.call(params, key)) continue;
            if (key === 'ec' || key === 'order_ref') continue;
            out[key] = params[key];
        }
        if (out.ecommerce && typeof out.ecommerce === 'object' && out.ecommerce.order_ref !== undefined) {
            out.ecommerce = stripKey(out.ecommerce, 'order_ref');
        }
        return out;
    }

    function stripKey(object, skip) {
        var out = {};
        for (var key in object) {
            if (Object.prototype.hasOwnProperty.call(object, key) && key !== skip) {
                out[key] = object[key];
            }
        }
        return out;
    }

    function isArray(value) {
        return Object.prototype.toString.call(value) === '[object Array]';
    }

    function hasAnyKey(object) {
        for (var key in object) {
            if (Object.prototype.hasOwnProperty.call(object, key)) return true;
        }
        return false;
    }

    function round2(value) {
        var number = parseFloat(value);
        if (isNaN(number)) return 0;
        return Math.round(number * 100) / 100;
    }

    // === Init ===

    var initialized = false;

    function init() {
        if (initialized) return;
        initialized = true;

        document.addEventListener('trackwp:consent_updated', onConsentUpdated);
        fireImmediate();
        initClassicAddToCart();
        initStoreApiWatcher();
    }

    // Exposed for the node test sandbox only; not a public API.
    window.trackwpWooInternals = {
        itemFromCartLine: itemFromCartLine,
        readChoice: readChoice
    };

    // trackwp.js loads deferred, so window.trackwp may not exist yet. Same
    // handshake as class-trackwp-forms.php uses.
    if (window.trackwp && typeof window.trackwp.sendEvent === 'function') {
        init();
    } else {
        document.addEventListener('trackwp:ready', init, { once: true });
    }
})();
