<?php
// Dashboard de partners (2026-10-20): antes era la vista "Partners" del Dashboard de supervisiones; se separó para que
// cada dashboard tenga lo suyo sin repetir. Cumplimiento (atención en 24 h de lo que se transfiere a los supervisores de
// partners, por partner y por supervisor, y órdenes bloqueadas) y observaciones y descargos.
// Datos: api/dashboard_partners.php (totales calculados en la base). Pantalla: assets/js/dashboard_partners.js con las
// piezas comunes de sgs-dashboard.js. Solo administración.
Auth::requireLogin();
if (!Auth::can(Auth::PERM_DASHBOARD_VIEW)) {
    echo '<div class="alert alert-danger m-4">Acceso reservado al personal administrativo.</div>';
    return;
}
$pdo = Database::getConnection();
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY nombre_partner")->fetchAll();
$zonas = sgsOperationalDepartments();
sort($zonas);
$kpisCumplimiento = [
    ['c_asignadas', 'Asignadas', 'bi-send'], ['c_a_tiempo', 'Atendidas a tiempo', 'bi-check2-circle'], ['c_bloqueadas', 'Bloqueadas', 'bi-lock'],
    ['c_en_plazo', 'En plazo', 'bi-hourglass-split'], ['c_promedio', 'Respuesta promedio', 'bi-stopwatch'], ['c_cumplimiento', 'Cumplimiento', 'bi-graph-up-arrow'],
];
$kpisObservaciones = [
    ['o_total', 'Observaciones', 'bi-chat-square-text'], ['o_a_tiempo', 'Respondidas a tiempo', 'bi-check2-circle'], ['o_vencidas', 'Sin respuesta vencidas', 'bi-exclamation-octagon'],
    ['o_en_plazo', 'Pendientes en plazo', 'bi-hourglass-split'], ['o_revision', 'Descargos por revisar', 'bi-search'], ['o_promedio', 'Respuesta promedio', 'bi-stopwatch'],
    ['o_validadas', 'Descargos validados', 'bi-patch-check'], ['o_penalizadas', 'Penalizadas', 'bi-x-octagon'], ['o_pct', 'Respuesta a tiempo', 'bi-graph-up-arrow'],
    ['o_pct_pen', 'Penalidad sobre evaluadas', 'bi-percent'],
];
$kpi = static fn(array $lista, string $cols) => '<div class="row ' . $cols . ' g-3 mb-3 dsh-kpis">' . implode('', array_map(static fn($k) =>
    '<div class="col"><div class="sgs-kpi p-3 h-100" data-tono="' . $k[0] . '"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi ' . $k[2] . '"></i></span>'
    . '<small class="sgs-kpi-label">' . htmlspecialchars($k[1], ENT_QUOTES, 'UTF-8') . '</small><h3 class="mb-0 sgs-kpi-value" data-kpi="' . $k[0] . '">—</h3></div></div>', $lista)) . '</div>';
$tabla = static fn(string $id, array $columnas, string $clase = '') => '<div class="table-responsive' . $clase . '"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas"><thead><tr>'
    . implode('', array_map(static fn($c) => '<th' . ($c[1] ? ' class="text-end"' : '') . '>' . $c[0] . '</th>', $columnas))
    . '</tr></thead><tbody id="' . $id . '"><tr><td colspan="' . count($columnas) . '" class="text-center text-muted py-4">Cargando…</td></tr></tbody></table></div>';
