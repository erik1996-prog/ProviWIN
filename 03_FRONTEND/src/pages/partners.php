<?php
// Partners (2026-10-15): registro de contratas. Solo administración (PERM_USERS_MANAGE). Datos y reglas en api/partners.php.
Auth::requireLogin();
if (!Auth::can(Auth::PERM_USERS_MANAGE, Auth::getLoggedInUser())) {
    echo '<div class="alert alert-danger m-4">Acceso reservado al administrador.</div>';
    return;
}
?>
<div class="container-fluid p-3 p-md-4">
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-buildings"></i></span>
      <small class="sgs-kpi-label">Partners activos</small><h3 class="mb-0 sgs-kpi-value" id="kActivos">—</h3></div></div>
    <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
      <small class="sgs-kpi-label">Técnicos líderes</small><h3 class="mb-0 sgs-kpi-value" id="kLideres">—</h3></div></div>
    <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
      <small class="sgs-kpi-label">Supervisores de partner</small><h3 class="mb-0 sgs-kpi-value" id="kUsuarios">—</h3></div></div>
    <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-slash-circle"></i></span>
      <small class="sgs-kpi-label">Partners inactivos</small><h3 class="mb-0 sgs-kpi-value" id="kInactivos">—</h3></div></div>
  </div>

  <div class="card sgs-toolbar-card p-3 mb-3">
    <div class="sgs-toolbar">
      <div class="sgs-segmented" role="group" aria-label="Estado del partner" id="ptEstados">
        <button type="button" class="filter-tab-btn active" data-estado="activos">Activos <span class="sgs-seg-count" data-cuenta="activos">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="inactivos">Inactivos <span class="sgs-seg-count" data-cuenta="inactivos">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="todos">Todos <span class="sgs-seg-count" data-cuenta="todos">0</span></button>
      </div>
      <div class="sgs-toolbar-actions">
        <button type="button" class="btn btn-win" id="btnNuevoPartner"><i class="bi bi-plus-lg me-1"></i>Nuevo partner</button>
      </div>
    </div>
    <div class="sgs-toolbar-row"><div class="sgs-filtros">
      <div class="sgs-filtro sgs-filtro--ancho"><label class="form-label" for="ptBuscar">Buscar</label>
        <div class="sgs-filtro-busqueda"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="ptBuscar" type="search" maxlength="60" placeholder="Nombre o RUC" autocomplete="off"></div></div>
      <button type="button" class="btn btn-outline-secondary sgs-filtro-limpiar" id="ptLimpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
    </div></div>
  </div>

  <div class="card p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h5 class="mb-0"><i class="bi bi-buildings me-2 text-primary"></i>Partners registrados</h5>
      <span class="sgs-counter" id="ptContador">—</span>
    </div>
    <p class="text-muted small">Un partner inactivo deja de aparecer para elegir (supervisiones, usuarios, técnicos), pero conserva todo su historial. Solo se puede eliminar un partner que no tenga nada registrado.</p>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
      <thead><tr><th>Partner</th><th class="text-end" title="Técnicos líderes habilitados">Líderes</th><th class="text-end" title="Técnicos de apoyo">Apoyos</th><th class="text-end">Supervisores</th><th class="text-end">Supervisiones</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody id="ptCuerpo"><tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
    </table></div>
  </div>
</div>

