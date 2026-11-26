<?php
// 02_BACKEND/src/config/telegram_token.php - Token del bot de Telegram entregado por la laptop (2026-11-21, pedido del usuario:
// "como Phoenix"). El token vive cifrado en el programa «Vínculo Wincore» de la laptop de la cuenta principal; su vigilante lo
// entrega cada día (api/puertos_agente.php → telegram_token) y aquí se guarda solo hasta las 23:59:59 (archivo 0600 en
// 08_LOGS/runtime/telegram, que no se publica). Con la laptop apagada todo el día, no salen reportes. Si el hosting todavía tiene
// TELEGRAM_TOKEN en su configuración, se usa solo cuando la laptop no entregó el token.

function sgsTelegramTokenArchivo(): string {
    $dir = STORAGE_PATH . '/telegram';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/token.json';
}

/** Token entregado por la laptop, si no venció: ['activo' => bool, 'vence' => ?int, 'token' => string]. */
function sgsTelegramTokenEstado(): array {
    $d = json_decode((string)@file_get_contents(sgsTelegramTokenArchivo()), true);
    if (!is_array($d) || (int)($d['vence'] ?? 0) <= time() || !is_string($d['token'] ?? null)) {
        if (is_array($d)) @unlink(sgsTelegramTokenArchivo());          // venció: se borra
        return ['activo' => false, 'vence' => null, 'token' => ''];
    }
    return ['activo' => true, 'vence' => (int)$d['vence'], 'token' => $d['token']];
}

/** Token para enviar: el de la laptop; si no hay, el de la configuración del hosting (si todavía lo tiene). */
function sgsTelegramToken(): string {
    return sgsTelegramTokenEstado()['token'] ?: (string)getenv('TELEGRAM_TOKEN');
}

function sgsTelegramTokenValido(string $token): bool {
    return (bool)preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,50}$/', $token);
}

/** Pregunta a Telegram de quién es el token (getMe, no envía nada). Nombre del bot, o null si Telegram lo rechazó. */
function sgsTelegramBotDe(#[\SensitiveParameter] string $token): ?string {
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/getMe');
    curl_setopt_array($ch, sgsCurlCa() + [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
    $crudo = curl_exec($ch);
    unset($ch);
    if ($crudo === false) throw new RuntimeException('No se pudo conectar con Telegram.');
    $r = json_decode((string)$crudo, true);
    return !empty($r['ok']) ? (string)($r['result']['username'] ?? '') : null;
}

/** Guarda el token hasta las 23:59:59 de hoy. Devuelve la hora de vencimiento. */
function sgsTelegramTokenGuardar(#[\SensitiveParameter] string $token, int $usuarioId): int {
    $vence = (new DateTimeImmutable('today 23:59:59', new DateTimeZone('America/Lima')))->getTimestamp();
    $archivo = sgsTelegramTokenArchivo();
    $tmp = $archivo . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode(['token' => $token, 'usuario_id' => $usuarioId, 'desde' => time(), 'vence' => $vence]), LOCK_EX);
    @chmod($tmp, 0600);
    rename($tmp, $archivo);
    return $vence;
}
