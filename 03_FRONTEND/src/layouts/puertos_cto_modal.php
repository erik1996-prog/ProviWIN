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
        <details class="pc-vinculo mt-3" id="pcVinculo">
          <summary><i class="bi bi-pc-display me-1"></i>Programa «Vínculo Wincore» <small class="text-muted">(en tu laptop con la VPN)</small></summary>
          <p class="small text-muted mt-2 mb-2">El programa de tu laptop atiende las consultas de puertos y conecta Phoenix y el bot solo, cada día. Se identifica con tu clave: créala una vez y pégala en el programa.</p>
          <span class="d-block small text-muted mb-2" id="pcAgenteEstado" role="status">Revisando…</span>
          <div class="alert alert-warning py-2 px-3 small mb-2 d-none" id="pcAgenteClave">
            <strong>Copia tu clave y pégala en el programa.</strong> Solo se muestra ahora:
            <div class="d-flex gap-2 mt-2"><input class="form-control form-control-sm" id="pcAgenteTexto" readonly aria-label="Clave del programa">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="pcAgenteCopiar"><i class="bi bi-clipboard me-1"></i>Copiar</button></div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="pcAgenteGenerar"><i class="bi bi-key me-1"></i>Crear clave del programa</button>
            <button type="button" class="btn btn-outline-danger btn-sm d-none" id="pcAgenteAnular"><i class="bi bi-x-circle me-1"></i>Anular clave</button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="pcDesactivar" disabled><i class="bi bi-power me-1"></i>Desactivar vínculo</button>
          </div>
        </details>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
