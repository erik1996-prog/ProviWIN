<?php
require_once __DIR__ . '/territorial.php';
// config/auth.php - Sistema de Autenticación y Autorización por Roles (RBAC)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';


class Auth {
    // Roles permitidos en el sistema
    public const ROLE_ADMINISTRADOR = 'Administrador';
    public const ROLE_GERENTE = 'Gerente';
    public const ROLE_COORDINADOR = 'Coordinador';
    public const ROLE_SUPERVISOR = 'Supervisor';
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
    public const PERM_USERS_TEAM = 'users.team'; // 2026-10-27: el líder registra y da de baja a su equipo (hacia abajo)
    public const PERM_USERS_MANAGE = 'users.manage';
    public const PERM_DASHBOARD_VIEW = 'dashboard.view';
    public const PERM_SYSTEM_MANAGE = 'system.manage';
    public const PERM_ATTENDANCE_SELF = 'attendance.self';
    public const PERM_TIMELINE_VIEW = 'timeline.view';
    // Módulos con ver / editar / eliminar (2026-10-23). El supervisor de partner no entra a estos módulos aunque su rol
    // los tenga: además del permiso se exige personal administrativo o directo (Auth::esPersonalWin).
    public const PERM_PARTNERS_DASHBOARD = 'dashboard.partners';
    public const PERM_PENDIENTES_VIEW = 'pendientes.view';
    /** Matriz de asistencia mensual (2026-10-28): ver y corregir celdas. */
    public const PERM_ASISTENCIA_MATRIZ_VIEW = 'asistencia_matriz.view';
    public const PERM_ASISTENCIA_MATRIZ_EDIT = 'asistencia_matriz.edit';
    public const PERM_CLIMA_VIEW = 'clima.view';
    public const PERM_CLIMA_EDIT = 'clima.edit';
    public const PERM_CLIMA_DELETE = 'clima.delete';
    public const PERM_EXAMENES_VIEW = 'examenes.view';
    public const PERM_EXAMENES_EDIT = 'examenes.edit';
    public const PERM_EXAMENES_DELETE = 'examenes.delete';
    public const PERM_CUADRILLAS_VIEW = 'cuadrillas.view';
    public const PERM_CUADRILLAS_EDIT = 'cuadrillas.edit';
    public const PERM_CUADRILLAS_IMPORT = 'cuadrillas.import';
    public const PERM_PARTNERS_VIEW = 'partners.view';
    public const PERM_PARTNERS_EDIT = 'partners.edit';
    public const PERM_PARTNERS_DELETE = 'partners.delete';
    public const PERM_OBSERVATION_DELETE = 'observation.delete';

    /** Orden de los roles (2026-10-27): un líder solo asigna roles por debajo del suyo. */
    public const NIVEL_ROL = ['Administrador' => 5, 'Gerente' => 4, 'Coordinador' => 3, 'Jefe de operaciones' => 2, 'Supervisor' => 1];
    /** Nombre que se muestra de cada rol (2026-10-27): por lo que hace, para no repetir los cargos. El valor guardado no cambia. */
    public const ETIQUETAS_ROL = ['Coordinador' => 'Gestión', 'Gerente' => 'Consulta', 'Jefe de operaciones' => 'Partner'];

