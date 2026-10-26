<?php
// index.php - Enrutador principal de SGS (único punto de entrada de pantallas).
// Pantallas: 03_FRONTEND/src/pages · Plantillas: 03_FRONTEND/src/layouts · Lógica: 02_BACKEND/src

require_once __DIR__ . '/02_BACKEND/src/config/config.php';
require_once BACKEND_PATH . '/config/auth.php';

$page = $_GET['page'] ?? '';

// Sin sesión (o petición explícita) -> login. El cierre de sesión es POST en api/auth.php.
if ($page === 'login' || !Auth::isLoggedIn()) {
    require FRONTEND_PATH . '/pages/login.php';
    exit;
}

$user = Auth::getLoggedInUser();
// Contraseña asignada por el administrador (2026-10-15): antes de cualquier pantalla, la persona crea la suya.
if (Auth::mustChangePassword()) {
    require FRONTEND_PATH . '/pages/cambiar_password.php';
    exit;
}
win_run_periodic_task('log-retention', 86400, 'sgs_purge_old_logs');
require_once BACKEND_PATH . '/config/turnos.php';
win_run_periodic_task('cierre-turnos', 300, static fn() => sgsCerrarTurnosVencidos(Database::getConnection())); // turnos olvidados: se cierran a las 23:59
// Respaldos (2026-10-16): ya no se hacen en las visitas; los hace la tarea programada (07_SCRIPTS/respaldar.php automatico).
// Cada usuario trabaja con su propia cuenta y rol (la "vista provisional" se retiró el 2026-10-06).
$rol = $user['rol'] ?? '';
$isWinDirecto = !empty($user['is_win_directo']);
$isPartnerSupervisor = ($rol === Auth::ROLE_JEFE_OPERACIONES) || ($rol === Auth::ROLE_SUPERVISOR && !$isWinDirecto);

// Solo las secciones de supervisión y sus tableros.
$allowedViews = [
    'supervision_lista'      => ['file' => 'supervision_lista.php',      'title' => 'Supervisiones',            'perm' => Auth::PERM_SUPERVISION_VIEW],
    'supervision_registro'   => ['file' => 'supervision_lista.php',      'title' => 'Registro de supervisión',  'perm' => Auth::PERM_SUPERVISION_VIEW],
    'admin_dashboard'        => ['file' => 'admin_dashboard.php',        'title' => 'Dashboard de supervisiones', 'perm' => Auth::PERM_DASHBOARD_VIEW],
    'cuadrillas'             => ['file' => 'cuadrillas.php',             'title' => 'Técnicos y cuadrillas',    'perm' => Auth::PERM_CUADRILLAS_VIEW],
    'examenes'               => ['file' => 'examenes.php',               'title' => 'Exámenes',                 'perm' => Auth::PERM_EXAMENES_VIEW],
    'dashboard_partners'     => ['file' => 'dashboard_partners.php',     'title' => 'Dashboard de partners',    'perm' => Auth::PERM_PARTNERS_DASHBOARD],
    'dashboard_lluvias'      => ['file' => 'dashboard_lluvias.php',      'title' => 'Clima',                    'perm' => Auth::PERM_CLIMA_VIEW],
    'dashboard_pendientes'   => ['file' => 'dashboard_pendientes.php',   'title' => 'Pendientes y rescates',    'perm' => Auth::PERM_PENDIENTES_VIEW],
    'timeline_supervisores'  => ['file' => 'timeline_supervisores.php',  'title' => 'Línea de tiempo',          'perm' => Auth::PERM_TIMELINE_VIEW],
    'registro_observaciones' => ['file' => 'registro_observaciones.php', 'title' => 'Observaciones',            'perm' => Auth::PERM_OBSERVATION_VIEW],
    'observaciones_partner'  => ['file' => 'registro_observaciones.php', 'title' => 'Observaciones',            'perm' => Auth::PERM_OBSERVATION_VIEW],
    'control_observaciones'  => ['file' => 'control_observaciones.php',  'title' => 'Control de observaciones', 'perm' => Auth::PERM_OBSERVATION_REVIEW],
    // Usuarios: basta con uno de los tres permisos; la pantalla muestra solo lo que cada uno puede hacer.
    'partners'               => ['file' => 'partners.php',               'title' => 'Partners',                 'perm' => Auth::PERM_PARTNERS_VIEW],
    'usuarios'               => ['file' => 'usuarios.php',               'title' => 'Usuarios y roles',         'perm' => [Auth::PERM_USERS_MANAGE, Auth::PERM_STAFF_MANAGE, Auth::PERM_USERS_VIEW]],
    'asistencia'             => ['file' => 'asistencia.php',             'title' => 'Inicio de turno',          'perm' => null],
    'mi_informacion'         => ['file' => 'mi_informacion.php',         'title' => 'Mi información',           'perm' => null],
    'guia_estilo'            => ['file' => 'guia_estilo.php',            'title' => 'Guía de diseño',           'perm' => Auth::PERM_USERS_MANAGE],
];

