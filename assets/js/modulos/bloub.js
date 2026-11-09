/**
 * bloub-engine.js — Motor puro SVG del avatar animado Bloub (x.ai / Grok style)
 * Basado en el proyecto de código abierto jeremy-prt/bloub (MIT License).
 * Soporta formas adaptables, estados de animación, seguimiento del cursor y render en tiempo real.
 */
(function(global) {
  'use strict';

  const TAU = Math.PI * 2;
  const clamp = (v, lo = 0, hi = 1) => (v < lo ? lo : v > hi ? hi : v);
  const lerp = (a, b, t) => a + (b - a) * t;
  const r2 = (v) => Math.round(v * 100) / 100;

  function loopNoise(t, period, seed = 0) {
    const p = (t / period) * TAU;
    return (
      0.55 * Math.sin(p + seed) +
      0.3 * Math.sin(2 * p + seed * 1.7 + 1.1) +
      0.15 * Math.sin(3 * p + seed * 2.3 + 2.4)
    );
  }

  const PROFILE_SAMPLES = 64;
  const ANGLES = Array.from({ length: PROFILE_SAMPLES }, (_, i) => (i / PROFILE_SAMPLES) * TAU);
  const COS = ANGLES.map(Math.cos);
  const SIN = ANGLES.map(Math.sin);

  const PROFILES = {
    circle: new Array(PROFILE_SAMPLES).fill(1),
    egg: [0.8369,0.8424,0.8497,0.8585,0.8674,0.8775,0.8878,0.8983,0.9089,0.9185,0.9288,0.9374,0.9445,0.9504,0.9543,0.9559,0.9555,0.9519,0.9466,0.9389,0.9302,0.9193,0.9085,0.8969,0.8852,0.8734,0.8625,0.8513,0.8411,0.8325,0.8243,0.8179,0.8137,0.8112,0.8102,0.8128,0.8178,0.8262,0.8374,0.8518,0.8702,0.8922,0.9169,0.9446,0.9741,1.0023,1.0267,1.0433,1.0481,1.0393,1.0216,0.9970,0.9697,0.9418,0.9169,0.8949,0.8760,0.8604,0.8490,0.8394,0.8337,0.8314,0.8305,0.8326],
    hexagon: [0.9210,0.9282,0.9441,0.9706,0.9984,1.0059,0.9896,0.9562,0.9290,0.9124,0.9047,0.9058,0.9157,0.9349,0.9642,0.9873,0.9882,0.9665,0.9336,0.9105,0.8968,0.8918,0.8955,0.9080,0.9293,0.9611,0.9820,0.9812,0.9590,0.9282,0.9089,0.8978,0.8964,0.9026,0.9189,0.9439,0.9778,0.9990,0.9964,0.9713,0.9439,0.9274,0.9196,0.9206,0.9308,0.9502,0.9799,1.0121,1.0226,1.0071,0.9752,0.9510,0.9366,0.9316,0.9351,0.9485,0.9711,1.0026,1.0213,1.0155,0.9863,0.9547,0.9347,0.9232],
    triangle: [0.7819,0.8211,0.8747,0.9440,1.0223,1.0960,1.1401,1.1340,1.0808,1.0047,0.9265,0.8603,0.8104,0.7730,0.7450,0.7273,0.7151,0.7118,0.7148,0.7245,0.7427,0.7680,0.8037,0.8518,0.9148,0.9876,1.0583,1.1073,1.1109,1.0667,0.9940,0.9164,0.8482,0.7948,0.7555,0.7261,0.7056,0.6925,0.6859,0.6869,0.6938,0.7084,0.7305,0.7615,0.8040,0.8595,0.9311,1.0092,1.0791,1.1171,1.1054,1.0501,0.9779,0.9050,0.8450,0.7990,0.7656,0.7413,0.7258,0.7160,0.7146,0.7204,0.7330,0.7528],
    squircle: ANGLES.map((_, i) => Math.pow(Math.pow(Math.abs(COS[i]), 3.8) + Math.pow(Math.abs(SIN[i]), 3.8), -1/3.8)),
    pebble: ANGLES.map((_, i) => 0.94 + 0.12 * Math.sin(ANGLES[i] * 2 + 0.5) - 0.08 * Math.cos(ANGLES[i] * 3)),
    cloud: ANGLES.map((_, i) => 0.92 + 0.14 * Math.sin(ANGLES[i] * 4) + 0.06 * Math.cos(ANGLES[i] * 2)),
    droplet: ANGLES.map((_, i) => 0.95 + 0.18 * Math.sin(ANGLES[i] - Math.PI/2) * (SIN[i] > 0 ? 0.3 : 1))
  };

  function closedPath(pts, tension = 1 / 6) {
    const n = pts.length;
    if (n < 3) return '';
    const first = pts[0];
    let d = `M${r2(first.x)} ${r2(first.y)}`;
    for (let i = 0; i < n; i++) {
      const p0 = pts[(i - 1 + n) % n];
      const p1 = pts[i];
      const p2 = pts[(i + 1) % n];
      const p3 = pts[(i + 2) % n];
      const c1x = p1.x + (p2.x - p0.x) * tension;
      const c1y = p1.y + (p2.y - p0.y) * tension;
      const c2x = p2.x - (p3.x - p1.x) * tension;
      const c2y = p2.y - (p3.y - p1.y) * tension;
      d += `C${r2(c1x)} ${r2(c1y)} ${r2(c2x)} ${r2(c2y)} ${r2(p2.x)} ${r2(p2.y)}`;
    }
    return d + 'Z';
  }

  function capsulePath(w, h) {
    const hw = Math.max(w, 0.01) / 2;
    const hh = Math.max(h, 0.01) / 2;
    const r = Math.min(hw, hh);
    return (
      `M${r2(-hw)} ${r2(-hh + r)}` +
      `A${r2(r)} ${r2(r)} 0 0 1 ${r2(-hw + r)} ${r2(-hh)}` +
      `L${r2(hw - r)} ${r2(-hh)}` +
      `A${r2(r)} ${r2(r)} 0 0 1 ${r2(hw)} ${r2(-hh + r)}` +
      `L${r2(hw)} ${r2(hh - r)}` +
      `A${r2(r)} ${r2(r)} 0 0 1 ${r2(hw - r)} ${r2(hh)}` +
      `L${r2(-hw + r)} ${r2(hh)}` +
      `A${r2(r)} ${r2(r)} 0 0 1 ${r2(-hw)} ${r2(hh - r)}Z`
    );
  }

  function spin(u, v, angle) {
    const c = Math.cos(angle);
    const s = Math.sin(angle);
    return [
      [u[0] * c + v[0] * s, u[1] * c + v[1] * s, u[2] * c + v[2] * s],
      [v[0] * c - u[0] * s, v[1] * c - u[1] * s, v[2] * c - u[2] * s]
    ];
  }

  const EYE_SPLIT = 15.46;
  const EYE_W = 0.186;
  const EYE_H = 0.412;
  const deg = (d) => (d * Math.PI) / 180;

  function eyePoses(gaze, scale, split = EYE_SPLIT) {
    let f = [0, 0, 1];
    let right = [1, 0, 0];
    let down = [0, 1, 0];
    [f, right] = spin(f, right, deg(gaze.yaw));
    [down, f] = spin(down, f, deg(gaze.pitch));
    [right, down] = spin(right, down, deg(gaze.roll));
    const build = (side) => {
      const [ef, er] = spin(f, right, deg(split * side));
      return {
        x: ef[0] * scale,
        y: ef[1] * scale,
        a: er[0],
        b: er[1],
        c: down[0],
        d: down[1],
        depth: ef[2]
      };
    };
    return [build(-1), build(1)];
  }

  class BloubAvatar {
    constructor(container, options = {}) {
      this.container = typeof container === 'string' ? document.querySelector(container) : container;
      if (!this.container) return;

      this.size = options.size || 64;
      this.shape = options.shape || 'circle';
      this.hue = options.hue !== undefined ? options.hue : 205;
      this.state = options.state || 'idle';
      this.interactive = options.interactive !== false;
      this.color = options.color || `hsl(${this.hue}, 75%, 62%)`;
      this.dark = options.dark !== false;

      this.targetLook = { yaw: 28.5, pitch: 28.6, mix: 0 };
      this.curLook = { yaw: 28.5, pitch: 28.6, mix: 0 };
      this.animId = null;
      this.startTime = performance.now();

      this.initSvg();
      if (this.interactive) this.bindEvents();
      this.start();
    }

    initSvg() {
      this.container.innerHTML = '';
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '-100 -100 200 200');
      svg.setAttribute('width', this.size);
      svg.setAttribute('height', this.size);
      svg.style.overflow = 'visible';
      svg.style.display = 'block';

      const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
      const filter = document.createElementNS('http://www.w3.org/2000/svg', 'filter');
      filter.setAttribute('id', `glow-${Math.random().toString(36).substr(2, 6)}`);
      defs.appendChild(filter);
      svg.appendChild(defs);

      // Grupos de decoracion
      this.gBack = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      this.bodyPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      this.bodyPath.setAttribute('fill', this.color);
      this.bodyPath.setAttribute('stroke', `hsl(${this.hue}, 65%, 48%)`);
      this.bodyPath.setAttribute('stroke-width', '2.5');

      this.gEyes = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      this.eyeL = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      this.eyeR = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      const eyeColor = '#1e2022';
      this.eyeL.setAttribute('fill', eyeColor);
      this.eyeR.setAttribute('fill', eyeColor);
      this.gEyes.appendChild(this.eyeL);
      this.gEyes.appendChild(this.eyeR);

      this.gFront = document.createElementNS('http://www.w3.org/2000/svg', 'g');

      svg.appendChild(this.gBack);
      svg.appendChild(this.bodyPath);
      svg.appendChild(this.gEyes);
      svg.appendChild(this.gFront);

      this.container.appendChild(svg);
      this.svg = svg;
    }

    bindEvents() {
      const onMove = (e) => {
        const rect = this.container.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        const dx = (e.clientX - cx) / (window.innerWidth / 2);
        const dy = (e.clientY - cy) / (window.innerHeight / 2);
        this.targetLook.yaw = clamp(dx * 45, -55, 55);
        this.targetLook.pitch = clamp(-dy * 45, -50, 50);
        this.targetLook.mix = 0.85;
      };
      const onLeave = () => {
        this.targetLook.yaw = 28.5;
        this.targetLook.pitch = 28.6;
        this.targetLook.mix = 0;
      };
      window.addEventListener('mousemove', onMove, { passive: true });
      document.addEventListener('mouseleave', onLeave);
    }

    setShape(shape) {
      if (PROFILES[shape]) this.shape = shape;
    }

    setHue(hue) {
      this.hue = hue;
      this.color = `hsl(${this.hue}, 75%, 62%)`;
      this.bodyPath.setAttribute('fill', this.color);
      this.bodyPath.setAttribute('stroke', `hsl(${this.hue}, 65%, 48%)`);
    }

    setState(state) {
      this.state = state;
    }

    start() {
      const loop = (now) => {
        this.render(now / 1000);
        this.animId = requestAnimationFrame(loop);
      };
      this.animId = requestAnimationFrame(loop);
    }

    stop() {
      if (this.animId) cancelAnimationFrame(this.animId);
    }

    render(t) {
      // Mirada interpolada
      this.curLook.yaw = lerp(this.curLook.yaw, this.targetLook.yaw, 0.1);
      this.curLook.pitch = lerp(this.curLook.pitch, this.targetLook.pitch, 0.1);
      this.curLook.mix = lerp(this.curLook.mix, this.targetLook.mix, 0.1);

      // Vida y clignotements
      const wander = 1 - this.curLook.mix;
      const dYaw = loopNoise(t, 11.3, 0.4) * 6 * wander;
      const dPitch = loopNoise(t, 9.1, 1.3) * 4 * wander;
      const dRoll = loopNoise(t, 13.7, 3.2) * 2.2 * wander;

      // Parpadeo periodico
      const blinkCycle = (t % 4.2);
      let lid = 1;
      if (blinkCycle > 3.9) {
        const bk = (blinkCycle - 3.9) / 0.18;
        lid = bk < 0.5 ? 1 - bk * 2 : (bk - 0.5) * 2;
      }
      if (this.state === 'sleep') lid = 0.05;
      if (this.state === 'wink') lid = 0.5;

      const gaze = {
        yaw: this.curLook.yaw + dYaw,
        pitch: this.curLook.pitch + dPitch,
        roll: -13 + dRoll
      };

      // Orbit animation state
      let rotOffset = 0;
      let radScale = 1;
      if (this.state === 'orbit') {
        rotOffset = t * 1.5;
        gaze.yaw += Math.sin(t * 3) * 20;
      } else if (this.state === 'thinking') {
        gaze.yaw = -25;
        gaze.pitch = 30;
      } else if (this.state === 'alert') {
        radScale = 1 + Math.sin(t * 8) * 0.04;
      }

      // Respiración leve del cuerpo
      const breath = 1 + Math.sin(t * 2) * 0.02;
      const R = 72 * radScale;

      // Construcción del path de la silueta
      const profile = PROFILES[this.shape] || PROFILES.circle;
      const pts = [];
      for (let i = 0; i < PROFILE_SAMPLES; i++) {
        const rad = profile[i] * R;
        const ang = ANGLES[i] + rotOffset;
        pts.push({
          x: rad * Math.cos(ang),
          y: rad * Math.sin(ang) * breath
        });
      }
      this.bodyPath.setAttribute('d', closedPath(pts));

      // Render de los ojos 3D proyectados
      const poses = eyePoses(gaze, R, EYE_SPLIT);
      const eyeList = [this.eyeL, this.eyeR];
      for (let i = 0; i < 2; i++) {
        const e = poses[i];
        const el = eyeList[i];
        if (e.depth <= 0.02) {
          el.setAttribute('opacity', '0');
          continue;
        }
        el.setAttribute('opacity', String(clamp(e.depth / 0.15)));
        const kLid = Math.max(0.08, lid);
        const w = EYE_W * R;
        const h = EYE_H * R;
        el.setAttribute('d', capsulePath(w, h));
        const matrix = `matrix(${r2(e.a)}, ${r2(e.b * kLid)}, ${r2(e.c)}, ${r2(e.d * kLid)}, ${r2(e.x)}, ${r2(e.y)})`;
        el.setAttribute('transform', matrix);
      }

      // Render extra: Anillos de orbit si está en orbit
      if (this.state === 'orbit') {
        this.renderOrbitRings(t, R);
      } else {
        this.gBack.innerHTML = '';
        this.gFront.innerHTML = '';
      }
    }

    renderOrbitRings(t, R) {
      if (this.gFront.children.length === 0) {
        const ring = document.createElementNS('http://www.w3.org/2000/svg', 'ellipse');
        ring.setAttribute('rx', R * 1.35);
        ring.setAttribute('ry', R * 0.38);
        ring.setAttribute('fill', 'none');
        ring.setAttribute('stroke', `hsl(${(this.hue + 45) % 360}, 85%, 65%)`);
        ring.setAttribute('stroke-width', '2.5');
        ring.setAttribute('stroke-dasharray', '8 4');
        ring.setAttribute('transform', 'rotate(-25)');
        this.gFront.appendChild(ring);
      }
    }
  }

  global.BloubAvatar = BloubAvatar;
})(window);
