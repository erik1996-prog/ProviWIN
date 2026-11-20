<?php
// 02_BACKEND/src/config/inicio_ordenes_telegram.php - Reportes de "Inicio de órdenes" a los grupos de Telegram (2026-11-19).
// Copia fiel de inicio-ordenes/core/telegram.py (formato aprobado por el usuario el 19/11):
//   · "alertas": órdenes iniciadas hace 2 h o más (🔴 3 h o más · 🟡 2 a 3 h). Sale solo en los cortes 10:30, 12:30,
//     14:30, 16:30 y 18:30 (services/InicioOrdenesService.php).
//   · "facilidades": cancelaciones del día por facilidades técnicas (lista fija SGS_IO_FACILIDADES).
// Token: TELEGRAM_TOKEN del .env (nunca en otros archivos ni en logs).
// Grupos: 09_CONFIG/inicio_ordenes_telegram.json → {"grupos": [{"nombre", "chat_id", "partners": [...] o ["*"],
//         "tipo": "SGI" | "SGA" | "", "menciones": {"codigo_telegram": "Nombre"}}],
//         "partners": {"OLMA": {"menciones": {"codigo_telegram": "Nombre"}}}}
//         ("*" = todos los partners; tipo SGA incluye TRASLADO, como en todo el sistema). Un mensaje por partner, con las
//         menciones del grupo más las de ese partner.
require_once __DIR__ . '/inicio_ordenes.php';      // sgsIoSinTildes, sgsIoCapitalizar

const SGS_IO_TELEGRAM_GRUPOS = BASE_PATH . '/09_CONFIG/inicio_ordenes_telegram.json';
const SGS_IO_LINEA = '━━━━━━━━━━━━━━━━━━';
const SGS_IO_REPORTES = ['primera' => 'Primera orden sin iniciar (08:05)', 'alertas' => 'Alertas de iniciadas (2 h o más)',
                         'facilidades' => 'Facilidades técnicas del día'];
const SGS_IO_HORA_PRIMERA = '08:05:59';     // la primera orden debía iniciar hasta las 08:05 (Cumple)

// Facilidades técnicas (19/11, criterio del usuario: el técnico va y NO PUEDE hacer el trabajo por algo físico, de red, del predio
// o de permisos; no cuentan las causas del cliente). Lista fija de motivos de cancelación de Winbo, revisados uno por uno sobre el
// mes 05/10–04/11. Se compara sin tildes ni signos; "empieza con" para variantes como "... NAP DUCTOS OBSTRUÍDOS -VT". Dudosos que
// el usuario aún no aprobó (quedan FUERA): zona sin cobertura por error comercial, predio no habilitado por error comercial, zona
// peligrosa, clima, "atención compleja".
const SGS_IO_FACILIDADES = [
    'Red, CTO o NAP' => [
        'SIN NAP CERCANA. SE NECESITA AMPLIACIÓN', 'CTOS EXCEDEN METRAJE', 'SIN FACILIDADES - NAP DUCTOS OBSTRUÍDOS',
        'CTOS INEXISTENTES (EN CAMPO Y/O KMZ)', 'CTO O NAP SATURADA', 'CTO CRUZA AVENIDA DOBLE VIA',
        'CTO O NAP ROBADA', 'CTO O NAP CON PROBLEMAS DE POTENCIA EN PUERTOS'],
    'Predio o edificio' => [
        'EDIFICIO O CONDOMINIO NO HABILITADA NO PERMITE TENDIDO AEREO', 'HOGAR O MULTIFAMILIAR NO PERMITE TENDIDO AEREO',
        'SIN FACILIDADES - CENTRO HISTÓRICO NO PERMITE TENDIDO AEREO',
        'SIN FACILIDADES - MERCADOS / GALERIAS SIN FACILIDADES PARA LA INSTALACIÓN'],
    'Zona e infraestructura' => [
        'NO HAY POSTE DE APOYO', 'SIN FACILIDADES - CONSTRUCCIONES EN LA ZONA', 'ZONA AGRESTE - INSTALACION EN ZONA ELEVADA',
        'SIN FACILIDADES - RUTA DE INSTALACIÓN CON RIESGO DE CAÍDA POR SUPERFICIE/ESTRUCTURA FRÁGIL',
        'SIN FACILIDADES - ACCESOS RESTRINGIDOS POR AMBULANTES O REJAS', 'SIN FACILIDADES - PASAJES'],
    'Permisos y accesos' => [
        'PERMISOS - CTO ACCESO VECINOS/ TENDIDO AEREO EXTERNO', 'REPROGRAMACIÓN - POR FALTA DE PERMISOS Y ACCESOS',
        'REPROGRAMACION - PERMISOS INTERNOS - NAP ACCESOS MONTANTE/AZOTEA/SOTANO/OTROS',
        'PERMISOS - NAP ACCESOS MONTANTE / AZOTEA / SOTANO'],
];

