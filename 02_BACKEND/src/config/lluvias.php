<?php
// 02_BACKEND/src/config/lluvias.php - Lluvia por zona (2026-10-20). Tabla lluvias_zona.
// El supervisor directo avisa desde "Mi turno" que llueve en su zona y luego que paró. La lluvia NO cambia su actividad
// (puede llover hasta en plena supervisión): al avisar y al cerrar se anota solo qué estaba haciendo cada uno. Una sola
// lluvia abierta por zona: inicio = el primero que la avisó, fin = el que la cerró. Si nadie la cierra, se cierra sola
// al terminar el turno quien la avisó, o al final de su día (23:59:59) si quedó abierta de un día anterior.
// La usan: api/lluvias.php (Mi turno), la Línea de tiempo (api/asistencia.php) y el indicador api/dashboard_lluvias.php.
require_once __DIR__ . '/territorial.php';
require_once __DIR__ . '/turnos.php';

/** Zonas donde el supervisor puede avisar lluvia: las suyas; si trabaja todas las provincias, todas. */
function sgsZonasLluvia(int $usuarioId): array {
    return sgsUserZones($usuarioId) ?? sgsOperationalDepartments();
}

/** Qué está haciendo ahora el supervisor (comentario de la lluvia): su supervisión en curso, su actividad o su turno. */
function sgsQueHaceSupervisor(PDO $pdo, int $usuarioId): string {
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(ticket_codigo, ''), codigo_supervision) FROM supervisiones
        WHERE supervisor_id = ? AND estado_supervision IN ('Iniciada', 'En proceso') AND fecha_registro >= CURRENT_DATE()
        ORDER BY fecha_registro DESC LIMIT 1");
    $st->execute([$usuarioId]);
    if ($ticket = $st->fetchColumn()) return "Supervisando {$ticket}";
    $turno = sgsTurnoAbiertoHoy($pdo, $usuarioId);
    if (!$turno) return 'Fuera de turno';
    $actividad = sgsActividadAbierta($pdo, (int)$turno['id']);
    return $actividad ? 'En ' . $actividad['nombre'] : 'En turno';
}

