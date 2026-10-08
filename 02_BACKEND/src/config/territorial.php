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
    ];
    return $map[mb_strtoupper(trim($department), 'UTF-8')] ?? '';
}

/** Departamento real de un nombre de ubigeo (corrige alias heredados: HUANCAYO como departamento = JUNÍN). */
function sgsDepartmentAlias(string $name): string
{
    $n = mb_strtoupper(trim($name), 'UTF-8');
    return ['HUANCAYO' => 'JUNÍN', 'CHIMBOTE' => 'ÁNCASH', 'TRUJILLO' => 'LA LIBERTAD', 'CHICLAYO' => 'LAMBAYEQUE'][$n] ?? $n;
}

/** Zonas de trabajo = departamentos donde se registran supervisiones (tabla ubigeo, la misma del formulario). */
function sgsOperationalDepartments(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = Database::getConnection()->query('SELECT DISTINCT departamento FROM ubigeo')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        win_log_error('zonas_ubigeo_error', $e->getMessage());
        $rows = ['ÁNCASH', 'AREQUIPA', 'CUSCO', 'JUNÍN', 'LA LIBERTAD', 'LAMBAYEQUE', 'PIURA'];
    }
    $deps = array_values(array_unique(array_filter(array_map('sgsDepartmentAlias', $rows))));
    $key = static fn($d) => strtr($d, ['Á' => 'A', 'Í' => 'I']);
    usort($deps, static fn($a, $b) => strcmp($key($a), $key($b)));
    return $cache = $deps;
}
