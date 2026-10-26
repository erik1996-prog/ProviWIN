// 03_FRONTEND/src/assets/js/ausencias.js - Vacaciones, descansos médicos y licencias (2026-10-26). API: api/ausencias.php.
// Mi turno (supervisor directo, #ausMias): registra sus ausencias y ve en qué estado están; las pendientes las puede anular.
// Monitor de turnos (administración, gerencia y coordinación, #ausJefes): aprueba o rechaza; anula una aprobada.
// Solo consulta al abrir la pantalla y después de cada acción (sin sondeos).
(() => {
    const $ = (id) => document.getElementById(id);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fecha = (d) => { const [a, m, dia] = String(d).slice(0, 10).split('-'); return `${dia}/${m}/${a}`; };
    const rango = (a) => a.fecha_inicio === a.fecha_fin ? fecha(a.fecha_inicio) : `${fecha(a.fecha_inicio)} al ${fecha(a.fecha_fin)}`;
    const ESTADO = { Pendiente: 'bg-warning', Aprobada: 'bg-success', Rechazada: 'bg-danger', Anulada: 'bg-secondary' };
    const insignia = (a) => `<span class="badge ${ESTADO[a.estado] || 'bg-secondary'}">${esc(a.estado)}</span>`
        + (a.motivo_revision && a.estado !== 'Aprobada' ? `<small class="d-block text-muted">${esc(a.motivo_revision)}</small>` : '');
    const sustento = (a) => Number(a.tiene_sustento)
        ? `<a href="api/ausencias.php?action=sustento&amp;id=${a.id}" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1"></i>Ver</a>` : '—';
    const pedir = async (accion, datos) => {
        const r = await (await fetch(`api/ausencias.php?action=${accion}`, datos ? { method: 'POST', body: datos } : {})).json().catch(() => null);
        if (!r) throw new Error('No se pudo conectar. Inténtelo otra vez.');
        if (r.status !== 'success') throw new Error(r.message || 'No se pudo completar.');
        return r;
    };
    const avisar = (e) => window.showToast?.(e.message, 'error');

    // ---- Supervisor: Mi turno ----
    if ($('ausMias')) {
        const modal = () => bootstrap.Modal.getOrCreateInstance($('modalAusencia')); // Bootstrap carga al final de la página
        const form = $('formAusencia'), error = $('ausError');
        const cargar = () => pedir('mias').then((r) => {
            $('ausHoy').classList.toggle('d-none', !r.hoy);
            $('ausHoy').textContent = r.hoy ? `Hoy tiene una ausencia aprobada: ${r.hoy}. No puede iniciar turno.` : '';
            $('ausMiasLista').innerHTML = r.data.length ? r.data.map((a) => `<tr>
                <td data-label="Tipo" class="fw-bold">${esc(a.tipo)}</td>
                <td data-label="Fechas">${rango(a)}</td>
                <td data-label="Días">${a.dias}</td>
                <td data-label="Estado">${insignia(a)}</td>
                <td class="text-end">${a.estado === 'Pendiente' ? `<button type="button" class="sgs-icon-action sgs-icon-action--danger" title="Anular" aria-label="Anular la solicitud de ${esc(a.tipo)}" data-aus-anular="${a.id}"><i class="bi bi-x-lg"></i></button>` : ''}</td></tr>`).join('')
                : '<tr><td colspan="5" class="text-muted"><i class="bi bi-inbox me-1"></i>No tiene solicitudes registradas.</td></tr>';
        }).catch(avisar);
        // Descanso médico: el sustento es obligatorio
        const marcarSustento = () => {
            const obligatorio = $('ausTipo').value === 'Descanso médico';
            $('ausSustentoNota').textContent = obligatorio ? '(obligatorio)' : '(opcional)';
            $('ausSustento').required = obligatorio;
        };
        $('ausTipo').addEventListener('change', marcarSustento);
        $('ausInicio').addEventListener('change', () => { if (!$('ausFin').value || $('ausFin').value < $('ausInicio').value) $('ausFin').value = $('ausInicio').value; });
        $('ausNueva').addEventListener('click', () => { form.reset(); error.classList.add('d-none'); marcarSustento(); modal().show(); });
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const falta = !$('ausTipo').value ? 'Elija el tipo de ausencia.' : (!$('ausInicio').value || !$('ausFin').value ? 'Elija las fechas.'
                : ($('ausSustento').required && !$('ausSustento').files.length ? 'Adjunte el certificado médico (foto o PDF).' : ''));
            if (falta) { error.textContent = falta; error.classList.remove('d-none'); return; }
            $('ausEnviar').disabled = true;
            try {
                const r = await pedir('registrar', new FormData(form));
                modal().hide();
                window.showToast?.(r.message, 'success');
                cargar();
            } catch (e) { error.textContent = e.message; error.classList.remove('d-none'); } finally { $('ausEnviar').disabled = false; }
        });
        $('ausMiasLista').addEventListener('click', async (ev) => {
            const b = ev.target.closest('[data-aus-anular]');
            if (!b || !confirm('¿Anular esta solicitud?')) return;
            try { window.showToast?.((await pedir('anular', new URLSearchParams({ id: b.dataset.ausAnular }))).message, 'success'); cargar(); } catch (e) { avisar(e); }
        });
        cargar();
    }

    // ---- Jefes: Monitor de turnos ----
    if ($('ausJefes')) {
        let estado = 'Pendiente', pendiente = null;
        const modal = () => bootstrap.Modal.getOrCreateInstance($('modalAusMotivo'));
        const cargar = () => pedir(`lista&estado=${encodeURIComponent(estado)}`).then((r) => {
            $('ausPendientes').textContent = r.pendientes;
            $('ausJefesLista').innerHTML = r.data.length ? r.data.map((a) => `<tr>
                <td data-label="Supervisor" class="sgs-col-persona"><div class="sgs-persona-texto"><span class="sgs-persona-nombre">${esc(a.supervisor)}</span><small>DNI ${esc(a.dni)}${a.comentario ? ` · ${esc(a.comentario)}` : ''}</small></div></td>
                <td data-label="Tipo">${esc(a.tipo)}</td>
                <td data-label="Fechas">${rango(a)}</td>
                <td data-label="Días">${a.dias}</td>
                <td data-label="Sustento">${sustento(a)}</td>
                <td data-label="Estado">${insignia(a)}${a.revisado_por && a.estado !== 'Pendiente' ? `<small class="d-block text-muted">${esc(a.revisado_por)}</small>` : ''}</td>
                <td class="text-end text-nowrap">${a.estado === 'Pendiente'
                    ? `<button type="button" class="btn btn-sm btn-outline-secondary me-1" data-aus-decidir="Aprobada" data-id="${a.id}"><i class="bi bi-check-lg me-1"></i>Aprobar</button><button type="button" class="btn btn-sm btn-outline-danger" data-aus-decidir="Rechazada" data-id="${a.id}" data-detalle="${esc(`${a.supervisor}: ${a.tipo}, ${rango(a)}`)}">Rechazar</button>`
                    : (a.estado === 'Aprobada' ? `<button type="button" class="btn btn-sm btn-outline-danger" data-aus-decidir="Anulada" data-id="${a.id}" data-detalle="${esc(`${a.supervisor}: ${a.tipo}, ${rango(a)}`)}">Anular</button>` : '')}</td></tr>`).join('')
                : `<tr><td colspan="7" class="text-muted"><i class="bi bi-inbox me-1"></i>${estado === 'Pendiente' ? 'No hay solicitudes por aprobar.' : 'No hay solicitudes.'}</td></tr>`;
        }).catch(avisar);
        const decidir = async (id, decision, motivo = '') => {
            const r = await pedir('decidir', new URLSearchParams({ id, decision, motivo }));
            window.showToast?.(r.message, 'success');
            cargar();
        };
        document.querySelectorAll('[data-aus-estado]').forEach((b) => b.addEventListener('click', () => {
            document.querySelectorAll('[data-aus-estado]').forEach((x) => x.classList.toggle('active', x === b));
            estado = b.dataset.ausEstado;
            cargar();
        }));
        $('ausJefesLista').addEventListener('click', (ev) => {
            const b = ev.target.closest('[data-aus-decidir]');
            if (!b) return;
            if (b.dataset.ausDecidir === 'Aprobada') { b.disabled = true; decidir(b.dataset.id, 'Aprobada').catch((e) => { avisar(e); b.disabled = false; }); return; }
            // Rechazar o anular: piden el motivo
            pendiente = { id: b.dataset.id, decision: b.dataset.ausDecidir };
            const anular = pendiente.decision === 'Anulada';
            $('modalAusMotivoTitulo').textContent = anular ? 'Anular ausencia aprobada' : 'Rechazar solicitud';
            $('ausMotivoConfirmar').textContent = anular ? 'Anular' : 'Rechazar';
            $('ausMotivoDetalle').textContent = b.dataset.detalle || '';
            $('ausMotivo').value = '';
            modal().show();
        });
        $('ausMotivoConfirmar').addEventListener('click', async () => {
            if (!pendiente) return;
            if (!$('ausMotivo').value.trim()) { window.showToast?.('Escriba el motivo.', 'error'); $('ausMotivo').focus(); return; }
            try { await decidir(pendiente.id, pendiente.decision, $('ausMotivo').value.trim()); modal().hide(); pendiente = null; } catch (e) { avisar(e); }
        });
        cargar();
    }
})();
