<?php
// 07_SCRIPTS/respaldar.php - Respaldos a mano o por la tarea programada de cPanel (solo CLI).
// Uso: php 07_SCRIPTS/respaldar.php [automatico|todo|base|sistema|fotos] [motivo]   (por defecto: todo, motivo "manual")
//   automatico  → para el cron cada 6 h: la base siempre; el sistema (diario) y las fotos (semanal) solo de madrugada.
//   todo        → base + sistema + fotos, ahora mismo.
// Deja los archivos en 10_RESPALDOS/base (.sql.gz), /sistema y /fotos (.tar.gz). Cómo restaurar: 10_RESPALDOS/LEEME.md
// 2026-10-16: las visitas de los usuarios ya no hacen respaldos (causaban el error 504); por eso hace falta el cron.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';

$modos = ['automatico', 'todo', 'base', 'sistema', 'fotos'];
$que = in_array($argv[1] ?? '', $modos, true) ? $argv[1] : 'todo';
$motivo = $argv[$que === ($argv[1] ?? '') ? 2 : 1] ?? ($que === 'automatico' ? 'automatico' : 'manual');
$error = false;
$informar = static function (string $nombre, callable $tarea) use (&$error) {
    try { $f = $tarea(); echo "{$nombre} OK: 10_RESPALDOS/" . basename(dirname($f)) . '/' . basename($f) . ' (' . round(filesize($f) / 1024) . " KB)\n"; }
    catch (Throwable $e) { $error = true; fwrite(STDERR, "ERROR en {$nombre}: " . $e->getMessage() . "\n"); }
};

if ($que === 'automatico') {
    $hecho = sgsRespaldoAutomatico(Database::getConnection(), $motivo);
    if (!$hecho) echo date('Y-m-d H:i') . " · Nada que respaldar todavía.\n";
    foreach ($hecho as $nombre => $resultado) {
        if (str_starts_with($resultado, 'ERROR')) { $error = true; fwrite(STDERR, date('Y-m-d H:i') . " · {$nombre}: {$resultado}\n"); }
        else echo date('Y-m-d H:i') . " · {$nombre}: {$resultado}\n";
    }
    exit($error ? 1 : 0);
}
if (in_array($que, ['todo', 'base'], true)) $informar('Respaldo de la base', fn() => sgsRespaldarBase(Database::getConnection(), $motivo));
if (in_array($que, ['todo', 'sistema'], true)) $informar('Respaldo del sistema', fn() => sgsRespaldarSistema($motivo));
if (in_array($que, ['todo', 'fotos'], true)) $informar('Respaldo de las fotos', fn() => sgsRespaldarFotos($motivo));
exit($error ? 1 : 0);
