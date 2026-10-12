<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/uploads.php';
require_once __DIR__ . '/../../config/asignacion_cancelaciones.php';

Auth::requireLogin();
Auth::requireCsrf();
if (!Auth::can(Auth::PERM_SUPERVISION_IMPORT)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Solo el administrador puede cargar cancelaciones.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Seleccione el archivo Excel de cancelaciones.']);
    exit;
}

// Mayúsculas y sin tildes. No se usa iconv(ASCII//TRANSLIT): en Windows convierte "Ó" en "'O" y los
// encabezados con tilde (CÓDIGO, GESTIÓN) o zonas (ÁNCASH, JUNÍN) dejaban de reconocerse.
function normalizeImportText(string $value): string {
    $value = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    return strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U', 'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U']);
}

function readXlsxRows(string $path): array {
    $sharedXml = false;
    $sheetXml = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('El archivo Excel no se pudo abrir.');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
    } else {
        $archive = new PharData($path);
        if (isset($archive['xl/sharedStrings.xml'])) $sharedXml = $archive['xl/sharedStrings.xml']->getContent();
        if (isset($archive['xl/worksheets/sheet1.xml'])) $sheetXml = $archive['xl/worksheets/sheet1.xml']->getContent();
    }
    $shared = [];
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si as $item) {
            $texts = [];
            if (isset($item->t)) $texts[] = (string)$item->t;
            foreach ($item->r as $run) $texts[] = (string)$run->t;
            $shared[] = implode('', $texts);
        }
    }
    if ($sheetXml === false) throw new RuntimeException('No se encontró la hoja de datos en el archivo Excel.');
    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowNumber = (int)$row['r'];
        $values = array_fill(0, 20, '');
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^[A-Z]+/', $ref, $match);
            $letters = $match[0] ?? '';
            $column = 0;
            for ($i = 0; $i < strlen($letters); $i++) {
                $column = $column * 26 + (ord($letters[$i]) - 64);
            }
            $column--;
            if ($column < 0 || $column >= 20) continue;
            $type = (string)$cell['t'];
            if ($type === 's') $value = $shared[(int)$cell->v] ?? '';
            elseif ($type === 'inlineStr') $value = (string)$cell->is->t;
            else $value = (string)$cell->v;
            $values[$column] = trim((string)$value);
        }
        $rows[$rowNumber] = $values;
    }
    return $rows;
}

function excelDateToSql(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    
    if (is_numeric($value)) {
        $base = new DateTimeImmutable('1899-12-30');
        $days = (int)floor((float)$value);
        return $base->modify("+{$days} days")->format('Y-m-d');
    }
    
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $value, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year = $m[3];
        return "{$year}-{$month}-{$day}";
    }
    
    $time = strtotime(str_replace('/', '-', $value));
    return $time ? date('Y-m-d', $time) : null;
}

