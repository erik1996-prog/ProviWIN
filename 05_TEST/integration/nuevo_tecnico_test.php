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

        // Corregir el T1 (nomenclatura y partner) y eliminar cuadrillas (2026-11-10)
        $idT1 = (int)$pdo->query("SELECT id FROM tecnicos WHERE nombre_tecnico = 'P 9 PRUEBA NUEVO SGI JUAN LIDER'")->fetchColumn();
        $idLuis = (int)$pdo->query("SELECT id FROM tecnicos WHERE nombre_tecnico = 'LUIS PRUEBA NUEVO AREQUIPA'")->fetchColumn();
        $cuadrilla = fn(array $cambios) => $cambios + ['id' => $idT1, 't1_nombre' => 'P 9 PRUEBA NUEVO SGA JUAN LIDER', 't1_partner_id' => $partner2, 'apoyo_id' => $idLuis,
            'placa' => '', 'vehiculo_tipo' => '', 'vehiculo_estado' => '', 'estado_operativo' => 'Habilitado', 'provincia' => 'AREQUIPA'];
        $r = $admin($cuadrilla([]), 'cuadrilla_guardar');
        $t1 = $pdo->query("SELECT nombre_tecnico, partner_id, apoyo_id FROM tecnicos WHERE id = $idT1")->fetch(PDO::FETCH_ASSOC);
        $check('corrige la nomenclatura y el partner del T1; su T2 pasa al mismo partner', ($r['status'] ?? '') === 'success' && $t1['nombre_tecnico'] === 'P 9 PRUEBA NUEVO SGA JUAN LIDER'
            && (int)$t1['partner_id'] === $partner2 && (int)$t1['apoyo_id'] === $idLuis && (int)$pdo->query("SELECT partner_id FROM tecnicos WHERE id = $idLuis")->fetchColumn() === $partner2, [$r, $t1]);
        $r = $admin($cuadrilla(['t1_nombre' => 'PEDRO PRUEBA NUEVO APOYO']), 'cuadrilla_guardar');
        $check('no acepta una nomenclatura que ya es de otro técnico', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'Ya hay un técnico'), $r);
        $r = $sup(['id' => $idT1], 'eliminar_cuadrilla');
        $check('un supervisor sin el permiso no elimina cuadrillas', ($r['status'] ?? '') === 'error', $r);
        $r = $admin(['id' => $idT1], 'eliminar_cuadrilla');
        $t1 = $pdo->query("SELECT estado_operativo, apoyo_id FROM tecnicos WHERE id = $idT1")->fetch(PDO::FETCH_ASSOC);
        $check('una cuadrilla con supervisiones no se borra: el T1 queda Inhabilitado y su T2 libre',
            ($r['status'] ?? '') === 'success' && $t1 && $t1['estado_operativo'] === 'Inhabilitado' && $t1['apoyo_id'] === null, [$r, $t1]);
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id, departamento, apoyo_id) VALUES ('P 9 PRUEBA NUEVO SGI BORRAR', '99999984', 1, :p, 'AREQUIPA', :a)")
            ->execute([':p' => $partner, ':a' => $idLuis]);
        $idBorrar = (int)$pdo->lastInsertId();
        $r = $admin(['id' => $idBorrar], 'eliminar_cuadrilla');
        $check('una cuadrilla sin historial se elimina y su T2 sigue registrado', ($r['status'] ?? '') === 'success'
            && !$pdo->query("SELECT id FROM tecnicos WHERE id = $idBorrar")->fetchColumn() && $pdo->query("SELECT id FROM tecnicos WHERE id = $idLuis")->fetchColumn(), $r);

        // Un T2 (aunque esté Inhabilitado por tener historial) pasa a T1 con su mismo registro (2026-11-11)
        $nuevoT1 = ['tipo_tecnico' => 1, 'partner_id' => $partner, 'nombre_tecnico' => 'P 9 PRUEBA NUEVO SGI PEDRO ASCENDIDO', 'dni' => '99999978', 'provincia' => 'AREQUIPA'];
        $r = $admin($nuevoT1);
        $check('con el DNI de un T2 pregunta si pasa a técnico líder', ($r['status'] ?? '') === 'confirmar' && str_contains($r['message'] ?? '', 'PEDRO PRUEBA NUEVO APOYO'), $r);
        $r = $admin($nuevoT1 + ['promover' => '1']);
        $t1 = $pdo->query("SELECT id, tipo_tecnico, nombre_tecnico, estado_operativo, departamento FROM tecnicos WHERE dni = '99999978'")->fetch(PDO::FETCH_ASSOC);
        $check('pasa a T1 con el mismo registro: nomenclatura, Habilitado y su zona', ($r['status'] ?? '') === 'success' && (int)$t1['id'] === $idT2
            && (int)$t1['tipo_tecnico'] === 1 && $t1['nombre_tecnico'] === 'P 9 PRUEBA NUEVO SGI PEDRO ASCENDIDO' && $t1['estado_operativo'] === 'Habilitado' && $t1['departamento'] === 'AREQUIPA', [$r, $t1]);
        $check('conserva su historial de supervisiones', (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE tecnico2_id = $idT2 OR tecnico1_id = $idT2")->fetchColumn() === $enSupervision, null);

        // La misma persona como T1 y como T2: mensaje al supervisor, unir y pasar a T2 (2026-11-11)
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id, departamento) VALUES ('P 9 PRUEBA NUEVO SGI MARIO DUPLICADO', NULL, 1, :p, 'AREQUIPA')")->execute([':p' => $partner]);
        $dupT1 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, celular, tipo_tecnico, partner_id, departamento) VALUES ('MARIO PRUEBA NUEVO DUP', '99999985', '911222333', 2, :p, 'AREQUIPA')")->execute([':p' => $partner]);
        $dupT2 = (int)$pdo->lastInsertId();
        $pdo->exec("UPDATE supervisiones SET tecnico2_id = $dupT2 WHERE id = $supervision");
        $r = $sup(['tipo_tecnico' => 2, 'partner_id' => $partner, 'nombre_tecnico' => 'Otro Prueba Nuevo Arequipa', 'dni' => '99999978', 'provincia' => 'AREQUIPA']);
        $check('el supervisor que registra como T2 a un T1 recibe: hable con el administrador', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'administrador'), $r);
        $r = $sup(['t1_id' => $dupT1, 't2_id' => $dupT2, 'queda' => 't1'], 'unir_tecnicos');
        $check('sin "Unir y cambiar T1/T2" no se pueden unir', ($r['status'] ?? '') === 'error', $r);
        $r = $admin(['t1_id' => $dupT1, 't2_id' => $dupT2, 'queda' => 't1'], 'unir_tecnicos');
        $quedo = $pdo->query("SELECT tipo_tecnico, dni, celular FROM tecnicos WHERE id = $dupT1")->fetch(PDO::FETCH_ASSOC);
        $check('unir: queda el T1 con el DNI, el celular y el historial del T2; el T2 se borra', ($r['status'] ?? '') === 'success' && (int)$quedo['tipo_tecnico'] === 1
            && $quedo['dni'] === '99999985' && $quedo['celular'] === '911222333' && !$pdo->query("SELECT id FROM tecnicos WHERE id = $dupT2")->fetchColumn()
            && (int)$pdo->query("SELECT tecnico2_id FROM supervisiones WHERE id = $supervision")->fetchColumn() === $dupT1, [$r, $quedo]);
        $r = $admin(['id' => $dupT1], 'pasar_a_t2');
        $quedo = $pdo->query("SELECT tipo_tecnico, nombre_tecnico, apoyo_id FROM tecnicos WHERE id = $dupT1")->fetch(PDO::FETCH_ASSOC);
        $check('pasar a T2: mismo registro, sin la nomenclatura y sin cuadrilla', ($r['status'] ?? '') === 'success' && (int)$quedo['tipo_tecnico'] === 2
            && !str_starts_with($quedo['nombre_tecnico'], 'P 9') && str_contains($quedo['nombre_tecnico'], 'MARIO') && $quedo['apoyo_id'] === null, [$r, $quedo]);
        // Con DNI distintos: pide elegir y se queda con el elegido
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id, departamento) VALUES ('P 9 PRUEBA NUEVO SGI ANA DOBLE', '99999986', 1, :p, 'AREQUIPA')")->execute([':p' => $partner]);
        $otroT1 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id, departamento) VALUES ('ANA PRUEBA NUEVO DOBLE', '99999987', 2, :p, 'AREQUIPA')")->execute([':p' => $partner]);
        $otroT2 = (int)$pdo->lastInsertId();
        $r = $admin(['t1_id' => $otroT1, 't2_id' => $otroT2, 'queda' => 't1'], 'unir_tecnicos');
        $check('con DNI distintos pide elegir con cuál se queda', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'elija'), $r);
        $r = $admin(['t1_id' => $otroT1, 't2_id' => $otroT2, 'queda' => 't1', 'dni_de' => 't2'], 'unir_tecnicos');
        $check('se queda con el DNI elegido (el del T2)', ($r['status'] ?? '') === 'success'
            && $pdo->query("SELECT dni FROM tecnicos WHERE id = $otroT1")->fetchColumn() === '99999987' && !$pdo->query("SELECT id FROM tecnicos WHERE id = $otroT2")->fetchColumn(), $r);
        $pdo->exec("UPDATE supervisiones SET tecnico2_id = NULL WHERE id = $supervision");
        $pdo->exec("DELETE FROM tecnicos WHERE id IN ($dupT1, $otroT1)");
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec("DELETE FROM supervisiones WHERE id = $supervision");
    }
} finally { $limpiar(); }
echo "\n$ok correctas, $fail con error. Usuarios y técnicos de prueba eliminados.\n";
exit($fail ? 1 : 0);
