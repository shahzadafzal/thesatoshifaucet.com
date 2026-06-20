/**
 * PENALTY SHOOTOUT — Game Logic
 * ─────────────────────────────────────────────────────────────
 * Architecture:
 *   STATE  → single source of truth object
 *   render → pure function that reads STATE and updates DOM
 *   action handlers → mutate STATE then call render()
 *
 * Zones layout (4 cols × 3 rows):
 *   [0][1][2][3]   ← top row
 *   [4][5][6][7]   ← middle row
 *   [8][9][10][11] ← bottom row
 *
 * GK dive covers 2 adjacent zones:
 *   LEFT  → col 0 (zones 0,4,8)   + partial col1
 *   RIGHT → col 3 (zones 3,7,11)  + partial col2
 *   CENTER→ cols 1-2 (zones 1,2,5,6,9,10) — covers middle 2 of top/mid/bot
 */

/* ═══════════════════════════════════════════════════════════════
   COUNTRY DATA  (flag emoji + name)
═══════════════════════════════════════════════════════════════ */
const COUNTRIES = [
  { id:'arg', flag:'🇦🇷', name:'Argentina' },
  { id:'aus', flag:'🇦🇺', name:'Australia' },
  { id:'bel', flag:'🇧🇪', name:'Belgium'   },
  { id:'bra', flag:'🇧🇷', name:'Brazil'    },
  { id:'cmr', flag:'🇨🇲', name:'Cameroon'  },
  { id:'can', flag:'🇨🇦', name:'Canada'    },
  { id:'chl', flag:'🇨🇱', name:'Chile'     },
  { id:'chn', flag:'🇨🇳', name:'China'     },
  { id:'col', flag:'🇨🇴', name:'Colombia'  },
  { id:'cro', flag:'🇭🇷', name:'Croatia'   },
  { id:'cze', flag:'🇨🇿', name:'Czechia'   },
  { id:'den', flag:'🇩🇰', name:'Denmark'   },
  { id:'egy', flag:'🇪🇬', name:'Egypt'     },
  { id:'eng', flag:'🏴󠁧󠁢󠁥󠁮󠁧󠁿', name:'England'   },
  { id:'fra', flag:'🇫🇷', name:'France'    },
  { id:'ger', flag:'🇩🇪', name:'Germany'   },
  { id:'gha', flag:'🇬🇭', name:'Ghana'     },
  { id:'gre', flag:'🇬🇷', name:'Greece'    },
  { id:'hun', flag:'🇭🇺', name:'Hungary'   },
  { id:'ind', flag:'🇮🇳', name:'India'     },
  { id:'iri', flag:'🇮🇷', name:'Iran'      },
  { id:'ire', flag:'🇮🇪', name:'Ireland'   },
  { id:'ita', flag:'🇮🇹', name:'Italy'     },
  { id:'jpn', flag:'🇯🇵', name:'Japan'     },
  { id:'mex', flag:'🇲🇽', name:'Mexico'    },
  { id:'mar', flag:'🇲🇦', name:'Morocco'   },
  { id:'ned', flag:'🇳🇱', name:'Netherlands'},
  { id:'nig', flag:'🇳🇬', name:'Nigeria'   },
  { id:'nor', flag:'🇳🇴', name:'Norway'    },
  { id:'pak', flag:'🇵🇰', name:'Pakistan'  },
  { id:'pol', flag:'🇵🇱', name:'Poland'    },
  { id:'por', flag:'🇵🇹', name:'Portugal'  },
  { id:'qat', flag:'🇶🇦', name:'Qatar'     },
  { id:'rou', flag:'🇷🇴', name:'Romania'   },
  { id:'rus', flag:'🇷🇺', name:'Russia'    },
  { id:'sau', flag:'🇸🇦', name:'Saudi Arabia'},
  { id:'sen', flag:'🇸🇳', name:'Senegal'   },
  { id:'srb', flag:'🇷🇸', name:'Serbia'    },
  { id:'esp', flag:'🇪🇸', name:'Spain'     },
  { id:'swe', flag:'🇸🇪', name:'Sweden'    },
  { id:'swi', flag:'🇨🇭', name:'Switzerland'},
  { id:'tur', flag:'🇹🇷', name:'Türkiye'   },
  { id:'uae', flag:'🇦🇪', name:'UAE'       },
  { id:'ukr', flag:'🇺🇦', name:'Ukraine'   },
  { id:'usa', flag:'🇺🇸', name:'USA'       },
  { id:'uru', flag:'🇺🇾', name:'Uruguay'   },
  { id:'wal', flag:'🏴󠁧󠁢󠁷󠁬󠁳󠁿', name:'Wales'     },
].sort((a,b) => a.name.localeCompare(b.name));

