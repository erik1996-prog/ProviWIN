<?php // 02_BACKEND/src/config/inicio_ordenes_whatsapp.php - WhatsApp de Inicio de órdenes (holainge), APARTE de Telegram (2026-11-20).
// Pedido del usuario: a su número le llegan reportes GENERALES (todos los partners de GESTIÓN PROVINCIA juntos, agrupados por partner):
//  - 'primera' (08:06): cuadrillas que iniciaron tarde su primera atención (después de las 08:05:59: Seguimiento e Incumple) y las que
//    aún no inician. Sin saludo ni cierre.
//  - 'alertas' (10:30, 12:30, 14:30, 16:30, 18:30, los mismos cortes de Telegram): órdenes iniciadas hace 2 h o más (🔴 3 h o más · 🟡 2 a 3 h).
// No toca Telegram. Destino y cortes: 09_CONFIG/inicio_ordenes_whatsapp.json. Token: INERCIA_TOKEN en el .env.
// API: POST https://whatsapp.holainge.com/api/v1/mensajes/texto, Bearer, {"numero","texto"}, Idempotency-Key; solo texto
// (las imágenes no llegan) y máximo 4096 caracteres por mensaje. Formato WhatsApp: *negrita*, _cursiva_ (sin HTML).
require_once __DIR__ . '/inicio_ordenes_telegram.php';   // sgsIoPartnerBase, sgsIoCuadrillaSinPartner, sgsIoTitulo, sgsIoIniciadas2h, sgsIoDur

const SGS_IO_WHATSAPP_CONFIG = BASE_PATH . '/09_CONFIG/inicio_ordenes_whatsapp.json';
const SGS_IO_WHATSAPP_URL = 'https://whatsapp.holainge.com/api/v1/mensajes/texto';
const SGS_IO_WHATSAPP_MAX = 3900;                          // margen bajo el límite de 4096
const SGS_IO_WHATSAPP_REPORTES = ['primera', 'alertas'];

/** Config: 'cortes' = {"HH:MM": "primera"|"alertas"} (una lista simple de horas vale como "primera"). */
function sgsIoWhatsappConfig(): array {
    $d = json_decode((string)@file_get_contents(SGS_IO_WHATSAPP_CONFIG), true);
    $d = is_array($d) ? $d : [];
    $nums = array_values(array_filter(array_map(static fn($n) => preg_replace('/\D/', '', (string)$n), (array)($d['numeros'] ?? []))));
    $cortes = [];
    foreach ((array)($d['cortes'] ?? []) as $k => $v) {
        [$hora, $rep] = is_int($k) ? [(string)$v, 'primera'] : [(string)$k, (string)$v];
        if (preg_match('/^\d{2}:\d{2}$/', $hora) && in_array($rep, SGS_IO_WHATSAPP_REPORTES, true)) $cortes[$hora] = $rep;
    }
    ksort($cortes);
    return ['activo' => ($d['activo'] ?? true) !== false, 'numeros' => $nums, 'cortes' => $cortes];
}

function sgsIoWhatsappConfigurado(): bool {
    $c = sgsIoWhatsappConfig();
    return (bool)getenv('INERCIA_TOKEN') && $c['activo'] && $c['numeros'] && $c['cortes'];
}

/** Texto seguro para WhatsApp: sin los signos de formato (* _ ~ `) dentro de los nombres. */
function sgsIoWa($s): string { return trim((string)preg_replace('/[*_~`]+/u', ' ', (string)$s)); }

/** "P 30 LUIS DIAZ (SGI)": la cuadrilla sin el nombre del partner (ya va en el subtítulo). */
function sgsIoWaCuadrilla(array $c, string $partner): string {
    return sgsIoWa(sgsIoCuadrillaSinPartner((string)($c['cuadrilla'] ?? ''), $partner)) . (($c['grupo'] ?? '') !== '' ? ' (' . $c['grupo'] . ')' : '');
}

function sgsIoWaOt($orden): string { $n = sgsIoWa($orden); return $n !== '' ? 'OT ' . $n : 'OT —'; }

