<?php
// 02_BACKEND/src/config/puertos_cto.php - Consulta de puertos de una CTO en Wincore (2026-11-11, pedido del usuario).
// Wincore solo se alcanza con la VPN y con la sesión de la cuenta principal, así que el servidor NO llama a Wincore: la laptop
// de la cuenta principal hace de puente. En Wincore pulsa el favorito "Vincular SGS", que abre la ventana del vínculo del
// SGS (puente_wincore.php); las dos ventanas se hablan con postMessage y la pestaña de Wincore hace las consultas con su
// propia sesión. La cookie y el token de Wincore nunca salen de la laptop, y los datos de clientes tampoco (solo puertos).
//   1) El supervisor pide una CTO → queda una consulta "pendiente" (un archivo cuyo nombre empieza con la hora: orden de llegada).
//   2) La ventana del vínculo pregunta cada SGS_PUENTE_SONDEO segundos y recibe UNA consulta a la vez ("tomada"): la siguiente
//      sale recién cuando Wincore respondió la anterior (2026-11-11, pedido del usuario: no saturar Wincore ni su sesión).
//   3) El resultado queda "lista" y el supervisor lo recoge; mientras tanto ve cuántas consultas hay antes que la suya.
// El vínculo dura SGS_PUENTE_HORAS desde que se activa; se apaga antes con "Desactivar" o si la ventana deja de responder.
// Sistema liviano: sin base de datos, archivos chicos en 08_LOGS/runtime/puertos que se borran solos a los 10 minutos.
const SGS_PUENTE_HORAS = 10;
const SGS_PUENTE_SONDEO = 3;          // segundos entre preguntas de la ventana del vínculo
const SGS_PUENTE_SIN_PULSO = 20;      // sin preguntas en este tiempo, el vínculo se considera apagado
const SGS_PUERTOS_ESPERA = 40;        // segundos que tiene Wincore para responder desde que toma la consulta
const SGS_PUERTOS_COLA = 15;          // consultas en espera como máximo; con más se pide intentar en un minuto

/** Origen de Wincore (se puede cambiar con WINCORE_ORIGEN en .env, p. ej. para pruebas locales). */
function sgsWincoreOrigen(): string {
    return rtrim((string)(getenv('WINCORE_ORIGEN') ?: 'https://wincore.win.pe'), '/');
}

