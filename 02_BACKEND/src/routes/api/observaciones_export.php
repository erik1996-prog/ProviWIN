<?php
// 02_BACKEND/src/routes/api/observaciones_export.php - Relación de observaciones filtrada, en Excel (.xlsx) o PDF.
// GET ?formato=xlsx|pdf + los mismos filtros de Registro de observaciones (fechas, partner, cuadrilla, departamento, estado, tipo).
// Cada ítem observado es una fila (un checklist con 3 criterios observados = 3 filas). Mismo alcance que el listado.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/observation_records.php';
require_once __DIR__ . '/../../utils/XlsxWriter.php';
Auth::requireLogin();
$user = Auth::getLoggedInUser();
if (empty($user['is_win_directo'])) { http_response_code(403); exit('La descarga de observaciones está disponible solo para el personal WIN.'); }
$formato = ($_GET['formato'] ?? 'xlsx') === 'pdf' ? 'pdf' : 'xlsx';
$pdo = Database::getConnection();

// Mismas restricciones de filtros que la pantalla
$filters = observationRegisterFilters($_GET);
if (!Auth::can(Auth::PERM_OBSERVATION_FILTER_DATE, $user)) { $filters['fecha_desde'] = ''; $filters['fecha_hasta'] = ''; }
if (!Auth::can(Auth::PERM_OBSERVATION_FILTER_ADVANCED, $user)) { $filters['partner_id'] = 0; $filters['cuadrilla'] = ''; $filters['departamento'] = ''; $filters['estado'] = ''; }

$registros = fetchObservationRecords($pdo, $user, $filters);
$limite = $formato === 'pdf' ? 3000 : 50000;
if (count($registros) > $limite) {
    http_response_code(422); header('Content-Type: text/plain; charset=utf-8');
    exit('Hay ' . count($registros) . ' observaciones con estos filtros. Acota el rango de fechas (máximo ' . number_format($limite, 0, ',', '.') . ' para ' . strtoupper($formato) . ').');
}

function sgsObsResultado(string $estado): string
{
    return match ($estado) {
        'Validado', 'Levantamiento validado', 'Apelación Aprobada' => 'Aceptado',
        'Penalizado', 'Levantamiento rechazado', 'Penalidad Directa', 'Apelación Rechazada' => 'Penalizado',
        'En revisión' => 'En revisión',
        'Reabierto' => 'Reabierto',
        default => 'Pendiente',
    };
}
$fecha = static fn($v, string $fmt = 'd/m/Y H:i') => $v ? date($fmt, strtotime((string)$v)) : '';

$filas = []; $resumen = [];
foreach ($registros as $r) {
    $estado = (string)($r['estado_respuesta'] ?: 'Pendiente');
    $resultado = sgsObsResultado($estado);
    $resumen[$resultado] = ($resumen[$resultado] ?? 0) + 1;
    $filas[] = [
        'fecha' => $fecha($r['fecha_registro']),
        'tipo' => $r['tipo_registro'] === 'Checklist' ? 'Checklist' : 'Campo',
        'pedido' => trim((string)$r['ticket_codigo']) !== '' ? $r['ticket_codigo'] : '',
        'codigo' => (string)$r['codigo_supervision'],
        'partner' => (string)($r['nombre_partner'] ?? ''),
        'cuadrilla' => (string)($r['cuadrilla'] ?? ''),
        'departamento' => (string)($r['departamento'] ?? ''),
        'provincia' => (string)($r['provincia'] ?? ''),
        'distrito' => (string)($r['distrito'] ?? ''),
        'supervisor' => (string)($r['supervisor_nombre'] ?? ''),
        'observacion' => (string)($r['titulo'] ?? ''),
        'detalle' => trim((string)($r['comentario'] ?? '')),
        'gravedad' => (string)($r['estado_item'] ?? ''),
        'plazo' => $fecha($r['fecha_limite_respuesta']),
        'estado' => $estado,
        'resultado' => $resultado,
        'f_respuesta' => $fecha($r['fecha_respuesta_partner']),
        'respuesta' => trim((string)($r['respuesta_partner'] ?? '')),
        'evaluacion' => trim((string)($r['sustentacion_evaluacion'] ?? '')),
    ];
}
$orden = ['Penalizado', 'Pendiente', 'En revisión', 'Reabierto', 'Aceptado'];
uksort($resumen, static fn($a, $b) => array_search($a, $orden, true) <=> array_search($b, $orden, true));

