<?php
// Inicio de órdenes (2026-11-19, pedido del usuario): solo la cuenta principal. Pasa a ProviWIN la versión Python
// (C:\xampp\htdocs\inicio-ordenes, que queda como respaldo). Dos vistas:
//   · Primera atención: por cuadrilla, su primera atención del día con la regla del dashboard de recableados (Cumple ≤ 08:05:59,
//     Seguimiento 08:06–08:10:59, Incumple ≥ 08:11), indicadores, avance, inicios cada 15 min y tabla.
//   · Órdenes en curso: En camino o Iniciadas ahora, con su duración en vivo (Iniciada 2 h–3 h amarillo, 3 h o más rojo; En camino
//     más de 40 min rojo; la 1ra atención se alerta si pasa las 08:10 sin iniciar).
// Datos: api/inicio_ordenes.php (Winbo en vivo, solo GESTIÓN PROVINCIA, nada se guarda). Pantalla: assets/js/inicio_ordenes.js.
if (!Auth::puedeVincularPhoenix(Auth::getLoggedInUser())) {
    echo '<div class="alert alert-danger m-4">Solo la cuenta principal puede ver Inicio de órdenes.</div>';
    return;
}
$kpisPrimera = [
    ['ioKTotal', '', 'bi-people', 'Cuadrillas del día', 'con órdenes agendadas hasta 07:30'],
    ['ioKIni', 'sgs-kpi--success', 'bi-play-circle', 'Iniciaron su 1ra atención', ''],
    ['ioKCam', 'sgs-kpi--warning', 'bi-truck', 'En camino', ''],
    ['ioKSin', 'sgs-kpi--danger', 'bi-hourglass-split', 'Sin iniciar', ''],
];
$kpisCurso = [
    ['ioCCam', 'sgs-kpi--warning', 'bi-truck', 'En camino', ''],
    ['ioCIni', 'sgs-kpi--success', 'bi-play-circle', 'Iniciadas', 'hasta 2 h: normal'],
    ['ioCAma', 'sgs-kpi--warning', 'bi-exclamation-triangle', 'Iniciadas 2 h a 3 h', 'amarillo'],
    ['ioCRoj', 'sgs-kpi--danger', 'bi-alarm', 'Iniciadas 3 h o más', 'rojo · alertar al partner'],
];
$kpis = static function (array $lista): void {
    foreach ($lista as [$id, $clase, $icono, $texto, $sub]): ?>
    <div class="col-6 col-lg-3"><div class="sgs-kpi <?= $clase ?> p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi <?= $icono ?>"></i></span>
      <small class="sgs-kpi-label"><?= htmlspecialchars($texto) ?></small>
      <h3 class="mb-0 sgs-kpi-value" id="<?= $id ?>">0</h3><small class="text-muted" id="<?= $id ?>Sub"><?= htmlspecialchars($sub) ?></small>
    </div></div>
    <?php endforeach;
};
$segmento = static function (string $id, string $etiqueta, array $opciones): void { ?>
    <div class="sgs-segmented" role="group" aria-label="<?= $etiqueta ?>" id="<?= $id ?>">
      <?php foreach ($opciones as $valor => $texto): ?><button type="button" class="filter-tab-btn<?= $valor === '' ? ' active' : '' ?>" data-v="<?= $valor ?>"><?= $texto ?></button><?php endforeach; ?>
    </div>
<?php };
$filtros = static function (string $pre, string $buscar, string $ayuda): void { ?>
    <div class="sgs-toolbar-row"><div class="sgs-filtros">
      <div class="sgs-filtro"><label class="form-label" for="<?= $pre ?>Prov">Partner</label><select class="form-select" id="<?= $pre ?>Prov"><option value="">Todos</option></select></div>
      <div class="sgs-filtro"><label class="form-label" for="<?= $pre ?>Dep">Departamento</label><select class="form-select" id="<?= $pre ?>Dep"><option value="">Todos</option></select></div>
      <div class="sgs-filtro sgs-filtro--ancho"><label class="form-label" for="<?= $pre ?>Txt"><?= $buscar ?></label>
        <div class="sgs-filtro-busqueda"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="<?= $pre ?>Txt" type="search" placeholder="<?= $ayuda ?>"></div></div>
      <button type="button" class="btn btn-outline-secondary sgs-filtro-limpiar" data-limpiar="<?= $pre ?>"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Limpiar</button>
    </div></div>
<?php };
?>
<div class="container-fluid p-3 p-md-4 io" id="ioPagina">
  <div class="sgs-page-actions">
    <span class="io-estado" id="ioEstado" data-tipo="">Cargando…</span>
    <?php $segmento('ioVistas', 'Vista', ['' => 'Primera atención', 'curso' => 'Órdenes en curso <span class="sgs-seg-count" id="ioNAlertas" title="Con alerta">0</span>']); ?>
    <button type="button" class="btn btn-outline-secondary" id="ioActualizar" title="Leer Winbo ahora"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Actualizar</button>
    <div class="dropdown">
      <button type="button" class="btn btn-win dropdown-toggle" id="ioEnviar" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar reporte</button>
      <div class="dropdown-menu dropdown-menu-end" id="ioMenuReporte"><span class="dropdown-item-text text-muted small">Cargando grupos…</span></div>
    </div>
  </div>
  <div class="alert d-none" id="ioAviso" role="status"></div>

  <!-- ───────── Primera atención ───────── -->
  <div id="ioVistaPrimera">
    <div class="row g-3 mb-3"><?php $kpis($kpisPrimera); ?></div>

    <div class="card p-3 p-md-4 mb-3">
      <h5 class="mb-2"><i class="bi bi-bar-chart-steps me-2 text-primary" aria-hidden="true"></i>Avance del día</h5>
      <div class="io-barra" id="ioBarra" aria-label="Avance del día"></div>
      <div class="io-regla">
        <span class="text-muted small">Regla del dashboard (primer En camino ≤ 08:00):</span>
        <span class="badge bg-success">Cumple <b id="ioRC">0</b></span>
        <span class="badge bg-warning">Seguimiento <b id="ioRS">0</b></span>
        <span class="badge bg-danger">Incumple <b id="ioRI">0</b></span>
        <span class="badge bg-dark">Pendiente <b id="ioRP">0</b></span>
        <span class="badge bg-secondary">Fuera de regla <b id="ioRE">0</b></span>
      </div>
    </div>

    <div class="card p-3 p-md-4 mb-3">
      <h5 class="mb-0"><i class="bi bi-graph-up me-2 text-primary" aria-hidden="true"></i>Cómo va el día</h5>
      <p class="text-muted small">Primeras atenciones iniciadas, acumuladas cada 15 minutos.</p>
      <div class="dsh-columnas dsh-columnas--dias" id="ioCurva"></div>
    </div>

    <div class="card sgs-toolbar-card p-3 mb-3">
      <div class="sgs-toolbar">
        <?php $segmento('ioFEstado', 'Estado', ['' => 'Todos', 'Inició' => 'Iniciaron', 'En camino' => 'En camino', 'Sin iniciar' => 'Sin iniciar']); ?>
        <?php $segmento('ioFGrupo', 'Grupo', ['' => 'Todos', 'SGI' => 'SGI', 'SGA' => 'SGA', 'Otros' => 'Otros']); ?>
      </div>
      <?php $filtros('ioF', 'Cuadrilla', 'Nombre o código'); ?>
    </div>
    <div class="card p-3 p-md-4 mb-3">
      <p class="sgs-counter mb-2" id="ioFResumen"></p>
      <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
        <thead><tr><th>Cuadrilla</th><th>Partner</th><th>Ubicación</th><th>Orden 1ra atención</th><th>Agendada</th><th>En camino</th><th>Inicio</th><th>Estado</th><th>Resultado</th></tr></thead>
        <tbody id="ioFilas"><tr><td colspan="9" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
      </table></div>
    </div>
  </div>

  <!-- ───────── Órdenes en curso ───────── -->
  <div id="ioVistaCurso" hidden>
    <div class="row g-3 mb-3"><?php $kpis($kpisCurso); ?></div>
    <div class="card sgs-toolbar-card p-3 mb-3">
      <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        <b>Iniciada:</b> hasta 2 h normal · de 2 h a 3 h <span class="badge bg-warning">amarillo</span> · 3 h o más <span class="badge bg-danger">rojo</span>.
        <b>En camino:</b> más de 40 min <span class="badge bg-danger">rojo</span>. La 1ra atención del día se alerta si pasa las 08:10 sin iniciar.
        Al finalizar, la orden sale de la lista.</p>
      <div class="sgs-toolbar">
        <?php $segmento('ioGEstado', 'Estado', ['' => 'Todas', 'En camino' => 'En camino', 'Iniciada' => 'Iniciadas', 'alerta' => 'Solo alertas']); ?>
        <?php $segmento('ioGGrupo', 'Grupo', ['' => 'Todos', 'SGI' => 'SGI', 'SGA' => 'SGA', 'Otros' => 'Otros']); ?>
      </div>
      <?php $filtros('ioG', 'Cuadrilla u orden', 'Nombre, código u orden'); ?>
    </div>
    <div class="card p-3 p-md-4 mb-3">
      <p class="sgs-counter mb-2" id="ioGResumen"></p>
      <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
        <thead><tr><th>Cuadrilla</th><th>Partner</th><th>Ubicación</th><th>Orden</th><th>Tipo trabajo</th><th>Estado</th><th>Desde</th><th>Duración</th><th>Alerta</th></tr></thead>
        <tbody id="ioGFilas"><tr><td colspan="9" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
      </table></div>
    </div>
  </div>

  <p class="text-muted small mb-0" id="ioPie"></p>
  <div class="dsh-tip" id="ioTip" role="tooltip" hidden></div>
</div>
<script src="03_FRONTEND/src/assets/js/sgs-dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="03_FRONTEND/src/assets/js/inicio_ordenes.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