/** Texto a bytes en Latin-1 (o Windows-1252); null si algún carácter no existe en esa tabla. */
function sgsIoBytes(string $s, bool $cp1252): ?string {
    static $t = [0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87,
                 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91,
                 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98,
                 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F];
    $out = '';
    foreach (mb_str_split($s) as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp < 0x80 || ($cp <= 0xFF && !($cp1252 && $cp < 0xA0))) $out .= chr($cp);
        elseif ($cp1252 && isset($t[$cp])) $out .= chr($t[$cp]);
        else return null;
    }
    return $out;
}

/** 'REPROGRAMACIÃ“N' → 'REPROGRAMACIÓN' (texto UTF-8 que Winbo entrega leído como Latin-1). */
function sgsIoArreglarTildes($s): string {
    $s = (string)($s ?? '');
    if (!str_contains($s, 'Ã') && !str_contains($s, 'Â')) return $s;
    foreach ([false, true] as $cp1252) {
        $b = sgsIoBytes($s, $cp1252);
        if ($b !== null && mb_check_encoding($b, 'UTF-8')) return $b;
    }
    return $s;
}

function sgsIoNormMotivo($s): string {
    $s = str_replace('�', '', sgsIoSinTildes(mb_strtoupper(sgsIoArreglarTildes($s), 'UTF-8')));
    return (string)preg_replace('/[^A-Z0-9]/', '', $s);
}

/** Categoría de facilidad técnica del motivo, o null si no es facilidad técnica. */
function sgsIoCategoriaFacilidad($motivo): ?string {
    $n = sgsIoNormMotivo($motivo);
    if ($n === '') return null;
    foreach (SGS_IO_FACILIDADES as $cat => $ms) foreach ($ms as $m) {
        $x = sgsIoNormMotivo($m);
        if ($n === $x || str_starts_with($n, $x)) return $cat;
    }
    return null;
}

function sgsIoTelegramGrupos(): array {
    $d = json_decode((string)@file_get_contents(SGS_IO_TELEGRAM_GRUPOS), true);
    return is_array($d['grupos'] ?? null) ? $d['grupos'] : [];
}

function sgsIoTelegramConfigurado(): bool { return (bool)getenv('TELEGRAM_TOKEN') && (bool)sgsIoTelegramGrupos(); }

function sgsIoPartnerBase($p): string {
    return trim((string)preg_replace('/\s+(SGA|SGI)$/u', '', mb_strtoupper((string)($p ?? ''), 'UTF-8'))) ?: 'SIN DATO';
}

