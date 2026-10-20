// 03_FRONTEND/src/assets/js/dashboard_pendientes.js - Dashboard "Pendientes y rescates" (2026-10-20, pages/dashboard_pendientes.php).
// Usa las piezas comunes de sgs-dashboard.js: totales (api/dashboard_pendientes.php?action=dashboard, calculados en la base)
// y tabla por páginas (action=dashboard_tabla). Gráficos de una sola serie con su texto.
(() => {
    const raiz = document.getElementById('dpd');
    if (!raiz || !window.SgsDashboard) return;
    const D = window.SgsDashboard;
    const $ = (id) => document.getElementById(id);
    const api = 'api/dashboard_pendientes.php?action=';
    const clases = { pendiente: 'bg-secondary', en_validacion: 'bg-primary', en_rescate: 'bg-warning', rescatada: 'bg-success', correcta: 'bg-dark', en_partner: 'bg-warning', partner_atendida: 'bg-primary' };
    // Tono del % (verde desde 90, amarillo desde 70, rojo debajo), siempre con su número.
    const tono = (v) => v >= 90 ? 'bg-success' : (v >= 70 ? 'bg-warning' : 'bg-danger');
    const n = (v) => Number(v) || 0;
    const vacioFila = (cols, texto) => `<tr><td colspan="${cols}" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>${texto}</td></tr>`;
    const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;
    const duracion = (h) => h < 24 ? `${Math.round(h)} h` : `${Math.round(h / 24 * 10) / 10} días`;
    const relativa = (texto, ayuda) => ({ valor: (x) => x.pct, texto, ayuda, vacio: 'Todavía no hay cancelaciones cargadas con estos filtros.' });

    const tabla = D.tabla({
        url: `${api}dashboard_tabla`, form: $('dpdFiltros'), cuerpo: $('dpdTabla'), paginas: $('dpdPaginas'),
        estados: $('dpdEstados'), buscar: $('dpdBuscar'), columnas: 5, unidad: 'órdenes',
        fila: (f, d) => `<tr>
            <td data-label="Pedido o ticket"><div><div class="fw-bold">${D.esc(f.ticket)}</div><small class="text-muted">Cargada el ${D.fecha(f.carga)} ${D.hora(f.carga)}</small></div></td>
            <td data-label="Zona y partner"><div><div>${D.esc(f.zona)}${f.distrito ? ` <small class="text-muted">· ${D.esc(f.distrito)}</small>` : ''}</div><small class="text-muted">${D.esc(f.partner)}</small></div></td>
            <td data-label="Motivo"><small>${D.esc(f.motivo)}</small></td>
            <td data-label="Responsable">${f.estado === 'pendiente' ? `<span class="text-muted">${D.esc(f.responsable)}</span>` : D.esc(f.responsable || '—')}${f.atiende_partner ? `<small class="text-muted d-block">Atiende: ${D.esc(f.atiende_partner)}</small>` : ''}</td>
            <td data-label="Estado"><div><span class="badge ${clases[f.estado] || 'bg-secondary'}">${D.esc(d.etiquetas[f.estado] || f.estado)}</span>
              <small class="text-muted d-block mt-1">${f.cierre ? `Cerrada en ${duracion(f.horas)}` : `Hace ${duracion(f.horas)}`}</small></div></td></tr>`,
    });

    D.iniciar({
        raiz, form: $('dpdFiltros'), url: `${api}dashboard`, excel: $('dpdExcel'), tabla, tip: $('dpdTip'),
        pintar: (d) => {
            const k = d.kpis;
            D.kpis(raiz, {
                cargadas: k.cargadas, pendiente: k.pendiente, en_rescate: k.en_rescate, rescatada: k.rescatada, correcta: k.correcta, en_validacion: k.en_validacion,
                en_partner: k.en_partner, partner_atendida: k.partner_atendida, vencidas_texto: k.vencidas_partner ? `${k.vencidas_partner} vencidas (más de 24 h)` : '',
                pendientes_texto: d.antiguedad[2].cantidad ? `${d.antiguedad[2].cantidad} con más de 3 días` : '',
                pct_rescate: k.pct_rescate === null ? '—' : `${k.pct_rescate} %`, pct_rescate_texto: (k.rescatada + k.correcta) ? `de ${plural(k.rescatada + k.correcta, 'cerrada', 'cerradas')}` : 'Sin cerradas',
                horas_promedio: k.horas_promedio === null ? '—' : duracion(k.horas_promedio), horas_texto: 'de la carga al cierre',
            });
            $('dpdContador').textContent = plural(k.cargadas, 'cancelación cargada', 'cancelaciones cargadas');
            D.cuentas(raiz, d.cuentas);
            $('dpdEmbudo').innerHTML = D.barras(d.embudo, relativa((x) => `${x.cantidad} <small>${x.pct} %</small>`, (x) => `${x.nombre}: ${x.cantidad} (${x.pct} % de las cargadas)`));
            $('dpdAntiguedad').innerHTML = D.columnas(d.antiguedad, { etiqueta: (x) => x.nombre, ayuda: (x) => `${x.nombre}: ${plural(x.cantidad, 'pendiente', 'pendientes')}`, vacio: 'No hay pendientes sin tomar.' });
            $('dpdZona').innerHTML = D.barras(d.por_zona, relativa((x) => `${x.cargadas} <small>${x.pendientes} pend.</small>`, (x) => `${x.nombre}: ${x.cargadas} cargadas, ${x.pendientes} pendientes, ${x.rescatadas} rescatadas`));
            $('dpdPartner').innerHTML = D.barras(d.por_partner.filter((x) => x.cerradas), { valor: (x) => x.pct_rescate, texto: (x) => `${x.pct_rescate} % <small>${x.rescatadas} de ${x.cerradas}</small>`,
                ayuda: (x) => `${x.nombre}: ${x.rescatadas} rescatadas de ${x.cerradas} cerradas (${x.pct_rescate} %)`, vacio: 'Todavía no hay cancelaciones cerradas con estos filtros.' });
            $('dpdMotivos').innerHTML = D.barras(d.motivos, relativa((x) => `${x.cantidad}`, (x) => `${x.nombre}: ${x.cantidad}`));
            $('dpdRescates').innerHTML = D.barras(d.rescates, { valor: (x) => x.pct, texto: (x) => `${x.cantidad}${x.en_curso ? ` <small>+${x.en_curso} en curso</small>` : ''}`,
                ayuda: (x) => `${x.nombre}: ${plural(x.cantidad, 'rescatada', 'rescatadas')}, ${x.en_curso} en curso`, vacio: 'Todavía no hay rescates con estos filtros.' });
            $('dpdDirectos').innerHTML = d.directos.length ? d.directos.map((x) => `<tr>
                <td data-label="Supervisor" class="fw-bold">${D.esc(x.nombre)}</td><td data-label="Tomadas" class="text-end">${n(x.tomadas)}</td>
                <td data-label="Atendió él" class="text-end">${n(x.cerradas)}</td><td data-label="En validación" class="text-end">${n(x.en_validacion)}</td>
                <td data-label="En rescate" class="text-end">${n(x.en_rescate)}</td><td data-label="Rescatadas" class="text-end">${n(x.rescatadas)}</td>
                <td data-label="Transferidas" class="text-end">${n(x.transferidas)}</td><td data-label="Atendió el partner" class="text-end">${n(x.atendidas_partner)}</td>
                <td data-label="Vencidas en partner" class="text-end">${n(x.vencidas_partner) ? `<span class="text-danger fw-bold">${n(x.vencidas_partner)}</span>` : 0}</td>
                <td data-label="Tiempo" class="text-end">${x.horas === null ? '—' : duracion(x.horas)}</td>
                <td data-label="Atención" class="text-end"><span class="badge ${tono(x.pct_atencion)}" title="${n(x.atendidas)} de ${n(x.tomadas)} atendidas">${x.pct_atencion} %</span></td></tr>`).join('')
                : vacioFila(11, 'Ningún supervisor directo tomó pendientes con estos filtros.');
            $('dpdPartners').innerHTML = d.partners.length ? d.partners.map((x) => `<tr>
                <td data-label="Supervisor" class="fw-bold">${D.esc(x.nombre)}</td><td data-label="Partner">${D.esc(x.partner)}</td>
                <td data-label="Asignadas" class="text-end">${n(x.asignadas)}</td><td data-label="Atendidas" class="text-end">${n(x.atendidas)}</td>
                <td data-label="En plazo" class="text-end">${n(x.en_plazo)}</td>
                <td data-label="Vencidas" class="text-end">${n(x.vencidas) ? `<span class="text-danger fw-bold">${n(x.vencidas)}</span>` : 0}</td>
                <td data-label="Tiempo de respuesta" class="text-end">${x.horas === null ? '—' : duracion(x.horas)}</td>
                <td data-label="A tiempo" class="text-end"><span class="badge ${tono(x.pct_a_tiempo)}" title="${n(x.a_tiempo)} de ${n(x.asignadas)} atendidas dentro de 24 h">${x.pct_a_tiempo} %</span></td></tr>`).join('')
                : vacioFila(8, 'No hay pendientes transferidas a partners con estos filtros.');
            $('dpdDias').innerHTML = D.columnas(d.por_dia, { etiqueta: (x) => D.fecha(x.fecha).slice(0, 5), ayuda: (x) => `${D.fecha(x.fecha)}: ${plural(x.cantidad, 'cancelación cargada', 'cancelaciones cargadas')}`, vacio: 'Todavía no hay cancelaciones cargadas con estos filtros.' });
        },
    });
})();
