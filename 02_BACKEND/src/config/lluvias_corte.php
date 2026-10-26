<?php
// 02_BACKEND/src/config/lluvias_corte.php - Corte diario de lluvias para el grupo de Teams (2026-10-23).
// Dibuja la matriz mensual de Clima (zonas × días del mes hasta el día del corte, con el total) como imagen PNG, con la
// misma letra (Nunito, 02_BACKEND/recursos/fuentes, licencia OFL) y los colores de la lluvia. Se dibuja al doble de
// resolución para que se lea nítida en celulares y al ampliarla. Lo usan 07_SCRIPTS/corte_lluvias.php (cron cada 15 min,
// envía según los horarios de lluvias_cortes_programados) y el botón "Enviar corte a Teams" de Clima.
// Requiere la extensión GD con FreeType.
require_once __DIR__ . '/lluvias.php';

/** Minutos de lluvia por zona y día (por la fecha de inicio, como la matriz de Clima) del mes de $hasta, del 1 a $hasta. */
function sgsMinutosLluviaDelMes(PDO $pdo, string $hasta): array {
    sgsCerrarLluviasViejas($pdo);
    $st = $pdo->prepare("SELECT departamento, DATE(inicio) AS dia, SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, inicio, COALESCE(fin, NOW())))) AS minutos, COUNT(*) AS lluvias
        FROM lluvias_zona WHERE inicio >= :desde AND inicio < DATE_ADD(:hasta, INTERVAL 1 DAY) GROUP BY departamento, DATE(inicio)");
    $st->execute([':desde' => substr($hasta, 0, 8) . '01', ':hasta' => $hasta]);
    $celdas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $z = mb_strtoupper(trim($f['departamento']), 'UTF-8');
        $celdas[$z][(int)substr($f['dia'], 8, 2)] = ['min' => (int)$f['minutos'], 'n' => (int)$f['lluvias']];
    }
    return $celdas;
}

/** PNG de la matriz del mes hasta $hasta (AAAA-MM-DD). $escala 2 = doble resolución; $colores > 0 = paleta sin tramado (más liviana). */
function sgsImagenMatrizLluvias(array $celdas, string $hasta, float $escala = 2, int $colores = 0): string {
    $fuente = dirname(__DIR__, 2) . '/recursos/fuentes/';
    $normal = $fuente . 'Nunito-Medium.ttf'; $negrita = $fuente . 'Nunito-Bold.ttf';
    $zonas = sgsOperationalDepartments();
    $dias = (int)substr($hasta, 8, 2);
    $s = $escala;
    // Medidas en píxeles de pantalla (×$escala): columnas de zona, día y total; alto del encabezado, filas y leyenda
    [$cZona, $cDia, $cTotal, $hCab, $hFila, $hLeyenda] = [118, 40, 64, 44, 30, 34];
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
    $texto('ZONA', 10.5, $negrita, $c['gris'], 10 * $s, 0, $cZona * $s, $hCab * $s, 'izq');
    $nombres = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];
    for ($d = 1; $d <= $dias; $d++) {
        $x = ($cZona + $cDia * ($d - 1)) * $s;
        $fecha = substr($hasta, 0, 8) . sprintf('%02d', $d);
        $texto($nombres[(int)date('w', strtotime($fecha))], 10, $negrita, $c['gris'], $x, 6 * $s, $cDia * $s, 16 * $s);
        $texto(sprintf('%02d', $d), 10.5, $negrita, $c['gris'], $x, 22 * $s, $cDia * $s, 16 * $s);
    }
    $texto('TOTAL', 10.5, $negrita, $c['gris'], $xTotal, 0, $cTotal * $s, $hCab * $s);

    // Filas: una por zona; cada día con sus horas en el tono de la lluvia (leve < 1 h, media 1–3 h, fuerte ≥ 3 h)
    foreach ($zonas as $i => $zona) {
        $y = ($hCab + $hFila * $i) * $s;
        $texto($zona, 12.5, $negrita, $c['tinta'], 10 * $s, $y, $cZona * $s, $hFila * $s, 'izq');
        $suma = 0;
        for ($d = 1; $d <= $dias; $d++) {
            $x = ($cZona + $cDia * ($d - 1)) * $s;
            $min = $celdas[mb_strtoupper($zona, 'UTF-8')][$d]['min'] ?? 0;
            $suma += $min;
            if (!$min) { $texto('-', 12, $normal, $c['tenue'], $x, $y, $cDia * $s, $hFila * $s); continue; }
            $tono = $min >= 180 ? 'fuerte' : ($min >= 60 ? 'media' : 'leve');
            $caja($x, $y, $cDia * $s, $hFila * $s, $c[$tono]);
            $texto($hm($min), 11.5, $negrita, $tono === 'fuerte' ? $c['blanco'] : $c['lluviaOsc'], $x, $y, $cDia * $s, $hFila * $s);
        }
        $texto($suma ? $hm($suma) : '-', 13, $negrita, $suma ? $c['tinta'] : $c['tenue'], $xTotal, $y, $cTotal * $s, $hFila * $s);
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
        $texto($etq, 11, $normal, $c['gris'], $x + 20 * $s, $y, 120 * $s, 20 * $s, 'izq');
        $x += 120 * $s;
    }
    $texto('Horas de lluvia (h:mm) por zona y día, según la hora de inicio', 11, $normal, $c['gris'], 0, $y, $ancho - 10 * $s, 20 * $s, 'der');

    if ($colores) imagetruecolortopalette($img, false, $colores);
    ob_start();
    imagepng($img, null, 9);
    imagedestroy($img);
    return (string)ob_get_clean();
}

