/**
 * node:vm sandbox for loading TrackWP's browser assets (assets/js/*.js) in
 * `node --test`, without a real browser. The scripts under test are plain
 * IIFEs that read `document`, `window`, `navigator.sendBeacon`, `gtag`,
 * `fbq`, `sessionStorage`/`localStorage` and `crypto.subtle` as globals, so
 * the sandbox's context object IS those globals (vm.createContext turns the
 * object passed to it into the context's global object).
 *
 * Owned by W0 (tests/js/helpers/*). Feature suites
 * (tests/js/*.test.mjs, owned by W5/W3) import createSandbox() and never
 * duplicate this DOM/cookie-jar logic.
 */

import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const PLUGIN_ROOT = path.resolve(__dirname, '..', '..', '..');

/**
 * A cookie jar with real Path/Expires/overwrite semantics (RFC 6265 subset):
 *  - a cookie is keyed by (name, path); setting the same name at the same
 *    path overwrites it; setting it at a DIFFERENT path creates a second,
 *    coexisting cookie (this is real browser behaviour, and TrackWP relies
 *    on it never happening by always writing Path=/ -- tests can use this
 *    jar to catch a regression that writes a path-scoped duplicate).
 *  - Expires (and Max-Age, converted to an absolute time using the jar's
 *    clock) in the past deletes the cookie immediately, matching how a
 *    browser reacts to `document.cookie = 'x=; expires=<past>'`.
 *  - `read(requestPath)` only returns cookies whose Path matches
 *    requestPath per RFC 6265 5.1.4 (equal, or requestPath is a
 *    Path-prefixed sub-path with a following '/').
 */
export class CookieJar {
    constructor() {
        /** @type {Map<string, {name:string,value:string,path:string,expires:Date|null,domain:string|null,secure:boolean,sameSite:string|null,httpOnly:boolean}>} */
        this.cookies = new Map();
        this.now = () => new Date();
    }

    setNow(fn) {
        this.now = fn;
    }

    _key(name, cookiePath) {
        return `${name}\u0000${cookiePath}`;
    }

    _pathMatches(cookiePath, requestPath) {
        if (requestPath === cookiePath) return true;
        if (requestPath.startsWith(cookiePath)) {
            if (cookiePath.endsWith('/')) return true;
            if (requestPath[cookiePath.length] === '/') return true;
        }
        return false;
    }

    /** Parses one `document.cookie = "..."` assignment string. */
    _parseSetString(str) {
        const parts = str.split(';').map((s) => s.trim()).filter(Boolean);
        const nameValue = parts.shift() || '';
        const eqIdx = nameValue.indexOf('=');
        const name = eqIdx === -1 ? nameValue : nameValue.slice(0, eqIdx);
        const rawValue = eqIdx === -1 ? '' : nameValue.slice(eqIdx + 1);

        const attrs = { path: '/', expires: null, domain: null, secure: false, sameSite: null, httpOnly: false };
        for (const part of parts) {
            const eq = part.indexOf('=');
            const key = (eq === -1 ? part : part.slice(0, eq)).toLowerCase();
            const value = eq === -1 ? '' : part.slice(eq + 1);
            if (key === 'path') attrs.path = value || '/';
            else if (key === 'expires') attrs.expires = new Date(value);
            else if (key === 'max-age') attrs.expires = new Date(this.now().getTime() + parseInt(value, 10) * 1000);
            else if (key === 'domain') attrs.domain = value;
            else if (key === 'secure') attrs.secure = true;
            else if (key === 'samesite') attrs.sameSite = value;
            else if (key === 'httponly') attrs.httpOnly = true;
        }
        return { name, value: rawValue, attrs };
    }

    set(setCookieString) {
        const { name, value, attrs } = this._parseSetString(setCookieString);
        const key = this._key(name, attrs.path);
        if (attrs.expires && attrs.expires.getTime() <= this.now().getTime()) {
            this.cookies.delete(key);
            return;
        }
        this.cookies.set(key, { name, value, ...attrs });
    }

    read(requestPath = '/') {
        const out = [];
        for (const cookie of this.cookies.values()) {
            if (cookie.expires && cookie.expires.getTime() <= this.now().getTime()) continue;
            if (!this._pathMatches(cookie.path, requestPath)) continue;
            out.push(`${cookie.name}=${cookie.value}`);
        }
        return out.join('; ');
    }

