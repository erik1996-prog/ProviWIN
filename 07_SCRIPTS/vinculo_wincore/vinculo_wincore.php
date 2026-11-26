<?php
// 07_SCRIPTS/vinculo_wincore/vinculo_wincore.php - Programa "Vínculo Wincore" de la laptop (2026-11-19, pedido del usuario).
// Lo abre "Vínculo Wincore.cmd" (que pide usuario y contraseña de Wincore sin mostrarlos y los pasa por la entrada estándar).
// Con la VPN conectada:
//   1) Inicia sesión en Wincore como su pantalla de ingreso (loglogin.createtickedtodevice → loglogin.login → dataTables.php).
//      La contraseña solo se usa para entrar y se borra: no se guarda en ningún archivo.
//   2) Activa el vínculo en el SGS (api/puertos_agente.php, con la clave del programa) y, cada pocos segundos, toma la siguiente
//      consulta de puertos, la hace en Wincore con su sesión y devuelve SOLO el estado de la CTO y de sus puertos (nunca datos
//      de clientes: la MAC solo sirve para pedir la potencia y no sale de la laptop).
//   3) Se detiene con Ctrl + C o al cerrar la ventana, si se cae la VPN o la sesión de Wincore, o al vencer el vínculo (10 h).
// 2026-11-19 (pedido del usuario): corre en segundo plano, sin ventana. El lanzador lo deja oculto y espera a que diga
// "Vinculado" o falle; lo que pasa se anota en <registro> (nunca la contraseña). Se detiene con el acceso directo
// "Detener Vínculo Wincore", que crea <registro>.detener, o con "Desactivar" en el SGS.
// Uso: php vinculo_wincore.php <archivo de configuración> [registro]  (JSON: sgs, clave y, opcional, wincore)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Lima');

$REGISTRO = (string)($argv[2] ?? '');
$DETENER = $REGISTRO !== '' ? $REGISTRO . '.detener' : '';
if ($DETENER !== '') @unlink($DETENER);
$decir = static function (string $texto) use ($REGISTRO): void {
    $linea = '[' . date('d/m H:i:s') . '] ' . $texto . PHP_EOL;
    if ($REGISTRO !== '') @file_put_contents($REGISTRO, $linea, FILE_APPEND | LOCK_EX); else echo $linea;
};
$cfg = json_decode((string)@file_get_contents($argv[1] ?? ''), true);
if (!is_array($cfg) || empty($cfg['sgs']) || empty($cfg['clave'])) { $decir('Falta la configuración (dirección del SGS y clave del programa).'); exit(2); }
// Dirección base del SGS aunque se haya pegado con index.php, una página (?page=…) o la ruta de la API
$SGS = rtrim(preg_replace(['~[?#].*$~', '~/(index\.php|api/.*)$~i'], '', trim((string)$cfg['sgs'])), '/');
$CLAVE = (string)$cfg['clave'];
$WINCORE = rtrim((string)($cfg['wincore'] ?? 'https://wincore.win.pe'), '/');
$usuario = trim(preg_replace('/^﻿/', '', (string)fgets(STDIN))); // sin la marca UTF-8 que Windows antepone a veces
$contrasena = rtrim((string)fgets(STDIN), "\r\n");
if ($usuario === '' || $contrasena === '') { $decir('Escribe tu usuario y tu contraseña de Wincore.'); exit(3); }

