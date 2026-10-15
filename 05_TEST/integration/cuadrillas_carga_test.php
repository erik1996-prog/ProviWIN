<?php
// 05_TEST/integration/cuadrillas_carga_test.php - (2026-10-15) Cuadrillas:
//   - la ficha del líder (T2, vehículo y estado) cambia solo al CERRAR la supervisión; T2 y vehículo pasan de una cuadrilla a otra;
//   - carga de técnicos líderes por Excel: nuevos, cambio de nomenclatura/partner, inhabilitados, filas con problemas;
//   - edición desde la pantalla Cuadrillas y descarga de la lista (por HTTP).
// La parte de servicio corre dentro de una transacción que se deshace. Lo demás crea datos de prueba
// (DNI 99999918 y 99999936, placa ZZ-9991) y los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/cuadrillas_carga_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/supervision_personal.php';
require BACKEND_PATH . '/config/tecnicos_lideres.php';
$pdo = Database::getConnection();

$clave = 'CuadrillaPrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$xlsx = tempnam(sys_get_temp_dir(), 'sgsl') . '.xlsx';
$cleanup = function () use ($pdo, $jar, $xlsx) {
    $pdo->exec("UPDATE tecnicos SET apoyo_id = NULL WHERE apoyo_id IN (SELECT id FROM (SELECT id FROM tecnicos WHERE dni = '99999936') x)");
    $pdo->exec("DELETE FROM tecnicos WHERE dni = '99999936'");
    $pdo->exec("UPDATE tecnicos SET vehiculo_id = NULL WHERE vehiculo_id IN (SELECT id FROM (SELECT id FROM vehiculos WHERE placa = 'ZZ-9991') x)");
    $pdo->exec("DELETE FROM vehiculos WHERE placa = 'ZZ-9991'");
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999918'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|cuadrilla.prueba")]);
    @unlink($jar); @unlink($xlsx);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    [$p1, $p2] = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $tec = function (string $nombre, string $dni, int $tipo, int $partner) use ($pdo): int {
        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, fecha_registro) VALUES (:n, :d, :t, 'Habilitado', :p, NOW())")
            ->execute([':n' => $nombre, ':d' => $dni, ':t' => $tipo, ':p' => $partner]);
        return (int)$pdo->lastInsertId();
    };
    $fila = fn(int $id) => $pdo->query("SELECT t.nombre_tecnico, t.partner_id, t.apoyo_id, t.vehiculo_id, t.estado_operativo, t.provincia, t.departamento FROM tecnicos t WHERE t.id = $id")->fetch(PDO::FETCH_ASSOC);

    echo "Cuadrilla al cerrar la supervisión (servicio, sin dejar cambios)\n";
    $pdo->beginTransaction();
    try {
        $a = $tec('P 1 PRUEBACUAD SGI LIDER UNO PRUEBA', '99999933', 1, $p1['id']);
        $b = $tec('P 2 PRUEBACUAD SGI LIDER DOS PRUEBA', '99999934', 1, $p1['id']);
        $x = $tec('APOYO ROTATIVO PRUEBA', '99999935', 2, $p2['id']);
        $c = $tec('P 3 TRASLADO PRUEBACUAD ROSA MARIA QUISPE PRUEBA', '99999937', 1, $p1['id']); // nomenclatura sin SGI/SGA
        $personal = fn(int $lider, string $nombre, string $dni) => [['id' => $lider, 'nombre' => $nombre, 'dni' => $dni], ['id' => $x, 'nombre' => 'APOYO ROTATIVO PRUEBA', 'dni' => '99999935']];
        $veh = fn(string $estado) => ['placa' => 'ZZ-9990', 'tipo' => 'Moto', 'estado' => $estado, 'soat' => null, 'revision' => null];

        guardarPersonalSupervision($pdo, 0, (int)$p1['id'], $personal($a, 'P 1 PRUEBACUAD SGI LIDER UNO PRUEBA', '99999933'), $veh('Mal estado'), false);
        $check('en proceso (sin cerrar): el T2 de otro partner se acepta y la ficha del líder no cambia', $fila($a)['apoyo_id'] === null && $fila($a)['vehiculo_id'] === null, $fila($a));
        guardarPersonalSupervision($pdo, 0, (int)$p1['id'], $personal($a, 'P 1 PRUEBACUAD SGI LIDER UNO PRUEBA', '99999933'), $veh('Mal estado'), true);
        $vehId = (int)$pdo->query("SELECT id FROM vehiculos WHERE placa = 'ZZ-9990'")->fetchColumn();
        $check('al cerrar: T2 y vehículo quedan en la cuadrilla del líder', (int)$fila($a)['apoyo_id'] === $x && (int)$fila($a)['vehiculo_id'] === $vehId, $fila($a));
        $check('al cerrar: el T2 pasa al partner de la supervisión', (int)$fila($x)['partner_id'] === (int)$p1['id'], $fila($x));
        guardarPersonalSupervision($pdo, 0, (int)$p1['id'], $personal($b, 'P 2 PRUEBACUAD SGI LIDER DOS PRUEBA', '99999934'), $veh('Buen estado'), true);
        $check('el T2 y el vehículo se van a la otra cuadrilla', (int)$fila($b)['apoyo_id'] === $x && (int)$fila($b)['vehiculo_id'] === $vehId, $fila($b));
        $check('y salen de la cuadrilla anterior', $fila($a)['apoyo_id'] === null && $fila($a)['vehiculo_id'] === null, $fila($a));
        $check('vehículo reparado: queda en "Buen estado"', $pdo->query("SELECT estado_conservacion FROM vehiculos WHERE id = $vehId")->fetchColumn() === 'Buen estado');

        echo "Carga de técnicos líderes por Excel (servicio, sin dejar cambios)\n";
        $vigentes = $pdo->query("SELECT t.id, t.nombre_tecnico, p.nombre_partner FROM tecnicos t LEFT JOIN partners p ON p.id = t.partner_id
            WHERE COALESCE(t.tipo_tecnico, 1) = 1 AND t.estado_operativo = 'Habilitado' AND t.id NOT IN ($a, $b, $c)")->fetchAll(PDO::FETCH_ASSOC);
        $filas = []; $n = 1;
        foreach ($vigentes as $v) $filas[] = ['fila' => ++$n, 'nombre' => sgsNombreLider($v['nombre_tecnico']), 'partner' => (string)$v['nombre_partner']];
        $filas[] = ['fila' => ++$n, 'nombre' => sgsNombreLider('p 9  pruebacuad sga lider uno prueba'), 'partner' => $p2['nombre_partner']];   // A: otra nomenclatura y partner
        $filas[] = ['fila' => ++$n, 'nombre' => 'P 5 PRUEBACUAD SGI NUEVO LIDER PRUEBA', 'partner' => mb_strtolower($p1['nombre_partner'])];  // nuevo
        $filas[] = ['fila' => ++$n, 'nombre' => 'P 4 PRUEBACUAD SGI ROSA MARIA QUISPE PRUEBA', 'partner' => $p1['nombre_partner']];         // C con nomenclatura nueva
        $filas[] = ['fila' => ++$n, 'nombre' => 'P 6 PRUEBACUAD SGI SIN PARTNER PRUEBA', 'partner' => 'PARTNER QUE NO EXISTE'];             // con problema
        $filas[] = ['fila' => ++$n, 'nombre' => 'P 5 PRUEBACUAD SGI NUEVO LIDER PRUEBA', 'partner' => $p1['nombre_partner']];               // repetido
        // B no viene en el archivo → se inhabilita

        $r = sgsImportarLideres($pdo, $filas, false, 0);
        $check('vista previa: reconoce el cambio de nomenclatura y de partner', count($r['actualizados']) === 2 && $r['actualizados'][0]['antes'] === 'P 1 PRUEBACUAD SGI LIDER UNO PRUEBA', $r['actualizados']);
        $check('vista previa: también reconoce una nomenclatura sin SGI/SGA', ($r['actualizados'][1]['antes'] ?? '') === 'P 3 TRASLADO PRUEBACUAD ROSA MARIA QUISPE PRUEBA', $r['actualizados']);
        $check('vista previa: 1 nuevo', count($r['nuevos']) === 1 && $r['nuevos'][0]['nombre'] === 'P 5 PRUEBACUAD SGI NUEVO LIDER PRUEBA', $r['nuevos']);
        $check('vista previa: se inhabilita solo el que no viene', count($r['desactivar']) === 1 && $r['desactivar'][0]['nombre'] === 'P 2 PRUEBACUAD SGI LIDER DOS PRUEBA', $r['desactivar']);
        $check('vista previa: partner desconocido y repetido quedan como filas con problemas', count($r['observadas']) === 2, $r['observadas']);
        $check('vista previa: no cambia nada todavía', $fila($a)['nombre_tecnico'] === 'P 1 PRUEBACUAD SGI LIDER UNO PRUEBA' && $fila($b)['estado_operativo'] === 'Habilitado');

        sgsImportarLideres($pdo, $filas, true, 0);
        $check('aplicar: renombra al líder y lo pasa al nuevo partner (conserva su ficha)', $fila($a)['nombre_tecnico'] === 'P 9 PRUEBACUAD SGA LIDER UNO PRUEBA' && (int)$fila($a)['partner_id'] === (int)$p2['id'], $fila($a));
        $check('aplicar: agrega el nuevo como habilitado', $pdo->query("SELECT estado_operativo FROM tecnicos WHERE nombre_tecnico = 'P 5 PRUEBACUAD SGI NUEVO LIDER PRUEBA' AND tipo_tecnico = 1")->fetchColumn() === 'Habilitado');
        $check('aplicar: inhabilita al ausente (no lo borra; conserva su cuadrilla)', $fila($b)['estado_operativo'] === 'Inhabilitado' && (int)$fila($b)['apoyo_id'] === $x, $fila($b));
        $r = sgsImportarLideres($pdo, array_merge($filas, [['fila' => 99, 'nombre' => 'P 2 PRUEBACUAD SGI LIDER DOS PRUEBA', 'partner' => $p1['nombre_partner']]]), true, 0);
        $check('si vuelve en un archivo posterior, se reactiva', $fila($b)['estado_operativo'] === 'Habilitado' && count($r['reactivados']) === 1, $r['reactivados']);

        echo "Zona de los técnicos: la provincia del Excel → departamento (2026-10-15)\n";
        $conZona = function (array $provincias, array $extra = []) use ($filas, $p1): array {
            $salida = array_merge($filas, [['fila' => 99, 'nombre' => 'P 2 PRUEBACUAD SGI LIDER DOS PRUEBA', 'partner' => $p1['nombre_partner']]], $extra);
            foreach ($salida as &$z) $z['provincia'] = $provincias[$z['nombre']] ?? ($z['provincia'] ?? '');
            return $salida;
        };
        $nuevoZona = ['fila' => 100, 'nombre' => 'P 8 PRUEBACUAD SGI ZONA NUEVA PRUEBA', 'partner' => $p1['nombre_partner'], 'provincia' => 'Sullana'];
        $prov = ['P 9 PRUEBACUAD SGA LIDER UNO PRUEBA' => 'Trujillo', 'P 2 PRUEBACUAD SGI LIDER DOS PRUEBA' => 'chimbote', 'P 4 PRUEBACUAD SGI ROSA MARIA QUISPE PRUEBA' => 'Narnia'];
        $r = sgsImportarLideres($pdo, $conZona($prov, [$nuevoZona]), false, 0);
        $cambio = array_column($r['cambios_zona'], 'zona', 'nombre');
        $check('vista previa: detecta el departamento de cada provincia (Trujillo → LA LIBERTAD, Chimbote → ÁNCASH)',
            ($cambio['P 9 PRUEBACUAD SGA LIDER UNO PRUEBA'] ?? '') === 'TRUJILLO · LA LIBERTAD' && ($cambio['P 2 PRUEBACUAD SGI LIDER DOS PRUEBA'] ?? '') === 'SANTA · ÁNCASH', $r['cambios_zona']);
        $check('vista previa: una provincia que no existe se avisa y no se aplica', count($r['zona_no_reconocida']) === 1 && $r['zona_no_reconocida'][0]['provincia'] === 'Narnia', $r['zona_no_reconocida']);
        $check('vista previa: el técnico nuevo trae su zona', ($r['nuevos'][0]['zona'] ?? '') === 'SULLANA · PIURA', $r['nuevos']);
        sgsImportarLideres($pdo, $conZona($prov, [$nuevoZona]), true, 0);
        $check('aplicar: guarda provincia y departamento', $fila($a)['provincia'] === 'TRUJILLO' && $fila($a)['departamento'] === 'LA LIBERTAD' && $fila($b)['departamento'] === 'ÁNCASH', [$fila($a), $fila($b)]);
        $check('aplicar: la provincia no reconocida deja la zona como estaba (vacía)', $fila($c)['departamento'] === null, $fila($c));
        $check('aplicar: el nuevo queda en PIURA', $pdo->query("SELECT departamento FROM tecnicos WHERE nombre_tecnico = 'P 8 PRUEBACUAD SGI ZONA NUEVA PRUEBA'")->fetchColumn() === 'PIURA');
        $r = sgsImportarLideres($pdo, $conZona(['P 9 PRUEBACUAD SGA LIDER UNO PRUEBA' => 'Chiclayo', 'P 2 PRUEBACUAD SGI LIDER DOS PRUEBA' => 'chimbote'], [$nuevoZona]), true, 0);
        $check('si el técnico se mueve, la nueva carga cambia su zona', $fila($a)['departamento'] === 'LAMBAYEQUE' && count($r['cambios_zona']) === 1, [$fila($a), $r['cambios_zona']]);
        $r = sgsImportarLideres($pdo, $conZona([], [$nuevoZona]), false, 0);
        $check('sin provincia en el archivo, la zona no cambia', count($r['cambios_zona']) === 0 && $fila($a)['departamento'] === 'LAMBAYEQUE', $r['cambios_zona']);
    } finally {
        $pdo->rollBack();
    }
    $check('la prueba de servicio no deja cambios', (int)$pdo->query("SELECT COUNT(*) FROM tecnicos WHERE nombre_tecnico LIKE '%PRUEBACUAD%' OR dni IN ('99999933','99999934','99999935','99999937')")->fetchColumn() === 0);

    echo "Pantalla Cuadrillas (HTTP) — $base\n";
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    (new UsuarioService($pdo))->guardar(['dni' => '99999918', 'nombres' => 'Cuadrilla', 'apellidos' => 'Prueba SGS', 'email' => 'cuadrilla.prueba@proviwin.test', 'usuario' => 'cuadrilla.prueba',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $http = function (string $url, array $opts = []) use ($jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90] + $opts);
        $body = (string)curl_exec($ch); $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
        return [$body, json_decode($body, true), $tipo];
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'cuadrilla.prueba', 'password' => $clave])]);
    [$pagina] = $http("$base/index.php?page=cuadrillas");
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    $csrf = ['X-CSRF-Token: ' . ($m[1] ?? '')];
    $check('la pantalla muestra la carga por Excel y la edición', str_contains($pagina, 'Cargar técnicos líderes') && str_contains($pagina, 'data-editar='));

    [$bin, , $tipo] = $http("$base/api/tecnicos.php?action=exportar_lideres");
    file_put_contents($xlsx, $bin);
    $leidas = sgsLeerExcelLideres($xlsx);
    $habilitados = (int)$pdo->query("SELECT COUNT(*) FROM tecnicos WHERE COALESCE(tipo_tecnico,1) = 1 AND estado_operativo = 'Habilitado'")->fetchColumn();
    $check('descargar lista: Excel con todos los líderes habilitados', str_contains($tipo, 'spreadsheetml') && count($leidas) === $habilitados, [$tipo, count($leidas), $habilitados]);
    [, $j] = $http("$base/api/tecnicos.php?action=importar_lideres", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => $csrf,
        CURLOPT_POSTFIELDS => ['modo' => 'previsualizar', 'archivo' => new CURLFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'lideres.xlsx')]]);
    $d = $j['data'] ?? [];
    $check('subir la misma lista: nada que agregar ni inhabilitar', ($j['status'] ?? '') === 'success' && !($j['aplicado'] ?? true)
        && count($d['nuevos'] ?? [1]) === 0 && count($d['desactivar'] ?? [1]) === 0 && count($d['actualizados'] ?? [1]) === 0 && count($d['cambios_zona'] ?? [1]) === 0, $j['message'] ?? $d);

    $lider = $tec('P 7 PRUEBACUAD SGI LIDER HTTP PRUEBA', '99999936', 1, (int)$p1['id']);
    $guardar = fn(array $campos) => $http("$base/api/tecnicos.php?action=cuadrilla_guardar", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => $csrf, CURLOPT_POSTFIELDS => $campos])[1];
    $j = $guardar(['id' => $lider, 'apoyo_id' => 0, 'placa' => 'zz-9991', 'vehiculo_tipo' => 'Auto', 'vehiculo_estado' => 'Regular', 'estado_operativo' => 'Habilitado']);
    $v = $pdo->query("SELECT v.placa, v.tipo, v.estado_conservacion FROM tecnicos t JOIN vehiculos v ON v.id = t.vehiculo_id WHERE t.id = $lider")->fetch(PDO::FETCH_ASSOC);
    $check('editar cuadrilla: guarda el vehículo con su tipo y estado', ($j['status'] ?? '') === 'success' && $v && $v['placa'] === 'ZZ-9991' && $v['tipo'] === 'Auto' && $v['estado_conservacion'] === 'Regular', [$j, $v]);
    $j = $guardar(['id' => $lider, 'apoyo_id' => 0, 'placa' => '', 'estado_operativo' => 'Inhabilitado']);
    $check('editar cuadrilla: sin placa queda sin vehículo y cambia el estado del líder', ($j['status'] ?? '') === 'success' && $fila($lider)['vehiculo_id'] === null && $fila($lider)['estado_operativo'] === 'Inhabilitado', $fila($lider));
    $j = $guardar(['id' => $lider, 'apoyo_id' => 0, 'placa' => 'NO VALIDA !!', 'estado_operativo' => 'Habilitado']);
    $check('editar cuadrilla: rechaza una placa inválida', ($j['status'] ?? '') === 'error', $j);
    $j = $guardar(['id' => $lider, 'apoyo_id' => 0, 'placa' => '', 'estado_operativo' => 'Habilitado', 'provincia' => 'huancayo']);
    $check('editar cuadrilla: la provincia escrita a mano pone su departamento (HUANCAYO → JUNÍN)', ($j['status'] ?? '') === 'success' && $fila($lider)['departamento'] === 'JUNÍN' && $fila($lider)['provincia'] === 'HUANCAYO', [$j, $fila($lider)]);
    $j = $guardar(['id' => $lider, 'apoyo_id' => 0, 'placa' => '', 'estado_operativo' => 'Habilitado', 'provincia' => 'Narnia']);
    $check('editar cuadrilla: rechaza una provincia que no existe', ($j['status'] ?? '') === 'error' && $fila($lider)['departamento'] === 'JUNÍN', $j);
    [, $j] = $http("$base/api/tecnicos.php?action=list");
    $enLista = array_values(array_filter($j['data'] ?? [], fn($t) => (int)$t['id'] === $lider))[0] ?? [];
    $check('la lista del formulario trae la zona de cada técnico', ($enLista['departamento'] ?? '') === 'JUNÍN', $enLista);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Datos de prueba eliminados.\n";
exit($fail ? 1 : 0);
