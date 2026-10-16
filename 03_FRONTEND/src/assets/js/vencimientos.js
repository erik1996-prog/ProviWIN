// 03_FRONTEND/src/assets/js/vencimientos.js - Ventana "Vencimientos de la cuadrilla" (2026-10-15).
// La usan Cuadrillas (fichas de documentos en la columna Vehículo) y el checklist de la supervisión (api/vencimientos.php).
//   SgsVencimientos.abrir({ liderId, placa, supervisionId, alActualizar(items) })  → muestra los 4 documentos y permite
//      actualizar fechas con foto obligatoria; alActualizar recibe los ítems recalculados.
//   SgsVencimientos.consultar(liderId, placa) → Promise con { lider, vehiculo, items } (para llenar el checklist).
(() => {
    const ETIQUETA = { bueno: ['bg-success', 'Vigente'], regular: ['bg-warning', 'Por vencer'], malo: ['bg-danger', 'Vencido'], sin_registrar: ['bg-secondary', 'Sin registrar'] };
    const AYUDA_INICIO = { 'Extintor': 'Última recarga', 'Brevete': 'Fecha de emisión', 'SOAT': 'Inicio de vigencia', 'Revisión técnica': 'Fecha de inspección' };
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fecha = (v) => { if (!v) return '—'; const [a, m, d] = String(v).slice(0, 10).split('-'); return `${d}/${m}/${a}`; };
    const fechaHora = (v) => v ? fecha(v) + ' ' + String(v).slice(11, 16) : '—';
    const insignia = (estado) => { const [c, t] = ETIQUETA[estado] || ETIQUETA.sin_registrar; return `<span class="badge ${c}">${t}</span>`; };

    async function llamar(accion, datos = null) {
        const res = await fetch('api/vencimientos.php?action=' + accion, datos ? { method: 'POST', headers: { 'X-CSRF-Token': token() }, body: datos } : {});
        const r = await res.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
        if (r.status !== 'success') throw new Error(r.message || 'No se pudo completar la acción.');
        return r;
    }
    const consultar = (liderId, placa = '') => llamar(`cuadrilla&lider_id=${encodeURIComponent(liderId)}&placa=${encodeURIComponent(placa || '')}`).then((r) => r.data);

    let modal = null, ctx = null;
    function crearModal() {
        if (modal) return modal;
        const el = document.createElement('div');
        el.className = 'modal fade';
        el.id = 'mVencimientos';
        el.tabIndex = -1;
        el.setAttribute('aria-labelledby', 'mVencimientosTitulo');
        el.innerHTML = `<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="mVencimientosTitulo"><i class="bi bi-calendar-check me-2"></i>Vencimientos de la cuadrilla</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body"><p class="venc-quien mb-3" id="vencQuien"></p><div id="vencItems" class="venc-items"></div></div>
            <div class="modal-footer"><small class="text-muted me-auto">Al actualizar una fecha se pide la foto del documento.</small>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
          </div></div>`;
        document.body.appendChild(el);
        el.addEventListener('click', alHacerClic);
        el.addEventListener('submit', alGuardar);
        modal = bootstrap.Modal.getOrCreateInstance(el);
        return modal;
    }

    function pintar(data) {
        const v = data.vehiculo || {};
        document.getElementById('vencQuien').innerHTML = `<strong>${esc(data.lider.nombre)}</strong><br><small class="text-muted">${esc(data.lider.partner || '—')}`
            + ` · Vehículo: ${v.placa ? esc(v.placa) + (v.nueva ? ' (todavía no registrado)' : '') : 'sin vehículo'}</small>`;
        document.getElementById('vencItems').innerHTML = data.items.map((it) => {
            const sinVehiculo = it.sujeto === 'vehiculo' && !it.sujeto_id;
            const hist = (it.historial || []).map((h) => `<li><span>${fecha(h.fecha_inicio)} → <strong>${fecha(h.fecha_vencimiento)}</strong></span>
                <small class="text-muted">${fechaHora(h.registrado_en)} · ${esc(h.registrado_por_nombre || '—')} · ${esc(h.origen)}${h.cuadrilla ? ' · ' + esc(h.cuadrilla) : ''}${h.departamento ? ' (' + esc(h.departamento) + ')' : ''}</small>
                <a href="${esc(h.foto)}" target="_blank" rel="noopener" class="small">Ver foto</a></li>`).join('');
            return `<section class="venc-item venc-${it.estado}" data-tipo="${esc(it.tipo)}">
              <div class="venc-cabecera">
                <span class="venc-icono" aria-hidden="true"><i class="bi ${esc(it.icono)}"></i></span>
                <div class="venc-titulo"><strong>${esc(it.tipo)}</strong><small class="text-muted">${it.sujeto === 'vehiculo' ? 'Vehículo' : 'Técnico líder'}</small></div>
                ${insignia(it.estado)}
              </div>
              <div class="venc-datos">
                <span><small class="text-muted">${esc(AYUDA_INICIO[it.tipo])}</small>${fecha(it.fecha_inicio)}</span>
                <span><small class="text-muted">Vence</small>${fecha(it.fecha_vencimiento)}</span>
                <span><small class="text-muted">Situación</small>${esc(it.texto)}</span>
                ${it.foto ? `<a href="${esc(it.foto)}" target="_blank" rel="noopener" class="small align-self-end"><i class="bi bi-image me-1"></i>Ver foto</a>` : ''}
              </div>
              ${sinVehiculo ? '<p class="small text-muted mb-0">La cuadrilla no tiene vehículo: registre primero la placa.</p>' : `
              <button type="button" class="btn btn-sm btn-outline-secondary" data-venc-editar><i class="bi bi-pencil me-1"></i>${it.fecha_vencimiento ? 'Actualizar fechas' : 'Registrar fechas'}</button>
              <form class="venc-form d-none" novalidate>
                <input type="hidden" name="tipo" value="${esc(it.tipo)}">
                <div class="row g-2">
                  <div class="col-sm-4"><label class="form-label">${esc(AYUDA_INICIO[it.tipo])}</label><input type="date" class="form-control" name="fecha_inicio" required max="${new Date().toISOString().slice(0, 10)}"></div>
                  <div class="col-sm-4"><label class="form-label">Vence</label><input type="date" class="form-control" name="fecha_vencimiento" required></div>
                  <div class="col-sm-4"><label class="form-label">Foto del documento</label><input type="file" class="form-control" name="foto" accept="image/*,application/pdf" required></div>
                </div>
                <div class="alert alert-danger small d-none mt-2 mb-0" role="alert"></div>
                <div class="d-flex gap-2 justify-content-end mt-2">
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-venc-cancelar>Cancelar</button>
                  <button type="submit" class="btn btn-sm btn-win"><i class="bi bi-check2 me-1"></i>Guardar ${esc(it.tipo)}</button>
                </div>
              </form>`}
              ${hist ? `<details class="venc-historial"><summary>Historial (${it.historial.length})</summary><ul>${hist}</ul></details>` : ''}
            </section>`;
        }).join('');
    }

    function alHacerClic(ev) {
        const editar = ev.target.closest('[data-venc-editar]');
        const cancelar = ev.target.closest('[data-venc-cancelar]');
        const item = ev.target.closest('.venc-item');
        if (editar && item) { item.querySelector('.venc-form').classList.remove('d-none'); editar.classList.add('d-none'); item.querySelector('input[name="fecha_inicio"]').focus(); }
        if (cancelar && item) { item.querySelector('.venc-form').classList.add('d-none'); item.querySelector('[data-venc-editar]').classList.remove('d-none'); }
    }

    async function alGuardar(ev) {
        const form = ev.target.closest('.venc-form');
        if (!form) return;
        ev.preventDefault();
        const error = form.querySelector('.alert');
        const boton = form.querySelector('button[type="submit"]');
        error.classList.add('d-none');
        if (!form.fecha_inicio.value || !form.fecha_vencimiento.value) { error.textContent = 'Registre las dos fechas.'; error.classList.remove('d-none'); return; }
        if (!form.foto.files.length) { error.textContent = 'Adjunte la foto del documento.'; error.classList.remove('d-none'); return; }
        if (form.foto.dataset.comprimiendo) { error.textContent = 'Espere: la foto se está preparando.'; error.classList.remove('d-none'); return; }
        boton.disabled = true;
        try {
            const datos = new FormData(form);
            datos.append('lider_id', ctx.liderId);
            if (ctx.placa) datos.append('placa', ctx.placa);
            if (ctx.supervisionId) datos.append('supervision_id', ctx.supervisionId);
            const r = await llamar('registrar', datos);
            ctx.data.items = r.data.items;
            pintar(ctx.data);
            window.showToast?.(r.message, 'success');
            ctx.alActualizar?.(r.data.items);
        } catch (e) {
            error.textContent = e.message; error.classList.remove('d-none');
        } finally { boton.disabled = false; }
    }

    async function abrir(opciones) {
        ctx = { ...opciones };
        crearModal();
        document.getElementById('vencQuien').textContent = 'Cargando…';
        document.getElementById('vencItems').innerHTML = '';
        modal.show();
        try {
            ctx.data = await consultar(ctx.liderId, ctx.placa);
            pintar(ctx.data);
        } catch (e) {
            document.getElementById('vencQuien').innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
        }
    }

    window.SgsVencimientos = { abrir, consultar, insignia, ETIQUETA };
})();
