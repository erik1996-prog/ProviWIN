<?php
// 02_BACKEND/src/config/respaldos.php - Respaldos automáticos de la base de datos y del sistema.
// - Base: volcado SQL hecho por PHP (no depende de mysqldump, que muchos hostings bloquean), comprimido .sql.gz.
// - Sistema: código y configuración (.env) en un .tar.gz, SIN fotos.
// - Fotos (uploads): aparte, una vez por semana.
// 2026-10-16: ya NO se hacen durante las visitas de los usuarios. Comprimir el sistema con todas las fotos dentro de una
// petición web tardaba minutos, ocupaba procesos del hosting y, si el hosting lo cortaba, se volvía a empezar en la
// siguiente visita: con varios usuarios conectados el servidor dejaba de responder (error 504 de Cloudflare).
// Ahora los hace la tarea programada (cron) de cPanel con "php 07_SCRIPTS/respaldar.php automatico" cada 6 h:
// la base en cada corrida; el sistema y las fotos solo de madrugada (antes de las 7:00), cada día y cada semana.
// La única copia que se hace desde la web es la de la BASE apenas vuelve después de una caída (liviana).
// Quedan en 10_RESPALDOS/base, /sistema y /fotos (carpeta bloqueada por web y fuera de git); se guardan los recientes.

const SGS_RESPALDOS_PATH = BASE_PATH . '/10_RESPALDOS';
const SGS_RESPALDO_BASE_CADA = 5 * 3600;          // el cron corre cada 6 h; margen para que no se salte
const SGS_RESPALDO_SISTEMA_CADA = 20 * 3600;      // una vez al día (de madrugada)
const SGS_RESPALDO_FOTOS_CADA = 6 * 86400 + 12 * 3600; // una vez por semana (de madrugada)
const SGS_RESPALDO_HORA_LIMITE = 7;               // sistema y fotos solo antes de esta hora: nadie está trabajando

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
    sgsLimpiarRespaldos($dir, '*.sql.gz', 7, 5); // una semana (y siempre los 5 más recientes)
    win_log_error('db_backup', 'Respaldo de base de datos creado', ['archivo' => basename($final), 'motivo' => $motivo, 'kb' => round(filesize($final) / 1024)]);
    return $final;
}

/** Respalda el sistema (código y .env, sin fotos: van en sgsRespaldarFotos). Devuelve la ruta del .tar.gz o lanza excepción. */
function sgsRespaldarSistema(string $motivo = 'manual'): string
{
    $dir = SGS_RESPALDOS_PATH . '/sistema';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException("No se pudo crear $dir");
    $motivo = preg_replace('/[^a-z0-9_-]+/i', '-', $motivo) ?: 'manual';
    $nombre = sprintf('%s/sgs_sistema_%s_%s', $dir, date('Ymd_His'), $motivo);
    $tar = "$nombre.tar";
    $base = str_replace('\\', '/', BASE_PATH);
    // Fuera del respaldo: historial de git, respaldos anteriores, logs y las fotos (van aparte, cada semana)
    $excluir = '#^(\.git|10_RESPALDOS|08_LOGS|uploads|11_PRIVADO)(/|$)#'; // 11_PRIVADO va con las fotos
    try {
        $archivo = new PharData($tar);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($base) + 1);
            if (preg_match($excluir, $rel)) continue;
            $archivo->addFile($f->getPathname(), $rel);
        }
        $archivo->compress(Phar::GZ); // crea .tar.gz
        unset($archivo);
    } finally {
        @unlink($tar);
    }
    $final = "$nombre.tar.gz";
    if (!is_file($final)) throw new RuntimeException('No se pudo comprimir el respaldo del sistema');
    sgsLimpiarRespaldos($dir, '*.tar.gz', 0, 3); // los 3 más recientes
    win_log_error('system_backup', 'Respaldo del sistema creado', ['archivo' => basename($final), 'motivo' => $motivo, 'kb' => round(filesize($final) / 1024)]);
    return $final;
}

/** Respalda las fotos (uploads: supervisiones, observaciones, checklists, documentos, avatares) y los sustentos privados
 * (11_PRIVADO: certificados de ausencias, 2026-10-26). Devuelve el .tar.gz. */
