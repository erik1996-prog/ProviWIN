<?php
// 05_TEST/integration/carga_100_usuarios.php - Prueba de carga: N usuarios simultáneos con sesiones distintas (solo CLI).
// Uso: php 05_TEST/integration/carga_100_usuarios.php [usuarios=100] [rondas=3] [url_base=http://localhost/ProviWIN]
// Crea un supervisor de prueba temporal (DNI 99999904) y N sesiones; al terminar borra ambos. Solo hace peticiones de lectura.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$usuarios = max(1, (int)($argv[1] ?? 100));
$rondas = max(1, (int)($argv[2] ?? 3));
$base = rtrim($argv[3] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';

$pdo = Database::getConnection();
$pdo->exec("DELETE FROM usuarios WHERE dni = '99999904'");
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$r = (new UsuarioService($pdo))->guardar(['dni' => '99999904', 'nombres' => 'Carga', 'apellidos' => 'Prueba SGS', 'email' => 'carga.prueba@proviwin.test',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['LA LIBERTAD'], 'password' => 'CargaPrueba123'], 0, $admin);
$u = $pdo->query("SELECT u.*, p.nombre_partner FROM usuarios u LEFT JOIN partners p ON p.id=u.partner_id WHERE u.id = {$r['id']}")->fetch();

// N sesiones reales en la carpeta de sesiones de PHP (misma que usa Apache)
$savePath = ini_get('session.save_path') ?: sys_get_temp_dir();
$ids = [];
for ($i = 0; $i < $usuarios; $i++) {
    $id = 'sgsload' . bin2hex(random_bytes(12));
    $data = ['user_id' => $u['id'], 'user_dni' => $u['dni'], 'user_usuario' => $u['usuario'], 'user_nombre' => $u['nombre_completo'],
        'user_rol' => $u['rol'], 'user_partner_id' => $u['partner_id'], 'user_partner_nombre' => $u['nombre_partner'], 'csrf_token' => 'carga'];
    $ser = ''; foreach ($data as $k => $v) $ser .= $k . '|' . serialize($v);
    file_put_contents("$savePath/sess_$id", $ser);
    $ids[] = $id;
}

$escenarios = [
    'Pantalla Supervisiones' => '/index.php?page=supervision_lista',
    'Lista de supervisiones (API)' => '/api/supervisiones.php?action=list&page_number=1&limit=50&estado=todos',
    'Notificaciones (sondeo cada 60 s)' => '/api/notificaciones.php?action=list',
    'Estado del turno (API)' => '/api/asistencia.php?action=status',
    'Mi información' => '/index.php?page=mi_informacion',
];
printf("Prueba de carga: %d usuarios simultáneos × %d rondas por escenario contra %s\n\n", $usuarios, $rondas, $base);
printf("%-36s %8s %9s %9s %9s %8s\n", 'Escenario', 'pet/s', 'p50 ms', 'p95 ms', 'máx ms', 'errores');
$global = ['n' => 0, 'err' => 0];
foreach ($escenarios as $nombre => $path) {
    $tiempos = []; $errores = 0; $t0 = microtime(true);
    for ($ronda = 0; $ronda < $rondas; $ronda++) {
        $mh = curl_multi_init(); $handles = [];
        foreach ($ids as $sid) {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "SGS_SESSID=$sid", CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: application/json, text/html']]);
            curl_multi_add_handle($mh, $ch); $handles[] = $ch;
        }
        do { $st = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 1.0); } while ($active && $st === CURLM_OK);
        foreach ($handles as $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $body = (string)curl_multi_getcontent($ch);
            $tiempos[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $esLogin = str_contains($body, 'Iniciar sesión · SGS');
            if ($code !== 200 || $esLogin || str_contains($body, '"status":"error"')) $errores++;
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
    }
    $dur = microtime(true) - $t0; sort($tiempos); $n = count($tiempos);
    printf("%-36s %8.1f %9.0f %9.0f %9.0f %8d\n", $nombre, $n / $dur, $tiempos[(int)($n * .5)], $tiempos[(int)min($n - 1, $n * .95)], end($tiempos), $errores);
    $global['n'] += $n; $global['err'] += $errores;
}

// Limpieza: sesiones y usuario de prueba
foreach ($ids as $sid) @unlink("$savePath/sess_$sid");
$pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = {$u['id']}");
$pdo->exec("DELETE FROM usuarios WHERE id = {$u['id']}");
$restos = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE dni='99999904'")->fetchColumn() + count(glob("$savePath/sess_sgsload*") ?: []);
printf("\nTotal: %d peticiones, %d con error. Usuario y sesiones de prueba eliminados: %s\n", $global['n'], $global['err'], $restos === 0 ? 'sí' : "NO ($restos)");
exit($global['err'] ? 1 : 0);
