<?php
// 05_TEST/integration/rescate_pendientes_test.php - Rescate de pendientes: un supervisor directo toma una pendiente como
// rescate (sale de la bolsa de los demás), registra avances sin plazo, el administrador puede reasignarla y se cierra como
// Rescatado / No rescatado (queda Finalizada). Por HTTP contra la API real.
// Crea un administrador (DNI 99999995), dos supervisores directos de LAMBAYEQUE (99999996-97), uno de PIURA (99999998),
// un supervisor de partner (99999990) y las pendientes PRUEBA-RES-1 y PRUEBA-RES-2; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/rescate_pendientes_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$usuarios = ['99999995' => ['res.admin', 'Administrador', ['LAMBAYEQUE']], '99999996' => ['res.uno', 'Supervisor', ['LAMBAYEQUE']],
    '99999997' => ['res.dos', 'Supervisor', ['LAMBAYEQUE']], '99999998' => ['res.piura', 'Supervisor', ['PIURA']],
    '99999990' => ['res.partner', 'Supervisor', ['LAMBAYEQUE'], 'partner']];
$jars = [];
$cleanup = function () use ($pdo, $usuarios, &$jars) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-RES-%'"); // historial y seguimientos en cascada
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", array_keys($usuarios)) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id IN ($in)");
        $pdo->exec("DELETE FROM notificaciones WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_departamentos_acceso WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_permisos WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach ($usuarios as $datosU) foreach (['::1', '127.0.0.1'] as $ip) if ($u = $datosU[0]) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'ResPrueba' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Rescate de pendientes — $base\n";

try {
    $svc = new UsuarioService($pdo);
    $ids = [];
    foreach ($usuarios as $dni => $datosU) {
        [$usuario, $rol, $zonas] = $datosU;
        $ids[$usuario] = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Rescate', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
            'rol' => $rol, 'organizacion' => $datosU[3] ?? 'directo', 'partner_id' => $partner, 'zonas' => $zonas, 'password' => $clave], 0, $admin)['id'];
    }
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno) VALUES (:s, CURRENT_DATE(), NOW(), 'En turno')")->execute([':s' => $ids['res.dos']]);
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();
    // Pendiente importada (en la bolsa de LAMBAYEQUE, asignada por carga a "uno")
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_servicio_caliente,
        es_cancelacion, origen_importacion, estado_supervision)
        VALUES ('SUP-RES-1', NOW(), NOW(), :sup, :adm, 'Líder', 'Regular', :dep, :prov, :dist, 'PRUEBA-RES-1', 'En frío', :p, 'Ninguno', 'Instalación', 'Instalación', '', 'Cancelada', 1, 1, 'Pendiente')")
        ->execute([':sup' => $ids['res.uno'], ':adm' => $ids['res.admin'], ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'], ':p' => $partner]);
    $orden = (int)$pdo->lastInsertId();

    $http = function (string $quien, string $url, array $post = null) use (&$jars, $base): array {
        $jars[$quien] ??= tempnam(sys_get_temp_dir(), 'sgsx');
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$quien], CURLOPT_COOKIEFILE => $jars[$quien], CURLOPT_TIMEOUT => 60];
        if ($post !== null) $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($GLOBALS['csrf'][$quien] ?? '')]];
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return json_decode($body, true) ?? ['crudo' => substr($body, 0, 300)];
    };
    $GLOBALS['csrf'] = [];
    foreach ($usuarios as $datosU) { $u = $datosU[0];
        $http($u, "$base/api/auth.php?action=login");
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u], CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); curl_close($ch);
        $ch = curl_init("$base/index.php?page=supervision_lista");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u]]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); curl_close($ch);
        $GLOBALS['csrf'][$u] = $m[1] ?? '';
    }
    $ve = function (string $u, string $estado) use ($http, $base, $orden): bool {
        $r = $http($u, "$base/api/supervisiones.php?action=list&estado=$estado&fecha_inicio=2000-01-01&limit=100");
        foreach (($r['data'] ?? []) as $f) if ((int)$f['id'] === $orden) return true;
        return false;
    };
    $accion = fn(string $u, string $a, array $campos = []) => $http($u, "$base/api/supervisiones.php?action=rescate_$a", ['supervision_id' => $orden] + $campos);

    $check('antes del rescate, "dos" la ve en Pendientes (bolsa de la zona)', $ve('res.dos', 'pendiente'));
    $r = $accion('res.piura', 'tomar');
    $check('un supervisor de otra zona no puede tomarla', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'zonas'), $r);
    $r = $accion('res.dos', 'tomar', ['comentario' => 'Cliente pidió llamada el lunes']);
    $check('"dos" la toma como rescate', ($r['status'] ?? '') === 'success', $r);
    $fila = $pdo->query("SELECT supervisor_id, rescate_estado, estado_supervision FROM supervisiones WHERE id=$orden")->fetch();
    $check('queda a su nombre, "En rescate" y sigue Pendiente (no ocupa su supervisión en proceso)', (int)$fila['supervisor_id'] === $ids['res.dos'] && $fila['rescate_estado'] === 'En rescate' && $fila['estado_supervision'] === 'Pendiente', $fila);
    $check('sale de la bolsa: "uno" ya no la ve en Pendientes', !$ve('res.uno', 'pendiente'));
    $check('"dos" la ve en la pestaña En rescate (y no en Pendientes)', $ve('res.dos', 'rescate') && !$ve('res.dos', 'pendiente'));
    $r = $accion('res.uno', 'tomar');
    $check('"uno" no puede tomarla (ya está en rescate)', ($r['status'] ?? '') === 'error', $r);
    $r = $http('res.dos', "$base/api/supervisiones.php?action=create", ['supervision_id_edit' => $orden, 'estado_supervision' => 'Iniciada', 'tipo_supervision' => 'Instalación',
        'tipo_supervision_inicial' => 'En frío', 'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'],
        'distrito' => $ubi['distrito'], 'ticket_codigo' => 'PRUEBA-RES-1', 'partner_id' => $partner]);
    $check('no se puede iniciar como supervisión normal mientras está en rescate', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'rescate'), $r);
    $r = $http('res.admin', "$base/api/supervisiones.php?action=asignar_partner", ['supervision_id' => $orden, 'supervisor_partner_id' => 1]);
    $check('no se puede asignar a un partner mientras está en rescate', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'rescate'), $r);
    $r = $accion('res.dos', 'avance');
    $check('un avance vacío se rechaza', ($r['status'] ?? '') === 'error', $r);
    $r = $accion('res.dos', 'avance', ['comentario' => 'Se llamó, volverá a evaluar']);
    $check('registra un avance', ($r['status'] ?? '') === 'success', $r);
    $r = $accion('res.dos', 'reasignar', ['supervisor_id' => $ids['res.uno']]);
    $check('el supervisor no puede reasignar (solo el administrador)', ($r['status'] ?? '') === 'error', $r);
    $r = $accion('res.admin', 'reasignar', ['supervisor_id' => $ids['res.piura']]);
    $check('el administrador no puede reasignar a alguien de otra zona', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'zona'), $r);
    $r = $accion('res.admin', 'reasignar', ['supervisor_id' => $ids['res.uno'], 'comentario' => 'Dos sale de vacaciones']);
    $check('el administrador reasigna a "uno"', ($r['status'] ?? '') === 'success', $r);
    $r = $accion('res.dos', 'avance', ['comentario' => 'intento']);
    $check('"dos" ya no puede registrar avances', ($r['status'] ?? '') === 'error', $r);
    $r = $accion('res.uno', 'cerrar', ['resultado' => 'Rescatado']);
    $check('cerrar sin comentario se rechaza', ($r['status'] ?? '') === 'error', $r);
    $r = $accion('res.uno', 'cerrar', ['resultado' => 'Rescatado', 'comentario' => 'Cliente aceptó reprogramar la instalación']);
    $check('"uno" lo cierra como Rescatado', ($r['status'] ?? '') === 'success', $r);
    $fila = $pdo->query("SELECT rescate_estado, estado_supervision, rescate_fin FROM supervisiones WHERE id=$orden")->fetch();
    $check('la pendiente queda Finalizada · Rescatado', $fila['rescate_estado'] === 'Rescatado' && $fila['estado_supervision'] === 'Finalizada' && $fila['rescate_fin'] !== null, $fila);
    $tipos = $pdo->query("SELECT tipo FROM rescate_seguimientos WHERE supervision_id=$orden ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $check('historial: inicio, avance, reasignación y cierre', $tipos === ['Inicio', 'Avance', 'Reasignación', 'Cierre'], $tipos);
    $d = $http('res.uno', "$base/api/supervisiones.php?action=detail&id=$orden");
    $check('el detalle trae el historial del rescate', count($d['rescate_seguimientos'] ?? []) === 4, array_keys($d));
    $hoy = date('Y-m-d');
    $p = $http('res.admin', "$base/api/dashboard_pendientes.php?action=dashboard&desde=$hoy&hasta=$hoy&supervisor_id={$ids['res.uno']}");
    $uno = array_column($p['data']['directos'] ?? [], null, 'id')[$ids['res.uno']] ?? [];
    $check('Pendientes y rescates la cuenta como rescatada y atendida por "uno"', ($p['data']['kpis']['rescatada'] ?? 0) === 1 && ($uno['rescatadas'] ?? 0) === 1 && ($uno['cerradas'] ?? 0) === 1, $p['data'] ?? $p);

    // ---------- Rescate propuesto por el partner ----------
    // "dos" asigna una pendiente al partner; el partner la atiende e indica que se puede rescatar (con evidencia);
    // solo "dos" (quien la asignó) la pasa a rescate. El supervisor asignado (del partner) no cambia.
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_servicio_caliente,
        es_cancelacion, origen_importacion, estado_supervision)
        VALUES ('SUP-RES-2', NOW(), NOW(), :sup, :adm, 'Líder', 'Regular', :dep, :prov, :dist, 'PRUEBA-RES-2', 'En frío', :p, 'Ninguno', 'Instalación', 'Instalación', '', 'Cancelada', 1, 1, 'Pendiente')")
        ->execute([':sup' => $ids['res.uno'], ':adm' => $ids['res.admin'], ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'], ':p' => $partner]);
    $orden2 = (int)$pdo->lastInsertId();
    $r = $http('res.dos', "$base/api/supervisiones.php?action=asignar_partner", ['supervision_id' => $orden2, 'supervisor_partner_id' => $ids['res.partner']]);
    $check('"dos" asigna la pendiente al supervisor del partner', ($r['status'] ?? '') === 'success', $r);
    $foto = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sgs_rescate_partner.png';
    file_put_contents($foto, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $r = $http('res.partner', "$base/api/supervisiones.php?action=partner_update", ['supervision_id' => $orden2, 'observacion_atencion_partner' => 'Cliente acepta reprogramar', 'partner_rescatable' => '1']);
    $check('el partner no puede indicar "se puede rescatar" sin evidencia', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'evidencia'), $r);
    $r = $http('res.partner', "$base/api/supervisiones.php?action=partner_update", ['supervision_id' => $orden2, 'observacion_atencion_partner' => 'Cliente acepta reprogramar',
        'partner_rescatable' => '1', 'foto_rescate_1' => new CURLFile($foto, 'image/png', 'evidencia.png')]);
    $check('el partner atiende e indica que se puede rescatar, con su evidencia', ($r['status'] ?? '') === 'success', $r);
    $avisos = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = {$ids['res.dos']} AND titulo = 'Se puede rescatar'")->fetchColumn();
    $check('se avisa al supervisor directo que la asignó', $avisos === 1, $avisos);
    $r = $http('res.partner', "$base/api/supervisiones.php?action=rescate_tomar", ['supervision_id' => $orden2]);
    $check('el supervisor del partner no puede pasarla a rescate (solo propone)', ($r['status'] ?? '') === 'error', $r);
    $r = $http('res.uno', "$base/api/supervisiones.php?action=rescate_tomar", ['supervision_id' => $orden2]);
    $check('otro supervisor directo no puede pasarla a rescate (solo quien la asignó)', ($r['status'] ?? '') === 'error', $r);
    $r = $http('res.dos', "$base/api/supervisiones.php?action=rescate_tomar", ['supervision_id' => $orden2, 'comentario' => 'Coordinaré con el cliente']);
    $check('"dos" (quien la asignó) la pasa a rescate', ($r['status'] ?? '') === 'success', $r);
    $fila = $pdo->query("SELECT supervisor_id, rescate_responsable_id, rescate_estado FROM supervisiones WHERE id=$orden2")->fetch();
    $check('el rescate queda a cargo de "dos" y el supervisor asignado sigue siendo el del partner', (int)$fila['rescate_responsable_id'] === $ids['res.dos']
        && (int)$fila['supervisor_id'] === $ids['res.partner'] && $fila['rescate_estado'] === 'En rescate', $fila);
    $check('"dos" la ve en su pestaña En rescate', (function () use ($http, $base, $orden2) { $r = $http('res.dos', "$base/api/supervisiones.php?action=list&estado=rescate&fecha_inicio=2000-01-01&limit=100");
        foreach (($r['data'] ?? []) as $f) if ((int)$f['id'] === $orden2) return true; return false; })());
    $r = $http('res.partner', "$base/api/supervisiones.php?action=rescate_avance", ['supervision_id' => $orden2, 'comentario' => 'intento del partner']);
    $check('el partner no registra avances del rescate', ($r['status'] ?? '') === 'error', $r);
    $hoy = date('Y-m-d');
    $c = $http('res.admin', "$base/api/dashboard_partners.php?action=dashboard&desde=$hoy&hasta=$hoy&partner_id=$partner");
    $filaP = array_values(array_filter($c['data']['cumplimiento']['supervisores'] ?? [], fn($x) => (int)$x['id'] === $ids['res.partner']))[0] ?? null;
    $check('el cumplimiento del partner no cambia: sigue contando como atendida a tiempo', $filaP && $filaP['asignadas'] === 1 && $filaP['a_tiempo'] === 1, $filaP);
    $r = $http('res.dos', "$base/api/supervisiones.php?action=rescate_cerrar", ['supervision_id' => $orden2, 'resultado' => 'No rescatado', 'comentario' => 'El cliente desistió']);
    $check('"dos" cierra el rescate como No rescatado', ($r['status'] ?? '') === 'success', $r);
    $tipos2 = $pdo->query("SELECT tipo FROM rescate_seguimientos WHERE supervision_id=$orden2 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $check('historial: propuesta del partner, inicio y cierre', $tipos2 === ['Propuesta del partner', 'Inicio', 'Cierre'], $tipos2);
    $p = $http('res.admin', "$base/api/dashboard_pendientes.php?action=dashboard_tabla&desde=$hoy&hasta=$hoy&supervisor_id={$ids['res.dos']}");
    $det = array_values(array_filter($p['data']['filas'] ?? [], fn($x) => (int)$x['id'] === $orden2))[0] ?? null;
    $check('Pendientes y rescates la muestra con "dos" como responsable y cancelación correcta (no rescatada)', $det && $det['estado'] === 'correcta' && str_contains($det['responsable'] ?? '', 'res.dos'), $det);
    foreach ($pdo->query("SELECT foto FROM rescate_seguimientos WHERE supervision_id=$orden2 AND foto IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $ruta) @unlink(UPLOAD_DIR . basename($ruta));
    $cancel = $pdo->query("SELECT id, estado_cancelacion FROM supervisiones WHERE id IN ($orden, $orden2)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check('al cerrar un rescate se anota en la cancelación: Rescate / Cancelación correcta', ($cancel[$orden] ?? '') === 'Rescate' && ($cancel[$orden2] ?? '') === 'Cancelación correcta', $cancel);

    // ---------- Resultado de la validación (Cancelación correcta / En proceso de rescate / Rescate) ----------
    $nueva = function (string $suf) use ($pdo, $ids, $ubi, $partner): int {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_servicio_caliente,
            es_cancelacion, tipificacion_cancelacion, origen_importacion, estado_supervision)
            VALUES (:c, NOW(), NOW(), :sup, :adm, 'Líder', 'Regular', :dep, :prov, :dist, :t, 'En frío', :p, 'Ninguno', 'Instalación', 'Instalación', '', 'Cancelada', 1,
            (SELECT descripcion FROM tipificaciones_cancelacion LIMIT 1), 1, 'Pendiente')")
            ->execute([':c' => "SUP-RES-$suf", ':t' => "PRUEBA-RES-$suf", ':sup' => $ids['res.uno'], ':adm' => $ids['res.admin'], ':dep' => $ubi['departamento'],
                ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'], ':p' => $partner]);
        return (int)$pdo->lastInsertId();
    };
    $editar = function (int $id, string $suf, string $estado, string $resultado, bool $conFoto = false) use ($http, $base, $ubi, $partner, $foto): array {
        $campos = ['supervision_id_edit' => $id, 'estado_supervision' => $estado, 'resultado_cancelacion' => $resultado, 'tipo_supervision' => 'Instalación',
            'tipo_supervision_inicial' => 'En frío', 'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'],
            'distrito' => $ubi['distrito'], 'ticket_codigo' => "PRUEBA-RES-$suf", 'partner_id' => $partner, 'es_cancelacion' => '1', 'observacion_servicio' => 'Validada con el cliente',
            'latitud' => '-6.77', 'longitud' => '-79.84'];
        if ($conFoto) $campos['foto_servicio_1'] = new CURLFile($foto, 'image/png', 'evidencia.png');
        return $http('res.dos', "$base/api/supervisiones.php?action=create", $campos);
    };
    file_put_contents($foto, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    // a) Desde la pendiente: "En proceso de rescate" la pasa a rescate (sin finalizar ni ocupar su supervisión en proceso)
    $o3 = $nueva('3');
    $r = $editar($o3, '3', 'Iniciada', 'En proceso de rescate');
    $f3 = $pdo->query("SELECT estado_supervision, rescate_estado, rescate_responsable_id, supervisor_id, estado_cancelacion FROM supervisiones WHERE id=$o3")->fetch();
    $check('"En proceso de rescate" pasa la pendiente a rescate a cargo de "dos" (sigue Pendiente)', ($r['status'] ?? '') === 'success' && str_contains($r['message'] ?? '', 'rescate')
        && $f3['estado_supervision'] === 'Pendiente' && $f3['rescate_estado'] === 'En rescate' && (int)$f3['rescate_responsable_id'] === $ids['res.dos']
        && $f3['estado_cancelacion'] === 'En proceso de rescate', [$r, $f3]);
    $activa = (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE supervisor_id = {$ids['res.dos']} AND estado_supervision IN ('Iniciada','En proceso')")->fetchColumn();
    $check('no le queda ninguna supervisión "en proceso" (el rescate no la ocupa)', $activa === 0, $activa);
    // b) "Rescate": se valida y se finaliza como rescatada
    $o4 = $nueva('4');
    $editar($o4, '4', 'Iniciada', 'Cancelación correcta');
    $r = $editar($o4, '4', 'Finalizada', 'Rescate', true);
    $f4 = $pdo->query("SELECT estado_supervision, rescate_estado, estado_cancelacion FROM supervisiones WHERE id=$o4")->fetch();
    $check('"Rescate" finaliza la orden como rescatada', ($r['status'] ?? '') === 'success' && $f4['estado_supervision'] === 'Finalizada' && $f4['rescate_estado'] === 'Rescatado' && $f4['estado_cancelacion'] === 'Rescate', [$r, $f4]);
    // c) Sin resultado elegido (2026-10-15) se puede iniciar y guardar avance, pero no finalizar
    $o5 = $nueva('5');
    $r = $editar($o5, '5', 'Iniciada', '');
    $check('sin "Resultado de la validación" se puede iniciar', ($r['status'] ?? '') === 'success', $r);
    $r = $editar($o5, '5', 'Finalizada', '', true);
    $estado5 = $pdo->query("SELECT estado_supervision FROM supervisiones WHERE id=$o5")->fetchColumn();
    $check('sin "Resultado de la validación" no se puede finalizar', ($r['status'] ?? '') === 'error' && ($r['field'] ?? '') === 'resultado_cancelacion' && $estado5 !== 'Finalizada', [$r, $estado5]);
    // d) "Cancelación correcta": se finaliza sin rescate
    $r = $editar($o5, '5', 'Finalizada', 'Cancelación correcta', true);
    $f5 = $pdo->query("SELECT estado_supervision, rescate_estado, estado_cancelacion FROM supervisiones WHERE id=$o5")->fetch();
    $check('"Cancelación correcta" finaliza sin rescate', ($r['status'] ?? '') === 'success' && $f5['estado_supervision'] === 'Finalizada' && $f5['rescate_estado'] === null && $f5['estado_cancelacion'] === 'Cancelación correcta', [$r, $f5]);
    foreach ($pdo->query("SELECT foto_servicio_1 FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-RES-%' AND foto_servicio_1 IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $ruta) @unlink(UPLOAD_DIR . basename($ruta));
    @unlink($foto);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, turno y pendiente de prueba eliminados.\n";
exit($fail ? 1 : 0);
