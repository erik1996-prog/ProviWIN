<?php
// 05_TEST/integration/importar_tipificacion_test.php - Importar pendientes de cancelación: la tipificación del Excel
// queda enlazada al catálogo tipificaciones_cancelacion (si es nueva, se registra sola).
// Crea un administrador temporal (DNI 99999914), importa un Excel de 1 fila por HTTP y BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/importar_tipificacion_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/utils/XlsxWriter.php';
$pdo = Database::getConnection();

$ticket = 'PRUEBA-TIP-' . random_int(10000, 99999);
$tipNueva = 'Prueba automatica cliente desistio ' . random_int(100, 999);
$clave = 'ImportPrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$xlsx = sys_get_temp_dir() . '/sgs_import_prueba_' . bin2hex(random_bytes(3)) . '.xlsx';
$notifAntes = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM notificaciones')->fetchColumn();
$cleanup = function () use ($pdo, $ticket, $tipNueva, $notifAntes, $xlsx, $jar) {
    $pdo->prepare('DELETE FROM supervision_estado_historial WHERE supervision_id IN (SELECT id FROM supervisiones WHERE ticket_codigo = :t)')->execute([':t' => $ticket]);
    $pdo->prepare('DELETE FROM supervisiones WHERE ticket_codigo = :t')->execute([':t' => $ticket]);
    $pdo->prepare('DELETE FROM tipificaciones_cancelacion WHERE descripcion = :d')->execute([':d' => $tipNueva]);
    $pdo->prepare('DELETE FROM notificaciones WHERE id > :n')->execute([':n' => $notifAntes]);
    $pdo->exec("DELETE FROM usuarios WHERE dni='99999914'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|import.prueba")]);
    @unlink($xlsx); @unlink($jar);
};
$pdo->exec("DELETE FROM usuarios WHERE dni='99999914'");
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
(new UsuarioService($pdo))->guardar(['dni' => '99999914', 'nombres' => 'Import', 'apellidos' => 'Prueba SGS', 'email' => 'import.prueba@proviwin.test', 'usuario' => 'import.prueba',
    'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);

// Excel de una fila con la plantilla del sistema
file_put_contents($xlsx, XlsxWriter::build('Pendientes',
    ['TICKET', 'PARTNER', 'PRODUCTO', 'FECHA CANCELACION', 'MOTIVO CANCELACION', 'DEPARTAMENTO', 'PROVINCIA', 'DISTRITO', 'COORDENADAS'],
    [[$ticket, 'VISUAL', 'INSTALACION', date('Y-m-d'), $tipNueva, 'PIURA', 'PIURA', 'PIURA', '-5.1945, -80.6328']]));

$http = function (string $url, array $opts = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120] + $opts);
    $body = (string)curl_exec($ch); curl_close($ch);
    return [$body, json_decode($body, true)];
};
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Importar pendientes: tipificación enlazada al catálogo — $base\n";

[, $login] = $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['usuario' => 'import.prueba', 'password' => $clave])]);
$check('ingresa el administrador de prueba', ($login['status'] ?? '') === 'success', $login);
[$pagina] = $http("$base/index.php?page=supervision_lista");
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
$csrf = $m[1] ?? '';
$check('obtiene el token de seguridad', $csrf !== '');

[$crudo, $res] = $http("$base/api/cancelaciones_import.php", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ["X-CSRF-Token: $csrf"],
    CURLOPT_POSTFIELDS => ['archivo' => new CURLFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pendientes.xlsx')]]);
$check('la importación responde correctamente', ($res['status'] ?? '') === 'success', $res ?? mb_substr($crudo, 0, 300));

$st = $pdo->prepare('SELECT descripcion, estado FROM tipificaciones_cancelacion WHERE descripcion = :d');
$st->execute([':d' => $tipNueva]);
$cat = $st->fetch();
$check('la tipificación nueva del Excel se registró en el catálogo', (bool)$cat, $cat);
$st = $pdo->prepare('SELECT s.tipificacion_cancelacion, t.id AS catalogo_id FROM supervisiones s LEFT JOIN tipificaciones_cancelacion t ON t.descripcion = s.tipificacion_cancelacion WHERE s.ticket_codigo = :t');
$st->execute([':t' => $ticket]);
$sup = $st->fetch();
$check('la orden pendiente quedó enlazada a esa tipificación', $sup && $sup['catalogo_id'] && $sup['tipificacion_cancelacion'] === $tipNueva, $sup);

$cleanup();
echo "\n$ok correctas, $fail con error. Usuario, orden y tipificación de prueba eliminados.\n";
exit($fail ? 1 : 0);
