<?php
// 05_TEST/frontend/smoke_pages.php - Prueba de humo: renderiza cada pantalla como un usuario del rol indicado.
// Uso (solo CLI):  php 05_TEST/frontend/smoke_pages.php [Rol]   (Administrador por defecto; también Supervisor:directo, Supervisor:partner)
// Solo lee la BD, salvo "registro_observaciones", que al abrirse aplica la regla SLA (vence plazos), igual que en uso normal.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$role = 'Administrador';
foreach (array_slice($argv, 1) as $arg) $role = $arg;

$root = dirname(__DIR__, 2);
$pages = ['guia_estilo', 'usuarios', 'asistencia', 'supervision_lista', 'registro_observaciones', 'admin_dashboard', 'timeline_supervisores', 'control_observaciones', 'mi_informacion'];

$failed = 0;
foreach ($pages as $page) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__ . '.child') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($role) . ' ' . escapeshellarg($page);
    $out = shell_exec($cmd);
    $ok = str_starts_with((string)$out, 'OK') || str_starts_with((string)$out, 'SKIP');
    $failed += $ok ? 0 : 1;
    printf("%-26s %s\n", $page, trim((string)$out));
}
exit($failed ? 1 : 0);
