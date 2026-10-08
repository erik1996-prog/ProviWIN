<?php
// 05_TEST/integration/cierre_turnos_test.php - Cierre automático de turnos olvidados a las 23:59.
// Crea dos supervisores temporales (DNI 99999910 y 99999911) con turnos de prueba y BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/cierre_turnos_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/config/turnos.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$dnis = ['99999910', '99999911'];
$cleanup = function () use ($pdo, $dnis) {
    foreach ($dnis as $d) {
        $id = $pdo->query("SELECT id FROM usuarios WHERE dni='$d'")->fetchColumn();
        if (!$id) continue;
        $pdo->exec("DELETE e FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $id");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = $id");
        $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    }
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$svc = new UsuarioService($pdo); $ids = [];
foreach ($dnis as $i => $d) $ids[] = (int)$svc->guardar(['dni' => $d, 'nombres' => 'Turno', 'apellidos' => "Prueba $i", 'email' => "turno$i.prueba@proviwin.test",
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => 'TurnoPrueba1234'], 0, $admin)['id'];

$ayer = date('Y-m-d', strtotime('-1 day'));
// Turno de ayer: 08:00 a (sin cerrar), almuerzo 13:00 a 14:00 cerrado y una reunión 17:00 sin cerrar
$pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno, fecha_registro) VALUES (:u, :f, :i, 'En reunión', :i2)")
    ->execute([':u' => $ids[0], ':f' => $ayer, ':i' => "$ayer 08:00:00", ':i2' => "$ayer 08:00:00"]);
$asistAyer = (int)$pdo->lastInsertId();
$ev = $pdo->prepare("INSERT INTO asistencia_eventos (asistencia_id, tipo_evento, inicio_evento, fin_evento) VALUES (:a, :t, :i, :f)");
$ev->execute([':a' => $asistAyer, ':t' => 'almuerzo', ':i' => "$ayer 13:00:00", ':f' => "$ayer 14:00:00"]);
$ev->execute([':a' => $asistAyer, ':t' => 'reunion', ':i' => "$ayer 17:00:00", ':f' => null]);
// Turno de hoy, en curso desde hace una hora
$pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno, fecha_registro) VALUES (:u, CURDATE(), NOW() - INTERVAL 1 HOUR, 'En turno', NOW())")->execute([':u' => $ids[1]]);
$asistHoy = (int)$pdo->lastInsertId();

$n = sgsCerrarTurnosVencidos($pdo);
$a = $pdo->query("SELECT * FROM asistencia_supervisores WHERE id = $asistAyer")->fetch();
$h = $pdo->query("SELECT * FROM asistencia_supervisores WHERE id = $asistHoy")->fetch();
$reunion = $pdo->query("SELECT fin_evento FROM asistencia_eventos WHERE asistencia_id = $asistAyer AND tipo_evento = 'reunion'")->fetchColumn();

$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Cierre automático de turnos a las 23:59\n";
$check('cerró al menos el turno olvidado de ayer', $n >= 1, $n);
$check('fin del turno de ayer = 23:59', $a['fin_turno'] === "$ayer 23:59:00", $a['fin_turno']);
$check('quedó "Fuera de turno"', $a['estado_turno'] === 'Fuera de turno', $a['estado_turno']);
$check('la reunión abierta también se cerró a las 23:59', $reunion === "$ayer 23:59:00", $reunion);
$check('horas trabajadas = 14.98 h (08:00–23:59 menos 1 h de almuerzo)', (float)$a['horas_trabajadas'] === 14.98, $a['horas_trabajadas']);
$check('horas extra = 6.98 h', (float)$a['horas_extras'] === 6.98, $a['horas_extras']);
$check('deja nota de cierre automático', str_contains((string)$a['observaciones'], 'Cierre automático'), $a['observaciones']);
$check('el turno de hoy en curso NO se toca', $h['fin_turno'] === null, $h['fin_turno']);

$cleanup();
echo "\n$ok correctas, $fail con error. Datos de prueba eliminados.\n";
exit($fail ? 1 : 0);