/** html.escape de Python (también comillas). */
function sgsIoE($s): string {
    return strtr((string)$s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
}

/** str.title() de Python: cada tramo de letras con su primera en mayúscula. 'SAN JUAN' → 'San Juan'. */
function sgsIoTitulo($s): string {
    $s = (string)($s ?? '');
    if ($s === '' || $s === 'Sin dato') return '—';
    return (string)preg_replace_callback('/\p{L}+/u', static fn($m) => sgsIoCapitalizar($m[0]), $s);
}

function sgsIoDur(int $m): string { return intdiv($m, 60) . ' h ' . sprintf('%02d', $m % 60) . ' min'; }

function sgsIoSaludo(DateTimeInterface $ahora): string {
    $h = (int)$ahora->format('G');
    return $h < 12 ? 'buenos días' : ($h < 19 ? 'buenas tardes' : 'buenas noches');
}

function sgsIoMenciones(array $menciones): array {
    if (!$menciones) return [];
    $partes = [];
    foreach ($menciones as $uid => $n) $partes[] = '<a href="tg://user?id=' . (int)$uid . '">' . sgsIoE($n) . '</a>';
    return ['📣 ' . implode(' ', $partes)];
}

/** Cuenta y ordena de mayor a menor; en empate, el que apareció primero (Counter.most_common). */
function sgsIoMasComunes(array $valores): array {
    $c = [];
    foreach ($valores as $v) $c[$v] = ($c[$v] ?? 0) + 1;
    $orden = array_keys($c);
    $pos = array_flip($orden);
    usort($orden, static fn($a, $b) => $c[$b] <=> $c[$a] ?: $pos[$a] <=> $pos[$b]);
    return array_map(static fn($k) => [$k, $c[$k]], $orden);
}

function sgsIoIniciadas2h(array $enCurso, DateTimeInterface $ahora): array {
    $out = [];
    foreach ($enCurso as $o) {
        if (($o['estado'] ?? '') !== 'Iniciada') continue;
        $desde = new DateTimeImmutable($o['desde'], new DateTimeZone('America/Lima'));
        $m = intdiv($ahora->getTimestamp() - $desde->getTimestamp(), 60);
        if ($m >= 120) $out[] = $o + ['m' => $m, 'partner' => sgsIoPartnerBase($o['proveedor'] ?? '')];
    }
    return $out;
}

/** Cuadrilla sin el nombre de su partner ni SGI/SGA (2026-11-19, pedido del usuario: el grupo ya sabe de qué partner es). */
function sgsIoCuadrillaSinPartner(string $cuadrilla, string $partner): string {
    $s = $cuadrilla;
    if ($partner !== '' && $partner !== 'SIN DATO') $s = (string)preg_replace('/(?<!\S)' . preg_quote($partner, '/') . '(?!\S)/iu', ' ', $s);
    $s = (string)preg_replace('/(?<!\S)(SGI|SGA)(?!\S)/iu', ' ', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s)) ?: $cuadrilla;
}

/**
 * Número de OT en azul (2026-11-19, pedido del usuario): Telegram no permite colores, pero pinta de azul los hashtags. "#OT3290004"
 * sale azul y, al tocarlo, muestra los mensajes del grupo con esa misma OT. Sin número, "OT —".
 */
function sgsIoOt($orden): string {
    $n = preg_replace('/[^0-9A-Za-z_]/', '', (string)$orden);
    return $n === '' ? 'OT —' : '#OT' . $n;
}

/** "de <b>OLMA SGI</b> " · "<b>SGI</b> " · "" (grupo propio del partner sin tipo). */
function sgsIoDeQuien(string $nombre): string {
    if ($nombre === '') return '';
    return (in_array($nombre, ['SGI', 'SGA'], true) ? '' : 'de ') . '<b>' . sgsIoE($nombre) . '</b> ';
}

/**
 * Líneas de una lista agrupada por zona (solo la provincia, como título resaltado: Telegram no cambia el tamaño de letra, así que
 * va en mayúsculas, negrita y subrayado). Zonas en orden alfabético; numeración seguida. $detalle($o) = segunda línea de cada uno.
 */
