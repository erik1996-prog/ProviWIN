// 03_FRONTEND/src/assets/js/dashboard_lluvias.js - Indicador de lluvias (2026-10-20, pages/dashboard_lluvias.php).
// Usa las piezas comunes de sgs-dashboard.js. Datos: api/dashboard_lluvias.php?action=dashboard (un mes, pocas filas).
// Calendario del mes: los días con lluvia en azul (3 tonos según las horas); al tocar un día la bitácora muestra ese día.
(() => {
    const raiz = document.getElementById('dll');
    if (!raiz || !window.SgsDashboard) return;
    const D = window.SgsDashboard;
    const $ = (id) => document.getElementById(id);
    const dur = (m) => m == null ? '—' : (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`);
    const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;
    const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    let datos = null, diaElegido = '';

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
            <td data-label="Duración" class="text-end">${dur(l.minutos)}</td></tr>`).join('')
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

    $('dllCalendario').addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-dia]');
        if (!b) return;
        diaElegido = diaElegido === b.dataset.dia ? '' : b.dataset.dia;
        pintarBitacora();
    });
    $('dllVerMes').addEventListener('click', () => { diaElegido = ''; pintarBitacora(); });

    D.iniciar({
        raiz, form: $('dllFiltros'), url: 'api/dashboard_lluvias.php?action=dashboard', tip: $('dllTip'),
        pintar: (d) => {
            datos = d; diaElegido = '';
            const k = d.kpis;
            D.kpis(raiz, { dias: k.dias, lluvias: k.lluvias, horas: dur(k.minutos), promedio: dur(k.promedio), zona_mas: k.zona_mas || '—' });
            $('dllContador').textContent = `${plural(k.lluvias, 'lluvia', 'lluvias')} en el mes${k.abiertas ? ` · ${k.abiertas} sigue${k.abiertas === 1 ? '' : 'n'} abierta${k.abiertas === 1 ? '' : 's'}` : ''}`;
            $('dllZonas').innerHTML = D.barras(d.por_zona, { valor: (z) => z.pct, texto: (z) => `${dur(z.minutos)} <small>${plural(z.dias, 'día', 'días')}</small>`,
                ayuda: (z) => `${z.nombre}: ${plural(z.lluvias, 'lluvia', 'lluvias')} en ${plural(z.dias, 'día', 'días')}, ${dur(z.minutos)}`, vacio: 'No hubo lluvias en el mes.' });
            pintarCalendario();
            pintarBitacora();
        },
    });
})();
