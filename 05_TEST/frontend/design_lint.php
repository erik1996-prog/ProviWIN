<?php
// 05_TEST/frontend/design_lint.php - Revisa que las pantallas cumplan el sistema de diseño SGS (skill "sgs-diseno").
// Uso (solo CLI): php 05_TEST/frontend/design_lint.php [archivo.php ...]   (sin argumentos: todas las pantallas)
// ERRORES (rompen la prueba): emojis, opciones "-- Seleccione --", fuentes ajenas, degradados de Bootstrap,
//   botones/insignias con colores decorativos de Bootstrap que no existen en la paleta (bg-purple, bg-gradient...).
// AVISOS (deuda heredada, no deben crecer): colores #hex y estilos en línea (style="...") dentro de las pantallas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$files = array_slice($argv, 1) ?: array_merge(glob("$root/03_FRONTEND/src/pages/*.php"), glob("$root/03_FRONTEND/src/layouts/*.php"));

// Línea base de deuda heredada (pantallas que vienen del sistema de origen). Una pantalla nueva debe tener 0.
$baseline = [ // valores al 2026-10-07; solo pueden bajar
    'admin_dashboard.php' => ['hex' => 6, 'style' => 18],
    'asistencia.php' => ['hex' => 2, 'style' => 0],
    'control_observaciones.php' => ['hex' => 0, 'style' => 3],
    'guia_estilo.php' => ['hex' => 13, 'style' => 4],
    'login.php' => ['hex' => 1, 'style' => 1],
    'mi_informacion.php' => ['hex' => 1, 'style' => 1],
    'observaciones_partner.php' => ['hex' => 0, 'style' => 3],
    'registro_observaciones.php' => ['hex' => 1, 'style' => 6],
    'supervision_lista.php' => ['hex' => 31, 'style' => 92],
    'supervision_registro.php' => ['hex' => 0, 'style' => 13],
    'timeline_supervisores.php' => ['hex' => 0, 'style' => 7],
    'topbar.php' => ['hex' => 0, 'style' => 2],
];

$errors = 0; $warnings = 0;
foreach ($files as $file) {
    $name = basename($file);
    $lines = file($file);
    $found = [];
    $hex = 0; $style = 0;
    foreach ($lines as $i => $line) {
        $n = $i + 1;
        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{23E9}-\x{23FA}]/u', $line, $m))
            $found[] = ["ERROR", $n, "emoji \"{$m[0]}\": usa un ícono de Bootstrap Icons (<i class=\"bi bi-...\"></i>)"];
        if (preg_match('/<option[^>]*>\s*--/u', $line))
            $found[] = ["ERROR", $n, 'opción con guiones "-- ... --": usa "Todos los…" o "Seleccione…"'];
        if (preg_match("/font-family\s*:\s*['\"]?(Inter|Comfortaa|Arial|Roboto|Poppins|Open Sans|Helvetica)/i", $line, $m))
            $found[] = ["ERROR", $n, "fuente \"{$m[1]}\": usa var(--sgs-font) o var(--sgs-font-display)"];
        if (preg_match('/\b(bg-gradient|bg-purple|bg-indigo|text-purple|btn-info)\b/', $line, $m))
            $found[] = ["ERROR", $n, "clase \"{$m[1]}\" fuera de la paleta SGS"];
        if (preg_match('/<h[1-6][^>]*>[^<]*\b[A-ZÁÉÍÓÚ][a-záéíóú]+ (?:[A-ZÁÉÍÓÚ][a-záéíóú]+ ){2,}/u', $line))
            $found[] = ["AVISO", $n, 'título en "Mayúscula En Cada Palabra": usa estilo de oración'];
        // Deuda medible
        $isStyleBlock = preg_match('/^\s*[.#@a-z][^{]*\{.*\}\s*$/i', $line) || preg_match('/^\s*--sgs-/', $line);
        if (!$isStyleBlock) $hex += preg_match_all('/(?<![&\w])#[0-9a-fA-F]{3,8}\b(?![\w-])/', preg_replace('/<style>.*?<\/style>/s', '', $line));
        $style += substr_count($line, 'style="');
    }
    $lim = $baseline[$name] ?? ['hex' => 0, 'style' => 0];
    if ($hex > $lim['hex']) $found[] = ["AVISO", 0, "$hex colores #hex en la pantalla (máximo $lim[hex]): usa var(--sgs-*)"];
    if ($style > $lim['style']) $found[] = ["AVISO", 0, "$style estilos en línea style=\"...\" (máximo $lim[style]): usa clases"];
    if (!$found) { printf("  ✓ %-28s hex %3d · style %3d\n", $name, $hex, $style); continue; }
    printf("  %s %-28s hex %3d · style %3d\n", array_filter($found, fn($f) => $f[0] === 'ERROR') ? '✗' : '!', $name, $hex, $style);
    foreach ($found as [$lvl, $n, $msg]) {
        echo "      $lvl" . ($n ? " L$n" : '') . ": $msg\n";
        $lvl === 'ERROR' ? $errors++ : $warnings++;
    }
}
echo "\n" . ($errors ? "$errors error(es)" : 'Sin errores') . ", $warnings aviso(s).\n";
exit($errors ? 1 : 0);
