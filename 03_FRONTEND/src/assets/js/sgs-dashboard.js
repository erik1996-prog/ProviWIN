// 03_FRONTEND/src/assets/js/sgs-dashboard.js - Piezas comunes de los dashboards (2026-10-20), para no repetir código:
// gráficos sin librerías (barras horizontales con su texto y columnas), ayuda flotante al pasar el mouse o enfocar,
// lectura de filtros y tabla por páginas que filtra el servidor (50 por página). Lo usan examenes.js (Exámenes) y
// dashboard_pendientes.js (Pendientes y rescates). Estilos: sgs-theme.css sección 56 (.dsh-*).
window.SgsDashboard = (() => {
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const aFecha = (v) => new Date(String(v).replace(' ', 'T'));
    const fecha = (v) => v ? aFecha(v).toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—';
    const hora = (v) => v ? aFecha(v).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }) : '';
    const vacio = (texto = 'Todavía no hay datos con estos filtros.') => `<p class="text-muted small mb-0 dsh-vacio"><i class="bi bi-inbox me-1" aria-hidden="true"></i>${esc(texto)}</p>`;

    /** Barras horizontales de una sola serie. opc: valor(x) 0-100, texto(x) HTML, ayuda(x), vacio. */
    const barras = (lista, opc) => !lista.length ? vacio(opc.vacio) : lista.map((x) => `<div class="dsh-barra" tabindex="0" data-tip="${esc(opc.ayuda(x))}">
        <span class="dsh-barra-nombre" title="${esc(x.nombre)}">${esc(x.nombre)}</span>
        <span class="dsh-barra-pista"><span class="dsh-barra-relleno" style="width:${Math.max(0, Math.min(100, opc.valor(x)))}%"></span></span>
        <span class="dsh-barra-valor">${opc.texto(x)}</span></div>`).join('');

    /** Columnas de una sola serie (cantidad). opc: etiqueta(x), ayuda(x), vacio. */
    const columnas = (lista, opc) => {
        const max = Math.max(1, ...lista.map((x) => x.cantidad));
        if (!lista.some((x) => x.cantidad)) return vacio(opc.vacio);
        return `<div class="dsh-col-area">${lista.map((x) => `<div class="dsh-col" tabindex="0" data-tip="${esc(opc.ayuda(x))}">
            <span class="dsh-col-valor">${x.cantidad || ''}</span><span class="dsh-col-barra" style="height:${(x.cantidad / max) * 100}%"></span>
            <span class="dsh-col-nombre">${esc(opc.etiqueta(x))}</span></div>`).join('')}</div>`;
    };

    /** Ayuda flotante para todo [data-tip] dentro de raiz. */
    const ayuda = (raiz, tip) => {
        const mostrar = (el) => {
            tip.textContent = el.dataset.tip; tip.hidden = false;
            const r = el.getBoundingClientRect();
            tip.style.left = `${Math.min(window.innerWidth - tip.offsetWidth - 8, Math.max(8, r.left + r.width / 2 - tip.offsetWidth / 2))}px`;
            tip.style.top = `${Math.max(8, r.top - tip.offsetHeight - 8)}px`;
        };
        raiz.addEventListener('mouseover', (ev) => { const el = ev.target.closest('[data-tip]'); if (el) mostrar(el); });
        raiz.addEventListener('mouseout', (ev) => { if (ev.target.closest('[data-tip]')) tip.hidden = true; });
        raiz.addEventListener('focusin', (ev) => { const el = ev.target.closest('[data-tip]'); if (el) mostrar(el); });
        raiz.addEventListener('focusout', () => { tip.hidden = true; });
        window.addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });
    };

    const parametros = (form) => new URLSearchParams([...new FormData(form)].filter(([, v]) => v !== ''));

    /** Pide datos al servidor; si no responde JSON (p. ej. falta un archivo en el hosting) da un aviso claro. */
    const leer = async (url) => {
        const res = await fetch(url);
        const r = await res.json().catch(() => null);
        if (!r) throw new Error(res.status === 404 ? 'No se encontró el servicio de datos en el servidor (falta subir un archivo). Avise al administrador.'
            : `El servidor respondió con un error (${res.status}). Inténtelo otra vez.`);
        return r;
    };

    /**
     * Tabla por páginas: el servidor filtra (estado y búsqueda) y entrega 50 por página.
     * opc: url (sin parámetros), form (filtros), cuerpo (tbody), paginas (contenedor), estados (grupo de botones data-estado),
     *      buscar (input), columnas (n), fila(f, datos) → <tr>, unidad ('técnicos'…).
     */
    const tabla = (opc) => {
        let pagina = 1, estado = '', q = '', pedido = 0;
        const cargar = async () => {
            const numero = ++pedido; // si llegan dos respuestas, solo vale la última
            const p = parametros(opc.form); if (estado) p.set('estado', estado); if (q) p.set('q', q); p.set('pagina', pagina);
            try {
                const r = await leer(`${opc.url}&${p}`);
                if (numero !== pedido) return;
                if (r.status !== 'success') throw new Error(r.message || 'No se pudo cargar la tabla.');
                const d = r.data;
                pagina = d.pagina;
                opc.cuerpo.innerHTML = d.filas.length ? d.filas.map((f) => opc.fila(f, d)).join('')
                    : `<tr><td colspan="${opc.columnas}" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>${estado || q ? 'Ningún registro con este filtro.' : 'Todavía no hay datos con estos filtros.'}</td></tr>`;
                opc.paginas.innerHTML = d.paginas <= 1 ? '' : `<span class="text-muted small">Página ${d.pagina} de ${d.paginas} · ${d.total} ${opc.unidad}</span>
                    <div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-pagina="${d.pagina - 1}" ${d.pagina <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i> Anterior</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-pagina="${d.pagina + 1}" ${d.pagina >= d.paginas ? 'disabled' : ''}>Siguiente <i class="bi bi-chevron-right"></i></button></div>`;
            } catch (e) {
                opc.cuerpo.innerHTML = `<tr><td colspan="${opc.columnas}" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`;
            }
        };
        opc.paginas.addEventListener('click', (ev) => { const b = ev.target.closest('[data-pagina]'); if (b) { pagina = Number(b.dataset.pagina); cargar(); } });
        opc.estados.addEventListener('click', (ev) => {
            const b = ev.target.closest('[data-estado]'); if (!b) return;
            estado = b.dataset.estado; pagina = 1;
            opc.estados.querySelectorAll('[data-estado]').forEach((x) => x.classList.toggle('active', x === b));
            cargar();
        });
        let t;
        opc.buscar.addEventListener('input', (ev) => { clearTimeout(t); t = setTimeout(() => { q = ev.target.value.trim(); pagina = 1; cargar(); }, 350); });
        return { cargar: () => { pagina = 1; return cargar(); } };
    };

    /**
     * Arma un dashboard: al cambiar los filtros recarga los totales (pintar) y la tabla; actualiza el enlace del Excel.
     * opc: raiz, form, url (de los totales), excel (enlace), pintar(datos), tabla (de tabla()), tip.
     */
    const iniciar = (opc) => {
        ayuda(opc.raiz, opc.tip);
        const recargar = async () => {
            const p = parametros(opc.form);
            if (opc.excel) opc.excel.href = `${opc.excel.dataset.base}&${p}`;
            opc.tabla.cargar();
            opc.raiz.classList.add('dsh-cargando');
            try {
                const r = await leer(`${opc.url}&${p}`);
                if (r.status !== 'success') throw new Error(r.message || 'No se pudo cargar el dashboard.');
                opc.pintar(r.data);
            } catch (e) { window.showToast?.(e.message, 'error'); }
            finally { opc.raiz.classList.remove('dsh-cargando'); }
        };
        opc.form.addEventListener('change', recargar);
        opc.form.addEventListener('submit', (ev) => { ev.preventDefault(); recargar(); });
        opc.form.addEventListener('reset', () => setTimeout(recargar, 0));
        recargar();
    };

    /** Escribe los indicadores: {clave: texto} en los elementos [data-kpi="clave"]. */
    const kpis = (raiz, valores) => Object.entries(valores).forEach(([k, v]) => { const el = raiz.querySelector(`[data-kpi="${k}"]`); if (el) el.textContent = v; });
    const cuentas = (raiz, valores) => raiz.querySelectorAll('[data-cuenta]').forEach((el) => { el.textContent = valores[el.dataset.cuenta] || 0; });

    return { esc, fecha, hora, vacio, barras, columnas, parametros, tabla, iniciar, kpis, cuentas };
})();
