<?php
// 05_TEST/integration/observaciones_export_test.php - Relación de observaciones filtrada en Excel y PDF (por HTTP).
// Crea un coordinador temporal (DNI 99999912), descarga los archivos y lo BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/observaciones_export_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'ExportPrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo) {
    $pdo->exec("DELETE FROM usuarios WHERE dni='99999912'");
    $pdo->exec("DELETE FROM observaciones_supervision WHERE detalle_observacion LIKE '[PRUEBA-EXPORT]%'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|export.prueba")]);
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
(new UsuarioService($pdo))->guardar(['dni' => '99999912', 'nombres' => 'Export', 'apellidos' => 'Prueba SGS', 'email' => 'export.prueba@proviwin.test', 'usuario' => 'export.prueba',
    'rol' => 'Coordinador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
// Dos observaciones temporales sobre una supervisión existente: una en Penalidad Directa y otra Pendiente
$sup = (int)$pdo->query('SELECT id FROM supervisiones ORDER BY id DESC LIMIT 1')->fetchColumn();
$sub = $pdo->query('SELECT id, categoria_id FROM subopciones_observacion ORDER BY id LIMIT 1')->fetch();
if ($sup && $sub) {
    $ins = $pdo->prepare("INSERT INTO observaciones_supervision (supervision_id, creado_por, gravedad, categoria_id, subopcion_id, detalle_observacion, fecha_limite_respuesta, estado_respuesta)
        VALUES (:s, :u, :g, :c, :o, :d, NOW() + INTERVAL 3 DAY, :e)");
    $ins->execute([':s' => $sup, ':u' => $admin, ':g' => 'Muy Grave', ':c' => $sub['categoria_id'], ':o' => $sub['id'], ':d' => '[PRUEBA-EXPORT] Cable sin rotular; texto con "comillas" & <signos>', ':e' => 'Penalidad Directa']);
    $ins->execute([':s' => $sup, ':u' => $admin, ':g' => 'Leve', ':c' => $sub['categoria_id'], ':o' => $sub['id'], ':d' => '[PRUEBA-EXPORT] Falta orden en la caja', ':e' => 'Pendiente']);
}

$http = function (string $url, ?array $json = null) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120]);
    if ($json !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($json)]);
    $body = (string)curl_exec($ch);
    $info = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => $body];
    curl_close($ch);
    return $info;
};
// Lee la hoja 1 de un .xlsx y devuelve las filas como arreglos de texto
$leerXlsx = function (string $bin): array {
    $f = sys_get_temp_dir() . '/sgs_test_' . bin2hex(random_bytes(4)) . '.zip';
    file_put_contents($f, $bin);
    $xml = file_get_contents('phar://' . $f . '/xl/worksheets/sheet1.xml');
    $rows = [];
    foreach (simplexml_load_string($xml)->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) $r[] = isset($c->is) ? (string)$c->is->t : (string)$c->v;
        $rows[] = $r;
    }
    @unlink($f);
    return $rows;
};

$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Relación de observaciones (Excel / PDF) — $base\n";
$sinSesion = $http("$base/api/observaciones_export.php?formato=xlsx");
$check('sin sesión no descarga nada', $sinSesion['code'] !== 200 || !str_starts_with($sinSesion['body'], 'PK'), $sinSesion['code']);
$login = json_decode($http("$base/api/auth.php?action=login", ['usuario' => 'export.prueba', 'password' => $clave])['body'], true);
$check('ingresa el coordinador de prueba', ($login['status'] ?? '') === 'success', $login);

$todo = $http("$base/api/observaciones_export.php?formato=xlsx");
$check('Excel: responde un .xlsx', $todo['code'] === 200 && str_contains($todo['type'], 'spreadsheetml') && str_starts_with($todo['body'], 'PK'), [$todo['code'], $todo['type'], mb_substr($todo['body'], 0, 120)]);
$filas = $leerXlsx($todo['body']);
$check('Excel: encabezado con Fecha, Estado y Resultado', ($filas[0][0] ?? '') === 'Fecha de ingreso' && in_array('Resultado', $filas[0], true), $filas[0] ?? null);
$total = count($filas) - 1;
echo "     ($total observaciones en total)\n";

$pen = $http("$base/api/observaciones_export.php?formato=xlsx&estado=Penalizado");
$filasPen = $leerXlsx($pen['body']);
$col = array_search('Resultado', $filasPen[0], true);
$soloPen = array_unique(array_column(array_slice($filasPen, 1), $col));
$check('Excel filtrado por Penalizados: solo trae penalizadas', $soloPen === [] || $soloPen === ['Penalizado'], $soloPen);
$check('el filtro reduce o iguala el total', count($filasPen) - 1 <= $total, [count($filasPen) - 1, $total]);
echo '     (' . (count($filasPen) - 1) . " penalizadas)\n";
$esperado = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM observaciones_supervision WHERE estado_respuesta IN ('Penalizado','Levantamiento rechazado','Penalidad Directa','Apelación Rechazada'))
    + (SELECT COUNT(*) FROM checklist_respuestas cr JOIN checklists_tecnicos ct ON ct.id = cr.checklist_id
       WHERE ct.estado_respuesta IN ('Penalizado','Levantamiento rechazado','Penalidad Directa','Apelación Rechazada')
       AND ((cr.observacion IS NOT NULL AND TRIM(cr.observacion)<>'') OR cr.foto_evidencia IS NOT NULL))")->fetchColumn();
$check('cantidad de penalizadas coincide con la base de datos', count($filasPen) - 1 === $esperado, [count($filasPen) - 1, $esperado]);

$pdf = $http("$base/api/observaciones_export.php?formato=pdf&estado=Penalizado");
$check('PDF: responde un PDF válido', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), [$pdf['code'], mb_substr($pdf['body'], 0, 160)]);
file_put_contents(sys_get_temp_dir() . '/sgs_obs_penalizadas.pdf', $pdf['body']);
$vacio = $http("$base/api/observaciones_export.php?formato=pdf&fecha_desde=1990-01-01&fecha_hasta=1990-01-02");
$check('PDF sin resultados igual se genera (con aviso)', $vacio['code'] === 200 && str_starts_with($vacio['body'], '%PDF'), $vacio['code']);

$cleanup(); @unlink($jar);
echo "\n$ok correctas, $fail con error. Usuario de prueba eliminado.\n";
exit($fail ? 1 : 0);
