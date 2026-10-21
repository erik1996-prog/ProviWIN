<?php
// Clima (2026-10-20): indicador de las lluvias por zona (menú Indicadores → Clima). Cada lluvia: inicio = el primer supervisor que la avisó, fin =
// el que la cerró, con qué hacía cada uno. Días con lluvia, horas por zona, calendario del mes y bitácora de cada día.
// Datos: api/dashboard_lluvias.php. Pantalla: assets/js/dashboard_lluvias.js con las piezas comunes de sgs-dashboard.js.
// Administración ve todas las zonas; el supervisor directo, las suyas.
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
if (!$esAdmin && !(($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']))) {
    echo '<div class="alert alert-danger m-4">Acceso reservado al personal administrativo y a los supervisores directos.</div>';
    return;
}
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$zonas = ($esAdmin ? null : sgsUserZones((int)$user['id'])) ?? sgsOperationalDepartments();
sort($zonas);
$kpis = [
    ['dias', 'Días con lluvia', 'bi-calendar-event'], ['lluvias', 'Lluvias', 'bi-cloud-rain-heavy'], ['horas', 'Horas de lluvia', 'bi-stopwatch'],
    ['promedio', 'Duración promedio', 'bi-hourglass-split'], ['zona_mas', 'Zona con más lluvia', 'bi-geo-alt'],
];
?>
<div class="container-fluid p-3 p-md-4 dsh" id="dll">
  <div class="sgs-page-actions">
    <span class="sgs-counter" id="dllContador">Cargando…</span>
  </div>

  <form class="card sgs-toolbar-card p-3 mb-3" id="dllFiltros">
    <div class="sgs-filtros">
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="dllMes">Mes</label><input class="form-control" type="month" id="dllMes" name="mes" value="<?= date('Y-m') ?>"></div>
      <div class="sgs-filtro"><label class="form-label" for="dllZona">Zona</label>
        <select class="form-select" id="dllZona" name="zona"><option value="">Todas las zonas</option>
          <?php foreach ($zonas as $z): ?><option value="<?= $e($z) ?>"><?= $e($z) ?></option><?php endforeach; ?>
        </select></div>
      <button type="reset" class="btn btn-outline-secondary sgs-filtro-limpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
    </div>
  </form>

  <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-3 dsh-kpis">
    <?php foreach ($kpis as [$clave, $texto, $icono]): ?>
    <div class="col"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi <?= $icono ?>"></i></span><small class="sgs-kpi-label"><?= $e($texto) ?></small>
      <h3 class="mb-0 sgs-kpi-value<?= $clave === 'zona_mas' ? ' is-text' : '' ?>" data-kpi="<?= $clave ?>">—</h3></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi bi-calendar3 me-2 text-primary"></i>Calendario de lluvias</h5>
      <p class="text-muted small mb-3">Los días con lluvia salen en azul (más oscuro, más horas). Toque un día para ver su bitácora.</p>
      <div class="sgs-cal-lluvia" id="dllCalendario"></div>
    </div></div>
    <div class="col-lg-5"><div class="card p-3 p-md-4 h-100">
      <h5 class="mb-1"><i class="bi bi-geo-alt me-2 text-primary"></i>Horas de lluvia por zona</h5>
      <p class="text-muted small mb-3">Cuánto llovió en cada zona en el mes.</p>
      <div class="dsh-barras" id="dllZonas"></div>
    </div></div>
  </div>

  <div class="card p-3 p-md-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
      <div><h5 class="mb-1"><i class="bi bi-journal-text me-2 text-primary"></i>Bitácora</h5>
        <p class="text-muted small mb-0" id="dllBitacoraTitulo">Todas las lluvias del mes.</p></div>
      <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="dllVerMes"><i class="bi bi-calendar3 me-1"></i>Ver todo el mes</button>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
      <thead><tr><th>Fecha</th><th>Zona</th><th>Inicio (quién avisó)</th><th>Fin (quién cerró)</th><th class="text-end">Duración</th></tr></thead>
      <tbody id="dllBitacora"><tr><td colspan="5" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
    </table></div>
  </div>
  <div class="dsh-tip" id="dllTip" role="tooltip" hidden></div>
</div>
<script src="03_FRONTEND/src/assets/js/sgs-dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="03_FRONTEND/src/assets/js/dashboard_lluvias.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
