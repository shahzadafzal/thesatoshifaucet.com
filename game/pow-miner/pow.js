/* PoW Mini Miner — The Satoshi Faucet
 *
 * Your device hashes a Bitcoin-style block header over and over (double SHA-256, in Web Workers)
 * and keeps the hash with the most leading zero bits, exactly the thing real miners race at.
 * Everything runs in the visitor's browser. It cannot earn real Bitcoin; sats here are play money.
 * Live Bitcoin numbers (chain tip, network hashrate) come from mempool.space for comparison only.
 */
(() => {
  'use strict';

  /* ================= Tunables ================= */
  const CONFIG = {
    satsPerLevel: 10,              // reaching leading-zero level L (for the first time) pays L x this many play-money sats
    autoStopMinutes: 20,           // take a break after this long (0 = never) to protect batteries
    apiBase: 'https://mempool.space/api',
    requestTimeoutMs: 6000,
    // used when mempool.space can't be reached (approximate)
    fallbackHashrate: 9.7e20,      // ~970 EH/s
    fallbackDifficulty: 1.33e14,
    helpOnFirstVisit: true,
    recordKey: 'satoshiFaucet.powMiner.recordBits',
    helpKey: 'satoshiFaucet.powMiner.helpSeen',
    soundKey: 'satoshiFaucet.powMiner.sound',     // '0' = muted
    powerKey: 'satoshiFaucet.powMiner.power',
    masterVolume: 0.5,
  };

  /* ================= Helpers ================= */
  const $ = (id) => document.getElementById(id);
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lsGet = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
  const lsSet = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } };

  const fmtInt = (n) => Math.round(n).toLocaleString('en-US');
  const fmtSats = (n) => fmtInt(n) + ' sats';
  const fmtBtc = (n) => (n / 1e8).toFixed(8) + ' BTC';

  const BIG = [[1e24, 'septillion'], [1e21, 'sextillion'], [1e18, 'quintillion'], [1e15, 'quadrillion'],
    [1e12, 'trillion'], [1e9, 'billion'], [1e6, 'million'], [1e3, 'thousand']];
  function fmtBig(n) {
    if (!isFinite(n)) return 'a huge number of';
    if (n < 1000) return String(Math.round(n));
    for (const [v, name] of BIG) {
      if (n >= v) { const x = n / v; return (x < 10 ? x.toFixed(1) : String(Math.round(x))) + ' ' + name; }
    }
    return fmtInt(n);
  }

  function fmtRate(h) {
    const u = [[1e18, 'EH/s'], [1e15, 'PH/s'], [1e12, 'TH/s'], [1e9, 'GH/s'], [1e6, 'MH/s'], [1e3, 'kH/s']];
    for (const [v, name] of u) if (h >= v) { const x = h / v; return (x < 10 ? x.toFixed(2) : x < 100 ? x.toFixed(1) : String(Math.round(x))) + ' ' + name; }
    return Math.round(h) + ' H/s';
  }

  function fmtCount(n) {
    if (n >= 1e12) return (n / 1e12).toFixed(2) + ' T';
    if (n >= 1e9) return (n / 1e9).toFixed(2) + ' B';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + ' M';
    return fmtInt(n);
  }

  function fmtElapsed(ms) {
    const s = Math.floor(ms / 1000);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
  }

  function fmtYears(y) {
    if (y < 1) return 'less than a year';
    if (y < 1000) return fmtInt(y) + ' years';
    return fmtBig(y) + ' years';
  }

  const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) ||
    (navigator.maxTouchPoints > 1 && /Macintosh/.test(navigator.userAgent));
  const cores = navigator.hardwareConcurrency || 4;
  const THREADS = {
    eco: () => 1,
    balanced: () => clamp(Math.ceil(cores / 2), 1, isMobile ? 2 : 4),
    turbo: () => clamp(cores - 1, 1, isMobile ? 4 : 8),
  };

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
      if (!enabled || !ac || ac.state !== 'running' || voices >= 10) return;
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
      start() { tone({ freq: 300, freq2: 600, type: 'triangle', dur: 0.16, gain: 0.2 }); tone({ freq: 600, freq2: 900, type: 'triangle', dur: 0.14, gain: 0.14, delay: 0.1 }); },
      stop() { tone({ freq: 600, freq2: 300, type: 'triangle', dur: 0.2, gain: 0.18 }); },
      // a rising chime: higher levels sound higher
      level(bits) { const f = 320 + Math.min(bits, 60) * 18; tone({ freq: f, freq2: f * 1.5, dur: 0.14, gain: 0.2 }); },
      record(bits) {
        const f = 400 + Math.min(bits, 60) * 12;
        [1, 1.25, 1.5, 2].forEach((m, i) => tone({ freq: f * m, type: 'triangle', dur: 0.22, gain: 0.18, delay: i * 0.09 }));
      },
    };
  })();

  /* ================= State ================= */
  const S = {
    running: false,
    resumeOnVisible: false,
    workers: [],
    total: 0,                 // hashes tried this session
    rate: 0,                  // smoothed hashes per second
    elapsedMs: 0,             // mining time banked from earlier runs
    runStart: 0,              // performance.now() when the current run began
    sample: '',               // latest hash seen
    bestBits: 0,
    bestHash: '',
    bestInfo: null,
    sats: 0,
    recordBits: parseInt(lsGet(CONFIG.recordKey), 10) || 0,
    power: ['eco', 'balanced', 'turbo'].includes(lsGet(CONFIG.powerKey)) ? lsGet(CONFIG.powerKey) : 'balanced',
  };

  // Bitcoin chain data used for the header and the comparisons.
  const chain = { live: false, height: null, version: 0x20000000, bits: 0x1d00ffff, prev: new Uint8Array(32) };
  const net = { live: false, hashrate: CONFIG.fallbackHashrate, difficulty: CONFIG.fallbackDifficulty };

  /* ================= Rendering ================= */
  const HEX64 = /^[0-9a-f]{64}$/;

  function renderHash(el, hex) {
    if (!HEX64.test(hex)) { el.textContent = ''; return; }
    let zc = 0;
    while (zc < 64 && hex[zc] === '0') zc++;
    const rows = [hex.slice(0, 32), hex.slice(32)];
    let html = '';
    let left = zc;
    for (const row of rows) {
      const z = Math.min(left, row.length);
      left -= z;
      html += '<div>' + (z ? '<span class="z">' + row.slice(0, z) + '</span>' : '') + row.slice(z) + '</div>';
    }
    el.innerHTML = html;   // hex digits only
  }

  function updateHud() {
    $('score').textContent = fmtSats(S.sats);
    $('scoreBtc').textContent = fmtBtc(S.sats);
    $('record').textContent = S.recordBits + ' bits';
  }

  function needBits() {
    return Math.round(32 + Math.log2(Math.max(net.difficulty, 1)));
  }

  function updateLadder() {
    const need = needBits();
    $('ladderFill').style.width = clamp((S.bestBits / need) * 100, 0, 100).toFixed(1) + '%';
    $('ladderNeed').textContent = 'Bitcoin block ≈ ' + need + ' bits';
  }

  function elapsedNow() {
    return S.elapsedMs + (S.running ? performance.now() - S.runStart : 0);
  }

  function updateFacts() {
    const rate = S.rate;
    $('factOdds').textContent = S.bestBits > 0
      ? 'Your best hash has ' + S.bestBits + ' leading zero bits — a 1-in-' + (S.bestBits < 40 ? fmtInt(Math.pow(2, S.bestBits)) : fmtBig(Math.pow(2, S.bestBits))) +
        ' result, like flipping ' + S.bestBits + ' heads in a row.'
      : 'Your best hash is a rare one — start hashing to find out how rare.';

    const netTxt = fmtRate(net.hashrate);
    $('factNet').textContent = rate > 0
      ? 'The Bitcoin network does about ' + netTxt + ' — roughly ' + fmtBig(net.hashrate / rate) + ' times faster than your device.'
      : 'The Bitcoin network does about ' + netTxt + ' — every second.';

    if (rate > 0) {
      const years = (net.difficulty * 4294967296) / rate / 31557600;
      $('factTime').textContent = 'At your speed, finding a real Bitcoin block would take about ' + fmtYears(years) +
        (years > 13.8e9 ? ' — longer than the universe has existed (13.8 billion years).' : '.');
    } else {
      $('factTime').textContent = '';
    }

    $('factChain').textContent = chain.live
      ? 'You’re hashing on top of the real latest block, #' + fmtInt(chain.height) + ' — the same tip Bitcoin miners are working from right now.'
      : 'Offline: using a demo block and approximate network numbers.';
    $('helpTip').textContent = chain.live ? 'currently block #' + fmtInt(chain.height) : 'we couldn’t reach mempool.space, so a demo block is used';
  }

  let noteTimer = 0;
  function showNote(text, ms) {
    const el = $('note');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(noteTimer);
    noteTimer = setTimeout(() => { el.hidden = true; }, ms || 7000);
  }

  function renderBest(flash) {
    $('bestBits').textContent = S.bestBits;
    renderHash($('bestHash'), S.bestHash);
    if (S.bestInfo) {
      const hexZeros = S.bestHash.length ? (S.bestHash.match(/^0*/)[0].length) : 0;
      $('bestMeta').textContent = hexZeros + ' hex zeros · nonce ' + fmtInt(S.bestInfo.nonce) + ' · thread ' + (S.bestInfo.worker + 1);
    }
    if (flash) {
      const card = $('bestCard');
      card.classList.remove('flash');
      void card.offsetWidth;
      card.classList.add('flash');
    }
  }

  let shownSample = '';
  function tickDisplay() {
    if (S.sample && S.sample !== shownSample) {
      shownSample = S.sample;
      renderHash($('latestHash'), S.sample);
    }
  }

  let lastTotal = 0;
  let lastStatsAt = performance.now();
  function tickStats() {
    const now = performance.now();
    if (S.running) {
      const dt = (now - lastStatsAt) / 1000;
      if (dt > 0) {
        const inst = (S.total - lastTotal) / dt;
        S.rate = S.rate === 0 ? inst : S.rate * 0.6 + inst * 0.4;
      }
    }
    lastTotal = S.total;
    lastStatsAt = now;

    $('rate').textContent = S.rate > 0 ? fmtRate(S.rate) : '—';
    $('total').textContent = fmtCount(S.total);
    $('elapsed').textContent = fmtElapsed(elapsedNow());
    $('latestMeta').textContent = S.running
      ? S.workers.length + ' thread' + (S.workers.length === 1 ? '' : 's') + ' hashing · ' + fmtRate(S.rate)
      : (S.total > 0 ? 'Paused — press Start to keep going.' : 'Press Start to begin hashing.');
    updateFacts();

    if (S.running && CONFIG.autoStopMinutes > 0 && now - S.runStart > CONFIG.autoStopMinutes * 60000) {
      stopMining();
      showNote('☕ Taking a break to save your battery after ' + CONFIG.autoStopMinutes + ' minutes. Tap Start to carry on!', 12000);
    }
  }

  /* ================= Mining control ================= */
  function buildBase() {
    const seed = new Uint8Array(24);
    (self.crypto || window.crypto).getRandomValues(seed);
    return {
      version: chain.version,
      prev: chain.prev,
      time: Math.floor(Date.now() / 1000),
      bits: chain.bits,
      seed,
    };
  }

  function startMining() {
    if (S.running) return;
    sfx.unlock();
    const n = THREADS[S.power]();
    const base = buildBase();
    const workers = [];
    try {
      for (let i = 0; i < n; i++) {
        const w = new Worker('pow-worker.js');
        w.onmessage = onWorkerMessage;
        w.onerror = () => { showNote('Something went wrong in a hashing thread. Try Eco mode or reload.', 9000); };
        w.postMessage({ type: 'start', workerId: i, version: base.version, prev: base.prev, seed: base.seed, time: base.time, bits: base.bits, needBits: S.bestBits + 1 });
        workers.push(w);
      }
    } catch (e) {
      workers.forEach((w) => w.terminate());
      showNote('Your browser couldn’t start background hashing threads here.', 9000);
      return;
    }
    S.workers = workers;
    S.running = true;
    S.runStart = performance.now();
    S.rate = 0;
    lastTotal = S.total;
    lastStatsAt = performance.now();
    renderControls();
    sfx.start();
  }

  function stopMining() {
    if (!S.running) return;
    S.elapsedMs += performance.now() - S.runStart;
    S.running = false;
    S.workers.forEach((w) => { try { w.postMessage({ type: 'stop' }); } catch (e) { /* ignore */ } w.terminate(); });
    S.workers = [];
    renderControls();
    tickStats();
    sfx.stop();
  }

  function onWorkerMessage(e) {
    const m = e.data;
    if (!S.running) return;
    if (m.type === 'progress') {
      S.total += m.hashes;
      S.sample = m.sample;
    } else if (m.type === 'best') {
      handleBest(m);
    }
  }

  function handleBest(m) {
    if (m.bits <= S.bestBits || !HEX64.test(m.hash)) return;
    const prev = S.bestBits;
    S.bestBits = m.bits;
    S.bestHash = m.hash;
    S.bestInfo = { nonce: m.nonce, extra: m.extra, worker: m.worker };
    for (let L = prev + 1; L <= m.bits; L++) S.sats += L * CONFIG.satsPerLevel;
    S.workers.forEach((w) => w.postMessage({ type: 'need', bits: S.bestBits + 1 }));

    const newRecord = m.bits > S.recordBits;
    if (newRecord) { S.recordBits = m.bits; lsSet(CONFIG.recordKey, String(S.recordBits)); }

    renderBest(m.bits >= 8);
    updateHud();
    updateLadder();
    if (newRecord && m.bits >= 16) {
      sfx.record(m.bits);
      showNote('🏆 New personal record: ' + m.bits + ' leading zero bits!', 6000);
    } else if (m.bits >= 12) {
      sfx.level(m.bits);
    }
  }

  function renderControls() {
    const b = $('startBtn');
    b.textContent = S.running ? '■ Stop' : (S.total > 0 ? '▶ Keep hashing' : '▶ Start hashing');
    b.classList.toggle('running', S.running);
    document.querySelectorAll('.pw').forEach((el) => el.classList.toggle('active', el.dataset.power === S.power));
  }

  function setPower(p) {
    S.power = p;
    lsSet(CONFIG.powerKey, p);
    renderControls();
    if (S.running) { stopMining(); startMining(); }
  }

  /* ================= Live Bitcoin data ================= */
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

  async function loadChain() {
    try {
      const blocks = await getJson('/v1/blocks');
      const b = Array.isArray(blocks) ? blocks[0] : null;
      if (b && /^[0-9a-f]{64}$/.test(b.id) && typeof b.bits === 'number' && typeof b.version === 'number') {
        const prev = new Uint8Array(32);
        for (let i = 0; i < 32; i++) prev[i] = parseInt(b.id.substr(62 - 2 * i, 2), 16);   // hash bytes go in reversed
        chain.prev = prev;
        chain.height = b.height;
        chain.version = b.version;
        chain.bits = b.bits;
        chain.live = true;
        if (typeof b.difficulty === 'number' && b.difficulty > 0) { net.difficulty = b.difficulty; }
      }
    } catch (e) { /* demo block */ }
    try {
      const hr = await getJson('/v1/mining/hashrate/3d');
      if (hr && typeof hr.currentHashrate === 'number' && hr.currentHashrate > 0) {
        net.hashrate = hr.currentHashrate;
        if (typeof hr.currentDifficulty === 'number' && hr.currentDifficulty > 0) net.difficulty = hr.currentDifficulty;
        net.live = true;
      }
    } catch (e) { /* approximate numbers */ }
    updateLadder();
    updateFacts();
  }

  /* ================= Wiring ================= */
  $('startBtn').addEventListener('click', () => { sfx.unlock(); if (S.running) stopMining(); else startMining(); });
  document.querySelectorAll('.pw').forEach((el) => el.addEventListener('click', () => { sfx.unlock(); setPower(el.dataset.power); }));
  document.addEventListener('pointerdown', () => sfx.unlock(), { passive: true });

  // thread counts on the power buttons
  for (const p of ['eco', 'balanced', 'turbo']) {
    const n = THREADS[p]();
    $('pw' + p[0].toUpperCase() + p.slice(1)).textContent = n + ' thread' + (n === 1 ? '' : 's');
  }

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
  $('helpPerLevel').textContent = CONFIG.satsPerLevel;
  $('helpAutoStop').textContent = CONFIG.autoStopMinutes;

  // full screen: real Fullscreen API where available, otherwise ask the parent page to stretch the popup
  const fsBtn = $('fullscreen');
  const root = document.documentElement;
  const inFrame = window.parent !== window;
  const fsApi = !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
  let maximized = false;
  const fsElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;

  function renderFs() {
    const real = !!fsElement();
    document.body.classList.toggle('is-fs', real);
    document.body.classList.toggle('is-max', maximized && !real);
    const on = real || maximized;
    fsBtn.setAttribute('aria-label', on ? 'Exit full screen' : 'Full screen');
    fsBtn.title = on ? 'Exit full screen' : 'Full screen';
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

  // pause hashing while the tab is hidden, resume when it's back
  document.addEventListener('visibilitychange', () => {
    sfx.visibility(document.hidden);
    if (document.hidden) {
      if (S.running) { S.resumeOnVisible = true; stopMining(); }
    } else if (S.resumeOnVisible) {
      S.resumeOnVisible = false;
      startMining();
    }
  });
  window.addEventListener('pagehide', () => { S.workers.forEach((w) => w.terminate()); });

  /* ================= Go ================= */
  renderControls();
  updateHud();
  updateLadder();
  updateFacts();
  loadChain();
  setInterval(tickDisplay, 90);
  setInterval(tickStats, 500);
  if (CONFIG.helpOnFirstVisit && lsGet(CONFIG.helpKey) !== '1') openHelp();
})();
