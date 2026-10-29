<?php
// 05_TEST/integration/tecnicos_sin_zona_test.php - (2026-10-28) Los técnicos sin zona solo los ve el administrador general
// (cuenta principal). Otro administrador y un supervisor directo no los reciben: ni en la lista del formulario de supervisión
// (api/tecnicos.php?action=list), ni en las cuadrillas (action=cuadrillas), ni en la pantalla Técnicos y cuadrillas.
// Por HTTP contra la API real (otro administrador y un supervisor) y la regla común sgsVeTecnicosSinZona (cuenta principal).
// Crea usuarios DNI 99999973-74 y dos técnicos PRUEBA-SINZONA-*; los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/tecnicos_sin_zona_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$dnis = ['99999973', '99999974'];
$frascos = [];
$limpiar = function () use ($pdo, $dnis, &$frascos) {
    $pdo->exec("DELETE FROM tecnicos WHERE nombre_tecnico LIKE 'PRUEBA-SINZONA-%'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
    foreach (['sz.admin', 'sz.sup'] as $u) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Técnicos sin zona — $base\n";
try {
    $principal = Auth::cuentaPrincipalId();
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $clave = 'SinZona#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    foreach ([['99999973', 'sz.admin', 'Administrador', []], ['99999974', 'sz.sup', 'Supervisor', ['AREQUIPA']]] as [$dni, $u, $rol, $zonas]) {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'SinZona', 'apellidos' => "Prueba $u", 'email' => "$u@proviwin.test", 'usuario' => $u, 'rol' => $rol,
            'organizacion' => 'directo', 'zonas' => $zonas, 'password' => $clave], 0, $principal ?: 1)['id'];
        $pdo->exec("UPDATE usuarios SET activo = 1, por_activar = 0, debe_cambiar_password = 0 WHERE id = $id");
    }
    $ins = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, tipo_tecnico, partner_id, departamento, provincia) VALUES (:n, 1, :p, :d, :pr)");
    $ins->execute([':n' => 'PRUEBA-SINZONA-SIN', ':p' => $partner, ':d' => null, ':pr' => null]);
    $ins->execute([':n' => 'PRUEBA-SINZONA-AQP', ':p' => $partner, ':d' => 'AREQUIPA', ':pr' => 'AREQUIPA']);
    // T2 sin zona propia, que apoya al líder de AREQUIPA: toma la zona de su líder (2026-10-29)
    $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, tipo_tecnico, partner_id) VALUES ('PRUEBA-SINZONA-T2', 2, :p)")->execute([':p' => $partner]);
    $pdo->exec("UPDATE tecnicos SET apoyo_id = " . (int)$pdo->lastInsertId() . " WHERE nombre_tecnico = 'PRUEBA-SINZONA-AQP'");

    $sesion = function (string $u) use ($base, $clave, &$frascos) {
        $fr = tempnam(sys_get_temp_dir(), 'sgsz'); $frascos[] = $fr;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch);
        return function (string $url) use ($fr) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_TIMEOUT => 60]); return (string)curl_exec($ch); };
    };
    foreach (['sz.admin' => 'Otro administrador', 'sz.sup' => 'Supervisor directo (AREQUIPA)'] as $u => $nombre) {
        $get = $sesion($u);
        $lista = $get("$base/api/tecnicos.php?action=list");
        $check("$nombre: la lista del formulario no trae técnicos sin zona", !str_contains($lista, 'PRUEBA-SINZONA-SIN') && str_contains($lista, 'PRUEBA-SINZONA-AQP'), substr($lista, 0, 120));
        $busqueda = $get("$base/api/tecnicos.php?action=search&q=PRUEBA-SINZONA");
        $check("$nombre: el T2 sin zona propia sí sale (toma la zona de su líder)", str_contains($lista, 'PRUEBA-SINZONA-T2'));
        $check("$nombre: la búsqueda tampoco", !str_contains($busqueda, 'PRUEBA-SINZONA-SIN') && str_contains($busqueda, 'PRUEBA-SINZONA-AQP'));
        $cuad = $get("$base/api/tecnicos.php?action=cuadrillas&q=PRUEBA-SINZONA");
        $check("$nombre: las cuadrillas tampoco", !str_contains($cuad, 'PRUEBA-SINZONA-SIN'), substr($cuad, 0, 120));
        $pagina = $get("$base/index.php?page=cuadrillas&vista=lideres&q=PRUEBA-SINZONA");
        $check("$nombre: Técnicos y cuadrillas no la muestra ni ofrece el filtro \"Sin zona\"", !str_contains($pagina, 'PRUEBA-SINZONA-SIN') && !str_contains($pagina, 'value="sin_zona"'));
    }
    $filaPrincipal = $principal ? $pdo->query("SELECT id, rol FROM usuarios WHERE id = $principal")->fetch(PDO::FETCH_ASSOC) : null;
    $check('el administrador general sí ve los técnicos sin zona', $filaPrincipal && sgsVeTecnicosSinZona($filaPrincipal));
    $otro = $pdo->query("SELECT id, rol FROM usuarios WHERE dni = '99999973'")->fetch(PDO::FETCH_ASSOC);
    $check('otro administrador no', !sgsVeTecnicosSinZona($otro));
} finally { $limpiar(); }
echo "\n$ok correctas, $fail con error. Usuarios y técnicos de prueba eliminados.\n";
exit($fail ? 1 : 0);