/* ═══════════════════════════════════════════════════════════════
   SOUND ENGINE  (Web Audio API — zero external assets)
═══════════════════════════════════════════════════════════════ */
const Sound = (() => {
  let ctx = null;
  let muted = false;

  function getCtx() {
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  /** Play a burst of white noise (crowd roar) */
  function crowd(duration = 1.2) {
    if (muted) return;
    const ac = getCtx();
    const buf = ac.createBuffer(1, ac.sampleRate * duration, ac.sampleRate);
    const data = buf.getChannelData(0);
    for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1) * 0.35;
    const src = ac.createBufferSource();
    src.buffer = buf;
    const gain = ac.createGain();
    gain.gain.setValueAtTime(0.01, ac.currentTime);
    gain.gain.linearRampToValueAtTime(0.35, ac.currentTime + 0.12);
    gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + duration);
    const filter = ac.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.value = 800;
    filter.Q.value = 0.4;
    src.connect(filter);
    filter.connect(gain);
    gain.connect(ac.destination);
    src.start();
    src.stop(ac.currentTime + duration);
  }

  /** Whistle: short high-pitched sine sweep */
  function whistle() {
    if (muted) return;
    const ac = getCtx();
    const osc = ac.createOscillator();
    const gain = ac.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(2400, ac.currentTime);
    osc.frequency.linearRampToValueAtTime(2800, ac.currentTime + 0.08);
    osc.frequency.linearRampToValueAtTime(2600, ac.currentTime + 0.18);
    gain.gain.setValueAtTime(0.4, ac.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.22);
    osc.connect(gain);
    gain.connect(ac.destination);
    osc.start();
    osc.stop(ac.currentTime + 0.25);
  }

  /** Goal celebration: whistle + crowd */
  function goal() {
    if (muted) return;
    whistle();
    setTimeout(() => crowd(2.0), 60);
    // Triumphant horn
    const ac = getCtx();
    [523, 659, 784].forEach((freq, i) => {
      const osc = ac.createOscillator();
      const g   = ac.createGain();
      osc.type = 'sawtooth';
      osc.frequency.value = freq;
      g.gain.setValueAtTime(0, ac.currentTime + i*0.12);
      g.gain.linearRampToValueAtTime(0.18, ac.currentTime + i*0.12 + 0.06);
      g.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + i*0.12 + 0.32);
      osc.connect(g); g.connect(ac.destination);
      osc.start(ac.currentTime + i*0.12);
      osc.stop(ac.currentTime + i*0.12 + 0.4);
    });
  }

  /** Saved: dull thud + short crowd groan */
  function saved() {
    if (muted) return;
    const ac = getCtx();
    const osc = ac.createOscillator();
    const g   = ac.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(160, ac.currentTime);
    osc.frequency.exponentialRampToValueAtTime(60, ac.currentTime + 0.18);
    g.gain.setValueAtTime(0.5, ac.currentTime);
    g.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.22);
    osc.connect(g); g.connect(ac.destination);
    osc.start(); osc.stop(ac.currentTime + 0.25);
    setTimeout(() => crowd(0.6), 80);
  }

  /** Miss: descending tones */
  function miss() {
    if (muted) return;
    const ac = getCtx();
    [440, 370, 330].forEach((freq, i) => {
      const osc = ac.createOscillator();
      const g   = ac.createGain();
      osc.frequency.value = freq;
      g.gain.setValueAtTime(0.2, ac.currentTime + i*0.1);
      g.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + i*0.1 + 0.25);
      osc.connect(g); g.connect(ac.destination);
      osc.start(ac.currentTime + i*0.1);
      osc.stop(ac.currentTime + i*0.1 + 0.3);
    });
  }

  return {
    goal, saved, miss, whistle,
    toggleMute() { muted = !muted; return muted; },
    isMuted() { return muted; }
  };
})();