try {
    $validated = validateUploadedFile($_FILES['archivo'], [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/zip' => 'xlsx',
        'application/octet-stream' => 'xlsx'
    ], 10 * 1024 * 1024);

    $rows = readXlsxRows($_FILES['archivo']['tmp_name']);

    // Detect header row and column positions
    $columnKeywords = [
        'supervision_type' => ['PRODUCTO', 'GESTION O ACTIVIDAD SUPERVISADA', 'TIPO DE SUPERVISION', 'TIPO'],
        'ot_status' => ['ESTADO OT', 'ESTADO_OT', 'ESTADO'],
        'province' => ['LOCALIDAD', 'DISTRITO'],
        'department' => ['PROVINCIA', 'DEPARTAMENTO', 'REGION'],
        'district' => ['ZONA', 'DISTRITO', 'DIST'],
        'ticket' => ['CODISEGUICLIEN', 'TICKET', 'CODISEGUICLIEN/TICKET', 'CODIGO DE SEGUIMIENTO'],
        'cancel_date' => ['FECHAINIVISI', 'FECHA INI VISI', 'F.CANCELACION', 'FECHA CANCELACION'],
        'reason' => ['CANCELACIÓN', 'CANCELACION', 'MOTIVO CANCELACION', 'MOTIVO'],
        'coordinates' => ['GEOREFERENCIA', 'COORDENADAS', 'LATITUD/LONGITUD'],
        'partner' => ['PROVEEDEOR-', 'PROVEEDEOR', 'PROVEEDOR', 'PARTNER', 'CONTRATISTA', 'EMPRESA']
    ];

    // 1) Nombres exactos de encabezado (plantilla del sistema y exportación antigua). Evita confusiones como
    //    "FECHA CANCELACIÓN" tomada como motivo o "DISTRITO" tomada como provincia.
    $exactHeaders = [
        'ticket' => ['CODIGO DE SEGUIMIENTO / TICKET', 'CODIGO DE SEGUIMIENTO', 'CODISEGUICLIEN', 'CODISEGUICLIEN/TICKET', 'TICKET', 'CODIGO SEGUIMIENTO', 'CODIGO DE PEDIDO'],
        'partner' => ['PARTNER', 'PROVEEDEOR', 'PROVEEDEOR-', 'PROVEEDOR', 'CONTRATISTA', 'EMPRESA'],
        'supervision_type' => ['GESTION O ACTIVIDAD SUPERVISADA', 'PRODUCTO', 'TIPO DE SUPERVISION', 'TIPO'],
        'ot_status' => ['ESTADO OT', 'ESTADO_OT', 'ESTADO'],
        'cancel_date' => ['FECHA CANCELACION', 'FECHAINIVISI', 'FECHA INI VISI', 'F.CANCELACION', 'FECHA DE CANCELACION'],
        'reason' => ['MOTIVO CANCELACION', 'MOTIVO DE CANCELACION', 'CANCELACION', 'MOTIVO'],
        'coordinates' => ['COORDENADAS DE VENTA', 'GEOREFERENCIA', 'COORDENADAS', 'LATITUD/LONGITUD'],
        'prior_observation' => ['OBSERVACION PREVIA', 'OBSERVACIONES', 'OBSERVACION'],
    ];
    $headerRowIdx = null;
    $columnMap = [];

    foreach ($rows as $rNum => $rowValues) {
        $normalizedRow = array_map('normalizeImportText', $rowValues);
        $tempMap = [];
        foreach ($exactHeaders as $key => $names) {
            foreach ($names as $name) {
                $col = array_search($name, $normalizedRow, true);
                if ($col !== false && !in_array($col, $tempMap, true)) { $tempMap[$key] = $col; break; }
            }
        }
        // Ubicación: plantilla del sistema = DEPARTAMENTO / PROVINCIA / DISTRITO;
        // exportación antigua = PROVINCIA (departamento) / LOCALIDAD (provincia) / ZONA (distrito).
        $colOf = static fn(string $n) => array_search($n, $normalizedRow, true);
        if ($colOf('DEPARTAMENTO') !== false) {
            $tempMap['department'] = $colOf('DEPARTAMENTO');
            if ($colOf('PROVINCIA') !== false) $tempMap['province'] = $colOf('PROVINCIA');
            if ($colOf('DISTRITO') !== false) $tempMap['district'] = $colOf('DISTRITO');
        } else {
            if ($colOf('PROVINCIA') !== false) $tempMap['department'] = $colOf('PROVINCIA');
            elseif ($colOf('REGION') !== false) $tempMap['department'] = $colOf('REGION');
            if ($colOf('LOCALIDAD') !== false) $tempMap['province'] = $colOf('LOCALIDAD');
            if ($colOf('ZONA') !== false) $tempMap['district'] = $colOf('ZONA');
            elseif ($colOf('DISTRITO') !== false) $tempMap['district'] = $colOf('DISTRITO');
        }
        // 2) Respaldo por coincidencia parcial, solo para lo que falte y sin repetir columnas.
        foreach ($normalizedRow as $colIdx => $cellText) {
            if ($cellText === '' || in_array($colIdx, $tempMap, true)) continue;
            foreach ($columnKeywords as $key => $keywords) {
                if (isset($tempMap[$key]) || in_array($key, ['province', 'department', 'district'], true)) continue;
                if ($key === 'reason' && str_contains($cellText, 'FECHA')) continue;
                foreach ($keywords as $kw) {
                    if (str_contains($cellText, normalizeImportText($kw))) { $tempMap[$key] = $colIdx; continue 3; }
                }
            }
        }

        if (isset($tempMap['ticket']) && isset($tempMap['partner']) && isset($tempMap['supervision_type'])) {
            $headerRowIdx = $rNum;
            $columnMap = $tempMap;
            break;
        }
    }

    if ($headerRowIdx === null) {
        throw new RuntimeException('No se pudieron identificar las columnas requeridas (CodiSeguiClien, Proveedeor, Producto) en el archivo Excel.');
    }

    $pdo = Database::getConnection();
    $user = Auth::getLoggedInUser();
    $errors = [];
    $created = 0;
    $avisosZona = [];

    $pdo->beginTransaction();

    $zoneMap = [
        'SANTA' => 'Chimbote',
        'CHIMBOTE' => 'Chimbote',
        'ANCASH' => 'Chimbote',
        'LAMBAYEQUE' => 'Chiclayo',
        'CHICLAYO' => 'Chiclayo',
        'LA LIBERTAD' => 'Trujillo',
        'TRUJILLO' => 'Trujillo',
        'AREQUIPA' => 'Arequipa',
        'PIURA' => 'Piura',
        'JUNIN' => 'Huancayo',
        'HUANCAYO' => 'Huancayo',
        'CUSCO' => 'Cusco',
        'LIMA' => 'Lima'
    ];

    foreach ($rows as $rowNumber => $row) {
        if ($rowNumber <= $headerRowIdx || !array_filter($row, fn($v) => trim((string)$v) !== '')) continue;

        // Algunas plantillas omiten columnas opcionales. Resolver primero el índice
        // evita avisos de PHP sin confundir una columna ausente con una celda vacía.
        $cell = static fn(string $key): string => trim((string)($row[$columnMap[$key] ?? -1] ?? ''));
        $dateRaw = $cell('cancel_date');
        $otStatus = $cell('ot_status');
        $department = $cell('department');
        $province = $cell('province');
        $district = $cell('district');
        $ticket = $cell('ticket');
        $reason = $cell('reason');
        $coordinates = $cell('coordinates');
        $partnerName = $cell('partner');
        $supervisionTypeRaw = $cell('supervision_type');
        
        // La cuadrilla la completa el supervisor en campo (con DNI); la columna CUADRILLA del Excel es referencial.
        $crewRaw = '';
        $priorObservation = $cell('prior_observation'); // "OBSERVACIÓN PREVIA" de la plantilla (antes se descartaba)

        $rowErrors = [];

        if (empty($ticket)) $rowErrors[] = 'Ticket/Código vacío';
        if (empty($partnerName)) $rowErrors[] = 'Proveedor vacío';
        $normProduct = normalizeImportText($supervisionTypeRaw);
        if (strpos($normProduct, 'AVERIA') !== false || strpos($normProduct, 'AVERÍA') !== false) {
            $supervisionType = 'Avería';
        } elseif (strpos($normProduct, 'POST') !== false || strpos($normProduct, 'POSV') !== false) {
            $supervisionType = 'Postventa';
        } elseif (strpos($normProduct, 'REITERADA') !== false) {
            $supervisionType = 'Reiterada';
        } elseif (strpos($normProduct, 'GARANTIA') !== false || strpos($normProduct, 'GARANTÍA') !== false) {
            $supervisionType = 'Garantía';
        } elseif (strpos($normProduct, 'INSTAL') !== false || strpos($normProduct, 'SGI PROV') !== false || strpos($normProduct, 'FIBRA') !== false) {
            // 'INTERNET FIBRA OPTICA SGI PROV' o similar se considerará Instalación.
            $supervisionType = 'Instalación';
        } else {
            $supervisionType = 'Instalación'; // Default por si viene algo totalmente distinto
        }

        $cancelDate = excelDateToSql($dateRaw);
        if (!$cancelDate) $cancelDate = date('Y-m-d');

        // Partner lookup
        $partner = null;
        if (!empty($partnerName)) {
            $normPartner = normalizeImportText($partnerName);
            $stmtPartner = $pdo->prepare("SELECT id, nombre_partner FROM partners WHERE UPPER(TRIM(nombre_partner)) = :nombre1 OR UPPER(TRIM(nombre_partner)) LIKE :like_nombre ORDER BY id DESC LIMIT 1");
            $stmtPartner->execute([':nombre1' => $normPartner, ':like_nombre' => "%{$normPartner}%"]);
            $partner = $stmtPartner->fetch(PDO::FETCH_ASSOC);

            if (!$partner) {
                // Si el proveedor no existe, podrías asignarlo a WIN Directo o reportar error. Dejamos el error original.
                $rowErrors[] = "Proveedor '$partnerName' no reconocido en el sistema";
            }
        }

        // Technician lookup skipped since it will be filled by the supervisor later
        $techName = null;
        $techDni = null;

        // Zone Auto-Recognition
        $normProv = normalizeImportText($province);
        $normDist = normalizeImportText($district);
        $normDep = normalizeImportText($department);

        $zone = null;
        if (isset($zoneMap[$normProv])) $zone = $zoneMap[$normProv];
        elseif (isset($zoneMap[$normDist])) $zone = $zoneMap[$normDist];
        elseif (isset($zoneMap[$normDep])) $zone = $zoneMap[$normDep];
        else {
            $zone = !empty($province) ? ucfirst(strtolower($province)) : (!empty($department) ? ucfirst(strtolower($department)) : 'Provincias');
        }

        // Coordinates parsing
        $lat = null;
        $lng = null;
        if (!empty($coordinates)) {
            if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\/\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $coordinates, $coordMatch)) {
                $lat = $coordMatch[1];
                $lng = $coordMatch[2];
            }
        }

        // Check for existing duplicate ticket
        if (!empty($ticket)) {
            $stmtDuplicate = $pdo->prepare("SELECT 1 FROM supervisiones WHERE ticket_codigo = :ticket LIMIT 1");
            $stmtDuplicate->execute([':ticket' => $ticket]);
            if ($stmtDuplicate->fetchColumn()) {
                $rowErrors[] = "El ticket '$ticket' ya fue registrado anteriormente.";
            }
        }

        $assignedSupervisor = null;
        if (!$rowErrors) {
            $assignedSupervisor = elegirSupervisorCancelacion($pdo, $department);
            if (!$assignedSupervisor) $rowErrors[] = "No hay supervisor directo activo para la zona {$zone}.";
        }
        if (!empty($rowErrors)) {
            $errors[] = [
                'fila' => $rowNumber,
                'ticket' => $ticket ?: '—',
                'errores' => $rowErrors
            ];
            continue;
        }

        // La tipificación del Excel debe existir en el catálogo (clave foránea): si es nueva se registra y se usa
        // el texto exacto del catálogo (la comparación ignora mayúsculas y tildes por el cotejamiento de la tabla).
        $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$reason)), 0, 200);
        if ($reason === '') {
            $reason = null;
        } else {
            $pdo->prepare('INSERT IGNORE INTO tipificaciones_cancelacion (descripcion, estado) VALUES (:d, 1)')->execute([':d' => $reason]);
            $stmtTip = $pdo->prepare('SELECT descripcion FROM tipificaciones_cancelacion WHERE descripcion = :d LIMIT 1');
            $stmtTip->execute([':d' => $reason]);
            $reason = $stmtTip->fetchColumn() ?: $reason;
        }

        $code = 'SUP-CAN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $stmtInsert = $pdo->prepare("INSERT INTO supervisiones (
            codigo_supervision, fecha_registro, supervisor_id, asignado_por, zona_asignacion,
            derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            cliente_nombre, cliente_dni, ticket_codigo, fecha_cancelacion, tipo_supervision_inicial,
            partner_id, tecnico1_nombre, tecnico1_dni, tecnico2_nombre, tecnico2_dni,
            brevete_poseedor, unidad_tipo, unidad_placa, unidad_estado_conservacion,
            clasificacion_supervision, area_servicio, sub_tipo_atencion, observacion_servicio,
            estado_servicio_caliente, es_cancelacion, tipificacion_cancelacion,
            origen_importacion, observacion_previa, estado_cancelacion,
            tipificacion_tecnico_correcta, tipificacion_corregida_supervisor, estado_supervision,
            latitud, longitud
        ) VALUES (
            :code, NOW(), :uploader, :assigned_by, :zone,
            'Líder', 'Regular', :dep, :prov, :dist,
            NULL, NULL, :ticket, :cancel_date, 'En frío',
            :partner, NULL, NULL, NULL, NULL,
            'Ninguno', NULL, NULL, NULL,
            :supervision_type1, :supervision_type2, NULL, '',
            'Cancelado', 1, :reason,
            1, :prior_observation, NULL,
            NULL, NULL, 'Pendiente',
            :lat, :lng
        )");

        $stmtInsert->execute([
            ':code' => $code,
            ':uploader' => $assignedSupervisor['id'],
            ':assigned_by' => $user['id'],
            ':zone' => $zone,
            ':dep' => $department,
            ':prov' => $province,
            ':dist' => $district,
            ':ticket' => $ticket,
            ':cancel_date' => $cancelDate,
            ':partner' => $partner['id'],
            ':reason' => $reason,
            ':supervision_type1' => $supervisionType,
            ':supervision_type2' => $supervisionType,
            ':prior_observation' => $priorObservation !== '' ? $priorObservation : null,
            ':lat' => $lat,
            ':lng' => $lng
        ]);

        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE supervisiones SET fecha_asignacion=NOW() WHERE id=:id')->execute([':id'=>$newId]);
        // Bolsa común (2026-10-11): la pendiente no se reparte; se avisa a todos los supervisores de la zona
        // y el primero que la inicia se queda con ella.
        foreach (supervisoresZonaCancelacion($pdo, $department) as $supZona) {
            $avisosZona[(int)$supZona['id']][$zone] = ($avisosZona[(int)$supZona['id']][$zone] ?? 0) + 1;
        }
        $created++;
    }

    if ($created === 0) {
        $pdo->rollBack();
        echo json_encode([
            'status' => 'error',
            'message' => 'No se importó ninguna fila. Revise las observaciones indicadas.',
            'creadas' => 0,
            'observadas' => $errors
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $avisoZona = $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:usuario, 'Nuevas cancelaciones pendientes en tu zona', :mensaje, 'index.php?page=supervision_lista')");
    foreach ($avisosZona as $supId => $porZona) {
        $partes = [];
        foreach ($porZona as $z => $total) $partes[] = "{$total} en {$z}";
        $avisoZona->execute([':usuario' => $supId, ':mensaje' => 'Se cargaron cancelaciones pendientes: ' . implode(', ', $partes) . '. Están visibles para todos los supervisores de la zona; la primera persona que la inicia se queda con la orden.']);
    }

    $notify = $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:usuario, 'Supervisiones pendientes cargadas', :mensaje, 'index.php?page=supervision_lista')");
    $notify->execute([
        ':usuario' => (int)$user['id'],
        ':mensaje' => "Se cargaron {$created} cancelaciones pendientes para validación de supervisores."
    ]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => "Se cargaron exitosamente {$created} cancelaciones pendientes.",
        'creadas' => $created,
        'observadas' => $errors
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
