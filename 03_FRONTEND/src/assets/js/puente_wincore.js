// 03_FRONTEND/src/assets/js/puente_wincore.js - Ventana del vínculo con Wincore (2026-11-11). La abre el favorito "Vincular
// SGS" desde la pestaña de Wincore (window.opener). Las dos ventanas se hablan con postMessage, cada una solo con el origen
// de la otra:
//   SGS → Wincore: hola, ping, consultar {id, codigo}, fin.   Wincore → SGS: listo {token}, pong, resultado {id, datos}.
// Cada SONDEO segundos (reloj en un Worker) y al terminar cada consulta pide al SGS la siguiente (una a la vez) y la pasa a Wincore. Si Wincore
// deja de responder (se recargó o se cerró), deja de preguntar y el vínculo se apaga solo a los 20 s para los supervisores.
(() => {
  const $ = (id) => document.getElementById(id);
  const WINCORE = document.body.dataset.wincore, SONDEO = Number(document.body.dataset.sondeo || 3) * 1000;
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const wincore = window.opener;
  let vinculado = false, sinRespuesta = 0, atendidas = 0, reloj = null;

  const estado = (tipo, texto) => { $('pvEstado').dataset.estado = tipo; $('pvEstado').textContent = texto; };
  const botones = () => $('pvDesactivar').classList.toggle('d-none', !vinculado);
  const enviar = (datos) => { try { wincore.postMessage(datos, WINCORE); } catch (_) { /* Wincore cerrado */ } };

  async function api(accion, datos) {
    let r;
    try {
      r = await fetch(`api/puertos_cto.php?action=${accion}`, datos
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

  async function vuelta() {
    if (!vinculado) return;
    if (wincore.closed) return apagar('Se cerró Wincore. Ábrelo, inicia sesión y vuelve a pulsar el favorito.');
    if (++sinRespuesta > 3) return apagar('Wincore dejó de responder (¿se recargó la página?). Vuelve a pulsar el favorito en Wincore.');
    enviar({ sgs: 'ping' });
    pedir();
  }

  // Pide al SGS la siguiente consulta: llega una a la vez y en orden de llegada; la siguiente sale cuando Wincore respondió.
  async function pedir() {
    try {
      const r = await api('tomar');
      if (!r.activo) return apagar('El vínculo se desactivó o venció. Pulsa el favorito en Wincore para activarlo otra vez.');
      $('pvVence').textContent = r.vence;
      $('pvEspera').textContent = String(r.en_espera);
      if (r.consulta) enviar({ sgs: 'consultar', id: r.consulta.id, codigo: r.consulta.codigo });
      if ($('pvEstado').dataset.estado !== 'ok') estado('ok', 'Vinculado: ya se pueden consultar puertos.');
    } catch (e) { estado('baja', e.message); }
  }

  window.addEventListener('message', async (e) => {
    if (e.origin !== WINCORE || e.source !== wincore || !e.data?.sgs) return;
    const d = e.data;
    sinRespuesta = 0;
    if (d.sgs === 'listo') {
      if (!d.token) { estado('error', 'No se encontró el token de Wincore: abre la pantalla donde consultas una CTO y vuelve a pulsar el favorito.'); return; }
      try {
        const r = await api('vincular', new FormData());
        vinculado = true;
        $('pvVence').textContent = r.vence;
        estado('ok', 'Vinculado: ya se pueden consultar puertos.');
        if (!reloj) { reloj = new Worker(document.body.dataset.reloj); reloj.onmessage = vuelta; }
        reloj.postMessage(SONDEO);
        botones();
        vuelta();
      } catch (err) { estado('error', err.message); }
    } else if (d.sgs === 'resultado' && vinculado) {
      const f = new FormData(); f.append('id', String(d.id)); f.append('datos', JSON.stringify(d.datos || {}));
      try { await api('responder', f); $('pvAtendidas').textContent = String(++atendidas); } catch (_) { /* la consulta ya venció */ }
      pedir(); // la siguiente de la cola sin esperar la próxima vuelta
    }
  });

  $('pvDesactivar').addEventListener('click', async () => {
    try { await api('desactivar', new FormData()); } catch (_) { /* igual se deja de preguntar */ }
    apagar('Vínculo desactivado. Nadie puede consultar puertos hasta que lo actives de nuevo.');
  });

  if (!wincore) estado('error', 'Abre esta ventana con el favorito "Vincular SGS" desde Wincore (con la VPN y la sesión de Wincore iniciadas).');
  else enviar({ sgs: 'hola' });
  botones();
})();
