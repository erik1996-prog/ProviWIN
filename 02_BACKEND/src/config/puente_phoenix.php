<?php
// 02_BACKEND/src/config/puente_phoenix.php - Vínculo con Phoenix (Winbo) para "Órdenes cercanas" (2026-11-13, pedido del usuario).
// Igual que el vínculo con Wincore (config/puertos_cto.php): la cuenta principal inicia sesión en Phoenix en su laptop y pulsa
// el favorito "Vincular SGS – Phoenix", que abre la ventana del vínculo (puente_phoenix.php). Mientras está activo, el servidor
// ya no entra a Phoenix con usuario y contraseña: le pide cada página a esa pestaña, que la trae con su propia sesión.
//   1) WinboService deja un pedido "pendiente" (pasos: rutas de Phoenix y sus datos) y NO espera: responde al navegador
//      "consultando" (SgsPhoenixEnCurso) y libera el proceso del hosting (2026-11-13, sistema liviano: antes esperaba hasta
//      35 s con un proceso ocupado y el hosting se saturaba). El navegador repite la misma petición cada 2 s.
//   2) La ventana del vínculo pregunta cada SGS_PUENTE_SONDEO segundos, toma un pedido a la vez y lo pasa a Phoenix.
//   3) La respuesta queda "lista" y, en la siguiente repetición, WinboService la recoge. El nombre del pedido sale de sus
//      pasos: si varios usuarios piden lo mismo, comparten un solo pedido. Lo leído se guarda 2 minutos para todos.
// El favorito solo acepta las rutas de lectura de la grilla de órdenes (no se le puede pedir otra cosa).
// Sistema liviano: sin base de datos, archivos chicos en 08_LOGS/runtime/phoenix que se borran solos a los 10 minutos.
require_once __DIR__ . '/puertos_cto.php';   // SGS_PUENTE_HORAS, SGS_PUENTE_SONDEO, SGS_PUENTE_SIN_PULSO, sgsPuertosLeer/Escribir

const SGS_PHOENIX_ORIGEN = 'https://winbo-phx.azurewebsites.net';
const SGS_PHOENIX_ESPERA = 35;          // segundos que tiene Phoenix para responder un pedido tomado
const SGS_PHOENIX_VIGENCIA = 120;       // segundos que vale una respuesta lista

/** El pedido a Phoenix sigue en curso: el navegador vuelve a preguntar en unos segundos. */
class SgsPhoenixEnCurso extends Exception {}

