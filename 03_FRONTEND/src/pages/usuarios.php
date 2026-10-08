<?php
// 03_FRONTEND/src/pages/usuarios.php - Usuarios y roles (solo administrador).
// Flujo: pantalla -> api/usuarios.php -> UsuarioController -> UsuarioService -> UsuarioRepository / PerfilRepository
Auth::requireLogin();
if (!Auth::can(Auth::PERM_USERS_MANAGE)) {
    echo '<div class="alert alert-danger m-4">Solo el administrador puede gestionar usuarios y roles.</div>';
    return;
}
$miId = (int)Auth::getLoggedInUser()['id'];
?>
<style>
.usr-tabs{display:inline-flex;gap:6px;padding:5px;background:var(--sgs-surface);border:1px solid var(--sgs-border);border-radius:14px;box-shadow:var(--sgs-shadow-field)}
.usr-tabs .btn{min-height:36px !important;padding:0 16px !important;border:0 !important;box-shadow:none !important;background:transparent !important;color:var(--sgs-ink-2) !important}
.usr-tabs .btn.active{background:var(--sgs-orange) !important;color:#fff !important;box-shadow:var(--sgs-shadow-btn) !important}
.usr-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.usr-toolbar>input{flex:1 1 260px}.usr-toolbar>select{flex:1 1 170px;max-width:220px}.usr-toolbar>button{flex:0 0 auto}
@media(max-width:575.98px){.usr-toolbar>select{max-width:none}}
.usr-avatar{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--sgs-orange-soft);color:var(--sgs-orange-dark);font-weight:900;font-size:13px;flex:none}
.usr-name{font-weight:800;color:var(--sgs-ink);line-height:1.2}
@media (min-width: 768px){.usr-table td:first-child{min-width:240px}}
.usr-sub{font-size:.78rem;color:var(--sgs-muted)}
.usr-table td{vertical-align:middle}
.usr-row-off td{opacity:.55}
.usr-org{font-size:.78rem;font-weight:700;color:var(--sgs-ink-2)}
.usr-perfil{display:inline-block;margin-top:3px;font-size:.72rem;font-weight:800;color:var(--sgs-orange-dark)}
.usr-section{font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--sgs-orange-dark);margin:6px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--sgs-border)}
.usr-perm-group{margin-bottom:12px}
.usr-perm-group h6{font-size:.78rem !important;color:var(--sgs-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em}
.usr-perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:4px 16px}
.usr-perm-grid .form-check{font-size:.86rem}
.usr-zonas{display:flex;flex-wrap:wrap;gap:8px}
.usr-zonas .btn-check+.btn{min-height:32px !important;font-size:.8rem !important;padding:0 12px !important}
.role-card{height:100%;display:flex;flex-direction:column;gap:8px;padding:18px !important}
.role-card h5{margin:0;font-size:1rem !important}
.role-card .role-meta{display:flex;gap:8px;flex-wrap:wrap}
.role-card .role-perms{font-size:.8rem;color:var(--sgs-muted);line-height:1.5}
.role-card.is-system{border-top:4px solid var(--sgs-ink-2) !important}
.role-card.is-custom{border-top:4px solid var(--sgs-orange) !important}
.role-card.is-off{opacity:.6}
/* Celular: cada usuario como tarjeta (sin desplazamiento lateral) */
@media(max-width:767.98px){
  .usr-table thead{display:none}
  .usr-table,.usr-table tbody,.usr-table tr,.usr-table td{display:block;width:100%}
  .usr-table tr{margin:10px;width:auto;border:1px solid var(--sgs-border);border-left:4px solid var(--sgs-orange);border-radius:14px;padding:6px 4px;background:var(--sgs-surface)}
  .usr-table tr.usr-row-off{border-left-color:var(--sgs-muted-2)}
  .usr-table td{border:0 !important;padding:6px 12px !important;text-align:left !important}
  .usr-table td[data-label]{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .usr-table td[data-label]::before{content:attr(data-label);font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--sgs-muted)}
  .usr-table td:last-child .sgs-row-actions{width:100%;justify-content:flex-end;padding-top:6px;border-top:1px dashed var(--sgs-border)}
}
.pw-box{font-family:var(--sgs-font-display);font-weight:900;font-size:1.35rem;letter-spacing:.06em;padding:12px 16px;border-radius:12px;background:var(--sgs-orange-soft);color:var(--sgs-orange-dark);text-align:center;user-select:all}
</style>

