<?php
// 03_FRONTEND/src/layouts/examen_seguimiento.php - Ventana "Seguimiento" de un link de examen (2026-11-05): examen en curso,
// terminado y faltan / no rindieron, en vivo. La incluyen Exámenes y Técnicos y cuadrillas; programa en assets/js/examen_links.js.
?>
<div class="modal fade" id="mExamenDetalle" tabindex="-1" aria-labelledby="mExamenDetalleTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="mExamenDetalleTitulo"><i class="bi bi-broadcast me-2"></i><span id="exDetTitulo">Seguimiento del link</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body" id="exDetalle"></div>
    <div class="modal-footer"><?php if (Auth::puede(Auth::PERM_EXAMENES_DELETE)): ?><button type="button" class="btn btn-outline-danger d-none me-auto" id="exCerrarLink"><i class="bi bi-lock me-1"></i>Cerrar link</button><?php endif; ?><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Salir</button></div>
  </div></div>
</div>
<script src="03_FRONTEND/src/assets/js/examen_links.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
