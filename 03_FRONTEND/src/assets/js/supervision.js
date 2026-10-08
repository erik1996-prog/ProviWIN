/* assets/js/supervision.js - Lógica del Formulario Operativo en 4 Pasos */

let currentStep = 1;

document.addEventListener('DOMContentLoaded', () => {
    initWizard();
    initUbigeo();
    initConditionalFields();
    initIntegratedObservation();
    initPhotoPreviews();
    initGpsCapture();
});

// Inicializar Captura GPS del Celular
function initGpsCapture() {
    const btnGps = document.getElementById('btn-reg-gps');
    if (!btnGps) return;

    btnGps.addEventListener('click', () => {
        if (navigator.geolocation) {
            btnGps.disabled = true;
            btnGps.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Obteniendo GPS...';
            navigator.geolocation.getCurrentPosition((pos) => {
                const lat = pos.coords.latitude.toFixed(6);
                const lng = pos.coords.longitude.toFixed(6);
                const inputLat = document.getElementById('latitud');
                const inputLng = document.getElementById('longitud');
                if (inputLat) inputLat.value = lat;
                if (inputLng) inputLng.value = lng;

                btnGps.disabled = false;
                btnGps.innerHTML = '<i class="bi bi-check-circle me-1"></i> GPS Capturado';
                showToast(`Coordenadas capturadas: ${lat}, ${lng}`, 'success');
            }, (err) => {
                btnGps.disabled = false;
                btnGps.innerHTML = '<i class="bi bi-crosshair me-1"></i> Reintentar GPS Celular';
                showToast('No se pudo obtener el GPS automático. Ingrese Latitud y Longitud manualmente.', 'warning');
            }, { enableHighAccuracy: true, timeout: 10000 });
        } else {
            showToast('Navegador no soporta GPS. Ingrese coordenadas manualmente.', 'warning');
        }
    });
}

// Inicializar Previsualización en Tiempo Real de Fotos
function initPhotoPreviews() {
    document.querySelectorAll('.input-foto-preview').forEach(input => {
        input.addEventListener('change', () => {
            const previewId = input.dataset.preview;
            const container = document.getElementById(previewId);
            if (!container) return;
            const img = container.querySelector('img');

            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                reader.onload = (e) => {
                    if (img) img.src = e.target.result;
                    container.classList.remove('d-none');
                };
                reader.readAsDataURL(file);
            } else {
                if (img) img.src = '';
                container.classList.add('d-none');
            }
        });
    });
}

// Helper para determinar el siguiente paso según la Clasificación
function getNextStep(fromStep) {
    const clasif = document.getElementById('clasificacion_supervision')?.value || 'Incidencia';
    const tieneCliente = document.getElementById('tiene_datos_cliente')?.value || '1';
    const requiereCliente = true; // El paso siempre solicita únicamente el código de pedido.
    const requiereTecnicoVehiculo = (clasif !== 'Visita partner');

    let next = fromStep + 1;

    if (next === 2 && !requiereCliente) {
        next = 3; // Saltar Paso 2 (Cliente)
    }

    if (next === 3 && !requiereTecnicoVehiculo) {
        next = 4; // Saltar Paso 3 (Técnico/Vehículo)
    }

    return Math.min(next, 4);
}

// Helper para determinar el paso anterior según la Clasificación
function getPrevStep(fromStep) {
    const clasif = document.getElementById('clasificacion_supervision')?.value || 'Incidencia';
    const tieneCliente = document.getElementById('tiene_datos_cliente')?.value || '1';
    const requiereCliente = true;
    const requiereTecnicoVehiculo = (clasif !== 'Visita partner');

    let prev = fromStep - 1;

    if (prev === 3 && !requiereTecnicoVehiculo) {
        prev = 2; // Retroceder saltando Paso 3
    }

    if (prev === 2 && !requiereCliente) {
        prev = 1; // Retroceder saltando Paso 2
    }

    return Math.max(prev, 1);
}

// Inicializar Navegación del Wizard 4 Pasos
function initWizard() {
    const btnNext = document.getElementById('btn-modal-next-step') || document.getElementById('btn-next-step');
    const btnPrev = document.getElementById('btn-modal-prev-step') || document.getElementById('btn-prev-step');

    if (btnNext) {
        btnNext.addEventListener('click', () => {
            if (validateStep(currentStep)) {
                currentStep = getNextStep(currentStep);
                showStep(currentStep);
            }
        });
    }

    if (btnPrev) {
        btnPrev.addEventListener('click', () => {
            if (currentStep > 1) {
                currentStep = getPrevStep(currentStep);
                showStep(currentStep);
            }
        });
    }
}

