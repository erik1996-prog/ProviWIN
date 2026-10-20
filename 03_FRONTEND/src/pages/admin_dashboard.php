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

    <!-- Vistas del Dashboard (2026-10-12): los filtros de arriba aplican a las dos -->
    <div class="sgs-segmented mb-4" role="tablist" aria-label="Vista del Dashboard" id="dash-vistas">
        <button type="button" class="filter-tab-btn active" role="tab" aria-selected="true" data-vista="supervision"><i class="bi bi-clipboard-data me-1"></i>Supervisión</button>
        <button type="button" class="filter-tab-btn" role="tab" aria-selected="false" data-vista="partners"><i class="bi bi-briefcase me-1"></i>Partners</button>
    </div>

    <div id="dash-vista-supervision">





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
    <script>
    // Meta de productividad: promedio por día trabajado frente a la meta diaria, y avance frente a la meta del mes a la fecha.
    (() => {
        const $ = (id) => document.getElementById(id);
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const estados = {
            cumple: ['bg-success', 'Cumple'], cerca: ['bg-warning', 'Cerca de la meta'],
            bajo: ['bg-danger', 'Bajo la meta'], sin_turnos: ['bg-secondary', 'Sin turnos']
        };
        const num = (v, dec = 2) => v === null || v === undefined ? '—' : Number(v).toLocaleString('es-PE', { minimumFractionDigits: dec, maximumFractionDigits: dec });

        async function cargar() {
            const tbody = $('prod-tabla');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr>';
            try {
                const res = await fetch('api/dashboard.php?action=productividad_mensual&' + new URLSearchParams(window.sgsDashFiltro()).toString());
                const json = await res.json();
                if (json.status !== 'success') throw new Error(json.message || 'No se pudo cargar la meta.');
                const d = json.data, r = d.resumen;
                $('prod-meta-txt').textContent = num(d.meta_diaria, 1);
                $('prod-k-meta').textContent = num(d.meta_diaria, 1);
                $('prod-k-equipo').textContent = r.promedio_equipo === null ? '—' : `${num(r.promedio_equipo)} / día`;
                const card = $('prod-k-equipo-card');
                card.classList.remove('sgs-kpi--success', 'sgs-kpi--warning', 'sgs-kpi--danger');
                if (r.promedio_equipo !== null) card.classList.add(r.promedio_equipo >= d.meta_diaria ? 'sgs-kpi--success' : (r.promedio_equipo >= d.meta_diaria * 0.8 ? 'sgs-kpi--warning' : 'sgs-kpi--danger'));
                $('prod-k-cumplen').textContent = `${r.cumplen} de ${r.supervisores}`;
                $('prod-k-habiles').textContent = `${d.dias_habiles_transcurridos} de ${d.dias_habiles_mes}`;
                if (!d.supervisores.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-4 mb-1"></i>No hay supervisores directos activos.</td></tr>'; return; }
                tbody.innerHTML = d.supervisores.map((s) => {
                    const [cls, txt] = estados[s.estado] || estados.sin_turnos;
                    const dias = s.dias_trabajados + (s.domingos_trabajados ? ` <small class="text-muted">(${s.domingos_trabajados} dom.)</small>` : '');
                    const pct = s.avance_pct ?? 0;
                    return `<tr>
                        <td><strong>${esc(s.nombre)}</strong></td>
                        <td class="text-center">${dias}</td>
                        <td class="text-center">${s.finalizadas}</td>
                        <td class="text-center"><strong>${num(s.promedio_dia_trabajado)}</strong> <small class="text-muted">/ ${num(d.meta_diaria, 1)}</small></td>
                        <td><div class="sgs-meta-avance"><progress class="sgs-meta-bar is-${esc(s.estado)}" max="100" value="${Math.min(100, pct)}" aria-label="Avance ${pct} %"></progress><small>${s.finalizadas} de ${num(s.meta_a_la_fecha, 1)} · ${s.avance_pct === null ? '—' : pct + ' %'}</small></div></td>
                        <td><span class="badge ${cls}">${txt}</span></td>
                    </tr>`;
                }).join('');
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${esc(e.message || 'No se pudo cargar la meta.')}</td></tr>`;
            }
        }
        window.sgsCargarProductividad = cargar; // lo llama el filtro general
    })();
    </script>

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

    <div id="dash-vista-partners" class="d-none">
    <!-- 3. CUMPLIMIENTO DE PARTNERS (atención en 24 h). Usa los filtros de partner y fechas de la sección anterior. -->
    <div class="card card-win p-3 p-md-4 mb-4" id="partner-compliance">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-stopwatch me-2" style="color: var(--sgs-orange);"></i>Cumplimiento de partners</h5>
                <small class="text-secondary">Órdenes transferidas a supervisores de contratas. Plazo de atención: 24 horas; si vence, la orden se bloquea y cuenta como incumplimiento.</small>
            </div>
            <span class="badge sgs-role-badge" id="pc-periodo">—</span>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-4 col-xxl-2"><div class="sgs-kpi sgs-kpi--info p-3 h-100"><small class="sgs-kpi-label">Asignadas</small><h3 class="mb-0 sgs-kpi-value" id="pc-asignadas">0</h3></div></div>
            <div class="col-6 col-md-4 col-xxl-2"><div class="sgs-kpi sgs-kpi--success p-3 h-100"><small class="sgs-kpi-label">Atendidas a tiempo</small><h3 class="mb-0 sgs-kpi-value" id="pc-a-tiempo">0</h3></div></div>
            <div class="col-6 col-md-4 col-xxl-2"><div class="sgs-kpi sgs-kpi--danger p-3 h-100"><small class="sgs-kpi-label">Bloqueadas</small><h3 class="mb-0 sgs-kpi-value" id="pc-bloqueadas">0</h3></div></div>
            <div class="col-6 col-md-4 col-xxl-2"><div class="sgs-kpi sgs-kpi--warning p-3 h-100"><small class="sgs-kpi-label">En plazo</small><h3 class="mb-0 sgs-kpi-value" id="pc-en-plazo">0</h3></div></div>
            <div class="col-6 col-md-4 col-xxl-2"><div class="sgs-kpi sgs-kpi--info p-3 h-100"><small class="sgs-kpi-label">Respuesta promedio</small><h3 class="mb-0 sgs-kpi-value" id="pc-promedio">—</h3></div></div>
            <div class="col-6 col-md-4 col-xxl-2"><div class="sgs-kpi sgs-kpi--info p-3 h-100" id="pc-cumplimiento-card"><small class="sgs-kpi-label">Cumplimiento</small><h3 class="mb-0 sgs-kpi-value" id="pc-cumplimiento">—</h3></div></div>
        </div>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Partner</th><th class="text-end">Asignadas</th><th class="text-end">A tiempo</th><th class="text-end">Bloqueadas</th><th class="text-end">Cumplimiento</th></tr></thead>
                        <tbody id="pc-tabla-partners"><tr><td colspan="5" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="table-responsive" style="max-height: 340px;">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Orden bloqueada</th><th>Partner / supervisor</th><th class="text-end">Vencida hace</th></tr></thead>
                        <tbody id="pc-tabla-bloqueadas"><tr><td colspan="3" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <h6 class="fw-bold mt-4 mb-2"><i class="bi bi-person-badge me-1 text-primary"></i>Por supervisor del partner</h6>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Supervisor</th><th>Partner</th><th class="text-end">Asignadas</th><th class="text-end">A tiempo</th><th class="text-end">En plazo</th><th class="text-end">Bloqueadas</th><th class="text-end">Respuesta promedio</th><th class="text-end">Cumplimiento</th></tr></thead>
                <tbody id="pc-tabla-supervisores"><tr><td colspan="8" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- OBSERVACIONES Y DESCARGOS DE LOS PARTNERS (2026-10-13). API: dashboard.php?action=observaciones_partners -->
    <div class="card card-win p-3 p-md-4 mb-4" id="partner-observaciones">
        <div class="mb-3">
            <h5 class="fw-bold mb-1"><i class="bi bi-chat-square-text me-2 text-primary"></i>Observaciones y descargos</h5>
            <small class="text-secondary">Observaciones de campo y de checklist hechas a los partners en el período, y cómo respondieron dentro del plazo.</small>
        </div>
        <div class="row row-cols-2 row-cols-lg-5 g-3 mb-3">
            <div class="col"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Observaciones</small><h3 class="mb-0 sgs-kpi-value" id="po-total">0</h3></div></div>
            <div class="col"><div class="sgs-kpi sgs-kpi--success p-3 h-100"><small class="sgs-kpi-label">Respondidas a tiempo</small><h3 class="mb-0 sgs-kpi-value" id="po-a-tiempo">0</h3></div></div>
            <div class="col"><div class="sgs-kpi sgs-kpi--danger p-3 h-100"><small class="sgs-kpi-label">Sin respuesta vencidas</small><h3 class="mb-0 sgs-kpi-value" id="po-vencidas">0</h3></div></div>
            <div class="col"><div class="sgs-kpi sgs-kpi--warning p-3 h-100"><small class="sgs-kpi-label">Pendientes en plazo</small><h3 class="mb-0 sgs-kpi-value" id="po-en-plazo">0</h3></div></div>
            <div class="col"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Descargos por revisar</small><h3 class="mb-0 sgs-kpi-value" id="po-revision">0</h3></div></div>
            <div class="col"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Respuesta promedio</small><h3 class="mb-0 sgs-kpi-value" id="po-promedio">—</h3></div></div>
            <div class="col"><div class="sgs-kpi sgs-kpi--success p-3 h-100"><small class="sgs-kpi-label">Descargos validados</small><h3 class="mb-0 sgs-kpi-value" id="po-validadas">0</h3></div></div>
            <div class="col"><div class="sgs-kpi sgs-kpi--danger p-3 h-100"><small class="sgs-kpi-label">Penalizadas</small><h3 class="mb-0 sgs-kpi-value" id="po-penalizadas">0</h3></div></div>
            <div class="col"><div class="sgs-kpi p-3 h-100" id="po-pct-card"><small class="sgs-kpi-label">Respuesta a tiempo</small><h3 class="mb-0 sgs-kpi-value" id="po-pct">—</h3></div></div>
            <div class="col"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Penalidad sobre evaluadas</small><h3 class="mb-0 sgs-kpi-value" id="po-pct-pen">—</h3></div></div>
        </div>
        <div class="row g-3">
            <div class="col-12">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Partner</th><th class="text-end">Observaciones</th><th class="text-end">A tiempo</th><th class="text-end">Vencidas sin respuesta</th><th class="text-end">Por revisar</th><th class="text-end">Validadas</th><th class="text-end">Penalizadas</th><th class="text-end">Respuesta a tiempo</th></tr></thead>
                        <tbody id="po-tabla-partners"><tr><td colspan="8" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card p-3 h-100 shadow-sm border">
                    <h6 class="fw-bold mb-2 border-bottom pb-2"><i class="bi bi-list-ol me-1 text-primary"></i>Motivos más observados</h6>
                    <div id="po-motivos" class="d-flex flex-column gap-2"></div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card p-3 h-100 shadow-sm border">
                    <h6 class="fw-bold mb-2 border-bottom pb-2"><i class="bi bi-bar-chart-steps me-1 text-primary"></i>Por gravedad</h6>
                    <div id="po-gravedad" class="d-flex flex-column gap-2"></div>
                </div>
            </div>
        </div>
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

<script>
    // Bloque de supervisión: En curso (borde naranja) → Finalizada (verde). Horas reales de inicio y fin.
    function sgsBloqueSupervision(b, extra = '') {
        const fin = b.estado === 'finalizada';
        const min = b.duracion_minutos !== null && b.duracion_minutos !== undefined ? `${b.duracion_minutos} min` : '';
        // Inicio · Fin · minutos (en curso: Inicio · En curso · minutos transcurridos)
        const partes = [`Inicio ${b.hora_inicio}`, fin ? `Fin ${b.hora_fin || 'sin registro'}` : 'En curso', min].filter(Boolean);
        return `<div class="timeline-block-item ${b.color_class || ''}" title="${fin ? 'Finalizada' : 'En curso'} · Técnico: ${escapeHtml(b.tecnico || '')}${extra ? ' · Partner: ' + escapeHtml(b.partner || '') : ''}">
            <i class="bi ${fin ? 'bi-check2-circle' : 'bi-hourglass-split'}" aria-hidden="true"></i>
            <div><strong class="d-block">${escapeHtml(b.codigo)}</strong><small class="sgs-tl-time">${partes.join(' · ')}</small></div>
        </div>`;
    }

    let leafletMap = null;
    let leafletMarkersLayer = null;

    document.addEventListener('DOMContentLoaded', () => {
        cargarMonitoreoRealTime();
        cargarTimelineSupervisores();
        cargarFiltrosIndicadores();
        iniciarFiltroGeneral();

        // Listeners
        const btnRefTL = document.getElementById('btn-refresh-timeline');
        const filterDateTL = document.getElementById('filter-timeline-fecha');
        if (btnRefTL) btnRefTL.addEventListener('click', cargarTimelineSupervisores);
        if (filterDateTL) filterDateTL.addEventListener('change', cargarTimelineSupervisores);

        const btnFiltrarInd = document.getElementById('btn-filtrar-indicadores');
        if (btnFiltrarInd) btnFiltrarInd.addEventListener('click', aplicarFiltroGeneral);

        // Auto refresco moderado; las pestañas en segundo plano no consultan.
        setInterval(() => {
            if (document.hidden) return;
            cargarMonitoreoRealTime();
            cargarTimelineSupervisores();
        }, 60000);
    });

    async function cargarMonitoreoRealTime() {
        const targets = {
            enTurno: document.getElementById('stat-en-turno'),
            enCapacitacion: document.getElementById('stat-en-capacitacion'),
            enBreak: document.getElementById('stat-en-break'),
            fueraTurno: document.getElementById('stat-fuera-turno')
        };
        // El bloque de resumen en tiempo real es opcional. Si la vista no lo
        // presenta, evitamos una petición innecesaria y referencias nulas.
        if (!Object.values(targets).some(Boolean)) return;
        try {
            const res = await fetch('api/dashboard.php?action=monitoreo_realtime');
            const data = await res.json();

            if (data.status === 'success') {
                if (targets.enTurno) targets.enTurno.textContent = data.resumen.en_turno;
                if (targets.enCapacitacion) targets.enCapacitacion.textContent = data.resumen.en_capacitacion;
                if (targets.enBreak) targets.enBreak.textContent = data.resumen.en_break;
                if (targets.fueraTurno) targets.fueraTurno.textContent = data.resumen.fuera_turno;
            }
        } catch (e) {
            console.error('Error realtime:', e);
        }
    }

    async function cargarTimelineSupervisores() {
        const container = document.getElementById('container-supervisors-timeline');
        const fechaVal = document.getElementById('filter-timeline-fecha')?.value || '';

        if (!container) return;

        try {
            const resp = await fetch(`api/asistencia.php?action=timeline_diaria&fecha=${fechaVal}`);
            const data = await resp.json();

            if (data.status !== 'success' || !data.supervisores) {
                container.innerHTML = '<div class="text-center text-muted py-3">No hay datos de línea de tiempo disponibles.</div>';
                return;
            }

            let html = '';

            data.supervisores.forEach(s => {
                let badgeClass = 'bg-secondary text-white';
                if (s.estado_general === 'Activo') badgeClass = 'bg-success text-white';
                else if (s.es_licencia) badgeClass = 'bg-info text-dark fw-bold';

                // Avatar / Foto de Perfil
                let avatarHtml = '';
                if (s.avatar) {
                    avatarHtml = `<img src="${escapeHtml(s.avatar)}" class="rounded-circle border border-warning shadow-sm me-2" style="width:40px; height:40px; object-fit:cover;">`;
                } else {
                    avatarHtml = `<div class="rounded-circle text-white d-flex align-items-center justify-content-center fw-bold shadow-sm me-2" style="width:40px;height:40px;background-color:var(--sgs-orange);font-size:.82rem;letter-spacing:.03em;">${nameInitials(s.nombre_corto || s.nombre)}</div>`;
                }

                // Generar Bloques Horizontales del Stream
                let horizontalBlocksHtml = '';

                if (s.es_licencia) {
                    horizontalBlocksHtml = `
                        <div class="alert alert-info border border-info mb-0 p-2 rounded-3 w-100">
                            <i class="bi bi-sun-fill text-info me-1"></i>
                            <strong>${escapeHtml(s.estado_general)}</strong>
                            <small class="d-block text-secondary mt-1">Período autorizado registrado en el sistema.</small>
                        </div>
                    `;
                } else if (s.timeline_blocks && s.timeline_blocks.length > 0) {
                    horizontalBlocksHtml += '<div class="timeline-horizontal-track">';

                    s.timeline_blocks.forEach(b => {
                        if (b.type === 'shift_start') {
                            horizontalBlocksHtml += `
                                <div class="timeline-block-item ${b.color_class}">
                                    <i class="bi bi-arrow-right-circle-fill me-1 fs-6"></i>
                                    <strong>Inicio: ${b.hora}</strong>
                                </div>
                            `;
                        } else if (b.type === 'supervision') {
                            horizontalBlocksHtml += sgsBloqueSupervision(b, 'partner');
                        } else if (b.type === 'travel') {
                            horizontalBlocksHtml += `
                                <div class="timeline-block-item ${b.color_class}">
                                    <i class="bi bi-car-front-fill me-1 fs-6 text-dark"></i>
                                    <span><i class="bi bi-car-front me-1"></i>${b.duracion_minutos} min de viaje</span>
                                </div>
                            `;
                        } else if (b.type === 'pause') {
                            horizontalBlocksHtml += `
                                <div class="timeline-block-item ${b.color_class}">
                                    <i class="bi bi-cup-hot-fill me-1 fs-6"></i>
                                    <div>
                                        <strong class="d-block"><i class="bi bi-cup-hot me-1"></i>${escapeHtml(b.title)}</strong>
                                        <small class="text-white-50 text-xs">${b.hora_inicio} - ${b.hora_fin}</small>
                                    </div>
                                </div>
                            `;
                        } else if (b.type === 'shift_end') {
                            horizontalBlocksHtml += `
                                <div class="timeline-block-item ${b.color_class}">
                                    <i class="bi bi-arrow-left-circle-fill me-1 fs-6"></i>
                                    <strong>Fin: ${b.hora}</strong>
                                </div>
                            `;
                        }
                    });

                    horizontalBlocksHtml += '</div>';
                } else {
                    horizontalBlocksHtml = '<div class="small text-muted py-2 bg-light rounded px-3 border">Sin actividad registrada en la fecha seleccionada.</div>';
                }

                html += `
                    <div class="card card-win p-3 shadow-sm border border-secondary-subtle">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-2 gap-2">
                            <div class="d-flex align-items-center overflow-hidden">
                                ${avatarHtml}
                                <div class="overflow-hidden">
                                    <h6 class="fw-bold text-dark mb-0 text-truncate" title="${escapeHtml(s.nombre_completo)}">${escapeHtml(s.nombre_corto)}</h6>
                                    <small class="text-secondary">${escapeHtml(s.partner)}</small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge ${badgeClass} small">${escapeHtml(s.estado_general)}</span>
                                <span class="badge bg-light text-dark border"><i class="bi bi-journal-text me-1"></i> ${s.total_supervisiones} sup</span>
                            </div>
                        </div>

                        <!-- Track Horizontal -->
                        <div class="mt-2">
                            ${horizontalBlocksHtml}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;

        } catch (e) {
            console.error('Error al cargar timeline supervisores:', e);
            container.innerHTML = '<div class="text-center text-danger py-3">Error al cargar datos de la línea de tiempo.</div>';
        }
    }

    // Cumplimiento de partners (atención en 24 h)
    async function cargarCumplimientoPartners() {
        const params = new URLSearchParams({
            action: 'cumplimiento_partners',
            partner_id: document.getElementById('ind-filter-partner')?.value || 0,
            departamento: document.getElementById('ind-filter-departamento')?.value || '',
            fecha_inicio: document.getElementById('ind-filter-f-ini')?.value || '',
            fecha_fin: document.getElementById('ind-filter-f-fin')?.value || '',
        });
        const tPartners = document.getElementById('pc-tabla-partners');
        const tBloq = document.getElementById('pc-tabla-bloqueadas');
        try {
            const resp = await fetch('api/dashboard.php?' + params.toString(), { cache: 'no-store' });
            const data = await resp.json();
            if (data.status !== 'success') throw new Error(data.message || 'Sin datos');
            const r = data.resumen || {};
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            const fmtMin = (m) => m == null ? '—' : (m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`);
            const fmtFecha = (f) => String(f || '').split('-').reverse().join('/');
            set('pc-periodo', `${fmtFecha(data.periodo.inicio)} – ${fmtFecha(data.periodo.fin)}`);
            set('pc-asignadas', r.asignadas || 0);
            set('pc-a-tiempo', r.a_tiempo || 0);
            set('pc-bloqueadas', r.bloqueadas || 0);
            set('pc-en-plazo', r.en_plazo || 0);
            set('pc-promedio', fmtMin(r.minutos_promedio));
            set('pc-cumplimiento', r.cumplimiento == null ? '—' : `${r.cumplimiento}%`);
            const card = document.getElementById('pc-cumplimiento-card');
            if (card) card.className = 'sgs-kpi p-3 h-100 ' + (r.cumplimiento == null ? 'sgs-kpi--info' : r.cumplimiento >= 90 ? 'sgs-kpi--success' : r.cumplimiento >= 70 ? 'sgs-kpi--warning' : 'sgs-kpi--danger');

            const partners = data.partners || [];
            tPartners.innerHTML = partners.length ? partners.map((p) => {
                const pct = p.cumplimiento == null ? '—' : `${p.cumplimiento}%`;
                const cls = p.cumplimiento == null ? 'bg-secondary' : p.cumplimiento >= 90 ? 'bg-success' : p.cumplimiento >= 70 ? 'bg-warning' : 'bg-danger';
                return `<tr><td class="fw-bold">${escapeHtml(p.nombre_partner)}</td><td class="text-end">${p.asignadas}</td><td class="text-end">${p.a_tiempo}</td><td class="text-end">${p.bloqueadas}</td><td class="text-end"><span class="badge ${cls}">${pct}</span></td></tr>`;
            }).join('') : '<tr><td colspan="5" class="text-center text-muted py-4">No hay órdenes asignadas a partners en el periodo.</td></tr>';

            const bloq = data.bloqueadas || [];
            tBloq.innerHTML = bloq.length ? bloq.map((b) => {
                const horas = Number(b.horas_vencida || 0);
                const hace = horas >= 24 ? `${Math.floor(horas / 24)} d ${horas % 24} h` : `${horas} h`;
                return `<tr><td><span class="fw-bold">${escapeHtml(b.codigo_supervision)}</span><br><small class="text-muted">${escapeHtml(b.departamento || '')}</small></td><td>${escapeHtml(b.nombre_partner)}<br><small class="text-muted">${escapeHtml(b.supervisor_partner || '')}</small></td><td class="text-end"><span class="badge bg-danger">${hace}</span></td></tr>`;
            }).join('') : '<tr><td colspan="3" class="text-center text-muted py-4">Sin órdenes bloqueadas en el periodo.</td></tr>';

            const tSup = document.getElementById('pc-tabla-supervisores');
            const sups = data.supervisores || [];
            if (tSup) tSup.innerHTML = sups.length ? sups.map((u) => {
                const pct = u.cumplimiento == null ? '—' : `${u.cumplimiento}%`;
                const cls = u.cumplimiento == null ? 'bg-secondary' : u.cumplimiento >= 90 ? 'bg-success' : u.cumplimiento >= 70 ? 'bg-warning' : 'bg-danger';
                return `<tr><td data-label="Supervisor" class="fw-bold">${escapeHtml(u.nombre_completo)}</td><td data-label="Partner">${escapeHtml(u.nombre_partner)}</td><td data-label="Asignadas" class="text-end">${Number(u.asignadas)}</td><td data-label="A tiempo" class="text-end">${Number(u.a_tiempo)}</td><td data-label="En plazo" class="text-end">${Number(u.en_plazo)}</td><td data-label="Bloqueadas" class="text-end">${Number(u.bloqueadas)}</td><td data-label="Respuesta promedio" class="text-end">${fmtMin(u.minutos_promedio)}</td><td data-label="Cumplimiento" class="text-end"><span class="badge ${cls}">${pct}</span></td></tr>`;
            }).join('') : '<tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>Sin órdenes asignadas a supervisores de partners en el periodo.</td></tr>';
        } catch (error) {
            if (tPartners) tPartners.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">No se pudo cargar el cumplimiento de partners.</td></tr>';
            if (tBloq) tBloq.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-4">No se pudo cargar el detalle.</td></tr>';
        }
    }

    // Observaciones y descargos de los partners (mismos filtros: partner, departamento y período)
    async function cargarObservacionesPartners() {
        const params = new URLSearchParams({
            action: 'observaciones_partners',
            partner_id: document.getElementById('ind-filter-partner')?.value || 0,
            departamento: document.getElementById('ind-filter-departamento')?.value || '',
            fecha_inicio: document.getElementById('ind-filter-f-ini')?.value || '',
            fecha_fin: document.getElementById('ind-filter-f-fin')?.value || '',
        });
        const tabla = document.getElementById('po-tabla-partners');
        const motivosBox = document.getElementById('po-motivos');
        const gravedadBox = document.getElementById('po-gravedad');
        if (!tabla) return;
        const vacio = '<div class="text-center text-muted py-3"><i class="bi bi-inbox me-1"></i>Sin observaciones en el periodo.</div>';
        try {
            const resp = await fetch('api/dashboard.php?' + params.toString(), { cache: 'no-store' });
            const data = await resp.json();
            if (data.status !== 'success') throw new Error(data.message || 'Sin datos');
            const r = data.resumen || {};
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            const fmtMin = (m) => m == null ? '—' : (m >= 1440 ? `${Math.floor(m / 1440)} d ${Math.floor((m % 1440) / 60)} h` : m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`);
            const pct = (v) => v == null ? '—' : `${v}%`;
            const tono = (v) => v == null ? 'bg-secondary' : v >= 90 ? 'bg-success' : v >= 70 ? 'bg-warning' : 'bg-danger';
            set('po-total', r.total || 0);
            set('po-a-tiempo', r.a_tiempo || 0);
            set('po-vencidas', r.sin_respuesta_vencidas || 0);
            set('po-en-plazo', r.en_plazo || 0);
            set('po-revision', r.en_revision || 0);
            set('po-promedio', fmtMin(r.minutos_respuesta));
            set('po-validadas', r.validadas || 0);
            set('po-penalizadas', r.penalizadas || 0);
            set('po-pct', pct(r.pct_a_tiempo));
            set('po-pct-pen', pct(r.pct_penalizadas));
            const card = document.getElementById('po-pct-card');
            if (card) card.className = 'sgs-kpi p-3 h-100 ' + (r.pct_a_tiempo == null ? '' : r.pct_a_tiempo >= 90 ? 'sgs-kpi--success' : r.pct_a_tiempo >= 70 ? 'sgs-kpi--warning' : 'sgs-kpi--danger');

            const partners = data.partners || [];
            tabla.innerHTML = partners.length ? partners.map((p) => `<tr><td data-label="Partner" class="fw-bold">${escapeHtml(p.nombre_partner)}</td><td data-label="Observaciones" class="text-end">${Number(p.total)}</td><td data-label="A tiempo" class="text-end">${Number(p.a_tiempo)}</td><td data-label="Vencidas sin respuesta" class="text-end">${Number(p.sin_respuesta_vencidas)}</td><td data-label="Por revisar" class="text-end">${Number(p.en_revision)}</td><td data-label="Validadas" class="text-end">${Number(p.validadas)}</td><td data-label="Penalizadas" class="text-end">${Number(p.penalizadas)}</td><td data-label="Respuesta a tiempo" class="text-end"><span class="badge ${tono(p.pct_a_tiempo)}">${pct(p.pct_a_tiempo)}</span></td></tr>`).join('')
                : '<tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>Sin observaciones a partners en el periodo.</td></tr>';

            const barra = (etiqueta, sub, total, max) => `<div><div class="d-flex justify-content-between gap-2 small"><span class="fw-bold text-truncate" title="${escapeHtml(etiqueta)}">${escapeHtml(etiqueta)}</span><span class="text-muted text-nowrap">${sub}</span></div><div class="progress mt-1" role="progressbar" aria-label="${escapeHtml(etiqueta)}" aria-valuenow="${Number(total)}" aria-valuemin="0" aria-valuemax="${Number(max)}"><div class="progress-bar bg-info" style="width:${Math.round(Number(total) * 100 / Math.max(1, Number(max)))}%"></div></div></div>`;
            const motivos = data.motivos || [];
            const maxM = motivos.reduce((m, x) => Math.max(m, Number(x.total)), 0);
            motivosBox.innerHTML = motivos.length ? motivos.map((m) => barra(m.motivo, `${Number(m.total)} · ${escapeHtml(m.tipo)}${Number(m.penalizadas) ? ` · ${Number(m.penalizadas)} penalizadas` : ''}`, m.total, maxM)).join('') : vacio;
            const orden = ['Muy Grave', 'Grave', 'Media', 'Leve', 'Checklist'];
            const grav = (data.gravedad || []).sort((a, b) => orden.indexOf(a.gravedad) - orden.indexOf(b.gravedad));
            const maxG = grav.reduce((m, x) => Math.max(m, Number(x.total)), 0);
            gravedadBox.innerHTML = grav.length ? grav.map((g) => barra(g.gravedad === 'Checklist' ? 'Checklist (ítems observados)' : g.gravedad, String(Number(g.total)), g.total, maxG)).join('') : vacio;
        } catch (error) {
            tabla.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">No se pudieron cargar las observaciones de los partners.</td></tr>';
            if (motivosBox) motivosBox.innerHTML = '';
            if (gravedadBox) gravedadBox.innerHTML = '';
        }
    }

    // Supervisores del departamento elegido: los que lo tienen como zona base o adicional (o trabajan todas las zonas)
    function sgsFiltrarSupervisoresPorDepartamento() {
        const selS = document.getElementById('ind-filter-supervisor');
        const dep = document.getElementById('ind-filter-departamento')?.value || '';
        if (!selS) return;
        const actual = selS.value;
        const lista = (window.sgsDashSupervisores || []).filter(s => !dep || s.zonas === null || (s.zonas || []).includes(dep));
        selS.innerHTML = '<option value="0">' + (dep ? `Todos los supervisores de ${escapeHtml(dep)}` : 'Todos los supervisores') + '</option>'
            + lista.map(s => `<option value="${s.id}">${escapeHtml(s.nombre_completo)}</option>`).join('');
        selS.value = lista.some(s => String(s.id) === actual) ? actual : '0';
    }

    async function cargarFiltrosIndicadores() {
        try {
            const resp = await fetch('api/asistencia.php?action=list_filters');
            const data = await resp.json();
            if (data.status !== 'success') return;

            const selP = document.getElementById('ind-filter-partner');
            const selS = document.getElementById('ind-filter-supervisor');

            if (selP && data.partners) {
                selP.innerHTML = '<option value="0">Todos los partners</option>';
                data.partners.forEach(p => {
                    selP.innerHTML += `<option value="${p.id}">${escapeHtml(p.nombre_partner)}</option>`;
                });
            }

            const selD = document.getElementById('ind-filter-departamento');
            if (selD && data.departamentos) {
                selD.innerHTML = '<option value="">Todos los departamentos</option>'
                    + data.departamentos.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('');
                selD.addEventListener('change', sgsFiltrarSupervisoresPorDepartamento);
            }
            window.sgsDashSupervisores = data.supervisores || [];
            sgsFiltrarSupervisoresPorDepartamento();
        } catch (e) {
            console.error('Error al cargar filtros de indicadores:', e);
        }
    }

    // Vistas del Dashboard: Supervisión / Partners (la elección se recuerda en este navegador)
    function mostrarVistaDashboard(vista) {
        document.querySelectorAll('#dash-vistas .filter-tab-btn').forEach((b) => {
            const activa = b.dataset.vista === vista;
            b.classList.toggle('active', activa);
            b.setAttribute('aria-selected', activa ? 'true' : 'false');
        });
        document.getElementById('dash-vista-supervision')?.classList.toggle('d-none', vista !== 'supervision');
        document.getElementById('dash-vista-partners')?.classList.toggle('d-none', vista !== 'partners');
        if (vista === 'supervision' && leafletMap) setTimeout(() => leafletMap.invalidateSize(), 50);
        try { localStorage.setItem('sgs-dash-vista', vista); } catch (e) {}
    }
    document.querySelectorAll('#dash-vistas .filter-tab-btn').forEach((b) => b.addEventListener('click', () => mostrarVistaDashboard(b.dataset.vista)));
    try { if (localStorage.getItem('sgs-dash-vista') === 'partners') mostrarVistaDashboard('partners'); } catch (e) {}

    // Modalidad: anillo con tres partes (en caliente / en frío / remota), leyenda con cantidad y porcentaje.
    function pintarModalidad(mod) {
        const cont = document.getElementById('dash-modalidad');
        if (!cont) return;
        const caliente = Number(mod?.['En caliente'] || 0), frio = Number(mod?.['En frío'] || 0), remota = Number(mod?.['Remota'] || 0), total = caliente + frio + remota;
        if (!total) {
            cont.innerHTML = '<p class="text-muted small mb-0"><i class="bi bi-inbox me-1"></i>No hay supervisiones con modalidad en este período y filtros.</p>';
            return;
        }
        const pct = (v) => Math.round((v / total) * 1000) / 10;
        const R = 60, C = 2 * Math.PI * R, GAP = 2;          // 2 px de separación entre las dos partes
        const partes = [
            { clase: 'is-caliente', nombre: 'En caliente', valor: caliente },
            { clase: 'is-frio', nombre: 'En frío', valor: frio },
            { clase: 'is-remota', nombre: 'Remota (videollamada)', valor: remota },
        ];
        let desde = 0;
        const arcos = partes.filter((p) => p.valor > 0).map((p, i, visibles) => {
            const largo = (p.valor / total) * C;
            const trazo = visibles.length > 1 ? Math.max(0, largo - GAP) : largo;
            const arco = `<circle class="sgs-donut-arc ${p.clase}" r="${R}" cx="80" cy="80" stroke-dasharray="${trazo} ${C - trazo}" stroke-dashoffset="${-desde}" tabindex="0"><title>${p.nombre}: ${p.valor} (${pct(p.valor)} %)</title></circle>`;
            desde += largo;
            return arco;
        }).join('');
        cont.innerHTML = `
            <svg class="sgs-donut" viewBox="0 0 160 160" role="img" aria-label="En caliente ${pct(caliente)} %, en frío ${pct(frio)} %, remota ${pct(remota)} %">
              <g transform="rotate(-90 80 80)">${arcos}</g>
              <text x="80" y="78" text-anchor="middle" class="sgs-donut-total">${total}</text>
              <text x="80" y="98" text-anchor="middle" class="sgs-donut-sub">supervisiones</text>
            </svg>
            <ul class="sgs-legend sgs-donut-legend list-unstyled mb-0">
              ${partes.map((p) => `<li><span class="sgs-donut-swatch ${p.clase}" aria-hidden="true"></span><span class="sgs-donut-name">${p.nombre} <span class="text-muted">· ${p.valor}</span></span><strong>${pct(p.valor)} %</strong></li>`).join('')}
            </ul>`;
    }

    async function cargarIndicadoresDashboard() {
        const pId = document.getElementById('ind-filter-partner')?.value || 0;
        const fIni = document.getElementById('ind-filter-f-ini')?.value || '';
        const fFin = document.getElementById('ind-filter-f-fin')?.value || '';
        const sId = document.getElementById('ind-filter-supervisor')?.value || 0;

        const dep = encodeURIComponent(document.getElementById('ind-filter-departamento')?.value || '');
        const url = `api/supervisiones.php?action=indicadores_dashboard&partner_id=${pId}&fecha_inicio=${fIni}&fecha_fin=${fFin}&supervisor_id=${sId}&departamento=${dep}`;

        try {
            const resp = await fetch(url);
            const data = await resp.json();

            if (data.status !== 'success') return;

            // KPIs superiores
            const tot = data.totales || {};
            document.getElementById('ind-stat-total').textContent = tot.total_supervisiones || 0;
            pintarModalidad(data.modalidad);

            // Indicadores de texto: el nombre arriba (hasta 2 líneas) y el detalle con la cantidad debajo, en gris.
            const pintarDestacado = (clave, nombre, detalle) => {
                const valor = document.getElementById(`ind-stat-${clave}-top`);
                valor.textContent = nombre || '—';
                valor.title = nombre || '';
                document.getElementById(`ind-stat-${clave}-sub`).textContent = detalle || '';
            };
            const nSup = n => `${Number(n || 0).toLocaleString('es-PE')} ${Number(n) === 1 ? 'supervisión' : 'supervisiones'}`;
            const p0 = (data.top_partners || [])[0];
            pintarDestacado('partner', p0?.nombre_partner, p0 ? nSup(p0.total) : '');
            const d0 = (data.top_distritos || [])[0];
            pintarDestacado('distrito', d0?.distrito, d0 ? [d0.departamento, nSup(d0.total)].filter(Boolean).join(' · ') : '');
            const c0 = (data.top_cuadrillas || [])[0];
            pintarDestacado('cuadrilla', c0?.tecnico, c0 ? [c0.nombre_partner, nSup(c0.total)].filter(Boolean).join(' · ') : '');

            // 1. MAPA GEOGRÁFICO INTERACTIVO LEAFLET CON PUNTOS ROJOS
            renderLeafletHeatmap(data.map_points);

            // 2. Ranking Distritos
            const cDistritos = document.getElementById('container-ranking-distritos');
            if (cDistritos) {
                if (!data.top_distritos || data.top_distritos.length === 0) {
                    cDistritos.innerHTML = '<div class="text-center text-muted py-3">Sin datos.</div>';
                } else {
                    let dHtml = '';
                    const maxD = data.top_distritos[0].total || 1;
                    data.top_distritos.forEach((d, i) => {
                        const pct = Math.round((d.total / maxD) * 100);
                        dHtml += `
                            <div class="p-2 bg-light rounded border">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-bold text-dark">#${i+1} ${escapeHtml(d.distrito)} (${escapeHtml(d.provincia)})</span>
                                    <span class="badge bg-warning text-dark fw-bold">${d.total} sup</span>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-warning" style="width: ${pct}%;"></div>
                                </div>
                            </div>
                        `;
                    });
                    cDistritos.innerHTML = dHtml;
                }
            }

            // 3. Ranking Partners
            const cPartners = document.getElementById('container-ranking-partners');
            if (cPartners) {
                if (!data.top_partners || data.top_partners.length === 0) {
                    cPartners.innerHTML = '<div class="text-center text-muted py-3">Sin datos.</div>';
                } else {
                    let pHtml = '';
                    const maxP = data.top_partners[0].total || 1;
                    data.top_partners.forEach((p, i) => {
                        const pct = Math.round((p.total / maxP) * 100);
                        pHtml += `
                            <div class="p-2 bg-light rounded border">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-bold text-dark">#${i+1} ${escapeHtml(p.nombre_partner)}</span>
                                    <span class="badge bg-info text-dark fw-bold">${p.total} inspecciones</span>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-info" style="width: ${pct}%;"></div>
                                </div>
                            </div>
                        `;
                    });
                    cPartners.innerHTML = pHtml;
                }
            }

            // 4. Ranking Cuadrillas
            const cCuadrillas = document.getElementById('container-ranking-cuadrillas');
            if (cCuadrillas) {
                if (!data.top_cuadrillas || data.top_cuadrillas.length === 0) {
                    cCuadrillas.innerHTML = '<div class="text-center text-muted py-3">Sin datos.</div>';
                } else {
                    let cHtml = '';
                    const maxC = data.top_cuadrillas[0].total || 1;
                    data.top_cuadrillas.forEach((c, i) => {
                        const pct = Math.round((c.total / maxC) * 100);
                        cHtml += `
                            <div class="p-2 bg-light rounded border">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-bold text-dark">#${i+1} ${escapeHtml(c.tecnico)} <small class="text-muted">(${escapeHtml(c.nombre_partner)})</small></span>
                                    <span class="badge bg-success text-white fw-bold">${c.total} auditadas</span>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: ${pct}%;"></div>
                                </div>
                            </div>
                        `;
                    });
                    cCuadrillas.innerHTML = cHtml;
                }
            }

        } catch (e) {
            console.error('Error al cargar indicadores:', e);
        }
    }

    function renderLeafletHeatmap(points) {
        const mapEl = document.getElementById('leaflet-heatmap');
        if (!mapEl || typeof L === 'undefined') return;

        if (!leafletMap) {
            // Inicializar mapa centrado en Perú
            leafletMap = L.map('leaflet-heatmap').setView([-9.19, -75.015], 6);

            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 18,
                attribution: 'Tiles © Esri | WIN Telecom'
            }).addTo(leafletMap);

            leafletMarkersLayer = L.layerGroup().addTo(leafletMap);
        } else {
            leafletMarkersLayer.clearLayers();
        }

        if (!points || points.length === 0) return;

        const bounds = [];

        points.forEach(p => {
            if (!p.lat || !p.lng) return;
            bounds.push([p.lat, p.lng]);

            // Círculo rojo de alta densidad brillante con borde
            const marker = L.circleMarker([p.lat, p.lng], {
                radius: 8,
                fillColor: '#ef4444',
                color: '#991b1b',
                weight: 2,
                opacity: 0.9,
                fillOpacity: 0.75
            });

            // Ventana del punto (2026-10-15): datos ordenados y botón para abrir esa supervisión.
            const fila = (etq, val) => val ? `<div class="sgs-mapa-pop-fila"><span>${etq}</span><strong>${escapeHtml(val)}</strong></div>` : '';
            marker.bindPopup(`
                <div class="sgs-mapa-pop">
                    <div class="sgs-mapa-pop-titulo"><i class="bi bi-pin-map"></i>${escapeHtml(p.ticket || p.codigo)}</div>
                    <div class="sgs-mapa-pop-lugar">${escapeHtml([p.distrito, p.provincia].filter(Boolean).join(', '))}</div>
                    ${fila('Partner', p.partner)}${fila('Técnico', p.tecnico)}${fila('Cliente', p.cliente)}${fila('Fecha', p.fecha)}
                    <a class="btn btn-win btn-sm w-100 mt-2" href="index.php?page=supervision_lista&highlight=${encodeURIComponent(p.id)}&abrir=1"><i class="bi bi-box-arrow-up-right me-1"></i>Ver supervisión</a>
                </div>
            `, { minWidth: 220, maxWidth: 280 });

            leafletMarkersLayer.addLayer(marker);
        });

        if (bounds.length > 0) {
            leafletMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
        }
    }

    // ---------- Filtro general del Dashboard ----------
    // Modo "mes": el período es el mes completo. Modo "rango": las fechas Desde/Hasta.
    // Todas las secciones leen window.sgsDashFiltro(): fecha_inicio, fecha_fin, partner_id, supervisor_id.
    const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    let dashModo = 'mes';
    const isoLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const fechaTxt = (iso) => iso.split('-').reverse().join('/');

    function sincronizarPeriodo() {
        const ini = document.getElementById('ind-filter-f-ini'), fin = document.getElementById('ind-filter-f-fin');
        if (dashModo === 'mes') {
            const m = +document.getElementById('dash-mes').value, a = +document.getElementById('dash-anio').value;
            ini.value = isoLocal(new Date(a, m - 1, 1));
            fin.value = isoLocal(new Date(a, m, 0));
        } else if (ini.value && fin.value && ini.value > fin.value) {
            [ini.value, fin.value] = [fin.value, ini.value];
        }
        const etiqueta = dashModo === 'mes'
            ? `${MESES[+document.getElementById('dash-mes').value - 1]} ${document.getElementById('dash-anio').value}`
            : `${fechaTxt(ini.value)} – ${fechaTxt(fin.value)}`;
        document.querySelector('#dash-periodo-txt span').textContent = `Período: ${etiqueta}`;
    }

    window.sgsDashFiltro = () => ({
        fecha_inicio: document.getElementById('ind-filter-f-ini').value,
        fecha_fin: document.getElementById('ind-filter-f-fin').value,
        partner_id: document.getElementById('ind-filter-partner').value || 0,
        supervisor_id: document.getElementById('ind-filter-supervisor').value || 0,
        departamento: document.getElementById('ind-filter-departamento')?.value || '',
    });

    function aplicarFiltroGeneral() {
        sincronizarPeriodo();
        cargarIndicadoresDashboard();
        cargarCumplimientoPartners();
        cargarObservacionesPartners();
        cargarMatrizMensual();
        if (window.sgsCargarProductividad) window.sgsCargarProductividad();
    }

    function iniciarFiltroGeneral() {
        const hoy = new Date();
        const selMes = document.getElementById('dash-mes'), selAnio = document.getElementById('dash-anio');
        selMes.innerHTML = MESES.map((m, i) => `<option value="${i + 1}"${i === hoy.getMonth() ? ' selected' : ''}>${m}</option>`).join('');
        selAnio.innerHTML = [0, 1, 2].map((d) => hoy.getFullYear() - d).map((a) => `<option value="${a}">${a}</option>`).join('');
        document.querySelectorAll('#dash-modo .filter-tab-btn').forEach((b) => b.addEventListener('click', () => {
            dashModo = b.dataset.modo;
            document.querySelectorAll('#dash-modo .filter-tab-btn').forEach((x) => x.classList.toggle('active', x === b));
            document.querySelectorAll('.dash-modo-mes').forEach((el) => el.classList.toggle('d-none', dashModo !== 'mes'));
            document.querySelectorAll('.dash-modo-rango').forEach((el) => el.classList.toggle('d-none', dashModo !== 'rango'));
            sincronizarPeriodo();
        }));
        // Cada filtro se aplica apenas cambia (sin botón "Filtrar"): mes, año, fechas, partner, departamento y supervisor.
        [selMes, selAnio, 'ind-filter-f-ini', 'ind-filter-f-fin', 'ind-filter-partner', 'ind-filter-supervisor']
            .map((el) => (typeof el === 'string' ? document.getElementById(el) : el))
            .forEach((el) => el?.addEventListener('change', aplicarFiltroGeneral));
        // El departamento primero ajusta la lista de supervisores y luego aplica.
        document.getElementById('ind-filter-departamento')?.addEventListener('change', () => {
            sgsFiltrarSupervisoresPorDepartamento();
            aplicarFiltroGeneral();
        });
        document.getElementById('btn-limpiar-dash').addEventListener('click', () => {
            document.querySelector('#dash-modo .filter-tab-btn[data-modo="mes"]').click();
            selMes.value = hoy.getMonth() + 1; selAnio.value = hoy.getFullYear();
            document.getElementById('ind-filter-partner').value = '0';
            const selDep = document.getElementById('ind-filter-departamento');
            if (selDep) { selDep.value = ''; sgsFiltrarSupervisoresPorDepartamento(); }
            document.getElementById('ind-filter-supervisor').value = '0';
            aplicarFiltroGeneral();
        });
        aplicarFiltroGeneral();
    }

    async function cargarMatrizMensual() {

        const thead = document.getElementById('thead-matriz');
        const tbody = document.getElementById('tbody-matriz');

        thead.innerHTML = '<tr><th colspan="35" class="text-center py-3 text-secondary">Cargando Matriz Mensual...</th></tr>';
        tbody.innerHTML = '';

        try {
            const res = await fetch('api/dashboard.php?action=matriz_mensual&' + new URLSearchParams(window.sgsDashFiltro()).toString());
            const data = await res.json();

            if (data.status !== 'success') { thead.innerHTML = `<tr><td class="text-muted py-3">${escapeHtml(data.message || 'No se pudo generar la matriz.')}</td></tr>`; return; }
            if (data.status === 'success') {
                let headHtml = `<tr>
                    <th class="sticky-col-name bg-light text-dark fw-bold">Supervisor</th>`;

                data.dias_mes.forEach(d => {
                    headHtml += `<th class="text-center small px-2">${d.etiqueta}</th>`;
                });

                headHtml += `<th class="sticky-col-total text-dark text-center fw-bold">TOTAL</th></tr>`;
                thead.innerHTML = headHtml;

                let bodyHtml = '';
                data.filas.forEach(f => {
                    bodyHtml += `<tr>
                        <td class="sticky-col-name fw-bold text-dark">
                            ${escapeHtml(f.nombre_completo)}
                        </td>`;

                    data.dias_mes.forEach(d => {
                        const cant = f.conteo_dias[d.fecha] || 0;
                        const cellStyle = cant > 0 ? 'color:#FF5A0A; font-weight:bold; background-color:#fffdfa;' : 'color:#a0aec0;';
                        bodyHtml += `<td class="text-center" style="${cellStyle}">${cant > 0 ? cant : '-'}</td>`;
                    });

                    bodyHtml += `<td class="sticky-col-total text-center fw-bold fs-6" style="color:var(--sgs-orange);">${f.total_mes}</td></tr>`;
                });

                tbody.innerHTML = bodyHtml;
            }
        } catch (e) {
            thead.innerHTML = '<tr><td class="text-danger py-3">Error al generar matriz.</td></tr>';
        }
    }

    function nameInitials(name) { const parts=String(name||'').trim().split(/\s+/).filter(Boolean); return ((parts[0]?.[0]||'U')+(parts.length>1?parts[parts.length-1][0]:'')).toLocaleUpperCase('es'); }
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>
