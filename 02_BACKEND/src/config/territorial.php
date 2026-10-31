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
 * Rol, área y zonas de varios usuarios, leídos de la BD (reflejan cambios sin pedir un nuevo inicio de sesión) y guardados
 * para el resto de la petición. Antes de recorrer una lista de usuarios se llama con todos sus id: así son 2 consultas en
 * total y no 3 por usuario (2026-10-27, sistema liviano). Devuelve [id => ['rol', 'area', 'zonas']].
 */
function sgsPrecargarUsuarios(array $ids): array
{
    static $cache = [];
    $faltan = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($cache)));
    if (!$faltan) return $cache;
    $pdo = Database::getConnection();
    $lista = implode(',', $faltan); // enteros
    $filas = [];
    foreach ($pdo->query("SELECT id, sede, zona, rol, area_trabajo FROM usuarios WHERE id IN ($lista)")->fetchAll() as $u) $filas[(int)$u['id']] = $u;
    foreach ($faltan as $id) {
        $u = $filas[$id] ?? [];
        $base = operationalDepartmentFromSite((string)(($u['sede'] ?? '') ?: ($u['zona'] ?? '')));
        $cache[$id] = ['rol' => (string)($u['rol'] ?? ''), 'area' => trim((string)($u['area_trabajo'] ?? '')),
            'zonas' => $base === 'PROVINCIAS' ? null : ($base !== '' ? [mb_strtoupper($base, 'UTF-8')] : [])];
    }
    foreach ($pdo->query("SELECT usuario_id, departamento FROM usuario_departamentos_acceso WHERE activo = 1 AND usuario_id IN ($lista)")->fetchAll() as $a) {
        $id = (int)$a['usuario_id'];
        if ($cache[$id]['zonas'] !== null) $cache[$id]['zonas'][] = mb_strtoupper(trim((string)$a['departamento']), 'UTF-8');
    }
    foreach ($faltan as $id) if ($cache[$id]['zonas'] !== null) $cache[$id]['zonas'] = array_values(array_unique(array_filter($cache[$id]['zonas'])));
    return $cache;
}

/**
 * Zona de trabajo de un usuario: departamento base (según su sede) + departamentos con acceso adicional.
 * Devuelve null si la sede es "Provincias" (alcance total) o una lista de departamentos en MAYÚSCULAS.
 */
function sgsUserZones(int $userId): ?array
{
    return sgsPrecargarUsuarios([$userId])[$userId]['zonas'];
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
    return sgsPrecargarUsuarios([$userId])[$userId]['rol'];
}

