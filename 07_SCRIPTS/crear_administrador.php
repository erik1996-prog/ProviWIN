<?php
// 07_SCRIPTS/crear_administrador.php - Crea el PRIMER administrador en una base nueva (solo CLI).
// Solo funciona si no existe ningún administrador activo; después, los usuarios se crean desde Usuarios y roles.
// La contraseña temporal se guarda en 09_CONFIG/<usuario>.local.txt (bloqueado por web y en .gitignore).
// Uso: php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> "<Nombres>" "<Apellidos>"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc < 6) { fwrite(STDERR, "Uso: php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> \"<Nombres>\" \"<Apellidos>\"\n"); exit(1); }

$_SESSION = [];
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

if ((int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'Administrador' AND activo = 1")->fetchColumn() > 0) {
    exit("Ya existe un administrador activo. Cree los usuarios desde Usuarios y roles, o use restablecer_contrasena.php.\n");
}
if (!(int)$pdo->query("SELECT COUNT(*) FROM partners WHERE nombre_partner = 'WIN DIRECTO'")->fetchColumn()) {
    exit("Falta el partner WIN DIRECTO: ejecute antes los scripts de 04_DATABASE/00_INSTALACION.\n");
}

[, $dni, $usuario, $email, $nombres, $apellidos] = $argv;
$temporal = 'Sgs-' . bin2hex(random_bytes(5));
try {
    $r = (new UsuarioService($pdo))->guardar(['dni' => $dni, 'usuario' => $usuario, 'email' => $email, 'nombres' => $nombres, 'apellidos' => $apellidos,
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $temporal], 0, 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo crear: ' . $e->getMessage() . "\n"); exit(1);
}
$file = BASE_PATH . '/09_CONFIG/' . preg_replace('/[^a-z0-9._-]/i', '_', $usuario) . '.local.txt';
file_put_contents($file, "SGS - Primer administrador (" . date('Y-m-d H:i') . ")\nUsuario:    $usuario\nContraseña: $temporal   (temporal)\n\n"
    . "Al ingresar, cámbiela en Mi información → Cambiar contraseña y borre este archivo.\n", LOCK_EX);
win_log_error('audit_user_created', 'Primer administrador creado desde 07_SCRIPTS (CLI)', ['target_id' => (int)$r['id']]);
echo "Administrador $usuario creado. Credenciales en: 09_CONFIG/" . basename($file) . "\n";