<div class="container-fluid p-3 p-md-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div class="usr-tabs" role="tablist">
      <button class="btn active" data-tab="usuarios" role="tab"><i class="bi bi-people"></i>Usuarios</button>
      <button class="btn" data-tab="roles" role="tab"><i class="bi bi-shield-lock"></i>Roles y perfiles</button>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-win" id="btnNuevoUsuario"><i class="bi bi-person-plus"></i>Nuevo usuario</button>
      <button class="btn btn-outline-secondary d-none" id="btnNuevoPerfil"><i class="bi bi-plus-lg"></i>Nuevo perfil</button>
    </div>
  </div>

  <!-- ===== Pestaña Usuarios ===== -->
  <section id="tab-usuarios">
    <div class="row g-3 mb-3" id="usrKpis">
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Usuarios activos</small><h3 class="mb-0 sgs-kpi-value" id="kActivos">—</h3></div></div>
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Personal directo WIN</small><h3 class="mb-0 sgs-kpi-value" id="kDirectos">—</h3></div></div>
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Usuarios de partners</small><h3 class="mb-0 sgs-kpi-value" id="kPartners">—</h3></div></div>
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Administradores</small><h3 class="mb-0 sgs-kpi-value" id="kAdmins">—</h3></div></div>
    </div>

    <div class="card p-3 mb-3">
      <div class="usr-toolbar">
        <input type="search" class="form-control form-control-sm" id="fQ" placeholder="Buscar por nombre, usuario, DNI o correo">
        <select class="form-select form-select-sm" id="fRol"><option value="">Todos los roles</option></select>
        <select class="form-select form-select-sm" id="fOrg"><option value="">Todas las organizaciones</option><option value="directo">WIN Directo</option></select>
        <select class="form-select form-select-sm" id="fEstado"><option value="">Activos e inactivos</option><option value="activos" selected>Solo activos</option><option value="inactivos">Solo inactivos</option></select>
        <button class="btn btn-outline-secondary btn-sm" id="fLimpiar"><i class="bi bi-x-lg"></i>Limpiar</button>
      </div>
    </div>

    <div class="card p-0 overflow-hidden">
      <div class="table-responsive">
        <table class="table table-hover mb-0 usr-table">
          <thead><tr><th>Usuario</th><th>Rol / perfil</th><th>Organización</th><th>Zonas</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
          <tbody id="usrBody"><tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ===== Pestaña Roles y perfiles ===== -->
  <section id="tab-roles" class="d-none">
    <div class="alert alert-primary d-flex gap-2 align-items-start mb-3">
      <i class="bi bi-info-circle mt-1"></i>
      <div><strong>Roles del sistema</strong>: definen los permisos de base y no se modifican.
        <strong>Perfiles</strong>: roles personalizados creados por el administrador sobre un rol base, con los permisos que elijas.
        Al asignar un perfil a un usuario, recibe su rol base y sus permisos; si editas el perfil, sus usuarios se actualizan al instante.</div>
    </div>
    <h6 class="usr-section">Roles del sistema</h6>
    <div class="row g-3 mb-4" id="rolesSistema"></div>
    <h6 class="usr-section">Perfiles personalizados</h6>
    <div class="row g-3" id="rolesPerfiles"></div>
  </section>
</div>

