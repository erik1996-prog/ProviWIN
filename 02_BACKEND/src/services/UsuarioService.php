<?php
// 02_BACKEND/src/services/UsuarioService.php - Reglas de negocio de usuarios, perfiles y permisos.
//
// Reglas:
//  - Nunca queda el sistema sin un administrador activo (no se puede desactivar ni bajar de rol al último).
//  - Un administrador no puede desactivarse a sí mismo.
//  - Personal directo WIN = partner WIN DIRECTO (o sin partner). El partner se elige solo para contratas.
//  - Perfil asignado: el rol del usuario pasa a ser el rol base del perfil y sus permisos se escriben en usuario_permisos.
//  - Sin perfil: el usuario usa los permisos por defecto de su rol, o permisos personalizados si el administrador los marca.

require_once BACKEND_PATH . '/validators/UsuarioValidator.php';
require_once BACKEND_PATH . '/repositories/UsuarioRepository.php';
require_once BACKEND_PATH . '/repositories/PerfilRepository.php';

final class UsuarioService
{
    private UsuarioRepository $usuarios;
    private PerfilRepository $perfiles;

    public function __construct(private PDO $pdo)
    {
        $this->usuarios = new UsuarioRepository($pdo);
        $this->perfiles = new PerfilRepository($pdo);
    }

    /** Permisos que el administrador puede delegar (los "reservados" quedan fijos por rol). */
    public static function permisosDelegables(): array
    {
        return array_keys(array_filter(Auth::permissionCatalog(), static fn($p) => !empty($p['delegable'])));
    }

    /** Permisos relevantes para SGS (se ocultan los de módulos que siguen en el sistema de origen). */
    public static function catalogoSgs(): array
    {
        $fuera = [Auth::PERM_RESOURCES_VIEW, Auth::PERM_EXAM_VIEW];
        $out = [];
        foreach (Auth::permissionCatalog() as $clave => $def) {
            if (empty($def['delegable']) || in_array($clave, $fuera, true)) continue;
            $out[] = ['clave' => $clave, 'grupo' => $def['group'], 'etiqueta' => $def['label']];
        }
        return $out;
    }

    public function catalogos(): array
    {
        $roles = [];
        $conteo = $this->usuarios->conteoPorRol();
        foreach (UsuarioValidator::ROLES as $rol) {
            $roles[] = ['rol' => $rol, 'permisos' => array_values(array_intersect(Auth::roleDefaultPermissions($rol), self::permisosDelegables())), 'usuarios' => $conteo[$rol] ?? 0];
        }
        $departamentos = sgsOperationalDepartments();
        return [
            'roles' => $roles,
            'perfiles' => $this->perfiles->listar(),
            'partners' => array_values(array_filter($this->usuarios->partners(), static fn($p) => !in_array(mb_strtoupper(trim($p['nombre_partner'])), ['WIN', 'WIN DIRECTO'], true))),
            'departamentos' => $departamentos,
            'permisos' => self::catalogoSgs(),
        ];
    }

    public function listar(array $filtros): array
    {
        $f = [
            'q' => mb_substr(trim((string)($filtros['q'] ?? '')), 0, 80),
            'rol' => in_array($filtros['rol'] ?? '', UsuarioValidator::ROLES, true) ? $filtros['rol'] : '',
            'estado' => in_array($filtros['estado'] ?? '', ['activos', 'inactivos'], true) ? $filtros['estado'] : '',
            'organizacion' => (string)($filtros['organizacion'] ?? ''),
        ];
        $lista = $this->usuarios->listar($f);
        $bloqueados = array_flip(Auth::lockedAccountIds(array_column($lista, 'id')));
        foreach ($lista as &$fila) {
            $fila['bloqueado'] = isset($bloqueados[(int)$fila['id']]);
            $fila['zonas'] = $this->zonasDe($fila);
            $fila['todas_zonas'] = $this->esTodasZonas($fila);
            unset($fila['sede'], $fila['zona']);
        }
        unset($fila);
        return ['usuarios' => $lista, 'resumen' => array_map('intval', $this->usuarios->resumen())];
    }

