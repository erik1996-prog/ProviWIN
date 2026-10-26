<?php
// 03_FRONTEND/src/pages/login.php - Inicio de sesión (usuario + contraseña; sin captcha desde v0.6.3).
// Flujo: pantalla -> POST api/auth.php?action=login -> login_attempts (bloqueo 15 min tras 5 fallos) -> Auth::login (02_BACKEND/src/config/auth.php)
$assets = '03_FRONTEND/src/assets';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · <?= htmlspecialchars(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="03_FRONTEND/src/assets/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<link rel="apple-touch-icon" href="03_FRONTEND/src/assets/img/favicon.svg">
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

      <h1>Iniciar sesión</h1>
      <p class="sgs-sub">Ingresa tus datos de acceso.</p>

      <div class="alert alert-danger sgs-alert d-none" id="loginAlert" role="alert"></div>

      <form id="loginForm" novalidate>
        <div class="mb-3">
          <label for="user" class="form-label">Usuario</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input id="user" name="usuario" type="text" class="form-control" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="DNI, usuario o correo" required autofocus>
          </div>
        </div>

        <div class="mb-3">
          <label for="pass" class="form-label">Contraseña</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input id="pass" name="password" type="password" class="form-control" autocomplete="current-password" placeholder="Escribe tu contraseña" required>
            <button type="button" class="btn btn-toggle" id="togglePass" aria-label="Mostrar contraseña">Ver</button>
          </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
          <a href="#" class="ask-admin small">¿Olvidaste tu contraseña?</a>
        </div>

        <button class="btn btn-sgs w-100" type="submit" id="btnIn">Iniciar sesión</button>
      </form>
    </div>
  </main>

  <footer class="sgs-footer">
    <nav><a href="#" class="ask-admin">Ayuda y soporte</a><a href="#" class="ask-admin">Términos de uso</a><a href="#" class="ask-admin">Privacidad</a></nav>
    <small>ProviWIN · <?= htmlspecialchars(SYSTEM_CODE) ?> v<?= htmlspecialchars(APP_VERSION) ?></small>
  </footer>

<script src="<?= $assets ?>/js/aviso-mayusculas.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
<script>
(() => {
  const $ = id => document.getElementById(id);
  const card = $('card'), alertBox = $('loginAlert');

  const shake = () => { card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake'); };
  const showError = (msg, fieldId) => {
    alertBox.textContent = msg; alertBox.classList.remove('d-none');
    ['user', 'pass'].forEach(id => $(id).classList.toggle('is-invalid', id === fieldId));
    if (fieldId) $(fieldId).focus();
    shake();
  };
  const clearError = () => { alertBox.classList.add('d-none'); ['user', 'pass'].forEach(id => $(id).classList.remove('is-invalid')); };

  $('togglePass').onclick = () => {
    const p = $('pass'), vis = p.type === 'password';
    p.type = vis ? 'text' : 'password';
    $('togglePass').textContent = vis ? 'Ocultar' : 'Ver';
    $('togglePass').setAttribute('aria-label', vis ? 'Ocultar contraseña' : 'Mostrar contraseña');
  };

  document.querySelectorAll('.ask-admin').forEach(a => a.addEventListener('click', e => {
    e.preventDefault();
    alert('Comunícate con el administrador del sistema para recuperar o solicitar tu acceso.');
  }));

  $('loginForm').onsubmit = async e => {
    e.preventDefault();
    clearError();
    const usuario = $('user').value.trim(), password = $('pass').value;
    if (usuario.length < 3) return showError('Escribe tu DNI, usuario o correo.', 'user');
    if (!password) return showError('Escribe tu contraseña.', 'pass');

    const b = $('btnIn'); b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Verificando…';
    try {
      const res = await fetch('api/auth.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ usuario, password })
      });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') { location.replace('index.php'); return; }
      $('pass').value = ''; showError(data.message || 'No se pudo iniciar sesión. Inténtelo nuevamente.', 'pass');
    } catch (_) {
      showError('No hay conexión con el servidor. Inténtelo nuevamente.');
    } finally {
      b.disabled = false; b.textContent = 'Iniciar sesión';
    }
  };
})();
</script>
</body>
</html>
