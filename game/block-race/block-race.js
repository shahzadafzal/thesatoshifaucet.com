/* Block Race — The Satoshi Faucet
 *
 * Predict what the next Bitcoin block will look like: when it lands, how many transactions it
 * holds and its median fee rate. Everything is streamed live from mempool.space straight to the
 * visitor's browser (WebSocket, with a REST fallback), so our server carries no load apart from
 * one tiny faucet-cooldown check. Scoring is play money, shown in sats.
 *
 * Optional "Play for real sats": the guesses are also sent to ../../block-race.php at lock-in. The
 * server grades them against the real block itself (the score shown here is never trusted) and turns
 * the score into a normal faucet claim (1% = 1 sat). See block-race-lib.php.
 */
(() => {
  'use strict';

  /* ================= Tunables ================= */
  const CONFIG = {
    satsPerGuess: 1000,              // play-money sats for a perfect guess (3 guesses per round)

    gridMain: 20,                    // the next block is drawn as a 20x20 grid
    gridSmall: 10,                   // the two blocks after it as 10x10

    wsUrl: 'wss://mempool.space/api/v1/ws',
    apiBase: 'https://mempool.space/api',
    cooldownUrl: '../../claim.php?cooldown_status=1',   // game lives in /game/block-race/
    captchaConfigUrl: '../../claim.php?captcha_config=1',
    apiUrl: '../../block-race.php',                     // play-for-sats entries
    infoRefreshMs: 30000,            // re-check paid spots this often while guessing
    ticketPollMs: 5000,              // check a paid entry's result this often once its block is found
    claimPollMs: 60000,              // ...and a queued claim's payout status this often
    maxTickets: 5,
    ticketsKey: 'satoshiFaucet.blockRace.tickets',
    restPollMs: 8000,                // fallback polling while the WebSocket is down
    wsRetryMinMs: 2000,
    wsRetryMaxMs: 30000,
    wsSilenceMs: 45000,              // reconnect if the socket goes quiet this long
    readyRecheckMs: 120000,          // re-check the cooldown this often while it shows "ready"
    requestTimeoutMs: 6000,

    defaultTimeMin: 10,              // starting value of the arrival-time slider
    feeMin: 0.1,                     // fee slider range (sat/vB, log scale)
    feeMax: 200,
    maxRain: 36,                     // falling-transaction dots on screen at once

    helpOnFirstVisit: true,
    bestKey: 'satoshiFaucet.blockRace.bestRoundSats',
    helpKey: 'satoshiFaucet.blockRace.helpSeen',
    soundKey: 'satoshiFaucet.blockRace.sound',   // '0' = muted
    masterVolume: 0.5,
  };

  /* ================= Helpers ================= */
  // `$('hintTime')` takes a plain element id (it is just document.getElementById), NOT a CSS selector,
  // so there is no "#" and no jQuery-style lookup. Don't confuse it with jQuery's $('#hintTime').
  const $ = (id) => document.getElementById(id);

  // Set an element's text, quietly skipping it if the page doesn't have that element. This keeps the game
  // running if the page file and this script ever get out of step (e.g. only one of them was uploaded).
  const setText = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const rand = (a, b) => a + Math.random() * (b - a);

  const fmtInt = (n) => Math.round(n).toLocaleString('en-US');
  const fmtSats = (n) => fmtInt(n) + ' sats';
  const fmtBtc = (n) => (n / 1e8).toFixed(8) + ' BTC';
  const fmtFee = (v) => (v < 10 ? v.toFixed(2) : v < 100 ? v.toFixed(1) : String(Math.round(v))) + ' sat/vB';
  const fmtClock = (ms) => {
    const s = Math.max(0, Math.floor(ms / 1000));
    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  };
  const fmtDur = (ms) => {
    const s = Math.max(0, Math.round(ms / 1000));
    return s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's';
  };
  const fmtMin = (m) => (m % 1 === 0 ? String(m) : m.toFixed(1)) + ' min';
  const fmtValue = (sats) => (sats >= 1e6 ? (sats / 1e8).toFixed(sats >= 1e8 ? 2 : 4) + ' BTC' : fmtInt(sats) + ' sats');

  const lsGet = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
  const lsSet = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } };

  // Fee rate -> colour. Cheap = blue, expensive = orange/red (same scale as the Aquarium).
  function feeColor(rate) {
    const t = clamp(Math.log(Math.max(rate, 0.1) / 0.1) / Math.log(300 / 0.1), 0, 1);
    return 'hsl(' + Math.round(215 - t * 205) + ',72%,50%)';
  }

  /* ================= Sound (Web Audio, no files) ================= */
  const sfx = (() => {
    let ac = null;
    let master = null;
    let voices = 0;
    let enabled = lsGet(CONFIG.soundKey) !== '0';

    function unlock() {
      if (!enabled) return;
      if (!ac) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        try { ac = new AC(); } catch (e) { return; }
        master = ac.createGain();
        master.gain.value = CONFIG.masterVolume;
        master.connect(ac.destination);
      }
      if (ac.state === 'suspended') ac.resume();
    }

    function tone(o) {
      if (!enabled || !ac || ac.state !== 'running' || voices >= 12) return;
      const t0 = ac.currentTime + (o.delay || 0);
      const dur = o.dur || 0.12;
      const osc = ac.createOscillator();
      const g = ac.createGain();
      osc.type = o.type || 'sine';
      osc.frequency.setValueAtTime(o.freq, t0);
      if (o.freq2) osc.frequency.exponentialRampToValueAtTime(o.freq2, t0 + dur);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.linearRampToValueAtTime(o.gain || 0.2, t0 + Math.min(0.02, dur / 3));
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      osc.connect(g);
      g.connect(master);
      voices++;
      osc.onended = () => { voices--; };
      osc.start(t0);
      osc.stop(t0 + dur + 0.05);
    }

    return {
      unlock,
      isOn: () => enabled,
      toggle() {
        enabled = !enabled;
        lsSet(CONFIG.soundKey, enabled ? '1' : '0');
        if (enabled) { unlock(); tone({ freq: 500, freq2: 900, dur: 0.1, gain: 0.2 }); }
        return enabled;
      },
      visibility(hidden) {
        if (!ac) return;
        if (hidden) ac.suspend(); else if (enabled) ac.resume();
      },
      lock() {
        tone({ freq: 520, freq2: 880, dur: 0.09, gain: 0.22 });
        tone({ freq: 880, freq2: 1320, dur: 0.08, gain: 0.12, delay: 0.07 });
      },
      block() {
        tone({ freq: 90, freq2: 45, dur: 0.5, gain: 0.4 });
        [523, 659, 784, 1047].forEach((f, i) => tone({ freq: f, type: 'triangle', dur: 0.25, gain: 0.18, delay: 0.12 + i * 0.09 }));
      },
      reveal(acc) {
        tone({ freq: 380 + acc * 700, freq2: 420 + acc * 800, dur: 0.16, gain: 0.2 });
      },
      ready() {
        tone({ freq: 660, type: 'triangle', dur: 0.18, gain: 0.18 });
        tone({ freq: 880, type: 'triangle', dur: 0.28, gain: 0.18, delay: 0.16 });
      },
    };
  })();

  /* ================= State ================= */
  const canvas = $('stage');
  const ctx = canvas.getContext('2d');
  const stageWrap = $('stageWrap');
  let W = 0, H = 0, dpr = 1, ts = 1;
  let bgGrad = null;

  // `blocks` remembers recent blocks by height ({height, timestamp (s), tx_count, medianFee, pool}) so the
  // previous block can be shown as the players' reference and used as the starting point for timing.
  const feed = { tip: null, lastBlockAt: null, projected: [], blocks: {}, wsOpen: false, restOk: false, status: 'connecting' };

  let geo = [];            // on-screen rectangles for up to 3 blocks
  let cells = [];          // per projected block: { side, count, rates, colors }
  let flashes = [];        // per projected block: Float32Array of last-landing times
  const rain = [];         // falling transaction dots
  const rainQueue = [];    // transactions waiting to fall
  const confetti = [];
  const seenTx = new Set();
  let lastTx = null;
  let nextRainAt = 0;
  let shake = 0;
  let flashAll = 0;

  const R = { state: 'loading', roundNo: 1, target: 0, guesses: null, lockedAt: 0 };
  const session = { total: 0 };
  let best = parseInt(lsGet(CONFIG.bestKey), 10) || 0;

  let raf = 0;
  let running = false;
  let last = 0;

  /* ================= Fee-range maths ================= */
  const PCTS = [0, 0.1, 0.25, 0.5, 0.75, 0.9, 1];

  // feeRange from mempool.space is a short list of fee rates from the cheapest to the dearest
  // transaction in the block. Interpolate it (in log space) at position q (0 = cheapest, 1 = dearest).
  function rateAtQ(range, q) {
    const n = range.length;
    const pos = n === 7 ? PCTS : range.map((_, i) => i / (n - 1));
    q = clamp(q, 0, 1);
    for (let i = 0; i < n - 1; i++) {
      if (q <= pos[i + 1]) {
        const t = (q - pos[i]) / ((pos[i + 1] - pos[i]) || 1);
        return Math.exp(lerp(Math.log(Math.max(range[i], 0.05)), Math.log(Math.max(range[i + 1], 0.05)), t));
      }
    }
    return range[n - 1];
  }

  function setProjected(arr) {
    feed.projected = arr.slice(0, 3);
    cells = feed.projected.map((p, i) => {
      const side = i === 0 ? CONFIG.gridMain : CONFIG.gridSmall;
      const count = side * side;
      const med = Number(p.medianFee) || 1;
      const range = Array.isArray(p.feeRange) && p.feeRange.length > 1 ? p.feeRange.map(Number) : [med, med];
      const rates = new Float32Array(count);
      const colors = new Array(count);
      for (let k = 0; k < count; k++) {
        const r = rateAtQ(range, 1 - k / (count - 1));   // top-left = dearest, bottom-right = cheapest
        rates[k] = r;
        colors[k] = feeColor(r);
      }
      return { side, count, rates, colors };
    });
    while (flashes.length < cells.length) flashes.push(new Float32Array((flashes.length === 0 ? CONFIG.gridMain : CONFIG.gridSmall) ** 2));
    updateHints();
    maybeStart();
  }

  /* ================= Layout & drawing ================= */
  function resizeCanvas() {
    const rect = stageWrap.getBoundingClientRect();
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = Math.max(1, Math.round(rect.width));
    H = Math.max(1, Math.round(rect.height));
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
    bgGrad = ctx.createLinearGradient(0, 0, 0, H);
    bgGrad.addColorStop(0, '#0e1524');
    bgGrad.addColorStop(1, '#070a11');
    layout();
  }

  function layout() {
    // keep the block clear of the score box (top-left) and the button column (right)
    const hud = document.querySelector('.hud-tl');
    const hudBottom = hud ? Math.max(0, hud.getBoundingClientRect().bottom - stageWrap.getBoundingClientRect().top) : 74;
    const wide = window.matchMedia('(min-width: 780px) and (min-height: 460px)').matches;
    const padL = 12, padR = wide ? 12 : 62, padT = Math.max(16, hudBottom + 8), padB = 8;
    const areaW = Math.max(120, W - padL - padR);
    const areaH = Math.max(100, H - padT - padB);
    ts = clamp(Math.min(areaW, areaH) / 330, 0.78, 1.2);
    const labelTop = 22 * ts, labelBottom = 38 * ts;
    const gap = Math.max(10, areaW * 0.04);
    let side = Math.min(areaH - labelTop - labelBottom, areaW * 0.62);
    side = Math.max(side, 70);
    const small = Math.min(side * 0.4, (side - gap) / 2);
    const totalW = side + gap + small;
    const x0 = padL + (areaW - totalW) / 2;
    const y0 = padT + Math.max(0, (areaH - side - labelTop - labelBottom) / 2) + labelTop;
    geo = [
      { x: x0, y: y0, s: side },
      { x: x0 + side + gap, y: y0 + side - small * 2 - gap, s: small },
      { x: x0 + side + gap, y: y0 + side - small, s: small },
    ];
  }

  function drawBlock(i, now) {
    const g = geo[i];
    const c = cells[i];
    if (!g || !c) return;
    const cs = g.s / c.side;
    const gp = Math.max(0.8, cs * 0.1);
    const fl = flashes[i];
    for (let k = 0; k < c.count; k++) {
      const x = g.x + (k % c.side) * cs;
      const y = g.y + Math.floor(k / c.side) * cs;
      ctx.fillStyle = c.colors[k];
      ctx.fillRect(x + gp / 2, y + gp / 2, cs - gp, cs - gp);
      const age = now - fl[k];
      if (fl[k] && age < 700) {
        ctx.fillStyle = 'rgba(255,255,255,' + (0.85 * (1 - age / 700)).toFixed(2) + ')';
        ctx.fillRect(x + gp / 2, y + gp / 2, cs - gp, cs - gp);
      }
    }

    // frame
    const pulse = R.state === 'wait' ? 0.5 + 0.5 * Math.sin(now / 350) : 0.35;
    ctx.lineWidth = i === 0 ? 2.5 : 1.5;
    ctx.strokeStyle = 'rgba(247,147,26,' + (i === 0 ? 0.45 + pulse * 0.5 : 0.35).toFixed(2) + ')';
    ctx.strokeRect(g.x - 1, g.y - 1, g.s + 2, g.s + 2);

    // labels
    const p = feed.projected[i];
    const tip = feed.tip || 0;
    ctx.textBaseline = 'alphabetic';
    ctx.textAlign = 'left';
    if (i === 0) {
      ctx.font = '800 ' + Math.round(13 * ts) + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillStyle = '#f7931a';
      ctx.fillText('NEXT BLOCK  #' + fmtInt(tip + 1), g.x, g.y - 8 * ts);
      // (the projected tx count / fee are deliberately not shown: players estimate from the last block)
      ctx.font = '600 ' + Math.round(11 * ts) + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillStyle = '#8a97ad';
      ctx.fillText('↑ highest fees at the top', g.x, g.y + g.s + 16 * ts);
    } else {
      ctx.font = '700 ' + Math.round(11 * ts) + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillStyle = '#8a97ad';
      ctx.fillText('#' + fmtInt(tip + 1 + i), g.x, g.y - 4 * ts);
    }
  }

  function draw(now) {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, W, H);

    if (!cells.length || !geo.length) {
      ctx.fillStyle = '#8a97ad';
      ctx.font = '600 14px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('Waiting for mempool.space…', W / 2, H / 2);
      return;
    }

    // soft glow behind the next block
    const g0 = geo[0];
    const glow = ctx.createRadialGradient(g0.x + g0.s / 2, g0.y + g0.s / 2, g0.s * 0.2, g0.x + g0.s / 2, g0.y + g0.s / 2, g0.s * 0.95);
    glow.addColorStop(0, 'rgba(247,147,26,0.12)');
    glow.addColorStop(1, 'rgba(247,147,26,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, W, H);

    ctx.save();
    if (shake > 0) ctx.translate((Math.random() - 0.5) * shake * 9, (Math.random() - 0.5) * shake * 6);

    for (let i = cells.length - 1; i >= 0; i--) drawBlock(i, now);

    // falling transactions
    for (const p of rain) {
      const e = p.t * p.t;
      const x = lerp(p.x0, p.tx, p.t);
      const y = lerp(p.y0, p.ty, e);
      const a = p.bi >= 0 ? 1 : clamp(1.2 - p.t, 0, 1);
      ctx.globalAlpha = a;
      ctx.fillStyle = p.color;
      ctx.beginPath();
      ctx.arc(x, y, Math.max(2.5, 3.5 * ts), 0, Math.PI * 2);
      ctx.fill();
      ctx.globalAlpha = a * 0.35;
      ctx.fillRect(x - 1, y - 14 * ts * p.t, 2, 14 * ts * p.t);
    }
    ctx.globalAlpha = 1;

    // block-found confetti
    for (const c of confetti) {
      ctx.globalAlpha = clamp(1 - c.age / c.life, 0, 1);
      ctx.fillStyle = c.color;
      ctx.fillRect(c.x, c.y, c.r, c.r);
    }
    ctx.globalAlpha = 1;
    ctx.restore();

    // latest transaction ticker
    if (lastTx) {
      ctx.textAlign = 'left';
      ctx.font = '600 ' + Math.round(11 * ts) + 'px -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillStyle = lastTx.color;
      ctx.fillText('↓ new tx ' + fmtValue(lastTx.value) + ' · ' + fmtFee(lastTx.rate), g0.x, g0.y + g0.s + 31 * ts);
    }

    if (flashAll > 0) {
      ctx.fillStyle = 'rgba(255,255,255,' + (flashAll * 0.45).toFixed(3) + ')';
      ctx.fillRect(0, 0, W, H);
    }
  }

  function update(dt, now) {
    // spawn queued transactions at a relaxed pace
    if (rainQueue.length && cells.length && geo.length && rain.length < CONFIG.maxRain && now >= nextRainAt) {
      spawnRain(rainQueue.shift(), now);
      nextRainAt = now + rand(90, 230);
    }
    for (let i = rain.length - 1; i >= 0; i--) {
      const p = rain[i];
      p.t += dt / p.dur;
      if (p.t >= 1) {
        if (p.bi >= 0 && flashes[p.bi]) flashes[p.bi][p.k] = now;
        rain.splice(i, 1);
      }
    }
    for (let i = confetti.length - 1; i >= 0; i--) {
      const c = confetti[i];
      c.age += dt;
      c.x += c.vx * dt;
      c.y += c.vy * dt;
      c.vy += 420 * dt;
      if (c.age >= c.life) confetti.splice(i, 1);
    }
    if (shake > 0) shake = Math.max(0, shake - dt * 2.2);
    if (flashAll > 0) flashAll = Math.max(0, flashAll - dt * 1.6);
  }

  function frame(ts2) {
    if (!running) return;
    raf = requestAnimationFrame(frame);
    const dt = Math.min(0.05, (ts2 - last) / 1000 || 0);
    last = ts2;
    update(dt, ts2);
    draw(ts2);
  }

  function start() {
    if (running) return;
    running = true;
    last = performance.now();
    raf = requestAnimationFrame(frame);
  }
  function stop() { running = false; cancelAnimationFrame(raf); }

  /* ================= Falling transactions ================= */
  function addRain(txs) {
    for (const t of txs) {
      if (!t || typeof t.txid !== 'string' || seenTx.has(t.txid)) continue;
      const rate = Number(t.rate) || (Number(t.vsize) > 0 ? Number(t.fee) / Number(t.vsize) : 0);
      if (!(rate > 0)) continue;
      seenTx.add(t.txid);
      rainQueue.push({ rate, value: Number(t.value) || 0 });
    }
    if (seenTx.size > 600) {
      let drop = seenTx.size - 400;
      for (const k of seenTx) { seenTx.delete(k); if (--drop <= 0) break; }
    }
    while (rainQueue.length > 50) rainQueue.shift();
  }

  function spawnRain(t) {
    // Which projected block would this transaction land in? The first whose cheapest fee it meets.
    let bi = -1;
    for (let i = 0; i < cells.length; i++) {
      if (t.rate >= cells[i].rates[cells[i].count - 1] * 0.999) { bi = i; break; }
    }
    const g0 = geo[0];
    const x0 = g0.x + rand(0, g0.s);
    const y0 = Math.max(8, g0.y - 30 * ts);
    let tx, ty, k = -1;
    if (bi >= 0) {
      const c = cells[bi];
      // rates run from dearest (k=0) to cheapest: find the first cell at or below this rate
      let lo = 0, hi = c.count - 1;
      while (lo < hi) {
        const mid = (lo + hi) >> 1;
        if (c.rates[mid] <= t.rate) hi = mid; else lo = mid + 1;
      }
      k = clamp(lo + Math.floor(rand(-5, 6)), 0, c.count - 1);
      const g = geo[bi];
      const cs = g.s / c.side;
      tx = g.x + (k % c.side) * cs + cs / 2;
      ty = g.y + Math.floor(k / c.side) * cs + cs / 2;
    } else {
      // too cheap for any projected block: it sinks past the bottom
      tx = g0.x + rand(0, g0.s);
      ty = g0.y + g0.s + 40 * ts;
    }
    rain.push({ x0, y0, tx, ty, bi, k, t: 0, dur: rand(0.7, 1.1), color: feeColor(t.rate) });
    lastTx = { rate: t.rate, value: t.value, color: feeColor(t.rate) };
  }

  function blockFx() {
    shake = 1;
    flashAll = 0.8;
    const g = geo[0];
    const c = cells[0];
    if (!g || !c) return;
    for (let i = 0; i < 90; i++) {
      const a = rand(0, Math.PI * 2);
      const sp = rand(80, 360);
      confetti.push({
        x: g.x + g.s / 2, y: g.y + g.s / 2,
        vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 120,
        r: rand(3, 7) * ts, age: 0, life: rand(0.9, 1.6),
        color: c.colors[Math.floor(rand(0, c.count))],
      });
    }
  }

  /* ================= Data feed ================= */
  async function getJson(path) {
    const ctl = new AbortController();
    const to = setTimeout(() => ctl.abort(), CONFIG.requestTimeoutMs);
    try {
      const res = await fetch(CONFIG.apiBase + path, { signal: ctl.signal, cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return await res.json();
    } finally {
      clearTimeout(to);
    }
  }

  const newest = (list) => list.reduce((a, b) => (b.height > a.height ? b : a));

  // Remember what we know about a block (never overwrite a known value with a missing one).
  function recordBlock(b) {
    if (!b || typeof b.height !== 'number') return;
    const old = feed.blocks[b.height] || {};
    const ex = b.extras || {};
    feed.blocks[b.height] = {
      height: b.height,
      timestamp: Number(b.timestamp) || old.timestamp || 0,
      tx_count: Number(b.tx_count) || old.tx_count || 0,
      medianFee: typeof ex.medianFee === 'number' ? ex.medianFee : (old.medianFee != null ? old.medianFee : null),
      pool: (ex.pool && ex.pool.name) || old.pool || null,
    };
    // keep the map small
    const keys = Object.keys(feed.blocks);
    if (keys.length > 40) {
      keys.map(Number).sort((a, c) => a - c).slice(0, keys.length - 30).forEach((k) => { delete feed.blocks[k]; });
    }
  }

  function noteBlock(b) {
    if (!b || typeof b.height !== 'number') return;
    recordBlock(b);
    if (feed.tip == null) {
      feed.tip = b.height;
      feed.lastBlockAt = (Number(b.timestamp) || 0) * 1000 || Date.now();
      maybeStart();
      return;
    }
    if (b.height > feed.tip) {
      const prevAt = feed.lastBlockAt;
      feed.tip = b.height;
      feed.lastBlockAt = Date.now();
      handleNewBlock(b, prevAt);
    }
  }

  // The last block's details are the players' reference numbers.
  function lastBlock() { return feed.tip != null ? feed.blocks[feed.tip] || null : null; }

  // Seconds between the last block and the one before it (miner clocks are approximate, so never negative).
  function lastGapSeconds() {
    const a = lastBlock();
    const p = feed.tip != null ? feed.blocks[feed.tip - 1] : null;
    if (!a || !p || !a.timestamp || !p.timestamp) return null;
    return Math.max(0, a.timestamp - p.timestamp);
  }

  function setStatus(kind) {
    feed.status = kind;
    $('dot').className = 'dot ' + (kind === 'live' ? 'live' : kind === 'poll' ? 'poll' : kind === 'off' ? 'off' : '');
    $('statusText').textContent = kind === 'live' ? 'Live' : kind === 'poll' ? 'Live (polling)' : kind === 'off' ? 'Offline' : 'Connecting…';
    const msg = $('stageMsg');
    if (kind === 'off' && !cells.length) {
      msg.textContent = "Can't reach mempool.space right now. Retrying…";
      msg.hidden = false;
    } else {
      msg.hidden = true;
    }
  }

  let ws = null;
  let wsRetry = 0;
  let wsTimer = 0;
  let lastMsgAt = 0;

  function connectWS() {
    if (ws && (ws.readyState === 0 || ws.readyState === 1)) return;
    try { ws = new WebSocket(CONFIG.wsUrl); } catch (e) { scheduleWS(); return; }
    ws.onopen = () => {
      feed.wsOpen = true;
      wsRetry = 0;
      lastMsgAt = Date.now();
      ws.send(JSON.stringify({ action: 'init' }));
      ws.send(JSON.stringify({ action: 'want', data: ['blocks', 'mempool-blocks', 'stats'] }));
      setStatus('live');
    };
    ws.onmessage = onWsMessage;
    ws.onclose = () => {
      feed.wsOpen = false;
      setStatus(feed.restOk ? 'poll' : 'off');
      scheduleWS();
    };
    ws.onerror = () => { try { ws.close(); } catch (e) { /* ignore */ } };
  }

  function scheduleWS() {
    clearTimeout(wsTimer);
    const delay = Math.min(CONFIG.wsRetryMaxMs, CONFIG.wsRetryMinMs * Math.pow(2, wsRetry++));
    wsTimer = setTimeout(connectWS, delay);
  }

  function onWsMessage(e) {
    lastMsgAt = Date.now();
    let m;
    try { m = JSON.parse(e.data); } catch (err) { return; }
    if (Array.isArray(m.blocks) && m.blocks.length) {
      m.blocks.forEach(recordBlock);          // remember all of them (the previous block is the reference)
      noteBlock(newest(m.blocks));
    }
    if (m.block) noteBlock(m.block);
    if (Array.isArray(m['mempool-blocks'])) setProjected(m['mempool-blocks']);
    if (Array.isArray(m.transactions)) addRain(m.transactions);
  }

  // REST fallback while the WebSocket is down.
  async function restTick() {
    if (feed.wsOpen || document.hidden) return;
    try {
      const [bl, mb, rec] = await Promise.all([
        getJson('/v1/blocks'), getJson('/v1/fees/mempool-blocks'), getJson('/mempool/recent'),
      ]);
      feed.restOk = true;
      if (Array.isArray(bl) && bl.length) {
        bl.forEach(recordBlock);
        noteBlock(newest(bl));
      }
      if (Array.isArray(mb)) setProjected(mb);
      if (Array.isArray(rec)) addRain(rec);
      if (!feed.wsOpen) setStatus('poll');
    } catch (e) {
      feed.restOk = false;
      if (!feed.wsOpen) setStatus('off');
    }
  }

  // A new block's WebSocket payload normally carries `extras` (median fee, pool); fetch it if not.
  async function withExtras(b) {
    if (b.extras && typeof b.extras.medianFee === 'number') return b;
    try {
      const list = await getJson('/v1/blocks/' + b.height);
      const f = Array.isArray(list) ? list.find((x) => x.height === b.height) : null;
      if (f) return f;
    } catch (e) { /* use what we have */ }
    return b;
  }

  /* ================= Cooldown (faucet) ================= */
  const cd = { known: false, ready: false, endsAt: null, wasCounting: false, lastCheck: 0, checking: false };

  async function checkCooldown() {
    cd.checking = true;
    try {
      const res = await fetch(CONFIG.cooldownUrl, { cache: 'no-store' });
      const j = await res.json();
      cd.lastCheck = Date.now();
      if (j && j.ok && typeof j.remaining_seconds === 'number') {
        cd.known = true;
        if (j.remaining_seconds > 0) {
          cd.endsAt = Date.now() + j.remaining_seconds * 1000;
          cd.ready = false;
          cd.wasCounting = true;
        } else {
          cd.endsAt = null;
          cd.ready = true;
        }
      }
    } catch (e) { /* cooldown info is optional */ }
    cd.checking = false;
  }

  function tickCooldown() {
    const now = Date.now();
    if (cd.endsAt && now >= cd.endsAt && !cd.checking) {
      cd.endsAt = null;
      checkCooldown().then(() => {
        if (cd.ready && cd.wasCounting) {
          cd.wasCounting = false;
          sfx.ready();
          showNote('✅ The faucet is ready! Go claim your sats — you can keep playing here too.', 9000);
        }
      });
    } else if (cd.ready && !cd.checking && now - cd.lastCheck > CONFIG.readyRecheckMs) {
      checkCooldown();
    }
    const el = $('cooldownTxt');
    if (!cd.known) { el.hidden = true; return; }
    el.hidden = false;
    if (cd.ready) {
      el.textContent = '✅ Faucet ready — go claim your sats!';
      el.className = 'ok';
    } else {
      el.textContent = '⏳ Faucet cooldown: ' + fmtClock((cd.endsAt || now) - now);
      el.className = '';
    }
  }

  /* ================= Play for real sats ================= */
  // `paid.info` is the server's view of the current block (spots left, when paid entries close).
  // Each paid entry is a "ticket" {token, target, entry, nextPoll} kept in localStorage so a reload doesn't lose it.
  const paid = { info: null, infoAt: 0, closesAt: 0, loading: false, captcha: null, tickets: [] };

  async function api(action, data) {
    try {
      let res;
      if (!data) {
        res = await fetch(CONFIG.apiUrl + '?action=' + action, { cache: 'no-store' });
      } else {
        const fd = new FormData();
        fd.append('action', action);
        for (const k of Object.keys(data)) if (data[k] != null) fd.append(k, data[k]);
        res = await fetch(CONFIG.apiUrl, { method: 'POST', body: fd, cache: 'no-store' });
      }
      return await res.json();
    } catch (e) {
      return { ok: false, error: 'network', message: "Couldn't reach the faucet server. Please try again." };
    }
  }

  async function loadInfo() {
    if (paid.loading) return;
    paid.loading = true;
    const j = await api('info');
    paid.loading = false;
    paid.infoAt = Date.now();
    // A failed refresh keeps the last good answer, so the box doesn't flicker away mid-captcha.
    const keepOld = (!j || !j.ok) && j && j.error !== 'disabled' && paid.info && paid.info.ok;
    paid.infoErr = !!keepOld;
    if (!keepOld) paid.info = j;
    if (j && j.ok) {
      paid.closesAt = Date.now() + j.closesIn * 1000;
      setText('helpRealMax', fmtSats(j.maxSats));
      setText('helpSpots', String(j.spotsTotal));
      setText('helpWindow', String(Math.round(j.lockWindow / 60)));
    }
    renderPaid();
  }

  // The "Play for real sats" box. Once shown it stays put: when paid entries are closed it is
  // disabled (greyed, with the reason) instead of hidden, so nothing jumps while someone solves the captcha.
  function renderPaid() {
    const box = $('paidBox');
    const info = paid.info;
    // hidden only before the first answer, when the feature is switched off, or outside the guess view
    if (!info || (!info.ok && info.error === 'disabled') || R.state !== 'guess') { box.hidden = true; return; }
    box.hidden = false;

    const now = Date.now();
    const secsLeft = Math.max(0, Math.round((paid.closesAt - now) / 1000));
    const entered = paid.tickets.some((t) => t.target === R.target);
    let closed = '';
    if (!info.ok) closed = "Can't reach the faucet server right now — play for fun, we'll keep trying.";
    else if (info.target !== R.target) closed = 'Checking the latest block…';
    else if (entered) closed = "✅ You're already entered for sats on this block.";
    else if (info.spotsLeft <= 0) closed = 'All ' + info.spotsTotal + ' paid spots for block #' + fmtInt(R.target) + ' are taken — play for fun and be quicker next block!';
    else if (secsLeft <= 0) closed = 'Paid entries for block #' + fmtInt(R.target) + ' closed ' + Math.round(info.lockWindow / 60) + ' min after the last block — play for fun and try again next block!';

    const toggle = $('paidOn');
    toggle.disabled = !!closed;
    box.classList.toggle('is-closed', !!closed);
    $('lnurlIn').disabled = !!closed;
    // keep the tick and the fields (LNURL, captcha) on screen; they come back to life when entries reopen
    $('paidFields').hidden = !toggle.checked;
    $('paidClosed').hidden = !closed;
    $('paidClosed').textContent = closed;
    $('paidSpots').textContent = !info.ok ? '' : closed && !entered && info.target === R.target
      ? (info.spotsLeft <= 0 ? 'No spots left' : 'Closed')
      : closed ? '' : info.spotsLeft + ' of ' + info.spotsTotal + ' spots left · closes in ' + fmtClock(secsLeft * 1000);
    if (info.ok) setText('paidHint', 'Your score becomes sats: 1% = 1 sat, up to ' + fmtSats(info.maxSats) + '. The LNURL can be added later, even after the block is found.');
  }

  // hCaptcha (or reCAPTCHA, whichever the faucet uses), loaded only when someone ticks "Play for real sats".
  function ensureCaptcha() {
    if (paid.captcha) return;
    paid.captcha = { api: null, id: null, provider: 'hcaptcha' };
    fetch(CONFIG.captchaConfigUrl, { cache: 'no-store' }).then((r) => r.json()).then((cfg) => {
      const google = cfg.provider === 'google';
      paid.captcha.provider = google ? 'google' : 'hcaptcha';
      window.blockRaceCaptchaReady = () => {
        const capi = google ? window.grecaptcha : window.hcaptcha;
        paid.captcha.api = capi;
        paid.captcha.id = capi.render('captchaBox', {
          sitekey: cfg.siteKey,
          theme: 'dark',
          size: $('captchaBox').clientWidth && $('captchaBox').clientWidth < 300 ? 'compact' : 'normal',
        });
      };
      const s = document.createElement('script');
      s.src = google
        ? 'https://www.google.com/recaptcha/api.js?render=explicit&onload=blockRaceCaptchaReady'
        : 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=blockRaceCaptchaReady';
      s.async = true;
      s.onerror = () => { paid.captcha = null; showNote("The CAPTCHA couldn't load. Check your connection and tick the box again."); };
      document.head.appendChild(s);
    }).catch(() => { paid.captcha = null; });
  }

  function captchaToken() {
    const c = paid.captcha;
    if (!c || !c.api || c.id == null) return '';
    try { return c.api.getResponse(c.id) || ''; } catch (e) { return ''; }
  }
  function resetCaptcha() {
    const c = paid.captcha;
    if (c && c.api && c.id != null) { try { c.api.reset(c.id); } catch (e) { /* ignore */ } }
  }

  /* ---- tickets (paid entries) ---- */
  function saveTickets() {
    lsSet(CONFIG.ticketsKey, JSON.stringify(paid.tickets.map((t) => ({ token: t.token, target: t.target, entry: t.entry }))));
  }
  function loadTickets() {
    try {
      const arr = JSON.parse(lsGet(CONFIG.ticketsKey) || '[]');
      if (Array.isArray(arr)) {
        paid.tickets = arr.filter((t) => t && /^[0-9a-f]{32}$/.test(t.token)).map((t) => ({ ...t, nextPoll: 0 }));
      }
    } catch (e) { paid.tickets = []; }
  }
  function addTicket(t) {
    paid.tickets.unshift({ ...t, nextPoll: 0 });
    paid.tickets = paid.tickets.slice(0, CONFIG.maxTickets);
    saveTickets();
    renderTickets();
  }

  const isFinal = (e) => e && (e.status === 'no_reward' || e.status === 'expired' || e.status === 'failed' ||
    (e.status === 'claimed' && (e.claimStatus === 'paid' || e.claimStatus === 'failed' || e.claimStatus === 'blocked')));

  // When should this ticket be asked about next? (0 = not at all)
  function pollDelay(t) {
    const e = t.entry;
    if (!e) return CONFIG.ticketPollMs;   // nothing known yet: ask once
    if (isFinal(e)) return 0;
    if (e.status === 'waiting') return feed.tip != null && feed.tip >= t.target ? CONFIG.ticketPollMs : 0;  // block not out yet
    if (e.status === 'graded') return CONFIG.claimPollMs;
    return CONFIG.claimPollMs;   // claimed: watch for the payout
  }

  async function pollTicket(t) {
    t.nextPoll = Date.now() + (pollDelay(t) || CONFIG.claimPollMs);
    const before = t.entry ? t.entry.status : '';
    const j = await api('status', { token: t.token });
    if (j && j.ok) {
      t.entry = j.entry;
      saveTickets();
      renderTickets();
      if (before === 'waiting' && j.entry.status !== 'waiting') sfx.ready();
    } else if (j && j.error === 'ticket') {
      paid.tickets = paid.tickets.filter((x) => x !== t);
      saveTickets();
      renderTickets();
    }
  }

  function tickPaid() {
    if (document.hidden) return;
    const now = Date.now();
    if (R.state === 'guess') {
      if (!paid.loading && (now - paid.infoAt > CONFIG.infoRefreshMs || (paid.info && paid.info.ok && paid.info.target !== R.target && now - paid.infoAt > 4000))) loadInfo();
      renderPaid();
    }
    for (const t of paid.tickets) {
      if (pollDelay(t) && now >= t.nextPoll) pollTicket(t);
    }
  }

  async function attachLnurl(t, input, btn) {
    const lnurl = input.value.trim();
    if (!lnurl) { input.focus(); return; }
    btn.disabled = true;
    const j = await api('attach', { token: t.token, lnurl });
    btn.disabled = false;
    if (j && j.entry) { t.entry = j.entry; saveTickets(); }
    if (!j || !j.ok) { showNote('⚠️ ' + ((j && j.message) || 'Something went wrong.')); }
    renderTickets();
  }

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function lnurlRow(t, label) {
    const row = el('div', 'row');
    const input = el('input', 'lnurl-in');
    input.type = 'text';
    input.placeholder = 'lnurl1…';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('aria-label', 'Your LNURL');
    const btn = el('button', '', label);
    btn.type = 'button';
    btn.addEventListener('click', () => { sfx.unlock(); attachLnurl(t, input, btn); });
    input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') btn.click(); });
    row.appendChild(input);
    row.appendChild(btn);
    return row;
  }

  function scoreLine(e) {
    const a = e.acc || {};
    const parts = [a.time, a.tx, a.fee].filter((v) => v != null).map((v) => v + '%');
    return parts.join(' + ') + ' → ' + e.score + '% = ' + fmtSats(e.sats || 0);
  }

  function renderTickets() {
    const wrap = $('tickets');
    wrap.textContent = '';
    for (const t of paid.tickets) {
      const e = t.entry || { status: 'waiting', spot: 0 };
      const card = el('div', 'ticket' + (isFinal(e) ? ' done' : ''));
      card.appendChild(el('h3', '', '🎟️ Sats entry · block #' + fmtInt(t.target) + (e.spot ? ' · spot ' + e.spot : '')));

      if (e.status === 'waiting') {
        if (e.hasLnurl) {
          card.appendChild(el('p', '', '✅ LNURL saved. When block #' + fmtInt(t.target) + ' is found, your score is sent as sats automatically.'));
        } else {
          card.appendChild(el('p', '', 'Add your LNURL any time — even after the block is found — to get your sats.'));
          card.appendChild(lnurlRow(t, 'Save'));
        }
        if (feed.tip != null && feed.tip >= t.target) card.appendChild(el('p', 'muted', 'Block found — checking your score…'));
      } else if (e.status === 'graded') {
        card.appendChild(el('p', 'big', scoreLine(e)));
        const mins = e.claimSecondsLeft != null ? Math.max(1, Math.round(e.claimSecondsLeft / 60)) : null;
        card.appendChild(el('p', '', '⚡ Claim your ' + fmtSats(e.sats) + ' now' + (mins ? ' (' + mins + ' min left)' : '') + ':'));
        card.appendChild(lnurlRow(t, 'Claim'));
      } else if (e.status === 'claimed') {
        card.appendChild(el('p', 'big', scoreLine(e)));
        const cs = e.claimStatus;
        card.appendChild(el('p', '', cs === 'paid' ? '✅ Paid! Enjoy your sats.'
          : cs === 'failed' || cs === 'blocked' ? '⚠️ The payout failed. Check that your LNURL is right.'
          : '⚡ Claim #' + e.claimId + ' is queued — payouts are sent by the faucet admin, so it can take a while.'));
      } else if (e.status === 'no_reward') {
        card.appendChild(el('p', '', 'Score: ' + (e.score != null ? e.score + '%' : '—') + ' — too low for a reward this time. 🎲'));
      } else {
        card.appendChild(el('p', '', '⚠️ ' + (e.reason || 'This entry has ended.')));
      }

      if (e.status !== 'waiting') {
        const x = el('button', 'x', '×');
        x.type = 'button';
        x.setAttribute('aria-label', 'Hide this entry');
        x.addEventListener('click', () => {
          paid.tickets = paid.tickets.filter((y) => y !== t);
          saveTickets();
          renderTickets();
        });
        card.appendChild(x);
      }
      wrap.appendChild(card);
    }
  }

  /* ================= Round logic ================= */
  const logFeeFromPos = (pos) => CONFIG.feeMin * Math.pow(CONFIG.feeMax / CONFIG.feeMin, pos / 1000);
  const posFromFee = (v) => Math.round(1000 * Math.log(clamp(v, CONFIG.feeMin, CONFIG.feeMax) / CONFIG.feeMin) / Math.log(CONFIG.feeMax / CONFIG.feeMin));
  const round2 = (v) => (v < 10 ? Math.round(v * 100) / 100 : Math.round(v * 10) / 10);

  const accLinear = (g, a, floor) => clamp(1 - Math.abs(g - a) / Math.max(g, a, floor, 1e-9), 0, 1);
  const accFee = (g, a) => clamp(1 - Math.abs(Math.log(Math.max(g, 0.05) / Math.max(a, 0.05))) / Math.log(4), 0, 1);

  function showView(name) {
    for (const v of ['Loading', 'Guess', 'Wait', 'Result']) $('view' + v).hidden = v.toLowerCase() !== name;
  }

  let noteTimer = 0;
  function showNote(text, ms) {
    const el = $('note');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(noteTimer);
    noteTimer = setTimeout(() => { el.hidden = true; }, ms || 7000);
  }

  function updateReadouts() {
    $('gTimeVal').textContent = fmtMin(+$('gTime').value);
    $('gTxVal').textContent = fmtInt(+$('gTx').value);
    $('gFeeVal').textContent = fmtFee(logFeeFromPos(+$('gFee').value));
  }

  // Under each slider: what the LAST block did. Players guess the next block from these numbers.
  function updateHints() {
    const lb = lastBlock();
    const gap = lastGapSeconds();
    const tag = lb ? '#' + fmtInt(lb.height) : 'the last block';

    if (gap == null) {
      setText('hintTime', 'Counted from the last block (' + tag + ').');
    } else if (gap < 60) {
      setText('hintTime', 'Counted from the last block (' + tag + '), which was found in under a minute.');
    } else {
      setText('hintTime', 'Counted from the last block (' + tag + '), which was found in ' + fmtMin(Math.round(gap / 6) / 10) + '.');
    }
    setText('hintTx', lb && lb.tx_count ? 'Last block: ' + fmtInt(lb.tx_count) + ' transactions' : 'Last block: …');
    setText('hintFee', lb && lb.medianFee != null ? 'Last block: ' + fmtFee(lb.medianFee) + ' (median)' : 'Last block: …');
  }

  function maybeStart() {
    if (R.state === 'loading' && feed.tip != null && feed.projected.length) startRound();
  }

  function startRound() {
    R.state = 'guess';
    R.target = (feed.tip || 0) + 1;
    R.guesses = null;
    R.lockedAt = 0;
    // Remember which block this round is measured from (timing is counted from it, not from "now").
    const lb = lastBlock();
    R.base = lb ? { height: lb.height, timestamp: lb.timestamp } : null;
    R.baseAt = feed.lastBlockAt;
    $('roundNo').textContent = R.roundNo;
    $('targetH').textContent = fmtInt(R.target);

    // Sliders start at the last block's numbers (the projection is no longer used as a default).
    const p = feed.projected[0];
    const txSeed = lb && lb.tx_count ? lb.tx_count : (p ? Number(p.nTx) : 0) || 4000;
    const feeSeed = lb && lb.medianFee != null ? lb.medianFee : (p ? Number(p.medianFee) : 0) || 1;
    $('gTime').value = CONFIG.defaultTimeMin;
    $('gTx').value = clamp(Math.round(txSeed / 50) * 50, +$('gTx').min, +$('gTx').max);
    $('gFee').value = posFromFee(feeSeed);
    updateReadouts();
    updateHints();
    showView('guess');
    renderPaid();
    renderTickets();   // a new block may have made an entry's result checkable
    if (!document.hidden) loadInfo();
  }

  let locking = false;
  async function lockIn() {
    if (R.state !== 'guess' || locking) return;
    const guesses = { time: +$('gTime').value, tx: +$('gTx').value, fee: round2(logFeeFromPos(+$('gFee').value)) };

    // Ticked but paid entries closed meanwhile: say so, then lock in for fun.
    if (!$('paidBox').hidden && $('paidOn').checked && $('paidOn').disabled) {
      showNote('Paid entries are closed for this block, so these guesses are just for fun.', 6000);
    }
    // Playing for sats: the server has to accept the entry first (captcha, spot, time window).
    if (!$('paidBox').hidden && $('paidOn').checked && !$('paidOn').disabled) {
      const token = captchaToken();
      if (!token) {
        showNote('Please complete the CAPTCHA to play for sats — or untick "Play for real sats" to play for fun.');
        return;
      }
      const target = R.target;
      locking = true;
      const btn = $('lockBtn');
      btn.disabled = true;
      btn.textContent = '⏳ Locking in…';
      const body = { target, time: guesses.time, tx: guesses.tx, fee: guesses.fee, lnurl: $('lnurlIn').value.trim() };
      body[paid.captcha.provider === 'google' ? 'g-recaptcha-response' : 'h-captcha-response'] = token;
      const j = await api('lock', body);
      locking = false;
      btn.disabled = false;
      btn.textContent = '🔒 Lock in my guesses';
      resetCaptcha();
      if (!j || !j.ok) {
        showNote('⚠️ ' + ((j && j.message) || 'Something went wrong.'), 9000);
        if (j && ['stale', 'closed', 'full', 'duplicate'].includes(j.error)) loadInfo();
        return;   // still in the guess view: they can retry, or untick and play for fun
      }
      addTicket({ token: j.token, target, entry: j.entry });
      $('lnurlIn').value = '';
      $('paidOn').checked = false;
      if (paid.info && paid.info.ok) paid.info.spotsLeft = Math.max(0, paid.info.spotsLeft - 1);
      showNote('🎟️ You got paid spot ' + j.spot + ' of ' + j.spotsTotal + ' for block #' + fmtInt(target) + '!', 6000);
      if (R.state !== 'guess' || R.target !== target) return;   // a block landed while we waited; the ticket still counts
    }

    R.guesses = guesses;
    R.lockedAt = Date.now();
    R.state = 'wait';
    $('targetH2').textContent = fmtInt(R.target);
    $('lkTime').textContent = fmtMin(R.guesses.time);
    $('lkTx').textContent = fmtInt(R.guesses.tx);
    $('lkFee').textContent = fmtFee(R.guesses.fee);
    $('elapsed').textContent = '00:00';
    showView('wait');
    sfx.lock();
  }

  async function handleNewBlock(b, prevAt) {
    const arrivedAt = Date.now();
    blockFx();
    sfx.block();
    // Make sure we hold the new block's median fee for the next round's hint (fetched if it came without).
    const fullP = withExtras(b);
    fullP.then((full) => { recordBlock(full); updateHints(); });

    if (R.state === 'guess') {
      // the block beat the player: no penalty, just restart for the following block
      showNote('⛏️ Block #' + fmtInt(b.height) + ' was found before you locked in — new round for #' + fmtInt(b.height + 1) + '.');
      startRound();
    } else if (R.state === 'wait') {
      R.state = 'grading';
      const full = await fullP;
      grade(full, arrivedAt, prevAt);
    }
  }

  function grade(b, arrivedAt, prevAt) {
    const g = R.guesses;
    // How long after the PREVIOUS block did this one land? Prefer the blocks' own timestamps (the same
    // clock the "last block took X min" hint uses); fall back to when we saw them arrive.
    let actualMs;
    if (R.base && R.base.timestamp && Number(b.timestamp)) {
      actualMs = Math.max(0, (Number(b.timestamp) - R.base.timestamp) * 1000);
    } else {
      actualMs = Math.max(0, arrivedAt - (R.baseAt || prevAt || R.lockedAt));
    }
    const actualTx = Number(b.tx_count) || 0;
    const actualFee = b.extras && typeof b.extras.medianFee === 'number' ? b.extras.medianFee : null;

    const rows = [
      { icon: '⏱️', name: 'Arrival', you: fmtMin(g.time), real: fmtDur(actualMs), acc: accLinear(g.time, actualMs / 60000, 2) },
      { icon: '📦', name: 'Transactions', you: fmtInt(g.tx), real: fmtInt(actualTx), acc: accLinear(g.tx, actualTx, 0) },
      { icon: '💸', name: 'Median fee', you: fmtFee(g.fee), real: actualFee == null ? 'unavailable' : fmtFee(actualFee), acc: actualFee == null ? null : accFee(g.fee, actualFee) },
    ];

    let total = 0;
    const table = $('resultRows');
    table.textContent = '';
    rows.forEach((r, i) => {
      const sats = r.acc == null ? 0 : Math.round(CONFIG.satsPerGuess * r.acc);
      total += sats;
      const tr = document.createElement('tr');
      const c1 = document.createElement('td');
      c1.textContent = r.icon + ' ' + r.name;
      const c2 = document.createElement('td');
      c2.textContent = 'You said ' + r.you + ' · actual ' + r.real;
      const c3 = document.createElement('td');
      c3.className = 'acc';
      const pct = document.createElement('div');
      pct.textContent = r.acc == null ? 'not scored' : Math.round(r.acc * 100) + '% accurate';
      const sp = document.createElement('div');
      sp.className = 'sats';
      sp.textContent = '+' + fmtSats(sats);
      c3.appendChild(pct);
      c3.appendChild(sp);
      tr.appendChild(c1); tr.appendChild(c2); tr.appendChild(c3);
      table.appendChild(tr);
      if (r.acc != null) setTimeout(() => sfx.reveal(r.acc), 350 + i * 220);
    });

    session.total += total;
    const newBest = total > best;
    if (newBest) { best = total; lsSet(CONFIG.bestKey, String(best)); }
    updateHud();

    const pool = b.extras && b.extras.pool && b.extras.pool.name ? b.extras.pool.name : null;
    $('resultTitle').textContent = '⛏️ Block #' + fmtInt(b.height) + ' found!';
    $('resultBlock').textContent = (pool ? 'Mined by ' + pool + ' · ' : '') + fmtInt(actualTx) + ' transactions · ' +
      fmtDur(actualMs) + ' after the previous block';

    const max = CONFIG.satsPerGuess * 3;
    const tot = $('roundTotal');
    tot.textContent = 'Round total: +' + fmtSats(total) + (newBest && total > 0 ? ' 🏆 new best!' : '');
    const small = document.createElement('small');
    small.textContent = fmtBtc(total) + ' · out of ' + fmtSats(max) + ' possible';
    tot.appendChild(small);

    const f = total / max;
    $('roundRating').textContent = f >= 0.9 ? '🎯 Sniper! Almost perfect.'
      : f >= 0.75 ? '🔥 Great round.'
      : f >= 0.5 ? '👍 Solid guessing.'
      : f >= 0.25 ? '🙂 Not bad — blocks are random!'
      : '🎲 Bitcoin kept its secrets this time.';

    R.state = 'result';
    showView('result');
    $('panel').scrollTop = 0;
  }

  function nextRound() {
    if (R.state !== 'result') return;
    R.roundNo += 1;
    startRound();
  }

  function updateHud() {
    $('score').textContent = fmtSats(session.total);
    $('scoreBtc').textContent = fmtBtc(session.total);
    $('best').textContent = fmtSats(best) + ' · ' + fmtBtc(best);
  }

  function tickClock() {
    const now = Date.now();
    if (feed.lastBlockAt) {
      $('sinceBlock').textContent = '⛏️ Last block #' + fmtInt(feed.tip) + ': ' + fmtClock(now - feed.lastBlockAt) + ' ago';
    }
    // while waiting, show how long it's been since the LAST block (the guess is measured from there)
    if (R.state === 'wait') $('elapsed').textContent = fmtClock(now - (feed.lastBlockAt || R.lockedAt));
    tickCooldown();
    tickPaid();
  }

  /* ================= Wiring ================= */
  // sliders
  for (const id of ['gTime', 'gTx', 'gFee']) $(id).addEventListener('input', updateReadouts);
  $('lockBtn').addEventListener('click', () => { sfx.unlock(); lockIn(); });
  $('paidOn').addEventListener('change', () => {
    if ($('paidOn').checked) ensureCaptcha();
    renderPaid();
  });
  $('nextBtn').addEventListener('click', () => { sfx.unlock(); nextRound(); });

  // any first tap unlocks audio (browser autoplay rules)
  document.addEventListener('pointerdown', () => sfx.unlock(), { passive: true });

  // sound button
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

  // help panel (opens automatically on a first visit)
  const helpPanel = $('helpPanel');
  function openHelp() {
    helpPanel.hidden = false;
    $('helpOk').focus({ preventScroll: true });
    lsSet(CONFIG.helpKey, '1');
  }
  function closeHelp() { helpPanel.hidden = true; sfx.unlock(); }
  $('help').addEventListener('click', openHelp);
  $('helpClose').addEventListener('click', closeHelp);
  $('helpOk').addEventListener('click', closeHelp);
  helpPanel.addEventListener('click', (e) => { if (e.target === helpPanel) closeHelp(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !helpPanel.hidden) closeHelp(); });
  $('helpMaxSats').textContent = fmtSats(CONFIG.satsPerGuess);
  $('helpMaxRound').textContent = fmtSats(CONFIG.satsPerGuess * 3);

  // full screen: real Fullscreen API where available, otherwise ask the parent page to
  // stretch the popup (iPhone Safari has no element fullscreen). Hidden if neither is possible.
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
    setTimeout(resizeCanvas, 120);
  }

  function toggleMaximize() {
    if (!inFrame) return;
    maximized = !maximized;
    window.parent.postMessage({ type: 'game-maximize', on: maximized }, '*');
    renderFs();
  }

  async function toggleFullscreen() {
    sfx.unlock();
    if (fsElement()) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
    if (maximized) { toggleMaximize(); return; }
    if (fsApi) {
      const req = root.requestFullscreen || root.webkitRequestFullscreen;
      try {
        const p = req.call(root);
        await Promise.race([
          p && p.then ? p : new Promise((r) => setTimeout(r, 500)),
          new Promise((_, rej) => setTimeout(() => rej(new Error('fullscreen timeout')), 900)),
        ]);
        if (fsElement()) return;
      } catch (e) { /* fall back to maximise below */ }
      if (fsElement()) return;
    }
    if (!maximized) toggleMaximize();
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
    if (document.hidden) stop(); else { start(); restTick(); }
  });

  if (typeof ResizeObserver === 'function') new ResizeObserver(resizeCanvas).observe(stageWrap);
  else window.addEventListener('resize', resizeCanvas);

  /* ================= Go ================= */
  loadTickets();
  renderTickets();
  updateHud();
  resizeCanvas();
  setStatus('connecting');
  start();
  connectWS();
  restTick();
  checkCooldown();
  setInterval(tickClock, 1000);
  setInterval(restTick, CONFIG.restPollMs);
  setInterval(() => {
    // reconnect a WebSocket that has silently gone quiet
    if (feed.wsOpen && Date.now() - lastMsgAt > CONFIG.wsSilenceMs) { try { ws.close(); } catch (e) { /* ignore */ } }
  }, 15000);

  if (CONFIG.helpOnFirstVisit && lsGet(CONFIG.helpKey) !== '1') openHelp();
})();