    get(name, requestPath = '/') {
        for (const cookie of this.cookies.values()) {
            if (cookie.name !== name) continue;
            if (cookie.expires && cookie.expires.getTime() <= this.now().getTime()) continue;
            if (!this._pathMatches(cookie.path, requestPath)) continue;
            return cookie;
        }
        return null;
    }

    clear() {
        this.cookies.clear();
    }
}

function makeStorage() {
    const map = new Map();
    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => map.set(String(k), String(v)),
        removeItem: (k) => map.delete(k),
        clear: () => map.clear(),
        key: (i) => Array.from(map.keys())[i] ?? null,
        get length() {
            return map.size;
        },
        __map: map,
    };
}

/**
 * @param {object} opts
 * @param {string[]} opts.scripts   Filenames under assets/js/ to load, in order.
 * @param {string} [opts.url]       Page URL the sandbox pretends to be on.
 * @param {object} [opts.config]    { consent: window.trackwpConsentConfig, trackwp: window.trackwpConfig }
 * @param {() => Date} [opts.now]   Clock used by the cookie jar (for expiry tests).
 * @returns {{ window: object, jar: CookieJar, spies: object, storage: () => object }}
 */
export function createSandbox({ scripts = [], url = 'https://example.org/', config = {}, now } = {}) {
    const jar = new CookieJar();
    if (now) jar.setNow(now);

    const parsedUrl = new URL(url);

    const spies = {
        fetch: [],
        sendBeacon: [],
        gtag: [],
        fbq: [],
    };

    const eventListeners = new Map();

    const documentObj = {
        get cookie() {
            return jar.read(parsedUrl.pathname);
        },
        set cookie(v) {
            jar.set(v);
        },
        location: parsedUrl,
        referrer: '',
        title: '',
        visibilityState: 'visible',
        hidden: false,
        readyState: 'complete',
        addEventListener(type, fn) {
            if (!eventListeners.has(type)) eventListeners.set(type, []);
            eventListeners.get(type).push(fn);
        },
        removeEventListener(type, fn) {
            const arr = eventListeners.get(type) || [];
            const idx = arr.indexOf(fn);
            if (idx >= 0) arr.splice(idx, 1);
        },
        dispatchEvent(evt) {
            const arr = eventListeners.get(evt.type) || [];
            arr.slice().forEach((fn) => fn(evt));
            return true;
        },
        createElement: () => ({
            setAttribute() {},
            getAttribute() {
                return null;
            },
            appendChild() {},
            addEventListener() {},
            style: {},
        }),
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
        body: { appendChild() {} },
        documentElement: { lang: 'da' },
    };

    const fakeFetch = (...args) => {
        spies.fetch.push(args);
        return Promise.resolve({
            ok: true,
            status: 200,
            headers: new Map(),
            json: async () => ({}),
            text: async () => '',
        });
    };

    const windowObj = {
        document: documentObj,
        location: parsedUrl,
        navigator: {
            sendBeacon: (...args) => {
                spies.sendBeacon.push(args);
                return true;
            },
            userAgent: 'trackwp-test-sandbox',
        },
        sessionStorage: makeStorage(),
        localStorage: makeStorage(),
        fetch: fakeFetch,
        gtag: (...args) => {
            spies.gtag.push(args);
        },
        fbq: (...args) => {
            spies.fbq.push(args);
        },
        dataLayer: [],
        performance: { now: () => Date.now() },
        crypto: crypto.webcrypto,
        trackwpConsentConfig: config.consent || {},
        trackwpConfig: config.trackwp || {},
        URL,
        URLSearchParams,
        console,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        addEventListener: documentObj.addEventListener.bind(documentObj),
        removeEventListener: documentObj.removeEventListener.bind(documentObj),
        dispatchEvent: documentObj.dispatchEvent.bind(documentObj),
    };
    windowObj.window = windowObj;
    windowObj.self = windowObj;
    windowObj.globalThis = windowObj;

    const context = vm.createContext(windowObj);

    for (const scriptName of scripts) {
        const file = path.join(PLUGIN_ROOT, 'assets', 'js', scriptName);
        const code = fs.readFileSync(file, 'utf8');
        vm.runInContext(code, context, { filename: file });
    }

    return { window: context, document: documentObj, jar, spies, storage: makeStorage };
}
