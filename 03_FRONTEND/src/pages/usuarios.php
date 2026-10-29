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
            <div class="form-text" id="uAreaAyuda">Instalaciones o VT (visitas técnicas): el supervisor solo ve las pendientes de su área. Con ambas, ve todas.</div></div>
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
          <p class="form-text mt-0 mb-2 d-none" id="zonasLider">Líder (Gestión): sin zonas ve todo; con zonas, solo esas (y solo su área de trabajo, si es Instalaciones o VT).</p>
          <div class="form-check form-switch mb-2" id="wrapTodasZonas"><input class="form-check-input" type="checkbox" role="switch" id="chkTodasZonas" name="todas_zonas" value="1"><label class="form-check-label" for="chkTodasZonas">Todas las zonas</label></div>
          <div class="usr-zonas mb-3" id="zonas"></div>
        </div>

        <div class="alert alert-warning small d-none" id="sinAccesoAviso">Con este cargo la persona queda registrada <strong>sin acceso</strong> al sistema: solo se guardan sus datos.</div>
        <div id="wrapAcceso">
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

<script>window.SGS_USUARIOS = <?= json_encode([
    'miId' => $miId, 'esAdmin' => $esAdminUsuarios, 'gestiona' => $gestionaPersonal, 'esTotal' => $esAdminTotal,
    'etiquetasRol' => Auth::ETIQUETAS_ROL, 'puedeActivar' => Auth::esCuentaPrincipal($miId),
    // "Ver como" (2026-10-28): solo el administrador general (cuenta principal), y no mientras ya ve como otro.
    'verComo' => Auth::hasRole([Auth::ROLE_ADMINISTRADOR]) && Auth::esCuentaPrincipal($miId) && !Auth::verComoActivo(),
], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="03_FRONTEND/src/assets/js/usuarios.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
