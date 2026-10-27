<?php
// 03_FRONTEND/src/pages/usuarios.php - Usuarios y roles / Personal.
// Flujo: pantalla -> api/usuarios.php -> UsuarioController -> UsuarioService -> UsuarioRepository
// Según el permiso: administrar (todo) · su equipo (líder, 2026-10-27: roles por debajo, sus zonas, su área y sus permisos;
// el servidor pone los límites) · agregar y editar personal (solo datos personales) · ver (solo consulta).
Auth::requireLogin();
$esAdminTotal = Auth::can(Auth::PERM_USERS_MANAGE);
$esAdminUsuarios = $esAdminTotal || Auth::can(Auth::PERM_USERS_TEAM); // formulario completo (el líder, con sus límites)
$gestionaPersonal = $esAdminUsuarios || Auth::can(Auth::PERM_STAFF_MANAGE);
if (!$gestionaPersonal && !Auth::can(Auth::PERM_USERS_VIEW)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver usuarios.</div>';
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
.usr-avatar{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--sgs-orange-soft);color:var(--sgs-orange-dark);font-weight: 700;font-size:13px;flex:none}
.usr-name{font-weight: 700;color:var(--sgs-ink);line-height:1.2}
@media (min-width: 768px){.usr-table td:first-child{min-width:240px}}
.usr-sub{font-size:.78rem;color:var(--sgs-muted)}
.usr-table td{vertical-align:middle}
.usr-row-off td{opacity:.55}
.usr-org{font-size:.78rem;font-weight:700;color:var(--sgs-ink-2)}
.usr-section{font-size:.74rem;font-weight: 700;text-transform:uppercase;letter-spacing:.06em;color:var(--sgs-orange-dark);margin:6px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--sgs-border)}
/* Permisos por pantalla (2026-10-26): Ver / Editar / Eliminar / Opciones; sin "Ver" el resto de la fila se apaga */
.usr-perm-tabla td,.usr-perm-tabla th{vertical-align:top}
.usr-perm-tabla .form-check{font-size:.84rem;margin-bottom:2px}
.usr-perm-tabla .form-check.is-bloqueado{opacity:.45}
.usr-perm-seccion td{background:var(--sgs-surface-alt);color:var(--sgs-orange-dark);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.usr-zonas{display:flex;flex-wrap:wrap;gap:8px}
.usr-zonas .btn-check+.btn{min-height:32px !important;font-size:.8rem !important;padding:0 12px !important}
.role-card{height:100%;display:flex;flex-direction:column;gap:8px;padding:18px !important}
.role-card h5{margin:0;font-size:1rem !important}
.role-card .role-meta{display:flex;gap:8px;flex-wrap:wrap}
.role-card .role-perms{font-size:.8rem;color:var(--sgs-muted);line-height:1.5}
.role-card.is-system{border-top:4px solid var(--sgs-ink-2) !important}
/* Celular: cada usuario como tarjeta (sin desplazamiento lateral) */
@media(max-width:767.98px){
  .usr-table thead{display:none}
  .usr-table,.usr-table tbody,.usr-table tr,.usr-table td{display:block;width:100%}
  .usr-table tr{margin:10px;width:auto;border:1px solid var(--sgs-border);border-left:4px solid var(--sgs-orange);border-radius:14px;padding:6px 4px;background:var(--sgs-surface)}
  .usr-table tr.usr-row-off{border-left-color:var(--sgs-muted-2)}
  .usr-table td{border:0 !important;padding:6px 12px !important;text-align:left !important}
  .usr-table td[data-label]{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .usr-table td[data-label]::before{content:attr(data-label);font-size:.72rem;font-weight: 700;text-transform:uppercase;letter-spacing:.04em;color:var(--sgs-muted)}
  .usr-table td:last-child .sgs-row-actions{width:100%;justify-content:flex-end;padding-top:6px;border-top:1px dashed var(--sgs-border)}
}
.pw-box{font-family:var(--sgs-font-display);font-weight: 700;font-size:1.35rem;letter-spacing:.06em;padding:12px 16px;border-radius:12px;background:var(--sgs-orange-soft);color:var(--sgs-orange-dark);text-align:center;user-select:all}
</style>

<div class="container-fluid p-3 p-md-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <?php if ($esAdminTotal): ?>
    <div class="usr-tabs" role="tablist">
      <button class="btn active" data-tab="usuarios" role="tab"><i class="bi bi-people"></i>Usuarios</button>
      <button class="btn" data-tab="roles" role="tab"><i class="bi bi-shield-lock"></i>Roles y permisos</button>
    </div>
    <?php else: ?><span class="sgs-counter" id="usrContador">—</span><?php endif; ?>
    <div class="d-flex gap-2">
      <?php if ($esAdminUsuarios): ?><button class="btn btn-win" id="btnNuevoUsuario"><i class="bi bi-person-plus"></i>Nuevo usuario</button>
      <?php elseif ($gestionaPersonal): ?><button class="btn btn-win" id="btnNuevoPersonal"><i class="bi bi-person-plus"></i>Agregar personal</button><?php endif; ?>
    </div>
  </div>

  <!-- ===== Pestaña Usuarios ===== -->
  <section id="tab-usuarios">
    <?php if ($esAdminUsuarios): ?>
    <div class="row g-3 mb-3" id="usrKpis">
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Usuarios activos</small><h3 class="mb-0 sgs-kpi-value" id="kActivos">—</h3></div></div>
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Personal directo WIN</small><h3 class="mb-0 sgs-kpi-value" id="kDirectos">—</h3></div></div>
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Usuarios de partners</small><h3 class="mb-0 sgs-kpi-value" id="kPartners">—</h3></div></div>
      <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Administradores</small><h3 class="mb-0 sgs-kpi-value" id="kAdmins">—</h3></div></div>
    </div>
    <?php endif; ?>

    <div class="card p-3 mb-3">
      <?php if ($esAdminUsuarios): ?>
      <div class="sgs-segmented mb-3" role="group" aria-label="Estado de acceso" id="usrEstados">
        <button type="button" class="filter-tab-btn active" data-estado="">Todos <span class="sgs-seg-count" data-cuenta="todos">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="con_acceso" title="Activos que ya ingresaron al sistema">Con acceso <span class="sgs-seg-count" data-cuenta="con_acceso">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="por_ingresar" title="Activos que aún no ingresan por primera vez">Por ingresar <span class="sgs-seg-count" data-cuenta="por_ingresar">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="por_activar" title="Creados por otro administrador: esperan que la cuenta principal los active">Por activar <span class="sgs-seg-count" data-cuenta="por_activar">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="sin_acceso" title="Registrados solo con datos personales: falta darles usuario y contraseña">Sin acceso <span class="sgs-seg-count" data-cuenta="sin_acceso">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="desactivados">Desactivados <span class="sgs-seg-count" data-cuenta="desactivados">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="bloqueados" title="3 intentos fallidos de contraseña">Bloqueados <span class="sgs-seg-count" data-cuenta="bloqueados">0</span></button>
      </div>
      <?php endif; ?>
      <div class="usr-toolbar">
        <input type="search" class="form-control form-control-sm" id="fQ" placeholder="Buscar por nombre, usuario, DNI o correo">
        <?php if ($esAdminUsuarios): ?>
        <select class="form-select form-select-sm" id="fRol"><option value="">Todos los roles</option></select>
        <select class="form-select form-select-sm" id="fOrg"><option value="">Todas las organizaciones</option><option value="directo">WIN Directo</option></select>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm" id="fLimpiar"><i class="bi bi-x-lg"></i>Limpiar</button>
      </div>
    </div>

    <div class="card p-0 overflow-hidden">
      <div class="table-responsive">
        <table class="table table-hover mb-0 usr-table">
          <thead><tr><th>Usuario</th><th>Rol y organización</th><th>Zonas</th><?php if ($esAdminUsuarios): ?><th>Estado</th><?php endif; ?><th class="text-end">Acciones</th></tr></thead>
          <tbody id="usrBody"><tr><td colspan="<?= $esAdminUsuarios ? 5 : 4 ?>" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ===== Pestaña Roles y permisos ===== -->
  <section id="tab-roles" class="d-none">
    <div class="alert alert-primary d-flex gap-2 align-items-start mb-3">
      <i class="bi bi-info-circle mt-1"></i>
      <div>Cada <strong>rol</strong> trae sus permisos por defecto. Si un usuario necesita algo distinto, edítalo y activa
        <strong>Personalizar</strong> en sus permisos: el cambio vale solo para esa persona.</div>
    </div>
    <h6 class="usr-section">Roles del sistema</h6>
    <div class="row g-3" id="rolesSistema"></div>
    <!-- Matriz (2026-10-23): por módulo, qué puede ver, editar y eliminar cada rol por defecto -->
    <h6 class="usr-section mt-4">Qué puede hacer cada rol</h6>
    <p class="small text-muted mb-2">Permisos por defecto de cada rol en cada módulo. Pase el cursor sobre una marca para ver el detalle. En el rol Supervisor, los módulos de gestión son solo para el supervisor directo: el de partner no los ve.</p>
    <div class="card p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas perm-matriz" id="matrizPermisos"></table></div></div>
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
          <div class="col-md-4"><label class="form-label" for="uEmail">Correo *</label><input class="form-control" type="email" id="uEmail" name="email" maxlength="150" autocomplete="off" required></div>
          <div class="col-md-4"><label class="form-label">Teléfono personal</label><input class="form-control" name="telefono_personal"></div>
          <div class="col-md-4"><label class="form-label">Teléfono corporativo</label><input class="form-control" name="telefono_corporativo"></div>
        </div>


        <div class="usr-section">Organización y rol</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Organización *</label>
            <select class="form-select" name="organizacion"><option value="directo">WIN · Personal directo</option><option value="partner">Partner (contrata)</option></select></div>
          <div class="col-md-6" id="wrapPartner"><label class="form-label">Partner *</label><select class="form-select" name="partner_id"><option value="0">Seleccione…</option></select></div>
          <div class="col-md-6"><label class="form-label" for="uArea">Área de trabajo</label><select class="form-select" id="uArea" name="area_trabajo" aria-describedby="uAreaAyuda"><option value="">—</option></select>
            <div class="form-text" id="uAreaAyuda">Instalaciones o Averías: el supervisor solo ve las pendientes de su área. Con ambas, ve todas.</div></div>
          <div class="col-md-6"><label class="form-label" for="uCargo">Cargo</label><select class="form-select" id="uCargo" name="cargo"><option value="">—</option></select></div>
          <div class="col-md-6"><label class="form-label">Rol del sistema *</label><select class="form-select" name="rol"></select></div>
          <div class="col-md-6"><label class="form-label">Modalidad</label><select class="form-select" name="modalidad"><option value="">—</option><option>PRESENCIAL</option><option>REMOTO</option><option>HÍBRIDO</option></select></div>
          <div class="col-md-6"><label class="form-label">Turno</label><select class="form-select" name="turno"><option value="">—</option><option>Día</option><option>Noche</option></select></div>
        </div>

        <div class="usr-section">Procesos en los que se le copia en los correos</div>
        <p class="small text-muted mb-2">Marque los procesos de los que debe recibir copia, o "Todos".</p>
        <div class="usr-zonas mb-3" id="procesos"></div>

        <div id="wrapZonas">
          <div class="usr-section">Zonas de trabajo <small class="text-muted text-transform-none">(lo que el usuario puede ver; el Norte Chico son las provincias Huaura, Huaral y Barranca)</small></div>
          <p class="form-text mt-0 mb-2 d-none" id="zonasLider">Líder (Gestión): sin zonas ve todo; con zonas, solo esas (y solo su área de trabajo, si es Instalaciones o Averías).</p>
          <div class="form-check form-switch mb-2" id="wrapTodasZonas"><input class="form-check-input" type="checkbox" role="switch" id="chkTodasZonas" name="todas_zonas" value="1"><label class="form-check-label" for="chkTodasZonas">Todas las zonas</label></div>
          <div class="usr-zonas mb-3" id="zonas"></div>
        </div>

        <div class="usr-section">Acceso</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Usuario</label><input class="form-control" name="usuario" placeholder="Se genera si lo dejas vacío"></div>
          <div class="col-md-6"><label class="form-label" id="lblPassword">Contraseña *</label>
            <div class="input-group"><input class="form-control" name="password" id="uPassword" type="text" autocomplete="new-password" placeholder="Mínimo 10, letras y números"><button type="button" class="btn btn-outline-secondary" id="btnGenerarPw" title="Generar contraseña segura"><i class="bi bi-magic"></i></button></div>
            <small class="text-muted" id="hintPassword">Compártela con el usuario por un medio seguro.</small>
            <div data-password-meter="uPassword"></div>
            <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="uPedirCambio" name="pedir_cambio" value="1" checked><label class="form-check-label small" for="uPedirCambio">Pedir que la cambie al ingresar</label></div></div>
        </div>

        <div class="usr-section d-flex align-items-center justify-content-between">
          <span>Permisos</span>
          <div class="form-check form-switch mb-0 text-transform-none"><input class="form-check-input" type="checkbox" id="chkPersonalizar" name="permisos_personalizados"><label class="form-check-label small fw-bold" for="chkPersonalizar">Personalizar</label></div>
        </div>
        <p class="small text-muted mb-2" id="permHint">Usa los permisos por defecto del rol. Activa "Personalizar" para ajustarlos solo a este usuario.</p>
        <div id="permisos"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win" id="btnGuardarUsuario"><i class="bi bi-check2"></i>Guardar</button></div>
    </form>
  </div>
</div>

<!-- ===== Modal personal: solo datos personales ===== -->
<div class="modal fade" id="mPersonal" tabindex="-1" aria-labelledby="mPersonalTitulo" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content" id="formPersonal" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="mPersonalTitulo">Agregar personal</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="alert alert-danger d-none" id="pError"></div>
        <p class="small text-muted mb-3" id="pAyuda">Complete los datos de la persona. El turno queda en Día y la modalidad en Presencial; el administrador le dará acceso al sistema.</p>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="pDni">DNI *</label><input class="form-control" id="pDni" name="dni" maxlength="8" inputmode="numeric" required></div>
          <div class="col-md-4"><label class="form-label" for="pNombres">Nombres *</label><input class="form-control" id="pNombres" name="nombres" required></div>
          <div class="col-md-4"><label class="form-label" for="pApellidos">Apellidos *</label><input class="form-control" id="pApellidos" name="apellidos" required></div>
          <div class="col-md-4"><label class="form-label" for="pEmail">Correo *</label><input class="form-control" type="email" id="pEmail" name="email" maxlength="150" required></div>
          <div class="col-md-4"><label class="form-label" for="pTelPersonal">Teléfono personal</label><input class="form-control" id="pTelPersonal" name="telefono_personal"></div>
          <div class="col-md-4"><label class="form-label" for="pTelCorp">Teléfono corporativo</label><input class="form-control" id="pTelCorp" name="telefono_corporativo"></div>
          <div class="col-md-4"><label class="form-label" for="pArea">Área de trabajo</label><select class="form-select" id="pArea" name="area_trabajo"><option value="">—</option></select></div>
          <div class="col-md-4"><label class="form-label" for="pCargo">Cargo</label><select class="form-select" id="pCargo" name="cargo"><option value="">—</option></select></div>
          <div class="col-md-4 d-none" id="pWrapOrg"><label class="form-label" for="pOrg">Organización *</label><select class="form-select" id="pOrg" name="organizacion"><option value="directo">WIN · Personal directo</option><option value="partner">Partner (contrata)</option></select></div>
          <div class="col-md-4 d-none" id="pWrapPartner"><label class="form-label" for="pPartner">Partner *</label><select class="form-select" id="pPartner" name="partner_id"><option value="0">Seleccione el partner</option></select></div>
          <div class="col-md-4 d-none" id="pWrapZona"><label class="form-label" for="pZona" id="pZonaLabel">Zona de trabajo</label><select class="form-select" id="pZona" name="zona"><option value="">Seleccione la zona</option></select></div>
          <div class="col-12 d-none" id="pInfo"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button type="submit" class="btn btn-win" id="btnGuardarPersonal"><i class="bi bi-check2"></i>Guardar</button></div>
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
      <p class="small text-muted mb-0">Compártela por un medio seguro. Al ingresar, el sistema le pedirá que cree su propia contraseña.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="btnCopiarPw"><i class="bi bi-clipboard"></i>Copiar</button><button type="button" class="btn btn-win" data-bs-dismiss="modal">Listo</button></div>
  </div></div>
</div>

<script>
// Espera a que carguen Bootstrap y main.js (se incluyen al final de la página).
document.addEventListener('DOMContentLoaded', () => {
  const MI_ID = <?= $miId ?>;
  const ES_ADMIN = <?= $esAdminUsuarios ? 'true' : 'false' ?>, GESTIONA = <?= $gestionaPersonal ? 'true' : 'false' ?>;
  const ES_TOTAL = <?= $esAdminTotal ? 'true' : 'false' ?>; // false = líder con su equipo: sin contraseñas ni desbloqueos
  const ETQ = <?= json_encode(Auth::ETIQUETAS_ROL, JSON_UNESCAPED_UNICODE) ?>, etqRol = (r) => ETQ[r] || r; // nombre que se muestra
  const PUEDE_ACTIVAR = <?= Auth::esCuentaPrincipal($miId) ? 'true' : 'false' ?>; // solo la cuenta principal activa (2026-10-26)
  const $ = (s, el = document) => el.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (m, t = 'success') => (window.showToast ? window.showToast(m, t) : alert(m));
  let CAT = null;
  const mUsuario = new bootstrap.Modal('#mUsuario'), mPersonal = new bootstrap.Modal('#mPersonal'), mPassword = new bootstrap.Modal('#mPassword');

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
    $('#btnNuevoUsuario').classList.toggle('d-none', roles);
  }));

  // ---------- Catálogos ----------
  // Permisos por pantalla (2026-10-26): una fila por pantalla, en el orden del menú; editar, eliminar y opciones
  // dependen de "Ver" (data-requiere): sin Ver se desmarcan y se bloquean (el servidor aplica la misma regla).
  const SECCIONES = ['Indicadores', 'Supervisiones', 'Seguimiento', 'Observaciones', 'Personal', 'Otros'];
  const pantallas = () => {
    const p = {};
    CAT.permisos.forEach((x) => (p[x.grupo] = p[x.grupo] || { seccion: x.seccion, items: [] }).items.push(x));
    return SECCIONES.map((s) => [s, Object.entries(p).filter(([, v]) => v.seccion === s)]).filter(([, l]) => l.length);
  };
  const etiquetaCorta = (p) => {
    if (p.accion === 'ver') return 'Ver';
    const t = p.etiqueta.replace(/^(Ver|Editar|Eliminar|Global):\s*/i, '');
    return t.charAt(0).toLocaleUpperCase('es') + t.slice(1);
  };
  function permisosHtml(prefix) {
    const COLS = [['ver', 'Ver'], ['editar', 'Editar'], ['eliminar', 'Eliminar'], [null, 'Opciones']];
    const casilla = (p) => `<div class="form-check"><input class="form-check-input" type="checkbox" name="permisos[]" value="${esc(p.clave)}" id="${prefix}_${esc(p.clave)}"${p.requiere ? ` data-requiere="${esc(p.requiere)}"` : ''}><label class="form-check-label" for="${prefix}_${esc(p.clave)}">${esc(etiquetaCorta(p))}</label></div>`;
    const filas = pantallas().map(([sec, lista]) => `<tr class="usr-perm-seccion"><td colspan="5">${esc(sec)}</td></tr>` + lista.map(([g, v]) => `<tr><td data-label="Pantalla" class="fw-bold">${esc(g)}</td>`
      + COLS.map(([a, t]) => { const ps = v.items.filter((p) => (p.accion || null) === a); return `<td data-label="${t}">${ps.length ? ps.map(casilla).join('') : '<span class="text-muted">—</span>'}</td>`; }).join('') + '</tr>').join('')).join('');
    return `<div class="table-responsive"><table class="table table-sm mb-0 sgs-tabla-tarjetas usr-perm-tabla"><thead><tr><th>Pantalla</th>${COLS.map(([, t]) => `<th>${t}</th>`).join('')}</tr></thead><tbody>${filas}</tbody></table></div>`;
  }
  function aplicarDependencias(cont) {
    const personal = $('#chkPersonalizar').checked;
    cont.querySelectorAll('input[name="permisos[]"]').forEach((c) => {
      const ver = c.dataset.requiere ? cont.querySelector(`input[value="${c.dataset.requiere}"]`) : null;
      const puede = !c.dataset.requiere || !!ver?.checked;
      if (!puede) c.checked = false;
      c.disabled = !personal || !puede;
      c.closest('.form-check')?.classList.toggle('is-bloqueado', !puede);
    });
  }
  const rolPermisos = (rol) => (CAT.roles.find((r) => r.rol === rol) || { permisos: [] }).permisos;
  const setChecks = (container, list) => container.querySelectorAll('input[name="permisos[]"]').forEach((c) => (c.checked = list.includes(c.value)));

  async function cargarCatalogos() {
    CAT = (await api('catalogos')).data;
    const rolOpts = CAT.roles.map((r) => `<option value="${esc(r.rol)}">${esc(etqRol(r.rol))}</option>`).join('');
    $('#fRol').innerHTML = '<option value="">Todos los roles</option>' + rolOpts;
    $('#wrapTodasZonas').classList.toggle('d-none', !!CAT.equipo); // el líder solo asigna sus zonas
    $('#fOrg').innerHTML = '<option value="">Todas las organizaciones</option><option value="directo">WIN Directo</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    const f = $('#formUsuario');
    f.rol.innerHTML = rolOpts;
    f.partner_id.innerHTML = '<option value="0">Seleccione…</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    llenarLista(f.area_trabajo, CAT.areas); llenarLista(f.cargo, CAT.cargos);
    $('#procesos').innerHTML = ['Todos', ...CAT.procesos].map((p, i) => `<input type="checkbox" class="btn-check" name="procesos[]" value="${esc(p)}" id="proc${i}"><label class="btn btn-outline-secondary" for="proc${i}">${esc(p)}</label>`).join('');
    $('#zonas').innerHTML = CAT.departamentos.map((d, i) => `<input type="checkbox" class="btn-check" name="zonas[]" value="${esc(d)}" id="z${i}"><label class="btn btn-outline-secondary" for="z${i}">${esc(d)}</label>`).join('');
    $('#permisos').innerHTML = permisosHtml('u');
    renderRoles();
  }

  // Listas fijas (área, cargo). Un valor guardado que no está en la lista (registros anteriores) se muestra igual.
  function llenarLista(select, opciones, valor = '') {
    if (!select) return;
    const extra = valor && !opciones.includes(valor) ? [valor] : [];
    select.innerHTML = '<option value="">—</option>' + [...extra, ...opciones].map((o) => `<option value="${esc(o)}">${esc(o)}${extra.includes(o) ? ' (anterior)' : ''}</option>`).join('');
    select.value = valor || '';
  }
  // "Todos" marca todos los procesos; si se desmarca uno, "Todos" se desmarca.
  document.getElementById('procesos')?.addEventListener('change', (ev) => {
    const caja = ev.target; if (!caja.matches('input[name="procesos[]"]')) return;
    const todas = [...document.querySelectorAll('#procesos input')];
    if (caja.value === 'Todos') todas.forEach((c) => (c.checked = caja.checked));
    else todas[0].checked = todas.slice(1).every((c) => c.checked);
  });

  // ---------- Usuarios ----------
  let estadoAcceso = '';
  // "2026-10-15 08:05:00" -> "15/10/2026 08:05"
  const fechaHora = (v) => { const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : String(v); };
  const iniciales = (n) => String(n || '').trim().split(/\s+/).filter(Boolean).map((x) => x[0]).slice(0, 2).join('').toUpperCase() || 'U';
  async function cargarUsuarios() {
    // Administración: estado de acceso elegido en los contadores; el resto ve solo al personal activo.
    const params = { q: $('#fQ').value.trim(), rol: $('#fRol')?.value || '', organizacion: $('#fOrg')?.value || '', estado: ES_ADMIN ? estadoAcceso : 'activos' };
    const body = $('#usrBody');
    try {
      const data = await api('listar', { params });
      const r = data.resumen || {};
      if (ES_ADMIN) {
        $('#kActivos').textContent = r.activos ?? 0; $('#kDirectos').textContent = r.directos ?? 0;
        $('#kPartners').textContent = r.partners ?? 0; $('#kAdmins').textContent = r.administradores ?? 0;
        document.querySelectorAll('#usrEstados [data-cuenta]').forEach((el) => { el.textContent = (data.conteos || {})[el.dataset.cuenta] ?? 0; });
      }
      const rows = data.usuarios || [];
      if ($('#usrContador')) $('#usrContador').textContent = `${rows.length} persona${rows.length === 1 ? '' : 's'}`;
      body.innerHTML = rows.length ? rows.map((u) => {
        const directo = !u.nombre_partner || ['WIN', 'WIN DIRECTO'].includes(String(u.nombre_partner).trim().toUpperCase());
        const activo = +u.activo === 1, yo = +u.id === MI_ID;
        return `<tr class="${activo ? '' : 'usr-row-off'}">
          <td><div class="d-flex align-items-center gap-2"><span class="usr-avatar">${esc(iniciales(u.nombre_completo))}</span><div><div class="usr-name">${esc(u.nombre_completo)}${yo ? ' <span class="badge bg-light">Tú</span>' : ''}</div><div class="usr-sub">${esc(u.usuario || '—')} · DNI ${esc(u.dni)}</div>${u.cargo || u.area_trabajo ? `<div class="usr-sub">${esc([u.cargo, u.area_trabajo].filter(Boolean).join(' · '))}</div>` : ''}</div></div></td>
          <td data-label="Rol y organización"><div class="usr-celda-doble"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(etqRol(u.rol))}</span><small class="usr-org">${directo ? 'WIN Directo' : esc(u.nombre_partner)}</small></div></td>
          <td data-label="Zonas">${u.todas_zonas ? 'Todas' : (u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : '—')}</td>
          ${!ES_ADMIN ? '' : `<td data-label="Estado"><div class="usr-celda-doble"><div class="usr-insignias">${u.sin_acceso ? '<span class="badge bg-warning" title="Registrado solo con datos personales: falta que el administrador le dé acceso">Sin acceso</span>' : (+u.por_activar === 1 && !activo ? '<span class="badge bg-warning" title="Espera que la cuenta principal lo active">Por activar</span>' : `<span class="badge ${activo ? 'bg-success' : 'bg-secondary'}">${activo ? 'Activo' : 'Inactivo'}</span>`)}${u.bloqueado ? ' <span class="badge bg-danger" title="3 intentos fallidos"><i class="bi bi-lock"></i> Bloqueado</span>' : ''}${u.debe_cambiar_password && !u.sin_acceso ? ' <span class="badge bg-warning" title="Al ingresar, el sistema le pedirá crear su propia contraseña">Cambiará su contraseña</span>' : ''}</div>
            <small class="usr-ingreso" title="Último ingreso">${u.ultimo_acceso ? '<i class="bi bi-box-arrow-in-right"></i> ' + esc(fechaHora(u.ultimo_acceso)) : 'Nunca ingresó'}</small></div></td>`}
          <td class="text-end"><div class="sgs-row-actions">${u.protegido && !yo ? '<span class="badge bg-secondary" title="Solo su titular puede verla o modificarla"><i class="bi bi-shield-lock me-1"></i>Cuenta protegida</span></div></td></tr>' : !ES_ADMIN ? (GESTIONA && u.rol !== 'Administrador'
            ? `<button class="sgs-icon-action" title="Editar datos personales" aria-label="Editar datos personales" data-personal="${u.id}"><i class="bi bi-pencil"></i></button>`
            : `<button class="sgs-icon-action" title="Ver ficha" aria-label="Ver ficha" data-ficha="${u.id}"><i class="bi bi-eye"></i></button>`) + '</div></td></tr>' : `
            <button class="sgs-icon-action" title="${u.sin_acceso ? 'Completar acceso' : 'Editar'}" aria-label="${u.sin_acceso ? 'Completar acceso' : 'Editar'}" data-edit="${u.id}"><i class="bi ${u.sin_acceso ? 'bi-person-gear' : 'bi-pencil'}"></i></button>
            ${u.bloqueado && ES_TOTAL ? `<button class="sgs-icon-action" title="Desbloquear" aria-label="Desbloquear usuario" data-unlock="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-unlock"></i></button>` : ''}
            ${ES_TOTAL ? `<button class="sgs-icon-action" title="Restablecer contraseña" aria-label="Restablecer contraseña" data-pw="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-key"></i></button>` : ''}
            ${yo || u.sin_acceso || (!activo && !PUEDE_ACTIVAR) ? '' : `<button class="sgs-icon-action ${activo ? 'sgs-icon-action--danger' : ''}" title="${activo ? 'Desactivar' : 'Activar'}" aria-label="${activo ? 'Desactivar' : 'Activar'}" data-state="${u.id}" data-to="${activo ? 0 : 1}" data-name="${esc(u.nombre_completo)}"><i class="bi ${activo ? 'bi-person-dash' : 'bi-person-check'}"></i></button>`}
          </div></td></tr>`}`;
      }).join('') : `<tr><td colspan="${ES_ADMIN ? 5 : 4}" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay personas con esos filtros.</td></tr>`;
    } catch (e) { body.innerHTML = `<tr><td colspan="${ES_ADMIN ? 5 : 4}" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; }
  }
  let tQ; $('#fQ').addEventListener('input', () => { clearTimeout(tQ); tQ = setTimeout(cargarUsuarios, 300); });
  ['#fRol', '#fOrg'].forEach((s) => $(s)?.addEventListener('change', cargarUsuarios));
  const marcarEstado = (valor) => {
    estadoAcceso = valor;
    document.querySelectorAll('#usrEstados [data-estado]').forEach((b) => b.classList.toggle('active', b.dataset.estado === valor));
  };
  $('#usrEstados')?.addEventListener('click', (ev) => { const b = ev.target.closest('[data-estado]'); if (b) { marcarEstado(b.dataset.estado); cargarUsuarios(); } });
  $('#fLimpiar').addEventListener('click', () => { $('#fQ').value = ''; if ($('#fRol')) $('#fRol').value = ''; if ($('#fOrg')) $('#fOrg').value = ''; marcarEstado(''); cargarUsuarios(); });

  // Formulario: reglas visibles
  const fU = $('#formUsuario');

  function syncForm() {
    const partner = fU.organizacion.value === 'partner';
    $('#wrapPartner').classList.toggle('d-none', !partner);
    // Llevan zonas el supervisor y el coordinador (líder: sin zonas ve todo); el jefe de operaciones ve todo su partner
    const rol = fU.rol.value, conZonas = ['Supervisor', 'Coordinador'].includes(rol);
    $('#wrapZonas').classList.toggle('d-none', !conZonas);
    $('#zonasLider').classList.toggle('d-none', rol !== 'Coordinador');
    const todas = $('#chkTodasZonas').checked;
    $('#zonas').querySelectorAll('input').forEach((c) => (c.disabled = todas));
    const personal = $('#chkPersonalizar').checked;
    if (!personal) setChecks($('#permisos'), rolPermisos(rol));
    aplicarDependencias($('#permisos'));
    $('#permHint').textContent = personal ? 'Permisos personalizados solo para este usuario.' : 'Usa los permisos por defecto del rol. Activa "Personalizar" para ajustarlos.';
  }
  ['rol', 'organizacion'].forEach((n) => fU[n].addEventListener('change', syncForm));

  $('#chkPersonalizar').addEventListener('change', syncForm);
  $('#permisos').addEventListener('change', (e) => { if (e.target.name === 'permisos[]') aplicarDependencias($('#permisos')); });
  $('#chkTodasZonas').addEventListener('change', syncForm);
  let zonaBase = '';
  $('#btnGenerarPw').addEventListener('click', () => {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz', n = '23456789', s = '#$%&*?';
    const pick = (src, k) => Array.from(crypto.getRandomValues(new Uint32Array(k)), (x) => src[x % src.length]).join('');
    fU.password.value = pick(a, 9) + pick(n, 3) + pick(s, 1);
    fU.password.dispatchEvent(new Event('input'));
  });

  function abrirUsuario(u = null) {
    fU.reset(); zonaBase = ''; $('#uError').classList.add('d-none'); fU.password.dispatchEvent(new Event('input'));
    fU.querySelectorAll('.is-invalid').forEach((x) => x.classList.remove('is-invalid'));
    fU.id.value = u ? u.id : 0;
    const completar = !!u && !u.usuario;   // personal registrado sin acceso: falta usuario y contraseña
    $('#mUsuarioTitulo').textContent = completar ? `Completar acceso · ${u.nombres} ${u.apellidos}` : (u ? `Editar · ${u.nombres} ${u.apellidos}` : 'Nuevo usuario');
    $('#lblPassword').textContent = u && !completar ? 'Nueva contraseña (opcional)' : 'Contraseña *';
    $('#hintPassword').textContent = completar ? 'Al guardar con contraseña, la persona queda activa y ya puede ingresar.' : (u ? 'Déjala vacía para mantener la actual.' : 'Compártela con el usuario por un medio seguro.');
    if (u) {
      ['dni', 'nombres', 'apellidos', 'email', 'telefono_personal', 'telefono_corporativo', 'usuario', 'area_trabajo', 'modalidad', 'turno'].forEach((k) => { if (fU[k]) fU[k].value = u[k] ?? ''; });
      fU.rol.value = u.rol; fU.organizacion.value = u.organizacion; fU.partner_id.value = u.partner_id || 0;
      llenarLista(fU.area_trabajo, CAT.areas, u.area_trabajo || ''); llenarLista(fU.cargo, CAT.cargos, u.cargo || '');
      const procs = u.procesos || [];
      document.querySelectorAll('#procesos input').forEach((c) => { c.checked = c.value === 'Todos' ? CAT.procesos.every((p) => procs.includes(p)) : procs.includes(c.value); });
      fU.querySelectorAll('input[name="zonas[]"]').forEach((c) => (c.checked = (u.zonas || []).includes(c.value)));
      $('#chkTodasZonas').checked = !!u.todas_zonas; zonaBase = (u.zonas || [])[0] || '';
      $('#chkPersonalizar').checked = !!u.permisos_personalizados;
    } else { fU.rol.value = 'Supervisor'; fU.organizacion.value = 'directo'; llenarLista(fU.area_trabajo, CAT.areas); llenarLista(fU.cargo, CAT.cargos); }
    syncForm();
    if (u && u.permisos_personalizados) { setChecks($('#permisos'), u.permisos || []); aplicarDependencias($('#permisos')); }
    mUsuario.show();
  }
  $('#btnNuevoUsuario')?.addEventListener('click', () => abrirUsuario());

  fU.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(fU), body = {};
    fd.forEach((v, k) => { if (k.endsWith('[]')) (body[k.slice(0, -2)] = body[k.slice(0, -2)] || []).push(v); else body[k] = v; });
    body.rol = fU.rol.value; body.permisos_personalizados = $('#chkPersonalizar').checked;
    body.pedir_cambio = $('#uPedirCambio').checked; // solo aplica si se escribe una contraseña

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
      else if (b.dataset.personal || b.dataset.ficha) { abrirPersonal((await api('ficha', { params: { id: b.dataset.personal || b.dataset.ficha } })).data, !!b.dataset.ficha); }
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

  // ---------- Roles y permisos ----------
  const etiquetaPermiso = (k) => (CAT.permisos.find((p) => p.clave === k) || { etiqueta: k }).etiqueta;
  function renderRoles() {
    $('#rolesSistema').innerHTML = CAT.roles.map((r) => `<div class="col-md-6 col-xl-4"><div class="card role-card is-system">
      <div class="d-flex justify-content-between align-items-center"><h5><i class="bi bi-shield-lock me-1"></i>${esc(etqRol(r.rol))}</h5><span class="badge bg-light">Sistema</span></div>
      <div class="role-meta"><span class="sgs-chip"><i class="bi bi-people"></i>${r.usuarios} activos</span><span class="sgs-chip"><i class="bi bi-check2-square"></i>${r.permisos.length} permisos</span></div>
      ${r.descripcion ? `<p class="small mb-1">${esc(r.descripcion)}</p>` : ''}
      <div class="role-perms">${r.permisos.slice(0, 6).map((p) => esc(etiquetaPermiso(p))).join(' · ')}${r.permisos.length > 6 ? ` · y ${r.permisos.length - 6} más` : ''}</div>
    </div></div>`).join('');
    // Matriz: filas = módulos (grupos con permisos de ver/editar/eliminar), columnas = roles
    const ACC = [['ver', 'Ver'], ['editar', 'Editar'], ['eliminar', 'Eliminar']];
    const conAccion = new Set(CAT.permisos.filter((p) => p.accion).map((p) => p.grupo));
    const celda = (rol, grupo) => ACC.map(([a, txt]) => {
      const perms = CAT.permisos.filter((p) => p.grupo === grupo && p.accion === a);
      if (!perms.length) return '';
      const tiene = perms.filter((p) => rol.permisos.includes(p.clave));
      return `<span class="perm-acc is-${a}${tiene.length ? '' : ' is-no'}" title="${esc(perms.map((p) => `${rol.permisos.includes(p.clave) ? 'Sí' : 'No'}: ${p.etiqueta}`).join('\n'))}">${tiene.length ? '' : '<s>'}${txt}${tiene.length ? '' : '</s>'}</span>`;
    }).join('');
    $('#matrizPermisos').innerHTML = `<thead><tr><th>Pantalla</th>${CAT.roles.map((r) => `<th>${esc(etqRol(r.rol))}</th>`).join('')}</tr></thead>`
      + `<tbody>${pantallas().map(([sec, lista]) => `<tr class="usr-perm-seccion"><td colspan="${CAT.roles.length + 1}">${esc(sec)}</td></tr>` + lista.filter(([g]) => conAccion.has(g)).map(([g]) => `<tr><td data-label="Pantalla" class="fw-bold">${esc(g)}</td>${CAT.roles.map((r) => `<td data-label="${esc(etqRol(r.rol))}"><div class="perm-accs">${celda(r, g)}</div></td>`).join('')}</tr>`).join('')).join('')}</tbody>`;
  }

  // ---------- Personal: solo datos personales (agregar, editar o ver ficha) ----------
  const fP = $('#formPersonal');
  let ZONAS = { zonas: [], es_partner: true, partners: [] };
  async function cargarZonasPropias() {
    ZONAS = (await api('zonas_propias')).data;
    llenarLista($('#pArea'), ZONAS.areas || []); llenarLista($('#pCargo'), ZONAS.cargos || []);
    $('#pZona').innerHTML = '<option value="">Seleccione la zona</option>' + ZONAS.zonas.map((z) => `<option value="${esc(z)}">${esc(z)}</option>`).join('');
    $('#pPartner').innerHTML = '<option value="0">Seleccione el partner</option>' + (ZONAS.partners || []).map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
  }
  function syncPersonal() {
    const nuevo = +fP.id.value === 0;
    $('#pWrapOrg').classList.toggle('d-none', !nuevo || ZONAS.es_partner);
    $('#pWrapPartner').classList.toggle('d-none', !nuevo || ZONAS.es_partner || fP.organizacion.value !== 'partner');
    $('#pWrapZona').classList.toggle('d-none', !nuevo);
    $('#pZonaLabel').textContent = ZONAS.es_partner ? 'Zona de trabajo *' : 'Zona de trabajo';
  }
  fP.organizacion.addEventListener('change', syncPersonal);
  function abrirPersonal(u = null, soloVer = false) {
    fP.reset(); fP.id.value = u ? u.id : 0; $('#pError').classList.add('d-none');
    ['dni', 'nombres', 'apellidos', 'email', 'telefono_personal', 'telefono_corporativo'].forEach((k) => { fP[k].value = u ? (u[k] ?? '') : ''; });
    llenarLista(fP.area_trabajo, ZONAS.areas || [], u?.area_trabajo || ''); llenarLista(fP.cargo, ZONAS.cargos || [], u?.cargo || '');
    $('#mPersonalTitulo').textContent = soloVer ? (u?.nombres ? `${u.nombres} ${u.apellidos}` : 'Ficha') : (u ? `Editar datos · ${u.nombres} ${u.apellidos}` : 'Agregar personal');
    $('#pAyuda').classList.toggle('d-none', soloVer || !!u);
    fP.querySelectorAll('input:not([type=hidden]),select').forEach((c) => (c.disabled = soloVer));
    $('#btnGuardarPersonal').classList.toggle('d-none', soloVer);
    const info = $('#pInfo');
    info.classList.toggle('d-none', !u);
    info.innerHTML = u ? `<div class="d-flex flex-wrap gap-2"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(etqRol(u.rol))}</span><span class="sgs-chip">${esc(u.nombre_partner || 'WIN Directo')}</span><span class="sgs-chip">${u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : 'Todas las zonas'}</span></div>` : '';
    syncPersonal();
    mPersonal.show();
  }
  $('#btnNuevoPersonal')?.addEventListener('click', () => abrirPersonal());
  fP.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = Object.fromEntries(new FormData(fP).entries());
    const btn = $('#btnGuardarPersonal'); btn.disabled = true;
    try { const r = await api('guardar_personal', { method: 'POST', body }); mPersonal.hide(); toast(r.message); cargarUsuarios(); }
    catch (e) { $('#pError').textContent = e.message; $('#pError').classList.remove('d-none'); if (e.field && fP[e.field]) fP[e.field].focus(); }
    finally { btn.disabled = false; }
  });

  if (ES_ADMIN) cargarCatalogos().then(cargarUsuarios).catch((e) => toast(e.message, 'error'));
  else { cargarUsuarios(); if (GESTIONA) cargarZonasPropias().catch((e) => toast(e.message, 'error')); }
});
</script>
