// 03_FRONTEND/src/assets/js/puente_phoenix.js - Ventana del vínculo con Phoenix (2026-11-13). La abre el favorito "Vincular SGS –
// Phoenix" desde la pestaña de Phoenix (window.opener). Las dos ventanas se hablan con postMessage, cada una solo con el origen
// de la otra:
//   SGS → Phoenix: hola, ping, pedido {id, pasos}, fin.   Phoenix → SGS: listo, pong, respuesta {id, datos}.
// Cada SONDEO segundos (reloj en un Worker) pide al SGS el siguiente pedido de Órdenes cercanas (uno a la vez) y lo pasa a Phoenix.
// Si Phoenix deja de responder (se recargó o se cerró), deja de preguntar y el vínculo se apaga solo a los 20 s.
(() => {
  const $ = (id) => document.getElementById(id);
  const PHOENIX = document.body.dataset.phoenix, SONDEO = Number(document.body.dataset.sondeo || 3) * 1000;
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const phoenix = window.opener;
  let vinculado = false, sinRespuesta = 0, atendidas = 0, reloj = null;

  const estado = (tipo, texto) => { $('pvEstado').dataset.estado = tipo; $('pvEstado').textContent = texto; };
  const botones = () => $('pvDesactivar').classList.toggle('d-none', !vinculado);
  const enviar = (datos) => { try { phoenix.postMessage(datos, PHOENIX); } catch (_) { /* Phoenix cerrado */ } };

  async function api(accion, datos) {
    let r;
    try {
      r = await fetch(`api/puente_phoenix.php?action=${accion}`, datos
        ? { method: 'POST', headers: { 'X-CSRF-Token': token }, body: datos } : { headers: { Accept: 'application/json' } });
    } catch (_) { throw new Error('No se pudo conectar con el SGS. Revisa tu internet.'); }
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.status !== 'success') throw new Error(d.message || 'No se pudo hablar con el SGS.');
    return d;
  }

  function apagar(texto) {
    vinculado = false;
    reloj?.postMessage(0);
    enviar({ sgs: 'fin' });
    estado('error', texto);
    $('pvVence').textContent = '—';
    botones();
  }

  function vuelta() {
    if (!vinculado) return;
    if (phoenix.closed) return apagar('Se cerró Phoenix. Ábrelo, inicia sesión y vuelve a pulsar el favorito.');
    if (++sinRespuesta > 3) return apagar('Phoenix dejó de responder (¿se recargó la página?). Vuelve a pulsar el favorito en Phoenix.');
    enviar({ sgs: 'ping' });
    pedir();
  }

  // Pide al SGS el siguiente pedido: uno a la vez (el SGS no entrega otro mientras Phoenix atiende uno).
  async function pedir() {
    try {
      const r = await api('tomar');
      if (!r.activo) return apagar('El vínculo se desactivó o venció. Pulsa el favorito en Phoenix para activarlo otra vez.');
      $('pvVence').textContent = r.vence;
      if (r.pedido) enviar({ sgs: 'pedido', id: r.pedido.id, pasos: r.pedido.pasos });
      if ($('pvEstado').dataset.estado !== 'ok') estado('ok', 'Vinculado: Órdenes cercanas ya lee las órdenes de Phoenix.');
    } catch (e) { estado('baja', e.message); }
  }

  window.addEventListener('message', async (e) => {
    if (e.origin !== PHOENIX || e.source !== phoenix || !e.data?.sgs) return;
    const d = e.data;
    sinRespuesta = 0;
    if (d.sgs === 'listo') {
      try {
        const r = await api('vincular', new FormData());
        vinculado = true;
        $('pvVence').textContent = r.vence;
        estado('ok', 'Vinculado: Órdenes cercanas ya lee las órdenes de Phoenix.');
        if (!reloj) { reloj = new Worker(document.body.dataset.reloj); reloj.onmessage = vuelta; }
        reloj.postMessage(SONDEO);
        botones();
        vuelta();
      } catch (err) { estado('error', err.message); }
    } else if (d.sgs === 'respuesta' && vinculado) {
      const f = new FormData(); f.append('id', String(d.id)); f.append('datos', JSON.stringify(d.datos || {}));
      try { await api('responder', f); $('pvAtendidas').textContent = String(++atendidas); } catch (_) { /* el pedido ya venció */ }
      pedir(); // el siguiente sin esperar la próxima vuelta
    }
  });

  $('pvDesactivar').addEventListener('click', async () => {
    try { await api('desactivar', new FormData()); } catch (_) { /* igual se deja de preguntar */ }
    apagar('Vínculo desactivado. Órdenes cercanas no funciona hasta que lo actives de nuevo.');
  });

  if (!phoenix) estado('error', 'Abre esta ventana con el favorito "Vincular SGS – Phoenix" desde Phoenix (con tu sesión de Phoenix iniciada).');
  else enviar({ sgs: 'hola' });
  botones();
})();
