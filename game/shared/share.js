/* Share button for the games (The Satoshi Faucet)
 *
 * Wires up a <button id="share"> inside a game page. What it shares is the HOME PAGE link with the
 * game's name in the fragment, e.g. https://thesatoshifaucet.com/#Block-Race, which opens that game
 * straight away for whoever taps it.
 *
 * - Phones, tablets and many desktops: opens the system share sheet (WhatsApp, X, Instagram, ...).
 * - Anywhere else: a small menu with WhatsApp, X, Telegram, Facebook and Copy link.
 *
 * The button carries the details, so each game only needs:
 *   <button id="share" class="round-btn share-btn" data-share-hash="Block-Race"
 *           data-share-title="Block Race" data-share-text="..." data-share-home="../../">
 */
(function () {
  'use strict';

  var btn = document.getElementById('share');
  if (!btn) return;

  var hash = btn.getAttribute('data-share-hash') || '';
  var title = btn.getAttribute('data-share-title') || document.title;
  var text = btn.getAttribute('data-share-text') || title;
  var homeRel = btn.getAttribute('data-share-home') || '../';

  // The home page address (no query, no fragment). Same-origin parent first, else a relative guess.
  function homeUrl() {
    var base = '';
    try {
      if (window.parent !== window) {
        var u = new URL(window.parent.location.href);
        u.hash = '';
        u.search = '';
        base = u.href;
      }
    } catch (e) { /* cross-origin parent: fall through */ }
    if (!base) {
      try { base = new URL(homeRel, window.location.href).href; }
      catch (e) { base = window.location.origin + '/'; }
    }
    return base.replace(/#.*$/, '');
  }

  function shareUrl() {
    var h = homeUrl();
    return hash ? h + '#' + hash : h;
  }

  // ---- fallback menu --------------------------------------------------------------------
  var backdrop = null;
  var menu = null;
  var statusEl = null;

  function build() {
    backdrop = document.createElement('div');
    backdrop.className = 'share-backdrop';
    backdrop.hidden = true;
    backdrop.addEventListener('click', closeMenu);

    menu = document.createElement('div');
    menu.className = 'share-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    menu.innerHTML =
      '<div class="share-menu-title">Share this game</div>' +
      '<a role="menuitem" data-k="whatsapp" target="_blank" rel="noopener noreferrer">💬 WhatsApp</a>' +
      '<a role="menuitem" data-k="x" target="_blank" rel="noopener noreferrer">𝕏 &nbsp;X (Twitter)</a>' +
      '<a role="menuitem" data-k="telegram" target="_blank" rel="noopener noreferrer">✈️ Telegram</a>' +
      '<a role="menuitem" data-k="facebook" target="_blank" rel="noopener noreferrer">👍 Facebook</a>' +
      '<button type="button" role="menuitem" data-k="copy">🔗 Copy link</button>' +
      '<div class="share-menu-status" aria-live="polite"></div>' +
      '<div class="share-menu-hint">Instagram has no web share link &mdash; copy the link and paste it into your story, bio or a message.</div>';
    statusEl = menu.querySelector('.share-menu-status');
    menu.querySelector('[data-k="copy"]').addEventListener('click', onCopy);

    document.body.appendChild(backdrop);
    document.body.appendChild(menu);
  }

  function openMenu() {
    if (!menu) build();
    var url = shareUrl();
    var enc = encodeURIComponent;
    menu.querySelector('[data-k="whatsapp"]').href = 'https://wa.me/?text=' + enc(text + ' ' + url);
    menu.querySelector('[data-k="x"]').href = 'https://twitter.com/intent/tweet?text=' + enc(text) + '&url=' + enc(url);
    menu.querySelector('[data-k="telegram"]').href = 'https://t.me/share/url?url=' + enc(url) + '&text=' + enc(text);
    menu.querySelector('[data-k="facebook"]').href = 'https://www.facebook.com/sharer/sharer.php?u=' + enc(url);
    statusEl.textContent = '';

    backdrop.hidden = false;
    menu.hidden = false;

    // sit beside the button, kept inside the screen
    var r = btn.getBoundingClientRect();
    var w = menu.offsetWidth;
    var h = menu.offsetHeight;
    var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left - w - 8));
    var top = Math.max(8, Math.min(window.innerHeight - h - 8, r.top));
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    btn.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    if (!menu) return;
    menu.hidden = true;
    backdrop.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  }

  function copyText(t) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(t).catch(function () { return legacyCopy(t); });
    }
    return legacyCopy(t);
  }

  function legacyCopy(t) {
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = t;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      if (ok) resolve(); else reject(new Error('copy failed'));
    });
  }

  function onCopy() {
    var url = shareUrl();
    copyText(url).then(function () {
      statusEl.textContent = '✅ Link copied!';
    }, function () {
      statusEl.textContent = 'Copy this link: ' + url;
    });
  }

  // ---- the button ------------------------------------------------------------------------
  btn.addEventListener('click', function () {
    if (menu && !menu.hidden) { closeMenu(); return; }
    var data = { title: title, text: text, url: shareUrl() };
    var canNative = typeof navigator.share === 'function' &&
      (typeof navigator.canShare !== 'function' || navigator.canShare(data));
    if (canNative) {
      navigator.share(data).catch(function (err) {
        // Cancelling the sheet is normal; anything else falls back to the menu.
        if (!err || err.name !== 'AbortError') openMenu();
      });
    } else {
      openMenu();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu && !menu.hidden) closeMenu();
  });
})();
