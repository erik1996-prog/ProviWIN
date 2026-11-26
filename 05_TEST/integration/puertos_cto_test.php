<?php
// 05_TEST/integration/puertos_cto_test.php - (2026-11-11) Consultar puertos de una CTO con el vínculo con Wincore.
//   - Solo quien tiene "Consultar puertos" (perfil administrador; el supervisor aún no) ve el botón y usa la API.
//   - El vínculo lo activa y atiende el programa «Vínculo Wincore» con su clave (api/puertos_agente.php; 2026-11-21 sin favorito).
//   - Recorrido completo: consulta pendiente → la ventana del vínculo la toma → responde → el que consultó la recibe una vez,
//     sin datos de clientes aunque Wincore los mande.
// Por HTTP contra la API real. Wincore no se llama: la prueba hace de programa de la laptop con una clave de prueba de la cuenta
// principal. Crea usuarios DNI 99999979-80; lo BORRA todo al final y deja el vínculo y la clave de la cuenta principal como estaban.
// Uso (solo CLI): php 05_TEST/integration/puertos_cto_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/config/puertos_cto.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$dnis = ['99999979', '99999980'];
$frascos = [];
$puente = sgsPuertosDir() . '/puente.json';
$puenteAntes = @file_get_contents($puente);
$limpiar = function () use ($pdo, $dnis, &$frascos) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
    foreach (['pc.admin', 'pc.sup'] as $u) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Consultar puertos — $base\n";