    public static function nivelRol(string $rol): int { return self::NIVEL_ROL[$rol] ?? 0; }
    public static function etiquetaRol(string $rol): string { return self::ETIQUETAS_ROL[$rol] ?? $rol; }

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
            self::PERM_PARTNERS_DASHBOARD, self::PERM_PENDIENTES_VIEW, self::PERM_CLIMA_VIEW, self::PERM_CLIMA_DELETE,
            self::PERM_ASISTENCIA_MATRIZ_VIEW, self::PERM_ASISTENCIA_MATRIZ_EDIT,
            self::PERM_EXAMENES_VIEW, self::PERM_EXAMENES_EDIT, self::PERM_EXAMENES_DELETE,
            self::PERM_CUADRILLAS_VIEW, self::PERM_CUADRILLAS_EDIT, self::PERM_CUADRILLAS_IMPORT,
            self::PERM_PARTNERS_VIEW, self::PERM_PARTNERS_EDIT, self::PERM_PARTNERS_DELETE, self::PERM_OBSERVATION_DELETE,
        ],
        self::ROLE_GERENTE => [
            self::PERM_SUPERVISION_VIEW, self::PERM_SUPERVISION_VIEW_ALL,
            self::PERM_OBSERVATION_VIEW, self::PERM_USERS_VIEW,
            self::PERM_DASHBOARD_VIEW, self::PERM_SUPERVISION_FILTERS,
            self::PERM_SUPERVISION_FILTER_DATE, self::PERM_SUPERVISION_FILTER_STATUS, self::PERM_SUPERVISION_FILTER_COLUMNS,
            self::PERM_OBSERVATION_FILTER_DATE, self::PERM_OBSERVATION_FILTER_ADVANCED,
            self::PERM_PARTNERS_DASHBOARD, self::PERM_PENDIENTES_VIEW, self::PERM_CLIMA_VIEW, self::PERM_ASISTENCIA_MATRIZ_VIEW,
            self::PERM_EXAMENES_VIEW, self::PERM_EXAMENES_EDIT, self::PERM_EXAMENES_DELETE,
            self::PERM_CUADRILLAS_VIEW, self::PERM_CUADRILLAS_EDIT, self::PERM_CUADRILLAS_IMPORT,
        ],
        self::ROLE_COORDINADOR => [
            self::PERM_SUPERVISION_VIEW, self::PERM_SUPERVISION_VIEW_ALL,
            self::PERM_OBSERVATION_VIEW, self::PERM_OBSERVATION_REVIEW,
            self::PERM_USERS_VIEW, self::PERM_DASHBOARD_VIEW,
            self::PERM_SUPERVISION_FILTERS, self::PERM_SUPERVISION_MAP,
            self::PERM_TIMELINE_VIEW,
            self::PERM_SUPERVISION_FILTER_DATE, self::PERM_SUPERVISION_FILTER_STATUS, self::PERM_SUPERVISION_FILTER_COLUMNS,
            self::PERM_OBSERVATION_FILTER_DATE, self::PERM_OBSERVATION_FILTER_ADVANCED,
            self::PERM_PARTNERS_DASHBOARD, self::PERM_PENDIENTES_VIEW, self::PERM_CLIMA_VIEW, self::PERM_ASISTENCIA_MATRIZ_VIEW,
            self::PERM_EXAMENES_VIEW, self::PERM_EXAMENES_EDIT, self::PERM_EXAMENES_DELETE,
            self::PERM_CUADRILLAS_VIEW, self::PERM_CUADRILLAS_EDIT, self::PERM_CUADRILLAS_IMPORT,
            self::PERM_USERS_TEAM, // 2026-10-27: el líder (Gestión) registra a sus supervisores de su zona y área
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
            self::PERM_PENDIENTES_VIEW, self::PERM_CLIMA_VIEW, self::PERM_CLIMA_EDIT,
            self::PERM_EXAMENES_VIEW, self::PERM_EXAMENES_EDIT, self::PERM_EXAMENES_DELETE,
            self::PERM_CUADRILLAS_VIEW, self::PERM_CUADRILLAS_EDIT,
        ],
        self::ROLE_JEFE_OPERACIONES => [self::PERM_SUPERVISION_VIEW, self::PERM_OBSERVATION_VIEW, self::PERM_OBSERVATION_FILTER_DATE, self::PERM_USERS_VIEW],
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
        $tiene = self::$permissionOverrides[$userId][$permission] ?? $allowed;
        // 2026-10-26: sin "Ver" de la pantalla no hay editar, eliminar ni opciones en ella (aunque hubiera quedado marcado)
        if ($tiene && ($requerido = self::permisoRequerido($permission)) !== null) return self::can($requerido, $user);
        return $tiene;
    }

    /** Permiso "Ver" de la pantalla a la que pertenece $permission (null si es el propio Ver o no depende de uno). */
    public static function permisoRequerido(string $permission): ?string {
        static $ver = null;
        if ($ver === null) {
            $ver = [];
            foreach (self::permissionCatalog() as $clave => $d) if ($d['delegable'] && ($d['action'] ?? '') === 'ver') $ver[$d['group']] = $clave;
        }
        $d = self::permissionCatalog()[$permission] ?? null;
        if (!$d || ($d['action'] ?? '') === 'ver') return null;
        return $ver[$d['group']] ?? null;
    }

    /** Quita de una lista los permisos cuya pantalla no se puede ver (al guardar permisos personalizados). */
    public static function conDependencias(array $permisos): array {
        return array_values(array_filter($permisos, static fn($p) => ($r = self::permisoRequerido((string)$p)) === null || in_array($r, $permisos, true)));
    }

    public static function permissionCatalog(): array {
        static $catalogo = null;
        return $catalogo ??= [
            self::PERM_SUPERVISION_VIEW => ['group'=>'Supervisiones','label'=>'Ver el módulo de supervisiones','action'=>'ver','delegable'=>true],
            self::PERM_SUPERVISION_VIEW_ALL => ['group'=>'Supervisiones','label'=>'Global: ver registros de todos los usuarios','delegable'=>true],
            self::PERM_SUPERVISION_CREATE => ['group'=>'Supervisiones','label'=>'Editar: registrar y completar supervisiones','action'=>'editar','delegable'=>true],
            self::PERM_SUPERVISION_ASSIGN => ['group'=>'Supervisiones','label'=>'Asignar pendientes a responsables','action'=>'editar','delegable'=>true],
            self::PERM_SUPERVISION_FILTERS => ['group'=>'Supervisiones','label'=>'Usar filtros y configuración de columnas','delegable'=>true],
            self::PERM_SUPERVISION_FILTER_DATE => ['group'=>'Supervisiones','label'=>'Rango de fechas','delegable'=>true],
            self::PERM_SUPERVISION_FILTER_STATUS => ['group'=>'Supervisiones','label'=>'Estados de supervisión','delegable'=>true],
            self::PERM_SUPERVISION_FILTER_COLUMNS => ['group'=>'Supervisiones','label'=>'Columnas visibles','delegable'=>true],
            self::PERM_SUPERVISION_MAP => ['group'=>'Supervisiones','label'=>'Ver mapa y rutas de pendientes','delegable'=>true],
            self::PERM_FORM_FINDINGS => ['group'=>'Supervisiones','label'=>'Abrir formulario de hallazgos','delegable'=>true],
            self::PERM_FORM_CHECKLIST => ['group'=>'Supervisiones','label'=>'Abrir checklist técnico','delegable'=>true],
            self::PERM_OBSERVATION_VIEW => ['group'=>'Observaciones','label'=>'Ver observaciones','action'=>'ver','delegable'=>true],
            self::PERM_OBSERVATION_RESPOND => ['group'=>'Observaciones','label'=>'Responder observaciones del partner (levantamiento con fotos)','action'=>'editar','delegable'=>true],
            self::PERM_OBSERVATION_FILTER_DATE => ['group'=>'Observaciones','label'=>'Rango de fechas','delegable'=>true],
            self::PERM_OBSERVATION_FILTER_ADVANCED => ['group'=>'Observaciones','label'=>'Partner, técnico, zona y estado','delegable'=>true],
            self::PERM_OBSERVATION_REVIEW => ['group'=>'Observaciones','label'=>'Revisar y validar observaciones','action'=>'editar','delegable'=>true],
            self::PERM_OBSERVATION_DELETE => ['group'=>'Observaciones','label'=>'Eliminar observaciones','action'=>'eliminar','delegable'=>true],
            self::PERM_USERS_VIEW => ['group'=>'Usuarios y roles','label'=>'Ver usuarios (solo consulta; el partner ve solo a su partner y sus zonas)','action'=>'ver','delegable'=>true],
            self::PERM_STAFF_MANAGE => ['group'=>'Usuarios y roles','label'=>'Agregar y editar personal (solo datos personales; sin acceso, rol ni activar)','action'=>'editar','delegable'=>true],
            self::PERM_USERS_TEAM => ['group'=>'Usuarios y roles','label'=>'Registrar, editar y dar de baja a sus supervisores (directos o de partner; sus zonas, su área y sus permisos)','action'=>'editar','delegable'=>true],
            self::PERM_DASHBOARD_VIEW => ['group'=>'Dashboard de supervisiones','label'=>'Ver Dashboard de supervisiones','action'=>'ver','delegable'=>true],
            self::PERM_PARTNERS_DASHBOARD => ['group'=>'Dashboard de partners','label'=>'Ver Dashboard de partners','action'=>'ver','delegable'=>true],
            self::PERM_PENDIENTES_VIEW => ['group'=>'Pendientes y rescates','label'=>'Ver Pendientes y rescates','action'=>'ver','delegable'=>true],
            self::PERM_ASISTENCIA_MATRIZ_VIEW => ['group'=>'Matriz de asistencia','label'=>'Ver la matriz de asistencia mensual','action'=>'ver','delegable'=>true],
            self::PERM_ASISTENCIA_MATRIZ_EDIT => ['group'=>'Matriz de asistencia','label'=>'Editar: corregir el código de un día','action'=>'editar','delegable'=>true],
            self::PERM_CLIMA_VIEW => ['group'=>'Clima','label'=>'Ver: indicador de clima y lluvias','action'=>'ver','delegable'=>true],
            self::PERM_CLIMA_EDIT => ['group'=>'Clima','label'=>'Editar: avisar y cerrar lluvias en su zona (Mi turno)','action'=>'editar','delegable'=>true],
            self::PERM_CLIMA_DELETE => ['group'=>'Clima','label'=>'Eliminar registros de lluvia','action'=>'eliminar','delegable'=>true],
            self::PERM_EXAMENES_VIEW => ['group'=>'Exámenes','label'=>'Ver: resultados y links de examen','action'=>'ver','delegable'=>true],
            self::PERM_EXAMENES_EDIT => ['group'=>'Exámenes','label'=>'Editar: generar links de examen','action'=>'editar','delegable'=>true],
            self::PERM_EXAMENES_DELETE => ['group'=>'Exámenes','label'=>'Eliminar: anular links de examen','action'=>'eliminar','delegable'=>true],
            self::PERM_CUADRILLAS_VIEW => ['group'=>'Técnicos y cuadrillas','label'=>'Ver: técnicos, cuadrillas y vencimientos','action'=>'ver','delegable'=>true],
            self::PERM_CUADRILLAS_EDIT => ['group'=>'Técnicos y cuadrillas','label'=>'Editar: cuadrillas y fechas de vencimiento','action'=>'editar','delegable'=>true],
            self::PERM_CUADRILLAS_IMPORT => ['group'=>'Técnicos y cuadrillas','label'=>'Cargar y descargar técnicos líderes (Excel)','action'=>'editar','delegable'=>true],
            self::PERM_PARTNERS_VIEW => ['group'=>'Partners','label'=>'Ver partners','action'=>'ver','delegable'=>true],
            self::PERM_PARTNERS_EDIT => ['group'=>'Partners','label'=>'Editar: crear, renombrar, activar y desactivar partners','action'=>'editar','delegable'=>true],
            self::PERM_PARTNERS_DELETE => ['group'=>'Partners','label'=>'Eliminar partners (solo sin historial)','action'=>'eliminar','delegable'=>true],
            self::PERM_TIMELINE_VIEW => ['group'=>'Línea de tiempo','label'=>'Ver línea de tiempo','action'=>'ver','delegable'=>true],
            self::PERM_ATTENDANCE_SELF => ['group'=>'Mi turno','label'=>'Registrar el propio turno','action'=>'editar','delegable'=>true],
            self::PERM_USERS_MANAGE => ['group'=>'Reservado','label'=>'Administrar usuarios','delegable'=>false],
            self::PERM_SUPERVISION_DELETE => ['group'=>'Reservado','label'=>'Eliminar supervisiones','delegable'=>false],
            self::PERM_SUPERVISION_IMPORT => ['group'=>'Reservado','label'=>'Importar cancelaciones, corregir las pendientes cargadas y marcarlas con prioridad alta','delegable'=>false],
            self::PERM_SYSTEM_MANAGE => ['group'=>'Reservado','label'=>'Administrar el sistema','delegable'=>false],
        ];
    }

    public static function roleDefaultPermissions(string $role): array {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    public static function isLoggedIn(): bool {
        if (empty($_SESSION['user_id'])) return false;
        // 4 horas sin ninguna petición: se cierra la sesión (2026-10-26). La pantalla además la cierra si nadie la usa.
        if (PHP_SAPI !== 'cli') {
            $ultimo = (int)($_SESSION['sgs_ultimo_uso'] ?? time());
            if (time() - $ultimo > SGS_SESION_INACTIVA) {
                win_log_error('session_idle', 'Sesión cerrada por inactividad', ['user_id' => (int)$_SESSION['user_id']]);
                self::logout();
                if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
                $_SESSION['sgs_motivo_salida'] = 'inactividad';
                return false;
            }
            $_SESSION['sgs_ultimo_uso'] = time();
        }
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

    /** Cuentas protegidas (2026-10-26): nadie más puede verlas en detalle ni modificarlas (datos, rol, permisos, contraseña,
     * estado, desbloqueo); solo su titular. Para que no las pueda tomar otra cuenta. Como nadie más puede desbloquearlas,
     * sus intentos fallidos las bloquean 30 minutos, no de forma indefinida. */
    public const USUARIOS_PROTEGIDOS = ['eplasencia'];
    private const LOCK_PROTEGIDA_MINUTOS = 30;

    /** Cuenta principal (2026-10-26): la primera cuenta protegida activa. Es la única que activa usuarios; si no existe, cualquier administrador. */
    public static function cuentaPrincipalId(): ?int {
        static $id = false;
        if ($id === false) {
            $in = implode(',', array_fill(0, count(self::USUARIOS_PROTEGIDOS), '?'));
            $st = Database::getConnection()->prepare("SELECT id FROM usuarios WHERE activo = 1 AND usuario IN ($in) ORDER BY id LIMIT 1");
            $st->execute(self::USUARIOS_PROTEGIDOS);
            $id = ($v = $st->fetchColumn()) ? (int)$v : null;
        }
        return $id;
    }

    /** La cuenta principal (o cualquiera, si no existe): activa usuarios y programa los envíos del corte de lluvias. */
    public static function esCuentaPrincipal(int $actorId): bool {
        $principal = self::cuentaPrincipalId();
        return $principal === null || $principal === $actorId;
    }

    public static function esProtegido(int $userId): bool {
        static $cache = [];
        if (!array_key_exists($userId, $cache)) {
            $st = Database::getConnection()->prepare('SELECT usuario FROM usuarios WHERE id = :id');
            $st->execute([':id' => $userId]);
            $cache[$userId] = in_array((string)$st->fetchColumn(), self::USUARIOS_PROTEGIDOS, true);
        }
        return $cache[$userId];
    }

    private static function accountKey(int $userId): string { return hash('sha256', 'sgs-cuenta|' . $userId); }

    public static function accountLocked(int $userId): bool {
        $st = Database::getConnection()->prepare('SELECT 1 FROM login_attempts WHERE fingerprint = :f AND blocked_until > NOW() LIMIT 1');
        $st->execute([':f' => self::accountKey($userId)]);
        return (bool)$st->fetchColumn();
    }

    /** Suma un intento fallido y devuelve cuántos quedan (0 = quedó bloqueado). */
    private static function registerFailedLogin(int $userId, bool $protegida = false): int {
        $pdo = Database::getConnection(); $key = self::accountKey($userId);
        $pdo->prepare('INSERT INTO login_attempts (fingerprint, attempts, first_attempt_at, last_attempt_at, blocked_until) VALUES (:f, 1, NOW(), NOW(), NULL)
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = NOW()')->execute([':f' => $key]);
        $st = $pdo->prepare('SELECT attempts FROM login_attempts WHERE fingerprint = :f'); $st->execute([':f' => $key]);
        $intentos = (int)$st->fetchColumn();
        if ($intentos >= self::MAX_LOGIN_FAILURES) {
            $hasta = $protegida ? date('Y-m-d H:i:s', time() + self::LOCK_PROTEGIDA_MINUTOS * 60) : self::LOCK_UNTIL;
            $pdo->prepare('UPDATE login_attempts SET blocked_until = :hasta WHERE fingerprint = :f')->execute([':hasta' => $hasta, ':f' => $key]);
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

        // Bloqueo por cuenta: 3 intentos fallidos seguidos bloquean el usuario hasta que el administrador lo libere
        // (una cuenta protegida, 30 minutos: nadie más puede liberarla).
        $protegida = in_array((string)$user['usuario'], self::USUARIOS_PROTEGIDOS, true);
        $mensajeBloqueo = $protegida ? 'Superaste los ' . self::MAX_LOGIN_FAILURES . ' intentos permitidos. Por seguridad, la cuenta quedó bloqueada ' . self::LOCK_PROTEGIDA_MINUTOS . ' minutos.' : self::LOCKED_MESSAGE;
        if (self::accountLocked((int)$user['id'])) {
            return ['status' => 'error', 'locked' => true, 'cuenta' => true, 'message' => $mensajeBloqueo];
        }
        if (!password_verify($password, $user['password_hash'])) {
            $restantes = self::registerFailedLogin((int)$user['id'], $protegida);
            if ($restantes <= 0) {
                win_log_error('audit_account_locked', 'Usuario bloqueado por ' . self::MAX_LOGIN_FAILURES . ' intentos fallidos', ['target_id' => (int)$user['id'], 'protegida' => $protegida]);
                return ['status' => 'error', 'locked' => true, 'cuenta' => true, 'message' => $mensajeBloqueo];
            }
            return ['status' => 'error', 'cuenta' => true, 'message' => 'Usuario o contraseña incorrectos. ' . ($restantes === 1 ? 'Te queda 1 intento.' : "Te quedan {$restantes} intentos.")];
        }
        self::clearFailedLogins((int)$user['id']);
        // Último ingreso (2026-10-15), para la lista de usuarios.
        $pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id')->execute([':id' => (int)$user['id']]);

        // Crear Sesión segura únicamente después de completar la autenticación.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['sgs_ultimo_uso'] = time();
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

    /** Personal de WIN (administrativo o supervisor directo). Los módulos de gestión exigen esto además del permiso. */
    public static function esPersonalWin(?array $user = null): bool {
        $user ??= self::getLoggedInUser();
        return in_array($user['rol'] ?? '', [self::ROLE_ADMINISTRADOR, self::ROLE_GERENTE, self::ROLE_COORDINADOR], true)
            || (($user['rol'] ?? '') === self::ROLE_SUPERVISOR && !empty($user['is_win_directo']));
    }

    /** Permiso de un módulo de gestión: tener el permiso y ser personal de WIN. */
    public static function puede(string $permission, ?array $user = null): bool {
        $user ??= self::getLoggedInUser();
        return self::esPersonalWin($user) && self::can($permission, $user);
    }

    public static function isAdministrative(): bool {
        return self::hasRole([
            self::ROLE_ADMINISTRADOR,
            self::ROLE_GERENTE,
            self::ROLE_COORDINADOR,
        ]);
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
}