// Mostrar el paso actual
function showStep(step) {
    document.querySelectorAll('.wizard-content').forEach(el => el.classList.add('d-none'));
    const target = document.getElementById(`step-content-${step}`);
    if (target) target.classList.remove('d-none');

    // Actualizar indicador visual en modal y vista
    for (let i = 1; i <= 4; i++) {
        const elNorm = document.getElementById(`step-indicator-${i}`);
        const elModal = document.getElementById(`modal-step-indicator-${i}`);
        [elNorm, elModal].forEach(el => {
            if (!el) return;
            if (i === step) {
                el.className = 'wizard-step active';
            } else if (i < step) {
                el.className = 'wizard-step completed';
            } else {
                el.className = 'wizard-step';
            }
        });
    }

    const btnNext = document.getElementById('btn-modal-next-step') || document.getElementById('btn-next-step');
    const btnPrev = document.getElementById('btn-modal-prev-step') || document.getElementById('btn-prev-step');

    if (btnPrev) btnPrev.disabled = (step === 1);
    if (btnNext) btnNext.disabled = (step === 4);
}

// Validar datos de cada paso antes de avanzar
function validateStep(step) {
    const clasif = document.getElementById('clasificacion_supervision')?.value || 'Incidencia';
    const tieneCliente = document.getElementById('tiene_datos_cliente')?.value || '1';
    const requiereCliente = true;

    if (step === 1) {
        const dep = document.getElementById('departamento').value;
        const prov = document.getElementById('provincia').value;
        const dist = document.getElementById('distrito').value;
        if (!dep || !prov || !dist) {
            showToast('Seleccione el Departamento, Provincia y Distrito de la supervisión.', 'warning');
            return false;
        }
    } else if (step === 2) {
        if (requiereCliente) {
            const ticket = document.getElementById('ticket_codigo').value.trim();
            if (!ticket) {
                showToast('Ingrese el Código de Pedido.', 'warning');
                return false;
            }
        }
    } else if (step === 3) {
        if (clasif !== 'Visita partner') {
            const radCaliente = document.getElementById('rad-caliente') || document.getElementById('tipo_inicial_caliente');
            const isCaliente = (clasif === 'Checklist') || (radCaliente ? radCaliente.checked : true);
            const partner = document.getElementById('partner_id').value;
            const uPlaca = document.getElementById('unidad_placa').value.trim();

            if (!partner || !uPlaca) {
                showToast('Seleccione la contrata/partner y la placa del vehículo.', 'warning');
                return false;
            }

            if (isCaliente) {
                const t1Nom = document.getElementById('tecnico1_nombre').value.trim();
                const t1Dni = document.getElementById('tecnico1_dni').value.trim();
                if (!t1Nom || !t1Dni) {
                    showToast('Los datos del Técnico 1 (Nombre y DNI) son obligatorios.', 'warning');
                    return false;
                }
            }
        }
    } else if (step === 4) {
        const chkObs = document.getElementById('chk_incluir_observacion');
        if (chkObs && chkObs.checked) {
            const grav = document.getElementById('obs_gravedad').value;
            const catId = document.getElementById('obs_categoria_id').value;
            const subId = document.getElementById('obs_subopcion_id').value;
            const det = document.getElementById('obs_detalle').value.trim();
            if (!grav || !catId || !subId || !det) {
                showToast('Complete la Gravedad, Motivo, Submotivo y Sustento de la sanción.', 'warning');
                return false;
            }
        }
    }
    return true;
}

