<?php
// 02_BACKEND/src/config/asignacion_cancelaciones.php - Cancelaciones pendientes: ticket, zona de asignación y supervisores
// de la zona. Lo usan la carga del Excel (api/cancelaciones_import.php) y la corrección de pendientes (api/supervisiones.php).
require_once __DIR__ . '/excel_lector.php'; // normalizeImportText

/** Código de seguimiento / ticket tal como se compara y se guarda: " 12345.0 " → "12345", "1.2345E+5" → "123450". */
function normalizarTicket(string $ticket): string {
    $ticket = mb_strtoupper(preg_replace('/\s+/u', '', $ticket), 'UTF-8');
    if (preg_match('/^\d+\.0+$/', $ticket)) $ticket = preg_replace('/\.0+$/', '', $ticket);
    elseif (preg_match('/^\d+(\.\d+)?E\+\d+$/', $ticket)) $ticket = sprintf('%.0f', (float)$ticket);
    return $ticket;
}

/** Zona de asignación de la pendiente (ciudad de la sede): por provincia, distrito o departamento. */
function zonaAsignacionCancelacion(string $departamento, string $provincia, string $distrito): string {
    $mapa = ['SANTA' => 'Chimbote', 'CHIMBOTE' => 'Chimbote', 'ANCASH' => 'Chimbote', 'LAMBAYEQUE' => 'Chiclayo', 'CHICLAYO' => 'Chiclayo',
        'LA LIBERTAD' => 'Trujillo', 'TRUJILLO' => 'Trujillo', 'AREQUIPA' => 'Arequipa', 'PIURA' => 'Piura', 'JUNIN' => 'Huancayo',
        'HUANCAYO' => 'Huancayo', 'CUSCO' => 'Cusco', 'LIMA' => 'Lima', 'CALLAO' => 'Callao', 'HUAURA' => 'Huacho', 'HUARAL' => 'Huacho', 'BARRANCA' => 'Huacho'];
    foreach ([$provincia, $distrito, $departamento] as $v) if (isset($mapa[normalizeImportText($v)])) return $mapa[normalizeImportText($v)];
    return $provincia !== '' ? ucfirst(strtolower($provincia)) : ($departamento !== '' ? ucfirst(strtolower($departamento)) : 'Provincias');
}

function normalizarZonaCancelacion(string $zona): string {
    $zona = mb_strtoupper(trim($zona), 'UTF-8');
    $zona = strtr($zona, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);
    $zona = preg_replace('/\s+/', ' ', $zona);
    return ['LA LIBERTAD'=>'TRUJILLO','LAMBAYEQUE'=>'CHICLAYO','ANCASH'=>'CHIMBOTE','SANTA'=>'CHIMBOTE','JUNIN'=>'HUANCAYO','NORTE CHICO'=>'HUACHO'][$zona] ?? $zona;
}

// La transacción y el bloqueo de supervisores serializan importaciones simultáneas.
function elegirSupervisorCancelacion(PDO $pdo, string $departamento, ?string $clasificacion = null): ?array {
    if (!$pdo->inTransaction()) throw new LogicException('La asignación requiere una transacción.');
    // Del área de la pendiente; si en la zona nadie es de esa área, cualquiera de la zona (la orden no queda sin dueño)
    $candidates = supervisoresZonaCancelacion($pdo, $departamento, true, $clasificacion) ?: supervisoresZonaCancelacion($pdo, $departamento, true);
    if (!$candidates) return null;
    $counts = $pdo->query("SELECT supervisor_id,COUNT(*) total FROM supervisiones WHERE estado_supervision IN ('Pendiente','Iniciada','En proceso') GROUP BY supervisor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    usort($candidates, fn($a,$b) => [(int)($counts[$a['id']] ?? 0),(int)$a['id']] <=> [(int)($counts[$b['id']] ?? 0),(int)$b['id']]);
    return $candidates[0];
}

/**
 * Supervisores directos que trabajan la zona ($departamento: zona de trabajo, p. ej. LIMA o NORTE CHICO; base o adicional).
 * Si nadie lo tiene, responden los de alcance PROVINCIAS. Todos ellos ven la
 * pendiente en su lista (bolsa común) y reciben el aviso al importarla.
 * Con $clasificacion (2026-10-27) solo los de esa área de trabajo (Instalaciones / Averías; "ambas" siempre entra).
 */
function supervisoresZonaCancelacion(PDO $pdo, string $departamento, bool $bloquear = false, ?string $clasificacion = null): array {
    $stmt = $pdo->query("SELECT u.id,u.nombre_completo,u.nombres,u.apellidos,u.zona,u.sede,u.area_trabajo FROM usuarios u
        LEFT JOIN partners p ON p.id=u.partner_id
        WHERE u.rol='Supervisor' AND u.activo=1
          AND (u.partner_id IS NULL OR UPPER(TRIM(p.nombre_partner)) IN ('WIN','WIN DIRECTO'))
        ORDER BY u.id" . ($bloquear ? ' FOR UPDATE' : ''));
    $target = normalizarZonaCancelacion($departamento);
    if ($target === '') return [];
    $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($clasificacion !== null) $supervisores = array_values(array_filter($supervisores, static fn($u) => !in_array($clasificacion, sgsClasificacionesFueraDeArea(trim((string)$u['area_trabajo'])), true)));
    // Zonas de trabajo = zona base (sede) + departamentos adicionales marcados en Usuarios y roles.
    $extras = [];
    foreach ($pdo->query("SELECT usuario_id, departamento FROM usuario_departamentos_acceso WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC) as $acc) {
        $extras[(int)$acc['usuario_id']][] = normalizarZonaCancelacion((string)$acc['departamento']);
    }
    $candidates = array_values(array_filter($supervisores, fn($u) =>
        normalizarZonaCancelacion((string)($u['sede'] ?: $u['zona'])) === $target
        || in_array($target, $extras[(int)$u['id']] ?? [], true)));
    // Un supervisor con alcance PROVINCIAS actúa como respaldo si el departamento
    // no tiene un responsable directo específico.
    if (!$candidates) {
        $candidates = array_values(array_filter($supervisores, fn($u) =>
            normalizarZonaCancelacion((string)($u['sede'] ?: $u['zona'])) === 'PROVINCIAS'));
    }
    return $candidates;
}