/** Área de trabajo del usuario, leída de la base (refleja cambios sin volver a iniciar sesión). */
function sgsUserArea(int $userId): string
{
    return sgsPrecargarUsuarios([$userId])[$userId]['area'];
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
 * Cuadrillas por área (2026-10-31, regla del usuario): en LIMA, CALLAO y NORTE CHICO cada cuadrilla es de SGI (instalaciones)
 * o de SGA (visitas técnicas), según la nomenclatura de su técnico líder ("P 1 KAJOMI SGI JUAN PEREZ"). En provincia la
 * nomenclatura también lleva SGI/SGA, pero la cuadrilla atiende todo. El T2 sigue a su líder. Igual en supervision_lista.js.
 */
const SGS_ZONAS_CUADRILLA_POR_AREA = ['LIMA', 'CALLAO', 'NORTE CHICO'];
const SGS_AREA_CUADRILLA = ['SGI' => 'Instalaciones', 'SGA' => 'VT (visitas técnicas)'];
/** Tipos que atienden las cuadrillas de las dos áreas (2026-10-31: la garantía también la ve una cuadrilla de instalaciones). */
const SGS_CUADRILLA_AMBAS_AREAS = ['Garantía'];

/** 'SGI' o 'SGA' si la cuadrilla es de una zona dividida y su nomenclatura dice una sola; null = atiende todo. */
function sgsAreaCuadrilla(?string $zona, ?string $nombreLider): ?string
{
    if (!in_array(mb_strtoupper(trim((string)$zona), 'UTF-8'), SGS_ZONAS_CUADRILLA_POR_AREA, true)) return null;
    preg_match_all('/(?<![A-Z0-9])SG[IA](?![A-Z0-9])/', mb_strtoupper((string)$nombreLider, 'UTF-8'), $m);
    $areas = array_unique($m[0]);
    return count($areas) === 1 ? $areas[0] : null;
}

/** Tipos de supervisión que NO atiende una cuadrilla SGI (averías, postventa, reiteradas) o SGA (instalaciones). */
function sgsCuadrillaNoAtiende(string $area): array
{
    return array_values(array_diff(sgsClasificacionesFueraDeArea(SGS_AREA_CUADRILLA[$area]), SGS_CUADRILLA_AMBAS_AREAS));
}

/** ¿La cuadrilla atiende esa clasificación? */
function sgsCuadrillaAtiende(?string $zona, ?string $nombreLider, ?string $clasificacion): bool
{
    $area = sgsAreaCuadrilla($zona, $nombreLider);
    return $area === null || !in_array((string)$clasificacion, sgsCuadrillaNoAtiende($area), true);
}

/** Condición SQL "cuadrilla del área del usuario" (zona y nombre del técnico líder). Sin área de trabajo: todas. */
function sgsCuadrillaAreaSql(string $zonaSql, string $nombreLiderSql, int $userId): string
{
    $area = array_search(sgsUserArea($userId), SGS_AREA_CUADRILLA, true);
    if ($area === false) return '1=1';
    $otra = $area === 'SGI' ? 'SGA' : 'SGI';
    $zonas = "'" . implode("','", SGS_ZONAS_CUADRILLA_POR_AREA) . "'";
    $nombre = "UPPER(COALESCE({$nombreLiderSql}, ''))";
    return "NOT (UPPER(COALESCE({$zonaSql}, '')) IN ({$zonas}) AND {$nombre} REGEXP '(^|[^A-Z0-9]){$otra}([^A-Z0-9]|$)'"
        . " AND {$nombre} NOT REGEXP '(^|[^A-Z0-9]){$area}([^A-Z0-9]|$)')";
}

/** Técnicos sin zona (2026-10-28, regla del usuario): solo los ve el administrador general (cuenta principal). */
function sgsVeTecnicosSinZona(array $user): bool
{
    return ($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR && Auth::esCuentaPrincipal((int)($user['id'] ?? 0));
}

/** Condición SQL "el técnico tiene zona" sobre la expresión de su departamento. */
function sgsTecnicoConZonaSql(string $departamento): string
{
    return "({$departamento} IS NOT NULL AND {$departamento} <> '')";
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
 * Un supervisor (2026-10-31) solo se ve a sí mismo, aunque tenga permiso de ver la matriz, el monitor o los dashboards.
 */
function sgsSupervisoresVisibles(array $viewer, array $supervisores): array
{
    $yo = (int)$viewer['id'];
    if (($viewer['rol'] ?? '') === Auth::ROLE_SUPERVISOR) return array_values(array_filter($supervisores, static fn($s) => (int)$s['id'] === $yo));
    if (!sgsAlcanceLimitado($viewer)) return $supervisores;
    sgsPrecargarUsuarios(array_merge([$yo], array_column($supervisores, 'id')));
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
    if (($viewer['rol'] ?? '') === Auth::ROLE_SUPERVISOR) return ' AND u.id = ' . (int)$viewer['id'];
    if (!sgsAlcanceLimitado($viewer)) return '';
    $ids = array_column(sgsSupervisoresVisibles($viewer, $pdo->query("SELECT id FROM usuarios WHERE rol = 'Supervisor' AND activo = 1")->fetchAll(PDO::FETCH_ASSOC)), 'id');
    return ' AND u.id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')';
}
