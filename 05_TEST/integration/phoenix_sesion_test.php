<?php
// 05_TEST/integration/phoenix_sesion_test.php - (2026-11-19) Sesión de Phoenix iniciada aquí, activa hasta la medianoche.
//   - Vence a las 23:59:59 hora de Perú; vencida o sin cookies se borra sola.
//   - Solo se guardan cookies y datos visibles: nunca la contraseña.
//   - Con la sesión activa (y sin vínculo del favorito) WinboService usa sus cookies; si Phoenix la cierra, la borra y avisa
//     en vez de volver a entrar.
//   - La API de la sesión no responde a quien no inició sesión en el SGS; la ventanita existe.
// Phoenix no se llama: no hace falta usuario ni contraseña reales. Deja la sesión y el vínculo como estaban.
// Uso (solo CLI): php 05_TEST/integration/phoenix_sesion_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/WinboService.php';

$json = sgsPhoenixDir() . '/sesion_aqui.json';
$cookie = sgsPhoenixSesionCookie();
$puente = sgsPhoenixDir() . '/puente.json';
$antes = [$json => @file_get_contents($json), $cookie => @file_get_contents($cookie), $puente => @file_get_contents($puente)];
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Sesión de Phoenix hasta la medianoche — $base\n";
try {
    @unlink($puente);                                   // sin vínculo del favorito durante la prueba
    $lima = new DateTimeZone('America/Lima');
    $esperada = (new DateTimeImmutable('now', $lima))->setTime(23, 59, 59)->getTimestamp();
    $check('vence a las 23:59:59 de hoy en Perú', sgsPhoenixMedianoche() === $esperada, date(DATE_ATOM, sgsPhoenixMedianoche()));

    file_put_contents($cookie, "# cookies de prueba\n");
    $vence = sgsPhoenixSesionGuardar('usuario.prueba', 1);
    $s = sgsPhoenixSesion();
    $check('guardada: queda activa hasta la medianoche', $s['activa'] && $s['vence'] === $vence && $s['usuario'] === 'usuario.prueba', $s);
    $guardado = (string)file_get_contents($json);
    $check('lo guardado no tiene contraseña', !preg_match('/clave|contrase|password/i', $guardado), $guardado);
    $check('Órdenes cercanas la cuenta como configurada', WinboService::conectado());

    $svc = new WinboService();
    $prop = static function ($o, string $p) { $r = new ReflectionProperty($o, $p); $r->setAccessible(true); return $r->getValue($o); };
    $check('WinboService usa las cookies de la sesión del SGS', $prop($svc, 'cookie') === $cookie);

    // Phoenix cerró la sesión: el servidor no vuelve a entrar (no tiene la contraseña), la borra y avisa (el programa vuelve a entrar).
    $conectar = new ReflectionMethod($svc, 'conectar'); $conectar->setAccessible(true);
    $msg = '';
    try { $conectar->invoke($svc); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
    $check('si Phoenix la cierra antes, avisa que el programa la vuelve a conectar', str_contains($msg, 'vuelve a conectar'), $msg);
    $check('…y la sesión se borra', !sgsPhoenixSesion()['activa'] && !is_file($cookie) && !is_file($json));

    // Vencida (pasó la medianoche): se borra sola.
    file_put_contents($cookie, "# cookies de prueba\n");
    sgsPuertosEscribir($json, ['usuario' => 'usuario.prueba', 'usuario_id' => 1, 'desde' => time() - 86400, 'vence' => time() - 1]);
    $check('pasada la medianoche ya no está activa', !sgsPhoenixSesion()['activa']);
    $check('…y sus archivos se borraron', !is_file($cookie) && !is_file($json));

    // Sin cookies no cuenta como activa.
    sgsPuertosEscribir($json, ['usuario' => 'usuario.prueba', 'usuario_id' => 1, 'desde' => time(), 'vence' => sgsPhoenixMedianoche()]);
    $check('sin cookies no está activa', !sgsPhoenixSesion()['activa']);

    // Por HTTP (2026-11-21): ya no hay ventanita ni favorito; los archivos de la sesión no se publican.
    $http = function (string $url, ?array $post = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
        if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
        $cuerpo = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $cuerpo];
    };
    [$c1] = $http("$base/api/puente_phoenix.php?action=sesion_estado");
    [$c3] = $http("$base/phoenix_sesion.php");
    $check('ya no existen la ventanita ni la API del favorito', $c1 === 404 && $c3 === 404, [$c1, $c3]);
    [$c4] = $http("$base/08_LOGS/runtime/phoenix/sesion_aqui.json");
    $check('los archivos de la sesión no se sirven por web', $c4 === 403 || $c4 === 404, $c4);
} catch (Throwable $e) {
    $check('sin excepciones', false, $e->getMessage());
} finally {
    foreach ($antes as $archivo => $contenido) {
        if ($contenido === false) @unlink($archivo); else file_put_contents($archivo, $contenido);
    }
}
echo "\n$ok correctas, $fail con error\n";
exit($fail ? 1 : 0);
