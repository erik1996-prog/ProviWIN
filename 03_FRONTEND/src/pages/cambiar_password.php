<?php
// 03_FRONTEND/src/pages/cambiar_password.php - Cambio de contraseña obligatorio al ingresar (2026-10-15).
// index.php la muestra en lugar de cualquier pantalla mientras la sesión tenga la marca debe_cambiar_password
// (contraseña puesta o restablecida por el administrador). Auth::requireLogin bloquea el resto de las APIs.
$assets = '03_FRONTEND/src/assets';
$nombre = trim((string)($user['nombres'] ?? '')) ?: (string)($user['nombre'] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<title>Cambiar contraseña · <?= htmlspecialchars(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="<?= $assets ?>/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<meta name="theme-color" content="#FF5A0A">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $assets ?>/css/sgs-login.css?v=<?= rawurlencode(ASSET_VERSION) ?>.<?= (int)@filemtime(FRONTEND_PATH . '/assets/css/sgs-login.css') ?>">
</head>
<body class="sgs-login">
  <div class="sgs-bg" aria-hidden="true"><span class="s1"></span><span class="s2"></span><span class="s3"></span></div>

  <main class="sgs-main">
    <div class="sgs-card" id="card">
      <div class="sgs-logo" role="img" aria-label="WIN" style="-webkit-mask-image:url(<?= $assets ?>/img/win-logo-blanco.png);mask-image:url(<?= $assets ?>/img/win-logo-blanco.png)"></div>
      <div class="sgs-system">Sistema de Gestión de Supervisión</div>

      <h1>Crea tu contraseña</h1>
      <p class="sgs-sub">Hola<?= $nombre !== '' ? ', ' . htmlspecialchars($nombre) : '' ?>. Tu contraseña la asignó el administrador: cámbiala por una que solo tú conozcas para continuar.</p>

      <div class="alert alert-danger sgs-alert d-none" id="pwAlert" role="alert"></div>

      <form id="pwForm" novalidate autocomplete="off">
        <div class="mb-3">
          <label for="pwActual" class="form-label">Contraseña actual (la que te dieron)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input id="pwActual" type="password" class="form-control" autocomplete="current-password" required autofocus>
          </div>
        </div>
        <div class="mb-3">
          <label for="pwNueva" class="form-label">Nueva contraseña</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input id="pwNueva" type="password" class="form-control" autocomplete="new-password" placeholder="Mínimo 10, letras y números" required>
            <button type="button" class="btn btn-toggle" id="toggleNueva" aria-label="Mostrar contraseña">Ver</button>
          </div>
          <div data-password-meter="pwNueva"></div>
        </div>
        <div class="mb-4">
          <label for="pwConfirma" class="form-label">Repite la nueva contraseña</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-check2-square"></i></span>
            <input id="pwConfirma" type="password" class="form-control" autocomplete="new-password" required>
          </div>
        </div>
        <button class="btn btn-sgs w-100" type="submit" id="btnPw">Guardar y continuar</button>
        <div class="text-center mt-3"><a href="#" id="pwSalir" class="small">Cerrar sesión</a></div>
      </form>
    </div>
  </main>

  <footer class="sgs-footer">
    <small>ProviWIN · <?= htmlspecialchars(SYSTEM_CODE) ?> v<?= htmlspecialchars(APP_VERSION) ?></small>
  </footer>

<script src="<?= $assets ?>/js/aviso-mayusculas.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script src="<?= $assets ?>/js/password-meter.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script>
(() => {
  const $ = id => document.getElementById(id);
  const token = document.querySelector('meta[name="csrf-token"]').content;
  const card = $('card'), alertBox = $('pwAlert');
  const error = (msg, campo) => {
    alertBox.textContent = msg; alertBox.classList.remove('d-none');
    ['pwActual', 'pwNueva', 'pwConfirma'].forEach(id => $(id).classList.toggle('is-invalid', id === campo));
    if (campo) $(campo).focus();
    card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake');
  };
  $('toggleNueva').onclick = () => {
    const vis = $('pwNueva').type === 'password';
    $('pwNueva').type = $('pwConfirma').type = vis ? 'text' : 'password';
    $('toggleNueva').textContent = vis ? 'Ocultar' : 'Ver';
    $('toggleNueva').setAttribute('aria-label', vis ? 'Ocultar contraseña' : 'Mostrar contraseña');
  };
  $('pwSalir').onclick = async e => {
    e.preventDefault();
    try { await fetch('api/auth.php?action=logout', { method: 'POST', headers: { 'X-CSRF-Token': token } }); } catch (_) {}
    location.replace('index.php?page=login');
  };
  $('pwForm').onsubmit = async e => {
    e.preventDefault();
    alertBox.classList.add('d-none');
    const actual = $('pwActual').value, nueva = $('pwNueva').value;
    if (!actual) return error('Escribe la contraseña que te dio el administrador.', 'pwActual');
    if (nueva.length < 10 || !/[A-Za-z]/.test(nueva) || !/\d/.test(nueva)) return error('La nueva contraseña debe tener al menos 10 caracteres, con letras y números.', 'pwNueva');
    if (nueva !== $('pwConfirma').value) return error('Las contraseñas nuevas no coinciden.', 'pwConfirma');
    const b = $('btnPw'); b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Guardando…';
    try {
      const res = await fetch('api/usuarios.php?action=cambiar_password_propia', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ actual, nueva })
      });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') { location.replace('index.php'); return; }
      error(data.message || 'No se pudo cambiar la contraseña.', data.field === 'actual' ? 'pwActual' : 'pwNueva');
    } catch (_) {
      error('No hay conexión con el servidor. Inténtelo nuevamente.');
    } finally {
      b.disabled = false; b.textContent = 'Guardar y continuar';
    }
  };
})();
</script>
</body>
</html>
