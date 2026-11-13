<?php
// 03_FRONTEND/src/pages/puente_wincore.php - Ventana del vínculo con Wincore (2026-11-11). Se entra por puente_wincore.php.
// Solo la cuenta principal. Queda abierta junto a Wincore: pasa a Wincore las consultas de puertos de los supervisores y
// devuelve sus resultados (assets/js/puente_wincore.js). Sin el menú del sistema; la sesión se cierra a la medianoche como en todo el SGS y
// el vínculo dura SGS_PUENTE_HORAS como máximo.
require_once BACKEND_PATH . '/config/puertos_cto.php';
if (!Auth::isLoggedIn()) { header('Location: index.php'); exit; } // tras iniciar sesión, se vuelve a pulsar el favorito
$usuario = Auth::getLoggedInUser();
$permitido = Auth::puedeVincularWincore($usuario);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$assets = '03_FRONTEND/src/assets';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $e(Auth::csrfToken()) ?>">
<title>Vínculo con Wincore · <?= $e(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="<?= $assets ?>/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $assets ?>/css/sgs-theme.css?v=<?= rawurlencode(ASSET_VERSION) ?>">
</head>
<body class="pv-cuerpo" data-wincore="<?= $e(sgsWincoreOrigen()) ?>" data-sondeo="<?= SGS_PUENTE_SONDEO ?>" data-reloj="<?= $assets ?>/js/puente_reloj.js?v=<?= rawurlencode(ASSET_VERSION) ?>">
  <main class="card p-3 p-md-4 pv-tarjeta">
    <h5 class="mb-1"><i class="bi bi-link-45deg me-2 text-primary"></i>Vínculo con Wincore</h5>
    <?php if (!$permitido): ?>
      <p class="text-muted small mb-0">Solo la cuenta principal puede vincular Wincore con el SGS.</p>
    <?php else: ?>
      <p class="text-muted small mb-3">Deja esta ventana abierta junto a Wincore (puedes minimizarla). Mientras esté activa, quienes tienen permiso consultan los puertos de las CTO a través de tu sesión de Wincore.</p>
      <span class="sgs-gps-estado d-block mb-2" id="pvEstado" data-estado="buscando" role="status">Conectando con Wincore…</span>
      <div class="sgs-ficha-datos mb-3">
        <div class="sgs-dato"><span class="sgs-dato-etiqueta">Activo hasta</span><span class="sgs-dato-valor" id="pvVence">—</span></div>
        <div class="sgs-dato"><span class="sgs-dato-etiqueta">Consultas atendidas</span><span class="sgs-dato-valor" id="pvAtendidas">0</span></div>
        <div class="sgs-dato"><span class="sgs-dato-etiqueta">En espera</span><span class="sgs-dato-valor" id="pvEspera">0</span></div>
      </div>
      <div>
        <button type="button" class="btn btn-outline-danger d-none" id="pvDesactivar"><i class="bi bi-power me-1"></i>Desactivar</button>
      </div>
    <?php endif; ?>
  </main>
  <?php if ($permitido): ?><script src="<?= $assets ?>/js/puente_wincore.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><?php endif; ?>
</body>
</html>