// Certificados: los del paquete de la laptop (php/cacert.pem) o, en una PC con XAMPP, los suyos.
$CA = [];
foreach ([__DIR__ . '/php/cacert.pem', 'C:/xampp/apache/bin/curl-ca-bundle.crt'] as $archivoCa) if (is_file($archivoCa)) { $CA = [CURLOPT_CAINFO => $archivoCa]; break; }
// Certificado de Wincore (2026-11-20): Chrome lo acepta, pero el programa no lo validaba ("unable to get local issuer
// certificate"). Se arma una lista propia con: la del paquete + la cadena que Windows ya validó (la guarda el lanzador en
// wincore_ca.pem: cubre entidades internas de la empresa o el antivirus) + el almacén de Windows + los emisores intermedios que
// falten, descargados de la dirección que trae el mismo certificado (CA Issuers), como hace Chrome. La verificación NUNCA se
// apaga: un certificado que no encadena a una entidad confiable se sigue rechazando.
$listaWincore = static function (string $url, array $base, string $carpeta): array {
    $ca = $base + (defined('CURLSSLOPT_NATIVE_CA') ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA] : []);
    if ($carpeta === '' || empty($base[CURLOPT_CAINFO])) return $ca;
    $propia = $carpeta . '/wincore_lista.pem';
    $texto = (string)file_get_contents($base[CURLOPT_CAINFO]) . "\n" . (string)@file_get_contents($carpeta . '/wincore_ca.pem');
    file_put_contents($propia, $texto);
    $ca[CURLOPT_CAINFO] = $propia;
    $probar = static function () use ($url, &$ca): int {
        $ch = curl_init($url);
        curl_setopt_array($ch, $ca + [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15]);
        curl_exec($ch); $e = curl_errno($ch); unset($ch); return $e;
    };
    if ($probar() !== 60) return $ca;                // 60 = el certificado no se pudo verificar
    // Solo para saber dónde está su emisor: se leen los certificados que presenta (no se envía nada ni se confía en ellos)
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_CERTINFO => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); $pendientes = array_column((array)curl_getinfo($ch, CURLINFO_CERTINFO), 'Cert'); unset($ch);
    $extra = '';
    for ($i = 0; $i < 6 && $pendientes; $i++) {
        $x = openssl_x509_parse(array_shift($pendientes));
        if (!$x || !preg_match('~CA Issuers - URI:(\S+)~', (string)($x['extensions']['authorityInfoAccess'] ?? ''), $m)) continue;
        $der = @file_get_contents($m[1], false, stream_context_create(['http' => ['timeout' => 10]]));
        if (!$der) continue;
        $pem = str_contains($der, 'BEGIN CERTIFICATE') ? $der : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        if (!openssl_x509_parse($pem) || str_contains($extra, trim($pem))) continue;
        $extra .= trim($pem) . "\n";
        $pendientes[] = $pem;                        // su emisor también, por si falta otro nivel
    }
    if ($extra !== '') file_put_contents($propia, $texto . "\n" . $extra);
    return $ca;
};
$CAWC = $listaWincore($WINCORE . '/', $CA, $REGISTRO !== '' ? dirname($REGISTRO) : '');
$jarWincore = tempnam(sys_get_temp_dir(), 'vwc');   // sesión de Wincore y del SGS: solo mientras corre el programa
$jarSgs = tempnam(sys_get_temp_dir(), 'vws');
register_shutdown_function(static function () use ($jarWincore, $jarSgs) { @unlink($jarWincore); @unlink($jarSgs); });

// ── Wincore ──────────────────────────────────────────────────────────────────
final class SinRed extends RuntimeException {}      // no se llegó a Wincore (VPN caída o sin internet)
final class SinSesion extends RuntimeException {}   // Wincore respondió, pero sin datos: la sesión venció

