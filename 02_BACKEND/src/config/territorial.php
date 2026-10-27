<?php

function operationalDepartmentFromSite(?string $site): string {
    $normalized = mb_strtoupper(trim((string)$site), 'UTF-8');
    return [
        'CHIMBOTE' => 'ÁNCASH',
        'AREQUIPA' => 'AREQUIPA',
        'CAJAMARCA' => 'CAJAMARCA',
        'CUSCO' => 'CUSCO',
        'ICA' => 'ICA',
        'HUANCAYO' => 'JUNÍN',
        'TRUJILLO' => 'LA LIBERTAD',
        'CHICLAYO' => 'LAMBAYEQUE',
        'PIURA' => 'PIURA',
        'TACNA' => 'TACNA',
        'LIMA' => 'LIMA',
        'CALLAO' => 'CALLAO',
        'HUACHO' => 'NORTE CHICO',
        'PROVINCIAS' => 'PROVINCIAS',
    ][$normalized] ?? '';
}

function operationalDepartmentSql(string $userAlias = 'u'): string {
    $site = "UPPER(TRIM(COALESCE(NULLIF({$userAlias}.sede,''),NULLIF({$userAlias}.zona,''),'')))";
    return "CASE {$site}
        WHEN 'CHIMBOTE' THEN 'ÁNCASH'
        WHEN 'AREQUIPA' THEN 'AREQUIPA'
        WHEN 'CAJAMARCA' THEN 'CAJAMARCA'
        WHEN 'CUSCO' THEN 'CUSCO'
        WHEN 'ICA' THEN 'ICA'
        WHEN 'HUANCAYO' THEN 'JUNÍN'
        WHEN 'TRUJILLO' THEN 'LA LIBERTAD'
        WHEN 'CHICLAYO' THEN 'LAMBAYEQUE'
        WHEN 'PIURA' THEN 'PIURA'
        WHEN 'TACNA' THEN 'TACNA'
        WHEN 'LIMA' THEN 'LIMA'
        WHEN 'CALLAO' THEN 'CALLAO'
        WHEN 'HUACHO' THEN 'NORTE CHICO'
        WHEN 'PROVINCIAS' THEN 'PROVINCIAS'
        ELSE '' END";
}

/**
 * Condición SQL "es personal directo de WIN" (sin partner o partner WIN / WIN DIRECTO).
 * Solo el personal directo tiene seguimiento (turnos, línea de tiempo, productividad);
 * los supervisores de contratas (partners) se evalúan únicamente por tiempo de respuesta.
 * Requiere que la consulta tenga "usuarios {$u}" y "LEFT JOIN partners {$p} ON {$p}.id = {$u}.partner_id".
 */
function sgsDirectStaffSql(string $u = 'u', string $p = 'p'): string
{
    return "({$u}.partner_id IS NULL OR UPPER(TRIM(COALESCE({$p}.nombre_partner,''))) IN ('','WIN','WIN DIRECTO'))";
}

/**
 * Zona de trabajo de un usuario: departamento base (según su sede) + departamentos con acceso adicional.
 * Devuelve null si la sede es "Provincias" (alcance total) o una lista de departamentos en MAYÚSCULAS.
 * Se lee de la BD para reflejar cambios de zona sin pedir un nuevo inicio de sesión.
 */
