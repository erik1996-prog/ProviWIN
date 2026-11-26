// 03_FRONTEND/src/assets/js/inicio_ordenes.js - Inicio de órdenes (2026-11-19): tablero en vivo de la cuenta principal.
// Copia de inicio-ordenes/static/app.js con el diseño de ProviWIN. Pide api/inicio_ordenes.php?action=tablero cada 60 s solo con la
// pestaña a la vista (sistema liviano); la duración de las órdenes en curso avanza cada 15 s sin consultar al servidor.
(() => {
  const $ = (id) => document.getElementById(id);
  const D = window.SgsDashboard;
  const esc = D.esc;
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const API = 'api/inicio_ordenes.php?action=';
  const pct = (n, t) => (t ? Math.round((n / t) * 1000) / 10 : 0);
  const hoyISO = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
  const aviso = (m, tipo) => (window.showToast ? window.showToast(m, tipo) : alert(m));

  let datos = null;
  const f = { estado: '', grupo: '' };      // Primera atención
  const g = { estado: '', grupo: '' };      // Órdenes en curso

  async function api(accion, cuerpo) {
    const op = cuerpo === undefined ? {} : { method: 'POST', headers: { 'X-CSRF-Token': token }, body: cuerpo };
    const r = await fetch(API + accion, op);
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.status !== 'success') throw new Error(j.message || `Error ${r.status}`);
    return j;
  }

  // ── Estado de la lectura de Winbo ─────────────────────────
  function pintarEstado(e) {
    const p = $('ioEstado'), a = $('ioAviso');
    let texto, tipo = '';
    if (!e.sesion) { texto = 'Sin sesión de Phoenix'; tipo = 'bad'; }
    else if (e.error) { texto = 'Error al leer Winbo'; tipo = 'bad'; }
    else if (e.avance) { texto = e.avance + '…'; tipo = 'warn'; }
    else if (e.actualizado) { texto = `Leído ${e.actualizado} · próxima ${e.enHorario ? e.proxima : 'al pulsar Actualizar'}`; tipo = 'ok'; }
    else { texto = e.enHorario ? 'Esperando la primera lectura' : `Fuera del horario de lectura (${e.horario})`; }
    p.textContent = texto; p.dataset.tipo = tipo; p.title = e.error || '';
    let msg = '', clase = 'alert-warning';
    if (!e.sesion) msg = 'No hay sesión de Phoenix: esta pantalla no puede leer las órdenes. Se conecta sola con el programa <b>Vínculo Wincore</b> de la laptop (de 05:55 a 21:00).';
    else if (e.error) { msg = esc(e.error); clase = 'alert-danger'; }
    else if (e.aviso) msg = esc(e.aviso);
    a.className = `alert ${clase}` + (msg ? '' : ' d-none');
    a.innerHTML = msg;
    $('ioActualizar').disabled = !e.sesion;
  }

  // Mientras se está leyendo Winbo (Actualizar o la primera lectura del día) se pregunta cada 5 s en vez de cada 60 s: cada
  // pregunta hace seguir la lectura en el servidor sin pausas, así termina pronto (2026-11-20, pedido del usuario).
  let seguir = null;
  async function cargar() {
    clearTimeout(seguir);
    try {
      datos = await api('tablero');
      pintarEstado(datos.estado);
      pintar();
      if (datos.estado.avance && !document.hidden) seguir = setTimeout(cargar, 5000);
    } catch (x) {
      $('ioPie').textContent = x.message;
    }
  }

  // ── Filtros comunes ───────────────────────────────────────
  function combo(sel, valores) {
    const v = sel.value;
    sel.innerHTML = '<option value="">Todos</option>' + valores.map((p) => `<option>${esc(p)}</option>`).join('');
    sel.value = valores.includes(v) ? v : '';
  }
  const unicos = (lista, k) => [...new Set(lista.map((x) => x[k]))].sort();
  const celdaUbic = (c) => `<td data-label="Ubicación">${esc(c.departamento)}<small class="d-block text-muted">${esc(c.provincia)}${c.zona && c.zona !== 'Sin dato' ? ' · ' + esc(c.zona) : ''}</small></td>`;
  const celdaPartner = (c) => `<td data-label="Partner">${esc(c.proveedor)}<small class="d-block text-muted">${esc(c.grupo)}</small></td>`;
  const vacio = (texto) => `<tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1" aria-hidden="true"></i>${texto}</td></tr>`;

  // ── Primera atención ──────────────────────────────────────
  function claseRegla(c) {
    if (c.resultado === 'Cumple') return ['bg-success', 'Cumple', 'cumple'];
    if (c.resultado === 'Seguimiento') return ['bg-warning', 'Seguimiento', 'seg'];
    if (c.resultado === 'Incumple') return ['bg-danger', 'Incumple', 'inc'];
    const vivo = datos && datos.dia === hoyISO();
    if (vivo && (c.estado === 'En camino' || (c.estado === 'Sin iniciar' && !c.motivo))) return ['bg-dark', 'Pendiente', 'pend'];
    if (!c.motivo) return ['bg-secondary', 'Sin inicio', 'excl'];
    return ['bg-secondary', 'Fuera de regla', 'excl'];
  }

  function pintar() {
    if (!datos) return;
    const todas = datos.cuadrillas || [];
    combo($('ioFProv'), unicos(todas, 'proveedor'));
    combo($('ioFDep'), unicos(todas, 'departamento'));
    const prov = $('ioFProv').value, dep = $('ioFDep').value, q = $('ioFTxt').value.trim().toUpperCase();
    const base = todas.filter((c) => (!prov || c.proveedor === prov) && (!dep || c.departamento === dep)
      && (!f.grupo || c.grupo === f.grupo) && (!q || c.cuadrilla.toUpperCase().includes(q)));
    const t = base.length, n = (e) => base.filter((c) => c.estado === e).length;
    const ini = n('Inició'), cam = n('En camino'), sin = n('Sin iniciar');
    $('ioKTotal').textContent = t;
    $('ioKIni').textContent = ini; $('ioKIniSub').textContent = `${pct(ini, t)}% del total`;
    $('ioKCam').textContent = cam; $('ioKCamSub').textContent = `${pct(cam, t)}% del total`;
    $('ioKSin').textContent = sin; $('ioKSinSub').textContent = `${pct(sin, t)}% del total`;
    $('ioBarra').innerHTML = [[ini, 'ok', 'Iniciaron'], [cam, 'warn', 'En camino'], [sin, 'bad', 'Sin iniciar']]
      .filter((x) => x[0]).map(([k, c, l]) => `<span class="io-barra-tramo io-barra-tramo--${c}" style="width:${pct(k, t)}%" title="${l}: ${k}">${l} ${pct(k, t)}%</span>`).join('');
    const cnt = { cumple: 0, seg: 0, inc: 0, pend: 0, excl: 0 };
    base.forEach((c) => { cnt[claseRegla(c)[2]]++; });
    $('ioRC').textContent = cnt.cumple; $('ioRS').textContent = cnt.seg; $('ioRI').textContent = cnt.inc;
    $('ioRP').textContent = cnt.pend; $('ioRE').textContent = cnt.excl;
    pintarCurva(base);

    const vis = base.filter((c) => !f.estado || c.estado === f.estado);
    $('ioFResumen').textContent = `Mostrando ${vis.length} de ${t} cuadrillas.`;
    const hm = (h) => (h ? h.slice(0, 8) : '—');
    $('ioFilas').innerHTML = vis.map((c) => {
      const [badge, texto, k] = claseRegla(c);
      const ec = c.estado === 'Inició' ? 'i' : c.estado === 'En camino' ? 'c' : 's';
      const ag = c.agenda ? (c.agendaDia && c.agendaDia !== datos.dia ? 'día anterior ' : '') + c.agenda.slice(0, 5)
        : c.primeraAgendada ? c.primeraAgendada.agenda : '—';
      const visita = c.primeraAgendada && c.estado === 'Sin iniciar' ? `<small class="d-block text-muted">visita ${esc(c.primeraAgendada.visita)}</small>` : '';
      return `<tr>
        <td data-label="Cuadrilla">${esc(c.cuadrilla)}</td>${celdaPartner(c)}${celdaUbic(c)}
        <td data-label="Orden 1ra atención">${esc(c.orden || '—')}${visita}</td>
        <td data-label="Agendada" class="io-hora">${esc(ag)}</td><td data-label="En camino" class="io-hora">${esc(hm(c.camino))}</td>
        <td data-label="Inicio" class="io-hora"><b>${esc(hm(c.inicio))}</b></td>
        <td data-label="Estado" class="io-est io-est--${ec}">${esc(c.estado)}${c.atrasada ? '<small class="d-block text-muted">pasó 08:10 sin inicio</small>' : ''}</td>
        <td data-label="Resultado"><span class="badge ${badge}">${texto}</span>${k === 'excl' && c.motivo ? `<small class="d-block text-muted">${esc(c.motivo)}</small>` : ''}</td>
      </tr>`;
    }).join('') || vacio(datos.estado.sesion ? 'Sin cuadrillas para mostrar todavía.' : 'Sin sesión de Phoenix.');
    pintarCurso();
    pintarPie();
  }

  function pintarCurva(base) {
    const horas = base.map((c) => c.inicio).filter(Boolean);
    const ahora = datos.ahora && datos.dia === hoyISO() ? datos.ahora.slice(0, 5) : '99:99';
    const pts = (datos.curva || []).map((p) => ({ hora: p.hora, cantidad: p.hora > ahora ? 0 : horas.filter((h) => h <= p.hora + ':59').length }));
    $('ioCurva').innerHTML = D.columnas(pts, {
      etiqueta: (p) => (p.hora.endsWith(':00') || p.hora.endsWith(':30') ? p.hora : ''),
      ayuda: (p) => `${p.hora}: ${p.cantidad} de ${base.length} (${pct(p.cantidad, base.length)}%)`,
      vacio: 'Todavía no inició ninguna primera atención.',
    });
  }

  // ── Órdenes en curso (En camino / Iniciadas) ──────────────
  const minDesde = (iso) => Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
  const durTxt = (m) => (m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min` : `${m} min`);

  function alerta(o) {
    const m = minDesde(o.desde);
    if (o.estado === 'Iniciada') {
      if (m >= 180) return ['rojo', 'bg-danger', 'Rojo · 3 h o más'];
      if (m >= 120) return ['amarillo', 'bg-warning', 'Amarillo · 2 h a 3 h'];
      return ['', '', ''];
    }
    if (o.primera) {               // 1ra atención: debía iniciar a las 08:05
      const h = new Date().toTimeString().slice(0, 8);
      return h > '08:10:59' ? ['rojo', 'bg-danger', '1ra atención sin iniciar (08:05)'] : ['', '', ''];
    }
    return m > 40 ? ['rojo', 'bg-danger', 'En camino más de 40 min'] : ['', '', ''];
  }

  function pintarCurso() {
    if (!datos) return;
    const todas = datos.enCurso || [];
    combo($('ioGProv'), unicos(todas, 'proveedor'));
    combo($('ioGDep'), unicos(todas, 'departamento'));
    const prov = $('ioGProv').value, dep = $('ioGDep').value, q = $('ioGTxt').value.trim().toUpperCase();
    const base = todas.filter((o) => (!prov || o.proveedor === prov) && (!dep || o.departamento === dep)
      && (!g.grupo || o.grupo === g.grupo) && (!q || `${o.cuadrilla} ${o.orden}`.toUpperCase().includes(q)))
      .map((o) => ({ ...o, m: minDesde(o.desde), al: alerta(o) }));
    const cam = base.filter((o) => o.estado === 'En camino'), ini = base.filter((o) => o.estado === 'Iniciada');
    $('ioCCam').textContent = cam.length; $('ioCCamSub').textContent = `${cam.filter((o) => o.al[0]).length} con alerta (más de 40 min)`;
    $('ioCIni').textContent = ini.length;
    $('ioCAma').textContent = ini.filter((o) => o.al[0] === 'amarillo').length;
    $('ioCRoj').textContent = ini.filter((o) => o.al[0] === 'rojo').length;
    $('ioNAlertas').textContent = todas.filter((o) => alerta(o)[0]).length;
    const vis = base.filter((o) => !g.estado || (g.estado === 'alerta' ? o.al[0] : o.estado === g.estado))
      .sort((a, b) => (b.al[0] === 'rojo') - (a.al[0] === 'rojo') || (b.al[0] === 'amarillo') - (a.al[0] === 'amarillo') || b.m - a.m);
    $('ioGResumen').textContent = `Mostrando ${vis.length} de ${base.length} órdenes en curso · duración contada en vivo`;
    $('ioGFilas').innerHTML = vis.map((o) => `<tr class="${o.al[0] ? 'io-fila--' + o.al[0] : ''}">
        <td data-label="Cuadrilla">${esc(o.cuadrilla)}${o.primera ? '<small class="d-block text-muted">1ra atención del día</small>' : ''}</td>
        ${celdaPartner(o)}${celdaUbic(o)}
        <td data-label="Orden">${esc(o.orden)}<small class="d-block text-muted">visita ${esc(o.visita || '—')}</small></td>
        <td data-label="Tipo trabajo">${esc(o.tipoTrabajo)}</td>
        <td data-label="Estado" class="io-est io-est--${o.estado === 'Iniciada' ? 'i' : 'c'}">${esc(o.estado)}${o.revision ? '<small class="d-block text-muted">en revisión</small>' : ''}${o.camino ? `<small class="d-block text-muted">En camino ${esc(o.camino.slice(0, 5))}</small>` : ''}</td>
        <td data-label="Desde" class="io-hora">${esc(o.desde.slice(11, 16))}</td><td data-label="Duración" class="io-hora"><b>${durTxt(o.m)}</b></td>
        <td data-label="Alerta">${o.al[1] ? `<span class="badge ${o.al[1]}">${esc(o.al[2])}</span>` : '<span class="text-muted">Normal</span>'}</td>
      </tr>`).join('') || vacio('No hay órdenes En camino ni Iniciadas en este momento.');
  }

  function pintarPie() {
    const e = datos.estado, u = e.ultimoEnvio;
    const envio = u && u.enviado ? ` · último corte ${u.hora.slice(11)}, enviado ${u.enviado} (${(u.resultados || []).filter((r) => r.ok).length} de ${(u.resultados || []).length} mensajes)` : '';
    $('ioPie').textContent = `${datos.ordenes} órdenes de hoy de GESTIÓN PROVINCIA, en vivo (no se guarda nada) · lectura de Winbo de ${e.horario}`
      + ` · reporte de alertas a Telegram por partner en los cortes de ${e.telegramCortes.join(', ')}${envio} · la pantalla se refresca cada minuto.`;
  }

  // ── Botones ───────────────────────────────────────────────
  $('ioActualizar').addEventListener('click', async () => {
    const b = $('ioActualizar');
    b.disabled = true;
    try { await api('actualizar', new FormData()); $('ioEstado').textContent = 'Leyendo Winbo…'; $('ioEstado').dataset.tipo = 'warn'; setTimeout(cargar, 3000); }
    catch (x) { aviso(x.message, 'error'); }
    finally { setTimeout(() => { b.disabled = false; }, 8000); }
  });

  // "Enviar reporte": al abrir el menú se leen los grupos; cada opción envía ese reporte ahora a todos los grupos.
  let grupos = [];
  $('ioEnviar').addEventListener('show.bs.dropdown', async () => {
    const m = $('ioMenuReporte');
    try {
      const r = await api('grupos');
      grupos = r.grupos;
      if (!r.configurado) { m.innerHTML = '<span class="dropdown-item-text text-muted small">Falta el token del bot (TELEGRAM_TOKEN en el .env) o los grupos.</span>'; return; }
      m.innerHTML = '<h6 class="dropdown-header">Enviar a todos los grupos</h6>' + Object.entries(r.reportes).map(([k, t]) =>
        `<button type="button" class="dropdown-item" data-r="${esc(k)}" data-t="${esc(t)}"><i class="bi ${({ primera: 'bi-truck', alertas: 'bi-alarm' })[k] || 'bi-cone-striped'} me-2" aria-hidden="true"></i>${esc(t)}</button>`).join('');
    } catch (x) { m.innerHTML = `<span class="dropdown-item-text text-danger small">${esc(x.message)}</span>`; }
  });
  $('ioMenuReporte').addEventListener('click', async (ev) => {
    const op = ev.target.closest('[data-r]');
    if (!op) return;
    const lista = grupos.map((x) => `• ${x.nombre} (${x.partners.includes('*') ? 'todos los partners' : x.partners.join(', ')}${x.tipo ? ' · solo ' + x.tipo : ''})`).join('\n');
    if (!confirm(`¿Enviar ahora "${op.dataset.t}" a estos grupos? Va un mensaje por partner.\n\n${lista}`)) return;
    const b = $('ioEnviar');
    b.disabled = true;
    try {
      const fd = new FormData(); fd.append('reporte', op.dataset.r);
      const r = await api('enviar', fd);
      const ok = r.resultados.filter((x) => x.ok), mal = r.resultados.filter((x) => !x.ok);
      if (!r.resultados.length) { aviso(`"${op.dataset.t}": no hay nadie en el reporte, no se envió nada.`, 'success'); return; }   // 2026-11-25
      aviso(`"${op.dataset.t}": ${ok.length} de ${r.resultados.length} mensaje(s) enviados.` + (mal.length ? ' No se pudo: ' + mal.map((x) => `${x.partner} (${x.error})`).join('; ') : ''), mal.length ? 'error' : 'success');
    } catch (x) { aviso(x.message, 'error'); }
    finally { b.disabled = false; }
  });

  // Vistas, segmentos y filtros.
  const segmento = (id, fijar) => $(id).addEventListener('click', (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    $(id).querySelectorAll('button').forEach((x) => x.classList.toggle('active', x === b));
    fijar(b.dataset.v);
  });
  segmento('ioVistas', (v) => { $('ioVistaPrimera').hidden = v === 'curso'; $('ioVistaCurso').hidden = v !== 'curso'; });
  segmento('ioFEstado', (v) => { f.estado = v; pintar(); });
  segmento('ioFGrupo', (v) => { f.grupo = v; pintar(); });
  segmento('ioGEstado', (v) => { g.estado = v; pintarCurso(); });
  segmento('ioGGrupo', (v) => { g.grupo = v; pintarCurso(); });
  ['ioFProv', 'ioFDep'].forEach((id) => $(id).addEventListener('change', pintar));
  $('ioFTxt').addEventListener('input', pintar);
  ['ioGProv', 'ioGDep'].forEach((id) => $(id).addEventListener('change', pintarCurso));
  $('ioGTxt').addEventListener('input', pintarCurso);
  document.querySelectorAll('[data-limpiar]').forEach((b) => b.addEventListener('click', () => {
    const p = b.dataset.limpiar;
    [`${p}Prov`, `${p}Dep`, `${p}Txt`].forEach((id) => { $(id).value = ''; });
    p === 'ioF' ? pintar() : pintarCurso();
  }));
  D.ayuda($('ioCurva'), $('ioTip'));

  // Sistema liviano: consulta cada 60 s solo con la pestaña a la vista; al volver a ella, al momento.
  cargar();
  setInterval(() => { if (!document.hidden) cargar(); }, 60000);
  setInterval(() => { if (!document.hidden) pintarCurso(); }, 15000);   // la duración avanza en vivo (sin consultar)
  document.addEventListener('visibilitychange', () => { if (!document.hidden) cargar(); });
})();