$wincoreHttp = static function (string $ruta, string $cuerpo, string $tipo) use ($WINCORE, $CAWC, $jarWincore): string {
    $ch = curl_init($WINCORE . $ruta);
    curl_setopt_array($ch, $CAWC + [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEFILE => $jarWincore, CURLOPT_COOKIEJAR => $jarWincore,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $tipo, 'X-Requested-With: XMLHttpRequest', 'Origin: ' . $WINCORE, 'Referer: ' . $WINCORE . '/'],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/152.0 Safari/537.36 SGS-VinculoWincore']);
    $r = curl_exec($ch);
    $err = curl_error($ch);
    unset($ch); // escribe las cookies
    if ($r === false) throw new SinRed('No se pudo llegar a Wincore (' . $err . '). ¿Está conectada la VPN?');
    return (string)$r;
};
$rpc = static function (string $metodo, array $params) use ($wincoreHttp): array {
    $t = $wincoreHttp('/php/index2.php', json_encode(['method' => $metodo, 'params' => $params], JSON_UNESCAPED_UNICODE), 'application/x-www-form-urlencoded; charset=UTF-8');
    $d = json_decode($t, true);
    if (!is_array($d)) throw new SinSesion('Wincore no respondió datos: la sesión de Wincore pudo vencer.');
    return $d;
};

$CAMPOS = ['tx_mensaje_activacion', 'tx_cto_nap', 'txt_estado_cto_nap', 'tx_cluster', 'tx_direccion', 'tx_distrito', 'tx_provincia',
    'tx_departamento', 'tx_puertos_cto', 'tx_lista_puertos', 'btn_consultar_potencia', 'file_ordenamiento', 'tx_lista_img', 'tx_n_pedido',
    'tx_id_regtra', 'no_cliente', 'nu_documento', 'tx_dir_direccion', 'tx_dir_lat', 'tx_dir_lon', 'tx_puerto', 'tx_estado_puerto',
    'bt_act_cliente', 'bt_act_cancelar', 'tx_task_observaciones', 'nu_task_groupfile', 'tx_id_sitio', 'tx_user_name_actualizo',
    'tx_fh_crea_actualizo', 'tx_in_estado', 'cod_sitio', 'tx_nodo', 'tx_mac_registradas', 'tx_id_cto', 'tx_lat_cto', 'tx_lon_cto',
    'tx_json_puertos_ini', 'tx_json_puertos', 'ap_cliente', 'tx_email', 'tx_celular', 'tx_id_cajas', 'tx_json_lista_img', 'ticked'];
$parametros = static function (array $extra) use ($CAMPOS, &$ticked): array {
    $p = array_fill_keys($CAMPOS, '');
    foreach ($extra as $k => $v) $p[$k] = $v ?? '';
    $p['ticked'] = $ticked;
    return $p;
};

/** Consulta de una CTO: estado y ubicación de la CTO y, por puerto, número, estado, servicio y potencia (sin clientes). */
$consultar = static function (string $cod) use ($rpc, $parametros, &$ticked): array {
    $info = $rpc('logordenamientoplanta.getdatacto', $parametros(['tx_cto_nap' => $cod, 'cod_sitio' => $cod]));
    $cto = $info['data'][0] ?? null;
    if (!is_array($cto)) {
        if (!isset($info['data'])) throw new SinSesion('Wincore no respondió datos: la sesión de Wincore pudo vencer.');
        throw new RuntimeException('No se encontró la CTO ' . $cod . ' en Wincore.');
    }
    $sitio = $cto['tx_id_sitio'] ?? $cto['id_sitio'] ?? $cto['tx_id_cto'] ?? $cto['id_cto'] ?? '';
    $extra = $cto; $extra['tx_cto_nap'] = $cod; $extra['cod_sitio'] = $cod; $extra['id_sitio'] = $sitio; $extra['tx_id_sitio'] = $sitio;
    $pr = $rpc('logordenamientoplanta.getpuertos', $parametros($extra));
    $lista = is_array($pr['data'] ?? null) ? $pr['data'] : [];
    if (!$lista) throw new RuntimeException('Wincore no devolvió los puertos de la CTO ' . $cod . '.');
    $p0 = $lista[0];
    $macDe = static fn(array $p): string => trim((string)($p['mac_prefijo_activacion'] ?? ''));
    // Potencia y estado en vivo de cada puerto ocupado, por su MAC de activación. Si todo vino en 0, se pregunta una vez más.
    $potencias = [];
    $macs = [];
    foreach ($lista as $p) if (($p['estado_puerto'] ?? '') === 'OCUPADO' && $macDe($p) !== '') $macs[] = ['nserie' => $macDe($p)];
    if ($macs) {
        try {
            for ($intento = 1; $intento <= 2; $intento++) {
                $po = $rpc('logconodered.getInfoPotenciaByMacs', ['allnserie' => $macs, 'ticked' => $ticked]);
                $potencias = []; $conValor = 0;
                foreach ((array)($po['data'] ?? []) as $d) {
                    if (empty($d['mac_serie'])) continue;
                    $red = strtolower((string)($d['estado_red'] ?? ''));
                    $valor = $red === 'inactive' ? 0 : ($d['potencia_ont'] ?? '');
                    $potencias[strtoupper((string)$d['mac_serie'])] = ['valor' => $valor ?? '', 'red' => $red];
                    if ((float)$valor) $conValor++;
                }
                if ($conValor || $intento === 2) break;
                sleep(3);
            }
        } catch (SinRed | SinSesion $e) { throw $e; } catch (Throwable $e) { $potencias = []; } // sin potencia, igual se muestran los puertos
    }
    // Estados como en la supervisión en vivo de Wincore (2026-11-13): OCUPADO sin MAC = DISPONIBLE; activo o suspendido según
    // el estado en vivo (estado_red), y si no llegó, según estado_unifilar.
    $puertos = [];
    foreach ($lista as $p) {
        $ocupado = ($p['estado_puerto'] ?? '') === 'OCUPADO';
        $mac = $ocupado ? $macDe($p) : '';
        $pot = $mac !== '' ? ($potencias[strtoupper($mac)] ?? null) : null;
        if ($ocupado && $mac === '') { $puertos[] = ['puerto' => $p['puerto'] ?? '', 'estado' => 'DISPONIBLE', 'servicio' => '', 'potencia' => '']; continue; }
        $servicio = $pot && $pot['red'] === 'active' ? 'ACTIVO' : ($pot && $pot['red'] === 'inactive' ? 'SUSPENDIDO' : (string)($p['estado_unifilar'] ?? ''));
        $puertos[] = ['puerto' => $p['puerto'] ?? '', 'estado' => $p['estado_puerto'] ?? '', 'servicio' => $mac !== '' ? $servicio : (string)($p['estado_unifilar'] ?? ''),
            'potencia' => $pot ? $pot['valor'] : ''];
    }
    return ['cto' => ['estado' => $cto['txt_estado_cto_nap'] ?? '', 'objeto' => $p0['objeto'] ?? '', 'direccion' => $cto['tx_direccion'] ?? '',
        'distrito' => $cto['tx_distrito'] ?? '', 'provincia' => $cto['tx_provincia'] ?? '', 'departamento' => $cto['tx_departamento'] ?? '',
        'lat' => $cto['tx_lat_cto'] ?? ($p0['n_latcto'] ?? ''), 'lon' => $cto['tx_lon_cto'] ?? ($p0['n_loncto'] ?? '')], 'puertos' => $puertos];
};

// ── SGS ──────────────────────────────────────────────────────────────────────
$sgs = static function (string $accion, ?array $post = null) use ($SGS, $CLAVE, $CA, $jarSgs): array {
    $ch = curl_init($SGS . '/api/puertos_agente.php?action=' . $accion);
    $op = $CA + [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEFILE => $jarSgs, CURLOPT_COOKIEJAR => $jarSgs,
        CURLOPT_HTTPHEADER => ['X-SGS-Clave: ' . $CLAVE, 'Accept: application/json'], CURLOPT_USERAGENT => 'SGS-VinculoWincore'];
    // Siempre POST con la clave también en el cuerpo (2026-11-20): algunos hostings o Cloudflare quitan la cabecera X-SGS-Clave.
    $op += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(($post ?? []) + ['clave' => $CLAVE])];
    curl_setopt_array($ch, $op);
    $r = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    if ($r === false) throw new SinRed('No se pudo conectar con el SGS. Revisa tu internet.');
    $d = json_decode((string)$r, true) ?? [];
    if ($codigo === 200 && !$d) throw new RuntimeException("La dirección del SGS no es la correcta ($SGS): respondió una página en lugar del programa. Borra %APPDATA%\SGS\vinculo_wincore.json y escribe la dirección sin index.php.");
    // Clave anulada o sin permiso (401/403): no tiene sentido reintentar. El hosting ocupado o caído (5xx, 429…): se reintenta.
    if (in_array($codigo, [401, 403], true)) throw new RuntimeException($d['message'] ?? "El SGS rechazó el programa ($codigo).");
    if ($codigo !== 200 || ($d['status'] ?? '') !== 'success') throw new SinRed($d['message'] ?? "El SGS no respondió bien ($codigo).");
    return $d;
};

