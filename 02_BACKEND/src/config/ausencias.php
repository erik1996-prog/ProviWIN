<?php
// 02_BACKEND/src/config/ausencias.php - Vacaciones, descansos médicos y licencias de los supervisores (2026-10-26).
// Tabla ausencias_supervisores. El supervisor directo las registra en "Mi turno"; quedan Pendientes hasta que
// administración, gerencia o coordinación las aprueba o rechaza (Monitor de turnos). Con una Aprobada que cubre el día,
// no puede iniciar turno (api/asistencia.php). El sustento (certificado médico, etc.) es un dato personal: se guarda en
// 11_PRIVADO/ausencias, bloqueada a la web, y solo se entrega por api/ausencias.php?action=sustento.
// La usan: api/ausencias.php y api/asistencia.php (inicio de turno y Monitor de turnos).

// Lo que registra el supervisor (2026-10-29, regla del usuario): solo estos cuatro. La licencia con goce, sin goce y el
// subsidio siguen en la base y en la Matriz de asistencia (el administrador los marca ahí), pero el supervisor no los pide.
const SGS_TIPOS_AUSENCIA = ['Vacaciones', 'Descanso médico', 'Licencia por paternidad', 'Licencia por fallecimiento'];
const SGS_AUSENCIA_MAX_DIAS = 60;      // una sola solicitud cubre a lo más 60 días
const SGS_AUSENCIA_DIAS_ATRAS = 30;    // se puede registrar hasta 30 días hacia atrás (el descanso médico suele llegar después)

/** Carpeta privada de los sustentos; la crea bloqueada a la web aunque el .htaccess principal no esté al día. */
function sgsCarpetaAusencias(): string {
    $dir = BASE_PATH . '/11_PRIVADO/ausencias';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
        @file_put_contents(BASE_PATH . '/11_PRIVADO/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/** Ausencia Aprobada que cubre la fecha (o null). */
function sgsAusenciaAprobada(PDO $pdo, int $supervisorId, string $fecha): ?array {
    $st = $pdo->prepare("SELECT id, tipo, fecha_inicio, fecha_fin FROM ausencias_supervisores
        WHERE supervisor_id = :s AND estado = 'Aprobada' AND fecha_inicio <= :f1 AND fecha_fin >= :f2 ORDER BY fecha_inicio LIMIT 1");
    $st->execute([':s' => $supervisorId, ':f1' => $fecha, ':f2' => $fecha]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Vacaciones que dejarían zonas sin supervisor (2026-10-29, regla del usuario). Las vacaciones pendientes o aprobadas ya ocupan
 * sus días (el primero que pide). En un mismo día:
 *   - dos supervisores de la misma zona no coinciden;
 *   - a lo más una zona queda sin supervisor (Arequipa y Cusco, con uno cada una, no a la vez; Lima, con dos, sí con otra).
 * Solo se revisan las vacaciones: el descanso médico, la paternidad y el fallecimiento no se planifican. Devuelve el motivo
 * para el supervisor, o null si puede pedirlas. Un supervisor de "todas las zonas" no deja ninguna vacía.
 */
function sgsChoqueVacaciones(PDO $pdo, int $supervisorId, string $ini, string $fin): ?string {
    $ids = $pdo->query("SELECT u.id FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id
        WHERE u.rol = 'Supervisor' AND u.activo = 1 AND " . sgsDirectStaffSql())->fetchAll(PDO::FETCH_COLUMN);
    $datos = sgsPrecargarUsuarios(array_merge($ids, [$supervisorId]));
    $misZonas = $datos[$supervisorId]['zonas'] ?? null;
    if (!$misZonas) return null;
    $porZona = []; // zona => supervisores que la trabajan
    foreach ($ids as $id) foreach ($datos[(int)$id]['zonas'] ?? [] as $z) $porZona[$z][] = (int)$id;
    $st = $pdo->prepare("SELECT a.supervisor_id, a.fecha_inicio, a.fecha_fin, u.nombre_completo FROM ausencias_supervisores a JOIN usuarios u ON u.id = a.supervisor_id
        WHERE a.tipo = 'Vacaciones' AND a.estado IN ('Pendiente', 'Aprobada') AND a.supervisor_id <> :s AND u.activo = 1 AND a.fecha_inicio <= :fin AND a.fecha_fin >= :ini");
    $st->execute([':s' => $supervisorId, ':ini' => $ini, ':fin' => $fin]);
    $otras = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$otras) return null;
    $nombre = static fn(array $a) => $a['nombre_completo'] . ' (' . implode(', ', $datos[(int)$a['supervisor_id']]['zonas'] ?? ['todas las zonas']) . ')';
    $dia = static fn(string $f) => date('d/m', strtotime($f));
    for ($d = $ini; $d <= $fin; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $fuera = array_values(array_filter($otras, static fn($a) => $a['fecha_inicio'] <= $d && $a['fecha_fin'] >= $d));
        if (!$fuera) continue;
        foreach ($fuera as $a) {
            if (array_intersect($datos[(int)$a['supervisor_id']]['zonas'] ?? [], $misZonas)) {
                return "{$a['nombre_completo']}, de su misma zona, ya tiene vacaciones el {$dia($d)}. Elija otras fechas.";
            }
        }
        $ausentes = array_merge([$supervisorId], array_map('intval', array_column($fuera, 'supervisor_id')));
        $vacias = array_keys(array_filter($porZona, static fn($sups) => !array_diff($sups, $ausentes)));
        if (count($vacias) > 1) {
            return "El {$dia($d)} ya está de vacaciones " . implode(' y ', array_map($nombre, $fuera)) . ': quedarían ' . count($vacias)
                . ' zonas sin supervisor (' . implode(', ', $vacias) . '). Elija otras fechas.';
        }
    }
    return null;
}

/** Texto para el supervisor: "Vacaciones del 27/10 al 31/10". */
function sgsTextoAusencia(array $a): string {
    $f = static fn(string $d) => date('d/m', strtotime($d));
    return $a['tipo'] . ($a['fecha_inicio'] === $a['fecha_fin'] ? ' el ' . $f($a['fecha_inicio']) : ' del ' . $f($a['fecha_inicio']) . ' al ' . $f($a['fecha_fin']));
}

/** Avisa en la campana a los usuarios indicados (activos). */
function sgsAvisarAusencia(PDO $pdo, array $usuarios, string $titulo, string $mensaje, string $url): void {
    $st = $pdo->prepare('INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:u, :t, :m, :url)');
    foreach (array_unique(array_map('intval', $usuarios)) as $u) if ($u > 0) $st->execute([':u' => $u, ':t' => $titulo, ':m' => $mensaje, ':url' => $url]);
}
