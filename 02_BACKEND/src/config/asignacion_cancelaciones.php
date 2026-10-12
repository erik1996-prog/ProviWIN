<?php
function normalizarZonaCancelacion(string $zona): string {
    $zona = mb_strtoupper(trim($zona), 'UTF-8');
    $zona = strtr($zona, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);
    $zona = preg_replace('/\s+/', ' ', $zona);
    return ['LA LIBERTAD'=>'TRUJILLO','LAMBAYEQUE'=>'CHICLAYO','ANCASH'=>'CHIMBOTE','SANTA'=>'CHIMBOTE','JUNIN'=>'HUANCAYO'][$zona] ?? $zona;
}

// La transacción y el bloqueo de supervisores serializan importaciones simultáneas.
function elegirSupervisorCancelacion(PDO $pdo, string $departamento): ?array {
    if (!$pdo->inTransaction()) throw new LogicException('La asignación requiere una transacción.');
    $candidates = supervisoresZonaCancelacion($pdo, $departamento, true);
    if (!$candidates) return null;
    $counts = $pdo->query("SELECT supervisor_id,COUNT(*) total FROM supervisiones WHERE estado_supervision IN ('Pendiente','Iniciada','En proceso') GROUP BY supervisor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    usort($candidates, fn($a,$b) => [(int)($counts[$a['id']] ?? 0),(int)$a['id']] <=> [(int)($counts[$b['id']] ?? 0),(int)$b['id']]);
    return $candidates[0];
}

/**
 * Supervisores directos que trabajan el departamento (zona base o adicional).
 * Si nadie lo tiene, responden los de alcance PROVINCIAS. Todos ellos ven la
 * pendiente en su lista (bolsa común) y reciben el aviso al importarla.
 */
function supervisoresZonaCancelacion(PDO $pdo, string $departamento, bool $bloquear = false): array {
    $stmt = $pdo->query("SELECT u.id,u.nombre_completo,u.zona,u.sede FROM usuarios u
        LEFT JOIN partners p ON p.id=u.partner_id
        WHERE u.rol='Supervisor' AND u.activo=1
          AND (u.partner_id IS NULL OR UPPER(TRIM(p.nombre_partner)) IN ('WIN','WIN DIRECTO'))
        ORDER BY u.id" . ($bloquear ? ' FOR UPDATE' : ''));
    $target = normalizarZonaCancelacion($departamento);
    if ($target === '') return [];
    $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