function sgsIoPorZona(array $lista, string $unidad, string $unidades, callable $detalle): array {
    $zonas = [];
    foreach ($lista as $o) {
        $z = sgsIoTitulo($o['provincia'] ?? null);
        $zonas[$z !== '—' ? $z : 'Sin zona'][] = $o;
    }
    ksort($zonas, SORT_STRING | SORT_FLAG_CASE);
    $S = []; $n = 0;
    foreach ($zonas as $z => $os) {
        array_push($S, '', '📍 <b><u>' . sgsIoE(mb_strtoupper($z, 'UTF-8')) . '</u></b> · ' . count($os) . ' ' . (count($os) === 1 ? $unidad : $unidades));
        foreach ($os as $o)
            $S[] = (++$n) . '. <b>' . sgsIoE(sgsIoCuadrillaSinPartner((string)$o['cuadrilla'], (string)($o['partner'] ?? ''))) . "</b>\n     " . $detalle($o);
    }
    return $S;
}

/**
 * Texto HTML del reporte de alertas. $nombre = "OLMA SGI" (grupo con varios partners), "SGI"/"SGA" o "" (grupo propio del
 * partner). 2026-11-19 (pedido del usuario): dentro de 🔴 CRÍTICO y 🟡 EN ALERTA, las órdenes van agrupadas por zona
 * (solo la provincia, como título) y la cuadrilla sin el nombre del partner.
 */
function sgsIoArmarAlertas(array $ords, string $nombre, array $menciones, DateTimeInterface $ahora): string {
    $saludo = sgsIoSaludo($ahora);
    usort($ords, static fn($a, $b) => $b['m'] <=> $a['m']);
    $crit = array_values(array_filter($ords, static fn($o) => $o['m'] >= 180));
    $aler = array_values(array_filter($ords, static fn($o) => $o['m'] < 180));
    $L = sgsIoMenciones($menciones);
    array_push($L, "Estimados, $saludo.", 'Se envía el reporte del <b>tiempo de atención</b> de las cuadrillas ' . sgsIoDeQuien($nombre) . 'para la alerta correspondiente.',
        '📅 ' . $ahora->format('d/m/Y') . ' · corte ' . $ahora->format('H:i'), '');
    if (!$ords) { $L[] = '✅ No hay órdenes iniciadas hace más de 2 horas.'; return implode("\n", $L); }
    $L[] = '📊 <b>Resumen:</b> 🔴 ' . count($crit) . ' crítica' . (count($crit) !== 1 ? 's' : '') . ' · 🟡 ' . count($aler) . ' en alerta';
    $detalle = static fn($o) => sgsIoOt($o['orden']) . ' · Iniciada hace <b>' . sgsIoDur($o['m']) . '</b> (desde las ' . substr($o['desde'], 11, 5) . ')';
    $seccion = static fn(array $lista, string $cab, string $sub) => array_merge(['', SGS_IO_LINEA, $cab, "<i>$sub</i>"], sgsIoPorZona($lista, 'orden', 'órdenes', $detalle));
    if ($crit) $L = array_merge($L, $seccion($crit, '🔴 <b>CRÍTICO · 3 horas o más (' . count($crit) . ')</b>', 'Requieren atención inmediata.'));
    if ($aler) $L = array_merge($L, $seccion($aler, '🟡 <b>EN ALERTA · entre 2 y 3 horas (' . count($aler) . ')</b>', 'Próximas a llegar a 3 horas.'));
    array_push($L, '', SGS_IO_LINEA, 'Por favor, revisar estas órdenes y agilizar a las cuadrillas correspondientes.');
    return implode("\n", $L);
}

/**
 * Cuadrillas que a las 08:05 seguían En camino a su PRIMERA orden del día sin iniciarla (2026-11-19, pedido del usuario).
 * Salen del tablero de primera atención (universo: órdenes agendadas hasta las 07:30). Se mira cómo estaban a las 08:05:59 aunque el
 * reporte se arme después: si iniciaron más tarde también entran (con su hora de inicio). No entran las fuera de regla: agendadas
 * después de las 07:30, sin agenda previa, reprogramadas o canceladas antes de iniciar.
 */
