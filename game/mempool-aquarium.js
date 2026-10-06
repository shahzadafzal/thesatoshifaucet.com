/* Mempool Aquarium — The Satoshi Faucet
 *
 * Every fish is a real, unconfirmed Bitcoin transaction streamed from mempool.space
 * straight to the visitor's browser (nothing goes through our server). Fish size grows
 * with the log of the transaction's total output value; colour comes from its fee rate.
 * Tap fish to catch them. Play-money points only, best score kept in localStorage.
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

    points: { shrimp: 1, fish: 3, shark: 10, whale: 50 },
    comboWindowMs: 1800,
    comboMax: 5,

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

    storageKey: 'satoshiFaucet.aquarium.best',
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

  function drawFish(f, time) {
    const r = f.r;
    const wag = Math.sin(time * 7 + f.phase * 2) * 0.28;
    const isWhale = f.tier === 'whale';

    ctx.save();
    ctx.translate(f.x, f.y);
    ctx.scale(f.dir, 1);

    if (isWhale) {
      ctx.shadowColor = 'rgba(255, 200, 60, 0.85)';
      ctx.shadowBlur = 24;
    }

    const sat = isWhale ? 95 : f.tier === 'shark' ? 25 : 80;
    const lightTop = isWhale ? 62 : 58;
    const lightBot = isWhale ? 42 : 38;
    const top = 'hsl(' + f.hue + ',' + sat + '%,' + lightTop + '%)';
    const bot = 'hsl(' + f.hue + ',' + sat + '%,' + lightBot + '%)';
    const fin = 'hsl(' + f.hue + ',' + sat + '%,' + (lightBot - 8) + '%)';

    // Tail
    ctx.save();
    ctx.translate(-r * 0.82, 0);
    ctx.rotate(wag);
    ctx.fillStyle = fin;
    ctx.beginPath();
    ctx.moveTo(r * 0.1, 0);
    ctx.lineTo(-r * 0.62, -r * 0.5);
    ctx.quadraticCurveTo(-r * 0.4, 0, -r * 0.62, r * 0.5);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // Dorsal fin
    const finH = f.tier === 'shark' ? 0.62 : 0.4;
    ctx.fillStyle = fin;
    ctx.beginPath();
    ctx.moveTo(-r * 0.25, -r * 0.5);
    ctx.quadraticCurveTo(r * 0.05, -r * (0.5 + finH), r * 0.38, -r * 0.5);
    ctx.closePath();
    ctx.fill();

    // Body
    const g = ctx.createLinearGradient(0, -r * 0.6, 0, r * 0.6);
    g.addColorStop(0, top);
    g.addColorStop(1, bot);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.ellipse(0, 0, r, r * 0.58, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.shadowBlur = 0;

    // Belly highlight
    ctx.fillStyle = 'rgba(255,255,255,0.18)';
    ctx.beginPath();
    ctx.ellipse(r * 0.05, r * 0.2, r * 0.7, r * 0.22, 0, 0, Math.PI * 2);
    ctx.fill();

    // Gill line
    ctx.strokeStyle = 'rgba(0,0,0,0.22)';
    ctx.lineWidth = Math.max(1, r * 0.04);
    ctx.beginPath();
    ctx.arc(r * 0.32, 0, r * 0.3, Math.PI * 0.62, Math.PI * 1.38);
    ctx.stroke();

    // Eye
    const er = Math.max(2.2, r * 0.13);
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.arc(r * 0.56, -r * 0.12, er, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#10202b';
    ctx.beginPath();
    ctx.arc(r * 0.6, -r * 0.12, er * 0.52, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();

    // Label (drawn unmirrored)
    if (r >= 26 * S || isWhale) {
      const fs = Math.round(clamp(r * 0.22, 10, 15));
      ctx.font = '700 ' + fs + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      const label = (isWhale ? TIERS.whale.icon + ' ' : '') + fmtValue(f.sats);
      ctx.lineWidth = 3;
      ctx.strokeStyle = 'rgba(2,18,30,0.8)';
      ctx.strokeText(label, f.x, f.y - r * 0.62 - 3);
      ctx.fillStyle = isWhale ? '#ffe08a' : '#e8f6ff';
      ctx.fillText(label, f.x, f.y - r * 0.62 - 3);
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

    combo = (now - lastCatchAt <= CONFIG.comboWindowMs) ? Math.min(combo + 1, CONFIG.comboMax) : 1;
    lastCatchAt = now;
    const pts = CONFIG.points[f.tier] * combo;
    score += pts;
    caught += 1;
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
    floaters.push({ x: f.x, y: f.y - f.r * 0.5, text: '+' + pts + (combo > 1 ? ' x' + combo : ''), age: 0, big: f.tier === 'whale' });
    if (f.tier === 'whale') flash = 1;

    if (f.tier === 'whale') sfx.catchWhale();
    else if (f.tier === 'shark') sfx.catchShark(combo);
    else sfx.catchSmall(combo);

    updateHud();
    showCatch(f);
  }

  /* ================= HUD ================= */
  let bannerTimer = 0;
  function showBanner(text) {
    const el = $('banner');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(bannerTimer);
    bannerTimer = setTimeout(() => { el.hidden = true; }, 3500);
  }

  function updateHud() {
    $('score').textContent = score.toLocaleString('en-US');
    $('best').textContent = best.toLocaleString('en-US');
    const c = $('combo');
    if (combo > 1) { c.textContent = 'x' + combo; c.hidden = false; c.style.animation = 'none'; void c.offsetWidth; c.style.animation = ''; }
    else c.hidden = true;
  }

  function showCatch(f) {
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
    const f = pointFish(e.clientX - rect.left, e.clientY - rect.top);
    if (f) catchFish(f, performance.now());
    else sfx.miss();
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

  document.addEventListener('visibilitychange', () => {
    sfx.visibility(document.hidden);
    if (document.hidden) stop(); else start();
  });
  window.addEventListener('resize', resize);
  window.addEventListener('orientationchange', () => setTimeout(resize, 150));

  buildLegend();
  resize();
  updateHud();
  setStatus('connecting');
  start();
  poll();
})();