// Seguimiento: el supervisor directo WIN empieza su jornada registrando el turno;
// sin turno activo la API no le permite registrar supervisiones.
$isDirectSupervisorView = $rol === Auth::ROLE_SUPERVISOR && $isWinDirecto;
$canMonitorShifts = in_array($rol, [Auth::ROLE_ADMINISTRADOR, Auth::ROLE_GERENTE, Auth::ROLE_COORDINADOR], true); // 2026-10-12: también el administrador
// Vencimientos (2026-10-15): quedaron dentro de Cuadrillas; la dirección antigua lleva a las cuadrillas con alertas.
if ($page === 'vencimientos') {
    header('Location: index.php?page=cuadrillas&docs=alerta');
    exit;
}
if (!array_key_exists($page, $allowedViews)) {
    $page = $isDirectSupervisorView ? 'asistencia' : 'supervision_lista';
}
// Turnos: solo supervisor directo (registra) y administración, gerencia o coordinación (monitorean). Partners no.
if ($page === 'asistencia' && !$isDirectSupervisorView && !$canMonitorShifts) {
    $page = 'supervision_lista';
}

// El supervisor del partner solo atiende supervisiones asignadas y observaciones de su contrata.
if ($isPartnerSupervisor && !in_array($page, ['mi_informacion', 'supervision_lista', 'supervision_registro', 'registro_observaciones', 'observaciones_partner', 'usuarios'], true)) {
    $page = 'supervision_lista';
}

// Usuarios no está aquí: entra quien tenga ver usuarios, agregar personal o administrar (se revisa abajo).
$administrativeViews = ['admin_dashboard', 'timeline_supervisores', 'control_observaciones'];
if (in_array($page, $administrativeViews, true) && !Auth::isAdministrative()) {
    $page = 'supervision_lista';
}
// Módulos de gestión (2026-10-23): además de su permiso (ver, editar, eliminar en Roles y permisos), solo personal de WIN
// (administrativo o supervisor directo). Las APIs aplican la misma regla con Auth::puede().
if (in_array($page, ['cuadrillas', 'examenes', 'dashboard_pendientes', 'dashboard_lluvias', 'dashboard_partners', 'partners'], true) && !Auth::esPersonalWin($user)) {
    $page = 'supervision_lista';
}

// Autorización por ruta: el menú es solo presentación.
$permissionUser = $user;
$requiredPermission = $allowedViews[$page]['perm'];
// 'perm' puede ser un permiso o una lista (basta con tener uno).
if ($requiredPermission !== null && !array_filter((array)$requiredPermission, static fn($p) => Auth::can($p, $permissionUser))) {
    $page = 'mi_informacion';
}
$_GET['page'] = $page; // sidebar y registro de errores leen la página efectiva

