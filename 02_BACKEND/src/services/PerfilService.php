<?php
// 02_BACKEND/src/services/PerfilService.php - Perfiles de acceso (2026-10-31, pedido del usuario, al estilo del sistema de
// referencia). Cada perfil tiene:
//  - Nombre, código (opcional: si falta, las iniciales del nombre) y descripción.
//  - "Perfil administrador" y "¿Qué datos puede ver?" (2026-11-01, como el sistema de referencia): Todo el país, Sus zonas a
//    cargo (sus zonas y su área: sgsAlcanceLimitado), Su partner o Solo lo suyo. De esas dos respuestas sale el tipo base
//    (usuarios.rol, que fija lo del sistema: quién registra turno, qué ve un partner…): ver DATOS_A_TIPO. Si el perfil ya
//    tiene usuarios, el tipo de ellos cambia con el perfil (validarCambioDeTipo: la cuenta principal sigue siendo
//    administrador y el personal de un partner solo puede ser "Su partner" o "Solo lo suyo").
//  - Un perfil administrador tiene acceso total a las pantallas y acciones aunque no estén marcadas (Auth::can), salvo lo
//    de campo (registrar supervisiones y el turno propio).
//  - Perfiles que puede asignar a su equipo (UsuarioService::equipo).
//  - Permisos por pantalla (Auth::can): todos se dan por perfil (2026-11-01: ya no hay reservados).
// Solo la cuenta principal (administrador general) crea, edita y elimina perfiles.

require_once BACKEND_PATH . '/validators/UsuarioValidator.php';
require_once BACKEND_PATH . '/repositories/PerfilRepository.php';

final class PerfilService
{
    /** "¿Qué datos puede ver?" → [tipo base (usuarios.rol), alcance guardado]. Un perfil administrador es tipo Administrador. */
    public const DATOS_A_TIPO = [
        'todo' => [Auth::ROLE_GERENTE, 'todo'], 'zonas' => [Auth::ROLE_COORDINADOR, 'zonas'],
        'partner' => [Auth::ROLE_JEFE_OPERACIONES, 'todo'], 'propio' => [Auth::ROLE_SUPERVISOR, 'propio'],
    ];

    private PerfilRepository $perfiles;

    public function __construct(private PDO $pdo)
    {
        $this->perfiles = new PerfilRepository($pdo);
    }

    public function repositorio(): PerfilRepository
    {
        return $this->perfiles;
    }

    public function listar(): array
    {
        return $this->perfiles->listar();
    }