function sgsRespaldarFotos(string $motivo = 'manual'): string
{
    $dir = SGS_RESPALDOS_PATH . '/fotos';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException("No se pudo crear $dir");
    $motivo = preg_replace('/[^a-z0-9_-]+/i', '-', $motivo) ?: 'manual';
    $nombre = sprintf('%s/sgs_fotos_%s_%s', $dir, date('Ymd_His'), $motivo);
    $tar = "$nombre.tar";
    // Las fotos pueden estar dentro de la carpeta del sistema (hosting) o al lado, en el sistema de origen (esta PC).
    $carpetas = array_unique(array_filter([str_replace('\\', '/', BASE_PATH) . '/uploads', str_replace('\\', '/', SHARED_FILES_PATH) . '/uploads'], 'is_dir'));
    if (!$carpetas) throw new RuntimeException('No se encontró la carpeta de fotos (uploads)');
    try {
        $archivo = new PharData($tar);
        foreach ($carpetas as $uploads) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) $archivo->addFile($f->getPathname(), 'uploads/' . substr(str_replace('\\', '/', $f->getPathname()), strlen($uploads) + 1));
            }
        }
        $privado = str_replace('\\', '/', BASE_PATH) . '/11_PRIVADO';
        if (is_dir($privado)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($privado, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) $archivo->addFile($f->getPathname(), '11_PRIVADO/' . substr(str_replace('\\', '/', $f->getPathname()), strlen($privado) + 1));
        }
        $archivo->compress(Phar::GZ);
        unset($archivo);
    } finally {
        @unlink($tar);
    }
    $final = "$nombre.tar.gz";
    if (!is_file($final)) throw new RuntimeException('No se pudo comprimir el respaldo de las fotos');
    sgsLimpiarRespaldos($dir, '*.tar.gz', 0, 2); // las 2 más recientes
    win_log_error('photos_backup', 'Respaldo de fotos creado', ['archivo' => basename($final), 'motivo' => $motivo, 'kb' => round(filesize($final) / 1024)]);
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

/**
 * Respaldo automático para la tarea programada (cron cada 6 h): la base en cada corrida; el sistema (diario) y las fotos
 * (semanal) solo de madrugada, cuando nadie trabaja. Devuelve lo que hizo, para el registro del cron.
 */
function sgsRespaldoAutomatico(PDO $pdo, string $motivo = 'automatico', bool $forzar = false): array
{
    $hecho = [];
    $ejecutar = static function (string $nombre, callable $tarea) use (&$hecho) {
        try { $hecho[$nombre] = basename($tarea()); }
        catch (Throwable $e) { $hecho[$nombre] = 'ERROR: ' . $e->getMessage(); win_log_error('backup_failed', "Falló el respaldo automático ({$nombre})", ['error' => $e->getMessage()]); }
    };
    $madrugada = (int)date('G') < SGS_RESPALDO_HORA_LIMITE;
    if ($forzar) {
        $ejecutar('base', fn() => sgsRespaldarBase($pdo, $motivo));
        $ejecutar('sistema', fn() => sgsRespaldarSistema($motivo));
        $ejecutar('fotos', fn() => sgsRespaldarFotos($motivo));
        return $hecho;
    }
    win_run_periodic_task('respaldo-base', SGS_RESPALDO_BASE_CADA, fn() => $ejecutar('base', fn() => sgsRespaldarBase($pdo, $motivo)));
    if ($madrugada) {
        win_run_periodic_task('respaldo-sistema', SGS_RESPALDO_SISTEMA_CADA, fn() => $ejecutar('sistema', fn() => sgsRespaldarSistema($motivo)));
        win_run_periodic_task('respaldo-fotos', SGS_RESPALDO_FOTOS_CADA, fn() => $ejecutar('fotos', fn() => sgsRespaldarFotos($motivo)));
    }
    return $hecho;
}

/**
 * Desde la web solo queda una copia: la de la BASE apenas vuelve después de una caída (database.php). Es liviana y se hace
 * después de enviar la página. El sistema y las fotos nunca se respaldan en una visita (ver el inicio del archivo).
 */
function sgsProgramarRespaldo(string $motivo = 'tras-caida'): void
{
    static $programado = false;
    if (PHP_SAPI === 'cli' || $programado) return;
    $programado = true;
    register_shutdown_function(static function () use ($motivo) {
        ignore_user_abort(true);
        @set_time_limit(120);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        try { sgsRespaldarBase(Database::getConnection(), $motivo); }
        catch (Throwable $e) { win_log_error('backup_failed', 'Falló el respaldo de la base tras la caída', ['error' => $e->getMessage()]); }
    });
}