$pageTitle = $allowedViews[$page]['title'];
if ($isPartnerSupervisor && in_array($page, ['supervision_lista', 'supervision_registro'], true)) $pageTitle = $rol === Auth::ROLE_JEFE_OPERACIONES ? 'Órdenes del partner' : 'Mi agenda';
if ($page === 'usuarios' && !Auth::can(Auth::PERM_USERS_MANAGE, $permissionUser)) $pageTitle = 'Personal';
if ($page === 'asistencia' && !$isDirectSupervisorView) $pageTitle = 'Monitor de turnos';
// Descripción breve bajo el título (barra superior). Es el único encabezado de cada pantalla.
$pageDescriptions = [
    'supervision_lista' => $isPartnerSupervisor
        ? 'Órdenes que WIN te asignó. Atiéndelas dentro de las 24 horas; si vence el plazo, la orden se bloquea.'
        : 'Bitácora de supervisiones de campo: registro, asignación a partners y seguimiento.',
    'registro_observaciones' => 'Hallazgos de campo y de checklist, con sus descargos y plazos de respuesta.',
    'admin_dashboard' => 'Indicadores de supervisión, cobertura geográfica, productividad y matriz mensual.',
    'dashboard_lluvias' => 'Clima por zona: días de lluvia, horas, calendario del mes y bitácora (quién avisó, quién cerró y qué hacían).',
    'dashboard_partners' => 'Cumplimiento de los partners en 24 h, por supervisor del partner, y sus observaciones y descargos.',
    'cuadrillas' => 'Cada técnico líder con su técnico de apoyo, su vehículo y sus documentos (SOAT, revisión técnica, extintor, brevete).',
    'examenes' => 'Resultados de los exámenes de los técnicos: aprobación por partner y zona, temas con más errores y detalle por técnico.',
    'dashboard_pendientes' => 'Cancelaciones cargadas por Excel: pendientes, en validación, en rescate, rescatadas y cancelación correcta.',
    'timeline_supervisores' => 'Jornada del personal directo: turno, supervisiones, traslados y pausas.',
    'control_observaciones' => 'Recurrencia de observaciones y desempeño de técnicos.',
    'guia_estilo' => 'Colores, tipografía y componentes oficiales de SGS. Referencia para crear nuevas secciones.',
    'asistencia' => $isDirectSupervisorView
        ? 'Registra el inicio y fin de tu jornada. Sin turno activo no puedes registrar supervisiones.'
        : 'Estado de los turnos del personal directo en tiempo real.',
    'mi_informacion' => 'Tus datos personales y laborales.',
    'usuarios' => 'Crea y administra usuarios, sus roles y permisos.',
    'partners' => 'Contratas que trabajan con WIN: registro, RUC y estado.',
];
$pageDescriptions['supervision_registro'] = $pageDescriptions['supervision_lista'];
$pageDescriptions['observaciones_partner'] = $pageDescriptions['registro_observaciones'];
$pageDescription = $pageDescriptions[$page] ?? '';
$viewFile = FRONTEND_PATH . '/pages/' . $allowedViews[$page]['file'];
$assets = '03_FRONTEND/src/assets';
$needsLeaflet = $page === 'admin_dashboard'
    || (in_array($page, ['supervision_lista', 'supervision_registro'], true)
        && Auth::can(Auth::PERM_SUPERVISION_MAP, $permissionUser));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="sgs-inactividad" content="<?= (int)SGS_SESION_INACTIVA ?>">
    <title><?= htmlspecialchars($pageTitle . ' · ' . SYSTEM_CODE) ?></title>
    <link rel="icon" type="image/svg+xml" href="03_FRONTEND/src/assets/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
    <!-- App instalable (PWA, 2026-10-26): manifest.webmanifest + sw.js -->
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="03_FRONTEND/src/assets/img/app/icono-apple-180.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="SGS WIN">
    <meta name="theme-color" content="#FF5A0A">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php if ($needsLeaflet): ?>
    <link rel="dns-prefetch" href="//server.arcgisonline.com">
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $assets ?>/css/sgs-theme.css?v=<?= rawurlencode(ASSET_VERSION) ?>">
    <?php $abreApp = ($_GET['origen'] ?? '') === 'app'; if ($abreApp): ?><link rel="stylesheet" href="<?= $assets ?>/css/sgs-bienvenida.css?v=<?= rawurlencode(ASSET_VERSION) ?>"><?php endif; ?>
</head>
<body data-page="<?= htmlspecialchars($page) ?>" data-role="<?= $isPartnerSupervisor ? 'partner' : ($isDirectSupervisorView ? 'directo' : 'gestion') ?>">
<?php if ($abreApp) { // App abierta desde el ícono (2026-10-26): bienvenida con el primer nombre
    $primerNombre = mb_convert_case(mb_strtolower(strtok(trim((string)($user['nombres'] ?? '')), ' ') ?: '', 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $bienvenidaAlAbrir = true; $bienvenidaSaludo = $primerNombre ? "¡Hola, {$primerNombre}!" : '¡Hola!';
    require FRONTEND_PATH . '/layouts/bienvenida.php';
} ?>
    <a class="skip-to-content" href="#main-content-area">Ir al contenido principal</a>

    <div id="wrapper">
        <div id="sidebar-overlay" class="sidebar-overlay"></div>
        <?php require_once FRONTEND_PATH . '/layouts/sidebar.php'; ?>

        <div id="content">
            <?php require_once FRONTEND_PATH . '/layouts/topbar.php'; ?>
            <main class="flex-grow-1" id="main-content-area" tabindex="-1">
                <?php
                if (is_file($viewFile)) {
                    require_once $viewFile;
                } else {
                    win_log_error('view_not_found', 'Vista no encontrada', ['page' => $page]);
                    echo '<div class="alert alert-danger m-4">Vista no encontrada. Código: ' . htmlspecialchars(sgs_error_code()) . '</div>';
                }
                ?>
            </main>
        </div>
    </div>
    <?php if ($rol === Auth::ROLE_SUPERVISOR) require FRONTEND_PATH . '/layouts/gps_gate.php'; // GPS obligatorio en campo ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $assets ?>/js/main.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
    <script src="<?= $assets ?>/js/app-instalable.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
    <?php if ($abreApp): ?><script src="<?= $assets ?>/js/bienvenida.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><?php endif; ?>
    <?php if (in_array($page, ['mi_informacion', 'usuarios'], true)): ?><script src="<?= $assets ?>/js/password-meter.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><script src="<?= $assets ?>/js/aviso-mayusculas.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><?php endif; ?>
    <?php if (in_array($page, ['cuadrillas', 'supervision_lista'], true)): ?><script src="<?= $assets ?>/js/vencimientos.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><?php endif; ?>
    <script src="<?= $assets ?>/js/error-monitor.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
</body>
</html>
