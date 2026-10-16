<?php
// Cuadrillas (2026-10-15): cada técnico líder con su técnico de apoyo habitual y su vehículo. Se arman solas al cerrar
// supervisiones con "Guardar el T2 y el vehículo" activado. El personal administrativo además puede editarlas aquí
// (emergencias) y cargar la lista de técnicos líderes por Excel (nuevos, cambios de nomenclatura e inhabilitados).
// Zona (2026-10-15): cada técnico líder tiene provincia y departamento; filtra la lista en el formulario de supervisión.
// Editor (2026-10-15): datos del T1 y del T2 (DNI, celular), cambiar de T2 o registrar uno nuevo.
// Link de examen (2026-10-15): para el T1 o el T2 de cada fila; se envía por WhatsApp o se copia (api/examenes.php).
// Vencimientos (2026-10-15): SOAT, revisión técnica, extintor y brevete van en la columna Vehículo con su color; al tocarlos
// se abre su historial y se actualizan fechas (vencimientos.js, api/vencimientos.php). Filtro "docs" por estado de documentos.
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$esDirecto = ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
$puedeEditar = Auth::isAdministrative();
if (!$puedeEditar && !$esDirecto) {
    echo '<div class="alert alert-danger m-4">Acceso reservado al personal administrativo y a los supervisores directos.</div>';
    return;
}
$pdo = Database::getConnection();
$partnerFiltro = (int)($_GET['partner_id'] ?? 0);
$busqueda = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$estadoFiltro = in_array($_GET['estado'] ?? '', ['completas', 'incompletas'], true) ? $_GET['estado'] : '';
$operativoFiltro = in_array($_GET['operativo'] ?? '', ['todos', 'Inhabilitado', 'Blacklist'], true) ? $_GET['operativo'] : '';
$zonaFiltro = mb_substr(trim((string)($_GET['zona'] ?? '')), 0, 100);
$docsFiltro = in_array($_GET['docs'] ?? '', ['alerta', 'malo', 'regular', 'sin_registrar'], true) ? $_GET['docs'] : '';
$zonasRegistradas = $pdo->query("SELECT DISTINCT departamento FROM tecnicos WHERE COALESCE(tipo_tecnico, 1) = 1 AND departamento IS NOT NULL AND departamento <> '' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
$partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY nombre_partner")->fetchAll();

$where = ['COALESCE(t.tipo_tecnico, 1) = 1'];
$params = [];
if ($operativoFiltro === '') $where[] = "t.estado_operativo = 'Habilitado'";
elseif ($operativoFiltro !== 'todos') { $where[] = 't.estado_operativo = :operativo'; $params[':operativo'] = $operativoFiltro; }
if ($partnerFiltro > 0) { $where[] = 't.partner_id = :partner'; $params[':partner'] = $partnerFiltro; }
// Zona del usuario (2026-10-15): el supervisor ve solo las cuadrillas de sus departamentos (más las que aún no tienen
// zona, como en el formulario). El personal administrativo y la sede "Provincias" ven todas.
$zonasUsuario = $puedeEditar ? null : sgsUserZones((int)$user['id']);
if ($zonasUsuario !== null) {
    $zp = [];
    $where[] = '(' . sgsZoneSql('t.departamento', (int)$user['id'], $zp, ':uz') . " OR t.departamento IS NULL OR t.departamento = '')";
    $params += $zp;
    $zonasRegistradas = array_values(array_filter($zonasRegistradas, static fn($z) => in_array(mb_strtoupper(trim($z), 'UTF-8'), $zonasUsuario, true)));
}
if ($zonaFiltro === 'sin_zona') $where[] = "(t.departamento IS NULL OR t.departamento = '')";
elseif ($zonaFiltro !== '') { $where[] = 't.departamento = :zona'; $params[':zona'] = $zonaFiltro; }
if ($busqueda !== '') {
    $where[] = '(t.nombre_tecnico LIKE :q1 OR ta.nombre_tecnico LIKE :q2 OR v.placa LIKE :q3 OR t.dni LIKE :q4)';
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
}
$stmt = $pdo->prepare("SELECT t.id, t.nombre_tecnico, t.dni, t.celular, t.estado_operativo, t.apoyo_id, t.vehiculo_id, t.provincia, t.departamento, p.nombre_partner,
        ta.nombre_tecnico AS apoyo_nombre, ta.dni AS apoyo_dni, ta.celular AS apoyo_celular, v.placa, v.tipo AS vehiculo_tipo, v.estado_conservacion AS vehiculo_estado,
        (SELECT COUNT(*) FROM supervisiones s WHERE s.tecnico1_id = t.id) AS supervisiones,
        (SELECT MAX(s.fecha_registro) FROM supervisiones s WHERE s.tecnico1_id = t.id) AS ultima_supervision
    FROM tecnicos t
    LEFT JOIN partners p ON p.id = t.partner_id
    LEFT JOIN tecnicos ta ON ta.id = t.apoyo_id
    LEFT JOIN vehiculos v ON v.id = t.vehiculo_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY (t.apoyo_id IS NULL AND t.vehiculo_id IS NULL), p.nombre_partner, t.nombre_tecnico
    LIMIT 1000");
$stmt->execute($params);
$filas = $stmt->fetchAll();
$total = count($filas);
$completas = count(array_filter($filas, static fn($f) => $f['apoyo_nombre'] && $f['placa']));
// Documentos de cada cuadrilla: SOAT, revisión técnica y extintor del vehículo; brevete del técnico líder.
require_once BACKEND_PATH . '/config/vencimientos.php';
$vigentes = sgsDocumentosVigentes($pdo, array_column($filas, 'vehiculo_id'), array_column($filas, 'id'));
foreach ($filas as &$fila) {
    $fila['docs'] = [];
    foreach (SGS_VENCIMIENTOS as $tipo => $cfg) {
        $id = $cfg['sujeto'] === 'vehiculo' ? (int)$fila['vehiculo_id'] : (int)$fila['id'];
        if (!$id) continue; // sin vehículo no hay SOAT, revisión técnica ni extintor que controlar
        $d = $vigentes[$tipo . '|' . ($cfg['sujeto'] === 'vehiculo' ? 'v' : 't') . $id] ?? null;
        $fila['docs'][$tipo] = sgsEstadoVencimiento($tipo, $d['fecha_inicio'] ?? null, $d['fecha_vencimiento'] ?? null) + ['vence' => $d['fecha_vencimiento'] ?? null];
    }
    $fila['docs_estados'] = array_column($fila['docs'], 'estado');
}
unset($fila);
$conVencidos = count(array_filter($filas, static fn($f) => in_array('malo', $f['docs_estados'], true)));
if ($estadoFiltro !== '') {
    $filas = array_values(array_filter($filas, static fn($f) => ($estadoFiltro === 'completas') === (bool)($f['apoyo_nombre'] && $f['placa'])));
}
$cumpleDocs = static fn(array $f, string $filtro): bool => $filtro === '' || ($filtro === 'alerta'
    ? (in_array('malo', $f['docs_estados'], true) || in_array('regular', $f['docs_estados'], true))
    : in_array($filtro, $f['docs_estados'], true));
$cuentaDocs = [];
foreach (['', 'alerta', 'malo', 'regular', 'sin_registrar'] as $filtro) $cuentaDocs[$filtro] = count(array_filter($filas, static fn($f) => $cumpleDocs($f, $filtro)));
if ($docsFiltro !== '') $filas = array_values(array_filter($filas, static fn($f) => $cumpleDocs($f, $docsFiltro)));
$apoyos = $puedeEditar ? $pdo->query("SELECT t.id, t.nombre_tecnico, t.dni, t.celular, p.nombre_partner FROM tecnicos t LEFT JOIN partners p ON p.id = t.partner_id
    WHERE t.tipo_tecnico = 2 AND t.estado_operativo <> 'Blacklist' ORDER BY t.nombre_tecnico")->fetchAll() : [];
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
require_once BACKEND_PATH . '/config/tecnicos_lideres.php'; // sgsTextoZona y la lista de provincias
$sinZona = count(array_filter($filas, static fn($f) => trim((string)$f['departamento']) === ''));
$etiquetaDoc = ['SOAT' => 'SOAT', 'Revisión técnica' => 'Rev. técnica', 'Extintor' => 'Extintor', 'Brevete' => 'Brevete'];
$urlDocs = static function (string $filtro): string {
    $q = $_GET; $q['page'] = 'cuadrillas';
    if ($filtro === '') unset($q['docs']); else $q['docs'] = $filtro;
    return 'index.php?' . http_build_query($q);
};
$claseVehiculo = ['Buen estado' => 'bg-success', 'Regular' => 'bg-warning', 'Mal estado' => 'bg-danger'];
$claseOperativo = ['Habilitado' => 'bg-success', 'Inhabilitado' => 'bg-secondary', 'Blacklist' => 'bg-danger'];
$columnas = 6;
?>
<div class="container-fluid p-3 p-md-4">
  <?php if ($puedeEditar): ?>
  <div class="sgs-page-actions">
    <a class="btn btn-outline-secondary" href="api/tecnicos.php?action=exportar_lideres"><i class="bi bi-file-earmark-arrow-down me-1"></i>Descargar lista (Excel)</a>
    <button type="button" class="btn btn-win" id="btnCargarLideres"><i class="bi bi-file-earmark-arrow-up me-1"></i>Cargar técnicos líderes</button>
  </div>
  <?php endif; ?>
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
      <small class="sgs-kpi-label">Técnicos líderes</small>
      <h3 class="mb-0 sgs-kpi-value"><?= $total ?></h3>
    </div></div>
    <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--success p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
      <small class="sgs-kpi-label">Cuadrillas completas</small>
      <h3 class="mb-0 sgs-kpi-value"><?= $completas ?></h3>
    </div></div>
    <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
      <small class="sgs-kpi-label">Por completar</small>
      <h3 class="mb-0 sgs-kpi-value"><?= $total - $completas ?></h3>
    </div></div>
    <div class="col-6 col-lg-3"><a class="sgs-kpi <?= $conVencidos ? 'sgs-kpi--danger' : '' ?> p-3 h-100 d-block text-decoration-none" href="<?= $e($urlDocs('malo')) ?>" title="Ver las cuadrillas con documentos vencidos">
      <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>
      <small class="sgs-kpi-label">Con documentos vencidos</small>
      <h3 class="mb-0 sgs-kpi-value"><?= $conVencidos ?></h3>
    </a></div>
  </div>

  <form class="card sgs-toolbar-card p-3 mb-3" method="get">
    <input type="hidden" name="page" value="cuadrillas">
    <?php if ($docsFiltro !== ''): ?><input type="hidden" name="docs" value="<?= $e($docsFiltro) ?>"><?php endif; ?>
    <div class="sgs-toolbar">
      <div class="sgs-segmented" role="group" aria-label="Documentos (SOAT, revisión técnica, extintor, brevete)">
        <?php foreach (['' => 'Todas', 'alerta' => 'Con alerta', 'malo' => 'Vencidos', 'regular' => 'Por vencer', 'sin_registrar' => 'Sin registrar'] as $clave => $texto): ?>
        <a class="filter-tab-btn <?= $docsFiltro === $clave ? 'active' : '' ?>" href="<?= $e($urlDocs($clave)) ?>"><?= $texto ?> <span class="sgs-seg-count"><?= $cuentaDocs[$clave] ?></span></a>
        <?php endforeach; ?>
      </div>
      <small class="text-muted cuad-docs-ayuda"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Documentos: SOAT, revisión técnica, extintor y brevete</small>
    </div>
    <div class="sgs-toolbar-row"><div class="sgs-filtros">
      <div class="sgs-filtro"><label class="form-label" for="cuadQ">Buscar</label>
        <div class="sgs-filtro-busqueda"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" id="cuadQ" name="q" maxlength="60" value="<?= $e($busqueda) ?>" placeholder="Técnico, DNI o placa"></div></div>
      <div class="sgs-filtro"><label class="form-label" for="cuadPartner">Partner</label>
        <select class="form-select" id="cuadPartner" name="partner_id">
          <option value="">Todos los partners</option>
          <?php foreach ($partners as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $partnerFiltro === (int)$p['id'] ? 'selected' : '' ?>><?= $e($p['nombre_partner']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="cuadZona">Zona</label>
        <select class="form-select" id="cuadZona" name="zona">
          <option value="">Todas las zonas</option>
          <?php foreach ($zonasRegistradas as $z): ?>
          <option value="<?= $e($z) ?>" <?= $zonaFiltro === $z ? 'selected' : '' ?>><?= $e($z) ?></option>
          <?php endforeach; ?>
          <option value="sin_zona" <?= $zonaFiltro === 'sin_zona' ? 'selected' : '' ?>>Sin zona</option>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="cuadEstado">Cuadrilla</label>
        <select class="form-select" id="cuadEstado" name="estado">
          <option value="">Todas</option>
          <option value="completas" <?= $estadoFiltro === 'completas' ? 'selected' : '' ?>>Completas</option>
          <option value="incompletas" <?= $estadoFiltro === 'incompletas' ? 'selected' : '' ?>>Por completar</option>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="cuadOperativo">Técnico líder</label>
        <select class="form-select" id="cuadOperativo" name="operativo">
          <option value="">Habilitados</option>
          <option value="Inhabilitado" <?= $operativoFiltro === 'Inhabilitado' ? 'selected' : '' ?>>Inhabilitados</option>
          <option value="Blacklist" <?= $operativoFiltro === 'Blacklist' ? 'selected' : '' ?>>Lista negra</option>
          <option value="todos" <?= $operativoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select></div>
      <button type="submit" class="btn btn-outline-secondary sgs-filtro-limpiar"><i class="bi bi-funnel me-1"></i>Filtrar</button>
      <a class="btn btn-outline-secondary sgs-filtro-limpiar" href="index.php?page=cuadrillas"><i class="bi bi-x-lg me-1"></i>Limpiar</a>
    </div></div>
  </form>

  <div class="card p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h5 class="mb-0"><i class="bi bi-people-fill me-2 text-primary"></i>Cuadrillas registradas</h5>
      <span class="sgs-counter"><?= count($filas) ?> <?= count($filas) === 1 ? 'técnico líder' : 'técnicos líderes' ?></span>
    </div>
    <p class="text-muted small">Se completan solas: al <strong>cerrar</strong> una supervisión con <strong>Guardar el T2 y el vehículo</strong> activado, el técnico de apoyo, el vehículo y su estado quedan como cuadrilla del técnico líder.<?= $puedeEditar ? ' Para una corrección urgente, use el lápiz de cada fila.' : '' ?> En el formulario de supervisión solo aparecen los técnicos líderes de la <strong>zona</strong> elegida.</p>
    <?php if ($zonasUsuario !== null): ?><p class="small mb-2"><i class="bi bi-geo-alt me-1 text-primary"></i>Se muestran las cuadrillas de su zona: <strong><?= $e($zonasUsuario ? implode(", ", $zonasUsuario) : "sin zona asignada") ?></strong>.</p><?php endif; ?>
    <?php if ($sinZona): ?><div class="alert alert-warning small py-2"><i class="bi bi-geo-alt me-1"></i><?= $sinZona ?> <?= $sinZona === 1 ? 'técnico líder no tiene' : 'técnicos líderes no tienen' ?> zona. Cargue el Excel con la columna <strong>PROVINCIA</strong><?= $puedeEditar ? ' o asígnela con el lápiz' : '' ?>; mientras tanto aparecen en cualquier zona.</div><?php endif; ?>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas cuad-tabla">
      <thead><tr><th>Técnico líder</th><th>Partner y zona</th><th title="Técnico de apoyo (T2)">Apoyo (T2)</th><th>Vehículo</th><th>Supervisiones</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="<?= $columnas ?>" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay técnicos líderes con estos filtros.</td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $f): ?>
        <tr>
          <td data-label="Técnico líder"><div><div class="fw-bold"><?= $e($f['nombre_tecnico']) ?></div>
            <small class="text-muted">DNI <?= $e($f['dni'] ?: '—') ?></small>
            <?php if (($f['estado_operativo'] ?? 'Habilitado') !== 'Habilitado'): ?> <span class="badge <?= $claseOperativo[$f['estado_operativo']] ?? 'bg-secondary' ?>"><?= $e($f['estado_operativo']) ?></span><?php endif; ?></div></td>
          <td data-label="Partner y zona"><div><div><?= $e($f['nombre_partner'] ?: '—') ?></div>
            <small class="<?= trim((string)$f['departamento']) === '' ? 'text-warning-emphasis' : 'text-muted' ?>"><i class="bi bi-geo-alt me-1"></i><?= trim((string)$f['departamento']) === '' ? 'Sin zona' : $e(sgsTextoZona($f['provincia'], $f['departamento'])) ?></small></div></td>
          <td data-label="Técnico de apoyo (T2)"><?= $f['apoyo_nombre'] ? $e($f['apoyo_nombre']) : '<span class="text-muted">Por definir</span>' ?></td>
          <td data-label="Vehículo"><div class="cuad-celda"><?php if ($f['placa']): ?><span class="fw-bold text-nowrap"><?= $e($f['placa']) ?><?= $f['vehiculo_tipo'] ? ' <small class="text-muted fw-normal">· ' . $e($f['vehiculo_tipo']) . '</small>' : '' ?></span>
            <?= $f['vehiculo_estado'] ? '<span class="badge ' . ($claseVehiculo[$f['vehiculo_estado']] ?? 'bg-secondary') . '">' . $e($f['vehiculo_estado']) . '</span>' : '' ?><?php else: ?><span class="text-muted">Sin vehículo</span><?php endif; ?>
            <div class="venc-chips" role="group" aria-label="Documentos de la cuadrilla">
              <?php foreach ($f['docs'] as $tipo => $doc): ?>
              <button type="button" class="venc-chip venc-chip--<?= $doc['estado'] ?>" data-vencimientos="<?= (int)$f['id'] ?>"
                title="<?= $e($tipo . ' · ' . SGS_VENC_ETIQUETAS[$doc['estado']] . ($doc['vence'] ? ' · vence el ' . date('d/m/Y', strtotime($doc['vence'])) . ' (' . $doc['texto'] . ')' : '')) ?>"><?= $e($etiquetaDoc[$tipo]) ?></button>
              <?php endforeach; ?>
            </div></div></td>
          <td data-label="Supervisiones"><div class="cuad-celda"><span class="fw-bold"><?= (int)$f['supervisiones'] ?></span>
            <?php if ($f['ultima_supervision']): ?><small class="text-muted text-nowrap" title="Última supervisión">Última: <?= date('d/m/Y', strtotime($f['ultima_supervision'])) ?></small><?php endif; ?></div></td>
          <td class="text-end"><div class="sgs-row-actions justify-content-end">
            <button type="button" class="sgs-icon-action" title="Link de examen" aria-label="Link de examen para la cuadrilla de <?= $e($f['nombre_tecnico']) ?>"
              data-examen="<?= (int)$f['id'] ?>"><i class="bi bi-link-45deg"></i></button>
            <?php if ($puedeEditar): ?>
            <button type="button" class="sgs-icon-action" title="Editar cuadrilla" aria-label="Editar cuadrilla de <?= $e($f['nombre_tecnico']) ?>"
              data-editar="<?= (int)$f['id'] ?>" data-nombre="<?= $e($f['nombre_tecnico']) ?>" data-partner="<?= $e($f['nombre_partner'] ?: '—') ?>"
              data-dni="<?= $e($f['dni']) ?>" data-celular="<?= $e($f['celular']) ?>"
              data-apoyo="<?= (int)$f['apoyo_id'] ?>" data-placa="<?= $e($f['placa']) ?>" data-tipo="<?= $e($f['vehiculo_tipo']) ?>"
              data-estado="<?= $e($f['vehiculo_estado']) ?>" data-operativo="<?= $e($f['estado_operativo']) ?>" data-provincia="<?= $e($f['provincia'] ?: $f['departamento']) ?>"><i class="bi bi-pencil"></i></button>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php if ($puedeEditar): ?>
<!-- ===== Editar cuadrilla ===== -->
<div class="modal fade" id="mCuadrilla" tabindex="-1" aria-labelledby="mCuadrillaTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="fCuadrilla" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="mCuadrillaTitulo"><i class="bi bi-people-fill me-2"></i>Editar cuadrilla</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <h6 class="cuad-seccion"><i class="bi bi-person-badge me-2" aria-hidden="true"></i>Técnico líder (T1)</h6>
        <p class="mb-2"><strong id="cuadNombre"></strong><br><small class="text-muted" id="cuadPartnerTexto"></small></p>
        <div class="row g-3 mb-3">
          <div class="col-sm-6"><label class="form-label" for="cuadT1Dni">DNI</label><input class="form-control" id="cuadT1Dni" name="t1_dni" inputmode="numeric" maxlength="8" placeholder="8 dígitos"></div>
          <div class="col-sm-6"><label class="form-label" for="cuadT1Cel">Celular</label><input class="form-control" id="cuadT1Cel" name="t1_celular" inputmode="tel" maxlength="9" placeholder="9XXXXXXXX"></div>
        </div>

        <h6 class="cuad-seccion"><i class="bi bi-person me-2" aria-hidden="true"></i>Técnico de apoyo (T2)</h6>
        <div class="mb-3"><label class="form-label" for="cuadApoyo">Técnico de apoyo</label>
          <select class="form-select" id="cuadApoyo" name="apoyo_id">
            <option value="0">Sin técnico de apoyo (T2)</option>
            <option value="nuevo">+ Registrar un técnico de apoyo nuevo</option>
            <?php foreach ($apoyos as $a): ?>
            <option value="<?= (int)$a['id'] ?>" data-dni="<?= $e($a['dni']) ?>" data-celular="<?= $e($a['celular']) ?>"><?= $e($a['nombre_tecnico'] . ($a['nombre_partner'] ? ' · ' . $a['nombre_partner'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Para cambiar de T2, elija otro. Si estaba en otra cuadrilla, sale de ella y pasa a esta (y a su partner y zona).</small></div>
        <div id="cuadT2Datos" class="d-none">
          <div class="mb-3 d-none" id="cuadT2NombreBox"><label class="form-label" for="cuadT2Nombre">Nombres y apellidos del nuevo T2</label><input class="form-control text-uppercase" id="cuadT2Nombre" name="t2_nombre" maxlength="120" autocomplete="off"></div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6"><label class="form-label" for="cuadT2Dni">DNI del T2</label><input class="form-control" id="cuadT2Dni" name="t2_dni" inputmode="numeric" maxlength="8" placeholder="8 dígitos"></div>
            <div class="col-sm-6"><label class="form-label" for="cuadT2Cel">Celular del T2</label><input class="form-control" id="cuadT2Cel" name="t2_celular" inputmode="tel" maxlength="9" placeholder="9XXXXXXXX"></div>
          </div>
        </div>

        <h6 class="cuad-seccion"><i class="bi bi-truck me-2" aria-hidden="true"></i>Vehículo</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-4"><label class="form-label" for="cuadPlaca">Placa</label><input class="form-control text-uppercase" id="cuadPlaca" name="placa" maxlength="10" placeholder="ABC-123"></div>
          <div class="col-sm-4"><label class="form-label" for="cuadTipo">Tipo de vehículo</label>
            <select class="form-select" id="cuadTipo" name="vehiculo_tipo"><option value="">Seleccione</option><option>Minivan</option><option>Auto</option><option>Moto</option><option>Otro</option></select></div>
          <div class="col-sm-4"><label class="form-label" for="cuadEstadoVeh">Estado</label>
            <select class="form-select" id="cuadEstadoVeh" name="vehiculo_estado"><option value="">Seleccione</option><option>Buen estado</option><option>Regular</option><option>Mal estado</option></select></div>
        </div>
        <small class="text-muted d-block mb-3">Placa vacía = sin vehículo. Un vehículo es de una sola cuadrilla: si lo tenía otro líder, se le quita.</small>

        <h6 class="cuad-seccion"><i class="bi bi-geo-alt me-2" aria-hidden="true"></i>Zona y estado</h6>
        <div class="mb-3"><label class="form-label" for="cuadProvincia">Provincia (zona)</label>
          <input class="form-control text-uppercase" id="cuadProvincia" name="provincia" maxlength="100" list="listaProvincias" placeholder="Ej.: TRUJILLO" autocomplete="off">
          <datalist id="listaProvincias"><?php foreach (sgsProvinciasPorDepartamento() as $dep => $provs) foreach ($provs as $pv): ?><option value="<?= $e($pv) ?>"><?= $e($dep) ?></option><?php endforeach; ?></datalist>
          <small class="text-muted">El departamento se busca solo. Vacía = sin zona (aparece en todas).</small></div>
        <div><label class="form-label" for="cuadOperativoEdit">Estado del técnico líder</label>
          <select class="form-select" id="cuadOperativoEdit" name="estado_operativo"><option>Habilitado</option><option>Inhabilitado</option><option value="Blacklist">Lista negra</option></select></div>
        <div class="alert alert-danger d-none mt-3 mb-0" id="cuadError" role="alert"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win" id="btnGuardarCuadrilla"><i class="bi bi-check2 me-1"></i>Guardar cuadrilla</button></div>
    </form>
  </div></div>
</div>

<!-- ===== Cargar técnicos líderes ===== -->
<div class="modal fade" id="mCargaLideres" tabindex="-1" aria-labelledby="mCargaTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="mCargaTitulo"><i class="bi bi-file-earmark-arrow-up me-2"></i>Cargar técnicos líderes</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <p class="small text-muted mb-2">Excel (.xlsx) con tres columnas: <strong>técnico líder</strong> (con su nomenclatura), <strong>partner</strong> y <strong>provincia</strong>. Debe traer <strong>todos</strong> los técnicos líderes vigentes:</p>
      <ul class="small text-muted mb-3">
        <li>Los que ya están quedan igual. Si cambió la nomenclatura (mismo nombre, otro inicio) o el partner, se actualizan.</li>
        <li>Los que no están en el sistema se agregan.</li>
        <li>Con la <strong>provincia</strong> el sistema busca su departamento y lo guarda como su <strong>zona</strong>. Si un técnico se movió de zona, se actualiza. Sin provincia, su zona no cambia.</li>
        <li>Los habilitados en el sistema que <strong>no</strong> vienen en el archivo se <strong>inhabilitan</strong> (no se borran; conservan su historial).</li>
      </ul>
      <label class="form-label" for="archivoLideres">Archivo</label>
      <input type="file" class="form-control mb-3" id="archivoLideres" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
      <div id="cargaResultado"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      <button type="button" class="btn btn-outline-secondary" id="btnRevisarLideres"><i class="bi bi-search me-1"></i>Revisar archivo</button>
      <button type="button" class="btn btn-win d-none" id="btnAplicarLideres"><i class="bi bi-check2-all me-1"></i>Aplicar cambios</button>
    </div>
  </div></div>
</div>
<?php endif; ?>

<!-- ===== Link de examen ===== -->
<div class="modal fade" id="mExamen" tabindex="-1" aria-labelledby="mExamenTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="mExamenTitulo"><i class="bi bi-link-45deg me-2"></i>Link de examen</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <div id="exCargando" class="text-muted small">Cargando…</div>
      <div id="exContenido" class="d-none">
        <label class="form-label">Técnico</label>
        <div class="sgs-segmented mb-3 ex-personas" role="group" aria-label="Técnico" id="exPersonas"></div>
        <div class="row g-3 mb-3">
          <div class="col-sm-7"><label class="form-label" for="exTitulo">Examen</label><input class="form-control" id="exTitulo" maxlength="150" value="Evaluación técnica"></div>
          <div class="col-sm-5"><label class="form-label" for="exHoras">Vigencia</label><select class="form-select" id="exHoras"><option value="2">2 horas</option><option value="24" selected>24 horas</option><option value="72">3 días</option></select></div>
        </div>
        <button type="button" class="btn btn-win w-100" id="exGenerar"><i class="bi bi-link-45deg me-1"></i>Generar link</button>
        <div class="alert alert-danger d-none mt-3 mb-0" id="exError" role="alert"></div>
        <div id="exResultado" class="ex-resultado d-none">
          <label class="form-label" for="exLink">Link para <span id="exPara"></span></label>
          <input class="form-control mb-2" id="exLink" readonly>
          <small class="text-muted d-block mb-3" id="exVence"></small>
          <div class="ex-enviar">
            <a class="btn btn-win" id="exWhatsapp" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>Enviar por WhatsApp</a>
            <button type="button" class="btn btn-outline-secondary" id="exCopiar"><i class="bi bi-clipboard me-1"></i>Copiar</button>
            <button type="button" class="btn btn-outline-secondary d-none" id="exCompartir"><i class="bi bi-share me-1"></i>Otras apps</button>
          </div>
          <small class="text-muted d-block mt-2">Envíelo ahora: por seguridad el link no se vuelve a mostrar. Si se pierde, genere otro (el anterior se anula).</small>
        </div>
        <h6 class="cuad-seccion mt-4"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historial de links</h6>
        <div id="exHistorial"></div>
      </div>
    </div>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $ = (id) => document.getElementById(id);
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const llamar = async (api, accion, datos = null) => {
    const opciones = datos ? { method: 'POST', headers: { 'X-CSRF-Token': token }, body: datos } : {};
    const res = await fetch(`api/${api}.php?action=${accion}`, opciones);
    const r = await res.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (r.status !== 'success') throw new Error(r.message || 'No se pudo completar la acción.');
    return r;
  };
  const enviar = (accion, datos) => llamar('tecnicos', accion, datos);
  const fechaHora = (v) => { if (!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }); };
  const tabla = document.querySelector('.cuad-tabla');

  // ---------- Vencimientos de la cuadrilla ----------
  tabla?.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-vencimientos]'); if (!b) return;
    let cambio = false;
    window.SgsVencimientos?.abrir({ liderId: b.dataset.vencimientos, alActualizar: () => { cambio = true; } });
    document.getElementById('mVencimientos')?.addEventListener('hidden.bs.modal', () => { if (cambio) location.reload(); }, { once: true });
  });

  // ---------- Link de examen (T1 o T2 de la cuadrilla) ----------
  const mExamen = bootstrap.Modal.getOrCreateInstance($('mExamen'));
  const estados = { activo: ['bg-success', 'Vigente'], abierto: ['bg-primary', 'Abierto por el técnico'], usado: ['bg-dark', 'Examen rendido'], vencido: ['bg-secondary', 'Vencido'], anulado: ['bg-secondary', 'Anulado'] };
  let personas = [], elegido = null;
  const pintarHistorial = (lista) => {
    $('exHistorial').innerHTML = !lista.length ? '<p class="text-muted small mb-0"><i class="bi bi-inbox me-1"></i>Todavía no se le generó ningún link.</p>'
      : '<ul class="ex-historial">' + lista.map((h) => {
        const [clase, texto] = estados[h.estado] || ['bg-secondary', h.estado];
        const vigente = h.estado === 'activo' || h.estado === 'abierto';
        return `<li><div class="ex-historial-texto"><strong>${esc(h.titulo)}</strong><small class="text-muted">Generado el ${fechaHora(h.creado_en)}${h.generado_por_nombre ? ' por ' + esc(h.generado_por_nombre) : ''} · vence ${fechaHora(h.vence_en)}</small></div>
          <div class="ex-historial-acciones"><span class="badge ${clase}">${texto}</span>${vigente ? `<button type="button" class="btn btn-sm btn-outline-danger" data-anular="${h.id}">Anular</button>` : ''}</div></li>`;
      }).join('') + '</ul>';
  };
  const elegir = (id) => {
    elegido = personas.find((p) => String(p.id) === String(id)) || personas[0];
    $('exPersonas').querySelectorAll('button').forEach((b) => b.classList.toggle('active', b.dataset.persona === String(elegido.id)));
    $('exResultado').classList.add('d-none'); $('exError').classList.add('d-none');
    const gen = $('exGenerar'); gen.className = 'btn btn-win w-100'; gen.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Generar link';
    pintarHistorial(elegido.historial || []);
  };
  tabla?.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-examen]'); if (!b) return;
    $('exCargando').classList.remove('d-none'); $('exContenido').classList.add('d-none');
    mExamen.show();
    try {
      const r = await llamar('examenes', 'tecnico&id=' + encodeURIComponent(b.dataset.examen));
      personas = r.data;
      $('exPersonas').innerHTML = personas.map((p) => `<button type="button" class="filter-tab-btn" data-persona="${p.id}">${p.tipo_tecnico === 1 ? 'T1' : 'T2'} · ${esc(p.nombre_persona)}</button>`).join('');
      elegir(personas[0].id);
      $('exCargando').classList.add('d-none'); $('exContenido').classList.remove('d-none');
    } catch (e) {
      $('exCargando').textContent = e.message;
    }
  });
  $('exPersonas').addEventListener('click', (ev) => { const b = ev.target.closest('[data-persona]'); if (b) elegir(b.dataset.persona); });
  $('exGenerar').addEventListener('click', async () => {
    if (!elegido) return;
    const btn = $('exGenerar'); btn.disabled = true; $('exError').classList.add('d-none');
    try {
      const datos = new FormData(); datos.append('tecnico_id', elegido.id); datos.append('horas', $('exHoras').value); datos.append('titulo', $('exTitulo').value);
      const r = await llamar('examenes', 'generar', datos);
      const d = r.data;
      $('exPara').textContent = d.nombre;
      $('exLink').value = d.url;
      $('exVence').textContent = 'Vence el ' + d.vence + '. Sirve una sola vez.';
      $('exWhatsapp').href = 'https://wa.me/' + (d.celular ? '51' + d.celular : '') + '?text=' + encodeURIComponent(d.mensaje);
      $('exWhatsapp').title = d.celular ? 'Abre el chat con ' + d.celular : 'Elija el contacto en WhatsApp';
      $('exCompartir').classList.toggle('d-none', !navigator.share);
      $('exCompartir').onclick = () => navigator.share({ title: 'Link de examen', text: d.mensaje }).catch(() => {});
      $('exCopiar').onclick = async () => {
        try { await navigator.clipboard.writeText(d.mensaje); } catch { $('exLink').select(); document.execCommand('copy'); }
        window.showToast?.('Mensaje con el link copiado.', 'success');
      };
      $('exResultado').classList.remove('d-none');
      btn.className = 'btn btn-outline-secondary w-100'; btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Generar otro link'; // la acción principal pasa a ser enviarlo
      elegido.historial = d.historial; pintarHistorial(d.historial);
    } catch (e) {
      $('exError').textContent = e.message; $('exError').classList.remove('d-none');
    } finally { btn.disabled = false; }
  });
  $('exHistorial').addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-anular]'); if (!b) return;
    if (!confirm('¿Anular este link? El técnico ya no podrá abrirlo.')) return;
    b.disabled = true;
    try {
      const datos = new FormData(); datos.append('id', b.dataset.anular);
      const r = await llamar('examenes', 'anular', datos);
      elegido.historial = r.data.historial; pintarHistorial(r.data.historial);
      $('exResultado').classList.add('d-none');
      window.showToast?.(r.message, 'success');
    } catch (e) { window.showToast?.(e.message, 'error'); b.disabled = false; }
  });

  if (!$('mCuadrilla')) return; // lo que sigue es solo para el personal administrativo

  // ---------- Editar cuadrilla: T1, T2 (cambiar o registrar uno nuevo), vehículo, zona y estado ----------
  const mCuadrilla = bootstrap.Modal.getOrCreateInstance($('mCuadrilla'));
  const f = $('fCuadrilla');
  const apoyo = $('cuadApoyo');
  const actualizarT2 = (cargarDatos = true) => {
    const v = apoyo.value, nuevo = v === 'nuevo';
    $('cuadT2Datos').classList.toggle('d-none', v === '0');
    $('cuadT2NombreBox').classList.toggle('d-none', !nuevo);
    if (!cargarDatos) return;
    const op = apoyo.selectedOptions[0];
    $('cuadT2Nombre').value = '';
    $('cuadT2Dni').value = nuevo ? '' : (op?.dataset.dni && op.dataset.dni !== '0' ? op.dataset.dni : '');
    $('cuadT2Cel').value = nuevo ? '' : (op?.dataset.celular || '');
  };
  apoyo.addEventListener('change', () => actualizarT2());
  tabla?.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-editar]'); if (!b) return;
    f.id.value = b.dataset.editar;
    $('cuadNombre').textContent = b.dataset.nombre;
    $('cuadPartnerTexto').textContent = 'Partner: ' + b.dataset.partner;
    $('cuadT1Dni').value = b.dataset.dni && b.dataset.dni !== '0' ? b.dataset.dni : '';
    $('cuadT1Cel').value = b.dataset.celular || '';
    apoyo.value = b.dataset.apoyo && b.dataset.apoyo !== '0' ? b.dataset.apoyo : '0';
    if (apoyo.value === '') apoyo.value = '0';
    actualizarT2();
    $('cuadPlaca').value = b.dataset.placa || '';
    $('cuadTipo').value = b.dataset.tipo || '';
    $('cuadEstadoVeh').value = b.dataset.estado || '';
    $('cuadOperativoEdit').value = b.dataset.operativo || 'Habilitado';
    $('cuadProvincia').value = b.dataset.provincia || '';
    $('cuadError').classList.add('d-none');
    mCuadrilla.show();
  });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = $('btnGuardarCuadrilla'); btn.disabled = true;
    try {
      const r = await enviar('cuadrilla_guardar', new FormData(f));
      mCuadrilla.hide(); window.showToast?.(r.message, 'success');
      setTimeout(() => location.reload(), 400);
    } catch (e) {
      $('cuadError').textContent = e.message; $('cuadError').classList.remove('d-none');
    } finally { btn.disabled = false; }
  });

  // ---------- Cargar técnicos líderes ----------
  const mCarga = bootstrap.Modal.getOrCreateInstance($('mCargaLideres'));
  const salida = $('cargaResultado'), btnRevisar = $('btnRevisarLideres'), btnAplicar = $('btnAplicarLideres'), archivo = $('archivoLideres');
  let aplicado = false;
  $('btnCargarLideres').addEventListener('click', () => { archivo.value = ''; salida.innerHTML = ''; btnAplicar.classList.add('d-none'); aplicado = false; mCarga.show(); });
  archivo.addEventListener('change', () => { salida.innerHTML = ''; btnAplicar.classList.add('d-none'); });
  $('mCargaLideres').addEventListener('hidden.bs.modal', () => { if (aplicado) location.reload(); });

  const lista = (titulo, items, fmt, abierta = false) => !items.length ? '' :
    `<details class="mb-2" ${abierta ? 'open' : ''}><summary class="fw-bold">${esc(titulo)} (${items.length})</summary><ul class="small mb-0 mt-1">${items.map((i) => `<li>${fmt(i)}</li>`).join('')}</ul></details>`;
  const pintar = (d, aplicadoAhora) => {
    const kpi = (etq, val, clase = '') => `<div class="col-6 col-md-3"><div class="sgs-kpi ${clase} p-2 h-100"><small class="sgs-kpi-label">${etq}</small><h3 class="mb-0 sgs-kpi-value">${val}</h3></div></div>`;
    salida.innerHTML = (aplicadoAhora ? '<div class="alert alert-success">Cambios aplicados.</div>' : '<div class="alert alert-info small mb-3">Vista previa: todavía no se cambió nada. Revise y pulse <strong>Aplicar cambios</strong>.</div>')
      + (d.aviso ? `<div class="alert alert-warning small">${esc(d.aviso)}</div>` : '')
      + '<div class="row g-2 mb-3">' + kpi('Nuevos', d.nuevos.length) + kpi('Actualizados', d.actualizados.length)
      + kpi('Se inhabilitan', d.desactivar.length, d.desactivar.length ? 'sgs-kpi--warning' : '') + kpi('Sin cambios', d.sin_cambios) + '</div>'
      + (Object.keys(d.por_departamento || {}).length ? `<p class="small mb-2"><strong>Zonas del archivo:</strong> ${Object.entries(d.por_departamento).sort((a, b) => b[1] - a[1]).map(([dep, n]) => `${esc(dep)} (${n})`).join(' · ')}${d.sin_zona ? ` · <span class="text-muted">sin provincia (${d.sin_zona})</span>` : ''}</p>` : (d.sin_zona ? '<div class="alert alert-warning small">El archivo no trae la columna <strong>PROVINCIA</strong>: la zona de los técnicos no cambia.</div>' : ''))
      + lista('Filas con problemas (no se aplican)', d.observadas, (i) => `Fila ${i.fila}: <strong>${esc(i.nombre)}</strong> — ${esc(i.motivo)}`, true)
      + lista('Provincias que no se reconocen (su zona no cambia)', d.zona_no_reconocida || [], (i) => `Fila ${i.fila}: <strong>${esc(i.nombre)}</strong> — "${esc(i.provincia)}"`, true)
      + lista('Nuevos', d.nuevos, (i) => `${esc(i.nombre)} <span class="text-muted">· ${esc(i.partner)}${i.zona ? ' · ' + esc(i.zona) : ''}</span>`)
      + lista('Cambian de zona', d.cambios_zona || [], (i) => `${esc(i.nombre)} <span class="text-muted">· ${esc(i.antes)} →</span> <strong>${esc(i.zona)}</strong>`)
      + lista('Actualizados', d.actualizados, (i) => `${esc(i.antes)} → <strong>${esc(i.nombre)}</strong>${i.partner_antes !== i.partner ? ` <span class="text-muted">· ${esc(i.partner_antes)} → ${esc(i.partner)}</span>` : ''}`)
      + lista('Se reactivan (estaban inhabilitados)', d.reactivados, (i) => esc(i.nombre))
      + lista('Se inhabilitan (no vienen en el archivo)', d.desactivar, (i) => `${esc(i.nombre)} <span class="text-muted">· ${esc(i.partner)}</span>`)
      + lista('En lista negra (no se reactivan)', d.lista_negra, (i) => esc(i.nombre));
  };
  const procesar = async (modo) => {
    if (!archivo.files.length) { salida.innerHTML = '<div class="alert alert-danger">Seleccione el archivo Excel.</div>'; return; }
    const datos = new FormData(); datos.append('archivo', archivo.files[0]); datos.append('modo', modo);
    const boton = modo === 'aplicar' ? btnAplicar : btnRevisar; boton.disabled = true;
    try {
      const r = await enviar('importar_lideres', datos);
      pintar(r.data, r.aplicado);
      const hayCambios = r.data.nuevos.length + r.data.actualizados.length + r.data.reactivados.length + r.data.desactivar.length + (r.data.cambios_zona || []).length > 0;
      btnAplicar.classList.toggle('d-none', r.aplicado || !hayCambios);
      if (r.aplicado) { aplicado = true; window.showToast?.('Técnicos líderes actualizados.', 'success'); }
    } catch (e) {
      salida.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
    } finally { boton.disabled = false; }
  };
  btnRevisar.addEventListener('click', () => procesar('previsualizar'));
  btnAplicar.addEventListener('click', () => {
    if (!confirm('¿Aplicar los cambios de la vista previa? Los técnicos que no vienen en el archivo quedarán inhabilitados.')) return;
    procesar('aplicar');
  });
});
</script>