// Cómo termina el programa (2026-11-20): el vigilante (vigilante.ps1) lee este código para saber si vuelve a entrar solo.
//   0 = lo detuvo el usuario (Detener, o Desactivar en el SGS) · 2 = clave del programa anulada o sin permiso · 3 = Wincore no
//   aceptó el usuario o la contraseña · 4 = se cerró la sesión de Wincore o venció el vínculo (vuelve a entrar en 15 s) ·
//   5 = sin red, VPN o SGS (reintenta cada minuto).
const SALIDA_DETENIDO = 0, SALIDA_CLAVE = 2, SALIDA_CONTRASENA = 3, SALIDA_SESION = 4, SALIDA_RED = 5;

// Antes de entrar a Wincore se comprueba la clave del programa (si fue anulada, no tiene sentido iniciar sesión)
try { $previo = $sgs('tomar'); } catch (Throwable $e) {
    $contrasena = '';
    $decir('El SGS no aceptó el programa: ' . $e->getMessage());
    exit($e instanceof SinRed ? SALIDA_RED : SALIDA_CLAVE);
}

// 2026-11-21: si otra laptop ya tiene el vínculo, se espera sin iniciar sesión en Wincore (el vigilante reintenta cada minuto).
if (!empty($previo['otra_laptop'])) {
    $contrasena = '';
    $decir('Otra laptop ya tiene activo el vínculo con Wincore: se espera a que se desconecte.');
    exit(SALIDA_RED);
}

