<?php
// 05_TEST/integration/zonas_lima_areas_test.php - Zonas de Lima, Callao y Norte Chico y área de trabajo (2026-10-27).
// La zona sale de la ubicación oficial (LIMA / HUAURA → NORTE CHICO) y el área de trabajo del usuario (Instalaciones /
// VT) decide qué pendientes de la bolsa ve cada supervisor. Por HTTP contra la API real.
// Crea un administrador (DNI 99999960), dos supervisores de LIMA (Instalaciones 99999961, VT 99999962), uno del
// NORTE CHICO (99999963, ambas áreas) y las pendientes PRUEBA-ZLM-1..4; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/zonas_lima_areas_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/config/asignacion_cancelaciones.php';
require BACKEND_PATH . '/config/zonas_peru.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$usuarios = ['99999960' => ['zlm.admin', 'Administrador', ['LIMA'], ''], '99999961' => ['zlm.inst', 'Supervisor', ['LIMA'], 'Instalaciones'],
    '99999962' => ['zlm.ave', 'Supervisor', ['LIMA'], 'VT (visitas técnicas)'], '99999963' => ['zlm.norte', 'Supervisor', ['NORTE CHICO'], 'Instalaciones y VT']];
$jars = [];
$cleanup = function () use ($pdo, $usuarios, &$jars) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-ZLM-%'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", array_keys($usuarios)) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach ($usuarios as [$u]) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'ZlmPrueba#' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Zonas de Lima, Callao y Norte Chico, y área de trabajo — $base\n";

