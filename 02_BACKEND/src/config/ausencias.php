<?php
// 02_BACKEND/src/config/ausencias.php - Vacaciones, descansos médicos y licencias de los supervisores (2026-10-26).
// Tabla ausencias_supervisores. El supervisor directo las registra en "Mi turno"; quedan Pendientes hasta que
// administración, gerencia o coordinación las aprueba o rechaza (Monitor de turnos). Con una Aprobada que cubre el día,
// no puede iniciar turno (api/asistencia.php). El sustento (certificado médico, etc.) es un dato personal: se guarda en
// 11_PRIVADO/ausencias, bloqueada a la web, y solo se entrega por api/ausencias.php?action=sustento.
// La usan: api/ausencias.php y api/asistencia.php (inicio de turno y Monitor de turnos).

// 2026-10-28 (Matriz de asistencia): se agregan LSG, LG y Subsidio; "Otra licencia" pasó a "Licencia con goce de haber".
const SGS_TIPOS_AUSENCIA = ['Vacaciones', 'Descanso médico', 'Subsidio', 'Licencia por paternidad', 'Licencia por fallecimiento',
    'Licencia con goce de haber', 'Licencia sin goce de haber'];
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
