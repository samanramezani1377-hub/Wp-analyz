(function () {
  'use strict';

  var config = window.WPAnalyzConfig || {};
  if (!config.endpoint) return;

  var started = false;
  var initialized = false;
  var denied = false;
  var sentPageView = false;
  var consentKey = 'wpa_consent_v1';
  var visitorKey = 'wpa_visitor_v1';
  var sessionKey = 'wpa_session_v1';
  var observedMetrics = {};
  var memoryIds = Object.create(null);

  function storageGet(storage, key) {
    try { return storage.getItem(key); } catch (e) { return null; }
  }
  function storageSet(storage, key, value) {
    try { storage.setItem(key, value); } catch (e) { /* Storage may be blocked. */ }
  }
  function storageRemove(storage, key) {
    try { storage.removeItem(key); } catch (e) { /* Storage may be blocked. */ }
  }
  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    var bytes = new Uint8Array(16);
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') window.crypto.getRandomValues(bytes);
    else for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    var hex = Array.from(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }
  function getId(storage, key) {
    if (memoryIds[key]) return memoryIds[key];
    var value = storageGet(storage, key);
    if (!value || !/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value)) {
      value = uuid();
      storageSet(storage, key, value);
    }
    memoryIds[key] = value;
    return value;
  }
  function visitorStorage() {
    if (config.persistentVisitor) {
      try { return window.localStorage; } catch (e) { /* Fall back to session scope. */ }
    }
    return window.sessionStorage;
  }
  function browserFamily() {
    var ua = navigator.userAgent || '';
    if (/Edg\//.test(ua)) return 'edge';
    if (/Firefox\//.test(ua)) return 'firefox';
    if (/Chrome\//.test(ua)) return 'chrome';
    if (/Safari\//.test(ua)) return 'safari';
    return 'other';
  }
  function deviceType() {
    var ua = navigator.userAgent || '';
    if (/iPad|Tablet|PlayBook/i.test(ua)) return 'tablet';
    return /Mobi|Android|iPhone|iPod/i.test(ua) ? 'mobile' : 'desktop';
  }
  function safePageUrl() {
    // Never transmit query strings or fragments: these can contain personal data.
    return window.location.origin + window.location.pathname;
  }
  function externalReferrer() {
    try {
      var ref = new URL(document.referrer);
      return ref.origin === window.location.origin ? '' : ref.origin;
    } catch (e) { return ''; }
  }
  function campaignData() {
    var result = {};
    try {
      var params = new URLSearchParams(window.location.search);
      ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (key) {
        var value = params.get(key);
        if (value) result[key] = value.slice(0, 120);
      });
    } catch (e) { /* URLSearchParams may be unavailable in old browsers. */ }
    return result;
  }
  function send(eventName, data, objectId) {
    if (!started || denied) return;
    var payload = {
      event_id: uuid(),
      visitor_id: getId(visitorStorage(), visitorKey),
      session_id: getId(window.sessionStorage, sessionKey),
      event: eventName,
      url: safePageUrl(),
      referrer: externalReferrer(),
      title: (document.title || '').slice(0, 255),
      device: deviceType(),
      browser: browserFamily(),
      object_id: Number(objectId) || 0,
      data: Object.assign({}, campaignData(), data || {})
    };
    var body;
    try { body = JSON.stringify(payload); } catch (e) { return; }
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }))) return;
      fetch(config.endpoint, {
        method: 'POST', body: body,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin', mode: 'same-origin', keepalive: true
      }).catch(function () {});
    } catch (e) { /* Analytics must never break the page. */ }
  }
  function setCookie(name, value) {
    try {
      var cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
      if (window.location.protocol === 'https:') cookie += '; Secure';
      document.cookie = cookie;
    } catch (e) { /* Cookies may be disabled by browser policy. */ }
  }
  function clearCookie(name) {
    try {
      var cookie = name + '=; path=/; Max-Age=0; SameSite=Lax';
      if (window.location.protocol === 'https:') cookie += '; Secure';
      document.cookie = cookie;
    } catch (e) { /* Cookies may be disabled by browser policy. */ }
  }
  function syncConsentCookies() {
    var visitor = getId(visitorStorage(), visitorKey);
    var session = getId(window.sessionStorage, sessionKey);
    setCookie('wpa_consent', config.requireConsent ? 'granted' : 'active');
    setCookie('wpa_visitor_id', visitor);
    setCookie('wpa_session_id', session);
  }
  function reportMetric(name, value) {
    if (typeof value !== 'number' || !isFinite(value) || value < 0 || observedMetrics[name] === value) return;
    observedMetrics[name] = value;
    send('web_vital', { metric: name, metric_value: String(Math.round(value * 100) / 100) });
  }
  function observePerformance() {
    if (!('PerformanceObserver' in window)) return;
    try {
      var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
      if (nav) reportMetric('ttfb_ms', nav.responseStart - nav.startTime);
    } catch (e) { /* Ignore unsupported performance entries. */ }
    try {
      var lcpObserver = new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (entries.length) reportMetric('lcp_ms', entries[entries.length - 1].startTime);
      });
      lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') lcpObserver.disconnect();
      }, { once: true });
    } catch (e) { /* LCP is not supported in every browser. */ }
    try {
      var clsValue = 0;
      var clsSessionValue = 0;
      var clsSessionStart = 0;
      var clsLastShift = 0;
      var clsObserver = new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          if (entry.hadRecentInput) return;
          var gap = entry.startTime - clsLastShift;
          if (gap > 1000 || entry.startTime - clsSessionStart > 5000) {
            clsSessionValue = entry.value;
            clsSessionStart = entry.startTime;
          } else {
            clsSessionValue += entry.value;
          }
          clsLastShift = entry.startTime;
          clsValue = Math.max(clsValue, clsSessionValue);
        });
        reportMetric('cls', clsValue);
      });
      clsObserver.observe({ type: 'layout-shift', buffered: true });
    } catch (e) { /* CLS is not supported in every browser. */ }
    try {
      var interactions = Object.create(null);
      var interactionObserver = new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          if (!entry.interactionId) return;
          interactions[entry.interactionId] = Math.max(interactions[entry.interactionId] || 0, entry.duration || 0);
        });
        var durations = Object.keys(interactions).map(function (key) { return interactions[key]; }).sort(function (a, b) { return a - b; });
        if (durations.length) {
          // This is a lightweight per-page interaction-latency estimate, not Google's full INP algorithm.
          reportMetric('interaction_latency_estimate_ms', durations[Math.max(0, Math.ceil(durations.length * 0.98) - 1)]);
        }
      });
      interactionObserver.observe({ type: 'event', buffered: true, durationThreshold: 40 });
    } catch (e) { /* Event Timing is not supported in every browser. */ }
  }
  function start() {
    if (started || denied) return;
    started = true;
    syncConsentCookies();
    if (initialized) return;
    if (!sentPageView) { sentPageView = true; send('page_view'); }
    if (config.productId) send('product_view', {}, config.productId);
    if (config.categoryId) send('category_view', {}, config.categoryId);
    if (config.searchTerm) send('search', { term: String(config.searchTerm).slice(0, 120) });
    observePerformance();

    var scrollDepth = 0;
    window.addEventListener('scroll', function () {
      var maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
      var depth = Math.min(100, Math.floor((window.scrollY / maxScroll) * 4) * 25);
      if (depth >= 25 && depth > scrollDepth) {
        scrollDepth = depth;
        send('scroll_depth', { depth: String(depth) });
      }
    }, { passive: true });

    document.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest('a,button,input[type="submit"]') : null;
      if (!target) return;
      if (target.matches('a[href]')) {
        try {
          var link = new URL(target.href, window.location.href);
          if (link.origin !== window.location.origin && /^https?:$/.test(link.protocol)) send('outbound_click', { link_host: link.host });
        } catch (e) { /* Ignore malformed links. */ }
      }
      if (target.matches('.add_to_cart_button,.single_add_to_cart_button,[name="add-to-cart"]')) {
        send('add_to_cart', { source: 'click' }, target.getAttribute('data-product_id') || target.value || config.productId || 0);
      }
      if (target.matches('.remove_from_cart_button,a.remove')) send('remove_from_cart', { source: 'click' });
      if (target.matches('#place_order,button[name="woocommerce_checkout_place_order"]')) send('begin_checkout', { source: 'checkout_button' });
    }, true);

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.matches) return;
      if (form.matches('form.search-form,form.woocommerce-product-search,form[role="search"]')) {
        var input = form.querySelector('input[type="search"],input[name="s"]');
        if (input && input.value) send('search', { term: String(input.value).trim().slice(0, 120) });
      } else {
        send('form_submit', { form_type: form.classList.contains('checkout') ? 'checkout' : 'form' });
      }
    }, true);

    window.addEventListener('error', function (event) {
      send('js_error', { source: event && event.filename ? 'script' : 'window_error' });
    });
    window.addEventListener('unhandledrejection', function () { send('js_error', { source: 'unhandled_rejection' }); });
    initialized = true;
  }
  function deny() {
    denied = true;
    started = false;
    storageSet(window.sessionStorage, consentKey, 'denied');
    storageRemove(window.sessionStorage, visitorKey);
    storageRemove(window.sessionStorage, sessionKey);
    memoryIds = Object.create(null);
    clearCookie('wpa_consent');
    clearCookie('wpa_visitor_id');
    clearCookie('wpa_session_id');
    if (config.persistentVisitor) {
      try { storageRemove(window.localStorage, visitorKey); } catch (e) { /* Ignore unavailable storage. */ }
    }
  }
  window.wpAnalyzConsent = function (status) {
    if (status === 'granted') {
      denied = false;
      storageSet(window.sessionStorage, consentKey, 'granted');
      start();
    } else if (status === 'denied' || status === 'revoke') {
      deny();
    }
  };

  if (config.requireConsent) {
    var consent = storageGet(window.sessionStorage, consentKey);
    if (consent === 'granted') start();
    else if (consent === 'denied') denied = true;
  } else {
    start();
  }
}());
