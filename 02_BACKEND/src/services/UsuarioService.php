<?php
// 02_BACKEND/src/services/UsuarioService.php - Reglas de negocio de usuarios y permisos.
//
// Reglas:
//  - Nunca queda el sistema sin un administrador activo (no se puede desactivar ni bajar de rol al último).
//  - Un administrador no puede desactivarse a sí mismo.
//  - Personal directo WIN = partner WIN DIRECTO (o sin partner). El partner se elige solo para contratas.
//  - Permisos: los del rol por defecto, o personalizados si el administrador los marca (usuario_permisos).
//    (2026-10-12: se quitaron los "perfiles de acceso"; no se usaban.)
//  - Quién ve a quién (2026-10-12): el administrador y el personal directo WIN ven a todos; el personal de un partner,
//    solo a los de su partner y dentro de sus zonas habilitadas.
//  - Equipo del líder (2026-10-27, permiso "Registrar… a sus supervisores", que el rol Gestión trae por defecto, sin
//    "Administrar usuarios"): ve, registra, edita y da de baja solo a su gente dentro de sus zonas y de su área: Supervisores
//    (directos o de partner), el jefe de operaciones de un partner (rol Partner) y, sin acceso, Asistente, Asesor, Almacenero
//    y Analista. Asigna solo los cargos de debajo, sus zonas, su área y sus propios permisos. No se modifica a sí mismo. Lo que registra entra de inmediato.
//    Un líder (Coordinador) con zonas o área propias que solo consulta, también ve solo a su equipo.
//  - "Agregar y editar personal": solo datos personales. El trabajador nuevo queda "Sin acceso" (sin usuario e inactivo)
//    hasta que el administrador le asigne usuario, contraseña, rol y zonas.

require_once BACKEND_PATH . '/validators/UsuarioValidator.php';
require_once BACKEND_PATH . '/repositories/UsuarioRepository.php';

final class UsuarioService
{
    private UsuarioRepository $usuarios;

    public function __construct(private PDO $pdo)
    {
        $this->usuarios = new UsuarioRepository($pdo);
    }

    /** Para qué sirve cada rol del sistema (se muestra en "Roles y permisos"). El cargo y el área no dan permisos. */
    public const DESCRIPCION_ROLES = [
        'Administrador' => 'Dueño del sistema: ve y configura todo (usuarios, permisos, cargas, eliminaciones).',
        'Gerente' => 'Consulta: vista general: indicadores, supervisiones y observaciones de todos. No opera ni valida.',
        'Coordinador' => 'Gestión (líder técnico): revisa y valida observaciones, monitorea turnos y línea de tiempo. Con zonas o área marcadas, ve solo eso; sin ellas, ve todo.',
        'Supervisor' => 'Trabaja en campo. Directo WIN: registra supervisiones y su turno. De un partner: atiende las órdenes asignadas y responde observaciones.',
        'Jefe de operaciones' => 'Partner (jefe de operaciones de la contrata): consulta las órdenes, observaciones y personal de su partner.',
    ];

    /** Roles que asigna un líder a su gente (2026-10-27): Supervisor (directo o de partner) y Partner (jefe de operaciones). */
    public const ROLES_DEL_LIDER = [Auth::ROLE_SUPERVISOR, Auth::ROLE_JEFE_OPERACIONES];

    /** Permisos que el administrador puede delegar (los "reservados" quedan fijos por rol). */
    public static function permisosDelegables(): array
    {
        return array_keys(array_filter(Auth::permissionCatalog(), static fn($p) => !empty($p['delegable'])));
    }

    /** Permisos que el administrador puede marcar por usuario (los reservados no se muestran). */
    /** Sección del menú de cada pantalla (2026-10-26): el editor de permisos y la matriz las ordenan igual que el menú. */
    public const SECCION_PANTALLA = [
        'Dashboard de supervisiones' => 'Indicadores', 'Dashboard de partners' => 'Indicadores', 'Pendientes y rescates' => 'Indicadores', 'Matriz de asistencia' => 'Indicadores',
        'Clima' => 'Indicadores', 'Exámenes' => 'Indicadores', 'Supervisiones' => 'Supervisiones', 'Línea de tiempo' => 'Seguimiento',
        'Mi turno' => 'Seguimiento', 'Observaciones' => 'Observaciones', 'Usuarios y roles' => 'Personal', 'Técnicos y cuadrillas' => 'Personal',
        'Partners' => 'Personal',
    ];