// 1) Inicio de sesión, igual que la pantalla de ingreso de Wincore (index2.html)
$decir('Iniciando sesión en Wincore…');
try {
    $t = $rpc('loglogin.createtickedtodevice', ['deviceid' => '1234567890']);
    $ticked = (string)($t['data']['id'] ?? '');
    if ($ticked === '') throw new RuntimeException('Wincore no entregó el permiso de ingreso.');
    $l = $rpc('loglogin.login', ['deviceid' => '1234567890', 'ticked' => $ticked, 'userid' => $usuario, 'password' => $contrasena]);
    $contrasena = '';                               // la contraseña ya no se necesita
    if ((string)($l['status'] ?? '') !== '0') {
        $decir('Wincore no aceptó el ingreso: ' . trim((string)($l['message'] ?? 'usuario o contraseña incorrectos')));
        exit(SALIDA_CONTRASENA);
    }
    $pagina = $wincoreHttp('/dataTables.php', http_build_query(['controlkey' => (string)($l['controlkey'] ?? ''), 'returndata' => json_encode($l['data'][0] ?? new stdClass(), JSON_UNESCAPED_UNICODE)]), 'application/x-www-form-urlencoded');
    // El token que usan las pantallas de Wincore (3 códigos md5 separados por punto); si la página no lo trae, el del ingreso.
    if (preg_match('/[0-9a-f]{32}\.[0-9a-f]{32}\.[0-9a-f]{32}/i', $pagina, $m)) $ticked = $m[0];
    unset($l, $pagina);
} catch (Throwable $e) {
    $contrasena = '';
    $decir($e->getMessage());
    exit($e instanceof SinRed ? SALIDA_RED : SALIDA_SESION);
}
$decir('Sesión de Wincore iniciada como ' . $usuario . '.');

$parar = false;
if (function_exists('sapi_windows_set_ctrl_handler')) sapi_windows_set_ctrl_handler(static function () use (&$parar) { $parar = true; });
if (function_exists('pcntl_signal')) { pcntl_async_signals(true); pcntl_signal(SIGINT, static function () use (&$parar) { $parar = true; }); }

try {
    $v = $sgs('vincular', []);
} catch (Throwable $e) {
    $decir('El SGS no aceptó el vínculo: ' . $e->getMessage());
    exit($e instanceof SinRed ? SALIDA_RED : SALIDA_CLAVE);
}
$sondeo = max(3, (int)($v['sondeo'] ?? 5));
$decir('Vinculado con el SGS hasta las ' . $v['vence'] . '. Los supervisores ya pueden consultar puertos.');
$decir($REGISTRO !== '' ? 'Funciona en segundo plano. Para detenerlo: acceso directo «Detener Vínculo Wincore» o Desactivar en el SGS.'
    : 'Deja esta ventana abierta (puedes minimizarla). Para detener: Ctrl + C o cierra la ventana.');

// Señal de vida a Wincore (2026-11-20): cada 8 min una consulta mínima (una CTO que no existe) para que no cierre la sesión por no
// usarla; si responde sin datos, la sesión ya se cerró y se sale para que el vigilante vuelva a entrar solo.
$VIDA = 480; $ultimaVida = time();
$vida = static function () use ($rpc, $parametros): void {
    $r = $rpc('logordenamientoplanta.getdatacto', $parametros(['tx_cto_nap' => 'SGS-VIDA', 'cod_sitio' => 'SGS-VIDA']));
    if (!array_key_exists('data', $r)) throw new SinSesion('Wincore no respondió datos: la sesión de Wincore pudo vencer.');
};

