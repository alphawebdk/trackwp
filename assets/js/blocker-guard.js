/**
 * TrackWP blocker guard (1.11.0, KB10 / PLAN-1.11.0-v2 §3.2 and §11).
 *
 * Inlined by PHP in the priority-1 head script after the consent reader.
 * Input: window.trackwpBlockerConfig = TrackWP_Blocker_Rules::client_rules()
 *   {v, home, urls: [[hostPathPrefix, cat, ruleId]], hosts: {host: [cat, ruleId]},
 *    pixels: [...], never: {paths: ['/prefix/'], urls: [hostPathPrefix], hosts: [host]}}
 * (also accepted wrapped as {rules: {...}}). exceptions.allow is compiled
 * into never by PHP, so never and allow share one priority (S11).
 *
 * Consent is read ONLY through trackwpConsentReader.read(); no cookie access.
 * Only SCRIPT and IFRAME nodes whose src matches a blocked rule in a category
 * without consent are held; everything else goes straight to the original
 * DOM methods. DOMContentLoaded is never re-dispatched (documented limit).
 */
(function(window, document) {
    'use strict';
    var Node = window.Node;
    if (window.trackwpBlocker || !Node || !Node.prototype || !document) return;

    var cfg = window.trackwpBlockerConfig || {};
    var R = cfg.rules || cfg;
    var never = R.never || {};
    var CATS = ['statistics', 'marketing', 'personalisation'];
    var BLOCKED = 'data-twp-blocked';
    var CAT = 'data-twp-category';
    var SRC = 'data-twp-src';
    var TYPE = 'data-twp-type';
    var DONE = 'data-twp-released';
    var WAIT_MS = 10000;
    // S10: WHATWG JavaScript MIME type essences (incl. legacy) plus module.
    var JS_TYPES = ' application/ecmascript application/javascript application/x-ecmascript application/x-javascript ' +
        'text/ecmascript text/javascript text/javascript1.0 text/javascript1.1 text/javascript1.2 text/javascript1.3 ' +
        'text/javascript1.4 text/javascript1.5 text/jscript text/livescript text/x-ecmascript text/x-javascript module ';

    // Object map {key: [a, b]} or list [[key, a, b]] -> list.
    function list(x) {
        var out = [], k;
        if (!x) return out;
        if (Object.prototype.toString.call(x) === '[object Array]') return x;
        for (k in x) {
            if (Object.prototype.hasOwnProperty.call(x, k)) out.push([k].concat(x[k]));
        }
        return out;
    }
    // SCRIPT and IFRAME match url: and host: rules only; pixel: is for IMG (PHP match()).
    var URLS = list(R.urls);
    var HOSTS = list(R.hosts);

    function trim(s) { return String(s == null ? '' : s).replace(/^\s+|\s+$/g, ''); }

    // S3, mirrors TrackWP_Blocker_Rules::normalize_url() exactly (no URL
    // API, so percent-encoding and IDN cannot differ from PHP).
    function split(u) {
        var m = /^([A-Za-z][A-Za-z0-9+.\-]*):\/\/([^\/?#]*)([^?#]*)/.exec(u), a, sc, host, port;
        if (!m) return null;
        sc = m[1].toLowerCase();
        if (sc !== 'http' && sc !== 'https') return null;
        a = m[2].slice(m[2].lastIndexOf('@') + 1);
        a = /^(\[[^\]]*\]|[^:]*)(?::(\d*))?$/.exec(a);
        if (!a || !a[1]) return null;
        host = a[1].toLowerCase();
        port = (a[2] || '').replace(/^0+/, '');
        if ((sc === 'http' && port === '80') || (sc === 'https' && port === '443')) port = '';
        return { sc: sc, o: sc + '://' + host + (port ? ':' + port : ''), h: host, hp: host + (port ? ':' + port : ''), p: dots(m[3] || '/') };
    }
    // RFC 3986 5.2.4 for a path starting with '/'.
    function dots(path) {
        if (path.charAt(0) !== '/') path = '/' + path;
        var inp = path.slice(1).split('/'), out = [], i, seg, last;
        for (i = 0; i < inp.length; i++) {
            seg = inp[i];
            last = i === inp.length - 1;
            if (seg === '.') { if (last) out.push(''); }
            else if (seg === '..') { out.pop(); if (last) out.push(''); }
            else out.push(seg);
        }
        return '/' + out.join('/');
    }
    var home = split(trim(cfg.home || R.home || '')) || split(window.location.href);
    if (!home) return;

    function norm(u) {
        u = String(u == null ? '' : u).replace(/^[ \t\n\f\r]+|[ \t\n\f\r]+$/g, '');
        if (!u) return null;
        var p, b, ref;
        if (/^[A-Za-z][A-Za-z0-9+.\-]*:/.test(u)) p = split(u);
        else if (u.slice(0, 2) === '//') p = split(home.sc + ':' + u);
        else if (u.charAt(0) === '/') p = split(home.o + u);
        else {
            b = split(String(document.baseURI || '')) || home;
            ref = u.replace(/[?#][\s\S]*$/, '');
            p = split(b.o + (ref === '' ? b.p : b.p.slice(0, b.p.lastIndexOf('/') + 1) + ref));
        }
        return p ? { h: p.h, hp: p.hp, s: p.hp + p.p, p: p.p } : null;
    }

    function onHost(h, r) {
        r = String(r).toLowerCase();
        return h === r || (h.length > r.length && h.slice(-r.length - 1) === '.' + r);
    }
    function anyHost(h, arr) {
        for (var i = 0; arr && i < arr.length; i++) if (onHost(h, arr[i])) return true;
        return false;
    }
    function anyPrefix(s, arr) {
        for (var i = 0; arr && i < arr.length; i++) if (arr[i] && s.indexOf(arr[i]) === 0) return true;
        return false;
    }

    // KB6/S11 order: never and allow, then url/pixel (longest prefix), then host.
    function match(url) {
        var n = norm(url);
        if (!n) return null;
        // MINOR 3 / KB8: never paths only on the site's own host[:port], as in PHP list_hits().
        if ((n.hp === home.hp && anyPrefix(n.p, never.paths)) || anyPrefix(n.s, never.urls) || anyHost(n.h, never.hosts)) return null;
        var best = null, i, r;
        for (i = 0; i < URLS.length; i++) {
            r = URLS[i];
            if (r[0] && n.s.indexOf(r[0]) === 0 && (!best || r[0].length > best[0].length)) best = r;
        }
        if (!best) {
            for (i = 0; i < HOSTS.length; i++) {
                r = HOSTS[i];
                if (onHost(n.h, r[0]) && (!best || r[0].length > best[0].length)) best = r;
            }
        }
        if (!best) return null;
        return { rule: best[2], category: best[1] };
    }

    function consented(cat) {
        if (cat === 'necessary') return true;
        var reader = window.trackwpConsentReader, c = null;
        try { c = reader && reader.read ? reader.read() : null; } catch (e) { c = null; }
        return !!(c && c[cat] === true);
    }

    function isJs(type) {
        if (type == null) return true;
        type = trim(type).toLowerCase();
        return type === '' || JS_TYPES.indexOf(' ' + type + ' ') !== -1;
    }

    function tagOf(el) { return el && el.nodeType === 1 ? String(el.tagName).toUpperCase() : ''; }

    // Returns the match for a SCRIPT/IFRAME that must be held now, else null.
    function blockedMatch(el) {
        var tag = tagOf(el);
        if (tag !== 'SCRIPT' && tag !== 'IFRAME') return null;
        if (el === inserting || el.getAttribute(BLOCKED) || el.getAttribute(DONE)) return null;
        if (tag === 'SCRIPT' && !isJs(el.getAttribute('type'))) return null;
        var m = match(el.getAttribute('src'));
        return m && !consented(m.category) ? m : null;
    }

    // === INTERCEPTION (S8: the ORIGINAL element is held, never recreated) ===
    var proto = Node.prototype;
    var origAppend = proto.appendChild;
    var origInsert = proto.insertBefore;
    var held = [];
    var inserting = null;
    var isReleasing = false;
    var pendingCats = [];

    function hold(el, parent, ref) {
        var m = blockedMatch(el);
        if (!m) return false;
        el.setAttribute(BLOCKED, m.rule);
        el.setAttribute(CAT, m.category);
        held.push({ el: el, parent: parent, ref: ref });
        return true;
    }

    proto.appendChild = function(node) {
        return hold(node, this, null) ? node : origAppend.call(this, node);
    };
    proto.insertBefore = function(node, ref) {
        return hold(node, this, ref || null) ? node : origInsert.call(this, node, ref);
    };

    // Backup for nodes that reached the DOM another way (parser, innerHTML).
    function neutralize(el) {
        var m = blockedMatch(el);
        if (!m) return;
        var src = el.getAttribute('src');
        if (tagOf(el) === 'SCRIPT') {
            var t = el.getAttribute('type');
            if (t) el.setAttribute(TYPE, t);
            el.setAttribute('type', 'text/plain');
        }
        el.setAttribute(SRC, src);
        el.removeAttribute('src');
        el.setAttribute(BLOCKED, m.rule);
        el.setAttribute(CAT, m.category);
    }

    function scan(node) {
        if (!node || node.nodeType !== 1) return;
        neutralize(node);
        // M5: while the document is loading the observer only neutralizes;
        // release happens at DOMContentLoaded (one pass, document order).
        var cat = node.getAttribute(BLOCKED) && !node.getAttribute(DONE) ? node.getAttribute(CAT) : null;
        if (cat && document.readyState !== 'loading' && consented(cat)) release(cat);
        var kids = node.childNodes || [];
        for (var i = 0; i < kids.length; i++) scan(kids[i]);
    }

    if (typeof window.MutationObserver === 'function' && document.documentElement) {
        new window.MutationObserver(function(records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes || [];
                for (var j = 0; j < added.length; j++) scan(added[j]);
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    // === RELEASE (S7, S9, S19) ===
    function recreate(old) {
        var s = document.createElement('script');
        var attrs = old.attributes || [];
        for (var i = 0; i < attrs.length; i++) {
            var a = attrs[i].name;
            if (a === 'type' || a === 'src' || a === 'async' || a === 'defer' || a === 'nonce' || a.indexOf('data-twp-') === 0) continue;
            s.setAttribute(a, attrs[i].value);
        }
        // M4: under a CSP header the browser hides the nonce attribute; the
        // IDL property still holds it.
        var nonce = old.nonce || old.getAttribute('nonce');
        if (nonce) s.nonce = nonce;
        var t = old.getAttribute(TYPE);
        if (t) s.setAttribute('type', t);
        var src = old.getAttribute(SRC);
        if (src) {
            s.async = false;
            s.src = src;
        } else {
            s.text = old.text || old.textContent || '';
        }
        s.setAttribute(DONE, '1');
        old.setAttribute(DONE, '1');
        return s;
    }

    // S19: the node being inserted by release() is never evaluated again.
    function put(node, parent, ref, old) {
        inserting = node;
        try {
            if (old) parent.replaceChild(node, old);
            else origInsert.call(parent, node, ref);
        } finally {
            inserting = null;
        }
    }

    // ONE chain for the whole page (S7, M5): an inline script waits for all
    // external scripts released before it, also across observer batches and
    // release() calls. Consent is re-read per element; an element whose
    // category lost consent is dropped from the chain and, if it was held,
    // goes back to `held` so a later grant still releases it (M3).
    // `gen` invalidates the counts of scripts that hit the 10 s cap, so a
    // hanging script delays the chain once, not every later inline script.
    var queue = [], pending = 0, gen = 0, timer = null, stepping = false;
    // A modern browser neither runs nor fires load/error for nomodule scripts.
    var HSE = window.HTMLScriptElement;
    var NOMODULE = !!(HSE && HSE.prototype && 'noModule' in HSE.prototype);

    function settle(g) {
        if (g !== gen || --pending > 0) return;
        if (timer !== null) { clearTimeout(timer); timer = null; }
        step();
    }
    // Only called after the element was actually inserted.
    function watch(el) {
        if (NOMODULE && el.getAttribute('nomodule') !== null) return;
        pending++;
        var fired = false, g = gen;
        function once() { if (!fired) { fired = true; settle(g); } }
        el.addEventListener('load', once);
        el.addEventListener('error', once);
    }
    function step() {
        if (stepping) return;
        stepping = true;
        try {
            while (queue.length) {
                var it = queue[0], el = it.el, tag = tagOf(el);
                if (!consented(it.cat)) {
                    queue.shift();
                    el.twpQueued = false;
                    if (it.held) held.push(it);
                    continue;
                }
                if (!it.held && tag === 'SCRIPT' && !el.getAttribute(SRC) && pending > 0) {
                    if (timer === null) timer = setTimeout(function() { timer = null; pending = 0; gen++; step(); }, WAIT_MS);
                    return;
                }
                queue.shift();
                el.twpQueued = false;
                if (it.held) {
                    el.removeAttribute(BLOCKED);
                    el.removeAttribute(CAT);
                    el.setAttribute(DONE, '1');
                    put(el, it.parent, it.ref && it.ref.parentNode === it.parent ? it.ref : null, null);
                    if (tag === 'SCRIPT') watch(el);
                } else if (tag === 'SCRIPT') {
                    var s = recreate(el);
                    if (el.parentNode) {
                        put(s, el.parentNode, null, el);
                        if (el.getAttribute(SRC)) watch(s);
                    }
                } else {
                    el.setAttribute('src', el.getAttribute(SRC));
                    el.removeAttribute(SRC);
                    el.setAttribute(DONE, '1');
                }
            }
        } finally {
            stepping = false;
        }
    }

    function release(cat) {
        if (isReleasing) {
            pendingCats.push(cat);
            return;
        }
        if (!cat || !consented(cat)) return;
        isReleasing = true;
        try {
            var i, el;
            var nodes = document.querySelectorAll('[' + BLOCKED + ']');
            for (i = 0; i < nodes.length; i++) {
                el = nodes[i];
                if (el.getAttribute(CAT) === cat && !el.getAttribute(DONE) && !el.twpQueued) {
                    el.twpQueued = true;
                    queue.push({ el: el, cat: cat });
                }
            }
            for (i = 0; i < held.length; i++) {
                if (held[i].el.getAttribute(CAT) === cat) {
                    held[i].held = true;
                    held[i].cat = cat;
                    held[i].el.twpQueued = true;
                    queue.push(held.splice(i--, 1)[0]);
                }
            }
            step();
        } finally {
            isReleasing = false;
        }
        if (pendingCats.length) release(pendingCats.shift());
    }

    function releaseConsented() {
        for (var i = 0; i < CATS.length; i++) if (consented(CATS[i])) release(CATS[i]);
    }

    document.addEventListener('trackwp:consent_updated', releaseConsented);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', releaseConsented);
    else releaseConsented();

    // normalize is exposed so the shared S3 URL vectors can be run in JS.
    window.trackwpBlocker = { v: 1, match: match, release: release, normalize: norm, active: true };
})(window, document);