/* ═══════════════════════════════════════════════════════════════
   CONFETTI ENGINE  (canvas-based, lightweight)
═══════════════════════════════════════════════════════════════ */
const Confetti = (() => {
  let animId = null;
  let pieces = [];

  function launch(canvas) {
    canvas.width  = canvas.offsetWidth  || window.innerWidth;
    canvas.height = canvas.offsetHeight || window.innerHeight;
    const ctx = canvas.getContext('2d');
    const colors = ['#f5c842','#4cce6a','#e03030','#ffffff','#5bc0ff','#ff6bbd'];
    pieces = Array.from({ length: 120 }, () => ({
      x: Math.random() * canvas.width,
      y: -20 - Math.random() * 80,
      w: 6 + Math.random() * 8,
      h: 10 + Math.random() * 8,
      color: colors[Math.floor(Math.random() * colors.length)],
      rot: Math.random() * Math.PI * 2,
      vx: (Math.random() - 0.5) * 3,
      vy: 2 + Math.random() * 4,
      vr: (Math.random() - 0.5) * 0.2,
      life: 1,
    }));

    function frame() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      pieces.forEach(p => {
        p.x  += p.vx;
        p.y  += p.vy;
        p.rot += p.vr;
        p.vy += 0.05;
        p.life -= 0.006;
        if (p.life < 0) p.life = 0;
        ctx.save();
        ctx.globalAlpha = p.life;
        ctx.translate(p.x, p.y);
        ctx.rotate(p.rot);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
        ctx.restore();
      });
      pieces = pieces.filter(p => p.life > 0 && p.y < canvas.height + 40);
      if (pieces.length) animId = requestAnimationFrame(frame);
    }
    if (animId) cancelAnimationFrame(animId);
    frame();
  }

  function stop(canvas) {
    if (animId) cancelAnimationFrame(animId);
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  return { launch, stop };
})();

/* ═══════════════════════════════════════════════════════════════
   GAME STATE
═══════════════════════════════════════════════════════════════ */
const STATE = {
  mode: 'pvc',          // 'pvc' | 'pvp'
  teams: [
    { ...COUNTRIES.find(c=>c.id==='eng') },  // left  (player 1)
    { ...COUNTRIES.find(c=>c.id==='esp') },  // right (player 2 / CPU)
  ],
  penalties: [
    [], // left  team outcomes: 'goal'|'saved'|'miss'|null
    [], // right team outcomes
  ],
  scores: [0, 0],
  round: 0,             // 0-9 (10 penalties total, alternating)
  phase: 'shooting',    // 'shooting' | 'result' | 'done'
  pendingSide: null,    // which team slot is picking a country
};

/* Helper: whose turn is it? (0=left, 1=right) */
function currentTeam() { return STATE.round % 2; }
function currentPenalty() { return Math.floor(STATE.round / 2) + 1; }  // 1-5

/* ═══════════════════════════════════════════════════════════════
   DOM REFS
═══════════════════════════════════════════════════════════════ */
const $ = id => document.getElementById(id);

const DOM = {
  flagLeft:    $('flag-left'),
  flagRight:   $('flag-right'),
  emojiLeft:   $('flag-emoji-left'),
  emojiRight:  $('flag-emoji-right'),
  nameLeft:    $('team-name-left'),
  nameRight:   $('team-name-right'),
  scoreLeft:   $('score-left'),
  scoreRight:  $('score-right'),
  counter:     $('penalty-counter'),
  dotsLeft:    $('dots-left'),
  dotsRight:   $('dots-right'),
  leftLabel:   $('left-label'),
  rightLabel:  $('right-label'),
  status:      $('status-msg'),
  modeBtn:     $('mode-btn'),
  muteBtn:     $('mute-btn'),
  resetBtn:    $('reset-btn'),
  goalkeeper:  $('goalkeeper'),
  shootZones:  $('shoot-zones'),
  resultOverlay: $('result-overlay'),
  resultIcon:    $('result-icon'),
  resultText:    $('result-text'),
  resultSub:     $('result-sub'),
  winnerOverlay: $('winner-overlay'),
  winnerFlag:    $('winner-flag'),
  winnerText:    $('winner-text'),
  winnerScore:   $('winner-score'),
  winnerReset:   $('winner-reset'),
  confettiCanvas:$('confetti-canvas'),
  modal:         $('country-modal'),
  modalClose:    $('modal-close'),
  countrySearch: $('country-search'),
  countryList:   $('country-list'),
};

/* ═══════════════════════════════════════════════════════════════
   BUILD SHOOTING ZONES
═══════════════════════════════════════════════════════════════ */
function buildZones() {
  DOM.shootZones.innerHTML = '';
  for (let i = 0; i < 12; i++) {
    const z = document.createElement('button');
    z.className   = 'zone';
    z.dataset.idx = i;
    z.setAttribute('aria-label', `Shoot zone ${i + 1}`);
    z.setAttribute('tabindex', '0');
    z.addEventListener('click',       () => onZoneClick(i));
    z.addEventListener('touchstart', e => { e.preventDefault(); onZoneClick(i); }, { passive: false });
    DOM.shootZones.appendChild(z);
  }
}