/**
 * Publica el corte del mes hasta $hasta. Con $urlSistema (https://dominio/ProviWIN) la imagen se guarda en
 * uploads/cortes_lluvia/ con un nombre al azar y el mensaje lleva su enlace (sin límite de tamaño, nítida y ampliable).
 * Sin ella, la imagen va dentro del mensaje a menor resolución (Teams acepta mensajes de ~28 KB).
 */
function sgsPublicarCorteLluvias(PDO $pdo, string $hasta, ?string $urlSistema): bool {
    $celdas = sgsMinutosLluviaDelMes($pdo, $hasta);
    $minutos = 0; $lluvias = 0;
    foreach ($celdas as $dias) foreach ($dias as $x) { $minutos += $x['min']; $lluvias += $x['n']; }
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $titulo = 'Corte de lluvias — ' . $meses[(int)substr($hasta, 5, 2) - 1] . ' ' . substr($hasta, 0, 4) . ', al ' . date('d/m', strtotime($hasta))
        . ($hasta === date('Y-m-d') ? ' a las ' . date('H:i') : '');
    $resumen = $lluvias ? "En el mes: {$lluvias} lluvia" . ($lluvias === 1 ? '' : 's') . ' · ' . intdiv($minutos, 60) . ' h ' . ($minutos % 60) . ' min en ' . count($celdas) . ' zona' . (count($celdas) === 1 ? '' : 's') . '.'
        : 'En el mes no hay lluvias registradas.';
    if ($urlSistema) {
        $carpeta = dirname(__DIR__, 3) . '/uploads/cortes_lluvia';
        if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);
        foreach (glob("$carpeta/*.png") ?: [] as $viejo) if (filemtime($viejo) < time() - 90 * 86400) @unlink($viejo); // se guardan 90 días
        $archivo = $hasta . '_' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents("$carpeta/$archivo", sgsImagenMatrizLluvias($celdas, $hasta, 2));
        $imagen = rtrim($urlSistema, '/') . "/uploads/cortes_lluvia/$archivo";
    } else {
        // Dentro del mensaje (Teams acepta ~28 KB): baja la resolución hasta que la imagen entre (fin de mes = más ancha)
        foreach ([1.5, 1.25, 1] as $escala) {
            $imagen = 'data:image/png;base64,' . base64_encode(sgsImagenMatrizLluvias($celdas, $hasta, $escala, 24));
            if (strlen($imagen) <= 26000) break;
        }
    }
    $cuerpo = [
        ['type' => 'TextBlock', 'text' => $titulo, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
        ['type' => 'TextBlock', 'text' => $resumen, 'wrap' => true, 'spacing' => 'Small', 'isSubtle' => true],
        ['type' => 'Image', 'url' => $imagen, 'size' => 'Stretch', 'altText' => 'Matriz mensual de lluvias', 'msteams' => ['allowExpand' => true]],
    ];
    if ($urlSistema) $cuerpo[] = ['type' => 'ActionSet', 'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Ver en el SGS', 'url' => rtrim($urlSistema, '/') . '/index.php?page=dashboard_lluvias']]];
    return sgsEnviarTeamsLluvias($cuerpo);
}

/**
 * Envíos programados (2026-10-23, tabla lluvias_cortes_programados): envía los horarios activos cuya hora ya llegó y que
 * aún no salieron hoy. Solo dentro de la hora siguiente a la programada: un horario creado tarde no se envía ese día.
 * Devuelve [hora => publicado] de lo que intentó enviar.
 */
function sgsEnviarCortesProgramados(PDO $pdo, ?string $urlSistema): array {
    $st = $pdo->query("SELECT id, hora, incluye_hoy FROM lluvias_cortes_programados
        WHERE activo = 1 AND hora <= CURTIME() AND CURTIME() < ADDTIME(hora, '01:00:00')
          AND (ultimo_envio IS NULL OR ultimo_envio < TIMESTAMP(CURDATE(), hora)) ORDER BY hora");
    $hechos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $ok = sgsPublicarCorteLluvias($pdo, date('Y-m-d', strtotime($p['incluye_hoy'] ? 'today' : '-1 day')), $urlSistema);
        if ($ok) $pdo->prepare('UPDATE lluvias_cortes_programados SET ultimo_envio = NOW() WHERE id = ?')->execute([$p['id']]);
        $hechos[substr($p['hora'], 0, 5)] = $ok;
    }
    return $hechos;
}
