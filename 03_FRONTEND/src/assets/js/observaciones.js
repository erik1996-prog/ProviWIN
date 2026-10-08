/* assets/js/observaciones.js - Lógica de Observaciones, Fotos y SLA de Partner */

document.addEventListener('DOMContentLoaded', () => {
    initObservacionesModal();
});

let catalogSubopciones = [];
let catalogCategorias = [];

async function observationFetchJson(resource, options = {}) {
    if (window.winFetchJson) return window.winFetchJson(resource, options);
    const response = await fetch(resource, options);
    const data = await response.json();
    if (!response.ok || data.status === 'error') throw new Error(data.message || 'No se pudo completar la operación.');
    return data;
}

function observationButtonLoading(button, loading, label = 'Guardando...') {
    if (window.winSetButtonLoading) window.winSetButtonLoading(button, loading, label);
    else if (button) button.disabled = loading;
}

function reloadObservations() {
    window.setTimeout(() => window.winReload ? window.winReload() : location.reload(), 450);
}

// Inicializar Catálogo de Categorías y Subopciones
async function initObservacionesModal() {
    const cboGrav = document.getElementById('obs_gravedad');
    const cboCat = document.getElementById('obs_categoria_id');
    const cboSub = document.getElementById('obs_subopcion_id');

    if (!cboCat || !cboSub) return;

    try {
        const res = await fetch('api/observaciones.php?action=catalogo');
        const data = await res.json();

        if (data.status === 'success') {
            catalogSubopciones = data.subopciones;
            catalogCategorias = data.categorias;
        }
    } catch (e) {
        console.error('Error catalogo obs:', e);
    }

    function renderCategorias() {
        const currentCatVal = cboCat.value;
        const gravFilter = (cboGrav.value || '').trim().toLowerCase();
        cboCat.innerHTML = '<option value="">-- Seleccione Motivo (Categoría) * --</option>';
        let cats = [...catalogCategorias];
        if (gravFilter) {
            const catIds = new Set(
                catalogSubopciones
                    .filter(s => s.gravedad && s.gravedad.toString().trim().toLowerCase() === gravFilter)
                    .map(s => parseInt(s.categoria_id, 10))
            );
            cats = cats.filter(c => catIds.has(parseInt(c.id, 10)));
        }
        cats.forEach(cat => {
            const isSel = (cat.id == currentCatVal) ? 'selected' : '';
            cboCat.innerHTML += `<option value="${cat.id}" ${isSel}>${cat.nombre_categoria}</option>`;
        });
    }

    function renderSubmotivos() {
        const selectedCat = parseInt(cboCat.value, 10) || 0;
        const selectedGrav = (cboGrav.value || '').trim().toLowerCase();
        const currentSubVal = cboSub.value;

        cboSub.innerHTML = '<option value="">-- Seleccione Submotivo (Tipificación) * --</option>';

        let subs = [...catalogSubopciones];
        if (selectedCat > 0) {
            subs = subs.filter(s => parseInt(s.categoria_id, 10) === selectedCat);
        }
        if (selectedGrav) {
            subs = subs.filter(s => s.gravedad && s.gravedad.toString().trim().toLowerCase() === selectedGrav);
        }

        subs.forEach(sub => {
            const isSel = (sub.id == currentSubVal) ? 'selected' : '';
            const tagPen = (parseFloat(sub.penalidad_monto) > 0) ? ` (S/ ${parseFloat(sub.penalidad_monto).toFixed(2)})` : '';
            cboSub.innerHTML += `<option value="${sub.id}" ${isSel}>${sub.descripcion_subopcion}${tagPen}</option>`;
        });
    }

    renderCategorias();
    renderSubmotivos();

    cboGrav.addEventListener('change', () => {
        renderCategorias();
        renderSubmotivos();
    });

    cboCat.addEventListener('change', () => {
        renderSubmotivos();
    });

    cboSub.addEventListener('change', () => {
        const subId = parseInt(cboSub.value, 10) || 0;
        const infoPenBox = document.getElementById('info_penalidad_subopcion');
        
        if (!subId) {
            if (infoPenBox) infoPenBox.classList.add('d-none');
            return;
        }

        const subObj = catalogSubopciones.find(s => parseInt(s.id, 10) === subId);
        if (subObj) {
            if (subObj.categoria_id && cboCat.value != subObj.categoria_id) {
                cboCat.value = subObj.categoria_id;
                renderSubmotivos();
                cboSub.value = subId;
            }
            if (subObj.gravedad) {
                const offGrav = subObj.gravedad.toString().trim();
                for (let i = 0; i < cboGrav.options.length; i++) {
                    if (cboGrav.options[i].value.toLowerCase() === offGrav.toLowerCase()) {
                        cboGrav.selectedIndex = i;
                        break;
                    }
                }
            }

            if (infoPenBox) {
                const monto = parseFloat(subObj.penalidad_monto || 0);
                const accion = subObj.accion_adicional || '';
                if (monto > 0 || accion) {
                    infoPenBox.classList.remove('d-none');
                    infoPenBox.innerHTML = `
                        <div class="alert alert-danger py-2 mb-2 d-flex align-items-center justify-content-between" style="font-size: 0.88rem;">
                            <div>
                                <i class="bi bi-shield-slash-fill me-1"></i> <strong>Sanción Oficial WIN:</strong> 
                                ${accion ? `<span class="badge bg-dark text-warning ms-1">${accion}</span>` : ''}
                            </div>
                            <div class="fw-bold text-danger fs-6">S/ ${monto.toFixed(2)}</div>
                        </div>
                    `;
                } else {
                    infoPenBox.classList.add('d-none');
                }
            }
        }
    });

    // Formulario guardar observación
    const formObs = document.getElementById('form-agregar-observacion');
    if (formObs) {
        formObs.addEventListener('submit', submitNuevaObservacion);
    }

    // Formulario responder partner
    const formResp = document.getElementById('form-responder-partner');
    if (formResp) {
        formResp.addEventListener('submit', submitRespuestaPartner);
    }

    // Formulario evaluar supervisor
    const formEval = document.getElementById('form-evaluar-supervisor');
    if (formEval) {
        formEval.addEventListener('submit', submitEvaluacionSupervisor);
    }
}