<!-- ===== Modal usuario ===== -->
<div class="modal fade" id="mUsuario" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="formUsuario" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="mUsuarioTitulo">Nuevo usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="alert alert-danger d-none" id="uError"></div>

        <div class="usr-section">Datos personales</div>
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label">DNI *</label><input class="form-control" name="dni" maxlength="8" inputmode="numeric" required></div>
          <div class="col-md-4"><label class="form-label">Nombres *</label><input class="form-control" name="nombres" required></div>
          <div class="col-md-4"><label class="form-label">Apellidos *</label><input class="form-control" name="apellidos" required></div>
          <div class="col-md-4"><label class="form-label">Correo *</label><input class="form-control" type="email" name="email" required></div>
          <div class="col-md-4"><label class="form-label">Teléfono personal</label><input class="form-control" name="telefono_personal"></div>
          <div class="col-md-4"><label class="form-label">Teléfono corporativo</label><input class="form-control" name="telefono_corporativo"></div>
        </div>

        <div class="usr-section">Acceso</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Usuario</label><input class="form-control" name="usuario" placeholder="Se genera si lo dejas vacío"></div>
          <div class="col-md-6"><label class="form-label" id="lblPassword">Contraseña *</label>
            <div class="input-group"><input class="form-control" name="password" type="text" autocomplete="new-password" placeholder="Mínimo 10, letras y números"><button type="button" class="btn btn-outline-secondary" id="btnGenerarPw" title="Generar contraseña segura"><i class="bi bi-magic"></i></button></div>
            <small class="text-muted" id="hintPassword">Compártela con el usuario por un medio seguro.</small></div>
        </div>

        <div class="usr-section">Rol y organización</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Perfil de acceso</label><select class="form-select" name="perfil_id"><option value="0">Sin perfil (usar un rol del sistema)</option></select></div>
          <div class="col-md-6"><label class="form-label">Rol del sistema *</label><select class="form-select" name="rol"></select></div>
          <div class="col-md-6"><label class="form-label">Organización *</label>
            <select class="form-select" name="organizacion"><option value="directo">WIN · Personal directo</option><option value="partner">Partner (contrata)</option></select></div>
          <div class="col-md-6" id="wrapPartner"><label class="form-label">Partner *</label><select class="form-select" name="partner_id"><option value="0">Seleccione…</option></select></div>
          <div class="col-md-6"><label class="form-label">Área de trabajo</label><input class="form-control" name="area_trabajo"></div>
          <div class="col-md-6"><label class="form-label">Modalidad</label><select class="form-select" name="modalidad"><option value="">—</option><option>PRESENCIAL</option><option>REMOTO</option><option>HÍBRIDO</option></select></div>
          <div class="col-md-6"><label class="form-label">Turno</label><select class="form-select" name="turno"><option value="">—</option><option>Mañana</option><option>Tarde</option><option>Noche</option><option>Rotativo</option></select></div>
        </div>

        <div id="wrapZonas">
          <div class="usr-section">Zonas de trabajo * <small class="text-muted text-transform-none">(departamentos que el usuario puede ver)</small></div>
          <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="chkTodasZonas" name="todas_zonas" value="1"><label class="form-check-label" for="chkTodasZonas">Todas las zonas</label></div>
          <div class="usr-zonas mb-3" id="zonas"></div>
        </div>

        <div class="usr-section d-flex align-items-center justify-content-between">
          <span>Permisos</span>
          <div class="form-check form-switch mb-0 text-transform-none"><input class="form-check-input" type="checkbox" id="chkPersonalizar" name="permisos_personalizados"><label class="form-check-label small fw-bold" for="chkPersonalizar">Personalizar</label></div>
        </div>
        <p class="small text-muted mb-2" id="permHint">Usa los permisos del rol o del perfil. Activa "Personalizar" para ajustarlos solo a este usuario.</p>
        <div id="permisos"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win" id="btnGuardarUsuario"><i class="bi bi-check2"></i>Guardar</button></div>
    </form>
  </div>
</div>

<!-- ===== Modal perfil ===== -->
<div class="modal fade" id="mPerfil" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="formPerfil" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="mPerfilTitulo">Nuevo perfil</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="alert alert-danger d-none" id="pError"></div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Nombre del perfil *</label><input class="form-control" name="nombre" maxlength="80" placeholder="Ej.: Supervisor de calidad" required></div>
          <div class="col-md-6"><label class="form-label">Rol base *</label><select class="form-select" name="rol_base"></select></div>
          <div class="col-12"><label class="form-label">Descripción</label><input class="form-control" name="descripcion" maxlength="255" placeholder="Para qué sirve este perfil"></div>
        </div>
        <div class="usr-section d-flex justify-content-between align-items-center"><span>Permisos del perfil</span><button type="button" class="btn btn-sm btn-outline-secondary" id="btnPermBase">Cargar permisos del rol base</button></div>
        <div id="permisosPerfil"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win"><i class="bi bi-check2"></i>Guardar perfil</button></div>
    </form>
  </div>
