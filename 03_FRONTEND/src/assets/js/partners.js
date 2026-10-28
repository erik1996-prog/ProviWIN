// 03_FRONTEND/src/assets/js/partners.js - Partners (pages/partners.php). Permisos del usuario en window.SGS_PARTNERS.
// 2026-10-27: salió de la página a este archivo (sistema liviano: el navegador lo guarda).
const PUEDE = window.SGS_PARTNERS;
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
          ${PUEDE.editar ? `<button type="button" class="sgs-icon-action" title="Editar" aria-label="Editar ${esc(p.nombre_partner)}" data-editar="${p.id}"><i class="bi bi-pencil"></i></button>` : ''}
          ${p.protegido || !PUEDE.editar ? '' : `<button type="button" class="sgs-icon-action ${activo ? 'sgs-icon-action--danger' : ''}" title="${activo ? 'Desactivar' : 'Activar'}" aria-label="${activo ? 'Desactivar' : 'Activar'} ${esc(p.nombre_partner)}" data-estado-id="${p.id}" data-activar="${activo ? 0 : 1}"><i class="bi ${activo ? 'bi-slash-circle' : 'bi-check-circle'}"></i></button>`}
          ${p.puede_eliminar && PUEDE.eliminar ? `<button type="button" class="sgs-icon-action sgs-icon-action--danger" title="Eliminar (no tiene registros)" aria-label="Eliminar ${esc(p.nombre_partner)}" data-eliminar="${p.id}"><i class="bi bi-trash3"></i></button>` : ''}
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
  $('btnNuevoPartner')?.addEventListener('click', () => abrir());
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
