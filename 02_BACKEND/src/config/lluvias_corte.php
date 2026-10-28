<?php
// 02_BACKEND/src/config/lluvias_corte.php - Corte diario de lluvias para el grupo de Teams (2026-10-23).
// Dibuja la matriz de lluvias de los últimos 7 días (zonas × días hasta el día del corte, con el total; 2026-10-26: antes
// era el mes completo, ancho e ilegible en el celular) como imagen PNG, con la misma letra (Nunito,
// 02_BACKEND/recursos/fuentes, licencia OFL) y los colores de la lluvia. Se dibuja al doble de resolución para que se lea
// nítida en celulares y al ampliarla. Lo usan 07_SCRIPTS/corte_lluvias.php (cron cada 15 min, envía según los horarios de
// lluvias_cortes_programados) y el botón "Enviar corte a Teams" de Clima. Requiere la extensión GD con FreeType.
require_once __DIR__ . '/lluvias.php';

const SGS_CORTE_DIAS = 7;
const SGS_CORTE_HORA_HOY = 12; // desde esta hora el corte incluye el día (corte de la tarde); antes, hasta ayer

/** Último día del corte según la hora del envío (2026-10-26): temprano = hasta ayer; desde las 12:00 = incluye hoy. */
function sgsHastaDelCorte(string $hora = 'now'): string {
    return (int)date('G', strtotime($hora)) >= SGS_CORTE_HORA_HOY ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));
}

/** Fechas del corte (AAAA-MM-DD): los SGS_CORTE_DIAS días que terminan en $hasta. */
function sgsDiasDelCorte(string $hasta): array {
    return array_map(static fn(int $i) => date('Y-m-d', strtotime("$hasta -$i days")), range(SGS_CORTE_DIAS - 1, 0));
}

/** Minutos de lluvia por zona y fecha (por la fecha de inicio, como la matriz de Clima) en los días del corte. */
function sgsMinutosLluviaDelCorte(PDO $pdo, string $hasta): array {
    sgsCerrarLluviasViejas($pdo);
    $st = $pdo->prepare("SELECT departamento, DATE(inicio) AS dia, SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, inicio, COALESCE(fin, NOW())))) AS minutos, COUNT(*) AS lluvias
        FROM lluvias_zona WHERE inicio >= :desde AND inicio < DATE_ADD(:hasta, INTERVAL 1 DAY) GROUP BY departamento, DATE(inicio)");
    $st->execute([':desde' => sgsDiasDelCorte($hasta)[0], ':hasta' => $hasta]);
    $celdas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $celdas[mb_strtoupper(trim($f['departamento']), 'UTF-8')][$f['dia']] = ['min' => (int)$f['minutos'], 'n' => (int)$f['lluvias']];
    }
    return $celdas;
}

/**
 * Zonas del corte (2026-10-27): las que tienen supervisores directos activos o lluvias en estos días. Así una zona nueva
 * aún sin supervisores (Lima, Callao o el Norte Chico al empezar) no aparece siempre como "sin lluvia".
 */