function sgsUserZones(int $userId): ?array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $pdo = Database::getConnection();
    $st = $pdo->prepare('SELECT sede, zona FROM usuarios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $userId]);
    $u = $st->fetch() ?: [];
    $base = operationalDepartmentFromSite((string)(($u['sede'] ?? '') ?: ($u['zona'] ?? '')));
    if ($base === 'PROVINCIAS') return $cache[$userId] = null;
    $zones = $base !== '' ? [mb_strtoupper($base, 'UTF-8')] : [];
    $st = $pdo->prepare('SELECT departamento FROM usuario_departamentos_acceso WHERE usuario_id = :id AND activo = 1');
    $st->execute([':id' => $userId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $zones[] = mb_strtoupper(trim((string)$d), 'UTF-8');
    return $cache[$userId] = array_values(array_unique(array_filter($zones)));
}

/** Condición SQL "el departamento pertenece a la zona del usuario". Sin zona asignada no ve nada. */
function sgsZoneSql(string $departmentExpr, int $userId, array &$params, string $prefix = ':zona'): string
{
    $zones = sgsUserZones($userId);
    if ($zones === null) return '1=1';
    if (!$zones) return '1=0';
    $names = [];
    foreach ($zones as $i => $zone) { $names[] = "{$prefix}{$i}"; $params["{$prefix}{$i}"] = $zone; }
    return "UPPER(TRIM({$departmentExpr})) IN (" . implode(',', $names) . ')';
}

function sgsUserInZone(int $userId, ?string $department): bool
{
    $zones = sgsUserZones($userId);
    return $zones === null || in_array(mb_strtoupper(trim((string)$department), 'UTF-8'), $zones, true);
}

/**
 * Inverso de operationalDepartmentFromSite(): sede (ciudad) que representa a un departamento.
 * SGS solo trabaja con departamentos ("Zonas de trabajo"); la sede se sigue guardando porque el
 * sistema de origen (misma BD) la usa para calcular el departamento base.
 */
function sgsSiteFromDepartment(string $department): string
{
    $map = [
        'ÁNCASH' => 'CHIMBOTE', 'AREQUIPA' => 'AREQUIPA', 'CAJAMARCA' => 'CAJAMARCA', 'CUSCO' => 'CUSCO', 'ICA' => 'ICA',
        'JUNÍN' => 'HUANCAYO', 'LA LIBERTAD' => 'TRUJILLO', 'LAMBAYEQUE' => 'CHICLAYO', 'PIURA' => 'PIURA', 'TACNA' => 'TACNA',
        'LIMA' => 'LIMA', 'CALLAO' => 'CALLAO', 'NORTE CHICO' => 'HUACHO',
    ];
    return $map[mb_strtoupper(trim($department), 'UTF-8')] ?? '';
}

/** Departamento real de un nombre de ubigeo (corrige alias heredados: HUANCAYO como departamento = JUNÍN). */
function sgsDepartmentAlias(string $name): string
{
    $n = mb_strtoupper(trim($name), 'UTF-8');
    return ['HUANCAYO' => 'JUNÍN', 'CHIMBOTE' => 'ÁNCASH', 'TRUJILLO' => 'LA LIBERTAD', 'CHICLAYO' => 'LAMBAYEQUE'][$n] ?? $n;
}

/**
 * Zonas por provincia (2026-10-27): por regla la zona de trabajo es el departamento; estas provincias forman otra zona.
 * El Norte Chico (Huaura, Huaral, Barranca) es del departamento de Lima pero lo atiende otro supervisor. La dirección
 * se sigue guardando oficial (LIMA / HUAURA / HUACHO): la zona se calcula. Para agregar otra (p. ej. Cañete), una línea.
 */
const SGS_ZONAS_POR_PROVINCIA = [
    'LIMA' => ['HUAURA' => 'NORTE CHICO', 'HUARAL' => 'NORTE CHICO', 'BARRANCA' => 'NORTE CHICO'],
];

/** Zona de trabajo de una ubicación (departamento y provincia oficiales), en MAYÚSCULAS. */
function sgsZonaDeUbicacion(?string $departamento, ?string $provincia = ''): string
{
    $dep = sgsDepartmentAlias((string)$departamento);
    return SGS_ZONAS_POR_PROVINCIA[$dep][mb_strtoupper(trim((string)$provincia), 'UTF-8')] ?? $dep;
}

/** La misma zona como expresión SQL, sobre una tabla con columnas departamento y provincia (alias). */
function sgsZonaSql(string $alias = 's'): string
{
    $dep = "UPPER(TRIM({$alias}.departamento))";
    $casos = '';
    foreach (SGS_ZONAS_POR_PROVINCIA as $d => $provincias) {
        foreach ($provincias as $p => $zona) $casos .= " WHEN {$dep} = '{$d}' AND UPPER(TRIM({$alias}.provincia)) = '{$p}' THEN '{$zona}'";
    }
    return "(CASE{$casos} ELSE {$dep} END)";
}

/** Departamento oficial de una zona (para empezar un formulario): NORTE CHICO → LIMA; el resto es el mismo nombre. */
function sgsDepartamentoDeZona(string $zona): string
{
    $zona = mb_strtoupper(trim($zona), 'UTF-8');
    foreach (SGS_ZONAS_POR_PROVINCIA as $dep => $provincias) if (in_array($zona, $provincias, true)) return $dep;
    return $zona;
}

/** Zonas de trabajo: las del catálogo de ubicaciones (tabla ubigeo, la misma del formulario), calculadas por provincia. */
function sgsOperationalDepartments(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = Database::getConnection()->query('SELECT DISTINCT departamento, provincia FROM ubigeo')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        win_log_error('zonas_ubigeo_error', $e->getMessage());
        $rows = array_map(static fn($d) => ['departamento' => $d, 'provincia' => ''], ['ÁNCASH', 'AREQUIPA', 'CUSCO', 'JUNÍN', 'LA LIBERTAD', 'LAMBAYEQUE', 'PIURA']);
    }
    $zonas = array_values(array_unique(array_filter(array_map(static fn($u) => sgsZonaDeUbicacion($u['departamento'], $u['provincia']), $rows))));
    $key = static fn($d) => strtr($d, ['Á' => 'A', 'Í' => 'I']);
    usort($zonas, static fn($a, $b) => strcmp($key($a), $key($b)));
    return $cache = $zonas;
}

/** Departamentos oficiales del catálogo (para elegir la ubicación de una supervisión). */
function sgsDepartamentosUbigeo(): array
{
    $deps = Database::getConnection()->query('SELECT DISTINCT departamento FROM ubigeo')->fetchAll(PDO::FETCH_COLUMN);
    $key = static fn($d) => strtr($d, ['Á' => 'A', 'Í' => 'I']);
    usort($deps, static fn($a, $b) => strcmp($key($a), $key($b)));
    return $deps;
}

/**
 * Área de trabajo del usuario (2026-10-27, campo "Área de trabajo" de Usuarios): "Instalaciones" ve solo instalaciones y
 * "VT (visitas técnicas)" solo averías, postventa, reiteradas y garantías. "Instalaciones y VT", "Almacén" o
 * vacío: todo, como antes. Se aplica a la bolsa de pendientes y a los avisos de pendientes de su zona.
 */
const SGS_AREAS_TRABAJO = [
    'Instalaciones' => ['Instalación'],
    'VT (visitas técnicas)' => ['Avería', 'Postventa', 'Reiterada', 'Garantía'],
];

/** Clasificaciones de supervisión que el usuario NO ve por su área (vacío = ve todas). */
function sgsClasificacionesFueraDeArea(?string $area): array
{
    if (!isset(SGS_AREAS_TRABAJO[$area])) return [];
    return array_merge(...array_values(array_diff_key(SGS_AREAS_TRABAJO, [$area => 1])));
}

/** Rol del usuario, leído de la base. */
function sgsUserRol(int $userId): string
{
    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        $st = Database::getConnection()->prepare('SELECT rol FROM usuarios WHERE id = :id');
        $st->execute([':id' => $userId]);
        $cache[$userId] = (string)$st->fetchColumn();
    }
    return $cache[$userId];
}

