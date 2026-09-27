/**
 * Mock receiver for GA4 Measurement Protocol, Meta Conversions API and
 * Google Ads click-upload, used by Playwright e2e specs
 * (tests/e2e/*.spec.mjs) instead of real platform endpoints. Outbound WP
 * HTTP calls are redirected here by
 * tests/docker/wp/mu-plugins/trackwp-test-mockplatforms.php.
 *
 * Endpoints:
 *   POST /ga4/mp/collect          -> logged as destination "ga4"
 *   POST /ga4/debug/mp/collect    -> logged as destination "ga4"
 *   POST /meta/capi               -> logged as destination "meta"
 *   POST /google-ads/upload       -> logged as destination "google_ads"
 *   GET    /__log?destination=x   -> JSON array of received requests
 *   DELETE /__log                 -> clears the log
 *   PUT    /__config              -> body {destination, mode, statusCode?, delayMs?, retryAfter?}
 *                                     mode: "ok" (default) | "timeout" | "5xx" | "429"
 *   GET    /__config              -> current per-destination config
 *   DELETE /__config              -> resets all configs to "ok"
 *
 * No dependencies: plain Node http, so the Docker image stays a one-line
 * node:alpine COPY with no npm install step.
 */
import http from 'node:http';

const PORT = process.env.PORT ? Number(process.env.PORT) : 8090;

const ROUTES = {
  '/ga4/mp/collect': 'ga4',
  '/ga4/debug/mp/collect': 'ga4',
  '/meta/capi': 'meta',
  '/google-ads/upload': 'google_ads',
};

const DEFAULT_CONFIG = { mode: 'ok', statusCode: null, delayMs: 0, retryAfter: null };

/** @type {Array<object>} */
let log = [];
/** @type {Record<string, {mode: string, statusCode: number|null, delayMs: number, retryAfter: number|null}>} */
let config = {
  ga4: { ...DEFAULT_CONFIG },
  meta: { ...DEFAULT_CONFIG },
  google_ads: { ...DEFAULT_CONFIG },
};

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function sendJson(res, status, body, headers = {}) {
  const payload = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload), ...headers });
  res.end(payload);
}

function successBody(destination) {
  if (destination === 'ga4') {
    return { body: '', status: 204 };
  }
  if (destination === 'meta') {
    return { body: JSON.stringify({ events_received: 1, messages: [], fbtrace_id: 'trackwp-mock' }), status: 200 };
  }
  // google_ads
  return { body: JSON.stringify({ results: [{}], partialFailureError: null }), status: 200 };
}

async function handlePlatformRequest(req, res, destination, url) {
  const bodyText = await readBody(req);
  let parsedBody = bodyText;
  try {
    parsedBody = JSON.parse(bodyText);
  } catch {
    // GA4 accepts non-JSON in some edge cases; keep the raw text.
  }

  const entry = {
    ts: new Date().toISOString(),
    destination,
    method: req.method,
    path: url.pathname,
    query: Object.fromEntries(url.searchParams),
    headers: req.headers,
    body: parsedBody,
  };
  log.push(entry);

  const cfg = config[destination] || DEFAULT_CONFIG;

  if (cfg.delayMs > 0) {
    await new Promise((r) => setTimeout(r, cfg.delayMs));
  }

  if (cfg.mode === 'timeout') {
    // Hold the connection open well past TrackWP's 4s per-request budget
    // (K1) so the adapter under test experiences a real timeout, not a
    // fast error.
    await new Promise((r) => setTimeout(r, 10000));
    res.destroy();
    return;
  }

  if (cfg.mode === '5xx') {
    sendJson(res, cfg.statusCode || 500, { error: 'mock 5xx' });
    return;
  }

  if (cfg.mode === '429') {
    const headers = {};
    if (cfg.retryAfter !== null) {
      headers['Retry-After'] = String(cfg.retryAfter);
    }
    sendJson(res, 429, { error: 'mock 429' }, headers);
    return;
  }

  const { body, status } = successBody(destination);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

async function handleLog(req, res, url) {
  if (req.method === 'GET') {
    const destination = url.searchParams.get('destination');
    const result = destination ? log.filter((e) => e.destination === destination) : log;
    sendJson(res, 200, result);
    return;
  }
  if (req.method === 'DELETE') {
    log = [];
    sendJson(res, 200, { cleared: true });
    return;
  }
  sendJson(res, 405, { error: 'method not allowed' });
}

async function handleConfig(req, res, url) {
  if (req.method === 'GET') {
    sendJson(res, 200, config);
    return;
  }
  if (req.method === 'DELETE') {
    for (const dest of Object.keys(config)) {
      config[dest] = { ...DEFAULT_CONFIG };
    }
    sendJson(res, 200, config);
    return;
  }
  if (req.method === 'PUT') {
    const bodyText = await readBody(req);
    let payload;
    try {
      payload = JSON.parse(bodyText);
    } catch {
      sendJson(res, 400, { error: 'invalid JSON body' });
      return;
    }
    const { destination, mode = 'ok', statusCode = null, delayMs = 0, retryAfter = null } = payload;
    if (!destination || !(destination in config)) {
      sendJson(res, 400, { error: `unknown destination "${destination}"` });
      return;
    }
    config[destination] = { mode, statusCode, delayMs, retryAfter };
    sendJson(res, 200, config[destination]);
    return;
  }
  sendJson(res, 405, { error: 'method not allowed' });
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

  if (url.pathname === '/__log') {
    handleLog(req, res, url).catch((err) => sendJson(res, 500, { error: String(err) }));
    return;
  }
  if (url.pathname === '/__config') {
    handleConfig(req, res, url).catch((err) => sendJson(res, 500, { error: String(err) }));
    return;
  }
  if (url.pathname === '/__health') {
    sendJson(res, 200, { ok: true });
    return;
  }

  const destination = ROUTES[url.pathname];
  if (destination) {
    handlePlatformRequest(req, res, destination, url).catch((err) => sendJson(res, 500, { error: String(err) }));
    return;
  }

  sendJson(res, 404, { error: `no route for ${url.pathname}` });
});

server.listen(PORT, () => {
  console.log(`mockplatforms listening on :${PORT}`);
});
