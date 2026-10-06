/* PoW Mini Miner — SHA-256 core (The Satoshi Faucet)
 *
 * A small, dependency-free double-SHA-256 tuned for the one job Bitcoin miners do: hash an
 * 80-byte block header over and over with a changing 4-byte nonce and look at the leading zeros.
 * The first 64 header bytes never change between attempts, so their SHA-256 state (the
 * "midstate") is computed once and each attempt costs just two compressions instead of three.
 *
 * Used in a Web Worker (importScripts) and in Node for tests (module.exports).
 */
(function (root) {
  'use strict';

  const K = new Int32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ]);

  const IV = new Int32Array([
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
  ]);

  const bswap32 = (x) => ((x & 0xff) << 24) | ((x & 0xff00) << 8) | ((x >>> 8) & 0xff00) | (x >>> 24);

  // One SHA-256 compression: out = H + rounds(H, W[0..15]). W must hold 64 words; W[16..63] is scratch.
  function compress(H, W, out) {
    for (let i = 16; i < 64; i++) {
      const w15 = W[i - 15];
      const w2 = W[i - 2];
      const s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3);
      const s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10);
      W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0;
    }
    let a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
    for (let i = 0; i < 64; i++) {
      const S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
      const t1 = (h + S1 + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0;
      const S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
      const t2 = (S0 + ((a & b) ^ (a & c) ^ (b & c))) | 0;
      h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
    }
    out[0] = (H[0] + a) | 0; out[1] = (H[1] + b) | 0; out[2] = (H[2] + c) | 0; out[3] = (H[3] + d) | 0;
    out[4] = (H[4] + e) | 0; out[5] = (H[5] + f) | 0; out[6] = (H[6] + g) | 0; out[7] = (H[7] + h) | 0;
  }

  const beWord = (bytes, o) => ((bytes[o] << 24) | (bytes[o + 1] << 16) | (bytes[o + 2] << 8) | bytes[o + 3]) | 0;
  const leWord = (bytes, o) => (bytes[o] | (bytes[o + 1] << 8) | (bytes[o + 2] << 16) | (bytes[o + 3] << 24)) | 0;

  // Build a mining job from an 80-byte header (the nonce bytes 76..79 are ignored).
  function makeJob(header) {
    const W = new Int32Array(64);
    for (let i = 0; i < 16; i++) W[i] = beWord(header, i * 4);
    const mid = new Int32Array(8);
    compress(IV, W, mid);                       // midstate after the first 64 bytes
    return {
      mid,
      w0: beWord(header, 64),                   // last 4 bytes of the merkle root
      w1: bswap32(leWord(header, 68)),          // time
      w2: bswap32(leWord(header, 72)),          // bits
      W: new Int32Array(64),
      S1: new Int32Array(8),
      S2: new Int32Array(8),
    };
  }

  // Double-SHA-256 of the job's header with the given nonce. Result words land in job.S2.
  function hashNonce(job, nonce) {
    const W = job.W, S1 = job.S1, S2 = job.S2;
    W[0] = job.w0; W[1] = job.w1; W[2] = job.w2; W[3] = bswap32(nonce);
    W[4] = 0x80000000 | 0;
    W[5] = 0; W[6] = 0; W[7] = 0; W[8] = 0; W[9] = 0; W[10] = 0; W[11] = 0; W[12] = 0; W[13] = 0; W[14] = 0;
    W[15] = 640;
    compress(job.mid, W, S1);
    W[0] = S1[0]; W[1] = S1[1]; W[2] = S1[2]; W[3] = S1[3]; W[4] = S1[4]; W[5] = S1[5]; W[6] = S1[6]; W[7] = S1[7];
    W[8] = 0x80000000 | 0;
    W[9] = 0; W[10] = 0; W[11] = 0; W[12] = 0; W[13] = 0; W[14] = 0;
    W[15] = 256;
    compress(IV, W, S2);
    return S2;
  }

  // Try `count` nonces starting at `start`. Any hash whose leading zero bits reach the threshold
  // (its first display word is < thr32) has its nonce pushed onto `hits`.
  function mineBatch(job, start, count, thr32, hits) {
    const W = job.W, S1 = job.S1, S2 = job.S2, mid = job.mid;
    const w0 = job.w0, w1 = job.w1, w2 = job.w2;
    const end = start + count;
    for (let n = start; n < end; n++) {
      W[0] = w0; W[1] = w1; W[2] = w2; W[3] = bswap32(n | 0);
      W[4] = 0x80000000 | 0;
      W[5] = 0; W[6] = 0; W[7] = 0; W[8] = 0; W[9] = 0; W[10] = 0; W[11] = 0; W[12] = 0; W[13] = 0; W[14] = 0;
      W[15] = 640;
      compress(mid, W, S1);
      W[0] = S1[0]; W[1] = S1[1]; W[2] = S1[2]; W[3] = S1[3]; W[4] = S1[4]; W[5] = S1[5]; W[6] = S1[6]; W[7] = S1[7];
      W[8] = 0x80000000 | 0;
      W[9] = 0; W[10] = 0; W[11] = 0; W[12] = 0; W[13] = 0; W[14] = 0;
      W[15] = 256;
      compress(IV, W, S2);
      if ((bswap32(S2[7]) >>> 0) < thr32) hits.push(n);
    }
  }

  // Leading zero bits of the displayed (byte-reversed) hash held in `S` (8 words).
  function leadingZeroBits(S) {
    let z = 0;
    for (let k = 7; k >= 0; k--) {
      const w = bswap32(S[k]) >>> 0;
      if (w === 0) { z += 32; continue; }
      return z + Math.clz32(w);
    }
    return z;
  }

  // The hash the way Bitcoin displays it: the digest bytes in reverse order, as hex.
  function displayHex(S) {
    let s = '';
    for (let k = 7; k >= 0; k--) {
      const w = S[k];
      for (let j = 0; j < 4; j++) s += ((w >>> (j * 8)) & 0xff).toString(16).padStart(2, '0');
    }
    return s;
  }

  // Threshold for mineBatch: a hash needs at least `bits` leading zeros -> first word < 2^(32-bits).
  function thresholdFor(bits) {
    if (bits <= 0) return 4294967296;
    if (bits >= 32) return 1;
    return Math.pow(2, 32 - bits);
  }

  const api = { compress, makeJob, hashNonce, mineBatch, leadingZeroBits, displayHex, thresholdFor, bswap32 };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.PowCore = api;
})(typeof self !== 'undefined' ? self : this);