    public function detalle(int $id): array
    {
        $u = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        $overrides = $this->usuarios->permisosPersonalizados($id);
        $defaults = array_flip(Auth::roleDefaultPermissions((string)$u['rol']));
        $efectivos = [];
        foreach (self::permisosDelegables() as $p) if ($overrides[$p] ?? isset($defaults[$p])) $efectivos[] = $p;
        $esDirecto = empty($u['nombre_partner']) || in_array(mb_strtoupper(trim($u['nombre_partner'])), ['WIN', 'WIN DIRECTO'], true);
        return [
            'id' => (int)$u['id'], 'dni' => $u['dni'], 'nombres' => $u['nombres'], 'apellidos' => $u['apellidos'], 'usuario' => $u['usuario'],
            'email' => $u['email'], 'telefono_personal' => $u['telefono_personal'], 'telefono_corporativo' => $u['telefono_corporativo'],
            'rol' => $u['rol'], 'perfil_id' => (int)($u['perfil_id'] ?? 0), 'organizacion' => $esDirecto ? 'directo' : 'partner',
            'partner_id' => $esDirecto ? 0 : (int)$u['partner_id'],
            'area_trabajo' => $u['area_trabajo'], 'modalidad' => $u['modalidad'], 'turno' => $u['turno'], 'activo' => (int)$u['activo'],
            'zonas' => $this->zonasDe($u), 'todas_zonas' => $this->esTodasZonas($u),
            'permisos' => $efectivos, 'permisos_personalizados' => !empty($overrides),
        ];
    }

