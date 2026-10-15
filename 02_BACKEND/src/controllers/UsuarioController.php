<?php
// 02_BACKEND/src/controllers/UsuarioController.php - Traduce peticiones HTTP a llamadas del servicio y responde JSON.
// Flujo (guía, sección 8): Route (api/usuarios.php) -> Controller -> Service -> Repository -> BD

require_once BACKEND_PATH . '/services/UsuarioService.php';

final class UsuarioController
{
    /** Acciones que requieren administrar usuarios (PERM_USERS_MANAGE). */
    private const ADMIN = ['catalogos', 'detalle', 'guardar', 'estado', 'restablecer_password', 'desbloquear'];
    /** Ver la lista de personal: PERM_USERS_VIEW, PERM_STAFF_MANAGE o PERM_USERS_MANAGE (cada uno con su alcance). */
    private const CONSULTA = ['listar', 'ficha', 'zonas_propias'];
    /** Agregar y editar solo datos personales: PERM_STAFF_MANAGE (o PERM_USERS_MANAGE). */
    private const PERSONAL = ['guardar_personal'];
    /** Acciones disponibles para cualquier usuario con sesión. */
    private const PROPIAS = ['cambiar_password_propia', 'mi_foto', 'quitar_mi_foto'];
    private const ESCRITURA = ['guardar', 'guardar_personal', 'estado', 'restablecer_password', 'desbloquear', 'cambiar_password_propia', 'mi_foto', 'quitar_mi_foto'];

    public function __construct(private UsuarioService $service, private array $user) {}

    public function handle(string $action): array
    {
        if (!in_array($action, array_merge(self::ADMIN, self::CONSULTA, self::PERSONAL, self::PROPIAS), true)) return $this->error(404, 'Acción no válida.');
        $admin = Auth::can(Auth::PERM_USERS_MANAGE, $this->user);
        $gestionaPersonal = $admin || Auth::can(Auth::PERM_STAFF_MANAGE, $this->user);
        if (in_array($action, self::CONSULTA, true) && !$gestionaPersonal && !Auth::can(Auth::PERM_USERS_VIEW, $this->user)) return $this->error(403, 'No tiene permiso para ver usuarios.');
        if (in_array($action, self::PERSONAL, true) && !$gestionaPersonal) return $this->error(403, 'No tiene permiso para agregar o editar personal.');
        if (in_array($action, self::ESCRITURA, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return $this->error(405, 'Use POST para esta acción.');
        if (in_array($action, self::ADMIN, true) && !Auth::can(Auth::PERM_USERS_MANAGE, $this->user)) return $this->error(403, 'Solo el administrador puede gestionar usuarios y roles.');

        $in = $this->input();
        $actor = (int)$this->user['id'];
        try {
            switch ($action) {
                case 'catalogos':
                    return ['status' => 'success', 'data' => $this->service->catalogos()];
                case 'listar':
                    return ['status' => 'success'] + $this->service->listar($_GET, $this->user);
                case 'ficha':
                    return ['status' => 'success', 'data' => $this->service->detallePersonal((int)($_GET['id'] ?? 0), $this->user)];
                case 'zonas_propias':
                    // Zonas donde puede registrar personal: las suyas (partner) o todas (directo WIN).
                    $alcance = $this->service->alcance($this->user);
                    return ['status' => 'success', 'data' => ['zonas' => $alcance === null ? sgsOperationalDepartments() : ($alcance['zonas'] ?? sgsOperationalDepartments()),
                        'es_partner' => $alcance !== null, 'partners' => $alcance === null ? $this->service->partnersOperativos() : [],
                        'areas' => UsuarioValidator::AREAS, 'cargos' => UsuarioValidator::CARGOS]];
                case 'guardar_personal':
                    $id = (int)($in['id'] ?? 0);
                    $r = $this->service->guardarPersonal($in, $id, $this->user);
                    win_log_error('audit_staff_saved', ($r['nuevo'] ? 'Personal registrado (sin acceso): ' : 'Datos personales actualizados: ') . $r['nombre'], ['target_id' => $r['id'], 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => $r['nuevo'] ? "{$r['nombre']} quedó registrado. El administrador le dará acceso al sistema." : 'Datos actualizados.', 'data' => $r];
                case 'detalle':
                    return ['status' => 'success', 'data' => $this->service->detalle((int)($_GET['id'] ?? 0))];
                case 'guardar':
                    $id = (int)($in['id'] ?? 0);
                    $r = $this->service->guardar($in, $id, $actor);
                    win_log_error('audit_user_saved', ($id ? 'Usuario actualizado' : 'Usuario creado') . ": {$r['usuario']}", ['target_id' => $r['id'], 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => $id ? 'Usuario actualizado.' : "Usuario creado: {$r['usuario']}", 'data' => $r];
                case 'estado':
                    $activo = !empty($in['activo']);
                    $this->service->cambiarEstado((int)($in['id'] ?? 0), $activo, $actor);
                    win_log_error('audit_user_status', $activo ? 'Usuario activado' : 'Usuario desactivado', ['target_id' => (int)($in['id'] ?? 0), 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => $activo ? 'Usuario activado.' : 'Usuario desactivado.'];
                case 'restablecer_password':
                    $temporal = $this->service->restablecerPassword((int)($in['id'] ?? 0), (string)($in['password'] ?? ''), !array_key_exists('pedir_cambio', $in) || !empty($in['pedir_cambio']));
                    win_log_error('audit_password_reset', 'Contraseña restablecida por el administrador', ['target_id' => (int)($in['id'] ?? 0), 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Contraseña restablecida.', 'password' => empty($in['password']) ? $temporal : null];
                case 'desbloquear':
                    Auth::unlockAccount((int)($in['id'] ?? 0));
                    win_log_error('audit_account_unlocked', 'Usuario desbloqueado por el administrador', ['target_id' => (int)($in['id'] ?? 0), 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Usuario desbloqueado. Ya puede volver a ingresar.'];
                case 'cambiar_password_propia':
                    $this->service->cambiarPasswordPropia($actor, (string)($in['actual'] ?? ''), (string)($in['nueva'] ?? ''));
                    win_log_error('audit_password_changed', 'El usuario cambió su contraseña', ['actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Tu contraseña se actualizó.'];
                case 'mi_foto':
                    $url = $this->service->cambiarFotoPropia($actor, $_FILES['foto'] ?? []);
                    $_SESSION['user_avatar'] = $url;
                    win_log_error('audit_avatar_changed', 'El usuario cambió su foto de perfil', ['actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Tu foto de perfil se actualizó.', 'avatar_url' => $url];
                case 'quitar_mi_foto':
                    $this->service->quitarFotoPropia($actor);
                    $_SESSION['user_avatar'] = 'assets/img/default-avatar.png';
                    return ['status' => 'success', 'message' => 'Se quitó tu foto de perfil.'];
            }
        } catch (ValidationException $e) {
            return $this->error(422, $e->getMessage(), $e->field);
        }
        return $this->error(404, 'Acción no válida.');
    }

    private function input(): array
    {
        $raw = (string)file_get_contents('php://input');
        $json = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($json) ? $json : $_POST;
    }

    private function error(int $code, string $message, ?string $field = null): array
    {
        http_response_code($code);
        return array_filter(['status' => 'error', 'message' => $message, 'field' => $field], static fn($v) => $v !== null);
    }
}
