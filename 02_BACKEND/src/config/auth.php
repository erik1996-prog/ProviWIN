<?php
require_once __DIR__ . '/territorial.php';
// config/auth.php - Sistema de Autenticación y Autorización por Roles (RBAC)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';


class Auth {
    // Roles permitidos en el sistema
    public const ROLE_ADMIN = 'Administrador';
    public const ROLE_ADMINISTRADOR = 'Administrador';
    public const ROLE_GERENTE = 'Gerente';
    public const ROLE_COORDINADOR = 'Coordinador';
    public const ROLE_SUPERVISOR = 'Supervisor';
    public const ROLE_SGA = 'SGA';
    public const ROLE_SGI = 'SGI';
    /** Jefe de operaciones de un partner (2026-10-12, reemplaza al rol "Partner"): solo consulta lo de su partner. */
    public const ROLE_JEFE_OPERACIONES = 'Jefe de operaciones';

    // Capacidades funcionales. Los roles definen el punto de partida y las
    // reglas territoriales/propiedad completan la autorización de cada registro.
    public const PERM_SUPERVISION_VIEW_ALL = 'supervision.view_all';
    public const PERM_SUPERVISION_VIEW = 'supervision.view';
    public const PERM_SUPERVISION_CREATE = 'supervision.create';
    public const PERM_SUPERVISION_ASSIGN = 'supervision.assign';
    public const PERM_SUPERVISION_DELETE = 'supervision.delete';
    public const PERM_SUPERVISION_IMPORT = 'supervision.import';
    public const PERM_OBSERVATION_REVIEW = 'observation.review';
    public const PERM_OBSERVATION_VIEW = 'observation.view';
    /** Responder observaciones y checklists de su partner (levantamiento con fotos). Por defecto: supervisor del partner. */
    public const PERM_OBSERVATION_RESPOND = 'observation.respond';
    public const PERM_SUPERVISION_FILTERS = 'supervision.filters';
    public const PERM_SUPERVISION_FILTER_DATE = 'supervision.filter.date';
    public const PERM_SUPERVISION_FILTER_STATUS = 'supervision.filter.status';
    public const PERM_SUPERVISION_FILTER_COLUMNS = 'supervision.filter.columns';
    public const PERM_OBSERVATION_FILTER_DATE = 'observation.filter.date';
    public const PERM_OBSERVATION_FILTER_ADVANCED = 'observation.filter.advanced';
    public const PERM_SUPERVISION_MAP = 'supervision.map';
    public const PERM_FORM_FINDINGS = 'supervision.form.findings';
    public const PERM_FORM_CHECKLIST = 'supervision.form.checklist';
    /** Ver la lista de usuarios (solo consulta). Directo WIN: todos; partner: su partner y sus zonas. */
    public const PERM_USERS_VIEW = 'users.view';
    /** Agregar y editar solo datos personales del personal (sin usuario, contraseña, rol, ni activar/desactivar). */
    public const PERM_STAFF_MANAGE = 'users.staff';
    public const PERM_USERS_MANAGE = 'users.manage';
    public const PERM_DASHBOARD_VIEW = 'dashboard.view';
    public const PERM_SYSTEM_MANAGE = 'system.manage';
    public const PERM_ATTENDANCE_SELF = 'attendance.self';
    public const PERM_TIMELINE_VIEW = 'timeline.view';