</div>

<!-- ===== Modal contraseña temporal ===== -->
<div class="modal fade" id="mPassword" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Contraseña temporal</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <p class="mb-2">Nueva contraseña de <strong id="pwUsuario"></strong>. Se muestra <strong>solo esta vez</strong>:</p>
      <div class="pw-box mb-3" id="pwValor"></div>
      <p class="small text-muted mb-0">Compártela por un medio seguro y pide al usuario que la cambie en <em>Mi información → Cambiar contraseña</em>.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="btnCopiarPw"><i class="bi bi-clipboard"></i>Copiar</button><button type="button" class="btn btn-win" data-bs-dismiss="modal">Listo</button></div>
  </div></div>
</div>

<script>
// Espera a que carguen Bootstrap y main.js (se incluyen al final de la página).
document.addEventListener('DOMContentLoaded', () => {
  const MI_ID = <?= $miId ?>;
  const $ = (s, el = document) => el.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (m, t = 'success') => (window.showToast ? window.showToast(m, t) : alert(m));
  let CAT = null, perfilesById = {};
  const mUsuario = new bootstrap.Modal('#mUsuario'), mPerfil = new bootstrap.Modal('#mPerfil'), mPassword = new bootstrap.Modal('#mPassword');

  async function api(action, { method = 'GET', params = {}, body = null } = {}) {
    const url = 'api/usuarios.php?' + new URLSearchParams({ action, ...params });
    const res = await fetch(url, { method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : null, cache: 'no-store' });
    const data = await res.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (data.status !== 'success') { const e = new Error(data.message || 'Error'); e.field = data.field; throw e; }
    return data;
  }

  // ---------- Pestañas ----------
  document.querySelectorAll('.usr-tabs .btn').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('.usr-tabs .btn').forEach((x) => x.classList.toggle('active', x === b));
    const roles = b.dataset.tab === 'roles';
    $('#tab-usuarios').classList.toggle('d-none', roles); $('#tab-roles').classList.toggle('d-none', !roles);
    $('#btnNuevoUsuario').classList.toggle('d-none', roles); $('#btnNuevoPerfil').classList.toggle('d-none', !roles);
  }));

  // ---------- Catálogos ----------
  function permisosHtml(prefix) {
    const grupos = {};
    CAT.permisos.forEach((p) => (grupos[p.grupo] = grupos[p.grupo] || []).push(p));
    return Object.entries(grupos).map(([g, items]) => `<div class="usr-perm-group"><h6>${esc(g)}</h6><div class="usr-perm-grid">${items.map((p) => `
      <div class="form-check"><input class="form-check-input" type="checkbox" name="permisos[]" value="${esc(p.clave)}" id="${prefix}_${esc(p.clave)}"><label class="form-check-label" for="${prefix}_${esc(p.clave)}">${esc(p.etiqueta)}</label></div>`).join('')}</div></div>`).join('');
  }
  const rolPermisos = (rol) => (CAT.roles.find((r) => r.rol === rol) || { permisos: [] }).permisos;
  const setChecks = (container, list) => container.querySelectorAll('input[name="permisos[]"]').forEach((c) => (c.checked = list.includes(c.value)));

  async function cargarCatalogos() {
    CAT = (await api('catalogos')).data;
    perfilesById = Object.fromEntries(CAT.perfiles.map((p) => [p.id, p]));
    const rolOpts = CAT.roles.map((r) => `<option value="${esc(r.rol)}">${esc(r.rol)}</option>`).join('');
    $('#fRol').innerHTML = '<option value="">Todos los roles</option>' + rolOpts;
    $('#fOrg').innerHTML = '<option value="">Todas las organizaciones</option><option value="directo">WIN Directo</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    const f = $('#formUsuario');
    f.rol.innerHTML = rolOpts;
    f.perfil_id.innerHTML = '<option value="0">Sin perfil (usar un rol del sistema)</option>' + CAT.perfiles.filter((p) => +p.activo).map((p) => `<option value="${p.id}">${esc(p.nombre)} · base ${esc(p.rol_base)}</option>`).join('');
    f.partner_id.innerHTML = '<option value="0">Seleccione…</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    $('#zonas').innerHTML = CAT.departamentos.map((d, i) => `<input type="checkbox" class="btn-check" name="zonas[]" value="${esc(d)}" id="z${i}"><label class="btn btn-outline-secondary" for="z${i}">${esc(d)}</label>`).join('');
    $('#permisos').innerHTML = permisosHtml('u');
    $('#formPerfil').rol_base.innerHTML = rolOpts;
    $('#permisosPerfil').innerHTML = permisosHtml('p');
    renderRoles();
  }

  // ---------- Usuarios ----------
  const iniciales = (n) => String(n || '').trim().split(/\s+/).filter(Boolean).map((x) => x[0]).slice(0, 2).join('').toUpperCase() || 'U';
  async function cargarUsuarios() {
    const params = { q: $('#fQ').value.trim(), rol: $('#fRol').value, organizacion: $('#fOrg').value, estado: $('#fEstado').value };
    const body = $('#usrBody');
    try {
      const data = await api('listar', { params });
      const r = data.resumen || {};
      $('#kActivos').textContent = r.activos ?? 0; $('#kDirectos').textContent = r.directos ?? 0;
      $('#kPartners').textContent = r.partners ?? 0; $('#kAdmins').textContent = r.administradores ?? 0;
      const rows = data.usuarios || [];
      body.innerHTML = rows.length ? rows.map((u) => {
        const directo = !u.nombre_partner || ['WIN', 'WIN DIRECTO'].includes(String(u.nombre_partner).trim().toUpperCase());
        const activo = +u.activo === 1, yo = +u.id === MI_ID;
        return `<tr class="${activo ? '' : 'usr-row-off'}">
          <td><div class="d-flex align-items-center gap-2"><span class="usr-avatar">${esc(iniciales(u.nombre_completo))}</span><div><div class="usr-name">${esc(u.nombre_completo)}${yo ? ' <span class="badge bg-light">Tú</span>' : ''}</div><div class="usr-sub">${esc(u.usuario || '—')} · DNI ${esc(u.dni)}</div></div></div></td>
          <td data-label="Rol / perfil"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(u.rol)}</span>${u.perfil_nombre ? `<br><span class="usr-perfil"><i class="bi bi-shield-check"></i> ${esc(u.perfil_nombre)}</span>` : ''}</td>
          <td data-label="Organización"><span class="usr-org">${directo ? 'WIN Directo' : esc(u.nombre_partner)}</span></td>
          <td data-label="Zonas">${u.todas_zonas ? 'Todas' : (u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : '—')}</td>
          <td data-label="Estado"><span class="badge ${activo ? 'bg-success' : 'bg-secondary'}">${activo ? 'Activo' : 'Inactivo'}</span>${u.bloqueado ? ' <span class="badge bg-danger" title="3 intentos fallidos"><i class="bi bi-lock"></i> Bloqueado</span>' : ''}</td>
          <td class="text-end"><div class="sgs-row-actions">
            <button class="sgs-icon-action" title="Editar" aria-label="Editar" data-edit="${u.id}"><i class="bi bi-pencil"></i></button>
            ${u.bloqueado ? `<button class="sgs-icon-action" title="Desbloquear" aria-label="Desbloquear usuario" data-unlock="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-unlock"></i></button>` : ''}
            <button class="sgs-icon-action" title="Restablecer contraseña" aria-label="Restablecer contraseña" data-pw="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-key"></i></button>
            ${yo ? '' : `<button class="sgs-icon-action ${activo ? 'sgs-icon-action--danger' : ''}" title="${activo ? 'Desactivar' : 'Activar'}" aria-label="${activo ? 'Desactivar' : 'Activar'}" data-state="${u.id}" data-to="${activo ? 0 : 1}" data-name="${esc(u.nombre_completo)}"><i class="bi ${activo ? 'bi-person-dash' : 'bi-person-check'}"></i></button>`}
          </div></td></tr>`;
      }).join('') : '<tr><td colspan="6" class="text-center text-muted py-4">No hay usuarios con esos filtros.</td></tr>';
    } catch (e) { body.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; }
  }
  let tQ; $('#fQ').addEventListener('input', () => { clearTimeout(tQ); tQ = setTimeout(cargarUsuarios, 300); });
  ['#fRol', '#fOrg', '#fEstado'].forEach((s) => $(s).addEventListener('change', cargarUsuarios));
  $('#fLimpiar').addEventListener('click', () => { $('#fQ').value = ''; $('#fRol').value = ''; $('#fOrg').value = ''; $('#fEstado').value = 'activos'; cargarUsuarios(); });

  // Formulario: reglas visibles
  const fU = $('#formUsuario');
  function syncForm() {
    const perfil = perfilesById[+fU.perfil_id.value];
    fU.rol.disabled = !!perfil;
    if (perfil) fU.rol.value = perfil.rol_base;
    const partner = fU.organizacion.value === 'partner';
    $('#wrapPartner').classList.toggle('d-none', !partner);
    const rol = fU.rol.value, esSup = rol === 'Supervisor' || rol === 'Partner';
    $('#wrapZonas').classList.toggle('d-none', !esSup);
    const todas = $('#chkTodasZonas').checked;
    $('#zonas').querySelectorAll('input').forEach((c) => (c.disabled = todas));
    const personal = $('#chkPersonalizar').checked && !perfil;
    $('#chkPersonalizar').disabled = !!perfil;
    $('#permisos').querySelectorAll('input').forEach((c) => (c.disabled = !personal));
    if (!personal) setChecks($('#permisos'), perfil ? perfil.permisos : rolPermisos(rol));
    $('#permHint').textContent = perfil ? `Permisos del perfil "${perfil.nombre}". Para cambiarlos, edita el perfil.` : (personal ? 'Permisos personalizados solo para este usuario.' : 'Usa los permisos por defecto del rol. Activa "Personalizar" para ajustarlos.');
  }
  ['perfil_id', 'rol', 'organizacion'].forEach((n) => fU[n].addEventListener('change', syncForm));
  $('#chkPersonalizar').addEventListener('change', syncForm);
  $('#chkTodasZonas').addEventListener('change', syncForm);
  let zonaBase = '';
  $('#btnGenerarPw').addEventListener('click', () => {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz', n = '23456789', s = '#$%&*?';
    const pick = (src, k) => Array.from(crypto.getRandomValues(new Uint32Array(k)), (x) => src[x % src.length]).join('');
    fU.password.value = pick(a, 9) + pick(n, 3) + pick(s, 1);
  });

  function abrirUsuario(u = null) {
    fU.reset(); zonaBase = ''; $('#uError').classList.add('d-none');
    fU.querySelectorAll('.is-invalid').forEach((x) => x.classList.remove('is-invalid'));
    fU.id.value = u ? u.id : 0;
    $('#mUsuarioTitulo').textContent = u ? `Editar · ${u.nombres} ${u.apellidos}` : 'Nuevo usuario';
    $('#lblPassword').textContent = u ? 'Nueva contraseña (opcional)' : 'Contraseña *';
    $('#hintPassword').textContent = u ? 'Déjala vacía para mantener la actual.' : 'Compártela con el usuario por un medio seguro.';
    if (u) {
      ['dni', 'nombres', 'apellidos', 'email', 'telefono_personal', 'telefono_corporativo', 'usuario', 'area_trabajo', 'modalidad', 'turno'].forEach((k) => { if (fU[k]) fU[k].value = u[k] ?? ''; });
      fU.rol.value = u.rol; fU.perfil_id.value = u.perfil_id || 0; fU.organizacion.value = u.organizacion; fU.partner_id.value = u.partner_id || 0;
      fU.querySelectorAll('input[name="zonas[]"]').forEach((c) => (c.checked = (u.zonas || []).includes(c.value)));
      $('#chkTodasZonas').checked = !!u.todas_zonas; zonaBase = (u.zonas || [])[0] || '';
      $('#chkPersonalizar').checked = !!u.permisos_personalizados && !u.perfil_id;
    } else { fU.rol.value = 'Supervisor'; fU.organizacion.value = 'directo'; }
    syncForm();
    if (u && u.permisos_personalizados && !u.perfil_id) setChecks($('#permisos'), u.permisos || []);
    mUsuario.show();
  }
  $('#btnNuevoUsuario').addEventListener('click', () => abrirUsuario());

  fU.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(fU), body = {};
    fd.forEach((v, k) => { if (k.endsWith('[]')) (body[k.slice(0, -2)] = body[k.slice(0, -2)] || []).push(v); else body[k] = v; });
    body.rol = fU.rol.value; body.permisos_personalizados = $('#chkPersonalizar').checked && !$('#chkPersonalizar').disabled;
    body.zonas = body.zonas || []; body.permisos = body.permisos || [];
    body.todas_zonas = $('#chkTodasZonas').checked;
    // La zona base actual se envía primero para no cambiar la sede heredada del usuario.
    if (zonaBase && body.zonas.includes(zonaBase)) body.zonas = [zonaBase, ...body.zonas.filter((z) => z !== zonaBase)];
    const btn = $('#btnGuardarUsuario'); btn.disabled = true;
    try {
      const r = await api('guardar', { method: 'POST', body });
      mUsuario.hide(); toast(r.message); cargarUsuarios(); cargarCatalogos();
    } catch (e) {
      $('#uError').textContent = e.message; $('#uError').classList.remove('d-none');
      if (e.field && fU[e.field]) { fU[e.field].classList.add('is-invalid'); fU[e.field].focus(); }
    } finally { btn.disabled = false; }
  });

  $('#usrBody').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    try {
      if (b.dataset.edit) { abrirUsuario((await api('detalle', { params: { id: b.dataset.edit } })).data); }
      else if (b.dataset.pw) {
        if (!confirm(`¿Generar una contraseña temporal para ${b.dataset.name}? La actual dejará de funcionar.`)) return;
        const r = await api('restablecer_password', { method: 'POST', body: { id: +b.dataset.pw } });
        $('#pwUsuario').textContent = b.dataset.name; $('#pwValor').textContent = r.password; mPassword.show();
      } else if (b.dataset.unlock) {
        if (!confirm(`¿Desbloquear a ${b.dataset.name}? Podrá volver a intentar ingresar con su contraseña actual.`)) return;
        const r = await api('desbloquear', { method: 'POST', body: { id: +b.dataset.unlock } });
        toast(r.message); cargarUsuarios();
      } else if (b.dataset.state) {
        const activar = b.dataset.to === '1';
        if (!confirm(`¿${activar ? 'Activar' : 'Desactivar'} a ${b.dataset.name}?${activar ? '' : ' No podrá ingresar al sistema.'}`)) return;
        const r = await api('estado', { method: 'POST', body: { id: +b.dataset.state, activo: activar } });
        toast(r.message); cargarUsuarios();
      }
    } catch (e) { toast(e.message, 'error'); }
  });
  $('#btnCopiarPw').addEventListener('click', () => navigator.clipboard?.writeText($('#pwValor').textContent).then(() => toast('Contraseña copiada.')));
  document.getElementById('mPassword').addEventListener('hidden.bs.modal', () => { $('#pwValor').textContent = ''; });

  // ---------- Roles y perfiles ----------
  const etiquetaPermiso = (k) => (CAT.permisos.find((p) => p.clave === k) || { etiqueta: k }).etiqueta;
  function renderRoles() {
    $('#rolesSistema').innerHTML = CAT.roles.map((r) => `<div class="col-md-6 col-xl-4"><div class="card role-card is-system">
      <div class="d-flex justify-content-between align-items-center"><h5><i class="bi bi-shield-lock me-1"></i>${esc(r.rol)}</h5><span class="badge bg-light">Sistema</span></div>
      <div class="role-meta"><span class="sgs-chip"><i class="bi bi-people"></i>${r.usuarios} activos</span><span class="sgs-chip"><i class="bi bi-check2-square"></i>${r.permisos.length} permisos</span></div>
      <div class="role-perms">${r.permisos.slice(0, 6).map((p) => esc(etiquetaPermiso(p))).join(' · ')}${r.permisos.length > 6 ? ` · y ${r.permisos.length - 6} más` : ''}</div>
    </div></div>`).join('');
    $('#rolesPerfiles').innerHTML = CAT.perfiles.length ? CAT.perfiles.map((p) => `<div class="col-md-6 col-xl-4"><div class="card role-card is-custom ${+p.activo ? '' : 'is-off'}">
      <div class="d-flex justify-content-between align-items-start gap-2"><h5>${esc(p.nombre)}</h5><span class="badge ${+p.activo ? 'bg-success' : 'bg-secondary'}">${+p.activo ? 'Activo' : 'Inactivo'}</span></div>
      <div class="small text-muted">${esc(p.descripcion || 'Sin descripción')}</div>
      <div class="role-meta"><span class="sgs-chip sgs-chip--brand">Base: ${esc(p.rol_base)}</span><span class="sgs-chip"><i class="bi bi-people"></i>${p.usuarios}</span><span class="sgs-chip"><i class="bi bi-check2-square"></i>${p.permisos.length}</span></div>
      <div class="mt-auto d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" data-pedit="${p.id}"><i class="bi bi-pencil"></i>Editar</button>
      <button class="btn btn-sm btn-outline-secondary" data-pstate="${p.id}" data-to="${+p.activo ? 0 : 1}">${+p.activo ? '<i class="bi bi-pause-circle"></i>Desactivar' : '<i class="bi bi-play-circle"></i>Activar'}</button></div>
    </div></div>`).join('') : `<div class="col-12"><div class="card p-4 text-center text-muted"><i class="bi bi-shield-plus fs-2 mb-2"></i>Aún no hay perfiles personalizados. Crea el primero con <strong>Nuevo perfil</strong>.</div></div>`;
  }
  const fP = $('#formPerfil');
  function abrirPerfil(p = null) {
    fP.reset(); $('#pError').classList.add('d-none');
    fP.id.value = p ? p.id : 0; $('#mPerfilTitulo').textContent = p ? `Editar perfil · ${p.nombre}` : 'Nuevo perfil';
    fP.nombre.value = p?.nombre || ''; fP.descripcion.value = p?.descripcion || ''; fP.rol_base.value = p?.rol_base || 'Supervisor';
    setChecks($('#permisosPerfil'), p ? p.permisos : rolPermisos(fP.rol_base.value));
    mPerfil.show();
  }
  $('#btnNuevoPerfil').addEventListener('click', () => abrirPerfil());
  $('#btnPermBase').addEventListener('click', () => setChecks($('#permisosPerfil'), rolPermisos(fP.rol_base.value)));
  fP.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = { id: +fP.id.value, nombre: fP.nombre.value, descripcion: fP.descripcion.value, rol_base: fP.rol_base.value, permisos: [...fP.querySelectorAll('input[name="permisos[]"]:checked')].map((c) => c.value) };
    try { const r = await api('guardar_perfil', { method: 'POST', body }); mPerfil.hide(); toast(r.message); await cargarCatalogos(); cargarUsuarios(); }
    catch (e) { $('#pError').textContent = e.message; $('#pError').classList.remove('d-none'); }
  });
  $('#rolesPerfiles').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    if (b.dataset.pedit) return abrirPerfil(perfilesById[+b.dataset.pedit]);
    if (b.dataset.pstate) {
      try { const r = await api('estado_perfil', { method: 'POST', body: { id: +b.dataset.pstate, activo: b.dataset.to === '1' } }); toast(r.message); await cargarCatalogos(); }
      catch (e) { toast(e.message, 'error'); }
    }
  });

  cargarCatalogos().then(cargarUsuarios).catch((e) => toast(e.message, 'error'));
});
</script>
