<?php
// 02_BACKEND/src/config/turnos.php - Cierre automático de turnos olvidados.
// Regla (2026-10-08): un turno que el supervisor no finalizó se cierra a las 23:59 del día en que se abrió.
// Se ejecuta (1) como tarea periódica cuando alguien usa el sistema y (2) desde 07_SCRIPTS/cerrar_turnos.php
// programado a las 23:59. El cálculo de horas es el mismo del cierre manual (api/asistencia.php, action=end).

function sgsCerrarTurnosVencidos(PDO $pdo): int
{
    $pendientes = $pdo->query("SELECT id, supervisor_id, fecha, inicio_turno, observaciones FROM asistencia_supervisores
        WHERE fin_turno IS NULL AND (fecha < CURDATE() OR NOW() >= CONCAT(fecha, ' 23:59:00'))")->fetchAll(PDO::FETCH_ASSOC);
    $cerrados = 0;
    foreach ($pendientes as $a) {
        $cierre = $a['fecha'] . ' 23:59:00';
        $pdo->beginTransaction();
        try {
            // Pausas abiertas: terminan al cierre del turno
            $pdo->prepare("UPDATE asistencia_eventos SET fin_evento = :c, duracion_minutos = GREATEST(0, TIMESTAMPDIFF(MINUTE, inicio_evento, :c2))
                WHERE asistencia_id = :id AND fin_evento IS NULL")->execute([':c' => $cierre, ':c2' => $cierre, ':id' => $a['id']]);
            $st = $pdo->prepare("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, inicio_evento, fin_evento)), 0) FROM asistencia_eventos WHERE asistencia_id = :id AND tipo_evento = 'almuerzo'");
            $st->execute([':id' => $a['id']]);
            $minAlmuerzo = (int)$st->fetchColumn();

            $total = max(0, (strtotime($cierre) - strtotime($a['inicio_turno'])) / 3600);
            $horasAlmuerzo = round($minAlmuerzo / 60, 2);
            if ($horasAlmuerzo < 0.5 && $total > 6) $horasAlmuerzo = 1.0;   // mismo criterio que el cierre manual
            $trabajadas = max(0, round($total - $horasAlmuerzo, 2));
            $extras = max(0, round($trabajadas - 8.0, 2));
            $nota = trim(($a['observaciones'] ?? '') . "\nCierre automático a las 23:59: el supervisor no finalizó su turno.");

            $upd = $pdo->prepare("UPDATE asistencia_supervisores SET fin_turno = :c, horas_trabajadas = :t, horas_almuerzo = :al, horas_extras = :ex,
                estado_turno = 'Fuera de turno', observaciones = :obs WHERE id = :id AND fin_turno IS NULL");
            $upd->execute([':c' => $cierre, ':t' => $trabajadas, ':al' => $horasAlmuerzo, ':ex' => $extras, ':obs' => $nota, ':id' => $a['id']]);
            $pdo->commit();
            if ($upd->rowCount()) {
                $cerrados++;
                win_log_error('audit_shift_autoclosed', 'Turno cerrado automáticamente a las 23:59', ['asistencia_id' => (int)$a['id'], 'target_id' => (int)$a['supervisor_id'], 'fecha' => $a['fecha']]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            win_log_error('shift_autoclose_error', $e->getMessage(), ['asistencia_id' => (int)$a['id']]);
        }
    }
    return $cerrados;
}
