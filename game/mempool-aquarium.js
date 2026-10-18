/* Mempool Aquarium — The Satoshi Faucet
 *
 * Every fish is a real, unconfirmed Bitcoin transaction streamed from mempool.space
 * straight to the visitor's browser (nothing goes through our server). Fish size grows
 * with the log of the transaction's total output value; colour comes from its fee rate.
 * Tap fish to catch them. Your score is the total value (in sats / BTC) of the transactions
 * you catch. Play money only: nothing is paid out. Best score is kept in localStorage.
 */
(() => {
  'use strict';

  /* ================= Tunables ================= */
  const CONFIG = {
    // Value tiers, in sats. A transaction whose total output value is >= a tier's
    // threshold belongs to that tier. Change whaleSats to move the "whale" line.
    whaleSats: 1e8,      // 1 BTC   -> whale
    sharkSats: 1e7,      // 0.1 BTC -> shark
    fishSats: 1e5,       // 0.001 BTC -> fish   (below this: shrimp)

    // Score = total value (sats) of the transactions you catch. A streak (catches within
    // comboWindowMs of each other) is counted without limit and raises the catch-sound pitch
    // (up to pitchStreakCap steps, so long streaks don't squeal); it does not change the score.
    comboWindowMs: 1800,
    pitchStreakCap: 5,
    helpOnFirstVisit: true,   // open the "how it works" panel the first time someone plays

    apiBase: 'https://mempool.space/api',
    pollMs: 4000,
    pollMaxMs: 20000,
    requestTimeoutMs: 6000,
    failuresBeforeSim: 3,

    maxFishDesktop: 36,
    maxFishMobile: 22,

    // Size scale: log10 of sats mapped between these bounds -> radius between min/max px.
    sizeLogMinSats: 1e3,
    sizeLogMaxSats: 1e11,
    minRadius: 11,
    maxRadius: 84,

    // New key: older versions stored "points", which must not be read back as sats.
    storageKey: 'satoshiFaucet.aquarium.bestSats',
    helpKey: 'satoshiFaucet.aquarium.helpSeen',
    soundKey: 'satoshiFaucet.aquarium.sound',   // '0' = muted
    masterVolume: 0.5,                           // 0..1
  };

  const TIERS = {
    shrimp: { name: 'Shrimp', icon: '🦐' },
    fish:   { name: 'Fish',   icon: '🐟' },
    shark:  { name: 'Shark',  icon: '🦈' },
    whale:  { name: 'Whale',  icon: '🐋' },
  };

  /* ================= Helpers ================= */
  const $ = (id) => document.getElementById(id);
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const rand = (a, b) => a + Math.random() * (b - a);

  function tierOf(sats) {
    if (sats >= CONFIG.whaleSats) return 'whale';
    if (sats >= CONFIG.sharkSats) return 'shark';
    if (sats >= CONFIG.fishSats) return 'fish';
    return 'shrimp';
  }

  function fmtValue(sats) {
    if (sats >= 1e6) {
      const btc = sats / 1e8;
      return btc.toFixed(btc >= 1 ? 2 : 4) + ' BTC';
    }
    return Math.round(sats).toLocaleString('en-US') + ' sats';
  }

  const fmtSats = (n) => Math.round(n).toLocaleString('en-US') + ' sats';
  const fmtBtc = (n) => (n / 1e8).toFixed(8) + ' BTC';

  function shortTxid(txid) {
    return txid.slice(0, 6) + '…' + txid.slice(-6);
  }

  /* ================= Sound (synthesised with Web Audio: no files, no licences) ================= */
  const sfx = (() => {
    let ac = null;
    let master = null;
    let noiseBuf = null;
    let voices = 0;
    let enabled = true;
    try { enabled = localStorage.getItem(CONFIG.soundKey) !== '0'; } catch (e) { /* ignore */ }

    // Must be called from a user gesture (browsers keep audio locked until then).
    function unlock() {
      if (!enabled) return;
      if (!ac) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        try { ac = new AC(); } catch (e) { return; }
        master = ac.createGain();
        master.gain.value = CONFIG.masterVolume;
        master.connect(ac.destination);
        noiseBuf = ac.createBuffer(1, Math.floor(ac.sampleRate * 0.4), ac.sampleRate);
        const d = noiseBuf.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
      }
      if (ac.state === 'suspended') ac.resume();
    }

    function ready() { return enabled && ac && ac.state === 'running' && voices < 12; }

    // One enveloped oscillator note. freq2 glides the pitch; vib adds a slow wobble.
    function tone(o) {
      if (!ready()) return;
      const t0 = ac.currentTime + (o.delay || 0);
      const dur = o.dur || 0.12;
      const osc = ac.createOscillator();
      const g = ac.createGain();
      osc.type = o.type || 'sine';
      osc.frequency.setValueAtTime(o.freq, t0);
      if (o.freq2) osc.frequency.exponentialRampToValueAtTime(o.freq2, t0 + dur);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.linearRampToValueAtTime(o.gain || 0.25, t0 + Math.min(0.02, dur / 3));
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      osc.connect(g);
      g.connect(master);
      let lfo = null;
      if (o.vib) {
        lfo = ac.createOscillator();
        const lg = ac.createGain();
        lfo.frequency.value = o.vib.rate;
        lg.gain.value = o.vib.depth;
        lfo.connect(lg);
        lg.connect(osc.frequency);
        lfo.start(t0);
        lfo.stop(t0 + dur + 0.05);
      }
      voices++;
      osc.onended = () => { voices--; };
      osc.start(t0);
      osc.stop(t0 + dur + 0.05);
    }

    // Filtered noise burst (splashes).
    function splash(o) {
      if (!ready()) return;
      const t0 = ac.currentTime + (o.delay || 0);
      const dur = o.dur || 0.18;
      const src = ac.createBufferSource();
      src.buffer = noiseBuf;
      const f = ac.createBiquadFilter();
      f.type = 'bandpass';
      f.frequency.setValueAtTime(o.freq || 1200, t0);
      f.frequency.exponentialRampToValueAtTime((o.freq || 1200) * 0.4, t0 + dur);
      f.Q.value = 0.9;
      const g = ac.createGain();
      g.gain.setValueAtTime(o.gain || 0.2, t0);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      src.connect(f);
      f.connect(g);
      g.connect(master);
      voices++;
      src.onended = () => { voices--; };
      src.start(t0);
      src.stop(t0 + dur + 0.02);
    }

    return {
      unlock,
      isOn: () => enabled,
      toggle() {
        enabled = !enabled;
        try { localStorage.setItem(CONFIG.soundKey, enabled ? '1' : '0'); } catch (e) { /* ignore */ }
        if (enabled) { unlock(); tone({ freq: 500, freq2: 900, dur: 0.1, gain: 0.2 }); }
        return enabled;
      },
      // Pause/resume with tab visibility so nothing plays in the background.
      visibility(hidden) {
        if (!ac) return;
        if (hidden) ac.suspend(); else if (enabled) ac.resume();
      },
      // Catch sounds. Bubble pitch climbs with the combo.
      catchSmall(combo) {
        const up = (combo - 1) * 70;
        tone({ freq: 420 + up, freq2: 900 + up * 1.4, dur: 0.1, gain: 0.26 });
        tone({ freq: 840 + up, freq2: 1500 + up, dur: 0.06, gain: 0.08, delay: 0.04 });
      },
      catchShark(combo) {
        const up = (combo - 1) * 40;
        splash({ freq: 1500, dur: 0.2, gain: 0.22 });
        tone({ freq: 190 + up, freq2: 330 + up, type: 'triangle', dur: 0.2, gain: 0.3 });
        tone({ freq: 380 + up, freq2: 520 + up, dur: 0.12, gain: 0.12, delay: 0.08 });
      },
      catchWhale() {
        // Whale song + coin chime.
        tone({ freq: 95, freq2: 210, dur: 1.3, gain: 0.34, vib: { rate: 5.5, depth: 9 } });
        tone({ freq: 210, freq2: 120, dur: 0.9, gain: 0.16, delay: 0.5, vib: { rate: 4, depth: 6 } });
        splash({ freq: 1000, dur: 0.35, gain: 0.2 });
        [659, 784, 988, 1319, 1568].forEach((f, i) => {
          tone({ freq: f, type: 'triangle', dur: 0.22, gain: 0.17, delay: 0.1 + i * 0.075 });
        });
      },
      whaleAlert() {
        // Sonar ping with an echo.
        tone({ freq: 880, freq2: 860, dur: 0.8, gain: 0.16 });
        tone({ freq: 880, freq2: 860, dur: 0.8, gain: 0.07, delay: 0.24 });
      },
      miss() {
        tone({ freq: 190, freq2: 110, dur: 0.07, gain: 0.07 });
      },
    };
  })();

  /* ================= State ================= */
  const canvas = $('tank');
  const ctx = canvas.getContext('2d');
  let W = 0, H = 0, dpr = 1, S = 1; // css size, device-pixel ratio, size scale
  let bgGrad = null;

  const fish = [];
  const queue = [];            // transactions waiting to become fish
  const bubbles = [];          // ambient bubbles
  const particles = [];        // catch bursts
  const floaters = [];         // floating "+N" texts
  const seen = new Set();      // txids already seen
  const weeds = [];

  let score = 0;
  let best = 0;
  let caught = 0;
  const counts = { shrimp: 0, fish: 0, shark: 0, whale: 0 };   // animals caught this game, by type
  let combo = 0;
  let lastCatchAt = 0;
  let nextSpawnAt = 0;
  let flash = 0;               // whale catch screen flash (0..1)
  let nextId = 1;

  let last = 0;
  let raf = 0;
  let running = false;

  try { best = parseInt(localStorage.getItem(CONFIG.storageKey), 10) || 0; } catch (e) { /* ignore */ }

  /* ================= Layout ================= */
  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
    S = clamp(Math.min(W, H) / 520, 0.7, 1.25);

    bgGrad = ctx.createLinearGradient(0, 0, 0, H);
    bgGrad.addColorStop(0, '#0b4a6e');
    bgGrad.addColorStop(0.55, '#06304a');
    bgGrad.addColorStop(1, '#031a2b');

    // Ambient bubbles scale with area.
    const wantBubbles = Math.round(clamp((W * H) / 22000, 10, 40));
    while (bubbles.length < wantBubbles) bubbles.push(newBubble(true));
    bubbles.length = wantBubbles;

    // Seaweed along the bottom.
    weeds.length = 0;
    const count = Math.round(W / 90);
    for (let i = 0; i < count; i++) {
      weeds.push({
        x: (i + 0.5) * (W / count) + rand(-20, 20),
        h: rand(40, 95) * S,
        phase: rand(0, 6.28),
        hue: rand(110, 150),
      });
    }
  }

  function newBubble(anywhere) {
    return {
      x: rand(0, W || 300),
      y: anywhere ? rand(0, H || 500) : (H || 500) + 10,
      r: rand(1.5, 5),
      vy: rand(14, 38),
      sway: rand(6, 18),
      phase: rand(0, 6.28),
    };
  }

  function maxFish() {
    return W < 600 ? CONFIG.maxFishMobile : CONFIG.maxFishDesktop;
  }

  /* ================= Fish ================= */
  function spawnFish(tx) {
    const tier = tierOf(tx.sats);
    const t = clamp(
      (Math.log10(Math.max(tx.sats, 1)) - Math.log10(CONFIG.sizeLogMinSats)) /
      (Math.log10(CONFIG.sizeLogMaxSats) - Math.log10(CONFIG.sizeLogMinSats)), 0, 1);
    const r = lerp(CONFIG.minRadius, CONFIG.maxRadius, t) * S;

    const dir = Math.random() < 0.5 ? 1 : -1;
    const topPad = 78 * S;
    const botPad = 70 * S;
    const y = rand(topPad + r * 0.6, Math.max(topPad + r * 0.6 + 1, H - botPad - r * 0.6));
    const speed = lerp(95, 42, t) * S * rand(0.8, 1.25);

    // Colour from fee rate: cheap = blue, expensive = orange/red.
    const feeRate = tx.vsize > 0 ? tx.fee / tx.vsize : 1;
    const hue = 205 - clamp(Math.log10(Math.max(feeRate, 1)) / 2, 0, 1) * 195;

    fish.push({
      id: nextId++,
      txid: tx.txid, sats: tx.sats, feeRate, sim: !!tx.sim,
      tier, r, dir,
      // whales come in two kinds, picked at random: a friendly cartoon whale or a long blue whale
      variant: tier === 'whale' ? (Math.random() < 0.5 ? 'blue' : 'whale') : null,
      x: dir > 0 ? -r * 2 : W + r * 2,
      y, baseY: y,
      vx: speed * dir,
      phase: rand(0, 6.28),
      bobAmp: rand(4, 14) * S,
      bobSpeed: rand(1.2, 2.4),
      hue: tier === 'whale' ? 44 : hue,
    });

    if (tier === 'whale') {
      showBanner('🐋 Whale alert! ' + fmtValue(tx.sats));
      sfx.whaleAlert();
    }
  }

  function updateFish(dt) {
    for (let i = fish.length - 1; i >= 0; i--) {
      const f = fish[i];
      f.x += f.vx * dt;
      f.phase += f.bobSpeed * dt;
      f.y = f.baseY + Math.sin(f.phase) * f.bobAmp;
      const off = f.dir > 0 ? f.x > W + f.r * 2.2 : f.x < -f.r * 2.2;
      if (off) fish.splice(i, 1);
    }
  }

  // Draw one creature (shrimp / fish / shark / whale, see aquarium-creatures.js) plus its value label.
  function drawFish(f, time) {
    const r = f.r;
    const isWhale = f.tier === 'whale';

    if (window.AquariumCreatures) window.AquariumCreatures.draw(ctx, f, time);

    // Label (drawn unmirrored)
    if (r >= 26 * S || isWhale) {
      const fs = Math.round(clamp(r * 0.22, 10, 15));
      ctx.font = '700 ' + fs + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.textAlign = 'center';
      // whales carry their label underneath (their spout rises above); sharks clear their tall fin
      const below = isWhale;
      ctx.textBaseline = below ? 'top' : 'bottom';
      const ly = below ? f.y + r * 0.82 + 3 : f.y - r * (f.tier === 'shark' ? 0.92 : 0.62) - 3;
      const label = (isWhale ? TIERS.whale.icon + ' ' : '') + fmtValue(f.sats);
      ctx.lineWidth = 3;
      ctx.strokeStyle = 'rgba(2,18,30,0.8)';
      ctx.strokeText(label, f.x, ly);
      ctx.fillStyle = isWhale ? '#ffe08a' : '#e8f6ff';
      ctx.fillText(label, f.x, ly);
    }
  }

  /* ================= Catching ================= */
  function pointFish(px, py) {
    const pad = 16;
    let bestFish = null;
    let bestScore = 1;
    for (let i = fish.length - 1; i >= 0; i--) {
      const f = fish[i];
      const dx = (px - f.x) / (f.r + pad);
      const dy = (py - f.y) / (f.r * 0.6 + pad);
      const d = dx * dx + dy * dy;
      if (d <= bestScore) { bestScore = d; bestFish = f; }
    }
    return bestFish;
  }

  function catchFish(f, now) {
    const idx = fish.indexOf(f);
    if (idx >= 0) fish.splice(idx, 1);

    combo = (now - lastCatchAt <= CONFIG.comboWindowMs) ? combo + 1 : 1;
    lastCatchAt = now;
    score += f.sats;
    caught += 1;
    if (counts[f.tier] !== undefined) counts[f.tier] += 1;
    bumpChip = f.tier;
    if (score > best) {
      best = score;
      try { localStorage.setItem(CONFIG.storageKey, String(best)); } catch (e) { /* ignore */ }
    }

    // Effects
    const n = f.tier === 'whale' ? 34 : 14;
    for (let i = 0; i < n; i++) {
      const a = rand(0, 6.28);
      const sp = rand(40, f.tier === 'whale' ? 300 : 170);
      particles.push({ x: f.x, y: f.y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 30, r: rand(2, 6) * S, life: rand(0.5, 1), age: 0,
        gold: f.tier === 'whale' });
    }
    floaters.push({ x: f.x, y: f.y - f.r * 0.5, text: '+' + fmtValue(f.sats), age: 0, big: f.tier === 'whale' });
    if (f.tier === 'whale') flash = 1;

    if (f.tier === 'whale') sfx.catchWhale();
    else if (f.tier === 'shark') sfx.catchShark(Math.min(combo, CONFIG.pitchStreakCap));
    else sfx.catchSmall(Math.min(combo, CONFIG.pitchStreakCap));

    updateHud();
    showCatch(f);
  }

  /* ================= HUD ================= */
  // The whale banner and the "caught" card are drawn on the canvas, UNDER the animals, so fish and
  // whales swim in front of them. The DOM copies (#banner, #catch) stay as visually-hidden text for
  // screen readers and keyboard users.
  let bannerState = null;    // { text, start, until }
  let catchState = null;     // { line1, line2, url }
  let catchLink = null;      // where the "View" button was last drawn: { x, y, w, h, url }

  let bannerTimer = 0;
  function showBanner(text) {
    const el = $('banner');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(bannerTimer);
    bannerTimer = setTimeout(() => { el.hidden = true; }, 3500);
    const now = performance.now();
    bannerState = { text, start: now, until: now + 3500 };
  }

  // rounded rectangle path (works without ctx.roundRect)
  function rr(x, y, w, h, rad) {
    rad = Math.min(rad, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + rad, y);
    ctx.arcTo(x + w, y, x + w, y + h, rad);
    ctx.arcTo(x + w, y + h, x, y + h, rad);
    ctx.arcTo(x, y + h, x, y, rad);
    ctx.arcTo(x, y, x + w, y, rad);
    ctx.closePath();
  }

  function drawBanner() {
    if (!bannerState) return;
    const now = performance.now();
    if (now > bannerState.until) { bannerState = null; return; }
    const age = now - bannerState.start;
    const left = bannerState.until - now;
    const a = clamp(Math.min(age / 220, left / 450), 0, 1);
    const slide = (1 - clamp(age / 220, 0, 1)) * -14;

    ctx.save();
    ctx.globalAlpha = a;
    ctx.font = '800 ' + (W < 420 ? 14 : 16) + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
    const tw = ctx.measureText(bannerState.text).width;
    const w = Math.min(W - 24, tw + 36), h = 34;
    const x = (W - w) / 2;
    // Sit below the score boxes when the banner would run behind them (narrow phones).
    let y = 62;
    const hudEl = document.querySelector('.hud-tl');
    if (hudEl) {
      const hb = hudEl.getBoundingClientRect();
      if (x < hb.right + 8) y = Math.max(y, hb.bottom + 8);
    }
    y += slide;
    const g = ctx.createLinearGradient(x, y, x + w, y + h);
    g.addColorStop(0, '#ffd166');
    g.addColorStop(1, '#f4a300');
    ctx.shadowColor = 'rgba(255, 190, 40, 0.5)';
    ctx.shadowBlur = 18;
    ctx.fillStyle = g;
    rr(x, y, w, h, 17);
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.fillStyle = '#3a2200';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(bannerState.text, W / 2, y + h / 2 + 1, w - 20);
    ctx.restore();
  }

  function drawCatchCard() {
    catchLink = null;
    if (!catchState) return;
    const w = Math.min(W - 24, 440), h = 50;
    const x = (W - w) / 2, y = H - 64 - h;
    const linkW = catchState.url ? 76 : 0;
    const textW = w - 24 - linkW;
    const FONT = '-apple-system, "Segoe UI", Roboto, sans-serif';

    ctx.save();
    ctx.fillStyle = 'rgba(2, 18, 30, 0.8)';
    rr(x, y, w, h, 12);
    ctx.fill();
    ctx.strokeStyle = 'rgba(160, 220, 255, 0.28)';
    ctx.lineWidth = 1;
    ctx.stroke();

    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    let fs = 13;
    ctx.font = '700 ' + fs + 'px ' + FONT;
    while (ctx.measureText(catchState.line1).width > textW && fs > 10) { fs--; ctx.font = '700 ' + fs + 'px ' + FONT; }
    ctx.fillStyle = '#e8f6ff';
    ctx.fillText(catchState.line1, x + 12, y + 22);

    fs = 12;
    ctx.font = '500 ' + fs + 'px ' + FONT;
    while (ctx.measureText(catchState.line2).width > textW && fs > 9) { fs--; ctx.font = '500 ' + fs + 'px ' + FONT; }
    ctx.fillStyle = 'rgba(232, 246, 255, 0.72)';
    ctx.fillText(catchState.line2, x + 12, y + 40);

    if (catchState.url) {
      const bx = x + w - linkW - 6, by = y + 8, bw = linkW, bh = h - 16;
      ctx.fillStyle = 'rgba(125, 211, 252, 0.16)';
      rr(bx, by, bw, bh, 10);
      ctx.fill();
      ctx.strokeStyle = 'rgba(125, 211, 252, 0.55)';
      ctx.stroke();
      ctx.fillStyle = '#7dd3fc';
      ctx.font = '700 13px ' + FONT;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText('View ↗', bx + bw / 2, by + bh / 2 + 1);
      catchLink = { x: bx - 4, y: y, w: bw + 10, h: h, url: catchState.url };
    }
    ctx.restore();
  }

  let bumpChip = null;   // which counter just went up (for the little pop animation)

  function updateHud() {
    $('score').textContent = fmtSats(score);
    $('scoreBtc').textContent = fmtBtc(score);
    $('best').textContent = fmtSats(best) + ' · ' + fmtBtc(best);

    for (const tier in counts) {
      $('cnt-' + tier).textContent = counts[tier];
      $('chip-' + tier).title = TIERS[tier].name + ' caught: ' + counts[tier];
    }
    if (bumpChip) {
      const chip = $('chip-' + bumpChip);
      chip.classList.remove('bump');
      void chip.offsetWidth;          // restart the animation
      chip.classList.add('bump');
      bumpChip = null;
    }
    const c = $('combo');
    if (combo > 1) { c.textContent = '🔥 ' + combo + ' streak'; c.hidden = false; c.style.animation = 'none'; void c.offsetWidth; c.style.animation = ''; }
    else c.hidden = true;
  }

  function showCatch(f) {
    // canvas copy (drawn under the animals)
    catchState = {
      line1: TIERS[f.tier].icon + ' ' + TIERS[f.tier].name + ' caught: ' + fmtValue(f.sats),
      line2: f.feeRate.toFixed(1) + ' sat/vB · ' + (f.sim ? 'simulated' : shortTxid(f.txid)),
      url: !f.sim && /^[0-9a-f]{64}$/i.test(f.txid) ? 'https://mempool.space/tx/' + f.txid : null,
    };

    // screen-reader copy
    const el = $('catch');
    el.textContent = '';
    const info = document.createElement('span');
    info.textContent = TIERS[f.tier].icon + ' ' + TIERS[f.tier].name + ' caught: ' + fmtValue(f.sats) +
      ' · ' + f.feeRate.toFixed(1) + ' sat/vB · ' + (f.sim ? 'simulated' : shortTxid(f.txid));
    el.appendChild(info);
    if (!f.sim && /^[0-9a-f]{64}$/i.test(f.txid)) {
      const a = document.createElement('a');
      a.href = 'https://mempool.space/tx/' + f.txid;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = 'View ↗';
      a.style.pointerEvents = 'auto';
      el.appendChild(a);
    }
    el.hidden = false;
  }

  // Help panel: tier list is built from CONFIG so it always matches the real thresholds.
  function buildHelpTiers() {
    const th = (s) => (s >= 1e6 ? (s / 1e8) + ' BTC' : (s / 1e3) + 'k sats');
    const rows = [
      ['shrimp', 'under ' + th(CONFIG.fishSats), 'small and common'],
      ['fish', th(CONFIG.fishSats) + ' to ' + th(CONFIG.sharkSats), 'everyday payments'],
      ['shark', th(CONFIG.sharkSats) + ' to ' + th(CONFIG.whaleSats), 'big movers, fin and all'],
      ['whale', th(CONFIG.whaleSats) + ' and up', 'rare! a whale or a blue whale, glowing gold'],
    ];
    $('helpTiers').innerHTML = rows.map((r) =>
      '<li>' + TIERS[r[0]].icon + ' <strong>' + TIERS[r[0]].name + '</strong> &mdash; ' + r[1] + ' (' + r[2] + ')</li>'
    ).join('');
  }

  function buildLegend() {
    const fmtThreshold = (s) => (s >= 1e6 ? (s / 1e8) + ' BTC' : (s / 1e3) + 'k sats');
    $('legend').innerHTML =
      '<span>' + TIERS.shrimp.icon + ' &lt;' + fmtThreshold(CONFIG.fishSats) + '</span>' +
      '<span>' + TIERS.fish.icon + ' ' + fmtThreshold(CONFIG.fishSats) + '+</span>' +
      '<span>' + TIERS.shark.icon + ' ' + fmtThreshold(CONFIG.sharkSats) + '+</span>' +
      '<span>' + TIERS.whale.icon + ' ' + fmtThreshold(CONFIG.whaleSats) + '+</span>' +
      '<span>· Tap to catch</span>';
  }

  function setStatus(kind) {
    const dot = $('dot');
    const txt = $('statusText');
    dot.className = 'dot ' + kind;
    txt.textContent = kind === 'live' ? 'Live: mempool.space'
      : kind === 'sim' ? 'Offline — simulated fish'
      : kind === 'off' ? 'Paused' : 'Connecting…';
  }

  /* ================= Data feed ================= */
  let failures = 0;
  let interval = CONFIG.pollMs;
  let pollTimer = 0;

  function enqueue(list) {
    let added = 0;
    for (const tx of list) {
      if (!tx || typeof tx.txid !== 'string' || seen.has(tx.txid)) continue;
      const sats = Number(tx.value);
      if (!(sats > 0)) continue;
      seen.add(tx.txid);
      const item = { txid: tx.txid, sats, fee: Number(tx.fee) || 0, vsize: Number(tx.vsize) || 140, sim: !!tx.sim };
      if (tierOf(sats) === 'whale') queue.unshift(item); else queue.push(item);
      added++;
    }
    // Keep memory/backlog bounded.
    if (seen.size > 800) {
      let drop = seen.size - 600;
      for (const k of seen) { seen.delete(k); if (--drop <= 0) break; }
    }
    while (queue.length > 30) queue.pop();
    return added;
  }

  function simulatedBatch() {
    const out = [];
    const n = 3 + Math.floor(Math.random() * 4);
    for (let i = 0; i < n; i++) {
      // Log-uniform between ~2k sats and ~2 BTC, with an occasional whale.
      let sats = Math.pow(10, rand(3.3, 8.3));
      if (Math.random() < 0.04) sats = rand(1e8, 6e8);
      const vsize = Math.round(rand(110, 600));
      out.push({ txid: 'sim' + Math.random().toString(16).slice(2), value: Math.round(sats), fee: Math.round(vsize * rand(1, 40)), vsize, sim: true });
    }
    return out;
  }

  async function poll() {
    if (document.hidden) { pollTimer = setTimeout(poll, 1000); return; }
    const ctl = new AbortController();
    const to = setTimeout(() => ctl.abort(), CONFIG.requestTimeoutMs);
    try {
      const res = await fetch(CONFIG.apiBase + '/mempool/recent', { signal: ctl.signal, cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const list = await res.json();
      if (!Array.isArray(list)) throw new Error('bad payload');
      failures = 0;
      interval = CONFIG.pollMs;
      setStatus('live');
      enqueue(list);
    } catch (e) {
      failures++;
      interval = Math.min(Math.round(interval * 1.6), CONFIG.pollMaxMs);
      if (failures >= CONFIG.failuresBeforeSim) {
        setStatus('sim');
        enqueue(simulatedBatch());
      }
    } finally {
      clearTimeout(to);
    }
    pollTimer = setTimeout(poll, interval);
  }

  /* ================= Frame loop ================= */
  function update(dt, nowMs) {
    // Spawn from the queue at a relaxed pace.
    if (queue.length && fish.length < maxFish() && nowMs >= nextSpawnAt) {
      spawnFish(queue.shift());
      nextSpawnAt = nowMs + rand(180, Math.max(260, interval / Math.max(1, queue.length + 1)));
    }

    updateFish(dt);

    for (const b of bubbles) {
      b.y -= b.vy * dt;
      b.phase += dt;
      if (b.y < -10) Object.assign(b, newBubble(false));
    }
    for (let i = particles.length - 1; i >= 0; i--) {
      const p = particles[i];
      p.age += dt;
      p.x += p.vx * dt; p.y += p.vy * dt;
      p.vx *= 0.96; p.vy = p.vy * 0.96 - 18 * dt;
      if (p.age >= p.life) particles.splice(i, 1);
    }
    for (let i = floaters.length - 1; i >= 0; i--) {
      const f = floaters[i];
      f.age += dt;
      f.y -= 38 * dt;
      if (f.age > 1.1) floaters.splice(i, 1);
    }
    if (flash > 0) flash = Math.max(0, flash - dt * 1.8);
    if (combo > 1 && nowMs - lastCatchAt > CONFIG.comboWindowMs) { combo = 0; updateHud(); }
  }

  function draw(time) {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, W, H);

    // Light rays
    ctx.save();
    for (let i = 0; i < 4; i++) {
      const a = 0.05 + 0.03 * Math.sin(time * 0.5 + i * 1.7);
      const x = W * (0.12 + i * 0.26) + Math.sin(time * 0.2 + i) * 30;
      ctx.fillStyle = 'rgba(190,235,255,' + a.toFixed(3) + ')';
      ctx.beginPath();
      ctx.moveTo(x, 0);
      ctx.lineTo(x + 70 * S, 0);
      ctx.lineTo(x + 70 * S - 140 * S, H);
      ctx.lineTo(x - 140 * S, H);
      ctx.closePath();
      ctx.fill();
    }
    ctx.restore();

    // Seabed + seaweed
    const sandH = 26 * S;
    const sand = ctx.createLinearGradient(0, H - sandH, 0, H);
    sand.addColorStop(0, '#2b4a4a');
    sand.addColorStop(1, '#1a2f33');
    ctx.fillStyle = sand;
    ctx.fillRect(0, H - sandH, W, sandH);
    for (const w of weeds) {
      ctx.strokeStyle = 'hsla(' + w.hue + ',55%,32%,0.85)';
      ctx.lineWidth = 5 * S;
      ctx.lineCap = 'round';
      const sway = Math.sin(time * 1.1 + w.phase) * 14 * S;
      ctx.beginPath();
      ctx.moveTo(w.x, H - sandH + 4);
      ctx.quadraticCurveTo(w.x + sway, H - sandH - w.h * 0.55, w.x + sway * 1.4, H - sandH - w.h);
      ctx.stroke();
    }

    // Ambient bubbles
    ctx.fillStyle = 'rgba(200,235,255,0.22)';
    for (const b of bubbles) {
      ctx.beginPath();
      ctx.arc(b.x + Math.sin(b.phase) * b.sway, b.y, b.r, 0, Math.PI * 2);
      ctx.fill();
    }

    // Banner + catch card sit under the animals, so fish swim in front of them
    drawBanner();
    drawCatchCard();

    // Fish: small first so big ones overlap them
    const ordered = fish.slice().sort((a, b) => a.r - b.r);
    for (const f of ordered) drawFish(f, time);

    // Catch particles
    for (const p of particles) {
      const a = 1 - p.age / p.life;
      ctx.fillStyle = p.gold ? 'rgba(255,214,102,' + a.toFixed(2) + ')' : 'rgba(210,240,255,' + a.toFixed(2) + ')';
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r * (0.5 + a * 0.5), 0, Math.PI * 2);
      ctx.fill();
    }

    // Floating score text
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const f of floaters) {
      const a = clamp(1.1 - f.age, 0, 1);
      ctx.font = '800 ' + (f.big ? 26 : 18) + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.lineWidth = 4;
      ctx.strokeStyle = 'rgba(2,18,30,' + (a * 0.8).toFixed(2) + ')';
      ctx.strokeText(f.text, f.x, f.y);
      ctx.fillStyle = (f.big ? 'rgba(255,224,138,' : 'rgba(255,255,255,') + a.toFixed(2) + ')';
      ctx.fillText(f.text, f.x, f.y);
    }

    if (flash > 0) {
      ctx.fillStyle = 'rgba(255,214,102,' + (flash * 0.3).toFixed(3) + ')';
      ctx.fillRect(0, 0, W, H);
    }
  }

  function frame(ts) {
    if (!running) return;
    raf = requestAnimationFrame(frame);
    const dt = Math.min(0.05, (ts - last) / 1000 || 0);
    last = ts;
    update(dt, ts);
    draw(ts / 1000);
  }

  function start() {
    if (running) return;
    running = true;
    last = performance.now();
    raf = requestAnimationFrame(frame);
  }

  function stop() {
    running = false;
    cancelAnimationFrame(raf);
  }

  /* ================= Wire up ================= */
  canvas.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    sfx.unlock();   // first tap unlocks audio (browser autoplay rules)
    const rect = canvas.getBoundingClientRect();
    const px = e.clientX - rect.left, py = e.clientY - rect.top;
    const f = pointFish(px, py);
    if (f) {
      catchFish(f, performance.now());   // an animal in front always wins over the card behind it
    } else if (catchLink && catchState && catchState.url &&
               px >= catchLink.x && px <= catchLink.x + catchLink.w &&
               py >= catchLink.y && py <= catchLink.y + catchLink.h) {
      window.open(catchState.url, '_blank', 'noopener,noreferrer');
    } else {
      sfx.miss();
    }
  });

  const soundBtn = $('sound');
  function renderSoundBtn() {
    const on = sfx.isOn();
    soundBtn.textContent = on ? '🔊' : '🔇';
    soundBtn.classList.toggle('off', !on);
    soundBtn.setAttribute('aria-pressed', String(on));
    soundBtn.setAttribute('aria-label', on ? 'Sound on' : 'Sound off');
  }
  soundBtn.addEventListener('click', () => { sfx.toggle(); renderSoundBtn(); });
  renderSoundBtn();

  // Help panel (? button). Opens automatically on a player's first visit.
  const helpPanel = $('helpPanel');
  function openHelp() {
    helpPanel.hidden = false;
    $('helpOk').focus({ preventScroll: true });
    try { localStorage.setItem(CONFIG.helpKey, '1'); } catch (e) { /* ignore */ }
  }
  function closeHelp() { helpPanel.hidden = true; sfx.unlock(); }
  $('help').addEventListener('click', openHelp);
  $('helpClose').addEventListener('click', closeHelp);
  $('helpOk').addEventListener('click', closeHelp);
  helpPanel.addEventListener('click', (e) => { if (e.target === helpPanel) closeHelp(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !helpPanel.hidden) closeHelp(); });

  // Full screen. Uses the real Fullscreen API where the browser allows it (desktop, Android,
  // iPad). Where it doesn't (iPhone Safari, or an iframe without permission) it asks the
  // parent page to stretch the popup to fill the screen instead. Hidden if neither is possible.
  const fsBtn = $('fullscreen');
  const root = document.documentElement;
  const inFrame = window.parent !== window;
  // iPhone/iPad (every iOS browser is WebKit) can pop up "It looks like you are typing while in full
  // screen" even on pages with no text fields, and it can't be switched off. There we skip the real
  // Fullscreen API and expand the game to fill the screen instead.
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const fsApi = !isIOS && !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
  let maximized = false;

  const fsElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;

  function renderFs() {
    const real = !!fsElement();
    document.body.classList.toggle('is-fs', real);
    document.body.classList.toggle('is-max', maximized && !real);
    const on = real || maximized;
    fsBtn.setAttribute('aria-label', on ? 'Exit full screen' : 'Full screen');
    fsBtn.title = on ? 'Exit full screen' : 'Full screen';
    setTimeout(resize, 120);   // let the layout settle, then fit the canvas
  }

  function toggleMaximize() {
    if (!inFrame) return;
    maximized = !maximized;
    window.parent.postMessage({ type: 'game-maximize', on: maximized }, '*');
    renderFs();
  }

  async function toggleFullscreen() {
    sfx.unlock();
    if (fsElement()) {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      return;
    }
    if (maximized) { toggleMaximize(); return; }
    if (fsApi) {
      const req = root.requestFullscreen || root.webkitRequestFullscreen;
      try {
        // Some embedded browsers never answer the request, so don't wait forever.
        const p = req.call(root);
        await Promise.race([
          p && p.then ? p : new Promise((r) => setTimeout(r, 500)),
          new Promise((_, rej) => setTimeout(() => rej(new Error('fullscreen timeout')), 900)),
        ]);
        if (fsElement()) return;
      } catch (e) { /* fall back to maximise below */ }
      if (fsElement()) return;
    }
    if (!maximized) toggleMaximize();   // (a fullscreenerror may already have done it)
  }

  if (fsApi || inFrame) {
    fsBtn.hidden = false;
    fsBtn.addEventListener('click', toggleFullscreen);
    document.addEventListener('fullscreenchange', renderFs);
    document.addEventListener('webkitfullscreenchange', renderFs);
    const onFsError = () => { if (!fsElement() && !maximized) toggleMaximize(); };
    document.addEventListener('fullscreenerror', onFsError);
    document.addEventListener('webkitfullscreenerror', onFsError);
  }

  document.addEventListener('visibilitychange', () => {
    sfx.visibility(document.hidden);
    if (document.hidden) stop(); else start();
  });
  window.addEventListener('resize', resize);
  window.addEventListener('orientationchange', () => setTimeout(resize, 150));

  buildLegend();
  buildHelpTiers();
  resize();
  updateHud();
  setStatus('connecting');
  start();
  poll();

  if (CONFIG.helpOnFirstVisit) {
    let seen = false;
    try { seen = localStorage.getItem(CONFIG.helpKey) === '1'; } catch (e) { /* ignore */ }
    if (!seen) openHelp();
  }
})();
