<?php
// 05_TEST/integration/productividad_test.php - Meta de productividad mensual (dashboard: action=productividad_mensual).
// Crea un supervisor directo temporal (DNI 99999908) con turnos y supervisiones en un mes pasado fijo, consulta la API
// y BORRA todo al final. Uso (solo CLI): php 05_TEST/integration/productividad_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$_SESSION = [];
require "$root/02_BACKEND/src/config/config.php";
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

// Mes de prueba: septiembre 2026 (pasado y completo). Lunes a sábado = 26 días (4 domingos: 6, 13, 20, 27).
$cleanup = function () use ($pdo) {
    $id = $pdo->query("SELECT id FROM usuarios WHERE dni='99999908'")->fetchColumn();
    if (!$id) return;
    $pdo->prepare("DELETE FROM supervisiones WHERE supervisor_id=:u AND codigo_supervision LIKE 'SUP-PROD-%'")->execute([':u' => $id]);
    $pdo->prepare("DELETE FROM asistencia_supervisores WHERE supervisor_id=:u")->execute([':u' => $id]);
    $pdo->prepare("DELETE FROM usuarios WHERE id=:u")->execute([':u' => $id]);
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$r = (new UsuarioService($pdo))->guardar(['dni' => '99999908', 'nombres' => 'Productividad', 'apellidos' => 'Prueba SGS', 'email' => 'productividad.prueba@proviwin.test',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => 'ProdPrueba1234'], 0, $admin);
$uid = (int)$r['id'];
// Turnos: domingo 2026-09-06 y lunes 2026-09-07. Supervisiones finalizadas: 5 el domingo + 4 el lunes = 9. Una "En proceso" no cuenta.
$turno = $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, fin_turno, estado_turno, fecha_registro) VALUES (:u, :f, :i, :fin, 'Fuera de turno', :reg)");
foreach (['2026-09-06', '2026-09-07'] as $f) $turno->execute([':u' => $uid, ':f' => $f, ':i' => "$f 08:00:00", ':fin' => "$f 17:00:00", ':reg' => "$f 08:00:00"]);
$sup = $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, departamento, provincia, distrito, tipo_supervision_inicial, estado_supervision) VALUES (:c, :f, :u, 'PIURA', 'PIURA', 'PIURA', 'En caliente', :e)");
$n = 0;
foreach ([['2026-09-06', 5, 'Finalizada'], ['2026-09-07', 4, 'Finalizada'], ['2026-09-07', 1, 'En proceso']] as [$f, $k, $e])
    for ($i = 0; $i < $k; $i++) $sup->execute([':c' => sprintf('SUP-PROD-%04d', ++$n), ':f' => "$f 10:" . sprintf('%02d', $i) . ':00', ':u' => $uid, ':e' => $e]);

$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/05_TEST/api/call_api.php") . ' dashboard "action=productividad_mensual&mes=9&anio=2026" Administrador 2>&1');
$j = json_decode(trim(preg_replace('/@@HTTP \d+@@/', '', (string)$out)), true);
$fila = null;
foreach ($j['data']['supervisores'] ?? [] as $s) if ((int)$s['supervisor_id'] === $uid) $fila = $s;

$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' (obtenido: ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ')') . "\n"; $cond ? $ok++ : $fail++; };
echo "Meta de productividad — septiembre 2026\n";
$check('meta diaria = 4.5', (float)($j['data']['meta_diaria'] ?? 0) === 4.5, $j['data']['meta_diaria'] ?? null);
$check('días hábiles del mes (lunes a sábado) = 26', ($j['data']['dias_habiles_mes'] ?? 0) === 26, $j['data']['dias_habiles_mes'] ?? null);
$check('solo cuentan las finalizadas (9 de 10)', ($fila['finalizadas'] ?? null) === 9, $fila['finalizadas'] ?? null);
$check('días trabajados = días con turno (2)', ($fila['dias_trabajados'] ?? null) === 2, $fila['dias_trabajados'] ?? null);
$check('detecta el domingo trabajado (1)', ($fila['domingos_trabajados'] ?? null) === 1, $fila['domingos_trabajados'] ?? null);
$check('promedio por día trabajado = 4.5', (float)($fila['promedio_dia_trabajado'] ?? 0) === 4.5, $fila['promedio_dia_trabajado'] ?? null);
$check('estado "cumple"', ($fila['estado'] ?? '') === 'cumple', $fila['estado'] ?? null);
$check('meta del mes = 4.5 × (26 hábiles + 1 domingo trabajado) = 121.5', (float)($fila['meta_a_la_fecha'] ?? 0) === 121.5, $fila['meta_a_la_fecha'] ?? null);
$check('avance = 9 / 121.5 = 7 %', ($fila['avance_pct'] ?? null) === 7, $fila['avance_pct'] ?? null);

$cleanup();
echo "\n$ok correctas, $fail con error. Datos de prueba eliminados.\n";
exit($fail ? 1 : 0);