/* ═══════════════════════════════════════════════════════════════
   GK DIVE LOGIC
   Zones by column:  col0=[0,4,8]  col1=[1,5,9]  col2=[2,6,10]  col3=[3,7,11]
   Dive LEFT  covers col0+col1 = [0,1,4,5,8,9]
   CENTER     covers col1+col2 = [1,2,5,6,9,10]
   Dive RIGHT covers col2+col3 = [2,3,6,7,10,11]
═══════════════════════════════════════════════════════════════ */
const GK_COVERAGE = {
  left:   new Set([0,1,4,5,8,9]),
  center: new Set([1,2,5,6,9,10]),
  right:  new Set([2,3,6,7,10,11]),
};

function gkDive() {
  const r = Math.random();
  if (r < 0.38)      return 'left';
  else if (r < 0.76) return 'right';
  else                return 'center';
}

/* ═══════════════════════════════════════════════════════════════
   SHOT RESOLUTION
═══════════════════════════════════════════════════════════════ */
function resolveShot(zoneIdx) {
  const dive = gkDive();
  const covered = GK_COVERAGE[dive].has(zoneIdx);
  // 15% chance shot goes wide (miss), even if zone not covered
  const missChance = 0.15;
  const outcome = covered
    ? 'saved'
    : Math.random() < missChance ? 'miss' : 'goal';
  return { dive, covered, outcome };
}

/* ═══════════════════════════════════════════════════════════════
   SHOT ANIMATION
═══════════════════════════════════════════════════════════════ */
function animateBall(zoneIdx, callback) {
  const zones   = DOM.shootZones.querySelectorAll('.zone');
  const zone    = zones[zoneIdx];
  const rect    = zone.getBoundingClientRect();
  const centerX = rect.left + rect.width  / 2;
  const centerY = rect.top  + rect.height / 2;

  // Ball starts from bottom-center of screen
  const startX = window.innerWidth  / 2 - 11;
  const startY = window.innerHeight - 60;

  const ball = document.createElement('div');
  ball.className = 'ball-anim';
  ball.style.left = startX + 'px';
  ball.style.top  = startY + 'px';
  ball.style.setProperty('--bx', (centerX - startX) + 'px');
  ball.style.setProperty('--by', (centerY - startY) + 'px');
  document.body.appendChild(ball);

  setTimeout(() => { ball.remove(); callback(); }, 460);
}

/* ═══════════════════════════════════════════════════════════════
   SHOW / HIDE RESULT OVERLAY
═══════════════════════════════════════════════════════════════ */
function showResult(outcome, team, callback) {
  const icons  = { goal: '⚽', saved: '🧤', miss: '💨' };
  const labels = { goal: 'GOAL!', saved: 'SAVED!', miss: 'MISSED!' };
  const subs   = {
    goal:  `${team.flag} ${team.name} scores!`,
    saved: `${team.flag} ${team.name} — The keeper gets it!`,
    miss:  `${team.flag} ${team.name} — Ball goes wide!`
  };
  DOM.resultIcon.textContent = icons[outcome];
  DOM.resultText.textContent = labels[outcome];
  DOM.resultText.className   = 'result-text ' + outcome;
  DOM.resultSub.innerHTML    = subs[outcome];
  DOM.resultOverlay.classList.remove('hidden');

  setTimeout(() => {
    DOM.resultOverlay.classList.add('hidden');
    callback();
  }, 1400);
}

/* ═══════════════════════════════════════════════════════════════
   SHOW WINNER
═══════════════════════════════════════════════════════════════ */
function showWinner() {
  const [s0, s1] = STATE.scores;
  let winnerIdx, text;
  if (s0 > s1)      { winnerIdx = 0; text = STATE.teams[0].name + ' Wins!'; }
  else if (s1 > s0) { winnerIdx = 1; text = STATE.teams[1].name + ' Wins!'; }
  else              { winnerIdx = -1; text = "It's a Draw!"; }

  DOM.winnerFlag.textContent  = winnerIdx >= 0 ? STATE.teams[winnerIdx].flag : '🏆';
  DOM.winnerText.textContent  = text;
  DOM.winnerScore.textContent = `${STATE.teams[0].name} ${s0} – ${s1} ${STATE.teams[1].name}`;
  DOM.winnerOverlay.classList.remove('hidden');
  Confetti.launch(DOM.confettiCanvas);
  if (winnerIdx >= 0) Sound.goal(); else Sound.whistle();
}

