<?php
// 05_TEST/integration/cuadrillas_area_test.php - (2026-10-31) Cuadrillas por área: en LIMA, CALLAO y NORTE CHICO cada cuadrilla es
// SGI (instalaciones) o SGA (visitas técnicas) según la nomenclatura de su líder; en provincia atiende todo.
// Revisa las reglas (sgsAreaCuadrilla, sgsCuadrillaAtiende), el filtro de Técnicos y cuadrillas para un líder de Instalaciones
// (sgsCuadrillaAreaSql) y que al guardar una avería no se acepte una cuadrilla SGI de Lima (guardarPersonalSupervision).
// Crea técnicos "PRUEBA AREA …", un usuario DNI 99999964 y supervisiones PRUEBA-AREA-*; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/cuadrillas_area_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/config/supervision_personal.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$limpiar = function () use ($pdo) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-AREA-%'");
    $pdo->exec("DELETE FROM tecnicos WHERE nombre_tecnico LIKE 'P 9 PRUEBA AREA %'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni = '99999964'")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['usuario_departamentos_acceso', 'usuario_permisos', 'notificaciones'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Cuadrillas por área (SGI / SGA)\n";
try {
    // Reglas
    $check('Lima + "SGI" en la nomenclatura → SGI', sgsAreaCuadrilla('LIMA', 'P 1 KAJOMI SGI JUAN PEREZ') === 'SGI');
    $check('Norte Chico + "SGA" → SGA; Callao también se divide', sgsAreaCuadrilla('NORTE CHICO', 'P 2 OLMA SGA ANA RUIZ') === 'SGA' && sgsAreaCuadrilla('CALLAO', 'P 3 X SGI LUIS') === 'SGI');
    $check('provincia (Arequipa) con "SGA" → sin división', sgsAreaCuadrilla('AREQUIPA', 'P 2 OLMA SGA ANA RUIZ') === null);
    $check('sin SGI/SGA, con las dos o con "SGIX" → sin división', sgsAreaCuadrilla('LIMA', 'P 4 JUAN') === null && sgsAreaCuadrilla('LIMA', 'P 4 SGI SGA JUAN') === null && sgsAreaCuadrilla('LIMA', 'P 4 SGIX JUAN') === null);
    $check('una SGI de Lima atiende instalación, no avería', sgsCuadrillaAtiende('LIMA', 'P 1 KAJOMI SGI JUAN', 'Instalación') && !sgsCuadrillaAtiende('LIMA', 'P 1 KAJOMI SGI JUAN', 'Avería'));
    $check('una SGA de Lima atiende postventa, no instalación', sgsCuadrillaAtiende('LIMA', 'P 2 OLMA SGA ANA', 'Postventa') && !sgsCuadrillaAtiende('LIMA', 'P 2 OLMA SGA ANA', 'Instalación'));
    $check('los demás tipos (p. ej. proyecto de ordenamiento) los atiende cualquiera', sgsCuadrillaAtiende('LIMA', 'P 1 KAJOMI SGI JUAN', 'Proyecto de ordenamiento'));
    $check('la garantía la atienden las dos (SGI y SGA)', sgsCuadrillaAtiende('LIMA', 'P 1 KAJOMI SGI JUAN', 'Garantía') && sgsCuadrillaAtiende('LIMA', 'P 2 OLMA SGA ANA', 'Garantía'));
    $check('una SGA de provincia atiende instalación', sgsCuadrillaAtiende('AREQUIPA', 'P 2 OLMA SGA ANA', 'Instalación'));

    // Técnicos y cuadrillas: un líder de Instalaciones ve las SGI de Lima y todas las de provincia
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $tec = function (string $nombre, string $zona) use ($pdo, $partner) {
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, departamento, fecha_registro) VALUES (:n, NULL, 1, 'Habilitado', :p, :d, NOW())")
            ->execute([':n' => $nombre, ':p' => $partner, ':d' => $zona]);
        return (int)$pdo->lastInsertId();
    };
    $ids = ['lima_sgi' => $tec('P 9 PRUEBA AREA SGI UNO', 'LIMA'), 'lima_sga' => $tec('P 9 PRUEBA AREA SGA DOS', 'LIMA'), 'aqp_sga' => $tec('P 9 PRUEBA AREA SGA TRES', 'AREQUIPA')];
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $lider = (int)(new UsuarioService($pdo))->guardar(['dni' => '99999964', 'nombres' => 'Area', 'apellidos' => 'Prueba Lider', 'email' => 'area.lider@proviwin.test', 'usuario' => 'area.lider',
        'rol' => 'Coordinador', 'organizacion' => 'directo', 'partner_id' => $partner, 'zonas' => ['LIMA', 'AREQUIPA'], 'area_trabajo' => 'Instalaciones', 'password' => 'AreaPrueba#' . random_int(1000, 9999)], 0, $admin)['id'];
    $pdo->exec("UPDATE usuarios SET area_trabajo = 'Instalaciones' WHERE id = $lider");
    $ve = $pdo->query("SELECT t.id FROM tecnicos t WHERE t.nombre_tecnico LIKE 'P 9 PRUEBA AREA %' AND " . sgsCuadrillaAreaSql('t.departamento', 't.nombre_tecnico', $lider))->fetchAll(PDO::FETCH_COLUMN);
    $ve = array_map('intval', $ve);
    $check('el líder de Instalaciones ve la SGI de Lima y la de Arequipa, no la SGA de Lima',
        in_array($ids['lima_sgi'], $ve, true) && in_array($ids['aqp_sga'], $ve, true) && !in_array($ids['lima_sga'], $ve, true), $ve);
    $check('un usuario sin área ve todas', sgsCuadrillaAreaSql('t.departamento', 't.nombre_tecnico', $admin) === '1=1');

    // Al guardar: una avería en Lima no acepta una cuadrilla SGI; una instalación sí
    $u = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LIMA' AND provincia='LIMA' LIMIT 1")->fetch();
    $sup = function (string $codigo, string $clas) use ($pdo, $admin, $partner, $u) {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision)
            VALUES (:c, NOW(), :s, 'Autogestión', 'Regular', :d, :p, :di, :t, 'En caliente', :pa, 'Ninguno', :cl, :cl2, '', 'En proceso')")
            ->execute([':c' => "SUP-$codigo", ':s' => $admin, ':d' => $u['departamento'], ':p' => $u['provincia'], ':di' => $u['distrito'], ':t' => "PRUEBA-AREA-$codigo", ':pa' => $partner, ':cl' => $clas, ':cl2' => $clas]);
        return (int)$pdo->lastInsertId();
    };
    $guardar = function (int $supId, string $nombre) use ($pdo, $partner) {
        try { guardarPersonalSupervision($pdo, $supId, $partner, [['nombre' => $nombre, 'dni' => '12345678'], []], [], false); return ''; }
        catch (RuntimeException $e) { return $e->getMessage(); }
    };
    $pdo->exec("UPDATE tecnicos SET dni = '12345678' WHERE id = {$ids['lima_sgi']}");
    $error = $guardar($sup('AVERIA', 'Avería'), 'P 9 PRUEBA AREA SGI UNO');
    $check('avería en Lima con cuadrilla SGI → se rechaza y explica por qué', str_contains($error, 'SGI') && str_contains($error, 'avería'), $error);
    $check('instalación en Lima con cuadrilla SGI → se guarda', $guardar($sup('INSTALACION', 'Instalación'), 'P 9 PRUEBA AREA SGI UNO') === '');
} finally {
    $limpiar();
}
echo "\n$ok correctas, $fail con error. Técnicos, usuario y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
