<?php
// 02_BACKEND/src/routes/api/tecnicos.php - Consulta y corrección de técnicos existentes
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
$pdo = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
Auth::requireCsrf();

try {
    if ($action === 'list' || $action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $stmt = $pdo->prepare("SELECT t.id, t.nombre_tecnico, COALESCE(NULLIF(TRIM(t.dni), ''), '0') AS dni, t.tipo_tecnico, t.celular, t.observacion, t.estado_operativo, t.partner_id, t.vehiculo_id, v.placa AS unidad_placa, v.tipo AS unidad_tipo FROM tecnicos t LEFT JOIN vehiculos v ON v.id=t.vehiculo_id WHERE nombre_tecnico LIKE :q ORDER BY nombre_tecnico ASC LIMIT 50");
            $stmt->execute([':q' => "%{$q}%"]);
        } else {
            $stmt = $pdo->query("SELECT t.id, t.nombre_tecnico, COALESCE(NULLIF(TRIM(t.dni), ''), '0') AS dni, t.tipo_tecnico, t.celular, t.observacion, t.estado_operativo, t.partner_id, t.vehiculo_id, v.placa AS unidad_placa, v.tipo AS unidad_tipo FROM tecnicos t LEFT JOIN vehiculos v ON v.id=t.vehiculo_id ORDER BY nombre_tecnico ASC");
        }
        $tecnicos = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $tecnicos]);
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
        $nombre = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string)($_POST['nombre_tecnico'] ?? ''))), 'UTF-8');
        $dni = trim((string)($_POST['dni'] ?? ''));
        if ($partnerId <= 0) throw new Exception('Seleccione primero el partner de la supervisión.');
        if (mb_strlen($nombre) < 5 || mb_strlen($nombre) > 120 || !preg_match('/^[\p{L} .\'-]+$/u', $nombre)) {
            throw new Exception('Escriba los nombres y apellidos del técnico (solo letras, de 5 a 120 caracteres).');
        }
        if (!preg_match('/^\d{8}$/', $dni)) throw new Exception('El DNI debe tener 8 dígitos.');

        $stmtPartner = $pdo->prepare('SELECT nombre_partner FROM partners WHERE id = :id AND estado = 1');
        $stmtPartner->execute([':id' => $partnerId]);
        $partnerNombre = $stmtPartner->fetchColumn();
        if (!$partnerNombre) throw new Exception('El partner seleccionado no está activo.');

        $stmtDni = $pdo->prepare('SELECT t.nombre_tecnico, t.tipo_tecnico, t.partner_id, p.nombre_partner FROM tecnicos t LEFT JOIN partners p ON p.id = t.partner_id WHERE t.dni = :dni LIMIT 1');
        $stmtDni->execute([':dni' => $dni]);
        if ($otro = $stmtDni->fetch()) {
            $rol = (int)$otro['tipo_tecnico'] === 2 ? 'técnico de apoyo' : 'técnico líder';
            throw new Exception("El DNI ya está registrado como {$rol}: {$otro['nombre_tecnico']}" . ($otro['nombre_partner'] ? " ({$otro['nombre_partner']})" : '') . '.');
        }
        $stmtNombre = $pdo->prepare('SELECT id FROM tecnicos WHERE nombre_tecnico = :nom LIMIT 1');
        $stmtNombre->execute([':nom' => $nombre]);
        if ($stmtNombre->fetch()) throw new Exception('Ya existe un técnico con ese nombre. Búsquelo en la lista.');

        $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, fecha_registro) VALUES (:nom, :dni, 2, 'Habilitado', :partner, NOW())")
            ->execute([':nom' => $nombre, ':dni' => $dni, ':partner' => $partnerId]);
        $id = (int)$pdo->lastInsertId();
        win_log_error('audit_technician_created', 'Técnico de apoyo (T2) registrado desde la supervisión', ['target_id' => $id, 'partner_id' => $partnerId]);
        echo json_encode([
            'status' => 'success',
            'message' => "Técnico de apoyo registrado en {$partnerNombre}.",
            'data' => ['id' => $id, 'nombre_tecnico' => $nombre, 'dni' => $dni, 'tipo_tecnico' => 2, 'celular' => null, 'observacion' => null,
                'estado_operativo' => 'Habilitado', 'partner_id' => $partnerId, 'vehiculo_id' => null, 'unidad_placa' => null, 'unidad_tipo' => null],
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
