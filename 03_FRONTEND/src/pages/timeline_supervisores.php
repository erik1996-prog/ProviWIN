<?php
// 03_FRONTEND/src/pages/timeline_supervisores.php - Consola Unificada de Línea de Tiempo Horizontal por Supervisor
Auth::requireLogin();
$user = Auth::getLoggedInUser();
?>

<div class="container-fluid p-3 p-md-4">
    <!-- Encabezado Principal -->
    <div class="sgs-page-actions">
        <div class="d-flex align-items-center gap-2">
            <label class="small fw-bold text-secondary mb-0">Fecha:</label>
            <input type="date" id="filter-timeline-fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" style="width: 150px;">
            <button type="button" class="btn btn-sm btn-win fw-bold" id="btn-refresh-timeline">
                <i class="bi bi-arrow-repeat me-1"></i> Actualizar
            </button>
        </div>
    </div>

    <!-- TARJETAS DE MONITOREO DE ESTADOS DE LA JORNADA -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="sgs-kpi p-3 h-100 sgs-kpi--success">
                <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-person-check"></i></span>
                <small class="sgs-kpi-label">En turno</small>
                <h3 class="mb-0 sgs-kpi-value" id="stat-en-turno">0</h3>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="sgs-kpi p-3 h-100 sgs-kpi--warning">
                <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-pause-circle"></i></span>
                <small class="sgs-kpi-label">En almuerzo / pausa</small>
                <h3 class="mb-0 sgs-kpi-value" id="stat-en-break">0</h3>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="sgs-kpi p-3 h-100 sgs-kpi--info">
                <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-journal-check"></i></span>
                <small class="sgs-kpi-label">Capacitación / reunión</small>
                <h3 class="mb-0 sgs-kpi-value" id="stat-en-capacitacion">0</h3>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="sgs-kpi p-3 h-100">
                <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-person-dash"></i></span>
                <small class="sgs-kpi-label">Fuera de turno</small>
                <h3 class="mb-0 sgs-kpi-value" id="stat-fuera-turno">0</h3>
            </div>
        </div>
    </div>

    <!-- CUADRO UNIFICADO DE LÍNEA DE TIEMPO (TODO EN UN SOLO MARCO COMPACTO) -->
    <div class="card card-win p-3 p-md-4 shadow-sm border-0">
        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3 flex-wrap gap-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-diagram-3-fill me-2" style="color: var(--sgs-orange);"></i> Actividad del día
            </h5>
            <span class="badge bg-light text-dark border">
                <i class="bi bi-people-fill me-1"></i> Por supervisor
            </span>
        </div>

        <!-- Leyenda Integrada de Bloques -->
        <div class="sgs-legend d-flex flex-wrap gap-2 mb-3 p-2 bg-light rounded-3 border align-items-center">
            <span class="small fw-bold text-secondary me-1" style="font-size: 0.75rem;">Leyenda:</span>
            <span class="badge bg-primary text-white"><i class="bi bi-arrow-right-circle-fill me-1"></i> Inicio</span>
            <span class="badge sgs-tl-legend-curso"><i class="bi bi-hourglass-split me-1"></i>Supervisión iniciada</span>
            <span class="badge sgs-tl-legend-fin"><i class="bi bi-check2-circle me-1"></i>Supervisión finalizada</span>
            <span class="badge bg-warning text-dark fw-bold"><i class="bi bi-car-front-fill me-1"></i> Traslado (min)</span>
            <span class="badge bg-pausa text-white"><i class="bi bi-cup-hot-fill me-1"></i> Pausa / almuerzo</span>
            <span class="badge bg-danger text-white"><i class="bi bi-arrow-left-circle-fill me-1"></i> Fin</span>
        </div>

        <!-- Contenedor Deslizable Interno (Todo en Cuadro Compacto de 500px) -->
        <div class="timeline-console-box overflow-auto border rounded-3 p-2 bg-white" style="max-height: 520px;">
            <div id="container-supervisors-timeline" class="d-flex flex-column gap-2">
                <div class="text-center py-4 text-secondary">
                    <div class="spinner-border spinner-border-sm text-warning me-2" role="status"></div> Cargando líneas de tiempo...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ESTILOS TRACK HORIZONTAL -->
<style>
.bg-pausa {
    background-color: var(--sgs-brown) !important;
}

.timeline-horizontal-track {
    display: flex;
    align-items: center;
    gap: 6px;
    overflow-x: auto;
    padding: 6px 4px;
    background-color: var(--sgs-surface-alt);
    border-radius: 6px;
    border: 1px solid var(--sgs-border);
}
.timeline-block-item {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
</style>

<!-- SCRIPT DE LÍNEA DE TIEMPO HORIZONTAL -->
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

let timelineRefreshRunning = false;
async function refrescarTimelineCompleto(force = false) {
    if (timelineRefreshRunning || (document.hidden && !force)) return;
    timelineRefreshRunning = true;
    try {
        await Promise.allSettled([cargarMonitoreoRealTime(), cargarTimelineSupervisores()]);
    } finally {
        timelineRefreshRunning = false;
    }
}
document.addEventListener('DOMContentLoaded', () => {
    refrescarTimelineCompleto(true);

    const btnRefTL = document.getElementById('btn-refresh-timeline');
    const filterDateTL = document.getElementById('filter-timeline-fecha');
    if (btnRefTL) btnRefTL.addEventListener('click', () => {
        refrescarTimelineCompleto(true);
    });
    if (filterDateTL) filterDateTL.addEventListener('change', cargarTimelineSupervisores);

    // Actualización moderada y solo cuando la pestaña está visible.
    setInterval(() => refrescarTimelineCompleto(), 60000);
});
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refrescarTimelineCompleto();
});

