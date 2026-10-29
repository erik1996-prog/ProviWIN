// 03_FRONTEND/src/assets/js/cuadrillas.js - Pantalla Técnicos y cuadrillas (pages/cuadrillas.php). Vencimientos, link general
// de examen, editor de cuadrilla y carga de técnicos líderes por Excel. 2026-10-27: salió de la página (sistema liviano: el
// navegador lo guarda) y el link de examen toma los líderes de todas las páginas del filtro (data-lideres del botón).
document.addEventListener('DOMContentLoaded', () => {
  const $ = (id) => document.getElementById(id);
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const llamar = async (api, accion, datos = null) => {
    const opciones = datos ? { method: 'POST', headers: { 'X-CSRF-Token': token }, body: datos } : {};
    const res = await fetch(`api/${api}.php?action=${accion}`, opciones);
    const r = await res.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (r.status !== 'success') throw new Error(r.message || 'No se pudo completar la acción.');
    return r;
  };
  const enviar = (accion, datos) => llamar('tecnicos', accion, datos);
  const fechaHora = (v) => { if (!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }); };
  const tabla = document.querySelector('.cuad-tabla');

  // ---------- Vencimientos de la cuadrilla ----------
  tabla?.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-vencimientos]'); if (!b) return;
    let cambio = false;
    window.SgsVencimientos?.abrir({ liderId: b.dataset.vencimientos, alActualizar: () => { cambio = true; } });
    document.getElementById('mVencimientos')?.addEventListener('hidden.bs.modal', () => { if (cambio) location.reload(); }, { once: true });
  });

  // ---------- Link general de examen (2026-10-20): T1 y T2 de las cuadrillas filtradas ----------
  const mExamen = bootstrap.Modal.getOrCreateInstance($('mExamen'));
  const mExamenDetalle = bootstrap.Modal.getOrCreateInstance($('mExamenDetalle'));
  const estadosLink = { activo: ['bg-success', 'Vigente'], vencido: ['bg-secondary', 'Vencido'], anulado: ['bg-secondary', 'Anulado'] };
  // Todos los líderes del filtro (no solo los de la página que se ve): los trae el botón
  const lideresFiltrados = () => ($('btnLinkExamen')?.dataset.lideres || '').split(',').filter(Boolean);
  const datosLideres = () => { const d = new FormData(); lideresFiltrados().forEach((id) => d.append('lider_ids[]', id)); return d; };
  const pintarHistorial = (lista) => {
    $('exHistorial').innerHTML = !lista.length ? '<p class="text-muted small mb-0"><i class="bi bi-inbox me-1"></i>Todavía no se generó ningún link.</p>'
      : '<ul class="ex-historial">' + lista.map((h) => {
        const [clase, texto] = estadosLink[h.estado] || ['bg-secondary', h.estado];
        return `<li><div class="ex-historial-texto"><strong>${esc(h.titulo)}</strong><small class="text-muted">${esc(h.filtro || '')}${h.filtro ? ' · ' : ''}Generado el ${fechaHora(h.creado_en)}${h.generado_por_nombre ? ' por ' + esc(h.generado_por_nombre) : ''} · vence ${fechaHora(h.vence_en)}</small>
            <small><strong>${Number(h.rindieron)}</strong> de ${Number(h.total)} rindieron · ${Number(h.aprobados)} aprobados</small></div>
          <div class="ex-historial-acciones"><span class="badge ${clase}">${texto}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ver-link="${h.id}">Ver técnicos</button>
            ${h.estado === 'activo' && $('mExamen').dataset.puedeAnular === '1' ? `<button type="button" class="btn btn-sm btn-outline-danger" data-anular-link="${h.id}">Anular</button>` : ''}</div></li>`;
      }).join('') + '</ul>';
  };
  const listaTecnicos = (titulo, items, clase, conEditar) => !items.length ? '' :
    `<div class="alert ${clase} small"><strong>${esc(titulo)}</strong><ul class="mb-0 mt-1 ex-lista-tecnicos">${items.map((t) =>
      `<li>${esc(t.persona)} <span class="text-muted">· ${t.tipo === 1 ? 'T1' + (t.partner ? ' · ' + esc(t.partner) : '') : 'T2 de ' + esc(t.cuadrilla)}${t.motivo ? ' · ' + esc(t.motivo) : ''}</span>${conEditar && document.querySelector(`[data-editar="${t.lider_id}"]`) ? ` <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-corregir-dni="${t.lider_id}">Registrar DNI</button>` : ''}</li>`).join('')}</ul></div>`;
  let sinDniActual = 0;
  const preparar = async () => {
    $('exCargando').classList.remove('d-none'); $('exCargando').textContent = 'Revisando a los técnicos…'; $('exContenido').classList.add('d-none');
    $('exResultado').classList.add('d-none'); $('exError').classList.add('d-none');
    const gen = $('exGenerar'); gen.className = 'btn btn-win w-100'; gen.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Generar link';
    try {
      const r = await llamar('examenes', 'grupo_preparar', datosLideres());
      const d = r.data;
      const kpi = (etq, val, clase = '') => `<div class="col-6 col-md-3"><div class="sgs-kpi ${clase} p-2 h-100"><small class="sgs-kpi-label">${etq}</small><h3 class="mb-0 sgs-kpi-value">${val}</h3></div></div>`;
      $('exResumen').innerHTML = kpi('Cuadrillas', d.cuadrillas) + kpi('Técnicos líderes (T1)', d.t1) + kpi('Técnicos de apoyo (T2)', d.t2)
        + kpi('Sin DNI', d.sin_dni.length, d.sin_dni.length ? 'sgs-kpi--danger' : 'sgs-kpi--success');
      sinDniActual = d.sin_dni.length;
      $('exSinDni').innerHTML = listaTecnicos(`${d.sin_dni.length === 1 ? 'Este técnico no tiene' : 'Estos técnicos no tienen'} DNI: regístrelo para poder generar el link (cada técnico entra con su DNI).`, d.sin_dni, 'alert-danger', true);
      $('exExcluidos').innerHTML = listaTecnicos('No entran al examen:', d.excluidos, 'alert-secondary', false);
      const sel = $('exExamen'), antes = sel.value;
      sel.innerHTML = (d.examenes || []).map((x) => `<option value="${esc(x.codigo)}" data-ayuda="${esc(`${x.preguntas} preguntas · ${x.minutos} minutos · aprueba con ${x.aprueba} %`)}">${esc(x.titulo)}</option>`).join('');
      if (antes) sel.value = antes;
      const ayuda = () => { $('exExamenAyuda').textContent = sel.selectedOptions[0]?.dataset.ayuda || ''; };
      sel.onchange = ayuda; ayuda();
      $('exGenerar').disabled = d.sin_dni.length > 0;
      $('exGenerar').title = d.sin_dni.length ? 'Primero registre el DNI de todos los técnicos' : '';
      $('exCargando').classList.add('d-none'); $('exContenido').classList.remove('d-none');
    } catch (e) { $('exCargando').textContent = e.message; }
  };
  const cargarHistorial = async () => {
    try { pintarHistorial((await llamar('examenes', 'grupos')).data); }
    catch (e) { $('exHistorial').innerHTML = `<p class="text-danger small mb-0">${esc(e.message)}</p>`; }
  };
  $('btnLinkExamen')?.addEventListener('click', () => {
    $('exFiltro').textContent = $('btnLinkExamen').dataset.filtro || '';
    mExamen.show(); preparar(); cargarHistorial();
  });
  $('exGenerar').addEventListener('click', async () => {
    const btn = $('exGenerar'); btn.disabled = true; $('exError').classList.add('d-none');
    try {
      const datos = datosLideres(); datos.append('horas', $('exHoras').value); datos.append('examen', $('exExamen').value); datos.append('filtro', $('btnLinkExamen').dataset.filtro || '');
      const r = await llamar('examenes', 'grupo_generar', datos);
      const d = r.data;
      $('exPara').textContent = `${d.total} técnicos`;
      $('exLink').value = d.url;
      $('exVence').textContent = 'Vence el ' + d.vence + '. Cada técnico entra con su DNI y rinde una vez.';
      $('exWhatsapp').href = 'https://wa.me/?text=' + encodeURIComponent(d.mensaje);
      $('exCompartir').classList.toggle('d-none', !navigator.share);
      $('exCompartir').onclick = () => navigator.share({ title: 'Link de examen', text: d.mensaje }).catch(() => {});
      $('exCopiar').onclick = async () => {
        try { await navigator.clipboard.writeText(d.mensaje); } catch { $('exLink').select(); document.execCommand('copy'); }
        window.showToast?.('Mensaje con el link copiado.', 'success');
      };
      $('exResultado').classList.remove('d-none');
      btn.className = 'btn btn-outline-secondary w-100'; btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Generar otro link'; // la acción principal pasa a ser enviarlo
      pintarHistorial(d.historial);
    } catch (e) {
      $('exError').textContent = e.message; $('exError').classList.remove('d-none');
    } finally { btn.disabled = sinDniActual > 0; }
  });
  // "Registrar DNI": abre el editor de esa cuadrilla; al guardar se recarga la página y se vuelve a revisar.
  $('exSinDni').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-corregir-dni]'); if (!b) return;
    mExamen.hide();
    document.querySelector(`[data-editar="${b.dataset.corregirDni}"]`)?.click();
  });
  $('exHistorial').addEventListener('click', async (ev) => {
    const ver = ev.target.closest('[data-ver-link]');
    if (ver) {
      $('exDetalle').innerHTML = '<p class="text-muted small mb-0">Cargando…</p>'; mExamenDetalle.show();
      try {
        const d = (await llamar('examenes', 'grupo&id=' + encodeURIComponent(ver.dataset.verLink))).data;
        $('exDetTitulo').textContent = d.titulo;
        const rindieron = d.tecnicos.filter((t) => t.usado_en).length;
        $('exDetalle').innerHTML = `<p class="small mb-2"><strong>${rindieron}</strong> de ${d.tecnicos.length} técnicos ya rindieron.</p><ul class="ex-historial">`
          + d.tecnicos.map((t) => `<li><div class="ex-historial-texto"><strong>${esc(t.persona)}</strong><small class="text-muted">${t.tipo === 1 ? 'T1' : 'T2'}${t.nombre_partner ? ' · ' + esc(t.nombre_partner) : ''} · DNI ${esc(t.dni || '—')}</small></div>
            <div class="ex-historial-acciones">${t.usado_en
              ? `<span class="badge ${Number(t.aprobado) ? 'bg-success' : 'bg-danger'}" title="Rindió el ${fechaHora(t.usado_en)}">${Number(t.aprobado) ? 'Aprobado' : 'No aprobado'} · ${Math.round(Number(t.nota))} %</span>`
              : (t.inicio_en ? '<span class="badge bg-warning">Rindiendo</span>' : (t.ingreso_en ? `<span class="badge bg-secondary" title="${fechaHora(t.ingreso_en)}">Entró</span>` : '<span class="badge bg-secondary">Falta</span>'))}</div></li>`).join('') + '</ul>';
      } catch (e) { $('exDetalle').innerHTML = `<p class="text-danger small mb-0">${esc(e.message)}</p>`; }
      return;
    }
    const b = ev.target.closest('[data-anular-link]'); if (!b) return;
    if (!confirm('¿Anular este link? Ningún técnico podrá entrar con él.')) return;
    b.disabled = true;
    try {
      const datos = new FormData(); datos.append('id', b.dataset.anularLink);
      const r = await llamar('examenes', 'grupo_anular', datos);
      pintarHistorial(r.data); window.showToast?.(r.message, 'success');
    } catch (e) { window.showToast?.(e.message, 'error'); b.disabled = false; }
  });

  if (!$('mCuadrilla')) return; // lo que sigue es para quien edita cuadrillas (administración y supervisores directos)

  // ---------- Editar cuadrilla: T1, T2 (cambiar o registrar uno nuevo), vehículo, zona y estado ----------
  const mCuadrilla = bootstrap.Modal.getOrCreateInstance($('mCuadrilla'));
  const f = $('fCuadrilla');
  const apoyo = $('cuadApoyo');
  const actualizarT2 = (cargarDatos = true) => {
    const v = apoyo.value, nuevo = v === 'nuevo';
    $('cuadT2Datos').classList.toggle('d-none', v === '0');
    $('cuadT2NombreBox').classList.toggle('d-none', !nuevo);
    if (!cargarDatos) return;
    const op = apoyo.selectedOptions[0];
    $('cuadT2Nombre').value = '';
    $('cuadT2Dni').value = nuevo ? '' : (op?.dataset.dni && op.dataset.dni !== '0' ? op.dataset.dni : '');
    $('cuadT2Cel').value = nuevo ? '' : (op?.dataset.celular || '');
  };
  apoyo.addEventListener('change', () => actualizarT2());
  tabla?.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-editar]'); if (!b) return;
    f.id.value = b.dataset.editar;
    $('cuadNombre').textContent = b.dataset.nombre;
    $('cuadPartnerTexto').textContent = 'Partner: ' + b.dataset.partner;
    $('cuadT1Dni').value = b.dataset.dni && b.dataset.dni !== '0' ? b.dataset.dni : '';
    $('cuadT1Cel').value = b.dataset.celular || '';
    apoyo.value = b.dataset.apoyo && b.dataset.apoyo !== '0' ? b.dataset.apoyo : '0';
    if (apoyo.value === '') apoyo.value = '0';
    actualizarT2();
    $('cuadPlaca').value = b.dataset.placa || '';
    $('cuadTipo').value = b.dataset.tipo || '';
    $('cuadEstadoVeh').value = b.dataset.estado || '';
    $('cuadOperativoEdit').value = b.dataset.operativo || 'Habilitado';
    $('cuadProvincia').value = b.dataset.provincia || '';
    $('cuadError').classList.add('d-none');
    mCuadrilla.show();
  });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = $('btnGuardarCuadrilla'); btn.disabled = true;
    try {
      const r = await enviar('cuadrilla_guardar', new FormData(f));
      mCuadrilla.hide(); window.showToast?.(r.message, 'success');
      setTimeout(() => location.reload(), 400);
    } catch (e) {
      $('cuadError').textContent = e.message; $('cuadError').classList.remove('d-none');
    } finally { btn.disabled = false; }
  });

  // ---------- Cargar técnicos líderes (solo administración) ----------
  if (!$('mCargaLideres')) return;
  const mCarga = bootstrap.Modal.getOrCreateInstance($('mCargaLideres'));
  const salida = $('cargaResultado'), btnRevisar = $('btnRevisarLideres'), btnAplicar = $('btnAplicarLideres'), archivo = $('archivoLideres');
  let aplicado = false;
  $('btnCargarLideres').addEventListener('click', () => { archivo.value = ''; salida.innerHTML = ''; btnAplicar.classList.add('d-none'); aplicado = false; mCarga.show(); });
  archivo.addEventListener('change', () => { salida.innerHTML = ''; btnAplicar.classList.add('d-none'); });
  $('mCargaLideres').addEventListener('hidden.bs.modal', () => { if (aplicado) location.reload(); });

  const lista = (titulo, items, fmt, abierta = false) => !items.length ? '' :
    `<details class="mb-2" ${abierta ? 'open' : ''}><summary class="fw-bold">${esc(titulo)} (${items.length})</summary><ul class="small mb-0 mt-1">${items.map((i) => `<li>${fmt(i)}</li>`).join('')}</ul></details>`;
  const pintar = (d, aplicadoAhora) => {
    const kpi = (etq, val, clase = '') => `<div class="col-6 col-md-3"><div class="sgs-kpi ${clase} p-2 h-100"><small class="sgs-kpi-label">${etq}</small><h3 class="mb-0 sgs-kpi-value">${val}</h3></div></div>`;
    salida.innerHTML = (aplicadoAhora ? '<div class="alert alert-success">Cambios aplicados.</div>' : '<div class="alert alert-info small mb-3">Vista previa: todavía no se cambió nada. Revise y pulse <strong>Aplicar cambios</strong>.</div>')
      + (d.aviso ? `<div class="alert alert-warning small">${esc(d.aviso)}</div>` : '')
      + '<div class="row g-2 mb-3">' + kpi('Nuevos', d.nuevos.length) + kpi('Actualizados', d.actualizados.length)
      + kpi('Se inhabilitan', d.desactivar.length, d.desactivar.length ? 'sgs-kpi--warning' : '') + kpi('Sin cambios', d.sin_cambios) + '</div>'
      + (Object.keys(d.por_departamento || {}).length ? `<p class="small mb-2"><strong>Zonas del archivo:</strong> ${Object.entries(d.por_departamento).sort((a, b) => b[1] - a[1]).map(([dep, n]) => `${esc(dep)} (${n})`).join(' · ')}${d.sin_zona ? ` · <span class="text-muted">sin provincia (${d.sin_zona})</span>` : ''}</p>` : (d.sin_zona ? '<div class="alert alert-warning small">El archivo no trae la columna <strong>PROVINCIA</strong>: la zona de los técnicos no cambia.</div>' : ''))
      + lista('Filas con problemas (no se aplican)', d.observadas, (i) => `Fila ${i.fila}: <strong>${esc(i.nombre)}</strong> — ${esc(i.motivo)}`, true)
      + lista('Provincias que no se reconocen (su zona no cambia)', d.zona_no_reconocida || [], (i) => `Fila ${i.fila}: <strong>${esc(i.nombre)}</strong> — "${esc(i.provincia)}"`, true)
      + lista('Nuevos', d.nuevos, (i) => `${esc(i.nombre)} <span class="text-muted">· ${esc(i.partner)}${i.zona ? ' · ' + esc(i.zona) : ''}</span>`)
      + lista('Cambian de zona', d.cambios_zona || [], (i) => `${esc(i.nombre)} <span class="text-muted">· ${esc(i.antes)} →</span> <strong>${esc(i.zona)}</strong>`)
      + lista('Actualizados', d.actualizados, (i) => `${esc(i.antes)} → <strong>${esc(i.nombre)}</strong>${i.partner_antes !== i.partner ? ` <span class="text-muted">· ${esc(i.partner_antes)} → ${esc(i.partner)}</span>` : ''}`)
      + lista('Se reactivan (estaban inhabilitados)', d.reactivados, (i) => esc(i.nombre))
      + lista('Se inhabilitan (no vienen en el archivo)', d.desactivar, (i) => `${esc(i.nombre)} <span class="text-muted">· ${esc(i.partner)}</span>`)
      + lista('En lista negra (no se reactivan)', d.lista_negra, (i) => esc(i.nombre));
  };
  const procesar = async (modo) => {
    if (!archivo.files.length) { salida.innerHTML = '<div class="alert alert-danger">Seleccione el archivo Excel.</div>'; return; }
    const datos = new FormData(); datos.append('archivo', archivo.files[0]); datos.append('modo', modo);
    const boton = modo === 'aplicar' ? btnAplicar : btnRevisar; boton.disabled = true;
    try {
      const r = await enviar('importar_lideres', datos);
      pintar(r.data, r.aplicado);
      const hayCambios = r.data.nuevos.length + r.data.actualizados.length + r.data.reactivados.length + r.data.desactivar.length + (r.data.cambios_zona || []).length > 0;
      btnAplicar.classList.toggle('d-none', r.aplicado || !hayCambios);
      if (r.aplicado) { aplicado = true; window.showToast?.('Técnicos líderes actualizados.', 'success'); }
    } catch (e) {
      salida.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
    } finally { boton.disabled = false; }
  };
  btnRevisar.addEventListener('click', () => procesar('previsualizar'));
  btnAplicar.addEventListener('click', () => {
    if (!confirm('¿Aplicar los cambios de la vista previa? Los técnicos que no vienen en el archivo quedarán inhabilitados.')) return;
    procesar('aplicar');
  });
  // Nuevo técnico (2026-10-29): un técnico líder (T1) o de apoyo (T2) suelto, sin el Excel (api/tecnicos.php?action=crear_tecnico).
  const fNuevo = $('fNuevoTecnico');
  $('btnNuevoTecnico')?.addEventListener('click', () => {
    fNuevo.reset(); $('ntError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance($('mNuevoTecnico')).show();
  });
  fNuevo?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const error = $('ntError');
    const falta = [...fNuevo.querySelectorAll('[required]')].find((c) => !c.value.trim());
    if (falta) { error.textContent = 'Complete: ' + (fNuevo.querySelector(`label[for="${falta.id}"]`)?.textContent || 'los datos') + '.'; error.classList.remove('d-none'); falta.focus(); return; }
    const boton = $('btnGuardarNuevoTecnico'); boton.disabled = true;
    try {
      const r = await (await fetch('api/tecnicos.php?action=crear_tecnico', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: new FormData(fNuevo) })).json();
      if (r.status !== 'success') throw new Error(r.message || 'No se pudo registrar el técnico.');
      window.showToast(r.message, 'success');
      window.location.reload();
    } catch (e) { error.textContent = e.message; error.classList.remove('d-none'); }
    finally { boton.disabled = false; }
  });
});
