<?php
// 03_FRONTEND/src/pages/supervision_lista.php - Registro de Supervisión (Diseño Ejecutivo WIN Telecom)
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$pdo = Database::getConnection();
$effectiveViewUser = $user;
$stmtRegisteredScope = $pdo->prepare('SELECT sede,zona FROM usuarios WHERE id=:id LIMIT 1');
$stmtRegisteredScope->execute([':id'=>(int)($effectiveViewUser['id'] ?? 0)]);
$registeredUserSite = $stmtRegisteredScope->fetch();
$registeredDepartmentScope = operationalDepartmentFromSite((string)(($registeredUserSite['sede'] ?? '') ?: ($registeredUserSite['zona'] ?? '')));
$esVistaSupervisorDirecto = ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
$esJefeOperacionesVista = ($effectiveViewUser['rol'] ?? '') === Auth::ROLE_JEFE_OPERACIONES;
$esSupervisorPartnerVista = $esJefeOperacionesVista
    || (($effectiveViewUser['rol'] ?? '') === Auth::ROLE_SUPERVISOR
        && empty($effectiveViewUser['is_win_directo'])
        && !empty($effectiveViewUser['partner_id']));
$canCreateSupervision = Auth::can(Auth::PERM_SUPERVISION_CREATE, $effectiveViewUser) && !empty($effectiveViewUser['is_win_directo']);
$canUseSupervisionFilters = Auth::can(Auth::PERM_SUPERVISION_FILTERS, $effectiveViewUser);
$canFilterSupervisionDate = $canUseSupervisionFilters && Auth::can(Auth::PERM_SUPERVISION_FILTER_DATE, $effectiveViewUser);
$canFilterSupervisionStatus = $canUseSupervisionFilters && Auth::can(Auth::PERM_SUPERVISION_FILTER_STATUS, $effectiveViewUser);
$canChooseSupervisionColumns = $canUseSupervisionFilters && Auth::can(Auth::PERM_SUPERVISION_FILTER_COLUMNS, $effectiveViewUser);
$canUsePendingMap = Auth::can(Auth::PERM_SUPERVISION_MAP, $effectiveViewUser);
$canUseFindingsForm = Auth::can(Auth::PERM_FORM_FINDINGS, $effectiveViewUser);
$canUseChecklistForm = Auth::can(Auth::PERM_FORM_CHECKLIST, $effectiveViewUser);

