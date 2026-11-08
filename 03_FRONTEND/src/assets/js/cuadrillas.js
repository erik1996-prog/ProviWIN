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
  // Para quién es el link: todos los líderes del filtro (no solo los de la página que se ve; los trae el botón) o una sola
  // cuadrilla (botón de la fila, 2026-11-05).
  let lideresLink = [], filtroLink = '';
  const datosLideres = () => { const d = new FormData(); lideresLink.forEach((id) => d.append('lider_ids[]', id)); return d; };
  // Lista de links generados con Seguimiento, Cerrar y Borrar: assets/js/examen_links.js (común con Exámenes).
  const pintarHistorial = (lista) => window.SgsExamenLinks.pintar($('exHistorial'), lista);
  const listaTecnicos = (titulo, items, clase, conEditar) => !items.length ? '' :
    `<div class="alert ${clase} small"><strong>${esc(titulo)}</strong><ul class="mb-0 mt-1 ex-lista-tecnicos">${items.map((t) =>
      `<li>${esc(t.persona)} <span class="text-muted">· ${t.tipo === 1 ? 'T1' + (t.partner ? ' · ' + esc(t.partner) : '') : 'T2 de ' + esc(t.cuadrilla)}${t.motivo ? ' · ' + esc(t.motivo) : ''}</span>${conEditar && (t.tipo === 1 || String(t.motivo || '').startsWith('Sin DNI')) ? corregirDni(t) : ''}</li>`).join('')}</ul></div>`;
  // 2026-11-05: si la cuadrilla no está en esta página del listado, se ofrece ir a ella (antes no salía ninguna opción).
  const corregirDni = (t) => !$('mCuadrilla') ? '' : document.querySelector(`[data-editar="${t.lider_id}"]`)
    ? ` <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-corregir-dni="${t.lider_id}">Registrar DNI</button>`
    : ` <a class="btn btn-link btn-sm p-0 align-baseline" href="index.php?page=cuadrillas&q=${encodeURIComponent(t.cuadrilla)}">Ir a la cuadrilla</a>`;
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
        + kpi('T1 sin DNI', d.sin_dni.length, d.sin_dni.length ? 'sgs-kpi--danger' : 'sgs-kpi--success');
      sinDniActual = d.sin_dni.length;
      $('exSinDni').innerHTML = listaTecnicos(`${d.sin_dni.length === 1 ? 'Este técnico líder (T1) no tiene' : 'Estos técnicos líderes (T1) no tienen'} DNI: regístrelo para poder generar el link (cada técnico entra con su DNI).`, d.sin_dni, 'alert-danger', true);
      $('exExcluidos').innerHTML = listaTecnicos('No entran al examen:', d.excluidos, 'alert-secondary', true);
      // Varias cuadrillas y algunas con el T1 sin DNI: se puede generar para las demás.
      const bloqueadas = [...new Set(d.sin_dni.map((t) => String(t.lider_id)))];
      if (bloqueadas.length && d.cuadrillas > bloqueadas.length) {
        $('exSinDni').insertAdjacentHTML('beforeend', `<button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="exSinBloqueadas"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Generar sin ${bloqueadas.length === 1 ? 'esa cuadrilla' : `esas ${bloqueadas.length} cuadrillas`}</button>`);
        $('exSinBloqueadas').onclick = () => {
          lideresLink = lideresLink.filter((id) => !bloqueadas.includes(String(id)));
          filtroLink += ` · sin ${bloqueadas.length} ${bloqueadas.length === 1 ? 'cuadrilla' : 'cuadrillas'} con T1 sin DNI`;
          $('exFiltro').textContent = filtroLink;
          preparar();
        };
      }
      // Referentes (2026-11-05): uno o varios con casillas; el examen saca sus 10 preguntas de los marcados.
      const marcados = [...document.querySelectorAll('#exReferentes input:checked')].map((c) => c.value);
      $('exReferentes').innerHTML = d.referentes.map((x, i) => `<label class="ex-referente"><input class="form-check-input" type="checkbox" value="${esc(x.codigo)}"
          ${(marcados.length ? marcados.includes(x.codigo) : i === 0) ? 'checked' : ''}><span><strong>${esc(x.nombre)}</strong><small>${esc(x.descripcion)}</small></span></label>`).join('');
      const g = d.reglas;
      const ayuda = () => {
        const n = document.querySelectorAll('#exReferentes input:checked').length;
        $('exExamenAyuda').textContent = n ? `${g.preguntas} preguntas al azar${n > 1 ? ', repartidas entre los referentes' : ''} · al menos ${g.min_foto} con foto si el referente tiene · ${g.minutos} minutos · aprueba con ${g.aprueba} %`
          : 'Marque al menos un referente.';
        $('exGenerar').disabled = d.sin_dni.length > 0 || !n;
      };
      $('exReferentes').onchange = ayuda; ayuda();
      $('exGenerar').title = d.sin_dni.length ? 'Primero registre el DNI de los técnicos líderes (T1)' : '';
      $('exCargando').classList.add('d-none'); $('exContenido').classList.remove('d-none');
    } catch (e) { $('exCargando').textContent = e.message; }
  };
  const cargarHistorial = () => window.SgsExamenLinks.cargar($('exHistorial'));
  let reabrirLink = null; // se guarda solo si se registra el DNI desde la ventana del link
  const abrirLink = (lideres, filtro, titulo) => {
    lideresLink = lideres; filtroLink = filtro;
    $('exTitulo').textContent = titulo; $('exFiltro').textContent = filtro;
    mExamen.show(); preparar(); cargarHistorial();
  };
  $('btnLinkExamen')?.addEventListener('click', () => abrirLink(($('btnLinkExamen').dataset.lideres || '').split(',').filter(Boolean),
    $('btnLinkExamen').dataset.filtro || '', 'Link de examen para las cuadrillas filtradas'));
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-link-examen]'); if (!b) return;
    abrirLink([b.dataset.linkExamen], 'Cuadrilla: ' + b.dataset.nombre, 'Link de examen para una cuadrilla');
  });
  try { // vuelve de "Registrar DNI": abre otra vez la ventana del link
    const r = JSON.parse(sessionStorage.getItem('sgsReabrirLink') || 'null');
    sessionStorage.removeItem('sgsReabrirLink');
    if (r?.lideres?.length) abrirLink(r.lideres, r.filtro, r.titulo);
  } catch { /* sin almacenamiento del navegador: no se reabre */ }
  $('exGenerar').addEventListener('click', async () => {
    const btn = $('exGenerar'); btn.disabled = true; $('exError').classList.add('d-none');
    try {
      const datos = datosLideres(); datos.append('minutos', $('exMinutos').value); document.querySelectorAll('#exReferentes input:checked').forEach((c) => datos.append('referentes[]', c.value)); datos.append('filtro', filtroLink);
      const r = await llamar('examenes', 'grupo_generar', datos);
      const d = r.data;
      if (r.message !== 'Link generado.') window.showToast?.(r.message, 'success'); // avisa si cerró el link anterior
      $('exPara').textContent = `${d.total} técnicos`;
      $('exLink').value = d.url;
      $('exVence').textContent = 'Se cierra el ' + d.vence + '. Cada técnico entra con su DNI y rinde una vez.';
      $('exWhatsapp').href = 'https://wa.me/?text=' + encodeURIComponent(d.mensaje);
      $('exCompartir').classList.toggle('d-none', !navigator.share);
      $('exCompartir').onclick = () => navigator.share({ title: 'Link de examen', text: d.mensaje }).catch(() => {});
      $('exCopiar').onclick = async () => { // solo el link, limpio (el mensaje completo va por WhatsApp u otras apps)
        try { await navigator.clipboard.writeText(d.url); } catch { $('exLink').select(); document.execCommand('copy'); }
        window.showToast?.('Link copiado.', 'success');
      };
      $('exResultado').classList.remove('d-none');
      btn.className = 'btn btn-outline-secondary w-100'; btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Generar otro link'; // la acción principal pasa a ser enviarlo
      pintarHistorial(d.historial);
    } catch (e) {
      $('exError').textContent = e.message; $('exError').classList.remove('d-none');
    } finally { btn.disabled = sinDniActual > 0 || !document.querySelector('#exReferentes input:checked'); }
  });
  // "Registrar DNI": abre el editor de esa cuadrilla; al guardar se recarga la página y se vuelve a revisar.
  $('exSinDni').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-corregir-dni]'); if (!b) return;
    reabrirLink = { lideres: lideresLink, filtro: filtroLink, titulo: $('exTitulo').textContent };
    mExamen.hide();
    document.querySelector(`[data-editar="${b.dataset.corregirDni}"]`)?.click();
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
      if (reabrirLink) try { sessionStorage.setItem('sgsReabrirLink', JSON.stringify(reabrirLink)); } catch { /* no se reabre */ }
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
  // Editar y eliminar un técnico de apoyo (T2) (2026-11-03): api/tecnicos.php?action=editar_t2 (datos, zona y partner, como el
  // T1; si cambia de partner o de zona deja su cuadrilla) / eliminar_t2
  const fEditarT2 = $('fEditarT2');
  document.addEventListener('click', async (ev) => {
    const ed = ev.target.closest('[data-t2-editar]');
    if (ed && fEditarT2) {
      fEditarT2.reset(); $('et2Error').classList.add('d-none');
      $('et2Id').value = ed.dataset.t2Editar; $('et2Nombre').value = ed.dataset.nombre; $('et2Dni').value = ed.dataset.dni;
      $('et2Celular').value = ed.dataset.celular; $('et2Estado').value = ed.dataset.estado; $('et2Observacion').value = ed.dataset.observacion;
      $('et2Partner').value = ed.dataset.partner; $('et2Provincia').value = ed.dataset.provincia;
      $('et2Ayuda').textContent = 'El departamento se busca solo.' + (ed.dataset.cuadrilla ? ` Si cambia de partner o de zona, dejará la cuadrilla de ${ed.dataset.cuadrilla}.` : '');
      bootstrap.Modal.getOrCreateInstance($('mEditarT2')).show();
      return;
    }
    const el = ev.target.closest('[data-t2-eliminar]'); if (!el) return;
    if (!confirm(`¿Eliminar a ${el.dataset.nombre}? Saldrá de su cuadrilla. Si ya está en supervisiones, quedará Inhabilitado para no perder ese historial.`)) return;
    const datos = new FormData(); datos.append('id', el.dataset.t2Eliminar);
    try { const r = await enviar('eliminar_t2', datos); window.showToast(r.message, 'success'); window.location.reload(); }
    catch (e) { window.showToast(e.message, 'error'); }
  });
  fEditarT2?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const error = $('et2Error'), boton = $('btnGuardarT2'); boton.disabled = true;
    try { const r = await enviar('editar_t2', new FormData(fEditarT2)); window.showToast(r.message, 'success'); window.location.reload(); }
    catch (e) { error.textContent = e.message; error.classList.remove('d-none'); }
    finally { boton.disabled = false; }
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