try {
    $principalId = Auth::cuentaPrincipalId();
    if (!$principalId) throw new RuntimeException('No hay cuenta principal activa.');
    $clave = 'Puertos#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    foreach ([['99999979', 'pc.admin', 'Administrador', []], ['99999980', 'pc.sup', 'Supervisor', ['AREQUIPA']]] as [$dni, $u, $rol, $zonas]) {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Puertos', 'apellidos' => "Prueba $u", 'email' => "$u@proviwin.test", 'usuario' => $u, 'rol' => $rol,
            'organizacion' => 'directo', 'zonas' => $zonas, 'password' => $clave], 0, $principalId)['id'];
        $pdo->exec("UPDATE usuarios SET activo = 1, por_activar = 0, debe_cambiar_password = 0 WHERE id = $id");
    }
    // Cliente HTTP con su frasco de cookies: devuelve [código, json] o el HTML de una página.
    $cliente = function (string $fr, string $csrf) use ($base) {
        return function (string $accion, ?array $post = null, string $extra = '') use ($base, $fr, $csrf) {
            $ch = curl_init("$base/api/puertos_cto.php?action=$accion$extra");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf]]);
            if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
            $cuerpo = (string)curl_exec($ch);
            return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($cuerpo, true) ?? []];
        };
    };
    $pagina = function (string $fr) use ($base): string {
        $ch = curl_init("$base/index.php?page=supervision_lista"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr]);
        return (string)curl_exec($ch);
    };
    $sesion = function (string $u) use ($base, $clave, &$frascos, $cliente, $pagina) {
        $fr = tempnam(sys_get_temp_dir(), 'sgsp'); $frascos[] = $fr;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch);
        unset($ch); // al cerrarse escribe la cookie de la sesión en el frasco
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina($fr), $m);
        return [$cliente($fr, $m[1] ?? ''), $fr];
    };
    // El programa de la laptop de la cuenta principal: su clave se guarda aquí y se devuelve como estaba al final.
    $claveAntes = @file_get_contents(sgsAgenteArchivo($principalId));
    $claveP = sgsAgenteGenerarClave($principalId);
    $principal = function (string $accion, ?array $post = null, string $extra = '') use ($base, $claveP) {
        $ch = curl_init("$base/api/puertos_agente.php?action=$accion$extra");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-SGS-Clave: ' . $claveP]]);
        if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
        $cuerpo = (string)curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($cuerpo, true) ?? []];
    };

    [$admin, $frA] = $sesion('pc.admin');
    [$sup, $frS] = $sesion('pc.sup');
    sgsPuenteDesactivar();

    // Acceso
    $check('el administrador ve el botón Consultar puertos en Supervisiones', str_contains($pagina($frA), 'modalPuertosCto'));
    $check('el supervisor aún no lo ve (se habilita en Permisos)', !str_contains($pagina($frS), 'modalPuertosCto'));
    [$c, $r] = $sup('estado');
    $check('el supervisor sin permiso no usa la API', $c === 403, [$c, $r]);
    [$c, $r] = $admin('agente_generar', []);
    $check('un administrador sin «Conectar desde su laptop» no crea clave del programa', $c === 403, [$c, $r]);
    [$c, $r] = $admin('vincular', []);
    $check('el navegador ya no activa el vínculo (solo el programa)', $c === 400, [$c, $r]);

    // Sin vínculo
    [$c, $r] = $admin('estado');
    $check('sin vínculo la consulta figura inactiva', $c === 200 && $r['activo'] === false, [$c, $r]);
    [$c, $r] = $admin('consultar', ['codigo' => 'WN-73651-1272741']);
    $check('sin vínculo no se puede consultar', $c === 424, [$c, $r]);

    // Vínculo activo
    [$c, $r] = $principal('vincular', []);
    $check('la cuenta principal activa el vínculo por ' . SGS_PUENTE_HORAS . ' horas', $c === 200 && ($r['vence'] ?? '') === date('H:i', time() + SGS_PUENTE_HORAS * 3600), [$c, $r]);
    [$c, $r] = $admin('estado');
    $check('con el vínculo activo la consulta figura activa', $r['activo'] === true, $r);
    [$c, $r] = $admin('consultar', ['codigo' => 'x; DROP']);
    $check('un código que no es de CTO se rechaza', $c === 400, [$c, $r]);
    [$c, $r] = $admin('consultar', ['codigo' => ' wn-73651-1272741 ']);
    $id = $r['id'] ?? '';
    $check('crea la consulta (código en mayúsculas)', $c === 200 && sgsPuertosIdValido($id), [$c, $r]);
    [$c, $r] = $admin('consultar', ['codigo' => 'WN-2']);
    $idB = $r['id'] ?? '';
    [$c, $r] = $admin('resultado', null, "&id=$idB");
    $check('la segunda consulta espera con 1 antes que ella', ($r['estado'] ?? '') === 'en_espera' && $r['antes'] === 1, $r);
    [$c, $r] = $principal('tomar');
    $check('la ventana del vínculo toma primero la que llegó primero, una sola', $r['activo'] === true && $r['consulta'] === ['id' => $id, 'codigo' => 'WN-73651-1272741'] && $r['en_espera'] === 1, $r);
    [$c, $r] = $admin('resultado', null, "&id=$id");
    $check('quien consultó ve que Wincore la está consultando', ($r['estado'] ?? '') === 'consultando', $r);
    [$c, $r] = $principal('tomar');
    $check('mientras Wincore atiende una, no entrega la siguiente', $r['consulta'] === null && $r['en_espera'] === 1, $r);
    [$c, $r] = $principal('resultado', null, "&id=$id");
    $check('nadie más que quien consultó ve el resultado', $c === 404, [$c, $r]);
    $wincore = ['cto' => ['estado' => 'OPERATIVA', 'objeto' => 'SPLITTER 1X16-PC', 'direccion' => 'AV. PRUEBA 123', 'distrito' => 'PIURA', 'lat' => '-5.18603', 'lon' => '-80.633147', 'no_cliente' => 'CLIENTE SECRETO'],
        'puertos' => [['puerto' => '2', 'estado' => 'OCUPADO', 'servicio' => 'ACTIVO', 'potencia' => '-18.861', 'mac' => '485754438CD2D9B0', 'cliente' => 'CLIENTE SECRETO', 'nro_identidad' => '12345678'],
            ['puerto' => '3', 'estado' => 'OCUPADO', 'servicio' => 'SUSPENDIDO', 'potencia' => 0],
            ['puerto' => '2', 'estado' => 'DISPONIBLE', 'servicio' => ''],
            ['puerto' => '4', 'estado' => 'OCUPADO', 'servicio' => 'ACTIVO', 'potencia' => '-21,5 dBm'],
            ['puerto' => '1', 'estado' => 'DISPONIBLE', 'servicio' => 'BAJAS']]];
    [$c, $r] = $principal('responder', ['id' => $id, 'datos' => json_encode($wincore)]);
    $check('la ventana del vínculo devuelve el resultado', $c === 200, [$c, $r]);
    [$c, $r] = $admin('resultado', null, "&id=$id");
    $texto = json_encode($r, JSON_UNESCAPED_UNICODE);
    $check('quien consultó recibe los puertos ordenados, la ubicación y la dirección', ($r['estado'] ?? '') === 'lista' && $r['puertos'][0]['puerto'] === '1'
        && $r['puertos'][1]['estado'] === 'OCUPADO' && $r['cto']['lat'] === '-5.18603' && $r['cto']['direccion'] === 'AV. PRUEBA 123', $r);
    $check('un número repetido queda una sola vez y gana el ocupado', count($r['puertos']) === 4 && $r['puertos'][1]['estado'] === 'OCUPADO', $r['puertos']);
    $check('cada puerto ocupado trae su potencia (0 = sin señal) y el libre ninguna', $r['puertos'][1]['potencia'] === '-18.86' && $r['puertos'][2]['potencia'] === '0' && $r['puertos'][3]['potencia'] === '-21.5' && $r['puertos'][0]['potencia'] === '', $r);
    $check('sin datos de clientes ni MAC aunque Wincore los mande', !str_contains($texto, 'SECRETO') && !str_contains($texto, '12345678') && !str_contains($texto, '485754438CD2D9B0'), $r);
    [$c, $r] = $admin('resultado', null, "&id=$id");
    $check('el resultado se entrega una sola vez (no queda guardado)', $c === 404, [$c, $r]);
    [$c, $r] = $principal('tomar');
    $check('al responder, sale la siguiente de la cola', ($r['consulta']['id'] ?? '') === $idB && ($r['consulta']['codigo'] ?? '') === 'WN-2', $r);
    $cb = sgsPuertosDir('consultas') . "/$idB.tomada.json";
    $v = sgsPuertosLeer($cb); $v['tomada'] = time() - SGS_PUERTOS_ESPERA - 1; sgsPuertosEscribir($cb, $v);
    [$c, $r] = $principal('tomar');
    [$c2, $r2] = $admin('resultado', null, "&id=$idB");
    $check('si Wincore no responde a tiempo, se avisa y la cola sigue', ($r2['estado'] ?? '') === 'error' && str_contains($r2['message'] ?? '', 'a tiempo'), [$r, $r2]);
    $ids = [];
    for ($k = 0; $k < SGS_PUERTOS_COLA; $k++) $ids[] = $admin('consultar', ['codigo' => "WN-COLA-$k"])[1]['id'] ?? '';
    [$c, $r] = $admin('consultar', ['codigo' => 'WN-COLA-X']);
    $check('con ' . SGS_PUERTOS_COLA . ' en espera, pide intentar en un minuto', $c === 429 && str_contains($r['message'] ?? '', 'minuto'), [$c, $r]);
    foreach (glob(sgsPuertosDir('consultas') . '/*.json') as $f) @unlink($f);

    [$c, $r] = $admin('consultar', ['codigo' => 'WN-1']);
    $id2 = $r['id'] ?? '';
    $check('si la cola está vacía, la toma enseguida', ($principal('tomar')[1]['consulta']['id'] ?? '') === $id2);
    $principal('responder', ['id' => $id2, 'datos' => json_encode(['error' => 'No se encontró la CTO WN-1 en Wincore.'])]);
    [$c, $r] = $admin('resultado', null, "&id=$id2");
    $check('si Wincore no la encuentra, se muestra el motivo', ($r['estado'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'WN-1'), $r);

    // Se apaga
    $v = sgsPuertosLeer($puente); $v['pulso'] = time() - SGS_PUENTE_SIN_PULSO - 5; sgsPuertosEscribir($puente, $v);
    [$c, $r] = $admin('estado');
    $check('si la ventana del vínculo deja de preguntar, se apaga sola', $r['activo'] === false, $r);
    $v['pulso'] = time(); $v['vence'] = time() - 1; sgsPuertosEscribir($puente, $v);
    [$c, $r] = $principal('tomar');
    $check('al vencer, la ventana del vínculo deja de atender', $r['activo'] === false, $r);
    $principal('vincular', []);
    [$c, $r] = $principal('desactivar', []);
    [$c2, $r2] = $admin('estado');
    $check('Desactivar apaga el vínculo para todos', $c === 200 && $r2['activo'] === false, [$r, $r2]);

} catch (Throwable $e) {
    $check('sin excepciones', false, $e->getMessage());
} finally {
    sgsPuenteDesactivar();
    if ($puenteAntes !== false) file_put_contents($puente, $puenteAntes);
    if (isset($principalId)) { if (!empty($claveAntes)) file_put_contents(sgsAgenteArchivo($principalId), $claveAntes); else @unlink(sgsAgenteArchivo($principalId)); }
    $limpiar();
}
echo "\n$ok correctas, $fail con error\n";
exit($fail ? 1 : 0);
