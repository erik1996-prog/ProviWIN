<?php // 03_FRONTEND/src/layouts/puertos_cto_modal.php - Ventana "Consultar puertos" de Supervisiones (2026-11-11). Lógica: config/puertos_cto.php.
require_once BACKEND_PATH . '/config/puertos_cto.php';
require_once BACKEND_PATH . '/config/examen_enlaces.php'; // sgsUrlSistema()
$puedeVincular = Auth::puedeVincularWincore($effectiveViewUser);
?>
<div class="modal fade" id="modalPuertosCto" tabindex="-1" aria-labelledby="pcTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pcTitulo"><i class="bi bi-hdd-network me-2 text-primary"></i>Consultar puertos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Escribe el código de la CTO para ver qué puertos están disponibles u ocupados y llegar a ella con Google Maps.</p>
        <div class="alert alert-info py-2 px-3 small mb-2"><i class="bi bi-clock me-1"></i>Horario de atención: de 8:00 a. m. a 6:00 p. m. Por motivos operativos, el servicio podría suspenderse antes de ese horario.</div>
        <span class="sgs-gps-estado d-block mb-3" id="pcEstado" role="status"></span>
        <form class="sgs-filtros mb-3" id="pcForm" novalidate>
          <div class="sgs-filtro sgs-filtro--ancho">
            <label class="form-label" for="pcCodigo">Código de la CTO</label>
            <input class="form-control pc-codigo" id="pcCodigo" type="text" maxlength="40" autocomplete="off" placeholder="WN-73651-1272741" required>
          </div>
          <button type="submit" class="btn btn-win" id="pcConsultar" disabled><i class="bi bi-search me-1"></i>Consultar</button>
        </form>
        <p class="text-muted small mb-2" id="pcAvance" role="status"></p>
        <div id="pcResultado"></div>
        <?php if ($puedeVincular): ?>
        <details class="pc-vinculo mt-3" id="pcVinculo">
          <summary><i class="bi bi-link-45deg me-1"></i>Vínculo con Wincore <small class="text-muted">(solo tú)</small></summary>
          <ol class="small mt-2 mb-2 ps-3">
            <li>Una sola vez: muestra la barra de favoritos de Chrome (Ctrl + Mayús + B) y <strong>arrastra</strong> a ella este botón:
              <a class="btn btn-outline-secondary btn-sm ms-1" id="pcFavorito" href="<?= htmlspecialchars(sgsFavoritoPuente(sgsUrlSistema() . '/'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-link-45deg me-1"></i>Vincular SGS</a></li>
            <li>Con la VPN conectada, entra a Wincore, inicia sesión y abre la pantalla donde consultas una CTO.</li>
            <li>Pulsa el favorito <strong>Vincular SGS</strong>: se abre una ventana del SGS junto a Wincore y el vínculo se activa por <?= SGS_PUENTE_HORAS ?> horas. Deja las dos abiertas.</li>
          </ol>
          <p class="small text-muted mb-2">Si recargas Wincore, vuelve a pulsar el favorito. La sesión y el token de Wincore no salen de tu laptop, y a los supervisores solo les llega el estado de los puertos, nunca los datos de clientes.</p>
          <button type="button" class="btn btn-outline-danger btn-sm" id="pcDesactivar" disabled><i class="bi bi-power me-1"></i>Desactivar vínculo</button>
        </details>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
