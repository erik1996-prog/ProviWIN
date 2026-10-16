<?php
// 03_FRONTEND/src/pages/examen_publico.php - Página que abre el técnico con su link de examen (2026-10-15). Sin inicio de
// sesión: el código del link es la llave (48 caracteres aleatorios; en la base solo su huella). Se entra por examen.php.
// Por ahora confirma que el link es suyo y está vigente; las preguntas del examen se agregarán en un cambio posterior
// (al rendirlo, el link queda "usado" y ya no se abre).
require_once BACKEND_PATH . '/config/database.php';
require_once BACKEND_PATH . '/config/examen_enlaces.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$assets = '03_FRONTEND/src/assets';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$codigo = strtolower(trim((string)($_GET['t'] ?? '')));
$enlace = null;
try {
    $enlace = sgsBuscarEnlaceExamen(Database::getConnection(), $codigo);
} catch (Throwable $ex) {
    win_log_error('exam_link_open_error', $ex->getMessage());
}
$estado = $enlace ? sgsEstadoEnlace($enlace) : 'invalido';
if (in_array($estado, ['activo', 'abierto'], true)) {
    if ($estado === 'activo') {
        Database::getConnection()->prepare('UPDATE examen_enlaces SET abierto_en = NOW() WHERE id = :id AND abierto_en IS NULL')->execute([':id' => (int)$enlace['id']]);
        win_log_error('audit_exam_link_opened', 'Link de examen abierto por el técnico', ['target_id' => (int)$enlace['tecnico_id'], 'enlace_id' => (int)$enlace['id']]);
    }
}
$nombre = $enlace ? sgsNombrePersonaTecnico((string)$enlace['nombre_tecnico'], (int)$enlace['tipo_tecnico']) : '';
$mensajes = [
    'invalido' => ['bi-x-circle', 'Link no válido', 'Este link no existe o está incompleto. Pide a tu supervisor que te envíe uno nuevo.'],
    'vencido' => ['bi-clock-history', 'Link vencido', 'Este link ya venció. Pide a tu supervisor que te genere uno nuevo.'],
    'anulado' => ['bi-slash-circle', 'Link anulado', 'Este link fue reemplazado o anulado. Usa el último link que te envió tu supervisor.'],
    'usado' => ['bi-check2-circle', 'Examen ya rendido', 'Este examen ya fue rendido con este link. Gracias.'],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Examen · <?= $e(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="<?= $assets ?>/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $assets ?>/css/sgs-login.css?v=<?= rawurlencode(ASSET_VERSION) ?>">
</head>
<body class="sgs-login">
  <div class="sgs-bg" aria-hidden="true"><span class="s1"></span><span class="s2"></span><span class="s3"></span></div>
  <main class="sgs-main">
    <div class="sgs-card sgs-examen">
      <div class="sgs-logo sgs-logo--examen" role="img" aria-label="WIN"></div>
      <div class="sgs-system">Sistema de Gestión de Supervisión</div>
      <?php if (isset($mensajes[$estado])): [$icono, $titulo, $texto] = $mensajes[$estado]; ?>
        <div class="sgs-examen-icono"><i class="bi <?= $icono ?>" aria-hidden="true"></i></div>
        <h1><?= $e($titulo) ?></h1>
        <p class="sgs-sub"><?= $e($texto) ?></p>
      <?php else: ?>
        <div class="sgs-examen-icono"><i class="bi bi-journal-check" aria-hidden="true"></i></div>
        <h1>Hola, <?= $e(mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')) ?></h1>
        <p class="sgs-sub"><?= $e($enlace['titulo']) ?><?= $enlace['nombre_partner'] ? ' · ' . $e($enlace['nombre_partner']) : '' ?></p>
        <div class="sgs-examen-dato"><i class="bi bi-clock" aria-hidden="true"></i>Disponible hasta el <strong><?= date('d/m/Y', strtotime($enlace['vence_en'])) ?> a las <?= date('H:i', strtotime($enlace['vence_en'])) ?></strong></div>
        <div class="alert alert-warning small mb-3">El examen se está preparando. Cuando esté listo, <strong>este mismo link</strong> te mostrará las preguntas mientras siga vigente.</div>
        <p class="small mb-0 sgs-examen-nota"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Este link es personal: no lo compartas. Sirve una sola vez.</p>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
