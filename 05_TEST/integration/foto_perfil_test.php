<?php
// 05_TEST/integration/foto_perfil_test.php - Prueba HTTP de la foto de perfil propia (subir, reemplazar, rechazar, quitar). Solo CLI.
// Uso: php 05_TEST/integration/foto_perfil_test.php [url_base=http://localhost/ProviWIN]
// Crea un supervisor temporal (DNI 99999905) con una sesión real; al terminar borra el usuario, la sesión y sus fotos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';

$pdo = Database::getConnection();
$avatarDir = SHARED_FILES_PATH . '/uploads/avatars/';
$cleanup = function () use ($pdo, $avatarDir) {
    $id = $pdo->query("SELECT id FROM usuarios WHERE dni = '99999905'")->fetchColumn();
    if ($id) foreach (glob($avatarDir . "avatar_user_{$id}_*") ?: [] as $f) @unlink($f);
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999905'");
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$r = (new UsuarioService($pdo))->guardar(['dni' => '99999905', 'nombres' => 'Foto', 'apellidos' => 'Prueba SGS', 'email' => 'foto.prueba@proviwin.test',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => 'FotoPrueba123'], 0, $admin);
$uid = (int)$r['id'];

$sid = 'sgsfoto' . bin2hex(random_bytes(12));
$sessFile = (ini_get('session.save_path') ?: sys_get_temp_dir()) . "/sess_$sid";
$data = ['user_id' => $uid, 'user_dni' => '99999905', 'user_usuario' => $r['usuario'], 'user_nombre' => 'Foto Prueba SGS',
    'user_rol' => 'Supervisor', 'user_partner_id' => null, 'user_partner_nombre' => '', 'csrf_token' => 'foto-test'];
$ser = ''; foreach ($data as $k => $v) $ser .= $k . '|' . serialize($v);
file_put_contents($sessFile, $ser);

// PNG 64×64 válido sin GD (zlib + CRC)
$png = function (int $rgb): string {
    $raw = str_repeat("\0" . str_repeat(pack('C3', $rgb >> 16, ($rgb >> 8) & 255, $rgb & 255), 64), 64);
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', 64, 64, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
};
$post = function (string $action, ?string $bytes = null, string $mime = 'image/png') use ($base, $sid) {
    $ch = curl_init("$base/api/usuarios.php?action=$action");
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIE => "SGS_SESSID=$sid", CURLOPT_HTTPHEADER => ['X-CSRF-Token: foto-test']];
    if ($bytes !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'sgsf'); file_put_contents($tmp, $bytes);
        $opts[CURLOPT_POSTFIELDS] = ['foto' => new CURLFile($tmp, $mime, 'foto.png')];
    } else $opts[CURLOPT_POSTFIELDS] = '';
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (isset($tmp)) @unlink($tmp);
    return [$code, json_decode($body, true) ?? ['raw' => mb_substr($body, 0, 200)]];
};
$avatar = fn() => $pdo->query("SELECT avatar FROM usuarios WHERE id = $uid")->fetchColumn();

$ok = 0; $fail = 0;
$check = function (string $name, callable $fn) use (&$ok, &$fail) {
    try { $res = $fn(); if ($res !== true) throw new RuntimeException(is_string($res) ? $res : 'resultado falso'); echo "  ✓ $name\n"; $ok++; }
    catch (Throwable $e) { echo "  ✗ $name — " . $e->getMessage() . "\n"; $fail++; }
};

echo "Foto de perfil — prueba HTTP contra $base\n";
$primera = null;
$check('sube una foto PNG válida', function () use ($post, $png, $avatar, $avatarDir, &$primera) {
    [$code, $j] = $post('mi_foto', $png(0xFF5A0A));
    if ($code !== 200 || ($j['status'] ?? '') !== 'success') return "HTTP $code " . json_encode($j, JSON_UNESCAPED_UNICODE);
    $primera = $avatar();
    return $primera === $j['avatar_url'] && is_file($avatarDir . basename($primera));
});
$check('la foto se sirve por la web (uploads/avatars)', function () use ($base, &$primera) {
    $ch = curl_init("$base/$primera"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 200 ?: "HTTP $code";
});
$check('reemplazar borra la foto anterior', function () use ($post, $png, $avatar, $avatarDir, &$primera) {
    [$code, $j] = $post('mi_foto', $png(0x141414));
    return $code === 200 && $avatar() !== $primera && !is_file($avatarDir . basename($primera)) && is_file($avatarDir . basename($avatar()));
});
$check('rechaza un archivo que no es imagen', function () use ($post) {
    [$code, $j] = $post('mi_foto', "<?php echo 'x';", 'image/png');
    return $code === 422 ?: "HTTP $code";
});
$check('rechaza sin token CSRF', function () use ($base, $sid) {
    $ch = curl_init("$base/api/usuarios.php?action=quitar_mi_foto");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_COOKIE => "SGS_SESSID=$sid"]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 403 ?: "HTTP $code";
});
$check('quitar foto deja avatar vacío y borra el archivo', function () use ($post, $avatar, $avatarDir) {
    $actual = $avatar();
    [$code] = $post('quitar_mi_foto');
    return $code === 200 && $avatar() === null && !is_file($avatarDir . basename($actual));
});

@unlink($sessFile);
$cleanup();
echo "\n$ok correctas, $fail con error. Usuario, sesión y fotos de prueba eliminados.\n";
exit($fail ? 1 : 0);
