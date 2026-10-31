<?php
// Cuadrillas (2026-10-15): cada técnico líder con su técnico de apoyo habitual y su vehículo. Se arman solas al cerrar
// supervisiones con "Guardar el T2 y el vehículo" activado. El personal administrativo además puede editarlas aquí
// (emergencias) y cargar la lista de técnicos líderes por Excel (nuevos, cambios de nomenclatura e inhabilitados).
// Zona (2026-10-15): cada técnico líder tiene provincia y departamento; filtra la lista en el formulario de supervisión.
// Editor (2026-10-15): datos del T1 y del T2 (DNI, celular), cambiar de T2 o registrar uno nuevo.
// Link de examen (2026-10-20): UN link general para los técnicos (T1 y T2) de las cuadrillas filtradas; cada técnico entra
// con su DNI. Solo se genera si todos tienen DNI (los que faltan se corrigen con el lápiz). Reemplaza al link por fila.
// Editar cuadrilla (2026-10-20): también los supervisores directos, solo las de su zona (api/tecnicos.php lo valida).
// Vistas (2026-10-20, ?vista=): Cuadrillas | Técnicos líderes (T1) | Técnicos de apoyo (T2), con los mismos filtros y el
// resultado del último examen de cada técnico. En la de T2 se ve a qué líder apoya (o "Sin cuadrilla").
// Vencimientos (2026-10-15): SOAT, revisión técnica, extintor y brevete van en la columna Vehículo con su color; al tocarlos
// se abre su historial y se actualizan fechas (vencimientos.js, api/vencimientos.php). Filtro "docs" por estado de documentos.
// Sistema liviano (2026-10-27): la tabla va de 50 en 50 (?p=) y el programa está en assets/js/cuadrillas.js.
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$esDirecto = ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
$esAdmin = Auth::isAdministrative() && !sgsAlcanceLimitado($user); // líder con zonas: solo las suyas
// Permisos (2026-10-23, Roles y permisos): ver, editar cuadrillas (el directo, solo de su zona), cargar Excel y links de examen.
$puedeEditar = Auth::puede(Auth::PERM_CUADRILLAS_EDIT, $user);
$puedeImportar = Auth::puede(Auth::PERM_CUADRILLAS_IMPORT, $user);
$puedeExamen = Auth::puede(Auth::PERM_EXAMENES_EDIT, $user);
$puedeAnularExamen = Auth::puede(Auth::PERM_EXAMENES_DELETE, $user);
if (!Auth::puede(Auth::PERM_CUADRILLAS_VIEW, $user)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver técnicos y cuadrillas.</div>';
    return;
}
$pdo = Database::getConnection();
$partnerFiltro = (int)($_GET['partner_id'] ?? 0);
$busqueda = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$estadoFiltro = in_array($_GET['estado'] ?? '', ['completas', 'incompletas'], true) ? $_GET['estado'] : '';
$operativoFiltro = in_array($_GET['operativo'] ?? '', ['todos', 'Inhabilitado', 'Blacklist'], true) ? $_GET['operativo'] : '';
$zonaFiltro = mb_substr(trim((string)($_GET['zona'] ?? '')), 0, 100);
$vista = in_array($_GET['vista'] ?? '', ['lideres', 'apoyo'], true) ? $_GET['vista'] : 'cuadrillas';
$docsFiltro = in_array($_GET['docs'] ?? '', ['alerta', 'malo', 'regular', 'sin_registrar'], true) ? $_GET['docs'] : '';
$zonasRegistradas = $pdo->query("SELECT DISTINCT departamento FROM tecnicos WHERE COALESCE(tipo_tecnico, 1) = 1 AND departamento IS NOT NULL AND departamento <> '' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
$partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY nombre_partner")->fetchAll();

$where = ['COALESCE(t.tipo_tecnico, 1) = 1'];
$params = [];
if ($operativoFiltro === '') $where[] = "t.estado_operativo = 'Habilitado'";
elseif ($operativoFiltro !== 'todos') { $where[] = 't.estado_operativo = :operativo'; $params[':operativo'] = $operativoFiltro; }
if ($partnerFiltro > 0) { $where[] = 't.partner_id = :partner'; $params[':partner'] = $partnerFiltro; }
// Zona del usuario (2026-10-15): el supervisor ve solo las cuadrillas de sus departamentos. El personal administrativo y la
// sede "Provincias" ven todas. Las que aún no tienen zona solo las ve el administrador general (2026-10-28).
$veSinZona = sgsVeTecnicosSinZona($user);
if (!$veSinZona && $zonaFiltro === 'sin_zona') $zonaFiltro = '';
if (!$veSinZona) $where[] = sgsTecnicoConZonaSql('t.departamento');
$zonasUsuario = $esAdmin ? null : sgsUserZones((int)$user['id']);
if ($zonasUsuario !== null) {
    $zp = [];
    $where[] = sgsZoneSql('t.departamento', (int)$user['id'], $zp, ':uz');
    $params += $zp;
    $zonasRegistradas = array_values(array_filter($zonasRegistradas, static fn($z) => in_array(mb_strtoupper(trim($z), 'UTF-8'), $zonasUsuario, true)));
}
// Área (2026-10-31): en Lima, Callao y Norte Chico quien es de Instalaciones ve las cuadrillas SGI y quien es de VT las SGA.
if (!$esAdmin) $where[] = sgsCuadrillaAreaSql('t.departamento', 't.nombre_tecnico', (int)$user['id']);
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
    WHERE t.tipo_tecnico = 2 AND t.estado_operativo <> 'Blacklist'" . ($veSinZona ? '' : ' AND (' . sgsTecnicoConZonaSql('t.departamento')
        . ' OR EXISTS (SELECT 1 FROM tecnicos lz WHERE lz.apoyo_id = t.id AND ' . sgsTecnicoConZonaSql('lz.departamento') . '))') . " ORDER BY t.nombre_tecnico")->fetchAll() : [];
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
// Partner con el área de la cuadrilla en Lima, Callao y Norte Chico ("VISUAL · SGI"); en provincia, solo el partner.
$partnerCuadrilla = static fn(?string $partner, ?string $zona, ?string $lider): string => ($partner ?: '—')
    . (($area = sgsAreaCuadrilla($zona, $lider)) ? " · {$area}" : '');