function sgsIoPrimeraSinIniciar(array $cuadrillas): array {
    $out = [];
    foreach ($cuadrillas as $c) {
        // Fuera de regla (agendada después de las 07:30, sin agenda previa, reprogramada o cancelada antes de iniciar): no entra.
        if (!$c['camino'] || strcmp($c['camino'], SGS_IO_HORA_PRIMERA) > 0) continue;
        if (preg_match('/^(Agendada después|Sin agenda previa|Reprogramada)/u', (string)$c['motivo'])) continue;
        if ($c['inicio'] && strcmp($c['inicio'], SGS_IO_HORA_PRIMERA) <= 0) continue;
        $out[] = $c + ['partner' => sgsIoPartnerBase($c['proveedor'] ?? '')];
    }
    usort($out, static fn($a, $b) => strcmp($a['camino'], $b['camino']));       // el que salió más temprano, primero
    return $out;
}

/** Texto HTML del reporte de primera orden sin iniciar (08:06), agrupado por zona como el de alertas. */
function sgsIoArmarPrimera(array $cuads, string $nombre, array $menciones, DateTimeInterface $ahora, ?int $enCamino = null): string {
    $L = sgsIoMenciones($menciones);
    // 2026-11-20 (pedido del usuario): si la lectura terminó después de las 08:05 y alguna ya inició, el encabezado lo dice.
    $tarde = (bool)array_filter($cuads, static fn($c) => !empty($c['inicio']));
    array_push($L, 'Estimados, ' . sgsIoSaludo($ahora) . '.',
        'Se envía el reporte correspondiente de las cuadrillas ' . sgsIoDeQuien($nombre) . 'que aún no inician su <b>primera orden</b>'
            . ($tarde ? ' o la iniciaron <b>después de las 08:05</b>.' : ', siendo las 08:05.'),
        '📅 ' . $ahora->format('d/m/Y') . ' · corte ' . $ahora->format('H:i'), '');
    // 2026-11-20 (pedido del usuario): si ninguna cuadrilla salió todavía, se dice que las órdenes siguen agendadas.
    if (!$cuads && $enCamino === 0) { $L[] = '🕗 Aún no hay cuadrillas en camino: las órdenes del día se encuentran agendadas.'; return implode("\n", $L); }
    if (!$cuads) { $L[] = '✅ Todas las cuadrillas en camino iniciaron su primera orden hasta las 08:05.'; return implode("\n", $L); }
    $nTarde = count(array_filter($cuads, static fn($c) => !empty($c['inicio'])));
    $nSin = count($cuads) - $nTarde;
    $L[] = '📊 <b>Resumen:</b> 🚚 ' . $nSin . ' cuadrilla' . ($nSin !== 1 ? 's' : '') . ' en camino sin iniciar'
        . ($nTarde ? ' · ' . $nTarde . ' inici' . ($nTarde !== 1 ? 'aron' : 'ó') . ' después de las 08:05' : '');
    $L = array_merge($L, ['', SGS_IO_LINEA, '<b>EN CAMINO · SIN INICIAR A LAS 08:05 (' . count($cuads) . ')</b>'],
        sgsIoPorZona($cuads, 'cuadrilla', 'cuadrillas', static fn($c) => sgsIoOt($c['orden'] ?? '') . ' · En camino desde las ' . substr($c['camino'], 0, 5)
            . ($c['inicio'] ? ' · inició a las ' . substr($c['inicio'], 0, 5) : '')));
    array_push($L, '', SGS_IO_LINEA, 'Para su seguimiento correspondiente.');
    return implode("\n", $L);
}


