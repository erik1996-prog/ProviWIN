// 03_FRONTEND/src/assets/js/phoenix_estado.js - Indicador "Phoenix" de la barra superior (2026-11-19, solo la cuenta principal).
// Un solo lugar para la sesión de Phoenix que usan Órdenes cercanas e Inicio de órdenes:
//   · Sin conexión: al pulsarlo se abre la ventanita phoenix_sesion.php (usuario y contraseña; la contraseña no se guarda).
//     Al entrar al sistema avisa una vez por pestaña que está sin conexión.
//   · Conectado: al pulsarlo pregunta si cerrar la sesión.
// Sistema liviano: el estado llega con la página (topbar.php); solo se vuelve a consultar al volver a la pestaña, al cerrar la
// ventanita o cuando otra pantalla lo pide (window.sgsPhoenixRevisar).
(() => {
  const boton = document.getElementById('sgsPhx');
  if (!boton) return;
  const texto = document.getElementById('sgsPhxTexto');
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const aviso = (m, tipo) => (window.showToast ? window.showToast(m, tipo) : alert(m));

  const pedir = async (accion, post) => {
    const r = await fetch(`api/puente_phoenix.php?action=${accion}`, post
      ? { method: 'POST', headers: { 'X-CSRF-Token': token }, body: new FormData() } : { headers: { Accept: 'application/json' } });
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.status !== 'success') throw new Error(d.message || 'No se pudo consultar la sesión de Phoenix.');
    return d;
  };
  const pintar = (d) => {
    const ok = d.activa || d.vinculo;
    boton.dataset.estado = ok ? 'ok' : 'sin';
    texto.textContent = d.activa ? `: conectado hasta las ${d.vence}` : d.vinculo ? ': conectado por el favorito' : ': sin conexión';
    boton.setAttribute('aria-label', `Phoenix${texto.textContent}`);
    document.dispatchEvent(new CustomEvent('sgs:phoenix', { detail: { conectado: !!ok } }));
  };
  const revisar = async () => { try { pintar(await pedir('sesion_estado')); } catch (_) { /* sin red: queda lo último que se vio */ } };
  window.sgsPhoenixRevisar = revisar;

  boton.addEventListener('click', async () => {
    if (boton.dataset.estado !== 'ok') {
      const w = 440, h = 560, x = Math.max(0, (screen.width - w) / 2), y = Math.max(0, (screen.height - h) / 2);
      if (!window.open('phoenix_sesion.php', 'sgsPhoenixSesion', `width=${w},height=${h},left=${x},top=${y}`))
        aviso('El navegador bloqueó la ventanita. Permite las ventanas emergentes de este sitio.', 'error');
      return;
    }
    if (texto.textContent.includes('favorito')) { aviso('Conectado por el favorito de tu laptop: se apaga con Desactivar en la ventana del vínculo.', 'success'); return; }
    if (!confirm('¿Cerrar la sesión de Phoenix? Órdenes cercanas e Inicio de órdenes dejarán de leer órdenes hasta que vuelvas a conectarla.')) return;
    try { await pedir('sesion_cerrar', true); revisar(); } catch (x) { aviso(x.message, 'error'); }
  });
  window.addEventListener('message', (ev) => { if (ev.origin === location.origin && ev.data?.tipo === 'phoenix-sesion') revisar(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) revisar(); });

  // Al entrar: si está sin conexión, avisarlo una vez por pestaña.
  try {
    if (boton.dataset.estado !== 'ok' && !sessionStorage.getItem('sgs-phx-avisado')) {
      sessionStorage.setItem('sgs-phx-avisado', '1');
      setTimeout(() => aviso('Phoenix sin conexión: pulsa "Phoenix" arriba para conectarlo (lo usan Órdenes cercanas e Inicio de órdenes).', 'warning'), 800);
    }
  } catch (_) { /* sin almacenamiento del navegador: no se avisa */ }
})();