function sgsZonasDelCorte(PDO $pdo, array $celdas): array {
    $todas = sgsOperationalDepartments();
    $conSupervisor = [];
    $ids = $pdo->query("SELECT u.id FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id
        WHERE u.rol = 'Supervisor' AND u.activo = 1 AND " . sgsDirectStaffSql('u', 'p'))->fetchAll(PDO::FETCH_COLUMN);
    $datos = sgsPrecargarUsuarios($ids);
    foreach ($ids as $id) {
        $zonas = $datos[(int)$id]['zonas'];
        if ($zonas === null) return $todas; // alguien trabaja todas las provincias
        foreach ($zonas as $z) $conSupervisor[$z] = true;
    }
    return array_values(array_filter($todas, static fn($z) => isset($conSupervisor[$z]) || isset($celdas[$z])));
}

/** PNG de la matriz de los últimos 7 días hasta $hasta (AAAA-MM-DD). $escala 2 = doble resolución; $colores > 0 = paleta sin tramado (más liviana). */
function sgsImagenMatrizLluvias(array $celdas, string $hasta, float $escala = 2, int $colores = 0): string {
    $fuente = dirname(__DIR__, 2) . '/recursos/fuentes/';
    $normal = $fuente . 'Nunito-Medium.ttf'; $negrita = $fuente . 'Nunito-Bold.ttf';
    $zonas = sgsZonasDelCorte(Database::getConnection(), $celdas);
    $fechas = sgsDiasDelCorte($hasta);
    $dias = count($fechas);
    $s = $escala;
    // Medidas en píxeles de pantalla (×$escala): columnas de zona, día y total; alto del encabezado, filas y leyenda
    [$cZona, $cDia, $cTotal, $hCab, $hFila, $hLeyenda] = [132, 78, 80, 48, 34, 58];
    $ancho = (int)round(($cZona + $cDia * $dias + $cTotal) * $s);
    $alto = (int)round(($hCab + $hFila * count($zonas) + $hLeyenda) * $s);
    $img = imagecreatetruecolor($ancho, $alto);
    imagealphablending($img, true);
    $rgb = static fn(string $hex) => imagecolorallocate($img, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    // Colores del sistema (sgs-theme.css): superficie, bordes, textos, lluvia (secciones 57 y 59) y total en naranja
    $c = ['blanco' => $rgb('#FFFFFF'), 'cabecera' => $rgb('#FAF9F7'), 'borde' => $rgb('#EDE4DE'), 'separa' => $rgb('#DCD6D1'),
        'tinta' => $rgb('#141414'), 'gris' => $rgb('#6B6D70'), 'tenue' => $rgb('#B4B6B8'),
        'leve' => $rgb('#EAF2F8'), 'media' => $rgb('#C5D4DD'), 'fuerte' => $rgb('#3F6E8F'), 'lluviaOsc' => $rgb('#2B5070'),
        'totalFondo' => $rgb('#FFF4EE'), 'naranja' => $rgb('#FF5A0A')];
    imagefilledrectangle($img, 0, 0, $ancho - 1, $alto - 1, $c['blanco']);
    // Texto: $tam en px de pantalla; GD mide en puntos a 96 ppp (px × 0,75). Alineado a la izquierda, al centro o a la derecha.
    $texto = static function (string $t, float $tam, string $f, $color, float $x, float $y, float $w, float $h, string $alinear = 'centro') use ($img, $s) {
        $pt = $tam * $s * 0.75;
        $b = imagettfbbox($pt, 0, $f, $t);
        $tw = $b[2] - $b[0];
        $tx = $alinear === 'izq' ? $x : ($alinear === 'der' ? $x + $w - $tw : $x + ($w - $tw) / 2);
        $alto = (int)round($tam * $s * 0.72); // altura de las mayúsculas de Nunito
        imagettftext($img, $pt, 0, (int)round($tx - $b[0]), (int)round($y + ($h + $alto) / 2), $color, $f, $t);
    };
    $caja = static fn(float $x, float $y, float $w, float $h, $color) => imagefilledrectangle($img, (int)round($x), (int)round($y), (int)round($x + $w) - 1, (int)round($y + $h) - 1, $color);
    $hm = static fn(int $m) => intdiv($m, 60) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
    $xTotal = ($cZona + $cDia * $dias) * $s;

    // Encabezado: ZONA · día de la semana y número en dos líneas · TOTAL
    $caja(0, 0, $ancho, $hCab * $s, $c['cabecera']);
    $caja($xTotal, 0, $cTotal * $s, $alto - $hLeyenda * $s, $c['totalFondo']);
    $texto('ZONA', 11.5, $negrita, $c['gris'], 10 * $s, 0, $cZona * $s, $hCab * $s, 'izq');
    $nombres = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];
    foreach ($fechas as $i => $fecha) {
        $x = ($cZona + $cDia * $i) * $s;
        $texto($nombres[(int)date('w', strtotime($fecha))], 11, $negrita, $c['gris'], $x, 6 * $s, $cDia * $s, 18 * $s);
        $texto(date('d/m', strtotime($fecha)), 12, $negrita, $c['gris'], $x, 24 * $s, $cDia * $s, 18 * $s);
    }
    $texto('TOTAL', 11.5, $negrita, $c['gris'], $xTotal, 0, $cTotal * $s, $hCab * $s);

    // Filas: una por zona; cada día con sus horas en el tono de la lluvia (leve < 1 h, media 1–3 h, fuerte ≥ 3 h)
    foreach ($zonas as $i => $zona) {
        $y = ($hCab + $hFila * $i) * $s;
        $texto($zona, 14, $negrita, $c['tinta'], 10 * $s, $y, $cZona * $s, $hFila * $s, 'izq');
        $suma = 0;
        foreach ($fechas as $d => $fecha) {
            $x = ($cZona + $cDia * $d) * $s;
            $min = $celdas[mb_strtoupper($zona, 'UTF-8')][$fecha]['min'] ?? 0;
            $suma += $min;
            if (!$min) { $texto('-', 13, $normal, $c['tenue'], $x, $y, $cDia * $s, $hFila * $s); continue; }
            $tono = $min >= 180 ? 'fuerte' : ($min >= 60 ? 'media' : 'leve');
            $caja($x, $y, $cDia * $s, $hFila * $s, $c[$tono]);
            $texto($hm($min), 14, $negrita, $tono === 'fuerte' ? $c['blanco'] : $c['lluviaOsc'], $x, $y, $cDia * $s, $hFila * $s);
        }
        $texto($suma ? $hm($suma) : '-', 15, $negrita, $suma ? $c['tinta'] : $c['tenue'], $xTotal, $y, $cTotal * $s, $hFila * $s);
    }

    // Líneas de la tabla y separadores de la columna de zona y del total
    $finTabla = ($hCab + $hFila * count($zonas)) * $s;
    for ($i = 0; $i <= count($zonas); $i++) $caja(0, ($hCab + $hFila * $i) * $s - ($i ? $s : 0), $ancho, $s, $c['borde']);
    for ($d = 1; $d < $dias; $d++) $caja(($cZona + $cDia * $d) * $s, 0, $s, $finTabla, $c['borde']);
    $caja($cZona * $s - $s, 0, 2 * $s, $finTabla, $c['separa']);
    $caja($xTotal - $s, 0, 2 * $s, $finTabla, $c['naranja']);

    // Leyenda de tonos
    $y = $finTabla + 8 * $s; $x = 10 * $s;
    foreach ([['leve', 'Menos de 1 h'], ['media', 'De 1 a 3 h'], ['fuerte', '3 h o más']] as [$tono, $etq]) {
        $caja($x, $y + 3 * $s, 14 * $s, 14 * $s, $c[$tono]);
        $texto($etq, 12, $normal, $c['gris'], $x + 20 * $s, $y, 130 * $s, 20 * $s, 'izq');
        $x += 130 * $s;
    }
    $texto('Horas de lluvia (h:mm) por zona y día, según la hora de inicio', 11.5, $normal, $c['gris'], 10 * $s, $y + 24 * $s, $ancho, 20 * $s, 'izq');

    if ($colores) imagetruecolortopalette($img, false, $colores);
    ob_start();
    imagepng($img, null, 9);
    imagedestroy($img);
    return (string)ob_get_clean();
}

/**
 * Publica el corte de los últimos 7 días hasta $hasta (ver sgsHastaDelCorte). Con $urlSistema (https://dominio/ProviWIN) la imagen se guarda en
 * uploads/cortes_lluvia/ con un nombre al azar y el mensaje lleva su enlace (sin límite de tamaño, nítida y ampliable).
 * Sin ella, la imagen va dentro del mensaje a menor resolución (Teams acepta mensajes de ~28 KB).
 */
function sgsPublicarCorteLluvias(PDO $pdo, string $hasta, ?string $urlSistema): bool {
    $celdas = sgsMinutosLluviaDelCorte($pdo, $hasta);
    $fechas = sgsDiasDelCorte($hasta);
    $minutos = 0; $lluvias = 0;
    foreach ($celdas as $dias) foreach ($dias as $x) { $minutos += $x['min']; $lluvias += $x['n']; }
    $titulo = 'Corte de lluvias — últimos ' . SGS_CORTE_DIAS . ' días (' . date('d/m', strtotime($fechas[0])) . ' al ' . date('d/m', strtotime($hasta)) . ')'
        . ($hasta === date('Y-m-d') ? ' a las ' . date('H:i') : '');
    $resumen = $lluvias ? "En los últimos " . SGS_CORTE_DIAS . " días: {$lluvias} lluvia" . ($lluvias === 1 ? '' : 's') . ' · ' . intdiv($minutos, 60) . ' h ' . ($minutos % 60) . ' min en ' . count($celdas) . ' zona' . (count($celdas) === 1 ? '' : 's') . '.'
        : 'En los últimos ' . SGS_CORTE_DIAS . ' días no hay lluvias registradas.';
    if ($urlSistema) {
        $carpeta = dirname(__DIR__, 3) . '/uploads/cortes_lluvia';
        if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);
        foreach (glob("$carpeta/*") ?: [] as $viejo) if (filemtime($viejo) < time() - 90 * 86400) @unlink($viejo); // se guardan 90 días
        $archivo = $hasta . '_' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents("$carpeta/$archivo", sgsImagenMatrizLluvias($celdas, $hasta, 2));
        $imagen = rtrim($urlSistema, '/') . "/uploads/cortes_lluvia/$archivo";
    } else {
        // Dentro del mensaje (Teams acepta ~28 KB): baja la resolución hasta que la imagen entre (fin de mes = más ancha)
        foreach ([2, 1.5, 1.25, 1] as $escala) {
            $imagen = 'data:image/png;base64,' . base64_encode(sgsImagenMatrizLluvias($celdas, $hasta, $escala, 24));
            if (strlen($imagen) <= 26000) break;
        }
    }
    // Resumen por zona en texto (2026-10-26): se lee en el celular sin abrir la imagen (la matriz es ancha para un teléfono)
    $porZona = []; $sinLluvia = [];
    foreach (sgsZonasDelCorte($pdo, $celdas) as $zona) {
        $dias = $celdas[mb_strtoupper($zona, 'UTF-8')] ?? [];
        $min = array_sum(array_column($dias, 'min'));
        if (!$min) { $sinLluvia[] = mb_convert_case(mb_strtolower($zona, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'); continue; }
        $porZona[] = ['min' => $min, 'title' => $zona, 'value' => ($min >= 60 ? intdiv($min, 60) . ' h ' . ($min % 60) . ' min' : "{$min} min")
            . ' · ' . count($dias) . ' día' . (count($dias) === 1 ? '' : 's') . (isset($dias[$hasta]) ? ' · llovió el ' . date('d/m', strtotime($hasta)) : '')];
    }
    usort($porZona, static fn($a, $b) => $b['min'] <=> $a['min']);
    // En el celular, tocar la imagen la abre en el navegador; en la PC se amplía en Teams (sgsImagenTeams). Sin botones ni
    // enlace al SGS (2026-10-26, pedido del usuario).
    $cuerpo = array_merge(array_values(array_filter([
        ['type' => 'TextBlock', 'text' => $titulo, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
        ['type' => 'TextBlock', 'text' => $resumen, 'wrap' => true, 'spacing' => 'Small', 'isSubtle' => true],
        $porZona ? ['type' => 'FactSet', 'spacing' => 'Medium', 'facts' => array_map(static fn($z) => ['title' => $z['title'], 'value' => $z['value']], $porZona)] : null,
        $sinLluvia ? ['type' => 'TextBlock', 'text' => 'Sin lluvia: ' . implode(', ', $sinLluvia) . '.', 'wrap' => true, 'size' => 'Small', 'isSubtle' => true, 'spacing' => 'Small'] : null,
    ])), sgsImagenTeams($imagen, 'Lluvias de los últimos 7 días por zona', ['spacing' => 'Medium']));
    return sgsEnviarTeamsLluvias($cuerpo);
}

/**
 * Envíos programados (2026-10-23, tabla lluvias_cortes_programados): envía los horarios activos cuya hora ya llegó y que
 * aún no salieron hoy. Solo dentro de la hora siguiente a la programada: un horario creado tarde no se envía ese día.
 * Cada horario se aparta antes de enviarlo (ultimo_envio), así el cron y las visitas nunca lo mandan dos veces; si Teams
 * falla, se devuelve como estaba para reintentar. Devuelve [hora => publicado] de lo que intentó enviar.
 */
function sgsEnviarCortesProgramados(PDO $pdo, ?string $urlSistema): array {
    $st = $pdo->query("SELECT id, hora, ultimo_envio FROM lluvias_cortes_programados
        WHERE activo = 1 AND hora <= CURTIME() AND CURTIME() < ADDTIME(hora, '01:00:00')
          AND (ultimo_envio IS NULL OR ultimo_envio < TIMESTAMP(CURDATE(), hora)) ORDER BY hora");
    $hechos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $apartar = $pdo->prepare('UPDATE lluvias_cortes_programados SET ultimo_envio = NOW() WHERE id = ? AND ultimo_envio <=> ?');
        $apartar->execute([$p['id'], $p['ultimo_envio']]);
        if (!$apartar->rowCount()) continue; // otro proceso ya lo está enviando
        $ok = sgsPublicarCorteLluvias($pdo, sgsHastaDelCorte($p['hora']), $urlSistema); // por la hora programada
        if (!$ok) $pdo->prepare('UPDATE lluvias_cortes_programados SET ultimo_envio = ? WHERE id = ?')->execute([$p['ultimo_envio'], $p['id']]);
        $hechos[substr($p['hora'], 0, 5)] = $ok;
    }
    return $hechos;
}

/**
 * Envíos programados desde una visita al sistema (2026-10-26): así salen aunque no haya cron. Se ejecuta después de
 * mandar la página (quien navega no espera) y con la dirección https del sistema para que la imagen vaya por enlace.
 */
function sgsEnviarCortesTrasLaVisita(): void {
    if (PHP_SAPI === 'cli') return;
    require_once __DIR__ . '/examen_enlaces.php'; // sgsUrlSistema
    $url = sgsUrlSistema();
    register_shutdown_function(static function () use ($url): void {
        ignore_user_abort(true);
        @set_time_limit(120);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        try { sgsEnviarCortesProgramados(Database::getConnection(), str_starts_with($url, 'https://') ? $url : null); }
        catch (Throwable $e) { win_log_error('corte_lluvias', 'Falló el envío programado del corte de lluvias', ['error' => $e->getMessage()]); }
    });
}
