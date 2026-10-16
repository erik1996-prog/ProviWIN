<?php
// 05_TEST/integration/vencimientos_test.php - (2026-10-15) Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor y brevete.
//   - reglas de estado (por vencer: 30 días SOAT/RT, 60 brevete, mitad de vigencia extintor; vencido desde el día siguiente);
//   - registrar fechas con foto obligatoria (HTTP), validaciones, zona del supervisor;
//   - Cuadrillas: documentos en la columna Vehículo y filtro por estado (con alerta, vencidos, por vencer, sin registrar);
//   - el vehículo cambia de cuadrilla y de zona: sus fechas lo acompañan y el historial guarda la zona anterior;
//   - documento vencido → observación automática de la supervisión, sin repetirse.
// Crea usuarios (DNI 99999928-29), técnicos (DNI 99999948-49), vehículo ZZ-9992 y una supervisión de prueba; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/vencimientos_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/vencimientos.php';
$pdo = Database::getConnection();

$clave = 'VencPrueba' . random_int(1000, 9999);
$jars = ['venc.admin' => tempnam(sys_get_temp_dir(), 'sgsc'), 'venc.sup' => tempnam(sys_get_temp_dir(), 'sgsc')];
$png = tempnam(sys_get_temp_dir(), 'sgsp') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$cleanup = function () use ($pdo, $jars, $png) {
    $fotos = $pdo->query("SELECT d.foto FROM documentos_vencimiento d LEFT JOIN vehiculos v ON v.id = d.vehiculo_id LEFT JOIN tecnicos t ON t.id = d.tecnico_id
        WHERE v.placa IN ('ZZ-9992','ZZ-9994') OR t.dni IN ('99999948','99999949')")->fetchAll(PDO::FETCH_COLUMN);
    $fotos = array_merge($fotos, $pdo->query("SELECT c.firma_supervisor FROM checklists_tecnicos c JOIN supervisiones s ON s.id = c.supervision_id
        WHERE s.codigo_supervision LIKE 'SUP-PRUEBA-VENC%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($fotos as $f) @unlink(SHARED_FILES_PATH . '/' . $f);
    $pdo->exec("DELETE FROM supervisiones WHERE codigo_supervision LIKE 'SUP-PRUEBA-VENC%'");
    $pdo->exec("UPDATE tecnicos SET vehiculo_id = NULL WHERE dni IN ('99999948','99999949')");
    $pdo->exec("DELETE FROM tecnicos WHERE dni IN ('99999948','99999949')");
    $pdo->exec("DELETE FROM vehiculos WHERE placa IN ('ZZ-9992','ZZ-9994')");
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999928','99999929')");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (array_keys($jars) as $u) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    echo "Reglas de estado\n";
    $e = fn($tipo, $ini, $ven, $hoy) => sgsEstadoVencimiento($tipo, $ini, $ven, $hoy)['estado'];
    $check('SOAT: a 40 días vigente, a 20 por vencer', $e('SOAT', '2026-01-10', '2027-01-10', '2026-12-01') === 'bueno' && $e('SOAT', '2026-01-10', '2027-01-10', '2026-12-21') === 'regular');
    $check('SOAT: el día del vencimiento aún sirve; al día siguiente, vencido', $e('SOAT', '2026-01-10', '2027-01-10', '2027-01-10') === 'regular' && $e('SOAT', '2026-01-10', '2027-01-10', '2027-01-11') === 'malo');
    $check('Brevete: a 50 días por vencer, a 70 vigente', $e('Brevete', '2022-03-01', '2027-03-01', '2027-01-10') === 'regular' && $e('Brevete', '2022-03-01', '2027-03-01', '2026-12-21') === 'bueno');
    $check('Extintor: por vencer pasada la mitad de su vigencia', $e('Extintor', '2026-01-01', '2027-01-01', '2026-06-01') === 'bueno' && $e('Extintor', '2026-01-01', '2027-01-01', '2026-08-01') === 'regular');
    $check('Sin fechas: sin registrar; en el checklist "No revisado"', $e('SOAT', null, null, '2026-10-15') === 'sin_registrar' && sgsChecklistDesdeVencimiento('sin_registrar') === 'No revisado' && sgsChecklistDesdeVencimiento('malo') === 'Malo');

    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $svc = new UsuarioService($pdo);
    $svc->guardar(['dni' => '99999928', 'nombres' => 'Venc', 'apellidos' => 'Admin Prueba', 'email' => 'venc.admin@proviwin.test', 'usuario' => 'venc.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $svc->guardar(['dni' => '99999929', 'nombres' => 'Venc', 'apellidos' => 'Sup Prueba', 'email' => 'venc.sup@proviwin.test', 'usuario' => 'venc.sup',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO vehiculos (placa, tipo) VALUES ('ZZ-9992', 'Auto')");
    $veh = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, provincia, departamento, vehiculo_id, fecha_registro)
        VALUES (:n, :d, 1, 'Habilitado', :p, :prov, :dep, :v, NOW())");
    $ins->execute([':n' => 'P 1 PRUEBAVENC SGI LIDER PIURA', ':d' => '99999948', ':p' => $partner, ':prov' => 'PIURA', ':dep' => 'PIURA', ':v' => $veh]);
    $lPiura = (int)$pdo->lastInsertId();
    $ins->execute([':n' => 'P 2 PRUEBAVENC SGI LIDER TRUJILLO', ':d' => '99999949', ':p' => $partner, ':prov' => 'TRUJILLO', ':dep' => 'LA LIBERTAD', ':v' => null]);
    $lTrujillo = (int)$pdo->lastInsertId();

    $csrf = [];
    $http = function (string $url, string $u, ?array $post = null) use ($jars, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u]];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($csrf[$u] ?? '')], CURLOPT_POSTFIELDS => $post];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // en PHP 8 las cookies se guardan al liberar el manejador
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code]];
    };
    foreach (array_keys($jars) as $u) {
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        [$pagina] = $http("$base/index.php?page=cuadrillas", $u);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
        $csrf[$u] = $m[1] ?? '';
    }
    $foto = fn() => new CURLFile($png, 'image/png', 'documento.png');
    $item = fn(array $j, string $tipo) => array_values(array_filter($j['data']['items'] ?? [], fn($i) => $i['tipo'] === $tipo))[0] ?? [];

    echo "Registrar fechas — $base\n";
    [, $j] = $http("$base/api/vencimientos.php?action=cuadrilla&lider_id=$lPiura", 'venc.sup');
    $check('la cuadrilla muestra los 4 documentos sin registrar', count($j['data']['items'] ?? []) === 4 && $item($j, 'SOAT')['estado'] === 'sin_registrar', $j);
    [, $j] = $http("$base/api/vencimientos.php?action=registrar", 'venc.sup', ['tipo' => 'SOAT', 'lider_id' => $lPiura, 'fecha_inicio' => date('Y-m-d', strtotime('-11 months')), 'fecha_vencimiento' => date('Y-m-d', strtotime('+20 days'))]);
    $check('sin foto no se registra', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'foto'), $j);
    [, $j] = $http("$base/api/vencimientos.php?action=registrar", 'venc.sup', ['tipo' => 'SOAT', 'lider_id' => $lPiura, 'fecha_inicio' => '2026-05-01', 'fecha_vencimiento' => '2026-04-01', 'foto' => $foto()]);
    $check('vencimiento anterior al inicio: rechazado', ($j['status'] ?? '') === 'error', $j);
    [, $j] = $http("$base/api/vencimientos.php?action=registrar", 'venc.sup', ['tipo' => 'SOAT', 'lider_id' => $lPiura, 'fecha_inicio' => date('Y-m-d', strtotime('-11 months')), 'fecha_vencimiento' => date('Y-m-d', strtotime('+20 days')), 'foto' => $foto()]);
    $check('SOAT con foto: queda "por vencer"', ($j['status'] ?? '') === 'success' && $item($j, 'SOAT')['estado'] === 'regular' && str_starts_with((string)$item($j, 'SOAT')['foto'], 'uploads/'), $j);
    [, $j] = $http("$base/api/vencimientos.php?action=registrar", 'venc.admin', ['tipo' => 'Brevete', 'lider_id' => $lPiura, 'fecha_inicio' => '2021-01-01', 'fecha_vencimiento' => date('Y-m-d', strtotime('-3 days')), 'foto' => $foto()]);
    $check('brevete vencido hace 3 días: "malo"', ($j['status'] ?? '') === 'success' && $item($j, 'Brevete')['estado'] === 'malo' && $item($j, 'Brevete')['dias'] === -3, $item($j, 'Brevete'));
    [, $j] = $http("$base/api/vencimientos.php?action=registrar", 'venc.sup', ['tipo' => 'Extintor', 'lider_id' => $lTrujillo, 'fecha_inicio' => '2026-01-01', 'fecha_vencimiento' => '2027-01-01', 'foto' => $foto()]);
    $check('supervisor de PIURA: no actualiza una cuadrilla de LA LIBERTAD', ($j['_http'] ?? 0) === 403, $j);

    echo "Cuadrillas: documentos en la columna Vehículo y filtro\n";
    $pagina = fn(string $u, string $docs = '') => $http("$base/index.php?page=cuadrillas&operativo=todos&q=PRUEBAVENC" . ($docs ? "&docs=$docs" : ''), $u)[0];
    $p = $pagina('venc.sup');
    $check('la fila muestra los documentos con su color (brevete vencido, SOAT por vencer)', preg_match('/venc-chip--malo"[^>]*title="Brevete/', $p) && preg_match('/venc-chip--regular"[^>]*title="SOAT/', $p));
    $check('filtro "Vencidos": aparece la cuadrilla de PIURA', str_contains($pagina('venc.sup', 'malo'), 'P 1 PRUEBAVENC SGI LIDER PIURA'));
    $check('filtro "Con alerta": también; la de otra zona no se ve', str_contains($pagina('venc.sup', 'alerta'), 'P 1 PRUEBAVENC SGI LIDER PIURA') && !str_contains($pagina('venc.sup', 'alerta'), 'LIDER TRUJILLO'));
    $check('filtro "Vencidos" (administración): no aparece la cuadrilla que no tiene vencidos', !str_contains($pagina('venc.admin', 'malo'), 'LIDER TRUJILLO') && str_contains($pagina('venc.admin', 'sin_registrar'), 'LIDER TRUJILLO'));

    echo "El vehículo cambia de cuadrilla y de zona\n";
    $pdo->exec("UPDATE tecnicos SET vehiculo_id = NULL WHERE id = $lPiura");
    $pdo->exec("UPDATE tecnicos SET vehiculo_id = $veh WHERE id = $lTrujillo");
    $check('el supervisor de PIURA ya no ve el vehículo', !str_contains($pagina('venc.sup'), 'ZZ-9992'));
    [, $j] = $http("$base/api/vencimientos.php?action=cuadrilla&lider_id=$lTrujillo", 'venc.admin');
    $soat = $item($j, 'SOAT');
    $check('las fechas del SOAT se fueron con la placa a la nueva cuadrilla', ($soat['estado'] ?? '') === 'regular' && ($soat['historial'][0]['departamento'] ?? '') === 'PIURA', $soat);
    $check('el brevete se quedó con su técnico', $item($j, 'Brevete')['estado'] === 'sin_registrar');

    echo "Observación automática en la supervisión\n";
    $sup = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999929'")->fetchColumn();
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='PIURA' LIMIT 1")->fetch();
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision,
        latitud, longitud, foto_servicio_1, tecnico1_id, vehiculo_id) VALUES ('SUP-PRUEBA-VENC', NOW(), :s, 'Líder', 'Regular', :d, :p, :di, 'PRUEBA-VENC', 'En caliente', :pa, 'Ninguno',
        'Instalación', 'Instalación', 'Prueba', 'En proceso', '-5.19', '-80.63', 'uploads/prueba.jpg', :t, NULL)")
        ->execute([':s' => $sup, ':d' => $ubi['departamento'], ':p' => $ubi['provincia'], ':di' => $ubi['distrito'], ':pa' => $partner, ':t' => $lPiura]);
    $supId = (int)$pdo->lastInsertId();
    $n1 = sgsObservacionesPorVencimiento($pdo, $supId, $partner, $lPiura, null, $sup);
    $n2 = sgsObservacionesPorVencimiento($pdo, $supId, $partner, $lPiura, null, $sup);
    $obs = $pdo->query("SELECT o.gravedad, o.estado_respuesta, o.detalle_observacion, s.descripcion_subopcion FROM observaciones_supervision o
        JOIN subopciones_observacion s ON s.id = o.subopcion_id WHERE o.supervision_id = $supId")->fetchAll(PDO::FETCH_ASSOC);
    $check('brevete vencido → observación con la falta de licencia de conducir', $n1 === 1 && count($obs) === 1 && str_starts_with($obs[0]['descripcion_subopcion'], 'NO PORTAR LA LICENCIA')
        && str_starts_with($obs[0]['detalle_observacion'], 'Automática:'), $obs);
    $check('muy grave → penalidad directa, como las demás observaciones', ($obs[0]['estado_respuesta'] ?? '') === 'Penalidad Directa', $obs);
    $check('no se repite al volver a guardar', $n2 === 0);

    echo "Fechas registradas en el checklist: pendientes hasta finalizar\n";
    // Brevete renovado en la visita (supervisión en proceso): pendiente, todavía no cambia la cuadrilla.
    $pdo->prepare("INSERT INTO documentos_vencimiento (tipo, tecnico_id, fecha_inicio, fecha_vencimiento, foto, origen, supervision_id, aplicado)
        VALUES ('Brevete', :t, :i, :f, 'uploads/observaciones/prueba_venc.png', 'Supervisión', :s, 0)")
        ->execute([':t' => $lPiura, ':i' => date('Y-m-d', strtotime('-1 day')), ':f' => date('Y-m-d', strtotime('+5 years')), ':s' => $supId]);
    $brev = fn(?int $s) => array_values(array_filter(sgsVencimientosCuadrilla($pdo, $lPiura, null, false, $s), fn($i) => $i['tipo'] === 'Brevete'))[0];
    $check('pendiente: la cuadrilla sigue con el brevete vencido', $brev(null)['estado'] === 'malo');
    $check('pendiente: dentro de su supervisión ya cuenta el brevete renovado', $brev($supId)['estado'] === 'bueno' && $brev($supId)['pendiente'] === true, $brev($supId));
    $pdo->exec("DELETE FROM observaciones_supervision WHERE supervision_id = $supId");
    $check('con el brevete renovado en la visita no hay observación de licencia', sgsObservacionesPorVencimiento($pdo, $supId, $partner, $lPiura, null, $sup) === 0);
    $check('al finalizar se asigna a la cuadrilla', sgsAplicarPendientesSupervision($pdo, $supId, $lPiura, null) === 1 && $brev(null)['estado'] === 'bueno');

    echo "Registrar en el checklist y finalizar (HTTP, placa nueva)\n";
    // Firma del checklist: una imagen PNG real de más de 200 bytes.
    $pngFirma = static function (): string {
        $raw = '';
        for ($y = 0; $y < 24; $y++) $raw .= "\0" . random_bytes(24 * 3);
        $chunk = static fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 24, 24, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
    };
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision,
        latitud, longitud, foto_servicio_1) VALUES ('SUP-PRUEBA-VENC2', NOW(), :s, 'Líder', 'Regular', :d, :p, :di, 'PRUEBA-VENC2', 'En caliente', :pa, 'Tecnico 1',
        'Instalación', 'Instalación', 'Prueba', 'Finalizada', '-5.19', '-80.63', 'uploads/prueba.jpg')")
        ->execute([':s' => $sup, ':d' => $ubi['departamento'], ':p' => $ubi['provincia'], ':di' => $ubi['distrito'], ':pa' => $partner]);
    $sup2 = (int)$pdo->lastInsertId();
    $soatIni = date('Y-m-d', strtotime('-1 month')); $soatFin = date('Y-m-d', strtotime('+11 months'));
    [, $j] = $http("$base/api/supervisiones.php?action=create", 'venc.admin', [
        'supervision_id_edit' => $sup2, 'estado_supervision' => 'Finalizada', 'tipo_supervision' => 'Instalación', 'tipo_supervision_inicial' => 'En caliente',
        'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'], 'distrito' => $ubi['distrito'],
        'ticket_codigo' => 'PRUEBA-VENC2', 'partner_id' => $partner, 'tecnico1_id' => $lPiura, 'tecnico1_nombre' => 'P 1 PRUEBAVENC SGI LIDER PIURA', 'tecnico1_dni' => '99999948',
        'brevete_poseedor' => 'Tecnico 1', 'unidad_tipo' => 'Auto', 'unidad_placa' => 'ZZ-9994', 'unidad_estado_conservacion' => 'Buen estado', 'unidad_soat' => '', 'unidad_revision_tecnica' => '',
        'observacion_servicio' => 'Prueba de vencimientos en el checklist.', 'latitud' => '-5.19', 'longitud' => '-80.63',
        'chk_incluir_checklist' => '1', 'checklist_criterio[0]' => 'SOAT', 'checklist_estado[0]' => 'No revisado', 'checklist_observacion[0]' => '',
        'checklist_criterio[1]' => 'Logos de WIN', 'checklist_estado[1]' => 'Bueno', 'checklist_observacion[1]' => '',
        'checklist_observacion_general' => 'Checklist de prueba.', 'checklist_firma_data' => 'data:image/png;base64,' . base64_encode($pngFirma()),
        'venc_inicio[SOAT]' => $soatIni, 'venc_fin[SOAT]' => $soatFin, 'venc_foto[SOAT]' => $foto()]);
    $docSoat = $pdo->query("SELECT d.aplicado, d.supervision_id, d.origen FROM documentos_vencimiento d JOIN vehiculos v ON v.id = d.vehiculo_id
        WHERE v.placa = 'ZZ-9994' AND d.tipo = 'SOAT'")->fetch(PDO::FETCH_ASSOC);
    $check('el SOAT de la placa nueva se registró desde el checklist y quedó asignado', ($j['status'] ?? '') === 'success' && $docSoat
        && (int)$docSoat['aplicado'] === 1 && (int)$docSoat['supervision_id'] === $sup2 && $docSoat['origen'] === 'Supervisión', [$j['message'] ?? $j, $docSoat]);
    $respSoat = $pdo->query("SELECT r.estado FROM checklist_respuestas r JOIN checklists_tecnicos c ON c.id = r.checklist_id WHERE c.supervision_id = $sup2 AND r.criterio = 'SOAT'")->fetchColumn();
    $check('el checklist guardó el SOAT como "Bueno" (calculado por el servidor)', $respSoat === 'Bueno', $respSoat);
    [, $j] = $http("$base/api/vencimientos.php?action=cuadrilla&lider_id=$lPiura&placa=ZZ-9994", 'venc.admin');
    $check('en la siguiente supervisión con esa placa ya aparece su SOAT vigente', $item($j, 'SOAT')['estado'] === 'bueno', $item($j, 'SOAT'));
    $check('corregir una supervisión ya finalizada no cambia la cuadrilla actual', $pdo->query("SELECT vehiculo_id FROM tecnicos WHERE id = $lPiura")->fetchColumn() === null);
    [, $j] = $http("$base/api/supervisiones.php?action=create", 'venc.admin', [
        'supervision_id_edit' => $sup2, 'estado_supervision' => 'Finalizada', 'tipo_supervision' => 'Instalación', 'tipo_supervision_inicial' => 'En caliente',
        'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'], 'distrito' => $ubi['distrito'],
        'ticket_codigo' => 'PRUEBA-VENC2', 'partner_id' => $partner, 'tecnico1_id' => $lPiura, 'tecnico1_nombre' => 'P 1 PRUEBAVENC SGI LIDER PIURA', 'tecnico1_dni' => '99999948',
        'brevete_poseedor' => 'Tecnico 1', 'unidad_tipo' => 'Auto', 'unidad_placa' => 'ZZ-9994', 'unidad_estado_conservacion' => 'Buen estado', 'unidad_soat' => '', 'unidad_revision_tecnica' => '',
        'observacion_servicio' => 'Prueba de vencimientos en el checklist.', 'latitud' => '-5.19', 'longitud' => '-80.63',
        'chk_incluir_checklist' => '1', 'checklist_criterio[0]' => 'Extintor', 'checklist_estado[0]' => 'No revisado', 'checklist_observacion[0]' => '',
        'checklist_observacion_general' => 'Checklist de prueba.', 'checklist_firma_data' => 'data:image/png;base64,' . base64_encode($pngFirma()),
        'venc_inicio[Extintor]' => '2026-01-01', 'venc_fin[Extintor]' => '2027-01-01']);
    $check('fechas sin foto en el checklist: se rechaza con el nombre del documento', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'Extintor'), $j);
} finally {
    $cleanup();
    @unlink($png);
}
echo "\n$ok correctas, $fail con error. Usuarios, técnicos, vehículo, supervisión y fotos de prueba eliminados.\n";
exit($fail ? 1 : 0);
