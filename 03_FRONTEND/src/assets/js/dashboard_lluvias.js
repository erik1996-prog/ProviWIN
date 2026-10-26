// 03_FRONTEND/src/assets/js/dashboard_lluvias.js - Indicador de lluvias (2026-10-20, pages/dashboard_lluvias.php).
// Usa las piezas comunes de sgs-dashboard.js. Datos: api/dashboard_lluvias.php?action=dashboard (un mes, pocas filas).
// Calendario del mes: los días con lluvia en azul (3 tonos según las horas); al tocar un día la bitácora muestra ese día.
// Matriz mensual (2026-10-23): zonas × días con las horas de lluvia, como la matriz mensual de supervisiones.
// Se actualiza sola cada 30 s mientras la pestaña está a la vista ("En vivo").
// Cada cuadro es opcional: si falta en la página (p. ej. la página vieja en el hosting), el resto carga igual.
(() => {
    const raiz = document.getElementById('dll');
    if (!raiz || !window.SgsDashboard) return;
    const D = window.SgsDashboard;
    const $ = (id) => document.getElementById(id);
    const dur = (m) => m == null ? '—' : (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`);
    const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;
    const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    let datos = null, diaElegido = '';
    const puedeEliminar = raiz.dataset.puedeEliminar === '1';

    const quien = (nombre, comentario) => `${D.esc(nombre || '—')}${comentario ? `<small class="text-muted d-block">${D.esc(comentario)}</small>` : ''}`;
    const pintarBitacora = () => {
        const lista = datos.bitacora.filter((l) => !diaElegido || l.inicio.startsWith(diaElegido));
        $('dllBitacoraTitulo').textContent = diaElegido ? `Lluvias del ${D.fecha(diaElegido)}.` : 'Todas las lluvias del mes.';
        $('dllVerMes').classList.toggle('d-none', !diaElegido);
        $('dllBitacora').innerHTML = lista.length ? lista.map((l) => `<tr>
            <td data-label="Fecha">${D.fecha(l.inicio)}</td><td data-label="Zona" class="fw-bold">${D.esc(l.zona)}</td>
            <td data-label="Inicio (quién avisó)"><div><span class="fw-bold">${D.hora(l.inicio)}</span> · ${quien(l.aviso, l.comentario_inicio)}</div></td>
            <td data-label="Fin (quién cerró)"><div>${l.sigue ? '<span class="badge badge-lluvia"><i class="bi bi-cloud-rain-heavy me-1"></i>Sigue lloviendo</span>'
                : `<span class="fw-bold">${D.hora(l.fin)}</span> · ${quien(l.cerro, l.comentario_fin)}`}</div></td>
            <td data-label="Duración" class="text-end">${dur(l.minutos)}${puedeEliminar ? ` <button type="button" class="sgs-icon-action sgs-icon-action--danger ms-2" title="Eliminar" aria-label="Eliminar la lluvia de ${D.esc(l.zona)} del ${D.fecha(l.inicio)}" data-eliminar-lluvia="${l.id}"><i class="bi bi-trash3"></i></button>` : ''}</td></tr>`).join('')
            : `<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>${diaElegido ? 'Ese día no llovió.' : 'No hubo lluvias en el mes.'}</td></tr>`;
        raiz.querySelectorAll('[data-dia]').forEach((b) => b.classList.toggle('is-elegido', b.dataset.dia === diaElegido));
    };
    // Calendario: semanas de lunes a domingo; tono según las horas de lluvia del día
    const pintarCalendario = () => {
        const [a, m] = datos.mes.split('-').map(Number);
        const primero = new Date(a, m - 1, 1), total = new Date(a, m, 0).getDate();
        const vacios = (primero.getDay() + 6) % 7;
        let html = DIAS.map((d) => `<span class="sgs-cal-cabecera">${d}</span>`).join('') + '<span></span>'.repeat(vacios);
        for (let d = 1; d <= total; d++) {
            const fecha = `${datos.mes}-${String(d).padStart(2, '0')}`, x = datos.dias[fecha];
            const tono = !x ? '' : (x.minutos >= 180 ? ' is-fuerte' : (x.minutos >= 60 ? ' is-media' : ' is-leve'));
            const ayuda = x ? `${D.fecha(fecha)}: ${plural(x.lluvias, 'lluvia', 'lluvias')}, ${dur(x.minutos)} · ${x.zonas.join(', ')}` : `${D.fecha(fecha)}: sin lluvia`;
            html += x ? `<button type="button" class="sgs-cal-dia${tono}" data-dia="${fecha}" data-tip="${D.esc(ayuda)}" aria-label="${D.esc(ayuda)}">
                <span class="sgs-cal-num">${d}</span><i class="bi bi-cloud-rain-heavy-fill" aria-hidden="true"></i><small>${dur(x.minutos)}</small></button>`
                : `<span class="sgs-cal-dia" aria-label="${D.esc(ayuda)}"><span class="sgs-cal-num">${d}</span></span>`;
        }
        $('dllCalendario').innerHTML = html;
    };

    // Zonas que se muestran: la elegida en el filtro o todas, en orden alfabético
    const zonasFiltro = () => ($('dllZona').value ? [$('dllZona').value] : [...$('dllZona').options].map((o) => o.value).filter(Boolean).sort((x, y) => x.localeCompare(y, 'es')));

    // Línea de tiempo de lluvias (2026-10-23): una fila por zona y solo los bloques de lluvia del día elegido (como la de supervisores).
    const hoyIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
    const seg = (v) => new Date(String(v).replace(' ', 'T')).getTime() / 1000;
    const hh = (t) => new Date(t * 1000).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false });
    const elegirDiaTl = () => {
        const campo = $('dllDiaTl'), [a, m] = datos.mes.split('-').map(Number);
        if (!campo) return;
        campo.min = `${datos.mes}-01`; campo.max = `${datos.mes}-${String(new Date(a, m, 0).getDate()).padStart(2, '0')}`;
        if (campo.value >= campo.min && campo.value <= campo.max) return;
        const conLluvia = Object.keys(datos.dias).sort().pop();
        campo.value = hoyIso().startsWith(datos.mes) ? hoyIso() : (conLluvia || campo.min);
    };
    const pintarTimeline = () => {
        if (!$('dllTimeline') || !$('dllDiaTl')) return;
        const dia = $('dllDiaTl').value, ahora = Date.now() / 1000, esHoy = dia === hoyIso();
        const lluvias = datos.bitacora.filter((l) => l.inicio.startsWith(dia)).map((l) => ({ ...l, ini: seg(l.inicio), fin: seg(l.inicio) + l.minutos * 60 }));
        const base = seg(`${dia} 00:00:00`);
        let ini = base + 6 * 3600, fin = base + 20 * 3600;
        lluvias.forEach((l) => { ini = Math.min(ini, Math.floor(l.ini / 3600) * 3600 - 3600); fin = Math.max(fin, Math.ceil(l.fin / 3600) * 3600 + 3600); });
        if (esHoy) fin = Math.max(fin, Math.ceil(ahora / 3600) * 3600);
        const pos = (t) => Math.min(100, Math.max(0, (t - ini) * 100 / (fin - ini))).toFixed(3);
        let horas = '';
        for (let t = ini; t < fin; t += 3600) horas += `<div class="sgs-gantt-hour" style="left:${pos(t)}%"><span>${hh(t)}</span></div>`;
        const lineas = horas.replace(/<span>[^<]*<\/span>/g, '');
        const linAhora = (etq) => (esHoy && ahora > ini && ahora < fin ? `<div class="sgs-gantt-ahora" style="left:${pos(ahora)}%">${etq ? `<span>${hh(ahora)}</span>` : ''}</div>` : '');
        // Barra proporcional: total de horas de lluvia del día por zona (la de más lluvia = barra completa)
        const minZona = (z) => lluvias.filter((l) => l.zona === z).reduce((t, l) => t + l.minutos, 0);
        const maxMin = Math.max(1, ...zonasFiltro().map(minZona));
        const filas = zonasFiltro().map((z) => {
            const bloques = lluvias.filter((l) => l.zona === z).map((l) => {
                const texto = `${hh(l.ini)}–${l.sigue ? 'sigue' : hh(l.fin)}`;
                const ayuda = `${z}: ${texto} (${dur(l.minutos)}) · Avisó ${l.aviso}${l.comentario_inicio ? ` (${l.comentario_inicio})` : ''}`
                    + (l.sigue ? ' · Sigue lloviendo' : ` · Cerró ${l.cerro || '—'}${l.comentario_fin ? ` (${l.comentario_fin})` : ''}`);
                // Intervalo: la hora de inicio a la izquierda del bloque y la de fin a la derecha ("sigue" si no paró)
                return `<div class="sgs-gantt-blk is-lluvia sgs-lluvia-tl${l.sigue ? ' is-activa' : ''}" tabindex="0" style="left:${pos(l.ini)}%;width:${Math.max(0.6, pos(l.fin) - pos(l.ini))}%" data-tip="${D.esc(ayuda)}" aria-label="${D.esc(ayuda)}"><i class="bi bi-cloud-rain-heavy-fill" aria-hidden="true"></i></div>`
                    + `<span class="sgs-lluvia-etq is-inicio" style="left:${pos(l.ini)}%" aria-hidden="true">${hh(l.ini)}</span>`
                    + `<span class="sgs-lluvia-etq" style="left:${pos(l.fin)}%" aria-hidden="true">${l.sigue ? 'sigue' : hh(l.fin)}</span>`;
            }).join('');
            const min = minZona(z);
            const barra = `<span class="sgs-tl-total" title="${D.esc(`${z}: ${min ? dur(min) : 'sin lluvia'} el ${D.fecha(dia)}`)}"><span class="sgs-tl-total-pista"><i style="width:${Math.round(min * 100 / maxMin)}%"></i></span><small>${min ? hm(min) : '-'}</small></span>`;
            return `<div class="sgs-gantt-row"><div class="sgs-gantt-name sgs-tl-zona"><strong>${D.esc(z)}</strong>${barra}</div><div class="sgs-gantt-track">${lineas}${linAhora(false)}${bloques}</div></div>`;
        }).join('');
        $('dllTimeline').innerHTML = `<div class="sgs-gantt-inner"><div class="sgs-gantt-row sgs-gantt-head"><div class="sgs-gantt-name">Zona</div><div class="sgs-gantt-track">${horas}${linAhora(true)}</div></div>${filas}</div>`
            + (lluvias.length ? '' : `<p class="text-muted small text-center my-3"><i class="bi bi-sun me-1"></i>No llovió el ${D.fecha(dia)} en las zonas mostradas.</p>`);
    };
    $('dllDiaTl')?.addEventListener('change', () => { if (datos) pintarTimeline(); });

    // Matriz mensual (como la de supervisiones): zonas × días, con las horas que llovió (h:mm). Filas = zonas del filtro.
    const hm = (m) => `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;
    const pintarMatriz = () => {
        if (!$('dllMatriz')) return;
        const [a, m] = datos.mes.split('-').map(Number);
        const total = new Date(a, m, 0).getDate();
        const zonas = zonasFiltro();
        const celdas = {};
        datos.bitacora.forEach((l) => { const k = `${l.zona}|${l.inicio.slice(0, 10)}`; celdas[k] = (celdas[k] || 0) + l.minutos; });
        const dias = Array.from({ length: total }, (_, i) => `${datos.mes}-${String(i + 1).padStart(2, '0')}`);
        const nombreDia = (f) => ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][new Date(`${f}T12:00:00`).getDay()];
        const tono = (min) => (min >= 180 ? ' is-fuerte' : (min >= 60 ? ' is-media' : ' is-leve'));
        const filas = zonas.map((z) => {
            let suma = 0;
            const tds = dias.map((f) => {
                const min = celdas[`${z}|${f}`] || 0;
                suma += min;
                return min ? `<td class="text-center sgs-matriz-lluvia${tono(min)}" title="${D.esc(`${z} · ${D.fecha(f)}: ${dur(min)}`)}"><i class="bi bi-cloud-rain-heavy-fill" aria-hidden="true"></i><span>${hm(min)}</span></td>` : '<td class="text-center text-muted">-</td>';
            }).join('');
            return `<tr><td class="sticky-col-name fw-bold">${D.esc(z)}</td>${tds}<td class="sticky-col-total text-center fw-bold">${suma ? hm(suma) : '-'}</td></tr>`;
        }).join('');
        $('dllMatriz').innerHTML = `<thead><tr><th class="sticky-col-name">Zona</th>${dias.map((f) => `<th class="text-center small px-2">${nombreDia(f)} ${f.slice(8)}</th>`).join('')}<th class="sticky-col-total text-center">Total</th></tr></thead>`
            + `<tbody>${filas || `<tr><td class="text-muted py-3" colspan="${total + 2}">Sin zonas para mostrar.</td></tr>`}</tbody>`;
    };
    $('dllCalendario').addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-dia]');
        if (!b) return;
        diaElegido = diaElegido === b.dataset.dia ? '' : b.dataset.dia;
        if (diaElegido && $('dllDiaTl')) { $('dllDiaTl').value = diaElegido; pintarTimeline(); }
        pintarBitacora();
    });
    $('dllVerMes').addEventListener('click', () => { diaElegido = ''; pintarBitacora(); });
    // Eliminar una lluvia (permiso "Eliminar registros de lluvia"): para avisos hechos por error
    $('dllBitacora').addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-eliminar-lluvia]'); if (!b || !confirm('¿Eliminar esta lluvia? No se puede deshacer.')) return;
        b.disabled = true;
        try {
            const r = await (await fetch('api/dashboard_lluvias.php?action=eliminar', { method: 'POST', body: new URLSearchParams({ id: b.dataset.eliminarLluvia }) })).json();
            if (r.status !== 'success') throw new Error(r.message);
            window.showToast?.(r.message, 'success');
            $('dllFiltros').dispatchEvent(new Event('change'));
        } catch (e) { window.showToast?.(e.message || 'No se pudo eliminar.', 'error'); b.disabled = false; }
    });
    // Matriz a pantalla completa (Esc o el mismo botón para salir)
    // Enviar corte a Teams (2026-10-23, solo administrativo): el mismo que sale a las 08:00, para probarlo o reenviarlo
    $('dllEnviarCorte')?.addEventListener('click', async (ev) => {
        const b = ev.currentTarget;
        if (!confirm('¿Enviar ahora al grupo de Teams el corte de lluvias del mes (hasta ayer)?')) return;
        b.disabled = true;
        try {
            const r = await (await fetch('api/dashboard_lluvias.php?action=enviar_corte', { method: 'POST' })).json();
            window.showToast?.(r.message, r.status === 'success' ? 'success' : 'error');
        } catch (e) { window.showToast?.('No se pudo enviar el corte.', 'error'); } finally { b.disabled = false; }
    });
    // Programar envíos del corte (2026-10-23, solo administrativo): horas del día en que sale solo a Teams
    const cortes = async (accion, datos) => {
        const r = await (await fetch(`api/dashboard_lluvias.php?action=${accion}`, datos ? { method: 'POST', body: new URLSearchParams(datos) } : {})).json();
        if (r.status !== 'success') throw new Error(r.message || 'No se pudo guardar.');
        if (r.message) window.showToast?.(r.message, 'success');
        $('mCortesLista').innerHTML = r.data.length ? r.data.map((p) => `<tr>
            <td data-label="Hora" class="fw-bold">${D.esc(p.hora)}</td>
            <td data-label="Contenido">${p.incluye_hoy == 1 ? 'Incluye hoy' : 'Hasta ayer'}</td>
            <td data-label="Último envío">${p.ultimo_envio ? `${D.fecha(p.ultimo_envio)} ${p.ultimo_envio.slice(11, 16)}` : '—'}</td>
            <td data-label="Activo"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-corte-activo="${p.id}" ${p.activo == 1 ? 'checked' : ''} aria-label="Envío de las ${D.esc(p.hora)} activo"></div></td>
            <td class="text-end"><button type="button" class="sgs-icon-action sgs-icon-action--danger" title="Eliminar" aria-label="Eliminar el envío de las ${D.esc(p.hora)}" data-corte-eliminar="${p.id}"><i class="bi bi-trash3"></i></button></td></tr>`).join('')
            : '<tr><td colspan="5" class="text-muted"><i class="bi bi-inbox me-1"></i>No hay envíos programados.</td></tr>';
    };
    const avisarError = (e) => window.showToast?.(e.message || 'No se pudo guardar.', 'error');
    $('dllProgramar')?.addEventListener('click', () => {
        bootstrap.Modal.getOrCreateInstance($('mCortes')).show();
        cortes('programas').catch(avisarError);
    });
    $('mCortesAgregar')?.addEventListener('click', () => cortes('programa_guardar', { hora: $('mCortesHora').value, incluye_hoy: $('mCortesHoy').value }).catch(avisarError));
    $('mCortesLista')?.addEventListener('change', (ev) => {
        const c = ev.target.closest('[data-corte-activo]');
        if (c) cortes('programa_estado', { id: c.dataset.corteActivo, activo: c.checked ? 1 : 0 }).catch((e) => { c.checked = !c.checked; avisarError(e); });
    });
    $('mCortesLista')?.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-corte-eliminar]');
        if (b && confirm('¿Eliminar este envío programado?')) cortes('programa_eliminar', { id: b.dataset.corteEliminar }).catch(avisarError);
    });
    const tarjeta = $('dllMatrizCard'), btnPantalla = $('dllPantalla');
    if (btnPantalla && !tarjeta?.requestFullscreen) btnPantalla.classList.add('d-none');
    btnPantalla?.addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : tarjeta.requestFullscreen()).catch(() => {}));
    document.addEventListener('fullscreenchange', () => {
        if (!btnPantalla) return;
        const dentro = document.fullscreenElement === tarjeta;
        btnPantalla.innerHTML = `<i class="bi ${dentro ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'} me-1"></i>${dentro ? 'Salir de pantalla completa' : 'Pantalla completa'}`;
    });

    // En vivo (2026-10-23, lo ven las jefaturas): cada 30 s y al volver a la pestaña, solo si está a la vista (sistema liviano).
    // Una lluvia abierta va sumando sus horas y, al cerrarse, queda con su duración final en todos los cuadros.
    const actualizar = () => { if (!document.hidden) $('dllFiltros').dispatchEvent(new Event('change')); };
    setInterval(actualizar, 30000);
    document.addEventListener('visibilitychange', actualizar);

    D.iniciar({
        raiz, form: $('dllFiltros'), url: 'api/dashboard_lluvias.php?action=dashboard', tip: $('dllTip'),
        pintar: (d) => {
            if (datos?.mes !== d.mes) diaElegido = ''; // al actualizar solo, se mantiene el día elegido
            if ($('dllEnVivo')) $('dllEnVivo').lastChild.textContent = `En vivo · actualizado a las ${new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false })}`;
            datos = d;
            const k = d.kpis;
            D.kpis(raiz, { dias: k.dias, lluvias: k.lluvias, horas: dur(k.minutos), promedio: dur(k.promedio), zona_mas: k.zona_mas || '—' });
            $('dllContador').textContent = `${plural(k.lluvias, 'lluvia', 'lluvias')} en el mes${k.abiertas ? ` · ${k.abiertas} sigue${k.abiertas === 1 ? '' : 'n'} abierta${k.abiertas === 1 ? '' : 's'}` : ''}`;
            $('dllZonas').innerHTML = D.barras(d.por_zona, { valor: (z) => z.pct, texto: (z) => `${dur(z.minutos)} <small>${plural(z.dias, 'día', 'días')}</small>`,
                ayuda: (z) => `${z.nombre}: ${plural(z.lluvias, 'lluvia', 'lluvias')} en ${plural(z.dias, 'día', 'días')}, ${dur(z.minutos)}`, vacio: 'No hubo lluvias en el mes.' });
            elegirDiaTl();
            pintarTimeline();
            pintarCalendario();
            pintarMatriz();
            pintarBitacora();
        },
    });
})();
