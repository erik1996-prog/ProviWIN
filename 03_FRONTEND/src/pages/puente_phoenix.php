<?php
// 03_FRONTEND/src/pages/puente_phoenix.php - Ventana del vínculo con Phoenix (2026-11-13). Se entra por puente_phoenix.php.
// Solo la cuenta principal. Queda abierta junto a Phoenix: le pasa los pedidos de Órdenes cercanas y devuelve sus respuestas
// (assets/js/puente_phoenix.js). Sin el menú del sistema; el vínculo dura SGS_PUENTE_HORAS como máximo.
require_once BACKEND_PATH . '/config/puente_phoenix.php';
if (!Auth::isLoggedIn()) { header('Location: index.php'); exit; } // tras iniciar sesión, se vuelve a pulsar el favorito
$usuario = Auth::getLoggedInUser();
$permitido = Auth::puedeVincularPhoenix($usuario);
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
<title>Vínculo con Phoenix · <?= $e(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="<?= $assets ?>/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $assets ?>/css/sgs-theme.css?v=<?= rawurlencode(ASSET_VERSION) ?>">
</head>
<body class="pv-cuerpo" data-phoenix="<?= $e(SGS_PHOENIX_ORIGEN) ?>" data-sondeo="<?= SGS_PUENTE_SONDEO ?>" data-reloj="<?= $assets ?>/js/puente_reloj.js?v=<?= rawurlencode(ASSET_VERSION) ?>">
  <main class="card p-3 p-md-4 pv-tarjeta">
    <h5 class="mb-1"><i class="bi bi-link-45deg me-2 text-primary"></i>Vínculo con Phoenix</h5>
    <?php if (!$permitido): ?>
      <p class="text-muted small mb-0">Solo la cuenta principal puede vincular Phoenix con el SGS.</p>
    <?php else: ?>
      <p class="text-muted small mb-3">Deja esta ventana abierta junto a Phoenix (puedes minimizarla). Mientras esté activa, Órdenes cercanas lee las órdenes a través de tu sesión de Phoenix, sin usar tu contraseña.</p>
      <span class="sgs-gps-estado d-block mb-2" id="pvEstado" data-estado="buscando" role="status">Conectando con Phoenix…</span>
      <div class="sgs-ficha-datos mb-3">
        <div class="sgs-dato"><span class="sgs-dato-etiqueta">Activo hasta</span><span class="sgs-dato-valor" id="pvVence">—</span></div>
        <div class="sgs-dato"><span class="sgs-dato-etiqueta">Lecturas atendidas</span><span class="sgs-dato-valor" id="pvAtendidas">0</span></div>
      </div>
      <div>
        <button type="button" class="btn btn-outline-danger d-none" id="pvDesactivar"><i class="bi bi-power me-1"></i>Desactivar</button>
      </div>
    <?php endif; ?>
  </main>
  <?php if ($permitido): ?><script src="<?= $assets ?>/js/puente_phoenix.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><?php endif; ?>
</body>
</html>
