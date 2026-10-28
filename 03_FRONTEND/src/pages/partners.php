<?php
// Partners (2026-10-15): registro de contratas. Datos y reglas en api/partners.php.
// Permisos (2026-10-23, Roles y permisos): ver, editar (crear, renombrar, activar/desactivar) y eliminar.
Auth::requireLogin();
$puedeEditarP = Auth::puede(Auth::PERM_PARTNERS_EDIT);
$puedeEliminarP = Auth::puede(Auth::PERM_PARTNERS_DELETE);
if (!Auth::puede(Auth::PERM_PARTNERS_VIEW)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver partners.</div>';
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
        <?php if ($puedeEditarP): ?><button type="button" class="btn btn-win" id="btnNuevoPartner"><i class="bi bi-plus-lg me-1"></i>Nuevo partner</button><?php endif; ?>
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

<script>window.SGS_PARTNERS = <?= json_encode(['editar' => $puedeEditarP, 'eliminar' => $puedeEliminarP]) ?>;</script>
<script src="03_FRONTEND/src/assets/js/partners.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
