<?php
// 05_TEST/integration/correccion_admin_test.php - (2026-10-15) El administrador corrige una supervisión FINALIZADA:
// se guarda, sigue finalizada y a nombre del supervisor que la hizo; no puede registrar supervisiones operativas.
// Además: una pendiente de la bolsa común muestra como responsables a todos los supervisores directos de la zona.
// Por HTTP contra la API real. Crea usuarios (DNI 99999919-21) y supervisiones de prueba (PRUEBA-CORR-*) y los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/correccion_admin_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'CorreccionPrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-CORR-%'");
    $pdo->exec("DELETE FROM tecnicos WHERE dni = '99999938'");
    $pdo->exec("DELETE FROM vehiculos WHERE placa = 'ZZ-9993'");
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999919','99999920','99999921')");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (['corr.admin', 'corr.uno', 'corr.dos'] as $u) {
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    }
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $svc = new UsuarioService($pdo);
    $svc->guardar(['dni' => '99999919', 'nombres' => 'Correccion', 'apellidos' => 'Admin Prueba', 'email' => 'corr.admin@proviwin.test', 'usuario' => 'corr.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $uno = $svc->guardar(['dni' => '99999920', 'nombres' => 'Zenon Primero', 'apellidos' => 'Pruebauno Sgs', 'email' => 'corr.uno@proviwin.test', 'usuario' => 'corr.uno',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin)['id'];
    $svc->guardar(['dni' => '99999921', 'nombres' => 'Zoila Segunda', 'apellidos' => 'Pruebados Sgs', 'email' => 'corr.dos@proviwin.test', 'usuario' => 'corr.dos',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='PIURA' LIMIT 1")->fetch();
    $insertar = function (string $ticket, string $estado, string $obs) use ($pdo, $uno, $ubi, $partner): int {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision,
            latitud, longitud, foto_servicio_1) VALUES (:c, '2026-10-10 09:00:00', :s, 'Líder', 'Regular', :d, :p, :di, :t, 'En frío', :pa, 'Ninguno', 'Instalación', 'Instalación', :o, :e,
            '-5.19', '-80.63', 'uploads/prueba.jpg')")
            ->execute([':c' => 'SUP-' . $ticket, ':s' => $uno, ':d' => $ubi['departamento'], ':p' => $ubi['provincia'], ':di' => $ubi['distrito'], ':t' => $ticket, ':pa' => $partner, ':o' => $obs, ':e' => $estado]);
        return (int)$pdo->lastInsertId();
    };
    $final = $insertar('PRUEBA-CORR-1', 'Finalizada', 'Texto con un error');
    $enProceso = $insertar('PRUEBA-CORR-2', 'En proceso', 'Aún abierta');
    $pendiente = $insertar('PRUEBA-CORR-3', 'Pendiente', '');

    $http = function (string $url, array $opts = []) use ($jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return [$body, json_decode($body, true)];
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'corr.admin', 'password' => $clave])]);
    [$pagina] = $http("$base/index.php?page=supervision_lista");
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    $guardar = fn(int $id, string $ticket, string $obs, string $estado = 'Finalizada') => $http("$base/api/supervisiones.php?action=create", [CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')], CURLOPT_POSTFIELDS => ['supervision_id_edit' => $id, 'estado_supervision' => $estado,
            'tipo_supervision' => 'Instalación', 'tipo_supervision_inicial' => 'En frío', 'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular',
            'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'], 'distrito' => $ubi['distrito'], 'ticket_codigo' => $ticket,
            'partner_id' => $partner, 'observacion_servicio' => $obs, 'latitud' => '-5.19', 'longitud' => '-80.63']])[1];
    $fila = fn(int $id) => $pdo->query("SELECT estado_supervision, supervisor_id, observacion_servicio, fecha_registro FROM supervisiones WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

    echo "Corrección por el administrador — $base\n";
    $r = $guardar($final, 'PRUEBA-CORR-1', 'Texto corregido por el administrador');
    $f = $fila($final);
    $check('el administrador corrige una supervisión finalizada', ($r['status'] ?? '') === 'success' && $f['observacion_servicio'] === 'Texto corregido por el administrador', [$r, $f]);
    $check('sigue finalizada, a nombre del supervisor y con su fecha', $f['estado_supervision'] === 'Finalizada' && (int)$f['supervisor_id'] === (int)$uno && $f['fecha_registro'] === '2026-10-10 09:00:00', $f);
    $r = $guardar($final, 'PRUEBA-CORR-1', 'Intento de reabrir', 'En proceso');
    $check('la corrección no la reabre aunque se pida', $fila($final)['estado_supervision'] === 'Finalizada', [$r, $fila($final)]);
    $r = $guardar($enProceso, 'PRUEBA-CORR-2', 'El administrador no la trabaja', 'En proceso');
    $check('una supervisión abierta la trabaja solo el supervisor (el administrador no)', ($r['status'] ?? '') === 'error' && $fila($enProceso)['observacion_servicio'] === 'Aún abierta', $r);
    $r = $http("$base/api/supervisiones.php?action=create", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['estado_supervision' => 'En proceso', 'tipo_supervision' => 'Instalación', 'ticket_codigo' => 'PRUEBA-CORR-NUEVA']])[1];
    $check('el administrador sigue sin poder registrar supervisiones nuevas', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'supervisor directo'), $r);

    // Presencial sin SOAT registrado (2026-10-15): el formulario manda unidad_soat vacío. En MySQL estricto
    // (hosting y esta PC) fallaba con "Data truncated for column 'unidad_soat'".
    $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, fecha_registro) VALUES ('TECNICO CORRECCION PRUEBA', '99999938', 1, 'Habilitado', :p, NOW())")
        ->execute([':p' => $partner]);
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, tecnico1_nombre, tecnico1_dni, brevete_poseedor, unidad_tipo, unidad_placa, unidad_estado_conservacion,
        clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision, latitud, longitud, foto_servicio_1)
        VALUES ('SUP-PRUEBA-CORR-4', '2026-10-10 09:00:00', :s, 'Líder', 'Regular', :d, :p, :di, 'PRUEBA-CORR-4', 'En caliente', :pa, 'TECNICO CORRECCION PRUEBA', '99999938',
        'Tecnico 1', 'Moto', 'ZZ-9993', 'Regular', 'Instalación', 'Instalación', 'Recableado', 'Finalizada', '-5.19', '-80.63', 'uploads/prueba.jpg')")
        ->execute([':s' => $uno, ':d' => $ubi['departamento'], ':p' => $ubi['provincia'], ':di' => $ubi['distrito'], ':pa' => $partner]);
    $presencial = (int)$pdo->lastInsertId();
    $r = $http("$base/api/supervisiones.php?action=create", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['supervision_id_edit' => $presencial, 'estado_supervision' => 'Finalizada', 'tipo_supervision' => 'Instalación',
            'tipo_supervision_inicial' => 'En caliente', 'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'],
            'provincia' => $ubi['provincia'], 'distrito' => $ubi['distrito'], 'ticket_codigo' => 'PRUEBA-CORR-4', 'partner_id' => $partner,
            'tecnico1_nombre' => 'TECNICO CORRECCION PRUEBA', 'tecnico1_dni' => '99999938', 'brevete_poseedor' => 'Tecnico 1',
            'unidad_tipo' => 'Moto', 'unidad_placa' => 'ZZ-9993', 'unidad_estado_conservacion' => 'Regular', 'unidad_soat' => '', 'unidad_revision_tecnica' => '',
            'recordar_cuadrilla' => '0', 'observacion_servicio' => 'Se dejó encaminado el recableado del servicio afectado.', 'latitud' => '-5.19', 'longitud' => '-80.63']])[1];
    $f4 = $pdo->query("SELECT observacion_servicio, unidad_soat, unidad_revision_tecnica, unidad_placa FROM supervisiones WHERE id = $presencial")->fetch(PDO::FETCH_ASSOC);
    $check('corrige una presencial sin SOAT registrado (SOAT vacío ya no da error)', ($r['status'] ?? '') === 'success'
        && $f4['observacion_servicio'] === 'Se dejó encaminado el recableado del servicio afectado.' && $f4['unidad_soat'] === null && $f4['unidad_placa'] === 'ZZ-9993', [$r, $f4]);

    echo "Pendientes sin tomar (2026-10-16: \"Aún no gestionado\", sin nombrar a los supervisores de la zona)\n";
    [, $lista] = $http("$base/api/supervisiones.php?action=list&codigo=PRUEBA-CORR&limit=50");
    $p = array_values(array_filter($lista['data'] ?? [], fn($x) => ($x['ticket_codigo'] ?? '') === 'PRUEBA-CORR-3'))[0] ?? null;
    $check('la pendiente sin tomar sale como no gestionada, sin lista de supervisores', $p && (int)$p['sin_gestionar'] === 1 && !isset($p['responsables_zona']), $p ?: 'no apareció en la lista');
    $p2 = array_values(array_filter($lista['data'] ?? [], fn($x) => ($x['ticket_codigo'] ?? '') === 'PRUEBA-CORR-1'))[0] ?? null;
    $check('una supervisión ya tomada muestra a su supervisor', $p2 === null || ((int)$p2['sin_gestionar'] === 0 && !empty($p2['supervisor_nombre'])), $p2);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
