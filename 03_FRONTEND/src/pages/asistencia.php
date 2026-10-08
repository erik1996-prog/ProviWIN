<?php
// 03_FRONTEND/src/pages/asistencia.php - Inicio y fin de turno, pausas e historial (supervisor directo WIN) y monitor de turnos (administración)
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$isAdmin = in_array($user['rol'], ['Administrador', 'Gerente', 'Coordinador']);
$isReadOnlyWinSupervisor = $user['rol'] === 'Supervisor' && !empty($user['is_win_directo']);

if (empty($user['is_win_directo']) && !$isAdmin) {
    echo '<div class="alert alert-warning m-4"><i class="bi bi-shield-lock-fill me-2"></i>El registro de asistencia e inicio de turno es exclusivo para los supervisores directos de WIN. Será derivado al panel de observaciones. <script>setTimeout(function(){ window.location.href="index.php?page=supervision_lista"; }, 2500);</script></div>';
    exit;
}
?>

<div class="container-fluid p-3 p-md-4 <?= $isReadOnlyWinSupervisor ? 'attendance-supervisor-compact' : '' ?>">
    
    <!-- Encabezado Principal -->
    <div class="attendance-page-header sgs-page-actions">

        <div class="attendance-header-actions d-flex align-items-center gap-2 flex-wrap">
            
        <?php if ($isAdmin): ?>
            <!-- Selector de Pestañas para Administradores -->
            <ul class="nav nav-pills custom-asist-pills bg-light p-1 rounded-3 border border-secondary-subtle" id="pills-tab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold px-3 py-2" id="tab-mi-asistencia" data-bs-toggle="pill" data-bs-target="#content-mi-asistencia" type="button" role="tab">
                        <i class="bi bi-person-badge me-1"></i> Mi turno
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold px-3 py-2" id="tab-vista-admin" data-bs-toggle="pill" data-bs-target="#content-vista-admin" type="button" role="tab">
                        <i class="bi bi-shield-lock-fill me-1"></i> Vista General (Admin)
                    </button>
                </li>
            </ul>
        <?php endif; ?>
        </div>
    </div>

    <div class="tab-content" id="pills-tabContent">
        <!-- ========================================================= -->
        <!-- PESTAÑA 1: VISTA DE SUPERVISOR (MI CONTROL DE ASISTENCIA) -->
        <!-- ========================================================= -->
        <div class="tab-pane fade show active" id="content-mi-asistencia" role="tabpanel">
            <div class="row g-3 g-md-4">
                <!-- Columna Izquierda: Tarjeta Principal de Registro -->
                <div class="col-12 col-lg-7">
                    <div class="card card-win attendance-command-card p-3 p-md-4 shadow-sm border-0">
                        <!-- Estado de Turno -->
                        <div class="attendance-card-title d-flex align-items-center justify-content-between mb-3 border-bottom pb-3 gap-2">
                            <h5 class="mb-0"><i class="bi bi-person-workspace me-2 text-primary"></i>Estado del turno</h5>
                            <span id="badge-shift-status" class="badge badge-status badge-fuera-turno fs-6 py-2 px-3">Consultando...</span>
                        </div>

                        <!-- Temporizador en Vivo -->
                        <div class="shift-hero p-3 p-md-4 rounded-4 text-center mb-3">
                            <div class="mb-1" id="lbl-timer-type">
                                Tiempo en turno
                            </div>
                            <div class="display-5 fw-bold my-1" id="txt-timer-live">
                                00:00:00
                            </div>
                            <div class="small" id="txt-timer-sub">
                                <i class="bi bi-geo-alt me-1"></i><span id="txt-location-status">Obteniendo ubicación GPS...</span>
                            </div>
                        </div>

                        <div class="attendance-metrics row g-2 mb-4" aria-label="Resumen de la jornada">
                            <div class="col-6"><div class="attendance-metric"><i class="bi bi-cup-hot"></i><span>Almuerzo</span><strong id="txt-total-almuerzo">0 min</strong></div></div>
                            <div class="col-6"><div class="attendance-metric"><i class="bi bi-people"></i><span>Reuniones</span><strong id="txt-total-reunion">0 min</strong></div></div>
                        </div>

                        <!-- Botones de Acción de Turno -->
                        <div class="mb-4">
                            <p class="sgs-kpi-label mb-2">Turno</p>
                            
                            <!-- Banner de Almuerzo / Reunión Activo -->
                            <div id="box-active-event-alert" class="alert alert-warning border border-warning d-none mb-3 p-3 rounded-3">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div>
                                        <h6 class="fw-bold mb-1 text-dark" id="txt-active-event-title"><i class="bi bi-cup-hot-fill me-2"></i> En Almuerzo</h6>
                                        <small class="text-secondary d-block" id="txt-active-event-detail">Iniciado a las --:--. Recuerda reanudar tu turno al culminar.</small>
                                    </div>
                                    <button type="button" id="btn-end-event" class="btn btn-dark fw-bold btn-sm py-2 px-3 shadow-sm">
                                        <i class="bi bi-play-fill me-1 text-success"></i> Reanudar turno
                                    </button>
                                </div>
                            </div>

                            <!-- Botones de Inicio / Fin Turno -->
                            <div class="row g-2 mb-3 attendance-primary-actions">
                                <div class="col-6">
                                    <button type="button" id="btn-start-shift" class="btn btn-win btn-lg w-100 py-3" disabled>
                                        <i class="bi bi-play-circle-fill fs-4 d-block mb-1"></i>
                                        <strong>Iniciar turno</strong>
                                    </button>
                                </div>
                                <div class="col-6">
                                    <button type="button" id="btn-end-shift" class="btn btn-outline-danger btn-lg w-100 py-3" disabled>
                                        <i class="bi bi-stop-circle-fill fs-4 d-block mb-1"></i>
                                        <strong>Finalizar turno</strong>
                                    </button>
                                </div>
                            </div>

                            <!-- Sub-Estados / Pausas durante el turno -->
                            <p class="sgs-kpi-label mt-4 mb-2">Pausas</p>
                            <div class="row g-2 attendance-pause-actions">
                                <div class="col-6 col-md-4">
                                    <button type="button" id="btn-start-almuerzo" class="btn btn-outline-secondary w-100 py-2" disabled>
                                        <i class="bi bi-cup-hot me-1"></i> Almuerzo
                                    </button>
                                </div>
                                <div class="col-6 col-md-4">
                                    <button type="button" id="btn-start-reunion" class="btn btn-outline-secondary w-100 py-2" disabled>
                                        <i class="bi bi-people me-1"></i> Reunión
                                    </button>
                                </div>
                                <div class="col-12 col-md-4">
                                    <button type="button" id="btn-start-capacitacion" class="btn btn-outline-secondary w-100 py-2" disabled>
                                        <i class="bi bi-mortarboard me-1"></i> Capacitación
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-5"><div class="card card-win attendance-history-card p-3 p-md-4 shadow-sm border-0 h-100"><h5 class="mb-3 pb-3 border-bottom"><i class="bi bi-journal-text me-2 text-primary"></i>Historial del día</h5><div id="container-timeline-today" class="timeline-today" aria-live="polite"><p class="text-muted small text-center my-3">No hay eventos registrados hoy.</p></div></div></div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- ========================================================= -->
            <!-- PESTAÑA 2: VISTA GENERAL ADMINISTRADOR Y MONITOR EN VIVO  -->
            <!-- ========================================================= -->
            <div class="tab-pane fade" id="content-vista-admin" role="tabpanel">
                <!-- Tarjetas KPI Administrador -->
                <div class="row g-2 g-md-3 mb-4">
                    <div class="col-6 col-sm-4 col-md-2">
                        <div class="sgs-kpi attendance-kpi p-3 h-100">
                            <small class="sgs-kpi-label">Total de personal</small>
                            <h3 class="mb-0 sgs-kpi-value" id="kpi-admin-total">0</h3>
                        </div>
                    </div>
                    <div class="col-6 col-sm-4 col-md-2">
                        <div class="sgs-kpi attendance-kpi p-3 h-100 sgs-kpi--success">
                            <small class="sgs-kpi-label">En turno</small>
                            <h3 class="mb-0 sgs-kpi-value" id="kpi-admin-turno">0</h3>
                        </div>
                    </div>
                    <div class="col-6 col-sm-4 col-md-2">
                        <div class="sgs-kpi attendance-kpi p-3 h-100 sgs-kpi--warning">
                            <small class="sgs-kpi-label">En almuerzo</small>
                            <h3 class="mb-0 sgs-kpi-value" id="kpi-admin-almuerzo">0</h3>
                        </div>
                    </div>
                    <div class="col-6 col-sm-4 col-md-2">
                        <div class="sgs-kpi attendance-kpi p-3 h-100 sgs-kpi--info">
                            <small class="sgs-kpi-label">En reunión</small>
                            <h3 class="mb-0 sgs-kpi-value" id="kpi-admin-reunion">0</h3>
                        </div>
                    </div>
                    <div class="col-6 col-sm-4 col-md-2">
                        <div class="sgs-kpi attendance-kpi p-3 h-100 sgs-kpi--info">
                            <small class="sgs-kpi-label">Capacitación</small>
                            <h3 class="mb-0 sgs-kpi-value" id="kpi-admin-capacitacion">0</h3>
                        </div>
                    </div>
                    <div class="col-6 col-sm-4 col-md-2">
                        <div class="sgs-kpi attendance-kpi p-3 h-100">
                            <small class="sgs-kpi-label">Finalizado</small>
                            <h3 class="mb-0 sgs-kpi-value" id="kpi-admin-finalizado">0</h3>
                        </div>
                    </div>
                </div>

                <!-- Filtros & Herramientas -->
                <div class="card card-win p-3 mb-4 shadow-sm border-0">
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small fw-bold mb-1 text-secondary">Fecha de consulta</label>
                            <input type="date" id="filter-admin-fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small fw-bold mb-1 text-secondary">Filtrar por estado</label>
                            <select id="filter-admin-estado" class="form-select form-select-sm">
                                <option value="">Todos los estados</option>
                                <option value="En turno">En Turno</option>
                                <option value="En almuerzo">En Almuerzo</option>
                                <option value="En reunión">En Reunión</option>
                                <option value="En capacitación">En Capacitación</option>
                                <option value="Fuera de turno">Turno Finalizado</option>
                                <option value="Sin iniciar">Sin Iniciar / Ausente</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-md-4">
                            <label class="form-label small fw-bold mb-1 text-secondary">Buscar por nombre o DNI</label>
                            <input type="text" id="filter-admin-search" class="form-control form-control-sm" placeholder="Escriba nombre o DNI...">
                        </div>
                        <div class="col-12 col-sm-6 col-md-2 d-flex align-items-end gap-2">
                            <button type="button" id="btn-admin-refresh" class="btn btn-outline-dark btn-sm w-100 py-1.5" title="Actualizar Datos">
                                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                            </button>
                            <button type="button" id="btn-admin-export" class="btn btn-success btn-sm py-1.5 px-3" title="Exportar CSV">
                                <i class="bi bi-file-earmark-excel"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Monitor de Asistencia -->
                <div class="card card-win p-0 shadow-sm border-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="table-admin-asistencia">
                            <thead class="table-dark small text-uppercase">
                                <tr>
                                    <th class="ps-3">Supervisor</th>
                                    <th>Partner / área</th>
                                    <th>Estado actual</th>
                                    <th>Inicio de turno</th>
                                    <th>Fin de turno</th>
                                    <th>Horas Trab.</th>
                                    <th>Almuerzo</th>
                                    <th>Horas extra</th>
                                    <th class="text-end pe-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-admin-asistencia">
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">Cargando monitor de asistencia...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL PARA REGISTRAR MOTIVO DE REUNIÓN -->
<div class="modal fade" id="modalReunion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-info text-dark">
                <h6 class="modal-title fw-bold"><i class="bi bi-people-fill me-1"></i> Registrar reunión</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label for="txt-motivo-reunion" class="form-label small fw-bold text-secondary">Motivo o tema de la reunión</label>
                <input type="text" id="txt-motivo-reunion" class="form-control form-control-sm" placeholder="Ej: Coordinación de Operaciones WIN">
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btn-confirm-reunion" class="btn btn-sm btn-win fw-bold">Iniciar reunión</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE DETALLE Y AUDITORÍA DE SUPERVISOR (ADMIN) -->
<div class="modal fade" id="modalDetailSupervisor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="modalDetailTitle"><i class="bi bi-card-checklist me-1 text-warning"></i> Detalle de asistencia</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDetailBody">
                <p class="text-center text-muted">Cargando detalle...</p>
            </div>
        </div>
    </div>