    /** Crea o edita un perfil. Al crearlo, empieza con los permisos por defecto de su tipo base. */
    public function guardar(array $in, int $id): array
    {
        $datos = (string)($in['datos'] ?? '');
        $esAdmin = !empty($in['es_admin']) && $in['es_admin'] !== 'false';
        if (!$esAdmin && !isset(self::DATOS_A_TIPO[$datos])) throw new ValidationException('Elija qué datos puede ver.', 'datos');
        [$rol, $alcance] = $esAdmin ? [Auth::ROLE_ADMINISTRADOR, 'todo'] : self::DATOS_A_TIPO[$datos];
        $d = [
            'nombre' => trim(preg_replace('/\s+/u', ' ', (string)($in['nombre'] ?? ''))),
            'codigo' => mb_strtoupper(trim((string)($in['codigo'] ?? '')), 'UTF-8'),
            'descripcion' => mb_substr(trim((string)($in['descripcion'] ?? '')), 0, 255),
            'rol_base' => $rol,
            'alcance' => $alcance,
        ];
        if (mb_strlen($d['nombre']) < 3 || mb_strlen($d['nombre']) > 60) throw new ValidationException('Escriba el nombre del perfil (de 3 a 60 caracteres).', 'nombre');
        if ($d['codigo'] === '') $d['codigo'] = $this->codigoLibre($d['nombre'], $id);
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $d['codigo'])) throw new ValidationException('El código lleva de 2 a 10 letras o números, sin espacios (p. ej. JTEC).', 'codigo');
        if ($dup = $this->perfiles->duplicado($d['nombre'], $d['codigo'], $id)) throw new ValidationException("Ya existe un perfil con ese {$dup}.", $dup === 'nombre' ? 'nombre' : 'codigo');
        $previo = $id > 0 ? ($this->perfiles->obtener($id) ?? throw new ValidationException('El perfil ya no existe.')) : null;
        // Cambiar el tipo de un perfil con usuarios (2026-11-01): sus usuarios cambian con él, salvo que choque con su organización
        $cambiaTipo = $previo && $previo['rol_base'] !== $d['rol_base'] && (int)$previo['usuarios'] > 0;
        if ($cambiaTipo) $this->validarCambioDeTipo($id, $d['rol_base']);
        $asignables = $this->perfiles->existentes((array)($in['asignables'] ?? []));
        $this->pdo->beginTransaction();
        try {
            if ($previo) {
                $this->perfiles->actualizar($id, $d);
                if ($cambiaTipo) $this->perfiles->cambiarTipoDeUsuarios($id, $d['rol_base']);
            } else {
                $id = $this->perfiles->crear($d);
                $this->perfiles->guardarPermisos($id, Auth::roleDefaultPermissions($d['rol_base']));
            }
            $this->perfiles->guardarAsignables($id, $asignables);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'nombre' => $d['nombre'], 'nuevo' => $previo === null];
    }

    /** Las mismas reglas que al elegir el perfil de un usuario (UsuarioService::guardar), para todos los del perfil. */
    private function validarCambioDeTipo(int $id, string $rol): void
    {
        $principal = Auth::cuentaPrincipalId();
        if ($rol !== Auth::ROLE_ADMINISTRADOR && $principal && (int)$this->pdo->query('SELECT COUNT(*) FROM usuarios WHERE id = ' . (int)$principal . ' AND perfil_id = ' . $id)->fetchColumn()) {
            throw new ValidationException('Este perfil es el de la cuenta principal: debe seguir siendo administrador.', 'es_admin');
        }
        $org = $this->perfiles->usuariosPorOrganizacion($id);
        if ($org['partner'] && !in_array($rol, [Auth::ROLE_SUPERVISOR, Auth::ROLE_JEFE_OPERACIONES], true)) {
            throw new ValidationException("El perfil tiene {$org['partner']} usuario(s) de un partner: solo puede ver \"Su partner\" o \"Solo lo suyo\". Páselos a otro perfil primero.", 'datos');
        }
        if ($org['directo'] && $rol === Auth::ROLE_JEFE_OPERACIONES) {
            throw new ValidationException("El perfil tiene {$org['directo']} usuario(s) de WIN Directo: \"Su partner\" es solo para personal de un partner.", 'datos');
        }
    }

    /** Código con las iniciales del nombre ("Jefe técnico" → JT); si ya existe, con un número (JT2). */
    private function codigoLibre(string $nombre, int $id): string
    {
        $ascii = strtoupper((string)iconv('UTF-8', 'ASCII//TRANSLIT', $nombre));
        preg_match_all('/[A-Z0-9]+/', $ascii, $m);
        $base = substr(implode('', array_map(static fn($p) => $p[0], $m[0])), 0, 8);
        if (strlen($base) < 2) $base = substr(implode('', $m[0]) . 'PF', 0, 8);
        for ($codigo = $base, $n = 2; $this->perfiles->duplicado('', $codigo, $id) !== null; $n++) $codigo = $base . $n;
        return $codigo;
    }

    /**
     * Permisos del perfil: solo los del catálogo, y sin "Ver" de una pantalla no queda nada de ella. El perfil de la cuenta
     * principal no pierde "Ver" ni "Administrar a todos" en Usuarios (si no, nadie podría volver a dárselos).
     */
    public function guardarPermisos(int $id, array $permisos, int $principalId): void
    {
        $perfil = $this->perfiles->obtener($id) ?? throw new ValidationException('El perfil ya no existe.');
        $permisos = Auth::conDependencias(array_values(array_unique(array_intersect(array_map('strval', $permisos), array_keys(Auth::permissionCatalog())))));
        $st = $this->pdo->prepare('SELECT perfil_id FROM usuarios WHERE id = :id');
        $st->execute([':id' => $principalId]);
        if ((int)$st->fetchColumn() === (int)$perfil['id'] && array_diff([Auth::PERM_USERS_VIEW, Auth::PERM_USERS_MANAGE], $permisos)) {
            throw new ValidationException('Es su propio perfil: no puede quitarle "Ver" ni "Administrar a todos" en Usuarios y perfiles.');
        }
        $this->perfiles->guardarPermisos($id, $permisos);
    }

    /** Solo un perfil sin usuarios, y nunca el último de su tipo base (los usuarios sin perfil usan ese). */
    public function eliminar(int $id): string
    {
        $perfil = $this->perfiles->obtener($id) ?? throw new ValidationException('El perfil ya no existe.');
        if ((int)$perfil['usuarios'] > 0) throw new ValidationException("No se puede eliminar: tiene {$perfil['usuarios']} usuario(s). Páselos a otro perfil primero.");
        if ($this->perfiles->cuantosDeRol($perfil['rol_base']) < 2) throw new ValidationException('Es el único perfil de su tipo base: no se puede eliminar.');
        $this->perfiles->eliminar($id);
        return $perfil['nombre'];
    }
}
