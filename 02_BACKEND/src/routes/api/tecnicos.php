<?php
// 02_BACKEND/src/routes/api/tecnicos.php - Consulta y corrección de técnicos existentes
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
$usuario = Auth::getLoggedInUser();
$pdo = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
Auth::requireCsrf();

try {
    if ($action === 'list' || $action === 'search') {
        // Técnicos sin zona (2026-10-28): solo los recibe el administrador general. El T2 sin zona propia toma la de su líder
        // (como en Técnicos y cuadrillas); antes quedaba fuera y el T2 de la cuadrilla salía con DNI pero sin nombre.
        $soloConZona = sgsVeTecnicosSinZona($usuario) ? '' : ' AND (' . sgsTecnicoConZonaSql('t.departamento')
            . ' OR EXISTS (SELECT 1 FROM tecnicos lz WHERE lz.apoyo_id = t.id AND ' . sgsTecnicoConZonaSql('lz.departamento') . '))';
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $stmt = $pdo->prepare("SELECT t.id, t.nombre_tecnico, COALESCE(NULLIF(TRIM(t.dni), ''), '0') AS dni, t.tipo_tecnico, t.celular, t.observacion, t.estado_operativo, t.partner_id, t.provincia, t.departamento, t.vehiculo_id, v.placa AS unidad_placa, v.tipo AS unidad_tipo, v.estado_conservacion AS unidad_estado, t.apoyo_id, ta.nombre_tecnico AS apoyo_nombre, pp.nombre_partner FROM tecnicos t LEFT JOIN vehiculos v ON v.id=t.vehiculo_id LEFT JOIN tecnicos ta ON ta.id=t.apoyo_id LEFT JOIN partners pp ON pp.id=t.partner_id WHERE t.nombre_tecnico LIKE :q{$soloConZona} ORDER BY t.nombre_tecnico ASC LIMIT 50");
            $stmt->execute([':q' => "%{$q}%"]);
        } else {
            $stmt = $pdo->query("SELECT t.id, t.nombre_tecnico, COALESCE(NULLIF(TRIM(t.dni), ''), '0') AS dni, t.tipo_tecnico, t.celular, t.observacion, t.estado_operativo, t.partner_id, t.provincia, t.departamento, t.vehiculo_id, v.placa AS unidad_placa, v.tipo AS unidad_tipo, v.estado_conservacion AS unidad_estado, t.apoyo_id, ta.nombre_tecnico AS apoyo_nombre, pp.nombre_partner FROM tecnicos t LEFT JOIN vehiculos v ON v.id=t.vehiculo_id LEFT JOIN tecnicos ta ON ta.id=t.apoyo_id LEFT JOIN partners pp ON pp.id=t.partner_id WHERE 1=1{$soloConZona} ORDER BY t.nombre_tecnico ASC");
        }
        $tecnicos = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $tecnicos]);
        exit;
    }

    // Cuadrillas (2026-10-15): cada técnico líder con su técnico de apoyo habitual y su vehículo, armadas solas al
    // registrar supervisiones. Solo consulta, para personal administrativo y supervisores directos.
    if ($action === 'cuadrillas') {
        $puede = Auth::puede(Auth::PERM_CUADRILLAS_VIEW, $usuario);
        if (!$puede) { http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'Sin acceso a las cuadrillas.']); exit; }
        $where = ['COALESCE(t.tipo_tecnico, 1) = 1'];
        $params = [];
        $partnerId = (int)($_GET['partner_id'] ?? 0);
        if (!sgsVeTecnicosSinZona($usuario)) $where[] = sgsTecnicoConZonaSql('t.departamento'); // sin zona: solo el administrador general
        if ($partnerId > 0) { $where[] = 't.partner_id = :partner'; $params[':partner'] = $partnerId; }
        // Zona del usuario (2026-10-15): igual que la pantalla Cuadrillas.
        if ((!Auth::isAdministrative() || sgsAlcanceLimitado($usuario)) && sgsUserZones((int)$usuario['id']) !== null) {
            $zp = [];
            $where[] = sgsZoneSql('t.departamento', (int)$usuario['id'], $zp, ':uz');
            $params += $zp;
        }
        if (!Auth::isAdministrative() || sgsAlcanceLimitado($usuario)) $where[] = sgsCuadrillaAreaSql('t.departamento', 't.nombre_tecnico', (int)$usuario['id']); // SGI / SGA (2026-10-31)
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        if ($q !== '') {
            $where[] = '(t.nombre_tecnico LIKE :q1 OR ta.nombre_tecnico LIKE :q2 OR v.placa LIKE :q3 OR t.dni LIKE :q4)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        $stmt = $pdo->prepare("SELECT t.id, t.nombre_tecnico, t.dni, t.estado_operativo, t.provincia, t.departamento, p.nombre_partner,
                ta.nombre_tecnico AS apoyo_nombre, v.placa, v.tipo AS vehiculo_tipo, v.estado_conservacion AS vehiculo_estado,
                (SELECT COUNT(*) FROM supervisiones s WHERE s.tecnico1_id = t.id) AS supervisiones,
                (SELECT MAX(s.fecha_registro) FROM supervisiones s WHERE s.tecnico1_id = t.id) AS ultima_supervision
            FROM tecnicos t
            LEFT JOIN partners p ON p.id = t.partner_id
            LEFT JOIN tecnicos ta ON ta.id = t.apoyo_id
            LEFT JOIN vehiculos v ON v.id = t.vehiculo_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (t.apoyo_id IS NULL AND t.vehiculo_id IS NULL), p.nombre_partner, t.nombre_tecnico
            LIMIT 500");
        $stmt->execute($params);
        $filas = $stmt->fetchAll();
        $completas = count(array_filter($filas, fn($f) => $f['apoyo_nombre'] && $f['placa']));
        echo json_encode(['status' => 'success', 'data' => $filas, 'resumen' => ['lideres' => count($filas), 'completas' => $completas]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre_tecnico'] ?? '');
        $dni = trim($_POST['dni'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $observacion = trim($_POST['observacion'] ?? '');
        $estadoOperativo = trim($_POST['estado_operativo'] ?? 'Habilitado');

        if ($id <= 0 || $nombre === '' || !preg_match('/^\d{8}$/', $dni)) {
            throw new Exception('Indique un técnico existente, su nombre y un DNI válido de 8 dígitos.');
        }

        $stmtCheck = $pdo->prepare("SELECT id, tipo_tecnico FROM tecnicos WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $existing = $stmtCheck->fetch();
        if (!$existing) {
            throw new Exception('El técnico seleccionado ya no existe.');
        }
        if ($existing['tipo_tecnico'] == 1 && !preg_match('/^\d{9}$/', $celular)) {
            throw new Exception('El técnico líder debe tener un número celular válido de 9 dígitos.');
        }
        if (!in_array($estadoOperativo, ['Habilitado', 'Inhabilitado', 'Blacklist'], true)) {
            throw new Exception('Seleccione un estado operativo válido.');
        }

        $stmtDuplicate = $pdo->prepare("SELECT id FROM tecnicos WHERE nombre_tecnico = :nom AND id <> :id LIMIT 1");
        $stmtDuplicate->execute([':nom' => $nombre, ':id' => $id]);
        if ($stmtDuplicate->fetch()) {
            throw new Exception('Ya existe otro técnico con ese nombre.');
        }

        $stmtUpd = $pdo->prepare("UPDATE tecnicos SET nombre_tecnico = :nom, dni = :dni, celular = :cel, observacion = :obs, estado_operativo = :estado, fecha_actualizacion = NOW() WHERE id = :id");
        $stmtUpd->execute([':nom' => $nombre, ':dni' => $dni, ':cel' => $celular ?: null, ':obs' => $observacion ?: null, ':estado' => $estadoOperativo, ':id' => $id]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Técnico actualizado correctamente.',
            'data' => ['id' => $id, 'nombre_tecnico' => $nombre, 'dni' => $dni, 'tipo_tecnico' => $existing['tipo_tecnico'], 'celular' => $celular, 'observacion' => $observacion, 'estado_operativo' => $estadoOperativo]
        ]);
        exit;
    }

    // Registro de técnico de apoyo (T2) desde la supervisión (2026-10-08). Los T2 son un grupo distinto de los
    // T1: pertenecen a un partner, no llevan nomenclatura ("P n …") y rotan entre cuadrillas.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'crear_apoyo') {
        $user = Auth::getLoggedInUser();
        if (!Auth::can(Auth::PERM_SUPERVISION_CREATE, $user) && !Auth::isAdministrative()) {
            http_response_code(403);
            throw new Exception('No tiene permiso para registrar técnicos.');
        }
        $partnerId = intval($_POST['partner_id'] ?? 0);
        if ($partnerId <= 0) throw new Exception('Seleccione primero el partner de la supervisión.');
        // Zona (2026-10-24): la de la supervisión donde se registra; antes quedaba "Sin zona"
        $departamento = sgsDepartmentAlias((string)($_POST['departamento'] ?? ''));
        if (!in_array($departamento, sgsOperationalDepartments(), true)) $departamento = null;
        $provincia = null;
        if ($departamento && trim((string)($_POST['provincia'] ?? '')) !== '') { // debe existir en el catálogo (2026-10-26)
            require_once __DIR__ . '/../../config/zonas_peru.php';
            $ubicacion = sgsValidarUbicacion($pdo, $departamento, (string)$_POST['provincia']);
            if ($ubicacion['error'] !== '') throw new Exception($ubicacion['error']);
            $provincia = $ubicacion['provincia'];
            $departamento = sgsZonaDeUbicacion($departamento, $provincia); // su zona: LIMA / HUAURA → NORTE CHICO (2026-10-27)
        }

        require_once __DIR__ . '/../../config/tecnicos_lideres.php';
        $id = sgsRegistrarTecnico($pdo, 2, (string)($_POST['nombre_tecnico'] ?? ''), (string)($_POST['dni'] ?? ''), $partnerId, $departamento, $provincia);
        $st = $pdo->prepare('SELECT nombre_tecnico, dni FROM tecnicos WHERE id = :id');
        $st->execute([':id' => $id]);
        ['nombre_tecnico' => $nombre, 'dni' => $dni] = $st->fetch(PDO::FETCH_ASSOC);
        $partnerNombre = $pdo->query('SELECT nombre_partner FROM partners WHERE id = ' . $partnerId)->fetchColumn();
        win_log_error('audit_technician_created', 'Técnico de apoyo (T2) registrado desde la supervisión', ['target_id' => $id, 'partner_id' => $partnerId]);
        echo json_encode([
            'status' => 'success',
            'message' => "Técnico de apoyo registrado en {$partnerNombre}.",
            'data' => ['id' => $id, 'nombre_tecnico' => $nombre, 'dni' => $dni, 'tipo_tecnico' => 2, 'celular' => null, 'observacion' => null,
                'estado_operativo' => 'Habilitado', 'partner_id' => $partnerId, 'departamento' => $departamento, 'provincia' => $provincia, 'vehiculo_id' => null, 'unidad_placa' => null, 'unidad_tipo' => null, 'unidad_estado' => null, 'apoyo_id' => null, 'apoyo_nombre' => null],
        ]);
        exit;
    }

    // Nuevo técnico (2026-10-29): desde Técnicos y cuadrillas se registra un técnico líder (T1) o de apoyo (T2) suelto, sin
    // el Excel. Quien puede editar cuadrillas; con zona propia, solo en una provincia de su zona. Sin provincia (sin zona)
    // solo el administrador general, que es el único que ve a los técnicos sin zona.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'crear_tecnico') {
        if (!Auth::puede(Auth::PERM_CUADRILLAS_EDIT, $usuario)) { http_response_code(403); throw new Exception('No tiene permiso para registrar técnicos.'); }
        require_once __DIR__ . '/../../config/tecnicos_lideres.php';
        $tipo = (int)($_POST['tipo_tecnico'] ?? 0);
        if (!in_array($tipo, [1, 2], true)) throw new Exception('Elija si es técnico líder (T1) o de apoyo (T2).');
        $provinciaTexto = mb_substr(trim((string)($_POST['provincia'] ?? '')), 0, 100);
        $zona = $provinciaTexto !== '' ? sgsZonaDesdeProvincia($provinciaTexto, $pdo) : ['provincia' => null, 'departamento' => null];
        if (!$zona) throw new Exception("No se reconoce la provincia \"{$provinciaTexto}\". Escríbala como en el mapa del Perú (p. ej. TRUJILLO, SANTA, CHICLAYO).");
        if (!$zona['departamento'] && !sgsVeTecnicosSinZona($usuario)) throw new Exception('Escriba la provincia donde trabaja el técnico.');
        $zonasEditor = Auth::isAdministrative() && !sgsAlcanceLimitado($usuario) ? null : sgsUserZones((int)$usuario['id']);
        if ($zonasEditor !== null && !in_array(mb_strtoupper((string)$zona['departamento'], 'UTF-8'), $zonasEditor, true)) {
            throw new Exception('La provincia elegida es de otra zona: solo puede registrar técnicos de su zona (' . implode(', ', $zonasEditor) . ').');
        }
        $id = sgsRegistrarTecnico($pdo, $tipo, (string)($_POST['nombre_tecnico'] ?? ''), (string)($_POST['dni'] ?? ''), (int)($_POST['partner_id'] ?? 0),
            $zona['departamento'], $zona['provincia'], (string)($_POST['celular'] ?? ''));
        win_log_error('audit_technician_created', ($tipo === 1 ? 'Técnico líder (T1)' : 'Técnico de apoyo (T2)') . ' registrado desde Técnicos y cuadrillas', ['target_id' => $id, 'partner_id' => (int)$_POST['partner_id']]);
        echo json_encode(['status' => 'success', 'message' => ($tipo === 1 ? 'Técnico líder' : 'Técnico de apoyo') . ' registrado.', 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Edición de una cuadrilla desde la pantalla Cuadrillas (2026-10-15): personal administrativo, para emergencias.
    // 2026-10-20: también los supervisores directos, solo para las cuadrillas de su zona (o sin zona) y solo hacia sus zonas.
    // Mismas reglas que al cerrar una supervisión: un T2 y un vehículo pertenecen a una sola cuadrilla.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cuadrilla_guardar') {
        $editor = Auth::getLoggedInUser();
        if (!Auth::puede(Auth::PERM_CUADRILLAS_EDIT, $editor)) { http_response_code(403); throw new Exception('No tiene permiso para editar cuadrillas.'); }
        $zonasEditor = Auth::isAdministrative() && !sgsAlcanceLimitado($editor) ? null : sgsUserZones((int)$editor['id']); // null = todas
        $id = (int)($_POST['id'] ?? 0);
        $apoyoId = (int)($_POST['apoyo_id'] ?? 0);
        $placa = strtoupper(trim((string)($_POST['placa'] ?? '')));
        $tipoVehiculo = in_array($_POST['vehiculo_tipo'] ?? '', ['Minivan', 'Auto', 'Moto', 'Otro'], true) ? $_POST['vehiculo_tipo'] : null;
        $estadoVehiculo = in_array($_POST['vehiculo_estado'] ?? '', ['Mal estado', 'Regular', 'Buen estado'], true) ? $_POST['vehiculo_estado'] : null;
        $estadoOperativo = (string)($_POST['estado_operativo'] ?? '');
        if (!in_array($estadoOperativo, ['Habilitado', 'Inhabilitado', 'Blacklist'], true)) throw new Exception('Seleccione un estado operativo válido.');
        // Zona (2026-10-15): se escribe la provincia y el sistema busca su departamento. Vacía = sin zona.
        require_once __DIR__ . '/../../config/zonas_peru.php';
        $provinciaTexto = mb_substr(trim((string)($_POST['provincia'] ?? '')), 0, 100);
        $zona = $provinciaTexto !== '' ? sgsZonaDesdeProvincia($provinciaTexto, $pdo) : ['provincia' => null, 'departamento' => null];
        if (!$zona) throw new Exception("No se reconoce la provincia \"{$provinciaTexto}\". Escríbala como en el mapa del Perú (p. ej. TRUJILLO, SANTA, CHICLAYO).");
        if (!$zona['departamento'] && !sgsVeTecnicosSinZona($editor)) throw new Exception('Escriba la provincia de la cuadrilla: solo el administrador general deja técnicos sin zona.');
        // Datos del T1 y del T2 (2026-10-15): DNI (8 dígitos) y celular (9 dígitos), opcionales; solo se tocan si llegan.
        $dniValido = static function (string $dni, string $quien): ?string {
            $dni = trim($dni);
            if ($dni === '' || $dni === '0') return null;
            if (!preg_match('/^\d{8}$/', $dni)) throw new Exception("El DNI del {$quien} debe tener 8 dígitos.");
            return $dni;
        };
        $celularValido = static function (string $cel, string $quien): ?string {
            $cel = preg_replace('/\D/', '', $cel);
            if ($cel === '') return null;
            if (!preg_match('/^9\d{8}$/', $cel)) throw new Exception("El celular del {$quien} debe tener 9 dígitos y empezar con 9.");
            return $cel;
        };
        $dniLibre = static function (?string $dni, int $propioId) use ($pdo): void {
            if ($dni === null) return;
            $st = $pdo->prepare('SELECT nombre_tecnico FROM tecnicos WHERE dni = :dni AND id <> :id LIMIT 1');
            $st->execute([':dni' => $dni, ':id' => $propioId]);
            if ($otro = $st->fetchColumn()) throw new Exception("El DNI {$dni} ya está registrado para {$otro}.");
        };
        $tocaT1 = array_key_exists('t1_dni', $_POST);
        $t1Dni = $tocaT1 ? $dniValido((string)$_POST['t1_dni'], 'técnico líder') : null;
        $t1Cel = $tocaT1 ? $celularValido((string)($_POST['t1_celular'] ?? ''), 'técnico líder') : null;
        $apoyoNuevo = ($_POST['apoyo_id'] ?? '') === 'nuevo';
        $tocaT2 = array_key_exists('t2_dni', $_POST) && ($apoyoId > 0 || $apoyoNuevo);
        $t2Dni = $tocaT2 ? $dniValido((string)$_POST['t2_dni'], 'técnico de apoyo') : null;
        $t2Cel = $tocaT2 ? $celularValido((string)($_POST['t2_celular'] ?? ''), 'técnico de apoyo') : null;
        $t2Nombre = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string)($_POST['t2_nombre'] ?? ''))), 'UTF-8');

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, nombre_tecnico, partner_id, departamento FROM tecnicos WHERE id = :id AND COALESCE(tipo_tecnico, 1) = 1 FOR UPDATE');
            $st->execute([':id' => $id]);
            $lider = $st->fetch();
            if (!$lider) throw new Exception('El técnico líder ya no existe.');
            if ($zonasEditor !== null) {
                // Supervisor directo: la cuadrilla debe ser de su zona (o no tener zona) y solo puede quedar en una de sus zonas.
                $enZona = static fn(?string $dep): bool => in_array(mb_strtoupper(trim((string)$dep), 'UTF-8'), $zonasEditor, true);
                $depActual = trim((string)$lider['departamento']);
                if ($depActual !== '' && !$enZona($depActual)) { http_response_code(403); throw new Exception('Esa cuadrilla es de otra zona: solo puede editar las de su zona.'); }
                if (!$zona['departamento'] && $depActual !== '') throw new Exception('Escriba la provincia de la cuadrilla (una de su zona).');
                if ($zona['departamento'] && !$enZona($zona['departamento'])) throw new Exception('La provincia elegida es de otra zona: solo puede asignar provincias de su zona (' . implode(', ', $zonasEditor) . ').');
            }

            if ($tocaT1) {
                $dniLibre($t1Dni, $id);
                $pdo->prepare('UPDATE tecnicos SET dni = :dni, celular = :cel WHERE id = :id')->execute([':dni' => $t1Dni, ':cel' => $t1Cel, ':id' => $id]);
            }
            if ($apoyoNuevo) {
                // T2 nuevo: queda en el partner y la zona del líder.
                require_once __DIR__ . '/../../config/tecnicos_lideres.php';
                $apoyoId = sgsRegistrarTecnico($pdo, 2, $t2Nombre, (string)$t2Dni, (int)$lider['partner_id'], $zona['departamento'], $zona['provincia'], $t2Cel);
                win_log_error('audit_technician_created', 'Técnico de apoyo (T2) registrado desde Cuadrillas', ['target_id' => $apoyoId, 'partner_id' => $lider['partner_id']]);
            } elseif ($tocaT2) {
                $dniLibre($t2Dni, $apoyoId);
                $pdo->prepare('UPDATE tecnicos SET dni = :dni, celular = :cel WHERE id = :id AND tipo_tecnico = 2')->execute([':dni' => $t2Dni, ':cel' => $t2Cel, ':id' => $apoyoId]);
            }

            if ($apoyoId > 0) {
                $st = $pdo->prepare('SELECT id FROM tecnicos WHERE id = :id AND tipo_tecnico = 2');
                $st->execute([':id' => $apoyoId]);
                if (!$st->fetchColumn()) throw new Exception('El técnico de apoyo elegido no está registrado como T2.');
                $pdo->prepare('UPDATE tecnicos SET apoyo_id = NULL WHERE apoyo_id = :a AND id <> :id')->execute([':a' => $apoyoId, ':id' => $id]);
                if ($lider['partner_id']) $pdo->prepare('UPDATE tecnicos SET partner_id = :p, fecha_actualizacion = NOW() WHERE id = :id')->execute([':p' => $lider['partner_id'], ':id' => $apoyoId]);
                // El T2 pasa también a la zona del líder.
                if ($zona['departamento']) $pdo->prepare('UPDATE tecnicos SET provincia = :prov, departamento = :dep WHERE id = :id')
                    ->execute([':prov' => $zona['provincia'], ':dep' => $zona['departamento'], ':id' => $apoyoId]);
            }
            $vehiculoId = null;
            if ($placa !== '') {
                if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,9}$/', $placa)) throw new Exception('Registre una placa válida de hasta 10 caracteres.');
                $pdo->prepare('INSERT INTO vehiculos (placa, tipo, estado_conservacion) VALUES (:placa, :tipo, :estado)
                        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), tipo = COALESCE(VALUES(tipo), tipo), estado_conservacion = COALESCE(VALUES(estado_conservacion), estado_conservacion)')
                    ->execute([':placa' => $placa, ':tipo' => $tipoVehiculo, ':estado' => $estadoVehiculo]);
                $q = $pdo->prepare('SELECT id FROM vehiculos WHERE placa = :placa');
                $q->execute([':placa' => $placa]);
                $vehiculoId = (int)$q->fetchColumn();
                $pdo->prepare('UPDATE tecnicos SET vehiculo_id = NULL WHERE vehiculo_id = :v AND id <> :id')->execute([':v' => $vehiculoId, ':id' => $id]);
            }
            $pdo->prepare('UPDATE tecnicos SET apoyo_id = :a, vehiculo_id = :v, estado_operativo = :e, provincia = :prov, departamento = :dep, fecha_actualizacion = NOW() WHERE id = :id')
                ->execute([':a' => $apoyoId ?: null, ':v' => $vehiculoId, ':e' => $estadoOperativo, ':prov' => $zona['provincia'], ':dep' => $zona['departamento'], ':id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e instanceof Exception ? $e : new Exception('No se pudo guardar la cuadrilla.');
        }
        win_log_error('audit_crew_edited', 'Cuadrilla editada desde la pantalla Cuadrillas', ['target_id' => $id, 'apoyo_id' => $apoyoId ?: null, 'vehiculo_id' => $vehiculoId]);
        echo json_encode(['status' => 'success', 'message' => "Cuadrilla de {$lider['nombre_tecnico']} actualizada."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Carga de técnicos líderes por Excel (2026-10-15): vista previa y luego aplicar. Ver config/tecnicos_lideres.php.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'importar_lideres') {
        if (!Auth::puede(Auth::PERM_CUADRILLAS_IMPORT)) { http_response_code(403); throw new Exception('No tiene permiso para cargar técnicos líderes.'); }
        require_once __DIR__ . '/../../config/uploads.php';
        require_once __DIR__ . '/../../config/tecnicos_lideres.php';
        if (empty($_FILES['archivo'])) throw new Exception('Seleccione el archivo Excel de técnicos líderes.');
        try {
            validateUploadedFile($_FILES['archivo'], ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/zip' => 'xlsx', 'application/octet-stream' => 'xlsx'], 5 * 1024 * 1024);
            $filas = sgsLeerExcelLideres($_FILES['archivo']['tmp_name']);
        } catch (Throwable $e) {
            throw new Exception('No se pudo leer el archivo. Debe ser un Excel (.xlsx) con dos columnas: técnico líder y partner.');
        }
        if (!$filas) throw new Exception('El archivo no tiene filas con técnicos.');
        $aplicar = ($_POST['modo'] ?? '') === 'aplicar';
        $r = sgsImportarLideres($pdo, $filas, $aplicar, (int)(Auth::getLoggedInUser()['id'] ?? 0));
        echo json_encode(['status' => 'success', 'aplicado' => $aplicar, 'message' => $aplicar ? 'Cambios aplicados.' : 'Vista previa lista.', 'data' => $r], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lista actual de técnicos líderes habilitados en Excel: sirve de modelo para la carga (mismas dos columnas).
    if ($action === 'exportar_lideres') {
        if (!Auth::puede(Auth::PERM_CUADRILLAS_IMPORT)) { http_response_code(403); throw new Exception('No tiene permiso para descargar la lista.'); }
        require_once __DIR__ . '/../../config/tecnicos_lideres.php';
        $filas = [['TÉCNICO LÍDER', 'PARTNER', 'PROVINCIA']];
        foreach ($pdo->query("SELECT t.nombre_tecnico, p.nombre_partner, COALESCE(NULLIF(t.provincia, ''), t.departamento) AS provincia FROM tecnicos t LEFT JOIN partners p ON p.id = t.partner_id
                WHERE COALESCE(t.tipo_tecnico, 1) = 1 AND t.estado_operativo = 'Habilitado' ORDER BY p.nombre_partner, t.nombre_tecnico") as $f) {
            $filas[] = [$f['nombre_tecnico'], $f['nombre_partner'] ?? '', $f['provincia'] ?? ''];
        }
        $bin = sgsEscribirXlsx($filas, 'Técnicos líderes');
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="tecnicos_lideres_' . date('Ymd') . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
