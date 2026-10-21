(function () {
  'use strict';

  var config = window.WPAnalyzConfig || {};
  if (!config.endpoint) return;

  // Aggregate-only mode: no visitor/session IDs, analytics cookies, browser storage,
  // page title, search terms, UTM values, or per-visitor history.
  function explicitlyDenied() {
    try {
      var match = document.cookie.match(/(?:^|;\s*)wpa_consent=([^;]*)/);
      return !!(match && decodeURIComponent(match[1]) === 'denied');
    } catch (e) { return false; }
  }
  if (explicitlyDenied()) return;

  var observedMetrics = Object.create(null);
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
  function deviceType() {
    var ua = navigator.userAgent || '';
    if (/iPad|Tablet|PlayBook/i.test(ua)) return 'tablet';
    return /Mobi|Android|iPhone|iPod/i.test(ua) ? 'mobile' : 'desktop';
  }
  function browserFamily() {
    var ua = navigator.userAgent || '';
    if (/Edg\//.test(ua)) return 'edge';
    if (/Firefox\//.test(ua)) return 'firefox';
    if (/Chrome\//.test(ua)) return 'chrome';
    if (/Safari\//.test(ua)) return 'safari';
    return 'other';
  }
  function safePageUrl() {
    return window.location.origin + window.location.pathname;
  }
  function post(eventName, data, objectId) {
    if (explicitlyDenied()) return;
    if (eventName === 'web_vital' && (!data || !data.metric || !isFinite(Number(data.metric_value)))) return;
    var key = eventName + '|' + safePageUrl() + '|' + (data && data.metric ? data.metric : '');
    if (eventName === 'web_vital') {
      var metricValue = Number(data.metric_value);
      if (observedMetrics[key] === metricValue) return;
      observedMetrics[key] = metricValue;
    }
    var payload = {
      mode: 'aggregate',
      event_id: uuid(),
      event: eventName,
      url: safePageUrl(),
      device: deviceType(),
      browser: browserFamily(),
      object_id: (eventName === 'product_view' || eventName === 'category_view' || eventName === 'add_to_cart' || eventName === 'remove_from_cart') ? (Number(objectId) || 0) : 0
    };
    if (eventName === 'web_vital') {
      payload.data = { metric: String(data.metric).slice(0, 80), metric_value: String(Math.max(0, Number(data.metric_value))).slice(0, 40) };
    }
    var body;
    try { body = JSON.stringify(payload); } catch (e) { return; }
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }))) return;
      fetch(config.endpoint, {
        method: 'POST', body: body, headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin', mode: 'same-origin', keepalive: true
      }).catch(function () {});
    } catch (e) { /* Analytics must never break the page. */ }
  }

  post('page_view');
  if (config.productId) post('product_view', null, config.productId);
  if (config.categoryId) post('category_view', null, config.categoryId);
  if (config.isSearch) post('search');

  if ('PerformanceObserver' in window) {
    var lcp = null, cls = 0, clsValue = 0, clsStart = 0, clsLast = 0, finalized = false, observers = [];
    function reportMetric(name, value) {
      if (typeof value !== 'number' || !isFinite(value) || value < 0) return;
      post('web_vital', { metric: name, metric_value: Math.round(value * 100) / 100 });
    }
    function finalize() {
      if (finalized) return;
      finalized = true;
      if (lcp !== null) reportMetric('lcp_ms', lcp);
      reportMetric('cls', cls);
      observers.forEach(function (observer) { try { observer.disconnect(); } catch (e) {} });
    }
    try {
      var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
      if (nav) reportMetric('ttfb_ms', nav.responseStart - nav.startTime);
    } catch (e) {}
    try {
      var lcpObserver = new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (entries.length) lcp = entries[entries.length - 1].startTime;
      });
      lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
      observers.push(lcpObserver);
    } catch (e) {}
    try {
      var clsObserver = new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          if (entry.hadRecentInput) return;
          if (entry.startTime - clsLast > 1000 || entry.startTime - clsStart > 5000) {
            clsValue = entry.value; clsStart = entry.startTime;
          } else clsValue += entry.value;
          clsLast = entry.startTime;
          cls = Math.max(cls, clsValue);
        });
      });
      clsObserver.observe({ type: 'layout-shift', buffered: true });
      observers.push(clsObserver);
    } catch (e) {}
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') finalize(); });
    window.addEventListener('pagehide', finalize, { once: true });
  }

  var scrollDepth = 0;
  var scrollPending = false;
  function recordScrollDepth() {
    scrollPending = false;
    var maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    var depth = Math.min(100, Math.floor((window.scrollY / maxScroll) * 4) * 25);
    if (depth >= 25 && depth > scrollDepth) {
      scrollDepth = depth;
      post('scroll_depth');
    }
  }
  window.addEventListener('scroll', function () {
    if (scrollPending) return;
    scrollPending = true;
    if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(recordScrollDepth);
    else recordScrollDepth();
  }, { passive: true });

  document.addEventListener('click', function (event) {
    var target = event.target && event.target.closest ? event.target.closest('a,button,input[type="submit"]') : null;
    if (!target) return;
    if (target.matches('a[href]')) {
      try {
        var link = new URL(target.href, window.location.href);
        if (link.origin !== window.location.origin && /^https?:$/.test(link.protocol)) post('outbound_click');
      } catch (e) {}
    }
    if (target.matches('.add_to_cart_button,.single_add_to_cart_button,[name="add-to-cart"]')) post('add_to_cart', null, target.getAttribute('data-product_id') || target.value || config.productId || 0);
    if (target.matches('.remove_from_cart_button,a.remove')) post('remove_from_cart', null, target.getAttribute('data-product_id') || 0);
    if (target.matches('#place_order,button[name="woocommerce_checkout_place_order"]')) post('begin_checkout');
  }, true);

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || form.matches('form.search-form,form.woocommerce-product-search,form[role="search"]')) return;
    post('form_submit');
  }, true);

  window.addEventListener('error', function () { post('js_error'); });
  window.addEventListener('unhandledrejection', function () { post('js_error'); });
}());
