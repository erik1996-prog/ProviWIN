// 03_FRONTEND/src/assets/js/supervision_lista.js - Pantalla de Supervisiones: listado, nueva supervisión y detalle.
// 2026-10-16: antes iba escrito dentro de la página (259 KB que se descargaban en cada visita); en un archivo aparte
// el navegador lo guarda. Los datos del servidor llegan en window.SGS_SUPERVISION (supervision_lista.php).
(function(){
  let records = [];
  let currentFilter = 'todos';
  let currentZoneFilter = '';
  let currentCodeFilter = '';
  let currentSupervisorFilter = '';
  let currentDateFromFilter = '';
  let currentDateToFilter = '';
  let currentPage = 1;
  let listHasMore = false;
  let listTotal = 0;
  let listCounts = {todos:0,iniciada:0,finalizada:0,pendiente:0};
  let activeSupervisionMeta = null;
  let recordsRequest = null;
  let pendingMapRecords = [];
  const initialHighlightId = Math.max(0, Number(new URLSearchParams(location.search).get('highlight') || 0));
  let abrirAlCargar = new URLSearchParams(location.search).get('abrir') === '1';
  let catalogoCategorias = [];
  let catalogoSubopciones = [];
  let listaTecnicos = [];
  let tecnicosRequest = null;
  let catalogoRequest = null;

  const tbody = document.getElementById('tbody');
  const toggleNewBtn = document.getElementById('toggleNewBtn');
  const cancelNewBtn = document.getElementById('cancelNewBtn');
  const formInicio = document.getElementById('form-inicio-supervision');
  const newSupervisionId = document.getElementById('new_supervision_id');
  const newWorkflowStatus = document.getElementById('new_workflow_status');
  const newFormTitle = document.getElementById('newFormTitle');
  const startBtn = document.getElementById('startBtn');
  const startBtnLabel = document.getElementById('startBtnLabel');
  const cancelSupBtn = document.getElementById('cancelSupBtn');
  let autoguardadoNuevo = null;   // guardado automático del formulario nuevo / en proceso
  let autoguardadoEdicion = null; // guardado automático de una pendiente ya iniciada
  let newWorkflowState = 'draft';
  let newHasSavedEvidence = false;
  let newFormDirty = false;
  const folioPreview = document.getElementById('folioPreview');
  const headerMeta = document.getElementById('headerMeta');
  const toast = document.getElementById('toast');
  const viewList = document.getElementById('view-list');
  const viewNew = document.getElementById('view-new');
  const viewDetail = document.getElementById('view-detail');
  const detailContent = document.getElementById('detailContent');
  const backBtn = document.getElementById('backBtn');
  const filterTabs = document.getElementById('filterTabs');
  const newChkObs = document.getElementById('new_chk_obs');
  const newBoxObs = document.getElementById('new_box_observacion');
  const newObsGrav = document.getElementById('new_obs_gravedad');
  const newObsCat = document.getElementById('new_obs_cat');
  const newObsSub = document.getElementById('new_obs_sub');
  const newTipoSupModalidad = document.getElementById('new_tipo_sup');
  const newSecCuadrilla = document.getElementById('new_sec_cuadrilla');
  const newTipoSupervision = document.getElementById('new_tipo_supervision');
  const newFieldSubtipo = document.getElementById('new_field_subtipo');
  const newSubTipoAtencion = document.getElementById('new_sub_tipo_atencion');
  const newFieldResultadoTecnico = document.getElementById('new_field_resultado_tecnico');
  const newResultadoTecnico = document.getElementById('new_resultado_tecnico');
  const newFieldPartner = document.getElementById('new_field_partner');
  const newPartner = document.getElementById('new_partner');
  const newBoxCancelacion = document.getElementById('new_box_cancelacion');
  const newFieldModalidad = document.getElementById('new_field_modalidad');
  const newFieldObsGeneral = document.getElementById('new_field_obs_general');
  const newSecObservaciones = document.getElementById('new_sec_observaciones');
  const newSecChecklist = document.getElementById('new_sec_checklist');
  const newToggleChecklist = document.getElementById('new_toggle_checklist');
  const newChkChecklist = document.getElementById('new_chk_checklist');
  const newSecCodigoPedido = document.getElementById('new_sec_codigo_pedido');
  const newTicketCodigo = document.getElementById('new_ticket_codigo');
  const newFieldEstadoServicio = document.getElementById('new_field_estado_servicio');
  const newEstadoServicio = document.getElementById('new_estado_servicio');

  // El registro de hallazgos siempre se presenta antes del checklist técnico.
  if (newSecObservaciones && newToggleChecklist?.parentNode) {
    newToggleChecklist.parentNode.insertBefore(newSecObservaciones, newToggleChecklist);
  }
  // El estado final de la orden es la última decisión del formulario.
  if (newSecChecklist && newFieldEstadoServicio) {
    newSecChecklist.insertAdjacentElement('afterend', newFieldEstadoServicio);
    newFieldEstadoServicio.style.marginTop = '16px';
    const motivoCancelacionFinal = document.getElementById('box_motivo_cancelacion_new');
    if (motivoCancelacionFinal) newFieldEstadoServicio.insertAdjacentElement('afterend', motivoCancelacionFinal);
  }

  const newTec1Nom = document.getElementById('new_tec1_nom');
  const newTec1Dni = document.getElementById('new_tec1_dni');
  const newTec2Nom = document.getElementById('new_tec2_nom');
  const newTec2Dni = document.getElementById('new_tec2_dni');
  const modalEditarTecnicoEl = document.getElementById('modalEditarTecnico');
  const formEditarTecnico = document.getElementById('form-editar-tecnico');

  const partnersData = SGS_SUPERVISION.partners;
  const tipificacionesCancelacion = SGS_SUPERVISION.tipificaciones;

  const currentUserRole = SGS_SUPERVISION.rol;
  const currentUserDepartment = SGS_SUPERVISION.departamento; // zona de trabajo
  const currentUserZoneDepartment = SGS_SUPERVISION.departamentoZona; // departamento oficial de esa zona (NORTE CHICO → LIMA)
  const esAdministradorActual = currentUserRole === 'Administrador';
  const currentUserId = SGS_SUPERVISION.usuarioId;
  const esSupervisorWinDirecto = currentUserRole === 'Supervisor' && SGS_SUPERVISION.winDirecto;
  const esSupervisorPartnerActual = SGS_SUPERVISION.supervisorPartner;
  const esJefeOperacionesActual = SGS_SUPERVISION.jefeOperaciones;   // solo consulta
  const canUseFindingsForm = SGS_SUPERVISION.formHallazgos;
  const canUseChecklistForm = SGS_SUPERVISION.formChecklist;
  const puedeAsignarPartner = esAdministradorActual || esSupervisorWinDirecto;

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

  // Solución aplicada o cierre técnico (Avería, Reiterada, Garantía). Única lista: se usa al crear y al editar.
  var LISTA_CIERRE_TECNICO = [
    'ACONDICIONAMIENTO DE DROP',
    'ADAPTADOR ROSETA',
    'ASISTENCIA DGO',
    'ASISTENCIA WIN TV',
    'CANCELADA',
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

  const importCancelModal = document.getElementById('modalImportarCancelaciones');
  document.getElementById('openCancelImportBtn')?.addEventListener('click', () => {
    document.getElementById('resultadoImportacionCancelaciones').innerHTML = '';
    bootstrap.Modal.getOrCreateInstance(importCancelModal).show();
  });
  document.getElementById('formImportarCancelaciones')?.addEventListener('submit', async event => {
    event.preventDefault();
    // El formulario se guarda antes de esperar al servidor: después del await, event.currentTarget ya es null y
    // limpiar el formulario fallaba, mostrando "No se pudo procesar" aunque la carga sí se hizo (2026-10-12).
    const form = event.currentTarget;
    const button = document.getElementById('submitCancelImportBtn');
    const output = document.getElementById('resultadoImportacionCancelaciones');
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Validando filas...';
    output.innerHTML = '<div class="alert alert-light border small">Procesando el archivo y validando técnicos, ubicaciones, partners y tickets.</div>';
    try {
      const response = await fetch('api/cancelaciones_import.php', {method:'POST',body:new FormData(form)});
      // Si el servidor no responde como se espera (error del hosting, límite de tamaño, bloqueo), se muestra el motivo
      // con el código HTTP y el de seguimiento, en lugar de un mensaje genérico (2026-10-12).
      const texto = await response.text();
      let result;
      try { result = JSON.parse(texto); } catch (errorJson) {
        const codigo = (texto.match(/SGS-[A-Z]+-[0-9A-F]{8}/) || [])[0];
        const motivo = response.status === 413 ? 'El archivo es demasiado grande para el servidor.'
          : (response.status === 403 ? 'El servidor bloqueó la subida del archivo.'
          : (response.status >= 500 || codigo ? 'El servidor tuvo un error al procesar el archivo.' : 'El servidor respondió algo inesperado.'));
        output.innerHTML = `<div class="alert alert-danger mb-0"><strong>${escapeHtml(motivo)}</strong><div class="small mt-1">Respuesta del servidor: HTTP ${Number(response.status)}${codigo ? ` · Código de seguimiento: <strong>${escapeHtml(codigo)}</strong>` : ''}. Envía esta información al administrador del sistema.</div></div>`;
        console.error('Respuesta no válida de la carga de cancelaciones', response.status, texto.slice(0, 500));
        return;
      }
      const issues = Array.isArray(result.observadas) ? result.observadas : [];
      const yaSupervisados = Array.isArray(result.ya_supervisados) ? result.ya_supervisados : [];
      const repetidos = Array.isArray(result.repetidos_archivo) ? result.repetidos_archivo : [];
      const sinCuadrilla = Array.isArray(result.cuadrillas_no_registradas) ? result.cuadrillas_no_registradas : [];
      const tablaCarga = (titulo, ayuda, cabeceras, filas) => `<div class="mt-3"><strong>${titulo}</strong>${ayuda ? `<div class="small text-muted">${ayuda}</div>` : ''}<div class="table-responsive sgs-import-tabla mt-2"><table class="table table-sm small mb-0"><thead><tr>${cabeceras.map(c => `<th>${c}</th>`).join('')}</tr></thead><tbody>${filas.join('')}</tbody></table></div></div>`;
      // Ya supervisados: no se ingresaron (2026-10-12)
      const yaHtml = yaSupervisados.length ? tablaCarga(`Códigos no ingresados: ya están en Supervisiones (${yaSupervisados.length})`,
        'Ya existe una supervisión con el mismo código de pedido o ticket. El estado indica si ya se supervisó (Finalizada) o si sigue pendiente.',
        ['Fila', 'Código / ticket', 'Supervisión', 'Estado', 'Fecha'],
        yaSupervisados.map(y => `<tr><td>${Number(y.fila)}</td><td><strong>${escapeHtml(y.ticket)}</strong></td><td>${escapeHtml(y.supervision || '—')}</td><td>${escapeHtml(y.estado || '—')}</td><td>${escapeHtml(y.fecha || '—')}</td></tr>`)) : '';
      const repetidosHtml = repetidos.length ? tablaCarga(`Repetidos en el mismo archivo (${repetidos.length})`, 'Se cargaron una sola vez.',
        ['Fila', 'Código / ticket', 'Ya estaba en la fila'],
        repetidos.map(x => `<tr><td>${Number(x.fila)}</td><td>${escapeHtml(x.ticket)}</td><td>${Number(x.fila_original)}</td></tr>`)) : '';
      // Técnico de la columna CUADRILLA que no está en Cuadrillas (2026-10-16): la pendiente se cargó con el nombre tal cual,
      // pero sus observaciones no se vinculan a ninguna cuadrilla hasta registrarlo (o corregir el nombre en el Excel).
      const cuadrillaHtml = sinCuadrilla.length ? tablaCarga(`Técnicos no registrados (${sinCuadrilla.length})`,
        'Se cargaron con el nombre de la columna CUADRILLA, pero ese técnico líder no está en Cuadrillas: sus observaciones no quedarán vinculadas a una cuadrilla. Regístrelo (Cuadrillas → Cargar técnicos líderes) o corrija el nombre en el Excel.',
        ['Fila', 'Código / ticket', 'Cuadrilla del Excel'],
        sinCuadrilla.map(x => `<tr><td>${Number(x.fila)}</td><td>${escapeHtml(x.ticket || '—')}</td><td>${escapeHtml(x.cuadrilla || '—')}</td></tr>`)) : '';
      const issueHtml = issues.length ? tablaCarga(`Filas con problemas (${issues.length})`, 'No se cargaron; corrija el dato y vuelva a subir solo esas filas.',
        ['Fila', 'Código / ticket', 'Motivo'],
        issues.map(issue => `<tr><td>${Number(issue.fila)}</td><td>${escapeHtml(issue.ticket || '—')}</td><td>${escapeHtml((issue.errores || []).join('; '))}</td></tr>`)) : '';
      output.innerHTML = `<div class="alert ${result.status==='success'?'alert-success':'alert-danger'} mb-0"><strong>${escapeHtml(result.message||'Proceso terminado.')}</strong>${result.que_hacer ? `<div class="mt-2"><strong>Qué hacer:</strong> ${escapeHtml(result.que_hacer)}</div>` : ''}${result.detalle ? `<div class="small text-muted mt-2">Código de seguimiento: ${escapeHtml(result.codigo || '—')} · Detalle técnico: <code>${escapeHtml(result.detalle)}</code></div>` : ''}${result.creadas!==undefined?`<div class="small mt-1">Pendientes creadas: ${Number(result.creadas)} · Ya existían: ${yaSupervisados.length} · Con problemas: ${issues.length}${sinCuadrilla.length ? ` · Técnicos no registrados: ${sinCuadrilla.length}` : ''}</div>`:''}</div>${cuadrillaHtml}${yaHtml}${repetidosHtml}${issueHtml}`;
      if (result.status === 'success') { form.reset(); try { await loadRecords(); } catch (e) { console.error(e); } }
    } catch (error) {
      console.error(error); output.innerHTML = '<div class="alert alert-danger mb-0">No se pudo procesar el archivo. Compruebe que sea un Excel (.xlsx) con los encabezados de la plantilla y vuelva a intentar.</div>';
    } finally { button.disabled=false; button.innerHTML='<i class="bi bi-cloud-arrow-up-fill me-1"></i>Procesar archivo'; }
  });

  function showToast(msg){
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(()=> toast.classList.remove('show'), 3000);
  }

  function todayISO(){
    return new Date().toISOString().slice(0,10);
  }

  function fmtDate(iso){
    if(!iso) return '—';
    const parts = iso.split('T')[0].split('-');
    if(parts.length < 3) return iso;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  }

  function nextFolio(){
    const year = new Date().getFullYear();
    const countThisYear = records.filter(r => r.codigo && r.codigo.includes(`SUP-${year}`)).length;
    const n = String(countThisYear + 1).padStart(3,'0');
    return `SUP-${year}-${n}`;
  }

  function updateFolioPreview(){
    if(folioPreview && !newSupervisionId?.value) folioPreview.textContent = nextFolio(); // con la supervisión creada se ve su código real
  }

  function escapeHtml(str){
    if(!str) return '';
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function activarBuscadorTecnico(select, tipo) {
    if (!select || select.dataset.searchable === '1') return;
    select.dataset.searchable = '1';
    const wrapper = document.createElement('div');
    wrapper.className = 'position-relative sgs-combo-campo';
    const search = document.createElement('input');
    search.type = 'text';
    search.className = 'form-control';
    search.placeholder = tipo === 1 ? 'Escriba para buscar al técnico líder...' : 'Escriba para buscar al técnico de apoyo...';
    search.autocomplete = 'off';
    search.setAttribute('aria-label', search.placeholder);
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-expanded', 'false');
    const results = document.createElement('div');
    results.className = 'list-group position-absolute start-0 end-0 mt-1 shadow d-none';
    results.style.zIndex = '1080';
    results.style.maxHeight = '230px';
    results.style.overflowY = 'auto';
    // Elegido el técnico, el campo queda fijo (no se escribe encima). Para cambiarlo: tocar el campo (2026-10-21: abre la
    // lista sin quitar al actual hasta elegir otro o escribir uno nuevo; antes solo servía la X) o la X (lo quita).
    const limpiar = document.createElement('button');
    limpiar.type = 'button';
    limpiar.className = 'sgs-combo-limpiar d-none';
    limpiar.setAttribute('aria-label', tipo === 1 ? 'Cambiar técnico líder' : 'Cambiar técnico de apoyo');
    limpiar.title = 'Cambiar';
    limpiar.innerHTML = '<i class="bi bi-x-lg"></i>';
    select.before(wrapper);
    wrapper.append(search, limpiar, results);
    select.classList.add('d-none');
    const bloquear = () => {
      const elegido = Boolean(select.value);
      search.readOnly = elegido;
      search.classList.toggle('is-elegido', elegido);
      search.title = elegido && !select.disabled ? 'Toque para cambiar' : '';
      limpiar.classList.toggle('d-none', !elegido || select.disabled);
    };
    search.value = select.value || '';
    bloquear();
    select.addEventListener('change', () => { search.value = select.value || ''; bloquear(); });
    const syncDisabled = () => { search.disabled = select.disabled; if(select.disabled) results.classList.add('d-none'); bloquear(); };
    new MutationObserver(syncDisabled).observe(select, {attributes:true, attributeFilter:['disabled']});
    syncDisabled();

    const closeResults = () => {
      results.classList.add('d-none');
      search.setAttribute('aria-expanded', 'false');
    };
    const chooseTechnician = name => {
      select.value = name;
      search.value = name;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      closeResults();
    };
    const renderResults = (todos = false) => {
      const term = todos ? '' : search.value.trim().toLocaleLowerCase('es');
      const otroTecnico = document.getElementById(select.id.replace(tipo === 1 ? 'tec1' : 'tec2', tipo === 1 ? 'tec2' : 'tec1'))?.value || '';
      const technicians = listaTecnicos.filter(item => {
        const itemType = Number(item.tipo_tecnico || 1);
        return !select.disabled && Array.from(select.options).some(option => option.value === item.nombre_tecnico) && itemType === tipo && item.nombre_tecnico !== otroTecnico && (!term || String(item.nombre_tecnico || '').toLocaleLowerCase('es').includes(term));
      });
      results.innerHTML = '';
      if (tipo === 2 && !term) {
        const withoutT2 = document.createElement('button');
        withoutT2.type = 'button';
        withoutT2.className = 'list-group-item list-group-item-action text-muted';
        withoutT2.textContent = 'Sin técnico de apoyo (T2)';
        withoutT2.addEventListener('click', () => chooseTechnician(''));
        results.append(withoutT2);
      }
      technicians.forEach(item => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'list-group-item list-group-item-action py-2';
        option.textContent = item.nombre_tecnico;
        const partnerForm = document.getElementById(select.id.startsWith('edit_') ? 'edit_partner' : 'new_partner')?.value || '';
        if (tipo === 2 && String(item.partner_id) !== String(partnerForm)) {
          const origen = document.createElement('small');
          origen.className = 'text-muted ms-2';
          origen.textContent = `${item.nombre_partner || 'Sin partner'} · pasará a esta cuadrilla al cerrar`;
          option.append(origen);
        }
        option.addEventListener('click', () => chooseTechnician(item.nombre_tecnico));
        results.append(option);
      });
      // T2 que todavía no está registrado: se registra aquí mismo en el partner de la supervisión
      const exacto = technicians.some(item => String(item.nombre_tecnico).toLocaleLowerCase('es') === term);
      if (tipo === 2 && term.length >= 3 && !exacto) {
        const registrar = document.createElement('button');
        registrar.type = 'button';
        registrar.className = 'list-group-item list-group-item-action py-2 text-primary fw-semibold';
        registrar.innerHTML = '<i class="bi bi-person-plus me-2"></i>';
        registrar.append(`Registrar "${search.value.trim()}" como técnico de apoyo`);
        registrar.addEventListener('click', () => { closeResults(); abrirRegistroApoyo(select, search.value.trim()); });
        results.append(registrar);
      }
      if (!results.children.length) {
        const empty = document.createElement('div');
        empty.className = 'list-group-item text-muted small';
        empty.textContent = tipo === 2 ? 'No hay técnicos de apoyo que coincidan con la búsqueda.' : 'No hay técnicos asociados a este partner que coincidan con la búsqueda.';
        results.append(empty);
      }
      results.classList.remove('d-none');
      search.setAttribute('aria-expanded', 'true');
    };

    search.addEventListener('focus', () => { if (!search.readOnly) renderResults(); });
    // Tocar el campo con un técnico ya elegido: se puede escribir otro nombre y se ve la lista completa
    search.addEventListener('click', () => {
      if (!search.readOnly || select.disabled) return;
      search.readOnly = false;
      search.classList.remove('is-elegido');
      search.select();
      renderResults(true);
    });
    // Si sale sin elegir otro, vuelve a mostrar al que ya estaba
    search.addEventListener('blur', () => setTimeout(() => {
      if (select.value && !wrapper.contains(document.activeElement)) { search.value = select.value; bloquear(); }
    }, 200));
    search.addEventListener('input', () => {
      if (select.value && search.value !== select.value) {
        const escrito = search.value;
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        search.value = escrito; // el change vacía el texto; se conserva lo que se está escribiendo
      }
      renderResults();
    });
    limpiar.addEventListener('click', () => {
      select.value = '';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      search.focus();
      renderResults();
    });
    document.addEventListener('click', event => {
      if (!wrapper.contains(event.target)) closeResults();
    });
  }

  // Registro de un técnico de apoyo (T2) en el partner de la supervisión; al guardarlo queda elegido en el campo.
  let apoyoDestino = null;
  function abrirRegistroApoyo(select, nombre) {
    const prefix = select.id.startsWith('edit_') ? 'edit' : 'new';
    const partner = document.getElementById(prefix + '_partner');
    if (!partner?.value) { showToast('Seleccione primero el partner de la supervisión.'); partner?.focus(); return; }
    apoyoDestino = { select, partnerId: partner.value, prefix };
    document.getElementById('nuevo_apoyo_partner').textContent = partner.selectedOptions[0]?.text || '—';
    document.getElementById('nuevo_apoyo_nombre').value = nombre.toLocaleUpperCase('es');
    document.getElementById('nuevo_apoyo_dni').value = '';
    document.getElementById('nuevo_apoyo_error').classList.add('d-none');
    const modal = document.getElementById('modalNuevoApoyo');
    modal.addEventListener('shown.bs.modal', () => document.getElementById('nuevo_apoyo_dni').focus(), { once: true });
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }
  document.getElementById('form-nuevo-apoyo')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!apoyoDestino) return;
    const error = document.getElementById('nuevo_apoyo_error');
    const boton = document.getElementById('btn-nuevo-apoyo');
    const nombre = document.getElementById('nuevo_apoyo_nombre').value.trim();
    const dni = document.getElementById('nuevo_apoyo_dni').value.trim();
    const mostrarError = (msg) => { error.textContent = msg; error.classList.remove('d-none'); };
    if (nombre.length < 5) return mostrarError('Escriba los nombres y apellidos del técnico.');
    if (!/^\d{8}$/.test(dni)) return mostrarError('El DNI debe tener 8 dígitos.');
    const datos = new FormData();
    datos.append('partner_id', apoyoDestino.partnerId);
    // Zona de la supervisión (2026-10-24): el T2 queda registrado en ese departamento y provincia
    datos.append('departamento', document.getElementById(apoyoDestino.prefix + '_dep')?.value || '');
    datos.append('provincia', document.getElementById(apoyoDestino.prefix + '_prov')?.value || '');
    datos.append('nombre_tecnico', nombre);
    datos.append('dni', dni);
    boton.disabled = true;
    try {
      const res = await fetch('api/tecnicos.php?action=crear_apoyo', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: datos });
      const r = await res.json();
      if (r.status !== 'success') return mostrarError(r.message || 'No se pudo registrar el técnico.');
      listaTecnicos.push(r.data);
      const { select } = apoyoDestino;
      select.add(new Option(r.data.nombre_tecnico, r.data.nombre_tecnico));
      select.value = r.data.nombre_tecnico;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNuevoApoyo')).hide();
      showToast(r.message);
    } catch (e) {
      mostrarError('No se pudo registrar el técnico. Revise la conexión e intente de nuevo.');
    } finally {
      boton.disabled = false;
    }
  });

  function activarBuscadorSelect(select, placeholderText) {
    if (!select || select.dataset.searchableSelect === '1') return;
    select.dataset.searchableSelect = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'position-relative select-search-wrapper';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control';
    search.placeholder = placeholderText || 'Escriba para buscar u opción...';
    search.autocomplete = 'off';

    const results = document.createElement('div');
    results.className = 'list-group position-absolute start-0 end-0 mt-1 shadow d-none';
    results.style.zIndex = '1080';
    results.style.maxHeight = '230px';
    results.style.overflowY = 'auto';

    select.before(wrapper);
    wrapper.append(search, results);
    select.classList.add('d-none');

    // 2026-10-20: mientras se escribe, el texto no se toca. Antes, al escribir la primera letra se vaciaba la opción
    // elegida y ese cambio borraba lo escrito: no filtraba y había que bajar por la lista.
    let escribiendo = false;
    const updateSearchValFromSelect = () => {
      if (escribiendo) return;
      const selectedOpt = select.options[select.selectedIndex];
      search.value = (selectedOpt && selectedOpt.value) ? selectedOpt.text : '';
    };

    updateSearchValFromSelect();

    select.addEventListener('change', updateSearchValFromSelect);

    const syncState = () => {
      search.disabled = select.disabled;
      if (document.activeElement !== search) updateSearchValFromSelect();
      if (select.disabled || select.closest('[style*="display: none"], .d-none')) {
        results.classList.add('d-none');
      }
    };

    new MutationObserver(syncState).observe(select, { attributes: true, attributeFilter: ['disabled', 'style', 'class'], childList: true });
    syncState();

    const closeResults = () => {
      results.classList.add('d-none');
    };

    const chooseOption = (val, text) => {
      select.value = val;
      search.value = text;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      closeResults();
    };

    const renderResults = () => {
      if (select.disabled) return;
      const term = search.value.trim().toLocaleLowerCase('es');
      const validOptions = Array.from(select.options).filter(opt => opt.value !== '');

      const filtered = validOptions.filter(opt => {
        return !term || opt.text.toLocaleLowerCase('es').includes(term) || opt.value.toLocaleLowerCase('es').includes(term);
      });

      results.innerHTML = '';

      const clearBtn = document.createElement('button');
      clearBtn.type = 'button';
      clearBtn.className = 'list-group-item list-group-item-action text-muted py-1 small';
      clearBtn.textContent = '-- Limpiar selección --';
      clearBtn.addEventListener('click', () => chooseOption('', ''));
      results.append(clearBtn);

      filtered.forEach(opt => {
        const itemBtn = document.createElement('button');
        itemBtn.type = 'button';
        itemBtn.className = 'list-group-item list-group-item-action py-2';
        if (opt.value === select.value) itemBtn.classList.add('active');
        itemBtn.textContent = opt.text;
        itemBtn.addEventListener('click', () => chooseOption(opt.value, opt.text));
        results.append(itemBtn);
      });

      if (!filtered.length) {
        const empty = document.createElement('div');
        empty.className = 'list-group-item text-muted small';
        empty.textContent = 'No hay opciones que coincidan.';
        results.append(empty);
      }

      results.classList.remove('d-none');
    };

    search.addEventListener('focus', renderResults);
    search.addEventListener('input', () => {
      if (select.value && search.value !== (select.options[select.selectedIndex]?.text || '')) {
        escribiendo = true; // al cambiar el texto, la opción anterior deja de valer (sin borrar lo escrito)
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        escribiendo = false;
      }
      renderResults();
    });
    search.addEventListener('search', renderResults);
    // Enter elige la primera coincidencia; Escape cierra la lista.
    search.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeResults();
      if (event.key !== 'Enter') return;
      event.preventDefault();
      results.querySelector('.list-group-item-action:not(.text-muted)')?.click();
    });
    // Al salir sin elegir, la caja vuelve a mostrar lo que realmente quedó elegido.
    search.addEventListener('blur', () => setTimeout(() => { if (!wrapper.contains(document.activeElement)) updateSearchValFromSelect(); }, 150));

    document.addEventListener('click', event => {
      if (!wrapper.contains(event.target)) closeResults();
    });
  }

  // Cargar lista de técnicos desde BD
  async function cargarListaTecnicos() {
    if (listaTecnicos.length) return listaTecnicos;
    if (tecnicosRequest) return tecnicosRequest;
    tecnicosRequest = (async () => {
      try {
        const res = await fetch('api/tecnicos.php?action=list');
        const data = await res.json();
        if (data.status === 'success' && Array.isArray(data.data)) {
          listaTecnicos = data.data;
          poblarSelectsTecnicos();
        }
      } catch(e) {
        showToast('No se pudo cargar el catálogo de técnicos.', 'error');
      } finally {
        tecnicosRequest = null;
      }
      return listaTecnicos;
    })();
    return tecnicosRequest;
  }

  // Zona (2026-10-15): el T1 se elige solo entre los técnicos líderes de la zona de la supervisión (su zona se
  // carga con la PROVINCIA en el Excel de Cuadrillas). Los que aún no tienen zona salen en todas, al final y marcados.
  // 2026-10-27: zona = departamento, salvo provincias con zona propia (LIMA / HUAURA → NORTE CHICO); sin provincia
  // elegida en un departamento así, aún no se filtra.
  const normalizarZona = v => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().trim();
  const zonasPorProvincia = Object.fromEntries(Object.entries(SGS_SUPERVISION.zonasPorProvincia || {})
    .map(([d, provs]) => [normalizarZona(d), Object.fromEntries(Object.entries(provs).map(([p, z]) => [normalizarZona(p), normalizarZona(z)]))]));
  const zonaDe = (departamento, provincia) => {
    const d = normalizarZona(departamento), especiales = zonasPorProvincia[d];
    if (!especiales) return d;
    return provincia ? (especiales[normalizarZona(provincia)] || d) : '';
  };
  function lideresDeZona(partnerId, departamento, actual = '', provincia = '') {
    const dep = zonaDe(departamento, provincia);
    return listaTecnicos
      .filter(t => String(t.partner_id) === String(partnerId) && (t.tipo_tecnico == 1 || !t.tipo_tecnico))
      .filter(t => !dep || !t.departamento || normalizarZona(t.departamento) === dep || (actual && t.nombre_tecnico === actual))
      .sort((a, b) => (a.departamento ? 0 : 1) - (b.departamento ? 0 : 1) || String(a.nombre_tecnico).localeCompare(String(b.nombre_tecnico), 'es'));
  }
  const textoLider = t => t.nombre_tecnico + (t.departamento ? '' : ' · sin zona');

  // t1Guardado (2026-10-24): al reabrir una supervisión en proceso, su T1 entra en la lista aunque la zona aún no esté
  // cargada; antes la lista quedaba la del partner o zona anteriores y el T1 se perdía (quedaba solo el T2).
  function poblarSelectsTecnicos(t1Guardado = '') {
    // TÉCNICO LÍDER (T1): los del partner y de la zona (departamento) elegidos.
    if (newTec1Nom) {
      const currentVal1 = t1Guardado || newTec1Nom.value;
      const dep = document.getElementById('new_dep')?.value || '';
      const t1List = lideresDeZona(newPartner.value, dep, currentVal1, document.getElementById('new_prov')?.value || '');
      const vacio = newPartner.value && dep && !t1List.length ? `No hay técnicos líderes de este partner en ${dep}` : 'Seleccione / busque técnico líder (T1)';
      newTec1Nom.innerHTML = `<option value="">${escapeHtml(vacio)}</option>`;
      t1List.forEach(t => {
        const isSel = (t.nombre_tecnico === currentVal1) ? 'selected' : '';
        newTec1Nom.innerHTML += `<option value="${escapeHtml(t.nombre_tecnico)}" data-id="${t.id}" ${isSel}>${escapeHtml(textoLider(t))}</option>`;
      });
    }

    // TÉCNICO APOYO (T2): opcional, sin ingreso libre ni creación automática.
    if (newTec2Nom) {
      const currentVal2 = newTec2Nom.value;
      newTec2Nom.innerHTML = '<option value="">Sin técnico de apoyo (T2)</option>';
      newTec2Nom.innerHTML += opcionesApoyo(newPartner.value, currentVal2);
    }
    activarBuscadorTecnico(newTec1Nom, 1);
    activarBuscadorTecnico(newTec2Nom, 2);
  }

  // Vencimientos en el checklist (2026-10-15): SOAT, revisión técnica, extintor (de la placa) y brevete (del T1) muestran
  // su estado según las fechas de la cuadrilla. "Registrar fechas" despliega ahí mismo inicio, vence y foto: viajan con la
  // supervisión, quedan pendientes y pasan a la cuadrilla al FINALIZARLA (en la siguiente supervisión ya salen solas).
  // El servidor recalcula el estado al guardar y crea la observación automática si algo quedó vencido.
  // No depende de vencimientos.js (este script corre antes de que cargue): consulta la API directamente.
  const VENC_AVISO = { 'SOAT': 30, 'Revisión técnica': 30, 'Brevete': 60, 'Extintor': 'mitad' };
  const fechaVencTxt = (v) => { const [a, m, d] = String(v).slice(0, 10).split('-'); return `${d}/${m}/${a}`; };
  const estadoVencLocal = (tipo, inicio, vence) => {
    if (!inicio || !vence) return null;
    const dia = 86400000;
    const hoy = new Date(new Date().toISOString().slice(0, 10)), i = new Date(inicio), v = new Date(vence);
    if (v <= i) return { estado: 'error', texto: 'La fecha de vencimiento debe ser posterior a la de inicio.' };
    const dias = Math.round((v - hoy) / dia);
    if (dias < 0) return { estado: 'malo', texto: `Vencido hace ${-dias} día${-dias === 1 ? '' : 's'}` };
    const aviso = VENC_AVISO[tipo] ?? 30;
    const regular = aviso === 'mitad' ? (hoy - i) * 2 >= (v - i) : dias <= aviso;
    return { estado: regular ? 'regular' : 'bueno', texto: dias === 0 ? 'Vence hoy' : `Vence en ${dias} día${dias === 1 ? '' : 's'}` };
  };
  const checklistDeVenc = (e) => ({ bueno: 'Bueno', regular: 'Regular', malo: 'Malo' })[e] || 'No revisado';
  const insigniaVenc = (e) => ({ bueno: '<span class="badge bg-success">Vigente</span>', regular: '<span class="badge bg-warning">Por vencer</span>',
    malo: '<span class="badge bg-danger">Vencido</span>' })[e] || '<span class="badge bg-secondary">Sin registrar</span>';

  function prepararVencimientosChecklist(prefijo, idSupervision) {
    const seccion = document.getElementById(prefijo + '_sec_checklist');
    const t1 = document.getElementById(prefijo + '_tec1_nom');
    const placa = document.getElementById(prefijo + '_u_placa');
    if (!seccion || !t1) return;
    const filas = () => [...seccion.querySelectorAll('.checklist-venc')];
    const liderActual = () => listaTecnicos.find(t => t.nombre_tecnico === t1.value && (t.tipo_tecnico == 1 || !t.tipo_tecnico));
    const deServidor = new Map(); // tipo → documento vigente (o pendiente de esta supervisión)
    // "Estado del vehículo" del checklist (2026-10-16): sale marcado con el estado ya registrado en la sección 3
    // (el de la cuadrilla) y, si se cambia en uno, se cambia en el otro: no se pide dos veces.
    const estadoSel = document.getElementById(prefijo + '_u_estado');
    const filaEstado = [...seccion.querySelectorAll('input[name="checklist_criterio[]"]')].find(i => i.value === 'Estado del vehículo')?.closest('.checklist-row');
    const aChecklist = { 'Buen estado': 'Bueno', 'Regular': 'Regular', 'Mal estado': 'Malo' };
    const aSeccion = { 'Bueno': 'Buen estado', 'Regular': 'Regular', 'Malo': 'Mal estado' };
    const llevarEstado = () => {
      if (!estadoSel || !filaEstado) return;
      const radio = filaEstado.querySelector(`input.checklist-estado[value="${aChecklist[estadoSel.value] || ''}"]`);
      if (radio && !radio.checked) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
    };
    estadoSel?.addEventListener('change', llevarEstado);
    filaEstado?.addEventListener('change', (ev) => {
      const v = ev.target.matches('input.checklist-estado') ? aSeccion[ev.target.value] : null;
      if (v && estadoSel && estadoSel.value !== v) estadoSel.value = v;
    });
    const pintarFila = (fila) => {
      const tipo = fila.dataset.venc, item = deServidor.get(tipo);
      const ini = fila.querySelector('.checklist-venc-inicio')?.value || '', fin = fila.querySelector('.checklist-venc-fin')?.value || '';
      const nuevo = estadoVencLocal(tipo, ini, fin);
      let estado = 'sin_registrar', texto;
      if (nuevo && nuevo.estado !== 'error') {
        estado = nuevo.estado;
        texto = `Con las fechas ingresadas: vence el ${fechaVencTxt(fin)} · ${nuevo.texto}. Se asigna a la cuadrilla al finalizar la supervisión.`;
      } else if (nuevo) {
        texto = nuevo.texto;
      } else if (fila.dataset.aviso) {
        texto = fila.dataset.aviso;
      } else if (item && item.fecha_vencimiento) {
        estado = item.estado;
        texto = (item.pendiente ? 'Registrado en esta supervisión: vence' : 'Vence') + ` el ${fechaVencTxt(item.fecha_vencimiento)} · ${item.texto}`
          + (item.pendiente ? '. Se asigna a la cuadrilla al finalizar.' : '');
      } else {
        texto = 'Sin fechas registradas: use "Registrar fechas".';
      }
      fila.querySelector('.checklist-venc-valor').value = checklistDeVenc(estado);
      fila.classList.remove('venc-bueno', 'venc-regular', 'venc-malo', 'venc-sin_registrar');
      fila.classList.add('venc-' + estado);
      fila.querySelector('.checklist-venc-estado').innerHTML = insigniaVenc(estado) + `<small class="text-muted">${escapeHtml(texto)}</small>`;
      const boton = fila.querySelector('.checklist-venc-actualizar');
      if (boton && !boton.dataset.abierto) boton.lastChild.textContent = item && item.fecha_vencimiento ? 'Actualizar fechas' : 'Registrar fechas';
    };
    let pedido = 0;
    const actualizar = async () => {
      setTimeout(llevarEstado, 0); // después de que se llenen los datos del vehículo del técnico elegido
      const lider = liderActual();
      deServidor.clear();
      filas().forEach(f => { delete f.dataset.aviso; });
      if (!lider) {
        filas().forEach(f => { f.dataset.aviso = 'Elija el técnico líder para ver sus fechas (o regístrelas aquí).'; pintarFila(f); });
        return;
      }
      const mio = ++pedido;
      try {
        const id = (typeof idSupervision === 'function' ? idSupervision() : idSupervision) || '';
        const res = await fetch(`api/vencimientos.php?action=cuadrilla&lider_id=${encodeURIComponent(lider.id)}&placa=${encodeURIComponent(placa?.value || '')}&supervision_id=${encodeURIComponent(id)}`);
        const r = await res.json().catch(() => ({ status: 'error', message: 'No se pudieron consultar las fechas.' }));
        if (mio !== pedido) return; // llegó una respuesta más nueva
        if (r.status !== 'success') throw new Error(r.message || 'No se pudieron consultar las fechas.');
        r.data.items.forEach(i => deServidor.set(i.tipo, i));
        filas().forEach(f => {
          const item = deServidor.get(f.dataset.venc);
          if (item && item.sujeto === 'vehiculo' && !item.sujeto_id) {
            f.dataset.aviso = r.data.vehiculo.placa ? 'Placa nueva: registre aquí sus fechas; se asignan al finalizar la supervisión.' : 'Registre la placa del vehículo.';
          }
          pintarFila(f);
        });
      } catch (e) {
        filas().forEach(f => { f.dataset.aviso = e.message; pintarFila(f); });
      }
    };
    seccion.addEventListener('click', (ev) => {
      const b = ev.target.closest('.checklist-venc-actualizar'); if (!b) return;
      const campos = b.closest('.checklist-venc').querySelector('.checklist-venc-campos');
      const abrir = campos.classList.contains('d-none');
      campos.classList.toggle('d-none', !abrir);
      if (abrir) { b.dataset.abierto = '1'; b.lastChild.textContent = 'Ocultar fechas'; campos.querySelector('.checklist-venc-inicio')?.focus(); }
      else { delete b.dataset.abierto; pintarFila(b.closest('.checklist-venc')); }
    });
    seccion.addEventListener('change', (ev) => {
      const fila = ev.target.closest('.checklist-venc');
      if (fila && ev.target.closest('.checklist-venc-campos')) pintarFila(fila);
    });
    t1.addEventListener('change', actualizar);
    placa?.addEventListener('change', actualizar);
    actualizar();
    return actualizar;
  }

  // T2 (2026-10-15): rotan entre cuadrillas, así que se puede elegir cualquiera. Primero los del partner de la
  // supervisión; los demás muestran su partner actual y, al cerrar la supervisión, pasan a esta cuadrilla.
  function opcionesApoyo(partnerId, actual) {
    const propios = String(partnerId || '');
    return listaTecnicos.filter(t => t.tipo_tecnico == 2)
      .sort((a, b) => (String(a.partner_id) === propios ? 0 : 1) - (String(b.partner_id) === propios ? 0 : 1) || String(a.nombre_tecnico).localeCompare(String(b.nombre_tecnico), 'es'))
      .map(t => {
        const otro = String(t.partner_id) !== propios ? ` · ${t.nombre_partner || 'sin partner'}` : '';
        return `<option value="${escapeHtml(t.nombre_tecnico)}" data-id="${t.id}" ${t.nombre_tecnico === actual ? 'selected' : ''}>${escapeHtml(t.nombre_tecnico + otro)}</option>`;
      }).join('');
  }

  function sincronizarTecnico(select, inputDni, tipo) {
    const match = listaTecnicos.find(t => t.nombre_tecnico === select.value);
    // 2026-10-19: si su ficha no tiene un DNI válido, el campo queda vacío para escribirlo (antes "0", que hacía
    // que el guardado automático descartara al técnico sin avisar).
    inputDni.value = match && /^\d{8}$/.test(String(match.dni || '')) ? String(match.dni) : '';
    inputDni.placeholder = match && !inputDni.value ? `Escriba el DNI del T${tipo} (8 dígitos)` : (tipo === 1 ? 'Se completa al seleccionar al técnico' : 'Sin técnico T2');
    if (tipo === 1) {
      const prefix = select.id.startsWith('edit_') ? 'edit' : 'new';
      const plate = document.getElementById(prefix + '_u_placa');
      const vehicleType = document.getElementById(prefix + '_u_tipo');
      if (plate) plate.value = match?.unidad_placa || '';
      if (vehicleType) vehicleType.value = match?.unidad_tipo || '';
      const vehicleState = document.getElementById(prefix + '_u_estado');
      if (vehicleState && match?.unidad_estado) vehicleState.value = match.unidad_estado; // último estado registrado de su vehículo
      // Cuadrilla (2026-10-24): el T2 depende del T1. Al elegir otro T1 (o quitarlo) el T2 pasa a su técnico de apoyo
      // habitual, o queda vacío; después se puede cambiar. Sin T1 el T2 queda bloqueado. Al abrir el formulario solo se
      // completa si está vacío (una supervisión guardada conserva el T2 que registró el supervisor).
      const t2 = document.getElementById(prefix + '_tec2_nom');
      const previo = select.dataset.ultimoT1;
      select.dataset.ultimoT1 = select.value;
      if (t2 && (previo === undefined ? !t2.value : previo !== select.value)) {
        const habitual = match?.apoyo_nombre && [...t2.options].some(o => o.value === match.apoyo_nombre) ? match.apoyo_nombre : '';
        if (t2.value !== habitual) { t2.value = habitual; t2.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      bloquearT2(prefix);
    }
  }
  // El T2 (y su DNI) solo se habilitan con un T1 elegido y la sección de personal activa.
  function bloquearT2(prefix) {
    const t1 = document.getElementById(prefix + '_tec1_nom');
    if (!t1) return;
    [prefix + '_tec2_nom', prefix + '_tec2_dni'].forEach(id => { const c = document.getElementById(id); if (c) c.disabled = t1.disabled || !t1.value; });
  }

  newPartner?.addEventListener('change', () => {
    poblarSelectsTecnicos();
    [newTec1Nom,newTec2Nom].forEach(el => el?.dispatchEvent(new Event('change')));
  });
  // Al cambiar el departamento, la lista de T1 se limita a esa zona.
  // Vencimientos del checklist del formulario nuevo (el id existe cuando la supervisión ya se inició).
  const refrescarVencimientosNuevo = prepararVencimientosChecklist('new', () => newSupervisionId?.value) || (() => {});
  document.getElementById('new_dep')?.addEventListener('change', () => {
    if (!listaTecnicos.length) return;
    const antes = newTec1Nom?.value || '';
    poblarSelectsTecnicos();
    if (newTec1Nom && newTec1Nom.value !== antes) newTec1Nom.dispatchEvent(new Event('change'));
  });

  // Manejador de selección y autocompletado de DNI para Técnico Líder (T1)
  if (newTec1Nom) {
    newTec1Nom.addEventListener('change', function() {
      sincronizarTecnico(this, newTec1Dni, 1);
    });
  }

  // T2 puede quedar vacío cuando la cuadrilla no cuenta con técnico de apoyo.
  if (newTec2Nom) {
    newTec2Nom.addEventListener('change', function() {
      sincronizarTecnico(this, newTec2Dni, 2);
      if (!this.value && document.getElementById('new_brevete')?.value === 'Tecnico 2') {
        document.getElementById('new_brevete').value = 'Tecnico 1';
      }
    });
  }

  document.addEventListener('click', event => {
    const btn = event.target.closest('.btn-editar-tecnico');
    if (!btn) return;
      const esEdicionDetalle = Boolean(btn.closest('#form-edit-detail'));
      const select = document.getElementById(`${esEdicionDetalle ? 'edit' : 'new'}_tec${btn.dataset.tipo}_nom`);
      const tecnico = listaTecnicos.find(t => t.nombre_tecnico === select?.value);
      if (!tecnico || !modalEditarTecnicoEl) return;
      document.getElementById('edit_tecnico_id').value = tecnico.id;
      document.getElementById('edit_tecnico_nombre').value = tecnico.nombre_tecnico;
      document.getElementById('edit_tecnico_dni').value = tecnico.dni || '';
      document.getElementById('edit_tecnico_celular').value = tecnico.celular || '';
      document.getElementById('edit_tecnico_celular_box').style.display = tecnico.tipo_tecnico == 1 ? '' : 'none';
      document.getElementById('edit_tecnico_estado').value = tecnico.estado_operativo || 'Habilitado';
      document.getElementById('edit_tecnico_observacion').value = tecnico.observacion || '';
      modalEditarTecnicoEl.dataset.selectId = select.id;
      bootstrap.Modal.getOrCreateInstance(modalEditarTecnicoEl).show();
  });

  if (formEditarTecnico) {
    formEditarTecnico.addEventListener('submit', async e => {
      e.preventDefault();
      const id = document.getElementById('edit_tecnico_id').value;
      const nombre = document.getElementById('edit_tecnico_nombre').value.trim();
      const dni = document.getElementById('edit_tecnico_dni').value.trim();
      if (!/^\d{8}$/.test(dni)) {
        showToast('El DNI debe contener exactamente 8 dígitos.');
        return;
      }
      const original = listaTecnicos.find(t => String(t.id) === String(id));
      const body = new FormData();
      body.append('action', 'update');
      body.append('id', id);
      body.append('nombre_tecnico', nombre);
      body.append('dni', dni);
      body.append('celular', document.getElementById('edit_tecnico_celular').value.trim());
      body.append('estado_operativo', document.getElementById('edit_tecnico_estado').value);
      body.append('observacion', document.getElementById('edit_tecnico_observacion').value.trim());
      try {
        const res = await fetch('api/tecnicos.php', { method: 'POST', body });
        const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo actualizar.');
        if (original) {
          original.nombre_tecnico = data.data.nombre_tecnico;
          original.dni = data.data.dni;
          original.celular = data.data.celular;
          original.observacion = data.data.observacion;
          original.estado_operativo = data.data.estado_operativo;
        }
        poblarSelectsTecnicos();
        const selectOrigen = document.getElementById(modalEditarTecnicoEl.dataset.selectId || '');
        if (selectOrigen) {
          let option = Array.from(selectOrigen.options).find(o => o.value === data.data.nombre_tecnico);
          if (!option) {
            option = new Option(`${data.data.nombre_tecnico} — DNI: ${data.data.dni}`, data.data.nombre_tecnico, true, true);
            selectOrigen.add(option);
          }
          selectOrigen.value = data.data.nombre_tecnico;
          const prefijo = selectOrigen.id.startsWith('edit_') ? 'edit' : 'new';
          const tipo = original?.tipo_tecnico == 2 ? 2 : 1;
          const dniOrigen = document.getElementById(`${prefijo}_tec${tipo}_dni`);
          if (dniOrigen) dniOrigen.value = data.data.dni;
        }
        bootstrap.Modal.getInstance(modalEditarTecnicoEl)?.hide();
        showToast('Datos del técnico actualizados.');
      } catch (error) {
        showToast(error.message || 'Error al actualizar el técnico.');
      }
    });
  }

  // Ocultar / Mostrar Sub-bloque de Cancelación según "Tipo de Supervisión" === "Cancelación"
  function bindCancelacionToggle(selectEl, boxEl) {
    if (!selectEl || !boxEl) return;
    function check() {
      const mostrar = selectEl.value === 'Cancelación';
      boxEl.style.display = mostrar ? 'block' : 'none';
      boxEl.querySelectorAll('input, select, textarea').forEach(campo => campo.disabled = !mostrar);
    }
    selectEl.addEventListener('change', check);
    check();
  }

  if (newTipoSupervision && newBoxCancelacion) {
    bindCancelacionToggle(newTipoSupervision, newBoxCancelacion);
  }

  // Supervisión remota: muestra los campos de la videollamada solo con la modalidad "Remota".
  function sgsActualizarRemota(form) {
    if (!form) return;
    const sel = form.querySelector('[name="tipo_supervision_inicial"]');
    const remota = Boolean(sel && !sel.disabled && sel.value === 'Remota');
    form.querySelectorAll('.remota-only').forEach(el => {
      el.classList.toggle('d-none', !remota);
      // No llevan 'required': la hora de fin aún no se conoce al iniciar. Se exigen al finalizar (faltantesNuevo, validación de edición y servidor).
      el.querySelectorAll('input,select').forEach(c => { if (!c.closest('fieldset[disabled]')) c.disabled = !remota || c.dataset.bloqueado === '1'; });
    });
    sgsModoCoordenadas(form, remota);
  }
  const esModalidadRemota = (id) => document.getElementById(id)?.value === 'Remota' && !document.getElementById(id)?.disabled;

  function updateChecklistMode() {
    if (!newTipoSupervision) return;
    const tipoActual = newTipoSupervision.value;
    const esChecklist = tipoActual === 'Checklist';
    const requiereSubtipoInicial = tipoActual === 'Postventa';
    const requiereResultadoPosterior = ['Avería', 'Reiterada', 'Garantía'].includes(tipoActual);
    const sinCodigoPedido = [
      'Incidencia / Casos atípicos',
      'Proyecto de ordenamiento',
      'Visita partner por capacitación',
      'Conjunta PEXT',
      'Checklist'
    ].includes(tipoActual);
    const sinPartner = tipoActual === 'Incidencia / Casos atípicos';
    const sinCuadrilla = ['Incidencia / Casos atípicos', 'Visita partner por capacitación'].includes(tipoActual);
    const sinEstadoServicio = newTipoSupModalidad?.value === 'En frío' || ['Incidencia / Casos atípicos', 'Visita partner por capacitación', 'Checklist'].includes(tipoActual);
    const esEnFrio = newTipoSupModalidad?.value === 'En frío';
    const sinBloquesAdicionales = ['Incidencia / Casos atípicos', 'Visita partner por capacitación'].includes(tipoActual)
      || (tipoActual === 'Proyecto de ordenamiento' && esEnFrio);
    const permiteChecklist = esChecklist || (!sinBloquesAdicionales && !esEnFrio);
    const incluyeChecklist = esChecklist || (permiteChecklist && Boolean(newChkChecklist?.checked));

    document.querySelectorAll('#form-inicio-supervision .checklist-vehicle-only').forEach(field => {
      field.style.display = incluyeChecklist ? '' : 'none';
      field.querySelectorAll('select,input').forEach(control => control.disabled = !incluyeChecklist);
    });

    const catalogosSubtipo = {
      'Postventa': LISTA_TRABAJOS_POSTVENTA
    };
    if (newFieldSubtipo && newSubTipoAtencion) {
      const valorAnterior = newSubTipoAtencion.value;
      newFieldSubtipo.style.display = requiereSubtipoInicial ? '' : 'none';
      newSubTipoAtencion.disabled = !requiereSubtipoInicial;
      newSubTipoAtencion.required = requiereSubtipoInicial;
      newSubTipoAtencion.innerHTML = '<option value="">Seleccione una opción</option>';
      (catalogosSubtipo[tipoActual] || []).forEach(opcion => {
        newSubTipoAtencion.add(new Option(opcion, opcion, false, opcion === valorAnterior));
      });
      activarBuscadorSelect(newSubTipoAtencion, 'Escriba para buscar trabajo programado...');
    }
    if (newFieldResultadoTecnico && newResultadoTecnico) {
      const valorAnterior = newResultadoTecnico.value;
      newFieldResultadoTecnico.style.display = requiereResultadoPosterior ? '' : 'none';
      newResultadoTecnico.disabled = !requiereResultadoPosterior;
      newResultadoTecnico.required = false;
      newResultadoTecnico.innerHTML = '<option value="">Se selecciona después de validar el servicio</option>';
      LISTA_CIERRE_TECNICO.forEach(opcion => newResultadoTecnico.add(new Option(opcion, opcion, false, opcion === valorAnterior)));
      activarBuscadorSelect(newResultadoTecnico, 'Escriba para buscar solución aplicada / cierre técnico...');
    }

    if (newToggleChecklist) newToggleChecklist.style.display = (!esChecklist && permiteChecklist) ? 'block' : 'none';
    if (newChkChecklist) {
      newChkChecklist.disabled = !permiteChecklist || esChecklist;
      if (!permiteChecklist) newChkChecklist.checked = false;
    }
    if (newSecCodigoPedido) newSecCodigoPedido.style.display = sinCodigoPedido ? 'none' : 'block';
    if (newTicketCodigo) {
      newTicketCodigo.disabled = sinCodigoPedido;
      newTicketCodigo.required = !sinCodigoPedido;
      if (sinCodigoPedido) newTicketCodigo.value = '';
    }
    if (newFieldPartner) newFieldPartner.style.display = sinPartner ? 'none' : '';
    if (newPartner) {
      newPartner.disabled = sinPartner;
      newPartner.required = !sinPartner;
      if (sinPartner) newPartner.value = '';
    }
    if (newFieldEstadoServicio) newFieldEstadoServicio.style.display = sinEstadoServicio ? 'none' : '';
    if (newEstadoServicio) {
      newEstadoServicio.disabled = sinEstadoServicio;
      newEstadoServicio.required = false;
    }
    const sinModalidad = esChecklist || tipoActual === 'Visita partner por capacitación';
    if (newFieldModalidad) newFieldModalidad.style.display = sinModalidad ? 'none' : '';
    if (newTipoSupModalidad) {
      newTipoSupModalidad.disabled = sinModalidad;
      if (sinModalidad) newTipoSupModalidad.value = 'En caliente';
    }
    sgsActualizarRemota(document.getElementById('form-inicio-supervision'));
    if (newFieldObsGeneral) newFieldObsGeneral.style.display = esChecklist ? 'none' : '';
    const newSecFotosGenerales = document.getElementById('new_sec_fotos_generales');
    if (newSecFotosGenerales) {
      newSecFotosGenerales.style.display = esChecklist ? 'none' : '';
      newSecFotosGenerales.querySelectorAll('input').forEach(el => el.disabled = esChecklist);
    }
    const obsGeneral = document.getElementById('new_obs');
    if (obsGeneral) obsGeneral.disabled = esChecklist;
    if (newSecObservaciones) newSecObservaciones.style.display = (esChecklist || sinBloquesAdicionales) ? 'none' : '';
    if (newChkObs) {
      if (esChecklist || sinBloquesAdicionales) newChkObs.checked = false;
      newChkObs.disabled = esChecklist || sinBloquesAdicionales;
    }
    if (newBoxObs && (esChecklist || sinBloquesAdicionales)) newBoxObs.style.display = 'none';
    if (newSecChecklist) newSecChecklist.style.display = incluyeChecklist ? 'block' : 'none';

    if (newSecChecklist) {
      newSecChecklist.querySelectorAll('input, textarea, button').forEach(control => {
        control.disabled = !incluyeChecklist;
      });
      if (!incluyeChecklist && !esChecklist) {
        newSecChecklist.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(control => control.checked = false);
        // Sin los ocultos: guardan el nombre de cada ítem (checklist_criterio[]); borrarlos dejaba el checklist sin nombres (2026-10-16).
        newSecChecklist.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]), textarea').forEach(control => { control.value = ''; });
        const canvas = document.getElementById('new_checklist_firma_canvas');
        canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
        const firmaDato = document.getElementById('new_checklist_firma_data'); if (firmaDato) firmaDato.value = '';
      }
    }

    document.querySelectorAll('#new_sec_checklist .checklist-estado').forEach(el => el.required = incluyeChecklist);
    const checklistComentarioGeneral = document.getElementById('new_checklist_obs_general');
    if (checklistComentarioGeneral) checklistComentarioGeneral.required = incluyeChecklist;
    [newTec1Nom, newTec1Dni, document.getElementById('new_u_tipo'), document.getElementById('new_u_placa')]
      .forEach(el => { if (el) el.required = incluyeChecklist; });
    if (newSecCuadrilla) {
      const mostrarPersonal = !sinCuadrilla && !esEnFrio && newTipoSupModalidad?.value !== 'Remota'; // remota: solo el partner
      newSecCuadrilla.style.display = sinPartner ? 'none' : '';
      const personal = document.getElementById('new_personal_vehiculo');
      personal.style.display = mostrarPersonal ? '' : 'none';
      personal.querySelectorAll('input,select,textarea,button').forEach(el => el.disabled = !mostrarPersonal);
      bloquearT2('new');
    }
    const motivoBoxActual = document.getElementById('box_motivo_cancelacion_new');
    const motivoSelectActual = document.getElementById('new_motivo_cancelacion');
    if (motivoBoxActual && newEstadoServicio) {
      const mostrarMotivo = !newEstadoServicio.disabled && tipoActual !== 'Conjunta PEXT' && newEstadoServicio.value === 'Cancelada';
      motivoBoxActual.style.display = mostrarMotivo ? 'block' : 'none';
      if (motivoSelectActual) {
        motivoSelectActual.disabled = !mostrarMotivo;
        motivoSelectActual.required = mostrarMotivo && newEstadoServicio.value === 'Cancelada';
        if (!mostrarMotivo) motivoSelectActual.value = '';
      }
    }
  }
  if (newTipoSupervision) newTipoSupervision.addEventListener('change', updateChecklistMode);
  if (newTipoSupModalidad) newTipoSupModalidad.addEventListener('change', updateChecklistMode);
  if (newChkChecklist) newChkChecklist.addEventListener('change', updateChecklistMode);
  updateChecklistMode();

  // Previsualización Inmediata de Fotos Cargadas
  function setupPhotoPreview(inputId, previewBoxId) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(previewBoxId);
    if (!input || !box) return;

    input.addEventListener('change', function () {
      const file = this.files[0];
      if (file) {
        box.style.display = 'block';
        box.innerHTML = '<span style="font-size:12px; color:var(--win-text-muted);">Cargando vista previa...</span>';
        const reader = new FileReader();
        reader.onload = function (e) {
          box.innerHTML = `
            <div style="position:relative; display:inline-block; margin-top:8px;">
              <img src="${e.target.result}" style="max-height:110px; border-radius:6px; border:1px solid var(--win-border); cursor:pointer; object-fit:cover;" onclick="abrirFotoModal('${e.target.result}', 'Vista previa')" title="Haz clic para ampliar">
              <button type="button" style="position:absolute; top:-6px; right:-6px; background:var(--sgs-danger); color:#fff; border:none; border-radius:50%; width:20px; height:20px; font-size:11px; cursor:pointer; display:flex; align-items:center; justify-content:center;" onclick="limpiarFotoInput('${inputId}', '${previewBoxId}')"><i class="bi bi-x"></i></button>
              <div style="font-size:11px; color:var(--sgs-success-dark); font-weight:600; margin-top:4px;"><i class="bi bi-check-circle me-1"></i>Foto cargada lista para guardar</div>
            </div>`;
        };
        reader.readAsDataURL(file);
      } else {
        box.style.display = 'none';
        box.innerHTML = '';
      }
    });
  }

  window.limpiarFotoInput = function(inputId, previewBoxId) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(previewBoxId);
    if (input) input.value = '';
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
  };

  // Visor común con zoom (main.js)
  window.abrirFotoModal = function(src, titulo) {
    window.sgsVerFoto(src, titulo || 'Evidencia fotográfica');
  };

  setupPhotoPreview('new_foto1', 'new_preview_foto1');
  setupPhotoPreview('new_foto2', 'new_preview_foto2');
  setupPhotoPreview('new_foto3', 'new_preview_foto3');

  setupPhotoPreview('new_foto_serv_1', 'new_preview_foto_serv_1');
  setupPhotoPreview('new_foto_serv_2', 'new_preview_foto_serv_2');
  setupPhotoPreview('new_foto_serv_3', 'new_preview_foto_serv_3');

  const cboEstadoNew = document.getElementById('new_estado_servicio');
  const boxCancelNew = document.getElementById('box_motivo_cancelacion_new');
  const cboMotivoNew = document.getElementById('new_motivo_cancelacion');

  if (cboEstadoNew && boxCancelNew) {
    cboEstadoNew.addEventListener('change', function () {
      const esConjuntaPext = newTipoSupervision?.value === 'Conjunta PEXT';
      if (!esConjuntaPext && !this.disabled && this.value === 'Cancelada') {
        boxCancelNew.style.display = 'block';
        if (cboMotivoNew) cboMotivoNew.required = (this.value === 'Cancelada');
      } else {
        boxCancelNew.style.display = 'none';
        if (cboMotivoNew) {
          cboMotivoNew.required = false;
          cboMotivoNew.value = '';
        }
      }
      updateChecklistMode();
    });
  }

  // Cargar Catálogo de Observaciones (Categorías y Subopciones)
  async function cargarCatalogoObservaciones() {
    if (catalogoCategorias.length || catalogoSubopciones.length) return;
    if (catalogoRequest) return catalogoRequest;
    catalogoRequest = (async () => {
      try {
        const res = await fetch('api/observaciones.php?action=catalogo');
        const data = await res.json();
        if(data.status === 'success') {
          catalogoCategorias = data.categorias || [];
          catalogoSubopciones = data.subopciones || [];
          const cGrav = document.getElementById('new_obs_gravedad');
          const cCat = document.getElementById('new_obs_cat');
          const cSub = document.getElementById('new_obs_sub');
          setupSmartObservationCascade(cGrav, cCat, cSub);
        }
      } catch(e) {
        showToast('No se pudo cargar el catálogo de observaciones.', 'error');
      } finally {
        catalogoRequest = null;
      }
    })();
    return catalogoRequest;
  }

  // Tipificación de la observación: UN solo campo con búsqueda. Al elegir la tipificación se completan solas
  // su categoría y su gravedad (vienen del catálogo). Los <select> originales se mantienen ocultos porque
  // el formulario y el servidor siguen recibiendo obs_gravedad / obs_categoria_id / obs_subopcion_id.
  function setupSmartObservationCascade(cboGrav, cboCat, cboSub) {
    if (!cboCat || !cboSub || cboSub.dataset.obsCombo) return;
    cboSub.dataset.obsCombo = '1';
    const normalizar = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const categoriaDe = new Map(catalogoCategorias.map(c => [String(c.id), c.nombre_categoria]));
    const items = catalogoSubopciones.map(s => ({
      id: String(s.id), categoriaId: String(s.categoria_id), texto: s.descripcion_subopcion,
      categoria: categoriaDe.get(String(s.categoria_id)) || '', gravedad: String(s.gravedad || '').trim(),
    }));
    items.forEach(it => { it.clave = normalizar(`${it.texto} ${it.categoria} ${it.gravedad}`); });

    // Selects ocultos con TODO el catálogo (sin cascada)
    cboSub.innerHTML = '<option value=""></option>' + items.map(it => `<option value="${it.id}">${escapeHtml(it.texto)}</option>`).join('');
    cboCat.innerHTML = '<option value=""></option>' + catalogoCategorias.map(c => `<option value="${c.id}">${escapeHtml(c.nombre_categoria)}</option>`).join('');
    cboCat.disabled = false; cboSub.disabled = false;
    if (cboGrav) [...new Set(items.map(it => it.gravedad))].forEach(g => {
      if (g && ![...cboGrav.options].some(o => o.value === g)) cboGrav.add(new Option(g, g));
    });
    [cboGrav, cboCat].forEach(el => el?.closest('.field-bitacora')?.classList.add('d-none'));
    cboSub.classList.add('d-none');

    const campo = cboSub.closest('.field-bitacora');
    campo?.classList.add('full');
    const uid = cboSub.id + '_buscar';
    const etiqueta = campo?.querySelector('label');
    if (etiqueta) { etiqueta.textContent = 'Tipificación de la observación *'; etiqueta.htmlFor = uid; }
    const caja = document.createElement('div');
    caja.className = 'sgs-combo';
    caja.dataset.obsComboBox = '1';
    caja.innerHTML = `
      <div class="sgs-combo-campo">
        <input type="text" id="${uid}" class="form-control" autocomplete="off" role="combobox" aria-autocomplete="list"
          aria-expanded="false" aria-controls="${uid}_lista" placeholder="Escriba para buscar, por ejemplo: inasistencia, cable, EPP">
        <button type="button" class="sgs-combo-limpiar d-none" aria-label="Cambiar tipificación" title="Cambiar"><i class="bi bi-x-lg"></i></button>
        <ul class="sgs-combo-lista d-none" id="${uid}_lista" role="listbox"></ul>
      </div>
      <div class="sgs-combo-resumen d-none" aria-live="polite"></div>`;
    cboSub.after(caja);
    const input = caja.querySelector('input'), lista = caja.querySelector('ul'), resumen = caja.querySelector('.sgs-combo-resumen');
    const limpiar = caja.querySelector('.sgs-combo-limpiar');
    // Elegida la tipificación, el campo queda fijo (no se escribe encima); el botón de quitar lo libera
    const bloquear = (elegido) => { input.readOnly = elegido; input.classList.toggle('is-elegido', elegido); limpiar.classList.toggle('d-none', !elegido); };
    [cboGrav, cboCat, cboSub].forEach(el => { if (el) el._comboInput = input; });
    let visibles = [], activo = -1;

    const claseGravedad = (g) => g === 'Media' ? 'bg-warning' : (g === 'Leve' ? 'bg-secondary' : 'bg-danger');
    function abrir(abierto) { lista.classList.toggle('d-none', !abierto); input.setAttribute('aria-expanded', abierto ? 'true' : 'false'); }
    function pintar() {
      const palabras = normalizar(input.value).split(/\s+/).filter(Boolean);
      visibles = items.filter(it => palabras.every(p => it.clave.includes(p))).slice(0, 60);
      activo = visibles.length ? 0 : -1;
      lista.innerHTML = visibles.length
        ? visibles.map((it, i) => `<li role="option" id="${uid}_op${i}" data-i="${i}" class="${i === activo ? 'is-activo' : ''}">
            <span class="sgs-combo-texto">${escapeHtml(it.texto)}</span>
            <span class="sgs-combo-meta">${escapeHtml(it.categoria)} · <span class="badge ${claseGravedad(it.gravedad)}">${escapeHtml(it.gravedad)}</span></span></li>`).join('')
        : '<li class="sgs-combo-vacio">Sin coincidencias. Pruebe con otra palabra.</li>';
      input.setAttribute('aria-activedescendant', activo >= 0 ? `${uid}_op${activo}` : '');
      abrir(true);
    }
    function marcarActivo(i) {
      if (!visibles.length) return;
      activo = Math.max(0, Math.min(visibles.length - 1, i));
      lista.querySelectorAll('li[data-i]').forEach(li => li.classList.toggle('is-activo', Number(li.dataset.i) === activo));
      lista.querySelector('.is-activo')?.scrollIntoView({ block: 'nearest' });
      input.setAttribute('aria-activedescendant', `${uid}_op${activo}`);
    }
    function asignar(it) {
      cboSub.value = it ? it.id : '';
      cboCat.value = it ? it.categoriaId : '';
      if (cboGrav) cboGrav.value = it ? it.gravedad : '';
      [cboSub, cboCat, cboGrav].forEach(el => el?.dispatchEvent(new Event('change', { bubbles: true })));
      resumen.classList.toggle('d-none', !it);
      bloquear(Boolean(it));
      resumen.innerHTML = it ? `<span><span class="text-muted">Categoría:</span> ${escapeHtml(it.categoria)}</span>
        <span><span class="text-muted">Gravedad:</span> <span class="badge ${claseGravedad(it.gravedad)}">${escapeHtml(it.gravedad)}</span></span>
        ${it.gravedad === 'Muy Grave' ? '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Pasa directo a penalidad</span>' : ''}` : '';
    }
    function elegir(it) { input.value = it.texto; asignar(it); abrir(false); }

    input.addEventListener('focus', () => { if (!input.readOnly) pintar(); });
    limpiar.addEventListener('click', () => { input.value = ''; asignar(null); input.focus(); pintar(); });
    input.addEventListener('input', () => { if (cboSub.value) asignar(null); pintar(); });
    input.addEventListener('keydown', (e) => {
      if (input.readOnly) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); if (lista.classList.contains('d-none')) pintar(); else marcarActivo(activo + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); marcarActivo(activo - 1); }
      else if (e.key === 'Enter') { if (!lista.classList.contains('d-none') && visibles[activo]) { e.preventDefault(); elegir(visibles[activo]); } }
      else if (e.key === 'Escape') abrir(false);
    });
    // mousedown (no click) para elegir antes de que el campo pierda el foco
    lista.addEventListener('mousedown', (e) => { const li = e.target.closest('li[data-i]'); if (!li) return; e.preventDefault(); elegir(visibles[Number(li.dataset.i)]); });
    input.addEventListener('blur', () => {
      abrir(false);
      if (!cboSub.value && input.value.trim()) {
        // Si lo escrito coincide exactamente con una tipificación, se toma; si no, se limpia (no se guarda texto libre)
        const exacta = items.find(it => normalizar(it.texto) === normalizar(input.value.trim()));
        if (exacta) elegir(exacta); else input.value = '';
      }
    });
    caja._reset = () => { input.value = ''; asignar(null); abrir(false); };
  }

  if (newChkObs) {
    newChkObs.addEventListener('change', () => {
      newBoxObs.style.display = newChkObs.checked ? 'block' : 'none';
      if (!newChkObs.checked) {
        newBoxObs.querySelectorAll('input, select, textarea').forEach(field => {
          if (field.type === 'file') field.value = '';
          else if (field.tagName === 'SELECT') field.selectedIndex = 0;
          else field.value = '';
        });
        newBoxObs.querySelectorAll('[data-obs-combo-box]').forEach(caja => caja._reset?.());
        ['new_preview_foto1','new_preview_foto2','new_preview_foto3'].forEach(id => {
          const preview = document.getElementById(id);
          if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
        });
      }
    });
  }

  // Captura GPS precisa (2026-10-15; afinada 2026-10-16). La precisión que da el celular es un radio con 95 % de
  // confianza (estándar de los navegadores): "±8 m" = 95 % de probabilidad de que el punto real esté a menos de 8 m.
  // Se escucha el GPS hasta 45 s; se descartan las lecturas de red o wifi (peor que ±100 m) y las que se alejan del
  // resto, y se PROMEDIAN las buenas (pesa más la más precisa): así se quita el "salto" típico del GPS. Termina antes
  // cuando junta 5 lecturas de ±10 m o mejor. La precisión que se muestra es la de la mejor lectura (no se exagera).
  var GPS_OBJETIVO = 10, GPS_LECTURAS_OBJETIVO = 5, GPS_ESPERA_MS = 45000, GPS_PRECISION_ACEPTABLE = 30, GPS_DESCARTE = 100; // var: sgsModoCoordenadas puede llamarse antes de esta línea
  var gpsEnCurso = window.sgsGpsEnCurso = window.sgsGpsEnCurso || {};
  const gpsDistanciaM = (a, b) => {
    const r = Math.PI / 180, dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) ** 2;
    return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
  };
  /** Promedio ponderado (1/precisión²) de las lecturas buenas, sin las que se alejan del resto. */
  const gpsCombinar = (lecturas) => {
    const mejorAcc = Math.min(...lecturas.map(l => l.acc));
    let usadas = lecturas.filter(l => l.acc <= GPS_DESCARTE && l.acc <= Math.max(GPS_OBJETIVO * 2, mejorAcc * 2));
    if (!usadas.length) usadas = [lecturas.reduce((a, b) => (b.acc < a.acc ? b : a))];
    const media = (lista) => {
      let w = 0, lat = 0, lng = 0;
      lista.forEach(l => { const p = 1 / (l.acc * l.acc); w += p; lat += l.lat * p; lng += l.lng * p; });
      return { lat: lat / w, lng: lng / w };
    };
    let centro = media(usadas);
    const coherentes = usadas.filter(l => gpsDistanciaM(l, centro) <= l.acc * 2 + 3);
    if (coherentes.length) { usadas = coherentes; centro = media(usadas); }
    return { ...centro, acc: Math.min(...usadas.map(l => l.acc)), n: usadas.length };
  };
  window.capturarGPS = function(targetLatId, targetLngId, statusId) {
    const statusEl = document.getElementById(statusId);
    const latEl = document.getElementById(targetLatId), lngEl = document.getElementById(targetLngId);
    const aviso = (texto, tipo = '') => { if (statusEl) { statusEl.textContent = texto; statusEl.dataset.estado = tipo; } };
    if (!navigator.geolocation) return aviso('Este navegador no permite usar el GPS. Avise al administrador.', 'error');
    detenerGPS(targetLatId);
    const lecturas = [];
    let resultado = null;
    const hora = () => new Date().toLocaleTimeString('es-PE', {hour:'2-digit', minute:'2-digit', hour12:false});
    const poner = (r) => {
      const lat = r.lat.toFixed(7), lng = r.lng.toFixed(7);
      if (latEl) latEl.value = lat;
      if (lngEl) lngEl.value = lng;
      latEl?.dispatchEvent(new Event('input', {bubbles:true}));
      lngEl?.dispatchEvent(new Event('input', {bubbles:true}));
    };
    const terminar = () => {
      detenerGPS(targetLatId);
      if (!resultado) return aviso('No se pudo tomar la ubicación. Revisa que el GPS esté encendido y presiona "Actualizar ubicación".', 'error');
      const m = Math.max(1, Math.round(resultado.acc));
      const lecturasTxt = resultado.n > 1 ? ` (promedio de ${resultado.n} lecturas)` : '';
      if (m <= GPS_OBJETIVO) return aviso(`Ubicación tomada a las ${hora()} con precisión de ±${m} m${lecturasTxt}: excelente.`, 'ok');
      if (m <= GPS_PRECISION_ACEPTABLE) return aviso(`Ubicación tomada a las ${hora()} con precisión de ±${m} m${lecturasTxt}.`, 'ok');
      aviso(`Ubicación tomada a las ${hora()}, pero con precisión baja (±${m} m). Sal a un lugar abierto, lejos de techos y paredes altas, espera unos segundos y presiona "Actualizar ubicación".`, 'baja');
    };
    aviso('Buscando tu ubicación… mantén el celular quieto, con la pantalla hacia el cielo.', 'buscando');
    const id = navigator.geolocation.watchPosition((pos) => {
      const l = { lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy };
      if (!Number.isFinite(l.lat) || !Number.isFinite(l.lng) || !Number.isFinite(l.acc)) return;
      lecturas.push(l);
      resultado = gpsCombinar(lecturas);
      poner(resultado);
      const finas = lecturas.filter(x => x.acc <= GPS_OBJETIVO).length;
      aviso(`Afinando ubicación… precisión ±${Math.round(resultado.acc)} m · ${lecturas.length} lectura${lecturas.length === 1 ? '' : 's'}`
        + (finas ? ` (${Math.min(finas, GPS_LECTURAS_OBJETIVO)} de ${GPS_LECTURAS_OBJETIVO} finas)` : '') + '.', 'buscando');
      if (finas >= GPS_LECTURAS_OBJETIVO) terminar();
    }, (err) => {
      if (err && err.code === 1) { detenerGPS(targetLatId); return aviso('El celular no dio permiso de ubicación. Actívalo y presiona "Actualizar ubicación".', 'error'); }
      if (!resultado && err && err.code === 3) terminar();
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: GPS_ESPERA_MS });
    gpsEnCurso[targetLatId] = { id, plazo: setTimeout(terminar, GPS_ESPERA_MS) };
  };
  function detenerGPS(targetLatId) {
    const c = (window.sgsGpsEnCurso || {})[targetLatId];
    if (!c) return;
    navigator.geolocation?.clearWatch(c.id);
    clearTimeout(c.plazo);
    delete window.sgsGpsEnCurso[targetLatId];
  }

  // Coordenadas escritas a mano (2026-10-15): se pueden pegar juntas ("-8.139075, -79.055686", como las copia
  // Google Maps) en cualquiera de los dos campos y se separan solas. Si el GPS seguía buscando, se detiene para no
  // borrar lo escrito (2026-10-20).
  document.addEventListener('input', (ev) => {
    const el = ev.target;
    if (!(el instanceof HTMLInputElement) || !['latitud', 'longitud'].includes(el.name)) return;
    const form = el.form || el.closest('form') || document;
    const lat = form.querySelector('input[name="latitud"]'), lng = form.querySelector('input[name="longitud"]');
    if (ev.isTrusted && lat) detenerGPS(lat.id);
    const m = el.value.match(/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/);
    if (!m || !lat || !lng) return;
    lat.value = m[1]; lng.value = m[2];
    lng.dispatchEvent(new Event('change', {bubbles:true}));
  });

  // Coordenadas (2026-10-20, a pedido del usuario): en todas las modalidades se toma el GPS al iniciar, se puede
  // actualizar y cualquier supervisor puede escribirlas o pegarlas a mano. La remota solo cambia el ejemplo y el aviso.
  function sgsModoCoordenadas(form, remota) {
    const lat = form?.querySelector('input[name="latitud"]'), lng = form?.querySelector('input[name="longitud"]');
    if (!lat || !lng) return;
    const status = document.getElementById(lat.id.replace('latitud', 'gps_status'));
    lat.placeholder = remota ? 'Ej.: -8.139075' : '-12.046374';
    lng.placeholder = remota ? 'Ej.: -79.055686' : '-77.042689';
    if (remota && status && !status.dataset.estado) {
      status.textContent = 'Supervisión remota: se toma tu ubicación al iniciar, y puedes corregirla a mano o pegar las coordenadas del cliente (por ejemplo -8.139075, -79.055686).';
    }
  }
  // Función para Eliminar una Supervisión
  window.eliminarSupervision = async function(id, codigo) {
    if (!confirm(`¿Está seguro de eliminar la supervisión ${codigo}? Esta acción no se puede deshacer.`)) {
      return;
    }
    try {
      showToast('Eliminando supervisión...');
      const formData = new FormData();
      formData.append('id', id);
      const res = await fetch('api/supervisiones.php?action=delete', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.status === 'success') {
        showToast(`Supervisión ${codigo} eliminada correctamente.`);
        closeDetail();
        loadRecords();
      } else {
        showToast(data.message || 'Error al eliminar la supervisión.');
      }
    } catch (e) {
      showToast('Error de conexión al eliminar.');
    }
  };

  function normalizeSupervisionRecord(item) {
    const rawSt = String(item.estado_supervision || '').toLowerCase();
    // Una pendiente "En rescate" tiene su propio estado: no se inicia ni se asigna a partner mientras dure el rescate.
    const st = item.rescate_estado === 'En rescate' ? 'rescate' : (['culminada','finalizada','finalizado','cancelada'].includes(rawSt)
      ? 'finalizada'
      : (rawSt === 'pendiente' ? 'pendiente' : 'iniciada'));
    // Pendiente sin tomar (2026-10-16): sin rescate ni asignación a partner. Al cargar el Excel queda a nombre de un
    // supervisor de la zona solo por orden interno; no está asignada a él, por eso se muestra "Aún no gestionado".
    // Asignada a partner = es_asignacion_partner (no basta fecha_asignacion: la carga del Excel la llena siempre).
    const sinGestionar = Number(item.sin_gestionar) === 1 || (rawSt === 'pendiente' && !item.rescate_estado && !Number(item.es_asignacion_partner));
    return {
      id: Number(item.id),
      codigo: item.codigo_supervision,
      creado: new Date(String(item.fecha_registro || '').replace(' ', 'T')).getTime() || Date.now(),
      fecha: item.fecha_registro ? item.fecha_registro.slice(0,10) : todayISO(),
      departamento: item.departamento || '', provincia: item.provincia || '', distrito: item.distrito || '', // sin "LIMA" inventado: una supervisión recién creada aún no tiene ubicación
      ubicacion: `${item.departamento || '—'} / ${item.distrito || '—'}`,
      direccion: item.direccion || '', // dirección del cliente (carga de cancelaciones, 2026-10-15)
      responsable: sinGestionar ? 'Aún no gestionado' : (item.supervisor_nombre || 'Supervisor WIN'), supervisor_id: String(item.supervisor_id || ''),
      asignado_por: String(item.asignado_por || ''), fecha_asignacion: item.fecha_asignacion || '',
      es_asignacion_partner: Number(item.es_asignacion_partner || 0), fecha_limite_partner: item.fecha_limite_partner || '',
      plazo_partner_vencido: item.plazo_partner_vencido || 0, estado_atencion_partner: item.estado_atencion_partner || 'Pendiente',
      observacion_atencion_partner: item.observacion_atencion_partner || '', fecha_atencion_partner: item.fecha_atencion_partner || '',
      clasificacion: item.clasificacion_supervision || item.tipo_servicio || 'Instalación', derivacion: item.derivacion_orden || 'Autogestión',
      tipo_servicio: item.tipo_servicio || 'Regular', area_servicio: item.area_servicio || 'Instalación', sub_tipo_atencion: item.sub_tipo_atencion || '',
      tipo_supervision_inicial: item.tipo_supervision_inicial || 'En caliente', partner_nombre: item.nombre_partner || '—', partner_id: item.partner_id,
      rescate_estado: item.rescate_estado || '', rescate_inicio: item.rescate_inicio || '', rescate_fin: item.rescate_fin || '',
      rescate_responsable_id: String(item.rescate_responsable_id || ''), partner_cancelacion_incorrecta: Number(item.partner_cancelacion_incorrecta || 0),
      cliente_nombre: item.cliente_nombre || '', cliente_dni: item.cliente_dni || '', ticket_codigo: item.ticket_codigo || '',
      tecnico1_nombre: item.tecnico1_nombre || '', tecnico1_dni: item.tecnico1_dni || '', tecnico2_nombre: item.tecnico2_nombre || '',
      tecnico2_dni: item.tecnico2_dni || '', brevete_poseedor: item.brevete_poseedor || '', unidad_tipo: item.unidad_tipo || '',
      unidad_placa: item.unidad_placa || '', unidad_soat: item.unidad_soat || '', unidad_revision_tecnica: item.unidad_revision_tecnica || '',
      unidad_estado: item.unidad_estado_conservacion || '', estado_supervision: item.estado_supervision || '',
      estado_servicio_caliente: ['Culminado con supervisor','Finalizado'].includes(item.estado_servicio_caliente) ? 'Finalizada' : (item.estado_servicio_caliente === 'Cancelado' ? 'Cancelada' : (item.estado_servicio_caliente || '')),
      tecnico1_id: item.tecnico1_id, tecnico2_id: item.tecnico2_id, es_cancelacion: Number(item.es_cancelacion || 0),
      origen_importacion: Number(item.origen_importacion || 0), observacion_previa: item.observacion_previa || '', estado_cancelacion: item.estado_cancelacion || '',
      fecha_cancelacion: item.fecha_cancelacion || '', tipificacion_cancelacion: item.tipificacion_cancelacion || '',
      tipificacion_corregida: item.tipificacion_corregida_supervisor || '', tipificacion_tecnico_correcta: item.tipificacion_tecnico_correcta,
      latitud: item.latitud || '', longitud: item.longitud || '', foto_servicio_1: item.foto_servicio_1 || '',
      foto_servicio_comentario_1: item.foto_servicio_comentario_1 || '', foto_servicio_2: item.foto_servicio_2 || '',
      foto_servicio_comentario_2: item.foto_servicio_comentario_2 || '', foto_servicio_3: item.foto_servicio_3 || '',
      foto_servicio_comentario_3: item.foto_servicio_comentario_3 || '', estado: st, observaciones: item.observacion_servicio || ''
    };
  }

  // Carga inicial breve y páginas adicionales bajo demanda. Los filtros viajan
  // al servidor para que resultados y contadores representen todo el historial.
  async function loadRecords({append = false} = {}){
    const requestedPage = append ? currentPage + 1 : 1;
    if (recordsRequest) recordsRequest.abort();
    recordsRequest = new AbortController();
    const params = new URLSearchParams({action:'list', page_number:String(requestedPage), limit:'50', estado:currentFilter});
    if (!append && initialHighlightId && currentFilter === 'todos' && !currentZoneFilter && !currentSupervisorFilter && !currentDateFromFilter && !currentDateToFilter && !currentCodeFilter) {
      params.set('highlight', String(initialHighlightId));
    }
    if (currentZoneFilter) params.set('departamento', currentZoneFilter);
    if (currentCodeFilter) params.set('codigo', currentCodeFilter);
    if (currentSupervisorFilter) params.set('supervisor_id', currentSupervisorFilter);
    if (currentDateFromFilter) params.set('fecha_inicio', currentDateFromFilter);
    if (currentDateToFilter) params.set('fecha_fin', currentDateToFilter);
    const moreButton = document.getElementById('loadMoreSupervisions');
    try {
      if (!append) headerMeta.textContent = 'Cargando datos...';
      if (moreButton) { moreButton.disabled = true; moreButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Cargando…'; }
      const res = await fetch(`api/supervisiones.php?${params}`, {signal:recordsRequest.signal});
      const data = await res.json();
      if (!res.ok || data.status !== 'success' || !Array.isArray(data.data)) throw new Error(data.message || 'No se pudo cargar el listado');
      const nextRecords = data.data.map(normalizeSupervisionRecord);
      if (append) {
        const byId = new Map(records.map(record => [record.id, record]));
        nextRecords.forEach(record => byId.set(record.id, record));
        records = [...byId.values()];
      } else records = nextRecords;
      const meta = data.meta || {};
      currentPage = Number(meta.page || requestedPage);
      listHasMore = Boolean(meta.has_more);
      listTotal = Number(meta.total || 0);
      listCounts = {...listCounts, ...(meta.counts || {})};
      activeSupervisionMeta = meta.active_supervision || null;
      window.pendingMapTotal = Number(meta.pending_map_total || 0);
      populateAdminSupervisionFilters(meta);
      // Llegada desde el mapa del Dashboard (?highlight=ID&abrir=1): se abre esa supervisión una sola vez.
      if (!append && initialHighlightId && abrirAlCargar) {
        abrirAlCargar = false;
        const destino = records.find(r => Number(r.id) === initialHighlightId);
        if (destino) setTimeout(() => abrirRegistro(destino), 0);
        else showToast('No tiene acceso a esa supervisión o ya no existe.');
      }
    } catch(e) {
      if (e.name === 'AbortError') return;
      if (!append) {
        records = [];
        listTotal = 0;
        listHasMore = false;
        listCounts = {todos:0,iniciada:0,finalizada:0,pendiente:0};
      }
      showToast('No se pudo cargar el listado de supervisiones.');
    } finally {
      if (moreButton) { moreButton.disabled = false; moreButton.innerHTML = '<i class="bi bi-arrow-down-circle me-1"></i>Cargar más registros'; }
    }
    renderList();
  }

  // SELECCIONADOR DE COLUMNAS CONFIGURABLES
  const ALL_SUPERVISION_COLUMNS = [
    { id: 'codigo', label: 'Código Pedido / Ticket', default: true },
    { id: 'clasificacion', label: 'Tipo Supervisión', default: true },
    { id: 'fecha', label: 'Fecha', default: true },
    { id: 'responsable', label: 'Responsable', default: true },
    { id: 'departamento', label: 'Departamento', default: true },
    { id: 'estado', label: 'Estado', default: true },
    { id: 'partner_nombre', label: 'Partner / Contrata', default: false },
    { id: 'tecnico1_nombre', label: 'Técnico Líder', default: false },
    { id: 'unidad_placa', label: 'Placa Vehículo', default: false }
  ];

  // El lado del partner no tiene las columnas de partner, técnico ni placa (2026-10-27: el servidor no envía esos datos)
  const COLUMNAS_SIN_PARTNER = ['partner_nombre', 'tecnico1_nombre', 'unidad_placa'];
  function getActiveColumns() {
    let cols = null;
    try {
      const saved = JSON.parse(localStorage.getItem('win_supervisiones_active_cols'));
      if (Array.isArray(saved) && saved.length > 0) cols = saved;
    } catch (_) {}
    cols = cols || ALL_SUPERVISION_COLUMNS.filter(c => c.default).map(c => c.id);
    return esSupervisorPartnerActual ? cols.filter(c => !COLUMNAS_SIN_PARTNER.includes(c)) : cols;
  }

  function saveActiveColumns(cols) {
    try {
      localStorage.setItem('win_supervisiones_active_cols', JSON.stringify(cols));
    } catch (_) {}
  }

  function initColumnSelector() {
    const activeCols = getActiveColumns();
    document.querySelectorAll('.col-toggle-chk').forEach(chk => {
      chk.checked = activeCols.includes(chk.dataset.col);
      chk.addEventListener('change', () => {
        const newCols = [...document.querySelectorAll('.col-toggle-chk:checked')].map(c => c.dataset.col);
        if (newCols.length === 0) {
          showToast('Debe mantener al menos una columna visible.');
          chk.checked = true;
          return;
        }
        saveActiveColumns(newCols);
        renderList();
      });
    });

    document.getElementById('btnResetColumns')?.addEventListener('click', () => {
      const defaultCols = ALL_SUPERVISION_COLUMNS.filter(c => c.default).map(c => c.id);
      saveActiveColumns(defaultCols);
      document.querySelectorAll('.col-toggle-chk').forEach(chk => {
        chk.checked = defaultCols.includes(chk.dataset.col);
      });
      renderList();
    });
  }

  // ---------- VISTA 1: LISTADO DE REGISTROS CON FILTROS ----------
  function renderList(){
    updateFolioPreview();
    const baseRecords = records;
    const countTodos = Number(listCounts.todos || 0);
    const countIniciada = Number(listCounts.iniciada || 0);
    const countFinalizada = Number(listCounts.finalizada || 0);
    const countPendiente = Number(listCounts.pendiente || 0);

    const activeForCurrentSupervisor = activeSupervisionMeta;
    if (toggleNewBtn) {
      toggleNewBtn.disabled = false;
      toggleNewBtn.innerHTML = activeForCurrentSupervisor
        ? '<i class="bi bi-arrow-right-circle me-1"></i>Continuar supervisión'
        : '<i class="bi bi-plus-lg me-1"></i>Nueva supervisión';
      toggleNewBtn.title = activeForCurrentSupervisor
        ? `Tiene la supervisión ${activeForCurrentSupervisor.ticket_codigo || activeForCurrentSupervisor.codigo_supervision} en proceso: se abre para continuar.`
        : 'Crear una nueva supervisión';
    }

    const countValues = {todos:countTodos,iniciada:countIniciada,finalizada:countFinalizada,pendiente:countPendiente,rescate:Number(listCounts.rescate || 0),
      partner_pendiente:Number(listCounts.partner_pendiente || 0),partner_en_proceso:Number(listCounts.partner_en_proceso || 0),partner_vencida:Number(listCounts.partner_vencida || 0),
      partner_finalizada:Number(listCounts.partner_finalizada || 0),asignada_partner:Number(listCounts.asignada_partner || 0),por_validar:Number(listCounts.por_validar || 0)};
    Object.entries(countValues).forEach(([key,value]) => {
      const target = document.getElementById(`count-${key}`);
      if (target) target.textContent = value;
    });

    headerMeta.textContent = listTotal > baseRecords.length
      ? `Mostrando ${baseRecords.length} de ${listTotal} registros`
      : `${listTotal} registro${listTotal===1?'':'s'} en bitácora`;
    const routesButton = document.getElementById('viewPendingRoutesBtn');
    if (routesButton) {
      const pendingRouteCount = Number(window.pendingMapTotal || 0);
      routesButton.disabled = pendingRouteCount === 0;
      routesButton.title = pendingRouteCount
        ? `Ver en el mapa ${pendingRouteCount} orden${pendingRouteCount === 1 ? '' : 'es'} pendiente${pendingRouteCount === 1 ? '' : 's'}`
        : 'No hay órdenes pendientes para mostrar';
    }

    // Filtrar registros según pestaña activa
    const filtered = [...baseRecords];

    const loadMoreWrap = document.getElementById('loadMoreSupervisionsWrap');
    if (loadMoreWrap) loadMoreWrap.classList.toggle('d-none', !listHasMore);

    const activeCols = getActiveColumns();
    const thead = document.getElementById('thead-supervisiones');
    if (thead) {
      let trHead = '<tr><th style="width: 24px;"></th>';
      ALL_SUPERVISION_COLUMNS.forEach(col => {
        if (activeCols.includes(col.id)) {
          trHead += `<th>${col.label}</th>`;
        }
      });
      trHead += '<th class="text-center" style="width: 70px;">Acciones</th></tr>';
      thead.innerHTML = trHead;
    }

    if(filtered.length === 0){
      tbody.innerHTML = `<tr><td colspan="${activeCols.length + 2}" class="empty-bitacora">No se encontraron supervisiones para el filtro seleccionado.</td></tr>`;
      const mobileContainer = document.getElementById('mobile-records-container');
      if (mobileContainer) {
        mobileContainer.innerHTML = `<div class="empty-bitacora">No se encontraron supervisiones para el filtro seleccionado.</div>`;
      }
      return;
    }

    const sorted = filtered.sort((a,b)=> b.creado - a.creado);
    tbody.innerHTML = '';
    const mobileContainer = document.getElementById('mobile-records-container');
    if (mobileContainer) mobileContainer.innerHTML = '';
    const renderMobileLayout = window.matchMedia('(max-width: 767.98px)').matches;

    sorted.forEach(rec => {
      const codigoDisplay = rec.ticket_codigo ? rec.ticket_codigo : rec.codigo;
      let labelEstado = rec.estado_supervision || 'En proceso';
      if (['Culminada','Finalizado'].includes(labelEstado)) labelEstado = 'Finalizada';
      if (rec.estado === 'rescate') labelEstado = 'En rescate';
      else if (['Rescatado', 'No rescatado'].includes(rec.rescate_estado)) labelEstado = `Finalizada · ${rec.rescate_estado}`;

      // Se crea únicamente la variante visible para no duplicar el DOM.
      if (!renderMobileLayout) {
      // 1. RENDER DESKTOP ROW SEGÚN COLUMNAS SELECCIONADAS
      const row = document.createElement('tr');
      row.className = 'row-bitacora';
      let rowHtml = '<td class="col-caret-bitacora">›</td>';

      ALL_SUPERVISION_COLUMNS.forEach(col => {
        if (!activeCols.includes(col.id)) return;
        switch (col.id) {
          case 'codigo':
            rowHtml += `<td class="folio-bitacora">${escapeHtml(codigoDisplay)}</td>`;
            break;
          case 'clasificacion':
            rowHtml += `<td>${escapeHtml(rec.clasificacion || rec.area_servicio || 'Instalación')}${rec.sub_tipo_atencion ? `<br><small class="text-muted">${escapeHtml(rec.sub_tipo_atencion)}</small>` : ''}</td>`;
            break;
          case 'fecha':
            rowHtml += `<td>${fmtDate(rec.fecha)}</td>`;
            break;
          case 'responsable':
            rowHtml += `<td>${escapeHtml(rec.responsable)}</td>`;
            break;
          case 'departamento':
            rowHtml += `<td>${escapeHtml(rec.departamento)}</td>`;
            break;
          case 'estado':
            rowHtml += `<td><span class="badge-exec ${rec.estado}">${labelEstado}</span>${rec.es_asignacion_partner?`<br><small class="${rec.estado_atencion_partner==='Atendida'?'text-success':(Number(rec.plazo_partner_vencido)?'text-danger':'text-warning')}">${rec.estado_atencion_partner==='Atendida'?`Partner atendió · cancelación ${rec.partner_cancelacion_incorrecta===1?'incorrecta':'correcta'}`:(Number(rec.plazo_partner_vencido)?'Partner no atendió · plazo vencido':'Partner pendiente · 24 h')}</small>`:''}${rec.es_asignacion_partner && rec.estado_atencion_partner==='Atendida' && rec.estado==='pendiente' && !rec.rescate_estado ? '<br><small class="text-primary fw-bold"><i class="bi bi-check2-square me-1"></i>Por validar</small>' : ''}</td>`;
            break;
          case 'partner_nombre':
            rowHtml += `<td>${escapeHtml(rec.partner_nombre || 'WIN Directo')}</td>`;
            break;
          case 'tecnico1_nombre':
            rowHtml += `<td>${escapeHtml(rec.tecnico1_nombre || 'Sin registrar')}</td>`;
            break;
          case 'unidad_placa':
            rowHtml += `<td>${escapeHtml(rec.unidad_placa || '—')}</td>`;
            break;
        }
      });

      rowHtml += `
        <td class="text-center" onclick="event.stopPropagation();"><div class="sgs-row-actions">
          ${puedeAsignarPartner && rec.estado === 'pendiente' ? `<button type="button" class="sgs-icon-action sgs-icon-action--primary" title="Asignar a supervisor partner" aria-label="Asignar a supervisor partner" onclick="asignarOrdenPartner(${rec.id}, '${escapeHtml(codigoDisplay)}')"><i class="bi bi-person-check-fill"></i></button>` : ''}
          ${esAdministradorActual ? `<button type="button" class="sgs-icon-action sgs-icon-action--danger" title="Eliminar supervisión" aria-label="Eliminar supervisión" onclick="eliminarSupervision(${rec.id}, '${rec.codigo}')"><i class="bi bi-trash3"></i></button>` : ''}
        </div></td>
      `;
      row.innerHTML = rowHtml;
      row.addEventListener('click', ()=> abrirRegistro(rec));
      tbody.appendChild(row);
      }

      // 2. RENDER MOBILE HORIZONTAL CARD
      if (mobileContainer && renderMobileLayout) {
        const card = document.createElement('div');
        card.className = 'mobile-supervision-card';
        card.innerHTML = `
          <div class="mobile-card-header">
            <div class="mobile-card-title-group">
              <span class="mobile-card-folio">${escapeHtml(codigoDisplay)}</span>
              <span class="badge-exec ${rec.estado}">${labelEstado}</span>
            </div>
            <i class="bi bi-chevron-right mobile-card-arrow"></i>
          </div>
          <div class="mobile-card-body">
            <div class="mobile-card-row">
              <div class="mobile-card-cell">
                <span class="mobile-card-label"><i class="bi bi-gear-wide-connected"></i> Gestión</span>
                <span class="mobile-card-val">${escapeHtml(rec.clasificacion || rec.area_servicio || 'Instalación')}</span>
              </div>
              <div class="mobile-card-cell">
                <span class="mobile-card-label"><i class="bi bi-calendar3"></i> Fecha</span>
                <span class="mobile-card-val">${fmtDate(rec.fecha)}</span>
              </div>
            </div>
            <div class="mobile-card-row">
              <div class="mobile-card-cell">
                <span class="mobile-card-label"><i class="bi bi-person"></i> Responsable</span>
                <span class="mobile-card-val">${escapeHtml(rec.responsable)}</span>
              </div>
              <div class="mobile-card-cell">
                <span class="mobile-card-label"><i class="bi bi-geo-alt"></i> Dpto</span>
                <span class="mobile-card-val">${escapeHtml(rec.departamento)}</span>
              </div>
            </div>
            ${rec.sub_tipo_atencion ? `
            <div class="mobile-card-subinfo">
              <i class="bi bi-info-circle me-1"></i> ${escapeHtml(rec.sub_tipo_atencion)}
            </div>` : ''}
          </div>
          ${(puedeAsignarPartner && rec.estado === 'pendiente') || esAdministradorActual ? `
          <div class="mobile-card-footer" onclick="event.stopPropagation();">
            ${puedeAsignarPartner && rec.estado === 'pendiente' ? `
              <button type="button" class="btn-mobile-action btn-mobile-assign" onclick="asignarOrdenPartner(${rec.id}, '${escapeHtml(codigoDisplay)}')">
                <i class="bi bi-person-check-fill me-1"></i> Asignar Partner
              </button>` : ''}
            ${esAdministradorActual ? `
              <button type="button" class="btn-mobile-action btn-mobile-delete" onclick="eliminarSupervision(${rec.id}, '${rec.codigo}')">
                <i class="bi bi-trash3-fill me-1"></i> Eliminar
              </button>` : ''}
          </div>` : ''}
        `;
        card.addEventListener('click', ()=> abrirRegistro(rec));
        mobileContainer.appendChild(card);
      }
    });
  }

  window.asignarOrdenPartner = async function(supervisionId, codigo) {
    try {
      const response = await fetch(`api/supervisiones.php?action=supervisores_partner&supervision_id=${encodeURIComponent(supervisionId)}`);
      const result = await response.json();
      if (result.status !== 'success') { showToast(result.message || 'No se pudo consultar los supervisores disponibles.'); return; }
      let modal = document.getElementById('modalAsignarPartner');
      if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalAsignarPartner'; modal.className = 'modal fade'; modal.tabIndex = -1;
        modal.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header bg-dark text-white"><h5 class="modal-title"><i class="bi bi-person-check-fill me-2 text-warning"></i>Asignar orden a Partner</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border small mb-3"><div>Orden: <strong id="assignOrderCode"></strong></div><div>Departamento: <strong id="assignOrderDepartment"></strong></div><div>Partner: <strong id="assignOrderPartner"></strong></div></div><label class="form-label fw-bold">Supervisor disponible *</label><select id="assignPartnerSupervisor" class="form-select"></select><small id="assignPartnerHelp" class="text-muted d-block mt-2"></small></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" id="confirmAssignPartner" class="btn btn-win">Confirmar asignación</button></div></div></div>`;
        document.body.appendChild(modal);
      }
      document.getElementById('assignOrderCode').textContent = codigo;
      document.getElementById('assignOrderDepartment').textContent = result.departamento || '—';
      document.getElementById('assignOrderPartner').textContent = result.partner || '—';
      const select = document.getElementById('assignPartnerSupervisor');
      const available = Array.isArray(result.data) ? result.data : [];
      select.disabled = available.length === 0;
      select.innerHTML = available.length
        ? '<option value="">Seleccione supervisor</option>' + available.map(s => `<option value="${Number(s.id)}">${escapeHtml(s.nombre_completo)} (${escapeHtml(s.dni)})</option>`).join('')
        : '<option value="">No hay supervisores compatibles</option>';
      document.getElementById('assignPartnerHelp').textContent = available.length
        ? 'Solo aparecen supervisores activos del partner con acceso a este departamento.'
        : `No existe un supervisor activo de ${result.partner || 'este partner'} habilitado para ${result.departamento || 'este departamento'}.`;
      const instance = bootstrap.Modal.getOrCreateInstance(modal);
      const confirmButton = document.getElementById('confirmAssignPartner');
      confirmButton.disabled = available.length === 0;
      document.getElementById('confirmAssignPartner').onclick = async () => {
        if (!select.value) { showToast('Seleccione un supervisor disponible.'); return; }
        const button = document.getElementById('confirmAssignPartner'); button.disabled = true;
        const body = new FormData(); body.append('supervision_id', supervisionId); body.append('supervisor_partner_id', select.value);
        try {
          const saveResponse = await fetch('api/supervisiones.php?action=asignar_partner', {method:'POST', body});
          const saveResult = await saveResponse.json();
          showToast(saveResult.message || 'Asignación procesada.');
          if (saveResult.status === 'success') { instance.hide(); await loadRecords(); }
        } finally { button.disabled = false; }
      };
      instance.show();
    } catch (error) { console.error(error); showToast('No se pudo cargar la lista de supervisores partner.'); }
  };

  function populateAdminSupervisionFilters(meta = {}) {
    const zonaSelect = document.getElementById('adminFilterZona');
    const supervisorSelect = document.getElementById('adminFilterSupervisor');
    if (!zonaSelect || !supervisorSelect) return;
    const zonas = Array.isArray(meta.departamentos) ? meta.departamentos : [];
    const supervisores = Array.isArray(meta.supervisores)
      ? meta.supervisores.map(item => [String(item.id), item.nombre_completo])
      : [];
    zonaSelect.innerHTML = '<option value="">Todos los departamentos</option>' + zonas.map(z => `<option value="${escapeHtml(z)}">${escapeHtml(z)}</option>`).join('');
    supervisorSelect.innerHTML = '<option value="">Todos los supervisores</option>' + supervisores.map(([id,nombre]) => `<option value="${id}">${escapeHtml(nombre)}</option>`).join('');
    zonaSelect.value = currentZoneFilter;
    supervisorSelect.value = currentSupervisorFilter;
  }

  let pendingMap = null;
  let pendingMarkers = null;
  let supervisorLocationMarker = null;
  let pendingFans = [];
  // Abanico: separa en pantalla (unos 34 px del punto real) los marcadores que comparten ubicación. Se recalcula al hacer zoom
  // porque la separación es en píxeles, no en metros.
  function acomodarAbanicosPendientes() {
    if (!pendingMap || !pendingMap._loaded) return; // sin vista aún: se acomoda al ubicar el mapa
    pendingFans.forEach(fan => {
      const n = fan.markers.length;
      const base = pendingMap.latLngToLayerPoint(fan.centro);
      fan.markers.forEach((marker, i) => {
        const ang = -Math.PI / 2 + (2 * Math.PI * i) / n;
        const punto = pendingMap.layerPointToLatLng(L.point(base.x + 34 * Math.cos(ang), base.y + 34 * Math.sin(ang)));
        marker.setLatLng(punto);
        fan.legs[i]?.setLatLngs([fan.centro, punto]);
      });
    });
  }

  function distanceInKm(origin, point) {
    const radians = degrees => degrees * Math.PI / 180;
    const earthRadiusKm = 6371;
    const deltaLat = radians(point.lat - origin.lat);
    const deltaLng = radians(point.lng - origin.lng);
    const value = Math.sin(deltaLat / 2) ** 2
      + Math.cos(radians(origin.lat)) * Math.cos(radians(point.lat)) * Math.sin(deltaLng / 2) ** 2;
    return earthRadiusKm * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
  }

  function getSupervisorLocation() {
    return new Promise(resolve => {
      if (!navigator.geolocation) { resolve(null); return; }
      navigator.geolocation.getCurrentPosition(
        position => resolve({lat:position.coords.latitude, lng:position.coords.longitude}),
        () => resolve(null),
        {enableHighAccuracy:true, timeout:7000, maximumAge:60000}
      );
    });
  }

  function renderPendingMap(supervisorLocation = null) {
    const summary = document.getElementById('pendingMapSummary');
    const missing = document.getElementById('pendingMapMissing');
    const routeLink = document.getElementById('pendingRouteLink');
    const pending = pendingMapRecords;

    let points = pending
      .filter(record => String(record.latitud || '').trim() !== '' && String(record.longitud || '').trim() !== '')
      .map(record => ({record, lat:Number(record.latitud), lng:Number(record.longitud)}))
      .filter(point => Number.isFinite(point.lat) && point.lat >= -90 && point.lat <= 90 && Number.isFinite(point.lng) && point.lng >= -180 && point.lng <= 180);
    if (supervisorLocation) {
      points = points
        .map(point => ({...point, distance:distanceInKm(supervisorLocation, point)}))
        .sort((a, b) => a.distance - b.distance);
    }
    if (summary) {
      summary.textContent = points.length
        ? `${points.length} pendiente${points.length === 1 ? '' : 's'} con GPS. ${supervisorLocation ? 'La número 1 es la más cercana.' : 'Toca un punto para abrir la orden.'}`
        : 'No hay órdenes pendientes con ubicación GPS.';
    }
    if (missing) missing.textContent = pending.length > points.length ? `${pending.length - points.length} orden(es) aún no tienen coordenadas y no pueden mostrarse en el mapa.` : '';
    if (typeof L === 'undefined') {
      if (missing) missing.textContent = 'No se pudo cargar el mapa. Verifique la conexión a internet.';
      return;
    }
    if (!pendingMap) {
      pendingMap = L.map('pending-map').setView([-9.19, -75.015], 6);
      L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
        maxZoom:19,
        attribution:'Tiles &copy; Esri'
      }).addTo(pendingMap);
      pendingMarkers = L.layerGroup().addTo(pendingMap);
      pendingMap.on('zoomend', acomodarAbanicosPendientes);
    }
    pendingMarkers.clearLayers();
    const bounds = [];
    if (supervisorLocation) {
      supervisorLocationMarker = L.circleMarker([supervisorLocation.lat, supervisorLocation.lng], {
        radius:9, color:'#0d6efd', weight:3, fillColor:'#fff', fillOpacity:1
      }).bindTooltip('Tu ubicación', {permanent:true, direction:'right'});
      pendingMarkers.addLayer(supervisorLocationMarker);
      bounds.push([supervisorLocation.lat, supervisorLocation.lng]);
    }
    const openFromMap = (id) => {
      const modalElement = document.getElementById('pendingRoutesModal');
      const modal = bootstrap.Modal.getInstance(modalElement);
      modalElement.addEventListener('hidden.bs.modal', () => openDetail(id), {once:true});
      modal?.hide();
    };
    // Órdenes en el mismo punto (a ~10 m): cada una con su propio marcador, abiertas en abanico alrededor del punto real
    // y unidas a él con una línea fina (2026-10-13). Antes se juntaban en un solo marcador "1 · 2".
    const groups = [];
    points.forEach((point, index) => {
      const key = `${point.lat.toFixed(4)},${point.lng.toFixed(4)}`;
      let group = groups.find(g => g.key === key);
      if (!group) { group = {key, lat:point.lat, lng:point.lng, items:[]}; groups.push(group); }
      group.items.push({...point, number:index + 1});
    });
    pendingFans = [];
    const colorAbanico = getComputedStyle(document.documentElement).getPropertyValue('--sgs-muted').trim() || 'gray';
    groups.forEach(group => {
      const centro = L.latLng(group.lat, group.lng);
      const fan = {centro, markers:[], legs:[]};
      if (group.items.length > 1) {
        pendingMarkers.addLayer(L.circleMarker(centro, {radius:4, color:colorAbanico, weight:1, fillColor:colorAbanico, fillOpacity:1, interactive:false}));
      }
      group.items.forEach(item => {
        const code = item.record.ticket_codigo || item.record.codigo;
        const distanceLabel = Number.isFinite(item.distance) ? ` · ${item.distance.toFixed(1)} km` : '';
        const marker = L.marker(centro, {title:`${code}${distanceLabel}${item.record.direccion ? ' · ' + item.record.direccion : ''}`});
        marker.bindTooltip(`${item.number}${item.number === 1 && supervisorLocation ? ' · Más cercana' : ''}`, {permanent:true, direction:'top', offset:[0,-10]});
        marker.on('click', () => openFromMap(item.record.id));
        pendingMarkers.addLayer(marker);
        fan.markers.push(marker);
        if (group.items.length > 1) {
          const leg = L.polyline([centro, centro], {color:colorAbanico, weight:1, dashArray:'3 3', interactive:false});
          pendingMarkers.addLayer(leg);
          fan.legs.push(leg);
        }
      });
      if (group.items.length > 1) pendingFans.push(fan);
      bounds.push([group.lat, group.lng]);
    });
    acomodarAbanicosPendientes();
    setTimeout(() => {
      pendingMap.invalidateSize();
      if (supervisorLocation) {
        pendingMap.setView([supervisorLocation.lat, supervisorLocation.lng], 16);
      } else if (bounds.length) {
        pendingMap.fitBounds(bounds, {padding:[30,30], maxZoom:15});
      } else {
        pendingMap.setView([-9.19,-75.015], 6);
      }
      acomodarAbanicosPendientes();
    }, 0);
    if (routeLink && points.length) {
      const coordinates = points.map(point => `${point.lat},${point.lng}`);
      const origin = coordinates[0];
      const destination = coordinates[coordinates.length - 1];
      const waypoints = coordinates.slice(1,-1).join('|');
      routeLink.href = `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}${waypoints ? `&waypoints=${encodeURIComponent(waypoints)}` : ''}`;
      routeLink.style.display = 'inline-flex';
    } else if (routeLink) routeLink.style.display = 'none';
  }

  document.getElementById('viewPendingRoutesBtn')?.addEventListener('click', async () => {
    const modalElement = document.getElementById('pendingRoutesModal');
    const summary = document.getElementById('pendingMapSummary');
    if (!modalElement) return;
    if (summary) summary.textContent = 'Obteniendo tu ubicación para encontrar la orden más cercana…';
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    modal.show();
    try {
      const [supervisorLocation, response] = await Promise.all([
        getSupervisorLocation(),
        fetch('api/supervisiones.php?action=pending_map')
      ]);
      const result = await response.json();
      if (!response.ok || result.status !== 'success' || !Array.isArray(result.data)) throw new Error(result.message || 'Mapa no disponible');
      pendingMapRecords = result.data.map(item => ({
        id:Number(item.id), codigo:item.codigo_supervision || '', ticket_codigo:item.ticket_codigo || '',
        latitud:item.latitud || '', longitud:item.longitud || '', departamento:item.departamento || '', distrito:item.distrito || '', direccion:item.direccion || ''
      }));
      renderPendingMap(supervisorLocation);
    } catch (error) {
      if (summary) summary.textContent = 'No se pudieron cargar las órdenes pendientes.';
      const missing = document.getElementById('pendingMapMissing');
      if (missing) missing.textContent = 'Revise la conexión e inténtelo nuevamente.';
    }
  });

  document.getElementById('pendingRoutesModal')?.addEventListener('shown.bs.modal', () => {
    setTimeout(() => pendingMap?.invalidateSize(), 0);
  });

  document.getElementById('adminFilterZona')?.addEventListener('change', e => { currentZoneFilter = e.target.value; loadRecords(); });
  document.getElementById('adminFilterSupervisor')?.addEventListener('change', e => { currentSupervisorFilter = e.target.value; loadRecords(); });
  document.getElementById('adminFilterFechaDesde')?.addEventListener('change', e => { currentDateFromFilter = e.target.value; loadRecords(); });
  document.getElementById('adminFilterFechaHasta')?.addEventListener('change', e => { currentDateToFilter = e.target.value; loadRecords(); });
  // Búsqueda por pedido o ticket: espera a que se deje de escribir para no consultar por cada tecla
  let codeFilterTimer = null;
  document.getElementById('filterCodigoSup')?.addEventListener('input', e => {
    clearTimeout(codeFilterTimer);
    codeFilterTimer = setTimeout(() => {
      const valor = e.target.value.trim();
      if (valor === currentCodeFilter) return;
      currentCodeFilter = valor;
      loadRecords();
    }, 350);
  });
  document.getElementById('btnLimpiarFiltrosSup')?.addEventListener('click', () => {
    ['filterCodigoSup','adminFilterZona','adminFilterSupervisor','adminFilterFechaDesde','adminFilterFechaHasta'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    currentZoneFilter = ''; currentSupervisorFilter = ''; currentDateFromFilter = ''; currentDateToFilter = ''; currentCodeFilter = '';
    loadRecords();
  });

  // Manejador de Pestañas de Filtro
  if (filterTabs) {
    filterTabs.addEventListener('click', (e) => {
      const btn = e.target.closest('.filter-tab-btn');
      if (!btn) return;
      
      filterTabs.querySelectorAll('.filter-tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentFilter = btn.dataset.filter || 'todos';
      loadRecords();
    });
  }

  // ---------- VISTA 3: PANTALLA DE DETALLE Y EDICIÓN (MISMA VENTANA) ----------
  function initChecklistSignaturePads(root=document) {
    root.querySelectorAll('.checklist-signature-pad:not([data-ready])').forEach(canvas => {
      canvas.dataset.ready='1'; const ctx=canvas.getContext('2d'); ctx.strokeStyle='#172033';ctx.lineWidth=2.2;ctx.lineCap='round';ctx.lineJoin='round';let drawing=false;
      const point=e=>{const r=canvas.getBoundingClientRect();return {x:(e.clientX-r.left)*(canvas.width/r.width),y:(e.clientY-r.top)*(canvas.height/r.height)}};
      canvas.addEventListener('pointerdown',e=>{drawing=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y)});
      canvas.addEventListener('pointermove',e=>{if(!drawing)return;const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke()});
      const finish=()=>{if(!drawing)return;drawing=false;const input=document.getElementById(canvas.id.replace('_canvas','_data'));if(input){input.value=canvas.toDataURL('image/png');input.dispatchEvent(new Event('input',{bubbles:true}))}};
      canvas.addEventListener('pointerup',finish);canvas.addEventListener('pointercancel',finish);
    });
  }
  document.addEventListener('click',event=>{const button=event.target.closest('.checklist-signature-clear');if(!button)return;const canvas=document.getElementById(button.dataset.canvas);const input=document.getElementById(button.dataset.input);if(canvas)canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);if(input){input.value='';input.dispatchEvent(new Event('input',{bubbles:true}))}});
  initChecklistSignaturePads();

  // Corregir una pendiente cargada (2026-10-27): quien carga cancelaciones corrige los datos del Excel sin iniciarla
  // (api/supervisiones.php?action=corregir_pendiente). Ventana #modalCorregirPendiente (supervision_lista.php).
  async function abrirCorreccionPendiente(rec) {
    const modal = document.getElementById('modalCorregirPendiente');
    const $ = (id) => document.getElementById(id);
    const opciones = (lista, actual) => lista.map(v => `<option value="${escapeHtml(v)}" ${v === actual ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('');
    const pedir = async (url) => { const r = await (await fetch(url)).json(); return r.status === 'success' ? r.data : []; };
    const cargarProvincias = async (prov) => {
      $('cp_prov').innerHTML = '<option value="">Seleccione</option>' + opciones(await pedir(`api/ubigeo.php?action=provincias&departamento=${encodeURIComponent($('cp_dep').value)}`), prov);
    };
    const cargarDistritos = async (dist) => {
      $('cp_dist').innerHTML = '<option value="">Seleccione</option>' + opciones(await pedir(`api/ubigeo.php?action=distritos&departamento=${encodeURIComponent($('cp_dep').value)}&provincia=${encodeURIComponent($('cp_prov').value)}`), dist);
    };
    // Cuadrilla: líderes del partner y la zona; "actual" deja la que vino del Excel aunque no esté registrada
    const cargarTecnicos = () => {
      const lideres = lideresDeZona($('cp_partner').value, $('cp_dep').value, rec.tecnico1_nombre, $('cp_prov').value);
      const registrado = lideres.find(t => t.nombre_tecnico === rec.tecnico1_nombre);
      $('cp_tecnico').innerHTML = (rec.tecnico1_nombre && !registrado ? `<option value="actual">${escapeHtml(rec.tecnico1_nombre)} (no registrado)</option>` : '')
        + '<option value="">Sin cuadrilla</option>'
        + lideres.map(t => `<option value="${t.id}" ${t === registrado ? 'selected' : ''}>${escapeHtml(textoLider(t))}</option>`).join('');
      if (rec.tecnico1_nombre && !registrado) $('cp_tecnico').value = 'actual';
    };
    $('cp_codigo').textContent = rec.codigo;
    $('cp_ticket').value = rec.ticket_codigo;
    $('cp_tipo').value = ['Instalación', 'Avería', 'Postventa', 'Reiterada', 'Garantía'].includes(rec.clasificacion) ? rec.clasificacion : 'Instalación';
    $('cp_partner').innerHTML = partnersData.map(p => `<option value="${p.id}" ${String(p.id) === String(rec.partner_id) ? 'selected' : ''}>${escapeHtml(p.nombre_partner)}</option>`).join('');
    $('cp_direccion').value = rec.direccion;
    $('cp_fecha').value = String(rec.fecha_cancelacion || '').slice(0, 10);
    $('cp_motivo').value = rec.tipificacion_cancelacion;
    $('cp_motivos').innerHTML = tipificacionesCancelacion.map(t => `<option value="${escapeHtml(t)}"></option>`).join('');
    $('cp_coordenadas').value = rec.latitud && rec.longitud ? `${rec.latitud}, ${rec.longitud}` : '';
    $('cp_obs').value = rec.observacion_previa;
    $('cp_error').classList.add('d-none');
    $('cp_dep').innerHTML = '<option value="">Seleccione</option>' + opciones(await pedir('api/ubigeo.php?action=departamentos'), rec.departamento);
    await cargarProvincias(rec.provincia);
    await cargarDistritos(rec.distrito);
    await cargarListaTecnicos();
    cargarTecnicos();
    $('cp_dep').onchange = async () => { await cargarProvincias(''); await cargarDistritos(''); cargarTecnicos(); };
    $('cp_prov').onchange = () => { cargarDistritos(''); cargarTecnicos(); };
    $('cp_partner').onchange = cargarTecnicos;
    $('formCorregirPendiente').onsubmit = async (ev) => {
      ev.preventDefault();
      const boton = $('cp_guardar');
      const datos = new FormData();
      Object.entries({ id: rec.id, ticket: $('cp_ticket').value, tipo: $('cp_tipo').value, partner_id: $('cp_partner').value, tecnico1: $('cp_tecnico').value,
        departamento: $('cp_dep').value, provincia: $('cp_prov').value, distrito: $('cp_dist').value, direccion: $('cp_direccion').value,
        fecha_cancelacion: $('cp_fecha').value, motivo: $('cp_motivo').value, coordenadas: $('cp_coordenadas').value, observacion_previa: $('cp_obs').value,
      }).forEach(([k, v]) => datos.append(k, v));
      boton.disabled = true;
      try {
        const r = await (await fetch('api/supervisiones.php?action=corregir_pendiente', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: datos })).json();
        if (r.status !== 'success') throw new Error(r.message || 'No se pudo guardar la corrección.');
        bootstrap.Modal.getOrCreateInstance(modal).hide();
        showToast(r.message);
        records = records.filter(x => x.id !== rec.id); // el detalle vuelve a leer la pendiente ya corregida
        await loadRecords();
        openDetail(rec.id);
      } catch (e) {
        $('cp_error').textContent = e.message || 'No se pudo guardar la corrección.';
        $('cp_error').classList.remove('d-none');
      } finally { boton.disabled = false; }
    };
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }
  async function openDetail(id, forceAdminEdit = false){
    let rec = records.find(r => r.id === Number(id));
    if (forceAdminEdit && !esAdministradorActual) forceAdminEdit = false;

    await Promise.all([cargarListaTecnicos(), cargarCatalogoObservaciones()]);

    // Obtener detalle extendido con observaciones vinculadas y fotos
    try {
      const res = await fetch(`api/supervisiones.php?action=detail&id=${id}`);
      const data = await res.json();
      if (!res.ok || data.status !== 'success') throw new Error(data.message || 'No se pudo abrir la supervisión');
      if (!rec && data.supervision) {
        rec = normalizeSupervisionRecord(data.supervision);
        records.push(rec);
      }
      if (!rec) throw new Error('La supervisión ya no está disponible');
      let obsVinculadas = [];
      if (Array.isArray(data.observaciones)) {
        obsVinculadas = data.observaciones;
      }
      if (data.supervision?.rescate_estado !== undefined) {
        rec.rescate_estado = data.supervision.rescate_estado || '';
        rec.rescate_inicio = data.supervision.rescate_inicio || ''; rec.rescate_fin = data.supervision.rescate_fin || '';
        rec.rescate_responsable_id = String(data.supervision.rescate_responsable_id || '');
        rec.rescate_responsable_nombre = data.supervision.rescate_responsable_nombre || '';
        rec.asignado_por_nombre = data.supervision.asignado_por_nombre || '';
        rec.partner_cancelacion_incorrecta = Number(data.supervision.partner_cancelacion_incorrecta || 0);
        if (rec.rescate_estado === 'En rescate') rec.estado = 'rescate';
      }
      detailContent.innerHTML = buildDetailContent(rec, obsVinculadas, forceAdminEdit);
      initChecklistSignaturePads(detailContent);
      attachDetailHandlers(rec, forceAdminEdit);
      renderRescate(rec, Array.isArray(data.rescate_seguimientos) ? data.rescate_seguimientos : []);
    } catch(e) {
      if (!rec) { showToast(e.message || 'No se pudo abrir la supervisión.'); return; }
      detailContent.innerHTML = buildDetailContent(rec, [], forceAdminEdit);
      initChecklistSignaturePads(detailContent);
      attachDetailHandlers(rec, forceAdminEdit);
    }
    // Orden sin coordenadas que el supervisor va a trabajar: la ubicación se toma sola. Si ya las trae
    // (por ejemplo, una cancelación importada con la dirección del cliente), se respetan.
    const editLat = document.getElementById('edit_latitud');
    if (editLat && !editLat.disabled && !editLat.readOnly && !String(editLat.value || '').trim()
        && rec.estado !== 'finalizada' && !esAdministradorActual) {
      capturarGPS('edit_latitud', 'edit_longitud', 'edit_gps_status');
    }

    viewList.classList.add('hidden');
    viewNew.classList.remove('open');
    viewDetail.classList.add('open');
    window.scrollTo({top:0, behavior:'smooth'});
  }
  // ---------- Atención del partner y rescate (2026-10-13; 2026-10-28 validación del directo) ----------
  // Orden transferida a un partner: lo que respondió (cancelación correcta o incorrecta, observación y fotos) y la validación
  // del supervisor directo que la transfirió (o del administrador): Cancelación correcta, En proceso de rescate o Rescatado.
  // En rescate → historial, avances y cierre (quien lo tomó o el administrador) y reasignación (solo el administrador).
  function renderRescate(rec, seguimientos) {
    const fmtFH = (v) => { if (!v) return '—'; const [d, h] = String(v).split(' '); return `${d.split('-').reverse().join('/')} ${(h || '').slice(0, 5)}`; };
    if (esSupervisorPartnerActual || esJefeOperacionesActual) return; // el partner responde en su formulario
    const esDueno = String(rec.rescate_responsable_id) === String(currentUserId);
    const atencion = seguimientos.filter(g => g.tipo === 'Atención del partner');
    const validacion = seguimientos.filter(g => g.tipo === 'Validación').pop();
    const historialRescate = seguimientos.filter(g => g.tipo !== 'Atención del partner'); // la validación abre el rescate
    let html = '';
    if (rec.es_asignacion_partner && rec.estado_atencion_partner === 'Atendida') {
      const incorrecta = rec.partner_cancelacion_incorrecta === 1;
      const porValidar = !validacion && !rec.rescate_estado && rec.estado === 'pendiente';
      const puedeValidar = porValidar && (esAdministradorActual || (esSupervisorWinDirecto && String(rec.asignado_por) === String(currentUserId)));
      const fotos = atencion.filter(g => g.foto).map((g, i) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-rescate-foto="${escapeHtml(g.foto)}"><i class="bi bi-image me-1"></i>Evidencia ${i + 1}</button>`).join('');
      html += `<div class="sgs-rescate card p-3 mb-3" id="partnerValidacionPanel">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <h5 class="mb-0"><i class="bi bi-building-check me-2 text-primary"></i>Atención del partner</h5>
          <span class="badge ${incorrecta ? 'bg-danger' : 'bg-success'}">Cancelación ${incorrecta ? 'incorrecta' : 'correcta'}</span>
        </div>
        <p class="small text-muted mb-2">${escapeHtml(rec.partner_nombre)} · atendida el ${fmtFH(rec.fecha_atencion_partner)}</p>
        <div class="mb-2">${escapeHtml(rec.observacion_atencion_partner || '—')}</div>
        <div class="d-flex flex-wrap gap-2 mb-2">${fotos || '<span class="small text-muted">Sin fotos.</span>'}</div>
        ${validacion ? `<div class="sgs-rescate-bloque">
          <div class="small"><strong>Validación del supervisor directo</strong> · ${fmtFH(validacion.fecha)} · ${escapeHtml(validacion.usuario || '—')}</div>
          <div>${escapeHtml(validacion.comentario || '')}</div>
        </div>` : ''}
        ${puedeValidar ? `<div class="sgs-rescate-bloque">
          <label class="form-label" for="validacionResultado">Resultado de la validación *</label>
          <select class="form-select mb-2" id="validacionResultado">
            <option value="">Seleccione el resultado de la validación</option>
            ${['Cancelación correcta', 'En proceso de rescate', 'Rescatado'].map(o => `<option value="${o}">${o}</option>`).join('')}
          </select>
          <label class="form-label" for="validacionComentario">Comentario *</label>
          <textarea class="form-control mb-2" id="validacionComentario" rows="2" maxlength="2000" placeholder="Qué revisó de la atención del partner"></textarea>
          <div class="text-end"><button type="button" class="btn btn-win" data-rescate="validar"><i class="bi bi-check2-square me-1"></i>Guardar validación</button></div>
        </div>` : (porValidar ? `<p class="small mb-0 text-muted">La valida el supervisor que la transfirió${rec.asignado_por_nombre ? `: <strong>${escapeHtml(rec.asignado_por_nombre)}</strong>` : ''}.</p>` : '')}
      </div>`;
    }
    if (rec.rescate_estado) {      const abierto = rec.rescate_estado === 'En rescate';
      const insignia = abierto ? 'bg-warning' : (rec.rescate_estado === 'Rescatado' ? 'bg-success' : 'bg-danger');
      const iconos = { 'Validación': 'bi-check2-square', 'Inicio': 'bi-play-circle', 'Avance': 'bi-chat-left-text', 'Reasignación': 'bi-arrow-left-right', 'Cierre': 'bi-flag' };
      const historial = historialRescate.length ? historialRescate.map((g) => `<li>
          <i class="bi ${iconos[g.tipo] || 'bi-dot'}" aria-hidden="true"></i>
          <div><div class="small"><strong>${escapeHtml(g.tipo)}</strong> · ${fmtFH(g.fecha)} · ${escapeHtml(g.usuario || '—')}</div>
          ${g.comentario ? `<div>${escapeHtml(g.comentario)}</div>` : ''}
          ${g.foto ? `<button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-rescate-foto="${escapeHtml(g.foto)}"><i class="bi bi-image me-1"></i>Ver foto</button>` : ''}</div>
        </li>`).join('') : '<li class="text-muted small">Sin movimientos registrados.</li>';
      const puedeTrabajar = abierto && (esDueno || esAdministradorActual);
      html += `<div class="sgs-rescate card p-3 mb-3" id="rescatePanel">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <h5 class="mb-0"><i class="bi bi-life-preserver me-2 text-primary"></i>Rescate</h5>
          <span class="badge ${insignia}">${escapeHtml(rec.rescate_estado)}</span>
        </div>
        <p class="small text-muted mb-2">Responsable: <strong>${escapeHtml(rec.rescate_responsable_nombre || rec.responsable)}</strong> · desde ${fmtFH(rec.rescate_inicio)}${rec.rescate_fin ? ` · cerrado ${fmtFH(rec.rescate_fin)}` : ''}</p>
        <ul class="list-unstyled sgs-rescate-historial mb-3">${historial}</ul>
        ${puedeTrabajar ? `
        <div class="sgs-rescate-bloque">
          <label class="form-label" for="rescateAvance">Registrar avance</label>
          <textarea class="form-control mb-2" id="rescateAvance" rows="2" maxlength="2000" placeholder="Qué se hizo y qué sigue"></textarea>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <input type="file" class="form-control" id="rescateFoto" accept="image/*" aria-label="Foto del avance (opcional)">
            <button type="button" class="btn btn-outline-secondary" data-rescate="avance"><i class="bi bi-plus-lg me-1"></i>Registrar avance</button>
          </div>
        </div>
        <div class="sgs-rescate-bloque">
          <label class="form-label" for="rescateCierre">Cerrar el rescate</label>
          <textarea class="form-control mb-2" id="rescateCierre" rows="2" maxlength="2000" placeholder="Resultado final (obligatorio)"></textarea>
          <div class="d-flex flex-wrap gap-2 justify-content-end">
            <button type="button" class="btn btn-outline-secondary" data-rescate="cerrar" data-resultado="No rescatado"><i class="bi bi-x-circle me-1"></i>Cancelación correcta</button>
            <button type="button" class="btn btn-win" data-rescate="cerrar" data-resultado="Rescatado"><i class="bi bi-check2-circle me-1"></i>Rescatado</button>
          </div>
        </div>` : ''}
        ${abierto && esAdministradorActual ? `
        <div class="sgs-rescate-bloque">
          <label class="form-label" for="rescateReasignar">Reasignar a otro supervisor (casos puntuales)</label>
          <div class="d-flex flex-wrap gap-2">
            <select class="form-select" id="rescateReasignar"><option value="">Cargando supervisores…</option></select>
            <button type="button" class="btn btn-outline-secondary" data-rescate="reasignar"><i class="bi bi-arrow-left-right me-1"></i>Reasignar</button>
          </div>
        </div>` : ''}
      </div>`;
    }
    if (!html) return;
    detailContent.insertAdjacentHTML('afterbegin', html);
    if (rec.estado === 'rescate') document.getElementById('saveProgressBtn')?.remove(); // se trabaja desde el rescate
    if (esAdministradorActual && document.getElementById('rescateReasignar')) {
      fetch('api/asistencia.php?action=list_filters').then(r => r.json()).then(d => {
        const sel = document.getElementById('rescateReasignar'); if (!sel) return;
        const dep = String(rec.departamento || '').toUpperCase();
        const lista = (d.supervisores || []).filter(u => String(u.id) !== String(rec.supervisor_id) && (u.zonas === null || (u.zonas || []).includes(dep)));
        sel.innerHTML = '<option value="">Seleccione un supervisor…</option>' + lista.map(u => `<option value="${Number(u.id)}">${escapeHtml(u.nombre_completo)}</option>`).join('');
      }).catch(() => {});
    }
    const alHacerClic = async (ev) => {
      const fotoBtn = ev.target.closest('[data-rescate-foto]');
      if (fotoBtn) { window.sgsVerFoto ? window.sgsVerFoto(fotoBtn.dataset.rescateFoto) : window.open(fotoBtn.dataset.rescateFoto, '_blank', 'noopener'); return; }
      const btn = ev.target.closest('[data-rescate]');
      if (!btn) return;
      const accion = btn.dataset.rescate;
      const body = new FormData();
      body.append('supervision_id', rec.id);
      if (accion === 'validar') {
        const resultado = document.getElementById('validacionResultado')?.value || '';
        const comentario = (document.getElementById('validacionComentario')?.value || '').trim();
        if (!resultado) { showToast('Elija el resultado de la validación.', 'error'); document.getElementById('validacionResultado')?.focus(); return; }
        if (!comentario) { showToast('Escriba el comentario de la validación.', 'error'); document.getElementById('validacionComentario')?.focus(); return; }
        const avisos = { 'Cancelación correcta': '¿Validar como CANCELACIÓN CORRECTA? La orden quedará finalizada.',
          'En proceso de rescate': '¿Pasar la orden a RESCATE? Quedará a su cargo hasta cerrarla.', 'Rescatado': '¿Validar como RESCATADO (se rescató el servicio)? La orden quedará finalizada.' };
        if (!confirm(avisos[resultado])) return;
        body.append('resultado', resultado); body.append('comentario', comentario);
      }
      if (accion === 'avance') {
        body.append('comentario', document.getElementById('rescateAvance')?.value || '');
        const foto = document.getElementById('rescateFoto')?.files?.[0]; if (foto) body.append('foto', foto);
      }
      if (accion === 'cerrar') {
        const comentario = (document.getElementById('rescateCierre')?.value || '').trim();
        if (!comentario) { showToast('Escriba el resultado final antes de cerrar el rescate.', 'error'); document.getElementById('rescateCierre')?.focus(); return; }
        if (!confirm(btn.dataset.resultado === 'Rescatado' ? '¿Cerrar como RESCATADO (se rescató el servicio)? La orden quedará finalizada.' : '¿Cerrar como CANCELACIÓN CORRECTA (no se rescató)? La orden quedará finalizada.')) return;
        body.append('comentario', comentario); body.append('resultado', btn.dataset.resultado);
      }
      if (accion === 'reasignar') {
        const destino = document.getElementById('rescateReasignar')?.value;
        if (!destino) { showToast('Elija el supervisor que seguirá el rescate.', 'error'); return; }
        body.append('supervisor_id', destino);
      }
      btn.disabled = true;
      try {
        const resp = await fetch(`api/supervisiones.php?action=${accion === 'validar' ? 'validar_partner' : `rescate_${accion}`}`, { method: 'POST', body });
        const res = await resp.json();
        showToast(res.message || 'Listo.', res.status === 'success' ? 'success' : 'error');
        if (res.status === 'success') {
          records = records.filter(r => r.id !== rec.id); // se vuelve a leer con su nuevo estado
          if (accion === 'avance' || body.get('resultado') === 'En proceso de rescate') { await openDetail(rec.id); loadRecords(); }
          else { closeDetail(); loadRecords(); }
        }
      } catch (e) { showToast('No se pudo completar la acción.', 'error'); }
      finally { btn.disabled = false; }
    };
    ['partnerValidacionPanel', 'rescatePanel'].forEach(id => document.getElementById(id)?.addEventListener('click', alHacerClic));
  }

  function closeDetail(){
    autoguardadoEdicion?.detener();
    autoguardadoEdicion = null;
    viewDetail.classList.remove('open');
    viewNew.classList.remove('open');
    viewList.classList.remove('hidden');
    loadRecords();
  }

  function buildPartnerDetailContent(rec){
    const attended=rec.estado_atencion_partner==='Atendida';
    const expired=!attended && Boolean(Number(rec.plazo_partner_vencido));
    const deadline=rec.fecha_limite_partner ? new Date(String(rec.fecha_limite_partner).replace(' ','T')).toLocaleString('es-PE') : 'Sin plazo';
    return `<div class="detail-head-bitacora"><div><h2>${escapeHtml(rec.codigo)}</h2><span class="detail-sub-bitacora">Orden pendiente asignada para revisión</span></div><span class="badge ${attended?'bg-success':(expired?'bg-danger':'bg-warning text-dark')}">${attended?'Atendida':(expired?'Plazo vencido':'Pendiente de atención')}</span></div>
      <form id="partner-supervision-form" enctype="multipart/form-data">
        <div class="alert ${expired?'alert-danger':'alert-info'}"><strong>Plazo de atención:</strong> ${escapeHtml(deadline)}${expired?' · Las 24 horas ya vencieron.':''}</div>
        <div class="panel-bitacora p-3 mb-3"><p class="section-subtitle-bitacora mt-0">Datos de la orden</p><div class="grid-bitacora">
          <div class="field-bitacora"><label>Departamento</label><input value="${escapeHtml(rec.departamento)}" disabled></div>
          <div class="field-bitacora"><label>Provincia</label><input value="${escapeHtml(rec.provincia)}" disabled></div>
          <div class="field-bitacora"><label>Distrito</label><input value="${escapeHtml(rec.distrito)}" disabled></div>
          ${rec.direccion ? `<div class="field-bitacora full"><label>Dirección</label><input value="${escapeHtml(rec.direccion)}" disabled></div>` : ''}
          <div class="field-bitacora"><label>Gestión o actividad supervisada</label><input value="${escapeHtml(rec.clasificacion)}" disabled></div>
          <div class="field-bitacora"><label>Código de pedido o ticket</label><input value="${escapeHtml(rec.ticket_codigo||'No aplica')}" disabled></div>
          ${esJefeOperacionesActual ? `<div class="field-bitacora"><label>Supervisor asignado</label><input value="${escapeHtml(rec.responsable||'—')}" disabled></div>` : ''}
        </div></div>
        <!-- 2026-10-27: el partner revisa sin saber qué partner canceló ni qué cuadrilla atendió (el servidor no los envía) -->
        <div class="panel-bitacora p-3 mb-3"><div class="field-bitacora full"><label>Observación de la atención *</label><textarea name="observacion_atencion_partner" rows="5" ${attended||expired||esJefeOperacionesActual?'disabled':''}>${escapeHtml(rec.observacion_atencion_partner||'')}</textarea></div></div>
        <div class="panel-bitacora p-3 mb-3">
          <div class="grid-bitacora">
            <div class="field-bitacora"><label for="partner_resultado">Resultado de la cancelación *</label>
              <select id="partner_resultado" name="partner_resultado" ${attended||expired||esJefeOperacionesActual?'disabled':''}>
                <option value="">Seleccione el resultado</option>
                <option value="correcta" ${attended && rec.partner_cancelacion_incorrecta!==1?'selected':''}>Cancelación correcta</option>
                <option value="incorrecta" ${attended && rec.partner_cancelacion_incorrecta===1?'selected':''}>Cancelación incorrecta</option>
              </select></div>
          </div>
          ${attended||expired||esJefeOperacionesActual ? '' : `<div id="partner_evidencias">
            <p class="small text-muted mt-2 mb-2"><i class="bi bi-camera me-1"></i>Evidencias: si la cancelación es incorrecta, suba al menos una foto. Luego el supervisor de WIN que le asignó la orden valida su atención.</p>
            <div class="grid-bitacora">
              ${[1,2,3].map(i => `<div class="field-bitacora"><label for="foto_partner_${i}">Evidencia ${i}</label><input type="file" class="form-control" id="foto_partner_${i}" name="foto_partner_${i}" accept="image/*"></div>`).join('')}
            </div>
          </div>`}
        </div>
        <div class="form-actions-bitacora">${esJefeOperacionesActual && !attended && !expired?'<span class="badge bg-warning p-2">Pendiente de atención del supervisor</span>':attended?'<span class="badge bg-success p-2">Atención registrada</span>':(expired?'<span class="badge bg-danger p-2">Plazo de 24 horas vencido</span>':'<button type="button" class="btn-bitacora btn-orange-bitacora" id="partnerSaveProgress">Registrar atención</button>')}</div>
      </form>`;
  }

  // Vista de consulta de una supervisión finalizada (2026-10-15): ficha de datos en rejilla de 4 columnas sin cajas.
  // Los textos largos (dirección, resumen) van a lo ancho al final de su sección, para no dejar huecos en blanco.
  function buildFinalizedViewContent(rec, obsVinculadas = []) {
    const vacio = v => { const t = String(v ?? '').trim(); return !t || ['0','—','No aplica','Sin registrar'].includes(t); };
    const dato = (label, value, tipo = '') => {
      if (vacio(value)) return null;
      const texto = String(value).trim();
      const visible = tipo === 'html' ? texto.replace(/<[^>]*>/g, '') : texto; // el ancho se decide con lo que se ve
      const largo = tipo === 'texto' || visible.length > 60 || visible.includes('\n');
      return { largo, html: `<div class="sgs-dato${largo ? ' sgs-dato--completo' : (visible.length > 26 ? ' sgs-dato--ancho' : '')}"><span class="sgs-dato-etiqueta">${escapeHtml(label)}</span><span class="sgs-dato-valor">${tipo === 'html' ? value : escapeHtml(texto)}</span></div>` };
    };
    const section = (title, items) => {
      const lista = items.filter(Boolean);
      if (!lista.length) return '';
      const html = [...lista.filter(i => !i.largo), ...lista.filter(i => i.largo)].map(i => i.html).join('');
      return `<div class="panel-bitacora p-3 p-md-4 mb-3"><p class="section-subtitle-bitacora mt-0">${escapeHtml(title)}</p><div class="sgs-ficha-datos">${html}</div></div>`;
    };
    const esCancel = Number(rec.es_cancelacion) === 1;
    const enFrio = ['En frío', 'Remota'].includes(rec.tipo_supervision_inicial); // sin cuadrilla en campo
    const gps = rec.latitud && rec.longitud
      ? `${escapeHtml(rec.latitud)}, ${escapeHtml(rec.longitud)} <a class="sgs-dato-enlace" href="https://maps.google.com/?q=${encodeURIComponent(rec.latitud + ',' + rec.longitud)}" target="_blank" rel="noopener"><i class="bi bi-geo-alt"></i>Ver en mapa</a>` : '';

    const general = section('Información registrada', [
      dato('Código de pedido o ticket', rec.ticket_codigo), dato('Gestión o actividad supervisada', rec.clasificacion),
      dato('Modalidad', rec.tipo_supervision_inicial === 'Remota' ? 'Remota (videollamada)' : rec.tipo_supervision_inicial),
      dato('Derivación de orden', rec.derivacion), dato('Tipo de servicio', rec.tipo_servicio),
      dato('Departamento', rec.departamento), dato('Provincia', rec.provincia), dato('Distrito', rec.distrito),
      dato('Dirección', rec.direccion, 'texto')
    ]);
    const recursos = section('Partner, personal técnico y vehículo', [
      dato('Partner o contrata responsable', rec.partner_nombre),
      ...(enFrio ? [] : [
        dato('Técnico líder', rec.tecnico1_nombre), dato('DNI del técnico líder', rec.tecnico1_dni),
        dato('Técnico de apoyo', rec.tecnico2_nombre), dato('DNI del técnico de apoyo', rec.tecnico2_dni),
        dato('Conductor con brevete', rec.brevete_poseedor), dato('Tipo de vehículo', rec.unidad_tipo), dato('Placa', rec.unidad_placa),
        dato('SOAT', rec.unidad_soat), dato('Revisión técnica', rec.unidad_revision_tecnica), dato('Estado del vehículo', rec.unidad_estado)
      ])
    ]);
    const resultado = section('Resultado de la supervisión', [
      dato('Estado de la orden', esCancel ? 'Cancelada' : ''),
      dato('Resultado de la validación', esCancel ? rec.estado_cancelacion : ''),
      dato('Tipificación registrada', esCancel ? rec.tipificacion_cancelacion : ''),
      dato('Fecha de cancelación', esCancel && rec.fecha_cancelacion ? String(rec.fecha_cancelacion).slice(0, 10).split('-').reverse().join('/') : ''),
      dato('Resultado o cierre técnico', rec.sub_tipo_atencion),
      dato('Ubicación GPS', gps, 'html'),
      dato('Observación previa para campo', rec.observacion_previa, 'texto'),
      dato('Resumen u observaciones', rec.observaciones, 'texto')
    ]);
    const fotos = [1,2,3].map(i => rec[`foto_servicio_${i}`]).filter(Boolean).map((src, i) =>
      `<button type="button" class="sgs-evidencia" onclick="abrirFotoModal('${escapeHtml(src)}','Evidencia ${i + 1}')" aria-label="Ver evidencia ${i + 1}"><img src="${escapeHtml(src)}" alt="Evidencia ${i + 1}"><span>Evidencia ${i + 1}</span></button>`).join('');
    const hallazgos = obsVinculadas.map((obs, idx) => ({ largo: true, html: `<div class="sgs-dato sgs-dato--completo"><span class="sgs-dato-etiqueta">Observación ${idx + 1}${obs.nombre_categoria ? ' · ' + escapeHtml(obs.nombre_categoria) : ''}</span><span class="sgs-dato-valor">${escapeHtml(obs.detalle_observacion || '')}</span></div>` }));

    return `<div class="detail-head-bitacora"><div><h2>${escapeHtml(rec.codigo)}${rec.ticket_codigo ? ` (${escapeHtml(rec.ticket_codigo)})` : ''}</h2><span class="detail-sub-bitacora">Finalizada · ${escapeHtml(rec.ubicacion)} · ${escapeHtml(rec.responsable)}</span></div><span class="badge-exec finalizada">Finalizada</span></div>
      ${general}
      ${recursos}
      ${resultado}
      ${fotos ? `<div class="panel-bitacora p-3 p-md-4 mb-3"><p class="section-subtitle-bitacora mt-0">Evidencias registradas</p><div class="sgs-evidencias">${fotos}</div></div>` : ''}
      ${section('Observaciones registradas', hallazgos)}
      <div class="form-actions-bitacora">${esAdministradorActual
        ? `<span class="badge bg-success p-2">Supervisión finalizada</span><button type="button" class="btn-bitacora btn-orange-bitacora" id="btnCorregirSupervision"><i class="bi bi-pencil-square me-1"></i>Corregir supervisión</button>`
        : `<span class="badge bg-success p-2">Supervisión finalizada · solo el administrador puede corregirla</span>`}</div>`;
  }
  function buildDetailContent(rec, obsVinculadas = [], forceAdminEdit = false){
    if (rec.estado === 'finalizada' && !forceAdminEdit) return buildFinalizedViewContent(rec, obsVinculadas);
    if(esSupervisorPartnerActual)return buildPartnerDetailContent(rec);
    const hasMap = rec.latitud && rec.longitud;
    const mapBtn = hasMap ? `<a href="https://maps.google.com/?q=${rec.latitud},${rec.longitud}" target="_blank" class="btn-bitacora btn-gps-bitacora" style="text-decoration:none; display:inline-block;"><i class="bi bi-map me-1"></i>Abrir en Google Maps (${rec.latitud}, ${rec.longitud})</a>` : '';
    const isFinished = rec.estado === 'finalizada';
    const esCancelacionImportada = Boolean(Number(rec.es_cancelacion)) && Boolean(Number(rec.origen_importacion)) && rec.tipo_supervision_inicial === 'En frío';
    const esCancelacionFrioPendiente = esCancelacionImportada && rec.estado === 'pendiente';
    const cancelacionAunPendiente = esCancelacionFrioPendiente;
    const tipificacionesCancelacionHtml = tipificacionesCancelacion.map(tipificacion =>
      `<option value="${escapeHtml(tipificacion)}" ${rec.tipificacion_corregida === tipificacion ? 'selected' : ''}>${escapeHtml(tipificacion)}</option>`
    ).join('');

    let labelState = rec.estado_supervision || 'En proceso';
    if (['Culminada','Finalizado'].includes(labelState)) labelState = 'Finalizada';
    const generalStateLabel = labelState;

    let partnerOptionsHtml = '';
    partnersData.forEach(p => {
      const selected = (p.id == rec.partner_id) ? 'selected' : '';
      partnerOptionsHtml += `<option value="${p.id}" ${selected}>${escapeHtml(p.nombre_partner)}</option>`;
    });

    let tec1OptionsHtml = '<option value="">Seleccione / busque técnico líder</option>';
    const t1List = lideresDeZona(rec.partner_id, rec.departamento, rec.tecnico1_nombre, rec.provincia); // el ya elegido se conserva aunque sea de otra zona
    t1List.forEach(t => {
      const sel = (t.nombre_tecnico === rec.tecnico1_nombre) ? 'selected' : '';
      tec1OptionsHtml += `<option value="${escapeHtml(t.nombre_tecnico)}" ${sel}>${escapeHtml(textoLider(t))}</option>`;
    });
    let tec2OptionsHtml = '<option value="">Sin técnico de apoyo (T2)</option>' + opcionesApoyo(rec.partner_id, rec.tecnico2_nombre);

    const seccionesChecklistEdicion = SGS_SUPERVISION.seccionesChecklist;
    const opcionesChecklistSeccion = seccion => seccion==='EPP y EPE' ? ['Sí','No','Para cambio','No revisado'] : (seccion==='Indumentaria' ? ['Sí','No','Mal estado','No revisado'] : (seccion==='Materiales y ferretería' ? ['Sí','No'] : ['Bueno','Regular','Malo','No tiene']));
    const claseOpcionChecklist = opcion => opcion.toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,'-');
    let checklistItemIndex = 0;
    const itemsVencimiento = SGS_SUPERVISION.itemsVencimiento;
    const inicioVencimiento = SGS_SUPERVISION.inicioVencimiento;
    const filaVencimiento = (criterio, idx) => `
      <div class="grid-bitacora mb-2 checklist-row checklist-venc" data-venc="${escapeHtml(criterio)}">
        <input type="hidden" name="checklist_criterio[]" value="${escapeHtml(criterio)}">
        <input type="hidden" name="checklist_estado[${idx}]" value="No revisado" class="checklist-venc-valor">
        <div class="field-bitacora"><label>${escapeHtml(criterio)}</label><div class="checklist-venc-estado"><span class="badge bg-secondary">Sin registrar</span><small class="text-muted">Elija el técnico líder y la placa.</small></div></div>
        <div class="field-bitacora"><label>Observación (opcional)</label><input name="checklist_observacion[]" type="text" placeholder="Detalle sobre este documento"></div>
        <div class="field-bitacora"><label>Fechas del documento</label><button type="button" class="btn btn-outline-secondary checklist-venc-actualizar"><i class="bi bi-calendar-check me-1"></i>Registrar fechas</button></div>
        <div class="checklist-venc-campos d-none">
          <div class="field-bitacora"><label>${escapeHtml(inicioVencimiento[criterio] || 'Inicio')}</label><input type="date" class="checklist-venc-inicio" name="venc_inicio[${escapeHtml(criterio)}]" max="${new Date().toISOString().slice(0, 10)}"></div>
          <div class="field-bitacora"><label>Vence</label><input type="date" class="checklist-venc-fin" name="venc_fin[${escapeHtml(criterio)}]"></div>
          <div class="field-bitacora"><label>Foto del documento</label><input type="file" name="venc_foto[${escapeHtml(criterio)}]" accept="image/*,application/pdf"></div>
        </div>
      </div>`;
    const checklistEdicionHtml = Object.entries(seccionesChecklistEdicion).map(([seccion,criterios]) => `<section class="checklist-group"><h4 class="checklist-group-title"><i class="bi bi-tools me-2"></i>${escapeHtml(seccion)}</h4><div class="checklist-group-body">${criterios.map(criterio => { const idx=checklistItemIndex++; if (itemsVencimiento.includes(criterio)) return filaVencimiento(criterio, idx); return `
      <div class="grid-bitacora mb-2 checklist-row" style="align-items:end;border-bottom:1px solid var(--sgs-success-soft);padding-bottom:10px;">
        <input type="hidden" name="checklist_criterio[]" value="${escapeHtml(criterio)}">
        <div class="field-bitacora"><label>${escapeHtml(criterio)} *</label>${seccion==='Materiales y ferretería'?'<small class="text-muted fw-semibold mb-1">¿Cuenta con stock?</small>':''}<div class="checklist-opciones">${opcionesChecklistSeccion(seccion).map(opcion=>`<label class="checklist-opcion ${claseOpcionChecklist(opcion)}"><input type="radio" name="checklist_estado[${idx}]" value="${escapeHtml(opcion)}" class="checklist-estado"><span>${escapeHtml(opcion)}</span></label>`).join('')}</div></div>
        <div class="field-bitacora"><label>Observación (opcional)</label><input name="checklist_observacion[]" type="text" placeholder="Detalle sobre esta herramienta"></div>
        <div class="field-bitacora"><label>Foto (opcional)</label><input name="checklist_foto[${idx}]" type="file" accept="image/jpeg,image/png,image/webp"></div>
      </div>`;}).join('')}</div></section>`).join('');

    // Renderizar observaciones existentes con sus 3 fotos con carga suave y visor modal
    let observacionesHtml = '';
    if (obsVinculadas.length > 0) {
      obsVinculadas.forEach((obs, idx) => {
        let fotosHtml = '';
        [obs.foto_1, obs.foto_2, obs.foto_3].forEach((f, fIdx) => {
          if (f) {
            fotosHtml += `
              <div class="photo-item" onclick="abrirFotoModal('${f}', 'Evidencia Fotográfica ${fIdx+1}')">
                <img src="${f}" alt="Evidencia ${fIdx+1}" onload="this.classList.add('loaded')">
              </div>`;
          }
        });

        observacionesHtml += `
          <div style="background:#fff; border:1px solid var(--win-border); padding:16px; border-radius:6px; margin-bottom:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <span style="font-weight:700; font-size:13px; color:var(--win-dark);">Observación #${idx+1} — ${escapeHtml(obs.nombre_categoria || 'General')} (${escapeHtml(obs.descripcion_subopcion || 'Sin subopción')})</span>
              <span class="badge-exec ${obs.gravedad==='Grave'?'pendiente':'iniciada'}">${escapeHtml(obs.gravedad)} · ${escapeHtml(obs.estado_respuesta)}</span>
            </div>
            <p style="font-size:13.5px; color:var(--win-text-dark); margin:0 0 10px; white-space:pre-wrap;">${escapeHtml(obs.detalle_observacion)}</p>
            ${fotosHtml ? `<div class="photo-gallery-grid">${fotosHtml}</div>` : ''}
          </div>`;
      });
    } else {
      observacionesHtml = `<p style="font-size:13px; color:var(--win-text-muted); font-style:italic;">No hay observaciones o faltas registradas en este servicio.</p>`;
    }

    const curTipoSup = rec.clasificacion || rec.area_servicio || 'Instalación';
    const subtiposEdicion = {
      'Postventa': LISTA_TRABAJOS_POSTVENTA
    };
    let subtiposEdicionHtml = '<option value="">Seleccione una opción</option>';
    (subtiposEdicion[curTipoSup] || []).forEach(opcion => {
      subtiposEdicionHtml += `<option value="${escapeHtml(opcion)}" ${rec.sub_tipo_atencion===opcion?'selected':''}>${escapeHtml(opcion)}</option>`;
    });
    const esCierreTecnico = ['Avería', 'Reiterada', 'Garantía'].includes(curTipoSup);
    let resultadosCierreHtml = '<option value="">Seleccione el resultado después de validar</option>';
    // Misma lista que al crear; si la supervisión guardó un valor que ya no está en la lista, se conserva.
    const opcionesCierre = esCierreTecnico && rec.sub_tipo_atencion && !LISTA_CIERRE_TECNICO.includes(rec.sub_tipo_atencion)
      ? [rec.sub_tipo_atencion, ...LISTA_CIERRE_TECNICO] : LISTA_CIERRE_TECNICO;
    opcionesCierre.forEach(opcion => {
      resultadosCierreHtml += `<option value="${escapeHtml(opcion)}" ${rec.sub_tipo_atencion===opcion?'selected':''}>${escapeHtml(opcion)}</option>`;
    });

    return `
      <div class="detail-head-bitacora">
        <div>
          <h2>${rec.codigo} ${rec.ticket_codigo ? '(' + escapeHtml(rec.ticket_codigo) + ')' : ''}</h2>
          <span class="detail-sub-bitacora">${generalStateLabel} · registrada el ${fmtDate(rec.fecha)} · ${escapeHtml(rec.ubicacion)} · ${escapeHtml(rec.responsable)}</span>
        </div>
        <div style="display:flex; align-items:center;">
          <span class="badge-exec ${rec.estado}">${labelState}</span>
          ${esAdministradorActual ? `<button type="button" class="btn-bitacora btn-danger-subtle" style="margin-left:12px; padding:6px 12px; font-size:12px;" onclick="eliminarSupervision(${rec.id}, '${rec.codigo}')"><i class="bi bi-trash3 me-1"></i>Eliminar</button>` : ''}
        </div>
      </div>

      <form id="form-edit-detail" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="supervision_id_edit" value="${rec.id}">
        <input type="hidden" id="edit_estado_supervision" name="estado_supervision" value="${isFinished ? 'Finalizada' : rec.estado_supervision}">
        <input type="hidden" name="departamento" value="${rec.departamento}">
        <input type="hidden" name="provincia" value="${rec.provincia}">
        <input type="hidden" name="distrito" value="${rec.distrito}">
        <input type="hidden" id="edit_tiene_foto_servicio" value="${[rec.foto_servicio_1, rec.foto_servicio_2, rec.foto_servicio_3].some(Boolean) ? '1' : '0'}">

        <p class="section-subtitle-bitacora">1. Información general de la supervisión</p>
        <div class="grid-bitacora mb-3">
          <div class="field-bitacora"><label>Departamento</label><input id="edit_departamento_visible" type="text" value="${escapeHtml(rec.departamento)}" ${esAdministradorActual ? '' : 'disabled'}></div>
          <div class="field-bitacora"><label>Provincia</label><input id="edit_provincia_visible" type="text" value="${escapeHtml(rec.provincia)}" ${esAdministradorActual ? '' : 'disabled'}></div>
          <div class="field-bitacora"><label>Distrito</label><input id="edit_distrito_visible" type="text" value="${escapeHtml(rec.distrito)}" ${esAdministradorActual ? '' : 'disabled'}></div>
          ${rec.direccion ? `<div class="field-bitacora full"><label>Dirección</label><input type="text" value="${escapeHtml(rec.direccion)}" disabled></div>` : ''}
        </div>

        <div class="grid-bitacora mb-3">
          
          <div class="field-bitacora">
            <label for="edit_tipo_supervision">Gestión o actividad supervisada *</label>
            <select id="edit_tipo_supervision" name="tipo_supervision">
              <option value="Instalación" ${curTipoSup==='Instalación'?'selected':''}>Instalación</option>
              <option value="Postventa" ${curTipoSup==='Postventa'?'selected':''}>Postventa</option>
              <option value="Avería" ${curTipoSup==='Avería'?'selected':''}>Avería</option>
              <option value="Reiterada" ${curTipoSup==='Reiterada'?'selected':''}>Reiterada</option>
              <option value="Garantía" ${curTipoSup==='Garantía'?'selected':''}>Garantía</option>
              <option value="Incidencia / Casos atípicos" ${curTipoSup.includes('Incidencia') || curTipoSup.includes('Casos')?'selected':''}>Incidencia / Casos atípicos</option>
              <option value="Proyecto de ordenamiento" ${curTipoSup==='Proyecto de ordenamiento'?'selected':''}>Proyecto de ordenamiento</option>
              <option value="Visita partner por capacitación" ${curTipoSup.includes('Visita')?'selected':''}>Visita partner por capacitación</option>
              <option value="Conjunta PEXT" ${curTipoSup==='Conjunta PEXT'?'selected':''}>Conjunta PEXT</option>
              <option value="Checklist" ${curTipoSup==='Checklist'?'selected':''}>Checklist</option>
            </select>
          </div>

          <div class="field-bitacora" id="edit_field_subtipo" style="${subtiposEdicion[curTipoSup] ? '' : 'display:none;'}">
            <label for="edit_sub_tipo_atencion">Trabajo de Postventa *</label>
            <select id="edit_sub_tipo_atencion" name="sub_tipo_atencion" ${subtiposEdicion[curTipoSup] ? 'required' : 'disabled'}>${subtiposEdicionHtml}</select>
          </div>

          <div class="field-bitacora">
            <label for="edit_derivacion">Derivación de orden</label>
            <select id="edit_derivacion" name="derivacion_orden">
              <option value="Autogestión" ${rec.derivacion==='Autogestión'?'selected':''}>Autogestión</option>
              <option value="Mesa de supervisión" ${rec.derivacion==='Mesa de supervisión'?'selected':''}>Mesa de supervisión</option>
              <option value="Líder" ${rec.derivacion==='Líder'?'selected':''}>Líder</option>
              <option value="Coordinador" ${rec.derivacion==='Coordinador'?'selected':''}>Coordinador</option>
              <option value="Gerente" ${rec.derivacion==='Gerente'?'selected':''}>Gerente</option>
            </select>
          </div>
          <div class="field-bitacora">
            <label for="edit_tipo_servicio">Tipo de servicio</label>
            <select id="edit_tipo_servicio" name="tipo_servicio">
              <option value="Regular" ${rec.tipo_servicio==='Regular'?'selected':''}>Regular</option>
              <option value="ZAV" ${rec.tipo_servicio==='ZAV'?'selected':''}>ZAV</option>
            </select>
          </div>
          <div class="field-bitacora">
            <label for="edit_tipo_sup">Modalidad de inspección</label>
            <select id="edit_tipo_sup" name="tipo_supervision_inicial">
              <option value="En caliente" ${rec.tipo_supervision_inicial==='En caliente'?'selected':''}>En caliente</option>
              <option value="En frío" ${rec.tipo_supervision_inicial==='En frío'?'selected':''}>En frío</option>
              <option value="Remota" ${rec.tipo_supervision_inicial==='Remota'?'selected':''}>Remota (videollamada)</option>
            </select>
          </div>
          <!-- Supervisión remota (2026-10-13): solo se ve con la modalidad "Remota" -->
          <div class="remota-only d-none sgs-remota-aviso"><i class="bi bi-camera-video me-1"></i>Supervisión remota: solo se pide el partner. Suba la <strong>captura de la videollamada</strong> como foto de evidencia (obligatoria).</div>
        </div>

        <input id="edit_cliente" name="cliente_nombre" type="hidden" value="${escapeHtml(rec.cliente_nombre)}">
        <input id="edit_dni" name="cliente_dni" type="hidden" value="${escapeHtml(rec.cliente_dni)}">
        <p class="section-subtitle-bitacora">2. Código de Pedido o Ticket</p>
        <div class="grid-bitacora mb-3">
          <div class="field-bitacora">
            <label for="edit_ticket">Código de Pedido o Ticket *</label>
            <input id="edit_ticket" name="ticket_codigo" type="text" value="${escapeHtml(rec.ticket_codigo)}" placeholder="12345" required>
          </div>
        </div>

        <!-- SECCIÓN 3: DATOS DE CUADRILLA & RECURSO MÓVIL (SE OCULTA EN MODALIDAD EN FRÍO) -->
        <div id="edit_sec_cuadrilla">
          <p class="section-subtitle-bitacora">3. Partner, personal técnico y vehículo</p>
          <div class="grid-bitacora mb-3">
          <div class="field-bitacora">
            <label for="edit_partner">Partner o contrata responsable *</label>
            <select id="edit_partner" name="partner_id">
              ${partnerOptionsHtml}
            </select>
          </div>
          ${Number(rec.es_cancelacion) && Number(rec.origen_importacion) ? `
          <div class="field-bitacora">
            <label for="edit_cuadrilla_carga">Técnico líder (cuadrilla de la carga)</label>
            <input id="edit_cuadrilla_carga" type="text" value="${escapeHtml(rec.tecnico1_nombre || 'Sin cuadrilla en la carga')}" readonly disabled
              title="Viene de la columna CUADRILLA del Excel. Las observaciones de esta cancelación quedan vinculadas a esta cuadrilla.">
          </div>` : ''}


            <div id="edit_personal_vehiculo" class="grid-bitacora full">
            <div class="field-bitacora">
              <label for="edit_tec1_nom">Técnico Líder (Seleccionar de Lista)</label>
              <select id="edit_tec1_nom" name="tecnico1_nombre" style="font-size:13.5px;">
                ${tec1OptionsHtml}
              </select>
            </div>
            <div class="field-bitacora">
              <label for="edit_tec1_dni">Técnico Líder (DNI)</label>
              <input id="edit_tec1_dni" name="tecnico1_dni" type="text" value="${escapeHtml(rec.tecnico1_dni)}" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" placeholder="Ingrese el DNI si aún no está registrado">
            </div>

            ${!esCancelacionFrioPendiente ? `
            <div class="field-bitacora">
              <label for="edit_tec2_nom">Técnico Apoyo (T2) — Opcional</label>
              <select id="edit_tec2_nom" name="tecnico2_nombre">${tec2OptionsHtml}</select>
            </div>
            <div class="field-bitacora">
              <label for="edit_tec2_dni">Técnico Apoyo (DNI)</label>
              <input id="edit_tec2_dni" name="tecnico2_dni" type="text" value="${escapeHtml(rec.tecnico2_dni)}" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" placeholder="Ingrese el DNI si aún no está registrado">
            </div>

            <div class="field-bitacora">
              <label for="edit_brevete">Integrante que cuenta con brevete</label>
              <select id="edit_brevete" name="brevete_poseedor">
                <option value="Tecnico 1" ${rec.brevete_poseedor==='Tecnico 1'?'selected':''}>Técnico 1 (Líder)</option>
                <option value="Tecnico 2" ${rec.brevete_poseedor==='Tecnico 2'?'selected':''}>Técnico 2 (Apoyo)</option>
                <option value="Ninguno" ${rec.brevete_poseedor==='Ninguno'?'selected':''}>Ninguno</option>
              </select>
            </div>
            <div class="field-bitacora">
              <label for="edit_u_tipo">Tipo de vehículo</label>
              <select id="edit_u_tipo" name="unidad_tipo">
                <option value="Minivan" ${rec.unidad_tipo==='Minivan'?'selected':''}>Minivan</option>
                <option value="Auto" ${rec.unidad_tipo==='Auto'?'selected':''}>Auto</option>
                <option value="Moto" ${rec.unidad_tipo==='Moto'?'selected':''}>Moto</option>
                <option value="Otro" ${rec.unidad_tipo==='Otro'?'selected':''}>Otro</option>
              </select>
            </div>
            <div class="field-bitacora">
              <label for="edit_u_placa">Placa del vehículo</label>
              <input id="edit_u_placa" name="unidad_placa" type="text" value="${escapeHtml(rec.unidad_placa)}" placeholder="ABC-123">

            </div>
            <div class="field-bitacora">
              <label for="edit_u_estado">Estado del vehículo</label>
              <select id="edit_u_estado" name="unidad_estado_conservacion">
                <option value="Mal estado" ${rec.unidad_estado==='Mal estado'?'selected':''}>Mal estado</option>
                <option value="Regular" ${rec.unidad_estado==='Regular'?'selected':''}>Regular</option>
                <option value="Buen estado" ${rec.unidad_estado==='Buen estado'?'selected':''}>Buen estado</option>
              </select>
            </div>
            ` : ''}
            </div>
          </div>
        </div>

        ${esCancelacionImportada && rec.observacion_previa ? `
        <div class="panel-bitacora p-3 mb-3">
          <p class="section-subtitle-bitacora mt-0">Observación previa para la visita de campo</p>
          <div class="field-bitacora full">
            <label>Recomendación u observación registrada en la carga</label>
            <textarea rows="3" disabled>${escapeHtml(rec.observacion_previa)}</textarea>
          </div>
        </div>` : ''}

        <p class="section-subtitle-bitacora">4. Geolocalización GPS & Observaciones del Servicio</p>

        <div class="grid-bitacora mb-3">
          <div class="field-bitacora">
            <label for="edit_latitud">Latitud GPS</label>
            <input id="edit_latitud" name="latitud" type="text" value="${escapeHtml(rec.latitud)}">
          </div>
          <div class="field-bitacora">
            <label for="edit_longitud">Longitud GPS</label>
            <input id="edit_longitud" name="longitud" type="text" value="${escapeHtml(rec.longitud)}">
          </div>
          <div class="field-bitacora" style="justify-content: flex-end;">
            <button type="button" class="btn-bitacora btn-gps-bitacora" onclick="capturarGPS('edit_latitud', 'edit_longitud', 'edit_gps_status')">
              <i class="bi bi-arrow-repeat me-1"></i>Actualizar ubicación
            </button>
          </div>
          <div class="field-bitacora full" id="edit_sec_resumen_general">
            <span id="edit_gps_status" class="sgs-gps-estado">
              ${hasMap ? `GPS Registrado: ${rec.latitud}, ${rec.longitud} &nbsp; | &nbsp; ${mapBtn}` : 'Sin coordenadas GPS registradas.'}
            </span>
          </div>

          <div class="field-bitacora full" id="edit_field_resultado_tecnico" style="${esCierreTecnico ? '' : 'display:none;'}">
            <label for="edit_resultado_tecnico">Resultado / cierre técnico encontrado</label>
            <select id="edit_resultado_tecnico" name="sub_tipo_atencion" ${esCierreTecnico ? '' : 'disabled'}>${resultadosCierreHtml}</select>
          </div>

          ${esCancelacionImportada ? `
          <div class="field-bitacora full panel-bitacora" id="box_motivo_cancelacion_edit" data-validacion-frio="1" style="display:block;padding:16px;margin-bottom:12px;">
            <input type="hidden" name="es_cancelacion" value="1">
            <div class="cancel-validation-grid">
              <div class="field-bitacora">
                <label>Estado de la orden</label>
                <input type="text" value="Cancelada" disabled>
              </div>
              <div class="field-bitacora">
                <label>Tipificación registrada</label>
                <input type="text" value="${escapeHtml(rec.tipificacion_cancelacion || 'No registrada')}" disabled>
              </div>
              <div class="field-bitacora">
                <label>Fecha de cancelación</label>
                <input type="text" value="${escapeHtml(rec.fecha_cancelacion ? rec.fecha_cancelacion.slice(0, 10) : 'No registrada')}" disabled>
              </div>
              <div class="field-bitacora" id="edit_resultado_cancelacion_box">
                <label for="edit_resultado_cancelacion">Resultado de la validación *</label>
                <select id="edit_resultado_cancelacion" name="resultado_cancelacion">
                  <option value="">Seleccione el resultado de la validación</option>
                  ${['Cancelación correcta', 'En proceso de rescate', 'Rescatado'].map(o => `<option value="${o}" ${rec.estado_cancelacion === o ? 'selected' : ''}>${o}</option>`).join('')}
                </select>
                <small class="text-muted" id="edit_resultado_cancelacion_ayuda"></small>
              </div>
            </div>
          </div>` : ''}

          <div class="field-bitacora full">
            <label for="edit_obs">Resumen u observaciones generales del servicio</label>
            <textarea id="edit_obs" name="observacion_servicio" placeholder="Describe lo verificado en campo...">${escapeHtml(rec.observaciones)}</textarea>
            
            ${[rec.foto_servicio_1, rec.foto_servicio_2, rec.foto_servicio_3].filter(Boolean).length > 0 ? `
              <div style="margin-top:10px; margin-bottom:12px;">
                <p style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:6px;"><i class="bi bi-camera me-1"></i>Fotos de Evidencia General del Servicio Registradas:</p>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                  ${[rec.foto_servicio_1, rec.foto_servicio_2, rec.foto_servicio_3].filter(Boolean).map((f, idx) => `
                    <a href="javascript:void(0)" onclick="verFotoEvidencia('${f}', 'Foto Evidencia General ${idx + 1}')" title="Ver foto a tamaño completo">
                      <img src="${f}" style="width:75px; height:75px; object-fit:cover; border-radius:6px; border:2px solid var(--win-border);" alt="Foto Servicio ${idx + 1}">
                    </a>
                  `).join('')}
                </div>
              </div>
            ` : ''}

            <div style="margin-top:10px;">
              <p style="font-size:11.5px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:6px;"><i class="bi bi-camera me-1"></i>Actualizar / Subir Fotos del Servicio General (Hasta 3 Fotos)</p>
              <div class="grid-bitacora">
                <div class="field-bitacora">
                  <label for="edit_foto_serv_1">Foto del servicio 1</label>
                  <input type="file" id="edit_foto_serv_1" name="foto_servicio_1" accept="image/*">
                  <div id="edit_preview_foto_serv_1" style="display:none; margin-top:8px;"></div>
                </div>
                <div class="field-bitacora">
                  <label for="edit_foto_serv_2">Foto del servicio 2</label>
                  <input type="file" id="edit_foto_serv_2" name="foto_servicio_2" accept="image/*">
                  <div id="edit_preview_foto_serv_2" style="display:none; margin-top:8px;"></div>
                </div>
                <div class="field-bitacora">
                  <label for="edit_foto_serv_3">Foto del servicio 3</label>
                  <input type="file" id="edit_foto_serv_3" name="foto_servicio_3" accept="image/*">
                  <div id="edit_preview_foto_serv_3" style="display:none; margin-top:8px;"></div>
                </div>
              </div>
            </div>
          </div>

        </div>

        <div id="edit_sec_checklist_toggle" class="optional-module-toggle ${canUseChecklistForm ? '' : 'd-none'}">
          <label style="font-weight:700;display:flex;align-items:center;gap:9px;cursor:pointer;">
            <input type="checkbox" id="edit_chk_checklist" name="chk_incluir_checklist" value="1" style="width:18px;height:18px;" ${canUseChecklistForm ? '' : 'disabled'}>
            <i class="bi bi-ui-checks-grid text-secondary"></i> Incluir evaluación mediante checklist técnico
          </label>
        </div>
        <div id="edit_sec_checklist" style="display:none;background:var(--sgs-surface-alt);border:1px solid var(--win-border);border-radius:8px;padding:16px;margin-bottom:16px;">
          <p class="section-subtitle-bitacora" style="margin-top:0;">Evaluación mediante checklist técnico</p>
          <input type="hidden" name="unidad_soat" value="${escapeHtml(rec.unidad_soat || '')}">
          <input type="hidden" name="unidad_revision_tecnica" value="${escapeHtml(rec.unidad_revision_tecnica || '')}">
          ${checklistEdicionHtml}
          <div class="grid-bitacora mt-3" style="align-items:end">
            <div class="field-bitacora"><label for="edit_checklist_obs_general">Comentario general de las observaciones encontradas</label><textarea id="edit_checklist_obs_general" name="checklist_observacion_general" rows="3" placeholder="Resumen general del checklist y de las observaciones detectadas"></textarea></div>
            <div class="field-bitacora"><label>Firma del supervisor *</label><canvas id="edit_checklist_firma_canvas" class="checklist-signature-pad" width="520" height="150"></canvas><input id="edit_checklist_firma_data" name="checklist_firma_data" type="hidden"><button type="button" class="btn btn-outline-secondary btn-sm mt-2 checklist-signature-clear" data-canvas="edit_checklist_firma_canvas" data-input="edit_checklist_firma_data"><i class="bi bi-eraser me-1"></i>Limpiar firma</button><small class="text-muted d-block mt-1">Firme a mano alzada dentro del recuadro.</small></div>
          </div>
        </div>

        <p class="section-subtitle-bitacora">5. Observaciones / Incidencias Registradas & Evidencias</p>
        <div class="mb-4">
          ${observacionesHtml}
        </div>

        <!-- AGREGAR NUEVA OBSERVACIÓN CON 3 FOTOS -->
        <div id="edit_sec_observaciones" class="${canUseFindingsForm ? '' : 'd-none'}" style="background:var(--sgs-surface-alt); padding:20px; border-radius:6px; border:1px solid var(--win-border); margin-bottom:20px;">
          <div class="mb-3">
            <label style="cursor:pointer; display:inline-flex; align-items:center; gap:8px; font-weight:700; color:var(--win-dark);">
              <input type="checkbox" id="edit_chk_obs" name="chk_incluir_observacion" value="1" style="width:18px; height:18px;" ${canUseFindingsForm ? '' : 'disabled'}>
              <i class="bi bi-plus-circle me-1"></i>¿Agregar una nueva observación o falta?
            </label>
          </div>

          <div id="edit_box_observacion" style="display:none;">
            <div class="grid-bitacora mb-3">
              <div class="field-bitacora">
                <label for="edit_obs_gravedad">Gravedad de la Falta *</label>
                <select id="edit_obs_gravedad" name="obs_gravedad">
                  <option value="">Seleccione gravedad</option>
                  <option value="Media">Media</option>
                  <option value="Grave">Grave</option>
                  <option value="Muy Grave">Muy Grave</option>
                </select>
              </div>
              <div class="field-bitacora">
                <label for="edit_obs_cat">Categoría de Observación *</label>
                <select id="edit_obs_cat" name="obs_categoria_id">
                  <option value="">Seleccione categoría</option>
                </select>
              </div>
              <div class="field-bitacora">
                <label for="edit_obs_sub">Sub-Opción / Tipificación *</label>
                <select id="edit_obs_sub" name="obs_subopcion_id">
                  <option value="">Seleccione categoría primero</option>
                </select>
              </div>
              <div class="field-bitacora full">
                <label for="edit_obs_det">Comentario de la observación *</label>
                <textarea id="edit_obs_det" name="obs_detalle" placeholder="Describa el incumplimiento o la falta encontrada; vale para todas las fotos..."></textarea>
              </div>
            </div>

            <!-- CARGA DE 3 FOTOS DE EVIDENCIA CON VISTA PREVIA INMEDIATA -->
            <p style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:10px;"><i class="bi bi-camera me-1"></i>Evidencias fotográficas (al menos 1, hasta 3)</p>
            <div class="grid-bitacora">
              <div class="field-bitacora">
                <label for="edit_foto1">Foto de evidencia 1</label>
                <input type="file" id="edit_foto1" name="foto_1" accept="image/*">
                <div id="edit_preview_foto1" style="display:none; margin-top:8px;"></div>
              </div>
              <div class="field-bitacora">
                <label for="edit_foto2">Foto de evidencia 2</label>
                <input type="file" id="edit_foto2" name="foto_2" accept="image/*">
                <div id="edit_preview_foto2" style="display:none; margin-top:8px;"></div>
              </div>
              <div class="field-bitacora">
                <label for="edit_foto3">Foto de evidencia 3</label>
                <input type="file" id="edit_foto3" name="foto_3" accept="image/*">
                <div id="edit_preview_foto3" style="display:none; margin-top:8px;"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="form-actions-bitacora">
          ${esSupervisorPartnerActual ? `<button class="btn-bitacora btn-orange-bitacora" id="savePartnerObservation" type="button"><i class="bi bi-chat-square-text me-1"></i>Registrar observación de validación</button>` : (isFinished && !forceAdminEdit
            ? (esAdministradorActual
              ? `<span class="badge bg-secondary p-2">Vista de consulta</span>
                 <button class="btn-bitacora btn-orange-bitacora" id="enableAdminEditBtn" type="button"><i class="bi bi-shield-lock me-1"></i>Habilitar edición administrativa</button>
                 <span id="adminFinalActions" style="display:none;gap:8px;">
                   <button class="btn-bitacora btn-ghost-bitacora" id="reopenBtn" type="button" style="color:var(--win-dark);border-color:var(--win-border);">Reabrir</button>
                   <button class="btn-bitacora btn-orange-bitacora" id="saveProgressBtn" type="button">Guardar cambios</button>
                 </span>`
              : `<span class="badge bg-success p-2">Supervisión finalizada · solo el administrador puede editarla</span>`)
            : (isFinished && forceAdminEdit
              ? `<button class="btn-bitacora btn-ghost-bitacora" id="btnCancelarCorreccion" type="button">Cancelar</button>
                 <button class="btn-bitacora btn-orange-bitacora" id="saveProgressBtn" type="button"><i class="bi bi-check2 me-1"></i>Guardar corrección</button>`
              : (rec.estado === 'iniciada' && Number(rec.supervisor_id) === currentUserId
                ? `<span class="sgs-autoguardado" id="edit_autoguardado" aria-live="polite"></span>
                   <button class="btn-bitacora btn-ghost-bitacora" id="cancelarEdicionBtn" type="button"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
                   <button class="btn-bitacora btn-orange-bitacora" id="saveProgressBtn" type="button"><i class="bi bi-check2-circle me-1"></i>Finalizar supervisión</button>`
                : `${esCancelacionFrioPendiente && SGS_SUPERVISION.corregirPendientes ? '<button class="btn-bitacora btn-ghost-bitacora" id="btnCorregirPendiente" type="button"><i class="bi bi-pencil-square me-1"></i>Corregir datos</button>' : ''}<button class="btn-bitacora ${rec.estado === 'pendiente' ? 'btn-orange-bitacora' : 'btn-ghost-bitacora'}" id="saveProgressBtn" type="button">${rec.estado === 'pendiente' ? 'Iniciar supervisión' : 'Guardar avance y volver al listado'}</button>`)))
          }
        </div>
      </form>`;
  }

  function attachDetailHandlers(rec, forceAdminEdit = false){
    // Corrección por el administrador (2026-10-15): desde la vista de consulta abre el formulario completo editable.
    document.getElementById('btnCorregirSupervision')?.addEventListener('click', () => openDetail(rec.id, true));
    document.getElementById('btnCancelarCorreccion')?.addEventListener('click', () => openDetail(rec.id));
    document.getElementById('btnCorregirPendiente')?.addEventListener('click', () => abrirCorreccionPendiente(rec).catch(() => showToast('No se pudo abrir la corrección.')));
    if(esSupervisorPartnerActual){
      const form=document.getElementById('partner-supervision-form');
      const sendPartnerUpdate=async estado=>{
        if(!form)return;
        const data=new FormData(form);data.append('supervision_id',rec.id);
        try{const response=await fetch('api/supervisiones.php?action=partner_update',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:data});const result=await response.json();showToast(result.message,result.status==='success'?'success':'error');if(result.status==='success')closeDetail();}catch(e){showToast('No se pudo guardar la validación.','error');}
      };
      const selResultado = document.getElementById('partner_resultado');
      document.getElementById('partnerSaveProgress')?.addEventListener('click',()=>{
        if (!selResultado?.value) { showToast('Indique si la cancelación fue correcta o incorrecta.', 'error'); selResultado?.focus(); return; }
        if (selResultado.value === 'incorrecta' && ![1,2,3].some(i => document.getElementById(`foto_partner_${i}`)?.files?.length)) {
          showToast('Si la cancelación es incorrecta, suba al menos una foto de evidencia.', 'error');
          document.getElementById('foto_partner_1')?.focus();
          return;
        }
        sendPartnerUpdate();
      });
      return;
    }
    setupPhotoPreview('edit_foto_serv_1', 'edit_preview_foto_serv_1');
    setupPhotoPreview('edit_foto_serv_2', 'edit_preview_foto_serv_2');
    setupPhotoPreview('edit_foto_serv_3', 'edit_preview_foto_serv_3');

    setupPhotoPreview('edit_foto1', 'edit_preview_foto1');
    setupPhotoPreview('edit_foto2', 'edit_preview_foto2');
    setupPhotoPreview('edit_foto3', 'edit_preview_foto3');

    const cboEstadoEdit = document.getElementById('edit_estado_servicio');
    const boxCancelEdit = document.getElementById('box_motivo_cancelacion_edit');
    const cboMotivoEdit = document.getElementById('edit_motivo_cancelacion');

    if (cboEstadoEdit && boxCancelEdit) {
      cboEstadoEdit.addEventListener('change', function () {
        const esConjuntaPext = document.getElementById('edit_tipo_supervision')?.value === 'Conjunta PEXT';
        if (!esConjuntaPext && !this.disabled && this.value === 'Cancelada') {
          boxCancelEdit.style.display = 'block';
          if (cboMotivoEdit) { cboMotivoEdit.disabled = false; cboMotivoEdit.required = true; }
        } else {
          boxCancelEdit.style.display = 'none';
          if (cboMotivoEdit) {
            cboMotivoEdit.required = false; cboMotivoEdit.disabled = true;
            cboMotivoEdit.value = '';
          }
        }
      });
    }

    const saveBtn = document.getElementById('saveProgressBtn');
    const openRescueBtn = document.getElementById('openRescueBtn');
    const reopenBtn = document.getElementById('reopenBtn');
    const enableAdminEditBtn = document.getElementById('enableAdminEditBtn');
    const adminFinalActions = document.getElementById('adminFinalActions');
    const editChkObs = document.getElementById('edit_chk_obs');
    const editBoxObs = document.getElementById('edit_box_observacion');
    const editChkChecklist = document.getElementById('edit_chk_checklist');
    const editSecChecklist = document.getElementById('edit_sec_checklist');
    const editSecChecklistToggle = document.getElementById('edit_sec_checklist_toggle');
    const editObsCat = document.getElementById('edit_obs_cat');
    const editObsSub = document.getElementById('edit_obs_sub');
    const editTipoSupModalidad = document.getElementById('edit_tipo_sup');
    const editSecCuadrilla = document.getElementById('edit_sec_cuadrilla');
    const editTipoSupervision = document.getElementById('edit_tipo_supervision');
    const editSubtipo = document.getElementById('edit_sub_tipo_atencion');
    const editFieldSubtipo = document.getElementById('edit_field_subtipo');
    const editResultadoTecnico = document.getElementById('edit_resultado_tecnico');
    const editFieldResultadoTecnico = document.getElementById('edit_field_resultado_tecnico');
    const editFieldEstadoServicio = document.getElementById('edit_field_estado_servicio');
    const editSecObservaciones = document.getElementById('edit_sec_observaciones');
    const savePartnerObservation = document.getElementById('savePartnerObservation');

    const editTec1Nom = document.getElementById('edit_tec1_nom');
    const editTec1Dni = document.getElementById('edit_tec1_dni');
    const editTec2Nom = document.getElementById('edit_tec2_nom');
    const editTec2Dni = document.getElementById('edit_tec2_dni');
    activarBuscadorTecnico(editTec1Nom, 1);
    activarBuscadorTecnico(editTec2Nom, 2);
    prepararVencimientosChecklist('edit', rec.id);
    document.getElementById('edit_partner')?.addEventListener('change', function() {
      [editTec1Nom,editTec2Nom].forEach((select,index) => {
        if (!select) return;
        if (index === 1) { select.innerHTML = '<option value="">Sin técnico de apoyo (T2)</option>' + opcionesApoyo(this.value, ''); select.dispatchEvent(new Event('change')); return; }
        select.innerHTML = '<option value="">Seleccione técnico</option>';
        lideresDeZona(this.value, rec.departamento, '', rec.provincia).forEach(t => select.add(new Option(textoLider(t), t.nombre_tecnico)));
        select.dispatchEvent(new Event('change'));
      });
    });
    const esCancelacionImportadaEdicion = Boolean(Number(rec.es_cancelacion)) && Boolean(Number(rec.origen_importacion)) && rec.tipo_supervision_inicial === 'En frío';
    const esOrdenPendiente = rec.estado === 'pendiente';
    const esCancelacionPendiente = esCancelacionImportadaEdicion && esOrdenPendiente;
    const cancelacionAunPendiente = esCancelacionPendiente && rec.estado === 'pendiente';

    const editEstadoCancelacion = document.getElementById('edit_estado_cancelacion');
    const editTipificacionCorregidaBox = document.getElementById('edit_tipificacion_corregida_box');
    const actualizarTipificacionCorregida = () => {
      if (!editEstadoCancelacion || !editTipificacionCorregidaBox || !cboMotivoEdit) return;
      const requerida = editEstadoCancelacion.value === 'Correcta con tipificación incorrecta';
      editTipificacionCorregidaBox.style.display = requerida ? '' : 'none';
      cboMotivoEdit.disabled = !requerida;
      cboMotivoEdit.required = requerida;
      if (!requerida) cboMotivoEdit.value = '';
    };
    editEstadoCancelacion?.addEventListener('change', actualizarTipificacionCorregida);
    actualizarTipificacionCorregida();

    function aplicarReglasEdicion() {
      if (!editTipoSupervision) return;
      const tipo = editTipoSupervision.value;
      if (esOrdenPendiente && editTipoSupModalidad) editTipoSupModalidad.value = 'En frío';
      const catalogos = {
        'Postventa': LISTA_TRABAJOS_POSTVENTA
      };
      const requiereSubtipo = Boolean(catalogos[tipo]);
      if (editFieldSubtipo && editSubtipo) {
        const anterior = editSubtipo.value;
        editFieldSubtipo.style.display = requiereSubtipo ? '' : 'none';
        editSubtipo.disabled = !requiereSubtipo;
        editSubtipo.required = requiereSubtipo;
        editSubtipo.innerHTML = '<option value="">Seleccione una opción</option>';
        (catalogos[tipo] || []).forEach(op => editSubtipo.add(new Option(op, op, false, op === anterior)));
        activarBuscadorSelect(editSubtipo, 'Escriba para buscar trabajo programado...');
      }
      const usaResultadoTecnico = ['Avería', 'Reiterada', 'Garantía'].includes(tipo);
      if (editFieldResultadoTecnico && editResultadoTecnico) {
        const anterior = editResultadoTecnico.value;
        editFieldResultadoTecnico.style.display = usaResultadoTecnico ? '' : 'none';
        editResultadoTecnico.disabled = !usaResultadoTecnico;
        editResultadoTecnico.innerHTML = '<option value="">Seleccione el resultado después de validar</option>';
        const opcionesEdicion = anterior && !LISTA_CIERRE_TECNICO.includes(anterior) ? [anterior, ...LISTA_CIERRE_TECNICO] : LISTA_CIERRE_TECNICO;
        opcionesEdicion.forEach(op => editResultadoTecnico.add(new Option(op, op, false, op === anterior)));
        activarBuscadorSelect(editResultadoTecnico, 'Escriba para buscar solución aplicada / cierre técnico...');
      }
      const sinPedido = ['Incidencia / Casos atípicos', 'Proyecto de ordenamiento', 'Visita partner por capacitación', 'Conjunta PEXT', 'Checklist'].includes(tipo);
      const ticket = document.getElementById('edit_ticket');
      if (ticket) {
        ticket.disabled = sinPedido;
        ticket.required = !sinPedido;
        ticket.closest('.grid-bitacora').style.display = sinPedido ? 'none' : '';
      }
      const sinPartner = tipo === 'Incidencia / Casos atípicos';
      const partner = document.getElementById('edit_partner');
      if (partner) {
        partner.disabled = sinPartner;
        partner.required = !sinPartner;
        partner.closest('.field-bitacora').style.display = sinPartner ? 'none' : '';
      }
      const sinEstado = editTipoSupModalidad?.value === 'En frío' || ['Incidencia / Casos atípicos', 'Visita partner por capacitación', 'Checklist'].includes(tipo) || esCancelacionImportadaEdicion;
      if (editFieldEstadoServicio) editFieldEstadoServicio.style.display = esCancelacionImportadaEdicion ? '' : (sinEstado ? 'none' : '');
      if (cboEstadoEdit) {
        cboEstadoEdit.disabled = sinEstado;
        cboEstadoEdit.required = !sinEstado;
      }
      const sinObservacionesAdicionales = ['Incidencia / Casos atípicos', 'Visita partner por capacitación'].includes(tipo)
        || (tipo === 'Proyecto de ordenamiento' && editTipoSupModalidad?.value === 'En frío');
      if (editSecObservaciones) editSecObservaciones.style.display = sinObservacionesAdicionales ? 'none' : '';
      if (editChkObs) {
        editChkObs.disabled = sinObservacionesAdicionales;
        if (sinObservacionesAdicionales) editChkObs.checked = false;
      }
      if (sinObservacionesAdicionales && editBoxObs) editBoxObs.style.display = 'none';
      const esGestionChecklist = tipo === 'Checklist';
      const resumenGeneral = document.getElementById('edit_sec_resumen_general');
      if (resumenGeneral) {
        resumenGeneral.style.display = esGestionChecklist ? 'none' : '';
        resumenGeneral.querySelectorAll('input,textarea').forEach(el => el.disabled = esGestionChecklist);
      }
      const permiteChecklist = !esOrdenPendiente && !['Incidencia / Casos atípicos', 'Visita partner por capacitación'].includes(tipo)
        && editTipoSupModalidad?.value !== 'En frío';
      if (editSecChecklistToggle) editSecChecklistToggle.style.display = permiteChecklist && !esGestionChecklist ? '' : 'none';
      if (editChkChecklist) {
        if (esGestionChecklist) editChkChecklist.checked = true;
        editChkChecklist.disabled = !permiteChecklist || esGestionChecklist;
        if (!permiteChecklist) editChkChecklist.checked = false;
      }
      if (editSecChecklist) editSecChecklist.style.display = permiteChecklist && editChkChecklist?.checked ? 'block' : 'none';
      const ejecutaChecklist = esGestionChecklist || (permiteChecklist && Boolean(editChkChecklist?.checked));
      document.querySelectorAll('#form-edit-detail .checklist-vehicle-only').forEach(field => {
        field.style.display = ejecutaChecklist ? '' : 'none';
        field.querySelectorAll('select,input').forEach(control => control.disabled = !ejecutaChecklist);
      });
      const sinModalidad = tipo === 'Visita partner por capacitación' || esCancelacionImportadaEdicion;
      if (editTipoSupModalidad) {
        editTipoSupModalidad.disabled = sinModalidad;
        editTipoSupModalidad.closest('.field-bitacora').style.display = sinModalidad ? 'none' : '';
        if (tipo === 'Visita partner por capacitación') editTipoSupModalidad.value = 'En caliente';
        if (esOrdenPendiente) editTipoSupModalidad.value = 'En frío';
      }
      if (boxCancelEdit && cboEstadoEdit) {
        if (boxCancelEdit.dataset.validacionFrio === '1') {
          boxCancelEdit.style.display = 'block';
        } else {
        const mostrarMotivo = !cboEstadoEdit.disabled && tipo !== 'Conjunta PEXT' && cboEstadoEdit.value === 'Cancelada';
        boxCancelEdit.style.display = mostrarMotivo ? 'block' : 'none';
        if (cboMotivoEdit) {
          cboMotivoEdit.disabled = !mostrarMotivo;
          cboMotivoEdit.required = mostrarMotivo && cboEstadoEdit.value === 'Cancelada';
          if (!mostrarMotivo) cboMotivoEdit.value = '';
        }
        }
      }
      const sinCuadrilla = ['Incidencia / Casos atípicos', 'Visita partner por capacitación'].includes(tipo);
      if (editSecCuadrilla) {
        const mostrar = !sinCuadrilla && !['En frío', 'Remota'].includes(editTipoSupModalidad?.value); // remota: solo el partner
        editSecCuadrilla.style.display = tipo === 'Incidencia / Casos atípicos' ? 'none' : '';
        const personal = document.getElementById('edit_personal_vehiculo');
        personal.style.display = mostrar ? '' : 'none';
        personal.querySelectorAll('input,select,textarea,button').forEach(el => el.disabled = !mostrar);
        bloquearT2('edit');
      }
    }
    editTipoSupervision?.addEventListener('change', aplicarReglasEdicion);
    editTipoSupModalidad?.addEventListener('change', aplicarReglasEdicion);
    editTipoSupModalidad?.addEventListener('change', () => sgsActualizarRemota(document.getElementById('form-edit-detail')));
    aplicarReglasEdicion();
    sgsActualizarRemota(document.getElementById('form-edit-detail'));

    const formDetalle = document.getElementById('form-edit-detail');
    const finalEditableControls = [];
    if (rec.estado === 'finalizada' && formDetalle && !forceAdminEdit) {
      formDetalle.querySelectorAll('input, select, textarea, button').forEach(campo => {
        if (!campo.disabled) finalEditableControls.push(campo);
        campo.disabled = true;
      });
      if (esAdministradorActual && enableAdminEditBtn) enableAdminEditBtn.disabled = false;
    }
    if (!esAdministradorActual) {
      // Mientras la supervisión siga abierta, todos sus campos permanecen editables.
      // El bloqueo total se aplica únicamente al cerrarla como finalizada.
      if (rec.estado !== 'finalizada' && esCancelacionPendiente && formDetalle) {
        // Pendiente sin iniciar (2026-10-16): todo en solo lectura; solo funciona "Iniciar supervisión". Al iniciarla se
        // reabre En proceso y ahí se habilitan el resultado de la validación, el resumen, las fotos, la ubicación y las
        // observaciones (antes se podían llenar sin haberla iniciado).
        formDetalle.querySelectorAll('input:not([type="hidden"]), select, textarea, button').forEach(campo => campo.disabled = true);
        document.querySelectorAll('.btn-gps-bitacora').forEach(boton => boton.disabled = true);
        const iniciar = document.getElementById('saveProgressBtn');
        if (iniciar) iniciar.disabled = false;
      }
      // Cancelación cargada ya iniciada (2026-10-16): los datos de la orden (vienen del Excel) siguen bloqueados. No se
      // desactivan (un campo desactivado no se envía al guardar): solo no se pueden tocar.
      if (rec.estado !== 'finalizada' && esCancelacionImportadaEdicion && !esCancelacionPendiente && formDetalle) {
        ['edit_tipo_supervision', 'edit_derivacion', 'edit_tipo_servicio', 'edit_ticket', 'edit_partner'].forEach(id => {
          const campo = document.getElementById(id);
          if (!campo) return;
          campo.classList.add('sgs-campo-bloqueado');
          campo.tabIndex = -1;
          campo.setAttribute('aria-readonly', 'true');
          if (campo.tagName === 'INPUT') campo.readOnly = true;
        });
      }
    }
    enableAdminEditBtn?.addEventListener('click', () => {
      if (!formDetalle || !esAdministradorActual) return;
      finalEditableControls.forEach(campo => campo.disabled = false);
      enableAdminEditBtn.style.display = 'none';
      if (adminFinalActions) adminFinalActions.style.display = 'inline-flex';
      // Los datos de origen de una cancelación importada continúan siendo evidencia inmutable.
      if (esCancelacionImportadaEdicion) {
        ['edit_motivo_original','edit_estado_servicio'].forEach(id => {
          const campo = document.getElementById(id); if (campo) campo.disabled = true;
        });
      }
    });
    if(esSupervisorPartnerActual && rec.estado === 'finalizada' && formDetalle){
      formDetalle.querySelectorAll('input,select,textarea,button').forEach(el=>el.disabled=true);
      editSecObservaciones?.querySelectorAll('input,select,textarea').forEach(el=>el.disabled=false);
      if(editChkObs){editChkObs.checked=true;editChkObs.disabled=true;}
      if(editBoxObs)editBoxObs.style.display='block';
      if(savePartnerObservation)savePartnerObservation.disabled=false;
    }

    if (editTec1Nom) {
      editTec1Nom.addEventListener('change', function() {
        sincronizarTecnico(this, editTec1Dni, 1);
      });
    }

    if (editTec2Nom) {
      editTec2Nom.addEventListener('change', function() {
        const match = listaTecnicos.find(t => t.nombre_tecnico === this.value);
        editTec2Dni.value = match && /^\d{8}$/.test(String(match.dni || '')) ? String(match.dni) : '';
        editTec2Dni.placeholder = match && !editTec2Dni.value ? 'Escriba el DNI del T2 (8 dígitos)' : 'Ingrese el DNI si aún no está registrado';
        const btn = this.closest('.grid-bitacora')?.querySelector('.btn-editar-tecnico[data-tipo="2"]');
        if (btn) btn.disabled = !match;
        if (!match && document.getElementById('edit_brevete')?.value === 'Tecnico 2') {
          document.getElementById('edit_brevete').value = 'Tecnico 1';
        }
      });
    }

    setupPhotoPreview('edit_foto1', 'edit_preview_foto1');
    setupPhotoPreview('edit_foto2', 'edit_preview_foto2');
    setupPhotoPreview('edit_foto3', 'edit_preview_foto3');

    const editObsGrav = document.getElementById('edit_obs_gravedad');
    if (editObsGrav && editObsCat && editObsSub) {
      setupSmartObservationCascade(editObsGrav, editObsCat, editObsSub);
    }

    if (editChkObs && editBoxObs) {
      editChkObs.addEventListener('change', () => {
        editBoxObs.style.display = editChkObs.checked ? 'block' : 'none';
      });
    }
    if (editChkChecklist && editSecChecklist) {
      editChkChecklist.addEventListener('change', () => {
        editSecChecklist.style.display = editChkChecklist.checked ? 'block' : 'none';
      });
    }
    
    // Pendiente ya iniciada por su supervisor (2026-10-16): se guarda sola; abajo solo "Cancelar" y "Finalizar".
    const modoAutoguardado = Boolean(document.getElementById('edit_autoguardado')) && !forceAdminEdit;
    autoguardadoEdicion?.detener();
    autoguardadoEdicion = null;
    if (modoAutoguardado && formDetalle) {
      autoguardadoEdicion = crearAutoguardado({
        indicador: document.getElementById('edit_autoguardado'),
        construir: () => limpiarParaAutoguardado(construirDatosEdicion('En proceso'), 'edit'),
        alGuardar: datos => {
          if (fotosServicioSubidas(datos, 'edit')) { const marca = document.getElementById('edit_tiene_foto_servicio'); if (marca) marca.value = '1'; }
        },
        advertencia: () => avisoDni('edit'),
      });
      escucharCambios(formDetalle, () => autoguardadoEdicion);
      vigilarDni(formDetalle, 'edit');
      autoguardadoEdicion.activar();
      document.getElementById('cancelarEdicionBtn')?.addEventListener('click', async () => {
        if (!confirm('¿Seguro que deseas cancelar esta supervisión? La orden vuelve a Pendientes con sus datos originales.')) return;
        const guardado = autoguardadoEdicion;
        guardado?.detener();
        if (!(await cancelarSupervision(rec.id))) { guardado?.activar(); return; }
        autoguardadoEdicion = null;
        closeDetail();
      });
    }

    const updateDetailWorkflowButton = () => {
      if (!saveBtn || !formDetalle || rec.estado === 'pendiente' || rec.estado === 'finalizada') return;
      const resultado = document.getElementById('edit_resultado_cancelacion');
      const usaResultado = resultado && !resultado.disabled;
      if (usaResultado && resultado.value === 'En proceso de rescate') {
        saveBtn.dataset.action = 'rescate';
        saveBtn.classList.add('btn-finish-bitacora'); saveBtn.classList.remove('btn-ghost-bitacora');
        saveBtn.innerHTML = '<i class="bi bi-life-preserver me-1"></i>Pasar a rescate';
        return;
      }
      const finishReady = validarCierreSupervision(formDetalle, false);
      // 2026-10-15: con todo completo pero sin "Resultado de la validación", el botón señala ese punto y no finaliza.
      const faltaResultado = finishReady && usaResultado && !resultado.value;
      if (modoAutoguardado) {
        // 2026-10-16: siempre "Finalizar"; si falta algo, al presionarlo se muestra la lista.
        saveBtn.dataset.action = faltaResultado ? 'resultado' : (finishReady ? 'finish' : 'faltantes');
        saveBtn.classList.toggle('btn-finish-bitacora', finishReady && !faltaResultado);
        saveBtn.classList.toggle('btn-orange-bitacora', !finishReady || faltaResultado);
        saveBtn.classList.remove('btn-ghost-bitacora');
        saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Finalizar supervisión';
        return;
      }
      saveBtn.dataset.action = faltaResultado ? 'resultado' : (finishReady ? 'finish' : 'progress');
      saveBtn.classList.toggle('btn-finish-bitacora', finishReady && !faltaResultado);
      saveBtn.classList.toggle('btn-ghost-bitacora', !finishReady || faltaResultado);
      saveBtn.innerHTML = faltaResultado
        ? '<i class="bi bi-exclamation-circle me-1"></i>Seleccione el resultado de la validación'
        : (finishReady ? '<i class="bi bi-check-circle me-1"></i>Finalizar supervisión' : '<i class="bi bi-save2 me-1"></i>Guardar avance y volver al listado');
    };
    formDetalle?.addEventListener('input', updateDetailWorkflowButton);
    formDetalle?.addEventListener('change', updateDetailWorkflowButton);
    document.getElementById('edit_checklist_firma_canvas')?.addEventListener('pointerup', updateDetailWorkflowButton);
    updateDetailWorkflowButton();

    // Resultado de la cancelación: "En proceso de rescate" no finaliza; pasa la orden a rescate con seguimiento.
    const selResultado = document.getElementById('edit_resultado_cancelacion');
    const ayudaResultado = document.getElementById('edit_resultado_cancelacion_ayuda');
    const textoBotonOriginal = saveBtn?.textContent;
    const actualizarResultado = () => {
      if (!selResultado) return;
      const ayudas = {
        'Cancelación correcta': 'La cancelación es correcta: se valida y se finaliza.',
        'En proceso de rescate': 'Pasa a rescate: queda a su cargo y le da seguimiento hasta cerrarla (no ocupa su supervisión en proceso).',
        'Rescatado': 'El servicio se rescató: se finaliza como rescatada.',
      };
      const pendienteResultado = !selResultado.disabled && !selResultado.value;
      if (ayudaResultado) ayudaResultado.textContent = ayudas[selResultado.value]
        || (pendienteResultado ? 'Obligatorio para finalizar: indique si la cancelación es correcta, si pasa a rescate o si se rescató.' : '');
      const cajaResultado = document.getElementById('edit_resultado_cancelacion_box');
      cajaResultado?.classList.toggle('sgs-campo-pendiente', pendienteResultado);
      if (!pendienteResultado) cajaResultado?.classList.remove('is-alerta');
      // Pendiente sin iniciar: el botón es "Iniciar supervisión" o "Pasar a rescate"; ya iniciada, lo decide updateDetailWorkflowButton.
      if (rec.estado === 'pendiente') { if (saveBtn) saveBtn.textContent = selResultado.value === 'En proceso de rescate' ? 'Pasar a rescate' : textoBotonOriginal; }
      else updateDetailWorkflowButton();
    };
    const senalarResultado = () => {
      const caja = document.getElementById('edit_resultado_cancelacion_box');
      caja?.classList.add('is-alerta');
      selResultado?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      selResultado?.focus({ preventScroll: true });
      showToast('Seleccione el resultado de la validación para finalizar.');
    };
    selResultado?.addEventListener('change', actualizarResultado);
    actualizarResultado();

    if(saveBtn) {
      saveBtn.addEventListener('click', async () => {
        if (selResultado?.value === 'En proceso de rescate') {
          if (!confirm('¿Pasar esta orden a rescate? Quedará a su cargo hasta cerrarla.')) return;
          await enviarFormularioEdicion('En proceso', cancelacionAunPendiente);
          return;
        }
        if (saveBtn.dataset.action === 'resultado') { senalarResultado(); return; }
        if (modoAutoguardado) {
          const faltan = faltantesEdicion(formDetalle);
          if (faltan.length) { mostrarFaltantes(faltan); return; }
          if (selResultado && !selResultado.disabled && !selResultado.value) { senalarResultado(); return; }
          if (!(await autoguardadoEdicion?.ahora())) { showToast('Sin conexión: no se pudo guardar. Inténtelo cuando vuelva la señal.'); return; }
          autoguardadoEdicion?.detener();
          document.getElementById('edit_estado_supervision').value = 'Finalizada';
          const ok = await enviarFormularioEdicion('Finalizada');
          if (!ok) autoguardadoEdicion?.activar();
          return;
        }
        const puedeFinalizar = rec.estado !== 'pendiente'
          && rec.estado !== 'finalizada'
          && validarCierreSupervision(formDetalle, false);
        if (puedeFinalizar && selResultado && !selResultado.disabled && !selResultado.value) { senalarResultado(); return; }
        const estadoGuardado = rec.estado === 'pendiente'
          ? 'Iniciada'
          : (rec.estado === 'finalizada' ? 'Finalizada' : (puedeFinalizar ? 'Finalizada' : 'En proceso'));
        if (estadoGuardado === 'Finalizada') document.getElementById('edit_estado_supervision').value = 'Finalizada';
        await enviarFormularioEdicion(estadoGuardado, cancelacionAunPendiente);
      });
    }
    openRescueBtn?.addEventListener('click', async () => {
      await enviarFormularioEdicion('En proceso', false, true);
    });
    savePartnerObservation?.addEventListener('click',async()=>{
      const data=new FormData();
      data.append('supervision_id',rec.id);data.append('gravedad',document.getElementById('edit_obs_gravedad')?.value||'');data.append('categoria_id',document.getElementById('edit_obs_cat')?.value||'');data.append('subopcion_id',document.getElementById('edit_obs_sub')?.value||'');data.append('detalle_observacion',document.getElementById('edit_obs_det')?.value||'');
      for(let i=1;i<=3;i++){const photo=document.getElementById(`edit_foto${i}`);const comment=formDetalle.querySelector(`[name="foto_comentario_${i}"]`);if(photo?.files?.[0])data.append(`foto_${i}`,photo.files[0]);data.append(`foto_comentario_${i}`,comment?.value||'');}
      const response=await fetch('api/observaciones.php?action=create',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:data});const result=await response.json();showToast(result.message,result.status==='success'?'success':'error');if(result.status==='success')openDetail(rec.id);
    });

    if(reopenBtn) {
      reopenBtn.addEventListener('click', async () => {
        document.getElementById('edit_estado_supervision').value = 'En proceso';
        await enviarFormularioEdicion('En proceso');
      });
    }
  }

  function confirmarActualizacionDniTecnicos(formData, prefijo) {
    for (const tipo of [1,2]) {
      const select = document.getElementById(prefijo + '_tec' + tipo + '_nom');
      if (!select || select.disabled || !select.value) continue;
      const tecnico = listaTecnicos.find(t => t.nombre_tecnico === select.value);
      const dni = document.getElementById(prefijo + '_tec' + tipo + '_dni')?.value.trim() || '';
      if (!tecnico || !/^\d{8}$/.test(dni)) { showToast('Seleccione un técnico y registre su DNI de 8 dígitos.'); return false; }
      formData.set('tecnico' + tipo + '_id',tecnico.id);
      formData.set('confirmar_dni_tecnico_' + tipo,'1');
    }
    return true;
  }

  // Datos del formulario de detalle. Los campos bloqueados se agregan de forma explícita.
  function construirDatosEdicion(nuevoEstado) {
    const form = document.getElementById('form-edit-detail');
    if (!form) return null;
    const formData = new FormData(form);
    formData.set('estado_supervision', nuevoEstado);
    const modalidadEdicion = document.getElementById('edit_tipo_sup');
    if (modalidadEdicion) formData.set('tipo_supervision_inicial', modalidadEdicion.value);
    [
      ['tipo_supervision','edit_tipo_supervision'],
      ['sub_tipo_atencion','edit_sub_tipo_atencion'],
      ['derivacion_orden','edit_derivacion'],
      ['tipo_servicio','edit_tipo_servicio'],
      ['partner_id','edit_partner'],
      ['ticket_codigo','edit_ticket'],
      ['departamento','edit_departamento_visible'],
      ['provincia','edit_provincia_visible'],
      ['distrito','edit_distrito_visible']
    ].forEach(([nombre, id]) => {
      const campo = document.getElementById(id);
      if (campo && campo.value !== '') formData.set(nombre, campo.value);
    });
    return formData;
  }

  async function enviarFormularioEdicion(nuevoEstado, esInicioPendiente = false, abrirRescate = false){
    const form = document.getElementById('form-edit-detail');
    if(!form) return false;
    if (nuevoEstado === 'Finalizada' && !validarCierreSupervision(form)) return false;
    const formData = construirDatosEdicion(nuevoEstado);
    if(!confirmarActualizacionDniTecnicos(formData,'edit'))return false;
    if (abrirRescate) formData.set('abrir_rescate', '1');

    try {
      showToast('Guardando datos y subiendo fotos...');
      const res = await fetch('api/supervisiones.php?action=create', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if(data.status === 'success') {
        const msg = (nuevoEstado === 'Finalizada')
          ? 'Supervisión FINALIZADA correctamente.'
          : (esInicioPendiente ? 'Supervisión iniciada correctamente.' : 'Avance/cambios guardados.');
        showToast(msg);
        if (nuevoEstado === 'Finalizada') listaTecnicos = []; // la cuadrilla del líder pudo cambiar al cerrar
        closeDetail();
        // Pendiente recién iniciada: se reabre En proceso, con los campos de la validación habilitados.
        const idIniciada = Number(formData.get('supervision_id_edit') || 0);
        if (esInicioPendiente && idIniciada && !abrirRescate) {
          try { await loadRecords(); } catch (e) { console.error(e); }
          openDetail(idIniciada);
        }
        return true;
      } else {
        showToast(data.message || 'Error al guardar.');
      }
    } catch(err) {
      showToast('Error de conexión al guardar.');
    }
    return false;
  }

  function validarCierreSupervision(form, showError = true) {
    const faltan = faltantesEdicion(form);
    if (!faltan.length) return true;
    if (showError) { showToast(faltan[0].texto); irAlCampo(faltan[0].el); }
    return false;
  }

  // Todo lo que falta para finalizar en el formulario de detalle: [{el, texto}] (2026-10-16).
  function faltantesEdicion(form) {
    const lista = [];
    const valor = id => document.getElementById(id)?.value.trim() || '';
    const visibleHabilitado = el => el && !el.disabled && el.offsetParent !== null;
    const marcarFaltante = (el, mensaje) => { lista.push({ el, texto: mensaje }); return false; };
    const remotaEdicion = esModalidadRemota('edit_tipo_sup');
    const noAplicaEnRemota = ['edit_tec1_nom','edit_tec1_dni','edit_brevete','edit_u_tipo','edit_u_placa','edit_u_estado','edit_latitud','edit_longitud'];
    const obligatoriosVisibles = [
      'edit_tipo_supervision','edit_sub_tipo_atencion','edit_derivacion','edit_tipo_servicio',
      'edit_tipo_sup','edit_partner','edit_ticket','edit_tec1_nom','edit_tec1_dni',
      'edit_brevete','edit_u_tipo','edit_u_placa','edit_u_estado','edit_latitud','edit_longitud',
      'edit_resultado_tecnico','edit_estado_servicio','edit_motivo_cancelacion'
      ,'edit_estado_cancelacion'
    ].filter(id => !(remotaEdicion && noAplicaEnRemota.includes(id))).map(id => document.getElementById(id));
    obligatoriosVisibles.filter(el => visibleHabilitado(el) && !String(el.value || '').trim())
      .forEach(el => marcarFaltante(el, etiquetaCampo(el)));

    const dniT1 = document.getElementById('edit_tec1_dni');
    if (visibleHabilitado(dniT1) && valor('edit_tec1_dni') && !/^\d{8}$/.test(valor('edit_tec1_dni'))) {
      marcarFaltante(dniT1, 'El DNI del técnico líder debe tener 8 dígitos.');
    }
    const dniT2 = document.getElementById('edit_tec2_dni');
    if (valor('edit_tec2_nom') && visibleHabilitado(dniT2) && !/^\d{8}$/.test(valor('edit_tec2_dni'))) {
      marcarFaltante(dniT2, 'DNI del técnico de apoyo (T2): debe tener 8 dígitos.');
    }

    const tieneFotoGuardada = document.getElementById('edit_tiene_foto_servicio')?.value === '1';
    const inputsFotoServicio = ['edit_foto_serv_1','edit_foto_serv_2','edit_foto_serv_3']
      .map(id => document.getElementById(id));
    const tieneFotoNueva = inputsFotoServicio.some(input => input?.files?.length);
    const esChecklistPuro = valor('edit_tipo_supervision') === 'Checklist';
    if (!esChecklistPuro && !tieneFotoGuardada && !tieneFotoNueva) {
      marcarFaltante(inputsFotoServicio[0], remotaEdicion
        ? 'Suba la captura de la videollamada como foto de evidencia para finalizar la supervisión remota.'
        : 'Suba al menos una foto de evidencia para finalizar la supervisión.');
    }

    const chkObs = document.getElementById('edit_chk_obs');
    if (chkObs?.checked) {
      const camposObs = ['edit_obs_gravedad','edit_obs_cat','edit_obs_sub','edit_obs_det'].map(id => document.getElementById(id));
      camposObs.filter(el => el && !String(el.value || '').trim())
        .forEach(el => marcarFaltante(el._comboInput || el, 'Observación: ' + etiquetaCampo(el)));
      const fotosObs = [1, 2, 3].map(i => document.getElementById(`edit_foto${i}`)).filter(Boolean);
      if (fotosObs.length && !fotosObs.some(foto => foto.files?.length)) marcarFaltante(fotosObs[0], 'Adjunte al menos una foto de evidencia de la observación.');
    }

    const chkChecklist = document.getElementById('edit_chk_checklist');
    if (chkChecklist?.checked) {
      const filas = [...form.querySelectorAll('#edit_sec_checklist .checklist-row:not(.checklist-venc)')];
      const filasIncompletas = filas.filter(fila => !fila.querySelector('input.checklist-estado:checked'));
      if (filasIncompletas.length) {
        marcarFaltante(filasIncompletas[0].querySelector('input.checklist-estado'), `Checklist técnico: ${filasIncompletas.length} ${filasIncompletas.length === 1 ? 'ítem' : 'ítems'} sin estado.`);
      }
      const firmaChecklist = document.getElementById('edit_checklist_firma_data');
      if (firmaChecklist && !firmaChecklist.value) {
        marcarFaltante(document.getElementById('edit_checklist_firma_canvas') || firmaChecklist, 'Dibuje la firma del supervisor para validar el checklist.');
      }
    }
    const esCancelacionValidada = Boolean(form.querySelector('input[name="es_cancelacion"][value="1"]'));
    if (esCancelacionValidada) {
      const resumenCancelacion = document.getElementById('edit_obs');
      if (!String(resumenCancelacion?.value || '').trim()) {
        marcarFaltante(resumenCancelacion, 'Registre el resumen u observación de la validación.');
      }
    }
    return lista;
  }

  // Volver: antes se guarda lo último que se escribió.
  if(backBtn) backBtn.addEventListener('click', async () => {
    if (autoguardadoEdicion && !(await autoguardadoEdicion.ahora())
      && !confirm('No se pudieron guardar los últimos cambios (sin conexión). ¿Volver al listado de todos modos?')) return;
    closeDetail();
  });

  // ---------- VISTA 2: NAVEGACIÓN Y CREACIÓN EN PANTALLA DEDICADA ----------
  const newSectionOneIds = ['new_tipo_supervision','new_sub_tipo_atencion','new_derivacion','new_tipo_servicio','new_tipo_sup','new_dep','new_prov','new_dis'];

  // Todo lo que falta para finalizar la supervisión nueva / en proceso: [{el, texto}] (2026-10-16).
  function faltantesNuevo() {
    const lista = [];
    const vistos = new Set();
    const agregar = (el, texto) => { if (!el || vistos.has(el)) return; vistos.add(el); lista.push({ el, texto: texto || etiquetaCampo(el) }); };
    const vacio = el => !String(el?.value || '').trim();
    newSectionOneIds.map(id => document.getElementById(id))
      .filter(f => f && !f.disabled && (vacio(f) || !f.checkValidity())).forEach(f => agregar(f));
    const tipo = newTipoSupervision?.value || '';
    const tiposSinPedido = ['Incidencia / Casos atípicos','Proyecto de ordenamiento','Visita partner por capacitación','Conjunta PEXT','Checklist'];
    const tiposSinPartner = ['Incidencia / Casos atípicos'];
    const tiposSinCuadrilla = ['Incidencia / Casos atípicos','Visita partner por capacitación'];
    const modalidad = newTipoSupModalidad?.value || '';
    if (!tiposSinPedido.includes(tipo) && vacio(newTicketCodigo)) agregar(newTicketCodigo);
    if (!tiposSinPartner.includes(tipo) && vacio(newPartner)) agregar(newPartner);
    const remota = modalidad === 'Remota';
    if (modalidad !== 'En frío' && !remota && !tiposSinCuadrilla.includes(tipo)) {
      if (vacio(newTec1Nom)) agregar(newTec1Nom);
      else if (!/^\d{8}$/.test(newTec1Dni?.value.trim() || '')) agregar(newTec1Dni, 'DNI del técnico líder (8 dígitos)');
      if (newTec2Nom?.value && !/^\d{8}$/.test(newTec2Dni?.value.trim() || '')) agregar(newTec2Dni, 'DNI del técnico de apoyo (T2), 8 dígitos');
      ['new_u_tipo','new_u_placa','new_u_estado'].map(id => document.getElementById(id)).filter(vacio).forEach(f => agregar(f));
    }
    const lat = Number(document.getElementById('new_latitud')?.value);
    const lng = Number(document.getElementById('new_longitud')?.value);
    if (!remota && (vacio(document.getElementById('new_latitud')) || !Number.isFinite(lat) || lat < -90 || lat > 90 || !Number.isFinite(lng) || lng < -180 || lng > 180)) {
      agregar(document.getElementById('new_latitud'), 'Ubicación GPS (presione "Actualizar ubicación")');
    }
    if (['Avería','Reiterada','Garantía'].includes(tipo) && vacio(newResultadoTecnico)) agregar(newResultadoTecnico);
    const hasNewEvidence = ['new_foto_serv_1','new_foto_serv_2','new_foto_serv_3'].some(id => document.getElementById(id)?.files?.length);
    if (tipo !== 'Checklist' && !newHasSavedEvidence && !hasNewEvidence) agregar(document.getElementById('new_foto_serv_1'), 'Al menos una foto de evidencia del servicio');
    if (tipo !== 'Checklist' && vacio(document.getElementById('new_obs'))) agregar(document.getElementById('new_obs'));
    if (newChkObs?.checked) {
      ['new_obs_gravedad','new_obs_cat','new_obs_sub','new_obs_det'].map(id => document.getElementById(id))
        .filter(vacio).forEach(f => agregar(f._comboInput || f, 'Observación: ' + etiquetaCampo(f)));
      // 2026-10-19: al menos una foto de la observación (antes las 3 obligatorias).
      const fotosObs = ['new_foto1','new_foto2','new_foto3'].map(id => document.getElementById(id)).filter(Boolean);
      if (fotosObs.length && !fotosObs.some(f => f.files?.length)) agregar(fotosObs[0], 'Al menos una foto de evidencia de la observación');
    }
    if (tipo === 'Checklist' || Boolean(newChkChecklist?.checked)) {
      const filas = [...formInicio.querySelectorAll('#new_sec_checklist .checklist-row:not(.checklist-venc)')]
        .filter(fila => !fila.querySelector('input.checklist-estado:checked'));
      if (filas.length) agregar(filas[0].querySelector('input.checklist-estado'), `Checklist técnico: ${filas.length} ${filas.length === 1 ? 'ítem' : 'ítems'} sin estado`);
      if (!document.getElementById('new_checklist_firma_data')?.value) agregar(document.getElementById('new_checklist_firma_canvas'), 'Firma del supervisor en el checklist');
      const comentario = document.getElementById('new_checklist_obs_general');
      if (vacio(comentario)) agregar(comentario);
    }
    [...formInicio.querySelectorAll('[required]')]
      .filter(f => !f.disabled && f.type !== 'radio' && f.offsetParent !== null && vacio(f)).forEach(f => agregar(f));
    return lista;
  }

  function updateNewWorkflowButton() {
    if (!startBtn || !startBtnLabel) return;
    const enProceso = newWorkflowState === 'progress';
    const completo = enProceso && faltantesNuevo().length === 0;
    startBtn.disabled = !enProceso;
    startBtn.dataset.action = 'finish';
    startBtnLabel.textContent = 'Finalizar supervisión';
    startBtn.classList.toggle('btn-finish-bitacora', completo);
    startBtn.classList.toggle('btn-orange-bitacora', !completo);
    if (cancelSupBtn) cancelSupBtn.disabled = !enProceso;
    if (newWorkflowStatus) newWorkflowStatus.textContent = completo
      ? 'Formulario completo. Ya puede finalizar la supervisión.'
      : 'Todo se guarda solo mientras llena el formulario. Al finalizar se le indicará lo que falte.';
  }

  // Nombre visible de un campo (su etiqueta, sin el asterisco).
  function etiquetaCampo(el) {
    if (!el) return 'Campo pendiente';
    const label = (el.id && document.querySelector(`label[for="${el.id}"]`)) || el.closest('.field-bitacora')?.querySelector('label');
    return (label?.textContent || el.getAttribute('aria-label') || el.name || 'Campo pendiente').replace(/\*/g, '').replace(/\s+/g, ' ').trim();
  }

  // Lleva al campo pendiente (en un buscador, a su caja de texto; en la firma, al recuadro).
  function irAlCampo(el) {
    if (!el) return;
    const destino = el.type === 'hidden' ? (el.closest('.field-bitacora') || el.parentElement) : el;
    const caja = destino?.closest?.('.field-bitacora');
    caja?.classList.add('is-alerta');
    setTimeout(() => caja?.classList.remove('is-alerta'), 4000);
    destino?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    destino?.focus?.({ preventScroll: true });
  }

  let faltantesMostrados = [];
  function mostrarFaltantes(lista) {
    faltantesMostrados = lista;
    const ul = document.getElementById('faltantesLista');
    const modal = document.getElementById('modalFaltantes');
    if (!ul || !modal) { showToast(lista[0]?.texto || 'Complete los datos pendientes.'); irAlCampo(lista[0]?.el); return; }
    ul.innerHTML = lista.map((item, i) => `<li><button type="button" data-faltante="${i}"><i class="bi bi-exclamation-circle"></i><span>${escapeHtml(item.texto)}</span><i class="bi bi-chevron-right ms-auto"></i></button></li>`).join('');
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }
  const irAFaltante = indice => {
    const modal = document.getElementById('modalFaltantes');
    const destino = faltantesMostrados[indice]?.el;
    modal?.addEventListener('hidden.bs.modal', () => irAlCampo(destino), { once: true });
    bootstrap.Modal.getOrCreateInstance(modal).hide();
  };
  document.getElementById('faltantesLista')?.addEventListener('click', e => {
    const boton = e.target.closest('[data-faltante]');
    if (boton) irAFaltante(Number(boton.dataset.faltante));
  });
  document.getElementById('faltantesIr')?.addEventListener('click', () => irAFaltante(0));

  // ---------- GUARDADO AUTOMÁTICO (2026-10-16) ----------
  // Mientras la supervisión está En proceso, cada cambio se guarda solo (estado "En proceso"). El checklist, la
  // observación y sus fotos se envían al finalizar (mientras tanto quedan en este celular, ver borrador local).
  function crearAutoguardado({ indicador, construir, alGuardar, advertencia }) {
    let temporizador = null, enCurso = null, otraVez = false, activo = false, fallo = false;
    const pintar = (texto, estado = '') => {
      if (!indicador) return;
      indicador.textContent = texto;
      indicador.title = texto;
      indicador.dataset.estado = estado;
    };
    const esperarFotos = async () => {
      for (let i = 0; i < 60 && document.querySelector('input[type="file"][data-comprimiendo="1"]'); i++) await new Promise(r => setTimeout(r, 250));
    };
    async function guardar() {
      if (!activo) return true;
      clearTimeout(temporizador); temporizador = null;
      if (enCurso) { otraVez = true; return enCurso; }
      enCurso = (async () => {
        await esperarFotos();
        const datos = construir();
        if (!datos) return true;
        pintar('Guardando…', 'guardando');
        try {
          const res = await fetch('api/supervisiones.php?action=create', { method: 'POST', body: datos });
          const data = await res.json();
          if (data.status === 'success') {
            fallo = false;
            alGuardar?.(datos, data);
            const hora = new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false });
            const aviso = advertencia?.() || '';
            pintar(aviso ? `Guardado ${hora} · ${aviso}` : `Guardado ${hora}`, aviso ? 'error' : 'ok');
            return true;
          }
          fallo = false;
          pintar(data.message || 'No se pudo guardar.', 'error');
          showToast(data.message || 'No se pudo guardar el avance.');
          return false;
        } catch (_) {
          fallo = true;
          pintar('Sin conexión: se guardará al volver la señal', 'error');
          return false;
        }
      })();
      const resultado = await enCurso;
      enCurso = null;
      if (otraVez && activo) { otraVez = false; return guardar(); }
      return resultado;
    }
    return {
      programar(ms = 1500) {
        if (!activo) return;
        pintar('Cambios sin guardar…', 'pendiente');
        clearTimeout(temporizador);
        temporizador = setTimeout(guardar, ms);
      },
      // Guarda ya lo que esté pendiente (antes de volver, finalizar o cerrar). Devuelve true si quedó guardado.
      async ahora() {
        if (enCurso) await enCurso;
        if (temporizador || otraVez || fallo) return guardar();
        return true;
      },
      reintentar() { if (activo && fallo) guardar(); },
      activar() { activo = true; fallo = false; pintar('', ''); },
      detener() { activo = false; otraVez = false; clearTimeout(temporizador); temporizador = null; },
    };
  }
  window.addEventListener('online', () => { autoguardadoNuevo?.reintentar(); autoguardadoEdicion?.reintentar(); });
  document.addEventListener('visibilitychange', () => {
    // Si el celular pasa a segundo plano (llamada, cámara, se apaga la pantalla) se guarda en el momento.
    if (document.visibilityState === 'hidden') { autoguardadoNuevo?.ahora(); autoguardadoEdicion?.ahora(); }
  });

  // DNI del T1 y T2 (2026-10-19): con el técnico elegido, su DNI debe tener 8 dígitos. Si no, se marca el campo y se
  // avisa junto a "Guardado"; lo demás se sigue guardando y el técnico ya guardado no se borra (el servidor lo conserva).
  function revisarDniTecnicos(prefijo) {
    const problemas = [];
    [1, 2].forEach(n => {
      const nombre = document.getElementById(`${prefijo}_tec${n}_nom`);
      const dni = document.getElementById(`${prefijo}_tec${n}_dni`);
      if (!nombre || !dni) return;
      const mal = Boolean(nombre.value) && !dni.disabled && dni.offsetParent !== null && !/^\d{8}$/.test(dni.value.trim());
      const caja = dni.closest('.field-bitacora');
      caja?.classList.toggle('sgs-dni-error', mal);
      let texto = caja?.querySelector('.sgs-dni-error-texto');
      if (mal && caja && !texto) { texto = document.createElement('small'); texto.className = 'sgs-dni-error-texto'; caja.append(texto); }
      if (texto) texto.textContent = !mal ? '' : (dni.value.trim() ? 'El DNI debe tener 8 dígitos.' : `Escriba el DNI del ${n === 1 ? 'técnico líder' : 'técnico de apoyo'}.`);
      if (mal && !dni.value.trim()) dni.placeholder = `Escriba el DNI del T${n} (8 dígitos)`;
      if (mal) problemas.push(`T${n}`);
    });
    return problemas;
  }
  const avisoDni = prefijo => { const p = revisarDniTecnicos(prefijo); return p.length ? `Revise el DNI del ${p.join(' y ')}` : ''; };
  // Se revisa al salir del campo; mientras se escribe, solo se quita la marca cuando ya está bien.
  const vigilarDni = (form, prefijo) => {
    form?.addEventListener('change', e => { if (/_tec\d_(nom|dni)$/.test(e.target.id || '')) revisarDniTecnicos(prefijo); });
    form?.addEventListener('input', e => {
      if (/_tec\d_dni$/.test(e.target.id || '') && /^\d{8}$/.test(e.target.value.trim())) revisarDniTecnicos(prefijo);
    });
  };

  // Quita lo que se envía recién al finalizar y lo que aún no está completo (evita errores y datos a medias).
  function limpiarParaAutoguardado(datos, prefijo) {
    if (!datos) return null;
    [...new Set(datos.keys())].forEach(clave => {
      if (/^(checklist_|venc_|chk_incluir_|obs_|foto_comentario_|confirmar_dni_|abrir_rescate|resultado_cancelacion)/.test(clave) || /^foto_[123]$/.test(clave)) datos.delete(clave);
    });
    const dni = n => document.getElementById(`${prefijo}_tec${n}_dni`)?.value.trim() || '';
    const nombre = n => document.getElementById(`${prefijo}_tec${n}_nom`)?.value || '';
    // Técnico con DNI incompleto: no se envía; el servidor conserva el que ya estaba guardado (no se borra).
    [1, 2].forEach(n => {
      if (nombre(n) && !/^\d{8}$/.test(dni(n))) ['nombre', 'dni', 'id'].forEach(c => datos.delete(`tecnico${n}_${c}`));
    });
    const placa = String(datos.get('unidad_placa') || '').trim();
    if (placa && !/^[A-Za-z0-9]{3}-?[A-Za-z0-9]{3}$/.test(placa)) datos.delete('unidad_placa'); // placa a medio escribir
    datos.set('action', 'create');
    datos.set('estado_supervision', 'En proceso');
    return datos;
  }

  // Fotos del servicio que ya subieron: se vacía el campo (quedan guardadas) para no volver a enviarlas.
  function fotosServicioSubidas(datos, prefijo) {
    let alguna = false;
    [1, 2, 3].forEach(n => {
      const input = document.getElementById(`${prefijo}_foto_serv_${n}`);
      const enviada = datos.get(`foto_servicio_${n}`);
      if (input && enviada instanceof File && enviada.size > 0 && input.files?.[0] === enviada) { input.value = ''; alguna = true; }
      else if (enviada instanceof File && enviada.size > 0) alguna = true;
    });
    return alguna;
  }

  function escucharCambios(form, obtener) {
    form.addEventListener('change', e => { if (!e.target.closest('[data-sin-autoguardado]')) obtener()?.programar(800); });
    form.addEventListener('input', e => { if (!e.target.closest('[data-sin-autoguardado]')) obtener()?.programar(2500); });
  }

  async function cancelarSupervision(id) {
    const datos = new FormData();
    datos.set('id', id);
    try {
      const res = await fetch('api/supervisiones.php?action=cancelar', { method: 'POST', body: datos });
      const data = await res.json();
      if (data.codigo === 'no_existe') { showToast(data.message); return true; } // ya se canceló en otro equipo: solo se cierra
      if (data.status !== 'success') { showToast(data.message || 'No se pudo cancelar la supervisión.'); return false; }
      try { localStorage.removeItem(claveBorrador(id)); } catch (_) {}
      showToast(data.message || 'Supervisión cancelada.');
      return true;
    } catch (_) {
      showToast('Sin conexión: no se pudo cancelar. Inténtelo cuando vuelva la señal.');
      return false;
    }
  }

  // Borrador local: checklist, firma y observación se guardan en este celular hasta finalizar (2026-10-16).
  const claveBorrador = id => `sgs_borrador_sup_${id}`;
  let borradorActivo = false; // solo con el formulario ya cargado (al limpiarlo o llenarlo no se pisa el borrador)
  const esCampoDeBorrador = el => el.name && el.type !== 'file' && el.type !== 'hidden'
    && /^(checklist_|venc_|chk_incluir_|obs_|foto_comentario_)/.test(el.name);
  function guardarBorradorLocal() {
    const id = newSupervisionId?.value;
    if (!id || !borradorActivo || newWorkflowState !== 'progress') return;
    const campos = [];
    const repetidos = {};
    formInicio.querySelectorAll('input, select, textarea').forEach(el => {
      if (!esCampoDeBorrador(el)) return;
      const clave = el.id || `${el.name}#${repetidos[el.name] = (repetidos[el.name] ?? -1) + 1}`;
      if (el.type === 'radio') { if (el.checked) campos.push({ n: el.name, v: el.value, radio: 1 }); return; }
      if (el.type === 'checkbox') { campos.push({ k: clave, c: el.checked }); return; }
      if (el.value !== '') campos.push({ k: clave, v: el.value });
    });
    const firma = document.getElementById('new_checklist_firma_data')?.value || '';
    try { localStorage.setItem(claveBorrador(id), JSON.stringify({ campos, firma })); } catch (_) {}
  }
  function restaurarBorradorLocal(id) {
    let datos = null;
    try { datos = JSON.parse(localStorage.getItem(claveBorrador(id)) || 'null'); } catch (_) {}
    if (!datos || !Array.isArray(datos.campos)) return;
    const porClave = {};
    const repetidos = {};
    formInicio.querySelectorAll('input, select, textarea').forEach(el => {
      if (!esCampoDeBorrador(el) || el.type === 'radio') return;
      porClave[el.id || `${el.name}#${repetidos[el.name] = (repetidos[el.name] ?? -1) + 1}`] = el;
    });
    const aplicar = soloCasillas => datos.campos.forEach(c => {
      if (c.radio) {
        if (soloCasillas) return;
        const radio = [...formInicio.querySelectorAll('input[type="radio"]')].find(r => r.name === c.n && r.value === c.v);
        if (radio && !radio.checked) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
        return;
      }
      const el = porClave[c.k];
      if (!el) return;
      if ('c' in c) { if (soloCasillas && el.checked !== c.c) { el.checked = c.c; el.dispatchEvent(new Event('change', { bubbles: true })); } return; }
      if (!soloCasillas && el.value !== c.v) { el.value = c.v; el.dispatchEvent(new Event('change', { bubbles: true })); el._comboSync?.(); }
    });
    aplicar(true);   // primero las casillas: abren el checklist y la observación
    aplicar(false);
    setTimeout(() => aplicar(false), 300); // la subcategoría se llena después de elegir la categoría
    if (datos.firma) {
      const input = document.getElementById('new_checklist_firma_data');
      const canvas = document.getElementById('new_checklist_firma_canvas');
      if (input) input.value = datos.firma;
      const img = new Image();
      img.onload = () => { const ctx = canvas?.getContext('2d'); if (ctx) { ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.drawImage(img, 0, 0); } };
      img.src = datos.firma;
    }
  }

  function resetNewWorkflowForm() {
    autoguardadoNuevo?.detener();
    borradorActivo = false;
    formInicio?.reset();
    // reset() no dispara eventos: sin esto quedaban datos de la supervisión anterior a la vista (sección de
    // observación abierta con sus fotos, tipificación marcada como elegida, buscadores de T1/T2 bloqueados).
    if (newChkObs) newChkObs.dispatchEvent(new Event('change'));
    formInicio?.querySelectorAll('[data-obs-combo-box]').forEach(caja => caja._reset?.());
    formInicio?.querySelectorAll('[id^="new_preview_"]').forEach(preview => { preview.innerHTML = ''; preview.style.display = 'none'; });
    if (newPartner) newPartner.value = '';
    [newTec1Nom, newTec2Nom].forEach(select => select?.dispatchEvent(new Event('change')));
    newSectionOneIds.map(id => document.getElementById(id)).forEach(field => { if (field) field.disabled = false; });
    newWorkflowState = 'draft';
    newHasSavedEvidence = false;
    newFormDirty = false;
    if (newSupervisionId) newSupervisionId.value = '';
    ['new_preview_foto_serv_1','new_preview_foto_serv_2','new_preview_foto_serv_3'].forEach(id => {
      const preview = document.getElementById(id);
      if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
    });
    if (newFormTitle) newFormTitle.textContent = 'Nueva supervisión de campo';
    if (folioPreview) folioPreview.textContent = '';
    updateChecklistMode();
    updateNewWorkflowButton();
  }

  if (formInicio) {
    autoguardadoNuevo = crearAutoguardado({
      indicador: document.getElementById('new_autoguardado'),
      construir: () => {
        if (!newSupervisionId?.value) return null;
        const datos = new FormData(formInicio);
        newSectionOneIds.forEach(id => {
          const field = document.getElementById(id);
          if (field?.name && field.value !== '') datos.set(field.name, field.value);
        });
        return limpiarParaAutoguardado(datos, 'new');
      },
      alGuardar: datos => { if (fotosServicioSubidas(datos, 'new')) newHasSavedEvidence = true; },
      advertencia: () => avisoDni('new'),
    });
    escucharCambios(formInicio, () => autoguardadoNuevo);
    vigilarDni(formInicio, 'new');
    const alCambiarNuevo = () => { newFormDirty = true; updateNewWorkflowButton(); guardarBorradorLocal(); };
    formInicio.addEventListener('input', alCambiarNuevo);
    formInicio.addEventListener('change', alCambiarNuevo);
    document.getElementById('new_checklist_firma_canvas')?.addEventListener('pointerup', () => setTimeout(alCambiarNuevo, 0));
  }

  // Abrir una supervisión de la lista (2026-10-15). Una cancelación cargada por Excel SIEMPRE se trabaja en su
  // formulario de validación (tipificación, "Resultado de la validación", ubicación de origen bloqueada), también
  // después de iniciarla. Antes, ya "En proceso", se abría el formulario de registro nuevo: no mostraba la validación,
  // el distrito del Excel podía quedar vacío y la sección 1 no dejaba continuar.
  function abrirRegistro(rec) {
    const esCancelacionCargada = Number(rec.es_cancelacion) === 1 && Number(rec.origen_importacion) === 1;
    return rec.estado === 'iniciada' && !esCancelacionCargada ? openProcessForm(rec) : openDetail(rec.id);
  }

  async function openProcessForm(rec) {
    if (!(await verificarTurnoActivo())) return;
    resetNewWorkflowForm();
    newWorkflowState = 'progress';
    newFormDirty = false;
    if (newSupervisionId) newSupervisionId.value = rec.id;
    if (newFormTitle) newFormTitle.textContent = 'Supervisión en proceso';
    if (folioPreview) folioPreview.textContent = rec.ticket_codigo || rec.codigo;

    const setValue = (id, value) => {
      const field = document.getElementById(id);
      if (field && value !== null && value !== undefined) field.value = value;
    };
    setValue('new_tipo_supervision', rec.clasificacion);
    setValue('new_derivacion', rec.derivacion);
    setValue('new_tipo_servicio', rec.tipo_servicio);
    setValue('new_tipo_sup', rec.tipo_supervision_inicial);
    sgsActualizarRemota(document.getElementById('form-inicio-supervision'));
    updateChecklistMode();
    setValue('new_sub_tipo_atencion', rec.sub_tipo_atencion);
    setValue('new_cliente_nombre', rec.cliente_nombre);
    setValue('new_cliente_dni', rec.cliente_dni);
    setValue('new_ticket_codigo', rec.ticket_codigo);
    setValue('new_partner', rec.partner_id);
    setValue('new_brevete', rec.brevete_poseedor);
    setValue('new_u_tipo', rec.unidad_tipo);
    setValue('new_u_placa', rec.unidad_placa);
    setValue('new_u_estado', rec.unidad_estado);
    setValue('new_latitud', rec.latitud);
    setValue('new_longitud', rec.longitud);
    setValue('new_estado_servicio', rec.estado_servicio_caliente);
    setValue('new_motivo_cancelacion', rec.tipificacion_cancelacion);
    
    // Si la tipificación importada no está en las opciones del select, lo mostramos en la caja readonly.
    if (rec.tipificacion_cancelacion && rec.es_cancelacion == 1) {
        document.getElementById('new_imported_reason_box').style.display = 'block';
        document.getElementById('new_imported_reason_text').textContent = rec.tipificacion_cancelacion;
        setValue('new_tipificacion_cancelacion', rec.tipificacion_cancelacion);
    } else {
        document.getElementById('new_imported_reason_box').style.display = 'none';
        document.getElementById('new_imported_reason_text').textContent = '';
        setValue('new_tipificacion_cancelacion', rec.tipificacion_cancelacion);
    }
    
    setValue('new_resultado_rescate', rec.tipificacion_corregida);
    setValue('new_obs', rec.observaciones);

    await Promise.all([cargarListaTecnicos(), cargarCatalogoObservaciones()]);
    poblarSelectsTecnicos(rec.tecnico1_nombre || '');
    setValue('new_tec1_nom', rec.tecnico1_nombre);
    setTimeout(refrescarVencimientosNuevo, 0); // el técnico y la placa se cargaron sin avisar el cambio
    setValue('new_tec1_dni', rec.tecnico1_dni);
    setValue('new_tec2_nom', rec.tecnico2_nombre);
    setValue('new_tec2_dni', rec.tecnico2_dni);
    newTec1Nom?.dispatchEvent(new Event('change', {bubbles:true}));
    newTec2Nom?.dispatchEvent(new Event('change', {bubbles:true}));
    // Al elegir el técnico líder se autocompleta la cuadrilla desde el catálogo (T2 y vehículo).
    // En una supervisión ya guardada manda lo que registró el supervisor: se vuelve a aplicar.
    const setSaved = (id, value) => {
      const field = document.getElementById(id);
      if (!field || value === null || value === undefined || String(value).trim() === '') return;
      if (field.tagName === 'SELECT' && ![...field.options].some(o => o.value === String(value))) field.add(new Option(String(value), String(value)));
      field.value = value;
    };
    if (newTec1Nom?.value) { setSaved('new_tec2_nom', rec.tecnico2_nombre); setSaved('new_tec2_dni', rec.tecnico2_dni); bloquearT2('new'); }
    setSaved('new_brevete', rec.brevete_poseedor);
    setSaved('new_u_tipo', rec.unidad_tipo);
    setSaved('new_u_placa', rec.unidad_placa);
    setSaved('new_u_estado', rec.unidad_estado);

    await cargarUbigeoNew();
    const dep = document.getElementById('new_dep');
    const prov = document.getElementById('new_prov');
    const dis = document.getElementById('new_dis');

    // Función auxiliar para seleccionar opción sin importar mayúsculas/minúsculas
    const setSelectCaseInsensitive = (selectElement, textValue) => {
      if (!textValue || !selectElement) return;
      const norm = (v) => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
      const target = norm(textValue);
      const opcion = [...selectElement.options].find(o => o.value && norm(o.value) === target);
      if (opcion) { selectElement.value = opcion.value; return; }
      // No está en el catálogo (p. ej. escrito distinto en el Excel): se agrega tal cual para no perder el dato.
      selectElement.add(new Option(String(textValue), String(textValue)));
      selectElement.value = String(textValue);
    };

    if (dep) {
      setSelectCaseInsensitive(dep, rec.departamento);
      if (typeof dep.onchange === 'function') await dep.onchange();
    }
    if (prov) {
      setSelectCaseInsensitive(prov, rec.provincia);
      if (typeof prov.onchange === 'function') await prov.onchange();
    }
    if (dis) {
        setSelectCaseInsensitive(dis, rec.distrito);
    }

    // Bloquear campos importados que no deben editarse (según requerimiento)
    const lockedFields = ['new_tipo_supervision','new_derivacion','new_tipo_servicio','new_tipo_sup','new_dep','new_prov','new_dis','new_ticket_codigo','new_partner'];
    lockedFields.forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        if (rec.id && rec.es_cancelacion == 1) { // Si es una orden importada existente
          el.style.pointerEvents = 'none';
          el.style.backgroundColor = '#e9ecef';
          el.tabIndex = -1; // Quitar del tabulador
          if(el.tagName === 'INPUT') el.readOnly = true;
        } else {
          el.style.pointerEvents = 'auto';
          el.style.backgroundColor = '';
          el.tabIndex = 0;
          if(el.tagName === 'INPUT') el.readOnly = false;
        }
      }
    });

    newHasSavedEvidence = Boolean(rec.foto_servicio_1 || rec.foto_servicio_2 || rec.foto_servicio_3);
    [rec.foto_servicio_1, rec.foto_servicio_2, rec.foto_servicio_3].forEach((photo, index) => {
      const preview = document.getElementById(`new_preview_foto_serv_${index + 1}`);
      if (!preview) return;
      if (photo) {
        preview.style.display = 'block';
        preview.innerHTML = `<div class="small text-success fw-semibold mb-1"><i class="bi bi-check-circle"></i> Evidencia guardada</div><button type="button" class="btn p-0 border-0 bg-transparent" onclick="abrirFotoModal('${escapeHtml(photo)}','Evidencia ${index + 1}')"><img src="${escapeHtml(photo)}" alt="Evidencia guardada ${index + 1}" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;border:1px solid var(--win-border);"></button>`;
      } else {
        preview.innerHTML = '';
        preview.style.display = 'none';
      }
    });
    viewList.classList.add('hidden');
    viewDetail.classList.remove('open');
    viewNew.classList.add('open');
    updateChecklistMode();
    restaurarBorradorLocal(rec.id);
    newWorkflowState = 'progress';
    borradorActivo = true;
    autoguardadoNuevo?.activar();
    updateNewWorkflowButton();
    window.scrollTo({top:0, behavior:'smooth'});
  }

  // Antes de trabajar en una supervisión, el supervisor directo debe tener su turno de hoy iniciado (y no cerrado).
  // Devuelve true si puede seguir; si no, muestra el aviso con el botón "Iniciar turno".
  async function verificarTurnoActivo() {
    if (!esSupervisorWinDirecto) return true; // solo el personal directo registra turno (partners no)
    try {
      const res = await fetch('api/asistencia.php?action=status', { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      const asistencia = data && data.asistencia;
      if (asistencia && !asistencia.fin_turno) return true;
      const cerrado = !!(asistencia && asistencia.fin_turno);
      document.getElementById('sinTurnoTitulo').textContent = cerrado ? 'Tu turno de hoy ya terminó' : 'Inicia tu turno';
      document.getElementById('sinTurnoTexto').textContent = cerrado
        ? 'Ya finalizaste tu turno de hoy, por eso no puedes registrar ni continuar supervisiones. Si es un error, comunícate con tu coordinador.'
        : 'Para registrar supervisiones primero debes iniciar tu turno de hoy. Así queda registrada tu jornada y tu ubicación de inicio.';
      document.getElementById('sinTurnoIr').classList.toggle('d-none', cerrado);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSinTurno')).show();
      return false;
    } catch (_) {
      return true; // sin conexión: el servidor igual valida el turno al guardar
    }
  }

  // Nueva supervisión (2026-10-16): se crea al instante "En proceso" con su código interno y se guarda sola.
  // Si ya tiene una en proceso, se abre esa para continuar (una a la vez).
  async function openNewForm(){
    if (!(await verificarTurnoActivo())) return;
    if (toggleNewBtn) toggleNewBtn.disabled = true;
    let data = null;
    try {
      const res = await fetch('api/supervisiones.php?action=iniciar_nueva', { method: 'POST' });
      data = await res.json();
    } catch (_) {
      showToast('Sin conexión: no se pudo iniciar la supervisión.');
      return;
    } finally {
      if (toggleNewBtn) toggleNewBtn.disabled = false;
    }
    if (data?.status === 'activa') { showToast(data.message); await abrirSupervisionPorId(data.id); return; }
    if (data?.status !== 'success') { showToast(data?.message || 'No se pudo iniciar la supervisión.'); return; }

    resetNewWorkflowForm();
    newWorkflowState = 'progress';
    if (newSupervisionId) newSupervisionId.value = data.id;
    if (newFormTitle) newFormTitle.textContent = 'Supervisión en proceso';
    if (folioPreview) folioPreview.textContent = data.codigo;
    if (newPartner) newPartner.value = '';
    await Promise.all([cargarListaTecnicos(), cargarCatalogoObservaciones()]);
    [newTec1Nom,newTec2Nom].forEach(el => el?.dispatchEvent(new Event('change')));
    await cargarUbigeoNew();
    viewList.classList.add('hidden');
    viewDetail.classList.remove('open');
    viewNew.classList.add('open');
    borradorActivo = true;
    autoguardadoNuevo?.activar();
    updateNewWorkflowButton();
    window.scrollTo({top:0, behavior:'smooth'});
    // La ubicación se toma sola al abrir (2026-10-12); el supervisor puede actualizarla con el botón.
    capturarGPS('new_latitud', 'new_longitud', 'new_gps_status');
  }

  async function abrirSupervisionPorId(id) {
    let rec = records.find(r => r.id === Number(id));
    if (!rec) {
      try {
        const res = await fetch(`api/supervisiones.php?action=detail&id=${Number(id)}`);
        const data = await res.json();
        if (data.status === 'success' && data.supervision) { rec = normalizeSupervisionRecord(data.supervision); records.push(rec); }
      } catch (_) {}
    }
    if (!rec) { showToast('No se pudo abrir la supervisión en proceso.'); return; }
    abrirRegistro(rec);
  }

  function closeNewForm(){
    resetNewWorkflowForm();
    viewNew.classList.remove('open');
    viewDetail.classList.remove('open');
    viewList.classList.remove('hidden');
    loadRecords();
  }

  if(toggleNewBtn) toggleNewBtn.addEventListener('click', openNewForm);
  // Volver al listado: lo último que se escribió se guarda antes de salir.
  const returnFromNewForm = async () => {
    if (autoguardadoNuevo && !(await autoguardadoNuevo.ahora())
      && !confirm('No se pudieron guardar los últimos cambios (sin conexión). ¿Volver al listado de todos modos?')) return;
    closeNewForm();
  };
  if(cancelNewBtn) cancelNewBtn.addEventListener('click', returnFromNewForm);

  // Cancelar: una supervisión nueva se borra; una pendiente iniciada vuelve a Pendientes con sus datos originales.
  cancelSupBtn?.addEventListener('click', async () => {
    const id = newSupervisionId?.value;
    if (!id) return;
    if (!confirm('¿Seguro que deseas cancelar esta supervisión? Se borrará lo registrado.')) return;
    autoguardadoNuevo?.detener();
    cancelSupBtn.disabled = true;
    const ok = await cancelarSupervision(id);
    cancelSupBtn.disabled = false;
    if (!ok) { autoguardadoNuevo?.activar(); return; }
    closeNewForm();
  });
  if(formInicio) {
    formInicio.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (newWorkflowState !== 'progress') return;
      const faltan = faltantesNuevo();
      if (faltan.length) { mostrarFaltantes(faltan); return; }
      const finishing = true;
      if (!(await autoguardadoNuevo?.ahora())) { showToast('Sin conexión: no se pudo guardar. Inténtelo cuando vuelva la señal.'); return; }
      autoguardadoNuevo?.detener();
      const formData = new FormData(formInicio);
      // Los campos congelados no entran en FormData; se incluyen de forma explícita.
      newSectionOneIds.forEach(id => {
        const field = document.getElementById(id);
        if (field?.name && field.value !== '') formData.set(field.name, field.value);
      });
      if (finishing && !confirmarActualizacionDniTecnicos(formData,'new')) { autoguardadoNuevo?.activar(); return; }
      formData.append('action', 'create');
      formData.set('estado_supervision', 'Finalizada');
      const idFinalizada = newSupervisionId?.value;

      try {
        startBtn.disabled = true;
        showToast('Finalizando supervisión...');
        const res = await fetch('api/supervisiones.php?action=create', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if(data.status === 'success') {
          showToast(data.message || 'Supervisión guardada correctamente.');
          currentFilter = finishing ? 'finalizada' : 'iniciada';
          currentZoneFilter = '';
          currentSupervisorFilter = '';
          currentDateFromFilter = '';
          currentDateToFilter = '';
          currentCodeFilter = '';
          filterTabs?.querySelectorAll('.filter-tab-btn').forEach(button => {
            button.classList.toggle('active', button.dataset.filter === currentFilter);
          });
          ['filterCodigoSup','adminFilterZona','adminFilterSupervisor','adminFilterFechaDesde','adminFilterFechaHasta'].forEach(id => {
            const filter = document.getElementById(id);
            if (filter) filter.value = '';
          });
          try { localStorage.removeItem(claveBorrador(idFinalizada)); } catch (_) {}
          if (finishing) listaTecnicos = []; // al cerrar pudo cambiar la cuadrilla del líder (T2, vehículo): la próxima vuelve a pedir el catálogo
          resetNewWorkflowForm();
          closeNewForm();
          return;
        } else {
          showToast(data.message || 'No se pudo guardar la supervisión.');
          autoguardadoNuevo?.activar();
        }
      } catch(err) {
        showToast('Error de conexión.');
        autoguardadoNuevo?.activar();
      } finally {
        updateNewWorkflowButton();
      }
    });
  }

  // UBIGEO DINÁMICO PARA PANTALLA NUEVA
  async function cargarUbigeoNew() {
    const cboDep = document.getElementById('new_dep');
    const cboProv = document.getElementById('new_prov');
    const cboDis = document.getElementById('new_dis');
    if(!cboDep || !cboProv || !cboDis) return;

    try {
      const restrictDepartment = currentUserRole === 'Supervisor'
        && currentUserDepartment
        && currentUserDepartment.toLocaleUpperCase('es') !== 'PROVINCIAS';
      const rDep = await fetch('api/ubigeo.php?action=departamentos');
      const dDep = await rDep.json();
      if(dDep.status === 'success') {
        cboDep.innerHTML = '<option value="">Seleccione departamento</option>';
        const departments = dDep.data.map(d => (typeof d === 'object' && d !== null) ? (d.departamento || d) : d);
        const visibleDepartments = restrictDepartment
          ? departments.filter(value => String(value).toLocaleUpperCase('es') === String(currentUserZoneDepartment).toLocaleUpperCase('es'))
          : departments;
        visibleDepartments.forEach(val => {
          cboDep.innerHTML += `<option value="${val}">${val}</option>`;
        });
        if (restrictDepartment) {
          if (!visibleDepartments.length) {
            cboDep.innerHTML = `<option value="">Departamento ${escapeHtml(currentUserDepartment)} sin ubigeo configurado</option>`;
            showToast(`El departamento ${currentUserDepartment} no tiene provincias configuradas. Contacte al administrador.`);
          } else {
            cboDep.value = visibleDepartments[0];
          }
          cboDep.disabled = true;
        } else {
          cboDep.disabled = false;
        }
      }

      const loadProvinces = async () => {
        const dep = cboDep.value;
        if (!dep) {
          cboProv.innerHTML = '<option value="">Seleccione departamento</option>';
          cboDis.innerHTML = '<option value="">Seleccione provincia primero</option>';
          return;
        }
        cboProv.innerHTML = '<option value="">Cargando...</option>';
        cboDis.innerHTML = '<option value="">Seleccione provincia primero</option>';
        const zona = restrictDepartment ? `&zona=${encodeURIComponent(currentUserDepartment)}` : ''; // solo las provincias de su zona
        const r = await fetch(`api/ubigeo.php?action=provincias&departamento=${encodeURIComponent(dep)}${zona}`);
        const d = await r.json();
        if(d.status === 'success') {
          cboProv.innerHTML = '<option value="">Seleccione provincia</option>';
          d.data.forEach(p => {
            const val = (typeof p === 'object' && p !== null) ? (p.provincia || p) : p;
            cboProv.innerHTML += `<option value="${val}">${val}</option>`;
          });
        }
      };
      cboDep.onchange = loadProvinces;

      cboProv.onchange = async () => {
        const dep = cboDep.value;
        const prov = cboProv.value;
        poblarSelectsTecnicos(); // la provincia define la zona del T1 (LIMA / HUAURA → NORTE CHICO)
        cboDis.innerHTML = '<option value="">Cargando...</option>';
        const r = await fetch(`api/ubigeo.php?action=distritos&departamento=${encodeURIComponent(dep)}&provincia=${encodeURIComponent(prov)}`);
        const d = await r.json();
        if(d.status === 'success') {
          cboDis.innerHTML = '<option value="">Seleccione distrito</option>';
          d.data.forEach(di => {
            const val = (typeof di === 'object' && di !== null) ? (di.distrito || di) : di;
            cboDis.innerHTML += `<option value="${val}">${val}</option>`;
          });
        }
      };
      if (restrictDepartment && cboDep.value) await loadProvinces();
    } catch(e){}
  }

  initColumnSelector();
  document.getElementById('loadMoreSupervisions')?.addEventListener('click', () => {
    if (listHasMore) loadRecords({append:true});
  });
  const supervisionLayoutMedia = window.matchMedia('(max-width: 767.98px)');
  supervisionLayoutMedia.addEventListener?.('change', () => renderList());
  loadRecords();
})();