// Ubigeo Dinámico en Cascada
async function initUbigeo() {
    const cboDep = document.getElementById('departamento');
    const cboProv = document.getElementById('provincia');
    const cboDist = document.getElementById('distrito');

    if (!cboDep) return;

    try {
        const res = await fetch('api/ubigeo.php?action=departamentos');
        const data = await res.json();
        if (data.status === 'success') {
            cboDep.innerHTML = '<option value="">-- Seleccione Departamento --</option>';
            data.data.forEach(dep => {
                cboDep.innerHTML += `<option value="${dep}">${dep}</option>`;
            });
        }
    } catch (e) {
        console.error('Error ubigeo dep:', e);
    }

    cboDep.addEventListener('change', async () => {
        cboProv.innerHTML = '<option value="">-- Seleccione Provincia --</option>';
        cboDist.innerHTML = '<option value="">-- Seleccione Distrito --</option>';
        if (!cboDep.value) return;

        const res = await fetch(`api/ubigeo.php?action=provincias&departamento=${encodeURIComponent(cboDep.value)}`);
        const data = await res.json();
        if (data.status === 'success') {
            data.data.forEach(prov => {
                cboProv.innerHTML += `<option value="${prov}">${prov}</option>`;
            });
        }
    });

    cboProv.addEventListener('change', async () => {
        cboDist.innerHTML = '<option value="">-- Seleccione Distrito --</option>';
        if (!cboProv.value) return;

        const res = await fetch(`api/ubigeo.php?action=distritos&departamento=${encodeURIComponent(cboDep.value)}&provincia=${encodeURIComponent(cboProv.value)}`);
        const data = await res.json();
        if (data.status === 'success') {
            data.data.forEach(dist => {
                cboDist.innerHTML += `<option value="${dist}">${dist}</option>`;
            });
        }
    });
}

