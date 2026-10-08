<?php
// 02_BACKEND/src/routes/api/usuarios.php - Ruta del módulo Usuarios y roles (primer módulo en capas, fase 2).
// Solo autenticación, CSRF y despacho: la lógica vive en controllers/ services/ repositories/ validators/.
ob_start();
header('Content-Type: application/json; charset=utf-8');
define('WIN_JSON_RESPONSE', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once BACKEND_PATH . '/controllers/UsuarioController.php';

Auth::requireLogin();
Auth::requireCsrf();

$controller = new UsuarioController(new UsuarioService(Database::getConnection()), Auth::getLoggedInUser());
$response = $controller->handle((string)($_GET['action'] ?? ''));
ob_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
