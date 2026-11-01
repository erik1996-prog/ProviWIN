<?php
// 02_BACKEND/src/services/PerfilService.php - Perfiles de acceso (2026-10-31, pedido del usuario, al estilo del sistema de
// referencia). Cada perfil tiene:
//  - Nombre, código y descripción.
//  - Tipo base: uno de los 5 roles (usuarios.rol). Define lo que está fijo en el sistema: quién registra turno, qué ve un
//    partner, etc. No cambia si el perfil ya tiene usuarios (se crea otro perfil).
//  - Qué datos ve (alcance): 'todo' (todo el país), 'zonas' (sus zonas y su área: sgsAlcanceLimitado) o 'propio' (solo lo
//    suyo). El supervisor siempre ve solo lo suyo y el partner, lo de su partner (lo fija su tipo base).
//  - Perfiles que puede asignar a su equipo (UsuarioService::equipo).
//  - Permisos por pantalla (Auth::can). Los reservados (administrar usuarios, importar, eliminar supervisiones, sistema)
//    siguen el tipo base.
// Solo la cuenta principal (administrador general) crea, edita y elimina perfiles.

require_once BACKEND_PATH . '/validators/UsuarioValidator.php';
require_once BACKEND_PATH . '/repositories/PerfilRepository.php';

final class PerfilService
{
    /** Alcances que admite cada tipo base (el primero es el que se pone por defecto). */
    public const ALCANCES_POR_ROL = [
        'Administrador' => ['todo', 'zonas'], 'Gerente' => ['todo', 'zonas'], 'Coordinador' => ['zonas', 'todo'],
        'Jefe de operaciones' => ['todo'], 'Supervisor' => ['propio'],
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
        return array_map(static fn($p) => $p + ['etiqueta_rol' => Auth::etiquetaRol($p['rol_base'])], $this->perfiles->listar());
    }

    /** Crea o edita un perfil. Al crearlo, empieza con los permisos por defecto de su tipo base. */
    public function guardar(array $in, int $id): array
    {
        $d = [
            'nombre' => trim(preg_replace('/\s+/u', ' ', (string)($in['nombre'] ?? ''))),
            'codigo' => mb_strtoupper(trim((string)($in['codigo'] ?? '')), 'UTF-8'),
            'descripcion' => mb_substr(trim((string)($in['descripcion'] ?? '')), 0, 255),
            'rol_base' => (string)($in['rol_base'] ?? ''),
            'alcance' => (string)($in['alcance'] ?? ''),
        ];
        if (mb_strlen($d['nombre']) < 3 || mb_strlen($d['nombre']) > 60) throw new ValidationException('Escriba el nombre del perfil (de 3 a 60 caracteres).', 'nombre');
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $d['codigo'])) throw new ValidationException('El código lleva de 2 a 10 letras o números, sin espacios (p. ej. JTEC).', 'codigo');
        if (!isset(self::ALCANCES_POR_ROL[$d['rol_base']])) throw new ValidationException('Elija el tipo base del perfil.', 'rol_base');
        if (!in_array($d['alcance'], self::ALCANCES_POR_ROL[$d['rol_base']], true)) $d['alcance'] = self::ALCANCES_POR_ROL[$d['rol_base']][0];
        if ($dup = $this->perfiles->duplicado($d['nombre'], $d['codigo'], $id)) throw new ValidationException("Ya existe un perfil con ese {$dup}.", $dup === 'nombre' ? 'nombre' : 'codigo');
        $previo = $id > 0 ? ($this->perfiles->obtener($id) ?? throw new ValidationException('El perfil ya no existe.')) : null;
        if ($previo && $previo['rol_base'] !== $d['rol_base'] && (int)$previo['usuarios'] > 0) {
            throw new ValidationException("El tipo base no se cambia porque el perfil tiene {$previo['usuarios']} usuario(s): cree otro perfil.", 'rol_base');
        }
        $asignables = $this->perfiles->existentes((array)($in['asignables'] ?? []));
        $this->pdo->beginTransaction();
        try {
            if ($previo) {
                $this->perfiles->actualizar($id, $d);
            } else {
                $id = $this->perfiles->crear($d);
                $this->perfiles->guardarPermisos($id, array_values(array_intersect(Auth::roleDefaultPermissions($d['rol_base']), UsuarioService::permisosDelegables())));
            }
            $this->perfiles->guardarAsignables($id, $asignables);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'nombre' => $d['nombre'], 'nuevo' => $previo === null];
    }

    /**
     * Permisos del perfil: solo los delegables, y sin "Ver" de una pantalla no queda nada de ella. El perfil de la cuenta
     * principal no pierde "Ver usuarios" (si no, nadie podría volver a dárselo).
     */
    public function guardarPermisos(int $id, array $permisos, int $principalId): void
    {
        $perfil = $this->perfiles->obtener($id) ?? throw new ValidationException('El perfil ya no existe.');
        $permisos = Auth::conDependencias(array_values(array_unique(array_intersect(array_map('strval', $permisos), UsuarioService::permisosDelegables()))));
        $st = $this->pdo->prepare('SELECT perfil_id FROM usuarios WHERE id = :id');
        $st->execute([':id' => $principalId]);
        if ((int)$st->fetchColumn() === (int)$perfil['id'] && !in_array(Auth::PERM_USERS_VIEW, $permisos, true)) {
            throw new ValidationException('Es su propio perfil: no puede quitarle "Ver" en Usuarios y perfiles.');
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