$atendidas = 0; $fallosRed = 0; $fallosSgs = 0; $motivo = ''; $salida = SALIDA_DETENIDO;
$pedirParar = static fn(): bool => $DETENER !== '' && is_file($DETENER);
while (!$parar && !($parar = $pedirParar())) {
    try {
        $t = $sgs('tomar');
        $fallosSgs = 0;
    } catch (Throwable $e) {
        if (++$fallosSgs >= 6) { $motivo = 'No hay conexión con el SGS hace un buen rato: ' . $e->getMessage(); $salida = $e instanceof SinRed ? SALIDA_RED : SALIDA_CLAVE; break; }
        $decir('Sin respuesta del SGS (' . $e->getMessage() . '). Reintentando…');
        sleep($sondeo); continue;
    }
    if (empty($t['activo'])) {
        if (!empty($t['desactivado'])) { $motivo = 'Desactivaste el vínculo en el SGS.'; $salida = SALIDA_DETENIDO; }
        else { $motivo = 'El vínculo venció en el SGS: se vuelve a activar.'; $salida = SALIDA_SESION; }
        break;
    }
    if (!empty($t['consulta'])) {
        $c = $t['consulta'];
        try {
            $datos = $consultar((string)$c['codigo']);
            $fallosRed = 0; $ultimaVida = time();
            $decir('Consulta ' . $c['codigo'] . ': ' . count($datos['puertos']) . ' puertos.' . ($t['en_espera'] ? ' En espera: ' . $t['en_espera'] . '.' : ''));
        } catch (SinSesion $e) {
            try { $sgs('responder', ['id' => $c['id'], 'datos' => json_encode(['error' => 'La consulta de puertos se interrumpió. Vuelve a intentarlo en un minuto.'])]); } catch (Throwable $_) {}
            $motivo = 'La sesión de Wincore se cerró: se vuelve a entrar solo.'; $salida = SALIDA_SESION; break;
        } catch (SinRed $e) {
            $datos = ['error' => 'Wincore no respondió. Vuelve a intentarlo en un momento.'];
            $decir($e->getMessage());
            if (++$fallosRed >= 3) {
                try { $sgs('responder', ['id' => $c['id'], 'datos' => json_encode($datos)]); } catch (Throwable $_) {}
                $motivo = 'Wincore no responde (¿se cayó la VPN?): se reintenta solo cada minuto.'; $salida = SALIDA_RED; break;
            }
        } catch (Throwable $e) {
            $datos = ['error' => $e->getMessage()];
            $decir('Consulta ' . $c['codigo'] . ': ' . $e->getMessage());
        }
        try { $sgs('responder', ['id' => $c['id'], 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE)]); $atendidas++; } catch (Throwable $e) { $decir('No se pudo entregar el resultado: ' . $e->getMessage()); }
        continue; // la siguiente de la cola sin esperar
    }
    if (time() - $ultimaVida >= $VIDA) {
        $ultimaVida = time();
        try { $vida(); }
        catch (SinSesion $e) { $motivo = 'La sesión de Wincore se cerró: se vuelve a entrar solo.'; $salida = SALIDA_SESION; break; }
        catch (SinRed $e) { $motivo = 'Wincore no responde (¿se cayó la VPN?): se reintenta solo cada minuto.'; $salida = SALIDA_RED; break; }
        catch (Throwable $e) { /* una respuesta rara: se vuelve a probar en la próxima señal */ }
    }
    for ($i = 0; $i < $sondeo * 4 && !$parar && !$pedirParar(); $i++) usleep(250000);
}
if ($salida === SALIDA_DETENIDO) {
    // Lo detuvo el usuario: el SGS lo marca como apagado a propósito (así el programa no vuelve a entrar solo).
    try { $sgs('desactivar', ['usuario' => '1']); } catch (Throwable $_) { /* el SGS lo apaga solo a los 20 s sin pulso */ }
}
if ($DETENER !== '') @unlink($DETENER);
$decir(($motivo ?: 'Programa detenido.') . " Consultas atendidas: $atendidas.");
exit($salida);
