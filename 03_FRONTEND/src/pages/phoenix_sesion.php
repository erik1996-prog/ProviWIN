<?php
// 03_FRONTEND/src/pages/phoenix_sesion.php - Ventanita "Iniciar sesión en Phoenix" (2026-11-19, pedido del usuario).
// Se entra por phoenix_sesion.php. Solo la cuenta principal: escribe una vez su usuario y contraseña de Phoenix, el servidor
// entra y guarda solo la sesión (cookies) hasta las 23:59; la ventanita avisa a Órdenes cercanas y se cierra sola
// (assets/js/phoenix_sesion.js). La contraseña no se guarda.
require_once BACKEND_PATH . '/config/puente_phoenix.php';
if (!Auth::isLoggedIn()) { header('Location: index.php'); exit; }
$permitido = Auth::puedeVincularPhoenix(Auth::getLoggedInUser());
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
<title>Iniciar sesión en Phoenix · <?= $e(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="<?= $assets ?>/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $assets ?>/css/sgs-theme.css?v=<?= rawurlencode(ASSET_VERSION) ?>">
</head>
<body class="pv-cuerpo">
  <main class="card p-3 p-md-4 pv-tarjeta">
    <h5 class="mb-1"><i class="bi bi-box-arrow-in-right me-2 text-primary"></i>Iniciar sesión en Phoenix</h5>
    <?php if (!$permitido): ?>
      <p class="text-muted small mb-0">Solo la cuenta principal puede iniciar la sesión de Phoenix.</p>
    <?php else: ?>
      <p class="text-muted small mb-3">Escribe tu usuario y contraseña de Phoenix una sola vez. La sesión queda activa hasta las 23:59 y puedes cerrar la laptop.</p>
      <form id="psForm" autocomplete="off" novalidate>
        <div class="mb-2">
          <label class="form-label" for="psUsuario">Usuario de Phoenix</label>
          <input class="form-control" id="psUsuario" name="usuario" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="psClave">Contraseña</label>
          <input class="form-control" id="psClave" name="clave" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-win w-100" id="psEntrar"><i class="bi bi-box-arrow-in-right me-1"></i>Iniciar sesión</button>
      </form>
      <p class="small mt-2 mb-0" id="psEstado" role="status"></p>
      <p class="small text-muted mt-2 mb-0"><i class="bi bi-shield-lock me-1"></i>La contraseña no se guarda: solo queda abierta la sesión hasta la medianoche.</p>
    <?php endif; ?>
  </main>
  <?php if ($permitido): ?><script src="<?= $assets ?>/js/phoenix_sesion.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script><?php endif; ?>
</body>
</html>