/** Observación en una línea: Winbo deja '\n' escritos como texto y saltos reales. */
function sgsIoLimpiarObs($s, int $largo = 160): string {
    $s = str_replace(['\\n', "\n"], ' ', sgsIoArreglarTildes($s));
    $s = trim((string)preg_replace('/\s+/u', ' ', $s), " .*-");
    return mb_strlen($s) <= $largo ? $s : rtrim(mb_substr($s, 0, $largo - 1)) . '…';
}

/** Reporte de cancelaciones por FACILIDADES TÉCNICAS del día (mismo estilo que el de alertas). */
function sgsIoArmarFacilidades(array $canc, string $nombre, array $menciones, DateTimeInterface $ahora): string {
    $saludo = sgsIoSaludo($ahora);
    $L = sgsIoMenciones($menciones);
    array_push($L, "Estimados, $saludo.", 'Se envía el reporte de <b>facilidades técnicas</b> del día de las cuadrillas ' . rtrim(sgsIoDeQuien($nombre)) . '.',
        '📅 ' . $ahora->format('d/m/Y') . ' · corte ' . $ahora->format('H:i'), '');
    if (!$canc) { $L[] = '✅ Hoy no hay órdenes canceladas por facilidades técnicas.'; return implode("\n", $L); }
    $L[] = '📊 <b>Resumen:</b> 🚧 ' . count($canc) . ' ' . (count($canc) !== 1 ? 'órdenes' : 'orden') . ' sin facilidades técnicas';
    foreach (sgsIoMasComunes(array_column($canc, 'categoria')) as [$cat, $n]) $L[] = '• ' . sgsIoE($cat) . ": $n";
    array_push($L, '', SGS_IO_LINEA, '🚧 <b>DETALLE (' . count($canc) . ')</b>', '');
    // Un mensaje por partner: la cuadrilla va sin el nombre del partner (2026-11-19).
    foreach (array_values($canc) as $i => $c)
        $L[] = ($i + 1) . '. ' . $c['hora'] . ' · <b>' . sgsIoE(sgsIoCuadrillaSinPartner((string)$c['cuadrilla'], sgsIoPartnerBase($c['proveedor']))) . "</b>\n     "
            . sgsIoOt($c['orden']) . ' · ' . sgsIoE(sgsIoTitulo($c['zona'] ?? null)) . ' (' . sgsIoE(sgsIoTitulo($c['provincia'] ?? null)) . ")\n"
            . '     Motivo: ' . sgsIoE(sgsIoCapitalizar(sgsIoArreglarTildes($c['motivo'])))
            . (!empty($c['observacion']) ? "\n     Obs.: " . sgsIoE(sgsIoLimpiarObs($c['observacion'])) : '') . "\n";
    array_push($L, SGS_IO_LINEA, 'Para su conocimiento y gestión correspondiente.');
    return implode("\n", $L);
}

/** 'OLMA' · 'OLMA SGA' · 'SGI' (todos los partners) · 'todos los partners'. */
function sgsIoNombreReporte(array $ps, string $tipo): string {
    if (in_array('*', $ps, true)) return in_array($tipo, ['SGI', 'SGA'], true) ? $tipo : 'todos los partners';
    return implode(', ', $ps) . (in_array($tipo, ['SGI', 'SGA'], true) ? " $tipo" : '');
}

/**
 * Envía el texto en partes de hasta 3900 caracteres (Telegram admite 4096). Telegram acepta unos 20 mensajes por minuto en un
 * mismo grupo: si pide esperar (429), se espera lo que indica (máximo 40 s) y se reintenta una vez.
 */
