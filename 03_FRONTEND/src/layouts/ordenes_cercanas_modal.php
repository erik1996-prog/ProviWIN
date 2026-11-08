<?php // 03_FRONTEND/src/layouts/ordenes_cercanas_modal.php - Ventana "Órdenes cercanas" de Supervisiones (2026-11-08). ?>
<div class="modal fade" id="modalOrdenesCercanas" tabindex="-1" aria-labelledby="ocTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ocTitulo"><i class="bi bi-geo-alt me-2 text-primary"></i>Órdenes cercanas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Órdenes iniciadas hoy, de la más cercana a la más lejana. Abre las coordenadas para ver la ruta en Google Maps.</p>
        <div class="d-flex flex-column gap-1 mb-3">
          <span class="sgs-gps-estado" id="ocFecha"></span>
          <span class="sgs-gps-estado" id="ocUbicacion"></span>
        </div>
        <div class="sgs-filtros mb-3">
          <div class="sgs-filtro">
            <label class="form-label" for="ocRadio">Radio</label>
            <select class="form-select" id="ocRadio">
              <option value="1">1 km</option><option value="2">2 km</option><option value="3" selected>3 km</option>
              <option value="5">5 km</option><option value="10">10 km</option><option value="20">20 km</option><option value="0">Todas</option>
            </select>
          </div>
        </div>
        <p class="text-muted small mb-2" id="ocAvance" role="status"></p>
        <div id="ocLista"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary d-none" id="ocReintentar"><i class="bi bi-arrow-clockwise me-1"></i>Reintentar</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-win d-none" id="ocEscanear"><i class="bi bi-radar me-1"></i>Escanear órdenes cercanas</button>
      </div>
    </div>
  </div>
</div>
