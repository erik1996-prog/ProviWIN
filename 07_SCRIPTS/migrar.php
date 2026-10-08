<?php
// 07_SCRIPTS/migrar.php - Aplica las migraciones pendientes de 04_DATABASE/09_MIGRATIONS (solo CLI).
// Uso: php 07_SCRIPTS/migrar.php            aplica las pendientes
//      php 07_SCRIPTS/migrar.php --estado   solo muestra qué está aplicado
// Registra cada migración en SGS_MIGRACION para no repetirla. Haga un respaldo antes (ver INSTALACION.md).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';

$pdo = Database::getConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS SGS_MIGRACION (
    ARCHIVO VARCHAR(120) NOT NULL,
    FECHA_APLICACION DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_SGS_MIGRACION PRIMARY KEY (ARCHIVO)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = array_flip($pdo->query('SELECT ARCHIVO FROM SGS_MIGRACION')->fetchAll(PDO::FETCH_COLUMN));
$files = glob(BASE_PATH . '/04_DATABASE/09_MIGRATIONS/[0-9][0-9][0-9]_*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) { echo "  aplicada   $name\n"; continue; }
    if (in_array('--estado', $argv, true)) { echo "  PENDIENTE  $name\n"; continue; }
    // Separa sentencias por ';' al final de línea (los archivos no usan ';' dentro de cadenas).
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // Índice ya existente (re-ejecución parcial): se ignora; cualquier otro error detiene todo.
            if ((int)($e->errorInfo[1] ?? 0) !== 1061) { fwrite(STDERR, "ERROR en $name: " . $e->getMessage() . "\n"); exit(1); }
        }
    }
    $pdo->prepare('INSERT INTO SGS_MIGRACION (ARCHIVO) VALUES (:a)')->execute([':a' => $name]);
    win_log_error('migration_applied', "Migración aplicada: $name");
    echo "  APLICADA   $name\n";
}
