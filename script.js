// ============================================
// BACKGROUND ORBS
// ============================================
(function () {
  const canvas = document.getElementById('bg');
  const ctx = canvas.getContext('2d');
  let W, H;

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  resize();
  window.addEventListener('resize', resize);

  const orbs = [
    { x: 0.15, y: 0.2, r: 320, color: 'rgba(220,38,38,0.12)' },
    { x: 0.85, y: 0.6, r: 260, color: 'rgba(248,113,113,0.08)' },
    { x: 0.5,  y: 1.0, r: 300, color: 'rgba(75,30,30,0.5)' },
  ];

  let t = 0;

  function draw() {
    ctx.clearRect(0, 0, W, H);
    orbs.forEach((o, i) => {
      const dx = Math.sin(t * 0.3 + i * 2) * 30;
      const dy = Math.cos(t * 0.25 + i * 1.5) * 20;
      const grd = ctx.createRadialGradient(
        o.x * W + dx, o.y * H + dy, 0,
        o.x * W + dx, o.y * H + dy, o.r
      );
      grd.addColorStop(0, o.color);
      grd.addColorStop(1, 'transparent');
      ctx.beginPath();
      ctx.arc(o.x * W + dx, o.y * H + dy, o.r, 0, Math.PI * 2);
      ctx.fillStyle = grd;
      ctx.fill();
    });
    t += 0.016;
    requestAnimationFrame(draw);
  }
  draw();
})();


// ============================================
// 3D ICOSAHEDRON
// ============================================
(function () {
  const canvas = document.getElementById('icosa');
  const ctx = canvas.getContext('2d');
  const DPR = window.devicePixelRatio || 1;
  const SIZE = 460;
  canvas.width = SIZE * DPR;
  canvas.height = SIZE * DPR;
  ctx.scale(DPR, DPR);
  const W = SIZE, H = SIZE;
  const cx = W / 2, cy = H / 2;

  const phi = (1 + Math.sqrt(5)) / 2;
  const raw = [
    [-1, phi, 0], [1, phi, 0], [-1, -phi, 0], [1, -phi, 0],
    [0, -1, phi], [0, 1, phi], [0, -1, -phi], [0, 1, -phi],
    [phi, 0, -1], [phi, 0, 1], [-phi, 0, -1], [-phi, 0, 1]
  ];
  const len = Math.sqrt(1 + phi * phi);
  const verts = raw.map(v => [v[0] / len, v[1] / len, v[2] / len]);

  const faces = [
    [0,11,5],[0,5,1],[0,1,7],[0,7,10],[0,10,11],
    [1,5,9],[5,11,4],[11,10,2],[10,7,6],[7,1,8],
    [3,9,4],[3,4,2],[3,2,6],[3,6,8],[3,8,9],
    [4,9,5],[2,4,11],[6,2,10],[8,6,7],[9,8,1]
  ];

  let rotX = 0.3, rotY = 0.3;
  let mouseX = 0, mouseY = 0;
  let dragging = false, lastX = 0, lastY = 0;

  canvas.addEventListener('mousedown', e => {
    dragging = true;
    lastX = e.clientX;
    lastY = e.clientY;
    canvas.style.cursor = 'grabbing';
  });
  window.addEventListener('mouseup', () => {
    dragging = false;
    canvas.style.cursor = 'grab';
  });
  window.addEventListener('mousemove', e => {
    if (dragging) {
      rotY += (e.clientX - lastX) * 0.012;
      rotX += (e.clientY - lastY) * 0.012;
      lastX = e.clientX;
      lastY = e.clientY;
    }
    const rect = canvas.getBoundingClientRect();
    mouseX = (e.clientX - rect.left - cx) / cx;
    mouseY = (e.clientY - rect.top - cy) / cy;
  });

  function rotateY(v, a) {
    const c = Math.cos(a), s = Math.sin(a);
    return [v[0] * c + v[2] * s, v[1], -v[0] * s + v[2] * c];
  }
  function rotateX(v, a) {
    const c = Math.cos(a), s = Math.sin(a);
    return [v[0], v[1] * c - v[2] * s, v[1] * s + v[2] * c];
  }
  function project(v, scale) {
    const z = v[2] + 2.5;
    const f = scale / z;
    return [v[0] * f + cx, v[1] * f + cy, v[2]];
  }

  let angle = 0;

  function draw() {
    ctx.clearRect(0, 0, W, H);

    const rx = rotX + mouseY * 0.15 + angle * 0.18;
    const ry = rotY + mouseX * 0.15 + angle * 0.28;

    const transformed = verts.map(v => {
      let r = rotateY(v, ry);
      r = rotateX(r, rx);
      return r;
    });

    const projected = transformed.map(v => project(v, 175));

    const faceData = faces.map(f => {
      const A = transformed[f[0]], B = transformed[f[1]], C = transformed[f[2]];
      const ax = B[0]-A[0], ay = B[1]-A[1], az = B[2]-A[2];
      const bx = C[0]-A[0], by = C[1]-A[1], bz = C[2]-A[2];
      const nx = ay*bz - az*by, ny = az*bx - ax*bz, nz = ax*by - ay*bx;
      const nl = Math.sqrt(nx*nx + ny*ny + nz*nz);
      const dot = nz / nl;
      const avgZ = (transformed[f[0]][2] + transformed[f[1]][2] + transformed[f[2]][2]) / 3;
      return { indices: f, dot, avgZ };
    });

    faceData.sort((a, b) => a.avgZ - b.avgZ);

    faceData.forEach(f => {
      const p = f.indices.map(i => projected[i]);
      ctx.beginPath();
      ctx.moveTo(p[0][0], p[0][1]);
      ctx.lineTo(p[1][0], p[1][1]);
      ctx.lineTo(p[2][0], p[2][1]);
      ctx.closePath();
      if (f.dot > 0) {
        const alpha = Math.min(f.dot * 0.12, 0.1);
        ctx.fillStyle = `rgba(248,113,113,${alpha})`;
        ctx.fill();
      }
      ctx.strokeStyle = `rgba(248,113,113,${0.25 + Math.max(0, f.dot) * 0.5})`;
      ctx.lineWidth = 0.8;
      ctx.stroke();
    });

    verts.forEach((v, i) => {
      const p = projected[i];
      if (p[2] > -0.1) {
        const g = ctx.createRadialGradient(p[0], p[1], 0, p[0], p[1], 5);
        g.addColorStop(0, 'rgba(248,113,113,0.9)');
        g.addColorStop(1, 'transparent');
        ctx.beginPath();
        ctx.arc(p[0], p[1], 5, 0, Math.PI * 2);
        ctx.fillStyle = g;
        ctx.fill();
      }
    });

    angle += 0.008;
    requestAnimationFrame(draw);
  }
  draw();
})();


// ============================================
// SKILL BARS — animate on scroll into view
// ============================================
(function () {
  const cards = document.querySelectorAll('.skill-card');
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const card = entry.target;
        const level = card.dataset.level;
        const bar = card.querySelector('.skill-bar');
        setTimeout(() => { bar.style.width = level + '%'; }, 100);
        observer.unobserve(card);
      }
    });
  }, { threshold: 0.3 });

  cards.forEach(c => observer.observe(c));
})();
