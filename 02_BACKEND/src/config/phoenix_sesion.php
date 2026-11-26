<?php
// 02_BACKEND/src/config/phoenix_sesion.php - Sesión de Phoenix (Winbo) del SGS, hasta la medianoche (2026-11-19; 2026-11-21 solo por
// el programa de la laptop). El programa «Vínculo Wincore» (api/puertos_agente.php → phoenix_iniciar) entra a Phoenix con el
// usuario y la contraseña guardados cifrados en la laptop; aquí se guardan solo las cookies de esa sesión (sesion_aqui.cookie) y
// sus datos visibles (sesion_aqui.json). La contraseña no se guarda en ningún archivo, base de datos ni log. Vence a las 23:59:59
// hora de Perú; si Phoenix la cierra antes, se borra y el programa vuelve a entrar solo (de 05:55 a 21:00).
// La usan Órdenes cercanas e Inicio de órdenes (services/WinboService.php). Archivos en 08_LOGS/runtime/phoenix (no se publican).
require_once __DIR__ . '/puertos_cto.php';   // sgsPuertosLeer / sgsPuertosEscribir

function sgsPhoenixDir(): string {
    $dir = STORAGE_PATH . '/phoenix';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

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