// Técnicos de apoyo (T2) con el líder al que apoyan; la zona es la suya o, si no tiene, la de su líder.
$depT2 = "COALESCE(NULLIF(a.departamento, ''), l.departamento)";
$whereT2 = ['a.tipo_tecnico = 2'];
$paramsT2 = [];
if ($operativoFiltro === '') $whereT2[] = "a.estado_operativo = 'Habilitado'";
elseif ($operativoFiltro !== 'todos') { $whereT2[] = 'a.estado_operativo = :operativo'; $paramsT2[':operativo'] = $operativoFiltro; }
if ($partnerFiltro > 0) { $whereT2[] = 'a.partner_id = :partner'; $paramsT2[':partner'] = $partnerFiltro; }
if ($zonasUsuario !== null) {
    $zp = [];
    $whereT2[] = sgsZoneSql($depT2, (int)$user['id'], $zp, ':uz');
    $paramsT2 += $zp;
}
if (!$veSinZona) $whereT2[] = sgsTecnicoConZonaSql($depT2);
if (!$esAdmin) $whereT2[] = sgsCuadrillaAreaSql($depT2, 'l.nombre_tecnico', (int)$user['id']); // el T2 sigue a su líder
if ($zonaFiltro === 'sin_zona') $whereT2[] = "({$depT2} IS NULL OR {$depT2} = '')";
elseif ($zonaFiltro !== '') { $whereT2[] = "{$depT2} = :zona"; $paramsT2[':zona'] = $zonaFiltro; }
if ($busqueda !== '') {
    $whereT2[] = '(a.nombre_tecnico LIKE :q1 OR a.dni LIKE :q2 OR l.nombre_tecnico LIKE :q3)';
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $paramsT2 += [':q1' => $like, ':q2' => $like, ':q3' => $like];
}
// La lista de T2 solo se trae en su vista; en las demás basta contarlos para la pestaña (consulta liviana).
$desdeT2 = 'FROM tecnicos a LEFT JOIN tecnicos l ON l.apoyo_id = a.id AND COALESCE(l.tipo_tecnico, 1) = 1 LEFT JOIN partners p ON p.id = a.partner_id
    WHERE ' . implode(' AND ', $whereT2);
