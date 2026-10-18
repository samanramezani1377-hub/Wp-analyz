'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

(async function () {
  const events = [];
  const windowListeners = {};
  const documentListeners = {};
  const cookieWrites = [];
  const session = new Map();
  const local = new Map();
  let uuidCounter = 0;
  const storage = (map) => ({
    getItem: (key) => map.has(key) ? map.get(key) : null,
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key)
  });
  const window = {
    WPAnalyzConfig: {
      endpoint: 'https://example.test/wp-json/wp-analyz/v1/event',
      requireConsent: true,
      baselineAnalytics: true,
      persistentVisitor: false,
      productId: 0,
      categoryId: 0,
      searchTerm: ''
    },
    location: { origin: 'https://example.test', pathname: '/products/item/', search: '?email=private', protocol: 'https:' },
    crypto: { randomUUID: () => {
      uuidCounter++;
      const tail = String(uuidCounter).padStart(12, '0');
      return '550e8400-e29b-41d4-a716-' + tail;
    } },
    sessionStorage: storage(session),
    localStorage: storage(local),
    innerHeight: 800,
    scrollY: 0,
    addEventListener: (name, fn) => { (windowListeners[name] ||= []).push(fn); }
  };
  const document = {
    title: 'Test product',
    referrer: '',
    visibilityState: 'visible',
    documentElement: { scrollHeight: 1000 },
    addEventListener: (name, fn) => { (documentListeners[name] ||= []).push(fn); },
    cookie: ''
  };
  Object.defineProperty(document, 'cookie', {
    get: () => cookieWrites[cookieWrites.length - 1] || '',
    set: (value) => cookieWrites.push(String(value))
  });
  const navigator = {
    userAgent: 'Mozilla/5.0 Chrome/130.0',
    sendBeacon: (url, blob) => { events.push({ url, blob }); return true; }
  };
  const context = { window, document, navigator, URL, URLSearchParams, Blob, Uint8Array, console, Math, Object, Array, isFinite };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('assets/js/tracker.js', 'utf8'), context);

  assert.strictEqual(events.length, 1, 'records only a minimal aggregate page view before consent');
  assert.strictEqual(session.size, 0, 'does not access sessionStorage before consent');
  assert.strictEqual(local.size, 0, 'does not access localStorage before consent');
  assert.strictEqual(cookieWrites.length, 0, 'does not set cookies before consent');
  window.wpAnalyzConsent('granted');
  assert.strictEqual(events.length, 1, 'does not duplicate page view after consent is granted');
  assert(cookieWrites.some((value) => value.startsWith('wpa_consent=granted')), 'writes consent cookie only after grant');
  assert(cookieWrites.some((value) => value.startsWith('wpa_visitor_id=')), 'writes visitor cookie for WooCommerce attribution');
  assert(cookieWrites.some((value) => value.startsWith('wpa_session_id=')), 'writes session cookie for WooCommerce attribution');
  const listenerCount = Object.values(windowListeners).reduce((n, list) => n + list.length, 0) + Object.values(documentListeners).reduce((n, list) => n + list.length, 0);

  window.wpAnalyzConsent('denied');
  assert(cookieWrites.some((value) => value.startsWith('wpa_consent=denied')), 'persists rejection in the essential consent-preference cookie');
  window.wpAnalyzConsent('granted');
  assert.strictEqual(events.length, 1, 'does not duplicate page view after revoke and re-grant on same page');
  const listenerCountAfterRegrant = Object.values(windowListeners).reduce((n, list) => n + list.length, 0) + Object.values(documentListeners).reduce((n, list) => n + list.length, 0);
  assert.strictEqual(listenerCountAfterRegrant, listenerCount, 'does not register duplicate listeners after re-grant');

  const body = await events[0].blob.text();
  const payload = JSON.parse(body);
  assert.strictEqual(payload.event, 'page_view', 'sends a page_view event');
  assert.strictEqual(payload.mode, 'aggregate', 'uses aggregate mode before consent');
  assert.strictEqual(payload.url, 'https://example.test/products/item/', 'does not send URL query parameters');
  assert.strictEqual(Object.prototype.hasOwnProperty.call(payload, 'visitor_id'), false, 'does not send a visitor ID before consent');
  assert.strictEqual(Object.prototype.hasOwnProperty.call(payload, 'session_id'), false, 'does not send a session ID before consent');
  assert.strictEqual(Object.prototype.hasOwnProperty.call(payload, 'referrer'), false, 'does not send referrer before consent');
  console.log('All WP Analyz tracker smoke tests passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
