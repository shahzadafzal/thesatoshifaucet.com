/* PoW Mini Miner — hashing worker (The Satoshi Faucet)
 *
 * Each worker owns its own slice of the search space (its own "extra nonce" in the merkle root
 * field) and tries nonces as fast as it can, reporting only when it beats the best leading-zero
 * count the game knows about. Runs entirely in the visitor's browser; nothing is sent anywhere.
 */
importScripts('pow-core.js');

const Core = self.PowCore;
const NONCE_SPACE = 4294967296;      // 2^32 nonces per extra-nonce value

let running = false;
let job = null;
let base = null;                     // fixed header parts
let workerId = 0;
let counter = 0;                     // extra nonce: bumped when the 32-bit nonce space is used up
let nonce = 0;
let needBits = 1;                    // a hash must have at least this many leading zero bits to be reported
let thr = Core.thresholdFor(1);
let batch = 20000;
let total = 0;
let lastTotal = 0;
let lastPost = 0;
const hits = [];

const mc = new MessageChannel();     // yields to the event loop without setTimeout's 4ms clamp
mc.port1.onmessage = loop;

function buildHeader() {
  const h = new Uint8Array(80);
  const dv = new DataView(h.buffer);
  dv.setUint32(0, base.version >>> 0, true);
  h.set(base.prev, 4);                                   // previous block hash (internal byte order)
  dv.setUint32(36, workerId >>> 0, true);                // merkle root: worker id,
  dv.setUint32(40, counter >>> 0, true);                 // extra nonce,
  h.set(base.seed, 44);                                  // and a per-session random seed
  dv.setUint32(68, base.time >>> 0, true);
  dv.setUint32(72, base.bits >>> 0, true);
  return h;
}

function rebuildJob() {
  job = Core.makeJob(buildHeader());
  nonce = 0;
}

function loop() {
  if (!running) return;
  const t0 = performance.now();

  const count = Math.min(batch, NONCE_SPACE - nonce);
  hits.length = 0;
  Core.mineBatch(job, nonce, count, thr, hits);
  nonce += count;
  total += count;

  for (let i = 0; i < hits.length; i++) {
    const n = hits[i] | 0;
    const S = Core.hashNonce(job, n);
    const bits = Core.leadingZeroBits(S);
    if (bits >= needBits) {
      needBits = bits + 1;
      thr = Core.thresholdFor(needBits);
      self.postMessage({ type: 'best', bits, hash: Core.displayHex(S), nonce: n >>> 0, extra: counter, worker: workerId });
    }
  }

  if (nonce >= NONCE_SPACE) {          // this extra nonce is exhausted: move to the next one
    counter++;
    rebuildJob();
  }

  const now = performance.now();
  const took = now - t0;
  batch = Math.max(2000, Math.min(400000, Math.round(batch * (50 / Math.max(took, 1)))));   // aim for ~50ms batches

  if (now - lastPost >= 200) {
    // the last hash computed is a nice "currently trying" sample for the display
    const sample = Core.displayHex(job.S2);
    self.postMessage({ type: 'progress', hashes: total - lastTotal, sample });
    lastTotal = total;
    lastPost = now;
  }

  mc.port2.postMessage(0);
}

self.onmessage = (e) => {
  const m = e.data;
  if (m.type === 'start') {
    base = { version: m.version, prev: m.prev, seed: m.seed, time: m.time, bits: m.bits };
    workerId = m.workerId;
    counter = 0;
    needBits = Math.max(1, m.needBits || 1);
    thr = Core.thresholdFor(needBits);
    total = 0;
    lastTotal = 0;
    lastPost = performance.now();
    batch = 20000;
    rebuildJob();
    running = true;
    mc.port2.postMessage(0);
  } else if (m.type === 'need') {
    needBits = Math.max(needBits, m.bits);
    thr = Core.thresholdFor(needBits);
  } else if (m.type === 'stop') {
    running = false;
  }
};
