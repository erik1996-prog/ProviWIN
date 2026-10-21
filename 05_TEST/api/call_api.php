<?php
// 05_TEST/api/call_api.php - Llama a un endpoint GET de la API como un usuario del rol indicado (solo CLI, solo lectura).
// Uso: php 05_TEST/api/call_api.php <endpoint> "<query>" [Rol]
//   php 05_TEST/api/call_api.php dashboard_partners "action=dashboard&desde=2026-09-01"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $endpoint, $query] = $argv + [null, null, ''];
$role = $argv[3] ?? 'Administrador';
[$roleName, $kind] = array_pad(explode(':', $role, 2), 2, '');
register_shutdown_function(static function () { fwrite(STDERR, "
@@HTTP " . (http_response_code() ?: 200) . "@@
"); });
$root = dirname(__DIR__, 2);
parse_str($query, $_GET);
$_SERVER = array_merge($_SERVER, ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => "/ProviWIN/api/$endpoint.php", 'REQUEST_URI' => "/ProviWIN/api/$endpoint.php?$query"]);
$_SESSION = [];
require $root . '/02_BACKEND/src/config/config.php';
require $root . '/02_BACKEND/src/config/auth.php';
$extra = $kind === 'directo' ? ' AND ' . sgsDirectStaffSql() : ($kind === 'partner' ? ' AND NOT ' . sgsDirectStaffSql() : '');
$st = Database::getConnection()->prepare("SELECT u.*, p.nombre_partner FROM usuarios u LEFT JOIN partners p ON p.id=u.partner_id WHERE u.rol=:r AND u.activo=1{$extra} ORDER BY u.id LIMIT 1");
$st->execute([':r' => $roleName]);
$u = $st->fetch() ?: exit("Sin usuario activo con rol $role\n");
$_SESSION += ['user_id' => $u['id'], 'user_dni' => $u['dni'], 'user_usuario' => $u['usuario'] ?? '', 'user_nombre' => $u['nombre_completo'] ?? '',
    'user_rol' => $u['rol'], 'user_partner_id' => $u['partner_id'], 'user_partner_nombre' => $u['nombre_partner'] ?? ''];
$_SESSION['csrf_token'] = $_SERVER['HTTP_X_CSRF_TOKEN'] = 'cli-test';
require $root . "/02_BACKEND/src/routes/api/$endpoint.php";