/** Una sección agrupada por partner (alfabético, numeración seguida). $linea($x) = detalle tras la cuadrilla. */
function sgsIoWaPorPartner(array $lista, callable $linea): array {
    $por = [];
    foreach ($lista as $x) $por[$x['partner']][] = $x;
    ksort($por, SORT_STRING);
    $L = []; $n = 0;
    foreach ($por as $p => $xs) {
        $L[] = '';
        $L[] = '▪️ *' . sgsIoWa($p) . '* (' . count($xs) . ')';
        foreach ($xs as $x) $L[] = (++$n) . '. ' . sgsIoWaCuadrilla($x, $p) . ' · ' . $linea($x);
    }
    return $L;
}

/** Junta las líneas en mensajes de hasta SGS_IO_WHATSAPP_MAX caracteres ("Parte n de N" si son varios). */
function sgsIoWaPartir(array $lineas): array {
    $msgs = []; $actual = '';
    foreach ($lineas as $l) {
        if ($actual !== '' && mb_strlen($actual . "\n" . $l) > SGS_IO_WHATSAPP_MAX) { $msgs[] = rtrim($actual); $actual = ''; }
        $actual .= ($actual === '' ? '' : "\n") . $l;
    }
    $msgs[] = rtrim($actual);
    $n = count($msgs);
    if ($n > 1) foreach ($msgs as $i => &$m) $m = '_Parte ' . ($i + 1) . ' de ' . $n . "_\n" . $m;
    unset($m);
    return $msgs;
}

/**
 * 08:06 · Reporte general de primera atención: solo las cuadrillas que iniciaron TARDE su primera atención (después de las 08:05:59,
 * resultado Seguimiento o Incumple) y las que AÚN NO INICIAN. No van las que cumplieron ni las fuera de regla.
 */
function sgsIoArmarPrimeraWa(array $cuadrillas, DateTimeInterface $ahora): array {
    // 2026-11-20 (pedido del usuario): más simple. Una línea por cuadrilla que NO cumple, por partner; sin saludo, resumen ni colores.
    // Solo "Aún en camino desde las HH:MM, no inicia" e "Inició fuera de horario HH:MM" (desde las 08:06:00; Seguimiento e Incumple cuentan igual).
    $por = [];
    foreach ($cuadrillas as $c) {
        if (($c['estado'] ?? '') === 'Inició') {
            if (!in_array($c['resultado'] ?? '', ['Seguimiento', 'Incumple'], true) || strcmp((string)$c['inicio'], SGS_IO_HORA_PRIMERA) <= 0) continue;
            [$orden, $txt] = ['3' . $c['inicio'], 'Inició fuera de horario ' . substr((string)$c['inicio'], 0, 5)];
        } else {
            // Regla de primera atención (como Telegram): solo las que pusieron su primer En camino y no iniciaron; las que no
            // pusieron En camino no aparecen, ni las fuera de regla (agendada después, sin agenda previa, reprogramada).
            if (empty($c['camino']) || preg_match('/^(Agendada después|Sin agenda previa|Reprogramada)/u', (string)($c['motivo'] ?? ''))) continue;
            [$orden, $txt] = ['1' . $c['camino'], 'Aún en camino desde las ' . substr((string)$c['camino'], 0, 5) . ', no inicia'];
        }
        $por[sgsIoPartnerBase($c['proveedor'] ?? '')][] = ['k' => $orden . ' ' . $c['cuadrilla'], 'linea' => sgsIoWa($c['cuadrilla'] ?? '') . ' — ' . $txt];
    }
    ksort($por, SORT_STRING);
    $L = ['*Reporte general de primera atención*', 'Corte ' . $ahora->format('H:i') . ' · ' . $ahora->format('d/m/Y')];
    if (!$por) $L[] = "\nTodas las cuadrillas iniciaron a tiempo.";
    foreach ($por as $p => $xs) {
        usort($xs, static fn($a, $b) => strcmp($a['k'], $b['k']));     // en camino, no inicia, inició fuera de horario
        array_push($L, '', '*' . sgsIoWa($p) . '*');
        foreach ($xs as $x) $L[] = $x['linea'];
    }
    return sgsIoWaPartir($L);
}

