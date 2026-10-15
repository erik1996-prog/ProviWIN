<?php
// 02_BACKEND/src/config/database.php - Conexión a MySQL con PDO (una por petición). No crea ni modifica tablas.

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ];

        try {
            // 1. Intentar conectar a la base de datos directamente
            $port = defined('DB_PORT') ? DB_PORT : '3306';
            $dsn = "mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            $downMarker = defined('STORAGE_PATH') ? STORAGE_PATH . '/database-down.marker' : null;
            if ($downMarker && is_file($downMarker)) {
                // La base vuelve después de una caída: respaldo inmediato de la base y del sistema
                @unlink($downMarker);
                if (function_exists('sgsProgramarRespaldo')) sgsProgramarRespaldo('tras-caida', true);
            }
            @self::$instance->exec("SET time_zone = '" . date('P') . "';");
            // La estructura nunca se toca al conectar: los cambios van como migración (07_SCRIPTS/migrar.php).
            return self::$instance;
        } catch (PDOException $e) {
            self::$instance = null;
            // SGS comparte win_telecom_db: nunca crea la base ni carga esquemas.
            self::respondUnavailable($e);
        }
    }

    /**
     * Causa de la falta de conexión en palabras simples (2026-10-14). Nunca incluye usuario, contraseña ni servidor:
     * la pantalla de inicio de sesión la ve cualquiera. El texto técnico completo queda en 08_LOGS/database.
     * @return array{0:int,1:string} [código MySQL, causa]
     */
    public static function causaConexion(PDOException $error): array {
        $codigo = (int)($error->errorInfo[1] ?? 0);
        if ($codigo === 0 && preg_match('/\[(\d{4})\]/', $error->getMessage(), $m)) $codigo = (int)$m[1];
        if ($codigo === 0 && is_numeric($error->getCode())) $codigo = (int)$error->getCode();
        $causas = [
            1045 => 'La base de datos rechazó el usuario o la contraseña. Revise DB_USER y DB_PASSWORD (en el archivo .env o en config.php).',
            1698 => 'Se está intentando entrar como "root" sin contraseña: no se están usando los datos del hosting. Revise si hay un archivo .env copiado de la PC (tiene prioridad sobre config.php).',
            1044 => 'El usuario de la base de datos no tiene permiso sobre esa base. Revise DB_NAME o los permisos del usuario en cPanel.',
            1049 => 'La base de datos indicada no existe. Revise DB_NAME (en el archivo .env o en config.php).',
            2002 => 'No se puede llegar al servidor de base de datos (apagado o dirección incorrecta). Revise DB_HOST y DB_PORT.',
            2003 => 'No se puede llegar al servidor de base de datos (apagado o puerto bloqueado). Revise DB_HOST y DB_PORT.',
            2005 => 'No se reconoce el servidor de base de datos. Revise DB_HOST.',
            2006 => 'El servidor de base de datos cortó la conexión. Intente de nuevo en unos segundos.',
            2013 => 'El servidor de base de datos cortó la conexión. Intente de nuevo en unos segundos.',
            1040 => 'El servidor de base de datos tiene demasiadas conexiones abiertas. Intente en unos minutos.',
            1203 => 'Se alcanzó el límite de conexiones del usuario de base de datos (plan del hosting). Intente en unos minutos.',
            1226 => 'Se alcanzó el límite de recursos del usuario de base de datos (plan del hosting). Intente en unos minutos.',
        ];
        $causa = $causas[$codigo] ?? (stripos($error->getMessage(), 'timed out') !== false
            ? 'El servidor de base de datos no respondió a tiempo. Revise DB_HOST o el estado del MySQL del hosting.'
            : 'No se pudo conectar con la base de datos.');
        // De dónde salieron los datos de conexión (sin mostrar contraseña ni servidor): ayuda a saber qué archivo corregir.
        if (in_array($codigo, [1045, 1698, 1044, 1049], true)) {
            $envArchivo = defined('BASE_PATH') && is_file(BASE_PATH . '/.env');
            $desdeEnv = getenv('DB_USER') !== false && getenv('DB_USER') !== '';
            $origen = $desdeEnv
                ? ($envArchivo ? 'el archivo .env de la carpeta del sistema' : 'variables del servidor (no hay archivo .env)')
                : 'config.php (02_BACKEND/src/config/config.php)' . ($envArchivo ? '; hay un .env pero no define DB_USER' : '; no hay archivo .env');
            $causa .= ' Datos tomados de: ' . $origen . (defined('DB_USER') && DB_USER === 'root' ? '; usuario: root.' : '.');
        }
        return [$codigo, $causa];
    }

    private static function respondUnavailable(PDOException $error): void {
        [$codigoMysql, $causa] = self::causaConexion($error);
        $marker = defined('STORAGE_PATH') ? STORAGE_PATH . '/database-down.marker' : null;
        $lastReport = $marker && is_file($marker) ? (int)@filemtime($marker) : 0;
        if (time() - $lastReport >= 60) {
            if ($marker) @touch($marker);
            if (function_exists('win_log_error')) {
                win_log_error('database_unavailable', $error->getMessage(), [
                    'driver_code' => (int)($error->errorInfo[1] ?? 0),
                ]);
            }
        }

        $acceptsJson = (defined('WIN_JSON_RESPONSE') && WIN_JSON_RESPONSE)
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if (ob_get_level() > 0) ob_clean();
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 15');
            header('Cache-Control: no-store, max-age=0');
            header('Content-Type: ' . ($acceptsJson ? 'application/json' : 'text/html') . '; charset=utf-8');
        }
        if ($acceptsJson) {
            echo json_encode([
                'status' => 'error',
                'message' => 'El servicio de datos no está disponible: ' . $causa . ($codigoMysql ? " (código MySQL {$codigoMysql})" : ''),
                'causa' => $causa,
                'codigo_mysql' => $codigoMysql ?: null,
                'request_id' => defined('WIN_REQUEST_ID') ? WIN_REQUEST_ID : null,
                'error_code' => function_exists('sgs_error_code') ? sgs_error_code() : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $requestId = function_exists('sgs_error_code') ? htmlspecialchars(sgs_error_code(), ENT_QUOTES, 'UTF-8') : '';
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Servicio temporalmente no disponible</title></head><body style="margin:0;background:#f4f6f9;color:#172033;font-family:Nunito,Segoe UI,sans-serif">'
            . '<main style="max-width:620px;margin:12vh auto;padding:28px"><section style="background:#fff;border:1px solid #e4e8ef;border-radius:16px;padding:32px;box-shadow:0 18px 50px rgba(19,31,55,.10)">'
            . '<div style="width:44px;height:44px;border-radius:12px;background:#fff1e9;color:#e9540b;display:grid;place-items:center;font-size:24px">!</div>'
            . '<h1 style="font-size:24px;margin:18px 0 8px">Estamos restableciendo la conexión</h1>'
            . '<p style="line-height:1.65;color:#596579;margin:0 0 12px">Sus datos no se perdieron. Espere unos segundos y vuelva a intentar.</p>'
            . '<p style="line-height:1.55;color:#596579;margin:0 0 22px;font-size:14px"><strong>Causa:</strong> ' . htmlspecialchars($causa, ENT_QUOTES, 'UTF-8')
            . ($codigoMysql ? ' <span style="color:#8a94a6">(código MySQL ' . $codigoMysql . ')</span>' : '') . '</p>'
            . '<a href="index.php" style="display:inline-block;background:#FF5A0A;color:#fff;text-decoration:none;font-weight:700;padding:11px 18px;border-radius:10px">Reintentar</a>'
            . ($requestId !== '' ? '<p style="font-size:12px;color:#8a94a6;margin:22px 0 0">Código de seguimiento: ' . $requestId . '</p>' : '')
            . '</section></main></body></html>';
        exit;
    }
}