    private const ROLE_PERMISSIONS = [
        self::ROLE_ADMINISTRADOR => [
            self::PERM_SUPERVISION_VIEW, self::PERM_SUPERVISION_VIEW_ALL, self::PERM_SUPERVISION_ASSIGN,
            self::PERM_SUPERVISION_DELETE, self::PERM_SUPERVISION_IMPORT,
            self::PERM_OBSERVATION_VIEW, self::PERM_OBSERVATION_REVIEW, self::PERM_USERS_VIEW,
            self::PERM_USERS_MANAGE, self::PERM_DASHBOARD_VIEW,
            self::PERM_SYSTEM_MANAGE, self::PERM_SUPERVISION_FILTERS,
            self::PERM_SUPERVISION_MAP, self::PERM_FORM_FINDINGS,
            self::PERM_FORM_CHECKLIST, self::PERM_STAFF_MANAGE,
            self::PERM_TIMELINE_VIEW,
            self::PERM_SUPERVISION_FILTER_DATE, self::PERM_SUPERVISION_FILTER_STATUS, self::PERM_SUPERVISION_FILTER_COLUMNS,
            self::PERM_OBSERVATION_FILTER_DATE, self::PERM_OBSERVATION_FILTER_ADVANCED,
        ],
        self::ROLE_GERENTE => [
            self::PERM_SUPERVISION_VIEW, self::PERM_SUPERVISION_VIEW_ALL,
            self::PERM_OBSERVATION_VIEW, self::PERM_USERS_VIEW,
            self::PERM_DASHBOARD_VIEW, self::PERM_SUPERVISION_FILTERS,
            self::PERM_SUPERVISION_FILTER_DATE, self::PERM_SUPERVISION_FILTER_STATUS, self::PERM_SUPERVISION_FILTER_COLUMNS,
            self::PERM_OBSERVATION_FILTER_DATE, self::PERM_OBSERVATION_FILTER_ADVANCED,
        ],
        self::ROLE_COORDINADOR => [
            self::PERM_SUPERVISION_VIEW, self::PERM_SUPERVISION_VIEW_ALL,
            self::PERM_OBSERVATION_VIEW, self::PERM_OBSERVATION_REVIEW,
            self::PERM_USERS_VIEW, self::PERM_DASHBOARD_VIEW,
            self::PERM_SUPERVISION_FILTERS, self::PERM_SUPERVISION_MAP,
            self::PERM_TIMELINE_VIEW,
            self::PERM_SUPERVISION_FILTER_DATE, self::PERM_SUPERVISION_FILTER_STATUS, self::PERM_SUPERVISION_FILTER_COLUMNS,
            self::PERM_OBSERVATION_FILTER_DATE, self::PERM_OBSERVATION_FILTER_ADVANCED,
        ],
        self::ROLE_SUPERVISOR => [
            self::PERM_SUPERVISION_VIEW, self::PERM_SUPERVISION_CREATE,
            self::PERM_SUPERVISION_ASSIGN, self::PERM_OBSERVATION_VIEW, self::PERM_OBSERVATION_RESPOND,
            self::PERM_OBSERVATION_REVIEW,
            self::PERM_ATTENDANCE_SELF, self::PERM_SUPERVISION_FILTERS,
            self::PERM_SUPERVISION_MAP, self::PERM_FORM_FINDINGS,
            self::PERM_FORM_CHECKLIST,
            self::PERM_SUPERVISION_FILTER_DATE, self::PERM_SUPERVISION_FILTER_STATUS,
            self::PERM_SUPERVISION_FILTER_COLUMNS, self::PERM_OBSERVATION_FILTER_DATE,
        ],
        self::ROLE_JEFE_OPERACIONES => [self::PERM_SUPERVISION_VIEW, self::PERM_OBSERVATION_VIEW, self::PERM_OBSERVATION_FILTER_DATE, self::PERM_USERS_VIEW],
        self::ROLE_SGA => [],
        self::ROLE_SGI => [],
    ];

    private static array $permissionOverrides = [];

