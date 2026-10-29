<?php
// 05_TEST/integration/entorno_zona_area_test.php - (2026-10-28) Un usuario creado para una zona y un área de trabajo ve solo
// eso en todas las pantallas que muestran supervisiones. Un líder (Coordinador) y un supervisor directo con zona AREQUIPA y área
// Instalaciones; supervisiones de muestra en AREQUIPA (Instalación y VT) y PIURA (Instalación): solo deben ver AREQUIPA-Instalación
// en Supervisiones, mapa de pendientes, Pendientes y rescates, Observaciones, Dashboard de partners e indicadores.
// Por HTTP contra la API real. Crea usuarios DNI 99999961-63 y supervisiones PRUEBA-ENT-*; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/entorno_zona_area_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$dnis = ['99999961', '99999962', '99999963'];
$frascos = [];
$limpiar = function () use ($pdo, $dnis, &$frascos) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-ENT-%'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['asistencia_supervisores' => 'supervisor_id', 'notificaciones' => 'usuario_id', 'usuario_departamentos_acceso' => 'usuario_id', 'usuario_permisos' => 'usuario_id'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
    foreach (['ent.lider', 'ent.sup', 'ent.otro'] as $u) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "
"; $cond ? $ok++ : $fail++; };
echo "Entorno por zona y área — $base
";
try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $clave = 'EntPrueba#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    $nuevo = function ($dni, $u, $rol, $zonas, $area) use ($svc, $admin, $clave, $pdo, $partner) {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Ent', 'apellidos' => "Prueba $u", 'email' => "$u@proviwin.test", 'usuario' => $u, 'rol' => $rol, 'organizacion' => 'directo',
            'partner_id' => $partner, 'zonas' => $zonas, 'area_trabajo' => $area, 'password' => $clave], 0, $admin)['id'];
        $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0, activo = 1, por_activar = 0, area_trabajo = " . ($area ? $pdo->quote($area) : 'NULL') . " WHERE id = $id");
        return $id;
    };
    $ids = ['lider' => $nuevo('99999961', 'ent.lider', 'Coordinador', ['AREQUIPA'], 'Instalaciones'),
        'sup' => $nuevo('99999962', 'ent.sup', 'Supervisor', ['AREQUIPA'], 'Instalaciones'),
        'otro' => $nuevo('99999963', 'ent.otro', 'Supervisor', ['AREQUIPA', 'PIURA'], 'Instalaciones y VT')];
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno) VALUES (:s, CURRENT_DATE(), NOW(), 'En turno')")->execute([':s' => $ids['otro']]);
    $ubi = fn($d) => $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='$d' LIMIT 1")->fetch();
    $sup = function ($codigo, $dep, $clas, $estado) use ($pdo, $ids, $ubi, $partner) {
        $u = $ubi($dep);
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_servicio_caliente, es_cancelacion, origen_importacion, estado_supervision, tecnico1_nombre)
            VALUES (:c, NOW(), NOW(), :s, :s2, 'Líder', 'Regular', :d, :p, :di, :t, 'En frío', :pa, 'Ninguno', :cl, 'Instalación', '', 'Cancelada', 1, 1, :e, :tec)")
            ->execute([':c' => "SUP-$codigo", ':s' => $ids['otro'], ':s2' => $ids['otro'], ':d' => $u['departamento'], ':p' => $u['provincia'], ':di' => $u['distrito'], ':t' => "PRUEBA-ENT-$codigo", ':pa' => $partner, ':cl' => $clas, ':e' => $estado, ':tec' => "PRUEBA-ENT-$codigo-TEC"]);
        return (int)$pdo->lastInsertId();
    };
    // Lo único que deberían ver: AQP-INS (Arequipa, Instalación). AQP-VT: otra área. PIU-INS: otra zona.
    $sup('F-AQP-INS', 'AREQUIPA', 'Instalación', 'Finalizada'); $sup('F-AQP-VT', 'AREQUIPA', 'Avería', 'Finalizada'); $sup('F-PIU-INS', 'PIURA', 'Instalación', 'Finalizada');
    $cat = $pdo->query("SELECT c.id cat, s.id sub FROM categorias_observacion c JOIN subopciones_observacion s ON s.categoria_id = c.id LIMIT 1")->fetch();
    foreach ($pdo->query("SELECT id, ticket_codigo FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-ENT-F-%'")->fetchAll() as $fs)
        $pdo->prepare("INSERT INTO observaciones_supervision (supervision_id, gravedad, categoria_id, subopcion_id, detalle_observacion, fecha_limite_respuesta, creado_por) VALUES (:s, 'Leve', :c, :o, :d, DATE_ADD(NOW(), INTERVAL 3 DAY), :u)")
            ->execute([':s' => $fs['id'], ':c' => $cat['cat'], ':o' => $cat['sub'], ':d' => 'OBS-' . $fs['ticket_codigo'], ':u' => $ids['otro']]);
    $sup('P-AQP-INS', 'AREQUIPA', 'Instalación', 'Pendiente'); $sup('P-AQP-VT', 'AREQUIPA', 'Avería', 'Pendiente'); $sup('P-PIU-INS', 'PIURA', 'Instalación', 'Pendiente');

    $sesion = function (string $u) use ($base, $clave, &$frascos) {
        $fr = tempnam(sys_get_temp_dir(), 'sgse'); $frascos[] = $fr;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch);
        return function (string $url) use ($fr) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_TIMEOUT => 60]); return (string)curl_exec($ch); };
    };
    $hoy = date('Y-m-d');
    foreach (['ent.lider' => 'Líder (Coordinador)', 'ent.sup' => 'Supervisor directo'] as $u => $nombre) {
        $get = $sesion($u);
        $pantallas = [
            'Supervisiones (todas)' => "$base/api/supervisiones.php?action=list&estado=todos&fecha_inicio=2000-01-01&limit=200",
            'Supervisiones (pendientes)' => "$base/api/supervisiones.php?action=list&estado=pendiente&fecha_inicio=2000-01-01&limit=200",
            'Mapa de pendientes' => "$base/api/supervisiones.php?action=pending_map",
            'Pendientes y rescates (tabla)' => "$base/api/dashboard_pendientes.php?action=dashboard_tabla&desde=$hoy&hasta=$hoy",
            'Observaciones (página)' => "$base/index.php?page=registro_observaciones",
            'Control y recurrencia' => "$base/index.php?page=control_observaciones",
            'Dashboard de partners' => "$base/api/dashboard_partners.php?action=dashboard&desde=$hoy&hasta=$hoy",
            'Indicadores dashboard' => "$base/api/supervisiones.php?action=indicadores_dashboard&fecha_inicio=$hoy&fecha_fin=$hoy",
        ];
        foreach ($pantallas as $p => $url) {
            $cuerpo = $get($url);
            if ($p === 'Dashboard de partners') {
                $j = json_decode($cuerpo, true);
                if ($u === 'ent.lider') $check("$nombre · $p: cuenta solo la observación de su entorno", ($j['data']['observaciones']['resumen']['total'] ?? null) === 1, $j['data']['observaciones']['resumen'] ?? $j);
                continue;
            }
            preg_match_all('/PRUEBA-ENT-([FP]-[A-Z]+-[A-Z]+)/', $cuerpo, $m);
            $vistos = array_values(array_unique($m[1]));
            $fuga = array_values(array_filter($vistos, fn($c) => !str_ends_with($c, 'AQP-INS')));
            $check("$nombre · $p: no ve otra zona ni otra área", !$fuga, $fuga);
            if ($u === 'ent.lider' && !in_array($p, ['Mapa de pendientes'], true)) $check("$nombre · $p: ve lo de su entorno", in_array('F-AQP-INS', $vistos, true) || in_array('P-AQP-INS', $vistos, true), $vistos);
        }
    }
} finally { $limpiar(); }
echo "\n$ok correctas, $fail con error. Usuarios y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
