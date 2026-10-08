<?php
// 05_TEST/integration/volumen_bd_prueba.php - Rendimiento con volumen realista en una BD DE PRUEBA APARTE (solo CLI).
// Crea win_telecom_db_carga (solo estructura copiada de la real, sin datos personales), la llena con
// 100 supervisores y ~1 año de trabajo, mide las consultas críticas llamando a la API real y BORRA la BD al final.
// Uso: php 05_TEST/integration/volumen_bd_prueba.php [supervisores=100] [dias=365]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sup = (int)($argv[1] ?? 100); $dias = (int)($argv[2] ?? 365);
$root = dirname(__DIR__, 2);
$mysql = 'C:/xampp/mysql/bin/mysql.exe'; $dump = 'C:/xampp/mysql/bin/mysqldump.exe';
$test = 'win_telecom_db_carga';

echo "1) Creando BD de prueba $test (estructura de win_telecom_db, sin datos)...\n";
shell_exec("\"$mysql\" -u root -e \"DROP DATABASE IF EXISTS $test; CREATE DATABASE $test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"");
shell_exec("\"$dump\" -u root --no-data --skip-triggers win_telecom_db | \"$mysql\" -u root $test");
$pdo = new PDO("mysql:host=127.0.0.1;dbname=$test;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

echo "2) Generando datos: $sup supervisores, $dias días...\n";
$t = microtime(true);
$pdo->exec("INSERT INTO partners (id, nombre_partner, estado) VALUES (1,'WIN DIRECTO',1),(2,'CONTRATA A',1),(3,'CONTRATA B',1)");
$sedes = ['TRUJILLO','CHICLAYO','PIURA','AREQUIPA','CUSCO','HUANCAYO','CHIMBOTE','ICA'];
$deps = ['LA LIBERTAD','LAMBAYEQUE','PIURA','AREQUIPA','CUSCO','JUNÍN','ÁNCASH','ICA'];
$hash = password_hash('x', PASSWORD_DEFAULT);
$ins = $pdo->prepare("INSERT INTO usuarios (id,dni,nombres,apellidos,nombre_completo,usuario,email,password_hash,rol,partner_id,sede,zona,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)");
$ins->execute([1, '90000000', 'Admin', 'Carga', 'Admin Carga', 'admin.carga', 'admin@carga.test', $hash, 'Administrador', 1, 'TRUJILLO', 'TRUJILLO']);
for ($i = 1; $i <= $sup + 20; $i++) {
    $partner = $i <= $sup ? 1 : ($i % 2 ? 2 : 3);
    $s = $sedes[$i % 8];
    $ins->execute([$i + 1, sprintf('9%07d', $i), "Sup$i", 'Carga', "Supervisor Carga $i", "sup$i", "sup$i@carga.test", $hash, 'Supervisor', $partner, $s, $s]);
}
$pdo->beginTransaction();
$insS = $pdo->prepare("INSERT INTO supervisiones (codigo_supervision,supervisor_id,partner_id,derivacion_orden,departamento,provincia,distrito,tipo_supervision_inicial,clasificacion_supervision,area_servicio,estado_supervision,fecha_registro,fecha_asignacion,estado_atencion_partner,fecha_atencion_partner,tecnico1_nombre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$insA = $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id,fecha,inicio_turno,fin_turno,estado_turno,horas_trabajadas) VALUES (?,?,?,?,?,?)");
$n = 0; $nt = 0; $hoy = strtotime('today');
for ($d = $dias; $d >= 0; $d--) {
    $fecha = date('Y-m-d', $hoy - $d * 86400);
    if (date('N', strtotime($fecha)) == 7) continue;
    for ($i = 1; $i <= $sup; $i++) {
        $uid = $i + 1; $dep = $deps[$i % 8];
        $fin = $d === 0 ? null : "$fecha 18:00:00";
        $insA->execute([$uid, $fecha, "$fecha 08:0" . ($i % 10) . ":00", $fin, $d === 0 ? 'En turno' : 'Fuera de turno', $d === 0 ? null : 9.5]); $nt++;
        $porDia = ($i + $d) % 3; // 0..2 supervisiones por día
        for ($k = 0; $k < $porDia; $k++) {
            $pend = ($d < 3 && $k === 0);
            $asignada = $pend && $i % 4 === 0;
            $insS->execute(['C' . $n, $asignada ? $sup + 2 + ($i % 20) : $uid, $asignada ? 2 + ($i % 2) : 1, 'Autogestión', $dep, $dep, "DIST$k", 'En caliente', 'Instalación', 'Instalación',
                $pend ? 'Pendiente' : 'Finalizada', "$fecha 1" . $k . ":30:00", $asignada ? "$fecha 09:00:00" : null, 'Pendiente', null, "Tecnico $i-$k"]);
            $n++;
        }
    }
}
$pdo->commit();
$pdo->exec("ANALYZE TABLE supervisiones, asistencia_supervisores, usuarios");
printf("   %d supervisiones y %d turnos generados en %.1f s\n\n", $n, $nt, microtime(true) - $t);

echo "3) Midiendo la API real contra la BD de prueba (5 llamadas por consulta):\n";
$casos = [
    ['Administrador', 'supervisiones', 'action=list&page_number=1&limit=50&estado=todos', 'Lista de supervisiones (admin, todas)'],
    ['Supervisor', 'supervisiones', 'action=list&page_number=1&limit=50&estado=todos', 'Lista de supervisiones (supervisor)'],
    ['Administrador', 'supervisiones', 'action=pending_map', 'Mapa de pendientes'],
    ['Administrador', 'supervisiones', 'action=indicadores_dashboard', 'Indicadores del dashboard (mes)'],
    ['Administrador', 'dashboard', 'action=matriz_mensual', 'Matriz mensual'],
    ['Administrador', 'dashboard', 'action=monitoreo_realtime', 'Monitoreo de turnos'],
    ['Administrador', 'dashboard', 'action=cumplimiento_partners&fecha_inicio=' . date('Y-m-d', $hoy - 365 * 86400), 'Cumplimiento partners (1 año)'],
    ['Administrador', 'asistencia', 'action=timeline_diaria', 'Línea de tiempo del día'],
    ['Administrador', 'asistencia', 'action=admin_summary', 'Monitor de turnos'],
    ['Supervisor', 'asistencia', 'action=status', 'Estado de mi turno'],
];
printf("   %-36s %10s %10s\n", 'Consulta', 'prom. ms', 'máx. ms');
$lento = 0;
foreach ($casos as [$rol, $ep, $q, $label]) {
    $ms = [];
    for ($k = 0; $k < 5; $k++) {
        $t = microtime(true);
        $out = shell_exec('set DB_NAME=' . $test . '&& ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/05_TEST/api/call_api.php") . " $ep \"$q\" $rol 2>&1");
        $ms[] = (microtime(true) - $t) * 1000;
    }
    $ok = str_contains((string)$out, '"status":"success"') || str_contains((string)$out, '"status":"ok"');
    $avg = array_sum($ms) / count($ms);
    if ($avg > 1000 || !$ok) $lento++;
    printf("   %-36s %10.0f %10.0f %s\n", $label, $avg, max($ms), $ok ? '' : ' ← ERROR: ' . mb_substr(trim((string)$out), 0, 80));
}
echo "   (incluye ~60-90 ms de arranque de PHP por llamada de consola)\n\n";

echo "4) Borrando la BD de prueba...\n";
shell_exec("\"$mysql\" -u root -e \"DROP DATABASE IF EXISTS $test;\"");
$existe = (int)(new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', ''))->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$test'")->fetchColumn();
echo $existe ? "   NO se pudo borrar $test\n" : "   BD de prueba eliminada. La BD real no fue modificada.\n";
exit($lento ? 1 : 0);
