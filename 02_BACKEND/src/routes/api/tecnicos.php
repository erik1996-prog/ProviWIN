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

    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