/** Área de trabajo del usuario, leída de la base (refleja cambios sin volver a iniciar sesión). */
function sgsUserArea(int $userId): string
{
    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        $st = Database::getConnection()->prepare('SELECT area_trabajo FROM usuarios WHERE id = :id');
        $st->execute([':id' => $userId]);
        $cache[$userId] = trim((string)$st->fetchColumn());
    }
    return $cache[$userId];
}

/** Condición SQL "la supervisión es del área del usuario" (alias con clasificacion_supervision). */
function sgsAreaSql(string $alias, int $userId): string
{
    $fuera = sgsClasificacionesFueraDeArea(sgsUserArea($userId));
    if (!$fuera) return '1=1';
    $lista = implode(',', array_map(static fn($c) => "'" . str_replace("'", "''", $c) . "'", $fuera));
    return "COALESCE({$alias}.clasificacion_supervision, '') NOT IN ({$lista})";
}

/** ¿La clasificación es del área del usuario? */
function sgsAreaPermite(int $userId, ?string $clasificacion): bool
{
    return !in_array((string)$clasificacion, sgsClasificacionesFueraDeArea(sgsUserArea($userId)), true);
}

/**
 * Líder con alcance propio (2026-10-27): un Coordinador (líder técnico) con zonas marcadas o con área Instalaciones o
 * VT ve solo eso (supervisiones, pendientes, supervisores, línea de tiempo, dashboards, técnicos y usuarios). Sin
 * zonas y con ambas áreas ve todo, como antes. El administrador y el gerente ven todo siempre.
 */
