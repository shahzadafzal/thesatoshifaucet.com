/* localtime.js — show the database's UTC times in each visitor's own time zone (from their browser/device).
 *
 * The server prints times as  <time datetime="2026-11-13T18:11:32Z" data-utc>2026-11-13 18:11:32 UTC</time>
 * and this script rewrites the text to local time, e.g. "13 Nov 2026, 10:11:32 PM" for someone in the UAE.
 * Hovering shows the original UTC time. Without JavaScript the UTC text simply stays.
 * Elements added later (auto-refresh, JS-built lists) are converted automatically.
 *
 *   window.tsfLocalTime('2026-11-13 18:11:32')  -> formatted local string (for scripts that build their own HTML)
 *   <span data-tz-name></span>                   -> filled with the visitor's time zone name, e.g. "Asia/Dubai"
 */
(function () {
  'use strict';

  var OPTS = { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' };
  var fmt;
  try { fmt = new Intl.DateTimeFormat(undefined, OPTS); } catch (e) { fmt = null; }

  // Accepts "YYYY-MM-DD HH:MM:SS" (UTC, as stored) or a full ISO string.
  function parseUtc(s) {
    if (!s) return null;
    s = String(s).trim();
    if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(s)) s = s.replace(' ', 'T') + 'Z';
    var d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  }

  function format(d) {
    try { return fmt ? fmt.format(d) : d.toLocaleString(); } catch (e) { return d.toLocaleString(); }
  }

  window.tsfLocalTime = function (s) {
    var d = parseUtc(s);
    return d ? format(d) : (s || '');
  };

  var tzName = '';
  try { tzName = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}

  function convert(root) {
    if (!root || !root.querySelectorAll) return;
    var list = root.matches && root.matches('time[data-utc]') ? [root] : [];
    list = list.concat(Array.prototype.slice.call(root.querySelectorAll('time[data-utc]:not([data-local-done])')));
    list.forEach(function (el) {
      var d = parseUtc(el.getAttribute('datetime'));
      if (!d) return;
      el.title = el.title || (el.getAttribute('datetime').replace('T', ' ').replace('Z', '') + ' UTC');
      el.textContent = format(d);
      el.setAttribute('data-local-done', '1');
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-tz-name]'), function (el) {
      el.textContent = tzName || 'your local time';
    });
  }

  function start() {
    convert(document.body);
    if ('MutationObserver' in window) {
      new MutationObserver(function (muts) {
        muts.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) convert(n); }); });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
