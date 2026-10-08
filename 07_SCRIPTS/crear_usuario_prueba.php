<?php
// 07_SCRIPTS/crear_usuario_prueba.php - Crea (o renueva la clave de) un usuario de PRUEBA para entrar a SGS.
//
// Uso (solo CLI):
//   php 07_SCRIPTS/crear_usuario_prueba.php                 crea / renueva clave (rol Administrador)
//   php 07_SCRIPTS/crear_usuario_prueba.php Supervisor      con otro rol
//   php 07_SCRIPTS/crear_usuario_prueba.php --eliminar      elimina el usuario de prueba
//
// Las credenciales se guardan en 09_CONFIG/usuario_prueba.local.txt (bloqueado por web y en .gitignore).
// Solo actúa sobre el usuario marcado como prueba: nunca modifica un usuario real.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';

const TEST_USER = 'sgs.prueba';
const TEST_DNI = '99999901';
const TEST_EMAIL = 'sgs.prueba@proviwin.test';
const TEST_MARK = 'USUARIO DE PRUEBA SGS - eliminar antes de producción';

$args = array_slice($argv, 1);
$delete = in_array('--eliminar', $args, true);
$role = 'Administrador';
foreach ($args as $arg) if ($arg[0] !== '-') $role = $arg;
$roles = ['Administrador', 'Gerente', 'Coordinador', 'Supervisor', 'Partner'];
if (!in_array($role, $roles, true)) { fwrite(STDERR, "Rol no válido. Use: " . implode(', ', $roles) . "\n"); exit(1); }

$pdo = Database::getConnection();
$credFile = BASE_PATH . '/09_CONFIG/usuario_prueba.local.txt';

$find = $pdo->prepare('SELECT id, observacion FROM usuarios WHERE usuario = :u OR dni = :d OR email = :e');
$find->execute([':u' => TEST_USER, ':d' => TEST_DNI, ':e' => TEST_EMAIL]);
$existing = $find->fetchAll();
foreach ($existing as $row) {
    if (($row['observacion'] ?? '') !== TEST_MARK) {
        fwrite(STDERR, "Ya existe un usuario REAL con ese usuario, DNI o correo (id {$row['id']}). No se modifica nada.\n");
        exit(1);
    }
}
$existingId = $existing ? (int)$existing[0]['id'] : 0;

if ($delete) {
    if (!$existingId) { echo "No existe el usuario de prueba.\n"; exit(0); }
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id AND observacion = :m')->execute([':id' => $existingId, ':m' => TEST_MARK]);
    @unlink($credFile);
    echo "Usuario de prueba eliminado (id $existingId).\n";
    exit(0);
}

$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$password = '';
for ($i = 0; $i < 12; $i++) $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
$password .= '#' . random_int(10, 99);
$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existingId) {
    $pdo->prepare('UPDATE usuarios SET password_hash = :h, rol = :r, activo = 1 WHERE id = :id AND observacion = :m')
        ->execute([':h' => $hash, ':r' => $role, ':id' => $existingId, ':m' => TEST_MARK]);
    $action = "renovado (id $existingId)";
} else {
    $pdo->prepare("INSERT INTO usuarios (dni, nombres, apellidos, usuario, nombre_completo, email, password_hash, rol, zona, observacion, partner_id, activo, fecha_creacion)
                   VALUES (:dni, 'Usuario', 'Prueba SGS', :u, 'Usuario Prueba SGS', :e, :h, :r, 'Provincias', :m, NULL, 1, NOW())")
        ->execute([':dni' => TEST_DNI, ':u' => TEST_USER, ':e' => TEST_EMAIL, ':h' => $hash, ':r' => $role, ':m' => TEST_MARK]);
    $action = 'creado (id ' . $pdo->lastInsertId() . ')';
}
// Limpia solo los bloqueos de intentos fallidos de este usuario desde esta máquina.
foreach (['127.0.0.1', '::1'] as $ip) {
    $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', $ip . '|' . TEST_USER)]);
}

$content = "SGS - Usuario de prueba (" . date('Y-m-d H:i') . ")\n"
    . "URL:        http://localhost/ProviWIN/\n"
    . "Usuario:    " . TEST_USER . "   (también sirve el DNI " . TEST_DNI . ")\n"
    . "Contraseña: $password\n"
    . "Rol:        $role\n\n"
    . "Este usuario también existe en SISTEMA-WIN-PROVINCIA (misma BD).\n"
    . "Eliminar:   php 07_SCRIPTS/crear_usuario_prueba.php --eliminar\n";
file_put_contents($credFile, $content, LOCK_EX);

echo "Usuario de prueba $action con rol $role.\n";
echo "Credenciales guardadas en: 09_CONFIG/usuario_prueba.local.txt\n";
