<?php
header('Content-Type: application/json; charset=utf-8');
// Cualquier aviso de PHP al leer el Excel no debe mezclarse con la respuesta (la pantalla dejaría de entenderla).
ob_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/uploads.php';
require_once __DIR__ . '/../../config/asignacion_cancelaciones.php';
require_once __DIR__ . '/../../config/excel_lector.php';
require_once __DIR__ . '/../../config/zonas_peru.php'; // sgsValidarUbicacion: la ubicación debe existir en el catálogo
require_once __DIR__ . '/../../config/tecnicos_lideres.php'; // sgsNombreLider / sgsPersonaLider: reconocer al técnico de la columna CUADRILLA

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

/**
 * Traduce un error inesperado de la carga a algo accionable (2026-10-12): qué pasó, en qué fila y qué hacer.
 * Devuelve ['que_paso' => …, 'que_hacer' => …]. Lo que no se reconoce queda como error interno con su código.
 */
function explicarErrorCarga(Throwable $e, ?int $fila): array {
    $msg = $e->getMessage();
    $codigo = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    $enFila = $fila ? " (fila {$fila} del Excel)" : '';
    $columna = preg_match("/column '([^']+)'/i", $msg, $m) ? $m[1] : '';
    $tabla = preg_match("/Table '[^.']*\.?([^']+)' doesn't exist/i", $msg, $m) ? $m[1] : '';
    $cambios = 'Aplique en la base del hosting los cambios pendientes de la carpeta 04_DATABASE/02_CAMBIOS (con migrar.php o ejecutando cada cambio.sql en phpMyAdmin) y vuelva a subir el archivo.';
    switch (true) {
        case $codigo === 1054:
            return ['que_paso' => "A la base del hosting le falta la columna «{$columna}» que usa el sistema.", 'que_hacer' => $cambios];
        case $codigo === 1146:
            return ['que_paso' => "A la base del hosting le falta la tabla «{$tabla}».", 'que_hacer' => $cambios];
        case in_array($codigo, [1265, 1366], true):
            return ['que_paso' => "Un valor del Excel no es válido para el dato «{$columna}»{$enFila}.",
                'que_hacer' => 'Revise ese dato en el Excel (por ejemplo, la fecha o la gestión). Si el valor es correcto, a la base le falta un cambio. ' . $cambios];
        case $codigo === 1406:
            return ['que_paso' => "Un dato del Excel es demasiado largo para «{$columna}»{$enFila}.", 'que_hacer' => 'Acorte ese dato en el Excel y vuelva a subir el archivo.'];
        case $codigo === 1452:
            $dato = str_contains($msg, 'tipificacion') ? 'la tipificación (motivo de cancelación)' : (str_contains($msg, 'partner') ? 'el partner' : (str_contains($msg, 'supervisor') ? 'el supervisor asignado' : 'un dato relacionado'));
            return ['que_paso' => "El Excel usa {$dato} que no existe en el sistema{$enFila}.",
                'que_hacer' => 'Corrija ese dato en el Excel para que coincida con el catálogo del sistema (partners, tipificaciones o usuarios) y vuelva a subir el archivo.'];
        case $codigo === 1062:
            return ['que_paso' => "Un código del Excel ya está registrado{$enFila}.", 'que_hacer' => 'Quite esa fila del Excel (ya está en Supervisiones) y vuelva a subir el archivo.'];
        case in_array($codigo, [2002, 2006, 2013, 1040, 1205, 1213], true):
            return ['que_paso' => 'La base de datos del hosting no respondió a tiempo o está saturada.', 'que_hacer' => 'Espere un minuto y vuelva a subir el archivo. Si se repite, revise en cPanel que MySQL esté funcionando.'];
        case $e instanceof Error && preg_match('/ZipArchive|PharData|Phar/i', $msg):
            return ['que_paso' => 'El servidor no puede abrir archivos Excel (le falta la extensión ZIP de PHP).',
                'que_hacer' => 'En cPanel, entre a "Seleccionar versión de PHP" → "Extensiones", active "zip" y vuelva a intentar.'];
        case $e instanceof Error && preg_match('/memory|Allowed memory/i', $msg):
            return ['que_paso' => 'El archivo es demasiado grande para la memoria del servidor.', 'que_hacer' => 'Divida el Excel en archivos más pequeños (por ejemplo, de 300 filas) y súbalos por separado.'];
    }
    return ['que_paso' => 'No se pudo procesar el archivo por un error interno' . $enFila . '.',
        'que_hacer' => 'Envíe al administrador del sistema el código de seguimiento y el detalle técnico que aparecen abajo.'];
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
        'partner' => ['PROVEEDEOR-', 'PROVEEDEOR', 'PROVEEDOR', 'PARTNER', 'CONTRATISTA', 'EMPRESA'],
        'address' => ['DIRECCION']
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
        // 2026-10-16: técnico líder que atendió la orden (columna CUADRILLA de la plantilla; opcional).
        'crew' => ['CUADRILLA', 'TECNICO LIDER', 'TECNICO', 'LIDER', 'TECNICO 1'],
        // 2026-10-15: dirección del cliente (opcional).
        'address' => ['DIRECCION', 'DIRECCION DEL CLIENTE', 'DIRECCION CLIENTE', 'DIRECCION DE INSTALACION', 'DIRECCION DE VENTA'],
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
    // 2026-10-12: un ticket que ya tiene supervisión no se vuelve a ingresar; se informa aparte (no es un error).
    $yaSupervisados = [];
    $repetidosArchivo = [];
    $ticketsVistos = [];
    $existentes = [];
    foreach ($pdo->query("SELECT ticket_codigo, codigo_supervision, estado_supervision, fecha_registro FROM supervisiones
        WHERE ticket_codigo IS NOT NULL AND TRIM(ticket_codigo) <> '' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $sup) {
        $clave = normalizarTicket((string)$sup['ticket_codigo']);
        if ($clave !== '' && !isset($existentes[$clave])) $existentes[$clave] = $sup;
    }

    // Técnicos líderes registrados (2026-10-16): la columna CUADRILLA se reconoce por el nombre exacto o, si cambió la
    // nomenclatura ("P 4 DOMINION SGI JUAN PÉREZ" ↔ "P 9 OLMA SGA JUAN PÉREZ"), por la persona; si hay varios, el del
    // mismo partner. Si no se reconoce, la pendiente se carga con el nombre tal cual y se avisa al final.
    $lideresCarga = $pdo->query("SELECT id, nombre_tecnico, dni, partner_id FROM tecnicos WHERE COALESCE(tipo_tecnico, 1) = 1")->fetchAll(PDO::FETCH_ASSOC);
    $liderPorNombre = []; $liderPorPersona = [];
    foreach ($lideresCarga as $l) {
        $liderPorNombre[sgsNombreLider($l['nombre_tecnico'])] = $l;
        $liderPorPersona[sgsPersonaLider($l['nombre_tecnico'])][] = $l;
    }
    $buscarLider = static function (string $texto, ?int $partnerId) use ($liderPorNombre, $liderPorPersona): ?array {
        $nombre = sgsNombreLider($texto);
        if ($nombre === '') return null;
        if (isset($liderPorNombre[$nombre])) return $liderPorNombre[$nombre];
        $candidatos = $liderPorPersona[sgsPersonaLider($nombre)] ?? [];
        if (count($candidatos) > 1 && $partnerId) $candidatos = array_values(array_filter($candidatos, static fn($l) => (int)$l['partner_id'] === $partnerId)) ?: $candidatos;
        return count($candidatos) === 1 ? $candidatos[0] : null;
    };
    $cuadrillasNoRegistradas = [];

    $pdo->beginTransaction();

    $filaActual = null;
    foreach ($rows as $rowNumber => $row) {
        $filaActual = $rowNumber;
        if ($rowNumber <= $headerRowIdx || !array_filter($row, fn($v) => trim((string)$v) !== '')) continue;

        // Algunas plantillas omiten columnas opcionales. Resolver primero el índice
        // evita avisos de PHP sin confundir una columna ausente con una celda vacía.
        $cell = static fn(string $key): string => trim((string)($row[$columnMap[$key] ?? -1] ?? ''));
        $dateRaw = $cell('cancel_date');
        $otStatus = $cell('ot_status');
        $department = $cell('department');
        $province = $cell('province');
        $district = $cell('district');
        $address = mb_substr(trim(preg_replace('/\s+/u', ' ', $cell('address'))), 0, 255);
        $ticket = normalizarTicket($cell('ticket'));
        $reason = $cell('reason');
        $coordinates = $cell('coordinates');
        $partnerName = $cell('partner');
        $supervisionTypeRaw = $cell('supervision_type');
        
        // Técnico líder que atendió la orden (columna CUADRILLA). Sus observaciones quedan vinculadas a esa cuadrilla.
        $crewRaw = mb_substr(trim(preg_replace('/\s+/u', ' ', $cell('crew'))), 0, 200);
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

        // Técnico líder de la columna CUADRILLA: el registrado (con su nombre actual) o, si no se reconoce, el texto tal cual.
        $lider = $crewRaw !== '' ? $buscarLider($crewRaw, $partner ? (int)$partner['id'] : null) : null;
        $techId = $lider ? (int)$lider['id'] : null;
        $techName = $lider ? $lider['nombre_tecnico'] : ($crewRaw !== '' ? sgsNombreLider($crewRaw) : null);
        $techDni = $lider && preg_match('/^\d{8}$/', (string)$lider['dni']) ? $lider['dni'] : null;

        // Ubicación (2026-10-26): debe existir en el catálogo de ubicaciones de trabajo; si no, la fila no se carga y se dice
        // qué no existe. Se guardan los nombres del catálogo (con tildes).
        $ubicacion = sgsValidarUbicacion($pdo, $department, $province, $district);
        if ($ubicacion['error'] !== '') $rowErrors[] = $ubicacion['error'];
        else [$department, $province, $district] = [$ubicacion['departamento'], $ubicacion['provincia'], $ubicacion['distrito']];

        $zone = zonaAsignacionCancelacion($department, $province, $district);

        // Coordinates parsing
        $lat = null;
        $lng = null;
        if (!empty($coordinates)) {
            if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\/\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $coordinates, $coordMatch)) {
                $lat = $coordMatch[1];
                $lng = $coordMatch[2];
            }
        }

        // Ticket ya supervisado: no se ingresa y va a la lista "ya supervisados"
        if ($ticket !== '' && isset($existentes[$ticket])) {
            $sup = $existentes[$ticket];
            $yaSupervisados[] = ['fila' => $rowNumber, 'ticket' => $ticket, 'supervision' => $sup['codigo_supervision'],
                'estado' => $sup['estado_supervision'], 'fecha' => $sup['fecha_registro'] ? date('d/m/Y', strtotime($sup['fecha_registro'])) : ''];
            continue;
        }
        // Mismo ticket dos veces en el archivo: se carga una sola vez
        if ($ticket !== '' && isset($ticketsVistos[$ticket])) {
            $repetidosArchivo[] = ['fila' => $rowNumber, 'ticket' => $ticket, 'fila_original' => $ticketsVistos[$ticket]];
            continue;
        }
        if ($ticket !== '') $ticketsVistos[$ticket] = $rowNumber;

        $assignedSupervisor = null;
        if (!$rowErrors) {
            $zonaTrabajo = sgsZonaDeUbicacion($department, $province); // LIMA / HUAURA → NORTE CHICO (2026-10-27)
            $assignedSupervisor = elegirSupervisorCancelacion($pdo, $zonaTrabajo, $supervisionType);
            if (!$assignedSupervisor) $rowErrors[] = "No hay supervisor directo activo para la zona {$zonaTrabajo}.";
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

        // estado_servicio_caliente solo acepta 'Finalizada' o 'Cancelada'. Antes se guardaba 'Cancelado': en el PC pasaba
        // (MySQL permisivo lo dejaba vacío) pero en el hosting, en modo estricto, la carga fallaba (2026-10-12).
        $stmtInsert = $pdo->prepare("INSERT INTO supervisiones (
            codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, zona_asignacion,
            derivacion_orden, tipo_servicio, departamento, provincia, distrito, direccion,
            cliente_nombre, cliente_dni, ticket_codigo, fecha_cancelacion, tipo_supervision_inicial,
            partner_id, tecnico1_id, tecnico1_nombre, tecnico1_dni, tecnico2_nombre, tecnico2_dni,
            brevete_poseedor, unidad_tipo, unidad_placa, unidad_estado_conservacion,
            clasificacion_supervision, area_servicio, sub_tipo_atencion, observacion_servicio,
            estado_servicio_caliente, es_cancelacion, tipificacion_cancelacion,
            origen_importacion, observacion_previa, estado_cancelacion,
            tipificacion_tecnico_correcta, tipificacion_corregida_supervisor, estado_supervision,
            latitud, longitud
        ) VALUES (
            :code, NOW(), NOW(), :uploader, :assigned_by, :zone,
            'Líder', 'Regular', :dep, :prov, :dist, :direccion,
            NULL, NULL, :ticket, :cancel_date, 'En frío',
            :partner, :tec1_id, :tec1_nombre, :tec1_dni, NULL, NULL,
            'Ninguno', NULL, NULL, NULL,
            :supervision_type1, :supervision_type2, NULL, '',
            'Cancelada', 1, :reason,
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
            ':direccion' => $address !== '' ? $address : null,
            ':ticket' => $ticket,
            ':cancel_date' => $cancelDate,
            ':partner' => $partner['id'],
            ':tec1_id' => $techId,
            ':tec1_nombre' => $techName,
            ':tec1_dni' => $techDni,
            ':reason' => $reason,
            ':supervision_type1' => $supervisionType,
            ':supervision_type2' => $supervisionType,
            ':prior_observation' => $priorObservation !== '' ? $priorObservation : null,
            ':lat' => $lat,
            ':lng' => $lng
        ]);

        $newId = (int)$pdo->lastInsertId();
        if ($crewRaw !== '' && !$techId) $cuadrillasNoRegistradas[] = ['fila' => $rowNumber, 'ticket' => $ticket, 'cuadrilla' => $techName];
        $pdo->prepare('UPDATE supervisiones SET fecha_asignacion=NOW() WHERE id=:id')->execute([':id'=>$newId]);
        // Bolsa común (2026-10-11): la pendiente no se reparte; se avisa a todos los supervisores de la zona
        // y el primero que la inicia se queda con ella.
        foreach (supervisoresZonaCancelacion($pdo, $zonaTrabajo, false, $supervisionType) as $supZona) {
            $avisosZona[(int)$supZona['id']][$zone] = ($avisosZona[(int)$supZona['id']][$zone] ?? 0) + 1;
        }
        $created++;
    }

    if ($created === 0) {
        $pdo->rollBack();
        ob_end_clean();
        $soloYaSupervisados = !$errors && ($yaSupervisados || $repetidosArchivo);
        echo json_encode([
            'status' => $soloYaSupervisados ? 'success' : 'error',
            'message' => $soloYaSupervisados
                ? 'No había cancelaciones nuevas: todos los códigos del archivo ya están en Supervisiones.'
                : ($errors || $yaSupervisados ? 'No se importó ninguna fila nueva. Revise el detalle.' : 'El archivo no tiene filas con datos debajo de los encabezados.'),
            'creadas' => 0,
            'observadas' => $errors,
            'ya_supervisados' => $yaSupervisados,
            'repetidos_archivo' => $repetidosArchivo
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
    ob_end_clean();

    echo json_encode([
        'status' => 'success',
        'message' => "Se cargaron {$created} cancelaciones pendientes.",
        'creadas' => $created,
        'observadas' => $errors,
        'ya_supervisados' => $yaSupervisados,
        'repetidos_archivo' => $repetidosArchivo,
        'cuadrillas_no_registradas' => $cuadrillasNoRegistradas
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(422);
    // Los avisos propios (RuntimeException) se muestran tal cual; un error inesperado (base de datos, PHP) se registra
    // y se muestra con su código de seguimiento, sin el texto técnico.
    if ($error instanceof RuntimeException && !$error instanceof PDOException) {
        echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        $fila = isset($filaActual) ? (int)$filaActual : null;
        win_log_error('cancel_import_error', $error->getMessage(), ['clase' => get_class($error), 'linea' => $error->getLine(), 'fila_excel' => $fila]);
        $explicacion = explicarErrorCarga($error, $fila);
        // La carga es solo del administrador: se le muestra el detalle técnico para poder corregir sin buscar en los registros.
        $detalle = get_class($error) . ': ' . mb_substr($error->getMessage(), 0, 400) . ' (línea ' . $error->getLine() . ')';
        echo json_encode(['status' => 'error', 'message' => $explicacion['que_paso'], 'que_hacer' => $explicacion['que_hacer'],
            'codigo' => sgs_error_code(), 'detalle' => $detalle], JSON_UNESCAPED_UNICODE);
    }
}
