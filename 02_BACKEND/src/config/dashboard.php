<?php
// 02_BACKEND/src/config/dashboard.php - Piezas comunes de los dashboards (2026-10-20), para no repetir código:
// filtros (fechas, partner, zona y zona del usuario), consultas agregadas sobre una misma base, tabla por páginas y
// descarga a Excel. Las usan api/examenes.php (Exámenes) y api/dashboard_pendientes.php (Pendientes y rescates).
// Los totales se calculan en la base de datos (GROUP BY): al navegador solo viajan números y 50 filas por página.

/**
 * Filtros comunes leídos de $_GET (desde, hasta, partner_id, zona). $col: expresiones SQL de 'fecha', 'partner' y 'zona'.
 * El supervisor directo solo ve su zona (y lo que aún no tiene zona). Devuelve [where[], params[]].
 */
function sgsFiltrosDashboard(array $col, array $usuario, bool $esAdmin): array {
    $where = ['1=1']; $params = [];
    $fecha = static fn(string $v): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    if ($d = $fecha((string)($_GET['desde'] ?? ''))) { $where[] = "{$col['fecha']} >= :desde"; $params[':desde'] = $d . ' 00:00:00'; }
    if ($h = $fecha((string)($_GET['hasta'] ?? ''))) { $where[] = "{$col['fecha']} <= :hasta"; $params[':hasta'] = $h . ' 23:59:59'; }
    if (($pid = (int)($_GET['partner_id'] ?? 0)) > 0) { $where[] = "{$col['partner']} = :partner"; $params[':partner'] = $pid; }
    $zona = mb_substr(trim((string)($_GET['zona'] ?? '')), 0, 100);
    if ($zona === 'sin_zona') $where[] = "({$col['zona']} IS NULL OR {$col['zona']} = '')";
    elseif ($zona !== '') { $where[] = "{$col['zona']} = :zona"; $params[':zona'] = $zona; }
    if (!$esAdmin && sgsUserZones((int)$usuario['id']) !== null) {
        $zp = [];
        $where[] = '(' . sgsZoneSql($col['zona'], (int)$usuario['id'], $zp, ':uz') . " OR {$col['zona']} IS NULL OR {$col['zona']} = '')";
        $params += $zp;
    }
    return [$where, $params];
}

/** Consulta sobre la base del dashboard: fn(select, extra = '', mas = [], unir = '') → filas. extra va después del WHERE. */
function sgsConsultaDashboard(PDO $pdo, string $desde, array $where, array $params): Closure {
    return static function (string $select, string $extra = '', array $mas = [], string $unir = '') use ($pdo, $desde, $where, $params): array {
        $st = $pdo->prepare("SELECT {$select} {$desde} {$unir} WHERE " . implode(' AND ', $where) . " {$extra}");
        $st->execute($params + $mas);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
}

/** Filtro de la tabla: estado (uno de los grupos, valores fijos) y búsqueda q en $camposBusqueda. Devuelve [extra, mas]. */
function sgsFiltroTablaDashboard(string $estadoSql, array $grupos, array $camposBusqueda): array {
    $extra = ''; $mas = [];
    if (isset($grupos[$g = (string)($_GET['estado'] ?? '')])) $extra .= " AND ({$estadoSql}) IN ('" . implode("','", $grupos[$g]) . "')";
    if (($q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60)) !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $ors = [];
        foreach (array_values($camposBusqueda) as $i => $campo) { $ors[] = "{$campo} LIKE :q{$i}"; $mas[":q{$i}"] = $like; }
        $extra .= ' AND (' . implode(' OR ', $ors) . ')';
    }
    return [$extra, $mas];
}

/** Una página de la tabla (50 filas). Devuelve ['filas', 'total', 'pagina', 'paginas']. */
function sgsPaginaDashboard(Closure $consulta, string $columnas, string $extra, array $mas, string $orden, callable $fila, int $porPagina = 50): array {
    $total = (int)$consulta('COUNT(*) AS n', $extra, $mas)[0]['n'];
    $paginas = (int)ceil(max(1, $total) / $porPagina);
    $pagina = max(1, min((int)($_GET['pagina'] ?? 1), $paginas));
    $filas = array_map($fila, $consulta($columnas, "{$extra} ORDER BY {$orden} LIMIT {$porPagina} OFFSET " . (($pagina - 1) * $porPagina), $mas));
    return ['filas' => $filas, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

/** % redondeado sin dividir entre cero. */
function sgsPorcentaje(int|float $parte, int|float $total): int {
    return $total > 0 ? (int)round($parte * 100 / $total) : 0;
}

/** Envía un Excel de una hoja (primera fila = encabezados) y termina. */
function sgsEnviarXlsx(array $hoja, string $nombreHoja, string $archivo): never {
    require_once __DIR__ . '/tecnicos_lideres.php'; // sgsEscribirXlsx
    $bin = sgsEscribirXlsx($hoja, $nombreHoja);
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $archivo . '_' . date('Ymd_Hi') . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}
