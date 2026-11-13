// 03_FRONTEND/src/assets/js/puertos_cto.js - Ventana "Consultar puertos" de Supervisiones (2026-11-11).
// Sin sondeos de fondo: pregunta el estado al abrir la ventana y, tras consultar, espera el resultado cada 1,5 s solo
// mientras la ventana siga abierta (Wincore responde a través del vínculo de la cuenta principal, una consulta a la vez:
// mientras tanto muestra cuántas hay antes que la suya).
(() => {
  const modal = document.getElementById('modalPuertosCto');
  if (!modal) return;
  const $ = id => document.getElementById(id);
  const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const token = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const ESPERA_MS = 1500;
  let turno = 0, activo = false;

  async function api(accion, datos, id = '') {
    let r;
    try {
      r = await fetch(`api/puertos_cto.php?action=${accion}${id ? '&id=' + encodeURIComponent(id) : ''}`, datos
        ? { method: 'POST', headers: { 'X-CSRF-Token': token(), Accept: 'application/json' }, body: datos }
        : { headers: { Accept: 'application/json' } });
    } catch { throw new Error('No se pudo conectar con el servidor.'); }
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.status !== 'success') throw new Error(d.message || 'No se pudo completar la consulta.');
    return d;
  }

  const estado = (tipo, texto) => { $('pcEstado').dataset.estado = tipo; $('pcEstado').textContent = texto; };
  async function verEstado() {
    estado('buscando', 'Revisando si la consulta de puertos está activa…');
    try {
      const d = await api('estado');
      activo = d.activo;
      activo ? estado('ok', `Consulta de puertos activa hasta las ${d.vence}.`)
        : estado('error', 'La consulta de puertos no está activa en este momento.');
    } catch (e) { activo = false; estado('error', e.message); }
    $('pcConsultar').disabled = !activo;
    if ($('pcDesactivar')) $('pcDesactivar').disabled = !activo;
  }

  const CLASE = { DISPONIBLE: 'is-libre', OCUPADO: 'is-ocupado' };
  // Potencia de un puerto ocupado: '0' sin señal (equipo apagado), '' sin dato (Wincore no la dio), si no el valor en dBm.
  // Usa el mismo estilo del texto del servicio (sin estilos nuevos). Los puertos libres no llevan potencia.
  const potencia = p => p.estado !== 'OCUPADO' ? '' : `<small>${p.potencia === '0' ? 'sin señal' : p.potencia ? esc(p.potencia) + ' dBm' : 'sin dato'}</small>`;
  function mostrar(r) {
    const c = r.cto, ps = r.puertos;
    const libres = ps.filter(p => p.estado === 'DISPONIBLE').length, ocupados = ps.filter(p => p.estado === 'OCUPADO').length;
    const lugar = [c.distrito, c.provincia, c.departamento].filter(Boolean).join(', ');
    let mapa = '';
    if (c.lat && c.lon) {
      const q = encodeURIComponent(`${c.lat},${c.lon}`);
      mapa = `<div class="pc-ubicacion">
          <span><i class="bi bi-geo-alt me-1"></i>${esc(c.lat)}, ${esc(c.lon)}</span>
          <button type="button" class="btn btn-outline-secondary" id="pcCopiar" title="Copiar coordenadas" aria-label="Copiar coordenadas"><i class="bi bi-copy"></i></button>
          <a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${q}"><i class="bi bi-map me-1"></i>Mapa</a>
          <a class="btn btn-win" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=${q}"><i class="bi bi-sign-turn-right me-1"></i>Cómo llegar</a>
        </div>`;
    }
    const otros = ps.length - libres - ocupados;
    const suspendidos = ps.filter(p => p.estado === 'OCUPADO' && /SUSPEND/i.test(p.servicio)).length;
    // Debajo del número: el disponible dice "disponible" (no el estado de un cliente anterior, como "bajas"); el ocupado, si está
    // activo o suspendido; otro estado de Wincore, tal cual.
    const frase = t => { const x = String(t || '').toLowerCase(); return x ? x[0].toUpperCase() + x.slice(1) : '—'; }; // ACTIVO → Activo
    const situacion = p => frase(p.estado === 'DISPONIBLE' ? 'disponible' : p.estado === 'OCUPADO' ? (p.servicio || 'ocupado') : p.estado);
    $('pcResultado').innerHTML = `<div class="pc-ficha">
        <div class="pc-ficha-titulo"><strong>${esc(r.codigo)}</strong>${c.estado ? `<span class="badge bg-secondary">${esc(c.estado)}</span>` : ''}${c.objeto ? `<small class="text-muted">${esc(c.objeto)}</small>` : ''}</div>
        ${c.direccion || lugar ? `<span class="text-muted">${esc(c.direccion)}${c.direccion && lugar ? ' – ' : ''}${esc(lugar)}</span>` : ''}
        ${mapa}
      </div>
      <div class="pc-resumen"><span class="badge bg-success">${libres} disponibles</span><span class="badge bg-danger">${ocupados} ocupados</span>${suspendidos ? `<span class="badge bg-secondary">${suspendidos} ${suspendidos === 1 ? 'suspendido' : 'suspendidos'}</span>` : ''}${otros ? `<span class="badge bg-secondary">${otros} otros</span>` : ''}<span>· en los ocupados: si está activo o suspendido y su potencia (dBm)</span></div>
      <div class="pc-puertos">${ps.map(p => `<div class="pc-puerto ${CLASE[p.estado] || 'is-otro'}" title="Puerto ${esc(p.puerto)}: ${esc(situacion(p))}">
          <strong>${esc(p.puerto)}</strong><span><small>${esc(situacion(p))}</small>${potencia(p)}</span></div>`).join('')}</div>`;
    $('pcCopiar')?.addEventListener('click', async () => {
      const txt = `${c.lat},${c.lon}`;
      try { await navigator.clipboard.writeText(txt); window.showToast?.('Coordenadas copiadas.', 'success'); }
      catch { window.prompt('Copia las coordenadas:', txt); }
    });
  }

  async function consultar(ev) {
    ev.preventDefault();
    const codigo = $('pcCodigo').value.trim().toUpperCase();
    if (!codigo) { $('pcAvance').textContent = 'Escribe el código de la CTO.'; return; }
    const mio = ++turno;
    $('pcConsultar').disabled = true; $('pcResultado').innerHTML = '';
    $('pcAvance').textContent = 'Consultando en Wincore…';
    try {
      const datos = new FormData(); datos.append('codigo', codigo);
      const { id } = await api('consultar', datos);
      for (;;) {
        await new Promise(ok => setTimeout(ok, ESPERA_MS));
        if (mio !== turno) return; // se cerró la ventana o hay otra consulta
        const r = await api('resultado', null, id);
        if (r.estado === 'lista') { mostrar(r); $('pcAvance').textContent = ''; break; }
        if (r.estado === 'error') throw new Error(r.message);
        $('pcAvance').textContent = r.estado === 'en_espera' && r.antes > 0
          ? `En espera: ${r.antes === 1 ? 'hay 1 consulta' : `hay ${r.antes} consultas`} antes que la tuya…` : 'Consultando en Wincore…';
      }
    } catch (e) {
      if (mio === turno) { $('pcAvance').textContent = e.message; verEstado(); }
    } finally {
      if (mio === turno) $('pcConsultar').disabled = !activo;
    }
  }

  $('pcForm').addEventListener('submit', consultar);
  modal.addEventListener('show.bs.modal', verEstado);
  // Al cerrar se borra todo (código, resultado y avisos): al volver a abrir queda lista para una consulta nueva.
  modal.addEventListener('hidden.bs.modal', () => { turno++; $('pcCodigo').value = ''; $('pcAvance').textContent = ''; $('pcResultado').innerHTML = ''; });
  $('pcFavorito')?.addEventListener('click', e => { e.preventDefault(); window.showToast?.('Arrástralo a la barra de favoritos de Chrome; aquí no hace nada.', 'error'); });
  $('pcDesactivar')?.addEventListener('click', async () => {
    if (!window.confirm('¿Desactivar el vínculo con Wincore? Nadie podrá consultar puertos hasta que lo vuelvas a activar desde Wincore.')) return;
    try { await api('desactivar', new FormData()); window.showToast?.('Vínculo con Wincore desactivado.', 'success'); }
    catch (e) { window.showToast?.(e.message, 'error'); }
    verEstado();
  });
})();
