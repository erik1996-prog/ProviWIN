<?php
// 05_TEST/frontend/check_inline_js.php - Valida la sintaxis del JavaScript incrustado en cada pantalla (requiere Node.js).
// Uso (solo CLI): php 05_TEST/frontend/check_inline_js.php [Rol]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$role = $argv[1] ?? 'Administrador';
$root = dirname(__DIR__, 2);
$pages = ['usuarios', 'asistencia', 'supervision_lista', 'registro_observaciones', 'admin_dashboard', 'timeline_supervisores', 'control_observaciones', 'cuadrillas', 'partners', 'mi_informacion'];
$tmp = sys_get_temp_dir() . '/sgs-inline-js';
@mkdir($tmp, 0700, true);
$fail = 0;
foreach ($pages as $page) {
    // Reutiliza el proceso hijo de la prueba de humo, pero pidiendo el HTML completo.
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/smoke_pages.php.child') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($role) . ' ' . escapeshellarg($page) . ' --html';
    $html = (string)shell_exec($cmd);
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $m);
    $errors = [];
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') continue;
        $file = "$tmp/{$page}_$i.js";
        file_put_contents($file, $js);
        $out = shell_exec('node --check ' . escapeshellarg($file) . ' 2>&1');
        if (trim((string)$out) !== '') $errors[] = "script #$i: " . trim(explode("\n", trim($out))[1] ?? $out) . ' | ' . trim(preg_replace('/\s+/', ' ', implode(' ', array_slice(explode("\n", trim($out)), 0, 4))));
    }
    $fail += count($errors);
    printf("%-24s %2d scripts  %s\n", $page, count($m[1]), $errors ? 'ERROR' : 'OK');
    foreach ($errors as $e) echo "    $e\n";
}
exit($fail ? 1 : 0);
