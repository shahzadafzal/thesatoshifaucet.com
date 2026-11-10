/* analytics.js — the site's one analytics loader. Every public page includes this file:
 *   <script src="assets/analytics.js" defer></script>   (adjust ../ for pages in subfolders)
 *
 * Runs Umami and Google Analytics 4 side by side while we compare them; drop either by blanking its ID.
 * - Never blocks the page: Umami is tiny and deferred; GA starts only after the page has fully loaded and gone idle.
 * - Skipped on localhost so local testing doesn't pollute the numbers (events are logged to the console instead).
 * - Pages send custom events with  window.track('event_name', { key: value })  — safe to call any time,
 *   even before the trackers have loaded or when they're blocked.
 */
(function () {
  'use strict';

  var UMAMI_ID = 'a95ca758-29c2-4a8c-ae3c-3e2d35505735';
  var GA_ID = 'G-CCV4JTG0H0';   // GA4 Measurement ID (public, not a secret). Empty = GA off.

  var host = location.hostname;
  var isLocal = host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || host === '' || /\.(localhost|test)$/.test(host);

  // Events sent before Umami has loaded wait here.
  var umamiQueue = [];
  function flushUmami() {
    if (!window.umami || typeof window.umami.track !== 'function') return;
    while (umamiQueue.length) { var e = umamiQueue.shift(); try { window.umami.track(e[0], e[1]); } catch (_) {} }
  }

  window.track = function (name, data) {
    if (!name) return;
    data = data || {};
    if (isLocal) { try { console.info('[analytics]', name, data); } catch (_) {} return; }
    if (UMAMI_ID) { umamiQueue.push([name, data]); flushUmami(); }
    if (GA_ID && typeof window.gtag === 'function') { try { window.gtag('event', name, data); } catch (_) {} }
  };

  if (isLocal) return;

  // --- Umami (about 2 KB) ---
  if (UMAMI_ID) {
    var u = document.createElement('script');
    u.defer = true;
    u.src = 'https://cloud.umami.is/script.js';
    u.setAttribute('data-website-id', UMAMI_ID);
    u.onload = flushUmami;
    document.head.appendChild(u);
  }

  // --- Google Analytics 4 ---
  if (GA_ID) {
    // gtag() works straight away: calls queue in dataLayer until gtag.js arrives.
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', GA_ID);

    var loadGa = function () {
      var g = document.createElement('script');
      g.async = true;
      g.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
      document.head.appendChild(g);
    };
    var whenIdle = function () {
      if ('requestIdleCallback' in window) window.requestIdleCallback(loadGa, { timeout: 3000 });
      else setTimeout(loadGa, 1);
    };
    if (document.readyState === 'complete') whenIdle();
    else window.addEventListener('load', whenIdle, { once: true });
  }
})();