// Guardar Nueva Observación con hasta 3 fotos
async function submitNuevaObservacion(e) {
    e.preventDefault();
    const form = e.target;
    if (!form.reportValidity()) return;
    const formData = new FormData(form);

    const btnSubmit = form.querySelector('button[type="submit"]');
    if (btnSubmit?.dataset.winActionRunning === '1') return;
    if (btnSubmit) btnSubmit.dataset.winActionRunning = '1';
    observationButtonLoading(btnSubmit, true, 'Registrando...');

    try {
        const data = await observationFetchJson('api/observaciones.php?action=create', {
            method: 'POST',
            body: formData
        });

        if (data.status === 'success') {
            showToast(data.message, 'success');
            const modalEl = document.getElementById('modalAgregarObservacion');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            form.reset();
            reloadObservations();
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast(err.message || 'Error al registrar observación.', 'error');
    } finally {
        if (btnSubmit) delete btnSubmit.dataset.winActionRunning;
        observationButtonLoading(btnSubmit, false);
    }
}

// Responder por parte del Partner
async function submitRespuestaPartner(e) {
    e.preventDefault();
    const form = e.target;
    const obsId = document.getElementById('resp_observacion_id').value;
    const respText = document.getElementById('resp_texto_partner').value.trim();
    const submit = e.submitter || form.querySelector('button[type="submit"]');
    if (!form.reportValidity() || submit?.dataset.winActionRunning === '1') return;
    if (submit) submit.dataset.winActionRunning = '1';
    observationButtonLoading(submit, true, 'Enviando...');

    try {
        const data = await observationFetchJson('api/observaciones.php?action=responder_partner', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ observacion_id: obsId, respuesta_partner: respText })
        });

        if (data.status === 'success') {
            showToast(data.message, 'success');
            reloadObservations();
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast(err.message || 'Error al enviar respuesta.', 'error');
    } finally {
        if (submit) delete submit.dataset.winActionRunning;
        observationButtonLoading(submit, false);
    }
}

// Evaluar Apelación por parte del Supervisor
async function submitEvaluacionSupervisor(e) {
    e.preventDefault();
    const form = e.target;
    const obsId = document.getElementById('eval_observacion_id').value;
    const selectedDecision = document.querySelector('input[name="eval_decision"]:checked');
    const submit = e.submitter || form.querySelector('button[type="submit"]');
    if (!selectedDecision) {
        showToast('Seleccione si aprueba o rechaza la apelación.', 'warning');
        document.querySelector('input[name="eval_decision"]')?.focus();
        return;
    }
    if (!form.reportValidity() || submit?.dataset.winActionRunning === '1') return;
    const decision = selectedDecision.value;
    const sustento = document.getElementById('eval_sustento').value.trim();
    if (submit) submit.dataset.winActionRunning = '1';
    observationButtonLoading(submit, true, 'Guardando...');

    try {
        const data = await observationFetchJson('api/observaciones.php?action=evaluar_supervisor', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                observacion_id: obsId,
                aprobar_apelacion: (decision === 'aprobar'),
                sustentacion_evaluacion: sustento
            })
        });

        if (data.status === 'success') {
            showToast(data.message, 'success');
            reloadObservations();
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast(err.message || 'Error al registrar evaluación.', 'error');
    } finally {
        if (submit) delete submit.dataset.winActionRunning;
        observationButtonLoading(submit, false);
    }
}

function intval(val) {
    return parseInt(val, 10) || 0;
}
