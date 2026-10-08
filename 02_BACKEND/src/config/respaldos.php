<?php
// 02_BACKEND/src/config/respaldos.php - Respaldos automáticos de la base de datos y del sistema.
// - Base: volcado SQL hecho por PHP (no depende de mysqldump, que muchos hostings bloquean), comprimido .sql.gz.
// - Sistema: código, configuración (.env) y fotos (uploads) en un .tar.gz.
// Se ejecutan: cada 6 h la base y cada 24 h el sistema mientras el sistema se usa (sgsRespaldoAutomatico),
// apenas la base vuelve a responder después de una caída (database.php) y a mano con 07_SCRIPTS/respaldar.php.
// Quedan en 10_RESPALDOS/base y 10_RESPALDOS/sistema (carpeta bloqueada por web y fuera de git).

const SGS_RESPALDOS_PATH = BASE_PATH . '/10_RESPALDOS';
const SGS_RESPALDO_BASE_CADA = 6 * 3600;
const SGS_RESPALDO_SISTEMA_CADA = 24 * 3600;

/** Respalda la base completa. Devuelve la ruta del .sql.gz o lanza excepción. */
function sgsRespaldarBase(PDO $pdo, string $motivo = 'manual'): string
{
    $dir = SGS_RESPALDOS_PATH . '/base';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException("No se pudo crear $dir");
    $motivo = preg_replace('/[^a-z0-9_-]+/i', '-', $motivo) ?: 'manual';
    $final = sprintf('%s/%s_%s_%s.sql.gz', $dir, DB_NAME, date('Ymd_His'), $motivo);
    $tmp = $final . '.parcial';
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) throw new RuntimeException('No se pudo escribir el respaldo de la base');
    try {
        // Foto consistente de todas las tablas (InnoDB), sin bloquear a los usuarios
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $tablas = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        gzwrite($gz, "-- Respaldo de " . DB_NAME . " (" . count($tablas) . " tablas) · " . date('Y-m-d H:i:s') . " · motivo: $motivo\n"
            . "-- Restaurar: phpMyAdmin → seleccionar la base → Importar este .sql.gz, o: gunzip -c archivo.sql.gz | mysql -u usuario -p NOMBRE_BASE\n"
            . "-- ATENCIÓN: reemplaza las tablas existentes por el contenido de este respaldo.\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        foreach ($tablas as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
            $st = $pdo->query("SELECT * FROM `$t`");
            $lote = []; $cols = null;
            while ($fila = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '`' . implode('`, `', array_keys($fila)) . '`';
                $lote[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $fila)) . ')';
                if (count($lote) === 200) { gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $lote) . ";\n"); $lote = []; }
            }
            if ($lote) gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $lote) . ";\n");
            gzwrite($gz, "\n");
        }
        $pdo->exec('COMMIT');
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- Respaldo completo\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        gzclose($gz); @unlink($tmp);
        throw $e;
    }
    gzclose($gz);
    rename($tmp, $final); // el archivo solo aparece cuando está completo
    sgsLimpiarRespaldos($dir, '*.sql.gz', 30, 5);
    win_log_error('db_backup', 'Respaldo de base de datos creado', ['archivo' => basename($final), 'motivo' => $motivo, 'kb' => round(filesize($final) / 1024)]);
    return $final;
}

/** Respalda el sistema (código, .env y fotos). Devuelve la ruta del .tar.gz o lanza excepción. */
function sgsRespaldarSistema(string $motivo = 'manual'): string
{
    $dir = SGS_RESPALDOS_PATH . '/sistema';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException("No se pudo crear $dir");
    $motivo = preg_replace('/[^a-z0-9_-]+/i', '-', $motivo) ?: 'manual';
    $nombre = sprintf('%s/sgs_sistema_%s_%s', $dir, date('Ymd_His'), $motivo);
    $tar = "$nombre.tar";
    $base = str_replace('\\', '/', BASE_PATH);
    // Fuera del respaldo: historial de git, respaldos anteriores, logs y archivos temporales
    $excluir = '#^(\.git|10_RESPALDOS|08_LOGS)(/|$)#';
    try {
        $archivo = new PharData($tar);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($base) + 1);
            if (preg_match($excluir, $rel)) continue;
            $archivo->addFile($f->getPathname(), $rel);
        }
        // Fotos que viven fuera de la carpeta del sistema (instalación local con el sistema de origen al lado)
        $uploads = str_replace('\\', '/', SHARED_FILES_PATH) . '/uploads';
        if (is_dir($uploads) && !str_starts_with($uploads, $base . '/')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) $archivo->addFile($f->getPathname(), 'uploads/' . substr(str_replace('\\', '/', $f->getPathname()), strlen($uploads) + 1));
            }
        }
        $archivo->compress(Phar::GZ); // crea .tar.gz
        unset($archivo);
    } finally {
        @unlink($tar);
    }
    $final = "$nombre.tar.gz";
    if (!is_file($final)) throw new RuntimeException('No se pudo comprimir el respaldo del sistema');
    sgsLimpiarRespaldos($dir, '*.tar.gz', 0, 5);
    win_log_error('system_backup', 'Respaldo del sistema creado', ['archivo' => basename($final), 'motivo' => $motivo, 'kb' => round(filesize($final) / 1024)]);
    return $final;
}

/** Borra respaldos viejos: los de más de $dias (0 = sin límite de días), conservando siempre los $minimo más recientes. */
function sgsLimpiarRespaldos(string $dir, string $patron, int $dias, int $minimo): void
{
    $archivos = glob("$dir/$patron") ?: [];
    usort($archivos, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($archivos, $minimo) as $i => $f) {
        if ($dias === 0 || filemtime($f) < time() - $dias * 86400) @unlink($f);
    }
}

/** Tarea periódica: se llama en cada petición; solo trabaja cuando toca. Nunca interrumpe al usuario. */
function sgsRespaldoAutomatico(PDO $pdo, string $motivo = 'automatico', bool $forzar = false): void
{
    $base = static function () use ($pdo, $motivo) {
        try { sgsRespaldarBase($pdo, $motivo); } catch (Throwable $e) { win_log_error('backup_failed', 'Falló el respaldo automático de la base', ['error' => $e->getMessage()]); }
    };
    $sistema = static function () use ($motivo) {
        try { sgsRespaldarSistema($motivo); } catch (Throwable $e) { win_log_error('backup_failed', 'Falló el respaldo automático del sistema', ['error' => $e->getMessage()]); }
    };
    if ($forzar) { $base(); $sistema(); return; }
    win_run_periodic_task('respaldo-base', SGS_RESPALDO_BASE_CADA, $base);
    win_run_periodic_task('respaldo-sistema', SGS_RESPALDO_SISTEMA_CADA, $sistema);
}

/** Programa el respaldo para DESPUÉS de enviar la página al usuario (no la hace esperar si el servidor lo permite). */
function sgsProgramarRespaldo(string $motivo = 'automatico', bool $forzar = false): void
{
    static $pendiente = null; // un solo respaldo por petición; el forzado (tras una caída) manda sobre el normal
    if (PHP_SAPI === 'cli') return;
    if ($pendiente !== null) {
        if ($forzar) $pendiente = ['motivo' => $motivo, 'forzar' => true];
        return;
    }
    $pendiente = ['motivo' => $motivo, 'forzar' => $forzar];
    register_shutdown_function(static function () use (&$pendiente) {
        ignore_user_abort(true);
        @set_time_limit(300);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        try { sgsRespaldoAutomatico(Database::getConnection(), $pendiente['motivo'], $pendiente['forzar']); } catch (Throwable $e) { /* ya registrado */ }
    });
}