// Partners supervisables. WIN DIRECTO se conserva exclusivamente para registrar usuarios internos.
$stmtPart = $pdo->query("
    SELECT id, nombre_partner
    FROM partners
    WHERE estado = 1
      AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN', 'WIN DIRECTO')
    ORDER BY nombre_partner ASC
");
$partnersList = $stmtPart->fetchAll();
$stmtTipificacionesCancelacion = $pdo->query("SELECT descripcion FROM tipificaciones_cancelacion WHERE estado = 1 ORDER BY descripcion ASC");
$tipificacionesCancelacion = $stmtTipificacionesCancelacion->fetchAll(PDO::FETCH_COLUMN);
$checklistSecciones = [
    'EPP y EPE' => ['Casco normado','Barbiquejo','Cinturón de seguridad','Guantes dieléctricos','Botas dieléctricas','Lentes de seguridad','Escalera telescópica (28 peldaños)','Conos de seguridad de 70 cm (6 unidades)','Barras retráctiles (4 unidades)'],
    'Indumentaria' => ['Polo de manga larga con logo WIN + Partner','Pantalón de trabajo con logo','Chaleco naranja con logo','Fotocheck','Portafotocheck + cinta lanyard'],
    'Kit de fibra óptica' => ['Fuente láser','Power meter (1490 nm)','Cortador de alta precisión F.O.','Peladora de F.O.','Patch cord de prueba','Limpiador One-Click','Dispensador + alcohol isopropílico','Pañitos de limpieza para F.O.','Plumón indeleble'],
    'Herramientas generales' => ['Escalera de 6 pasos','Alicate de corte','Taladro percutor','Brocas (pasamuros / pequeña)','Zunchadora','Martillo','Extensión mínimo 15 m','Destornillador estrella','Destornillador plano','Wincha pasacable (15 m)','Portacarrete para F.O.','Peladora de cable drop'],
    'Materiales y ferretería' => ['Fibra drop 1H - marca OPT','Fibra drop preconectorizada','Roseta','Adaptador óptico','Conector mecánico tipo S (homologado)','Grapas de plástico','Cinta doble contacto','Templador tipo P','Cinta band-it 3/4','Hebillas 3/4','Anclaje tipo P','Clevis','Cable acerado','Tarugos para anclaje tipo P','Cinta vulcanizante','Cintillo bandera color blanco','Cintillo de amarre negro'],
    'Vehículo' => ['Logos de WIN','SOAT','Revisión técnica','Extintor','Brevete','Estado del vehículo']
];
// Vencimientos (2026-10-15): estos ítems no se marcan a mano; su estado sale de las fechas registradas (vencimientos.js).
$itemsVencimiento = ['SOAT','Revisión técnica','Extintor','Brevete'];
$inicioVencimiento = ['SOAT' => 'Inicio de vigencia', 'Revisión técnica' => 'Fecha de inspección', 'Extintor' => 'Última recarga', 'Brevete' => 'Fecha de emisión'];
$itemsChecklist = array_merge(...array_values($checklistSecciones));
?>

<link rel="stylesheet" href="03_FRONTEND/src/assets/css/supervision_lista.css?v=<?= (int)@filemtime(FRONTEND_PATH . '/assets/css/supervision_lista.css') ?>">

<div class="wrap-bitacora">

  <!-- Aviso: para supervisar primero se inicia el turno -->
  <div class="modal fade" id="modalSinTurno" tabindex="-1" aria-labelledby="modalSinTurnoTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalSinTurnoTitulo"><i class="bi bi-play-circle me-2 text-primary"></i><span id="sinTurnoTitulo">Inicia tu turno</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0" id="sinTurnoTexto">Para registrar supervisiones primero debes iniciar tu turno de hoy. Así queda registrada tu jornada y tu ubicación de inicio.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Ahora no</button>
          <a class="btn btn-win" href="index.php?page=asistencia" id="sinTurnoIr"><i class="bi bi-play-circle me-1"></i>Iniciar turno</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Lo que falta para finalizar (2026-10-16) -->
  <div class="modal fade" id="modalFaltantes" tabindex="-1" aria-labelledby="modalFaltantesTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalFaltantesTitulo"><i class="bi bi-list-check me-2 text-primary"></i>Falta completar para finalizar</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-2">Lo avanzado ya está guardado. Toque un dato para ir a ese campo.</p>
          <ul class="sgs-faltantes" id="faltantesLista"></ul>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button type="button" class="btn btn-win" id="faltantesIr"><i class="bi bi-arrow-right-circle me-1"></i>Ir al primero</button>
        </div>
      </div>
    </div>
  </div>

  <!-- DATALISTS PARA AUTOCOMPLETADO Y SUGERENCIAS -->
  <!-- MASTHEAD HEADER EJECUTIVO -->
  <div class="masthead-bitacora">
    <div>
      <h1>REGISTRO DE SUPERVISIÓN</h1>
    </div>
    <div class="meta-bitacora" id="headerMeta">Cargando registros…</div>
  </div>

  <!-- ===================== VISTA 1: LISTADO (MISMA VENTANA) ===================== -->
  <div id="view-list">

    <!-- PANEL DE CONTROL: fila 1 = estados + acciones · fila 2 = filtros con etiqueta. Los id los usa el JS. -->
    <div class="card sgs-toolbar-card p-3 mb-3">
      <div class="sgs-toolbar">
        <?php if ($esSupervisorPartnerVista): ?>
        <div class="sgs-segmented" id="filterTabs" role="group" aria-label="Estado de la orden asignada">
          <button type="button" class="filter-tab-btn active" data-filter="todos">Todas <span class="sgs-seg-count" id="count-todos">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="partner_pendiente" title="Asignadas, sin atender y dentro de las 24 horas">Pendientes <span class="sgs-seg-count" id="count-partner_pendiente">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="partner_en_proceso" title="Ya registraste la atención; falta que WIN la cierre">En proceso <span class="sgs-seg-count" id="count-partner_en_proceso">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="partner_vencida" title="Pasaron las 24 horas sin atención">No revisados <span class="sgs-seg-count" id="count-partner_vencida">0</span></button>
        </div>
        <?php elseif ($canFilterSupervisionStatus): ?>
        <div class="sgs-segmented" id="filterTabs" role="group" aria-label="Estado de la supervisión">
          <button type="button" class="filter-tab-btn active" data-filter="todos">Todas <span class="sgs-seg-count" id="count-todos">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="iniciada">En proceso <span class="sgs-seg-count" id="count-iniciada">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="finalizada">Finalizadas <span class="sgs-seg-count" id="count-finalizada">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="pendiente">Pendientes <span class="sgs-seg-count" id="count-pendiente">0</span></button>
          <button type="button" class="filter-tab-btn" data-filter="rescate" title="Pendientes tomadas como rescate: seguimiento hasta cerrarlas">En rescate <span class="sgs-seg-count" id="count-rescate">0</span></button>
        </div>
        <?php endif; ?>
        <div class="sgs-toolbar-actions">
          <?php if ($canUsePendingMap): ?><button class="btn btn-outline-secondary" id="viewPendingRoutesBtn" type="button"><i class="bi bi-map me-1"></i>Ver rutas</button><?php endif; ?>
          <?php if ($canChooseSupervisionColumns): ?><div class="dropdown">
            <button type="button" class="btn btn-outline-secondary sgs-btn-icono" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="btnColumnSelector" title="Columnas visibles" aria-label="Columnas visibles"><i class="bi bi-layout-three-columns"></i></button>
            <div class="dropdown-menu dropdown-menu-end p-3 sgs-menu-columns" id="dropdownColumnSelector">
          <p class="sgs-kpi-label mb-2">Columnas visibles</p>
          <div class="d-flex flex-column gap-2" id="columnCheckboxesContainer">
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="codigo" checked> Código / ticket
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="clasificacion" checked> Gestión / actividad
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="fecha" checked> Fecha
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="responsable" checked> Responsable
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="departamento" checked> Departamento
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="estado" checked> Estado
            </label>
            <hr class="my-1">
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="partner_nombre"> Partner / contrata
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="tecnico1_nombre"> Técnico líder
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 style-col-check">
              <input type="checkbox" class="form-check-input col-toggle-chk" data-col="unidad_placa"> Placa del vehículo
            </label>
          </div>
          <div class="mt-2 pt-2 border-top text-end">
            <button type="button" class="btn btn-sm btn-link p-0 text-muted text-decoration-none" id="btnResetColumns">Restablecer</button>
          </div>
        </div>
          </div><?php endif; ?>
          <?php if (Auth::isAdministrative()): ?><div class="dropdown">
            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-x-octagon me-1"></i>Cancelaciones</button>
            <div class="dropdown-menu dropdown-menu-end">
              <a class="dropdown-item" href="03_FRONTEND/src/assets/templates/plantilla_cancelaciones_v5.xlsx?v=<?= (int)@filemtime(FRONTEND_PATH . '/assets/templates/plantilla_cancelaciones_v5.xlsx') ?>" download="plantilla_cancelaciones_v5.xlsx"><i class="bi bi-file-earmark-arrow-down me-2"></i>Descargar modelo (Excel)</a>
              <button class="dropdown-item" id="openCancelImportBtn" type="button"><i class="bi bi-cloud-arrow-up me-2"></i>Subir cancelaciones</button>
            </div>
          </div><?php endif; ?>
          <?php if ($canCreateSupervision): ?><button class="btn btn-win" id="toggleNewBtn" type="button"><i class="bi bi-plus-lg me-1"></i>Nueva supervisión</button><?php endif; ?>
        </div>
      </div>

      <?php $sgsAdminFilters = Auth::isAdministrative() && $canUseSupervisionFilters; ?>
      <!-- Fila 2 (2026-10-15): solo filtros, a todo el ancho; todos los controles de 40 px y alineados abajo. -->
      <div class="sgs-toolbar-row">
        <div class="sgs-filtros">
          <div class="sgs-filtro sgs-filtro--ancho"><label class="form-label" for="filterCodigoSup">Pedido o ticket</label>
            <div class="sgs-filtro-busqueda"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="filterCodigoSup" type="search" maxlength="60" placeholder="Código de pedido o ticket" autocomplete="off"></div></div>
          <?php if ($sgsAdminFilters): ?>
          <div class="sgs-filtro"><label class="form-label" for="adminFilterZona">Departamento</label>
            <select class="form-select" id="adminFilterZona"><option value="">Todos los departamentos</option></select></div>
          <div class="sgs-filtro"><label class="form-label" for="adminFilterSupervisor">Supervisor</label>
            <select class="form-select" id="adminFilterSupervisor"><option value="">Todos los supervisores</option></select></div>
          <?php endif; ?>
          <?php if ($canFilterSupervisionDate): ?>
          <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="adminFilterFechaDesde">Desde</label><input class="form-control" id="adminFilterFechaDesde" type="date"></div>
          <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="adminFilterFechaHasta">Hasta</label><input class="form-control" id="adminFilterFechaHasta" type="date"></div>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-secondary sgs-filtro-limpiar" id="btnLimpiarFiltrosSup" title="Quitar filtros"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
        </div>
      </div>
    </div>

    <!-- VISTA DESKTOP: Tabla Ejecutiva -->
    <div class="panel-bitacora table-panel-bitacora d-none d-md-block">
      <div class="table-responsive"><!-- 2026-10-16: con muchas columnas se recorre arrastrando con la mano (main.js) -->
      <table class="table-bitacora">
        <thead id="thead-supervisiones">
          <tr>
            <th style="width: 24px;"></th>
            <th>Código de pedido / ticket</th>
            <th>Tipo de supervisión</th>
            <th>Fecha</th>
            <th>Responsable</th>
            <th>Departamento</th>
            <th>Estado</th>
            <th class="text-center" style="width: 70px;">Acciones</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <tr><td colspan="8" class="empty-bitacora">Cargando registros…</td></tr>
        </tbody>
      </table>
      </div>
    </div>

    <!-- VISTA MÓVIL: Tarjetas Horizontales -->
    <div class="d-md-none mobile-records-wrapper" id="mobile-records-container">
      <div class="empty-bitacora">Cargando registros…</div>
    </div>
    <div class="text-center py-3 d-none" id="loadMoreSupervisionsWrap">
      <button type="button" class="btn btn-outline-dark px-4" id="loadMoreSupervisions">
        <i class="bi bi-arrow-down-circle me-1"></i>Cargar más registros
      </button>
    </div>
  </div>

  <div class="modal fade" id="pendingRoutesModal" tabindex="-1" aria-labelledby="pendingRoutesTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
      <div class="modal-header bg-dark text-white"><div><h2 class="modal-title fs-6" id="pendingRoutesTitle"><i class="bi bi-map-fill text-warning me-2"></i>Rutas de órdenes pendientes</h2><div id="pendingMapSummary" class="small text-white-50 mt-1"></div></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body"><div id="pending-map"></div><div id="pendingMapMissing" class="small text-muted mt-2"></div></div>
      <div class="modal-footer"><a id="pendingRouteLink" class="btn-bitacora btn-gps-bitacora" href="#" target="_blank" rel="noopener" style="display:none;text-decoration:none;"><i class="bi bi-sign-turn-right-fill me-1"></i>Abrir ruta completa</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
  </div>

  <!-- ===================== VISTA 2: NUEVA SUPERVISIÓN (MISMA VENTANA) ===================== -->
  <div id="view-new">
    <button class="back-btn-bitacora" id="cancelNewBtn" type="button"><i class="bi bi-arrow-left me-1"></i>Volver al listado</button>

    <div class="panel-bitacora form-panel-bitacora">
      <p class="form-title-bitacora">
        <span id="newFormTitle">Nueva supervisión de campo</span>
        <span class="folio-preview" id="folioPreview">SUP-2026-001</span>
      </p>

      <form id="form-inicio-supervision" enctype="multipart/form-data" novalidate>
        <input type="hidden" id="new_supervision_id" name="supervision_id_edit" value="">
        <div id="new_workflow_status" class="alert alert-light border mb-3" role="status">
          Complete la sección 1 para habilitar el inicio de la supervisión.
        </div>
        <!-- SECCIÓN 1: TIPO DE SUPERVISIÓN & UBICACIÓN -->
        <p class="section-subtitle-bitacora">1. Información general de la supervisión</p>
        <div class="grid-bitacora mb-3">
          
          <div class="field-bitacora">
            <label for="new_tipo_supervision">Gestión o actividad supervisada *</label>
            <select id="new_tipo_supervision" name="tipo_supervision" required>
              <option value="Instalación">Instalación</option>
              <option value="Postventa">Postventa</option>
              <option value="Avería">Avería</option>
              <option value="Reiterada">Reiterada</option>
              <option value="Garantía">Garantía</option>
              <option value="Incidencia / Casos atípicos">Incidencia / Casos atípicos</option>
              <option value="Proyecto de ordenamiento">Proyecto de ordenamiento</option>
              <option value="Visita partner por capacitación">Visita partner por capacitación</option>
              <option value="Conjunta PEXT">Conjunta PEXT</option>
              <option value="Checklist">Checklist</option>
            </select>
          </div>

          <div class="field-bitacora" id="new_field_subtipo" style="display:none;">
            <label for="new_sub_tipo_atencion">Trabajo programado de Postventa *</label>
            <select id="new_sub_tipo_atencion" name="sub_tipo_atencion">
              <option value="">Seleccione una opción</option>
            </select>
          </div>

          <div class="field-bitacora">
            <label for="new_derivacion">Origen de la solicitud *</label>
            <select id="new_derivacion" name="derivacion_orden" required>
              <option value="Autogestión">Autogestión</option>
              <option value="Mesa de supervisión">Mesa de supervisión</option>
              <option value="Líder">Líder</option>
              <option value="Coordinador">Coordinador</option>
              <option value="Gerente">Gerente</option>
            </select>
          </div>

          <div class="field-bitacora">
            <label for="new_tipo_servicio">Segmento del servicio *</label>
            <select id="new_tipo_servicio" name="tipo_servicio" required>
              <option value="Regular">Regular</option>
              <option value="ZAV">ZAV (Zona de Alto Valor)</option>
            </select>
          </div>

          <div class="field-bitacora" id="new_field_modalidad">
            <label for="new_tipo_sup">Modalidad de ejecución *</label>
            <select id="new_tipo_sup" name="tipo_supervision_inicial" required>
              <option value="En caliente">En caliente</option>
              <option value="En frío">En frío</option>
              <option value="Remota">Remota (videollamada)</option>
            </select>
          </div>
          <!-- Supervisión remota (2026-10-13): solo se ve con la modalidad "Remota" -->
          <div class="remota-only d-none sgs-remota-aviso"><i class="bi bi-camera-video me-1"></i>Supervisión remota: solo se pide el partner. Suba la <strong>captura de la videollamada</strong> como foto de evidencia (obligatoria).</div>

          <!-- LISTA DE 7 PARTNERS OFICIALES -->

          <div class="field-bitacora">
            <label for="new_dep">Departamento *</label>
            <select id="new_dep" name="departamento" required>
              <option value="">Cargando...</option>
            </select>
          </div>

          <div class="field-bitacora">
            <label for="new_prov">Provincia *</label>
            <select id="new_prov" name="provincia" required>
              <option value="">Seleccione departamento</option>
            </select>
          </div>

          <div class="field-bitacora">
            <label for="new_dis">Distrito *</label>
            <select id="new_dis" name="distrito" required>
              <option value="">Seleccione provincia</option>
            </select>
          </div>
        </div>

        <!-- Los campos de cliente permanecen internamente disponibles para una futura reactivación. -->
        <input id="new_cliente_nombre" name="cliente_nombre" type="hidden" value="">
        <input id="new_cliente_dni" name="cliente_dni" type="hidden" value="">
        <div id="new_sec_codigo_pedido">
        <p class="section-subtitle-bitacora">2. Identificación de la orden</p>
        <div class="grid-bitacora mb-2">
          <div class="field-bitacora">
            <label for="new_ticket_codigo">Código de Pedido o Ticket *</label>
            <input id="new_ticket_codigo" name="ticket_codigo" type="text" placeholder="Ej. 998877" required>
          </div>
        </div>
        </div>

        <!-- SECCIÓN 3: DATOS DE CUADRILLA & RECURSO MÓVIL (SE OCULTA EN MODALIDAD EN FRÍO) -->
        <div id="new_sec_cuadrilla">
          <p class="section-subtitle-bitacora" id="new_titulo_cuadrilla">3. Partner, personal técnico y vehículo</p>
          <div class="grid-bitacora mb-3">
          <div class="field-bitacora" id="new_field_partner">
            <label for="new_partner">Partner o contrata responsable *</label>
            <select id="new_partner" name="partner_id" required>
              <option value="">Seleccione contrata</option>
              <?php foreach ($partnersList as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre_partner']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>


            
            <!-- TÉCNICO LÍDER (T1): SELECCIÓN DE LISTA MAESTRA -->
            <div id="new_personal_vehiculo" class="grid-bitacora full">
            <div class="field-bitacora cancelacion-t1">
              <label for="new_tec1_nom">Técnico líder (T1)</label>
              <select id="new_tec1_nom" name="tecnico1_nombre" style="font-size:13.5px;">
                <option value="">Seleccione / busque técnico líder</option>
              </select>
            </div>
            <div class="field-bitacora cancelacion-t1">
              <label for="new_tec1_dni">DNI del técnico líder</label>
              <input id="new_tec1_dni" name="tecnico1_dni" type="text" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" placeholder="Se completa al seleccionar al técnico">
            </div>

            <!-- TÉCNICO APOYO (T2): OPCIONAL Y SOLO DESDE LA LISTA MAESTRA -->
            <div class="field-bitacora cancelacion-extra">
              <label for="new_tec2_nom">Técnico de apoyo (T2) — Opcional</label>
              <select id="new_tec2_nom" name="tecnico2_nombre" style="font-size:13.5px;">
                <option value="">Sin técnico de apoyo (T2)</option>
              </select>
            </div>
            <div class="field-bitacora cancelacion-extra">
              <label for="new_tec2_dni">DNI del técnico de apoyo</label>
              <input id="new_tec2_dni" name="tecnico2_dni" type="text" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" placeholder="Sin técnico T2">
            </div>

            <div class="field-bitacora cancelacion-extra">
              <label for="new_brevete">Integrante que cuenta con brevete</label>
              <select id="new_brevete" name="brevete_poseedor">
                <option value="Tecnico 1" selected>Técnico 1 (Líder)</option>
                <option value="Tecnico 2">Técnico 2 (Apoyo)</option>
                <option value="Ninguno">Ninguno</option>
              </select>
            </div>
            <div class="field-bitacora cancelacion-extra">
              <label for="new_u_tipo">Tipo de vehículo</label>
              <select id="new_u_tipo" name="unidad_tipo">
                <option value="Minivan">Minivan</option>
                <option value="Auto">Auto</option>
                <option value="Moto">Moto</option>
                <option value="Otro">Otro</option>
              </select>
            </div>
            <div class="field-bitacora cancelacion-extra">
              <label for="new_u_placa">Placa del vehículo</label>
              <input id="new_u_placa" name="unidad_placa" type="text" placeholder="Ej. ABC-123">

            </div>
            <div class="field-bitacora cancelacion-extra">
              <label for="new_u_estado">Estado del vehículo</label>
              <select id="new_u_estado" name="unidad_estado_conservacion">
                <option value="Mal estado">Mal estado</option>
                <option value="Regular">Regular</option>
                <option value="Buen estado" selected>Buen estado</option>
              </select>
            </div>
            </div>
          </div>
        </div>

        <!-- SECCIÓN 4: COORDENADAS GPS & OBSERVACIONES DE CAMPO -->
        <p class="section-subtitle-bitacora">4. Resultado y evidencias de la supervisión</p>
        
        <!-- SUB-BLOQUE ESPECÍFICO SI SE SELECCIONA CANCELACIÓN -->
        <div id="new_box_cancelacion" style="display:none; background:var(--sgs-danger-soft); border:1px solid var(--sgs-danger-border); padding:18px; border-radius:6px; margin-bottom:16px;">
          <p style="font-size:12.5px; font-weight:700; color:var(--sgs-danger-dark); text-transform:uppercase; margin:0 0 12px; display:flex; align-items:center; gap:6px;">
            <i class="bi bi-x-octagon me-1"></i>Detalle de la cancelación
          </p>
          <div class="grid-bitacora">
            <div class="field-bitacora" style="grid-column: span 2; margin-bottom: 12px; display: none;" id="new_imported_reason_box">
              <label style="color:var(--sgs-danger-dark); font-size: 11px; text-transform: uppercase; font-weight: bold;">Motivo importado según Excel</label>
              <div id="new_imported_reason_text" style="padding:10px; background:#fff; border:1px dashed #fca5a5; border-radius:4px; font-size:13px; font-weight:600; color:var(--sgs-danger-dark); word-break: break-word;"></div>
            </div>
            
            <div class="field-bitacora">
              <label for="new_tipificacion_cancelacion" style="color:var(--sgs-danger-dark);">Tipificación de Cancelación Final *</label>
              <select id="new_tipificacion_cancelacion" name="tipificacion_cancelacion">
                <option value="">Seleccionar</option>
                <option value="Fuera de cobertura">Fuera de cobertura</option>
                <option value="Desistimiento del cliente">Desistimiento del cliente</option>
                <option value="Costo de instalación / Tarifas">Costo de instalación / Tarifas</option>
                <option value="Problemas técnicos / Sin facilidades">Problemas técnicos / Sin facilidades</option>
                <option value="Competencia / Migración">Competencia / Migración</option>
                <option value="Dirección errada / Inubicable">Dirección errada / Inubicable</option>
                <option value="Otro motivo">Otro motivo</option>
              </select>
            </div>

            <div class="field-bitacora">
              <label for="new_resultado_rescate" style="color:var(--sgs-danger-dark);">Resultado de Rescate del Servicio *</label>
              <select id="new_resultado_rescate" name="tipificacion_corregida_supervisor">
                <option value="No se pudo rescatar">No se pudo rescatar (Cancelado definitivo)</option>
                <option value="Se rescató el servicio">Se rescató el servicio / venta</option>
              </select>
            </div>
          </div>
        </div>

        <div class="grid-bitacora mb-2">
          <div class="field-bitacora">
            <label for="new_latitud">Coordenada GPS: latitud</label>
            <input id="new_latitud" name="latitud" type="text" placeholder="-12.046374">
          </div>
          <div class="field-bitacora">
            <label for="new_longitud">Coordenada GPS: longitud</label>
            <input id="new_longitud" name="longitud" type="text" placeholder="-77.042689">
          </div>
          <div class="field-bitacora" style="justify-content: flex-end;">
            <button type="button" class="btn-bitacora btn-gps-bitacora" onclick="capturarGPS('new_latitud', 'new_longitud', 'new_gps_status')">
              <i class="bi bi-arrow-repeat me-1"></i>Actualizar ubicación
            </button>
          </div>
          <div class="field-bitacora full">
            <span id="new_gps_status" class="sgs-gps-estado">
              Tu ubicación se toma sola al abrir la supervisión. Si cambias de lugar, presiona "Actualizar ubicación", o escribe o pega las coordenadas a mano.
            </span>
          </div>
          <div class="field-bitacora full" id="new_field_resultado_tecnico" style="display:none;">
            <label for="new_resultado_tecnico">Solución aplicada o cierre técnico</label>
            <select id="new_resultado_tecnico" name="sub_tipo_atencion" disabled>
              <option value="">Se selecciona después de validar el servicio</option>
            </select>
          </div>

          <!-- CARGA DE HASTA 3 FOTOS DE EVIDENCIA DEL SERVICIO GENERAL -->
          <div class="field-bitacora full" id="new_sec_fotos_generales" style="margin-top:12px;">
            <p style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:8px;"><i class="bi bi-camera me-1"></i>Fotos de Evidencia General del Servicio (Hasta 3 Fotos)</p>
            <div class="grid-bitacora">
              <div class="field-bitacora">
                <label for="new_foto_serv_1">Evidencia general 1</label>
                <input type="file" id="new_foto_serv_1" name="foto_servicio_1" accept="image/*">
                <div id="new_preview_foto_serv_1" style="display:none; margin-top:8px;"></div>
              </div>
              <div class="field-bitacora">
                <label for="new_foto_serv_2">Evidencia general 2</label>
                <input type="file" id="new_foto_serv_2" name="foto_servicio_2" accept="image/*">
                <div id="new_preview_foto_serv_2" style="display:none; margin-top:8px;"></div>
              </div>
              <div class="field-bitacora">
                <label for="new_foto_serv_3">Evidencia general 3</label>
                <input type="file" id="new_foto_serv_3" name="foto_servicio_3" accept="image/*">
                <div id="new_preview_foto_serv_3" style="display:none; margin-top:8px;"></div>
              </div>
            </div>
          </div>

          <div class="field-bitacora full" id="new_field_obs_general">
            <label for="new_obs">Resumen del resultado de la supervisión</label>
            <textarea id="new_obs" name="observacion_servicio" placeholder="Describe cómo se deja el servicio o lo verificado en campo..."></textarea>
          </div>

        </div>

        <div id="new_toggle_checklist" class="optional-module-toggle <?= $canUseChecklistForm ? '' : 'd-none' ?>">
          <label style="font-weight:700;display:flex;align-items:center;gap:9px;cursor:pointer;">
            <input type="checkbox" id="new_chk_checklist" name="chk_incluir_checklist" value="1" style="width:18px;height:18px;" <?= $canUseChecklistForm ? '' : 'disabled' ?>>
            <i class="bi bi-ui-checks-grid text-secondary"></i> Incluir evaluación mediante checklist técnico
          </label>
        </div>
        <div id="new_sec_checklist" style="display:none;background:var(--sgs-surface-alt);border:1px solid var(--win-border);border-radius:8px;padding:16px;margin-bottom:16px;">
          <p class="section-subtitle-bitacora" style="margin-top:0;">6. Evaluación mediante checklist técnico</p>

          <?php $idx=0; foreach($checklistSecciones as $seccion=>$itemsSeccion): $opcionesSeccion=$seccion==='EPP y EPE'?['Sí','No','Para cambio','No revisado']:($seccion==='Indumentaria'?['Sí','No','Mal estado','No revisado']:($seccion==='Materiales y ferretería'?['Sí','No']:['Bueno','Regular','Malo','No tiene'])); ?><section class="checklist-group"><h4 class="checklist-group-title"><i class="bi bi-tools me-2"></i><?= htmlspecialchars($seccion) ?></h4><div class="checklist-group-body"><?php foreach($itemsSeccion as $item): if(in_array($item,$itemsVencimiento,true)): ?>
          <div class="grid-bitacora mb-2 checklist-row checklist-venc" data-venc="<?= htmlspecialchars($item,ENT_QUOTES,'UTF-8') ?>">
            <input type="hidden" name="checklist_criterio[]" value="<?= htmlspecialchars($item,ENT_QUOTES,'UTF-8') ?>">
            <input type="hidden" name="checklist_estado[<?= $idx ?>]" value="No revisado" class="checklist-venc-valor">
            <div class="field-bitacora"><label><?= htmlspecialchars($item) ?></label><div class="checklist-venc-estado"><span class="badge bg-secondary">Sin registrar</span><small class="text-muted">Elija el técnico líder y la placa.</small></div></div>
            <div class="field-bitacora"><label>Observación (opcional)</label><input name="checklist_observacion[]" type="text" placeholder="Detalle sobre este documento"></div>
            <div class="field-bitacora"><label>Fechas del documento</label><button type="button" class="btn btn-outline-secondary checklist-venc-actualizar"><i class="bi bi-calendar-check me-1"></i>Registrar fechas</button></div>
            <?php $claveVenc = htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?>
            <div class="checklist-venc-campos d-none">
              <div class="field-bitacora"><label><?= htmlspecialchars($inicioVencimiento[$item]) ?></label><input type="date" class="checklist-venc-inicio" name="venc_inicio[<?= $claveVenc ?>]" max="<?= date('Y-m-d') ?>"></div>
              <div class="field-bitacora"><label>Vence</label><input type="date" class="checklist-venc-fin" name="venc_fin[<?= $claveVenc ?>]"></div>
              <div class="field-bitacora"><label>Foto del documento</label><input type="file" name="venc_foto[<?= $claveVenc ?>]" accept="image/*,application/pdf"></div>
            </div>
          </div>
          <?php else: ?>
          <div class="grid-bitacora mb-2 checklist-row" data-criterio="<?= htmlspecialchars($item,ENT_QUOTES,'UTF-8') ?>" style="align-items:end;border-bottom:1px solid var(--sgs-success-soft);padding-bottom:10px;">
            <input type="hidden" name="checklist_criterio[]" value="<?= htmlspecialchars($item,ENT_QUOTES,'UTF-8') ?>">
            <div class="field-bitacora"><label><?= htmlspecialchars($item) ?> *</label><?php if($seccion==='Materiales y ferretería'): ?><small class="text-muted fw-semibold mb-1">¿Cuenta con stock?</small><?php endif; ?><div class="checklist-opciones">
              <?php foreach($opcionesSeccion as $opcion): $claseOpcion=strtolower(str_replace(['í',' ','ó'],['i','-','o'],$opcion)); ?><label class="checklist-opcion <?= htmlspecialchars($claseOpcion) ?>"><input type="radio" name="checklist_estado[<?= $idx ?>]" value="<?= htmlspecialchars($opcion,ENT_QUOTES,'UTF-8') ?>" class="checklist-estado"><span><?= htmlspecialchars($opcion) ?></span></label><?php endforeach; ?>
            </div></div>
            <div class="field-bitacora"><label>Observación (opcional)</label><input name="checklist_observacion[]" type="text" placeholder="Detalle sobre esta herramienta"></div>
            <div class="field-bitacora"><label>Foto (opcional)</label><input name="checklist_foto[<?= $idx ?>]" type="file" accept="image/jpeg,image/png,image/webp"></div>
          </div>
          <?php endif; $idx++; endforeach; ?></div></section><?php endforeach; ?>
          <div class="grid-bitacora mt-3" style="align-items:end">
            <div class="field-bitacora"><label for="new_checklist_obs_general">Comentario general de las observaciones encontradas</label><textarea id="new_checklist_obs_general" name="checklist_observacion_general" rows="3" placeholder="Resumen general del checklist y de las observaciones detectadas"></textarea></div>
            <div class="field-bitacora"><label>Firma del supervisor *</label><canvas id="new_checklist_firma_canvas" class="checklist-signature-pad" width="520" height="150"></canvas><input id="new_checklist_firma_data" name="checklist_firma_data" type="hidden"><button type="button" class="btn btn-outline-secondary btn-sm mt-2 checklist-signature-clear" data-canvas="new_checklist_firma_canvas" data-input="new_checklist_firma_data"><i class="bi bi-eraser me-1"></i>Limpiar firma</button><small class="text-muted d-block mt-1">Firme a mano alzada dentro del recuadro.</small></div>
          </div>
        </div>

        <!-- SECCIÓN 5: REGISTRO DE OBSERVACIÓN / FALTA CON HASTA 3 FOTOS -->
        <div id="new_sec_observaciones" class="<?= $canUseFindingsForm ? '' : 'd-none' ?>">
        <p class="section-subtitle-bitacora">5. Hallazgos o incumplimientos adicionales</p>
        <div class="optional-module-toggle">
          <label style="cursor:pointer; display:inline-flex; align-items:center; gap:8px; font-weight:700; color:var(--win-dark);">
            <input type="checkbox" id="new_chk_obs" name="chk_incluir_observacion" value="1" style="width:18px; height:18px;">
            <i class="bi bi-exclamation-circle text-secondary"></i> Registrar un hallazgo o incumplimiento adicional
          </label>
        </div>

        <div id="new_box_observacion" style="display:none; background:var(--sgs-surface-alt); padding:20px; border-radius:6px; border:1px solid var(--win-border); margin-bottom:16px;">
          <div class="grid-bitacora mb-3">
            <div class="field-bitacora">
              <label for="new_obs_gravedad">Gravedad de la Falta *</label>
              <select id="new_obs_gravedad" name="obs_gravedad">
                <option value="">Seleccione gravedad</option>
                <option value="Media">Media</option>
                <option value="Grave">Grave</option>
                <option value="Muy Grave">Muy Grave</option>
              </select>
            </div>
            <div class="field-bitacora">
              <label for="new_obs_cat">Categoría de Observación *</label>
              <select id="new_obs_cat" name="obs_categoria_id" disabled>
                <option value="">Seleccione primero la gravedad</option>
              </select>
            </div>
            <div class="field-bitacora">
              <label for="new_obs_sub">Sub-Opción / Tipificación *</label>
              <select id="new_obs_sub" name="obs_subopcion_id" disabled>
                <option value="">Seleccione primero una categoría</option>
              </select>
            </div>
            <div class="field-bitacora full">
              <label for="new_obs_det">Comentario de la observación *</label>
              <textarea id="new_obs_det" name="obs_detalle" placeholder="Describa el incumplimiento o la falta encontrada; vale para todas las fotos..."></textarea>
            </div>
          </div>

          <!-- CARGA DE HASTA 3 FOTOS DE EVIDENCIA CON VISTA PREVIA INMEDIATA -->
          <p style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:10px;"><i class="bi bi-camera me-1"></i>Evidencias fotográficas (al menos 1, hasta 3)</p>
          <div class="grid-bitacora">
            <div class="field-bitacora">
              <label for="new_foto1">Foto de evidencia 1</label>
              <input type="file" id="new_foto1" name="foto_1" accept="image/*">
              <div id="new_preview_foto1" style="display:none; margin-top:8px;"></div>
            </div>
            <div class="field-bitacora">
              <label for="new_foto2">Foto de evidencia 2</label>
              <input type="file" id="new_foto2" name="foto_2" accept="image/*">
              <div id="new_preview_foto2" style="display:none; margin-top:8px;"></div>
            </div>
            <div class="field-bitacora">
              <label for="new_foto3">Foto de evidencia 3</label>
              <input type="file" id="new_foto3" name="foto_3" accept="image/*">
              <div id="new_preview_foto3" style="display:none; margin-top:8px;"></div>
            </div>
          </div>
        </div>
        </div>

      </form>
      <div class="sgs-acciones-supervision" id="newActions">
        <span class="sgs-autoguardado" id="new_autoguardado" aria-live="polite"></span>
        <button class="btn-bitacora btn-ghost-bitacora" id="cancelSupBtn" type="button"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
        <button class="btn-bitacora btn-orange-bitacora" id="startBtn" type="submit" form="form-inicio-supervision" disabled>
          <i class="bi bi-check2-circle me-2"></i><span id="startBtnLabel">Finalizar supervisión</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ===================== VISTA 3: DETALLE Y EDICIÓN (MISMA VENTANA) ===================== -->
  <div id="view-detail">
    <button class="back-btn-bitacora" id="backBtn" type="button"><i class="bi bi-arrow-left me-1"></i>Volver al listado</button>
    <div class="panel-bitacora detail-panel-bitacora" id="detailContent">
      <!-- Contenido Inyectado Dinámicamente -->
    </div>
  </div>

</div>

<?php if (($user['rol'] ?? '') === 'Administrador'): ?>
<div class="modal fade" id="modalImportarCancelaciones" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content border-0 shadow-lg">
    <div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-excel-fill me-2 text-success"></i>Carga masiva de cancelaciones</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="formImportarCancelaciones"><div class="modal-body p-4">
      <div class="alert alert-warning small"><strong>Importante:</strong> use la plantilla oficial. Seleccione el tipo de supervisión y use Observación previa solo cuando necesite dejar una recomendación para campo. El sistema asignará cada pendiente al supervisor correspondiente.</div>
      <label class="form-label fw-bold" for="archivoCancelaciones">Archivo Excel completado *</label>
      <input class="form-control" id="archivoCancelaciones" name="archivo" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <div id="resultadoImportacionCancelaciones" class="mt-3"></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button id="submitCancelImportBtn" type="submit" class="btn btn-win"><i class="bi bi-cloud-arrow-up-fill me-1"></i>Procesar archivo</button></div></form>
  </div></div>
</div>
<?php endif; ?>

<!-- MODAL OCULTO: SOLO CORRECCIÓN DE TÉCNICOS EXISTENTES -->
<div class="modal fade" id="modalEditarTecnico" tabindex="-1" aria-labelledby="modalEditarTecnicoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg">
      <form id="form-editar-tecnico">
        <div class="modal-header">
          <h5 class="modal-title fs-6 fw-bold" id="modalEditarTecnicoTitulo">Corregir técnico</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="edit_tecnico_id">
          <div class="mb-3">
            <label for="edit_tecnico_nombre" class="form-label fw-semibold">Nombre completo</label>
            <input type="text" class="form-control" id="edit_tecnico_nombre" maxlength="200" required>
          </div>
          <div class="mb-3">
            <label for="edit_tecnico_dni" class="form-label fw-semibold">DNI</label>
            <input type="text" class="form-control" id="edit_tecnico_dni" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" required>
            <div class="form-text">Solo se actualizará este técnico. No se crearán registros nuevos.</div>
          </div>
          <div class="mb-3" id="edit_tecnico_celular_box">
            <label for="edit_tecnico_celular" class="form-label fw-semibold">Número celular del técnico líder</label>
            <input type="text" class="form-control" id="edit_tecnico_celular" maxlength="9" inputmode="numeric">
          </div>
          <div class="mb-3">
            <label for="edit_tecnico_estado" class="form-label fw-semibold">Estado operativo</label>
            <select class="form-select" id="edit_tecnico_estado"><option>Habilitado</option><option>Inhabilitado</option><option>Blacklist</option></select>
          </div>
          <div>
            <label for="edit_tecnico_observacion" class="form-label fw-semibold">Observación del técnico</label>
            <textarea class="form-control" id="edit_tecnico_observacion" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning" id="btn-guardar-tecnico">Guardar corrección</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Registro de técnico de apoyo (T2): grupo distinto de los T1, sin nomenclatura, pertenece al partner de la supervisión -->
<div class="modal fade" id="modalNuevoApoyo" tabindex="-1" aria-labelledby="modalNuevoApoyoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="form-nuevo-apoyo" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="modalNuevoApoyoTitulo"><i class="bi bi-person-plus me-2 text-primary"></i>Registrar técnico de apoyo (T2)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Queda registrado en el partner <strong id="nuevo_apoyo_partner">—</strong> y aparecerá en la lista de T2 para las siguientes supervisiones.</p>
          <div class="mb-3">
            <label for="nuevo_apoyo_nombre" class="form-label">Nombres y apellidos</label>
            <input type="text" class="form-control" id="nuevo_apoyo_nombre" maxlength="120" autocomplete="off" required>
          </div>
          <div>
            <label for="nuevo_apoyo_dni" class="form-label">DNI</label>
            <input type="text" class="form-control" id="nuevo_apoyo_dni" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" autocomplete="off" required>
          </div>
          <div class="alert alert-danger small mt-3 mb-0 d-none" id="nuevo_apoyo_error" role="alert"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-win" id="btn-nuevo-apoyo"><i class="bi bi-check2 me-1"></i>Registrar técnico</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="toast-bitacora" id="toast"></div>

<script>
// Datos del servidor para supervision_lista.js (2026-10-16).
window.SGS_SUPERVISION = <?= json_encode([
    'partners' => $partnersList,
    'tipificaciones' => $tipificacionesCancelacion,
    'rol' => $effectiveViewUser['rol'] ?? '',
    'departamento' => $registeredDepartmentScope,
    'usuarioId' => (int)($effectiveViewUser['id'] ?? 0),
    'winDirecto' => !empty($effectiveViewUser['is_win_directo']),
    'supervisorPartner' => !empty($esSupervisorPartnerVista),
    'jefeOperaciones' => !empty($esJefeOperacionesVista),
    'formHallazgos' => (bool)$canUseFindingsForm,
    'formChecklist' => (bool)$canUseChecklistForm,
    'seccionesChecklist' => $checklistSecciones,
    'itemsVencimiento' => $itemsVencimiento,
    'inicioVencimiento' => $inicioVencimiento,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="03_FRONTEND/src/assets/js/supervision_lista.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