try {
    // Regla de zona
    $check('zona: LIMA/LIMA → LIMA, LIMA/HUAURA, HUARAL y BARRANCA → NORTE CHICO, CALLAO → CALLAO, AREQUIPA igual',
        sgsZonaDeUbicacion('LIMA', 'LIMA') === 'LIMA' && sgsZonaDeUbicacion('Lima', 'Huaura') === 'NORTE CHICO' && sgsZonaDeUbicacion('LIMA', 'HUARAL') === 'NORTE CHICO'
        && sgsZonaDeUbicacion('LIMA', 'BARRANCA') === 'NORTE CHICO' && sgsZonaDeUbicacion('CALLAO', 'CALLAO') === 'CALLAO' && sgsZonaDeUbicacion('AREQUIPA', 'AREQUIPA') === 'AREQUIPA');
    $zonas = sgsOperationalDepartments();
    $check('las zonas de trabajo incluyen LIMA, CALLAO y NORTE CHICO (y siguen las de provincias)',
        !array_diff(['LIMA', 'CALLAO', 'NORTE CHICO', 'AREQUIPA', 'PIURA'], $zonas), $zonas);
    $check('la ubicación oficial se valida: LIMA / HUAURA / HUACHO existe; NORTE CHICO no es un departamento',
        sgsValidarUbicacion($pdo, 'LIMA', 'HUAURA', 'HUACHO')['error'] === '' && sgsValidarUbicacion($pdo, 'NORTE CHICO')['error'] !== '');

    $svc = new UsuarioService($pdo);
    $ids = [];
    foreach ($usuarios as $dni => [$usuario, $rol, $zonasU, $area]) {
        $ids[$usuario] = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Zona', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
            'rol' => $rol, 'organizacion' => 'directo', 'zonas' => $zonasU, 'area_trabajo' => $area, 'password' => $clave], 0, $admin)['id'];
    }
    $pdo->exec('UPDATE usuarios SET debe_cambiar_password = 0 WHERE id IN (' . implode(',', $ids) . ')');
    $check('el supervisor del Norte Chico queda con la zona NORTE CHICO (sede Huacho)', sgsUserZones($ids['zlm.norte']) === ['NORTE CHICO'], sgsUserZones($ids['zlm.norte']));

    // Pendientes en la bolsa: 1 Lima instalación, 2 Lima avería, 3 Huacho instalación, 4 Callao avería
    $ins = $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_servicio_caliente,
        es_cancelacion, origen_importacion, estado_supervision)
        VALUES (:c, NOW(), NOW(), :sup, :adm, 'Líder', 'Regular', :dep, :prov, :dist, :t, 'En frío', :p, 'Ninguno', :cl, :cl2, '', 'Cancelada', 1, 1, 'Pendiente')");
    $pend = [1 => ['LIMA', 'LIMA', 'COMAS', 'Instalación'], 2 => ['LIMA', 'LIMA', 'ATE', 'Avería'], 3 => ['LIMA', 'HUAURA', 'HUACHO', 'Instalación'], 4 => ['CALLAO', 'CALLAO', 'BELLAVISTA', 'Avería']];
    $idPend = [];
    foreach ($pend as $n => [$dep, $prov, $dist, $cl]) {
        $ins->execute([':c' => "SUP-ZLM-$n", ':sup' => $ids['zlm.inst'], ':adm' => $ids['zlm.admin'], ':dep' => $dep, ':prov' => $prov, ':dist' => $dist, ':t' => "PRUEBA-ZLM-$n", ':p' => $partner, ':cl' => $cl, ':cl2' => $cl]);
        $idPend[$n] = (int)$pdo->lastInsertId();
    }

    $sesion = function (string $u) use (&$jars, $base, $clave): callable {
        $jars[$u] = tempnam(sys_get_temp_dir(), 'sgsz');
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u], CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); curl_close($ch);
        return function (string $url) use ($jars, $u): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u], CURLOPT_TIMEOUT => 60]);
            $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            return [$code, json_decode($body, true) ?? []];
        };
    };
    $bolsa = function (callable $http) use ($base): array {
        [, $j] = $http("$base/api/supervisiones.php?action=list&estado=pendiente&fecha_inicio=2000-01-01&limit=100");
        $tickets = array_values(array_filter(array_column($j['data'] ?? [], 'ticket_codigo'), static fn($t) => str_starts_with((string)$t, 'PRUEBA-ZLM-')));
        sort($tickets);
        return $tickets;
    };
    $s = ['inst' => $sesion('zlm.inst'), 'ave' => $sesion('zlm.ave'), 'norte' => $sesion('zlm.norte'), 'admin' => $sesion('zlm.admin')];
    $check('Lima · Instalaciones ve solo la instalación de Lima (no la avería, ni Huacho, ni Callao)', $bolsa($s['inst']) === ['PRUEBA-ZLM-1'], $bolsa($s['inst']));
    $check('Lima · VT ve solo la avería de Lima', $bolsa($s['ave']) === ['PRUEBA-ZLM-2'], $bolsa($s['ave']));
    $check('Norte Chico ve solo la de Huacho (aunque su departamento sea LIMA)', $bolsa($s['norte']) === ['PRUEBA-ZLM-3'], $bolsa($s['norte']));
    $check('el administrador ve las cuatro', $bolsa($s['admin']) === ['PRUEBA-ZLM-1', 'PRUEBA-ZLM-2', 'PRUEBA-ZLM-3', 'PRUEBA-ZLM-4'], $bolsa($s['admin']));
    [$c1] = $s['inst']("$base/api/supervisiones.php?action=detail&id={$idPend[2]}");
    [$c2] = $s['inst']("$base/api/supervisiones.php?action=detail&id={$idPend[3]}");
    $check('tampoco puede abrir por enlace la avería de Lima ni la pendiente del Norte Chico', $c1 === 403 && $c2 === 403, [$c1, $c2]);

    // Formulario: el supervisor del Norte Chico elige LIMA y solo sus provincias
    [, $prov] = $s['norte']("$base/api/ubigeo.php?action=provincias&departamento=LIMA&zona=NORTE%20CHICO");
    $check('formulario del Norte Chico: en LIMA solo salen Barranca, Huaral y Huaura', ($prov['data'] ?? []) === ['BARRANCA', 'HUARAL', 'HUAURA'], $prov);

    // Carga de pendientes: se asignan y avisan por zona y área
    $pdo->beginTransaction();
    $dueñoAveria = elegirSupervisorCancelacion($pdo, 'LIMA', 'Avería');
    $dueñoHuacho = elegirSupervisorCancelacion($pdo, sgsZonaDeUbicacion('LIMA', 'HUAURA'), 'Instalación');
    $pdo->rollBack();
    $check('una avería de Lima queda con el supervisor de VT; una instalación de Huacho, con el del Norte Chico',
        (int)($dueñoAveria['id'] ?? 0) === $ids['zlm.ave'] && (int)($dueñoHuacho['id'] ?? 0) === $ids['zlm.norte'], [$dueñoAveria['id'] ?? null, $dueñoHuacho['id'] ?? null]);
    $avisados = array_map('intval', array_column(supervisoresZonaCancelacion($pdo, 'LIMA', false, 'Instalación'), 'id'));
    $check('el aviso de una instalación de Lima llega solo al de Instalaciones de Lima', $avisados === [$ids['zlm.inst']], $avisados);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y pendientes de prueba eliminados.\n";
exit($fail ? 1 : 0);
