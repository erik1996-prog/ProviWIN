<?php
// 05_TEST/integration/importar_cancelaciones_test.php - Carga de cancelaciones (por HTTP, como administrador):
// - un código ya supervisado (aunque venga en minúsculas, con espacios o como número de Excel) NO se ingresa y se lista aparte;
// - un código repetido en el mismo archivo se carga una sola vez;
// - el Excel puede traer muchas columnas (los datos clave más allá de la columna T) y tickets numéricos.
// Requiere el Python con openpyxl que usa el sistema para los PDF (SGS_PYTHON_BIN). Crea un administrador temporal
// (DNI 99999920), una supervisión ya hecha y las pendientes de la carga (PRUEBA-CAN-*); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/importar_cancelaciones_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'CargaPrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsi');
$xlsx = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prueba_cancelaciones_' . random_int(1000, 9999) . '.xlsx';
$maxNotif = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM notificaciones')->fetchColumn();
$cleanup = function () use ($pdo, $jar, $xlsx, &$maxNotif) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-CAN-%' OR ticket_codigo = '990000123'");
    $pdo->prepare('DELETE FROM notificaciones WHERE id > :m')->execute([':m' => $maxNotif]);
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999920'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|carga.prueba")]);
    @unlink($jar); @unlink($xlsx);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Carga de cancelaciones: ya supervisados, repetidos y Excel con muchas columnas — $base\n";

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    (new UsuarioService($pdo))->guardar(['dni' => '99999920', 'nombres' => 'Carga', 'apellidos' => 'Prueba SGS', 'email' => 'carga.prueba@proviwin.test', 'usuario' => 'carga.prueba',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $partner = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado=1 AND UPPER(nombre_partner) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetch();
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='PIURA' LIMIT 1")->fetch();
    // Una supervisión ya hecha con ese pedido
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision)
        VALUES ('SUP-PRUEBA-CAN', NOW(), :s, 'Líder', 'Regular', :d, :p, :di, 'PRUEBA-CAN-100', 'En caliente', :pa, 'Ninguno', 'Instalación', 'Instalación', '', 'Finalizada')")
        ->execute([':s' => $admin, ':d' => $ubi['departamento'], ':p' => $ubi['provincia'], ':di' => $ubi['distrito'], ':pa' => $partner['id']]);

    // Excel con 26 columnas: ticket en W, proveedor en Y (antes solo se leían A–T)
    $py = <<<'PY'
import sys, json
from openpyxl import Workbook
salida = sys.argv[1]
datos = json.load(open(sys.argv[2], encoding="utf-8"))
wb = Workbook(); ws = wb.active; ws.title = "Cancelaciones"
cab = [f"COLUMNA {i}" for i in range(1, 27)]
cab[0], cab[1], cab[2], cab[3], cab[4], cab[5], cab[22], cab[24], cab[25] = "DEPARTAMENTO", "PROVINCIA", "DISTRITO", "GESTION O ACTIVIDAD SUPERVISADA", "DIRECCIÓN", "CUADRILLA", "CODIGO DE SEGUIMIENTO / TICKET", "PARTNER", "FECHA CANCELACION"
ws.append(cab)
for f in datos:
    fila = [""] * 26
    fila[0], fila[1], fila[2], fila[3], fila[4], fila[5], fila[22], fila[24], fila[25] = f["dep"], f["prov"], f["dist"], "Instalación", f.get("dir", ""), f.get("cuad", ""), f["ticket"], f["partner"], "12/10/2026"
    ws.append(fila)
wb.save(salida)
PY;
    // Columna CUADRILLA (2026-10-16): un técnico líder del partner escrito con otra nomenclatura y en minúsculas, y uno que no existe.
    $liderCarga = $pdo->prepare("SELECT id, nombre_tecnico FROM tecnicos WHERE partner_id = :p AND COALESCE(tipo_tecnico, 1) = 1 AND nombre_tecnico REGEXP '^P [0-9]+ ' ORDER BY id LIMIT 1");
    $liderCarga->execute([':p' => $partner['id']]);
    $liderCarga = $liderCarga->fetch(PDO::FETCH_ASSOC) ?: null;
    $cuadOtraNomenclatura = $liderCarga ? mb_strtolower(preg_replace('/^P \d+ /', 'P 99 ', $liderCarga['nombre_tecnico']), 'UTF-8') : '';
    $filas = [
        ['ticket' => ' prueba-can-100 ', 'partner' => $partner['nombre_partner']],   // ya supervisado (minúsculas y espacios)
        ['ticket' => 'PRUEBA-CAN-200', 'partner' => $partner['nombre_partner'], 'dir' => 'Av.  Grau 123,  frente al parque', 'cuad' => $cuadOtraNomenclatura], // nuevo, con dirección y cuadrilla
        ['ticket' => 'PRUEBA-CAN-200', 'partner' => $partner['nombre_partner']],     // repetido en el archivo
        ['ticket' => 990000123, 'partner' => $partner['nombre_partner'], 'cuad' => 'P 1 PRUEBA SGI TECNICO QUE NO EXISTE'], // número de Excel, cuadrilla no registrada
        ['ticket' => 'PRUEBA-CAN-300', 'partner' => 'PROVEEDOR QUE NO EXISTE'],      // con problema
        // Ubicación que no existe en el catálogo (2026-10-26): no se carga y se dice qué no existe
        ['ticket' => 'PRUEBA-CAN-400', 'partner' => $partner['nombre_partner'], 'dep' => 'HUANCAYO', 'prov' => 'HUANCAYO', 'dist' => 'EL TAMBO'],
        ['ticket' => 'PRUEBA-CAN-500', 'partner' => $partner['nombre_partner'], 'dist' => 'DISTRITO INVENTADO'],
    ];
    foreach ($filas as &$f) $f += ['dep' => $ubi['departamento'], 'prov' => $ubi['provincia'], 'dist' => $ubi['distrito']];
    unset($f);
    $script = tempnam(sys_get_temp_dir(), 'sgsxl') . '.py';
    file_put_contents($script, $py);
    $datosJson = $script . '.json';
    file_put_contents($datosJson, json_encode($filas));
    exec('"' . SGS_PYTHON_BIN . '" -I ' . escapeshellarg($script) . ' ' . escapeshellarg($xlsx) . ' ' . escapeshellarg($datosJson) . ' 2>&1', $salidaPy, $codigo);
    @unlink($script); @unlink($datosJson);
    $check('se generó el Excel de prueba', $codigo === 0 && is_file($xlsx), $salidaPy);

    $http = function (string $url, array $opts = []) use ($jar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return [$body, json_decode($body, true)];
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'carga.prueba', 'password' => $clave])]);
    [$pagina] = $http("$base/index.php?page=supervision_lista");
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    [$cuerpo, $r] = $http("$base/api/cancelaciones_import.php", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['archivo' => new CURLFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'cancelaciones.xlsx')]]);

    $check('la respuesta es JSON limpio (sin avisos de PHP mezclados)', is_array($r), substr($cuerpo, 0, 300));
    $check('se cargaron 2 nuevas (la del archivo y la numérica)', ($r['creadas'] ?? -1) === 2, $r);
    $check('cada pendiente cargada guarda su fecha de carga (hoy)', (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE ticket_codigo = '990000123' AND DATE(fecha_carga) = CURRENT_DATE()")->fetchColumn() === 1);
    $ya = $r['ya_supervisados'] ?? [];
    $check('el código ya supervisado no se ingresó y aparece en su lista', count($ya) === 1 && $ya[0]['ticket'] === 'PRUEBA-CAN-100' && $ya[0]['supervision'] === 'SUP-PRUEBA-CAN', $ya);
    $check('el repetido del archivo se cargó una sola vez', count($r['repetidos_archivo'] ?? []) === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE ticket_codigo = 'PRUEBA-CAN-200'")->fetchColumn() === 1, $r['repetidos_archivo'] ?? null);
    $check('la dirección del Excel se guarda (sin espacios de más)', $pdo->query("SELECT direccion FROM supervisiones WHERE ticket_codigo = 'PRUEBA-CAN-200'")->fetchColumn() === 'Av. Grau 123, frente al parque');
    $tec200 = $pdo->query("SELECT tecnico1_id, tecnico1_nombre FROM supervisiones WHERE ticket_codigo = 'PRUEBA-CAN-200'")->fetch(PDO::FETCH_ASSOC);
    if ($liderCarga) $check('CUADRILLA: reconoce al técnico líder aunque cambie la nomenclatura (queda vinculado)', (int)$tec200['tecnico1_id'] === (int)$liderCarga['id'] && $tec200['tecnico1_nombre'] === $liderCarga['nombre_tecnico'], $tec200);
    $tecNum = $pdo->query("SELECT tecnico1_id, tecnico1_nombre FROM supervisiones WHERE ticket_codigo = '990000123'")->fetch(PDO::FETCH_ASSOC);
    $check('CUADRILLA no registrada: se carga con el nombre y se avisa', $tecNum['tecnico1_id'] === null && $tecNum['tecnico1_nombre'] === 'P 1 PRUEBA SGI TECNICO QUE NO EXISTE'
        && count($r['cuadrillas_no_registradas'] ?? []) === 1 && ($r['cuadrillas_no_registradas'][0]['ticket'] ?? '') === '990000123', [$tecNum, $r['cuadrillas_no_registradas'] ?? null]);
    $check('sin dirección queda vacía', $pdo->query("SELECT direccion FROM supervisiones WHERE ticket_codigo = '990000123'")->fetchColumn() === null);
    $check('el ticket numérico se guardó sin ".0"', (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE ticket_codigo = '990000123'")->fetchColumn() === 1);
    $obs = array_column($r['observadas'] ?? [], 'errores', 'ticket');
    $check('el proveedor inexistente queda en "filas con problemas"', isset($obs['PRUEBA-CAN-300']), $r['observadas'] ?? null);
    $check('ubicación inexistente: no se carga y se dice qué no existe (HUANCAYO no es departamento; distrito inventado)',
        str_contains(implode(' ', $obs['PRUEBA-CAN-400'] ?? []), 'HUANCAYO no es un departamento: es una provincia de JUNÍN')
        && str_contains(implode(' ', $obs['PRUEBA-CAN-500'] ?? []), 'El distrito DISTRITO INVENTADO no existe')
        && !$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE ticket_codigo IN ('PRUEBA-CAN-400', 'PRUEBA-CAN-500')")->fetchColumn(), $r['observadas'] ?? null);
    $check('sigue habiendo una sola supervisión con PRUEBA-CAN-100', (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE UPPER(TRIM(ticket_codigo)) = 'PRUEBA-CAN-100'")->fetchColumn() === 1);

    // La plantilla oficial que descarga el sistema (XML con prefijo x:) debe leerse: vacía, reconoce los encabezados
    [, $rp] = $http("$base/api/cancelaciones_import.php", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['archivo' => new CURLFile(dirname(__DIR__, 2) . '/03_FRONTEND/src/assets/templates/plantilla_cancelaciones_v5.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'plantilla.xlsx')]]);
    $check('la plantilla oficial se lee (encabezados reconocidos)', str_contains($rp['message'] ?? '', 'no tiene filas con datos'), $rp);

    // Volver a subir el mismo archivo: ya no hay nada nuevo
    [, $r2] = $http("$base/api/cancelaciones_import.php", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['archivo' => new CURLFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'cancelaciones.xlsx')]]);
    $check('al subirlo otra vez no ingresa nada: los 4 códigos ya están en Supervisiones', ($r2['creadas'] ?? -1) === 0 && count($r2['ya_supervisados'] ?? []) === 4, $r2);

    // Seguridad (2026-10-13): eliminar solo por POST y con token; un enlace (GET) o una petición sin token no borra nada.
    $idBorrar = (int)$pdo->query("SELECT id FROM supervisiones WHERE ticket_codigo = '990000123' LIMIT 1")->fetchColumn();
    $existe = fn() => (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE id = $idBorrar")->fetchColumn() === 1;
    [, $rg] = $http("$base/api/supervisiones.php?action=delete&id=$idBorrar");
    $check('eliminar por enlace (GET) se rechaza y la supervisión sigue', ($rg['status'] ?? '') === 'error' && $existe(), $rg);
    [, $rs] = $http("$base/api/supervisiones.php?action=delete", [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['id' => $idBorrar]]);
    $check('eliminar por POST sin token se rechaza y la supervisión sigue', str_contains($rs['message'] ?? '', 'CSRF') && $existe(), $rs);
    [, $rok] = $http("$base/api/supervisiones.php?action=delete", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')], CURLOPT_POSTFIELDS => ['id' => $idBorrar]]);
    $check('eliminar por POST con token sí funciona', ($rok['status'] ?? '') === 'success' && !$existe(), $rok);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Administrador, supervisiones y avisos de prueba eliminados.\n";
exit($fail ? 1 : 0);
