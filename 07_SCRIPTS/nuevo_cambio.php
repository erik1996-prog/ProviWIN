<?php
// 07_SCRIPTS/nuevo_cambio.php - Crea la carpeta de un cambio de base de datos con la fecha de hoy (solo CLI).
// Uso: php 07_SCRIPTS/nuevo_cambio.php "agregar columna turno a supervisiones"
// Resultado: 04_DATABASE/02_CAMBIOS/AAAA-MM-DD_NN_agregar_columna_turno_a_supervisiones/{cambio.sql, reversion.sql}
// NN = número del cambio en ese día (01, 02…). Luego se completan los dos .sql y se aplica con migrar.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';

$descripcion = trim(implode(' ', array_slice($argv, 1)));
if ($descripcion === '') { fwrite(STDERR, "Uso: php 07_SCRIPTS/nuevo_cambio.php \"descripción del cambio\"\n"); exit(1); }
$sinTildes = strtr(mb_strtolower($descripcion, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
$slug = trim(preg_replace('/[^a-z0-9]+/', '_', $sinTildes), '_');
$slug = substr($slug, 0, 60) ?: 'cambio';

$dir = BASE_PATH . '/04_DATABASE/02_CAMBIOS';
$hoy = date('Y-m-d');
$n = count(glob("$dir/{$hoy}_*", GLOB_ONLYDIR) ?: []) + 1;
$carpeta = sprintf('%s/%s_%02d_%s', $dir, $hoy, $n, $slug);
if (!mkdir($carpeta, 0775, true)) { fwrite(STDERR, "No se pudo crear $carpeta\n"); exit(1); }

foreach (['cambio.sql', 'reversion.sql'] as $archivo) {
    $plantilla = (string)@file_get_contents("$dir/_PLANTILLA/$archivo");
    $plantilla = str_replace(['{{CARPETA}}', '{{FECHA}}', '{{DESCRIPCION}}'], [basename($carpeta), $hoy, $descripcion], $plantilla);
    file_put_contents("$carpeta/$archivo", $plantilla);
}
echo "Cambio creado: 04_DATABASE/02_CAMBIOS/" . basename($carpeta) . "/\n  cambio.sql     → lo que se modifica\n  reversion.sql  → cómo deshacerlo\n"
    . "Después: php 07_SCRIPTS/respaldar.php base antes-de-cambio && php 07_SCRIPTS/migrar.php\n";
