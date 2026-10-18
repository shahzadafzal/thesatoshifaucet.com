/* Mempool Aquarium — creature drawing (The Satoshi Faucet)
 *
 * Each transaction tier gets its own animal:
 *   shrimp (small)  ·  fish  ·  shark  ·  whale (random: "whale" or "blue whale")
 *
 * All shapes are drawn with plain canvas paths, facing +x, then mirrored by f.dir.
 * `f` needs: x, y, r (half the body length), dir (1 or -1), hue, phase, tier, variant.
 * `t` is time in seconds (for the swimming animation).
 */
(function (root) {
  'use strict';

  var TAU = Math.PI * 2;
  var hsl = function (h, s, l) { return 'hsl(' + h + ',' + s + '%,' + l + '%)'; };

  function begin(ctx, f) {
    ctx.save();
    ctx.translate(f.x, f.y);
    ctx.scale(f.dir, 1);
  }

  /* ---------------- shrimp ---------------- */
  function drawShrimp(ctx, f, t) {
    var r = f.r;
    var hue = f.hue;
    var body = hsl(hue, 78, 60), shade = hsl(hue, 78, 42), light = hsl(hue, 88, 78);
    var flap = Math.sin(t * 9 + f.phase * 2);
    var sway = Math.sin(t * 3.2 + f.phase);
    var lw = Math.max(1, r * 0.06);

    begin(ctx, f);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    // spine: head (u=0, right) to tail (u=1, left), arched like a swimming shrimp
    var N = 7, pts = [], i, u;
    for (i = 0; i < N; i++) {
      u = i / (N - 1);
      pts.push({
        x: r * (0.62 - 1.35 * u),
        y: r * (-0.18 - 0.30 * Math.sin(Math.PI * Math.min(1, u * 1.1)) + 0.38 * u * u),
        rad: r * (0.34 - 0.2 * u),
      });
    }
    var head = pts[0], tail = pts[N - 1];

    // antennae
    ctx.strokeStyle = shade;
    ctx.lineWidth = lw;
    ctx.beginPath();
    ctx.moveTo(head.x + r * 0.18, head.y - r * 0.12);
    ctx.quadraticCurveTo(head.x + r * 0.8, head.y - r * 0.7 + sway * r * 0.12, head.x + r * 1.35, head.y - r * 0.35 + sway * r * 0.2);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(head.x + r * 0.2, head.y - r * 0.02);
    ctx.quadraticCurveTo(head.x + r * 0.85, head.y - r * 0.05 - sway * r * 0.1, head.x + r * 1.25, head.y + r * 0.3 + sway * r * 0.15);
    ctx.stroke();

    // little swimming legs
    ctx.lineWidth = Math.max(1, r * 0.05);
    for (i = 1; i <= 4; i++) {
      var p = pts[i];
      var ox = (i % 2 ? 1 : -1) * flap * r * 0.07;
      ctx.beginPath();
      ctx.moveTo(p.x, p.y + p.rad * 0.7);
      ctx.lineTo(p.x - r * 0.06 + ox, p.y + p.rad * 0.7 + r * 0.26);
      ctx.stroke();
    }

    // tail fan
    ctx.lineWidth = Math.max(1, r * 0.04);
    var fan = [-0.55, 0, 0.55];
    for (i = 0; i < fan.length; i++) {
      ctx.save();
      ctx.translate(tail.x, tail.y);
      ctx.rotate(Math.PI + 0.35 + fan[i] + flap * 0.12);
      ctx.fillStyle = light;
      ctx.strokeStyle = shade;
      ctx.beginPath();
      ctx.ellipse(r * 0.2, 0, r * 0.26, r * 0.1, 0, 0, TAU);
      ctx.fill();
      ctx.stroke();
      ctx.restore();
    }

    // abdomen segments, tail to head so the head overlaps
    ctx.strokeStyle = shade;
    ctx.lineWidth = Math.max(1, r * 0.045);
    for (i = N - 1; i >= 0; i--) {
      ctx.fillStyle = body;
      ctx.beginPath();
      ctx.arc(pts[i].x, pts[i].y, pts[i].rad, 0, TAU);
      ctx.fill();
      ctx.stroke();
    }

    // carapace / head
    ctx.fillStyle = body;
    ctx.beginPath();
    ctx.ellipse(head.x + r * 0.06, head.y, r * 0.42, r * 0.34, -0.1, 0, TAU);
    ctx.fill();
    ctx.stroke();

    // rostrum (the pointy bit on the nose)
    ctx.fillStyle = shade;
    ctx.beginPath();
    ctx.moveTo(head.x + r * 0.3, head.y - r * 0.2);
    ctx.lineTo(head.x + r * 0.82, head.y - r * 0.42);
    ctx.lineTo(head.x + r * 0.34, head.y - r * 0.02);
    ctx.closePath();
    ctx.fill();

    // eye
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.arc(head.x + r * 0.22, head.y - r * 0.08, Math.max(2, r * 0.1), 0, TAU);
    ctx.fill();
    ctx.fillStyle = '#10202b';
    ctx.beginPath();
    ctx.arc(head.x + r * 0.25, head.y - r * 0.08, Math.max(1, r * 0.055), 0, TAU);
    ctx.fill();

    ctx.restore();
  }

  /* ---------------- fish ---------------- */
  function drawFish(ctx, f, t) {
    var r = f.r;
    var wag = Math.sin(t * 7 + f.phase * 2) * 0.28;
    var top = hsl(f.hue, 80, 58), bot = hsl(f.hue, 80, 38), fin = hsl(f.hue, 80, 30);

    begin(ctx, f);

    // tail
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

    // dorsal fin
    ctx.fillStyle = fin;
    ctx.beginPath();
    ctx.moveTo(-r * 0.25, -r * 0.5);
    ctx.quadraticCurveTo(r * 0.05, -r * 0.9, r * 0.38, -r * 0.5);
    ctx.closePath();
    ctx.fill();

    // body
    var g = ctx.createLinearGradient(0, -r * 0.6, 0, r * 0.6);
    g.addColorStop(0, top);
    g.addColorStop(1, bot);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.ellipse(0, 0, r, r * 0.58, 0, 0, TAU);
    ctx.fill();

    // belly highlight
    ctx.fillStyle = 'rgba(255,255,255,0.18)';
    ctx.beginPath();
    ctx.ellipse(r * 0.05, r * 0.2, r * 0.7, r * 0.22, 0, 0, TAU);
    ctx.fill();

    // gill line
    ctx.strokeStyle = 'rgba(0,0,0,0.22)';
    ctx.lineWidth = Math.max(1, r * 0.04);
    ctx.beginPath();
    ctx.arc(r * 0.32, 0, r * 0.3, Math.PI * 0.62, Math.PI * 1.38);
    ctx.stroke();

    // eye
    var er = Math.max(2.2, r * 0.13);
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.arc(r * 0.56, -r * 0.12, er, 0, TAU);
    ctx.fill();
    ctx.fillStyle = '#10202b';
    ctx.beginPath();
    ctx.arc(r * 0.6, -r * 0.12, er * 0.52, 0, TAU);
    ctx.fill();

    ctx.restore();
  }

  /* ---------------- shark ---------------- */
  function drawShark(ctx, f, t) {
    var r = f.r;
    var wag = Math.sin(t * 5 + f.phase * 2) * 0.2;
    var topC = hsl(f.hue, 22, 36), midC = hsl(f.hue, 20, 52);
    var lw = Math.max(1, r * 0.04);

    begin(ctx, f);
    ctx.lineJoin = 'round';

    // tail (crescent), swinging from the tail base
    ctx.save();
    ctx.translate(-r * 0.78, 0);
    ctx.rotate(wag);
    ctx.fillStyle = topC;
    ctx.beginPath();
    ctx.moveTo(r * 0.12, -r * 0.06);
    ctx.lineTo(-r * 0.4, -r * 0.66);
    ctx.quadraticCurveTo(-r * 0.2, -r * 0.14, -r * 0.2, 0);
    ctx.quadraticCurveTo(-r * 0.16, r * 0.14, -r * 0.3, r * 0.42);
    ctx.lineTo(r * 0.12, r * 0.07);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // pectoral fin and tall dorsal fin
    ctx.fillStyle = topC;
    ctx.beginPath();
    ctx.moveTo(r * 0.26, r * 0.2);
    ctx.lineTo(-r * 0.18, r * 0.66);
    ctx.lineTo(-r * 0.1, r * 0.18);
    ctx.closePath();
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(r * 0.2, -r * 0.34);
    ctx.quadraticCurveTo(r * 0.0, -r * 0.62, -r * 0.14, -r * 0.84);
    ctx.lineTo(-r * 0.4, -r * 0.28);
    ctx.closePath();
    ctx.fill();

    // body: dark back fading to a white belly
    var g = ctx.createLinearGradient(0, -r * 0.46, 0, r * 0.42);
    g.addColorStop(0, topC);
    g.addColorStop(0.5, midC);
    g.addColorStop(0.58, '#e9eff5');
    g.addColorStop(1, '#ffffff');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.moveTo(r * 1.04, r * 0.06);
    ctx.bezierCurveTo(r * 0.66, -r * 0.48, -r * 0.15, -r * 0.52, -r * 0.84, -r * 0.08);
    ctx.lineTo(-r * 0.84, r * 0.08);
    ctx.bezierCurveTo(-r * 0.2, r * 0.48, r * 0.6, r * 0.44, r * 1.04, r * 0.06);
    ctx.closePath();
    ctx.fill();

    // gill slits, mouth, eye
    ctx.strokeStyle = 'rgba(25,40,55,0.55)';
    ctx.lineWidth = lw;
    ctx.lineCap = 'round';
    for (var k = 0; k < 3; k++) {
      ctx.beginPath();
      ctx.moveTo(r * (0.3 - k * 0.1), -r * 0.2);
      ctx.quadraticCurveTo(r * (0.24 - k * 0.1), 0, r * (0.3 - k * 0.1), r * 0.2);
      ctx.stroke();
    }
    ctx.beginPath();
    ctx.moveTo(r * 1.0, r * 0.1);
    ctx.quadraticCurveTo(r * 0.74, r * 0.24, r * 0.52, r * 0.17);
    ctx.stroke();
    ctx.fillStyle = '#0b1620';
    ctx.beginPath();
    ctx.arc(r * 0.7, -r * 0.1, Math.max(2, r * 0.075), 0, TAU);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.85)';
    ctx.beginPath();
    ctx.arc(r * 0.72, -r * 0.12, Math.max(0.8, r * 0.025), 0, TAU);
    ctx.fill();

    ctx.restore();
  }

  /* ---------------- whales ---------------- */
  function glow(ctx) {
    ctx.shadowColor = 'rgba(255, 200, 60, 0.85)';
    ctx.shadowBlur = 24;
  }

  // A little fountain of water above the head, puffing every few seconds.
  function spout(ctx, x, y, r, t, phase) {
    var cyc = (t * 0.42 + phase / TAU) % 1;
    if (cyc > 0.45) return;
    var k = cyc / 0.45;
    var a = Math.sin(k * Math.PI);
    ctx.fillStyle = 'rgba(205, 238, 255,' + (0.85 * a).toFixed(2) + ')';
    for (var i = -2; i <= 2; i++) {
      var px = x + i * r * 0.1 * (0.5 + k);
      var py = y - r * 0.75 * k + Math.abs(i) * r * 0.1 * k;
      ctx.beginPath();
      ctx.arc(px, py, Math.max(1.5, r * 0.055), 0, TAU);
      ctx.fill();
    }
  }

  function drawBlueWhale(ctx, f, t) {
    var r = f.r;
    var wag = Math.sin(t * 2.2 + f.phase) * 0.12;

    begin(ctx, f);
    ctx.lineJoin = 'round';

    // tail flukes: a short, wide, gently swept pair
    ctx.save();
    ctx.translate(-r * 1.0, -r * 0.02);
    ctx.rotate(wag);
    glow(ctx);
    ctx.fillStyle = '#2f5f96';
    ctx.beginPath();
    ctx.moveTo(r * 0.16, 0);
    ctx.bezierCurveTo(-r * 0.05, -r * 0.08, -r * 0.2, -r * 0.3, -r * 0.4, -r * 0.4);
    ctx.bezierCurveTo(-r * 0.3, -r * 0.14, -r * 0.3, -r * 0.04, -r * 0.3, 0);
    ctx.bezierCurveTo(-r * 0.3, r * 0.04, -r * 0.3, r * 0.14, -r * 0.4, r * 0.4);
    ctx.bezierCurveTo(-r * 0.2, r * 0.3, -r * 0.05, r * 0.08, r * 0.16, 0);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // body: long, with a broad rounded head; slate blue on top, pale underneath
    var g = ctx.createLinearGradient(0, -r * 0.5, 0, r * 0.5);
    g.addColorStop(0, '#2f5f96');
    g.addColorStop(0.52, '#5f93c4');
    g.addColorStop(0.6, '#a9cde8');
    g.addColorStop(1, '#d6e8f5');
    glow(ctx);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.moveTo(r * 1.1, r * 0.14);
    ctx.bezierCurveTo(r * 1.16, -r * 0.34, r * 0.6, -r * 0.52, r * 0.0, -r * 0.42);
    ctx.bezierCurveTo(-r * 0.45, -r * 0.34, -r * 0.7, -r * 0.14, -r * 1.04, -r * 0.03);
    ctx.lineTo(-r * 1.04, r * 0.05);
    ctx.bezierCurveTo(-r * 0.55, r * 0.3, r * 0.2, r * 0.62, r * 0.72, r * 0.5);
    ctx.bezierCurveTo(r * 0.98, r * 0.44, r * 1.1, r * 0.3, r * 1.1, r * 0.14);
    ctx.closePath();
    ctx.fill();
    ctx.shadowBlur = 0;

    // long jaw line
    ctx.strokeStyle = 'rgba(15,40,75,0.55)';
    ctx.lineWidth = Math.max(1, r * 0.035);
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(r * 1.08, r * 0.2);
    ctx.quadraticCurveTo(r * 0.7, r * 0.3, r * 0.3, r * 0.2);
    ctx.stroke();

    // belly grooves
    ctx.strokeStyle = 'rgba(20,50,90,0.28)';
    ctx.lineWidth = Math.max(1, r * 0.025);
    for (var k = 0; k < 5; k++) {
      ctx.beginPath();
      ctx.moveTo(r * 0.85, r * (0.13 + k * 0.04));
      ctx.quadraticCurveTo(r * 0.2, r * (0.32 + k * 0.04), -r * 0.45, r * (0.2 + k * 0.025));
      ctx.stroke();
    }

    // mottled speckles
    ctx.fillStyle = 'rgba(190,220,245,0.35)';
    for (var s = 0; s < 7; s++) {
      ctx.beginPath();
      ctx.arc(r * (0.5 - s * 0.2), -r * (0.18 - (s % 3) * 0.04), Math.max(1, r * 0.035), 0, TAU);
      ctx.fill();
    }

    // small dorsal fin near the tail, long flipper
    ctx.fillStyle = '#2f5f96';
    ctx.beginPath();
    ctx.moveTo(-r * 0.42, -r * 0.22);
    ctx.lineTo(-r * 0.55, -r * 0.46);
    ctx.lineTo(-r * 0.7, -r * 0.16);
    ctx.closePath();
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(r * 0.3, r * 0.26);
    ctx.quadraticCurveTo(r * 0.05, r * 0.5, -r * 0.3, r * 0.74);
    ctx.quadraticCurveTo(-r * 0.12, r * 0.4, -r * 0.08, r * 0.24);
    ctx.closePath();
    ctx.fill();

    // eye
    ctx.fillStyle = '#0b1c2e';
    ctx.beginPath();
    ctx.arc(r * 0.8, r * 0.02, Math.max(2, r * 0.05), 0, TAU);
    ctx.fill();

    spout(ctx, r * 0.5, -r * 0.5, r, t, f.phase);
    ctx.restore();
  }

  function drawCartoonWhale(ctx, f, t) {
    var r = f.r;
    var wag = Math.sin(t * 2.6 + f.phase) * 0.1;

    begin(ctx, f);
    ctx.lineJoin = 'round';

    // curled tail with flukes, rising behind the body
    ctx.save();
    ctx.translate(-r * 0.78, -r * 0.1);
    ctx.rotate(wag);
    glow(ctx);
    ctx.fillStyle = '#2a4a82';
    ctx.beginPath();
    ctx.moveTo(r * 0.2, r * 0.25);
    ctx.quadraticCurveTo(-r * 0.3, r * 0.15, -r * 0.42, -r * 0.35);
    ctx.lineTo(-r * 0.3, -r * 0.4);
    ctx.quadraticCurveTo(-r * 0.15, -r * 0.0, r * 0.2, -r * 0.1);
    ctx.closePath();
    ctx.fill();
    ctx.beginPath();
    ctx.ellipse(-r * 0.46, -r * 0.46, r * 0.3, r * 0.1, -0.9, 0, TAU);
    ctx.fill();
    ctx.beginPath();
    ctx.ellipse(-r * 0.34, -r * 0.52, r * 0.3, r * 0.1, -0.2, 0, TAU);
    ctx.fill();
    ctx.restore();

    // big round body: navy back, white belly
    var g = ctx.createLinearGradient(0, -r * 0.7, 0, r * 0.65);
    g.addColorStop(0, '#2f5aa0');
    g.addColorStop(0.55, '#3d6fb8');
    g.addColorStop(0.6, '#eaf2fa');
    g.addColorStop(1, '#ffffff');
    glow(ctx);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.moveTo(r * 1.0, r * 0.16);
    ctx.bezierCurveTo(r * 1.02, -r * 0.62, r * 0.2, -r * 0.82, -r * 0.5, -r * 0.34);
    ctx.bezierCurveTo(-r * 0.8, -r * 0.1, -r * 0.82, r * 0.12, -r * 0.62, r * 0.28);
    ctx.bezierCurveTo(-r * 0.2, r * 0.78, r * 0.66, r * 0.8, r * 1.0, r * 0.16);
    ctx.closePath();
    ctx.fill();
    ctx.shadowBlur = 0;

    // belly lines
    ctx.strokeStyle = 'rgba(40,80,140,0.22)';
    ctx.lineWidth = Math.max(1, r * 0.03);
    for (var k = 0; k < 4; k++) {
      ctx.beginPath();
      ctx.moveTo(r * 0.8, r * (0.3 + k * 0.08));
      ctx.quadraticCurveTo(r * 0.2, r * (0.55 + k * 0.05), -r * 0.35, r * (0.4 + k * 0.04));
      ctx.stroke();
    }

    // flipper
    ctx.fillStyle = '#2a4a82';
    ctx.beginPath();
    ctx.moveTo(r * 0.12, r * 0.3);
    ctx.quadraticCurveTo(-r * 0.12, r * 0.62, -r * 0.4, r * 0.58);
    ctx.quadraticCurveTo(-r * 0.2, r * 0.38, -r * 0.1, r * 0.28);
    ctx.closePath();
    ctx.fill();

    // friendly face: eye, cheek, smile
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.arc(r * 0.62, -r * 0.1, Math.max(2.5, r * 0.11), 0, TAU);
    ctx.fill();
    ctx.fillStyle = '#0b1c2e';
    ctx.beginPath();
    ctx.arc(r * 0.65, -r * 0.1, Math.max(1.5, r * 0.06), 0, TAU);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,140,160,0.45)';
    ctx.beginPath();
    ctx.ellipse(r * 0.5, r * 0.1, r * 0.1, r * 0.06, 0, 0, TAU);
    ctx.fill();
    ctx.strokeStyle = '#0b1c2e';
    ctx.lineWidth = Math.max(1, r * 0.04);
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.arc(r * 0.66, r * 0.12, r * 0.22, 0.25, 1.15);
    ctx.stroke();

    spout(ctx, r * 0.35, -r * 0.7, r, t, f.phase);
    ctx.restore();
  }

  // One entry point for the game: pick the right animal for the transaction's tier.
  function drawCreature(ctx, f, t) {
    switch (f.tier) {
      case 'shrimp': drawShrimp(ctx, f, t); break;
      case 'shark': drawShark(ctx, f, t); break;
      case 'whale': (f.variant === 'blue' ? drawBlueWhale : drawCartoonWhale)(ctx, f, t); break;
      default: drawFish(ctx, f, t);
    }
  }

  root.AquariumCreatures = {
    draw: drawCreature,
    drawShrimp: drawShrimp, drawFish: drawFish, drawShark: drawShark,
    drawBlueWhale: drawBlueWhale, drawCartoonWhale: drawCartoonWhale,
  };
})(typeof self !== 'undefined' ? self : this);
