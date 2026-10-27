<?php
// 03_FRONTEND/src/layouts/sidebar.php - Menú lateral de SGS (solo secciones de supervisión).
$user = Auth::getLoggedInUser();
$currentPage = $_GET['page'] ?? 'supervision_lista';
$rol = $user['rol'] ?? '';

if (!function_exists('formatSidebarShortName')) {
    function formatSidebarShortName($fullName) {
        $parts = array_values(array_filter(explode(' ', trim((string)$fullName))));
        if (count($parts) === 0) return '';
        if (count($parts) <= 2) return implode(' ', $parts);
        return $parts[0] . ' ' . ((count($parts) >= 4) ? $parts[2] : $parts[1]);
    }
}
if (!function_exists('formatSidebarInitials')) {
    function formatSidebarInitials(array $profile): string {
        $firstName = trim((string)($profile['nombres'] ?? ''));
        $firstSurname = trim((string)($profile['apellidos'] ?? ''));
        if ($firstName !== '' && $firstSurname !== '') {
            return mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($firstSurname, 0, 1));
        }
        $parts = array_values(array_filter(preg_split('/\s+/u', trim((string)($profile['nombre'] ?? '')))));
        if (!$parts) return 'U';
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts)-1], 0, 1) : ''));
    }
}

$shortName = formatSidebarShortName($user['nombre'] ?? '');
$sidebarInitials = formatSidebarInitials($user);
$showAdministrativeMenu = Auth::isAdministrative();
$isPartnerMenu = $rol === Auth::ROLE_JEFE_OPERACIONES || ($rol === Auth::ROLE_SUPERVISOR && empty($user['is_win_directo']));
$isDirectMenu = $rol === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
$canMonitorShifts = in_array($rol, [Auth::ROLE_ADMINISTRADOR, Auth::ROLE_GERENTE, Auth::ROLE_COORDINADOR], true); // 2026-10-12: también el administrador
$canViewSupervisions = Auth::can(Auth::PERM_SUPERVISION_VIEW, $user);
$canViewObservations = Auth::can(Auth::PERM_OBSERVATION_VIEW, $user);
$canReviewObservations = Auth::can(Auth::PERM_OBSERVATION_REVIEW, $user);
$canViewDashboard = Auth::can(Auth::PERM_DASHBOARD_VIEW, $user);
$canViewTimeline = Auth::can(Auth::PERM_TIMELINE_VIEW, $user);
$canManageUsers = Auth::can(Auth::PERM_USERS_MANAGE, $user);
// Personal: ver usuarios o agregar/editar datos personales (sin administrar accesos).
$canSeeStaff = $canManageUsers || Auth::can(Auth::PERM_STAFF_MANAGE, $user) || Auth::can(Auth::PERM_USERS_VIEW, $user);
$avatarPath = (string)($user['avatar'] ?? '');
$hasAvatar = $avatarPath !== '' && !str_contains($avatarPath, 'default-avatar') && is_file(SHARED_FILES_PATH . '/' . $avatarPath);

$isUserActive = false;
try {
    $stmtCheckAct = Database::getConnection()->prepare("SELECT estado_turno FROM asistencia_supervisores WHERE supervisor_id = :uid AND fecha = CURRENT_DATE() AND fin_turno IS NULL");
    $stmtCheckAct->execute([':uid' => $user['id']]);
    $stRow = $stmtCheckAct->fetch();
    $isUserActive = $stRow && in_array($stRow['estado_turno'], ['En turno', 'En almuerzo', 'En reunión', 'En capacitación'], true);
} catch (Throwable $t) {
    win_log_error('sidebar_status_error', $t->getMessage());
}