/* ═══════════════════════════════════════════════════════════════
   RENDER  — updates DOM from STATE
═══════════════════════════════════════════════════════════════ */
function render() {
  // Flags + names
  DOM.emojiLeft.textContent   = STATE.teams[0].flag;
  DOM.emojiRight.textContent  = STATE.teams[1].flag;
  DOM.nameLeft.textContent    = STATE.teams[0].name;
  DOM.nameRight.textContent   = STATE.teams[1].name;
  DOM.leftLabel.innerHTML  = `${STATE.teams[0].flag} ${STATE.teams[0].name}`;
  DOM.rightLabel.innerHTML = `${STATE.teams[1].flag} ${STATE.teams[1].name}`;

  // Scores
  DOM.scoreLeft.textContent  = STATE.scores[0];
  DOM.scoreRight.textContent = STATE.scores[1];

  // Penalty counter
  const pen = Math.min(currentPenalty(), 5);
  DOM.counter.textContent = `Penalty ${pen} of 5`;

  // Mode button
  DOM.modeBtn.textContent = STATE.mode === 'pvc' ? 'HODLer 👤 vs FUDster 🤖' : 'HODLer 👤 vs HODLer 👤';

  // Penalty dots
  renderDots(DOM.dotsLeft,  STATE.penalties[0], currentTeam() === 0 && STATE.phase === 'shooting');
  renderDots(DOM.dotsRight, STATE.penalties[1], currentTeam() === 1 && STATE.phase === 'shooting');

  // Status message — flag + name + instruction
  if (STATE.phase === 'shooting') {
    const team = STATE.teams[currentTeam()];
    const isComputer = STATE.mode === 'pvc' && currentTeam() === 1;
    DOM.status.innerHTML = isComputer
      ? `<span class="status-flag">${team.flag}</span> <span class="status-name">${team.name}</span> <span class="status-hint">— Computer is shooting…</span>`
      : `<span class="status-flag">${team.flag}</span> <span class="status-name">${team.name}</span> <span class="status-hint">— Click a zone to shoot!</span>`;
  }

  // Enable / disable zones
  const zonesDisabled = STATE.phase !== 'shooting'
    || (STATE.mode === 'pvc' && currentTeam() === 1);
  DOM.shootZones.querySelectorAll('.zone').forEach(z => {
    z.disabled = zonesDisabled;
    z.style.cursor = zonesDisabled ? 'default' : 'crosshair';
  });
}

function renderDots(container, outcomes, isActive) {
  container.innerHTML = '';
  for (let i = 0; i < 5; i++) {
    const d = document.createElement('span');
    d.className = 'dot';
    if (outcomes[i] === 'goal')  d.classList.add('goal');
    if (outcomes[i] === 'saved' || outcomes[i] === 'miss') d.classList.add('saved');
    if (i === outcomes.length && isActive) d.classList.add('current');
    container.appendChild(d);
  }
}

/* ═══════════════════════════════════════════════════════════════
   ZONE CLICK HANDLER
═══════════════════════════════════════════════════════════════ */
function onZoneClick(idx) {
  if (STATE.phase !== 'shooting') return;
  if (STATE.mode === 'pvc' && currentTeam() === 1) return; // block during CPU turn

  processShot(idx);
}

function processShot(zoneIdx) {
  if (STATE.phase !== 'shooting') return;

  STATE.phase = 'result';
  Sound.whistle();

  const team = currentTeam();
  const { dive, outcome } = resolveShot(zoneIdx);

  // Highlight chosen zone
  const zones = DOM.shootZones.querySelectorAll('.zone');
  zones[zoneIdx].classList.add('targeted');

  // Animate GK
  DOM.goalkeeper.className = `goalkeeper ${dive}`;
  if (outcome === 'saved') {
    // highlight saved zones
    GK_COVERAGE[dive].forEach(z => zones[z] && zones[z].classList.add('saved-zone'));
  }

  // Animate ball, then show result
  animateBall(zoneIdx, () => {
    // Play sound
    if      (outcome === 'goal')  Sound.goal();
    else if (outcome === 'saved') Sound.saved();
    else                           Sound.miss();

    // Record outcome
    STATE.penalties[team].push(outcome);
    if (outcome === 'goal') STATE.scores[team]++;

    showResult(outcome, STATE.teams[team], () => {
      // Reset visual state
      zones.forEach(z => z.classList.remove('targeted','saved-zone'));
      DOM.goalkeeper.className = 'goalkeeper center';

      STATE.round++;

      // Check if game is done
      if (STATE.round >= 10) {
        STATE.phase = 'done';
        render();
        showWinner();
        return;
      }

      STATE.phase = 'shooting';
      render();

      // If CPU turn in PvC mode, auto-shoot after short delay
      if (STATE.mode === 'pvc' && currentTeam() === 1) {
        setTimeout(() => {
          const cpuZone = Math.floor(Math.random() * 12);
          processShot(cpuZone);
        }, 900);
      }
    });
  });
}