// Lógica de Menús Condicionales (Clasificación, Caliente/Frío y Áreas)
function initConditionalFields() {
    const cboClasif = document.getElementById('clasificacion_supervision');
    const cboTieneCliente = document.getElementById('tiene_datos_cliente');
    const boxTieneCliente = document.getElementById('box-tiene-datos-cliente');

    const radCaliente = document.getElementById('rad-caliente') || document.getElementById('tipo_inicial_caliente');
    const radFrio = document.getElementById('rad-frio') || document.getElementById('tipo_inicial_frio');

    const secTecnicos = document.getElementById('sec-tecnicos-cuadrilla');
    const secVehiculo = document.getElementById('sec-datos-vehiculo');
    const secEstadoCaliente = document.getElementById('sec-estado-servicio-caliente');
    const cboEstadoServ = document.getElementById('estado_supervision');
    const boxMotivoCancel = document.getElementById('box_motivo_cancelacion_reg');
    const cboMotivoCancel = document.getElementById('tipificacion_cancelacion');

    if (cboEstadoServ && boxMotivoCancel) {
        cboEstadoServ.addEventListener('change', () => {
            if (cboEstadoServ.value === 'Cancelada') {
                boxMotivoCancel.classList.remove('d-none');
                if (cboMotivoCancel) cboMotivoCancel.required = (cboEstadoServ.value === 'Cancelada');
            } else {
                boxMotivoCancel.classList.add('d-none');
                if (cboMotivoCancel) {
                    cboMotivoCancel.required = false;
                    cboMotivoCancel.value = '';
                }
            }
        });
    }

    function updateAllFieldVisibilities() {
        const clasif = cboClasif ? cboClasif.value : 'Incidencia';
        const tieneClienteVal = cboTieneCliente ? cboTieneCliente.value : '1';

        // 1. Selector Casos Atípicos (¿Cuenta con datos del cliente?)
        if (clasif === 'Casos atípicos') {
            if (boxTieneCliente) boxTieneCliente.classList.remove('d-none');
        } else {
            if (boxTieneCliente) boxTieneCliente.classList.add('d-none');
        }

        // 2. Regla "Checklist": Siempre En Caliente (Personal Técnico Presente)
        if (clasif === 'Checklist') {
            if (radCaliente) {
                radCaliente.checked = true;
                radCaliente.disabled = true;
            }
            if (radFrio) radFrio.disabled = true;
        } else {
            if (radCaliente) radCaliente.disabled = false;
            if (radFrio) radFrio.disabled = false;
        }

        const isCaliente = radCaliente ? radCaliente.checked : true;

        // 3. Reglas de Visibilidad Técnico & Vehículo
        if (clasif === 'Visita partner') {
            if (secTecnicos) secTecnicos.classList.add('d-none');
            if (secVehiculo) secVehiculo.classList.add('d-none');
            setTecnicoRequired(false);
            setVehiculoRequired(false);
        } else if (!isCaliente && clasif !== 'Checklist') {
            if (secTecnicos) {
                secTecnicos.classList.add('d-none');
            }
            if (secVehiculo) {
                secVehiculo.classList.add('d-none');
            }
            setTecnicoRequired(false);
            setVehiculoRequired(false);
        } else {
            // En Caliente / Checklist / Conjunta PEXT / etc.
            if (secTecnicos) {
                secTecnicos.classList.remove('d-none', 'opacity-50');
            }
            if (secVehiculo) secVehiculo.classList.remove('d-none');
            setTecnicoRequired(true);
            setVehiculoRequired(true);
        }

        // 4. Regla Datos de Cliente
        const requiereCliente = true;
        setClienteRequired(requiereCliente);
    }

    function setTecnicoRequired(req) {
        const t1Nom = document.getElementById('tecnico1_nombre');
        const t1Dni = document.getElementById('tecnico1_dni');
        if (t1Nom) t1Nom.required = req;
        if (t1Dni) t1Dni.required = req;
    }

    function setVehiculoRequired(req) {
        const uPlaca = document.getElementById('unidad_placa');
        if (uPlaca) uPlaca.required = req;
    }

    function setClienteRequired(req) {
        const cNom = document.getElementById('cliente_nombre');
        const cDni = document.getElementById('cliente_dni');
        const tCode = document.getElementById('ticket_codigo');
        if (cNom) cNom.required = false;
        if (cDni) cDni.required = false;
        if (tCode) tCode.required = req;
    }

    if (cboClasif) cboClasif.addEventListener('change', updateAllFieldVisibilities);
    if (cboTieneCliente) cboTieneCliente.addEventListener('change', updateAllFieldVisibilities);
    if (radCaliente) radCaliente.addEventListener('change', updateAllFieldVisibilities);
    if (radFrio) radFrio.addEventListener('change', updateAllFieldVisibilities);

    updateAllFieldVisibilities();

    // 2. Área de Servicio y Submenús Condicionales
    const cboArea = document.getElementById('area_servicio');
    const containerSubtipo = document.getElementById('container-subtipo-atencion');
    const cboSubtipo = document.getElementById('sub_tipo_atencion');
    const secCancelacion = document.getElementById('sec-cancelacion-especial');

    var LISTA_TRABAJOS_POSTVENTA = [
        'CABLEADO 02 MESH - POSTVENTA',
        'CABLEADO 02 MESH + ENTREGA Y CONFIG 1 MESH - POSTVENTA',
        'CABLEADO 03 MESH - POSTVENTA',
        'CABLEADO MESH - POSTVENTA',
        'CABLEADO MESH + 1 MESH + CAMBIO DE ONT',
        'CABLEADO MESH + 1 WIN BOX',
        'CABLEADO MESH + 2 WIN BOX',
        'CABLEADO MESH + ENTREGA Y CONFIG 1 MESH-POSTVENTA',
        'CABLEADO MESH + ENTREGA Y CONFIG 2 MESH - POSTVENTA',
        'CAMBIO DE ONT - MEJORA TECNOLÓGICA - POSTVENTA',
        'CAMBIO DE ONT - ONT ROBADA',
        'CAMBIO DE ONT + 1 WIN BOX',
        'CAMBIO ONT - POST VENTA',
        'CONJUNTA FINALIZADA + RECABLEADOS',
        'ENTREGA + CONFIG 1 APARATO TELEFONICO',
        'ENTREGA + CONFIG 1 MESH',
        'ENTREGA + CONFIG 1 MESH + 1 WIN BOX',
        'ENTREGA + CONFIG 1 WIN BOX',
        'ENTREGA + CONFIG 2 MESH',
        'ENTREGA + CONFIG 2 WIN BOX',
        'INSTALADO',
        'INSTALADO - CABLE CON CONECTOR - PENDIENTES EVIDENCIAS',
        'INSTALADO - PENDIENTE FONO WIN',
        'INSTALADO - PENDIENTE MESH',
        'INSTALADO - PENDIENTES EVIDENCIAS',
        'MIGRA GAMER REALIZADO - POSTVENTA',
        'PATCH CORD - POST VENTA',
        'RECABLEADO + 1 MESH',
        'RECABLEADO + CABLEADO MESH - POSTVENTA',
        'RECABLEADO + CABLEADO MESH + 1 MESH',
        'RECABLEADO + CAMBIO DE ONT + CABLEADO MESH + 1 MESH',
        'RECABLEADO + CAMBIO DE ONT POST VENTA',
        'RECABLEADO + WIN BOX',
        'REUBICACION CON RESERVA',
        'REUBICACION SIN RESERVA',
        'REUBICACION SIN RESERVA / CON RESERVA + CABLEADO MESH - POSTVENTA',
        'REUBICACION SIN RESERVA / CON RESERVA + CAMBIO DE ONT POST VENTA',
        'TRASLADO',
        'TRASLADO + 1 MESH',
        'TRASLADO + 1 WIN BOX',
        'TRASLADO + CABLEADO MESH + 01 MESH + 1 APARATO TELEFONICO',
        'TRASLADO + CABLEADO MESH + 1 MESH',
        'TRASLADO + CAMBIO DE ONT + 1 MESH',
        'TRASLADO + CAMBIO DE ONT + CABLEADO MESH + 1 MESH',
        'TRASLADO + CAMBIO ONT'
    ];

    var LISTA_CIERRE_TECNICO = [
        'ACONDICIONAMIENTO DE DROP',
        'ADAPTADOR ROSETA',
        'ASISTENCIA DGO',
        'ASISTENCIA WIN TV',
        'CAMBIO DE CABLE PATCH CORD',
        'CAMBIO DE CONECTOR CTO/NAP',
        'CAMBIO DE CONECTOR EN ROSETA',
        'CAMBIO DE ONT - CARGADOR MALOGRADO',
        'CAMBIO DE ONT - MEJORA TECNOLÓGICA',
        'CAMBIO DE ONT - NO SINCRONIZA / NO EMITE SEÑAL',
        'CAMBIO DE ONT - ONT INOPERATIVA',
        'CAMBIO DE ONT - PUERTOS AVERIADOS',
        'CAMBIO DE PHONOWIN',
        'CAMBIO DE WINBOX',
        'CAMBIO MESH - PUERTOS AVERIADOS',
        'CAMBIO MESH - CARGADOR MALOGRADO',
        'CAMBIO MESH - MESH INOPERATIVO',
        'CAMBIO MESH - NO SINCRONIZA / NO EMITE SEÑAL',
        'CARGADOR DE ONT',
        'CONFIGURACION MESH',
        'CONFIGURACION ONT',
        'CONFIGURACION PHONOWIN',
        'CONFIGURACIÓN WINBOX',
        'CONJUNTA PEXT AVERÌA',
        'PRUEBA DE SERVICIO',
        'PRUEBA DE SERVICIO - CTO CON POTENCIA DEGRADADA',
        'PRUEBA DE SERVICIO - CTO CON PUERTOS DEGRADADOS',
        'PRUEBA DE SERVICIO - CTO O NAP CON PROBLEMAS DE POTENCIA EN PUERTOS',
        'PRUEBA DE SERVICIO - CTO O NAP ROBADA',
        'PRUEBA DE SERVICIO - CTO SIN POTENCIA',
        'PRUEBA DE SERVICIO - NAP CON POTENCIA DEGRADADA',
        'PRUEBA DE SERVICIO - NAP SIN POTENCIA',
        'PRUEBA DE SERVICIO - VALIDACION CLIENTE',
        'RECABLEADO - AVERIADO POR CLIENTE',
        'RECABLEADO - AVERIADO POR TERCEROS',
        'RECABLEADO - CAMBIO DE POSTE',
        'RECABLEADO - CLIENTE MIGRA A OTRA CTO',
        'RECABLEADO - DESCONEXIÓN DEL CLIENTE',
        'RECABLEADO - NO CUMPLE POLÍTICAS DE INSTALACIÓN',
        'RECABLEADO - POST VENTA',
        'RECABLEADO CORTE MUNICIPAL',
        'RECABLEADO POR CORTE CONCESIONARIA',
        'RECABLEADO POR MANTENIMIENTO',
        'SPLITTER',
        'TRABAJO EN CONJUNTO VT / PEXT'
    ];

    const opcionesSubtipo = {
        'Instalación': [],
        'Postventa': LISTA_TRABAJOS_POSTVENTA,
        'Avería': LISTA_CIERRE_TECNICO,
        'Reiterada': LISTA_CIERRE_TECNICO,
        'Garantía': LISTA_CIERRE_TECNICO,
        'Cancelación': ['Facilidades Técnicas Negativas', 'Cliente Rechaza Servicio', 'Dirección Inaccesible', 'Saturación de NAP']
    };


    if (cboArea && cboSubtipo) {
        cboArea.addEventListener('change', () => {
            const area = cboArea.value;
            const lista = opcionesSubtipo[area] || [];

            if (lista.length > 0) {
                containerSubtipo.classList.remove('d-none');
                cboSubtipo.innerHTML = '<option value="">-- Seleccione Detalle de Atención --</option>';
                lista.forEach(item => {
                    cboSubtipo.innerHTML += `<option value="${item}">${item}</option>`;
                });
            } else {
                containerSubtipo.classList.add('d-none');
                cboSubtipo.innerHTML = '';
            }

            if (area === 'Cancelación') {
                if (secCancelacion) secCancelacion.classList.remove('d-none');
            } else {
                if (secCancelacion) secCancelacion.classList.add('d-none');
            }
        });
    }
}

