<?php
// 02_BACKEND/src/config/lluvias.php - Lluvia por zona (2026-10-20). Tabla lluvias_zona.
// El supervisor directo avisa desde "Mi turno" que llueve en su zona y luego que paró. La lluvia NO cambia su actividad
// (puede llover hasta en plena supervisión): al avisar y al cerrar se anota solo qué estaba haciendo cada uno. Una sola
// lluvia abierta por zona: inicio = el primero que la avisó, fin = el que la cerró. Si nadie la cierra, se cierra sola
// al terminar el turno quien la avisó, o al final de su día (23:59:59) si quedó abierta de un día anterior.
// La usan: api/lluvias.php (Mi turno), la Línea de tiempo (api/asistencia.php) y el indicador api/dashboard_lluvias.php.
require_once __DIR__ . '/territorial.php';
require_once __DIR__ . '/turnos.php';

/** Zonas donde el supervisor puede avisar lluvia: las suyas; si trabaja todas las provincias, todas. */
function sgsZonasLluvia(int $usuarioId): array {
    return sgsUserZones($usuarioId) ?? sgsOperationalDepartments();
}

/** Qué está haciendo ahora el supervisor (comentario de la lluvia): su supervisión en curso, su actividad o su turno. */
function sgsQueHaceSupervisor(PDO $pdo, int $usuarioId): string {
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(ticket_codigo, ''), codigo_supervision) FROM supervisiones
        WHERE supervisor_id = ? AND estado_supervision IN ('Iniciada', 'En proceso') AND fecha_registro >= CURRENT_DATE()
        ORDER BY fecha_registro DESC LIMIT 1");
    $st->execute([$usuarioId]);
    if ($ticket = $st->fetchColumn()) return "Supervisando {$ticket}";
    $turno = sgsTurnoAbiertoHoy($pdo, $usuarioId);
    if (!$turno) return 'Fuera de turno';
    $actividad = sgsActividadAbierta($pdo, (int)$turno['id']);
    return $actividad ? 'En ' . $actividad['nombre'] : 'En turno';
}

/** Cierra al final de su día las lluvias que quedaron abiertas de días anteriores. */
function sgsCerrarLluviasViejas(PDO $pdo): void {
    $pdo->exec("UPDATE lluvias_zona SET fin = CONCAT(DATE(inicio), ' 23:59:59'), comentario_fin = 'Cierre automático al final del día'
        WHERE fin IS NULL AND inicio < CURRENT_DATE()");
}

/** Lluvias abiertas ahora en estas zonas: [departamento => fila]. */
function sgsLluviasAbiertas(PDO $pdo, array $zonas): array {
    if (!$zonas) return [];
    sgsCerrarLluviasViejas($pdo);
    $marcas = implode(',', array_fill(0, count($zonas), '?'));
    $st = $pdo->prepare("SELECT l.id, l.departamento, l.inicio, u.nombre_completo AS reportado_por
        FROM lluvias_zona l JOIN usuarios u ON u.id = l.reportado_por WHERE l.fin IS NULL AND l.departamento IN ({$marcas})");
    $st->execute(array_values($zonas));
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'departamento');
}

/** Avisa que llueve en la zona. Si ya hay una lluvia abierta ahí, devuelve esa (no se duplica). */
function sgsAvisarLluvia(PDO $pdo, int $usuarioId, string $zona): array {
    $abierta = sgsLluviasAbiertas($pdo, [$zona])[$zona] ?? null;
    if ($abierta) return ['nueva' => false, 'lluvia' => $abierta];
    $pdo->prepare('INSERT INTO lluvias_zona (departamento, inicio, reportado_por, comentario_inicio) VALUES (?, NOW(), ?, ?)')
        ->execute([$zona, $usuarioId, sgsQueHaceSupervisor($pdo, $usuarioId)]);
    return ['nueva' => true, 'lluvia' => sgsLluviasAbiertas($pdo, [$zona])[$zona] ?? null];
}

/** Marca que paró la lluvia de la zona. Devuelve true si había una abierta. */
function sgsTerminarLluvia(PDO $pdo, int $usuarioId, string $zona): bool {
    $st = $pdo->prepare('UPDATE lluvias_zona SET fin = NOW(), cerrado_por = ?, comentario_fin = ? WHERE fin IS NULL AND departamento = ?');
    $st->execute([$usuarioId, sgsQueHaceSupervisor($pdo, $usuarioId), $zona]);
    return $st->rowCount() > 0;
}

/** Al terminar su turno se cierran las lluvias que avisó y siguen abiertas. */
function sgsCerrarLluviasDe(PDO $pdo, int $usuarioId): void {
    $pdo->prepare("UPDATE lluvias_zona SET fin = NOW(), cerrado_por = reportado_por, comentario_fin = 'Cierre automático al terminar su turno'
        WHERE fin IS NULL AND reportado_por = ?")->execute([$usuarioId]);
}

/**
 * Lluvias que tocan la fecha: [departamento => [[ts_inicio, ts_fin|null, avisó, comentario_inicio, cerró, comentario_fin], …]].
 * ts_fin null = sigue lloviendo (hoy). Las horas se recortan al día pedido.
 */
function sgsLluviasDelDia(PDO $pdo, string $fecha): array {
    sgsCerrarLluviasViejas($pdo);
    $st = $pdo->prepare("SELECT l.departamento, l.inicio, l.fin, ua.nombre_completo AS aviso, l.comentario_inicio, uc.nombre_completo AS cerro, l.comentario_fin
        FROM lluvias_zona l JOIN usuarios ua ON ua.id = l.reportado_por LEFT JOIN usuarios uc ON uc.id = l.cerrado_por
        WHERE l.inicio < DATE_ADD(:f1, INTERVAL 1 DAY) AND (l.fin IS NULL OR l.fin >= :f2) ORDER BY l.inicio");
    $st->execute([':f1' => $fecha, ':f2' => $fecha]);
    $diaIni = strtotime($fecha . ' 00:00:00'); $diaFin = strtotime($fecha . ' 23:59:59');
    $porZona = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $porZona[mb_strtoupper(trim($l['departamento']), 'UTF-8')][] = [max($diaIni, strtotime($l['inicio'])), $l['fin'] ? min($diaFin, strtotime($l['fin'])) : null,
            $l['aviso'], $l['comentario_inicio'], $l['cerro'], $l['comentario_fin']];
    }
    return $porZona;
}

/**
 * Lluvias que le tocan al supervisor, ordenadas: las de sus zonas. Si trabaja todas las provincias, las de las zonas
 * donde supervisó ese día ($zonasDelDia), para no llenarle la fila con la lluvia de todo el país.
 */
function sgsLluviasDelSupervisor(array $lluviasDelDia, int $usuarioId, array $zonasDelDia): array {
    $zonas = sgsUserZones($usuarioId) ?? array_map(static fn($z) => mb_strtoupper(trim((string)$z), 'UTF-8'), $zonasDelDia);
    $tramos = [];
    foreach ($lluviasDelDia as $zona => $lista) {
        if (!in_array($zona, $zonas, true)) continue;
        foreach ($lista as [$ini, $fin, $aviso, $comIni, $cerro, $comFin]) {
            $tramos[] = ['ts_inicio' => $ini, 'ts_fin' => $fin, 'zona' => $zona, 'aviso' => $aviso, 'comentario_inicio' => $comIni, 'cerro' => $cerro, 'comentario_fin' => $comFin];
        }
    }
    usort($tramos, static fn($a, $b) => $a['ts_inicio'] <=> $b['ts_inicio']);
    return $tramos;
}