$menuItem = static function (string $href, array $pages, string $icon, string $label, string $current): string {
    $active = in_array($current, $pages, true) ? ' class="active"' : '';
    $aria = $active ? ' aria-current="page"' : '';
    return '<li' . $active . '><a href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($label) . '"' . $aria . '>'
        . '<i class="bi ' . $icon . '"></i><span class="menu-label-text">' . htmlspecialchars($label) . '</span></a></li>';
};
?>
<nav id="sidebar" class="sgs-sidebar d-flex flex-column h-100" aria-label="Navegación principal">
    <div class="sidebar-fixed-top flex-shrink-0">
        <div class="sgs-brand">
            <div class="menu-label-text">
                <img class="sgs-brand-logo" src="03_FRONTEND/src/assets/img/win-logo-blanco.png" width="96" height="40" alt="WIN">
                <div class="sgs-brand-sub"><?= htmlspecialchars(SYSTEM_CODE) ?> · Gestión de Supervisión</div>
            </div>
            <button type="button" id="sidebarCloseMobile" class="btn btn-link text-white p-1 d-lg-none ms-auto" aria-label="Ocultar menú lateral" title="Ocultar menú">
                <i class="bi bi-x-lg fs-5"></i>
            </button>
        </div>

        <div class="sgs-profile">
            <div class="sgs-avatar">
                <?php if ($hasAvatar): ?>
                    <img src="<?= htmlspecialchars($avatarPath) ?>" alt="">
                <?php else: ?>
                    <span><?= htmlspecialchars($sidebarInitials) ?></span>
                <?php endif; ?>
                <i class="sgs-status-dot <?= $isUserActive ? 'is-on' : '' ?>" title="<?= $isUserActive ? 'En turno' : 'Fuera de turno' ?>"></i>
            </div>
            <div class="menu-label-text overflow-hidden">
                <div class="sgs-profile-name text-truncate" title="<?= htmlspecialchars($user['nombre'] ?? '') ?>"><?= htmlspecialchars($shortName) ?></div>
                <span class="sgs-role-pill"><?= htmlspecialchars(Auth::etiquetaRol($rol)) ?></span>
            </div>
        </div>
    </div>

    <?php
    // Menú por secciones (2026-10-15): cada sección agrupa las pantallas de una misma tarea y se despliega en acordeón
    // (al abrir una se cierra la otra; la de la pantalla actual empieza abierta). Una sección con una sola opción
    // visible para el usuario se muestra como enlace directo (con su "nombre suelto", si lo tiene).
    // [sección, ícono, [[visible, href, páginas, ícono, nombre, nombre suelto opcional], …]]
    // 2026-10-20: "Indicadores" junta los dashboards y "Personal" a usuarios, técnicos y partners.
    // 2026-10-23: los módulos de gestión salen según su permiso de "ver" (Roles y permisos) y solo al personal de WIN.
    $ve = static fn(string $perm): bool => Auth::puede($perm, $user);
    $menuGroups = [
        ['Inicio', 'bi-house', [
            [$isDirectMenu, 'index.php?page=asistencia', ['asistencia'], 'bi-play-circle', 'Mi turno'],
        ]],
        ['Indicadores', 'bi-graph-up', [
            [$showAdministrativeMenu && $canViewDashboard, 'index.php?page=admin_dashboard', ['admin_dashboard'], 'bi-speedometer2', 'Dashboard de supervisiones'],
            [$ve(Auth::PERM_PARTNERS_DASHBOARD), 'index.php?page=dashboard_partners', ['dashboard_partners'], 'bi-buildings', 'Dashboard de partners'],
            [$ve(Auth::PERM_PENDIENTES_VIEW), 'index.php?page=dashboard_pendientes', ['dashboard_pendientes'], 'bi-arrow-repeat', 'Pendientes y rescates'],
            [$ve(Auth::PERM_CLIMA_VIEW), 'index.php?page=dashboard_lluvias', ['dashboard_lluvias'], 'bi-cloud-sun', 'Clima'],
            [$ve(Auth::PERM_EXAMENES_VIEW), 'index.php?page=examenes', ['examenes'], 'bi-clipboard-check', 'Exámenes'],
        ]],
        ['Supervisiones', 'bi-journal-check', [
            [$canViewSupervisions, 'index.php?page=supervision_lista', ['supervision_lista', 'supervision_registro'], $isPartnerMenu ? 'bi-calendar-check' : 'bi-journal-check', $isPartnerMenu ? ($rol === Auth::ROLE_JEFE_OPERACIONES ? 'Órdenes del partner' : 'Mi agenda') : 'Supervisiones'],
        ]],
        ['Seguimiento', 'bi-person-workspace', [
            [$showAdministrativeMenu && $canViewTimeline, 'index.php?page=timeline_supervisores', ['timeline_supervisores'], 'bi-diagram-3', 'Línea de tiempo'],
            [$canMonitorShifts, 'index.php?page=asistencia', ['asistencia'], 'bi-clock-history', 'Monitor de turnos'],
        ]],
        ['Observaciones', 'bi-clipboard2-pulse', [
            [$canViewObservations, 'index.php?page=registro_observaciones', ['registro_observaciones', 'observaciones_partner'], 'bi-clipboard2-pulse', 'Registro', 'Observaciones'],
            [$showAdministrativeMenu && $canReviewObservations, 'index.php?page=control_observaciones', ['control_observaciones'], 'bi-exclamation-diamond', 'Control y recurrencia'],
        ]],
        ['Personal', 'bi-people', [
            [$canSeeStaff, 'index.php?page=usuarios', ['usuarios'], 'bi-person-badge', $canManageUsers ? 'Usuarios y roles' : 'Personal'],
            [$ve(Auth::PERM_CUADRILLAS_VIEW), 'index.php?page=cuadrillas', ['cuadrillas'], 'bi-people-fill', 'Técnicos y cuadrillas'],
            [$ve(Auth::PERM_PARTNERS_VIEW), 'index.php?page=partners', ['partners'], 'bi-buildings', 'Partners'],
        ]],
    ];
    ?>
    <div class="sidebar-scrollable-menu flex-grow-1 overflow-auto">
        <?php foreach ($menuGroups as [$groupLabel, $groupIcon, $items]):
            $visible = array_values(array_filter($items, static fn($it) => $it[0]));
            if (!$visible) continue;
            if (count($visible) === 1):
                [, $href, $pages, $icon, $label] = $visible[0];
                $label = $visible[0][5] ?? $label; ?>
        <div class="sgs-menu-group sgs-menu-directo">
            <ul class="list-unstyled components mb-0"><?= $menuItem($href, $pages, $icon, $label, $currentPage) ?></ul>
        </div>
            <?php continue; endif;
            $groupId = 'mg-' . preg_replace('/[^a-z]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $groupLabel)));
            $groupActive = (bool)array_filter($visible, static fn($it) => in_array($currentPage, $it[2], true)); ?>
        <div class="sgs-menu-group sgs-menu-seccion<?= $groupActive ? ' is-open is-current' : '' ?>">
            <button type="button" class="sgs-menu-toggle" aria-expanded="<?= $groupActive ? 'true' : 'false' ?>" aria-controls="<?= $groupId ?>" title="<?= htmlspecialchars($groupLabel) ?>">
                <i class="bi <?= $groupIcon ?>" aria-hidden="true"></i><span class="menu-label-text"><?= htmlspecialchars($groupLabel) ?></span><i class="bi bi-chevron-down sgs-menu-flecha" aria-hidden="true"></i>
            </button>
            <div class="sgs-menu-items" id="<?= $groupId ?>">
                <ul class="list-unstyled components mb-0" aria-label="<?= htmlspecialchars($groupLabel) ?>">
                    <?php foreach ($visible as [, $href, $pages, $icon, $label]) echo $menuItem($href, $pages, $icon, $label, $currentPage); ?>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    // Acordeón: al abrir una sección se cierran las demás.
    (() => {
        const secciones = [...document.querySelectorAll('#sidebar .sgs-menu-seccion')];
        const poner = (g, abierta) => { g.classList.toggle('is-open', abierta); g.querySelector('.sgs-menu-toggle').setAttribute('aria-expanded', abierta ? 'true' : 'false'); };
        secciones.forEach((g) => g.querySelector('.sgs-menu-toggle').addEventListener('click', () => {
            const abrir = !g.classList.contains('is-open');
            secciones.forEach((otra) => poner(otra, otra === g && abrir));
        }));
    })();
    </script>
    <div class="sgs-sidebar-account flex-shrink-0">
        <ul class="list-unstyled components mb-0">
            <?= $menuItem('index.php?page=mi_informacion', ['mi_informacion'], 'bi-person-vcard', 'Mi información', $currentPage) ?>
            <!-- Instalar app (2026-10-26, app-instalable.js): con un toque donde el navegador lo permite; en otros celulares (Huawei, iPhone…) muestra los pasos -->
            <li class="d-none"><a href="#" id="btnInstalarApp" title="Instalar la app en este equipo"><i class="bi bi-phone"></i><span class="menu-label-text">Instalar app</span></a></li>
            <li>
                <a href="#" id="btn-logout-sidebar" class="sgs-logout" title="Cerrar sesión">
                    <i class="bi bi-box-arrow-right"></i><span class="menu-label-text">Cerrar sesión</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="sgs-sidebar-foot menu-label-text">v<?= htmlspecialchars(APP_VERSION) ?> · <?= htmlspecialchars(APP_ENV) ?></div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const btnLogout = document.getElementById('btn-logout-sidebar');
    if (!btnLogout) return;
    btnLogout.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!confirm('¿Desea cerrar la sesión actual?') || btnLogout.dataset.processing === '1') return;
        btnLogout.dataset.processing = '1';
        const original = btnLogout.innerHTML;
        btnLogout.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><span class="menu-label-text">Cerrando…</span>';
        try {
            const response = await fetch('api/auth.php?action=logout', { method: 'POST' });
            const result = await response.json();
            if (!response.ok || result.status !== 'success') throw new Error(result.message || 'No se pudo cerrar la sesión.');
            location.replace('index.php?page=login');
        } catch (error) {
            delete btnLogout.dataset.processing;
            btnLogout.innerHTML = original;
            (window.showToast || alert)(error.message || 'No se pudo cerrar la sesión. Inténtelo nuevamente.', 'error');
        }
    });
});
</script>