/** Cierra al final de su día las lluvias que quedaron abiertas de días anteriores. */
function sgsCerrarLluviasViejas(PDO $pdo): void {
    $pdo->exec("UPDATE lluvias_zona SET fin = CONCAT(DATE(inicio), ' 23:59:59'), comentario_fin = 'Cierre automático al final del día'
        WHERE fin IS NULL AND inicio < CURRENT_DATE()");
}

/** Lluvias abiertas ahora en estas zonas: [departamento => fila]. */
function sgsLluviasAbiertas(PDO $pdo, array $zonas): array {
    if (!$zonas) return [];
    sgsCerrarLluviasViejas($pdo);
    $marcas = implode(',', array_fill(0, count($zonas), '?'));
    $st = $pdo->prepare("SELECT l.id, l.departamento, l.inicio, u.nombre_completo AS reportado_por
        FROM lluvias_zona l JOIN usuarios u ON u.id = l.reportado_por WHERE l.fin IS NULL AND l.departamento IN ({$marcas})");
    $st->execute(array_values($zonas));
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'departamento');
}

/** Avisa que llueve en la zona. Si ya hay una lluvia abierta ahí, devuelve esa (no se duplica). */
function sgsAvisarLluvia(PDO $pdo, int $usuarioId, string $zona): array {
    $abierta = sgsLluviasAbiertas($pdo, [$zona])[$zona] ?? null;
    if ($abierta) return ['nueva' => false, 'lluvia' => $abierta];
    $pdo->prepare('INSERT INTO lluvias_zona (departamento, inicio, reportado_por, comentario_inicio) VALUES (?, NOW(), ?, ?)')
        ->execute([$zona, $usuarioId, sgsQueHaceSupervisor($pdo, $usuarioId)]);
    return ['nueva' => true, 'lluvia' => sgsLluviasAbiertas($pdo, [$zona])[$zona] ?? null];
}

/** Marca que paró la lluvia de la zona. Devuelve true si había una abierta. */
function sgsTerminarLluvia(PDO $pdo, int $usuarioId, string $zona): bool {
    $st = $pdo->prepare('UPDATE lluvias_zona SET fin = NOW(), cerrado_por = ?, comentario_fin = ? WHERE fin IS NULL AND departamento = ?');
    $st->execute([$usuarioId, sgsQueHaceSupervisor($pdo, $usuarioId), $zona]);
    return $st->rowCount() > 0;
}

/** Al terminar su turno se cierran las lluvias que avisó y siguen abiertas. */
function sgsCerrarLluviasDe(PDO $pdo, int $usuarioId): void {
    $pdo->prepare("UPDATE lluvias_zona SET fin = NOW(), cerrado_por = reportado_por, comentario_fin = 'Cierre automático al terminar su turno'
        WHERE fin IS NULL AND reportado_por = ?")->execute([$usuarioId]);
}

/**
 * Lluvias que tocan la fecha: [departamento => [[ts_inicio, ts_fin|null, avisó, comentario_inicio, cerró, comentario_fin], …]].
 * ts_fin null = sigue lloviendo (hoy). Las horas se recortan al día pedido.
 */
function sgsLluviasDelDia(PDO $pdo, string $fecha): array {
    sgsCerrarLluviasViejas($pdo);
    $st = $pdo->prepare("SELECT l.departamento, l.inicio, l.fin, ua.nombre_completo AS aviso, l.comentario_inicio, uc.nombre_completo AS cerro, l.comentario_fin
        FROM lluvias_zona l JOIN usuarios ua ON ua.id = l.reportado_por LEFT JOIN usuarios uc ON uc.id = l.cerrado_por
        WHERE l.inicio < DATE_ADD(:f1, INTERVAL 1 DAY) AND (l.fin IS NULL OR l.fin >= :f2) ORDER BY l.inicio");
    $st->execute([':f1' => $fecha, ':f2' => $fecha]);
    $diaIni = strtotime($fecha . ' 00:00:00'); $diaFin = strtotime($fecha . ' 23:59:59');
    $porZona = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $porZona[mb_strtoupper(trim($l['departamento']), 'UTF-8')][] = [max($diaIni, strtotime($l['inicio'])), $l['fin'] ? min($diaFin, strtotime($l['fin'])) : null,
            $l['aviso'], $l['comentario_inicio'], $l['cerro'], $l['comentario_fin']];
    }
    return $porZona;
}

/**
 * Lluvias que le tocan al supervisor, ordenadas: las de sus zonas. Si trabaja todas las provincias, las de las zonas
 * donde supervisó ese día ($zonasDelDia), para no llenarle la fila con la lluvia de todo el país.
 */
function sgsLluviasDelSupervisor(array $lluviasDelDia, int $usuarioId, array $zonasDelDia): array {
    $zonas = sgsUserZones($usuarioId) ?? array_map(static fn($z) => mb_strtoupper(trim((string)$z), 'UTF-8'), $zonasDelDia);
    $tramos = [];
    foreach ($lluviasDelDia as $zona => $lista) {
        if (!in_array($zona, $zonas, true)) continue;
        foreach ($lista as [$ini, $fin, $aviso, $comIni, $cerro, $comFin]) {
            $tramos[] = ['ts_inicio' => $ini, 'ts_fin' => $fin, 'zona' => $zona, 'aviso' => $aviso, 'comentario_inicio' => $comIni, 'cerro' => $cerro, 'comentario_fin' => $comFin];
        }
    }
    usort($tramos, static fn($a, $b) => $a['ts_inicio'] <=> $b['ts_inicio']);
    return $tramos;
}

/*
 * Aviso a Teams (2026-10-23): al iniciar y al terminar una lluvia se publica un mensaje en el grupo de Teams.
 * La URL del flujo de Teams (Workflows › "solicitud de webhook") es secreta: variable de entorno SGS_TEAMS_LLUVIAS_URL
 * o el archivo 09_CONFIG/teams_lluvias.local.txt (carpeta bloqueada por web y fuera de git). Sin URL no se envía nada.
 * Si Teams no responde, la lluvia se guarda igual (espera máxima 4 s).
 */
/**
 * Imagen de un mensaje de Teams (2026-10-26). Con enlace https van dos copias según el ancho de la pantalla (targetWidth):
 * en el celular (angosta) tocarla abre la imagen en el navegador, donde se amplía con los dedos (Teams del celular no amplía
 * imágenes); en la PC se amplía dentro del mismo Teams (allowExpand). Sin enlace (imagen dentro del mensaje) va una sola.
 */
function sgsImagenTeams(string $url, string $alt, array $extra = []): array {
    $imagen = ['type' => 'Image', 'url' => $url, 'size' => 'Stretch', 'altText' => $alt, 'msteams' => ['allowExpand' => true]] + $extra;
    if (!str_starts_with($url, 'https://')) return [$imagen];
    return [
        ['targetWidth' => 'atMost:narrow', 'selectAction' => ['type' => 'Action.OpenUrl', 'title' => 'Ampliar', 'url' => $url]] + array_diff_key($imagen, ['msteams' => 1]),
        ['targetWidth' => 'atLeast:standard'] + $imagen,
    ];
}

/** Aviso de inicio, fin o anulación en el grupo de Teams. $foto: enlace https o imagen dentro del mensaje (data:, reducida;
 *  sin servidor https). Ver sgsFotoLluviaParaTeams y sgsImagenTeams. */
function sgsAvisoTeamsLluvia(string $titulo, string $detalle, string $comentario = '', string $foto = ''): bool {
    return sgsEnviarTeamsLluvias(array_merge(array_values(array_filter([
        ['type' => 'TextBlock', 'text' => $titulo, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
        ['type' => 'TextBlock', 'text' => $detalle, 'wrap' => true, 'spacing' => 'Small'],
        $comentario !== '' ? ['type' => 'TextBlock', 'text' => "Comentario: {$comentario}", 'wrap' => true] : null,
    ])), $foto !== '' ? sgsImagenTeams($foto, 'Foto de la lluvia') : []));
}

/**
 * Foto del aviso de lluvia para Teams (2026-10-26). Con el sistema en https (el hosting) se guarda en buena calidad en
 * uploads/lluvias_teams/ con nombre al azar (se borra a los 90 días) y se devuelve su enlace: en Teams se ve nítida y se
 * amplía en el celular y en la PC. Sin https (una PC local), se reduce para que entre dentro del mensaje (~28 KB).
 * Devuelve '' si no hay foto válida.
 */
function sgsFotoLluviaParaTeams(array $archivo, ?string $urlSistema): string {
    require_once __DIR__ . '/uploads.php';
    try {
        $ok = validateUploadedFile($archivo, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1024 * 1024);
    } catch (RuntimeException $e) {
        return '';
    }
    if ($urlSistema && str_starts_with($urlSistema, 'https://')) {
        $carpeta = dirname(__DIR__, 3) . '/uploads/lluvias_teams';
        if (!is_dir($carpeta)) @mkdir($carpeta, 0755, true);
        foreach (glob("$carpeta/*") ?: [] as $viejo) if (filemtime($viejo) < time() - 90 * 86400) @unlink($viejo);
        $nombre = date('Y-m-d') . '_' . bin2hex(random_bytes(16)) . '.' . $ok['extension'];
        return move_uploaded_file($archivo['tmp_name'], "$carpeta/$nombre") ? rtrim($urlSistema, '/') . "/uploads/lluvias_teams/$nombre" : '';
    }
    if (!function_exists('imagecreatefromstring') || !($img = @imagecreatefromstring((string)file_get_contents($archivo['tmp_name'])))) return '';
    foreach ([480, 400, 320, 240] as $ancho) {
        $alto = (int)round(imagesy($img) * $ancho / max(1, imagesx($img)));
        $chica = imagecreatetruecolor($ancho, $alto);
        imagecopyresampled($chica, $img, 0, 0, 0, 0, $ancho, $alto, imagesx($img), imagesy($img));
        foreach ([60, 45, 35, 25] as $calidad) {
            ob_start(); imagejpeg($chica, null, $calidad); $jpg = (string)ob_get_clean();
            if (strlen($jpg) <= 19000) return 'data:image/jpeg;base64,' . base64_encode($jpg);
        }
    }
    return '';
}

/** Publica una tarjeta (Adaptive Card 1.5) en el grupo de Teams de lluvias. Sin dirección configurada no hace nada. */
function sgsEnviarTeamsLluvias(array $cuerpo): bool {
    $url = trim((string)(getenv('SGS_TEAMS_LLUVIAS_URL') ?: @file_get_contents(dirname(__DIR__, 3) . '/09_CONFIG/teams_lluvias.local.txt')));
    if (!preg_match('#^https://\S+$#', $url) || !function_exists('curl_init')) return false;
    $tarjeta = ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => [
        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.5', 'msteams' => ['width' => 'Full'], 'body' => $cuerpo,
    ]]]];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($tarjeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    if ($codigo < 200 || $codigo >= 300) win_log_error('teams_lluvia_error', 'No se pudo publicar en el grupo de Teams de lluvias', ['http' => $codigo]);
    return $codigo >= 200 && $codigo < 300;
}