function sgsIoTelegramEnviar($chatId, string $texto): void {
    $partes = []; $actual = '';
    foreach (explode("\n\n", $texto) as $bloque) {
        if (mb_strlen($actual) + mb_strlen($bloque) + 2 > 3900) { $partes[] = $actual; $actual = ''; }
        $actual .= ($actual !== '' ? "\n\n" : '') . $bloque;
    }
    $partes[] = $actual;
    foreach ($partes as $p) {
        for ($intento = 0; ; $intento++) {
            $ch = curl_init('https://api.telegram.org/bot' . getenv('TELEGRAM_TOKEN') . '/sendMessage');
            curl_setopt_array($ch, (is_file('C:/xampp/apache/bin/curl-ca-bundle.crt') ? [CURLOPT_CAINFO => 'C:/xampp/apache/bin/curl-ca-bundle.crt'] : []) + [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,      // 2026-11-19: por IPv6 Telegram no respondía desde la red local
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $p, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true], JSON_UNESCAPED_UNICODE)]);
            $crudo = curl_exec($ch);
            $falla = curl_error($ch);
            curl_close($ch);
            if ($crudo === false) {                       // sin conexión con Telegram: un reintento y, si no, aviso claro
                if ($intento === 0) { sleep(2); continue; }
                throw new RuntimeException('No se pudo conectar con Telegram (' . ($falla ?: 'sin respuesta') . ').');
            }
            $r = json_decode((string)$crudo, true);
            if (!empty($r['ok'])) break;
            $espera = (int)($r['parameters']['retry_after'] ?? 0);
            if ($intento === 0 && (int)($r['error_code'] ?? 0) === 429 && $espera > 0 && $espera <= 40) { sleep($espera); continue; }
            throw new RuntimeException((string)($r['description'] ?? 'Telegram no aceptó el mensaje'));
        }
        usleep(400000);                                   // un respiro entre mensajes al mismo grupo
    }
}

/** Menciones de un partner (09_CONFIG: "partners": {"OLMA": {"menciones": {"codigo": "Nombre"}}}), sin distinguir mayúsculas. */
function sgsIoMencionesPartner(string $partner): array {
    $d = json_decode((string)@file_get_contents(SGS_IO_TELEGRAM_GRUPOS), true);
    foreach ((array)($d['partners'] ?? []) as $nombre => $p)
        if (sgsIoPartnerBase((string)$nombre) === $partner && is_array($p['menciones'] ?? null)) return $p['menciones'];
    return [];
}

/**
 * Mensajes del reporte elegido para cada grupo configurado (filtrado por sus partners y tipo SGI/SGA). Un mensaje por partner
 * (2026-11-19, pedido del usuario): "OLMA SGI", "OLMA SGA", "ALFITELL SGI"… solo de los partners que tienen órdenes en el
 * reporte, con las menciones del grupo más las del partner. Si ningún partner tiene, un solo mensaje "✅" por grupo.
 * Devuelve [['grupo', 'chat_id', 'partner', 'ordenes', 'texto'], …].
 */
