<?php
// Exámenes (2026-10-20): dashboard de los exámenes de los técnicos (link general de Cuadrillas).
// Indicadores, % de aprobación por partner y por zona, temas con más errores, notas obtenidas, exámenes por día y la tabla
// de resultados (con descarga a Excel). Filtros: fecha del link, examen, partner y zona. Administración ve todo; el
// supervisor directo, su zona. Datos: api/examenes.php (dashboard = totales calculados en la base; dashboard_tabla = 50 por
// página). Pantalla: assets/js/examenes.js.
// Gráficos de una sola serie (sin librerías): el % de aprobación lleva su texto ("8 de 10"); los colores de estado
// (aprobado / no aprobado) solo en las etiquetas de la tabla, siempre con su palabra (verde y rojo no se distinguen con daltonismo).
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative() && !sgsAlcanceLimitado($user); // líder con zonas: solo las suyas
if (!Auth::puede(Auth::PERM_EXAMENES_VIEW, $user)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver Exámenes.</div>';
    return;
}
require_once BACKEND_PATH . '/config/examen_motor.php';
$pdo = Database::getConnection();
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY nombre_partner")->fetchAll();
$zonasUsuario = $esAdmin ? null : sgsUserZones((int)$user['id']);
$zonas = $pdo->query("SELECT DISTINCT departamento FROM tecnicos WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
if ($zonasUsuario !== null) $zonas = array_values(array_filter($zonas, static fn($z) => in_array(mb_strtoupper(trim($z), 'UTF-8'), $zonasUsuario, true)));
$examenes = sgsExamenesDisponibles();
?>
<div class="container-fluid p-3 p-md-4 dsh" id="exd">
  <div class="sgs-page-actions">
    <span class="sgs-counter" id="exdContador">Cargando…</span>
    <a class="btn btn-outline-secondary" id="exdExcel" href="api/examenes.php?action=dashboard_excel" data-base="api/examenes.php?action=dashboard_excel"><i class="bi bi-file-earmark-arrow-down me-1"></i>Descargar Excel</a>
    <?php if (Auth::puede(Auth::PERM_EXAMENES_EDIT, $user) && Auth::puede(Auth::PERM_CUADRILLAS_VIEW, $user)): ?><a class="btn btn-win" href="index.php?page=cuadrillas"><i class="bi bi-link-45deg me-1"></i>Generar link de examen</a><?php endif; ?>
  </div>

  <form class="card sgs-toolbar-card p-3 mb-3" id="exdFiltros">
    <div class="sgs-filtros">
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="exdDesde">Link desde</label><input class="form-control" type="date" id="exdDesde" name="desde"></div>
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="exdHasta">Hasta</label><input class="form-control" type="date" id="exdHasta" name="hasta"></div>
      <div class="sgs-filtro"><label class="form-label" for="exdExamen">Examen</label>
        <select class="form-select" id="exdExamen" name="examen"><option value="">Todos los exámenes</option>
          <?php foreach ($examenes as $codigo => $b): ?><option value="<?= $e($codigo) ?>"><?= $e($b['titulo']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="exdPartner">Partner</label>
        <select class="form-select" id="exdPartner" name="partner_id"><option value="">Todos los partners</option>
          <?php foreach ($partners as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['nombre_partner']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="exdZona">Zona</label>
        <select class="form-select" id="exdZona" name="zona"><option value="">Todas las zonas</option>
          <?php foreach ($zonas as $z): ?><option value="<?= $e($z) ?>"><?= $e($z) ?></option><?php endforeach; ?>
          <option value="sin_zona">Sin zona</option>
        </select></div>
      <button type="reset" class="btn btn-outline-secondary sgs-filtro-limpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
    </div>
    <?php if ($zonasUsuario !== null): ?><small class="text-muted mt-2"><i class="bi bi-geo-alt me-1 text-primary"></i>Se muestran los técnicos de su zona: <strong><?= $e($zonasUsuario ? implode(', ', $zonasUsuario) : 'sin zona asignada') ?></strong>.</small><?php endif; ?>
  </form>

  <div class="row g-3 mb-3 dsh-kpis">
    <div class="col-6 col-lg"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-people"></i></span><small class="sgs-kpi-label">Convocados</small><h3 class="mb-0 sgs-kpi-value" data-kpi="convocados">—</h3></div></div>
    <div class="col-6 col-lg"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span><small class="sgs-kpi-label">Rindieron</small><h3 class="mb-0 sgs-kpi-value" data-kpi="rindieron">—</h3></div></div>
    <div class="col-6 col-lg"><div class="sgs-kpi p-3 h-100" data-kpi-caja="aprobacion"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-patch-check"></i></span><small class="sgs-kpi-label">Aprobación</small><h3 class="mb-0 sgs-kpi-value" data-kpi="pct_aprobacion">—</h3><small class="text-muted" data-kpi="aprobados_texto"></small></div></div>
    <div class="col-6 col-lg"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-bar-chart"></i></span><small class="sgs-kpi-label">Nota promedio</small><h3 class="mb-0 sgs-kpi-value" data-kpi="nota_promedio">—</h3></div></div>
    <div class="col-6 col-lg"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span><small class="sgs-kpi-label">Por rendir</small><h3 class="mb-0 sgs-kpi-value" data-kpi="por_rendir">—</h3><small class="text-muted" data-kpi="no_rindieron_texto"></small></div></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi bi-building me-2 text-primary"></i>Aprobación por partner</h5>
      <p class="text-muted small mb-3">% de técnicos que aprobaron, de los que rindieron.</p>
      <div class="dsh-barras" id="exdPartner_g"></div>
    </div></div>
    <div class="col-lg-6"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi bi-geo-alt me-2 text-primary"></i>Aprobación por zona</h5>
      <p class="text-muted small mb-3">% de técnicos que aprobaron, de los que rindieron.</p>
      <div class="dsh-barras" id="exdZona_g"></div>
    </div></div>
    <div class="col-lg-6"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi bi-exclamation-triangle me-2 text-primary"></i>Temas con más errores</h5>
      <p class="text-muted small mb-3">% de respuestas incorrectas en cada tema: lo que más hay que reforzar en las charlas.</p>
      <div class="dsh-barras" id="exdTemas_g"></div>
    </div></div>
    <div class="col-lg-6"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi bi-reception-4 me-2 text-primary"></i>Notas obtenidas</h5>
      <p class="text-muted small mb-3">Cuántos técnicos sacaron cada rango de nota. Se aprueba desde 90 %.</p>
      <div class="dsh-columnas" id="exdNotas_g"></div>
    </div></div>
    <div class="col-12"><div class="card p-3 p-md-4">
      <h5 class="mb-1"><i class="bi bi-calendar3 me-2 text-primary"></i>Exámenes rendidos por día</h5>
      <p class="text-muted small mb-3">Últimos 31 días con exámenes, según los filtros.</p>
      <div class="dsh-columnas dsh-columnas--dias" id="exdDias_g"></div>
    </div></div>
  </div>

  <div class="card p-3 p-md-4">
    <div class="sgs-toolbar mb-2">
      <div class="sgs-segmented" role="group" aria-label="Resultado" id="exdEstados">
        <button type="button" class="filter-tab-btn active" data-estado="">Todos <span class="sgs-seg-count" data-cuenta="">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="aprobado">Aprobados <span class="sgs-seg-count" data-cuenta="aprobado">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="desaprobado">No aprobados <span class="sgs-seg-count" data-cuenta="desaprobado">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="por_rendir">Por rendir <span class="sgs-seg-count" data-cuenta="por_rendir">0</span></button>
        <button type="button" class="filter-tab-btn" data-estado="no_rindio">No rindieron <span class="sgs-seg-count" data-cuenta="no_rindio">0</span></button>
      </div>
      <div class="sgs-filtro sgs-filtro--ancho dsh-busqueda"><label class="visually-hidden" for="exdBuscar">Buscar técnico</label>
        <div class="sgs-filtro-busqueda"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" id="exdBuscar" placeholder="Técnico, DNI o partner"></div></div>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
      <thead><tr><th>Técnico</th><th>Partner y zona</th><th>Examen</th><th>Rendido</th><th class="text-end">Nota</th><th>Resultado</th></tr></thead>
      <tbody id="exdTabla"><tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
    </table></div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" id="exdPaginas"></div>
  </div>
  <div class="dsh-tip" id="exdTip" role="tooltip" hidden></div>
</div>
<script src="03_FRONTEND/src/assets/js/sgs-dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="03_FRONTEND/src/assets/js/examenes.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
