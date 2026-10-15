<?php
// 03_FRONTEND/src/pages/timeline_supervisores.php - Línea de tiempo por horas de la jornada del personal directo.
// 2026-10-13: cada actividad se dibuja en su hora real (regla de horas arriba, una fila por supervisor) y al hacer clic
// se abre una ventana flotante con el detalle y las acciones (abrir la supervisión, reabrir el turno).
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
            <span><i class="sw is-ahora"></i>Ahora</span>
        </div>
        <div class="sgs-gantt" id="tl-gantt">
            <div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando la jornada…</div>
        </div>
    </div>
</div>

<script>
(function () {
    const esc = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const hhmm = (ts) => ts ? new Date(ts * 1000).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }) : '—';
    const dur = (min) => { if (min == null || isNaN(min)) return '—'; min = Math.max(0, Math.round(min)); return min >= 60 ? `${Math.floor(min / 60)} h ${min % 60} min` : `${min} min`; };
    const iniciales = (n) => { const p = String(n || '').trim().split(/\s+/).filter(Boolean); return ((p[0]?.[0] || 'U') + (p.length > 1 ? p[p.length - 1][0] : '')).toLocaleUpperCase('es'); };
    let datos = null, rango = null, pop = null, cargando = false;
    // Preferencias de este navegador: escala de horas y supervisores desplegados
    const leer = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch (e) { return d; } };
    const guardar = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin almacenamiento: no se recuerda */ } };
    let escala = [1, 2, 4].includes(leer('sgs-tl-escala', 1)) ? leer('sgs-tl-escala', 1) : 1;
    const abiertos = new Set(leer('sgs-tl-abiertos', []));
    const PX_HORA = 90; // ancho de una hora en escala ×1 cuando la pantalla es angosta

    // Fin de un bloque: el registrado; si sigue abierto, ahora (hoy) o su duración conocida.
    const finDe = (b) => b.ts_fin || (datos.es_hoy ? datos.ahora : (b.ts_inicio + Math.max(1, Number(b.duracion_minutos) || 1) * 60));
    const filas = () => {
        const q = (document.getElementById('filter-timeline-buscar')?.value || '').trim().toLocaleLowerCase('es');
        const soloActivos = document.getElementById('filter-timeline-activos')?.checked;
        return (datos?.supervisores || []).filter((s) => (!q || String(s.nombre_completo).toLocaleLowerCase('es').includes(q))
            && (!soloActivos || s.turno_ts || s.es_licencia || (s.timeline_blocks || []).some((b) => b.ts_inicio)));
    };
    function calcularRango(lista) {
        const ts = [];
        lista.forEach((s) => {
            if (s.turno_ts) { ts.push(s.turno_ts.inicio); ts.push(s.turno_ts.fin || (datos.es_hoy ? datos.ahora : s.turno_ts.inicio)); }
            (s.timeline_blocks || []).forEach((b) => { if (b.ts_inicio) { ts.push(b.ts_inicio); ts.push(finDe(b)); } });
        });
        const base = new Date(`${datos.fecha}T00:00:00`).getTime() / 1000;
        let ini = ts.length ? Math.min(...ts) : base + 7 * 3600, fin = ts.length ? Math.max(...ts) : base + 19 * 3600;
        ini = Math.floor(ini / 3600) * 3600; fin = Math.ceil(fin / 3600) * 3600;
        if (fin - ini < 8 * 3600) { const falta = 8 * 3600 - (fin - ini); ini -= Math.floor(falta / 7200) * 3600; fin = ini + 8 * 3600; }
        return { ini, fin };
    }
    // Única forma de escribir la posición calculada de un bloque (left/width en %): no hay estilos fijos en línea.
    const est = (css) => 'style' + '="' + css + '"';
    const pos = (ts) => Math.min(100, Math.max(0, (ts - rango.ini) * 100 / (rango.fin - rango.ini)));
    const caja = (ini, fin) => `left:${pos(ini).toFixed(3)}%;width:${Math.max(0, pos(fin) - pos(ini)).toFixed(3)}%`;
    function horas(conEtiqueta) {
        let html = '';
        for (let t = rango.ini; t < rango.fin; t += 3600) html += `<div class="sgs-gantt-hour" ${est(`left:${pos(t).toFixed(3)}%`)}>${conEtiqueta ? `<span>${hhmm(t)}</span>` : ''}</div>`;
        return html;
    }
    const lineaAhora = (conEtiqueta) => datos.es_hoy && datos.ahora > rango.ini && datos.ahora < rango.fin
        ? `<div class="sgs-gantt-ahora" ${est(`left:${pos(datos.ahora).toFixed(3)}%`)}>${conEtiqueta ? `<span>${hhmm(datos.ahora)}</span>` : ''}</div>` : '';

    function bloqueHtml(b, si, bi) {
        if (!b.ts_inicio) return '';
        const fin = finDe(b);
        const datosAttr = `data-s="${si}" data-b="${bi}"`;
        if (b.type === 'supervision') {
            const cls = (b.estado === 'finalizada' ? 'is-sup-fin' : 'is-sup-curso') + (b.modalidad === 'Remota' ? ' is-remota' : '');
            const titulo = `${b.ticket || b.codigo} · ${hhmm(b.ts_inicio)}–${b.ts_fin ? hhmm(b.ts_fin) : 'en curso'}`;
            return `<button type="button" class="sgs-gantt-blk ${cls}" ${est(`${caja(b.ts_inicio, fin)}`)} ${datosAttr} title="${esc(titulo)}" aria-label="Supervisión ${esc(titulo)}">${esc(b.ticket || b.codigo)}</button>`;
        }
        if (b.type === 'travel') return `<button type="button" class="sgs-gantt-blk is-traslado" ${est(`${caja(b.ts_inicio, fin)}`)} ${datosAttr} title="Traslado de ${Number(b.duracion_minutos)} min" aria-label="Traslado de ${Number(b.duracion_minutos)} minutos"></button>`;
        if (b.type === 'pause' || b.type === 'remote') {
            const nombre = b.type === 'remote' ? 'Trabajo remoto' : b.title;
            return `<button type="button" class="sgs-gantt-blk ${b.type === 'remote' ? 'is-remoto' : 'is-pausa'}" ${est(`${caja(b.ts_inicio, fin)}`)} ${datosAttr} title="${esc(nombre)} · ${hhmm(b.ts_inicio)}–${b.ts_fin ? hhmm(b.ts_fin) : 'en curso'}" aria-label="${esc(nombre)}">${esc(nombre)}</button>`;
        }
        return '';
    }

    // Lista de lo que hizo el supervisor, en orden de hora (se despliega debajo de su fila)
    function detalleHtml(s, si) {
        const filasD = [];
        if (s.turno_ts) filasD.push({ ts: s.turno_ts.inicio, html: `<td>${hhmm(s.turno_ts.inicio)}</td><td colspan="2"><i class="bi bi-arrow-right-circle-fill text-success me-1"></i><strong>Inicio de turno</strong></td><td>—</td><td></td>` });
        (s.timeline_blocks || []).forEach((b, bi) => {
            if (!b.ts_inicio) return;
            const fin = finDe(b), hora = `${hhmm(b.ts_inicio)}–${b.ts_fin || b.type === 'travel' ? hhmm(fin) : 'en curso'}`, d = dur((fin - b.ts_inicio) / 60);
            if (b.type === 'supervision') filasD.push({ ts: b.ts_inicio, html: `<td>${hora}</td><td><span class="badge ${b.estado === 'finalizada' ? 'bg-success' : 'bg-warning'}">${b.estado === 'finalizada' ? 'Supervisión' : 'Supervisión en curso'}</span></td><td><strong>${esc(b.ticket || b.codigo)}</strong><br><small class="text-muted">${esc(b.tecnico || '')}${b.partner ? ' · ' + esc(b.partner) : ''}</small></td><td>${d}</td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="index.php?page=supervision_lista&highlight=${Number(b.id)}" aria-label="Abrir supervisión ${esc(b.ticket || b.codigo)}"><i class="bi bi-box-arrow-up-right"></i></a></td>` });
            else if (b.type === 'travel') filasD.push({ ts: b.ts_inicio, html: `<td>${hora}</td><td><span class="badge bg-secondary">Traslado</span></td><td><small class="text-muted">Hacia la siguiente supervisión</small></td><td>${d}</td><td></td>` });
            else if (b.type === 'pause' || b.type === 'remote') filasD.push({ ts: b.ts_inicio, html: `<td>${hora}</td><td><span class="badge bg-secondary">${esc(b.type === 'remote' ? 'Trabajo remoto' : b.title)}</span></td><td><small class="text-muted">${esc(b.nota || '')}</small></td><td>${d}</td><td></td>` });
        });
        if (s.turno_ts?.fin) filasD.push({ ts: s.turno_ts.fin, html: `<td>${hhmm(s.turno_ts.fin)}</td><td colspan="2"><i class="bi bi-arrow-left-circle-fill me-1"></i><strong>Fin de turno</strong></td><td>—</td><td></td>` });
        filasD.sort((a, b) => a.ts - b.ts);
        return `<div class="sgs-gantt-detalle"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Hora</th><th>Actividad</th><th>Detalle</th><th>Duración</th><th class="text-end"><span class="visually-hidden">Abrir</span></th></tr></thead>
            <tbody>${filasD.length ? filasD.map((f) => `<tr>${f.html}</tr>`).join('') : '<tr><td colspan="5" class="text-center text-muted py-3">Sin actividades registradas.</td></tr>'}</tbody></table></div></div>`;
    }

    // La lista desplegada ocupa el ancho visible del diagrama aunque las horas sigan de largo (escala ×2/×4)
    function anchoVisible() { const cont = document.getElementById('tl-gantt'); if (cont) cont.style.setProperty('--sgs-tl-visible', `${cont.clientWidth}px`); }
    if (window.ResizeObserver) document.addEventListener('DOMContentLoaded', () => { const c = document.getElementById('tl-gantt'); if (c) new ResizeObserver(anchoVisible).observe(c); });

    function render() {
        const cont = document.getElementById('tl-gantt');
        if (!cont || !datos) return;
        cerrarPop(false);
        const lista = filas();
        rango = calcularRango(lista);
        const cnt = document.getElementById('tl-contador');
        if (cnt) cnt.innerHTML = `<i class="bi bi-people"></i><span>${lista.length} supervisor${lista.length === 1 ? '' : 'es'}</span>`;
        if (!lista.length) { cont.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-inbox me-1"></i>Sin actividad registrada en la fecha.</div>'; return; }
        document.querySelectorAll('#tl-escala [data-escala]').forEach((b) => b.classList.toggle('active', Number(b.dataset.escala) === escala));
        // Escala ×2 / ×4: cada hora se ensancha y el diagrama se desplaza de lado
        const anchoHoras = (rango.fin - rango.ini) / 3600 * PX_HORA * escala;
        let html = `<div class="sgs-gantt-inner" ${escala > 1 ? est(`min-width:${Math.round(230 + anchoHoras)}px`) : ''}><div class="sgs-gantt-row sgs-gantt-head"><div class="sgs-gantt-name">Supervisor</div><div class="sgs-gantt-track">${horas(true)}${lineaAhora(true)}</div></div>`;
        lista.forEach((s) => {
            const si = datos.supervisores.indexOf(s);
            const avatar = s.avatar ? `<img class="sgs-gantt-avatar" src="${esc(s.avatar)}" alt="">` : `<span class="sgs-gantt-avatar" aria-hidden="true">${esc(iniciales(s.nombre_corto || s.nombre_completo))}</span>`;
            const sub = s.es_licencia ? s.estado_general : `${s.sub_estado || s.estado_general} · ${Number(s.total_supervisiones)} sup.`;
            let pista = horas(false) + lineaAhora(false), marcas = '';
            if (s.es_licencia) pista += `<div class="sgs-gantt-licencia"><i class="bi bi-sun me-1"></i>${esc(s.estado_general)}</div>`;
            else {
                if (s.turno_ts) {
                    const finT = s.turno_ts.fin || (datos.es_hoy ? datos.ahora : s.turno_ts.inicio + 60);
                    pista += `<button type="button" class="sgs-gantt-turno${s.turno_ts.fin ? '' : ' is-abierto'}" ${est(`${caja(s.turno_ts.inicio, finT)}`)} data-s="${si}" data-turno="1" title="Turno ${hhmm(s.turno_ts.inicio)}–${s.turno_ts.fin ? hhmm(s.turno_ts.fin) : 'en curso'}" aria-label="Turno de ${esc(s.nombre_corto)}"></button>`;
                    // Flechas de apertura y cierre del turno, con su hora (se dibujan al final para quedar encima de los bloques)
                    marcas = `<button type="button" class="sgs-gantt-marca is-inicio" ${est(`left:${pos(s.turno_ts.inicio).toFixed(3)}%`)} data-s="${si}" data-turno="1" title="Inició turno a las ${hhmm(s.turno_ts.inicio)}" aria-label="Inició turno a las ${hhmm(s.turno_ts.inicio)}"><i class="bi bi-arrow-right-circle-fill" aria-hidden="true"></i><span>${hhmm(s.turno_ts.inicio)}</span></button>`
                        + (s.turno_ts.fin ? `<button type="button" class="sgs-gantt-marca is-fin" ${est(`left:${pos(s.turno_ts.fin).toFixed(3)}%`)} data-s="${si}" data-turno="1" title="Cerró turno a las ${hhmm(s.turno_ts.fin)}" aria-label="Cerró turno a las ${hhmm(s.turno_ts.fin)}"><i class="bi bi-arrow-left-circle-fill" aria-hidden="true"></i><span>${hhmm(s.turno_ts.fin)}</span></button>` : '');
                }
                (s.timeline_blocks || []).forEach((b, bi) => { pista += bloqueHtml(b, si, bi); });
                if (!s.turno_ts && !(s.timeline_blocks || []).some((b) => b.ts_inicio)) pista += '<div class="sgs-gantt-vacio">Sin actividad en la fecha</div>';
                pista += marcas;
            }
            const abierto = abiertos.has(Number(s.supervisor_id));
            html += `<div class="sgs-gantt-row"><div class="sgs-gantt-name"><button type="button" class="sgs-gantt-expand${abierto ? ' is-open' : ''}" data-expand="${Number(s.supervisor_id)}" aria-expanded="${abierto}" aria-label="${abierto ? 'Ocultar' : 'Ver'} lo que atendió ${esc(s.nombre_corto)}"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>`
                + `<button type="button" class="sgs-gantt-who-btn" data-s="${si}" data-turno="1" title="${esc(s.nombre_completo)}">${avatar}<span class="sgs-gantt-who"><strong>${esc(s.nombre_corto || s.nombre_completo)}</strong><small>${esc(sub)}</small></span></button></div><div class="sgs-gantt-track">${pista}</div></div>`
                + (abierto ? detalleHtml(s, si) : '');
        });
        cont.innerHTML = html + '</div>';
        anchoVisible();
        // Al abrir, la regla queda en la hora actual (o al inicio de la jornada)
        const primeras = lista.flatMap((s) => [s.turno_ts?.inicio, ...(s.timeline_blocks || []).map((b) => b.ts_inicio)]).filter(Boolean);
        const objetivo = datos.es_hoy ? datos.ahora - 2 * 3600 : (primeras.length ? Math.min(...primeras) - 1800 : rango.ini);
        const inner = cont.querySelector('.sgs-gantt-inner');
        const anchoNombre = cont.querySelector('.sgs-gantt-name')?.offsetWidth || 0;
        if (inner && cont.scrollWidth > cont.clientWidth) cont.scrollLeft = Math.max(0, (inner.scrollWidth - anchoNombre) * pos(objetivo) / 100);
    }

    // ---------- Ventana flotante ----------
    // devolverFoco: al cerrar con la X o Esc el foco vuelve al bloque; al pasar a otra ventana no (movería el diagrama y cerraría la nueva)
    function cerrarPop(devolverFoco = true) { if (pop) { const origen = pop._origen; pop.remove(); pop = null; if (devolverFoco) origen?.focus?.({ preventScroll: true }); } }
    function abrirPop(origen, titulo, cuerpo, acciones = '') {
        cerrarPop(false);
        pop = document.createElement('div');
        pop.className = 'sgs-tl-pop';
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', titulo.replace(/<[^>]+>/g, ''));
        pop.innerHTML = `<div class="sgs-tl-pop-head"><h6>${titulo}</h6><button type="button" class="btn-close" aria-label="Cerrar"></button></div>${cuerpo}${acciones ? `<div class="d-flex flex-wrap gap-2 justify-content-end">${acciones}</div>` : ''}`;
        pop._origen = origen;
        pop._abiertoEn = Date.now();
        document.body.appendChild(pop);
        if (window.innerWidth < 576) pop.classList.add('is-sheet');
        else {
            const r = origen.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
            let left = Math.min(window.innerWidth - w - 12, Math.max(12, r.left + r.width / 2 - w / 2));
            let top = r.bottom + 8; if (top + h > window.innerHeight - 12) top = Math.max(12, r.top - h - 8);
            pop.style.left = `${left}px`; pop.style.top = `${top}px`;
        }
        pop.querySelector('.btn-close').addEventListener('click', cerrarPop);
        pop.querySelector('.btn-close').focus({ preventScroll: true });
    }
    const fila = (k, v) => `<dt>${k}</dt><dd>${v}</dd>`;

    function popSupervisor(origen, s) {
        const bloques = (s.timeline_blocks || []).filter((b) => b.ts_inicio);
        const minutos = (tipo) => bloques.filter((b) => tipo.includes(b.type)).reduce((t, b) => t + (finDe(b) - b.ts_inicio) / 60, 0);
        const t = s.turno_ts;
        const finT = t ? (t.fin || (datos.es_hoy ? datos.ahora : null)) : null;
        let cuerpo = '<dl>';
        if (s.es_licencia) cuerpo += fila('Estado', esc(s.estado_general));
        else if (!t) cuerpo += fila('Turno', 'No inició turno');
        else {
            cuerpo += fila('Inicio de turno', hhmm(t.inicio)) + fila('Fin de turno', t.fin ? hhmm(t.fin) : '<span class="badge bg-success">En turno</span>')
                + fila(t.fin ? 'Duración del turno' : 'Tiempo en turno', dur(finT ? (finT - t.inicio) / 60 : null));
        }
        cuerpo += fila('Supervisiones', Number(s.total_supervisiones)) + fila('En supervisiones', dur(minutos(['supervision'])))
            + fila('En traslados', dur(minutos(['travel']))) + fila('Almuerzo y pausas', dur(minutos(['pause']))) + fila('Trabajo remoto', dur(minutos(['remote'])));
        if (s.primera_accion) cuerpo += fila('1.ª acción', `${esc(s.primera_accion.tipo)} a las ${esc(s.primera_accion.hora)} (${Number(s.primera_accion.minutos)} min después)`);
        cuerpo += '</dl>';
        let acciones = '';
        if (datos.puede_reabrir && datos.es_hoy && t && t.fin) acciones += `<button type="button" class="btn btn-outline-secondary btn-sm" data-reabrir="${Number(s.supervisor_id)}"><i class="bi bi-arrow-counterclockwise me-1"></i>Reabrir turno</button>`;
        abrirPop(origen, `<i class="bi bi-person-badge me-1 text-primary"></i>${esc(s.nombre_completo)}`, cuerpo, acciones);
        pop.querySelector('[data-reabrir]')?.addEventListener('click', async (ev) => {
            if (!confirm(`¿Reabrir el turno de hoy de ${s.nombre_completo}?`)) return;
            ev.currentTarget.disabled = true;
            try {
                const resp = await fetch('api/asistencia.php?action=reabrir', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ supervisor_id: Number(s.supervisor_id) }) });
                const res = await resp.json();
                window.showToast?.(res.message || 'Listo.', res.status === 'success' ? 'success' : 'error');
                if (res.status === 'success') { cerrarPop(); cargar(true); }
            } catch (e) { window.showToast?.('No se pudo reabrir el turno.', 'error'); }
        });
    }
    function popBloque(origen, s, b) {
        const fin = b.ts_fin ? hhmm(b.ts_fin) : (b.type === 'travel' ? hhmm(finDe(b)) : '<span class="badge bg-warning">En curso</span>');
        const minutos = (finDe(b) - b.ts_inicio) / 60;
        if (b.type === 'supervision') {
            const cuerpo = '<dl>' + fila('Supervisor', esc(s.nombre_corto)) + fila('Código', esc(b.codigo)) + (b.ticket ? fila('Pedido / ticket', esc(b.ticket)) : '')
                + fila('Estado', b.estado === 'finalizada' ? '<span class="badge bg-success">Finalizada</span>' : '<span class="badge bg-warning">En curso</span>')
                + (b.modalidad ? fila('Modalidad', b.modalidad === 'Remota' ? '<i class="bi bi-camera-video me-1"></i>Remota (videollamada)' : esc(b.modalidad)) : '')
                + fila('Inicio', hhmm(b.ts_inicio)) + fila('Fin', fin) + fila(b.ts_fin ? 'Duración' : 'Lleva', dur(minutos))
                + fila('Técnico', esc(b.tecnico || '—')) + fila('Partner', esc(b.partner || '—')) + '</dl>';
            abrirPop(origen, `<i class="bi bi-clipboard-check me-1 text-primary"></i>Supervisión ${esc(b.ticket || b.codigo)}`, cuerpo,
                `<a class="btn btn-win btn-sm" href="index.php?page=supervision_lista&highlight=${Number(b.id)}"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir supervisión</a>`);
            return;
        }
        const nombre = b.type === 'travel' ? 'Traslado' : (b.type === 'remote' ? 'Trabajo remoto' : b.title);
        let cuerpo = '<dl>' + fila('Supervisor', esc(s.nombre_corto)) + fila('Desde', hhmm(b.ts_inicio)) + fila('Hasta', fin) + fila(b.ts_fin ? 'Duración' : 'Lleva', dur(minutos));
        if (b.type === 'travel') cuerpo += fila('Entre', 'el fin de una supervisión y el inicio de la siguiente');
        if (b.type === 'remote' && b.nota) cuerpo += fila('Nota', esc(b.nota));
        abrirPop(origen, `<i class="bi ${b.type === 'travel' ? 'bi-car-front' : b.type === 'remote' ? 'bi-laptop' : 'bi-cup-hot'} me-1 text-primary"></i>${esc(nombre)}`, cuerpo + '</dl>');
    }

    document.addEventListener('click', (ev) => {
        if (pop && !pop.contains(ev.target) && !ev.target.closest('[data-s]')) cerrarPop();
        const exp = ev.target.closest('#tl-gantt [data-expand]');
        if (exp) {
            const id = Number(exp.dataset.expand);
            abiertos.has(id) ? abiertos.delete(id) : abiertos.add(id);
            guardar('sgs-tl-abiertos', [...abiertos]);
            const izq = document.getElementById('tl-gantt').scrollLeft;
            render();
            document.getElementById('tl-gantt').scrollLeft = izq;
            document.querySelector(`#tl-gantt [data-expand="${id}"]`)?.focus({ preventScroll: true });
            return;
        }
        const esc2 = ev.target.closest('#tl-escala [data-escala]');
        if (esc2) { escala = Number(esc2.dataset.escala); guardar('sgs-tl-escala', escala); render(); return; }
        const el = ev.target.closest('#tl-gantt [data-s]');
        if (!el || !datos) return;
        const s = datos.supervisores[Number(el.dataset.s)];
        if (!s) return;
        if (el.dataset.turno) popSupervisor(el, s);
        else popBloque(el, s, s.timeline_blocks[Number(el.dataset.b)]);
    });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarPop(); });
    window.addEventListener('resize', () => cerrarPop(false));
    // Al desplazar el diagrama la ventana quedaría lejos de su bloque: se cierra. No en celular (hoja inferior) ni justo al abrir,
    // porque tocar un bloque cortado en el borde hace que el navegador desplace el diagrama para mostrarlo.
    document.getElementById('tl-gantt')?.addEventListener('scroll', () => { if (pop && !pop.classList.contains('is-sheet') && Date.now() - pop._abiertoEn > 500) cerrarPop(false); }, { passive: true });

    // ---------- Carga ----------
    async function cargarEstados() {
        try {
            const data = await (await fetch('api/dashboard.php?action=monitoreo_realtime')).json();
            if (data.status !== 'success') return;
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? 0; };
            set('stat-en-turno', data.resumen.en_turno); set('stat-en-capacitacion', data.resumen.en_capacitacion);
            set('stat-en-break', data.resumen.en_break); set('stat-fuera-turno', data.resumen.fuera_turno);
        } catch (e) { /* los contadores no bloquean la línea de tiempo */ }
    }
    async function cargar(forzar = false) {
        if (cargando || (!forzar && (document.hidden || pop))) return; // no se recarga con la ventana flotante abierta
        cargando = true;
        try {
            const fecha = document.getElementById('filter-timeline-fecha')?.value || '';
            const [, resp] = await Promise.all([cargarEstados(), fetch(`api/asistencia.php?action=timeline_diaria&fecha=${encodeURIComponent(fecha)}`)]);
            const data = await resp.json();
            if (data.status !== 'success') throw new Error(data.message || 'Sin datos');
            datos = data;
            render();
        } catch (e) {
            const cont = document.getElementById('tl-gantt');
            if (cont) cont.innerHTML = '<div class="text-center text-danger py-4">No se pudo cargar la línea de tiempo.</div>';
        } finally { cargando = false; }
    }

    document.addEventListener('DOMContentLoaded', () => {
        cargar(true);
        document.getElementById('btn-refresh-timeline')?.addEventListener('click', () => cargar(true));
        document.getElementById('filter-timeline-fecha')?.addEventListener('change', () => cargar(true));
        document.getElementById('filter-timeline-buscar')?.addEventListener('input', render);
        document.getElementById('filter-timeline-activos')?.addEventListener('change', render);
        setInterval(() => cargar(), 60000);
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) cargar(); });
})();
</script>
