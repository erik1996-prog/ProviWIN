<?php
// 03_FRONTEND/src/layouts/novedades.php - Recorrido "El sistema se actualizó" (2026-11-08): al iniciar sesión, a pantalla
// completa y paso a paso, solo con lo que este usuario puede usar ($novedades, config/novedades.php).
// Oculto hasta que lo abre assets/js/novedades.js: si el programa no carga, no tapa el sistema.
$primerNombre = mb_convert_case(mb_strtolower(strtok(trim((string)($user['nombres'] ?? '')), ' ') ?: '', 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
$totalNovedades = count($novedades);
?>
<div class="sgs-nov" id="sgsNovedades" role="dialog" aria-modal="true" aria-labelledby="novTitulo" hidden>
  <img class="sgs-nov-logo" src="03_FRONTEND/src/assets/img/win-logo-blanco.png" width="96" height="40" alt="WIN">
  <div class="sgs-nov-tarjeta">
    <p class="sgs-nov-saludo"><?= $primerNombre !== '' ? 'Hola, ' . htmlspecialchars($primerNombre) : 'Hola' ?></p>
    <h1 class="sgs-nov-titulo" id="novTitulo">El sistema se actualizó</h1>
    <p class="sgs-nov-sub"><?= $totalNovedades === 1 ? 'Esto es nuevo para ti:' : "Hay {$totalNovedades} novedades para ti:" ?></p>
    <div class="sgs-nov-pasos" aria-live="polite">
      <?php foreach ($novedades as $i => $n): ?>
      <section class="sgs-nov-paso"<?= $i ? ' hidden' : '' ?>>
        <span class="sgs-nov-icono" aria-hidden="true"><i class="bi <?= htmlspecialchars($n['icono']) ?>"></i></span>
        <h2><?= htmlspecialchars($n['titulo']) ?></h2>
        <p><?= htmlspecialchars($n['texto']) ?></p>
        <a href="<?= htmlspecialchars($n['url']) ?>">Ir a verlo<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
      </section>
      <?php endforeach; ?>
    </div>
    <?php if ($totalNovedades > 1): ?>
    <div class="sgs-nov-puntos">
      <?php for ($i = 0; $i < $totalNovedades; $i++): ?><button type="button" data-nov-ir="<?= $i ?>" aria-label="Novedad <?= $i + 1 ?> de <?= $totalNovedades ?>"></button><?php endfor; ?>
    </div>
    <?php endif; ?>
    <div class="sgs-nov-acciones">
      <button type="button" class="btn btn-link sgs-nov-omitir" id="novOmitir">Omitir</button>
      <button type="button" class="btn btn-outline-secondary" id="novAnterior" hidden><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Anterior</button>
      <button type="button" class="btn btn-win" id="novSiguiente"></button>
    </div>
  </div>
</div>
