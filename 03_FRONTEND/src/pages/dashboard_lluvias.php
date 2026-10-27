<?php
// Clima (2026-10-20): indicador de las lluvias por zona (menú Indicadores → Clima). Cada lluvia: inicio = el primer supervisor que la avisó, fin =
// el que la cerró, con qué hacía cada uno. Línea de tiempo por zona (solo lluvias), días con lluvia, horas por zona,
// calendario del mes, bitácora de cada día y matriz mensual.
// Datos: api/dashboard_lluvias.php. Pantalla: assets/js/dashboard_lluvias.js con las piezas comunes de sgs-dashboard.js.
// Administración ve todas las zonas; el supervisor directo, las suyas.
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
if (!Auth::puede(Auth::PERM_CLIMA_VIEW, $user)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver Clima.</div>';
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
<div class="container-fluid p-3 p-md-4 dsh" id="dll" data-puede-eliminar="<?= Auth::puede(Auth::PERM_CLIMA_DELETE, $user) ? '1' : '0' ?>">
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

  <div class="card p-3 p-md-4 mb-3">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
      <div><h5 class="mb-1"><i class="bi bi-calendar-range me-2 text-primary"></i>Línea de tiempo de lluvias</h5>
        <p class="text-muted small mb-0">Cuándo llovió en cada zona, hora por hora. Pase el cursor sobre una lluvia para ver quién la avisó y quién la cerró.</p></div>
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="dllDiaTl">Día</label><input class="form-control" type="date" id="dllDiaTl"></div>
    </div>
    <div class="sgs-gantt" id="dllTimeline"></div>
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
  <div class="card p-3 p-md-4 mt-3 sgs-matriz-card" id="dllMatrizCard">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
      <div><h5 class="mb-1"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Matriz mensual de lluvias</h5>
        <p class="text-muted small mb-0">Horas de lluvia por zona y por día del mes (horas:minutos).</p></div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="sgs-en-vivo" id="dllEnVivo"><i aria-hidden="true"></i>En vivo</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="dllPantalla"><i class="bi bi-arrows-fullscreen me-1"></i>Pantalla completa</button>
        <?php if (Auth::isAdministrative()): ?><button type="button" class="btn btn-outline-secondary btn-sm" id="dllEnviarCorte" title="Publica ahora en el grupo de Teams las lluvias de los últimos 7 días; desde las 12:00 incluye hoy (también sale sola en los horarios programados)"><i class="bi bi-send me-1"></i>Enviar corte a Teams</button>
        <?php endif; ?>
        <?php if (Auth::isAdministrative() && Auth::esCuentaPrincipal((int)$user['id'])): // solo la cuenta principal programa (2026-10-26) ?><button type="button" class="btn btn-outline-secondary btn-sm" id="dllProgramar"><i class="bi bi-clock me-1"></i>Programar envíos</button><?php endif; ?>
      </div>
    </div>
    <div class="matriz-scroll-box"><table class="table-matriz align-middle" id="dllMatriz"><tbody><tr><td class="text-muted py-3">Cargando…</td></tr></tbody></table></div>
  </div>

  <div class="dsh-tip" id="dllTip" role="tooltip" hidden></div>
</div>
<?php if (Auth::isAdministrative() && Auth::esCuentaPrincipal((int)$user['id'])): ?>
<!-- Envíos programados del corte de lluvias (2026-10-23, tabla lluvias_cortes_programados; los envía 07_SCRIPTS/corte_lluvias.php) -->
<div class="modal fade" id="mCortes" tabindex="-1" aria-labelledby="mCortesTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mCortesTitulo"><i class="bi bi-clock me-2 text-primary"></i>Programar envíos del corte</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Cada día, a estas horas (hora de Lima), se publica en el grupo de Teams el corte de lluvias de los últimos 7 días: los envíos de la mañana llegan hasta ayer y los de las 12:00 en adelante incluyen el día. Sale con el primer uso del sistema desde esa hora (o con la tarea programada del hosting): puede llegar unos minutos después.</p>
        <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
          <thead><tr><th>Hora</th><th>Último envío</th><th>Activo</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr></thead>
          <tbody id="mCortesLista"><tr><td colspan="4" class="text-muted">Cargando…</td></tr></tbody>
        </table></div>
        <h6 class="mt-4 mb-2">Agregar un envío</h6>
        <div class="row g-2 align-items-end">
          <div class="col-6 col-sm-4"><label class="form-label" for="mCortesHora">Hora</label><input class="form-control" type="time" id="mCortesHora" value="08:00"></div>
          <div class="col-6 col-sm-5"><button type="button" class="btn btn-win w-100 text-nowrap" id="mCortesAgregar"><i class="bi bi-plus-lg me-1"></i>Agregar envío</button></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<script src="03_FRONTEND/src/assets/js/sgs-dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="03_FRONTEND/src/assets/js/dashboard_lluvias.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