function sgsIoMensajes(array $tablero, string $reporte, DateTimeInterface $ahora): array {
    if (!isset(SGS_IO_REPORTES[$reporte])) throw new RuntimeException('Reporte desconocido.');
    $gs = sgsIoTelegramGrupos();
    if (!$gs) throw new RuntimeException('No hay grupos configurados en 09_CONFIG/inicio_ordenes_telegram.json.');
    if ($reporte === 'alertas') {
        $todas = sgsIoIniciadas2h($tablero['enCurso'] ?? [], $ahora);
    } elseif ($reporte === 'primera') {
        $todas = sgsIoPrimeraSinIniciar($tablero['cuadrillas'] ?? []);
    } else {
        $todas = [];
        foreach ($tablero['cancelaciones'] ?? [] as $c) {
            $cat = sgsIoCategoriaFacilidad($c['motivo'] ?? '');
            if ($cat) $todas[] = $c + ['categoria' => $cat, 'partner' => sgsIoPartnerBase($c['proveedor'] ?? '')];
        }
    }
    $out = [];
    foreach ($gs as $g) {
        $ps = array_map(static fn($p) => mb_strtoupper((string)$p, 'UTF-8'), $g['partners'] ?? []);
        $tipo = mb_strtoupper((string)($g['tipo'] ?? ''), 'UTF-8');          // "SGI", "SGA" o vacío = todos
        $ords = in_array('*', $ps, true) ? $todas : array_values(array_filter($todas, static fn($o) => in_array($o['partner'], $ps, true)));
        if (in_array($tipo, ['SGI', 'SGA'], true)) $ords = array_values(array_filter($ords, static fn($o) => ($o['grupo'] ?? '') === $tipo));
        $menc = is_array($g['menciones'] ?? null) ? $g['menciones'] : [];
        $porPartner = [];
        foreach ($ords as $o) $porPartner[$o['partner']][] = $o;
        ksort($porPartner, SORT_STRING);
        // Grupo propio de un partner: el encabezado no repite su nombre ("las cuadrillas SGI"); grupo con varios: "de OLMA SGI".
        $propio = !in_array('*', $ps, true);
        $nombreDe = static fn(string $p) => $propio ? (in_array($tipo, ['SGI', 'SGA'], true) ? $tipo : '') : sgsIoNombreReporte([$p], $tipo);
        $mensajes = $porPartner ? [] : [[$propio ? $nombreDe('') : sgsIoNombreReporte($ps, $tipo), [], $menc]];      // sin órdenes: uno solo para el grupo
        foreach ($porPartner as $p => $os) $mensajes[] = [$nombreDe((string)$p), $os, $menc + sgsIoMencionesPartner((string)$p)];
        // Primera orden: cuántas cuadrillas del grupo ya salieron (En camino hasta las 08:05) para distinguir "todas iniciaron" de "aún agendadas".
        $enCamino = null;
        if ($reporte === 'primera') {
            $enCamino = 0;
            foreach ($tablero['cuadrillas'] ?? [] as $c) {
                if (!$c['camino'] || strcmp($c['camino'], SGS_IO_HORA_PRIMERA) > 0) continue;
                if (!in_array('*', $ps, true) && !in_array(sgsIoPartnerBase($c['proveedor'] ?? ''), $ps, true)) continue;
                if (in_array($tipo, ['SGI', 'SGA'], true) && ($c['grupo'] ?? '') !== $tipo) continue;
                $enCamino++;
            }
        }
        foreach ($mensajes as [$nombre, $os, $m])
            $out[] = ['grupo' => (string)($g['nombre'] ?? ''), 'chat_id' => $g['chat_id'] ?? null, 'partner' => $nombre, 'ordenes' => count($os),
                      'texto' => match ($reporte) {
                          'alertas' => sgsIoArmarAlertas($os, $nombre, $m, $ahora),
                          'primera' => sgsIoArmarPrimera($os, $nombre, $m, $ahora, $enCamino),
                          default => sgsIoArmarFacilidades($os, $nombre, $m, $ahora)}];
    }
    return $out;
}

/** Envía el reporte elegido: un mensaje por partner y grupo (sgsIoMensajes). Devuelve el resultado de cada mensaje. */
function sgsIoEnviarATodos(array $tablero, string $reporte = 'alertas', ?DateTimeInterface $ahora = null): array {
    $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    if (!getenv('TELEGRAM_TOKEN')) throw new RuntimeException('Falta el token del bot (TELEGRAM_TOKEN en el .env).');
    $mensajes = sgsIoMensajes($tablero, $reporte, $ahora);
    @set_time_limit(180);
    $res = [];
    foreach ($mensajes as $msj) {
        $r = ['grupo' => $msj['grupo'], 'partner' => $msj['partner'], 'ordenes' => $msj['ordenes']];
        try {
            sgsIoTelegramEnviar($msj['chat_id'], $msj['texto']);
            $res[] = $r + ['ok' => true];
        } catch (Throwable $ex) {
            $res[] = $r + ['ok' => false, 'error' => mb_substr(str_replace((string)getenv('TELEGRAM_TOKEN'), '***', $ex->getMessage()), 0, 200)];
        }
    }
    return $res;
}
