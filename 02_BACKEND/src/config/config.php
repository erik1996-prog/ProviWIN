<?php
// 02_BACKEND/src/config/config.php - Configuración global de SGS (Sistema de Gestión de Supervisión)

define('SYSTEM_CODE', 'SGS');
define('APP_NAME', 'SGS · Sistema de Gestión de Supervisión');
define('INTERNAL_CHAT_ENABLED', false);

// Rutas del proyecto (estructura según 01_DOCUMENTACION/00_REFERENCIAS/Guia_Estructura_y_Nomenclatura_Sistema.pdf)
define('BASE_PATH', dirname(__DIR__, 3));
define('BACKEND_PATH', BASE_PATH . '/02_BACKEND/src');
define('FRONTEND_PATH', BASE_PATH . '/03_FRONTEND/src');
define('LOG_PATH', BASE_PATH . '/08_LOGS');
define('STORAGE_PATH', LOG_PATH . '/runtime');

require_once __DIR__ . '/env.php';
sgs_load_env(BASE_PATH . '/.env');

define('APP_VERSION', trim((string)@file_get_contents(BASE_PATH . '/VERSION')) ?: '0.0.0');
// Versión de estáticos: versión del sistema + fecha del último cambio en estilos o JavaScript, para que el
// navegador descargue los archivos nuevos en cuanto cambian (sin depender de subir VERSION).
// 2026-10-12: antes solo miraba el CSS y los cambios de JavaScript quedaban en la caché del navegador.
define('ASSET_VERSION', getenv('APP_ASSET_VERSION') ?: APP_VERSION . '.' . max(array_map(
    static fn($archivo) => (int)@filemtime($archivo),
    array_merge([FRONTEND_PATH . '/assets/css/sgs-theme.css'], glob(FRONTEND_PATH . '/assets/js/*.js') ?: [])
)));
define('APP_ENV', getenv('APP_ENV') ?: 'production');
// Meta de productividad: supervisiones finalizadas por día laborable (lunes a sábado; un domingo cuenta solo si se trabajó).
define('SGS_META_SUPERVISIONES_DIA', (float)(getenv('SGS_META_SUPERVISIONES_DIA') ?: 4.5));
define('BRAND_COLOR', '#FF5A0A');
define('BRAND_RGB', '255, 90, 10');

// Base de datos: se comparte win_telecom_db con el sistema de origen.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'win_telecom_db');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
// SGS nunca modifica el esquema de la BD compartida.

// Archivos compartidos (fotos, firmas, avatares). Si el sistema de origen está instalado al lado, se usan sus
// uploads (las URLs "uploads/..." se reescriben hacia allí en .htaccess). Si SGS está solo en el servidor,
// se guardan en ProviWIN/uploads/. SHARED_FILES_PATH en .env manda sobre ambos.
$sgsOrigen = dirname(BASE_PATH) . '/SISTEMA-WIN-PROVINCIA';
define('SHARED_FILES_PATH', rtrim(getenv('SHARED_FILES_PATH') ?: (is_dir($sgsOrigen . '/uploads') ? $sgsOrigen : BASE_PATH), '/\\'));
unset($sgsOrigen);
define('UPLOAD_DIR', SHARED_FILES_PATH . '/uploads/observaciones/');
define('UPLOAD_URL', 'uploads/observaciones/');
// Python para los PDF (requiere reportlab). PYTHON_BIN en .env manda; si no, python3 en Linux o el intérprete local en Windows.
define('SGS_PYTHON_BIN', getenv('PYTHON_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\Users\ERIKJ\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' : 'python3'));

date_default_timezone_set('America/Lima');

// En producción nunca se muestran errores en pantalla (van a 08_LOGS con su código SGS-…)
ini_set('display_errors', APP_ENV === 'production' ? '0' : '1');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // HTTPS directo o detrás de un proxy del hosting (Cloudflare, balanceador)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    // HSTS (2026-10-13): con HTTPS, el navegador ya no intenta entrar por http:// durante 180 días.
    if ($isHttps && !headers_sent()) header('Strict-Transport-Security: max-age=15552000');
    // Nombre propio: la sesión de SGS no se mezcla con la del sistema de origen.
    session_name('SGS_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

foreach ([LOG_PATH . '/backend', LOG_PATH . '/frontend', LOG_PATH . '/database', LOG_PATH . '/errores', STORAGE_PATH] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
}
// Carpetas de fotos (en un servidor nuevo no existen todavía); 0755 porque el servidor web las sirve
foreach ([UPLOAD_DIR, SHARED_FILES_PATH . '/uploads/avatars/'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

require_once __DIR__ . '/runtime.php';
require_once __DIR__ . '/respaldos.php';
