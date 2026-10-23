<?php
// Pendientes y rescates (2026-10-20): dashboard de las cancelaciones cargadas por Excel y qué pasó con ellas (pendientes,
// en validación, en rescate, rescatadas, cancelación correcta y transferidas a partner), con indicadores por supervisor
// directo. Es el único lugar con esta información (antes se repetía en el Dashboard de supervisiones). El cumplimiento
// de cada supervisor de partner está en el Dashboard de partners (todas las órdenes que le transfieren, no solo estas).
// Administración ve todo; el supervisor directo, su zona.
// Datos: api/dashboard_pendientes.php (totales calculados en la base; tabla de 50 en 50). Pantalla: assets/js/
// dashboard_pendientes.js con las piezas comunes de sgs-dashboard.js (las mismas del dashboard de Exámenes).
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
if (!Auth::puede(Auth::PERM_PENDIENTES_VIEW, $user)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver Pendientes y rescates.</div>';
    return;
}
$pdo = Database::getConnection();
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY nombre_partner")->fetchAll();
$zonasUsuario = $esAdmin ? null : sgsUserZones((int)$user['id']);
$zonas = $pdo->query("SELECT DISTINCT departamento FROM supervisiones WHERE es_cancelacion = 1 AND origen_importacion = 1 AND departamento <> '' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
if ($zonasUsuario !== null) $zonas = array_values(array_filter($zonas, static fn($z) => in_array(mb_strtoupper(trim($z), 'UTF-8'), $zonasUsuario, true)));
$directos = $pdo->query("SELECT u.id, u.nombre_completo FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id
    WHERE u.rol = 'Supervisor' AND u.activo = 1 AND " . sgsDirectStaffSql() . ' ORDER BY u.nombre_completo')->fetchAll();
$kpis = [
    ['cargadas', 'Cargadas', 'bi-cloud-arrow-up', ''], ['pendiente', 'Pendientes sin tomar', 'bi-hourglass-split', 'pendientes_texto'],
    ['en_rescate', 'En rescate', 'bi-life-preserver', ''], ['rescatada', 'Rescatadas', 'bi-arrow-counterclockwise', ''],
    ['correcta', 'Cancelación correcta', 'bi-check2-square', ''], ['en_validacion', 'En validación', 'bi-search', ''],
    ['en_partner', 'En partner', 'bi-buildings', 'vencidas_texto'], ['partner_atendida', 'Atendidas por partner', 'bi-building-check', ''],
    ['pct_rescate', '% de rescate', 'bi-graph-up-arrow', 'pct_rescate_texto'], ['horas_promedio', 'Tiempo de atención', 'bi-stopwatch', 'horas_texto'],
];
$graficos = [
    ['dpdEmbudo', 'bi-funnel', 'Embudo', 'De las cargadas, cuántas atendió un supervisor, cuántas pasaron a rescate y cuántas se rescataron.', 'barras', 'col-lg-6'],
    ['dpdAntiguedad', 'bi-clock-history', 'Antigüedad de las pendientes', 'Pendientes que nadie tomó todavía, según cuánto tiempo llevan cargadas.', 'columnas', 'col-lg-6'],
    ['dpdZona', 'bi-geo-alt', 'Cargadas por zona', 'Cuántas se cargaron en cada zona y cuántas siguen pendientes.', 'barras', 'col-lg-6'],
    ['dpdPartner', 'bi-building', '% de rescate por partner', 'De las cerradas, cuántas se rescataron.', 'barras', 'col-lg-6'],
    ['dpdMotivos', 'bi-tags', 'Motivos de cancelación', 'Los motivos más frecuentes en las cargas.', 'barras', 'col-lg-6'],
    ['dpdRescates', 'bi-person-check', 'Rescates por supervisor', 'Servicios rescatados por cada responsable.', 'barras', 'col-lg-6'],
    ['dpdDias', 'bi-calendar3', 'Cargas por día', 'Últimos 31 días con cargas, según los filtros.', 'columnas dsh-columnas--dias', 'col-12'],
];
?>
<div class="container-fluid p-3 p-md-4 dsh" id="dpd">
  <div class="sgs-page-actions">
    <span class="sgs-counter" id="dpdContador">Cargando…</span>
    <a class="btn btn-outline-secondary" id="dpdExcel" href="api/dashboard_pendientes.php?action=dashboard_excel" data-base="api/dashboard_pendientes.php?action=dashboard_excel"><i class="bi bi-file-earmark-arrow-down me-1"></i>Descargar Excel</a>
    <a class="btn btn-win" href="index.php?page=supervision_lista"><i class="bi bi-journal-check me-1"></i>Ir a las pendientes</a>
  </div>

  <form class="card sgs-toolbar-card p-3 mb-3" id="dpdFiltros">
    <div class="sgs-filtros">
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="dpdDesde">Cargadas desde</label><input class="form-control" type="date" id="dpdDesde" name="desde"></div>
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="dpdHasta">Hasta</label><input class="form-control" type="date" id="dpdHasta" name="hasta"></div>
      <div class="sgs-filtro"><label class="form-label" for="dpdPartnerF">Partner</label>
        <select class="form-select" id="dpdPartnerF" name="partner_id"><option value="">Todos los partners</option>
          <?php foreach ($partners as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['nombre_partner']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="dpdZonaF">Zona</label>
        <select class="form-select" id="dpdZonaF" name="zona"><option value="">Todas las zonas</option>
          <?php foreach ($zonas as $z): ?><option value="<?= $e($z) ?>"><?= $e($z) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="dpdSupervisor">Supervisor directo</label>
        <select class="form-select" id="dpdSupervisor" name="supervisor_id"><option value="">Todos</option>
          <?php foreach ($directos as $d): ?><option value="<?= (int)$d['id'] ?>"><?= $e($d['nombre_completo']) ?></option><?php endforeach; ?>
        </select></div>
      <button type="reset" class="btn btn-outline-secondary sgs-filtro-limpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
    </div>
    <?php if ($zonasUsuario !== null): ?><small class="text-muted mt-2"><i class="bi bi-geo-alt me-1 text-primary"></i>Se muestran las pendientes de su zona: <strong><?= $e($zonasUsuario ? implode(', ', $zonasUsuario) : 'sin zona asignada') ?></strong>.</small><?php endif; ?>
  </form>

  <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-3 dsh-kpis">
    <?php foreach ($kpis as [$clave, $texto, $icono, $detalle]): ?>
    <div class="col"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi <?= $icono ?>"></i></span><small class="sgs-kpi-label"><?= $e($texto) ?></small>
      <h3 class="mb-0 sgs-kpi-value" data-kpi="<?= $clave ?>">—</h3><?php if ($detalle): ?><small class="text-muted" data-kpi="<?= $detalle ?>"></small><?php endif; ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-3">
    <?php foreach ($graficos as [$id, $icono, $titulo, $ayuda, $tipo, $col]): ?>
    <div class="<?= $col ?>"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi <?= $icono ?> me-2 text-primary"></i><?= $e($titulo) ?></h5>
      <p class="text-muted small mb-3"><?= $e($ayuda) ?></p>
      <div class="dsh-<?= $tipo ?>" id="<?= $id ?>"></div>
    </div></div>
    <?php endforeach; ?>
  </div>

  <div class="card p-3 p-md-4 mb-3">
    <h5 class="mb-1"><i class="bi bi-person-check me-2 text-primary"></i>Por supervisor directo</h5>
    <p class="text-muted small mb-3">Las que tomó cada uno. Si la transfirió a un partner sigue siendo suya: se ve si el partner la atendió o se le venció.<?php if ($esAdmin): ?>
      El cumplimiento de cada supervisor de partner está en el <a href="index.php?page=dashboard_partners">Dashboard de partners</a>.<?php endif; ?></p>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
      <thead><tr><th>Supervisor</th><th class="text-end">Tomadas</th><th class="text-end">Atendió él</th><th class="text-end">En validación</th><th class="text-end">En rescate</th>
        <th class="text-end">Rescatadas</th><th class="text-end">Transferidas</th><th class="text-end">Atendió el partner</th><th class="text-end">Vencidas en partner</th><th class="text-end">Tiempo</th><th class="text-end">Atención</th></tr></thead>
      <tbody id="dpdDirectos"><tr><td colspan="11" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
    </table></div>
  </div>

  <div class="card p-3 p-md-4">
    <div class="sgs-toolbar mb-2">
      <div class="sgs-segmented" role="group" aria-label="Estado" id="dpdEstados">
        <?php foreach (['' => 'Todas', 'pendiente' => 'Pendientes', 'en_validacion' => 'En validación', 'en_rescate' => 'En rescate', 'rescatada' => 'Rescatadas', 'correcta' => 'Cancelación correcta', 'en_partner' => 'En partner', 'partner_atendida' => 'Atendidas por partner'] as $clave => $texto): ?>
        <button type="button" class="filter-tab-btn <?= $clave === '' ? 'active' : '' ?>" data-estado="<?= $clave ?>"><?= $texto ?> <span class="sgs-seg-count" data-cuenta="<?= $clave ?>">0</span></button>
        <?php endforeach; ?>
      </div>
      <div class="sgs-filtro sgs-filtro--ancho dsh-busqueda"><label class="visually-hidden" for="dpdBuscar">Buscar</label>
        <div class="sgs-filtro-busqueda"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" id="dpdBuscar" placeholder="Pedido, partner o motivo"></div></div>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
      <thead><tr><th>Pedido o ticket</th><th>Zona y partner</th><th>Motivo de cancelación</th><th>Responsable</th><th>Estado</th></tr></thead>
      <tbody id="dpdTabla"><tr><td colspan="5" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
    </table></div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" id="dpdPaginas"></div>
  </div>
  <div class="dsh-tip" id="dpdTip" role="tooltip" hidden></div>
</div>
<script src="03_FRONTEND/src/assets/js/sgs-dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="03_FRONTEND/src/assets/js/dashboard_pendientes.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
