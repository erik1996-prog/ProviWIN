// 03_FRONTEND/src/assets/js/admin_dashboard.js - Dashboard (pages/admin_dashboard.php): meta de productividad, línea de tiempo horizontal, indicadores y mapa de calor.
// 2026-10-27: salió de la página a este archivo (sistema liviano: el navegador lo guarda).
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
