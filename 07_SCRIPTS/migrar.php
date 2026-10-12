<?php
// 07_SCRIPTS/migrar.php - Aplica los cambios pendientes de la base (solo CLI).
// Cada cambio es una carpeta con fecha en 04_DATABASE/02_CAMBIOS: AAAA-MM-DD_NN_descripcion/{cambio.sql, reversion.sql}
// (se crea con: php 07_SCRIPTS/nuevo_cambio.php "descripcion"). Se aplican en orden de nombre y se anotan en
// la tabla sgs_migracion para no repetirlos.
// Uso: php 07_SCRIPTS/migrar.php                      aplica los pendientes
//      php 07_SCRIPTS/migrar.php --estado             solo muestra cuáles están aplicados y cuáles pendientes
//      php 07_SCRIPTS/migrar.php --revertir CARPETA   ejecuta su reversion.sql y lo quita del registro
// Respaldar antes: php 07_SCRIPTS/respaldar.php base antes-de-cambio
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';

$pdo = Database::getConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS sgs_migracion (
    archivo VARCHAR(120) NOT NULL COMMENT 'Carpeta del cambio en 04_DATABASE/02_CAMBIOS',
    fecha_aplicacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (archivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$dirCambios = BASE_PATH . '/04_DATABASE/02_CAMBIOS';
// Ejecuta un .sql sentencia por sentencia (separadas por ';' al final de línea; los scripts no usan ';' dentro de textos)
$ejecutar = function (string $archivo) use ($pdo): void {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($archivo));
    foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $sentencia) {
        try {
            $pdo->exec($sentencia);
        } catch (PDOException $e) {
            // Índice ya existente (re-ejecución parcial): se ignora; cualquier otro error detiene todo.
            if ((int)($e->errorInfo[1] ?? 0) !== 1061) throw $e;
        }
    }
};

// Revertir un cambio aplicado
$i = array_search('--revertir', $argv, true);
if ($i !== false) {
    $nombre = basename((string)($argv[$i + 1] ?? ''));
    $reversion = "$dirCambios/$nombre/reversion.sql";
    if ($nombre === '' || !is_file($reversion)) { fwrite(STDERR, "Indique la carpeta del cambio a revertir (debe tener reversion.sql).\n"); exit(1); }
    try { $ejecutar($reversion); } catch (PDOException $e) { fwrite(STDERR, "ERROR al revertir $nombre: " . $e->getMessage() . "\n"); exit(1); }
    $pdo->prepare('DELETE FROM sgs_migracion WHERE archivo = :a')->execute([':a' => $nombre]);
    win_log_error('migration_reverted', "Cambio revertido: $nombre");
    echo "  REVERTIDO  $nombre\n";
    exit(0);
}

$aplicados = array_flip($pdo->query('SELECT archivo FROM sgs_migracion')->fetchAll(PDO::FETCH_COLUMN));
$carpetas = array_filter(glob("$dirCambios/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_*", GLOB_ONLYDIR) ?: [], fn($d) => is_file("$d/cambio.sql"));
sort($carpetas);
if (!$carpetas) echo "  (no hay cambios en 04_DATABASE/02_CAMBIOS)\n";

foreach ($carpetas as $carpeta) {
    $nombre = basename($carpeta);
    if (isset($aplicados[$nombre])) { echo "  aplicado   $nombre\n"; continue; }
    if (in_array('--estado', $argv, true)) { echo "  PENDIENTE  $nombre\n"; continue; }
    try {
        $ejecutar("$carpeta/cambio.sql");
    } catch (PDOException $e) {
        fwrite(STDERR, "ERROR en $nombre: " . $e->getMessage() . "\nSe detuvo aquí; los cambios siguientes no se aplicaron.\n");
        exit(1);
    }
    $pdo->prepare('INSERT INTO sgs_migracion (archivo) VALUES (:a)')->execute([':a' => $nombre]);
    win_log_error('migration_applied', "Cambio aplicado: $nombre");
    echo "  APLICADO   $nombre\n";
}
