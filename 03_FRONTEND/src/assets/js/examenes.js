// 03_FRONTEND/src/assets/js/examenes.js - Dashboard de exámenes (2026-10-20, pages/examenes.php).
// Usa las piezas comunes de sgs-dashboard.js: totales (api/examenes.php?action=dashboard, calculados en la base) y tabla
// por páginas (action=dashboard_tabla). Barras de una sola serie: % de aprobación con su texto ("8 de 10").
(() => {
    const raiz = document.getElementById('exd');
    if (!raiz || !window.SgsDashboard) return;
    const D = window.SgsDashboard;
    const $ = (id) => document.getElementById(id);
    const clases = { aprobado: 'bg-success', desaprobado: 'bg-danger', pendiente: 'bg-secondary', rindiendo: 'bg-warning', no_rindio: 'bg-secondary', anulado: 'bg-secondary' };
    const aprobacion = { valor: (x) => x.pct, texto: (x) => `${x.pct} % <small>${x.aprobados} de ${x.rindieron}</small>`,
        ayuda: (x) => `${x.nombre}: ${x.aprobados} de ${x.rindieron} aprobaron (${x.pct} %)`, vacio: 'Todavía no hay exámenes rendidos con estos filtros.' };

    const tabla = D.tabla({
        url: 'api/examenes.php?action=dashboard_tabla', form: $('exdFiltros'), cuerpo: $('exdTabla'), paginas: $('exdPaginas'),
        estados: $('exdEstados'), buscar: $('exdBuscar'), columnas: 6, unidad: 'técnicos',
        fila: (f, d) => `<tr>
            <td data-label="Técnico"><div><div class="fw-bold">${D.esc(f.tecnico)}</div><small class="text-muted">${f.tipo === 1 ? 'T1' : 'T2'} · DNI ${D.esc(f.dni || '—')}</small></div></td>
            <td data-label="Partner y zona"><div><div>${D.esc(f.partner)}</div><small class="text-muted">${D.esc(f.zona)}</small></div></td>
            <td data-label="Examen"><div><div>${D.esc(f.examen)}</div><small class="text-muted">Link del ${D.fecha(f.link)}</small></div></td>
            <td data-label="Rendido">${f.rendido ? `${D.fecha(f.rendido)} <small class="text-muted">${D.hora(f.rendido)}</small>` : '<span class="text-muted">—</span>'}</td>
            <td data-label="Nota" class="text-end">${f.nota === null ? '<span class="text-muted">—</span>' : `<span class="fw-bold">${Math.round(f.nota)} %</span><br><small class="text-muted">${f.correctas} de ${f.total}${f.segundos !== null ? ` · ${Math.floor(f.segundos / 60)}:${String(f.segundos % 60).padStart(2, '0')} min` : ''}</small>`}</td>
            <td data-label="Resultado"><span class="badge ${clases[f.estado] || 'bg-secondary'}">${D.esc(d.etiquetas[f.estado] || f.estado)}</span></td></tr>`,
    });

    D.iniciar({
        raiz, form: $('exdFiltros'), url: 'api/examenes.php?action=dashboard', excel: $('exdExcel'), tabla, tip: $('exdTip'),
        pintar: (d) => {
            const k = d.kpis;
            D.kpis(raiz, {
                convocados: k.convocados, rindieron: k.rindieron, pct_aprobacion: k.pct_aprobacion === null ? '—' : `${k.pct_aprobacion} %`,
                aprobados_texto: k.rindieron ? `${k.aprobados} de ${k.rindieron} aprobaron` : 'Sin exámenes rendidos',
                nota_promedio: k.nota_promedio === null ? '—' : `${k.nota_promedio} %`, por_rendir: k.por_rendir,
                no_rindieron_texto: k.no_rindieron ? `${k.no_rindieron} no rindieron (link vencido)` : '',
            });
            $('exdContador').textContent = `${k.convocados} ${k.convocados === 1 ? 'técnico convocado' : 'técnicos convocados'}`;
            D.cuentas(raiz, d.cuentas);
            $('exdPartner_g').innerHTML = D.barras(d.por_partner, aprobacion);
            $('exdZona_g').innerHTML = D.barras(d.por_zona, aprobacion);
            $('exdTemas_g').innerHTML = D.barras(d.temas, { valor: (x) => x.pct, texto: (x) => `${x.pct} % <small>${x.falladas} de ${x.total}</small>`,
                ayuda: (x) => `${x.nombre}: ${x.falladas} de ${x.total} respuestas incorrectas (${x.pct} %)`, vacio: 'Todavía no hay exámenes rendidos con estos filtros.' });
            $('exdNotas_g').innerHTML = D.columnas(d.notas, { etiqueta: (x) => x.nombre, ayuda: (x) => `${x.nombre}: ${x.cantidad} ${x.cantidad === 1 ? 'técnico' : 'técnicos'}`, vacio: 'Todavía no hay exámenes rendidos con estos filtros.' });
            $('exdDias_g').innerHTML = D.columnas(d.por_dia, { etiqueta: (x) => D.fecha(x.fecha).slice(0, 5), ayuda: (x) => `${D.fecha(x.fecha)}: ${x.cantidad} ${x.cantidad === 1 ? 'examen rendido' : 'exámenes rendidos'}`, vacio: 'Todavía no hay exámenes rendidos con estos filtros.' });
        },
    });
})();