?>
<div class="container-fluid p-3 p-md-4 dsh" id="dpa">
  <div class="sgs-page-actions">
    <span class="sgs-counter" id="dpaPeriodo"><i class="bi bi-calendar3"></i><span>Cargando…</span></span>
  </div>

  <form class="card sgs-toolbar-card p-3 mb-3" id="dpaFiltros">
    <div class="sgs-filtros">
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="dpaDesde">Desde</label><input class="form-control" type="date" id="dpaDesde" name="desde" value="<?= date('Y-m-01') ?>"></div>
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="dpaHasta">Hasta</label><input class="form-control" type="date" id="dpaHasta" name="hasta" value="<?= date('Y-m-d') ?>"></div>
      <div class="sgs-filtro"><label class="form-label" for="dpaPartner">Partner</label>
        <select class="form-select" id="dpaPartner" name="partner_id"><option value="">Todos los partners</option>
          <?php foreach ($partners as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['nombre_partner']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="dpaZona">Zona</label>
        <select class="form-select" id="dpaZona" name="zona"><option value="">Todas las zonas</option>
          <?php foreach ($zonas as $z): ?><option value="<?= $e($z) ?>"><?= $e($z) ?></option><?php endforeach; ?>
        </select></div>
      <button type="reset" class="btn btn-outline-secondary sgs-filtro-limpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
    </div>
  </form>

  <div class="card p-3 p-md-4 mb-3">
    <h5 class="mb-1"><i class="bi bi-stopwatch me-2 text-primary"></i>Cumplimiento de partners</h5>
    <p class="text-muted small mb-3">Órdenes transferidas a supervisores de partners. Plazo de atención: 24 horas; si vence, la orden se bloquea y cuenta como incumplimiento.</p>
    <?= $kpi($kpisCumplimiento, 'row-cols-2 row-cols-md-3 row-cols-xl-6') ?>
    <div class="row g-3">
      <div class="col-lg-6"><h6 class="mb-2">Por partner</h6>
        <?= $tabla('dpaCumplPartners', [['Partner', false], ['Asignadas', true], ['A tiempo', true], ['Bloqueadas', true], ['Cumplimiento', true]]) ?></div>
      <div class="col-lg-6"><h6 class="mb-2">Órdenes bloqueadas</h6>
        <?= $tabla('dpaBloqueadas', [['Orden', false], ['Partner y supervisor', false], ['Vencida hace', true]], ' dsh-tabla-alta') ?></div>
      <div class="col-12"><h6 class="mb-2"><i class="bi bi-person-badge me-1 text-primary"></i>Por supervisor del partner</h6>
        <?= $tabla('dpaSupervisores', [['Supervisor', false], ['Partner', false], ['Asignadas', true], ['A tiempo', true], ['En plazo', true], ['Bloqueadas', true], ['Respuesta promedio', true], ['Cumplimiento', true]]) ?></div>
    </div>
  </div>

  <div class="card p-3 p-md-4">
    <h5 class="mb-1"><i class="bi bi-chat-square-text me-2 text-primary"></i>Observaciones y descargos</h5>
    <p class="text-muted small mb-3">Observaciones de campo y de checklist hechas a los partners en el período, y cómo respondieron dentro del plazo.</p>
    <?= $kpi($kpisObservaciones, 'row-cols-2 row-cols-md-3 row-cols-xl-5') ?>
    <div class="row g-3">
      <div class="col-12">
        <?= $tabla('dpaObsPartners', [['Partner', false], ['Observaciones', true], ['A tiempo', true], ['Vencidas sin respuesta', true], ['Por revisar', true], ['Validadas', true], ['Penalizadas', true], ['Respuesta a tiempo', true]]) ?></div>
      <div class="col-lg-7"><div class="card p-3 h-100"><h6 class="mb-2"><i class="bi bi-list-ol me-1 text-primary"></i>Motivos más observados</h6><div class="dsh-barras" id="dpaMotivos"></div></div></div>
      <div class="col-lg-5"><div class="card p-3 h-100"><h6 class="mb-2"><i class="bi bi-bar-chart-steps me-1 text-primary"></i>Por gravedad</h6><div class="dsh-barras" id="dpaGravedad"></div></div></div>
    </div>
  </div>
  <div class="dsh-tip" id="dpaTip" role="tooltip" hidden></div>
</div>
<script src="03_FRONTEND/src/assets/js/sgs-dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="03_FRONTEND/src/assets/js/dashboard_partners.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
