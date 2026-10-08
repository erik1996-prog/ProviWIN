<?php
// 02_BACKEND/src/controllers/UsuarioController.php - Traduce peticiones HTTP a llamadas del servicio y responde JSON.
// Flujo (guía, sección 8): Route (api/usuarios.php) -> Controller -> Service -> Repository -> BD

require_once BACKEND_PATH . '/services/UsuarioService.php';

final class UsuarioController
{
    /** Acciones que requieren administrar usuarios (PERM_USERS_MANAGE). */
    private const ADMIN = ['catalogos', 'listar', 'detalle', 'guardar', 'estado', 'restablecer_password', 'desbloquear', 'guardar_perfil', 'estado_perfil'];
    /** Acciones disponibles para cualquier usuario con sesión. */
    private const PROPIAS = ['cambiar_password_propia', 'mi_foto', 'quitar_mi_foto'];
    private const ESCRITURA = ['guardar', 'estado', 'restablecer_password', 'desbloquear', 'guardar_perfil', 'estado_perfil', 'cambiar_password_propia', 'mi_foto', 'quitar_mi_foto'];

    public function __construct(private UsuarioService $service, private array $user) {}

    public function handle(string $action): array
    {
        if (!in_array($action, array_merge(self::ADMIN, self::PROPIAS), true)) return $this->error(404, 'Acción no válida.');
        if (in_array($action, self::ESCRITURA, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return $this->error(405, 'Use POST para esta acción.');
        if (in_array($action, self::ADMIN, true) && !Auth::can(Auth::PERM_USERS_MANAGE, $this->user)) return $this->error(403, 'Solo el administrador puede gestionar usuarios y roles.');

        $in = $this->input();
        $actor = (int)$this->user['id'];
        try {
            switch ($action) {
                case 'catalogos':
                    return ['status' => 'success', 'data' => $this->service->catalogos()];
                case 'listar':
                    return ['status' => 'success'] + $this->service->listar($_GET);
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
                    $temporal = $this->service->restablecerPassword((int)($in['id'] ?? 0), (string)($in['password'] ?? ''));
                    win_log_error('audit_password_reset', 'Contraseña restablecida por el administrador', ['target_id' => (int)($in['id'] ?? 0), 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Contraseña restablecida.', 'password' => empty($in['password']) ? $temporal : null];
                case 'desbloquear':
                    Auth::unlockAccount((int)($in['id'] ?? 0));
                    win_log_error('audit_account_unlocked', 'Usuario desbloqueado por el administrador', ['target_id' => (int)($in['id'] ?? 0), 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Usuario desbloqueado. Ya puede volver a ingresar.'];
                case 'guardar_perfil':
                    $pid = $this->service->guardarPerfil($in, (int)($in['id'] ?? 0), $actor);
                    win_log_error('audit_profile_saved', 'Perfil de acceso guardado: ' . ($in['nombre'] ?? ''), ['perfil_id' => $pid, 'actor_id' => $actor]);
                    return ['status' => 'success', 'message' => 'Perfil guardado.', 'data' => ['id' => $pid]];
                case 'estado_perfil':
                    $this->service->cambiarEstadoPerfil((int)($in['id'] ?? 0), !empty($in['activo']));
                    return ['status' => 'success', 'message' => !empty($in['activo']) ? 'Perfil activado.' : 'Perfil desactivado.'];
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
