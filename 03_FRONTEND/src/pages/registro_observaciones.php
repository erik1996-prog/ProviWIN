<?php
Auth::requireLogin();
$user = Auth::getLoggedInUser();

if (empty($user['is_win_directo']) && empty($user['partner_id'])) {
    echo '<div class="alert alert-danger m-4">No tiene acceso al registro de observaciones.</div>';
    return;
}

$pdo = Database::getConnection();
win_run_periodic_task('observation-sla', 60, static function () use ($pdo): void {
    $pdo->exec("UPDATE observaciones_supervision SET estado_respuesta='Penalidad Directa' WHERE estado_respuesta='Pendiente' AND fecha_limite_respuesta<NOW()");
});
win_run_periodic_task('checklist-sla', 60, static function () use ($pdo): void {
    $pdo->exec("UPDATE checklists_tecnicos SET estado_respuesta='Penalizado' WHERE estado_respuesta IN ('Pendiente','Reabierto') AND COALESCE(fecha_limite_respuesta,CASE WEEKDAY(fecha_registro) WHEN 0 THEN DATE_ADD(fecha_registro,INTERVAL 3 DAY) WHEN 1 THEN DATE_ADD(fecha_registro,INTERVAL 3 DAY) WHEN 2 THEN DATE_ADD(fecha_registro,INTERVAL 3 DAY) WHEN 3 THEN DATE_ADD(fecha_registro,INTERVAL 4 DAY) WHEN 4 THEN DATE_ADD(fecha_registro,INTERVAL 4 DAY) WHEN 5 THEN DATE_ADD(fecha_registro,INTERVAL 4 DAY) ELSE DATE_ADD(fecha_registro,INTERVAL 3 DAY) END)<NOW()");
});
$isAdmin = Auth::isAdministrative();
$esAdministrador = ($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR; // ampliar plazos vencidos
$puedeEliminarObs = Auth::puede(Auth::PERM_OBSERVATION_DELETE, $user); // Roles y permisos (2026-10-23)
$isDirectSupervisor = ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
$isPartner = empty($user['is_win_directo']) && !empty($user['partner_id']);
// Responder: personal del partner con el permiso (el jefe de operaciones solo si el administrador se lo activó).
$puedeResponderPartner = Auth::can(Auth::PERM_OBSERVATION_RESPOND, $user);
$canObservationFilterDate = Auth::can(Auth::PERM_OBSERVATION_FILTER_DATE, $user);
$canObservationFilterAdvanced = Auth::can(Auth::PERM_OBSERVATION_FILTER_ADVANCED, $user);
require_once BACKEND_PATH . '/config/observation_records.php';
$filters = observationRegisterFilters($_GET);
if (!$canObservationFilterDate) {
    $filters['fecha_desde'] = '';
    $filters['fecha_hasta'] = '';
}
if (!$canObservationFilterAdvanced) {
    $filters['partner_id'] = 0;
    $filters['cuadrilla'] = '';
    $filters['departamento'] = '';
}
$effectivePartnerId=0;
if($isPartner){
    if(!$isAdmin){$effectivePartnerId=(int)$user['partner_id'];}
    else{
        $effectivePartnerId=(int)($_SESSION['preview_partner_id']??0);
        if($effectivePartnerId<1){$effectivePartnerId=(int)$pdo->query("SELECT s.partner_id FROM observaciones_supervision o JOIN supervisiones s ON s.id=o.supervision_id WHERE s.partner_id IS NOT NULL ORDER BY (o.estado_respuesta='Pendiente') DESC,o.id DESC LIMIT 1")->fetchColumn();$_SESSION['preview_partner_id']=$effectivePartnerId;}
    }
    $filters['partner_id']=$effectivePartnerId;
}
extract($filters, EXTR_PREFIX_ALL, 'f');
$partnerFiltro=$f_partner_id; $cuadrillaFiltro=$f_cuadrilla; $departamentoFiltro=$f_departamento; $codigoFiltro=$f_codigo; $fechaDesde=$f_fecha_desde; $fechaHasta=$f_fecha_hasta; $estadoFiltro=$f_estado; $tipoFiltro=$f_tipo_registro;
// Listas de filtros con la misma regla que el listado: el partner, su partner, su zona y su área; el líder con zonas o área
// propias, solo su entorno (2026-10-28: antes el líder veía en las listas las cuadrillas y zonas de todo el país).
// Los valores van ya escapados dentro del SQL (estas listas se consultan sin parámetros).
$enLinea = static function (string $sql, array $params) use ($pdo): string {
    uksort($params, static fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($params as $k => $v) $sql = str_replace($k, $pdo->quote($v), $sql);
    return $sql;
};
$partnerUserScope = '';
if ($isPartner) {
    $zoneParams = [];
    $partnerUserScope = ' AND ' . $enLinea(sgsZoneSql(sgsZonaSql('s'), (int)$user['id'], $zoneParams, ':zf'), $zoneParams) // zona: departamento o NORTE CHICO
        . ' AND ' . sgsAreaSql('s', (int)$user['id']);
}
$alcParams = [];
$alcanceLider = $isAdmin && sgsAlcanceLimitado($user) ? ' AND ' . $enLinea(sgsAlcanceSupervisionSql($user, 's', $alcParams, ':af'), $alcParams) : '';
$scopeSql = $isPartner
    ? ' AND s.partner_id = ' . $effectivePartnerId . $partnerUserScope
    : ($isAdmin ? $alcanceLider : ' AND (s.supervisor_id = ' . (int)$user['id'] . ' OR s.asignado_por = ' . (int)$user['id'] . ')');
$partners = $pdo->query("SELECT DISTINCT p.id,p.nombre_partner FROM supervisiones s JOIN partners p ON p.id=s.partner_id WHERE 1=1 {$scopeSql} ORDER BY p.nombre_partner")->fetchAll();
$cuadrillas = $pdo->query("SELECT DISTINCT s.tecnico1_nombre FROM supervisiones s WHERE s.tecnico1_nombre IS NOT NULL AND TRIM(s.tecnico1_nombre)<>'' {$scopeSql} ORDER BY s.tecnico1_nombre")->fetchAll(PDO::FETCH_COLUMN);
$departamentos = $pdo->query("SELECT DISTINCT s.departamento FROM supervisiones s WHERE s.departamento IS NOT NULL AND TRIM(s.departamento)<>'' {$scopeSql} ORDER BY s.departamento")->fetchAll(PDO::FETCH_COLUMN);
$observationPage = max(1, (int)($_GET['pagina_observaciones'] ?? 1));
$observationPageData = fetchObservationRecordsPage($pdo,$user,$filters,$observationPage,30);
$registros = $observationPageData['data'];
$observationTotal = (int)$observationPageData['total'];
$observationRangeStart = $observationTotal > 0 ? (($observationPage - 1) * $observationPageData['per_page']) + 1 : 0;
$observationRangeEnd = 0;
$registrosAgrupados=[];$indiceChecklist=[];
foreach($registros as $registro){
    if(($registro['tipo_registro']??'')!=='Checklist'){$registrosAgrupados[]=$registro;continue;}
    $clave='checklist_'.(int)$registro['supervision_id'];
    if(!isset($indiceChecklist[$clave])){$registro['_checklist_items']=[];$indiceChecklist[$clave]=count($registrosAgrupados);$registrosAgrupados[]=$registro;}
    $registrosAgrupados[$indiceChecklist[$clave]]['_checklist_items'][]=$registro;
}
$registros=$registrosAgrupados;
if ($registros) {
    $observationRangeEnd = min($observationTotal,$observationRangeStart + count($registros) - 1);
} else {
    $observationRangeStart = 0;
    $observationRangeEnd = 0;
}

function observationPhotoUrl(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return ltrim(str_replace('\\', '/', $path), '/');
}
?>
<style>
.obs-history-header{background:linear-gradient(135deg,var(--sgs-ink),var(--sgs-ink-2));border-radius:16px;color:#fff;padding:22px 24px;box-shadow:0 12px 30px rgba(15,23,42,.12)}
.obs-history-filter{border:1px solid var(--sgs-border);border-radius:12px}.obs-history-table{font-size:.8rem}.obs-history-table thead th{background:var(--sgs-ink);color:#fff;border:0;font-size:.68rem;text-transform:uppercase;letter-spacing:.025em;white-space:normal;line-height:1.3;padding:10px 9px}.obs-history-table td{padding:10px 9px;vertical-align:middle;line-height:1.4}.obs-history-table strong{font-weight:650}.obs-history-table small{font-size:.72rem}.obs-history-table .btn{min-height:30px;padding:.3rem .55rem;font-size:.73rem}.obs-history-table .badge{font-size:.67rem;padding:.36rem .48rem}.obs-comment{white-space:normal;line-height:1.45}.obs-photo{width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid #fff;box-shadow:0 2px 8px rgba(15,23,42,.14);cursor:pointer}.obs-photo-link+.obs-photo-link{margin-left:-8px}.obs-type{font-size:.67rem}.obs-empty{padding:42px 18px;text-align:center;color:var(--sgs-muted)}.obs-empty i{font-size:1.6rem;color:var(--sgs-orange)}.obs-photo-modal-img{max-height:75vh;max-width:100%;object-fit:contain}.checklist-record-row,.field-record-row{cursor:pointer}.checklist-record-row:hover td,.field-record-row:hover td{background:var(--sgs-orange-subtle)!important}.checklist-detail-photo{width:110px;height:85px;object-fit:cover;border-radius:10px}.obs-mobile-context{display:none}
.obs-kind-tabs .btn{font-weight:700;border-radius:10px!important}.obs-direct-table{width:100%;table-layout:auto}.obs-direct-table th:nth-child(1){width:105px}.obs-direct-table th:nth-child(2){width:115px}.obs-direct-table th:nth-child(4){width:18%}.obs-direct-table th:nth-child(5){width:16%}.obs-direct-table th:nth-child(10){width:145px}.obs-direct-table th:nth-child(11){width:170px}.obs-direct-table th:nth-child(3),.obs-direct-table td:nth-child(3),.obs-direct-table th:nth-child(6),.obs-direct-table td:nth-child(6),.obs-direct-table th:nth-child(7),.obs-direct-table td:nth-child(7),.obs-direct-table th:nth-child(8),.obs-direct-table td:nth-child(8),.obs-direct-table th:nth-child(9),.obs-direct-table td:nth-child(9){display:none}.obs-direct-table td{overflow-wrap:anywhere}.obs-direct-table td:last-child .btn{white-space:normal}
#observationWorkspace.is-filtering{opacity:.58;pointer-events:none;transition:opacity .15s ease}
@media(max-width:767.98px){#observationWorkspace{width:100%;max-width:100%;overflow-x:hidden}.obs-history-header{padding:16px;margin-left:0!important;margin-right:0!important}.obs-history-table{font-size:.75rem}.table-responsive{overflow:visible!important;width:100%;max-width:100%}.obs-direct-table,.obs-partner-table{table-layout:auto;width:100%!important;max-width:100%}.obs-direct-table thead,.obs-partner-table thead{display:none}.obs-direct-table tbody,.obs-direct-table tr,.obs-direct-table td,.obs-partner-table tbody,.obs-partner-table tr,.obs-partner-table td{display:block;width:100%!important;max-width:100%}.obs-direct-table tr,.obs-partner-table tr{margin:10px 0;border:1px solid #e4e8ee;border-left:4px solid var(--sgs-orange);border-radius:12px;overflow:hidden;width:100%!important;max-width:100%!important;box-shadow:0 4px 14px rgba(15,23,42,.06)}.obs-direct-table td,.obs-partner-table td{display:grid!important;grid-template-columns:36% minmax(0,1fr);column-gap:10px;align-items:start;white-space:normal!important;padding:9px 10px!important;border:0!important;text-align:right;min-width:0!important;overflow-wrap:anywhere}.obs-direct-table td>* ,.obs-partner-table td>*{grid-column:2;max-width:100%;justify-self:end}.obs-direct-table td:nth-child(7),.obs-direct-table td:nth-child(8){display:none!important}.obs-direct-table td::before,.obs-partner-table td::before{font-weight: 700;color:#526070;text-align:left;grid-column:1;grid-row:1 / span 3}.obs-direct-table td:nth-child(1)::before{content:'Fecha'}.obs-direct-table td:nth-child(2)::before{content:'Origen'}.obs-direct-table td:nth-child(3)::before{content:'Código'}.obs-direct-table td:nth-child(4)::before{content:'Cuadrilla'}.obs-direct-table td:nth-child(5)::before{content:'Partner'}.obs-direct-table td:nth-child(6)::before{content:'Ubicación'}.obs-direct-table td:nth-child(9)::before{content:'Supervisor'}.obs-direct-table td:nth-child(10)::before{content:'Estado'}.obs-direct-table td:nth-child(11)::before{content:'Acción'}.obs-partner-table td:nth-child(1)::before{content:'Código'}.obs-partner-table td:nth-child(2)::before{content:'Fecha'}.obs-partner-table td:nth-child(3)::before{content:'Cuadrilla'}.obs-partner-table td:nth-child(4)::before{content:'Plazo'}.obs-partner-table td:nth-child(5)::before{content:'Estado'}.obs-partner-table td:nth-child(6)::before{content:'Acción'}.obs-mobile-context{display:block!important;margin-top:5px;color:var(--sgs-muted);text-align:right;line-height:1.35}.obs-history-table .obs-empty{display:block!important;text-align:center!important}.obs-history-table .obs-empty::before{display:none}.obs-kind-tabs{display:grid!important;grid-template-columns:1fr;width:100%}.obs-kind-tabs .btn{width:100%;font-size:.75rem;padding:.55rem .35rem;border-radius:8px!important}#observationWorkspace>.container-fluid{max-width:100%;overflow-x:hidden;padding-left:10px!important;padding-right:10px!important}}
</style>

<div id="observationWorkspace">
<div class="container-fluid p-3 p-md-4">
    <div class="obs-history-header d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="bi bi-clipboard2-pulse-fill me-2" style="color:var(--sgs-orange)"></i>Registro de observaciones</h3>
            <p class="mb-0 text-white-50">Historial de hallazgos, comentarios generales y observaciones del checklist.</p>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-2"><?php if(!$isPartner): ?><?php $pdfChecklist=array_merge($filters,['tipo_registro'=>'Checklist','supervision_id'=>0]); $exportQuery=array_filter($filters); ?>
            <div class="dropdown">
                <button type="button" class="btn btn-win btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1"></i>Descargar</button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><h6 class="dropdown-header">Con los filtros aplicados</h6></li>
                    <li><a class="dropdown-item" download href="api/observaciones_export.php?<?= htmlspecialchars(http_build_query(['formato'=>'xlsx']+$exportQuery),ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Relación en Excel</a></li>
                    <li><a class="dropdown-item" download href="api/observaciones_export.php?<?= htmlspecialchars(http_build_query(['formato'=>'pdf']+$exportQuery),ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Relación en PDF</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" download href="api/observaciones_pdf.php?<?= htmlspecialchars(http_build_query($pdfChecklist),ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-images me-2"></i>PDF de checklist con evidencias</a></li>
                </ul>
            </div><?php endif; ?><span class="sgs-counter"><?= $observationRangeStart ?>–<?= $observationRangeEnd ?> de <?= $observationTotal ?> registro<?= $observationTotal === 1 ? '' : 's' ?></span></div>
    </div>

    <form id="observationFilters" class="card obs-history-filter border-0 shadow-sm p-3 mb-4" method="get">
        <input type="hidden" name="page" value="registro_observaciones">
        <div class="btn-group obs-kind-tabs mb-3" role="group" aria-label="Tipo de observación">
            <input type="radio" class="btn-check" name="tipo_registro" id="tipoTodas" value="" <?= $tipoFiltro===''?'checked':'' ?>><label class="btn btn-outline-secondary" for="tipoTodas">Todas</label>
            <input type="radio" class="btn-check" name="tipo_registro" id="tipoChecklist" value="Checklist" <?= $tipoFiltro==='Checklist'?'checked':'' ?>><label class="btn btn-outline-secondary" for="tipoChecklist"><i class="bi bi-ui-checks-grid me-1"></i>Checklist</label>
            <input type="radio" class="btn-check" name="tipo_registro" id="tipoCampo" value="Campo" <?= $tipoFiltro==='Campo'?'checked':'' ?>><label class="btn btn-outline-secondary" for="tipoCampo"><i class="bi bi-geo-alt me-1"></i>Observaciones en campo</label>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-md-4 col-xl-3"><label for="obsCodigo" class="form-label fw-bold small">Pedido o ticket</label><input id="obsCodigo" type="search" class="form-control form-control-sm" name="codigo" value="<?= htmlspecialchars($filters['codigo']) ?>" maxlength="60" placeholder="Código de pedido o ticket" autocomplete="off"></div>
            <div class="col-sm-6 col-md-4 col-xl-2"><label for="obsState" class="form-label fw-bold small">Estado</label><select id="obsState" class="form-select form-select-sm" name="estado"><option value="">Todos los estados</option><option value="Pendiente" <?= $estadoFiltro==='Pendiente'?'selected':'' ?>>Pendientes</option><option value="En revisión" <?= $estadoFiltro==='En revisión'?'selected':'' ?>>En revisión</option><option value="Validado" <?= $estadoFiltro==='Validado'?'selected':'' ?>>Aceptados</option><option value="Penalizado" <?= $estadoFiltro==='Penalizado'?'selected':'' ?>>Penalizados</option><option value="Reabierto" <?= $estadoFiltro==='Reabierto'?'selected':'' ?>>Reabiertos</option></select></div>
            <?php if($canObservationFilterDate): ?><div class="col-sm-6 col-md-4 col-xl-3"><label for="obsFechaDesde" class="form-label fw-bold small">Fecha desde</label><input id="obsFechaDesde" type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>"></div>
            <div class="col-sm-6 col-md-4 col-xl-3"><label for="obsFechaHasta" class="form-label fw-bold small">Fecha hasta</label><input id="obsFechaHasta" type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>"></div><?php endif; ?>
            <?php if($canObservationFilterAdvanced && $isAdmin && !$isPartner && !$isDirectSupervisor): ?>
            <div class="col-md-4 col-xl-2"><label for="obsPartner" class="form-label fw-bold small">Partner</label><select id="obsPartner" class="form-select form-select-sm" name="partner_id"><option value="0">Todos los partners</option><?php foreach($partners as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $partnerFiltro === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre_partner']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4 col-xl-2"><label for="obsCrew" class="form-label fw-bold small">Cuadrilla / técnico T1</label><select id="obsCrew" class="form-select form-select-sm" name="cuadrilla"><option value="">Todas las cuadrillas</option><?php foreach($cuadrillas as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= $cuadrillaFiltro === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4 col-xl-2"><label for="obsDepartment" class="form-label fw-bold small">Departamento</label><select id="obsDepartment" class="form-select form-select-sm" name="departamento"><option value="">Todos los departamentos</option><?php foreach($departamentos as $d): ?><option value="<?= htmlspecialchars($d) ?>" <?= $departamentoFiltro === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-sm-6 col-md-3 col-xl-2"><a href="index.php?page=registro_observaciones" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar</a></div>
        </div>
    </form>

    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0 obs-history-table <?= $isPartner?'obs-partner-table':'obs-direct-table' ?>">
                <caption class="visually-hidden">Historial de observaciones y levantamientos de supervisión</caption>
                <?php if($isPartner): ?><thead><tr><th>Pedido o ticket</th><th>Fecha de ingreso</th><th>Cuadrilla</th><th>Plazo para regularizar</th><th>Estado</th><th>Detalle</th></tr></thead><?php else: ?><thead><tr><th>Fecha de ingreso</th><th>Tipo</th><th>Pedido o ticket</th><th>Cuadrilla</th><th>Partner</th><th>Ubicación</th><th>Evidencias y comentarios</th><th>Comentario general</th><th>Supervisor</th><th>Estado / plazo</th><th>Gestión</th></tr></thead><?php endif; ?>
                <tbody>
                <?php if (!$registros): ?>
                    <tr><td colspan="<?= $isPartner ? 6 : 11 ?>" class="obs-empty"><i class="bi bi-inbox d-block mb-2"></i>No existen observaciones para los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach($registros as $r): ?>
                    <?php $photoEntries=[]; for($pi=1;$pi<=3;$pi++){ $url=observationPhotoUrl($r['foto_'.$pi]); if($url!=='')$photoEntries[]=['url'=>$url,'comment'=>$r['foto_comentario_'.$pi]??'']; } $photos=array_column($photoEntries,'url'); ?>
                    <?php $puedeResponder=$isPartner&&$puedeResponderPartner&&!empty($r['observacion_id'])&&in_array($r['estado_respuesta'],['Pendiente','Levantamiento rechazado','Apelación Rechazada'],true)&&(empty($r['fecha_limite_respuesta'])||strtotime($r['fecha_limite_respuesta'])>=time()); ?>
                    <?php $descargosFlujo=[];for($di=1;$di<=3;$di++){if(!empty($r['descargo_foto_'.$di]))$descargosFlujo[]=['url'=>observationPhotoUrl($r['descargo_foto_'.$di]),'comment'=>$r['descargo_comentario_'.$di]??''];}
                    $flowPayload=['id'=>(int)$r['observacion_id'],'codigo'=>trim((string)$r['ticket_codigo'])!==''?$r['ticket_codigo']:$r['codigo_supervision'],'codigo_supervision'=>$r['codigo_supervision'],'fecha'=>date('d/m/Y H:i',strtotime($r['fecha_registro'])),'plazo'=>$r['fecha_limite_respuesta']?date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta'])):'','plazo_iso'=>$r['fecha_limite_respuesta']?date('Y-m-d\TH:i',strtotime($r['fecha_limite_respuesta'])):'','estado'=>$r['estado_respuesta']?:'Pendiente','partner'=>$r['nombre_partner']?:'Sin partner','cuadrilla'=>$r['cuadrilla']?:'Sin cuadrilla','departamento'=>$r['departamento']?:'','zona'=>implode(' · ',array_filter([$r['provincia'],$r['distrito']])),'supervisor'=>$r['supervisor_nombre']?:'Sin asignar','titulo'=>$r['titulo']?:'Observación registrada','observacion'=>$r['comentario']??'','gravedad'=>$r['estado_item']??'','fotos'=>$photoEntries,'respondido_por'=>$r['respondido_por_nombre']??'','fecha_respuesta'=>!empty($r['fecha_respuesta_partner'])?date('d/m/Y H:i',strtotime($r['fecha_respuesta_partner'])):'','respuesta'=>$r['respuesta_partner']??'','descargos'=>$descargosFlujo,'evaluacion'=>$r['sustentacion_evaluacion']??'','fecha_evaluacion'=>!empty($r['fecha_evaluacion'])?date('d/m/Y H:i',strtotime($r['fecha_evaluacion'])):'','puede_eliminar'=>$puedeEliminarObs,'puede_ampliar'=>$esAdministrador&&($r['estado_respuesta']??'')!=='Levantamiento validado','puede_responder'=>$puedeResponder]; ?>
                    <?php if($r['tipo_registro']==='Checklist'): ?>
                    <?php $itemsChecklist=$r['_checklist_items']??[$r];$pdfCuadrilla=http_build_query(['tipo_registro'=>'Checklist','supervision_id'=>(int)$r['supervision_id']]);$checklistItems=[];foreach($itemsChecklist as $item){$checklistItems[]=['id'=>(int)$item['registro_id'],'titulo'=>$item['titulo'],'observacion'=>$item['comentario'],'foto'=>observationPhotoUrl($item['foto_1']??''),'descargo_foto'=>observationPhotoUrl($item['descargo_foto_1']??''),'descargo_comentario'=>$item['descargo_comentario_1']??''];}$checklistCode=trim((string)($r['ticket_codigo']??''))!==''?$r['ticket_codigo']:$r['codigo_supervision'];$checklistPayload=['supervision_id'=>(int)$r['supervision_id'],'codigo'=>$checklistCode,'cuadrilla'=>$r['cuadrilla']?:'Sin cuadrilla','partner'=>$r['nombre_partner']?:'','supervisor'=>$r['supervisor_nombre']?:'','respondido_por'=>$r['respondido_por_nombre']??'','ubicacion'=>implode(' · ',array_filter([$r['departamento'],$r['provincia'],$r['distrito']])),'fecha'=>date('d/m/Y H:i',strtotime($r['fecha_registro'])),'fecha_respuesta'=>!empty($r['fecha_respuesta_partner'])?date('d/m/Y H:i',strtotime($r['fecha_respuesta_partner'])):'','plazo'=>$r['fecha_limite_respuesta']?date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta'])):'','comentario_general'=>$r['comentario_general']??'','respuesta_general'=>$r['respuesta_partner']??'','evaluacion'=>$r['sustentacion_evaluacion']??'','estado'=>$r['estado_respuesta']??'Pendiente','items'=>$checklistItems];$canRespondChecklist=$isPartner&&$puedeResponderPartner&&in_array($checklistPayload['estado'],['Pendiente','Levantamiento rechazado'],true);$canReviewChecklist=!$isPartner&&$checklistPayload['estado']==='En revisión'&&($isDirectSupervisor||$isAdmin);$checklistStateLabel=$checklistPayload['estado']==='Levantamiento validado'?'Válido':($checklistPayload['estado']==='Levantamiento rechazado'?'No válido':$checklistPayload['estado']); ?>
                    <?php $canRespondChecklist=$isPartner&&$puedeResponderPartner&&in_array($checklistPayload['estado'],['Pendiente','Reabierto'],true);$canReopenChecklist=!$isPartner&&$checklistPayload['estado']==='Penalizado'&&($isDirectSupervisor||$isAdmin);$checklistStateClass=match($checklistPayload['estado']){'Validado'=>'bg-success','Penalizado'=>'bg-danger','En revisión'=>'bg-info text-dark','Reabierto'=>'bg-primary',default=>'bg-warning text-dark'}; ?>
                    <tr class="checklist-record-row" tabindex="0" role="button" data-detail="<?= htmlspecialchars(json_encode($checklistPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>" data-can-respond="<?= $canRespondChecklist?'1':'0' ?>" data-can-review="<?= $canReviewChecklist?'1':'0' ?>" data-can-reopen="<?= $canReopenChecklist?'1':'0' ?>">
                    <?php if($isPartner): ?>
                      <td><div><strong><?= htmlspecialchars($checklistCode) ?></strong><small class="obs-mobile-context"><?= htmlspecialchars($checklistPayload['ubicacion']?:'Ubicación no registrada') ?><br>Supervisor: <?= htmlspecialchars($checklistPayload['supervisor']?:'Sin asignar') ?></small></div></td><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_registro']))) ?></td><td><strong><?= htmlspecialchars($r['cuadrilla']?:'Sin cuadrilla') ?></strong></td><td><?= in_array($checklistPayload['estado'],['Pendiente','Reabierto'],true)&&$r['fecha_limite_respuesta']?htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta']))):'Plazo detenido' ?></td><td><span class="badge <?= $checklistStateClass ?>"><?= htmlspecialchars($checklistStateLabel) ?></span></td><td><span class="text-primary fw-bold small"><i class="bi bi-box-arrow-in-right me-1"></i>Ver detalle</span></td>
                    <?php else: ?>
                      <td class="text-nowrap"><strong><?= htmlspecialchars(date('d/m/Y',strtotime($r['fecha_registro']))) ?></strong><small class="d-block text-muted"><?= htmlspecialchars(date('H:i',strtotime($r['fecha_registro']))) ?></small></td>
                      <td><span class="badge bg-primary">Checklist</span></td><td><strong class="text-dark"><?= htmlspecialchars($checklistCode) ?></strong></td>
                      <td><strong><?= htmlspecialchars($r['cuadrilla']?:'Sin cuadrilla') ?></strong></td><td><?= htmlspecialchars($r['nombre_partner']?:'Sin partner') ?></td>
                      <td class="obs-location"><strong><?= htmlspecialchars($r['departamento']?:'—') ?></strong><small class="d-block text-muted"><?= htmlspecialchars(implode(' · ',array_filter([$r['provincia'],$r['distrito']]))) ?></small></td>
                      <td><strong><?= count($itemsChecklist) ?></strong> observación<?= count($itemsChecklist)===1?'':'es' ?></td>
                      <td class="obs-comment"><?= nl2br(htmlspecialchars($r['comentario_general']?:'Sin comentario general')) ?></td>
                      <td><?= htmlspecialchars($r['supervisor_nombre']?:'Sin asignar') ?></td>
                      <td class="text-nowrap"><span class="badge <?= $checklistStateClass ?>"><?= htmlspecialchars($checklistStateLabel) ?></span><?php if(in_array($checklistPayload['estado'],['Pendiente','Reabierto'],true)): ?><small class="d-block mt-2 text-muted">Levantar hasta</small><strong><?= htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta']))) ?></strong><?php elseif($checklistPayload['estado']==='En revisión'): ?><small class="d-block mt-2 text-muted">Respuesta recibida</small><?php endif; ?></td>
                      <td><span class="text-primary fw-bold small me-2"><i class="bi bi-eye me-1"></i>Ver observaciones</span><a class="btn btn-sm btn-outline-danger text-nowrap" data-no-row-open href="api/observaciones_pdf.php?<?= htmlspecialchars($pdfCuadrilla,ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-file-earmark-pdf-fill me-1"></i>PDF cuadrilla</a></td>
                    <?php endif; ?></tr>
                    <?php elseif($isPartner): ?>
                    <tr class="<?= $r['observacion_id']?'field-record-row':'' ?>" tabindex="<?= $r['observacion_id']?'0':'-1' ?>" <?= $r['observacion_id']?'role="button" title="Ver detalle"':'' ?>><td><div><strong><?= htmlspecialchars($r['ticket_codigo']?:$r['codigo_supervision']) ?></strong><small class="obs-mobile-context"><?= htmlspecialchars($detailPayload['ubicacion']?:'Ubicación no registrada') ?><br>Supervisor: <?= htmlspecialchars($detailPayload['supervisor']) ?></small></div></td><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_registro']))) ?></td><td><strong><?= htmlspecialchars($r['cuadrilla']?:'Sin cuadrilla') ?></strong></td><td><?= $r['fecha_limite_respuesta']?htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta']))):'No aplica' ?></td><td><span class="badge <?= in_array($r['estado_respuesta'],['Levantamiento validado','Apelación Aprobada'],true)?'bg-success':(in_array($r['estado_respuesta'],['Levantamiento rechazado','Penalidad Directa','Apelación Rechazada'],true)?'bg-danger':($r['estado_respuesta']==='En revisión'?'bg-info text-dark':'bg-warning text-dark')) ?>"><?= htmlspecialchars($r['estado_respuesta']?:'Pendiente') ?></span></td><td><?php if($r['observacion_id']): ?><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-secondary btn-detalle-flujo text-nowrap" data-detail="<?= htmlspecialchars(json_encode($flowPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-eye me-1"></i>Ver detalle</button><?php if($puedeResponder): ?><button type="button" class="btn btn-sm btn-win btn-descargo text-nowrap" data-id="<?= (int)$r['observacion_id'] ?>" data-codigo="<?= htmlspecialchars($flowPayload['codigo'],ENT_QUOTES,'UTF-8') ?>" data-title="<?= htmlspecialchars($r['titulo'],ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-reply me-1"></i>Responder</button><?php endif; ?></div><?php endif; ?></td></tr>
                    <?php else: ?>
                    <tr class="<?= $r['observacion_id']?'field-record-row':'' ?>">
                        <td class="text-nowrap"><strong><?= htmlspecialchars(date('d/m/Y', strtotime($r['fecha_registro']))) ?></strong><small class="d-block text-muted"><?= htmlspecialchars(date('H:i', strtotime($r['fecha_registro']))) ?></small></td>
                        <td><span class="badge obs-type <?= $r['tipo_registro']==='Checklist' ? 'bg-primary' : ($r['tipo_registro']==='Hallazgo' ? 'bg-danger' : 'bg-secondary') ?>"><?= htmlspecialchars($r['tipo_registro']) ?></span><?php if($r['estado_item']): ?><small class="d-block mt-1 fw-bold text-muted"><?= htmlspecialchars($r['estado_item']) ?></small><?php endif; ?></td>
                        <td><strong class="text-dark"><?= htmlspecialchars(trim((string)$r['ticket_codigo']) !== '' ? $r['ticket_codigo'] : 'No aplica') ?></strong><small class="d-block text-muted"><?= htmlspecialchars($r['codigo_supervision']) ?></small></td>
                        <td><strong><?= htmlspecialchars($r['cuadrilla'] ?: 'Sin cuadrilla') ?></strong></td>
                        <td><?= htmlspecialchars($r['nombre_partner'] ?: 'Sin partner') ?></td>
                        <td class="obs-location"><strong><?= htmlspecialchars($r['departamento'] ?: '—') ?></strong><small class="d-block text-muted"><?= htmlspecialchars(implode(' · ', array_filter([$r['provincia'],$r['distrito']]))) ?></small></td>
                        <td style="min-width:190px"><?php if($photoEntries): ?><?php foreach($photoEntries as $i=>$entry): ?><div class="d-flex gap-2 align-items-start mb-2"><button type="button" class="btn p-0 border-0 bg-transparent obs-photo-link flex-shrink-0" data-photo="<?= htmlspecialchars($entry['url'],ENT_QUOTES,'UTF-8') ?>" data-title="Evidencia <?= $i+1 ?> · <?= htmlspecialchars($r['codigo_supervision'],ENT_QUOTES,'UTF-8') ?>"><img class="obs-photo" src="<?= htmlspecialchars($entry['url']) ?>" alt="Evidencia <?= $i+1 ?>" loading="lazy"></button><?php if($entry['comment']): ?><small class="text-muted text-wrap"><?= htmlspecialchars($entry['comment']) ?></small><?php endif; ?></div><?php endforeach; ?><?php else: ?><span class="text-muted small">Sin fotos</span><?php endif; ?></td>
                        <td class="obs-comment"><strong class="d-block mb-1" style="color:#d95112"><?= htmlspecialchars($r['titulo']) ?></strong><?= nl2br(htmlspecialchars($r['comentario'])) ?></td>
                        <td><?= htmlspecialchars($r['supervisor_nombre'] ?: 'Sin asignar') ?></td>
                        <td class="text-nowrap"><?php if($r['observacion_id']): ?><span class="badge <?= in_array($r['estado_respuesta'],['Levantamiento validado','Apelación Aprobada'],true)?'bg-success':(in_array($r['estado_respuesta'],['Levantamiento rechazado','Penalidad Directa','Apelación Rechazada'],true)?'bg-danger':($r['estado_respuesta']==='En revisión'?'bg-info text-dark':'bg-warning text-dark')) ?>"><?= htmlspecialchars($r['estado_respuesta']) ?></span><small class="d-block mt-2 text-muted">Regularizar hasta</small><strong><?= htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta']))) ?></strong><?php if(($isDirectSupervisor&&!in_array($r['estado_respuesta'],['Levantamiento validado','Penalidad Directa'],true))||($esAdministrador&&$r['estado_respuesta']!=='Levantamiento validado')): ?><button type="button" class="btn btn-link btn-sm p-0 d-block btn-editar-plazo" data-id="<?= (int)$r['observacion_id'] ?>" data-fecha="<?= htmlspecialchars(date('Y-m-d\TH:i',strtotime($r['fecha_limite_respuesta'])),ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-calendar-event me-1"></i>Editar fecha</button><?php endif; ?><?php elseif($r['tipo_registro']==='Checklist' && $r['fecha_limite_respuesta']): ?><span class="badge bg-warning text-dark">Pendiente</span><small class="d-block mt-2 text-muted">Regularizar hasta</small><strong><?= htmlspecialchars(date('d/m/Y H:i',strtotime($r['fecha_limite_respuesta']))) ?></strong><?php else: ?><span class="text-muted">No aplica</span><?php endif; ?></td>
                        <td><?php if($r['observacion_id']): ?>
                            <?php if($isPartner && in_array($r['estado_respuesta'],['Pendiente','Levantamiento rechazado','Apelación Rechazada'],true)): ?><button class="btn btn-sm btn-win btn-descargo text-nowrap" data-id="<?= (int)$r['observacion_id'] ?>" data-title="<?= htmlspecialchars($r['titulo'],ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Ingresar levantamiento</button>
                            <?php elseif(!$isPartner && $r['estado_respuesta']==='En revisión'): ?><?php $descargoFotos=[];for($di=1;$di<=3;$di++){if(!empty($r['descargo_foto_'.$di]))$descargoFotos[]=['url'=>$r['descargo_foto_'.$di],'comentario'=>$r['descargo_comentario_'.$di]??''];} ?><button class="btn btn-sm btn-dark btn-revision" data-id="<?= (int)$r['observacion_id'] ?>" data-title="<?= htmlspecialchars($r['titulo'],ENT_QUOTES,'UTF-8') ?>" data-respuesta="<?= htmlspecialchars($r['respuesta_partner'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-respondido-por="<?= htmlspecialchars($r['respondido_por_nombre'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-fotos='<?= htmlspecialchars(json_encode($descargoFotos,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>'><i class="bi bi-search me-1"></i>Revisar descargo</button>
                            <?php else: ?><button class="btn btn-sm btn-outline-secondary btn-detalle-flujo" data-detail="<?= htmlspecialchars(json_encode($flowPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>" data-titulo="<?= htmlspecialchars($r['titulo'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-observacion="<?= htmlspecialchars($r['comentario'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-gravedad="<?= htmlspecialchars($r['estado_item'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-evidencias="<?= htmlspecialchars(json_encode($photoEntries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>" data-respuesta="<?= htmlspecialchars($r['respuesta_partner'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-respondido-por="<?= htmlspecialchars($r['respondido_por_nombre'] ?? '',ENT_QUOTES,'UTF-8') ?>" data-evaluacion="<?= htmlspecialchars($r['sustentacion_evaluacion'] ?? '',ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-eye me-1"></i>Ver detalle</button><?php endif; ?>
                        <?php elseif($r['tipo_registro']==='Checklist'): ?><?php $pdfChecklistQuery=http_build_query(['tipo_registro'=>'Checklist','supervision_id'=>(int)$r['supervision_id']]); ?><a class="btn btn-sm btn-outline-danger text-nowrap" href="api/observaciones_pdf.php?<?= htmlspecialchars($pdfChecklistQuery,ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-file-earmark-pdf-fill me-1"></i>PDF observado</a><?php endif; ?></td>
                    </tr><?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($observationTotal > $observationPageData['per_page']): ?>
    <?php
      $previousObservationQuery = array_merge($filters,['page'=>'registro_observaciones','pagina_observaciones'=>max(1,$observationPage-1)]);
      $nextObservationQuery = array_merge($filters,['page'=>'registro_observaciones','pagina_observaciones'=>$observationPage+1]);
      $lastObservationPage = max(1,(int)ceil($observationTotal/$observationPageData['per_page']));
    ?>
    <nav class="d-flex justify-content-between align-items-center gap-3 mt-3" aria-label="Paginación del registro de observaciones">
      <a class="btn btn-outline-secondary <?= $observationPage<=1?'disabled':'' ?>" <?= $observationPage<=1?'aria-disabled="true" tabindex="-1"':'' ?> href="index.php?<?= htmlspecialchars(http_build_query($previousObservationQuery),ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-arrow-left me-1"></i>Anterior</a>
      <span class="small text-muted fw-semibold">Página <?= $observationPage ?> de <?= $lastObservationPage ?></span>
      <a class="btn btn-outline-dark <?= !$observationPageData['has_more']?'disabled':'' ?>" <?= !$observationPageData['has_more']?'aria-disabled="true" tabindex="-1"':'' ?> href="index.php?<?= htmlspecialchars(http_build_query($nextObservationQuery),ENT_QUOTES,'UTF-8') ?>">Siguiente<i class="bi bi-arrow-right ms-1"></i></a>
    </nav>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalChecklistDetail" tabindex="-1" aria-labelledby="checklistDetailTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><form id="formChecklistResponse" class="modal-content" enctype="multipart/form-data"><div class="modal-header bg-dark text-white"><div><h5 class="modal-title mb-1" id="checklistDetailTitle"><i class="bi bi-clipboard2-check-fill me-2 text-warning"></i>Observaciones del checklist</h5><small id="checklistDetailCrew" class="text-white-50"></small></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><input type="hidden" name="supervision_id" id="checklistSupervisionId"><div id="checklistDetailMeta" class="alert alert-light border mb-3"></div><div id="checklistDetailItems" class="row g-3"></div><div id="checklistGeneralOriginal" class="mt-3"></div><div id="checklistResponseArea" class="mt-4"><label for="checklistGeneralResponse" class="form-label fw-bold">Comentario general del levantamiento *</label><textarea id="checklistGeneralResponse" class="form-control" name="respuesta_general" rows="4" maxlength="2000" required></textarea></div><div id="checklistReviewArea" class="mt-4 d-none"><div class="p-3 rounded border bg-light mb-3"><strong>Comentario general presentado por el partner</strong><div id="checklistPartnerGeneral" class="mt-1"></div></div><label for="checklistReviewComment" class="form-label fw-bold">Criterio del supervisor directo *</label><textarea id="checklistReviewComment" class="form-control" rows="3" maxlength="2000"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button id="checklistRejectButton" class="btn btn-outline-danger d-none" type="button"><i class="bi bi-x-circle me-1"></i>Rechazar levantamiento</button><button id="checklistApproveButton" class="btn btn-success d-none" type="button"><i class="bi bi-check-circle me-1"></i>Aprobar levantamiento</button><button id="checklistSubmitButton" class="btn btn-win" type="submit"><i class="bi bi-send-fill me-1"></i>Enviar levantamiento</button></div></form></div></div>

<div class="modal fade" id="modalObservationPhoto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content bg-dark"><div class="modal-header border-secondary"><h6 class="modal-title text-white" id="observationPhotoTitle">Evidencia</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center p-2"><img id="observationPhotoImage" class="obs-photo-modal-img" alt="Evidencia ampliada"></div></div></div>
</div>

<div class="modal fade" id="modalDescargo" tabindex="-1" aria-labelledby="descargoModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><form id="formDescargo" class="modal-content" enctype="multipart/form-data"><div class="modal-header"><div><h5 class="modal-title" id="descargoModalTitle"><i class="bi bi-reply me-2 text-primary"></i>Responder observación</h5><small class="text-muted" id="descargoCodigo"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><input type="hidden" name="observacion_id" id="descargoObsId"><p id="descargoTitle" class="fw-bold mb-3"></p><h6 class="sgs-obs-seccion-titulo">Fotos del levantamiento (al menos 1, hasta 3)</h6><div class="row g-3 mb-3"><?php for($i=1;$i<=3;$i++): ?><div class="col-md-4"><label for="descargoFoto<?= $i ?>" class="form-label">Foto <?= $i ?></label><input id="descargoFoto<?= $i ?>" class="form-control form-control-sm" type="file" name="descargo_foto_<?= $i ?>" accept="image/jpeg,image/png,image/webp"></div><?php endfor; ?></div><label for="descargoRespuestaGeneral" class="form-label">Comentario del levantamiento *</label><textarea id="descargoRespuestaGeneral" class="form-control" name="respuesta_partner" rows="4" maxlength="2000" required placeholder="Explique qué se corrigió; vale para todas las fotos..."></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-win" type="submit"><i class="bi bi-send-fill me-1"></i>Enviar a revisión</button></div></form></div></div>

<div class="modal fade" id="modalRevision" tabindex="-1" aria-labelledby="revisionModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><form id="formRevision" class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title" id="revisionModalTitle"><i class="bi bi-shield-check me-2 text-warning"></i>Validación del descargo del partner</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><input type="hidden" id="revisionObsId"><p id="revisionTitle" class="fw-bold mb-1"></p><p id="revisionRespondidoPor" class="small text-muted mb-3"></p><div class="p-3 rounded border bg-light mb-3"><small class="fw-bold text-muted d-block mb-1">Comentario general del partner</small><div id="revisionRespuesta"></div></div><div id="revisionFotos" class="d-flex flex-wrap gap-2 mb-3"></div><label for="revisionComentario" class="form-label fw-bold">Comentario de la validación *</label><textarea id="revisionComentario" class="form-control" rows="3" maxlength="2000" required placeholder="Indique por qué valida o rechaza el descargo..."></textarea></div><div class="modal-footer"><button type="submit" data-aprobar="0" class="btn btn-outline-danger revision-submit"><i class="bi bi-x-circle me-1"></i>Descargo no válido</button><button type="submit" data-aprobar="1" class="btn btn-success revision-submit"><i class="bi bi-check-circle me-1"></i>Validar y cerrar</button></div></form></div></div>

<div class="modal fade" id="modalFlowDetail" tabindex="-1" aria-labelledby="flowTituloVentana" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="flowTituloVentana"><i class="bi bi-clipboard2-pulse me-2 text-primary"></i>Detalle de la observación</h5>
          <small class="text-muted" id="flowCodigoVentana"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body sgs-obs-detalle">
        <div class="sgs-obs-estado" id="flowEstado"></div>
        <section class="sgs-obs-seccion">
          <h6 class="sgs-obs-seccion-titulo">Datos de la orden</h6>
          <dl class="sgs-obs-datos" id="flowDatos"></dl>
        </section>
        <section class="sgs-obs-seccion">
          <h6 class="sgs-obs-seccion-titulo">Hallazgo registrado</h6>
          <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
            <strong class="sgs-obs-hallazgo" id="flowTitulo"></strong>
            <span class="badge" id="flowGravedad"></span>
          </div>
          <p class="mb-3 mt-2" id="flowObservacion"></p>
          <div class="sgs-obs-fotos" id="flowEvidencias"></div>
        </section>
        <section class="sgs-obs-seccion">
          <h6 class="sgs-obs-seccion-titulo">Seguimiento</h6>
          <ol class="sgs-obs-seguimiento" id="flowSeguimiento"></ol>
        </section>
      </div>
      <div class="modal-footer d-none" id="flowAcciones">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="flowEliminar"><i class="bi bi-trash3 me-1"></i>Eliminar observación</button>
        <button type="button" class="btn btn-outline-secondary d-none" id="flowAmpliar"><i class="bi bi-calendar-plus me-1"></i>Ampliar plazo</button>
        <button type="button" class="btn btn-win d-none" id="flowResponder"><i class="bi bi-reply me-1"></i>Responder</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modalEliminarObs" tabindex="-1" aria-labelledby="eliminarObsTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="formEliminarObs">
      <div class="modal-header">
        <h5 class="modal-title" id="eliminarObsTitulo"><i class="bi bi-trash3 me-2 text-danger"></i>Eliminar observación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger small mb-3">Se borrará la observación del pedido <strong id="eliminarObsCodigo"></strong> y dejará de contar en reportes y penalidades. Queda una copia en el registro de auditoría.</div>
        <label for="eliminarObsMotivo" class="form-label">Motivo de la eliminación</label>
        <textarea class="form-control" id="eliminarObsMotivo" rows="3" minlength="5" maxlength="500" required placeholder="Por ejemplo: registrada por error en otra orden"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger" id="eliminarObsConfirmar"><i class="bi bi-trash3 me-1"></i>Eliminar</button>
      </div>
    </form>
  </div>
</div>
<div class="modal fade" id="modalPlazo" tabindex="-1" aria-labelledby="plazoModalTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form id="formPlazo" class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title" id="plazoModalTitle">Editar fecha de levantamiento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><input type="hidden" id="plazoObsId"><label for="plazoFecha" class="form-label fw-bold">Fecha y hora máxima para regularizar</label><input type="datetime-local" id="plazoFecha" class="form-control" required></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-win" type="submit">Guardar fecha</button></div></form></div></div></div>
</div>
<script src="03_FRONTEND/src/assets/js/registro_observaciones.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