/* ═══════════════════════════════════════════════════════════════
   RESET GAME
═══════════════════════════════════════════════════════════════ */
function resetGame() {
  STATE.penalties = [[], []];
  STATE.scores    = [0, 0];
  STATE.round     = 0;
  STATE.phase     = 'shooting';

  DOM.winnerOverlay.classList.add('hidden');
  DOM.resultOverlay.classList.add('hidden');
  Confetti.stop(DOM.confettiCanvas);

  // Reset GK
  DOM.goalkeeper.className = 'goalkeeper center';

  // Clear zone highlights
  DOM.shootZones.querySelectorAll('.zone').forEach(z =>
    z.classList.remove('targeted','saved-zone')
  );

  render();

  // Auto CPU shot if CPU goes first (shouldn't happen since left=player)
  if (STATE.mode === 'pvc' && currentTeam() === 1) {
    setTimeout(() => processShot(Math.floor(Math.random() * 12)), 900);
  }
}

/* ═══════════════════════════════════════════════════════════════
   COUNTRY PICKER MODAL
═══════════════════════════════════════════════════════════════ */
function openCountryModal(side) {
  STATE.pendingSide = side;
  DOM.countrySearch.value = '';
  renderCountryList('');
  DOM.modal.classList.remove('hidden');
  DOM.countrySearch.focus();
}

function closeCountryModal() {
  DOM.modal.classList.add('hidden');
  STATE.pendingSide = null;
}

function renderCountryList(filter) {
  const f = filter.toLowerCase().trim();
  const filtered = f ? COUNTRIES.filter(c => c.name.toLowerCase().includes(f)) : COUNTRIES;
  DOM.countryList.innerHTML = '';
  filtered.forEach(c => {
    const li = document.createElement('li');
    li.setAttribute('role','option');
    li.innerHTML = `<span class="c-flag">${c.flag}</span><span class="c-name">${c.name}</span>`;
    li.addEventListener('click', () => {
      STATE.teams[STATE.pendingSide] = { ...c };
      closeCountryModal();
      render();
    });
    DOM.countryList.appendChild(li);
  });
}

/* ═══════════════════════════════════════════════════════════════
   EVENT LISTENERS
═══════════════════════════════════════════════════════════════ */

// Flag buttons → open country picker
DOM.flagLeft.addEventListener('click',  () => openCountryModal(0));
DOM.flagRight.addEventListener('click', () => openCountryModal(1));

// Modal close
DOM.modalClose.addEventListener('click', closeCountryModal);
DOM.modal.addEventListener('click', e => { if (e.target === DOM.modal) closeCountryModal(); });
DOM.countrySearch.addEventListener('input', e => renderCountryList(e.target.value));

// Mode toggle
DOM.modeBtn.addEventListener('click', () => {
  STATE.mode = STATE.mode === 'pvc' ? 'pvp' : 'pvc';
  resetGame();
});

// Reset buttons
DOM.resetBtn.addEventListener('click', resetGame);
DOM.winnerReset.addEventListener('click', resetGame);

// Mute toggle
DOM.muteBtn.addEventListener('click', () => {
  const nowMuted = Sound.toggleMute();
  DOM.muteBtn.textContent = nowMuted ? '🔇' : '🔊';
  DOM.muteBtn.title       = nowMuted ? 'Unmute' : 'Mute';
});

// Keyboard: Escape closes modal
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeCountryModal();
});

/* ═══════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════ */
buildZones();
render();

// Short delay before CPU shoots (if CPU is first — edge case)
if (STATE.mode === 'pvc' && currentTeam() === 1) {
  setTimeout(() => processShot(Math.floor(Math.random() * 12)), 900);
}
