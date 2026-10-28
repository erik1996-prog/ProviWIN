<?php
// 03_FRONTEND/src/pages/admin_dashboard.php - Dashboard Administrador (Línea de Tiempo Horizontal, Indicadores & Mapa de Calor Interactivo)
Auth::requireLogin();
$user = Auth::getLoggedInUser();

if (!in_array($user['rol'], ['Administrador', 'Gerente', 'Coordinador'])) {
    echo '<div class="alert alert-danger m-4">Acceso denegado: Se requieren permisos de administración.</div>';
    exit;
}
// SGS: el calendario de asistencia no forma parte de este sistema; solo se muestra el tablero de supervisión.
?>

<!-- Leaflet CSS & JS para Mapa Interactivo con Zoom y Puntos Rojos -->

<div class="container-fluid p-3 p-md-4">
    <!-- FILTRO GENERAL: el período, el partner y el supervisor elegidos aquí recargan todas las secciones del Dashboard -->
    <div class="card sgs-toolbar-card p-3 mb-4" id="dashFiltros">
        <div class="sgs-toolbar">
            <div class="sgs-segmented" role="group" aria-label="Tipo de período" id="dash-modo">
                <button type="button" class="filter-tab-btn active" data-modo="mes">Por mes</button>
                <button type="button" class="filter-tab-btn" data-modo="rango">Rango de fechas</button>
            </div>
            <span class="sgs-counter" id="dash-periodo-txt"><i class="bi bi-calendar3"></i><span>—</span></span>
        </div>
        <div class="row g-3 sgs-toolbar-filters">
            <div class="col-6 col-lg-2 dash-modo-mes"><label class="form-label" for="dash-mes">Mes</label><select class="form-select" id="dash-mes"></select></div>
            <div class="col-6 col-lg-2 dash-modo-mes"><label class="form-label" for="dash-anio">Año</label><select class="form-select" id="dash-anio"></select></div>
            <div class="col-6 col-lg-2 dash-modo-rango d-none"><label class="form-label" for="ind-filter-f-ini">Desde</label><input type="date" class="form-control" id="ind-filter-f-ini" value="<?= date('Y-m-01') ?>"></div>
            <div class="col-6 col-lg-2 dash-modo-rango d-none"><label class="form-label" for="ind-filter-f-fin">Hasta</label><input type="date" class="form-control" id="ind-filter-f-fin" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label" for="ind-filter-partner">Partner</label><select id="ind-filter-partner" class="form-select"><option value="0">Todos los partners</option></select></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label" for="ind-filter-departamento">Departamento</label><select id="ind-filter-departamento" class="form-select"><option value="">Todos los departamentos</option></select></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label" for="ind-filter-supervisor">Supervisor</label><select id="ind-filter-supervisor" class="form-select"><option value="0">Todos los supervisores</option></select></div>
            <div class="col-12 col-lg-auto"><button type="button" id="btn-limpiar-dash" class="btn btn-outline-secondary w-100"><i class="bi bi-x-lg me-1"></i>Limpiar</button></div>
        </div>
    </div>

    <!-- 2026-10-20: la vista "Partners" pasó a su propio Dashboard de partners (pages/dashboard_partners.php). -->

    <!-- 2. NUEVO DASHBOARD DE INDICADORES DE SUPERVISIÓN & MAPA DE CALOR INTERACTIVO -->
    <div class="card card-win p-3 p-md-4 mb-4 shadow-sm border-0">
        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-1">
                    <i class="bi bi-bar-chart-line-fill me-2" style="color: var(--sgs-orange);"></i> Indicadores operativos
                </h5>
                <small class="text-secondary">Análisis de supervisiones por partner, rango de fechas, supervisor y departamento.</small>
            </div>
        </div>

        <!-- TARJETAS DE INDICADORES RESUMEN -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="sgs-kpi p-3 h-100">
                    <small class="sgs-kpi-label">Total de supervisiones</small>
                    <h3 class="mb-0 sgs-kpi-value" id="ind-stat-total">0</h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sgs-kpi p-3 h-100">
                    <small class="sgs-kpi-label">Partner destacado</small>
                    <h6 class="mb-0 sgs-kpi-value is-text" id="ind-stat-partner-top">—</h6>
                    <small class="sgs-kpi-sub" id="ind-stat-partner-sub"></small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sgs-kpi p-3 h-100">
                    <small class="sgs-kpi-label">Distrito principal</small>
                    <h6 class="mb-0 sgs-kpi-value is-text" id="ind-stat-distrito-top">—</h6>
                    <small class="sgs-kpi-sub" id="ind-stat-distrito-sub"></small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sgs-kpi p-3 h-100">
                    <small class="sgs-kpi-label">Cuadrilla destacada</small>
                    <h6 class="mb-0 sgs-kpi-value is-text" id="ind-stat-cuadrilla-top">—</h6>
                    <small class="sgs-kpi-sub" id="ind-stat-cuadrilla-sub"></small>
                </div>
            </div>
        </div>

        <!-- FILA 1: MAPA DE CALOR INTERACTIVO (LEAFLET.JS) Y DISTRITOS MÁS SUPERVISADOS -->
        <div class="row g-3 mb-4">
            <!-- MAPA DE CALOR INTERACTIVO CON ZOOM Y PUNTOS ROJOS DE ALTA DENSIDAD -->
            <div class="col-12 col-lg-7">
                <div class="card p-3 h-100 shadow-sm border">
                    <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-2">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i> Mapa de calor de supervisiones
                        </h6>
                        <span class="badge bg-danger text-white small"><i class="bi bi-fire me-1"></i> Puntos de supervisión</span>
                    </div>
                    <p class="text-secondary small mb-2">Use el mouse o la pantalla táctil para <strong>acercar (+)</strong>, <strong>alejar (-)</strong> y consultar cada punto de supervisión.</p>
                    <div id="leaflet-heatmap" style="height: 380px; width: 100%; border-radius: 10px; border: 1px solid var(--sgs-border-strong); z-index: 1;"></div>
                </div>
            </div>

            <!-- DISTRITOS MÁS SUPERVISADOS -->
            <div class="col-12 col-lg-5">
                <div class="card p-3 h-100 shadow-sm border">
                    <h6 class="fw-bold text-dark mb-2 border-bottom pb-2">
                        <i class="bi bi-building text-warning me-1"></i> Distritos más supervisados
                    </h6>
                    <div id="container-ranking-distritos" class="d-flex flex-column gap-2 overflow-auto" style="max-height: 380px;">
                        <!-- Se puebla dinámicamente con JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- FILA 2: MODALIDAD (en caliente / en frío). Responde a todos los filtros -->
        <div class="card p-3 shadow-sm border" id="cardModalidad">
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-thermometer-half me-1 text-primary"></i> Modalidad de las supervisiones</h6>
            <p class="text-muted small mb-3">Qué se supervisa más: en caliente (con la cuadrilla en campo) o en frío (después del servicio). No incluye checklist ni visitas de capacitación, que no tienen modalidad.</p>
            <div class="sgs-donut-wrap" id="dash-modalidad"><p class="text-muted small mb-0">Cargando…</p></div>
        </div>
    </div>
    <!-- META DE PRODUCTIVIDAD MENSUAL (personal directo). API: dashboard.php?action=productividad_mensual -->
    <div class="card card-win p-3 p-md-4 mb-4" id="cardProductividad">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-bullseye me-2 text-primary"></i>Meta de productividad</h5>
                <p class="text-muted small mb-0">Meta: <strong id="prod-meta-txt">4.5</strong> supervisiones finalizadas por día, de lunes a sábado. Si un supervisor trabaja un domingo, ese día también cuenta. El promedio usa los días con turno iniciado.</p>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-3"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-bullseye"></i></span><small class="sgs-kpi-label">Meta diaria</small><h3 class="mb-0 sgs-kpi-value" id="prod-k-meta">—</h3></div></div>
            <div class="col-6 col-xl-3"><div class="sgs-kpi p-3 h-100" id="prod-k-equipo-card"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-people"></i></span><small class="sgs-kpi-label">Promedio del equipo</small><h3 class="mb-0 sgs-kpi-value" id="prod-k-equipo">—</h3></div></div>
            <div class="col-6 col-xl-3"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-trophy"></i></span><small class="sgs-kpi-label">Cumplen la meta</small><h3 class="mb-0 sgs-kpi-value" id="prod-k-cumplen">—</h3></div></div>
            <div class="col-6 col-xl-3"><div class="sgs-kpi p-3 h-100"><span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-calendar-week"></i></span><small class="sgs-kpi-label">Días hábiles transcurridos</small><h3 class="mb-0 sgs-kpi-value" id="prod-k-habiles">—</h3></div></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Supervisor</th><th class="text-center">Días trabajados</th><th class="text-center">Finalizadas</th><th class="text-center">Promedio por día</th><th>Avance del mes</th><th>Estado</th></tr></thead>
                <tbody id="prod-tabla"><tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="card card-win p-3 p-md-4 mb-4">
        <h5 class="fw-bold mb-1"><i class="bi bi-briefcase-fill me-2 text-primary"></i>Partners y cuadrillas más supervisados</h5>
        <p class="text-muted small mb-3">Según las supervisiones de los supervisores directos y los filtros de arriba.</p>
        <!-- FILA 2: PARTNERS Y CUADRILLAS MÁS SUPERVISADAS -->
        <div class="row g-3">
            <!-- PARTNERS MÁS SUPERVISADOS -->
            <div class="col-12 col-lg-6">
                <div class="card p-3 h-100 shadow-sm border">
                    <h6 class="fw-bold text-dark mb-2 border-bottom pb-2">
                        <i class="bi bi-briefcase-fill text-info me-1"></i> Partner más supervisado
                    </h6>
                    <div id="container-ranking-partners" class="d-flex flex-column gap-2">
                        <!-- Se puebla dinámicamente con JS -->
                    </div>
                </div>
            </div>

            <!-- CUADRILLAS MÁS SUPERVISADAS -->
            <div class="col-12 col-lg-6">
                <div class="card p-3 h-100 shadow-sm border">
                    <h6 class="fw-bold text-dark mb-2 border-bottom pb-2">
                        <i class="bi bi-people-fill text-success me-1"></i> Cuadrilla o técnico más supervisado
                    </h6>
                    <div id="container-ranking-cuadrillas" class="d-flex flex-column gap-2">
                        <!-- Se puebla dinámicamente con JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. SECCIÓN MATRIZ MENSUAL DE SUPERVISIONES -->
    <div class="card card-win p-3 p-md-4 mb-4 shadow-sm border-0">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-3">
            <div>
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-grid-3x3-gap-fill me-2" style="color: var(--sgs-orange);"></i> Matriz mensual de supervisiones</h5>
                <small class="text-secondary">Supervisiones finalizadas por día y supervisor en el período del filtro.</small>
            </div>

        </div>

        <!-- Contenedor con Scroll Fijo y Columnas Sticky -->
        <div class="matriz-scroll-box">
            <table class="table-matriz align-middle" id="tabla-matriz-mensual">
                <thead id="thead-matriz">
                    <!-- Se puebla con JS -->
                </thead>
                <tbody id="tbody-matriz">
                    <!-- Se puebla con JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ESTILOS ADICIONALES PARA LÍNEA DE TIEMPO HORIZONTAL Y MAPA DE CALOR -->
<style>
.bg-pausa {
    background-color: var(--sgs-brown) !important;
}

/* TRACK HORIZONTAL DE LÍNEA DE TIEMPO */
.timeline-horizontal-track {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    padding: 10px 4px;
    background-color: var(--sgs-surface-alt);
    border-radius: 8px;
    border: 1px solid var(--sgs-border);
}
.timeline-block-item {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    white-space: nowrap;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.heatmap-card-item {
    border-radius: 8px;
    padding: 10px;
    border-left: 4px solid #3b82f6;
    background: var(--sgs-surface-alt);
    transition: transform 0.15s ease;
}
.heatmap-card-item:hover {
    transform: translateY(-2px);
}
</style>

<script src="03_FRONTEND/src/assets/js/admin_dashboard.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
