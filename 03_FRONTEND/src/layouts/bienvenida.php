<?php
// 03_FRONTEND/src/layouts/bienvenida.php - Bienvenida animada (2026-10-26, sgs-bienvenida.css, bienvenida.js).
// $bienvenidaAlAbrir = true: se muestra al cargar (app abierta desde el ícono) y se va sola; si no, queda oculta y
// login.php la muestra al iniciar sesión. $bienvenidaSaludo: "¡Hola, Nombre!" o el nombre de la app.
$bienvenidaAlAbrir = !empty($bienvenidaAlAbrir);
?>
  <div class="sgs-bienvenida" id="bienvenida" role="status" aria-live="polite"<?= $bienvenidaAlAbrir ? ' data-al-abrir' : ' hidden' ?>>
    <svg class="sgs-fibra" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <path d="M-40 620 C 260 520, 420 760, 700 600 S 1080 420, 1240 520"/>
      <path d="M-40 220 C 240 320, 460 80, 720 220 S 1060 360, 1240 240"/>
      <path d="M-40 420 C 300 360, 520 520, 780 420 S 1100 300, 1240 400"/>
      <path class="pulso" d="M-40 620 C 260 520, 420 760, 700 600 S 1080 420, 1240 520"/>
      <path class="pulso p2" d="M-40 220 C 240 320, 460 80, 720 220 S 1060 360, 1240 240"/>
      <path class="pulso p3" d="M-40 420 C 300 360, 520 520, 780 420 S 1100 300, 1240 400"/>
    </svg>
    <div class="sgs-bienvenida-centro">
      <div class="sgs-bienvenida-logo" role="img" aria-label="WIN"></div>
      <p class="sgs-bienvenida-saludo" id="bienvenidaSaludo"><?= htmlspecialchars($bienvenidaSaludo ?? '¡Hola!') ?></p>
      <p class="sgs-bienvenida-texto" id="bienvenidaTexto">Conectando con WIN…</p>
      <div class="sgs-bienvenida-barra"><span></span></div>
    </div>
  </div>