    public static function can(string $permission, ?array $user = null): bool {
        $role = (string)($user['rol'] ?? ($_SESSION['user_rol'] ?? ''));
        $allowed = in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true);
        $userId = (int)($user['id'] ?? ($_SESSION['user_id'] ?? 0));
        if ($userId < 1 || !array_key_exists($permission, self::permissionCatalog())) return $allowed;
        if (!array_key_exists($userId, self::$permissionOverrides)) {
            self::$permissionOverrides[$userId] = [];
            try {
                $stmt = Database::getConnection()->prepare('SELECT permiso, permitido FROM usuario_permisos WHERE usuario_id=:usuario_id');
                $stmt->execute([':usuario_id'=>$userId]);
                foreach ($stmt->fetchAll() as $row) self::$permissionOverrides[$userId][$row['permiso']] = (bool)$row['permitido'];
            } catch (Throwable $error) {
                // Durante una migración pendiente se conservan de forma segura los permisos del rol.
            }
        }
        return self::$permissionOverrides[$userId][$permission] ?? $allowed;
    }

    public static function permissionCatalog(): array {
        return [
            self::PERM_SUPERVISION_VIEW => ['group'=>'Supervisiones','label'=>'Ver el módulo de supervisiones','delegable'=>true],
            self::PERM_SUPERVISION_VIEW_ALL => ['group'=>'Supervisiones · Visibilidad','label'=>'Global: ver registros de todos los usuarios','delegable'=>true],
            self::PERM_SUPERVISION_CREATE => ['group'=>'Supervisiones','label'=>'Registrar supervisiones','delegable'=>true],
            self::PERM_SUPERVISION_ASSIGN => ['group'=>'Supervisiones','label'=>'Asignar pendientes a responsables','delegable'=>true],
            self::PERM_SUPERVISION_FILTERS => ['group'=>'Supervisiones','label'=>'Usar filtros y configuración de columnas','delegable'=>true],
            self::PERM_SUPERVISION_FILTER_DATE => ['group'=>'Supervisiones · Filtros','label'=>'Rango de fechas','delegable'=>true],
            self::PERM_SUPERVISION_FILTER_STATUS => ['group'=>'Supervisiones · Filtros','label'=>'Estados de supervisión','delegable'=>true],
            self::PERM_SUPERVISION_FILTER_COLUMNS => ['group'=>'Supervisiones · Filtros','label'=>'Columnas visibles','delegable'=>true],
            self::PERM_SUPERVISION_MAP => ['group'=>'Supervisiones','label'=>'Ver mapa y rutas de pendientes','delegable'=>true],
            self::PERM_FORM_FINDINGS => ['group'=>'Formularios','label'=>'Abrir formulario de hallazgos','delegable'=>true],
            self::PERM_FORM_CHECKLIST => ['group'=>'Formularios','label'=>'Abrir checklist técnico','delegable'=>true],
            self::PERM_OBSERVATION_VIEW => ['group'=>'Observaciones','label'=>'Ver observaciones','delegable'=>true],
            self::PERM_OBSERVATION_RESPOND => ['group'=>'Observaciones','label'=>'Responder observaciones del partner (levantamiento con fotos)','delegable'=>true],
            self::PERM_OBSERVATION_FILTER_DATE => ['group'=>'Observaciones · Filtros','label'=>'Rango de fechas','delegable'=>true],
            self::PERM_OBSERVATION_FILTER_ADVANCED => ['group'=>'Observaciones · Filtros','label'=>'Partner, técnico, zona y estado','delegable'=>true],
            self::PERM_OBSERVATION_REVIEW => ['group'=>'Observaciones','label'=>'Revisar y validar observaciones','delegable'=>true],
            self::PERM_USERS_VIEW => ['group'=>'Usuarios','label'=>'Ver usuarios (solo consulta; el partner ve solo a su partner y sus zonas)','delegable'=>true],
            self::PERM_STAFF_MANAGE => ['group'=>'Usuarios','label'=>'Agregar y editar personal (solo datos personales; sin acceso, rol ni activar)','delegable'=>true],
            self::PERM_DASHBOARD_VIEW => ['group'=>'Consulta','label'=>'Ver indicadores generales','delegable'=>true],
            self::PERM_TIMELINE_VIEW => ['group'=>'Consulta','label'=>'Ver línea de tiempo','delegable'=>true],
            self::PERM_ATTENDANCE_SELF => ['group'=>'Operación personal','label'=>'Registrar el propio turno','delegable'=>true],
            self::PERM_USERS_MANAGE => ['group'=>'Reservado','label'=>'Administrar usuarios','delegable'=>false],
            self::PERM_SUPERVISION_DELETE => ['group'=>'Reservado','label'=>'Eliminar supervisiones','delegable'=>false],
            self::PERM_SUPERVISION_IMPORT => ['group'=>'Reservado','label'=>'Importar cancelaciones','delegable'=>false],
            self::PERM_SYSTEM_MANAGE => ['group'=>'Reservado','label'=>'Administrar el sistema','delegable'=>false],
        ];
    }

    public static function roleDefaultPermissions(string $role): array {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    public static function requirePermission(string $permission): void {
        self::requireLogin();
        if (!self::can($permission)) {
            http_response_code(403);
            if ((defined('WIN_JSON_RESPONSE') && WIN_JSON_RESPONSE) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'No tiene permisos para realizar esta acción.']);
            } else {
                header('Location: index.php?page=unauthorized');
            }
            exit;
        }
    }

    public static function isLoggedIn(): bool {
        if (empty($_SESSION['user_id'])) return false;
        // Una cuenta desactivada o eliminada pierde el acceso aunque su sesión siga abierta.
        // Se revalida contra la BD como máximo una vez por minuto para no consultar en cada llamada.
        if (PHP_SAPI !== 'cli' && time() - (int)($_SESSION['sgs_revalidated_at'] ?? 0) >= 60) {
            $stmt = Database::getConnection()->prepare('SELECT activo, rol FROM usuarios WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$_SESSION['user_id']]);
            $row = $stmt->fetch();
            if (!$row || empty($row['activo']) || $row['rol'] !== ($_SESSION['user_rol'] ?? null)) {
                win_log_error('session_revoked', 'Sesión cerrada: la cuenta fue desactivada, eliminada o cambió de rol.', ['user_id' => (int)$_SESSION['user_id']]);
                self::logout();
                if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
                return false;
            }
            $_SESSION['sgs_revalidated_at'] = time();
        }
        return true;
    }

    public static function getLoggedInUser(): ?array {
        if (!self::isLoggedIn()) return null;
        $partnerNombre = $_SESSION['user_partner_nombre'] ?? '';
        // Nombre exacto (2026-10-13): con "contiene WIN", un partner llamado p. ej. "NETWIN" habría visto todo como personal directo.
        $isWinDirecto = (trim($partnerNombre) === '' || in_array(mb_strtoupper(trim($partnerNombre), 'UTF-8'), ['WIN', 'WIN DIRECTO'], true))
            && ($_SESSION['user_rol'] !== self::ROLE_JEFE_OPERACIONES);

        return [
            'id' => $_SESSION['user_id'],
            'dni' => $_SESSION['user_dni'] ?? '',
            'nombres' => $_SESSION['user_nombres'] ?? '',
            'apellidos' => $_SESSION['user_apellidos'] ?? '',
            'usuario' => $_SESSION['user_usuario'] ?? '',
            'nombre' => $_SESSION['user_nombre'] ?? '',
            'email' => $_SESSION['user_email'] ?? '',
            'rol' => $_SESSION['user_rol'] ?? '',
            'partner_id' => $_SESSION['user_partner_id'] ?? null,
            'partner_nombre' => $partnerNombre,
            'area_trabajo' => $_SESSION['user_area_trabajo'] ?? '',
            'zona' => $_SESSION['user_zona'] ?? 'Provincias',
            'departamento' => $_SESSION['user_departamento'] ?? '',
            'procesos_copiados' => $_SESSION['user_procesos_copiados'] ?? '',
            'observacion' => $_SESSION['user_observacion'] ?? '',
            'avatar' => $_SESSION['user_avatar'] ?? 'assets/img/default-avatar.png',
            'is_win_directo' => $isWinDirecto
        ];
    }

    // ---------- Bloqueo de cuenta por intentos fallidos (tabla login_attempts, clave por usuario) ----------
    public const MAX_LOGIN_FAILURES = 3;
    public const LOCKED_MESSAGE = 'Superaste los 3 intentos permitidos y tu usuario quedó bloqueado. Comunícate con el administrador del sistema para recuperar tu acceso.';
    private const LOCK_UNTIL = '2099-12-31 23:59:59'; // indefinido: solo el administrador lo libera

    private static function accountKey(int $userId): string { return hash('sha256', 'sgs-cuenta|' . $userId); }

    public static function accountLocked(int $userId): bool {
        $st = Database::getConnection()->prepare('SELECT 1 FROM login_attempts WHERE fingerprint = :f AND blocked_until > NOW() LIMIT 1');
        $st->execute([':f' => self::accountKey($userId)]);
        return (bool)$st->fetchColumn();
    }

    /** Suma un intento fallido y devuelve cuántos quedan (0 = quedó bloqueado). */
    private static function registerFailedLogin(int $userId): int {
        $pdo = Database::getConnection(); $key = self::accountKey($userId);
        $pdo->prepare('INSERT INTO login_attempts (fingerprint, attempts, first_attempt_at, last_attempt_at, blocked_until) VALUES (:f, 1, NOW(), NOW(), NULL)
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = NOW()')->execute([':f' => $key]);
        $st = $pdo->prepare('SELECT attempts FROM login_attempts WHERE fingerprint = :f'); $st->execute([':f' => $key]);
        $intentos = (int)$st->fetchColumn();
        if ($intentos >= self::MAX_LOGIN_FAILURES) {
            $pdo->prepare('UPDATE login_attempts SET blocked_until = :hasta WHERE fingerprint = :f')->execute([':hasta' => self::LOCK_UNTIL, ':f' => $key]);
            return 0;
        }
        return self::MAX_LOGIN_FAILURES - $intentos;
    }

    private static function clearFailedLogins(int $userId): void {
        Database::getConnection()->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => self::accountKey($userId)]);
    }

    /** El administrador libera la cuenta (también al restablecer la contraseña). */
    public static function unlockAccount(int $userId): void { self::clearFailedLogins($userId); }

    /** IDs bloqueados, para mostrarlos en Usuarios y roles. */
    public static function lockedAccountIds(array $userIds): array {
        if (!$userIds) return [];
        $keys = []; foreach ($userIds as $id) $keys[self::accountKey((int)$id)] = (int)$id;
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = Database::getConnection()->prepare("SELECT fingerprint FROM login_attempts WHERE blocked_until > NOW() AND fingerprint IN ($in)");
        $st->execute(array_keys($keys));
        return array_values(array_map(fn($f) => $keys[$f], $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function login(string $credential, string $password): array {

        $pdo = Database::getConnection();
        
        $term = trim($credential);
        $stmt = $pdo->prepare("
            SELECT u.*, p.nombre_partner 
            FROM usuarios u
            LEFT JOIN partners p ON u.partner_id = p.id
            WHERE (u.usuario = :u OR u.dni = :d OR u.email = :e) AND u.activo = 1
            LIMIT 1
        ");
        $stmt->execute([':u' => $term, ':d' => $term, ':e' => $term]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 'error', 'message' => 'Usuario o contraseña incorrectos.'];
        }

        // Bloqueo por cuenta: 3 intentos fallidos seguidos bloquean el usuario hasta que el administrador lo libere.
        if (self::accountLocked((int)$user['id'])) {
            return ['status' => 'error', 'locked' => true, 'cuenta' => true, 'message' => self::LOCKED_MESSAGE];
        }
        if (!password_verify($password, $user['password_hash'])) {
            $restantes = self::registerFailedLogin((int)$user['id']);
            if ($restantes <= 0) {
                win_log_error('audit_account_locked', 'Usuario bloqueado por ' . self::MAX_LOGIN_FAILURES . ' intentos fallidos', ['target_id' => (int)$user['id']]);
                return ['status' => 'error', 'locked' => true, 'cuenta' => true, 'message' => self::LOCKED_MESSAGE];
            }
            return ['status' => 'error', 'cuenta' => true, 'message' => 'Usuario o contraseña incorrectos. ' . ($restantes === 1 ? 'Te queda 1 intento.' : "Te quedan {$restantes} intentos.")];
        }
        self::clearFailedLogins((int)$user['id']);
        // Último ingreso (2026-10-15), para la lista de usuarios.
        $pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id')->execute([':id' => (int)$user['id']]);

        // Crear Sesión segura únicamente después de completar la autenticación.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_dni'] = $user['dni'];
        $_SESSION['user_nombres'] = $user['nombres'] ?? '';
        $_SESSION['user_apellidos'] = $user['apellidos'] ?? '';
        $_SESSION['user_usuario'] = $user['usuario'] ?? '';
        $_SESSION['user_nombre'] = !empty($user['nombre_completo']) ? $user['nombre_completo'] : trim(($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? ''));
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_rol'] = $user['rol'];
        $_SESSION['user_partner_id'] = $user['partner_id'];
        $_SESSION['user_partner_nombre'] = $user['nombre_partner'] ?? '';
        $_SESSION['user_area_trabajo'] = $user['area_trabajo'] ?? '';
        $_SESSION['user_zona'] = !empty($user['zona']) ? $user['zona'] : 'Provincias';
        $_SESSION['user_departamento'] = $user['departamento'] ?? operationalDepartmentFromSite((string)($user['sede'] ?? $user['zona'] ?? ''));
        $_SESSION['user_procesos_copiados'] = $user['procesos_copiados'] ?? '';
        $_SESSION['user_observacion'] = $user['observacion'] ?? '';
        $_SESSION['user_avatar'] = !empty($user['avatar']) ? $user['avatar'] : 'assets/img/default-avatar.png';
        // Reiniciar el control visual de flyers en cada autenticación. De esta
        // manera el flyer vigente aparece una vez por ingreso al sistema.
        $_SESSION['flyers_mostrados_ingreso'] = [];
        // Contraseña puesta por el administrador: hasta cambiarla solo puede ver la pantalla de cambio (requireLogin).
        if (!empty($user['debe_cambiar_password'])) $_SESSION['debe_cambiar_password'] = true;
        else unset($_SESSION['debe_cambiar_password']);

        return [
            'status' => 'success',
            'debe_cambiar_password' => !empty($user['debe_cambiar_password']),
            'message' => 'Inicio de sesión exitoso.',
            'user' => self::getLoggedInUser()
        ];
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function hasRole(array $allowedRoles): bool {
        if (!self::isLoggedIn()) return false;
        return in_array($_SESSION['user_rol'], $allowedRoles, true);
    }

    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Solicitud rechazada: token CSRF inválido.']);
            exit;
        }
    }

    public static function isAdministrative(): bool {
        return self::hasRole([
            self::ROLE_ADMINISTRADOR,
            self::ROLE_GERENTE,
            self::ROLE_COORDINADOR,
        ]);
    }

    public static function isAdministrator(): bool {
        return self::hasRole([self::ROLE_ADMINISTRADOR]);
    }

    public static function mustChangePassword(): bool { return !empty($_SESSION['debe_cambiar_password']); }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            // Las APIs responden 401 en JSON; las pantallas vuelven al login.
            if (defined('WIN_JSON_RESPONSE') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
                if (ob_get_level() > 0) ob_clean();
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'Su sesión expiró. Vuelva a iniciar sesión.', 'session_expired' => true]);
                exit;
            }
            header('Location: index.php?page=login');
            exit;
        }
        // Cambio de contraseña obligatorio (2026-10-15): las APIs solo atienden el cambio propio; las pantallas
        // (index.php) muestran únicamente el formulario de cambio. Cerrar sesión no pasa por aquí.
        if (self::mustChangePassword() && str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')
            && !(str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/api/usuarios.php') && ($_GET['action'] ?? '') === 'cambiar_password_propia')) {
            if (ob_get_level() > 0) ob_clean();
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Antes de continuar debe cambiar su contraseña.', 'debe_cambiar_password' => true]);
            exit;
        }
        // Rendimiento con muchos usuarios: PHP bloquea la sesión durante toda la petición, y las consultas
        // simultáneas del mismo usuario (una pantalla dispara varias) se ponían en fila. Las lecturas (GET) de
        // la API no escriben en la sesión después de este punto, así que se libera de inmediato.
        if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function requireRoles(array $allowedRoles): void {
        self::requireLogin();
        if (!self::hasRole($allowedRoles)) {
            header('Location: index.php?page=unauthorized');
            exit;
        }
    }
}
