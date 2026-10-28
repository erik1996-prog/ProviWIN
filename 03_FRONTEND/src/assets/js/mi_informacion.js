// 03_FRONTEND/src/assets/js/mi_informacion.js - Mi información (pages/mi_informacion.php): cambio de contraseña propia y foto de perfil.
// 2026-10-27: salió de la página a este archivo (sistema liviano: el navegador lo guarda).
(() => {
  const f = document.getElementById('formPassword'), msg = document.getElementById('pwMsg'), btn = document.getElementById('btnPassword');
  const show = (text, ok) => { msg.textContent = text; msg.className = 'alert mb-0 ' + (ok ? 'alert-success' : 'alert-danger'); };
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (f.nueva.value !== document.getElementById('pwConfirma').value) return show('Las contraseñas nuevas no coinciden.', false);
    btn.disabled = true;
    try {
      const res = await fetch('api/usuarios.php?action=cambiar_password_propia', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ actual: f.actual.value, nueva: f.nueva.value }) });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') { show(data.message, true); f.reset(); f.nueva.dispatchEvent(new Event('input')); } else show(data.message || 'No se pudo cambiar la contraseña.', false);
    } catch (_) { show('No hay conexión con el servidor.', false); }
    finally { btn.disabled = false; }
  });
})();

// Foto de perfil: el usuario encuadra la imagen; se exporta un cuadrado de 512 px en JPEG (pesa poco y sin datos ocultos como el GPS).
(() => {
  const $ = (id) => document.getElementById(id);
  const btn = $('btnFoto'), inp = $('inpFoto'), quitar = $('btnQuitarFoto'), msg = $('fotoMsg');
  if (!btn || !inp) return;
  const stage = $('cropStage'), canvas = $('cropCanvas'), zoomIn = $('cropZoom'), guardar = $('cropGuardar');
  const OUT = 512;
  const st = { img: null, rot: 0, zoom: 1, ox: 0, oy: 0 };
  const say = (t, ok) => { msg.textContent = t; msg.className = 'small ' + (ok ? 'text-success' : 'text-danger'); };
  const modal = () => bootstrap.Modal.getOrCreateInstance($('mFoto'));

  const setAvatar = (url) => {
    if (!url) return location.reload();
    const img = document.createElement('img');
    img.className = 'sgs-pcard-avatar'; img.id = 'pcAvatar'; img.alt = 'Foto de perfil'; img.src = url;
    $('pcAvatar').replaceWith(img);
    // Menú lateral: misma foto sin recargar
    document.querySelectorAll('.sgs-avatar').forEach((a) => {
      const cur = a.querySelector('img, span'); if (!cur) return;
      const i = document.createElement('img'); i.src = url; i.alt = ''; cur.replaceWith(i);
    });
  };

  // Geometría: la imagen (girada) siempre cubre todo el cuadro; ox/oy = desplazamiento del centro en px del cuadro.
  const size = () => stage.clientWidth;
  const dims = () => (st.rot % 180 ? [st.img.naturalHeight, st.img.naturalWidth] : [st.img.naturalWidth, st.img.naturalHeight]);
  const scaleFor = (S) => { const [w, h] = dims(); return Math.max(S / w, S / h) * st.zoom; };
  const clamp = () => {
    const S = size(), [w, h] = dims(), k = scaleFor(S);
    const mx = Math.max(0, (w * k - S) / 2), my = Math.max(0, (h * k - S) / 2);
    st.ox = Math.min(mx, Math.max(-mx, st.ox)); st.oy = Math.min(my, Math.max(-my, st.oy));
  };
  const paint = (ctx, S, factor) => {
    const k = scaleFor(S) * factor;
    ctx.save(); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, S * factor, S * factor);
    ctx.translate((S / 2 + st.ox) * factor, (S / 2 + st.oy) * factor);
    ctx.rotate(st.rot * Math.PI / 180); ctx.scale(k, k);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(st.img, -st.img.naturalWidth / 2, -st.img.naturalHeight / 2);
    ctx.restore();
  };
  const render = () => {
    if (!st.img) return;
    // Resolución real del lienzo = tamaño en pantalla × densidad de píxeles (ancho Y alto; el lienzo nace en 300×150).
    const S = size(), dpr = Math.max(1, window.devicePixelRatio || 1), px = Math.round(S * dpr);
    if (canvas.width !== px || canvas.height !== px) { canvas.width = px; canvas.height = px; }
    clamp(); paint(canvas.getContext('2d'), S, dpr);
  };
  // El zoom mantiene enfocado lo que está en el centro del cuadro.
  const setZoom = (z) => {
    const nz = Math.min(4, Math.max(1, z)), r = nz / st.zoom;
    st.zoom = nz; st.ox *= r; st.oy *= r; zoomIn.value = nz; render();
  };

  // Arrastrar (mouse o dedo) y zoom con dos dedos
  const ptrs = new Map(); let pinch = null;
  stage.addEventListener('pointerdown', (e) => { stage.setPointerCapture(e.pointerId); ptrs.set(e.pointerId, [e.clientX, e.clientY]); stage.classList.add('is-dragging'); });
  stage.addEventListener('pointermove', (e) => {
    if (!ptrs.has(e.pointerId)) return;
    const prev = ptrs.get(e.pointerId); ptrs.set(e.pointerId, [e.clientX, e.clientY]);
    if (ptrs.size === 2) {
      const [a, b] = [...ptrs.values()], d = Math.hypot(a[0] - b[0], a[1] - b[1]);
      if (pinch) setZoom(st.zoom * d / pinch); pinch = d; return;
    }
    st.ox += e.clientX - prev[0]; st.oy += e.clientY - prev[1]; render();
  });
  const up = (e) => { ptrs.delete(e.pointerId); pinch = null; if (!ptrs.size) stage.classList.remove('is-dragging'); };
  stage.addEventListener('pointerup', up); stage.addEventListener('pointercancel', up);
  stage.addEventListener('wheel', (e) => { e.preventDefault(); setZoom(st.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08)); }, { passive: false });
  zoomIn.addEventListener('input', () => setZoom(+zoomIn.value));
  $('cropMenos').addEventListener('click', () => setZoom(st.zoom - 0.2));
  $('cropMas').addEventListener('click', () => setZoom(st.zoom + 0.2));
  $('cropGirar').addEventListener('click', () => { st.rot = (st.rot + 90) % 360; st.ox = st.oy = 0; render(); });
  $('cropOtra').addEventListener('click', () => inp.click());
  $('mFoto').addEventListener('shown.bs.modal', render);
  window.addEventListener('resize', render);

  btn.addEventListener('click', () => inp.click());
  inp.addEventListener('change', () => {
    const file = inp.files && inp.files[0]; inp.value = '';
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return say('Usa una imagen JPG, PNG o WEBP.', false);
    if (file.size > 15 * 1024 * 1024) return say('La imagen es muy pesada (máximo 15 MB).', false);
    const url = URL.createObjectURL(file), img = new Image();
    img.onload = () => {
      if (st.img && st.img.src.startsWith('blob:')) URL.revokeObjectURL(st.img.src);
      Object.assign(st, { img, rot: 0, zoom: 1, ox: 0, oy: 0 }); zoomIn.value = 1; say('', true);
      if ($('mFoto').classList.contains('show')) render(); else modal().show();
    };
    img.onerror = () => { URL.revokeObjectURL(url); say('No se pudo leer la imagen. Prueba con otra.', false); };
    img.src = url;
  });

  guardar.addEventListener('click', async () => {
    if (!st.img) return;
    guardar.disabled = true; const label = guardar.innerHTML;
    guardar.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Guardando…';
    try {
      const out = document.createElement('canvas'); out.width = out.height = OUT;
      clamp(); paint(out.getContext('2d'), size(), OUT / size());
      const blob = await new Promise((r, j) => out.toBlob((b) => (b ? r(b) : j(new Error('blob'))), 'image/jpeg', 0.9));
      const fd = new FormData(); fd.append('foto', blob, 'foto.jpg');
      const res = await fetch('api/usuarios.php?action=mi_foto', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') { setAvatar(data.avatar_url); quitar.classList.remove('d-none'); say(data.message, true); modal().hide(); }
      else say(data.message || 'No se pudo actualizar la foto.', false);
    } catch (_) { say('No hay conexión con el servidor.', false); }
    finally { guardar.disabled = false; guardar.innerHTML = label; }
  });

  quitar.addEventListener('click', async () => {
    if (!confirm('¿Quitar tu foto de perfil?')) return;
    quitar.disabled = true;
    try {
      const res = await fetch('api/usuarios.php?action=quitar_mi_foto', { method: 'POST' });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') setAvatar(null); else say(data.message || 'No se pudo quitar la foto.', false);
    } catch (_) { say('No hay conexión con el servidor.', false); }
    finally { quitar.disabled = false; }
  });
})();