    public static function catalogoSgs(): array
    {
        $out = [];
        foreach (Auth::permissionCatalog() as $clave => $def) {
            if (empty($def['delegable'])) continue;
            $out[] = ['clave' => $clave, 'grupo' => $def['group'], 'etiqueta' => $def['label'], 'accion' => $def['action'] ?? null,
                'requiere' => Auth::permisoRequerido($clave), 'seccion' => self::SECCION_PANTALLA[$def['group']] ?? 'Otros'];
        }
        return $out;
    }

    /** Partners (contratas) activos, sin WIN ni WIN DIRECTO. */
    public function partnersOperativos(): array
    {
        return array_values(array_filter($this->usuarios->partners(), static fn($p) => !in_array(mb_strtoupper(trim($p['nombre_partner'])), ['WIN', 'WIN DIRECTO'], true)));
    }

    public function catalogos(?array $actor = null): array
    {
        $eq = $actor ? $this->equipo($actor) : null;
        $roles = [];
        $conteo = $this->usuarios->conteoPorRol();
        foreach (UsuarioValidator::ROLES as $rol) {
            if ($eq && !in_array($rol, self::ROLES_DEL_LIDER, true)) continue; // el líder: supervisores y el jefe de operaciones del partner
            $roles[] = ['rol' => $rol, 'etiqueta' => Auth::etiquetaRol($rol), 'descripcion' => self::DESCRIPCION_ROLES[$rol] ?? '', 'permisos' => array_values(array_intersect(Auth::roleDefaultPermissions($rol), self::permisosDelegables())), 'usuarios' => $conteo[$rol] ?? 0];
        }
        $departamentos = $eq['zonas'] ?? sgsOperationalDepartments();
        $permisos = self::catalogoSgs();
        if ($eq) $permisos = array_values(array_filter($permisos, static fn($p) => in_array($p['clave'], $eq['permisos'], true)));
        return [
            'equipo' => (bool)$eq,
            'roles' => $roles,
            'partners' => array_values(array_filter($this->usuarios->partners(), static fn($p) => !in_array(mb_strtoupper(trim($p['nombre_partner'])), ['WIN', 'WIN DIRECTO'], true))),
            'departamentos' => $departamentos,
            'permisos' => $permisos,
            'areas' => $eq && isset(SGS_AREAS_TRABAJO[$eq['area']]) ? [$eq['area']] : UsuarioValidator::AREAS,
            'cargos' => $eq ? UsuarioValidator::CARGOS_DEL_LIDER : UsuarioValidator::CARGOS, 'cargos_sin_acceso' => UsuarioValidator::CARGOS_SIN_ACCESO, 'procesos' => UsuarioValidator::PROCESOS,
        ];
    }

    /**
     * Líder que gestiona a su equipo: límites (nivel de su rol, sus zonas —null = todas—, su área y sus permisos), o null si
     * administra todo o no tiene el permiso. Con $soloVer también cuenta el líder con zonas o área propias que solo consulta.
     */
    public function equipo(array $actor, bool $soloVer = false): ?array
    {
        if (Auth::can(Auth::PERM_USERS_MANAGE, $actor)) return null;
        if (!Auth::can(Auth::PERM_USERS_TEAM, $actor) && !($soloVer && sgsAlcanceLimitado($actor))) return null;
        $id = (int)$actor['id'];
        return ['id' => $id, 'nivel' => Auth::nivelRol((string)$actor['rol']), 'zonas' => sgsUserZones($id) ?: null,
            'area' => sgsUserArea($id), 'permisos' => $this->permisosEfectivos($id, (string)$actor['rol'])];
    }