</div>

<style>
.attendance-page-header {
    padding: 1.25rem;
    border: 1px solid var(--sgs-border);
    border-radius: 1rem;
    background: linear-gradient(110deg, #fff 0%, #fff8f3 100%);
}
.attendance-eyebrow { color: var(--sgs-muted); font-size: .72rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.attendance-command-card { border-top: 0 !important; }
.attendance-card-title { min-height: 52px; }
.shift-hero { background: var(--sgs-surface-alt); border: 1px solid var(--sgs-border); color: var(--sgs-ink); }
.shift-hero #lbl-timer-type { color: var(--sgs-muted); font-size: .72rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
.shift-hero #txt-timer-live { font-family: var(--sgs-font-display); color: var(--sgs-ink); letter-spacing: .02em; }
.shift-hero #txt-timer-sub { color: var(--sgs-muted); }
.shift-hero #lbl-timer-type, .shift-hero #txt-timer-live { position: relative; }
.attendance-metric { height: 100%; min-height: 94px; padding: .8rem; border: 1px solid var(--sgs-border); border-radius: .85rem; background: #fff; display: flex; flex-direction: column; gap: .15rem; }
.attendance-metric i { color: var(--sgs-orange); font-size: 1.05rem; }
.attendance-metric span { color: var(--sgs-muted); font-size: .75rem; font-weight: 600; }
.attendance-metric strong { color: var(--sgs-ink); font-size: 1rem; }
.attendance-primary-actions .btn { border-radius: .85rem; transition: transform .15s ease, box-shadow .15s ease; }
.attendance-primary-actions .btn:not(:disabled):hover { transform: translateY(-2px); box-shadow: 0 .6rem 1.1rem rgba(15, 23, 42, .13); }
.attendance-pause-actions .btn { min-height: 46px; border-radius: .7rem; }
.badge-sin-iniciar { background-color: var(--sgs-border); color: #475569; }

.timeline-today { border-left: 2px solid var(--sgs-border); padding-left: 18px; margin-left: 5px; }
.timeline-item { position: relative; margin-bottom: 16px; padding-bottom: 2px; }
.timeline-item::before {
    content: '';
    position: absolute;
    left: -21px;
    top: 5px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: var(--sgs-orange);
}
.custom-asist-pills .nav-link { color: #475569; }
.custom-asist-pills .nav-link.active { background-color: var(--sgs-orange) !important; color: #fff !important; }
.attendance-kpi { min-height: 105px; justify-content: center; transition: transform .15s ease; }
.attendance-supervisor-compact{padding-top:12px!important;padding-bottom:12px!important}
.attendance-supervisor-compact .attendance-page-header{display:none!important}
.attendance-supervisor-compact .attendance-page-header h3{font-size:1.15rem;margin-bottom:0!important}
.attendance-supervisor-compact .attendance-page-header p{display:none}
.attendance-supervisor-compact .attendance-command-card,
.attendance-supervisor-compact .attendance-history-card{padding:.85rem!important}
.attendance-supervisor-compact .attendance-card-title{min-height:38px;margin-bottom:.55rem!important;padding-bottom:.55rem!important}
.attendance-supervisor-compact .shift-hero{padding:.65rem!important;margin-bottom:.55rem!important}
.attendance-supervisor-compact .shift-hero .display-5{font-size:2rem;margin:.1rem 0!important}
.attendance-supervisor-compact .attendance-metrics{margin-bottom:.65rem!important}
.attendance-supervisor-compact .attendance-metric{min-height:62px;padding:.45rem .65rem;display:grid;grid-template-columns:auto 1fr;column-gap:.45rem;align-items:center}
.attendance-supervisor-compact .attendance-metric strong{grid-column:2;font-size:.85rem}
.attendance-supervisor-compact .attendance-command-card>.mb-4{margin-bottom:0!important}
.attendance-supervisor-compact .attendance-command-card h6{font-size:.8rem;margin-top:.55rem!important;margin-bottom:.45rem!important}
.attendance-supervisor-compact .attendance-primary-actions{margin-bottom:.35rem!important}
.attendance-supervisor-compact .attendance-primary-actions .btn{padding:.45rem!important;font-size:.82rem}
.attendance-supervisor-compact .attendance-primary-actions .btn i{display:inline!important;font-size:1rem!important;margin:0 .25rem 0 0!important}
.attendance-supervisor-compact .attendance-pause-actions .btn{min-height:36px;padding:.35rem!important;font-size:.78rem}
.attendance-supervisor-compact .timeline-item{margin-bottom:9px;font-size:.78rem}
.attendance-supervisor-compact .attendance-history-card h6{margin-bottom:.55rem!important;padding-bottom:.45rem!important}

@media (max-width: 767.98px) {
    .attendance-page-header { padding: 1rem; }
    .attendance-header-actions { width: 100%; }
    .attendance-header-actions > a { flex: 1 1 auto; }
    .custom-asist-pills { width: 100%; }
    .custom-asist-pills .nav-item { flex: 1 1 0; }
    .custom-asist-pills .nav-link { width: 100%; font-size: .82rem; padding-inline: .5rem !important; }
    .attendance-card-title { align-items: flex-start !important; }
    .shift-hero .display-5 { font-size: clamp(2rem, 12vw, 3rem); }
    .attendance-metric { min-height: 84px; }
    .attendance-supervisor-compact .attendance-page-header p{display:block}
    .attendance-supervisor-compact .attendance-metric{min-height:70px}
}
</style>

<script src="03_FRONTEND/src/assets/js/asistencia.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
