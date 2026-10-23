// 03_FRONTEND/src/assets/js/lluvia.js - "Lluvia en su zona" en Mi turno (2026-10-20, supervisor directo). API: api/lluvias.php.
// Una tarjeta por zona con la nube del clima (se toca): sin lluvia → "Está lloviendo"; lloviendo → "Ya paró la lluvia".
// La lluvia no cambia su actividad (puede llover hasta supervisando): el sistema anota solo qué hacía al avisar y al cerrar.
// Con lluvia la nube flota y caen gotas; sin lluvia asoma el sol (solo CSS).
// Se actualiza junto con el estado del turno (asistencia.js avisa con 'sgs:turno-cargado'); no consulta por su cuenta.
// 2026-10-23: "Está lloviendo" pide foto y comentario (#modalLluvia) para el grupo de Teams. La foto se reduce aquí a 400 px
// y se baja la calidad hasta que pese ≤ 19 KB (Teams acepta mensajes de ~28 KB); no se guarda en el sistema.
(() => {
    const caja = document.getElementById('lluviaZonas');
    if (!caja) return;
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const hora = (v) => new Date(String(v).replace(' ', 'T')).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false });
    // Nube del clima (dibujo de nube de Bootstrap Icons "cloud-fill"). bloqueo: por qué aún no se puede (vacío = se puede)
    const nube = (accion, zona, lloviendo, texto, bloqueo) => `<button type="button" class="sgs-clima${lloviendo ? ' is-lloviendo' : ''}" data-accion="${accion}" data-zona="${esc(zona)}"`
        + ` title="${esc(bloqueo || texto)}" aria-label="${esc(bloqueo || texto)}"${bloqueo ? ' disabled' : ''}><i class="bi bi-sun-fill sgs-clima-sol" aria-hidden="true"></i>`
        + '<svg class="sgs-clima-nube" viewBox="0 0 16 14" aria-hidden="true"><path d="M4.406 3.342A5.53 5.53 0 0 1 8 2c2.69 0 4.923 2 5.166 4.579C14.758 6.804 16 8.137 16 9.773 16 11.569 14.502 13 12.687 13H3.781C1.708 13 0 11.366 0 9.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383z"/></svg>'
        + `<span class="sgs-clima-gotas" aria-hidden="true">${'<i></i>'.repeat(7)}</span></button>`;
    const boton = (accion, zona, clase, icono, texto, bloqueo) =>
        `<button type="button" class="btn btn-sm ${clase}" data-accion="${accion}" data-zona="${esc(zona)}"${bloqueo ? ` disabled title="${esc(bloqueo)}"` : ''}><i class="bi ${icono} me-1"></i>${texto}</button>`;

    const pintar = ({ zonas, abiertas, en_turno: enTurno }) => {
        const bloqueo = enTurno ? '' : 'Inicie su turno para avisar la lluvia';
        caja.innerHTML = zonas.length ? zonas.map((z) => {
            const l = abiertas[z];
            return `<div class="sgs-lluvia-zona${l ? ' is-lloviendo' : ''}">
                <div class="sgs-lluvia-zona-info">${l ? nube('paro', z, true, 'Toque la nube cuando pare la lluvia', '') : nube('avisar', z, false, 'Toque la nube si está lloviendo', bloqueo)}
                  <div><strong>${esc(z)}</strong><small>${l ? `Lloviendo desde las ${hora(l.inicio)} · avisó ${esc(l.reportado_por)}` : 'Sin lluvia.'}</small></div></div>
                <div class="sgs-lluvia-zona-acciones">${l ? boton('paro', z, 'btn-lluvia', 'bi-sun', 'Ya paró la lluvia', '')
                    : boton('avisar', z, 'btn-outline-lluvia', 'bi-cloud-rain-heavy', 'Está lloviendo', bloqueo)}</div></div>`;
        }).join('') : '<p class="text-muted small mb-0">No tiene una zona asignada. Pida al administrador que se la asigne.</p>';
    };
    const pedir = async (url, opciones) => {
        const r = await (await fetch(url, opciones)).json().catch(() => null);
        if (!r) throw new Error('No se pudo consultar la lluvia. Inténtelo otra vez.');
        if (r.status !== 'success') throw new Error(r.message);
        return r;
    };
    const cargar = () => pedir('api/lluvias.php?action=estado').then((r) => pintar(r.data))
        .catch((e) => { caja.innerHTML = `<p class="text-danger small mb-0">${esc(e.message)}</p>`; });

    const enviar = async (accion, datos, b) => {
        b.disabled = true;
        try {
            const r = await pedir(`api/lluvias.php?action=${accion}`, { method: 'POST', body: new URLSearchParams(datos) });
            window.showToast?.(r.message, 'success');
            pintar(r.data);
            return true;
        } catch (e) { window.showToast?.(e.message, 'error'); return false; } finally { b.disabled = false; }
    };

    // Foto reducida: 400 px de ancho como máximo, calidad desde 0.45 hasta 0.2; si no entra, se achica a 320 px
    const reducir = async (archivo) => {
        const img = await createImageBitmap(archivo);
        for (const ancho of [400, 320]) {
            const e = Math.min(1, ancho / img.width), lienzo = document.createElement('canvas');
            lienzo.width = Math.round(img.width * e); lienzo.height = Math.round(img.height * e);
            lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
            for (const calidad of [0.45, 0.35, 0.25, 0.2]) {
                const datos = lienzo.toDataURL('image/jpeg', calidad);
                if (datos.length * 0.75 <= 19000) return datos;
            }
        }
        throw new Error('La foto es muy pesada. Tome otra.');
    };
    const modal = document.getElementById('modalLluvia');
    const foto = document.getElementById('lluviaFoto'), previa = document.getElementById('lluviaFotoPrevia');
    const comentario = document.getElementById('lluviaComentario'), botonEnviar = document.getElementById('lluviaEnviar');
    let zonaAviso = '', fotoLista = '';
    foto.addEventListener('change', async () => {
        fotoLista = ''; previa.hidden = true;
        if (!foto.files[0]) return;
        try { fotoLista = await reducir(foto.files[0]); previa.src = fotoLista; previa.hidden = false; }
        catch (e) { window.showToast?.(e.message || 'No se pudo leer la foto.', 'error'); foto.value = ''; }
    });
    botonEnviar.addEventListener('click', async () => {
        if (!fotoLista) { window.showToast?.('Tome una foto de la lluvia.', 'error'); return; }
        if (!comentario.value.trim()) { window.showToast?.('Escriba un comentario de la lluvia.', 'error'); comentario.focus(); return; }
        const datos = { zona: zonaAviso, comentario: comentario.value.trim(), foto: fotoLista.split(',')[1] };
        if (await enviar('avisar', datos, botonEnviar)) bootstrap.Modal.getOrCreateInstance(modal).hide();
    });

    caja.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-accion]');
        if (!b) return;
        if (b.dataset.accion === 'paro') { enviar('paro', { zona: b.dataset.zona }, b); return; }
        zonaAviso = b.dataset.zona; fotoLista = ''; foto.value = ''; comentario.value = ''; previa.hidden = true;
        document.getElementById('lluviaZonaTitulo').textContent = zonaAviso;
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    document.addEventListener('sgs:turno-cargado', cargar);
})();