    /** ¿El usuario (fila con zonas, todas_zonas, rol y área) está en el equipo del líder? */
    private function enEquipo(array $u, array $eq): bool
    {
        if ((int)$u['id'] === $eq['id'] || !in_array($u['rol'], self::ROLES_DEL_LIDER, true) || Auth::nivelRol((string)$u['rol']) >= $eq['nivel']) return false;
        if ($eq['zonas'] !== null && (!empty($u['todas_zonas']) || !$u['zonas'] || array_diff($u['zonas'], $eq['zonas']))) return false;
        $cargo = trim((string)($u['cargo'] ?? ''));
        if ($cargo !== '' && !in_array($cargo, UsuarioValidator::CARGOS_DEL_LIDER, true)) return false; // cargo por encima del líder
        $area = trim((string)($u['area_trabajo'] ?? ''));
        return !(isset(SGS_AREAS_TRABAJO[$eq['area']]) && isset(SGS_AREAS_TRABAJO[$area]) && $area !== $eq['area']);
    }

    /** El usuario debe ser del equipo del líder (si quien actúa es un líder). */
    public function verificarEquipo(int $id, array $actor): void
    {
        $eq = $this->equipo($actor);
        if (!$eq) return;
        $u = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        if (!$this->enEquipo($this->filaConZonas($u), $eq)) throw new ValidationException('Este usuario no está a su cargo.');
    }

    /** Permisos que tiene el usuario: los personalizados o, si no tiene, los de su rol. */
    private function permisosEfectivos(int $id, string $rol): array
    {
        $overrides = $this->usuarios->permisosPersonalizados($id);
        $defaults = array_flip(Auth::roleDefaultPermissions($rol));
        return array_values(array_filter(self::permisosDelegables(), static fn($p) => $overrides[$p] ?? isset($defaults[$p])));
    }

    /** Alcance de quien consulta: null = todos; si no, su partner y sus zonas (null = todas sus zonas). */
    public function alcance(array $actor): ?array
    {
        if (Auth::can(Auth::PERM_USERS_MANAGE, $actor) || !empty($actor['is_win_directo'])) return null;
        // El jefe de operaciones ve a todo su partner; el resto del personal del partner, solo sus zonas.
        $zonas = ($actor['rol'] ?? '') === Auth::ROLE_JEFE_OPERACIONES ? null : sgsUserZones((int)($actor['id'] ?? 0));
        return ['partner_id' => (int)($actor['partner_id'] ?? 0), 'zonas' => $zonas];
    }

    /** $fila debe traer 'partner_id', 'zonas' y 'todas_zonas' ya calculados. */
    private function dentroDelAlcance(array $fila, ?array $alcance): bool
    {
        if ($alcance === null) return true;
        if ($alcance['partner_id'] < 1 || (int)($fila['partner_id'] ?? 0) !== $alcance['partner_id']) return false;
        if ($alcance['zonas'] === null || !empty($fila['todas_zonas'])) return true;
        return (bool)array_intersect($fila['zonas'] ?? [], $alcance['zonas']);
    }

    /** Varias filas con sus zonas: las adicionales de todas se leen en una sola consulta (2026-10-27, sistema liviano). */
    private function filasConZonas(array $filas): array
    {
        $adicionales = $this->usuarios->zonasAdicionalesDe(array_column($filas, 'id'));
        return array_map(fn($u) => $this->filaConZonas($u, $adicionales[(int)$u['id']] ?? []), $filas);
    }

    /** $adicionales: zonas adicionales ya leídas (null = leerlas ahora). */
    private function filaConZonas(array $u, ?array $adicionales = null): array
    {
        $u['zonas'] = $this->zonasDe($u, $adicionales);
        $u['todas_zonas'] = $this->esTodasZonas($u);
        return $u;
    }

    /** Usuario dentro del alcance de quien consulta, o error. */
    private function usuarioVisible(int $id, array $actor): array
    {
        $u = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        if (!$this->dentroDelAlcance($this->filaConZonas($u), $this->alcance($actor))) throw new ValidationException('Este usuario no pertenece a su partner o a sus zonas.');
        return $u;
    }

    /** Estados de acceso para los contadores de Usuarios y roles (2026-10-15). */
    public const ESTADOS_ACCESO = ['con_acceso', 'por_ingresar', 'por_activar', 'sin_acceso', 'desactivados', 'bloqueados'];

