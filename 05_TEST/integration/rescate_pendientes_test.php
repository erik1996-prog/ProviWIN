<?php
// 05_TEST/integration/rescate_pendientes_test.php - Rescate de pendientes: un supervisor directo la pasa a rescate con el
// "Resultado de la validación" (sale de la bolsa de los demás), registra avances sin plazo, el administrador puede reasignarla
// y se cierra como Rescatado / No rescatado (queda Finalizada). Orden transferida a un partner (2026-10-28): el partner dice
// si la cancelación fue correcta o incorrecta y el directo que se la transfirió valida su atención. Por HTTP contra la API real.
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
    $foto = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sgs_rescate_partner.png';
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
    $editar = function (int $id, string $suf, string $estado, string $resultado, bool $conFoto = false, string $quien = 'res.dos') use ($http, $base, $ubi, $partner, $foto): array {
        $campos = ['supervision_id_edit' => $id, 'estado_supervision' => $estado, 'resultado_cancelacion' => $resultado, 'tipo_supervision' => 'Instalación',
            'tipo_supervision_inicial' => 'En frío', 'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'],
            'distrito' => $ubi['distrito'], 'ticket_codigo' => "PRUEBA-RES-$suf", 'partner_id' => $partner, 'es_cancelacion' => '1', 'observacion_servicio' => 'Validada con el cliente',
            'latitud' => '-6.77', 'longitud' => '-79.84'];
        if ($conFoto) $campos['foto_servicio_1'] = new CURLFile($foto, 'image/png', 'evidencia.png');
        return $http($quien, "$base/api/supervisiones.php?action=create", $campos);
    };
    file_put_contents($foto, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    // Pendiente importada (en la bolsa de LAMBAYEQUE, asignada por carga a "uno")
    $orden = $nueva('1');
    $ve = function (string $u, string $estado) use ($http, $base, $orden): bool {
        $r = $http($u, "$base/api/supervisiones.php?action=list&estado=$estado&fecha_inicio=2000-01-01&limit=100");
        foreach (($r['data'] ?? []) as $f) if ((int)$f['id'] === $orden) return true;
        return false;
    };
    $accion = fn(string $u, string $a, array $campos = []) => $http($u, "$base/api/supervisiones.php?action=rescate_$a", ['supervision_id' => $orden] + $campos);

    $check('antes del rescate, "dos" la ve en Pendientes (bolsa de la zona)', $ve('res.dos', 'pendiente'));
    $r = $editar($orden, '1', 'Iniciada', 'En proceso de rescate', false, 'res.piura');
    $check('un supervisor de otra zona no puede pasarla a rescate', ($r['status'] ?? '') !== 'success', $r);
    $r = $editar($orden, '1', 'Iniciada', 'En proceso de rescate');
    $check('"dos" la pasa a rescate con el resultado de la validación', ($r['status'] ?? '') === 'success', $r);
    $fila = $pdo->query("SELECT supervisor_id, rescate_estado, estado_supervision FROM supervisiones WHERE id=$orden")->fetch();
    $check('queda a su nombre, "En rescate" y sigue Pendiente (no ocupa su supervisión en proceso)', (int)$fila['supervisor_id'] === $ids['res.dos'] && $fila['rescate_estado'] === 'En rescate' && $fila['estado_supervision'] === 'Pendiente', $fila);
    $check('sale de la bolsa: "uno" ya no la ve en Pendientes', !$ve('res.uno', 'pendiente'));
    $check('"dos" la ve en la pestaña En rescate (y no en Pendientes)', $ve('res.dos', 'rescate') && !$ve('res.dos', 'pendiente'));
    $r = $editar($orden, '1', 'Iniciada', 'Cancelación correcta', false, 'res.uno');
    $check('"uno" no puede trabajarla (ya está en rescate)', ($r['status'] ?? '') === 'error', $r);
    $r = $editar($orden, '1', 'Iniciada', '');
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

    // ---------- Orden transferida al partner (2026-10-28) ----------
    // "dos" la transfiere al partner; el partner dice si la cancelación fue correcta o incorrecta (incorrecta, con evidencia)
    // y solo "dos" (quien la transfirió) valida su atención. El supervisor asignado (del partner) no cambia.
    $orden2 = $nueva('2');
    $r = $http('res.dos', "$base/api/supervisiones.php?action=asignar_partner", ['supervision_id' => $orden2, 'supervisor_partner_id' => $ids['res.partner']]);
    $check('"dos" asigna la pendiente al supervisor del partner', ($r['status'] ?? '') === 'success', $r);
    // Pestañas (2026-10-28): en cuáles aparece la orden para cada uno
    $pestana = function (string $u, string $estado, int $id) use ($http, $base): bool {
        $r = $http($u, "$base/api/supervisiones.php?action=list&estado=$estado&fecha_inicio=2000-01-01&limit=100");
        foreach (($r['data'] ?? []) as $f) if ((int)$f['id'] === $id) return true;
        return false;
    };
    $check('"dos" la ve en "Asignadas a partner" (y no en "Por validar"); el partner, en sus Pendientes', $pestana('res.dos', 'asignada_partner', $orden2)
        && !$pestana('res.dos', 'por_validar', $orden2) && $pestana('res.partner', 'partner_pendiente', $orden2));
    $validar = fn(string $u, int $id, array $campos) => $http($u, "$base/api/supervisiones.php?action=validar_partner", ['supervision_id' => $id] + $campos);
    $r = $validar('res.dos', $orden2, ['resultado' => 'Cancelación correcta', 'comentario' => 'antes de tiempo']);
    $check('no se valida antes de que el partner la atienda', ($r['status'] ?? '') === 'error', $r);
    $r = $http('res.partner', "$base/api/supervisiones.php?action=partner_update", ['supervision_id' => $orden2, 'observacion_atencion_partner' => 'Cliente acepta reprogramar']);
    $check('el partner debe elegir si la cancelación fue correcta o incorrecta', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'correcta o incorrecta'), $r);
    $r = $http('res.partner', "$base/api/supervisiones.php?action=partner_update", ['supervision_id' => $orden2, 'observacion_atencion_partner' => 'Cliente acepta reprogramar', 'partner_resultado' => 'incorrecta']);
    $check('"cancelación incorrecta" sin evidencia no se acepta', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'evidencia'), $r);
    $r = $http('res.partner', "$base/api/supervisiones.php?action=partner_update", ['supervision_id' => $orden2, 'observacion_atencion_partner' => 'Cliente acepta reprogramar',
        'partner_resultado' => 'incorrecta', 'foto_partner_1' => new CURLFile($foto, 'image/png', 'evidencia.png')]);
    $check('el partner registra "cancelación incorrecta" con su evidencia', ($r['status'] ?? '') === 'success'
        && (int)$pdo->query("SELECT partner_cancelacion_incorrecta FROM supervisiones WHERE id=$orden2")->fetchColumn() === 1, $r);
    $avisos = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = {$ids['res.dos']} AND titulo = 'Validar atención del partner'")->fetchColumn();
    $check('se avisa al supervisor directo que la transfirió para que la valide', $avisos === 1, $avisos);
    $check('"dos" la ve en "Por validar" (ya no en "Asignadas a partner"); el partner, en "En proceso"', $pestana('res.dos', 'por_validar', $orden2)
        && !$pestana('res.dos', 'asignada_partner', $orden2) && $pestana('res.partner', 'partner_en_proceso', $orden2) && !$pestana('res.partner', 'partner_finalizada', $orden2));
    $p = $http('res.admin', "$base/api/dashboard_pendientes.php?action=dashboard_tabla&desde=" . date('Y-m-d') . '&hasta=' . date('Y-m-d') . "&supervisor_id={$ids['res.dos']}");
    $det = array_values(array_filter($p['data']['filas'] ?? [], fn($x) => (int)$x['id'] === $orden2))[0] ?? null;
    $check('Pendientes y rescates la muestra como "Partner: por validar"', $det && $det['estado'] === 'partner_atendida', $det);
    $r = $validar('res.partner', $orden2, ['resultado' => 'Cancelación correcta', 'comentario' => 'x']);
    $check('el supervisor del partner no puede validar', ($r['status'] ?? '') === 'error', $r);
    $r = $validar('res.uno', $orden2, ['resultado' => 'Cancelación correcta', 'comentario' => 'x']);
    $check('otro supervisor directo no puede validar (solo quien la transfirió)', ($r['status'] ?? '') === 'error', $r);
    $r = $validar('res.dos', $orden2, ['resultado' => 'En proceso de rescate']);
    $check('validar sin comentario no se acepta', ($r['status'] ?? '') === 'error', $r);
    $r = $validar('res.dos', $orden2, ['resultado' => 'En proceso de rescate', 'comentario' => 'Coordinaré con el cliente']);
    $check('"dos" (quien la transfirió) valida: En proceso de rescate', ($r['status'] ?? '') === 'success', $r);
    $r = $validar('res.dos', $orden2, ['resultado' => 'Rescatado', 'comentario' => 'otra vez']);
    $check('una atención ya validada no se vuelve a validar', ($r['status'] ?? '') === 'error', $r);
    $check('validada: el partner la ve en "Finalizadas" (ya no en "En proceso") y sale de "Por validar" de "dos"', $pestana('res.partner', 'partner_finalizada', $orden2)
        && !$pestana('res.partner', 'partner_en_proceso', $orden2) && !$pestana('res.dos', 'por_validar', $orden2));
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
    $check('historial: atención del partner, validación y cierre', $tipos2 === ['Atención del partner', 'Validación', 'Cierre'], $tipos2);
    $p = $http('res.admin', "$base/api/dashboard_pendientes.php?action=dashboard_tabla&desde=$hoy&hasta=$hoy&supervisor_id={$ids['res.dos']}");
    $det = array_values(array_filter($p['data']['filas'] ?? [], fn($x) => (int)$x['id'] === $orden2))[0] ?? null;
    $check('Pendientes y rescates la muestra con "dos" como responsable y cancelación correcta (no rescatada)', $det && $det['estado'] === 'correcta' && str_contains($det['responsable'] ?? '', 'res.dos'), $det);
    foreach ($pdo->query("SELECT foto FROM rescate_seguimientos WHERE supervision_id=$orden2 AND foto IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $ruta) @unlink(UPLOAD_DIR . basename($ruta));
    $cancel = $pdo->query("SELECT id, estado_cancelacion FROM supervisiones WHERE id IN ($orden, $orden2)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check('al cerrar un rescate se anota en la cancelación: Rescatado / Cancelación correcta', ($cancel[$orden] ?? '') === 'Rescatado' && ($cancel[$orden2] ?? '') === 'Cancelación correcta', $cancel);

    $transferida = function (string $suf, string $resultadoPartner) use ($nueva, $http, $base, $ids, $foto): int {
        $id = $nueva($suf);
        $http('res.dos', "$base/api/supervisiones.php?action=asignar_partner", ['supervision_id' => $id, 'supervisor_partner_id' => $ids['res.partner']]);
        $http('res.partner', "$base/api/supervisiones.php?action=partner_update", ['supervision_id' => $id, 'observacion_atencion_partner' => 'Revisada en campo',
            'partner_resultado' => $resultadoPartner] + ($resultadoPartner === 'incorrecta' ? ['foto_partner_1' => new CURLFile($foto, 'image/png', 'evidencia.png')] : []));
        return $id;
    };
    $o6 = $transferida('6', 'correcta');
    $r = $validar('res.dos', $o6, ['resultado' => 'Cancelación correcta', 'comentario' => 'Coincide con lo revisado']);
    $f6 = $pdo->query("SELECT estado_supervision, rescate_estado, estado_cancelacion, partner_cancelacion_incorrecta FROM supervisiones WHERE id=$o6")->fetch();
    $check('partner "correcta" + validación "Cancelación correcta": se finaliza sin rescate', ($r['status'] ?? '') === 'success' && $f6['estado_supervision'] === 'Finalizada'
        && $f6['rescate_estado'] === null && $f6['estado_cancelacion'] === 'Cancelación correcta' && (int)$f6['partner_cancelacion_incorrecta'] === 0, [$r, $f6]);
    $d6 = $http('res.dos', "$base/api/supervisiones.php?action=detail&id=$o6");
    $check('"dos" consulta la orden finalizada que transfirió, con la atención y su validación', ($d6['status'] ?? '') === 'success'
        && array_column($d6['rescate_seguimientos'] ?? [], 'tipo') === ['Atención del partner', 'Validación'], $d6['message'] ?? array_column($d6['rescate_seguimientos'] ?? [], 'tipo'));
    $check('finalizada por la validación: el partner la sigue viendo, en "Finalizadas"', $pestana('res.partner', 'partner_finalizada', $o6) && $pestana('res.partner', 'todos', $o6));
    $o7 = $transferida('7', 'incorrecta');
    $r = $validar('res.admin', $o7, ['resultado' => 'Rescatado', 'comentario' => 'Se reprogramó la instalación']);
    $f7 = $pdo->query("SELECT estado_supervision, rescate_estado, estado_cancelacion, supervisor_id FROM supervisiones WHERE id=$o7")->fetch();
    $check('el administrador también valida: "Rescatado" finaliza como rescatada y el supervisor del partner no cambia', ($r['status'] ?? '') === 'success'
        && $f7['estado_supervision'] === 'Finalizada' && $f7['rescate_estado'] === 'Rescatado' && $f7['estado_cancelacion'] === 'Rescatado' && (int)$f7['supervisor_id'] === $ids['res.partner'], [$r, $f7]);
    $p = $http('res.admin', "$base/api/dashboard_pendientes.php?action=dashboard_tabla&desde=" . date('Y-m-d') . '&hasta=' . date('Y-m-d'));
    $estados = array_column(array_filter($p['data']['filas'] ?? [], fn($x) => in_array((int)$x['id'], [$o6, $o7], true)), 'estado', 'id');
    $check('Pendientes y rescates: cancelación correcta y rescatada', ($estados[$o6] ?? '') === 'correcta' && ($estados[$o7] ?? '') === 'rescatada', $estados);
    foreach ($pdo->query("SELECT foto FROM rescate_seguimientos WHERE supervision_id IN ($o6, $o7) AND foto IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $ruta) @unlink(UPLOAD_DIR . basename($ruta));

    // ---------- Prioridad alta en Pendientes (2026-10-28) ----------
    $o8 = $nueva('8'); $o9 = $nueva('9');
    $orden = function (string $u) use ($http, $base, $o8, $o9): array {
        $r = $http($u, "$base/api/supervisiones.php?action=list&estado=pendiente&fecha_inicio=2000-01-01&limit=100");
        $ids = array_map('intval', array_column($r['data'] ?? [], 'id'));
        return [array_search($o8, $ids, true), array_search($o9, $ids, true), array_column($r['data'] ?? [], 'prioridad_alta', 'id')];
    };
    [$p8, $p9] = $orden('res.dos');
    $check('sin prioridad, en Pendientes sale primero la más reciente', $p8 !== false && $p9 !== false && $p9 < $p8, [$p8, $p9]);
    $prioridad = fn(string $u, int $id, string $p) => $http($u, "$base/api/supervisiones.php?action=prioridad_pendiente", ['supervision_id' => $id, 'prioridad' => $p]);
    $r = $prioridad('res.dos', $o8, '1');
    $check('un supervisor directo no puede marcar la prioridad', ($r['status'] ?? '') === 'error', $r);
    $r = $prioridad('res.admin', $o8, '1');
    $check('el administrador marca la prioridad alta', ($r['status'] ?? '') === 'success', $r);
    [$p8, $p9, $marcas] = $orden('res.dos');
    $check('con prioridad alta sale primero en Pendientes del supervisor directo, con su marca', $p8 !== false && $p8 < $p9 && (int)($marcas[$o8] ?? 0) === 1, [$p8, $p9, $marcas[$o8] ?? null]);
    $o10 = $transferida('10', 'correcta');
    $prioridad('res.admin', $o10, '1');
    $lp = $http('res.partner', "$base/api/supervisiones.php?action=list&estado=todos&fecha_inicio=2000-01-01&limit=100");
    $filaP = array_values(array_filter($lp['data'] ?? [], fn($x) => (int)$x['id'] === $o10))[0] ?? null;
    $check('el partner no recibe la prioridad de su orden', $filaP !== null && ($filaP['prioridad_alta'] ?? null) === null, $filaP);
    $r = $prioridad('res.admin', $o6, '1');
    $check('una orden finalizada no se marca', ($r['status'] ?? '') === 'error', $r);
    $r = $prioridad('res.admin', $o8, '0');
    $check('el administrador quita la prioridad', ($r['status'] ?? '') === 'success' && (int)$pdo->query("SELECT prioridad_alta FROM supervisiones WHERE id=$o8")->fetchColumn() === 0, $r);
    // ---------- Resultado de la validación (Cancelación correcta / En proceso de rescate / Rescatado) ----------
    // a) Desde la pendiente: "En proceso de rescate" la pasa a rescate (sin finalizar ni ocupar su supervisión en proceso)
    $o3 = $nueva('3');
    $r = $editar($o3, '3', 'Iniciada', 'En proceso de rescate');
    $f3 = $pdo->query("SELECT estado_supervision, rescate_estado, rescate_responsable_id, supervisor_id, estado_cancelacion FROM supervisiones WHERE id=$o3")->fetch();
    $check('"En proceso de rescate" pasa la pendiente a rescate a cargo de "dos" (sigue Pendiente)', ($r['status'] ?? '') === 'success' && str_contains($r['message'] ?? '', 'rescate')
        && $f3['estado_supervision'] === 'Pendiente' && $f3['rescate_estado'] === 'En rescate' && (int)$f3['rescate_responsable_id'] === $ids['res.dos']
        && $f3['estado_cancelacion'] === 'En proceso de rescate', [$r, $f3]);
    $activa = (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE supervisor_id = {$ids['res.dos']} AND estado_supervision IN ('Iniciada','En proceso')")->fetchColumn();
    $check('no le queda ninguna supervisión "en proceso" (el rescate no la ocupa)', $activa === 0, $activa);
    // b) "Rescatado": se valida y se finaliza como rescatada
    $o4 = $nueva('4');
    $editar($o4, '4', 'Iniciada', 'Cancelación correcta');
    $r = $editar($o4, '4', 'Finalizada', 'Rescatado', true);
    $f4 = $pdo->query("SELECT estado_supervision, rescate_estado, estado_cancelacion FROM supervisiones WHERE id=$o4")->fetch();
    $check('"Rescatado" finaliza la orden como rescatada', ($r['status'] ?? '') === 'success' && $f4['estado_supervision'] === 'Finalizada' && $f4['rescate_estado'] === 'Rescatado' && $f4['estado_cancelacion'] === 'Rescatado', [$r, $f4]);
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
