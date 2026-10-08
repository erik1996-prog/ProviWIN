<?php
// 05_TEST/api/nombres_tablas_test.php - Los nombres de tabla del código deben coincidir EXACTO (mayúsculas incluidas)
// con los de la base. En Windows MySQL no distingue mayúsculas; en Linux (hosting) sí, y la pantalla falla.
// Uso (solo CLI): php 05_TEST/api/nombres_tablas_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
$tablas = Database::getConnection()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$porMinuscula = [];
foreach ($tablas as $t) $porMinuscula[strtolower($t)] = $t;

$errores = [];
$sinMayus = 0;
foreach ($tablas as $t) if ($t !== strtolower($t)) $errores[] = "Tabla con mayúsculas en la base: $t (use minúsculas)";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $ruta = str_replace('\\', '/', $f->getPathname());
    if ($f->getExtension() !== 'php' || preg_match('#/(05_TEST|04_DATABASE|08_LOGS|\.git)/#', $ruta)) continue;
    foreach (file($ruta) as $n => $linea) {
        if (!preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?: IF NOT EXISTS)?)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $linea, $m)) continue;
        foreach ($m[1] as $nombre) {
            $clave = strtolower($nombre);
            if (isset($porMinuscula[$clave]) && $porMinuscula[$clave] !== $nombre) {
                $errores[] = substr($ruta, strlen(str_replace('\\', '/', BASE_PATH)) + 1) . ':' . ($n + 1) . " usa `$nombre` y la tabla es `{$porMinuscula[$clave]}`";
            }
        }
    }
}
echo "Nombres de tabla (" . count($tablas) . " tablas)\n";
foreach ($errores as $e) echo "  ✗ $e\n";
echo $errores ? "\n" . count($errores) . " problema(s): fallará en un servidor Linux.\n" : "  ✓ el código usa los nombres exactos de la base\n";
exit($errores ? 1 : 0);
