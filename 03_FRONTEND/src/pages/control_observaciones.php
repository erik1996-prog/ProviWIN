<?php
Auth::requireLogin();
$user = Auth::getLoggedInUser();
if (!Auth::isAdministrative()) {
    echo '<div class="alert alert-danger m-4">Acceso reservado al personal administrativo.</div>';
    return;
}
$pdo = Database::getConnection();
$partnerFiltro = (int)($_GET['partner_id'] ?? 0);
$zonaFiltro = trim($_GET['zona'] ?? '');
$fechaDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_desde'] ?? '') ? $_GET['fecha_desde'] : '';
$fechaHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_hasta'] ?? '') ? $_GET['fecha_hasta'] : '';
$partnersObs = $pdo->query("SELECT id,nombre_partner FROM partners WHERE estado=1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY nombre_partner")->fetchAll();
$zonasObs = $pdo->query("SELECT DISTINCT departamento FROM supervisiones WHERE departamento<>'' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
$sqlEventos = "
    SELECT s.tecnico1_nombre tecnico, s.tecnico1_dni dni, 'T1' tipo, p.id partner_id, p.nombre_partner,
           s.departamento zona, o.fecha_registro fecha_observacion,
           COALESCE(sub.descripcion_subopcion,c.nombre_categoria,'Observación general') observacion
    FROM observaciones_supervision o JOIN supervisiones s ON s.id=o.supervision_id
    LEFT JOIN partners p ON p.id=s.partner_id LEFT JOIN categorias_observacion c ON c.id=o.categoria_id
    LEFT JOIN subopciones_observacion sub ON sub.id=o.subopcion_id WHERE s.tecnico1_nombre IS NOT NULL
    UNION ALL
    SELECT s.tecnico2_nombre, s.tecnico2_dni, 'T2', p.id, p.nombre_partner, s.departamento, o.fecha_registro,
           COALESCE(sub.descripcion_subopcion,c.nombre_categoria,'Observación general')
    FROM observaciones_supervision o JOIN supervisiones s ON s.id=o.supervision_id
    LEFT JOIN partners p ON p.id=s.partner_id LEFT JOIN categorias_observacion c ON c.id=o.categoria_id
    LEFT JOIN subopciones_observacion sub ON sub.id=o.subopcion_id WHERE s.tecnico2_nombre IS NOT NULL AND s.tecnico2_nombre<>''";
$eventos = $pdo->query($sqlEventos)->fetchAll();
$ranking = [];
$motivosGlobales = [];
$totalObservacionesFiltradas = 0;
foreach ($eventos as $evento) {
    if ($partnerFiltro && (int)$evento['partner_id'] !== $partnerFiltro) continue;
    if ($zonaFiltro !== '' && $evento['zona'] !== $zonaFiltro) continue;
    $fechaEvento = substr((string)$evento['fecha_observacion'], 0, 10);
    if ($fechaDesde !== '' && $fechaEvento < $fechaDesde) continue;
    if ($fechaHasta !== '' && $fechaEvento > $fechaHasta) continue;
    $clave = ($evento['dni'] ?: $evento['tecnico']) . '|' . $evento['tipo'];
    if (!isset($ranking[$clave])) $ranking[$clave] = ['tecnico'=>$evento['tecnico'],'dni'=>$evento['dni'],'tipo'=>$evento['tipo'],'partner'=>$evento['nombre_partner'],'zona'=>$evento['zona'],'total'=>0,'motivos'=>[]];
    $ranking[$clave]['total']++;
    $motivo = $evento['observacion'];
    $ranking[$clave]['motivos'][$motivo] = ($ranking[$clave]['motivos'][$motivo] ?? 0) + 1;
    $motivosGlobales[$motivo] = ($motivosGlobales[$motivo] ?? 0) + 1;
    $totalObservacionesFiltradas++;
}
foreach ($ranking as &$fila) { arsort($fila['motivos']); } unset($fila);
usort($ranking, fn($a,$b) => $b['total'] <=> $a['total']);
arsort($motivosGlobales);
$maxTecnico = $ranking[0]['total'] ?? 1;
$maxMotivo = $motivosGlobales ? max($motivosGlobales) : 1;
$tecnicoTop = $ranking[0]['tecnico'] ?? 'Sin datos';
$motivoTop = array_key_first($motivosGlobales) ?: 'Sin datos';
?>
<style>
.obs-kpi{min-height:132px;height:auto;padding:12px!important;border:0;border-radius:14px;background:linear-gradient(145deg,var(--sgs-ink),#313a48);color:#fff;box-shadow:0 12px 28px rgba(15,23,42,.13)}
.obs-kpi .icon{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:9px;background:rgba(255,90,10,.18);color:#ff8a50;font-size:1rem}.obs-kpi small{color:#d6dde6;text-transform:uppercase;font-weight:750;font-size:.74rem;line-height:1.3;letter-spacing:.025em}.obs-kpi strong{font-size:1.08rem;line-height:1.2;margin-top:5px}.obs-bar{height:8px;background:var(--sgs-surface-alt);border-radius:20px;overflow:hidden;min-width:105px}.obs-bar>span{display:block;height:100%;border-radius:20px;background:linear-gradient(90deg,var(--sgs-orange),#dc3545)}
.obs-rank-number{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:var(--sgs-ink);color:var(--sgs-warning);font-weight:800;font-size:.76rem}.obs-motive-row{padding:10px 0;border-bottom:1px solid var(--sgs-surface-alt)}.obs-motive-row:last-child{border-bottom:0}.obs-ranking-table{font-size:.82rem}.obs-ranking-table th{font-size:.72rem;white-space:nowrap}.obs-ranking-row{cursor:pointer}.obs-ranking-row.is-selected{--bs-table-bg:var(--sgs-orange-subtle);box-shadow:inset 4px 0 var(--sgs-orange)}.obs-kpi-value{display:block!important;font-size:.92rem!important;line-height:1.3!important;overflow-wrap:anywhere;word-break:normal}
@media (max-width:575.98px){.obs-kpi{min-height:128px;padding:12px!important}.obs-kpi-value{font-size:.84rem!important}.obs-kpi small{font-size:.7rem}}
</style>
<div class="container-fluid p-3 p-md-4">
  <form class="card border-0 shadow-sm p-3 mb-4" method="get"><input type="hidden" name="page" value="control_observaciones"><div class="row g-3 align-items-end">
    <div class="col-md-3"><label for="control-obs-partner" class="form-label fw-bold small">Partner</label><select id="control-obs-partner" class="form-select form-select-sm" name="partner_id"><option value="0">Todos los partners</option><?php foreach($partnersObs as $p): ?><option value="<?= $p['id'] ?>" <?= $partnerFiltro===$p['id']?'selected':'' ?>><?= htmlspecialchars($p['nombre_partner']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label for="control-obs-departamento" class="form-label fw-bold small">Departamento</label><select id="control-obs-departamento" class="form-select form-select-sm" name="zona"><option value="">Todos los departamentos</option><?php foreach($zonasObs as $z): ?><option <?= $zonaFiltro===$z?'selected':'' ?>><?= htmlspecialchars($z) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label for="control-obs-desde" class="form-label fw-bold small">Desde</label><input id="control-obs-desde" class="form-control form-control-sm" type="date" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>"></div>
    <div class="col-6 col-md-2"><label for="control-obs-hasta" class="form-label fw-bold small">Hasta</label><input id="control-obs-hasta" class="form-control form-control-sm" type="date" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>"></div>
    <div class="col-md-2"><button class="btn btn-win btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button></div>
  </div></form>
  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="obs-kpi p-3"><div class="icon mb-2"><i class="bi bi-exclamation-triangle"></i></div><small>Observaciones asociadas</small><strong class="d-block"><?= $totalObservacionesFiltradas ?></strong></div></div>
    <div class="col-6 col-xl-3"><div class="obs-kpi p-3"><div class="icon mb-2"><i class="bi bi-people"></i></div><small>Técnicos observados</small><strong class="d-block"><?= count($ranking) ?></strong></div></div>
    <div class="col-6 col-xl-3"><div class="obs-kpi p-3"><div class="icon mb-2"><i class="bi bi-trophy"></i></div><small>Técnico con mayor recurrencia</small><strong class="obs-kpi-value" title="<?= htmlspecialchars($tecnicoTop) ?>"><?= htmlspecialchars($tecnicoTop) ?></strong></div></div>
    <div class="col-6 col-xl-3"><div class="obs-kpi p-3"><div class="icon mb-2"><i class="bi bi-repeat"></i></div><small>Observación más recurrente</small><strong class="obs-kpi-value" title="<?= htmlspecialchars($motivoTop) ?>"><?= htmlspecialchars($motivoTop) ?></strong></div></div>
  </div>
  <div class="row g-4">
  <div class="col-xl-8"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-dark text-white fw-bold py-3">Ranking de técnicos observados</div><div class="table-responsive"><table class="table table-hover align-middle mb-0 obs-ranking-table"><thead><tr><th>#</th><th>Técnico</th><th>Partner / Departamento</th><th style="min-width:190px">Observaciones generales</th></tr></thead><tbody>
  <?php if(!$ranking): ?><tr><td colspan="4" class="text-center text-muted py-4">Todavía no hay observaciones para los filtros seleccionados.</td></tr><?php endif; ?>
  <?php foreach($ranking as $i=>$r): ?><tr class="obs-ranking-row" tabindex="0" data-index="<?= $i ?>"><td><span class="obs-rank-number"><?= $i+1 ?></span></td><td><strong><?= htmlspecialchars($r['tecnico']) ?></strong><small class="d-block text-muted"><?= htmlspecialchars($r['tipo'].' · '.$r['dni']) ?></small></td><td><strong><?= htmlspecialchars($r['partner'] ?: '—') ?></strong><small class="d-block text-muted"><?= htmlspecialchars($r['zona']) ?></small></td><td><div class="d-flex align-items-center gap-2"><div class="obs-bar flex-grow-1"><span style="width:<?= round(($r['total']/$maxTecnico)*100) ?>%"></span></div><span class="badge bg-danger"><?= $r['total'] ?></span></div></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
  <div class="col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white py-3"><div class="fw-bold"><i class="bi bi-bar-chart-fill me-2 text-warning"></i>Observaciones más recurrentes</div><small class="text-muted" id="obs-motive-scope">Vista global</small></div><div class="card-body py-1" id="obs-motive-list"></div></div></div>
  </div>
</div>
<script>
(() => {
  const globalMotives = <?= json_encode($motivosGlobales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const technicians = <?= json_encode(array_map(fn($r) => ['name'=>$r['tecnico'], 'motives'=>$r['motivos']], $ranking), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const list = document.getElementById('obs-motive-list');
  const scope = document.getElementById('obs-motive-scope');
  const escapeHtml = value => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  function renderMotives(motives, label) {
    scope.textContent = label;
    const entries = Object.entries(motives).sort((a,b) => b[1] - a[1]).slice(0,10);
    if (!entries.length) { list.innerHTML = '<div class="text-center text-muted py-5">Sin observaciones.</div>'; return; }
    const max = entries[0][1] || 1;
    list.innerHTML = entries.map(([motive,total]) => `<div class="obs-motive-row"><div class="d-flex justify-content-between gap-2 mb-2"><span class="small fw-bold">${escapeHtml(motive)}</span><span class="badge bg-dark text-warning">${total}</span></div><div class="obs-bar"><span style="width:${Math.round(total/max*100)}%"></span></div></div>`).join('');
  }
  renderMotives(globalMotives, 'Vista global');
  document.querySelectorAll('.obs-ranking-row').forEach(row => {
    const select = () => {
      const tech = technicians[Number(row.dataset.index)];
      if (!tech) return;
      document.querySelectorAll('.obs-ranking-row').forEach(item => item.classList.remove('is-selected'));
      row.classList.add('is-selected');
      renderMotives(tech.motives, `Técnico: ${tech.name}`);
    };
    row.addEventListener('click', select);
    row.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(); } });
  });
  scope.addEventListener('click', () => { document.querySelectorAll('.obs-ranking-row').forEach(item => item.classList.remove('is-selected')); renderMotives(globalMotives, 'Vista global'); });
  scope.title = 'Haz clic aquí para volver a la vista global';
  scope.style.cursor = 'pointer';
})();
</script>
