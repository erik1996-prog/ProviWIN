// 03_FRONTEND/src/assets/js/examenes.js - Dashboard de exámenes (2026-10-20, pages/examenes.php).
// Usa las piezas comunes de sgs-dashboard.js: totales (api/examenes.php?action=dashboard, calculados en la base) y tabla
// por páginas (action=dashboard_tabla). Barras de una sola serie: % de aprobación con su texto ("8 de 10").
(() => {
    const raiz = document.getElementById('exd');
    if (!raiz || !window.SgsDashboard) return;
    const D = window.SgsDashboard;
    const puedeBorrar = raiz.dataset.puedeBorrar === '1'; // permiso "Borrar exámenes" (2026-11-05)
    const $ = (id) => document.getElementById(id);
    const clases = { aprobado: 'bg-success', desaprobado: 'bg-danger', pendiente: 'bg-secondary', rindiendo: 'bg-warning', no_rindio: 'bg-secondary' };
    const aprobacion = { valor: (x) => x.pct, texto: (x) => `${x.pct} % <small>${x.aprobados} de ${x.rindieron}</small>`,
        ayuda: (x) => `${x.nombre}: ${x.aprobados} de ${x.rindieron} aprobaron (${x.pct} %)`, vacio: 'Todavía no hay exámenes rendidos con estos filtros.' };

    const tabla = D.tabla({
        url: 'api/examenes.php?action=dashboard_tabla', form: $('exdFiltros'), cuerpo: $('exdTabla'), paginas: $('exdPaginas'),
        estados: $('exdEstados'), buscar: $('exdBuscar'), columnas: 7, unidad: 'técnicos',
        fila: (f, d) => `<tr>
            <td data-label="Técnico"><div><div class="fw-bold">${D.esc(f.tecnico)}</div><small class="text-muted">${f.tipo === 1 ? 'T1' : 'T2'} · DNI ${D.esc(f.dni || '—')}</small></div></td>
            <td data-label="Partner y zona"><div><div>${D.esc(f.partner)}</div><small class="text-muted">${D.esc(f.zona)}</small></div></td>
            <td data-label="Examen"><div><div>${D.esc(f.examen)}</div><small class="text-muted">Link del ${D.fecha(f.link)}</small></div></td>
            <td data-label="Rendido">${f.rendido ? `${D.fecha(f.rendido)} <small class="text-muted">${D.hora(f.rendido)}</small>` : '<span class="text-muted">—</span>'}</td>
            <td data-label="Nota" class="text-end">${f.nota === null ? '<span class="text-muted">—</span>' : `<span class="fw-bold">${Math.round(f.nota)} %</span><br><small class="text-muted">${f.correctas} de ${f.total}${f.segundos !== null ? ` · ${Math.floor(f.segundos / 60)}:${String(f.segundos % 60).padStart(2, '0')} min` : ''}</small>`}</td>
            <td data-label="Resultado"><span class="badge ${clases[f.estado] || 'bg-secondary'}">${D.esc(d.etiquetas[f.estado] || f.estado)}</span></td>
            <td class="text-end"><div class="sgs-row-actions justify-content-end">
              ${f.rendido ? `<button type="button" class="sgs-icon-action" data-ver-examen="${f.id}" title="Ver examen: qué falló" aria-label="Ver el examen de ${D.esc(f.tecnico)}"><i class="bi bi-eye"></i></button>` : ''}
              ${puedeBorrar ? `<button type="button" class="sgs-icon-action text-danger" data-borrar-examen="${f.id}" title="Borrar este examen" aria-label="Borrar el examen de ${D.esc(f.tecnico)}"><i class="bi bi-trash"></i></button>` : ''}
            </div></td></tr>`,
    });

    // ---------- Ver el examen rendido (2026-11-05): qué marcó y cuál era la correcta, para reforzar ----------
    const foto = (src, clase) => src ? `<img class="${clase}" src="${D.esc(src)}" alt="" loading="lazy">` : '';
    const opcion = (p, o) => {
        let marca = '', estado = '';
        if (p.tipo === 'orden') {
            marca = `<span class="exr-pos" title="Posición correcta">${o.correcta}</span>`;
            estado = o.marcada === null ? '<small class="text-muted">No lo ordenó</small>'
                : `<small class="${o.marcada === o.correcta ? 'text-success' : 'text-danger'}">Lo puso ${o.marcada}.º</small>`;
        } else {
            marca = `<i class="bi ${o.correcta ? 'bi-check-circle-fill text-success' : (o.marcada ? 'bi-x-circle-fill text-danger' : 'bi-circle text-muted')}" aria-hidden="true"></i>`;
            estado = [o.marcada ? '<span class="badge bg-secondary">Su respuesta</span>' : '', o.correcta ? '<span class="badge bg-success">Correcta</span>' : ''].join(' ');
        }
        return `<li class="exr-opcion${o.correcta === true ? ' is-correcta' : ''}${o.marcada && o.correcta === false ? ' is-error' : ''}">${marca}${foto(o.imagen, 'exr-opcion-foto')}<span class="exr-opcion-texto">${D.esc(o.texto)}</span><span class="exr-opcion-estado">${estado}</span></li>`;
    };
    const verExamen = async (id) => {
        $('exTecCuerpo').innerHTML = '<p class="text-muted small mb-0">Cargando…</p>';
        bootstrap.Modal.getOrCreateInstance($('mExamenTec')).show(); // Bootstrap carga después de este archivo
        try {
            const r = await (await fetch(`api/examenes.php?action=examen_tecnico&id=${encodeURIComponent(id)}`, { credentials: 'same-origin' })).json();
            if (r.status !== 'success') throw new Error(r.message || 'No se pudo abrir el examen.');
            const d = r.data, res = d.resultado, falladas = d.preguntas.filter((p) => !p.acierto);
            $('exTecTitulo').textContent = d.tecnico;
            $('exTecCuerpo').innerHTML = `<p class="small text-muted mb-2">${d.tipo === 1 ? 'T1' : 'T2'} · DNI ${D.esc(d.dni || '—')}${d.partner ? ' · ' + D.esc(d.partner) : ''} · ${D.esc(d.examen)} · rendido el ${D.fecha(d.rendido)} ${D.hora(d.rendido)}</p>
                <div class="exr-resumen ${res.aprobado ? 'is-aprobado' : 'is-desaprobado'}"><strong>${Math.round(res.nota)} %</strong> · ${res.aprobado ? 'Aprobado' : 'No aprobado'} · ${res.correctas} de ${res.total} correctas</div>
                ${(res.reforzar || []).length ? `<p class="small mb-3"><i class="bi bi-lightbulb me-1 text-primary" aria-hidden="true"></i><strong>Reforzar:</strong> ${res.reforzar.map(D.esc).join(', ')}</p>` : ''}
                <h6 class="cuad-seccion"><i class="bi bi-x-circle me-2" aria-hidden="true"></i>Falló (${falladas.length})</h6>
                ${falladas.length ? '' : '<p class="text-muted small">Respondió todo bien.</p>'}
                ${d.preguntas.map((p, i) => `${i === falladas.length ? `<h6 class="cuad-seccion"><i class="bi bi-check2-circle me-2" aria-hidden="true"></i>Acertó (${d.preguntas.length - falladas.length})</h6>` : ''}
                  <div class="exr-pregunta ${p.acierto ? 'is-acierto' : 'is-falla'}">
                    <div class="exr-cabecera"><span class="badge ${p.acierto ? 'bg-success' : 'bg-danger'}">${p.acierto ? 'Correcta' : (p.respondida ? 'Incorrecta' : 'Sin responder')}</span><small class="text-muted">Pregunta ${p.n} · ${D.esc(p.tema)}</small></div>
                    <p class="exr-texto">${D.esc(p.texto)}</p>${foto(p.imagen, 'exr-imagen')}
                    <ul class="exr-opciones">${p.opciones.map((o) => opcion(p, o)).join('')}</ul>
                  </div>`).join('')}`;
        } catch (e) { $('exTecCuerpo').innerHTML = `<p class="text-danger small mb-0">${D.esc(e.message)}</p>`; }
    };
    $('exdTabla').addEventListener('click', async (ev) => {
        const ver = ev.target.closest('[data-ver-examen]');
        if (ver) return verExamen(ver.dataset.verExamen);
        const b = ev.target.closest('[data-borrar-examen]'); if (!b) return;
        if (!confirm('¿Borrar este examen? Se borran la nota y las respuestas del técnico. No se puede deshacer.')) return;
        b.disabled = true;
        try {
            const datos = new FormData(); datos.append('id', b.dataset.borrarExamen);
            const r = await (await fetch('api/examenes.php?action=resultado_borrar', { method: 'POST', body: datos, credentials: 'same-origin',
                headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' } })).json();
            if (r.status !== 'success') throw new Error(r.message || 'No se pudo borrar.');
            window.showToast?.(r.message, 'success');
            $('exdFiltros').dispatchEvent(new Event('submit', { cancelable: true })); // vuelve a calcular totales y tabla
        } catch (e) { window.showToast?.(e.message, 'error'); b.disabled = false; }
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
