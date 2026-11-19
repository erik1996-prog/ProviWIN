<?php // 03_FRONTEND/src/layouts/ordenes_cercanas_modal.php - Ventana "Órdenes cercanas" de Supervisiones (2026-11-08).
// 2026-11-13: la cuenta principal ve aquí cómo vincular Phoenix (config/puente_phoenix.php).
// 2026-11-19: y puede iniciar la sesión de Phoenix aquí (ventanita phoenix_sesion.php), activa hasta las 23:59; el favorito queda como otra forma.
require_once BACKEND_PATH . '/config/puente_phoenix.php';
require_once BACKEND_PATH . '/config/examen_enlaces.php'; // sgsUrlSistema()
$puedeVincularPhoenix = Auth::puedeVincularPhoenix($effectiveViewUser);
?>
<div class="modal fade" id="modalOrdenesCercanas" tabindex="-1" aria-labelledby="ocTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ocTitulo"><i class="bi bi-geo-alt me-2 text-primary"></i>Órdenes cercanas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Órdenes de hoy, de la más cercana a la más lejana. Abre la ubicación para ver la ruta en Google Maps.</p>
        <div class="d-flex flex-column gap-1 mb-3">
          <span class="sgs-gps-estado" id="ocFecha"></span>
          <span class="sgs-gps-estado" id="ocUbicacion"></span>
        </div>
        <div class="sgs-filtros mb-3">
          <div class="sgs-filtro">
            <label class="form-label" for="ocEstado">Estado</label>
            <select class="form-select" id="ocEstado">
              <option value="iniciada" selected>Iniciadas</option><option value="agendada">Agendadas</option>
              <option value="finalizada">Finalizadas</option><option value="cancelada">Canceladas</option>
            </select>
          </div>
          <div class="sgs-filtro d-none" id="ocGrupoFiltro">
            <label class="form-label" for="ocGrupo">Órdenes de</label>
            <select class="form-select" id="ocGrupo"></select>
          </div>
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
        <?php if ($puedeVincularPhoenix): ?>
        <details class="pc-vinculo mt-3">
          <summary><i class="bi bi-link-45deg me-1"></i>Vínculo con Phoenix <small class="text-muted">(solo tú)</small></summary>
          <div class="mt-2 mb-3" id="ocPhxSesion">
            <span class="sgs-gps-estado d-block mb-2" id="ocPhxEstado" role="status">Consultando la sesión de Phoenix…</span>
            <button type="button" class="btn btn-win btn-sm d-none" id="ocPhxIniciar"><i class="bi bi-box-arrow-in-right me-1"></i>Iniciar sesión en Phoenix</button>
            <button type="button" class="btn btn-outline-danger btn-sm d-none" id="ocPhxCerrar"><i class="bi bi-box-arrow-right me-1"></i>Cerrar sesión</button>
          </div>
          <p class="small text-muted mb-1">Otra forma (con la laptop abierta):</p>
          <ol class="small mt-2 mb-2 ps-3">
            <li>Una sola vez: muestra la barra de favoritos de Chrome (Ctrl + Mayús + B) y <strong>arrastra</strong> a ella este botón:
              <a class="btn btn-outline-secondary btn-sm ms-1" href="<?= htmlspecialchars(sgsFavoritoPhoenix(sgsUrlSistema() . '/'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-link-45deg me-1"></i>Vincular SGS – Phoenix</a></li>
            <li>Entra a Phoenix e inicia sesión.</li>
            <li>Pulsa el favorito <strong>Vincular SGS – Phoenix</strong>: se abre una ventana del SGS junto a Phoenix y el vínculo se activa por <?= SGS_PUENTE_HORAS ?> horas. Deja las dos abiertas (puedes minimizarlas).</li>
          </ol>
          <p class="small text-muted mb-0">Mientras el vínculo esté activo, Órdenes cercanas lee las órdenes con tu sesión de Phoenix y el SGS no usa tu contraseña. Si recargas Phoenix, vuelve a pulsar el favorito. Para apagarlo, usa Desactivar en la ventana del vínculo.</p>
        </details>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary d-none" id="ocReintentar"><i class="bi bi-arrow-clockwise me-1"></i>Reintentar</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-win d-none" id="ocEscanear"><i class="bi bi-radar me-1"></i>Escanear órdenes cercanas</button>
      </div>
    </div>
  </div>
</div>
