<?php
// 02_BACKEND/src/routes/api/ubigeo.php - API REST para Selector Maestro de Ubigeo Geográfico
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = Database::getConnection();
$action = $_GET['action'] ?? '';

require_once __DIR__ . '/../../config/territorial.php';

if ($action === 'departamentos') {
    // Departamentos oficiales del catálogo (la zona de trabajo se calcula aparte: territorial.php, 2026-10-27).
    $data = sgsDepartamentosUbigeo();
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

if ($action === 'provincias') {
    $dep = $_GET['departamento'] ?? '';
    $stmt = $pdo->prepare("SELECT DISTINCT provincia FROM ubigeo WHERE departamento = :dep ORDER BY provincia ASC");
    $stmt->execute([':dep' => $dep]);
    $data = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Con zona (supervisor de una zona, 2026-10-27): solo las provincias de esa zona (NORTE CHICO → Huaura, Huaral, Barranca)
    $zona = mb_strtoupper(trim((string)($_GET['zona'] ?? '')), 'UTF-8');
    if ($zona !== '') $data = array_values(array_filter($data, static fn($p) => sgsZonaDeUbicacion($dep, $p) === $zona));
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

if ($action === 'distritos') {
    $dep = $_GET['departamento'] ?? '';
    $prov = $_GET['provincia'] ?? '';
    $stmt = $pdo->prepare("SELECT DISTINCT distrito FROM ubigeo WHERE departamento = :dep AND provincia = :prov ORDER BY distrito ASC");
    $stmt->execute([':dep' => $dep, ':prov' => $prov]);
    $data = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción de ubigeo no válida.']);