    public function guardar(array $in, int $id, int $actorId): array
    {
        $d = UsuarioValidator::usuario($in, $id === 0);
        $organizacion = ($in['organizacion'] ?? 'directo') === 'partner' ? 'partner' : 'directo';
        if ($organizacion === 'partner') {
            if ($d['partner_id'] < 1) throw new ValidationException('Seleccione el partner (contrata) del usuario.', 'partner_id');
        } else {
            $d['partner_id'] = (int)($this->usuarios->partnerWinDirectoId() ?? 0);
        }

        $perfil = null;
        if ($d['perfil_id'] > 0) {
            $perfil = $this->perfiles->obtener($d['perfil_id']);
            if (!$perfil || !$perfil['activo']) throw new ValidationException('El perfil seleccionado no existe o está inactivo.', 'perfil_id');
            $d['rol'] = $perfil['rol_base'];
        }
        $operativo = in_array($d['rol'], [Auth::ROLE_SUPERVISOR, Auth::ROLE_PARTNER], true);
        if ($operativo && !$d['todas_zonas'] && !$d['zonas']) throw new ValidationException('Marque al menos una zona de trabajo (o "Todas las zonas"): define qué puede ver el usuario.', 'zonas');
        // La primera zona es la base; se guarda como sede porque el sistema de origen la usa. Las demás, como accesos adicionales.
        $d['sede'] = $d['todas_zonas'] ? 'PROVINCIAS' : ($d['zonas'] ? sgsSiteFromDepartment($d['zonas'][0]) : '');
        $zonasAdicionales = $d['todas_zonas'] ? [] : array_slice($d['zonas'], 1);
        if ($organizacion === 'partner' && in_array($d['rol'], [Auth::ROLE_ADMINISTRADOR, Auth::ROLE_GERENTE, Auth::ROLE_COORDINADOR], true)) {
            throw new ValidationException('El personal de un partner solo puede tener rol Supervisor o Partner.', 'rol');
        }

        if ($id > 0) {
            $actual = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
            if ($actual['rol'] === Auth::ROLE_ADMINISTRADOR && $d['rol'] !== Auth::ROLE_ADMINISTRADOR && $actual['activo'] && $this->usuarios->administradoresActivos($id) === 0) {
                throw new ValidationException('Es el único administrador activo: asigne primero otro administrador antes de cambiarle el rol.', 'rol');
            }
        }

        if ($d['usuario'] === '') {
            $base = mb_strtolower(mb_substr(iconv('UTF-8', 'ASCII//TRANSLIT', $d['nombres']) ?: $d['nombres'], 0, 1) . preg_replace('/[^a-z]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $d['apellidos'])[0]) ?: '')));
            $d['usuario'] = $this->usuarios->usuarioDisponible($base !== '' ? $base : 'usuario');
        }
        if ($dup = $this->usuarios->existeDuplicado($d['dni'], $d['email'], $d['usuario'], $id)) {
            throw new ValidationException("Ya existe otro usuario con el mismo $dup.", $dup === 'correo' ? 'email' : ($dup === 'DNI' ? 'dni' : 'usuario'));
        }

        $this->pdo->beginTransaction();
        try {
            if ($id > 0) {
                $this->usuarios->actualizar($id, $d);
                if ($d['password'] !== '') $this->usuarios->cambiarPassword($id, password_hash($d['password'], PASSWORD_DEFAULT));
            } else {
                $id = $this->usuarios->crear($d, password_hash($d['password'], PASSWORD_DEFAULT));
            }
            $delegables = self::permisosDelegables();
            if ($perfil) {
                $this->usuarios->asignarPerfil($id, (int)$perfil['id'], $actorId);
                $this->usuarios->guardarPermisos($id, array_intersect($perfil['permisos'], $delegables), $delegables, $actorId);
            } else {
                $this->usuarios->quitarPerfil($id);
                if ($d['permisos_personalizados']) $this->usuarios->guardarPermisos($id, array_intersect($d['permisos'], $delegables), $delegables, $actorId);
                else $this->usuarios->limpiarPermisos($id);
            }
            $this->usuarios->guardarZonas($id, $operativo ? $zonasAdicionales : [], $actorId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'usuario' => $d['usuario']];
    }

    public function cambiarEstado(int $id, bool $activo, int $actorId): void
    {
        $u = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        if (!$activo && $id === $actorId) throw new ValidationException('No puede desactivar su propia cuenta.');
        if (!$activo && $u['rol'] === Auth::ROLE_ADMINISTRADOR && $this->usuarios->administradoresActivos($id) === 0) {
            throw new ValidationException('Es el único administrador activo: el sistema quedaría sin administrador.');
        }
        $this->usuarios->cambiarEstado($id, $activo);
    }

    /** Restablece la contraseña. Si no se envía una, genera una temporal segura y la devuelve una sola vez. */
    public function restablecerPassword(int $id, string $nueva = ''): string
    {
        $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        if ($nueva === '') $nueva = self::passwordTemporal();
        UsuarioValidator::password($nueva);
        $this->usuarios->cambiarPassword($id, password_hash($nueva, PASSWORD_DEFAULT));
        Auth::unlockAccount($id); // una contraseña nueva también libera el bloqueo por intentos
        return $nueva;
    }

    public function cambiarPasswordPropia(int $id, string $actual, string $nueva): void
    {
        $hash = $this->usuarios->passwordHash($id);
        if ($hash === null || !password_verify($actual, $hash)) throw new ValidationException('La contraseña actual no es correcta.', 'actual');
        if ($actual === $nueva) throw new ValidationException('La nueva contraseña debe ser distinta de la actual.', 'nueva');
        UsuarioValidator::password($nueva);
        $this->usuarios->cambiarPassword($id, password_hash($nueva, PASSWORD_DEFAULT));
    }

    /**
     * Foto de perfil propia. Se guarda en la carpeta compartida con el sistema de origen
     * (uploads/avatars, columna usuarios.avatar) para que ambos muestren la misma foto.
     */
    public function cambiarFotoPropia(int $id, array $file): string
    {
        require_once BACKEND_PATH . '/config/uploads.php';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new ValidationException('Seleccione una imagen.', 'foto');
        try {
            $ok = validateUploadedFile($file, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 3 * 1024 * 1024);
        } catch (RuntimeException $e) {
            throw new ValidationException('La foto debe ser JPG, PNG o WEBP de hasta 3 MB.', 'foto');
        }
        $dim = @getimagesize($file['tmp_name']);
        if (!$dim || $dim[0] < 32 || $dim[1] < 32 || $dim[0] > 6000 || $dim[1] > 6000) throw new ValidationException('La imagen no es válida.', 'foto');
        $dir = SHARED_FILES_PATH . '/uploads/avatars/';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException('No existe la carpeta de fotos.');
        $name = randomUploadName('avatar_user_' . $id . '_', $ok['extension']);
        if (!move_uploaded_file($file['tmp_name'], $dir . $name)) throw new RuntimeException('No se pudo guardar la foto.');
        $anterior = $this->usuarios->avatar($id);
        $this->usuarios->cambiarAvatar($id, 'uploads/avatars/' . $name);
        $this->borrarFotoAnterior($id, $anterior);
        return 'uploads/avatars/' . $name;
    }

    public function quitarFotoPropia(int $id): void
    {
        $anterior = $this->usuarios->avatar($id);
        $this->usuarios->cambiarAvatar($id, null);
        $this->borrarFotoAnterior($id, $anterior);
    }

    /** Solo borra archivos propios del usuario dentro de uploads/avatars (nunca otra ruta). */
    private function borrarFotoAnterior(int $id, ?string $ruta): void
    {
        if (!$ruta || !preg_match('#^uploads/avatars/(avatar_user_' . $id . '_[A-Za-z0-9]+\.(?:jpe?g|png|webp))$#', $ruta, $m)) return;
        $path = SHARED_FILES_PATH . '/uploads/avatars/' . $m[1];
        if (is_file($path)) @unlink($path);
    }

    /** Zonas de trabajo efectivas: departamento de la sede + accesos adicionales (sin repetir). */
    private function zonasDe(array $u): array
    {
        if ($this->esTodasZonas($u)) return [];
        $base = mb_strtoupper(operationalDepartmentFromSite((string)(($u['sede'] ?? '') ?: ($u['zona'] ?? ''))), 'UTF-8');
        $zonas = $base !== '' && $base !== 'PROVINCIAS' ? [$base] : [];
        foreach ($this->usuarios->zonasAdicionales((int)$u['id']) as $z) if (!in_array($z, $zonas, true)) $zonas[] = $z;
        return $zonas;
    }

    private function esTodasZonas(array $u): bool
    {
        return operationalDepartmentFromSite((string)(($u['sede'] ?? '') ?: ($u['zona'] ?? ''))) === 'PROVINCIAS';
    }

    public static function passwordTemporal(): string
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz'; $n = '23456789'; $s = '#$%&*?';
        $p = '';
        for ($i = 0; $i < 9; $i++) $p .= $a[random_int(0, strlen($a) - 1)];
        for ($i = 0; $i < 3; $i++) $p .= $n[random_int(0, strlen($n) - 1)];
        return $p . $s[random_int(0, strlen($s) - 1)];
    }

    // ---------------- Perfiles ----------------

    public function guardarPerfil(array $in, int $id, int $actorId): int
    {
        $d = UsuarioValidator::perfil($in);
        $d['permisos'] = array_values(array_intersect($d['permisos'], self::permisosDelegables()));
        if ($this->perfiles->nombreEnUso($d['nombre'], $id)) throw new ValidationException('Ya existe un perfil con ese nombre.', 'nombre');
        $this->pdo->beginTransaction();
        try {
            $id = $this->perfiles->guardar($id, $d, $actorId);
            // Los usuarios que ya tienen el perfil reciben los cambios al instante.
            $delegables = self::permisosDelegables();
            foreach ($this->usuarios->usuariosConPerfil($id) as $userId) {
                $u = $this->usuarios->obtener($userId);
                if ($u && $u['rol'] !== $d['rol_base']) {
                    if ($u['rol'] === Auth::ROLE_ADMINISTRADOR && $u['activo'] && $this->usuarios->administradoresActivos($userId) === 0) {
                        throw new ValidationException('El perfil lo usa el único administrador activo; no se puede cambiar su rol base.', 'rol_base');
                    }
                    $this->pdo->prepare('UPDATE usuarios SET rol = :r WHERE id = :id')->execute([':r' => $d['rol_base'], ':id' => $userId]);
                }
                $this->usuarios->guardarPermisos($userId, $d['permisos'], $delegables, $actorId);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function cambiarEstadoPerfil(int $id, bool $activo): void
    {
        $p = $this->perfiles->obtener($id) ?? throw new ValidationException('Perfil no encontrado.');
        if (!$activo && $this->usuarios->usuariosConPerfil($id)) {
            throw new ValidationException('Hay usuarios con este perfil. Cámbieles el perfil antes de desactivarlo.');
        }
        $this->perfiles->cambiarEstado((int)$p['id'], $activo);
    }
}
