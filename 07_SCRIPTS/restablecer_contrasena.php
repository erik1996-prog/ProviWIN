<?php
// 07_SCRIPTS/restablecer_contrasena.php - Recuperación de acceso desde el servidor (solo CLI).
// Genera una contraseña temporal para el usuario indicado y la guarda en 09_CONFIG/<usuario>.local.txt
// (carpeta bloqueada por web y excluida en .gitignore). Úsela cuando nadie pueda entrar al sistema,
// por ejemplo si el único administrador olvidó su contraseña.
// Uso: php 07_SCRIPTS/restablecer_contrasena.php <usuario|DNI>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (empty($argv[1])) { fwrite(STDERR, "Uso: php 07_SCRIPTS/restablecer_contrasena.php <usuario|DNI>\n"); exit(1); }

$_SESSION = [];
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';

$pdo = Database::getConnection();
$st = $pdo->prepare('SELECT id, usuario, nombre_completo, rol, activo FROM usuarios WHERE usuario = :u OR dni = :d LIMIT 1');
$st->execute([':u' => $argv[1], ':d' => $argv[1]]);
$u = $st->fetch() ?: exit("No existe el usuario {$argv[1]}.\n");
if (!$u['activo']) exit("La cuenta {$u['usuario']} está desactivada; actívela antes de restablecer la contraseña.\n");

$temporal = (new UsuarioService($pdo))->restablecerPassword((int)$u['id']);
// Limpia bloqueos por intentos fallidos de este usuario.
$pdo->prepare('DELETE FROM login_attempts WHERE fingerprint IN (:a, :b)')->execute([
    ':a' => hash('sha256', '127.0.0.1|' . mb_strtolower($u['usuario'])), ':b' => hash('sha256', '::1|' . mb_strtolower($u['usuario'])),
]);
$file = BASE_PATH . '/09_CONFIG/' . preg_replace('/[^a-z0-9._-]/i', '_', $u['usuario']) . '.local.txt';
file_put_contents($file, "SGS - Acceso de {$u['nombre_completo']} (" . date('Y-m-d H:i') . ")\n"
    . "URL:        http://localhost/ProviWIN/\nUsuario:    {$u['usuario']}\nContraseña: $temporal   (temporal)\nRol:        {$u['rol']}\n\n"
    . "Al ingresar, cámbiela en Mi información → Cambiar contraseña y borre este archivo.\n", LOCK_EX);
win_log_error('audit_password_reset', 'Contraseña restablecida desde 07_SCRIPTS (CLI)', ['target_id' => (int)$u['id']]);
echo "Contraseña temporal generada para {$u['usuario']} ({$u['rol']}).\nCredenciales en: 09_CONFIG/" . basename($file) . "\n";