$apoyosLista = [];
if ($vista === 'apoyo') {
    $stT2 = $pdo->prepare("SELECT a.id, a.nombre_tecnico, a.dni, a.celular, a.estado_operativo, a.provincia, a.departamento, p.nombre_partner,
            l.id AS lider_id, l.nombre_tecnico AS lider_nombre, l.provincia AS lider_provincia, l.departamento AS lider_departamento
        {$desdeT2} ORDER BY (l.id IS NULL), p.nombre_partner, a.nombre_tecnico LIMIT 1000");
    $stT2->execute($paramsT2);
    $apoyosLista = $stT2->fetchAll();
    $totalApoyos = count($apoyosLista);
} else {
    $stT2 = $pdo->prepare("SELECT COUNT(*) {$desdeT2}");
    $stT2->execute($paramsT2);
    $totalApoyos = (int)$stT2->fetchColumn();
}
// Páginas de 50 filas (2026-10-27, sistema liviano): los totales y filtros usan todo; la tabla, solo la página pedida.
$porPagina = 50;
$listaVista = $vista === 'apoyo' ? $apoyosLista : $filas;
$paginas = max(1, (int)ceil(count($listaVista) / $porPagina));
$pagina = min($paginas, max(1, (int)($_GET['p'] ?? 1)));
$filasPagina = array_slice($filas, ($pagina - 1) * $porPagina, $porPagina);
$apoyosPagina = array_slice($apoyosLista, ($pagina - 1) * $porPagina, $porPagina);
$urlPagina = static function (int $p): string {
    $q = $_GET; $q['page'] = 'cuadrillas';
    if ($p <= 1) unset($q['p']); else $q['p'] = $p;
    return 'index.php?' . http_build_query($q);
};
// Último examen rendido de cada técnico: solo en las vistas que lo muestran (T1 y T2), de la página que se ve.
$ultimoExamen = [];
$idsExamen = ['apoyo' => array_column($apoyosPagina, 'id'), 'lideres' => array_column($filasPagina, 'id')][$vista] ?? [];
if ($idsExamen) {
    $ph = implode(',', array_fill(0, count($idsExamen), '?'));
    $stEx = $pdo->prepare("SELECT ct.tecnico_id, ct.nota, ct.aprobado, ct.usado_en, c.titulo FROM examen_convocatoria_tecnicos ct
        JOIN examen_convocatorias c ON c.id = ct.convocatoria_id WHERE ct.usado_en IS NOT NULL AND ct.tecnico_id IN ($ph) ORDER BY ct.usado_en DESC");
    $stEx->execute(array_map('intval', $idsExamen));
    foreach ($stEx->fetchAll() as $x) $ultimoExamen[(int)$x['tecnico_id']] ??= $x;
}
$badgeExamen = static function (int $id) use ($ultimoExamen, $e): string {
    $x = $ultimoExamen[$id] ?? null;
    if (!$x) return '<span class="text-muted">Sin examen</span>';
    return '<span class="badge ' . ($x['aprobado'] ? 'bg-success' : 'bg-danger') . '" title="' . $e($x['titulo'] . ' · ' . date('d/m/Y', strtotime($x['usado_en']))) . '">'
        . ($x['aprobado'] ? 'Aprobado' : 'No aprobado') . ' · ' . (int)round((float)$x['nota']) . ' %</span>';
};
$urlVista = static function (string $v): string {
    $q = $_GET; $q['page'] = 'cuadrillas'; unset($q['p']);
    if ($v === 'cuadrillas') unset($q['vista']); else $q['vista'] = $v;
    return 'index.php?' . http_build_query($q);
};
require_once BACKEND_PATH . '/config/tecnicos_lideres.php'; // sgsTextoZona y la lista de provincias
$sinZona = count(array_filter($filas, static fn($f) => trim((string)$f['departamento']) === ''));
$etiquetaDoc = ['SOAT' => 'SOAT', 'Revisión técnica' => 'Rev. técnica', 'Extintor' => 'Extintor', 'Brevete' => 'Brevete'];
$urlDocs = static function (string $filtro): string {
    $q = $_GET; $q['page'] = 'cuadrillas'; unset($q['p']);
    if ($filtro === '') unset($q['docs']); else $q['docs'] = $filtro;
    return 'index.php?' . http_build_query($q);
};
$claseVehiculo = ['Buen estado' => 'bg-success', 'Regular' => 'bg-warning', 'Mal estado' => 'bg-danger'];
$claseOperativo = ['Habilitado' => 'bg-success', 'Inhabilitado' => 'bg-secondary', 'Blacklist' => 'bg-danger'];
$columnas = 6;
$partnerNombre = '';
foreach ($partners as $p) if ((int)$p['id'] === $partnerFiltro) $partnerNombre = $p['nombre_partner'];
$descripcionFiltro = implode(' · ', array_filter([
    $partnerNombre ?: 'Todos los partners',
    $zonaFiltro === 'sin_zona' ? 'Sin zona' : ($zonaFiltro ?: ($zonasUsuario ? implode(', ', $zonasUsuario) : 'Todas las zonas')),
    ['completas' => 'Completas', 'incompletas' => 'Por completar'][$estadoFiltro] ?? '',
    ['alerta' => 'Documentos con alerta', 'malo' => 'Documentos vencidos', 'regular' => 'Documentos por vencer', 'sin_registrar' => 'Documentos sin registrar'][$docsFiltro] ?? '',
    $busqueda !== '' ? 'Búsqueda: ' . $busqueda : '',
]));
// Provincias que puede elegir en el editor: el supervisor directo, solo las de su zona.
$provinciasEditor = sgsProvinciasPorDepartamento();
if ($zonasUsuario !== null) $provinciasEditor = array_filter($provinciasEditor, static fn($dep) => in_array(mb_strtoupper($dep, 'UTF-8'), $zonasUsuario, true), ARRAY_FILTER_USE_KEY);
?>
<div class="container-fluid p-3 p-md-4">
  <div class="sgs-page-actions">
    <?php if ($puedeExamen): ?>
    <button type="button" class="btn <?= $puedeImportar ? 'btn-outline-secondary' : 'btn-win' ?>" id="btnLinkExamen" data-filtro="<?= $e($descripcionFiltro) ?>" data-lideres="<?= $e(implode(',', array_column($filas, 'id'))) ?>" <?= $filas && $vista !== 'apoyo' ? '' : 'disabled' ?>
      title="<?= $vista === 'apoyo' ? 'El link se arma por cuadrillas: use la vista Cuadrillas o Técnicos líderes' : 'Un link para los técnicos (T1 y T2) de las cuadrillas que se ven con estos filtros' ?>"><i class="bi bi-link-45deg me-1"></i>Link de examen (<?= count($filas) ?> <?= count($filas) === 1 ? 'cuadrilla' : 'cuadrillas' ?>)</button>
    <?php endif; ?>
    <?php if ($puedeEditar): ?><button type="button" class="btn btn-outline-secondary" id="btnNuevoTecnico"><i class="bi bi-person-plus me-1"></i>Nuevo técnico</button><?php endif; ?>
    <?php if ($puedeImportar): ?>
    <a class="btn btn-outline-secondary" href="api/tecnicos.php?action=exportar_lideres"><i class="bi bi-file-earmark-arrow-down me-1"></i>Descargar lista (Excel)</a>
    <button type="button" class="btn btn-win" id="btnCargarLideres"><i class="bi bi-file-earmark-arrow-up me-1"></i>Cargar técnicos líderes</button>
    <?php endif; ?>
  </div>
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
    <?php if ($vista !== 'cuadrillas'): ?><input type="hidden" name="vista" value="<?= $e($vista) ?>"><?php endif; ?>
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
          <?php if ($veSinZona): ?><option value="sin_zona" <?= $zonaFiltro === 'sin_zona' ? 'selected' : '' ?>>Sin zona</option><?php endif; ?>
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
      <div class="sgs-segmented cuad-vistas" role="group" aria-label="Vista">
        <?php foreach (['cuadrillas' => ['Cuadrillas', count($filas)], 'lideres' => ['Técnicos líderes (T1)', count($filas)], 'apoyo' => ['Técnicos de apoyo (T2)', $totalApoyos]] as $clave => [$texto, $n]): ?>
        <a class="filter-tab-btn <?= $vista === $clave ? 'active' : '' ?>" href="<?= $e($urlVista($clave)) ?>"><?= $texto ?> <span class="sgs-seg-count"><?= $n ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="text-muted small">Se completan solas: al <strong>cerrar</strong> una supervisión, el técnico de apoyo, el vehículo y su estado quedan como cuadrilla del técnico líder. Para corregirla, use el lápiz de cada fila<?= $esAdmin ? '' : ' (solo las de su zona)' ?>. En el formulario de supervisión solo aparecen los técnicos líderes de la <strong>zona</strong> elegida.</p>
    <?php if ($zonasUsuario !== null): ?><p class="small mb-2"><i class="bi bi-geo-alt me-1 text-primary"></i>Se muestran las cuadrillas de su zona: <strong><?= $e($zonasUsuario ? implode(", ", $zonasUsuario) : "sin zona asignada") ?></strong>.</p><?php endif; ?>
    <?php if ($sinZona): ?><div class="alert alert-warning small py-2"><i class="bi bi-geo-alt me-1"></i><?= $sinZona ?> <?= $sinZona === 1 ? 'técnico líder no tiene' : 'técnicos líderes no tienen' ?> zona. Cargue el Excel con la columna <strong>PROVINCIA</strong><?= $puedeEditar ? ' o asígnela con el lápiz' : '' ?>; mientras tanto aparecen en cualquier zona.</div><?php endif; ?>
    <?php
    // Lápiz de edición de la cuadrilla (mismo editor en la vista Cuadrillas y en Técnicos líderes).
    $botonEditar = static fn(array $f): string => $puedeEditar ? '<button type="button" class="sgs-icon-action" title="Editar cuadrilla" aria-label="Editar cuadrilla de ' . $e($f['nombre_tecnico']) . '"'
        . ' data-editar="' . (int)$f['id'] . '" data-nombre="' . $e($f['nombre_tecnico']) . '" data-partner="' . $e($f['nombre_partner'] ?: '—') . '"'
        . ' data-dni="' . $e($f['dni']) . '" data-celular="' . $e($f['celular']) . '" data-apoyo="' . (int)$f['apoyo_id'] . '" data-placa="' . $e($f['placa']) . '"'
        . ' data-tipo="' . $e($f['vehiculo_tipo']) . '" data-estado="' . $e($f['vehiculo_estado']) . '" data-operativo="' . $e($f['estado_operativo']) . '"'
        . ' data-provincia="' . $e($f['provincia'] ?: $f['departamento']) . '"><i class="bi bi-pencil"></i></button>' : '';
    $zonaTexto = static fn(?string $prov, ?string $dep): string => trim((string)$dep) === '' ? '<span class="text-warning-emphasis">Sin zona</span>' : $e(sgsTextoZona($prov, $dep));
    $estadoBadge = static fn(?string $estado): string => ($estado ?? 'Habilitado') !== 'Habilitado' ? ' <span class="badge ' . ($claseOperativo[$estado] ?? 'bg-secondary') . '">' . $e($estado) . '</span>' : '';
    ?>
    <?php if ($vista === 'lideres'): ?>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas cuad-tabla">
      <thead><tr><th>Técnico líder (T1)</th><th>DNI y celular</th><th>Partner y zona</th><th>Su cuadrilla</th><th>Último examen</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay técnicos líderes con estos filtros.</td></tr><?php endif; ?>
      <?php foreach ($filasPagina as $f): ?>
        <tr data-lider="<?= (int)$f['id'] ?>">
          <td data-label="Técnico líder"><span class="fw-bold"><?= $e($f['nombre_tecnico']) ?></span><?= $estadoBadge($f['estado_operativo']) ?></td>
          <td data-label="DNI y celular"><div><div class="<?= preg_match('/^\d{8}$/', (string)$f['dni']) ? '' : 'text-danger fw-bold' ?>"><?= preg_match('/^\d{8}$/', (string)$f['dni']) ? $e($f['dni']) : 'Sin DNI' ?></div><small class="text-muted"><?= $e($f['celular'] ?: 'Sin celular') ?></small></div></td>
          <td data-label="Partner y zona"><div><div><?= $e($partnerCuadrilla($f['nombre_partner'], $f['departamento'], $f['nombre_tecnico'])) ?></div><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= $zonaTexto($f['provincia'], $f['departamento']) ?></small></div></td>
          <td data-label="Su cuadrilla"><div><div><?= $f['apoyo_nombre'] ? 'T2: ' . $e($f['apoyo_nombre']) : '<span class="text-muted">Sin T2</span>' ?></div><small class="text-muted"><?= $f['placa'] ? 'Vehículo ' . $e($f['placa']) : 'Sin vehículo' ?></small></div></td>
          <td data-label="Último examen"><?= $badgeExamen((int)$f['id']) ?></td>
          <td class="text-end"><div class="sgs-row-actions justify-content-end"><?= $botonEditar($f) ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php elseif ($vista === 'apoyo'): ?>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas cuad-tabla">
      <thead><tr><th>Técnico de apoyo (T2)</th><th>DNI y celular</th><th>Partner y zona</th><th>Apoya a</th><th>Último examen</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$apoyosLista): ?><tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay técnicos de apoyo con estos filtros.</td></tr><?php endif; ?>
      <?php foreach ($apoyosPagina as $a): ?>
        <tr>
          <td data-label="Técnico de apoyo"><span class="fw-bold"><?= $e($a['nombre_tecnico']) ?></span><?= $estadoBadge($a['estado_operativo']) ?></td>
          <td data-label="DNI y celular"><div><div class="<?= preg_match('/^\d{8}$/', (string)$a['dni']) ? '' : 'text-danger fw-bold' ?>"><?= preg_match('/^\d{8}$/', (string)$a['dni']) ? $e($a['dni']) : 'Sin DNI' ?></div><small class="text-muted"><?= $e($a['celular'] ?: 'Sin celular') ?></small></div></td>
          <td data-label="Partner y zona"><div><div><?= $e($partnerCuadrilla($a['nombre_partner'], $a['lider_departamento'], $a['lider_nombre'])) ?></div><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= trim((string)$a['departamento']) !== '' ? $zonaTexto($a['provincia'], $a['departamento']) : $zonaTexto($a['lider_provincia'], $a['lider_departamento']) ?></small></div></td>
          <td data-label="Apoya a"><?= $a['lider_id'] ? $e($a['lider_nombre']) : '<span class="text-muted">Sin cuadrilla</span>' ?></td>
          <td data-label="Último examen"><?= $badgeExamen((int)$a['id']) ?></td>
          <td class="text-end"><div class="sgs-row-actions justify-content-end"><?php if ($a['lider_id']): ?>
            <a class="sgs-icon-action" href="index.php?page=cuadrillas&amp;q=<?= $e(rawurlencode($a['lider_nombre'])) ?>" title="Ver su cuadrilla" aria-label="Ver la cuadrilla de <?= $e($a['nombre_tecnico']) ?>"><i class="bi bi-people"></i></a>
          <?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas cuad-tabla">
      <thead><tr><th>Técnico líder</th><th>Partner y zona</th><th title="Técnico de apoyo (T2)">Apoyo (T2)</th><th>Vehículo</th><th>Supervisiones</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="<?= $columnas ?>" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay técnicos líderes con estos filtros.</td></tr>
      <?php endif; ?>
      <?php foreach ($filasPagina as $f): ?>
        <tr data-lider="<?= (int)$f['id'] ?>">
          <td data-label="Técnico líder"><div><div class="fw-bold"><?= $e($f['nombre_tecnico']) ?></div>
            <small class="text-muted">DNI <?= $e($f['dni'] ?: '—') ?></small>
            <?php if (($f['estado_operativo'] ?? 'Habilitado') !== 'Habilitado'): ?> <span class="badge <?= $claseOperativo[$f['estado_operativo']] ?? 'bg-secondary' ?>"><?= $e($f['estado_operativo']) ?></span><?php endif; ?></div></td>
          <td data-label="Partner y zona"><div><div><?= $e($partnerCuadrilla($f['nombre_partner'], $f['departamento'], $f['nombre_tecnico'])) ?></div>
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
            <?= $botonEditar($f) ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($paginas > 1): $desde = ($pagina - 1) * $porPagina + 1; ?>
    <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Páginas">
      <small class="text-muted">Mostrando <?= $desde ?>–<?= min($desde + $porPagina - 1, count($listaVista)) ?> de <?= count($listaVista) ?></small>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary btn-sm<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $e($urlPagina($pagina - 1)) ?>"<?= $pagina <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-chevron-left me-1"></i>Anterior</a>
        <span class="small">Página <?= $pagina ?> de <?= $paginas ?></span>
        <a class="btn btn-outline-secondary btn-sm<?= $pagina >= $paginas ? ' disabled' : '' ?>" href="<?= $e($urlPagina($pagina + 1)) ?>"<?= $pagina >= $paginas ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Siguiente<i class="bi bi-chevron-right ms-1"></i></a>
      </div>
    </nav>
    <?php endif; ?>
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
          <datalist id="listaProvincias"><?php foreach ($provinciasEditor as $dep => $provs) foreach ($provs as $pv): ?><option value="<?= $e($pv) ?>"><?= $e($dep) ?></option><?php endforeach; ?></datalist>
          <small class="text-muted">El departamento se busca solo.<?= $veSinZona ? ' Vacía = sin zona (solo usted la verá).' : ($esAdmin ? '' : ' Solo provincias de su zona.') ?></small></div>
        <div><label class="form-label" for="cuadOperativoEdit">Estado del técnico líder</label>
          <select class="form-select" id="cuadOperativoEdit" name="estado_operativo"><option>Habilitado</option><option>Inhabilitado</option><option value="Blacklist">Lista negra</option></select></div>
        <div class="alert alert-danger d-none mt-3 mb-0" id="cuadError" role="alert"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win" id="btnGuardarCuadrilla"><i class="bi bi-check2 me-1"></i>Guardar cuadrilla</button></div>
    </form>
  </div></div>
</div>

<!-- ===== Nuevo técnico (2026-10-29): un técnico líder (T1) o de apoyo (T2) suelto, sin el Excel ===== -->
<div class="modal fade" id="mNuevoTecnico" tabindex="-1" aria-labelledby="mNuevoTecnicoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="fNuevoTecnico" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="mNuevoTecnicoTitulo"><i class="bi bi-person-plus me-2"></i>Nuevo técnico</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label" for="ntTipo">Tipo de técnico</label>
            <select class="form-select" id="ntTipo" name="tipo_tecnico" required><option value="">Seleccione</option><option value="1">Técnico líder (T1)</option><option value="2">Técnico de apoyo (T2)</option></select></div>
          <div class="col-sm-6"><label class="form-label" for="ntPartner">Partner</label>
            <select class="form-select" id="ntPartner" name="partner_id" required><option value="">Seleccione</option>
              <?php foreach ($partners as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['nombre_partner']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label" for="ntNombre">Nombres y apellidos</label>
            <input class="form-control text-uppercase" id="ntNombre" name="nombre_tecnico" maxlength="120" required placeholder="Ej.: P 1 KAJOMI SGI JUAN PEREZ (líder) o JUAN PEREZ (apoyo)"></div>
          <div class="col-sm-6"><label class="form-label" for="ntDni">DNI</label><input class="form-control" id="ntDni" name="dni" inputmode="numeric" maxlength="8" required placeholder="8 dígitos"></div>
          <div class="col-sm-6"><label class="form-label" for="ntCelular">Celular (opcional)</label><input class="form-control" id="ntCelular" name="celular" inputmode="tel" maxlength="9" placeholder="9XXXXXXXX"></div>
          <div class="col-12"><label class="form-label" for="ntProvincia">Provincia (zona)</label>
            <input class="form-control text-uppercase" id="ntProvincia" name="provincia" maxlength="100" list="listaProvincias" autocomplete="off" placeholder="Ej.: SANTA"<?= $veSinZona ? '' : ' required' ?>>
            <small class="text-muted">El departamento se busca solo.<?= $veSinZona ? ' Vacía = sin zona (solo usted lo verá).' : ' Solo provincias de su zona.' ?></small></div>
        </div>
        <div class="alert alert-danger d-none mt-3 mb-0" id="ntError" role="alert"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-win" id="btnGuardarNuevoTecnico"><i class="bi bi-check2 me-1"></i>Registrar técnico</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
<?php if ($puedeImportar): ?>
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

<!-- ===== Link general de examen (2026-10-20) ===== -->
<div class="modal fade" id="mExamen" data-puede-anular="<?= $puedeAnularExamen ? '1' : '0' ?>" tabindex="-1" aria-labelledby="mExamenTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="mExamenTitulo"><i class="bi bi-link-45deg me-2"></i>Link de examen para las cuadrillas filtradas</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <div id="exCargando" class="text-muted small">Revisando a los técnicos…</div>
      <div id="exContenido" class="d-none">
        <p class="small text-muted mb-2"><i class="bi bi-funnel me-1" aria-hidden="true"></i><span id="exFiltro"></span></p>
        <div class="row g-2 mb-3" id="exResumen"></div>
        <div id="exSinDni"></div>
        <div id="exExcluidos"></div>
        <div class="row g-3 mb-3">
          <div class="col-sm-7"><label class="form-label" for="exExamen">Examen</label><select class="form-select" id="exExamen"></select><small class="text-muted" id="exExamenAyuda"></small></div>
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
          <small class="text-muted d-block mt-2">Envíelo ahora: por seguridad el link no se vuelve a mostrar. Cada técnico entra con su DNI.</small>
        </div>
      </div>
      <h6 class="cuad-seccion mt-4"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Links generados</h6>
      <div id="exHistorial"><p class="text-muted small mb-0">Cargando…</p></div>
    </div>
  </div></div>
</div>

<!-- ===== Técnicos de un link (quién ya entró) ===== -->
<div class="modal fade" id="mExamenDetalle" tabindex="-1" aria-labelledby="mExamenDetalleTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="mExamenDetalleTitulo"><i class="bi bi-people me-2"></i><span id="exDetTitulo">Técnicos del link</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body" id="exDetalle"></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
  </div></div>
</div>

<script src="03_FRONTEND/src/assets/js/cuadrillas.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
