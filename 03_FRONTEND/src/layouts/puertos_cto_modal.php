<?php // 03_FRONTEND/src/layouts/puertos_cto_modal.php - Ventana "Consultar puertos" de Supervisiones (2026-11-11). Lógica: config/puertos_cto.php.
require_once BACKEND_PATH . '/config/puertos_cto.php';

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
        <details class="pc-vinculo mt-3" id="pcVinculo" data-wincore="<?= htmlspecialchars(sgsWincoreOrigen(), ENT_QUOTES, 'UTF-8') ?>">
          <summary><i class="bi bi-link-45deg me-1"></i>Vínculo con Wincore <small class="text-muted">(solo tú, en tu laptop con la VPN)</small></summary>
          <ol class="small mt-2 mb-2 ps-3 pc-pasos">
            <li class="mb-2"><strong>VPN:</strong> <span class="sgs-gps-estado d-inline-block" id="pcVpn" role="status">Sin revisar</span>
              <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" id="pcVpnRevisar">Revisar</button></li>
            <li class="mb-2"><strong>Wincore:</strong> <a class="btn btn-outline-secondary btn-sm ms-1" id="pcAbrirWincore" href="<?= htmlspecialchars(sgsWincoreOrigen(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir Wincore</a> e inicia sesión.</li>
            <li><strong>Favorito «Vincular SGS»</strong> (se arma una sola vez):
              <span class="d-block text-muted" id="pcAgenteEstado" role="status">Revisando…</span>
              <div class="alert alert-warning py-2 px-3 mt-2 mb-2 d-none" id="pcAgenteClave">
                <strong>Arrastra este botón a la barra de favoritos de Chrome</strong> (Ctrl + Mayús + B para verla). Solo aparece ahora:
                <div class="mt-2"><a class="btn btn-win btn-sm" id="pcFavorito" href="#"><i class="bi bi-link-45deg me-1"></i>Vincular SGS</a></div>
                <div class="mt-2 text-muted">¿Usas el programa «Vínculo Wincore»? Pega esta clave en él:</div>
                <div class="d-flex gap-2 mt-1"><input class="form-control form-control-sm" id="pcAgenteTexto" readonly aria-label="Clave del programa">
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="pcAgenteCopiar"><i class="bi bi-clipboard me-1"></i>Copiar</button></div>
              </div>
              <div class="mt-1"><button type="button" class="btn btn-outline-secondary btn-sm" id="pcAgenteGenerar"><i class="bi bi-key me-1"></i>Crear mi favorito</button>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="pcAgenteAnular"><i class="bi bi-x-circle me-1"></i>Anular favorito</button></div></li>
          </ol>
          <p class="small text-muted mb-2">En Wincore pulsa el favorito: se abre la ventanita <strong>«Vínculo Wincore»</strong>. Minimízala y no la cierres; puedes seguir usando Wincore en tu otra ventana sin que se corte. Tu sesión de Wincore no sale de tu laptop y a los supervisores solo les llega el estado de los puertos.</p>
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