function sgsPuertosDir(string $sub = ''): string {
    $dir = STORAGE_PATH . '/puertos' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function sgsPuertosLeer(string $archivo): ?array {
    $t = @file_get_contents($archivo);
    $d = $t === false ? null : json_decode($t, true);
    return is_array($d) ? $d : null;
}

function sgsPuertosEscribir(string $archivo, array $datos): void {
    file_put_contents($archivo, json_encode($datos, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Estado del vínculo: activo solo si no venció y la ventana del vínculo preguntó hace poco. */
function sgsPuenteEstado(): array {
    $p = sgsPuertosLeer(sgsPuertosDir() . '/puente.json');
    $ahora = time();
    $activo = $p && $p['vence'] > $ahora && $ahora - $p['pulso'] <= SGS_PUENTE_SIN_PULSO;
    return ['activo' => $activo, 'vence' => $activo ? (int)$p['vence'] : null, 'usuario_id' => $p ? (int)$p['usuario_id'] : null];
}

function sgsPuenteActivar(int $usuarioId): int {
    $vence = time() + SGS_PUENTE_HORAS * 3600;
    sgsPuertosEscribir(sgsPuertosDir() . '/puente.json', ['usuario_id' => $usuarioId, 'desde' => time(), 'vence' => $vence, 'pulso' => time()]);
    return $vence;
}

/** Apaga el vínculo y descarta lo que esperaba respuesta (el supervisor ve "no está activa"). */
function sgsPuenteDesactivar(): void {
    @unlink(sgsPuertosDir() . '/puente.json');
    foreach (glob(sgsPuertosDir('consultas') . '/*.{pendiente,tomada}.json', GLOB_BRACE) ?: [] as $f) @unlink($f);
}

/** Id de una consulta: milisegundos de su creación (14 cifras, da el orden de llegada) + 12 letras al azar. */
function sgsPuertosIdValido(string $id): bool {
    return preg_match('/^\d{14}[a-f0-9]{12}$/', $id) === 1;
}

/**
 * Pregunta de la ventana del vínculo: marca el pulso y, si Wincore no está atendiendo otra, entrega la consulta que llegó
 * primero (pasa a "tomada" con un rename: no se toma dos veces). La que Wincore no respondió en SGS_PUERTOS_ESPERA se cierra
 * con error y sigue la siguiente. null si el vínculo venció o fue desactivado.
 */
function sgsPuenteTomar(int $usuarioId): ?array {
    $archivo = sgsPuertosDir() . '/puente.json';
    $p = sgsPuertosLeer($archivo);
    if (!$p || (int)$p['usuario_id'] !== $usuarioId || $p['vence'] <= time()) return null;
    $p['pulso'] = time();
    sgsPuertosEscribir($archivo, $p);
    $dir = sgsPuertosDir('consultas');
    $ocupado = false;
    foreach (glob("$dir/*.tomada.json") ?: [] as $f) {
        $c = sgsPuertosLeer($f);
        if ($c && time() - (int)($c['tomada'] ?? 0) <= SGS_PUERTOS_ESPERA) $ocupado = true;
        else sgsPuertosGuardarRespuesta(basename($f, '.tomada.json'), ['error' => 'Wincore no respondió a tiempo. Vuelve a intentarlo en un momento.']);
    }
    $pendientes = glob("$dir/*.pendiente.json") ?: []; // ordenadas por nombre = por orden de llegada
    $consulta = null;
    if (!$ocupado && $pendientes) {
        $id = basename(array_shift($pendientes), '.pendiente.json');
        if (@rename("$dir/$id.pendiente.json", "$dir/$id.tomada.json") && ($c = sgsPuertosLeer("$dir/$id.tomada.json"))) {
            $c['tomada'] = time();
            sgsPuertosEscribir("$dir/$id.tomada.json", $c);
            $consulta = ['id' => $id, 'codigo' => $c['codigo']];
        }
    }
    return ['vence' => (int)$p['vence'], 'consulta' => $consulta, 'en_espera' => count($pendientes)];
}
/** Código de CTO normalizado (WN-73651-1272741) o null si no lo parece. */
function sgsCodigoCto(string $codigo): ?string {
    $c = strtoupper(trim($codigo));
    return preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $c) ? $c : null;
}

/** Nueva consulta al final de la cola. null si ya hay SGS_PUERTOS_COLA en espera. */
function sgsPuertosCrearConsulta(int $usuarioId, string $codigo): ?string {
    $dir = sgsPuertosDir('consultas');
    // Limpieza al paso: nada de lo consultado se guarda más de 10 minutos.
    foreach (glob($dir . '/*.json') ?: [] as $f) if (filemtime($f) < time() - 600) @unlink($f);
    if (count(glob("$dir/*.pendiente.json") ?: []) >= SGS_PUERTOS_COLA) return null;
    $id = sprintf('%014d', (int)(microtime(true) * 1000)) . bin2hex(random_bytes(6));
    sgsPuertosEscribir("$dir/$id.pendiente.json", ['usuario_id' => $usuarioId, 'codigo' => $codigo, 'creada' => time()]);
    return $id;
}

/**
 * Resultado para quien pidió la consulta (null si no existe o no es suya):
 *   en_espera (con "antes": cuántas se atienden antes que la suya), consultando, lista (se entrega una sola vez) o error.
 */
function sgsPuertosResultado(int $usuarioId, string $id): ?array {
    if (!sgsPuertosIdValido($id)) return null;
    $dir = sgsPuertosDir('consultas');
    foreach (['lista', 'tomada', 'pendiente'] as $estado) {
        $archivo = "$dir/$id.$estado.json";
        $c = sgsPuertosLeer($archivo);
        if (!$c) continue;
        if ((int)$c['usuario_id'] !== $usuarioId) return null;
        if ($estado === 'lista') {
            @unlink($archivo);
            return !empty($c['error']) ? ['estado' => 'error', 'message' => $c['error']] : ['estado' => 'lista', 'codigo' => $c['codigo'], 'cto' => $c['cto'], 'puertos' => $c['puertos']];
        }
        // Si la ventana del vínculo se apagó, nadie la va a atender: se avisa en vez de esperar.
        $sinVinculo = !sgsPuenteEstado()['activo'];
        if ($sinVinculo || ($estado === 'tomada' && time() - (int)$c['tomada'] > SGS_PUERTOS_ESPERA + 2 * SGS_PUENTE_SONDEO)) {
            @unlink($archivo);
            return ['estado' => 'error', 'message' => $sinVinculo ? 'La consulta de puertos se desactivó. Vuelve a intentarlo más tarde.' : 'Wincore no respondió a tiempo. Vuelve a intentarlo en un momento.'];
        }
        if ($estado === 'tomada') return ['estado' => 'consultando'];
        $antes = count(glob("$dir/*.tomada.json") ?: []);
        foreach (glob("$dir/*.pendiente.json") ?: [] as $f) { if (basename($f) >= basename($archivo)) break; $antes++; }
        return ['estado' => 'en_espera', 'antes' => $antes];
    }
    return null;
}
/**
 * Respuesta de Wincore (desde la ventana del vínculo). Solo se guarda lo que se muestra: estado y ubicación de la CTO y,
 * por puerto, número, estado y servicio. Nunca datos de clientes, aunque vinieran.
 */
function sgsPuertosGuardarRespuesta(string $id, array $r): bool {
    if (!sgsPuertosIdValido($id)) return false;
    $dir = sgsPuertosDir('consultas');
    $c = sgsPuertosLeer("$dir/$id.tomada.json");
    if (!$c) return false;
    $txt = static fn($v, int $max = 120) => mb_substr(trim((string)$v), 0, $max);
    $coord = static fn($v) => is_numeric($v) && abs((float)$v) <= 180 ? (string)(float)$v : '';
    if (!empty($r['error'])) {
        $c['error'] = $txt($r['error'], 300);
    } else {
        $cto = is_array($r['cto'] ?? null) ? $r['cto'] : [];
        $c['cto'] = ['estado' => $txt($cto['estado'] ?? ''), 'objeto' => $txt($cto['objeto'] ?? ''), 'direccion' => $txt($cto['direccion'] ?? '', 200),
            'distrito' => $txt($cto['distrito'] ?? ''), 'provincia' => $txt($cto['provincia'] ?? ''), 'departamento' => $txt($cto['departamento'] ?? ''),
            'lat' => $coord($cto['lat'] ?? ''), 'lon' => $coord($cto['lon'] ?? '')];
        $c['puertos'] = [];
        foreach (array_slice(is_array($r['puertos'] ?? null) ? $r['puertos'] : [], 0, 128) as $p) {
            if (!is_array($p)) continue;
            $c['puertos'][] = ['puerto' => $txt($p['puerto'] ?? '', 10), 'estado' => $txt($p['estado'] ?? '', 30), 'servicio' => $txt($p['servicio'] ?? '', 30)];
        }
        usort($c['puertos'], static fn($a, $b) => (int)$a['puerto'] <=> (int)$b['puerto']);
    }
    sgsPuertosEscribir("$dir/$id.lista.json", $c);
    @unlink("$dir/$id.tomada.json");
    return true;
}

/** Favorito "Vincular SGS" (javascript:) para la barra de Chrome, con la dirección de este SGS y la de Wincore. */
function sgsFavoritoPuente(string $urlSgs): string {
    $js = (string)file_get_contents(FRONTEND_PATH . '/assets/js/puente_favorito.js');
    $js = preg_replace('#^\s*//.*$#m', '', $js);          // sin comentarios de línea
    $js = preg_replace('/\s*\n\s*/', "\n", trim($js));
    $js = strtr($js, ['__SGS__' => $urlSgs, '__WINCORE__' => sgsWincoreOrigen()]);
    return 'javascript:' . rawurlencode($js);
}
