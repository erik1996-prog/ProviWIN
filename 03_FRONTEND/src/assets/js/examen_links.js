// 03_FRONTEND/src/assets/js/examen_links.js - Links de examen generados y su SEGUIMIENTO EN VIVO (2026-11-05). Lo usan
// Indicadores → Exámenes (sección "Links de examen y seguimiento") y Técnicos y cuadrillas (ventana del link).
// Cada link: estado, cuántos rindieron, "Seguimiento", "Cerrar link" (permiso Cerrar links) y borrar (permiso Borrar
// exámenes). Los permisos llegan en data-puede-anular / data-puede-borrar del contenedor de la lista.
// Seguimiento: examen en curso (preguntas respondidas y tiempo que queda), terminado (nota) y faltan / no rindieron.
// Se actualiza cada 30 s solo con la ventana abierta y la pestaña visible (sistema liviano).
// Ventana: layouts/examen_seguimiento.php (#mExamenDetalle). API: api/examenes.php (grupos, grupo, grupo_anular, grupo_borrar).
window.SgsExamenLinks = (() => {
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const llamar = async (accion, datos = null) => {
    const opciones = datos ? { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: datos } : {};
    const r = await (await fetch(`api/examenes.php?action=${accion}`, { credentials: 'same-origin', ...opciones })).json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (r.status !== 'success') throw new Error(r.message || 'No se pudo completar la acción.');
    return r;
  };
  const fechaHora = (v) => { if (!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }); };
  const hora = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }) : '';
  const reloj = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  const estados = { activo: ['bg-success', 'Vigente'], completo: ['bg-primary', 'Todos rindieron'], vencido: ['bg-secondary', 'Vencido'], anulado: ['bg-secondary', 'Cerrado'] };
  const listas = new Set(); // contenedores que muestran la lista (se repintan al cerrar o borrar)

  // ---------- Lista de links ----------
  const pintar = (cont, lista) => {
    listas.add(cont);
    const anular = cont.dataset.puedeAnular === '1', borrar = cont.dataset.puedeBorrar === '1';
    cont.innerHTML = !lista.length ? '<p class="text-muted small mb-0"><i class="bi bi-inbox me-1" aria-hidden="true"></i>Todavía no se generó ningún link.</p>'
      : '<ul class="ex-historial">' + lista.map((h) => {
        const [clase, texto] = estados[h.estado] || ['bg-secondary', h.estado];
        return `<li><div class="ex-historial-texto"><strong>${esc(h.titulo)}</strong><small class="text-muted">${esc(h.filtro || '')}${h.filtro ? ' · ' : ''}Generado el ${fechaHora(h.creado_en)}${h.generado_por_nombre ? ' por ' + esc(h.generado_por_nombre) : ''} · se cierra ${fechaHora(h.vence_en)}</small>
            <small><strong>${Number(h.rindieron)}</strong> de ${Number(h.total)} rindieron · ${Number(h.aprobados)} aprobados</small></div>
          <div class="ex-historial-acciones"><span class="badge ${clase}">${texto}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ver-link="${h.id}"><i class="bi bi-broadcast me-1" aria-hidden="true"></i>Seguimiento</button>
            ${h.estado === 'activo' && anular ? `<button type="button" class="btn btn-sm btn-outline-danger" data-anular-link="${h.id}"><i class="bi bi-lock me-1" aria-hidden="true"></i>Cerrar link</button>` : ''}
            ${borrar ? `<button type="button" class="btn btn-sm btn-outline-danger sgs-btn-icono" data-borrar-link="${h.id}" title="Borrar link y resultados" aria-label="Borrar link y resultados"><i class="bi bi-trash"></i></button>` : ''}</div></li>`;
      }).join('') + '</ul>';
    if (!cont.dataset.escucha) { cont.dataset.escucha = '1'; cont.addEventListener('click', accionLista); }
  };
  const repintar = (lista) => listas.forEach((c) => pintar(c, lista));
  const cargar = async (cont) => {
    try { pintar(cont, (await llamar('grupos')).data); }
    catch (e) { cont.innerHTML = `<p class="text-danger small mb-0">${esc(e.message)}</p>`; }
  };

  // ---------- Seguimiento en vivo ----------
  let enVivo = null, temporizador = null;
  const quien = (t) => `<div class="ex-historial-texto"><strong>${esc(t.persona)}</strong><small class="text-muted">${t.tipo === 1 ? 'T1' : 'T2'}${t.partner ? ' · ' + esc(t.partner) : ''} · DNI ${esc(t.dni || '—')}</small></div>`;
  const grupo = (titulo, icono, lista, fila) => `<h6 class="cuad-seccion"><i class="bi ${icono} me-2" aria-hidden="true"></i>${titulo} (${lista.length})</h6>`
    + (lista.length ? '<ul class="ex-historial mb-2">' + lista.map((t) => `<li>${quien(t)}<div class="ex-historial-acciones">${fila(t)}</div></li>`).join('') + '</ul>' : '<p class="text-muted small">Nadie por ahora.</p>');
  const actualizar = async () => {
    clearTimeout(temporizador);
    if (!enVivo) return;
    if (!document.hidden) {
      try {
        const d = (await llamar('grupo&id=' + encodeURIComponent(enVivo))).data;
        if (!enVivo) return;
        const de = (e) => d.tecnicos.filter((t) => e.includes(t.estado));
        const enCurso = de(['en_curso']), terminados = de(['terminado']), faltan = de(['entro', 'falta']);
        const cerrado = d.estado !== 'activo';
        $('exCerrarLink')?.classList.toggle('d-none', cerrado);
        const kpi = (etq, val, clase = '') => `<div class="col-6 col-md-3"><div class="sgs-kpi ${clase} p-2 h-100"><small class="sgs-kpi-label">${etq}</small><h3 class="mb-0 sgs-kpi-value">${val}</h3></div></div>`;
        const [clase, texto] = estados[d.estado] || ['bg-secondary', d.estado];
        $('exDetTitulo').textContent = d.titulo;
        $('exDetalle').innerHTML = `<div class="ex-vivo"><span class="badge ${clase}">${texto}</span>${d.filtro ? `<small class="text-muted">${esc(d.filtro)}</small>` : ''}
            <small class="text-muted ex-vivo-hora"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Se actualiza solo · ${new Date().toLocaleTimeString('es-PE', { hour12: false })}</small></div>
          <div class="row g-2 mb-3">${kpi('En curso', enCurso.length, enCurso.length ? 'sgs-kpi--warning' : '')}${kpi('Terminaron', terminados.length)}${kpi('Aprobados', terminados.filter((t) => t.aprobado).length, 'sgs-kpi--success')}${kpi(cerrado ? 'No rindieron' : 'Faltan', faltan.length, cerrado && faltan.length ? 'sgs-kpi--danger' : '')}</div>`
          + grupo('Examen en curso', 'bi-pencil-square', enCurso, (t) => `<small class="text-muted text-nowrap">${t.respondidas} de ${t.preguntas} respondidas</small><span class="badge bg-warning" title="Tiempo que le queda"><i class="bi bi-stopwatch me-1" aria-hidden="true"></i>${reloj(t.restan || 0)}</span>`)
          + grupo('Examen terminado', 'bi-check2-circle', terminados, (t) => `<small class="text-muted">${hora(t.usado_en)}</small><span class="badge ${t.aprobado ? 'bg-success' : 'bg-danger'}">${t.aprobado ? 'Aprobado' : 'No aprobado'} · ${Math.round(Number(t.nota))} %</span>`)
          + (cerrado
            ? grupo('No rindieron', 'bi-x-circle', faltan, () => '<span class="badge bg-danger">No rindió el examen</span>')
            : grupo('Faltan', 'bi-hourglass', faltan, (t) => t.estado === 'entro' ? `<span class="badge bg-secondary" title="Entró a las ${hora(t.ingreso_en)}">Entró, no empezó</span>` : '<span class="badge bg-secondary">No entró</span>'));
      } catch (e) { $('exDetalle').innerHTML = `<p class="text-danger small mb-0">${esc(e.message)}</p>`; }
    }
    temporizador = setTimeout(actualizar, 30000);
  };
  const seguir = (id) => {
    const modal = $('mExamenDetalle');
    if (!modal.dataset.escucha) {
      modal.dataset.escucha = '1';
      modal.addEventListener('hidden.bs.modal', () => { enVivo = null; clearTimeout(temporizador); $('exCerrarLink')?.classList.add('d-none'); });
      $('exCerrarLink')?.addEventListener('click', (ev) => cerrar(enVivo, ev.currentTarget));
      document.addEventListener('visibilitychange', () => { if (!document.hidden && enVivo) actualizar(); });
    }
    enVivo = String(id);
    $('exDetalle').innerHTML = '<p class="text-muted small mb-0">Cargando…</p>';
    bootstrap.Modal.getOrCreateInstance(modal).show();
    actualizar();
  };

  // ---------- Cerrar y borrar ----------
  const cerrar = async (id, boton) => {
    if (!confirm('¿Cerrar este link? Ningún técnico podrá entrar; quien está rindiendo termina su examen y los demás quedan como "No rindió".')) return;
    boton.disabled = true;
    try {
      const datos = new FormData(); datos.append('id', id);
      const r = await llamar('grupo_anular', datos);
      repintar(r.data); window.showToast?.(r.message, 'success');
      if (enVivo === String(id)) actualizar();
    } catch (e) { window.showToast?.(e.message, 'error'); }
    boton.disabled = false;
  };
  const borrarLink = async (id, boton) => {
    if (!confirm('¿Borrar este link con todos sus resultados? Desaparece también del dashboard de Exámenes. No se puede deshacer.')) return;
    boton.disabled = true;
    try {
      const datos = new FormData(); datos.append('id', id);
      const r = await llamar('grupo_borrar', datos);
      repintar(r.data); window.showToast?.(r.message, 'success');
      document.dispatchEvent(new CustomEvent('sgs:examen-borrado'));
    } catch (e) { window.showToast?.(e.message, 'error'); boton.disabled = false; }
  };
  function accionLista(ev) {
    const ver = ev.target.closest('[data-ver-link]'); if (ver) return seguir(ver.dataset.verLink);
    const b = ev.target.closest('[data-borrar-link]'); if (b) return borrarLink(b.dataset.borrarLink, b);
    const a = ev.target.closest('[data-anular-link]'); if (a) return cerrar(a.dataset.anularLink, a);
  }

  return { pintar, cargar };
})();
