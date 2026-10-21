'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

async function runTracker(cookie) {
  const events = [];
  const windowListeners = {};
  const documentListeners = {};
  const cookieWrites = [];
  let uuidCounter = 0;
  const window = {
    WPAnalyzConfig: {
      endpoint: 'https://example.test/wp-json/wp-analyz/v1/event',
      productId: 42,
      categoryId: 0,
      isSearch: true
    },
    location: { origin: 'https://example.test', pathname: '/products/item/', href: 'https://example.test/products/item/', protocol: 'https:' },
    crypto: { randomUUID: () => {
      uuidCounter++;
      return '550e8400-e29b-41d4-a716-' + String(uuidCounter).padStart(12, '0');
    } },
    innerHeight: 800,
    scrollY: 0,
    addEventListener: (name, fn) => { (windowListeners[name] ||= []).push(fn); }
  };
  const document = {
    referrer: '',
    visibilityState: 'visible',
    documentElement: { scrollHeight: 1000 },
    addEventListener: (name, fn) => { (documentListeners[name] ||= []).push(fn); },
    cookie
  };
  Object.defineProperty(document, 'cookie', {
    get: () => cookie,
    set: (value) => cookieWrites.push(String(value))
  });
  const navigator = {
    userAgent: 'Mozilla/5.0 Chrome/130.0',
    sendBeacon: (url, blob) => { events.push({ url, blob }); return true; }
  };
  const context = { window, document, navigator, URL, URLSearchParams, Blob, Uint8Array, console, Math, Object, Array, isFinite, fetch: () => Promise.resolve() };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('assets/js/tracker-basic.js', 'utf8'), context);
  return { events, window, document, cookieWrites, windowListeners, documentListeners };
}

(async function () {
  const run = await runTracker('');
  assert.strictEqual(run.events.length, 3, 'records aggregate page, product, and search-result counts');
  const clickTarget = {
    matches: (selector) => selector.indexOf('add_to_cart_button') !== -1,
    getAttribute: (name) => name === 'data-product_id' ? '42' : null,
    value: '',
    closest: function () { return this; }
  };
  run.documentListeners.click[0]({ target: clickTarget });
  assert.strictEqual(run.events.length, 4, 'records aggregate add-to-cart interaction');
  const cartPayload = JSON.parse(await run.events[3].blob.text());
  assert.strictEqual(cartPayload.event, 'add_to_cart', 'cart event is recorded');
  assert.strictEqual(cartPayload.object_id, 42, 'cart event is attributed to the product without a visitor ID');
  assert.strictEqual(run.cookieWrites.length, 0, 'does not write cookies');
  assert.strictEqual(typeof run.window.wpAnalyzConsent, 'undefined', 'does not expose the advanced consent API');
  const payloads = await Promise.all(run.events.map(async (event) => JSON.parse(await event.blob.text())));
  assert(payloads.every((payload) => payload.mode === 'aggregate'), 'all events use aggregate mode');
  assert(payloads.every((payload) => !('visitor_id' in payload) && !('session_id' in payload)), 'never sends visitor or session identifiers');
  assert(payloads.every((payload) => !('referrer' in payload) && !('title' in payload) && !('data' in payload)), 'does not send title, referrer, or search term data');
  assert.strictEqual(payloads[0].url, 'https://example.test/products/item/', 'does not send query parameters or fragments');
  assert.strictEqual(payloads[1].object_id, 42, 'records product view as an aggregate product dimension');

  const denied = await runTracker('wpa_consent=denied');
  assert.strictEqual(denied.events.length, 0, 'respects an existing explicit denial');
  assert.strictEqual(denied.cookieWrites.length, 0, 'does not write cookies even when denied');
  console.log('All WP Analyz aggregate-only tracker tests passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
