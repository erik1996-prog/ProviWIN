<?php
// 05_TEST/integration/nuevo_tecnico_test.php - (2026-10-29) "Nuevo técnico" en Técnicos y cuadrillas: un técnico líder (T1) o de
// apoyo (T2) suelto, sin el Excel (api/tecnicos.php?action=crear_tecnico), con las reglas comunes de sgsRegistrarTecnico:
//   - queda con su tipo, partner y zona (la provincia busca su departamento);
//   - no se repite el DNI ni la misma persona con otra nomenclatura;
//   - sin provincia (sin zona) solo el administrador general; un supervisor solo registra en su zona.
// Por HTTP contra la API real. Crea usuarios DNI 99999975-76 y técnicos PRUEBA NUEVO *; los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/nuevo_tecnico_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$dnis = ['99999975', '99999976'];
$frascos = [];
$limpiar = function () use ($pdo, $dnis, &$frascos) {
    $pdo->exec("DELETE FROM tecnicos WHERE nombre_tecnico LIKE '%PRUEBA NUEVO%'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
    foreach (['nt.admin', 'nt.sup'] as $u) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Nuevo técnico — $base\n";
try {
    $principal = Auth::cuentaPrincipalId() ?: 1;
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $clave = 'NuevoTec#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    foreach ([['99999975', 'nt.admin', 'Administrador', []], ['99999976', 'nt.sup', 'Supervisor', ['AREQUIPA']]] as [$dni, $u, $rol, $zonas]) {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'NuevoTec', 'apellidos' => "Prueba $u", 'email' => "$u@proviwin.test", 'usuario' => $u, 'rol' => $rol,
            'organizacion' => 'directo', 'zonas' => $zonas, 'password' => $clave], 0, $principal)['id'];
        $pdo->exec("UPDATE usuarios SET activo = 1, por_activar = 0, debe_cambiar_password = 0 WHERE id = $id");
    }
    $sesion = function (string $u) use ($base, $clave, &$frascos) {
        $fr = tempnam(sys_get_temp_dir(), 'sgsn'); $frascos[] = $fr;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch);
        $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m);
        $csrf = $m[1] ?? '';
        return function (array $datos, string $accion = 'crear_tecnico') use ($base, $fr, $csrf): array {
            $ch = curl_init("$base/api/tecnicos.php?action=$accion");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $datos, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf]]);
            return json_decode((string)curl_exec($ch), true) ?? [];
        };
    };
    $fila = fn(string $nombre) => $pdo->query("SELECT tipo_tecnico, partner_id, departamento, provincia, dni FROM tecnicos WHERE nombre_tecnico = " . $pdo->quote($nombre))->fetch(PDO::FETCH_ASSOC) ?: null;
    $admin = $sesion('nt.admin');
    $r = $admin(['tipo_tecnico' => 1, 'partner_id' => $partner, 'nombre_tecnico' => 'p 9 prueba nuevo sgi juan lider', 'dni' => '99999977', 'provincia' => 'arequipa']);
    $t1 = $fila('P 9 PRUEBA NUEVO SGI JUAN LIDER');
    $check('registra un técnico líder (T1) con su partner y zona', ($r['status'] ?? '') === 'success' && $t1 && (int)$t1['tipo_tecnico'] === 1 && (int)$t1['partner_id'] === $partner && $t1['departamento'] === 'AREQUIPA', [$r, $t1]);
    $r = $admin(['tipo_tecnico' => 2, 'partner_id' => $partner, 'nombre_tecnico' => 'Pedro Prueba Nuevo Apoyo', 'dni' => '99999978', 'celular' => '987654321', 'provincia' => 'SANTA']);
    $t2 = $fila('PEDRO PRUEBA NUEVO APOYO');
    $check('registra un técnico de apoyo (T2) con su zona (SANTA → ÁNCASH)', ($r['status'] ?? '') === 'success' && $t2 && (int)$t2['tipo_tecnico'] === 2 && str_contains((string)$t2['departamento'], 'NCASH'), [$r, $t2]);
    $r = $admin(['tipo_tecnico' => 2, 'partner_id' => $partner, 'nombre_tecnico' => 'Otro Prueba Nuevo', 'dni' => '99999978', 'provincia' => 'SANTA']);
    $check('no acepta un DNI ya registrado', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'DNI ya está registrado'), $r);
    $r = $admin(['tipo_tecnico' => 1, 'partner_id' => $partner, 'nombre_tecnico' => 'P 3 OTRO PRUEBA NUEVO SGA JUAN LIDER', 'dni' => '99999979', 'provincia' => 'AREQUIPA']);
    $check('no acepta a la misma persona con otra nomenclatura', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'ya está registrada'), $r);
    $r = $admin(['tipo_tecnico' => 1, 'partner_id' => $partner, 'nombre_tecnico' => 'P 9 PRUEBA NUEVO SGI SIN ZONA', 'dni' => '99999980', 'provincia' => '']);
    $check('sin provincia (sin zona): solo el administrador general', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'provincia'), $r);
    $r = $admin(['tipo_tecnico' => 1, 'partner_id' => $partner, 'nombre_tecnico' => 'P 9 PRUEBA NUEVO SGI MALA', 'dni' => '99999980', 'provincia' => 'NO EXISTE']);
    $check('una provincia que no existe se rechaza', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'No se reconoce'), $r);
    $r = $admin(['tipo_tecnico' => 3, 'partner_id' => $partner, 'nombre_tecnico' => 'P 9 PRUEBA NUEVO SGI', 'dni' => '99999980', 'provincia' => 'AREQUIPA']);
    $check('el tipo debe ser T1 o T2', ($r['status'] ?? '') === 'error', $r);

    $sup = $sesion('nt.sup');
    $r = $sup(['tipo_tecnico' => 2, 'partner_id' => $partner, 'nombre_tecnico' => 'Luis Prueba Nuevo Piura', 'dni' => '99999980', 'provincia' => 'PIURA']);
    $check('un supervisor no registra técnicos de otra zona', ($r['status'] ?? '') === 'error', $r);
    $r = $sup(['tipo_tecnico' => 2, 'partner_id' => $partner, 'nombre_tecnico' => 'Luis Prueba Nuevo Arequipa', 'dni' => '99999980', 'provincia' => 'AREQUIPA']);
    $check('un supervisor (con "Agregar T2") registra un T2 en su zona', ($r['status'] ?? '') === 'success', $r);
    // 2026-11-04: el T1 solo con "Cargar Excel y agregar T1" (el supervisor no lo tiene)
    $r = $sup(['tipo_tecnico' => 1, 'partner_id' => $partner, 'nombre_tecnico' => 'P 9 PRUEBA NUEVO SGI SUP LIDER', 'dni' => '99999983', 'provincia' => 'AREQUIPA']);
    $check('un supervisor no agrega un técnico líder (T1)', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'T1'), $r);

    // Editar un T2 (2026-11-03, como el T1): datos, zona y partner; si cambia de partner o de zona deja su cuadrilla
    $partner2 = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') AND id <> $partner ORDER BY id LIMIT 1")->fetchColumn();
    $idT2 = (int)$pdo->query("SELECT id FROM tecnicos WHERE nombre_tecnico = 'PEDRO PRUEBA NUEVO APOYO'")->fetchColumn();
    $pdo->exec("UPDATE tecnicos SET apoyo_id = $idT2 WHERE nombre_tecnico = 'P 9 PRUEBA NUEVO SGI JUAN LIDER'");
    $editarT2 = fn(array $cambios) => $cambios + ['id' => $idT2, 'nombre_tecnico' => 'PEDRO PRUEBA NUEVO APOYO', 'dni' => '99999978', 'celular' => '987654321',
        'estado_operativo' => 'Habilitado', 'observacion' => '', 'partner_id' => $partner, 'provincia' => 'SANTA'];
    $r = $sup($editarT2(['partner_id' => $partner2]), 'editar_t2');
    $check('un supervisor no edita un T2 de otra zona', ($r['status'] ?? '') === 'error', $r);
    $r = $admin($editarT2(['partner_id' => $partner2]), 'editar_t2');
    $lider = $pdo->query("SELECT apoyo_id FROM tecnicos WHERE nombre_tecnico = 'P 9 PRUEBA NUEVO SGI JUAN LIDER'")->fetchColumn();
    $check('el T2 cambia de partner y deja su cuadrilla anterior', ($r['status'] ?? '') === 'success' && (int)$fila('PEDRO PRUEBA NUEVO APOYO')['partner_id'] === $partner2 && $lider === null, [$r, $lider]);
    $r = $admin($editarT2(['partner_id' => $partner2, 'provincia' => 'AREQUIPA']), 'editar_t2');
    $check('el T2 cambia de zona (AREQUIPA)', ($r['status'] ?? '') === 'success' && $fila('PEDRO PRUEBA NUEVO APOYO')['departamento'] === 'AREQUIPA', $r);
    $r = $admin($editarT2(['partner_id' => $partner2, 'provincia' => 'NO EXISTE']), 'editar_t2');
    $check('una provincia que no existe se rechaza al editar', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'No se reconoce'), $r);
    $pdo->exec("UPDATE tecnicos SET partner_id = $partner2, departamento = 'ÁNCASH', provincia = 'SANTA' WHERE id = $idT2"); // como venía

    // El T2 sin zona toma la de la supervisión donde se usa, aunque siga en proceso (2026-10-29)
    require_once BACKEND_PATH . '/config/supervision_personal.php';
    $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id) VALUES ('APOYO PRUEBA NUEVO SINZONA', '99999981', 2, :p)")->execute([':p' => $partner]);
    $supId = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999976'")->fetchColumn();
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento = 'AREQUIPA' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito, ticket_codigo,
        tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision)
        VALUES ('SUP-PRUEBA-NUEVO', NOW(), :s, 'Líder', 'Regular', :d, :p, :di, 'PRUEBA NUEVO', 'En caliente', :pa, 'Ninguno', 'Instalación', 'Instalación', '', 'En proceso')")
        ->execute([':s' => $supId, ':d' => $ubi['departamento'], ':p' => $ubi['provincia'], ':di' => $ubi['distrito'], ':pa' => $partner]);
    $supervision = (int)$pdo->lastInsertId();
    try {
        $pdo->beginTransaction();
        guardarPersonalSupervision($pdo, $supervision, $partner, [['nombre' => 'P 9 PRUEBA NUEVO SGI JUAN LIDER', 'dni' => '99999977'], ['nombre' => 'APOYO PRUEBA NUEVO SINZONA', 'dni' => '99999981']], [], false);
        $pdo->commit();
        $zonaT2 = $fila('APOYO PRUEBA NUEVO SINZONA')['departamento'] ?? null;
        $check('el T2 sin zona toma la de la supervisión aunque siga en proceso', $zonaT2 === 'AREQUIPA', $zonaT2);
        // 2026-10-31: un T2 de otro partner y otra zona (buscado entre todos los T2) pasa al cerrar a esta cuadrilla, partner y zona
        $pdo->beginTransaction();
        guardarPersonalSupervision($pdo, $supervision, $partner, [['nombre' => 'P 9 PRUEBA NUEVO SGI JUAN LIDER', 'dni' => '99999977'], ['nombre' => 'PEDRO PRUEBA NUEVO APOYO', 'dni' => '99999978']], [], true);
        $pdo->commit();
        $t2 = $fila('PEDRO PRUEBA NUEVO APOYO');
        $apoyoDelLider = (int)$pdo->query("SELECT apoyo_id FROM tecnicos WHERE nombre_tecnico = 'P 9 PRUEBA NUEVO SGI JUAN LIDER'")->fetchColumn();
        $check('un T2 de otro partner y otra zona pasa al cerrar al partner, la zona y la cuadrilla de la supervisión',
            (int)$t2['partner_id'] === $partner && $t2['departamento'] === 'AREQUIPA' && $apoyoDelLider === $idT2, [$t2, $apoyoDelLider]);

        // Editar y eliminar un T2 en Técnicos y cuadrillas (2026-11-03)
        $r = $admin($editarT2(['celular' => '912345678', 'observacion' => 'Prueba', 'provincia' => 'AREQUIPA']), 'editar_t2');
        $sigue = (int)$pdo->query("SELECT apoyo_id FROM tecnicos WHERE nombre_tecnico = 'P 9 PRUEBA NUEVO SGI JUAN LIDER'")->fetchColumn() === $idT2;
        $check('edita un T2 (celular y observación) sin cambiar partner ni zona: sigue en su cuadrilla',
            ($r['status'] ?? '') === 'success' && $pdo->query("SELECT celular FROM tecnicos WHERE id = $idT2")->fetchColumn() === '912345678' && $sigue, [$r, $sigue]);
        $r = $sup(['id' => $idT2], 'eliminar_t2');
        $check('un supervisor sin el permiso no elimina un T2', ($r['status'] ?? '') === 'error', $r);
        $enSupervision = (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE tecnico2_id = $idT2 OR tecnico1_id = $idT2")->fetchColumn();
        $r = $admin(['id' => $idT2], 'eliminar_t2');
        $quedo = $pdo->query("SELECT estado_operativo FROM tecnicos WHERE id = $idT2")->fetchColumn();
        $apoyoDelLider = $pdo->query("SELECT apoyo_id FROM tecnicos WHERE nombre_tecnico = 'P 9 PRUEBA NUEVO SGI JUAN LIDER'")->fetchColumn();
        $check('un T2 con supervisiones no se borra: queda Inhabilitado y fuera de su cuadrilla',
            $enSupervision > 0 && ($r['status'] ?? '') === 'success' && $quedo === 'Inhabilitado' && $apoyoDelLider === null, [$enSupervision, $r, $quedo, $apoyoDelLider]);
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id, departamento) VALUES ('BORRAR PRUEBA NUEVO', '99999982', 2, :p, 'AREQUIPA')")->execute([':p' => $partner]);
        $idBorrar = (int)$pdo->lastInsertId();
        $r = $admin(['id' => $idBorrar], 'eliminar_t2');
        $check('un T2 sin supervisiones se elimina', ($r['status'] ?? '') === 'success' && !$pdo->query("SELECT id FROM tecnicos WHERE id = $idBorrar")->fetchColumn(), $r);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec("DELETE FROM supervisiones WHERE id = $supervision");
    }
} finally { $limpiar(); }
echo "\n$ok correctas, $fail con error. Usuarios y técnicos de prueba eliminados.\n";
exit($fail ? 1 : 0);