    public function listar(array $filtros, ?array $actor = null): array
    {
        $alcance = $actor ? $this->alcance($actor) : null;
        $eq = $actor ? $this->equipo($actor, true) : null; // líder: solo su equipo (2026-10-27)
        $f = [
            'q' => mb_substr(trim((string)($filtros['q'] ?? '')), 0, 80),
            'rol' => in_array($filtros['rol'] ?? '', UsuarioValidator::ROLES, true) ? $filtros['rol'] : '',
            'estado' => in_array($filtros['estado'] ?? '', ['activos', 'inactivos'], true) ? $filtros['estado'] : '',
            'organizacion' => (string)($filtros['organizacion'] ?? ''),
        ];
        // Estado de acceso (2026-10-15): cada persona está en uno; "bloqueados" se cuenta aparte (se superpone).
        $estadoAcceso = in_array($filtros['estado'] ?? '', self::ESTADOS_ACCESO, true) ? $filtros['estado'] : '';
        $conteos = array_fill_keys(array_merge(['todos'], self::ESTADOS_ACCESO), 0);
        $lista = $this->usuarios->listar($f);
        $bloqueados = array_flip(Auth::lockedAccountIds(array_column($lista, 'id')));
        $visibles = [];
        foreach ($this->filasConZonas($lista) as $fila) {
            if (!$this->dentroDelAlcance($fila, $alcance) || ($eq && !$this->enEquipo($fila, $eq))) continue;
            $fila['bloqueado'] = isset($bloqueados[(int)$fila['id']]);
            $fila['protegido'] = in_array((string)($fila['usuario'] ?? ''), Auth::USUARIOS_PROTEGIDOS, true);
            $fila['sin_acceso'] = trim((string)($fila['usuario'] ?? '')) === '';
            $fila['estado_acceso'] = $fila['sin_acceso'] ? 'sin_acceso'
                : ((int)$fila['activo'] !== 1 ? (!empty($fila['por_activar']) ? 'por_activar' : 'desactivados') : ($fila['ultimo_acceso'] ? 'con_acceso' : 'por_ingresar'));
            $fila['debe_cambiar_password'] = (int)($fila['debe_cambiar_password'] ?? 0) === 1;
            $conteos['todos']++;
            $conteos[$fila['estado_acceso']]++;
            if ($fila['bloqueado']) $conteos['bloqueados']++;
            if ($estadoAcceso === 'bloqueados' ? !$fila['bloqueado'] : ($estadoAcceso !== '' && $fila['estado_acceso'] !== $estadoAcceso)) continue;
            unset($fila['sede'], $fila['zona']);
            $visibles[] = $fila;
        }
        if ($eq) {
            $suyos = array_filter($this->filasConZonas($this->usuarios->listar(['q' => '', 'rol' => '', 'estado' => '', 'organizacion' => ''])), fn($u) => $this->enEquipo($u, $eq));
            $activos = array_filter($suyos, fn($u) => (int)$u['activo'] === 1);
            $resumen = ['total' => count($suyos), 'activos' => count($activos), 'partners' => count(array_filter($activos, fn($u) => !in_array(mb_strtoupper(trim((string)$u['nombre_partner'])), ['', 'WIN', 'WIN DIRECTO'], true))),
                'administradores' => 0];
            $resumen['directos'] = $resumen['activos'] - $resumen['partners'];
        } elseif ($alcance === null) $resumen = array_map('intval', $this->usuarios->resumen());
        else {
            // Indicadores solo con el personal que esta persona puede ver.
            $todos = array_filter($this->filasConZonas($this->usuarios->listar(['q' => '', 'rol' => '', 'estado' => '', 'organizacion' => ''])),
                fn($u) => $this->dentroDelAlcance($u, $alcance));
            $activos = array_filter($todos, fn($u) => (int)$u['activo'] === 1);
            $resumen = ['total' => count($todos), 'activos' => count($activos), 'directos' => 0, 'partners' => count($activos),
                'administradores' => 0];
        }
        return ['usuarios' => $visibles, 'resumen' => $resumen, 'conteos' => $conteos];
    }

    /** Ficha con datos personales (para quien solo ve o gestiona personal). */
    public function detallePersonal(int $id, array $actor): array
    {
        $u = $this->usuarioVisible($id, $actor);
        return ['id' => (int)$u['id'], 'dni' => $u['dni'], 'nombres' => $u['nombres'], 'apellidos' => $u['apellidos'], 'email' => $u['email'],
            'telefono_personal' => $u['telefono_personal'], 'telefono_corporativo' => $u['telefono_corporativo'], 'area_trabajo' => $u['area_trabajo'], 'cargo' => $u['cargo'] ?? null,
            'rol' => $u['rol'], 'nombre_partner' => $u['nombre_partner'], 'zonas' => $this->zonasDe($u)];
    }

