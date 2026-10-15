<?php
// 05_TEST/integration/acceso_y_cuadrillas_test.php - (2026-10-15) Cambio de contraseña obligatorio al ingresar,
// último ingreso, contadores de estado de acceso en Usuarios y cuadrillas (T2 y vehículo del técnico líder).
// Por HTTP contra la API real + servicio. Crea usuarios temporales (DNI 99999916 y 99999917) y los BORRA al final;
// la parte de cuadrillas trabaja dentro de una transacción que se deshace (no deja cambios).
// Uso (solo CLI): php 05_TEST/integration/acceso_y_cuadrillas_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/supervision_personal.php';
$pdo = Database::getConnection();

$clave = 'AccesoPrueba' . random_int(1000, 9999);
$nueva = 'MiPropiaClave' . random_int(1000, 9999) . '#';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar) {
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999916','99999917')");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (['acceso.prueba', 'acceso.admin'] as $u) {
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    }
    @unlink($jar);
};
$pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999916','99999917')");
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$svc = new UsuarioService($pdo);

$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
$http = function (string $url, array $opts = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
    $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$body, json_decode($body, true), $code];
};
$login = fn(string $usuario, string $pass) => $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $pass])])[1];
$csrf = fn(string $html) => preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';

try {
    echo "Cambio de contraseña obligatorio y último ingreso — $base\n";
    $r = $svc->guardar(['dni' => '99999916', 'nombres' => 'Acceso', 'apellidos' => 'Prueba SGS', 'email' => 'acceso.prueba@proviwin.test', 'usuario' => 'acceso.prueba',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave, 'pedir_cambio' => true], 0, $admin);
    $uid = (int)$r['id'];
    $fila = fn() => $pdo->query("SELECT debe_cambiar_password, ultimo_acceso FROM usuarios WHERE id = $uid")->fetch();
    $check('creado con "Pedir que la cambie al ingresar": queda marcado', (int)$fila()['debe_cambiar_password'] === 1, $fila());
    $check('aún no ingresa: último ingreso vacío', $fila()['ultimo_acceso'] === null, $fila());

    $lista = $svc->listar(['q' => '99999916']);
    $check('en Usuarios aparece como "Por ingresar"', ($lista['usuarios'][0]['estado_acceso'] ?? '') === 'por_ingresar' && ($lista['conteos']['por_ingresar'] ?? 0) === 1, $lista['conteos'] ?? null);

    $a = $login('acceso.prueba', $clave);
    $check('el ingreso avisa que debe cambiar la contraseña', ($a['status'] ?? '') === 'success' && !empty($a['debe_cambiar_password']), $a);
    $check('se registra el último ingreso', $fila()['ultimo_acceso'] !== null, $fila());
    [$pagina] = $http("$base/index.php?page=supervision_lista");
    $check('cualquier pantalla muestra solo el cambio de contraseña', str_contains($pagina, 'Crea tu contraseña') && !str_contains($pagina, 'id="sidebar'), mb_substr(strip_tags($pagina), 0, 120));
    $token = $csrf($pagina);
    [, $j, $code] = $http("$base/api/supervisiones.php?action=list");
    $check('las demás APIs quedan bloqueadas (403)', $code === 403 && !empty($j['debe_cambiar_password']), [$code, $j]);
    $cambiar = fn(string $actual, string $n) => $http("$base/api/usuarios.php?action=cambiar_password_propia", [CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $token], CURLOPT_POSTFIELDS => json_encode(['actual' => $actual, 'nueva' => $n])])[1];
    $j = $cambiar($clave, 'corta');
    $check('rechaza una contraseña débil', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', '10 caracteres'), $j);
    $j = $cambiar($clave, $nueva);
    $check('acepta la nueva contraseña', ($j['status'] ?? '') === 'success', $j);
    $check('la marca se quita en la base', (int)$fila()['debe_cambiar_password'] === 0, $fila());
    [$pagina] = $http("$base/index.php?page=supervision_lista");
    $check('después del cambio entra al sistema normal', !str_contains($pagina, 'Crea tu contraseña') && str_contains($pagina, 'id="main-content-area"'));
    $lista = $svc->listar(['q' => '99999916']);
    $check('en Usuarios pasa a "Con acceso"', ($lista['usuarios'][0]['estado_acceso'] ?? '') === 'con_acceso', $lista['usuarios'][0] ?? null);

    $svc->restablecerPassword($uid, '', false);
    $check('restablecer sin pedir el cambio no marca', (int)$fila()['debe_cambiar_password'] === 0);
    $svc->restablecerPassword($uid);
    $check('restablecer (temporal) sí pide el cambio', (int)$fila()['debe_cambiar_password'] === 1);
    $svc->guardar(array_merge($svc->detalle($uid), ['password' => $clave, 'pedir_cambio' => false]), $uid, $admin);
    $check('editar con contraseña y la opción desmarcada no pide el cambio', (int)$fila()['debe_cambiar_password'] === 0);

    $svc->cambiarEstado($uid, false, $admin);
    $lista = $svc->listar(['q' => '99999916', 'estado' => 'desactivados']);
    $check('filtro "Desactivados" lo muestra', count($lista['usuarios']) === 1 && $lista['usuarios'][0]['estado_acceso'] === 'desactivados', $lista['conteos'] ?? null);
    $lista = $svc->listar(['q' => '99999916', 'estado' => 'con_acceso']);
    $check('filtro "Con acceso" ya no lo muestra (los conteos sí lo cuentan)', count($lista['usuarios']) === 0 && $lista['conteos']['todos'] === 1, $lista['conteos'] ?? null);

    echo "Cuadrillas — $base\n";
    $par = $pdo->query("SELECT t1.id t1, t1.nombre_tecnico n1, t1.dni d1, t1.partner_id p, t1.apoyo_id apoyo, t1.vehiculo_id vehiculo
        FROM tecnicos t1 WHERE COALESCE(t1.tipo_tecnico, 1) = 1 AND t1.partner_id IS NOT NULL AND t1.dni REGEXP '^[0-9]{8}$' LIMIT 1")->fetch();
    if (!$par) {
        echo "  (sin un técnico líder con DNI y partner: se omite)\n";
    } else {
        $estado = fn() => $pdo->query("SELECT apoyo_id, vehiculo_id FROM tecnicos WHERE id = {$par['t1']}")->fetch();
        $pdo->beginTransaction();
        try {
            // T2 temporal del mismo partner (se deshace con la transacción).
            $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, fecha_registro) VALUES ('APOYO CUADRILLA PRUEBA', '99999932', 2, 'Habilitado', :p, NOW())")
                ->execute([':p' => $par['p']]);
            $par['t2'] = (int)$pdo->lastInsertId();
            $tec = [['id' => $par['t1'], 'nombre' => $par['n1'], 'dni' => $par['d1']], ['id' => $par['t2'], 'nombre' => 'APOYO CUADRILLA PRUEBA', 'dni' => '99999932']];
            guardarPersonalSupervision($pdo, 0, (int)$par['p'], $tec, ['placa' => 'ZZ-9999', 'tipo' => 'Moto', 'estado' => 'Regular', 'soat' => null, 'revision' => null], false);
            $e = $estado();
            $check('con "Guardar el T2 y el vehículo" desmarcado la ficha del líder no cambia', (int)$e['apoyo_id'] === (int)$par['apoyo'] && (int)$e['vehiculo_id'] === (int)$par['vehiculo'], $e);
            guardarPersonalSupervision($pdo, 0, (int)$par['p'], $tec, ['placa' => 'ZZ-9999', 'tipo' => 'Moto', 'estado' => 'Regular', 'soat' => null, 'revision' => null], true);
            $e = $estado();
            $vehiculo = (int)$pdo->query("SELECT id FROM vehiculos WHERE placa = 'ZZ-9999'")->fetchColumn();
            $check('marcado: el T2 queda como apoyo habitual del líder', (int)$e['apoyo_id'] === (int)$par['t2'], $e);
            $check('marcado: el vehículo queda asignado al líder', (int)$e['vehiculo_id'] === $vehiculo && $vehiculo > 0, $e);
        } finally {
            $pdo->rollBack();
        }
        $check('la prueba no deja cambios en el técnico', (int)$estado()['apoyo_id'] === (int)$par['apoyo']);
    }

    $svc->guardar(['dni' => '99999917', 'nombres' => 'Acceso', 'apellidos' => 'Admin SGS', 'email' => 'acceso.admin@proviwin.test', 'usuario' => 'acceso.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $login('acceso.admin', $clave);
    [, $j] = $http("$base/api/tecnicos.php?action=cuadrillas");
    $check('API de cuadrillas responde con resumen', ($j['status'] ?? '') === 'success' && isset($j['resumen']['lideres'], $j['resumen']['completas']), $j['message'] ?? null);
    [$pagina] = $http("$base/index.php?page=cuadrillas");
    $check('pantalla Cuadrillas carga', str_contains($pagina, 'Cuadrillas registradas'));
} finally {
    $cleanup();
}
echo "\nResultado: $ok correctas, $fail con error\n";
exit($fail ? 1 : 0);
