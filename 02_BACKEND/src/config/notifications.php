<?php
declare(strict_types=1);

function observationBusinessDeadline(int $days = 3): string
{
    $deadline = new DateTimeImmutable('now');
    $added = 0;
    while ($added < max(1, $days)) {
        $deadline = $deadline->modify('+1 day');
        if ((int)$deadline->format('N') !== 7) $added++;
    }
    return $deadline->format('Y-m-d H:i:s');
}

function notifyPartnerObservation(PDO $pdo, int $observationId, int $supervisionId, int $partnerId, int $days, bool $immediate, bool $directPenalty = false): void
{
    if ($partnerId <= 0) return;
    // Jefe de operaciones del partner y supervisor asignado, no todos sus supervisores.
    $stmtUsers = $pdo->prepare("SELECT u.id FROM usuarios u
        WHERE u.partner_id=:partner AND u.activo=1
          AND (u.rol='Jefe de operaciones' OR (u.rol='Supervisor' AND EXISTS (
              SELECT 1 FROM supervisiones s WHERE s.id=:supervision AND s.supervisor_id=u.id
          )))");
    $stmtUsers->execute([':partner'=>$partnerId, ':supervision'=>$supervisionId]);
    $recipientIds = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    $title = $directPenalty ? 'Observación muy grave: penalidad directa' : ($immediate ? 'Subsanación inmediata requerida' : 'Nueva observación por regularizar');
    $deadline = $directPenalty ? 'fue clasificada como Muy Grave y pasó directamente a penalidad' : ($immediate ? 'requiere atención inmediata, antes de finalizar el día' : "debe regularizarse en {$days} día" . ($days === 1 ? '' : 's') . ' hábiles');
    $message = "Se registró una observación en la supervisión #{$supervisionId}; {$deadline}." . ($directPenalty ? '' : ' Si vence el plazo pasará a penalidad directa.');
    $stmt = $pdo->prepare("INSERT INTO notificaciones (usuario_id,observacion_id,titulo,mensaje,url_destino) VALUES (:uid,:oid,:titulo,:mensaje,'index.php?page=registro_observaciones')");
    foreach ($recipientIds as $recipientId) {
        $stmt->execute([':uid'=>(int)$recipientId,':oid'=>$observationId,':titulo'=>$title,':mensaje'=>$message]);
    }
}
