// 03_FRONTEND/src/assets/js/dashboard_partners.js - Dashboard de partners (2026-10-20, pages/dashboard_partners.php).
// Usa las piezas comunes de sgs-dashboard.js: totales de api/dashboard_partners.php?action=dashboard (calculados en la base).
(() => {
    const raiz = document.getElementById('dpa');
    if (!raiz || !window.SgsDashboard) return;
    const D = window.SgsDashboard;
    const $ = (id) => document.getElementById(id);
    const n = (v) => Number(v) || 0;
    const pct = (v) => v == null ? '—' : `${v} %`;
    // Tono del %: verde desde 90, amarillo desde 70, rojo debajo (siempre con su número)
    const tono = (v) => v == null ? '' : (v >= 90 ? 'success' : (v >= 70 ? 'warning' : 'danger'));
    const insignia = (v, ayuda = '') => `<span class="badge bg-${tono(v) || 'secondary'}"${ayuda ? ` title="${D.esc(ayuda)}"` : ''}>${pct(v)}</span>`;
    const tonoKpi = (clave, v) => { const c = raiz.querySelector(`[data-tono="${clave}"]`); if (c) c.className = `sgs-kpi p-3 h-100${tono(v) ? ` sgs-kpi--${tono(v)}` : ''}`; };
    const minutos = (m) => m == null ? '—' : (m >= 1440 ? `${Math.floor(m / 1440)} d ${Math.floor((m % 1440) / 60)} h` : (m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`));
    const filas = (cuerpo, lista, columnas, fila, vacio) => {
        $(cuerpo).innerHTML = lista.length ? lista.map(fila).join('')
            : `<tr><td colspan="${columnas}" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>${vacio}</td></tr>`;
    };
    const total = (x) => n(x.total) || 1;

    D.iniciar({
        raiz, form: $('dpaFiltros'), url: 'api/dashboard_partners.php?action=dashboard', tip: $('dpaTip'),
        pintar: (d) => {
            $('dpaPeriodo').querySelector('span').textContent = `Período: ${D.fecha(d.periodo.inicio)} – ${D.fecha(d.periodo.fin)}`;
            const c = d.cumplimiento, rc = c.resumen, o = d.observaciones, ro = o.resumen;
            D.kpis(raiz, {
                c_asignadas: n(rc.asignadas), c_a_tiempo: n(rc.a_tiempo), c_bloqueadas: n(rc.bloqueadas), c_en_plazo: n(rc.en_plazo),
                c_promedio: minutos(rc.minutos_promedio), c_cumplimiento: pct(rc.cumplimiento),
                o_total: n(ro.total), o_a_tiempo: n(ro.a_tiempo), o_vencidas: n(ro.sin_respuesta_vencidas), o_en_plazo: n(ro.en_plazo),
                o_revision: n(ro.en_revision), o_promedio: minutos(ro.minutos_respuesta), o_validadas: n(ro.validadas), o_penalizadas: n(ro.penalizadas),
                o_pct: pct(ro.pct_a_tiempo), o_pct_pen: pct(ro.pct_penalizadas),
            });
            tonoKpi('c_cumplimiento', rc.cumplimiento);
            tonoKpi('o_pct', ro.pct_a_tiempo);

            filas('dpaCumplPartners', c.partners, 5, (p) => `<tr><td data-label="Partner" class="fw-bold">${D.esc(p.nombre_partner)}</td>
                <td data-label="Asignadas" class="text-end">${n(p.asignadas)}</td><td data-label="A tiempo" class="text-end">${n(p.a_tiempo)}</td>
                <td data-label="Bloqueadas" class="text-end">${n(p.bloqueadas)}</td><td data-label="Cumplimiento" class="text-end">${insignia(p.cumplimiento)}</td></tr>`,
            'No hay órdenes asignadas a partners en el período.');
            filas('dpaBloqueadas', c.bloqueadas, 3, (b) => {
                const h = n(b.horas_vencida);
                return `<tr><td data-label="Orden"><div><div class="fw-bold">${D.esc(b.codigo_supervision)}</div><small class="text-muted">${D.esc(b.departamento || '')}</small></div></td>
                    <td data-label="Partner y supervisor"><div><div>${D.esc(b.nombre_partner)}</div><small class="text-muted">${D.esc(b.supervisor_partner || '')}</small></div></td>
                    <td data-label="Vencida hace" class="text-end"><span class="badge bg-danger">${h >= 24 ? `${Math.floor(h / 24)} d ${h % 24} h` : `${h} h`}</span></td></tr>`;
            }, 'Sin órdenes bloqueadas en el período.');
            filas('dpaSupervisores', c.supervisores, 8, (u) => `<tr><td data-label="Supervisor" class="fw-bold">${D.esc(u.nombre_completo)}</td>
                <td data-label="Partner">${D.esc(u.nombre_partner)}</td><td data-label="Asignadas" class="text-end">${n(u.asignadas)}</td>
                <td data-label="A tiempo" class="text-end">${n(u.a_tiempo)}</td><td data-label="En plazo" class="text-end">${n(u.en_plazo)}</td>
                <td data-label="Bloqueadas" class="text-end">${n(u.bloqueadas)}</td><td data-label="Respuesta promedio" class="text-end">${minutos(u.minutos_promedio)}</td>
                <td data-label="Cumplimiento" class="text-end">${insignia(u.cumplimiento)}</td></tr>`,
            'Sin órdenes asignadas a supervisores de partners en el período.');

            filas('dpaObsPartners', o.partners, 8, (p) => `<tr><td data-label="Partner" class="fw-bold">${D.esc(p.nombre_partner)}</td>
                <td data-label="Observaciones" class="text-end">${n(p.total)}</td><td data-label="A tiempo" class="text-end">${n(p.a_tiempo)}</td>
                <td data-label="Vencidas sin respuesta" class="text-end">${n(p.sin_respuesta_vencidas)}</td><td data-label="Por revisar" class="text-end">${n(p.en_revision)}</td>
                <td data-label="Validadas" class="text-end">${n(p.validadas)}</td><td data-label="Penalizadas" class="text-end">${n(p.penalizadas)}</td>
                <td data-label="Respuesta a tiempo" class="text-end">${insignia(p.pct_a_tiempo)}</td></tr>`,
            'Sin observaciones a partners en el período.');
            const maxM = Math.max(1, ...o.motivos.map(total));
            $('dpaMotivos').innerHTML = D.barras(o.motivos.map((m) => ({ ...m, nombre: m.motivo })), {
                valor: (m) => total(m) * 100 / maxM, texto: (m) => `${n(m.total)}${n(m.penalizadas) ? ` <small>${n(m.penalizadas)} penalizadas</small>` : ''}`,
                ayuda: (m) => `${m.motivo} (${m.tipo}): ${n(m.total)} observaciones, ${n(m.penalizadas)} penalizadas`, vacio: 'Sin observaciones en el período.' });
            const orden = ['Muy Grave', 'Grave', 'Media', 'Leve', 'Checklist'];
            const grav = [...o.gravedad].sort((a, b) => orden.indexOf(a.gravedad) - orden.indexOf(b.gravedad));
            const maxG = Math.max(1, ...grav.map(total));
            $('dpaGravedad').innerHTML = D.barras(grav.map((g) => ({ ...g, nombre: g.gravedad === 'Checklist' ? 'Checklist (ítems observados)' : g.gravedad })), {
                valor: (g) => total(g) * 100 / maxG, texto: (g) => `${n(g.total)}`, ayuda: (g) => `${g.nombre}: ${n(g.total)}`, vacio: 'Sin observaciones en el período.' });
        },
    });
})();