function sgsPhoenixDir(string $sub = ''): string {
    $dir = STORAGE_PATH . '/phoenix' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** Activo solo si no venció y la ventana del vínculo preguntó hace poco. */
function sgsPhoenixEstado(): array {
    $p = sgsPuertosLeer(sgsPhoenixDir() . '/puente.json');
    $ahora = time();
    $activo = $p && $p['vence'] > $ahora && $ahora - $p['pulso'] <= SGS_PUENTE_SIN_PULSO;
    return ['activo' => $activo, 'vence' => $activo ? (int)$p['vence'] : null];
}

function sgsPhoenixActivar(int $usuarioId): int {
    $vence = time() + SGS_PUENTE_HORAS * 3600;
    sgsPuertosEscribir(sgsPhoenixDir() . '/puente.json', ['usuario_id' => $usuarioId, 'desde' => time(), 'vence' => $vence, 'pulso' => time()]);
    return $vence;
}

function sgsPhoenixDesactivar(): void {
    @unlink(sgsPhoenixDir() . '/puente.json');
    foreach (glob(sgsPhoenixDir('pedidos') . '/*.{pendiente,tomada}.json', GLOB_BRACE) ?: [] as $f) @unlink($f);
}

/** Pregunta de la ventana del vínculo: marca el pulso y entrega el pedido más antiguo si Phoenix no atiende otro. */
function sgsPhoenixTomar(int $usuarioId): ?array {
    $archivo = sgsPhoenixDir() . '/puente.json';
    $p = sgsPuertosLeer($archivo);
    if (!$p || (int)$p['usuario_id'] !== $usuarioId || $p['vence'] <= time()) return null;
    $p['pulso'] = time();
    sgsPuertosEscribir($archivo, $p);
    $dir = sgsPhoenixDir('pedidos');
    foreach (glob("$dir/*.tomada.json") ?: [] as $f) {
        $c = sgsPuertosLeer($f);
        if ($c && time() - (int)($c['tomada'] ?? 0) <= SGS_PHOENIX_ESPERA) return ['vence' => (int)$p['vence'], 'pedido' => null];
        @unlink($f);                                  // Phoenix no respondió: quien esperaba ya recibió el aviso
    }
    $pedido = null;
    $pendientes = glob("$dir/*.pendiente.json") ?: [];
    usort($pendientes, static fn($a, $b) => @filemtime($a) <=> @filemtime($b));   // por orden de llegada
    foreach ($pendientes as $f) {
        $id = basename($f, '.pendiente.json');
        if (!@rename($f, "$dir/$id.tomada.json") || !($c = sgsPuertosLeer("$dir/$id.tomada.json"))) continue;
        $c['tomada'] = time();
        sgsPuertosEscribir("$dir/$id.tomada.json", $c);
        $pedido = ['id' => $id, 'pasos' => $c['pasos']];
        break;
    }
    return ['vence' => (int)$p['vence'], 'pedido' => $pedido];
}

/** Respuesta de Phoenix: el texto de cada paso (o un error). */
function sgsPhoenixResponder(string $id, array $r): bool {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return false;
    $dir = sgsPhoenixDir('pedidos');
    if (!is_file("$dir/$id.tomada.json")) return false;
    $textos = array_values(array_map('strval', array_filter(is_array($r['textos'] ?? null) ? $r['textos'] : [], 'is_scalar')));
    sgsPuertosEscribir("$dir/$id.lista.json", empty($r['error']) ? ['textos' => $textos] : ['error' => mb_substr((string)$r['error'], 0, 300)]);
    @unlink("$dir/$id.tomada.json");
    return true;
}

/**
 * Pide a Phoenix, a través del vínculo, los pasos dados (cada uno: ['ruta' => ..., 'json' => array|null]) y devuelve sus
 * respuestas en el mismo orden si ya llegaron. Si no, deja el pedido (o ve que ya está en curso) y lanza SgsPhoenixEnCurso:
 * nunca deja un proceso del hosting esperando. Lo usa WinboService cuando el vínculo está activo.
 */
function sgsPhoenixPedir(array $pasos): array {
    $dir = sgsPhoenixDir('pedidos');
    foreach (glob($dir . '/*.json') ?: [] as $f) if (filemtime($f) < time() - 600) @unlink($f);
    $id = md5(json_encode($pasos, JSON_UNESCAPED_UNICODE));
    if ($c = sgsPuertosLeer("$dir/$id.lista.json")) {
        $vieja = time() - (int)@filemtime("$dir/$id.lista.json") > SGS_PHOENIX_VIGENCIA;
        if (!empty($c['error']) || $vieja) @unlink("$dir/$id.lista.json");
        if (!empty($c['error'])) throw new RuntimeException($c['error']);
        if (!$vieja) return $c['textos'];
    }
    if (!sgsPhoenixEstado()['activo']) throw new RuntimeException('La consulta de órdenes no está activa en este momento.');
    if (!is_file("$dir/$id.pendiente.json") && !is_file("$dir/$id.tomada.json")) {
        sgsPuertosEscribir("$dir/$id.pendiente.json", ['pasos' => $pasos, 'creado' => time()]);
    }
    throw new SgsPhoenixEnCurso();
}
// ── Sesión de Phoenix iniciada aquí, hasta la medianoche (2026-11-19, pedido del usuario) ──────────────────────────────
// Otra forma de conectar, sin dejar la laptop abierta: la cuenta principal abre la ventanita "Iniciar sesión en Phoenix"
// (phoenix_sesion.php), escribe su usuario y contraseña una vez y el servidor entra a Phoenix (WinboService::iniciarSesionAqui).
// Solo se guardan las cookies de esa sesión (sesion_aqui.cookie) y sus datos visibles (sesion_aqui.json); la contraseña no se
// guarda en ningún archivo, base de datos ni log. Vence a las 23:59:59 hora de Perú; si Phoenix la cierra antes, se borra y
// Órdenes cercanas pide iniciar de nuevo. El favorito sigue funcionando y, si está activo, tiene prioridad.
function sgsPhoenixSesionCookie(): string { return sgsPhoenixDir() . '/sesion_aqui.cookie'; }

/** 23:59:59 de hoy en Perú. */
function sgsPhoenixMedianoche(): int {
    return (new DateTimeImmutable('today 23:59:59', new DateTimeZone('America/Lima')))->getTimestamp();
}

/** Activa si no pasó la medianoche y sus cookies siguen ahí; si venció, se borra. */
function sgsPhoenixSesion(): array {
    $s = sgsPuertosLeer(sgsPhoenixDir() . '/sesion_aqui.json');
    if (!$s) return ['activa' => false];
    if ((int)($s['vence'] ?? 0) <= time() || !is_file(sgsPhoenixSesionCookie())) { sgsPhoenixSesionCerrar(); return ['activa' => false]; }
    return ['activa' => true, 'vence' => (int)$s['vence'], 'usuario' => (string)($s['usuario'] ?? ''), 'desde' => (int)($s['desde'] ?? 0)];
}

function sgsPhoenixSesionGuardar(string $usuarioPhoenix, int $usuarioId): int {
    $vence = sgsPhoenixMedianoche();
    sgsPuertosEscribir(sgsPhoenixDir() . '/sesion_aqui.json', ['usuario' => $usuarioPhoenix, 'usuario_id' => $usuarioId, 'desde' => time(), 'vence' => $vence]);
    @chmod(sgsPhoenixSesionCookie(), 0600);
    return $vence;
}

function sgsPhoenixSesionCerrar(): void {
    @unlink(sgsPhoenixDir() . '/sesion_aqui.json');
    @unlink(sgsPhoenixSesionCookie());
}

/** Favorito "Vincular SGS – Phoenix": el código de assets/js/phoenix_favorito.js con la dirección del SGS. */
function sgsFavoritoPhoenix(string $urlSgs): string {
    $js = (string)file_get_contents(FRONTEND_PATH . '/assets/js/phoenix_favorito.js');
    $js = preg_replace('#^\s*//.*$#m', '', $js);
    $js = preg_replace('/\s*\n\s*/', "\n", trim($js));
    $js = strtr($js, ['__SGS__' => $urlSgs, '__PHOENIX__' => SGS_PHOENIX_ORIGEN]);
    return 'javascript:' . rawurlencode($js);
}