// Texto de filtros para el encabezado del PDF
$estadoTexto = ['Pendiente' => 'Pendientes', 'En revisión' => 'En revisión', 'Validado' => 'Aceptados', 'Penalizado' => 'Penalizados', 'Reabierto' => 'Reabiertos'];
$textoFiltros = [];
if ($filters['fecha_desde'] || $filters['fecha_hasta']) {
    $textoFiltros[] = 'Fechas: ' . ($filters['fecha_desde'] ? $fecha($filters['fecha_desde'], 'd/m/Y') : 'inicio') . ' al ' . ($filters['fecha_hasta'] ? $fecha($filters['fecha_hasta'], 'd/m/Y') : 'hoy');
}
if ($filters['tipo_registro']) $textoFiltros[] = 'Tipo: ' . ($filters['tipo_registro'] === 'Checklist' ? 'Checklist' : 'Observaciones en campo');
if ($filters['estado']) $textoFiltros[] = 'Estado: ' . $estadoTexto[$filters['estado']];
if ($filters['partner_id']) {
    $st = $pdo->prepare('SELECT nombre_partner FROM partners WHERE id = :id'); $st->execute([':id' => $filters['partner_id']]);
    $textoFiltros[] = 'Partner: ' . ($st->fetchColumn() ?: '#' . $filters['partner_id']);
}
if ($filters['departamento']) $textoFiltros[] = 'Departamento: ' . $filters['departamento'];
if ($filters['cuadrilla']) $textoFiltros[] = 'Cuadrilla: ' . $filters['cuadrilla'];
if ($filters['codigo']) $textoFiltros[] = 'Código: ' . $filters['codigo'];

$nombreBase = 'observaciones_' . ($filters['estado'] ? strtolower(str_replace(' ', '_', $estadoTexto[$filters['estado']])) . '_' : '') . date('Ymd_His');
win_log_error('audit_observations_export', 'Exportó la relación de observaciones', ['formato' => $formato, 'filas' => count($filas), 'filtros' => array_filter($filters)]);

if ($formato === 'xlsx') {
    $cols = ['Fecha de ingreso' => 16, 'Tipo' => 10, 'Pedido / ticket' => 16, 'Código de supervisión' => 18, 'Partner' => 24, 'Cuadrilla (T1)' => 28,
        'Departamento' => 15, 'Provincia' => 15, 'Distrito' => 18, 'Supervisor' => 26, 'Observación' => 36, 'Detalle' => 50, 'Gravedad / criterio' => 16,
        'Plazo para regularizar' => 18, 'Estado' => 20, 'Resultado' => 13, 'Fecha de respuesta' => 16, 'Respuesta del partner' => 45, 'Sustentación de la evaluación' => 45];
    $xlsx = XlsxWriter::build('Observaciones', array_keys($cols), array_map('array_values', $filas), array_values($cols));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombreBase . '.xlsx"');
    header('Content-Length: ' . strlen($xlsx));
    echo $xlsx;
    exit;
}

// PDF: columnas esenciales en A4 horizontal (273 mm útiles)
$cols = ['Fecha' => 20, 'Tipo' => 15, 'Pedido / ticket' => 24, 'Partner' => 27, 'Cuadrilla' => 30, 'Zona' => 27, 'Supervisor' => 28, 'Observación' => 58, 'Plazo' => 20, 'Estado' => 24];
$payload = [
    'titulo' => 'Relación de observaciones' . ($filters['estado'] ? ' · ' . $estadoTexto[$filters['estado']] : ''),
    'filtros' => $textoFiltros,
    'generado' => date('d/m/Y H:i'),
    'usuario' => (string)($user['nombre'] ?: $user['usuario']),
    'columnas' => array_keys($cols),
    'anchos_mm' => array_values($cols),
    'resumen' => array_map(null, array_keys($resumen), array_values($resumen)),
    'filas' => array_map(static fn($f) => [
        $f['fecha'], $f['tipo'], $f['pedido'] ?: $f['codigo'], $f['partner'], $f['cuadrilla'],
        implode(' / ', array_filter([$f['departamento'], $f['provincia']])), $f['supervisor'],
        $f['observacion'] . ($f['detalle'] !== '' ? "\n" . mb_strimwidth($f['detalle'], 0, 220, '…') : ''),
        $f['plazo'], $f['estado'] === $f['resultado'] ? $f['estado'] : $f['estado'] . "\n(" . $f['resultado'] . ')',
    ], $filas),
];
$token = bin2hex(random_bytes(10));
$jsonPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sgs_obs_lista_' . $token . '.json';
$pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sgs_obs_lista_' . $token . '.pdf';
file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$python = SGS_PYTHON_BIN;
$script = BASE_PATH . DIRECTORY_SEPARATOR . '07_SCRIPTS' . DIRECTORY_SEPARATOR . 'render_observaciones_lista.py';
exec('"' . $python . '" "' . $script . '" "' . $jsonPath . '" "' . $pdfPath . '" 2>&1', $output, $code);
@unlink($jsonPath);
if ($code !== 0 || !is_file($pdfPath)) {
    win_log_error('pdf_generation_failed', 'No se pudo generar el PDF de la relación de observaciones', ['exit_code' => $code, 'output' => mb_substr(implode("\n", $output), 0, 4000)]);
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar el PDF. Código de seguimiento: ' . sgs_error_code(); @unlink($pdfPath); exit;
}
$pdf = file_get_contents($pdfPath); @unlink($pdfPath);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreBase . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