function sgsAlcanceLimitado(array $user): bool
{
    if (($user['rol'] ?? '') !== Auth::ROLE_COORDINADOR) return false;
    $id = (int)($user['id'] ?? 0);
    return (bool)sgsUserZones($id) || sgsClasificacionesFueraDeArea(sgsUserArea($id)) !== [];
}

/** Condición SQL del alcance del líder sobre supervisiones (alias con departamento, provincia y clasificación). */
function sgsAlcanceSupervisionSql(array $user, string $alias, array &$params, string $prefix = ':alc'): string
{
    if (!sgsAlcanceLimitado($user)) return '1=1';
    $id = (int)$user['id'];
    $partes = [sgsAreaSql($alias, $id)];
    if (sgsUserZones($id)) $partes[] = sgsZoneSql(sgsZonaSql($alias), $id, $params, $prefix);
    return '(' . implode(' AND ', $partes) . ')';
}

/** ¿La supervisión está dentro del alcance del líder? (fila con departamento, provincia y clasificacion_supervision) */
function sgsAlcancePermiteSupervision(array $user, array $sup): bool
{
    if (!sgsAlcanceLimitado($user)) return true;
    $id = (int)$user['id'];
    if (!sgsAreaPermite($id, $sup['clasificacion_supervision'] ?? '')) return false;
    $zonas = sgsUserZones($id);
    return !$zonas || in_array(sgsZonaDeUbicacion($sup['departamento'] ?? '', $sup['provincia'] ?? ''), $zonas, true);
}

/**
 * Supervisores que ve el líder (filas con 'id'): los de un rol por debajo del suyo que trabajan alguna de sus zonas (no los
 * de "todas las zonas") y no son de la otra área. Él mismo siempre. Sin alcance propio, todos.
 */
function sgsSupervisoresVisibles(array $viewer, array $supervisores): array
{
    if (!sgsAlcanceLimitado($viewer)) return $supervisores;
    $yo = (int)$viewer['id'];
    $zonas = sgsUserZones($yo);
    $miArea = sgsUserArea($yo);
    return array_values(array_filter($supervisores, static function ($s) use ($yo, $zonas, $miArea, $viewer) {
        $id = (int)$s['id'];
        if ($id === $yo) return true;
        if (Auth::nivelRol(sgsUserRol($id)) >= Auth::nivelRol((string)($viewer['rol'] ?? ''))) return false; // ni pares ni superiores
        if ($zonas) { $suyas = sgsUserZones($id); if (!$suyas || !array_intersect($suyas, $zonas)) return false; }
        $area = sgsUserArea($id);
        return !(isset(SGS_AREAS_TRABAJO[$miArea]) && isset(SGS_AREAS_TRABAJO[$area]) && $area !== $miArea);
    }));
}

/** " AND u.id IN (…)" con los supervisores que ve el líder ('' si ve a todos), para consultas sobre usuarios u. */
function sgsSoloSupervisoresVisiblesSql(array $viewer, PDO $pdo): string
{
    if (!sgsAlcanceLimitado($viewer)) return '';
    $ids = array_column(sgsSupervisoresVisibles($viewer, $pdo->query("SELECT id FROM usuarios WHERE rol = 'Supervisor' AND activo = 1")->fetchAll(PDO::FETCH_ASSOC)), 'id');
    return ' AND u.id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')';
}