async function cargarMonitoreoRealTime() {
    try {
        const res = await fetch('api/dashboard.php?action=monitoreo_realtime');
        const data = await res.json();

        if (data.status === 'success') {
            document.getElementById('stat-en-turno').textContent = data.resumen.en_turno;
            document.getElementById('stat-en-capacitacion').textContent = data.resumen.en_capacitacion;
            document.getElementById('stat-en-break').textContent = data.resumen.en_break;
            document.getElementById('stat-fuera-turno').textContent = data.resumen.fuera_turno;
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
            container.innerHTML = '<div class="text-center text-muted py-3">No hay datos de línea de tiempo disponibles para la fecha seleccionada.</div>';
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
                avatarHtml = `<img src="${escapeHtml(s.avatar)}" class="rounded-circle border border-warning shadow-sm me-2 flex-shrink-0" style="width:34px; height:34px; object-fit:cover;">`;
            } else {
                avatarHtml = `<div class="rounded-circle text-white d-flex align-items-center justify-content-center fw-bold shadow-sm me-2 flex-shrink-0" style="width:34px;height:34px;background-color:var(--sgs-orange);font-size:.72rem;letter-spacing:.03em;">${nameInitials(s.nombre_corto || s.nombre)}</div>`;
            }

            // Generar Bloques Horizontales del Stream
            let horizontalBlocksHtml = '';

            if (s.es_licencia) {
                horizontalBlocksHtml = `
                    <div class="alert alert-info border border-info mb-0 p-2 rounded-3 w-100 small">
                        <i class="bi bi-sun-fill text-info me-1"></i>
                        <strong>${escapeHtml(s.estado_general)}</strong>
                        <span class="text-secondary ms-2">Período autorizado registrado.</span>
                    </div>
                `;
            } else if (s.timeline_blocks && s.timeline_blocks.length > 0) {
                horizontalBlocksHtml += '<div class="timeline-horizontal-track">';

                s.timeline_blocks.forEach(b => {
                    if (b.type === 'shift_start') {
                        horizontalBlocksHtml += `
                            <div class="timeline-block-item ${b.color_class}">
                                <i class="bi bi-arrow-right-circle-fill me-1"></i>
                                <strong>Inicio: ${b.hora}</strong>
                            </div>
                        `;
                    } else if (b.type === 'supervision') {
                        horizontalBlocksHtml += sgsBloqueSupervision(b);
                    } else if (b.type === 'travel') {
                        horizontalBlocksHtml += `
                            <div class="timeline-block-item ${b.color_class}">
                                <i class="bi bi-car-front-fill me-1 text-dark"></i>
                                <span><i class="bi bi-car-front me-1"></i>${b.duracion_minutos} min viaje</span>
                            </div>
                        `;
                    } else if (b.type === 'pause') {
                        horizontalBlocksHtml += `
                            <div class="timeline-block-item ${b.color_class}">
                                <i class="bi bi-cup-hot-fill me-1"></i>
                                <div>
                                    <strong class="d-block"><i class="bi bi-cup-hot me-1"></i>${escapeHtml(b.title)}</strong>
                                    <small class="text-white-50 text-xs">${b.hora_inicio} - ${b.hora_fin}</small>
                                </div>
                            </div>
                        `;
                    } else if (b.type === 'shift_end') {
                        horizontalBlocksHtml += `
                            <div class="timeline-block-item ${b.color_class}">
                                <i class="bi bi-arrow-left-circle-fill me-1"></i>
                                <strong>Fin: ${b.hora}</strong>
                            </div>
                        `;
                    }
                });

                horizontalBlocksHtml += '</div>';
            } else {
                horizontalBlocksHtml = '<div class="small text-muted py-1 bg-light rounded px-2 border">Sin actividad en la fecha.</div>';
            }

            html += `
                <div class="p-2 bg-light rounded-3 border d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                    <!-- Columna Información de Supervisor -->
                    <div class="d-flex align-items-center flex-shrink-0" style="min-width: 210px; max-width: 230px;">
                        ${avatarHtml}
                        <div class="overflow-hidden">
                            <strong class="text-dark d-block text-truncate small" title="${escapeHtml(s.nombre_completo)}">${escapeHtml(s.nombre_corto)}</strong>
                            <div class="d-flex align-items-center gap-1 mt-0.5">
                                <span class="badge ${badgeClass} text-xs">${escapeHtml(s.estado_general)}</span>
                                <span class="badge bg-secondary text-white text-xs">${s.total_supervisiones} sup</span>
                            </div>
                        </div>
                    </div>

                    <!-- Columna Stream de Actividades -->
                    <div class="flex-grow-1 overflow-hidden">
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

function nameInitials(name) { const parts=String(name||'').trim().split(/\s+/).filter(Boolean); return ((parts[0]?.[0]||'U')+(parts.length>1?parts[parts.length-1][0]:'')).toLocaleUpperCase('es'); }
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
