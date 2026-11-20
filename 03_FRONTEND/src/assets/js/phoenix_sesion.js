// 03_FRONTEND/src/assets/js/phoenix_sesion.js - Ventanita "Iniciar sesión en Phoenix" (2026-11-19).
// Manda usuario y contraseña una vez a api/puente_phoenix.php?action=sesion_iniciar, borra la contraseña del campo, avisa a la
// ventana que la abrió (indicador Phoenix de la barra superior, mismo origen) y se cierra sola.
(() => {
  const $ = (id) => document.getElementById(id);
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const estado = (clase, texto) => { $('psEstado').className = `small mt-2 mb-0 ${clase}`; $('psEstado').textContent = texto; };

  $('psForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const usuario = $('psUsuario').value.trim(), clave = $('psClave').value;
    if (!usuario || !clave) { estado('text-danger', 'Escribe tu usuario y tu contraseña de Phoenix.'); return; }
    const datos = new FormData();
    datos.append('usuario', usuario);
    datos.append('clave', clave);
    $('psClave').value = '';
    $('psEntrar').disabled = true;
    estado('text-muted', 'Entrando a Phoenix…');
    let d = {};
    try {
      const r = await fetch('api/puente_phoenix.php?action=sesion_iniciar', { method: 'POST', headers: { 'X-CSRF-Token': token }, body: datos });
      d = await r.json().catch(() => ({}));
      if (!r.ok || d.status !== 'success') throw new Error(d.message || 'No se pudo entrar a Phoenix.');
    } catch (x) {
      estado('text-danger', x.message === 'Failed to fetch' ? 'No se pudo conectar con el SGS. Revisa tu internet.' : x.message);
      $('psEntrar').disabled = false;
      $('psClave').focus();
      return;
    }
    estado('text-success', `Listo: sesión de Phoenix activa hasta las ${d.vence}. Esta ventana se cerrará sola.`);
    try { window.opener?.postMessage({ tipo: 'phoenix-sesion', activa: true }, location.origin); } catch (_) { /* sin ventana de origen */ }
    setTimeout(() => window.close(), 1500);
  });
})();