/** 10:30 / 12:30 / 14:30 / 16:30 / 18:30 · Alertas de órdenes iniciadas hace 2 h o más, todos los partners juntos (misma lógica de Telegram). */
function sgsIoArmarAlertasWa(array $enCurso, DateTimeInterface $ahora): array {
    $ords = sgsIoIniciadas2h($enCurso, $ahora);
    usort($ords, static fn($a, $b) => $b['m'] <=> $a['m']);
    $crit = array_values(array_filter($ords, static fn($o) => $o['m'] >= 180));
    $aler = array_values(array_filter($ords, static fn($o) => $o['m'] < 180));
    $L = ['*Reporte general de alertas · órdenes iniciadas hace 2 h o más*', '📅 ' . $ahora->format('d/m/Y') . ' · corte ' . $ahora->format('H:i'), ''];
    if (!$ords) { $L[] = '✅ No hay órdenes iniciadas hace más de 2 horas.'; return sgsIoWaPartir($L); }
    $L[] = '📊 Resumen: 🔴 ' . count($crit) . ' crítica' . (count($crit) !== 1 ? 's' : '') . ' · 🟡 ' . count($aler) . ' en alerta';
    $det = static fn($o) => sgsIoWaOt($o['orden'] ?? '') . ' · ' . sgsIoWa(sgsIoTitulo($o['provincia'] ?? null)) . ' · *' . sgsIoDur($o['m']) . '* (desde ' . substr((string)$o['desde'], 11, 5) . ')';
    if ($crit) { array_push($L, '', '🔴 *Crítico · 3 horas o más (' . count($crit) . ')*'); $L = array_merge($L, sgsIoWaPorPartner($crit, $det)); }
    if ($aler) { array_push($L, '', '🟡 *En alerta · entre 2 y 3 horas (' . count($aler) . ')*'); $L = array_merge($L, sgsIoWaPorPartner($aler, $det)); }
    return sgsIoWaPartir($L);
}

/** Mensajes del reporte elegido con el tablero del momento. */
function sgsIoMensajesWa(array $tablero, string $reporte, DateTimeInterface $ahora): array {
    return $reporte === 'alertas' ? sgsIoArmarAlertasWa($tablero['enCurso'] ?? [], $ahora) : sgsIoArmarPrimeraWa($tablero['cuadrillas'] ?? [], $ahora);
}

/** Envía un texto por holainge. Lanza excepción con un mensaje claro si no se pudo. */
function sgsIoWhatsappEnviar(string $numero, string $texto, string $idempotencia): void {
    $token = (string)getenv('INERCIA_TOKEN');
    if ($token === '') throw new RuntimeException('Falta el token de WhatsApp (INERCIA_TOKEN en el .env).');
    $ultimo = '';
    for ($intento = 1; $intento <= 2; $intento++) {
        $ch = curl_init(SGS_IO_WHATSAPP_URL);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json', 'Idempotency-Key: ' . $idempotencia],
            CURLOPT_POSTFIELDS => json_encode(['numero' => $numero, 'texto' => $texto], JSON_UNESCAPED_UNICODE)]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) return;
        $d = is_string($resp) ? json_decode($resp, true) : null;
        $ultimo = $resp === false ? "sin conexión ($err)" : "HTTP $code" . (is_array($d) ? ': ' . mb_substr((string)($d['mensaje'] ?? $d['message'] ?? $d['error'] ?? ''), 0, 160) : '');
        if ($resp !== false && $code < 500 && $code !== 429) break;          // error del pedido: no sirve repetir
        sleep(2);
    }
    throw new RuntimeException('No se pudo enviar por WhatsApp (' . $ultimo . ').');
}

/** Reporte a todos los números configurados. $clave identifica el envío (día + corte) para no duplicar. */
function sgsIoWhatsappEnviarReporte(array $tablero, string $reporte, string $clave, ?DateTimeInterface $ahora = null): array {
    $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $msgs = sgsIoMensajesWa($tablero, $reporte, $ahora);
    $res = [];
    foreach (sgsIoWhatsappConfig()['numeros'] as $num) {
        foreach ($msgs as $i => $m) {
            $r = ['reporte' => $reporte, 'numero' => substr($num, 0, 4) . '…' . substr($num, -3), 'parte' => $i + 1];
            try {
                sgsIoWhatsappEnviar($num, $m, 'proviwin-io-' . $clave . '-' . $num . '-' . ($i + 1));
                $res[] = $r + ['ok' => true];
            } catch (Throwable $ex) {
                $res[] = $r + ['ok' => false, 'error' => $ex->getMessage()];
            }
            usleep(500000);
        }
    }
    return $res;
}