<div class="modal fade" id="mPartner" tabindex="-1" aria-labelledby="mPartnerTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="fPartner" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="mPartnerTitulo"><i class="bi bi-buildings me-2"></i>Nuevo partner</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="mb-3"><label class="form-label" for="ptNombre">Nombre del partner *</label>
          <input class="form-control text-uppercase" id="ptNombre" name="nombre_partner" maxlength="150" required placeholder="Ej.: OLMA">
          <small class="text-muted">Se guarda en mayúsculas. Es el nombre que aparece en las listas y en la carga de cancelaciones (columna PARTNER).</small></div>
        <div><label class="form-label" for="ptRuc">RUC (opcional)</label>
          <input class="form-control" id="ptRuc" name="ruc" inputmode="numeric" maxlength="11" placeholder="11 dígitos"></div>
        <div class="alert alert-danger d-none mt-3 mb-0" id="ptError" role="alert"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win" id="btnGuardarPartner"><i class="bi bi-check2 me-1"></i>Guardar partner</button></div>
    </form>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $ = (id) => document.getElementById(id);
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (v) => Number(v || 0).toLocaleString('es-PE');
  const api = async (accion, cuerpo) => {
    const opciones = cuerpo ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify(cuerpo) } : {};
    const r = await fetch('api/partners.php?action=' + accion, opciones);
    const d = await r.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (d.status !== 'success') { const e = new Error(d.message || 'No se pudo completar la acción.'); e.field = d.field; throw e; }
    return d;
  };
  let partners = [], estado = 'activos';
  const mPartner = bootstrap.Modal.getOrCreateInstance($('mPartner'));
  const f = $('fPartner');

  const pintar = () => {
    const q = $('ptBuscar').value.trim().toLowerCase();
    const activos = partners.filter((p) => +p.estado === 1);
    $('kActivos').textContent = activos.length;
    $('kInactivos').textContent = partners.length - activos.length;
    $('kLideres').textContent = num(activos.reduce((s, p) => s + +p.lideres, 0));
    $('kUsuarios').textContent = num(activos.filter((p) => !p.protegido).reduce((s, p) => s + +p.usuarios, 0));
    const cuenta = { activos: activos.length, inactivos: partners.length - activos.length, todos: partners.length };
    document.querySelectorAll('#ptEstados [data-cuenta]').forEach((el) => { el.textContent = cuenta[el.dataset.cuenta]; });
    const lista = partners.filter((p) => (estado === 'todos' || (estado === 'activos') === (+p.estado === 1))
      && (!q || String(p.nombre_partner).toLowerCase().includes(q) || String(p.ruc || '').includes(q)));
    $('ptContador').textContent = `${lista.length} partner${lista.length === 1 ? '' : 's'}`;
    $('ptCuerpo').innerHTML = lista.length ? lista.map((p) => {
      const activo = +p.estado === 1;
      return `<tr>
        <td data-label="Partner"><div><div class="fw-bold">${esc(p.nombre_partner)}${p.protegido ? ' <span class="badge bg-primary">Personal propio</span>' : ''}</div><small class="text-muted">${p.ruc ? 'RUC ' + esc(p.ruc) : 'Sin RUC'}</small></div></td>
        <td data-label="Técnicos líderes" class="text-end">${num(p.lideres)}</td>
        <td data-label="Técnicos de apoyo" class="text-end">${num(p.apoyos)}</td>
        <td data-label="Supervisores" class="text-end">${num(p.usuarios)}</td>
        <td data-label="Supervisiones" class="text-end">${num(p.supervisiones)}</td>
        <td data-label="Estado"><span class="badge ${activo ? 'bg-success' : 'bg-secondary'}">${activo ? 'Activo' : 'Inactivo'}</span></td>
        <td class="text-end"><div class="sgs-row-actions justify-content-end">
          <button type="button" class="sgs-icon-action" title="Editar" aria-label="Editar ${esc(p.nombre_partner)}" data-editar="${p.id}"><i class="bi bi-pencil"></i></button>
          ${p.protegido ? '' : `<button type="button" class="sgs-icon-action ${activo ? 'sgs-icon-action--danger' : ''}" title="${activo ? 'Desactivar' : 'Activar'}" aria-label="${activo ? 'Desactivar' : 'Activar'} ${esc(p.nombre_partner)}" data-estado-id="${p.id}" data-activar="${activo ? 0 : 1}"><i class="bi ${activo ? 'bi-slash-circle' : 'bi-check-circle'}"></i></button>`}
          ${p.puede_eliminar ? `<button type="button" class="sgs-icon-action sgs-icon-action--danger" title="Eliminar (no tiene registros)" aria-label="Eliminar ${esc(p.nombre_partner)}" data-eliminar="${p.id}"><i class="bi bi-trash3"></i></button>` : ''}
        </div></td></tr>`;
    }).join('') : `<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay partners con estos filtros.</td></tr>`;
  };
  const cargar = async () => {
    try { partners = (await api('list')).data || []; pintar(); }
    catch (e) { $('ptCuerpo').innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; }
  };

  const abrir = (p = null) => {
    f.reset(); $('ptError').classList.add('d-none');
    f.querySelectorAll('.is-invalid').forEach((x) => x.classList.remove('is-invalid'));
    f.id.value = p ? p.id : 0;
    f.nombre_partner.value = p ? p.nombre_partner : '';
    f.ruc.value = p?.ruc || '';
    f.nombre_partner.readOnly = !!p?.protegido;
    $('mPartnerTitulo').innerHTML = `<i class="bi bi-buildings me-2"></i>${p ? 'Editar partner' : 'Nuevo partner'}`;
    mPartner.show();
  };
  $('btnNuevoPartner').addEventListener('click', () => abrir());
  $('mPartner').addEventListener('shown.bs.modal', () => f.nombre_partner.focus());
  f.ruc.addEventListener('input', () => { f.ruc.value = f.ruc.value.replace(/\D/g, '').slice(0, 11); });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = $('btnGuardarPartner'); btn.disabled = true;
    f.querySelectorAll('.is-invalid').forEach((x) => x.classList.remove('is-invalid'));
    try {
      const r = await api('guardar', { id: +f.id.value, nombre_partner: f.nombre_partner.value, ruc: f.ruc.value });
      mPartner.hide(); window.showToast?.(r.message, 'success'); cargar();
    } catch (e) {
      $('ptError').textContent = e.message; $('ptError').classList.remove('d-none');
      if (e.field && f[e.field]) { f[e.field].classList.add('is-invalid'); f[e.field].focus(); }
    } finally { btn.disabled = false; }
  });

  $('ptCuerpo').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    const p = partners.find((x) => String(x.id) === (b.dataset.editar || b.dataset.estadoId || b.dataset.eliminar));
    if (!p) return;
    try {
      if (b.dataset.editar) return abrir(p);
      if (b.dataset.estadoId) {
        const activar = b.dataset.activar === '1';
        if (!activar && !confirm(`¿Desactivar ${p.nombre_partner}? Dejará de aparecer para elegir en supervisiones, usuarios y técnicos, pero conserva su historial.`)) return;
        const r = await api('estado', { id: p.id, activo: activar }); window.showToast?.(r.message, 'success');
      } else if (b.dataset.eliminar) {
        if (!confirm(`¿Eliminar ${p.nombre_partner}? No tiene registros; esta acción no se puede deshacer.`)) return;
        const r = await api('eliminar', { id: p.id }); window.showToast?.(r.message, 'success');
      }
      cargar();
    } catch (e) { window.showToast?.(e.message, 'error'); }
  });

  $('ptEstados').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-estado]'); if (!b) return;
    estado = b.dataset.estado;
    document.querySelectorAll('#ptEstados [data-estado]').forEach((x) => x.classList.toggle('active', x === b));
    pintar();
  });
  $('ptBuscar').addEventListener('input', pintar);
  $('ptLimpiar').addEventListener('click', () => { $('ptBuscar').value = ''; document.querySelector('#ptEstados [data-estado="activos"]').click(); });
  cargar();
});
</script>
