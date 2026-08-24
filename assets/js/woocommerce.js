/**
 * TrackWP WooCommerce Integration
 *
 * Fires the shop funnel events through window.trackwp.sendEvent. All payloads
 * are built in PHP (see class-trackwp-woocommerce.php) and handed over in
 * window.trackwpWoo; this file only decides WHEN to send them.
 *
 * The one thing PHP cannot pre-build is an AJAX add-to-cart, because no page
 * render happens. WooCommerce has two AJAX paths and they are handled
 * separately:
 *
 *   classic  jQuery 'added_to_cart' on document.body, resolved against the
 *            product map PHP emitted for the products on this page.
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
    var debug = !!config.debug;

    // How long the store watcher stays quiet after the classic path emitted.
    var SUPPRESS_MS = 3000;
    var suppressUntil = 0;

    function log() {
        if (!debug || typeof console === 'undefined' || !console.log) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[TrackWP/Woo]');
        console.log.apply(console, args);
    }

    function send(eventName, value, currency, items, extra) {
        if (!window.trackwp || typeof window.trackwp.sendEvent !== 'function') return;

        var ecommerce = extra || {};
        if (items && items.length) {
            ecommerce.items = items;
        }

        var params = {
            value: value || 0,
            currency: currency || config.currency || 'DKK'
        };
        // Only attach ecommerce when there is something in it: an empty object
        // would otherwise travel to the server on every event.
        if (hasAnyKey(ecommerce)) {
            params.ecommerce = ecommerce;
        }

        log('send', eventName, params);
        window.trackwp.sendEvent(eventName, params);
    }

    // === Events PHP already resolved (view_item, begin_checkout, purchase,
    // and add_to_cart replayed from a non-AJAX add on the previous request) ===

    function fireImmediate() {
        var immediate = config.immediate;
        if (!immediate || !immediate.length) return;

        for (var i = 0; i < immediate.length; i++) {
            var entry = immediate[i];
            if (!entry || !entry.event) continue;
            var ecommerce = entry.ecommerce || {};

            // The server-side order flag cannot stop a purchase that the
            // BROWSER replays from its own HTTP cache: WooCommerce serves the
            // order-received page with a short max-age, so a reload or a
            // back/forward navigation can re-render the same payload without
            // the server ever being asked. GA4 and Google Ads deduplicate on
            // transaction_id, but Meta deduplicates on event_id, which is
            // regenerated per send — so the sale would be counted twice there.
            if (entry.event === 'purchase') {
                var transactionId = ecommerce.transaction_id;
                if (transactionId && alreadySentPurchase(transactionId)) {
                    log('purchase skipped, already sent for order', transactionId);
                    continue;
                }
                if (transactionId) {
                    rememberPurchase(transactionId);
                }
            }

            send(
                entry.event,
                entry.value,
                entry.currency,
                ecommerce.items,
                // transaction_id / coupon travel alongside items.
                stripItems(ecommerce)
            );
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
            // No storage available: the server-side order flag is still the
            // primary guard, this is only the cache-replay backstop.
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
        if (!config.addToCart) return;
        if (typeof window.jQuery !== 'function') return;

        window.jQuery(document.body).on('added_to_cart', function (event, fragments, cartHash, $button) {
            var item = resolveFromButton($button);
            if (!item) {
                // Unresolved: let the store watcher handle it if the blocks
                // data store is present, since it has the real cart contents.
                log('classic add_to_cart could not be resolved from the button');
                return;
            }

            suppressUntil = Date.now() + SUPPRESS_MS;

            var value = round2((parseFloat(item.price) || 0) * item.quantity);
            send('add_to_cart', value, config.currency, [item]);
        });

        log('classic add-to-cart listener bound');
    }

    // Resolve the clicked add-to-cart button against the product map PHP
    // emitted for this page. Variations are not resolvable this way: the loop
    // button carries the parent id only, which is why the single-product form
    // POST path is handled server-side instead.
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
    // install window.wp.data / wc.wcBlocksData are simply not defined -- an
    // earlier version of this file depended on them and would have fired
    // nothing at all. Verified against WooCommerce 11 / WordPress 7.1.
    //
    // The baseline comes from PHP (config.cart), so the very first add is a real
    // delta instead of being swallowed as "the initial state".

    var cartBaseline = null;

    function initStoreApiWatcher() {
        if (!config.addToCart) return;
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

        send('add_to_cart', round2(value), currency, items);
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

    function itemFromCartLine(line, minorUnit) {
        if (!line) return null;

        var quantity = parseInt(line.quantity, 10);
        if (!(quantity > 0)) quantity = 1;

        // Prefer the line totals: they carry per-line tax, which lets this path
        // apply the SAME value basis as the PHP-built payloads. prices.price
        // follows the shop's cart display setting instead, so using it made
        // add_to_cart disagree with begin_checkout for the same product.
        var totals = line.totals || {};
        var subtotal = money(totals.line_subtotal, minorUnit);
        var subtotalTax = money(totals.line_subtotal_tax, minorUnit);
        var lineTotal = money(totals.line_total, minorUnit);
        var lineTotalTax = money(totals.line_total_tax, minorUnit);

        var price;
        var discount = 0;

        if (subtotal !== null) {
            var includeTax = !!config.includeTax;
            var grossSubtotal = includeTax ? subtotal + (subtotalTax || 0) : subtotal;
            var grossTotal = includeTax
                ? (lineTotal === null ? grossSubtotal : lineTotal + (lineTotalTax || 0))
                : (lineTotal === null ? grossSubtotal : lineTotal);
            price = round2(grossSubtotal / quantity);
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

        fireImmediate();
        initClassicAddToCart();
        initStoreApiWatcher();
    }

    // trackwp.js loads async, so window.trackwp may not exist yet. Same
    // handshake as class-trackwp-forms.php uses.
    if (window.trackwp && typeof window.trackwp.sendEvent === 'function') {
        init();
    } else {
        document.addEventListener('trackwp:ready', init, { once: true });
    }
})();
