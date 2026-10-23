<?php
// 02_BACKEND/src/routes/api/lluvias.php - Lluvia por zona desde "Mi turno" (2026-10-20). Ver config/lluvias.php.
// Solo el supervisor directo. La lluvia no cambia su actividad: se anota qué estaba haciendo al avisar y al cerrar.
//   GET  ?action=estado          → sus zonas y la lluvia abierta en cada una
//   POST ?action=avisar  zona    → con turno abierto: avisa la lluvia (si otro ya la avisó, no se duplica)
//   POST ?action=paro    zona    → cierra la lluvia de la zona (cualquiera de la zona)
// Cada inicio y fin se publica en el grupo de Teams si está configurado (sgsAvisoTeamsLluvia).
// Al avisar llegan también comentario y foto (JPEG ya reducido en el celular, en base64): solo se publican en Teams, no se guardan.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/lluvias.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!Auth::puede(Auth::PERM_CLIMA_EDIT, $usuario)) {
    $responder(['status' => 'error', 'message' => 'No tiene permiso para avisar lluvias.'], 403);
}
$pdo = Database::getConnection();
$uid = (int)$usuario['id'];
$action = $_GET['action'] ?? '';
$zonas = sgsZonasLluvia($uid);
$estado = static fn(): array => ['zonas' => $zonas, 'abiertas' => sgsLluviasAbiertas($pdo, $zonas), 'en_turno' => (bool)sgsTurnoAbiertoHoy($pdo, $uid)];

if ($action === 'estado') $responder(['status' => 'success', 'data' => $estado()]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['avisar', 'paro'], true)) {
    $responder(['status' => 'error', 'message' => 'Acción no válida.'], 404);
}
$zona = mb_strtoupper(trim((string)($_POST['zona'] ?? '')), 'UTF-8');
if (!in_array($zona, $zonas, true)) $responder(['status' => 'error', 'message' => 'Elija una de sus zonas.'], 422);

if ($action === 'avisar') {
    if (!sgsTurnoAbiertoHoy($pdo, $uid)) $responder(['status' => 'error', 'message' => 'Inicie su turno para avisar la lluvia.'], 409);
    $comentario = trim(preg_replace('/\s+/u', ' ', (string)($_POST['comentario'] ?? '')));
    $foto = base64_decode((string)($_POST['foto'] ?? ''), true);
    if ($comentario === '' || mb_strlen($comentario) > 200) $responder(['status' => 'error', 'message' => 'Escriba un comentario corto de la lluvia.'], 422);
    if (!$foto || !str_starts_with($foto, "��") || strlen($foto) > 20000) $responder(['status' => 'error', 'message' => 'Tome una foto de la lluvia.'], 422);
    $r = sgsAvisarLluvia($pdo, $uid, $zona);
    if ($r['nueva']) sgsAvisoTeamsLluvia("Inicio de lluvia — {$zona}", 'Hora: ' . date('H:i') . " · Reportó: {$usuario['nombre']}", $comentario, $foto);
    $responder(['status' => 'success', 'data' => $estado(), 'message' => $r['nueva'] ? "Lluvia avisada en {$zona}. Avise cuando pare."
        : "Ya estaba avisada la lluvia en {$zona} por {$r['lluvia']['reportado_por']}."]);
}

$cerro = sgsTerminarLluvia($pdo, $uid, $zona);
if ($cerro) sgsAvisoTeamsLluvia("Fin de lluvia — {$zona}", 'Hora: ' . date('H:i') . " · Finalizó: {$usuario['nombre']}");
$responder(['status' => 'success', 'data' => $estado(), 'message' => $cerro ? "Listo: dejó de llover en {$zona}." : "No había lluvia abierta en {$zona}."]);
