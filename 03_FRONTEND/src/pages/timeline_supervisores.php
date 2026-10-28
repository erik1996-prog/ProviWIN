<?php
// 03_FRONTEND/src/pages/timeline_supervisores.php - Línea de tiempo por horas de la jornada del personal directo.
// 2026-10-13: cada actividad se dibuja en su hora real (regla de horas arriba, una fila por supervisor) y al hacer clic
// se abre una ventana flotante con el detalle y las acciones (abrir la supervisión, reabrir el turno).
// 2026-10-20: el tiempo desde el inicio del turno hasta la primera actividad se dibuja y se suma (antes no se veía), y la
// lluvia de su zona: un bloque azul con gotas de su inicio a su fin (detrás de los demás: puede llover hasta en plena
// supervisión), con quién la avisó y la cerró y qué hacía cada uno; la nube junto al nombre sale solo mientras llueve.
// Datos: api/asistencia.php?action=timeline_diaria (bloques con ts_inicio / ts_fin) y api/dashboard.php?action=monitoreo_realtime.
// Estilos: sgs-theme.css, sección 33.
Auth::requireLogin();
$user = Auth::getLoggedInUser();
?>

<div class="container-fluid p-3 p-md-4">
    <!-- Estados de la jornada (ahora mismo) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--success p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-person-check"></i></span><small class="sgs-kpi-label">En turno</small><h3 class="mb-0 sgs-kpi-value" id="stat-en-turno">0</h3></div></div>
        <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--warning p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-pause-circle"></i></span><small class="sgs-kpi-label">En almuerzo / pausa</small><h3 class="mb-0 sgs-kpi-value" id="stat-en-break">0</h3></div></div>
        <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--info p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-laptop"></i></span><small class="sgs-kpi-label">Reunión, capacitación o remoto</small><h3 class="mb-0 sgs-kpi-value" id="stat-en-capacitacion">0</h3></div></div>
        <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-person-dash"></i></span><small class="sgs-kpi-label">Fuera de turno</small><h3 class="mb-0 sgs-kpi-value" id="stat-fuera-turno">0</h3></div></div>
    </div>

    <div class="card sgs-toolbar-card p-3 mb-3">
        <div class="row g-3 sgs-toolbar-filters">
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="filter-timeline-fecha">Fecha</label><input type="date" id="filter-timeline-fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-sm-6 col-lg-4"><label class="form-label" for="filter-timeline-buscar">Supervisor</label><input type="search" id="filter-timeline-buscar" class="form-control" placeholder="Buscar por nombre"></div>
            <div class="col-sm-6 col-lg-3 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="filter-timeline-activos" checked><label class="form-check-label" for="filter-timeline-activos">Solo con actividad</label></div></div>
            <div class="col-sm-6 col-lg-2 d-flex align-items-end"><button type="button" class="btn btn-outline-secondary w-100" id="btn-refresh-timeline"><i class="bi bi-arrow-repeat me-1"></i>Actualizar</button></div>
        </div>
    </div>

    <div class="card card-win p-3 p-md-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-calendar-range me-2 text-primary"></i>Jornada por horas</h5>
                <p class="text-muted small mb-0">Cada actividad aparece de la hora en que empezó a la hora en que terminó. Toque un bloque o el nombre para ver el detalle; con <i class="bi bi-chevron-right"></i> se despliega la lista de lo que atendió.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="sgs-segmented" role="group" aria-label="Escala de horas" id="tl-escala">
                    <button type="button" class="filter-tab-btn active" data-escala="1" title="Todo el día en pantalla">Día</button>
                    <button type="button" class="filter-tab-btn" data-escala="2" title="Horas el doble de anchas">×2</button>
                    <button type="button" class="filter-tab-btn" data-escala="4" title="Horas cuatro veces más anchas">×4</button>
                </div>
                <span class="sgs-counter" id="tl-contador"><span>—</span></span>
            </div>
        </div>
        <div class="sgs-gantt-legend mb-3" aria-label="Leyenda">
            <span><i class="bi bi-arrow-right-circle-fill is-inicio"></i>Inicio de turno</span>
            <span><i class="bi bi-arrow-left-circle-fill is-fin"></i>Fin de turno</span>
            <span><i class="sw is-turno"></i>Turno</span>
            <span><i class="sw sgs-gantt-blk is-sup-fin"></i>Supervisión finalizada</span>
            <span><i class="sw sgs-gantt-blk is-sup-curso"></i>Supervisión en curso</span>
            <span><i class="sw sgs-gantt-blk is-traslado"></i>Traslado</span>
            <span><i class="sw sgs-gantt-blk is-pausa"></i>Almuerzo, reunión o capacitación</span>
            <span><i class="sw sgs-gantt-blk is-remoto"></i>Trabajo remoto</span>
            <span><i class="sw sgs-gantt-blk is-lluvia"></i>Lluvia en su zona</span>
            <span><i class="sw is-ahora"></i>Ahora</span>
        </div>
        <div class="sgs-gantt" id="tl-gantt">
            <div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando la jornada…</div>
        </div>
    </div>
</div>

<script src="03_FRONTEND/src/assets/js/timeline_supervisores.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
