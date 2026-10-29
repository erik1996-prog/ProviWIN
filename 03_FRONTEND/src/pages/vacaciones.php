<?php
// 03_FRONTEND/src/pages/vacaciones.php - Vacaciones y licencias (2026-10-29, sección Asistencia). Antes estaba repartido entre
// Mi turno (el supervisor registra) y el Monitor de turnos (los jefes aprueban). Programa: assets/js/ausencias.js; datos y
// reglas (no dejar dos zonas sin supervisor, etc.): api/ausencias.php y config/ausencias.php.
//   Supervisor directo: registra sus vacaciones, descansos médicos y licencias y ve su estado.
//   Administración, Consulta y Gestión: aprueban, rechazan o anulan las de los supervisores.
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$isAdmin = Auth::isAdministrative();
$isReadOnlyWinSupervisor = ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
if (!$isAdmin && !$isReadOnlyWinSupervisor) {
    echo '<div class="alert alert-danger m-4">Esta pantalla es para los supervisores directos y sus jefes.</div>';
    return;
}
?>
<div class="container-fluid p-3 p-md-4">
<?php if ($isReadOnlyWinSupervisor): ?>
            <!-- Vacaciones y licencias (2026-10-26, assets/js/ausencias.js, api/ausencias.php): las aprueba su jefe -->
            <div class="card card-win p-3 p-md-4 shadow-sm border-0 mb-4" id="ausMias">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                    <div><h5 class="mb-1"><i class="bi bi-calendar2-x me-2 text-primary"></i>Vacaciones y licencias</h5>
                        <p class="text-muted small mb-0">Registre sus vacaciones, descansos médicos y licencias. Su jefe las aprueba; esos días no podrá iniciar turno.</p></div>
                    <button type="button" class="btn btn-win" id="ausNueva"><i class="bi bi-plus-lg me-1"></i>Registrar ausencia</button>
                </div>
                <div class="alert alert-info d-none" id="ausHoy" role="status"></div>
                <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
                    <thead><tr><th>Tipo</th><th>Fechas</th><th>Días</th><th>Estado</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody id="ausMiasLista"><tr><td colspan="5" class="text-muted">Cargando…</td></tr></tbody>
                </table></div>
            </div>
<?php endif; ?>
<?php if ($isAdmin): ?>
                <!-- Vacaciones y licencias por aprobar (2026-10-26, assets/js/ausencias.js, api/ausencias.php) -->
                <div class="card card-win p-3 p-md-4 mb-4 shadow-sm border-0" id="ausJefes">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                        <div><h5 class="mb-1"><i class="bi bi-calendar2-x me-2 text-primary"></i>Vacaciones y licencias</h5>
                            <p class="text-muted small mb-0">Solicitudes de los supervisores. Con una aprobada, esos días no pueden iniciar turno.</p></div>
                        <div class="sgs-segmented" role="group" aria-label="Solicitudes">
                            <button type="button" class="filter-tab-btn active" data-aus-estado="Pendiente">Por aprobar <span class="sgs-seg-count" id="ausPendientes">0</span></button>
                            <button type="button" class="filter-tab-btn" data-aus-estado="vigentes">Aprobadas vigentes</button>
                            <button type="button" class="filter-tab-btn" data-aus-estado="todas">Últimos 90 días</button>
                        </div>
                    </div>
                    <div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">
                        <thead><tr><th class="sgs-col-persona">Supervisor</th><th>Tipo</th><th>Fechas</th><th>Días</th><th>Sustento</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody id="ausJefesLista"><tr><td colspan="7" class="text-muted">Cargando…</td></tr></tbody>
                    </table></div>
                </div>
<?php endif; ?>
</div>
<?php if ($isReadOnlyWinSupervisor): ?>
<div class="modal fade" id="modalAusencia" tabindex="-1" aria-labelledby="modalAusenciaTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="formAusencia" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="modalAusenciaTitulo"><i class="bi bi-calendar2-x me-2 text-primary"></i>Registrar ausencia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="ausTipo">Tipo</label>
                <select class="form-select" id="ausTipo" name="tipo" required>
                    <option value="">Seleccione</option>
                    <?php require_once BACKEND_PATH . '/config/ausencias.php'; foreach (SGS_TIPOS_AUSENCIA as $tipoAus): ?>
                    <option value="<?= htmlspecialchars($tipoAus) ?>"><?= htmlspecialchars($tipoAus) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-1">Vacaciones: no pueden coincidir con las de otro supervisor de su zona ni dejar dos zonas sin supervisor el mismo día (las ya pedidas ocupan sus fechas).</small>
                <div class="row g-2 mt-1">
                    <div class="col-6"><label class="form-label" for="ausInicio">Desde</label><input class="form-control" type="date" id="ausInicio" name="fecha_inicio" required></div>
                    <div class="col-6"><label class="form-label" for="ausFin">Hasta</label><input class="form-control" type="date" id="ausFin" name="fecha_fin" required></div>
                </div>
                <label class="form-label mt-3" for="ausSustento">Sustento <span class="text-muted" id="ausSustentoNota">(opcional)</span></label>
                <input class="form-control" type="file" id="ausSustento" name="sustento" accept="image/jpeg,image/png,image/webp,application/pdf">
                <small class="text-muted d-block">Foto o PDF del certificado o documento. Solo lo ven usted y sus jefes.</small>
                <label class="form-label d-block mt-3" for="ausComentario">Comentario <span class="text-muted">(opcional)</span></label>
                <textarea class="form-control" id="ausComentario" name="comentario" rows="2" maxlength="300"></textarea>
                <div class="alert alert-danger mt-3 mb-0 d-none" id="ausError" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-win" id="ausEnviar"><i class="bi bi-send me-1"></i>Enviar solicitud</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if ($isAdmin): ?>
<div class="modal fade" id="modalAusMotivo" tabindex="-1" aria-labelledby="modalAusMotivoTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalAusMotivoTitulo">Rechazar solicitud</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted" id="ausMotivoDetalle"></p>
                <label class="form-label" for="ausMotivo">Motivo</label>
                <textarea class="form-control" id="ausMotivo" rows="2" maxlength="300"></textarea>
                <small class="text-muted">El supervisor lo verá en su aviso.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-danger" id="ausMotivoConfirmar">Rechazar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="03_FRONTEND/src/assets/js/ausencias.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
