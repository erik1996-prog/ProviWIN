<?php
// 03_FRONTEND/src/pages/matriz_asistencia.php - Matriz de asistencia mensual de los supervisores directos (2026-10-28).
// Una fila por supervisor y una columna por día con su código (A, V, LSG, LG, DM, SUB, LPF, LP, D, FT, C) y los totales.
// Reglas en config/asistencia_matriz.php; datos en api/asistencia_matriz.php; pantalla en assets/js/matriz_asistencia.js.
// Quien tiene "Editar" (por defecto el administrador) corrige un día con un clic (ej. un domingo trabajado o una falta justificada).
Auth::requireLogin();
$user = Auth::getLoggedInUser();
if (!Auth::puede(Auth::PERM_ASISTENCIA_MATRIZ_VIEW, $user)) {
    echo '<div class="alert alert-danger m-4">No tiene permiso para ver la matriz de asistencia.</div>';
    return;
}
require_once BACKEND_PATH . '/config/asistencia_matriz.php';
$pdo = Database::getConnection();
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$zonasUsuario = sgsAlcanceLimitado($user) ? sgsUserZones((int)$user['id']) : null;
$zonas = $zonasUsuario ?? sgsOperationalDepartments();
$supervisores = sgsSupervisoresMatriz($pdo, $user, date('Y-m-01'), '', 0);
?>
<div class="container-fluid p-3 p-md-4">
  <div class="card sgs-toolbar-card p-3 mb-3">
    <div class="sgs-toolbar">
      <div class="sgs-asis-leyenda" aria-label="Leyenda">
        <?php foreach (SGS_ASISTENCIA_CODIGOS as $codigo => $nombre): ?>
        <span><span class="sgs-asis sgs-asis--<?= $e($codigo) ?>"><?= $e($codigo) ?></span><?= $e($nombre) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="sgs-toolbar-actions">
        <button type="button" class="btn btn-outline-secondary" id="masExcel"><i class="bi bi-file-earmark-excel me-1"></i>Descargar Excel</button>
      </div>
    </div>
    <div class="sgs-toolbar-row"><div class="sgs-filtros">
      <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="masMes">Mes</label><input class="form-control" id="masMes" type="month" value="<?= $e(date('Y-m')) ?>"></div>
      <div class="sgs-filtro"><label class="form-label" for="masZona">Zona</label>
        <select class="form-select" id="masZona"><option value="">Todas las zonas</option>
          <?php foreach ($zonas as $z): ?><option value="<?= $e($z) ?>"><?= $e($z) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sgs-filtro"><label class="form-label" for="masSupervisor">Supervisor</label>
        <select class="form-select" id="masSupervisor"><option value="0">Todos</option>
          <?php foreach ($supervisores as $s): ?><option value="<?= (int)$s['id'] ?>"><?= $e($s['nombre_completo']) ?></option><?php endforeach; ?>
        </select></div>
      <button type="button" class="btn btn-outline-secondary sgs-filtro-limpiar" id="masLimpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
    </div></div>
  </div>

  <div class="card p-3 p-md-4">
    <h5 class="mb-1"><i class="bi bi-calendar3 me-2 text-primary"></i>Matriz de asistencia</h5>
    <p class="text-muted small mb-3">A = inició turno. D = domingo. FT = día pasado sin turno ni licencia. Las licencias salen de Vacaciones y licencias (aprobadas).
      <?php if (Auth::puede(Auth::PERM_ASISTENCIA_MATRIZ_EDIT, $user)): ?>Haga clic en un día para corregirlo; los corregidos llevan una línea abajo.<?php endif; ?></p>
    <div class="matriz-scroll-box">
      <table class="table-matriz align-middle sgs-asis-tabla" id="masTabla">
        <thead id="masHead"></thead>
        <tbody id="masBody"><tr><td class="text-center text-muted py-4">Cargando…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="masModal" tabindex="-1" aria-labelledby="masModalTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="masForm">
    <div class="modal-header"><h5 class="modal-title" id="masModalTitulo"><i class="bi bi-pencil-square me-2 text-primary"></i>Corregir día</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <p class="small text-muted mb-2" id="masModalDetalle"></p>
      <label class="form-label" for="masCodigo">Código</label>
      <select class="form-select mb-2" id="masCodigo">
        <option value="">Volver a lo calculado (quitar corrección)</option>
        <?php foreach (SGS_ASISTENCIA_CODIGOS as $codigo => $nombre): ?><option value="<?= $e($codigo) ?>"><?= $e("$codigo · $nombre") ?></option><?php endforeach; ?>
      </select>
      <label class="form-label" for="masComentario">Comentario (opcional)</label>
      <input class="form-control" id="masComentario" maxlength="200" placeholder="Ej.: trabajó el domingo por apoyo en lluvias">
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
      <button type="submit" class="btn btn-win">Guardar corrección</button>
    </div>
  </form></div>
</div>
<script>window.SGS_MATRIZ = <?= json_encode(['puedeEditar' => Auth::puede(Auth::PERM_ASISTENCIA_MATRIZ_EDIT, $user)]) ?>;</script>
<script src="03_FRONTEND/src/assets/js/matriz_asistencia.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