// Cargar Catálogo e Inicializar Toggle de Observación Integrada
let catalogSubopcionesSupervision = [];
let catalogCategoriasSupervision = [];

async function initIntegratedObservation() {
    const chkObs = document.getElementById('chk_incluir_observacion');
    const secObs = document.getElementById('sec-registro-observacion-integrada');
    const cboGrav = document.getElementById('obs_gravedad');
    const cboCat = document.getElementById('obs_categoria_id');
    const cboSub = document.getElementById('obs_subopcion_id');

    if (!cboCat || !cboSub) return;

    if (chkObs && secObs) {
        chkObs.addEventListener('change', () => {
            if (chkObs.checked) {
                secObs.classList.remove('d-none');
            } else {
                secObs.classList.add('d-none');
            }
        });
    }

    try {
        const res = await fetch('api/observaciones.php?action=catalogo');
        const data = await res.json();

        if (data.status === 'success') {
            catalogSubopcionesSupervision = data.subopciones;
            catalogCategoriasSupervision = data.categorias;
        }
    } catch (e) {
        console.error('Error al cargar catálogo de observaciones:', e);
    }

    function renderCategorias() {
        const currentCatVal = cboCat.value;
        const gravFilter = (cboGrav.value || '').trim().toLowerCase();
        cboCat.innerHTML = '<option value="">-- Seleccione Motivo (Categoría) * --</option>';
        let cats = [...catalogCategoriasSupervision];
        if (gravFilter) {
            const catIds = new Set(
                catalogSubopcionesSupervision
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

        let subs = [...catalogSubopcionesSupervision];
        if (selectedCat > 0) {
            subs = subs.filter(s => parseInt(s.categoria_id, 10) === selectedCat);
        }
        if (selectedGrav) {
            subs = subs.filter(s => s.gravedad && s.gravedad.toString().trim().toLowerCase() === selectedGrav);
        }

        subs.forEach(sub => {
            const isSel = (sub.id == currentSubVal) ? 'selected' : '';
            cboSub.innerHTML += `<option value="${sub.id}" ${isSel}>${sub.descripcion_subopcion}</option>`;
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
        if (!subId) return;

        const subObj = catalogSubopcionesSupervision.find(s => parseInt(s.id, 10) === subId);
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
        }
    });
}

// Enviar Formulario de Supervisión (con soporte de Multipart / Fotos)
async function submitSupervisionForm(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const estado = formData.get('estado_supervision') || 'Finalizada';

    if (estado !== 'Iniciada' && !validateStep(4)) return;

    const btnSubmit = document.getElementById('btn-submit-supervision');
    const btnSaveIniciada = document.getElementById('btn-save-iniciada');
    if (btnSubmit) btnSubmit.disabled = true;
    if (btnSaveIniciada) btnSaveIniciada.disabled = true;

    try {
        const res = await fetch('api/supervisiones.php?action=create', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.status === 'success') {
            showToast(`Supervisión ${data.codigo} (${data.estado_supervision}) registrada correctamente.`, 'success');
            setTimeout(() => {
                window.location.href = `index.php?page=supervision_lista&highlight=${data.supervision_id}`;
            }, 1000);
        } else {
            showToast(data.message, 'error');
            if (btnSubmit) btnSubmit.disabled = false;
            if (btnSaveIniciada) btnSaveIniciada.disabled = false;
        }
    } catch (err) {
        showToast('Error de servidor al guardar supervisión.', 'error');
        if (btnSubmit) btnSubmit.disabled = false;
        if (btnSaveIniciada) btnSaveIniciada.disabled = false;
    }
}