    /**
     * Agregar o editar personal: SOLO datos personales. Al crear, el trabajador queda "Sin acceso" (sin usuario, inactivo)
     * y se avisa a los administradores para que completen usuario, contraseña, rol y zonas.
     */
    public function guardarPersonal(array $in, int $id, array $actor): array
    {
        $d = UsuarioValidator::personal($in);
        $alcance = $this->alcance($actor);
        $previo = $id > 0 ? $this->usuarios->obtener($id) : null;
        $d['area_trabajo'] = UsuarioValidator::deLista($d['area_trabajo'], UsuarioValidator::AREAS, $previo['area_trabajo'] ?? null, 'area_trabajo', 'área de trabajo');
        $d['cargo'] = UsuarioValidator::deLista($d['cargo'], UsuarioValidator::CARGOS, $previo['cargo'] ?? null, 'cargo', 'cargo');
        if ($id > 0) {
            $u = $this->usuarioVisible($id, $actor);
            if ($u['rol'] === Auth::ROLE_ADMINISTRADOR && !Auth::can(Auth::PERM_USERS_MANAGE, $actor)) {
                throw new ValidationException('Los datos de un administrador solo los cambia otro administrador.');
            }
            if ($dup = $this->usuarios->existeDuplicado($d['dni'], $d['email'], (string)($u['usuario'] ?? ''), $id)) {
                throw new ValidationException("Ya existe otro usuario con el mismo $dup.", $dup === 'correo' ? 'email' : 'dni');
            }
            $this->usuarios->actualizarPersonal($id, $d);
            return ['id' => $id, 'nombre' => $d['nombres'] . ' ' . $d['apellidos'], 'nuevo' => false];
        }

        // Organización y zona del trabajador nuevo
        $zona = mb_strtoupper(trim((string)($in['zona'] ?? '')), 'UTF-8');
        if ($alcance !== null) {
            $d['partner_id'] = $alcance['partner_id'];
            $permitidas = $alcance['zonas'] ?? sgsOperationalDepartments();
            if (!in_array($zona, $permitidas, true)) throw new ValidationException('Elija una de sus zonas de trabajo.', 'zona');
        } else {
            $organizacion = ($in['organizacion'] ?? 'directo') === 'partner' ? 'partner' : 'directo';
            $d['partner_id'] = $organizacion === 'partner' ? (int)($in['partner_id'] ?? 0) : (int)($this->usuarios->partnerWinDirectoId() ?? 0);
            if ($organizacion === 'partner' && $d['partner_id'] < 1) throw new ValidationException('Seleccione el partner del trabajador.', 'partner_id');
            if ($zona !== '' && !in_array($zona, sgsOperationalDepartments(), true)) throw new ValidationException('Zona de trabajo no válida.', 'zona');
        }
        $d['sede'] = $zona !== '' ? sgsSiteFromDepartment($zona) : '';
        if ($dup = $this->usuarios->existeDuplicado($d['dni'], $d['email'], '', 0)) {
            throw new ValidationException("Ya existe otro usuario con el mismo $dup.", $dup === 'correo' ? 'email' : 'dni');
        }
        $this->pdo->beginTransaction();
        try {
            $nuevoId = $this->usuarios->crearPersonal($d, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT));
            $nombre = $d['nombres'] . ' ' . $d['apellidos'];
            $this->usuarios->notificarAdministradores('Nuevo personal por completar',
                $nombre . ' fue registrado por ' . ($actor['nombre_completo'] ?? 'un usuario') . '. Asígnale usuario, contraseña, rol y zonas para darle acceso.',
                'index.php?page=usuarios');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $nuevoId, 'nombre' => $nombre, 'nuevo' => true];
    }

    public function detalle(int $id): array
    {
        $u = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        $overrides = $this->usuarios->permisosPersonalizados($id);
        $efectivos = $this->permisosEfectivos($id, (string)$u['rol']);
        $esDirecto = empty($u['nombre_partner']) || in_array(mb_strtoupper(trim($u['nombre_partner'])), ['WIN', 'WIN DIRECTO'], true);
        return [
            'id' => (int)$u['id'], 'dni' => $u['dni'], 'nombres' => $u['nombres'], 'apellidos' => $u['apellidos'], 'usuario' => $u['usuario'],
            'email' => $u['email'], 'telefono_personal' => $u['telefono_personal'], 'telefono_corporativo' => $u['telefono_corporativo'],
            'rol' => $u['rol'], 'organizacion' => $esDirecto ? 'directo' : 'partner',
            'partner_id' => $esDirecto ? 0 : (int)$u['partner_id'],
            'area_trabajo' => $u['area_trabajo'], 'cargo' => $u['cargo'] ?? null,
            'procesos' => array_values(array_filter(array_map('trim', explode(',', (string)($u['procesos_copiados'] ?? ''))))),
            'modalidad' => $u['modalidad'], 'turno' => $u['turno'], 'activo' => (int)$u['activo'],
            'zonas' => $this->zonasDe($u), 'todas_zonas' => $this->esTodasZonas($u),
            'permisos' => $efectivos, 'permisos_personalizados' => !empty($overrides),
        ];
    }

    /** $quedaActivo = false (2026-10-26): un usuario nuevo, o al que se le da acceso, queda "Por activar" hasta que la
     *  cuenta principal lo active (UsuarioController decide según quién lo crea). */
    public function guardar(array $in, int $id, int $actorId, bool $quedaActivo = true, ?array $eq = null): array
    {
        $d = UsuarioValidator::usuario($in, $id === 0);
        $organizacion = ($in['organizacion'] ?? 'directo') === 'partner' ? 'partner' : 'directo';
        if ($organizacion === 'partner') {
            if ($d['partner_id'] < 1) throw new ValidationException('Seleccione el partner (contrata) del usuario.', 'partner_id');
        } else {
            $d['partner_id'] = (int)($this->usuarios->partnerWinDirectoId() ?? 0);
        }


        // El jefe de operaciones ve todo su partner: no lleva zonas. El coordinador (líder) puede llevarlas: sin zonas ve todo.
        $operativo = in_array($d['rol'], [Auth::ROLE_SUPERVISOR, Auth::ROLE_COORDINADOR], true);
        if ($d['rol'] === Auth::ROLE_SUPERVISOR && !$d['todas_zonas'] && !$d['zonas']) throw new ValidationException('Marque al menos una zona de trabajo (o "Todas las zonas"): define qué puede ver el usuario.', 'zonas');
        // La primera zona es la base; se guarda como sede porque el sistema de origen la usa. Las demás, como accesos adicionales.
        $d['sede'] = $d['todas_zonas'] ? 'PROVINCIAS' : ($d['zonas'] ? sgsSiteFromDepartment($d['zonas'][0]) : '');
        $zonasAdicionales = $d['todas_zonas'] ? [] : array_slice($d['zonas'], 1);
        if ($organizacion === 'partner' && !in_array($d['rol'], [Auth::ROLE_SUPERVISOR, Auth::ROLE_JEFE_OPERACIONES], true)) {
            throw new ValidationException('El personal de un partner solo puede tener rol Supervisor o Jefe de operaciones.', 'rol');
        }
        if ($organizacion === 'directo' && $d['rol'] === Auth::ROLE_JEFE_OPERACIONES) {
            throw new ValidationException('El rol Jefe de operaciones es solo para personal de un partner.', 'rol');
        }

        $completaAcceso = false;
        $previo = $id > 0 ? $this->usuarios->obtener($id) : null;
        $d['area_trabajo'] = UsuarioValidator::deLista($d['area_trabajo'], UsuarioValidator::AREAS, $previo['area_trabajo'] ?? null, 'area_trabajo', 'área de trabajo');
        $d['cargo'] = UsuarioValidator::deLista($d['cargo'], UsuarioValidator::CARGOS, $previo['cargo'] ?? null, 'cargo', 'cargo');
        if ($eq) $this->validarEquipo($d, $id, $eq);
        if ($id > 0) {
            $actual = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
            // Personal registrado "Sin acceso": al completarlo el administrador debe darle una contraseña; queda activo.
            $completaAcceso = trim((string)($actual['usuario'] ?? '')) === '';
            if ($completaAcceso && $d['password'] === '') throw new ValidationException('Asigne una contraseña para darle acceso al sistema.', 'password');
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
                if ($d['password'] !== '') $this->usuarios->cambiarPassword($id, password_hash($d['password'], PASSWORD_DEFAULT), $d['pedir_cambio']);
                if ($completaAcceso) $quedaActivo ? $this->usuarios->activar($id) : $this->usuarios->dejarPorActivar($id);
            } else {
                $hash = password_hash($d['password'], PASSWORD_DEFAULT);
                $id = $this->usuarios->crear($d, $hash, $quedaActivo);
                if ($d['pedir_cambio']) $this->usuarios->cambiarPassword($id, $hash, true);
            }
            $delegables = self::permisosDelegables();
            // Sin "Ver" de una pantalla no se guarda editar, eliminar ni opciones de ella (Auth::conDependencias)
            if ($d['permisos_personalizados']) $this->usuarios->guardarPermisos($id, Auth::conDependencias(array_values(array_intersect($d['permisos'], $delegables))), $delegables, $actorId);
            else $this->usuarios->limpiarPermisos($id);
            $this->usuarios->guardarZonas($id, $operativo ? $zonasAdicionales : [], $actorId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'usuario' => $d['usuario'], 'por_activar' => !$quedaActivo && ($previo === null || $completaAcceso),
            'nombre' => $d['nombres'] . ' ' . $d['apellidos']];
    }

    /** Lo que registra o edita un líder debe quedar dentro de su equipo: roles por debajo, sus zonas, su área y sus permisos. */
    private function validarEquipo(array $d, int $id, array $eq): void
    {
        if ($id === $eq['id']) throw new ValidationException('Su propio rol, zonas, área y permisos solo los cambia el administrador.');
        if ($id > 0) {
            $u = $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
            if (!$this->enEquipo($this->filaConZonas($u), $eq)) throw new ValidationException('Este usuario no está a su cargo.');
        }
        if (!in_array($d['rol'], self::ROLES_DEL_LIDER, true)) throw new ValidationException('Como líder solo puede registrar supervisores o al jefe de operaciones de un partner.', 'rol');
        if ($d['rol'] === Auth::ROLE_JEFE_OPERACIONES && ($d['cargo'] ?? '') !== 'Jefe de operaciones') {
            throw new ValidationException('El rol Partner es para el cargo Jefe de operaciones.', 'cargo');
        }
        if (($d['cargo'] ?? '') !== '' && !in_array($d['cargo'], UsuarioValidator::CARGOS_DEL_LIDER, true)) {
            throw new ValidationException('Ese cargo está por encima del suyo: elija uno de la lista.', 'cargo');
        }
        if ($eq['zonas'] !== null) {
            if ($d['todas_zonas'] || !$d['zonas'] || array_diff($d['zonas'], $eq['zonas'])) {
                throw new ValidationException('Solo puede asignar sus zonas: ' . implode(', ', $eq['zonas']) . '.', 'zonas');
            }
        }
        if (isset(SGS_AREAS_TRABAJO[$eq['area']]) && $d['area_trabajo'] !== $eq['area']) {
            throw new ValidationException("Solo puede asignar su área de trabajo: {$eq['area']}.", 'area_trabajo');
        }
        if ($d['permisos_personalizados'] && array_diff($d['permisos'], $eq['permisos'])) {
            throw new ValidationException('Solo puede dar permisos que usted tiene.', 'permisos');
        }
    }

    /**
     * Líder: registra o actualiza a alguien sin acceso al sistema (Asistente, Asesor, Almacenero, Analista; 2026-10-27). Solo
     * guarda sus datos, su cargo, su área, su organización y su zona (dentro de los límites del líder); no tiene usuario.
     */
    public function guardarSinAcceso(array $in, int $id, array $actor): array
    {
        $eq = $this->equipo($actor) ?? throw new ValidationException('No tiene permiso para registrar personal.');
        $d = UsuarioValidator::personal($in);
        $previo = $id > 0 ? $this->usuarios->obtener($id) : null;
        $d['area_trabajo'] = UsuarioValidator::deLista($d['area_trabajo'], UsuarioValidator::AREAS, $previo['area_trabajo'] ?? null, 'area_trabajo', 'área de trabajo');
        $d['cargo'] = UsuarioValidator::deLista($d['cargo'], UsuarioValidator::CARGOS, $previo['cargo'] ?? null, 'cargo', 'cargo');
        $zonas = array_values(array_filter(array_map(static fn($z) => mb_strtoupper(trim((string)$z), 'UTF-8'), (array)($in['zonas'] ?? []))));
        $this->validarEquipo(['rol' => Auth::ROLE_SUPERVISOR, 'cargo' => $d['cargo'], 'todas_zonas' => false, 'zonas' => $zonas,
            'area_trabajo' => $d['area_trabajo'], 'permisos_personalizados' => false, 'permisos' => []], $id, $eq);
        if ($previo && trim((string)($previo['usuario'] ?? '')) !== '') throw new ValidationException('Esta persona tiene acceso al sistema: su cargo solo lo cambia el administrador.', 'cargo');
        if ($dup = $this->usuarios->existeDuplicado($d['dni'], $d['email'], '', $id)) {
            throw new ValidationException("Ya existe otro usuario con el mismo $dup.", $dup === 'correo' ? 'email' : 'dni');
        }
        $nombre = $d['nombres'] . ' ' . $d['apellidos'];
        if ($id > 0) {
            $this->usuarios->actualizarPersonal($id, $d);
            return ['id' => $id, 'usuario' => '', 'nombre' => $nombre, 'por_activar' => false];
        }
        $partner = ($in['organizacion'] ?? 'directo') === 'partner';
        $d['partner_id'] = $partner ? (int)($in['partner_id'] ?? 0) : (int)($this->usuarios->partnerWinDirectoId() ?? 0);
        if ($partner && $d['partner_id'] < 1) throw new ValidationException('Seleccione el partner del trabajador.', 'partner_id');
        $d['sede'] = $zonas ? sgsSiteFromDepartment($zonas[0]) : '';
        $nuevo = $this->usuarios->crearPersonal($d, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT));
        return ['id' => $nuevo, 'usuario' => '', 'nombre' => $nombre, 'por_activar' => false];
    }

    /** Aviso en la campana de la cuenta principal (si existe). */
    public function avisarCuentaPrincipal(string $titulo, string $mensaje): void
    {
        $principal = Auth::cuentaPrincipalId();
        if ($principal) $this->pdo->prepare('INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:u, :t, :m, :url)')
            ->execute([':u' => $principal, ':t' => $titulo, ':m' => $mensaje, ':url' => 'index.php?page=usuarios']);
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
    public function restablecerPassword(int $id, string $nueva = '', bool $pedirCambio = true): string
    {
        $this->usuarios->obtener($id) ?? throw new ValidationException('Usuario no encontrado.');
        if ($nueva === '') $nueva = self::passwordTemporal();
        UsuarioValidator::password($nueva);
        $this->usuarios->cambiarPassword($id, password_hash($nueva, PASSWORD_DEFAULT), $pedirCambio);
        Auth::unlockAccount($id); // una contraseña nueva también libera el bloqueo por intentos
        return $nueva;
    }

    public function cambiarPasswordPropia(int $id, string $actual, string $nueva): void
    {
        $hash = $this->usuarios->passwordHash($id);
        if ($hash === null || !password_verify($actual, $hash)) throw new ValidationException('La contraseña actual no es correcta.', 'actual');
        if ($actual === $nueva) throw new ValidationException('La nueva contraseña debe ser distinta de la actual.', 'nueva');
        UsuarioValidator::password($nueva);
        $this->usuarios->cambiarPassword($id, password_hash($nueva, PASSWORD_DEFAULT)); // quita el cambio obligatorio
        if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['user_id'] ?? 0) === $id) unset($_SESSION['debe_cambiar_password']);
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
    private function zonasDe(array $u, ?array $adicionales = null): array
    {
        if ($this->esTodasZonas($u)) return [];
        $base = mb_strtoupper(operationalDepartmentFromSite((string)(($u['sede'] ?? '') ?: ($u['zona'] ?? ''))), 'UTF-8');
        $zonas = $base !== '' && $base !== 'PROVINCIAS' ? [$base] : [];
        foreach ($adicionales ?? $this->usuarios->zonasAdicionales((int)$u['id']) as $z) if (!in_array($z, $zonas, true)) $zonas[] = $z;
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
}
