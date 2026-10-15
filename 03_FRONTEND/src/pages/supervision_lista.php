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
    'Vehículo' => ['Logos de WIN','SOAT','Revisión técnica','Estado del vehículo']
];
$itemsChecklist = array_merge(...array_values($checklistSecciones));
?>

<style>
  :root {
    --win-dark: var(--sgs-ink);
    --win-dark-gradient: linear-gradient(135deg, var(--sgs-ink) 0%, var(--sgs-ink-2) 100%);
    --win-orange: var(--sgs-orange);
    --win-orange-gradient: linear-gradient(135deg, var(--sgs-orange) 0%, var(--sgs-orange-dark) 100%);
    --win-orange-hover: var(--sgs-orange-dark);
    --win-card-bg: #ffffff;
    --win-bg: var(--sgs-bg);
    --win-text-dark: var(--sgs-ink);
    --win-text-muted: var(--sgs-muted);
    --win-border: var(--sgs-border);
    --win-radius: 8px;
  }

  .wrap-bitacora {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 16px;
    font-family: var(--sgs-font);
  }
  #pending-map { height:360px; width:100%; border-radius:10px; border:1px solid var(--win-border); z-index:1; }
  .pending-map-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
  @media(max-width:700px){#pending-map{height:300px}.pending-map-header{align-items:flex-start;flex-direction:column}}

  /* Encabezado Ejecutivo */
  .masthead-bitacora {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--sgs-surface);
    padding: 22px 28px;
    border-radius: var(--win-radius);
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(20, 20, 20, 0.22);
    border-left: 0;
  }
  .masthead-bitacora h1 {
    font-weight: 700;
    font-size: clamp(22px, 3.5vw, 28px);
    letter-spacing: -0.01em;
    color: #ffffff;
    margin: 0;
    text-transform: uppercase;
  }
  .masthead-bitacora .meta-bitacora {
    font-size: 12.5px;
    color: var(--sgs-border-strong);
    background: rgba(255, 255, 255, 0.08);
    padding: 8px 16px;
    border-radius: 6px;
    text-align: right;
    border: 1px solid rgba(255, 255, 255, 0.12);
  }

  /* Botones Ejecutivos & Toolbar */
  .action-toolbar-bitacora {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .action-buttons-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
  }

  .new-btn-bitacora {
    font-size: 13.5px;
    font-weight: 600;
    background: var(--win-orange-gradient);
    color: #ffffff;
    border: none;
    padding: 9px 18px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 4px 14px rgba(255,90,10, 0.35);
  }
  .new-btn-bitacora:hover { opacity: 0.96; transform: translateY(-2px); box-shadow: 0 6px 18px rgba(255,90,10, 0.45); }
  .new-btn-bitacora:active { transform: translateY(0); }
  .new-btn-bitacora:disabled { cursor:not-allowed; opacity:.6; filter:grayscale(.35); transform:none; box-shadow:none; }

  .btn-action-excel {
    font-size: 13px;
    font-weight: 500;
    color: var(--sgs-ink);
    background: #ffffff;
    border: 1px solid var(--win-border);
    padding: 9px 15px;
    border-radius: 8px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.15s ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
  }
  .btn-action-excel:hover { background: var(--sgs-surface-alt); color: var(--sgs-ink); border-color: var(--sgs-border-strong); transform: translateY(-1px); }

  .btn-action-upload {
    font-size: 13px;
    font-weight: 600;
    color: #ffffff;
    background: var(--sgs-surface);
    border: 1px solid rgba(255,255,255,0.1);
    padding: 9px 16px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(20, 20, 20, 0.2);
  }
  .btn-action-upload:hover { opacity: 0.95; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(20, 20, 20, 0.3); }

  .back-btn-bitacora {
    font-size: 13px;
    font-weight: 500;
    color: var(--win-text-muted);
    background: #ffffff;
    border: 1px solid var(--win-border);
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 20px;
    transition: all 0.15s ease;
  }
  .back-btn-bitacora:hover { background: var(--sgs-surface-alt); color: var(--win-text-dark); border-color: var(--sgs-border-strong); }

  /* Filtros por Estado */
  .filter-tabs-bitacora {
    display: inline-flex;
    background: #ffffff;
    padding: 4px;
    border-radius: 8px;
    border: 1px solid var(--win-border);
    gap: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
  }
  .filter-tab-btn {
    font-size: 12.5px;
    font-weight: 600;
    padding: 7px 12px;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--win-text-muted);
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .filter-tab-btn:hover {
    color: var(--win-text-dark);
    background: var(--sgs-surface-alt);
  }
  .filter-tab-btn.active {
    background: var(--win-dark);
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(20, 20, 20, 0.15);
  }

  /* Panel de Control Unificado en una Sola Línea Recta */
  .supervision-control-panel-inline {
    background: #ffffff;
    border: 1px solid var(--win-border);
    border-radius: 12px;
    padding: 12px 18px;
    margin-bottom: 22px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
  }

  /* Filtro de Fechas Compacto */
  .filter-date-panel-compact {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    background: #ffffff;
    border: 1px solid var(--win-border);
    border-radius: 8px;
    padding: 4px 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
  }
  .admin-scope-filters {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .admin-scope-filter {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .admin-scope-filter label {
    margin: 0;
    color: var(--win-text-muted);
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
  }
  .admin-scope-filter select {
    min-width: 170px;
    max-width: 220px;
    height: 34px;
    padding: 4px 30px 4px 9px;
    border: 1px solid var(--win-border);
    border-radius: 7px;
    background-color: var(--sgs-surface-alt);
    color: var(--win-text-dark);
    font-size: 12px;
  }
  .admin-scope-filter select:focus {
    border-color: var(--win-orange);
    outline: none;
    box-shadow: 0 0 0 2px rgba(255,90,10, 0.15);
  }
  .filter-date-group {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .filter-date-group label {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--win-text-muted);
    margin: 0;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .filter-date-group input[type="date"] {
    font-family: var(--sgs-font);
    font-size: 12px;
    padding: 4px 8px;
    height: 32px;
    border: 1px solid var(--win-border);
    border-radius: 6px;
    background: var(--sgs-surface-alt);
    color: var(--win-text-dark);
    max-width: 140px;
    transition: all 0.15s ease;
  }
  .filter-date-group input[type="date"]:focus {
    background: #ffffff;
    border-color: var(--win-orange);
    outline: none;
    box-shadow: 0 0 0 2px rgba(255,90,10, 0.15);
  }

  /* Botón Selector de Columnas Visibles */
  .btn-action-columns {
    font-size: 13px;
    font-weight: 600;
    color: var(--sgs-ink);
    background: #ffffff;
    border: 1px solid var(--win-border);
    padding: 6px 14px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
  }
  .btn-action-columns:hover {
    background: var(--sgs-surface-alt);
    border-color: var(--sgs-border-strong);
  }
  .style-col-check {
    font-size: 12.5px;
    color: var(--win-text-dark);
    cursor: pointer;
    user-select: none;
    transition: color 0.12s ease;
  }
  .style-col-check:hover {
    color: var(--win-orange);
  }

  /* Vista Móvil: Tarjetas Horizontales */
  .mobile-records-wrapper {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .mobile-supervision-card {
    background: #ffffff;
    border: 1px solid var(--win-border);
    border-radius: 12px;
    padding: 14px 16px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.04);
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
  }
  .mobile-supervision-card:active {
    background: var(--sgs-surface-alt);
    transform: scale(0.99);
  }
  .mobile-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--sgs-surface-alt);
    margin-bottom: 10px;
  }
  .mobile-card-title-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .mobile-card-folio {
    font-family: var(--sgs-font);
    font-size: 14px;
    font-weight: 700;
    color: var(--win-orange);
  }
  .mobile-card-arrow {
    color: var(--sgs-muted-2);
    font-size: 16px;
  }
  .mobile-card-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .mobile-card-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .mobile-card-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .mobile-card-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--win-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .mobile-card-val {
    font-size: 13px;
    font-weight: 600;
    color: var(--win-text-dark);
    word-break: break-word;
  }
  .mobile-card-subinfo {
    font-size: 12px;
    color: var(--win-text-muted);
    background: var(--sgs-surface-alt);
    padding: 6px 10px;
    border-radius: 6px;
    border: 1px solid var(--sgs-border);
    margin-top: 4px;
  }
  .mobile-card-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed var(--sgs-border);
  }
  .btn-mobile-action {
    font-size: 12px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .btn-mobile-assign {
    background: var(--sgs-orange-soft);
    color: var(--sgs-orange-dark);
    border-color: var(--sgs-orange-border);
  }
  .btn-mobile-delete {
    background: var(--sgs-danger-soft);
    color: var(--sgs-danger);
    border-color: var(--sgs-danger-border);
  }

  /* Card Contenedora */
  .panel-bitacora {
    background: var(--win-card-bg);
    border-radius: var(--win-radius);
    border: 1px solid var(--win-border);
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
  }

  .form-panel-bitacora {
    padding: 28px;
    margin-bottom: 24px;
    border-top: 0;
  }
  .form-title-bitacora {
    font-size: 18px;
    font-weight: 700;
    color: var(--win-dark);
    margin: 0 0 20px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .form-title-bitacora .folio-preview {
    font-size: 13px;
    color: var(--win-orange);
    background: rgba(255,90,10, 0.1);
    padding: 3px 10px;
    border-radius: 4px;
    font-weight: 600;
  }

  .section-subtitle-bitacora {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--win-dark);
    margin: 22px 0 12px;
    padding-bottom: 6px;
    border-bottom: 2px solid var(--win-border);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .section-subtitle-bitacora::before {
    content: none;
  }

  .grid-bitacora {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 18px;
  }
  .field-bitacora { display: flex; flex-direction: column; gap: 6px; }
  .field-bitacora.full, .grid-bitacora.full { grid-column: 1 / -1; }
  .field-bitacora label {
    font-size: 12.5px;
    font-weight: 650;
    color: var(--win-text-dark);
    text-transform: none;
    letter-spacing: 0;
  }
  .field-bitacora input, .field-bitacora select, .field-bitacora textarea {
    font-family: var(--sgs-font);
    font-size: 12.5px;
    padding: 8px 10px;
    border: 1px solid var(--win-border);
    border-radius: 6px;
    background: #ffffff;
    color: var(--win-text-dark);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }
  .field-bitacora textarea { resize: vertical; min-height: 80px; }
  .field-bitacora input:focus, .field-bitacora select:focus, .field-bitacora textarea:focus {
    border-color: var(--win-orange);
    outline: none;
    box-shadow: 0 0 0 3px rgba(255,90,10, 0.15);
  }
  .field-bitacora .input-group { display:flex; flex-wrap:nowrap; align-items:stretch; width:100%; }
  .field-bitacora .input-group > input { flex:1 1 auto; min-width:0; width:1%; border-radius:6px 0 0 6px; }
  .field-bitacora .input-group > .btn-editar-tecnico { flex:0 0 44px; width:44px; margin:0; border:1px solid var(--win-border); border-left:0; border-radius:0 6px 6px 0; display:flex; align-items:center; justify-content:center; }
  .optional-module-toggle { background:var(--sgs-surface-alt); border:1px solid var(--win-border); border-radius:8px; padding:14px; margin-bottom:12px; }
  .optional-module-toggle label { margin:0; }
  .cancel-validation-grid { display:grid; grid-template-columns:1fr; gap:14px; }
  .floating-start-action { position:static; display:flex; width:max-content; min-width:230px; margin:24px 0 0 auto; justify-content:center; box-shadow:0 8px 22px rgba(0,0,0,.16); }

  .form-actions-bitacora {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid var(--win-border);
  }
  .btn-bitacora {
    font-size: 13px;
    font-weight: 600;
    padding: 8px 13px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .btn-primary-bitacora { background: var(--win-dark); color: #fff; }
  .btn-primary-bitacora:hover { background: var(--win-dark-hover); }
  .btn-orange-bitacora { background: var(--win-orange-gradient); color: #fff; }
  .btn-orange-bitacora:hover { opacity: 0.95; }
  .btn-ghost-bitacora { background: #ffffff; color: var(--win-text-muted); border-color: var(--win-border); }
  .btn-ghost-bitacora:hover { background: var(--sgs-surface-alt); color: var(--win-text-dark); }
  .btn-finish-bitacora { background: #059669; color: #fff; }
  .btn-finish-bitacora:hover { background: #047857; }
  .btn-danger-bitacora { background: var(--sgs-danger); color: #fff; }
  .btn-danger-bitacora:hover { background: var(--sgs-danger-dark); }
  .btn-danger-subtle { background: var(--sgs-danger-soft); color: var(--sgs-danger); border: 1px solid var(--sgs-danger-border); }
  .btn-danger-subtle:hover { background: var(--sgs-danger-soft); color: var(--sgs-danger-dark); }
  .btn-gps-bitacora { background: var(--sgs-surface-alt); color: var(--sgs-ink-2); border: 1px solid var(--sgs-border-strong); padding: 9px 14px; font-size: 12.5px; }
  .btn-gps-bitacora:hover { background: var(--sgs-border); color: var(--sgs-ink); }

  /* Galería de Evidencias y Carga Suave */
  .photo-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 12px;
    margin-top: 10px;
  }
  .photo-item {
    position: relative;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--win-border);
    background: var(--sgs-border);
    aspect-ratio: 4/3;
    cursor: pointer;
  }
  .photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity 0.35s ease, transform 0.2s ease;
  }
  .photo-item img.loaded {
    opacity: 1;
  }
  .photo-item:hover img {
    transform: scale(1.05);
  }

  /* Tabla Estilo Ejecutivo */
  .table-panel-bitacora { padding: 0; overflow: hidden; border-radius: var(--win-radius); }
  table.table-bitacora { width: 100%; border-collapse: collapse; text-align: left; }
  table.table-bitacora thead th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #ffffff;
    background: var(--sgs-surface);
    padding: 15px 18px;
    font-weight: 600;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }
  table.table-bitacora tbody tr.row-bitacora {
    cursor: pointer;
    border-bottom: 1px solid var(--win-border);
    transition: background 0.12s ease;
  }
  table.table-bitacora tbody tr.row-bitacora:last-child { border-bottom: none; }
  table.table-bitacora tbody tr.row-bitacora:hover { background: var(--sgs-surface-alt); }
  table.table-bitacora tbody td {
    padding: 15px 18px;
    font-size: 13.5px;
    vertical-align: middle;
    color: var(--win-text-dark);
  }
  .folio-bitacora {
    font-family: var(--sgs-font);
    font-size: 13px;
    color: var(--win-orange);
    font-weight: 700;
  }
  .col-caret-bitacora { width: 24px; color: var(--sgs-muted-2); font-size: 14px; text-align: center; }

  /* Badges Ejecutivos de Estado */
  .badge-exec {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
  }
  .badge-exec.iniciada {
    background: #fffbe0;
    color: #b45309;
    border: 1px solid #fde68a;
  }
  .badge-exec.iniciada::before {
    content: ''; display: inline-block; width: 6px; height: 6px; background: #d97706; border-radius: 50%;
  }
  .badge-exec.finalizada {
    background: #f0fdf4;
    color: var(--sgs-success-dark);
    border: 1px solid #bbf7d0;
  }
  .badge-exec.finalizada::before {
    content: ''; display: inline-block; width: 6px; height: 6px; background: #16a34a; border-radius: 50%;
  }
  .badge-exec.pendiente {
    background: var(--sgs-danger-soft);
    color: var(--sgs-danger-dark);
    border: 1px solid var(--sgs-danger-border);
  }
  .badge-exec.pendiente::before {
    content: ''; display: inline-block; width: 6px; height: 6px; background: var(--sgs-danger); border-radius: 50%;
  }

  .empty-bitacora {
    padding: 50px 20px;
    text-align: center;
    color: var(--win-text-muted);
    font-size: 14px;
  }

  /* Swapping en la misma ventana */
  #view-detail, #view-new { display: none !important; }
  #view-detail.open, #view-new.open { display: block !important; }
  #view-list.hidden { display: none !important; }

  .detail-panel-bitacora { padding: 30px; border-top: 4px solid var(--win-dark); }
  .detail-head-bitacora {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 22px;
    flex-wrap: wrap;
    gap: 12px;
    border-bottom: 1px solid var(--win-border);
    padding-bottom: 18px;
  }
  .detail-head-bitacora h2 {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 6px;
    color: var(--win-dark);
  }
  .detail-sub-bitacora {
    font-size: 13px;
    color: var(--win-text-muted);
  }

  .toast-bitacora {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--win-dark);
    color: #ffffff;
    font-size: 13px;
    font-weight: 500;
    padding: 12px 22px;
    border-radius: 6px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    opacity: 0;
    transition: opacity 0.2s ease, transform 0.2s ease;
    pointer-events: none;
    z-index: 9999;
  }
  .toast-bitacora.show { opacity: 1; transform: translateX(-50%) translateY(0); }

  /* Ajustes móviles: los filtros, acciones y tabla conservan una interacción clara. */
  @media (max-width: 767.98px) {
    .wrap-bitacora { padding: 14px 10px; }
    .masthead-bitacora { padding: 18px; align-items: flex-start; flex-direction: column; gap: 12px; }
    .masthead-bitacora .meta-bitacora { width: 100%; text-align: left; }
    .supervision-control-panel-inline { padding: 14px 12px; gap: 12px; flex-direction: column; align-items: stretch; }
    .filter-date-panel-compact { width: 100%; justify-content: space-between; }
    .admin-scope-filters { display: grid; grid-template-columns: 1fr; width: 100%; }
    .admin-scope-filter { align-items: stretch; flex-direction: column; gap: 4px; }
    .admin-scope-filter select { width: 100%; max-width: none; }
    .btn-action-columns { width: 100%; justify-content: center; }
    .action-buttons-group { flex-direction: column; width: 100%; gap: 8px; }
    .action-buttons-group .new-btn-bitacora,
    .action-buttons-group .btn-action-excel,
    .action-buttons-group .btn-action-upload {
      width: 100%;
      justify-content: center;
    }
    .filter-tabs-bitacora {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
      width: 100%;
      overflow: visible;
    }
    .filter-tab-btn { width: 100%; text-align: center; white-space: normal; line-height: 1.2; padding: 8px 6px; }
    .form-panel-bitacora, .detail-panel-bitacora { padding: 18px 14px; }
    .grid-bitacora { grid-template-columns: 1fr; gap: 14px; }
    .form-actions-bitacora { flex-direction: column-reverse; }
    .form-actions-bitacora .btn-bitacora, .new-btn-bitacora { justify-content: center; width: 100%; }
    .floating-start-action { width:100%; min-width:0; margin-top:20px; }
    .cancel-validation-grid { grid-template-columns:1fr; }
  }
</style>

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
    <button class="back-btn-bitacora" id="cancelNewBtn" type="button"><i class="bi bi-arrow-left me-1"></i>Retroceder</button>

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
            <div class="field-bitacora full sgs-recordar-cuadrilla">
              <input type="hidden" name="recordar_cuadrilla" value="0">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="new_recordar_cuadrilla" name="recordar_cuadrilla" value="1" checked>
                <label class="form-check-label" for="new_recordar_cuadrilla"><strong>Guardar el T2 y el vehículo</strong> (con su estado) como cuadrilla de este técnico líder al cerrar la supervisión: saldrán por defecto la próxima vez.</label>
              </div>
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
              Tu ubicación se toma sola al abrir la supervisión. Si cambias de lugar, presiona "Actualizar ubicación".
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
        <style>
          .checklist-opciones{display:grid;grid-template-columns:repeat(4,minmax(82px,1fr));gap:7px}
          .checklist-opcion{position:relative;cursor:pointer;margin:0}
          .checklist-opcion input{position:absolute;opacity:0;pointer-events:none}
          .checklist-opcion span{display:flex;align-items:center;justify-content:center;min-height:44px;padding:8px 6px;border:2px solid #d5dce3;border-radius:9px;background:#fff;color:#475569;font-size:12px;font-weight: 700;text-align:center;transition:.15s ease;user-select:none}
          .checklist-opcion input:focus-visible + span{outline:3px solid rgba(255,90,10,.28)}
          .checklist-opcion.bueno input:checked + span{background:var(--sgs-success-soft);border-color:var(--sgs-success);color:var(--sgs-success-dark)}
          .checklist-opcion.regular input:checked + span{background:var(--sgs-warning-soft);border-color:var(--sgs-warning);color:var(--sgs-warning-dark)}
          .checklist-opcion.malo input:checked + span{background:var(--sgs-danger-soft);border-color:var(--sgs-danger);color:var(--sgs-danger-dark)}
          .checklist-opcion.no-tiene input:checked + span{background:var(--sgs-border);border-color:var(--sgs-muted);color:var(--sgs-ink-2)}
          .checklist-opcion.si input:checked + span{background:var(--sgs-success-soft);border-color:var(--sgs-success);color:var(--sgs-success-dark)}
          .checklist-opcion.tiene input:checked + span{background:var(--sgs-success-soft);border-color:var(--sgs-success);color:var(--sgs-success-dark)}
          .checklist-opcion.no input:checked + span,.checklist-opcion.mal-estado input:checked + span{background:var(--sgs-danger-soft);border-color:var(--sgs-danger);color:var(--sgs-danger-dark)}
          .checklist-opcion.para-cambio input:checked + span{background:var(--sgs-warning-soft);border-color:var(--sgs-warning);color:var(--sgs-warning-dark)}
          .checklist-opcion.no-revisado input:checked + span{background:var(--sgs-border);border-color:var(--sgs-muted);color:var(--sgs-ink-2)}
          .checklist-group{border:1px solid #dde3ea;border-radius:12px;background:#fff;margin-bottom:16px;overflow:hidden}
          .checklist-group-title{margin:0;padding:11px 14px;background:linear-gradient(135deg,var(--sgs-ink),var(--sgs-ink-2));color:#fff;font-size:13px;font-weight: 700;text-transform:uppercase;letter-spacing:.035em;border-left:0}
          .checklist-group-body{padding:4px 14px}
          .checklist-row{grid-template-columns:minmax(460px,2fr) minmax(210px,1fr) minmax(210px,1fr)!important;gap:14px!important;padding:12px 0!important;margin:0!important}
          .checklist-row:last-child{border-bottom:0!important}
          .checklist-row input[type=file]{font-size:12px;padding:7px}
          .checklist-signature-pad{display:block;width:100%;max-width:520px;height:150px;background:#fff;border:2px dashed var(--sgs-muted-2);border-radius:10px;touch-action:none;cursor:crosshair}
          @media(max-width:1250px) and (min-width:701px){.checklist-row{grid-template-columns:1fr 1fr!important}.checklist-row>.field-bitacora:first-of-type{grid-column:1/-1}}
          @media(max-width:700px){.checklist-opciones{grid-template-columns:repeat(2,1fr)}.checklist-opcion span{min-height:50px;font-size:13px}.checklist-row{display:block!important}.checklist-row>.field-bitacora{margin-bottom:10px}}
        </style>
        <div id="new_sec_checklist" style="display:none;background:var(--sgs-surface-alt);border:1px solid var(--win-border);border-radius:8px;padding:16px;margin-bottom:16px;">
          <p class="section-subtitle-bitacora" style="margin-top:0;">6. Evaluación mediante checklist técnico</p>

          <?php $idx=0; foreach($checklistSecciones as $seccion=>$itemsSeccion): $opcionesSeccion=$seccion==='EPP y EPE'?['Sí','No','Para cambio','No revisado']:($seccion==='Indumentaria'?['Sí','No','Mal estado','No revisado']:($seccion==='Materiales y ferretería'?['Sí','No']:['Bueno','Regular','Malo','No tiene'])); ?><section class="checklist-group"><h4 class="checklist-group-title"><i class="bi bi-tools me-2"></i><?= htmlspecialchars($seccion) ?></h4><div class="checklist-group-body"><?php foreach($itemsSeccion as $item): ?>
          <div class="grid-bitacora mb-2 checklist-row" data-criterio="<?= htmlspecialchars($item,ENT_QUOTES,'UTF-8') ?>" style="align-items:end;border-bottom:1px solid var(--sgs-success-soft);padding-bottom:10px;">
            <input type="hidden" name="checklist_criterio[]" value="<?= htmlspecialchars($item,ENT_QUOTES,'UTF-8') ?>">
            <div class="field-bitacora"><label><?= htmlspecialchars($item) ?> *</label><?php if($seccion==='Materiales y ferretería'): ?><small class="text-muted fw-semibold mb-1">¿Cuenta con stock?</small><?php endif; ?><div class="checklist-opciones">
              <?php foreach($opcionesSeccion as $opcion): $claseOpcion=strtolower(str_replace(['í',' ','ó'],['i','-','o'],$opcion)); ?><label class="checklist-opcion <?= htmlspecialchars($claseOpcion) ?>"><input type="radio" name="checklist_estado[<?= $idx ?>]" value="<?= htmlspecialchars($opcion,ENT_QUOTES,'UTF-8') ?>" class="checklist-estado"><span><?= htmlspecialchars($opcion) ?></span></label><?php endforeach; ?>
            </div></div>
            <div class="field-bitacora"><label>Observación (opcional)</label><input name="checklist_observacion[]" type="text" placeholder="Detalle sobre esta herramienta"></div>
            <div class="field-bitacora"><label>Foto (opcional)</label><input name="checklist_foto[<?= $idx ?>]" type="file" accept="image/jpeg,image/png,image/webp"></div>
          </div>
          <?php $idx++; endforeach; ?></div></section><?php endforeach; ?>
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
          <p style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:10px;"><i class="bi bi-camera me-1"></i>Evidencias Fotográficas (Carga de hasta 3 Fotos)</p>
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
      <button class="btn-bitacora btn-orange-bitacora floating-start-action" id="startBtn" type="submit" form="form-inicio-supervision" disabled>
        <i class="bi bi-save2 me-2"></i><span id="startBtnLabel">Complete la sección 1</span>
      </button>
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

<script class="spa-dynamic-script">
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

  const partnersData = <?= json_encode($partnersList) ?>;
  const tipificacionesCancelacion = <?= json_encode($tipificacionesCancelacion, JSON_UNESCAPED_UNICODE) ?>;

  const currentUserRole = <?= json_encode($effectiveViewUser['rol'] ?? '') ?>;
  const currentUserDepartment = <?= json_encode($registeredDepartmentScope, JSON_UNESCAPED_UNICODE) ?>;
  const esAdministradorActual = currentUserRole === 'Administrador';
  const currentUserId = <?= (int)($effectiveViewUser['id'] ?? 0) ?>;
  const esSupervisorWinDirecto = currentUserRole === 'Supervisor' && <?= !empty($effectiveViewUser['is_win_directo']) ? 'true' : 'false' ?>;
  const esSupervisorPartnerActual = <?= !empty($esSupervisorPartnerVista) ? 'true' : 'false' ?>;
  const esJefeOperacionesActual = <?= !empty($esJefeOperacionesVista) ? 'true' : 'false' ?>;   // solo consulta
  const canUseFindingsForm = <?= $canUseFindingsForm ? 'true' : 'false' ?>;
  const canUseChecklistForm = <?= $canUseChecklistForm ? 'true' : 'false' ?>;
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
      const tablaCarga = (titulo, ayuda, cabeceras, filas) => `<div class="mt-3"><strong>${titulo}</strong>${ayuda ? `<div class="small text-muted">${ayuda}</div>` : ''}<div class="table-responsive sgs-import-tabla mt-2"><table class="table table-sm small mb-0"><thead><tr>${cabeceras.map(c => `<th>${c}</th>`).join('')}</tr></thead><tbody>${filas.join('')}</tbody></table></div></div>`;
      // Ya supervisados: no se ingresaron (2026-10-12)
      const yaHtml = yaSupervisados.length ? tablaCarga(`Códigos no ingresados: ya están en Supervisiones (${yaSupervisados.length})`,
        'Ya existe una supervisión con el mismo código de pedido o ticket. El estado indica si ya se supervisó (Finalizada) o si sigue pendiente.',
        ['Fila', 'Código / ticket', 'Supervisión', 'Estado', 'Fecha'],
        yaSupervisados.map(y => `<tr><td>${Number(y.fila)}</td><td><strong>${escapeHtml(y.ticket)}</strong></td><td>${escapeHtml(y.supervision || '—')}</td><td>${escapeHtml(y.estado || '—')}</td><td>${escapeHtml(y.fecha || '—')}</td></tr>`)) : '';
      const repetidosHtml = repetidos.length ? tablaCarga(`Repetidos en el mismo archivo (${repetidos.length})`, 'Se cargaron una sola vez.',
        ['Fila', 'Código / ticket', 'Ya estaba en la fila'],
        repetidos.map(x => `<tr><td>${Number(x.fila)}</td><td>${escapeHtml(x.ticket)}</td><td>${Number(x.fila_original)}</td></tr>`)) : '';
      const issueHtml = issues.length ? tablaCarga(`Filas con problemas (${issues.length})`, 'No se cargaron; corrija el dato y vuelva a subir solo esas filas.',
        ['Fila', 'Código / ticket', 'Motivo'],
        issues.map(issue => `<tr><td>${Number(issue.fila)}</td><td>${escapeHtml(issue.ticket || '—')}</td><td>${escapeHtml((issue.errores || []).join('; '))}</td></tr>`)) : '';
      output.innerHTML = `<div class="alert ${result.status==='success'?'alert-success':'alert-danger'} mb-0"><strong>${escapeHtml(result.message||'Proceso terminado.')}</strong>${result.que_hacer ? `<div class="mt-2"><strong>Qué hacer:</strong> ${escapeHtml(result.que_hacer)}</div>` : ''}${result.detalle ? `<div class="small text-muted mt-2">Código de seguimiento: ${escapeHtml(result.codigo || '—')} · Detalle técnico: <code>${escapeHtml(result.detalle)}</code></div>` : ''}${result.creadas!==undefined?`<div class="small mt-1">Pendientes creadas: ${Number(result.creadas)} · Ya existían: ${yaSupervisados.length} · Con problemas: ${issues.length}</div>`:''}</div>${yaHtml}${repetidosHtml}${issueHtml}`;
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
    if(folioPreview) folioPreview.textContent = nextFolio();
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
    // Elegido el técnico, el campo queda fijo (no se escribe encima); el botón de quitar lo libera para buscar otro.
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
    const renderResults = () => {
      const term = search.value.trim().toLocaleLowerCase('es');
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
    apoyoDestino = { select, partnerId: partner.value };
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

    const updateSearchValFromSelect = () => {
      const selectedOpt = select.options[select.selectedIndex];
      search.value = (selectedOpt && selectedOpt.value) ? selectedOpt.text : '';
    };

    updateSearchValFromSelect();

    select.addEventListener('change', updateSearchValFromSelect);

    const syncState = () => {
      search.disabled = select.disabled;
      updateSearchValFromSelect();
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
      if (search.value !== (select.options[select.selectedIndex]?.text || '')) {
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
      renderResults();
    });
    search.addEventListener('search', renderResults);

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

  function poblarSelectsTecnicos() {
    // TÉCNICO LÍDER (T1): Cargar todos los de la lista maestra (tipo 1)
    if (newTec1Nom) {
      const currentVal1 = newTec1Nom.value;
      newTec1Nom.innerHTML = '<option value="">Seleccione / busque técnico líder (T1)</option>';
      const t1List = listaTecnicos.filter(t => String(t.partner_id) === newPartner.value && (t.tipo_tecnico == 1 || !t.tipo_tecnico));
      t1List.forEach(t => {
        const isSel = (t.nombre_tecnico === currentVal1) ? 'selected' : '';
        newTec1Nom.innerHTML += `<option value="${escapeHtml(t.nombre_tecnico)}" data-id="${t.id}" ${isSel}>${escapeHtml(t.nombre_tecnico)}</option>`;
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
    inputDni.value = match ? (match.dni || '0') : '';
    if (tipo === 1) {
      const prefix = select.id.startsWith('edit_') ? 'edit' : 'new';
      const plate = document.getElementById(prefix + '_u_placa');
      const vehicleType = document.getElementById(prefix + '_u_tipo');
      if (plate) plate.value = match?.unidad_placa || '';
      if (vehicleType) vehicleType.value = match?.unidad_tipo || '';
      const vehicleState = document.getElementById(prefix + '_u_estado');
      if (vehicleState && match?.unidad_estado) vehicleState.value = match.unidad_estado; // último estado registrado de su vehículo
      // Cuadrilla (2026-10-15): su técnico de apoyo habitual, si el T2 está vacío y es de la lista de este partner.
      const t2 = document.getElementById(prefix + '_tec2_nom');
      if (t2 && match?.apoyo_nombre && !t2.value && [...t2.options].some(o => o.value === match.apoyo_nombre)) {
        t2.value = match.apoyo_nombre;
        t2.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

  newPartner?.addEventListener('change', () => {
    poblarSelectsTecnicos();
    [newTec1Nom,newTec2Nom].forEach(el => el?.dispatchEvent(new Event('change')));
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
      // No llevan 'required': la hora de fin aún no se conoce al iniciar. Se exigen al finalizar (newFormReadyToFinish, validación de edición y servidor).
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
        newSecChecklist.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]), textarea').forEach(control => { control.value = ''; });
        const canvas = document.getElementById('new_checklist_firma_canvas');
        canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
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

  // Captura GPS precisa (2026-10-15). Antes se tomaba la PRIMERA lectura, que el celular suele dar por red o wifi
  // (errores de cientos de metros). Ahora se escucha el GPS hasta 25 s y se conserva la lectura más precisa;
  // termina antes si llega a ±15 m. Las coordenadas se van mostrando mientras mejoran.
  var GPS_PRECISION_BUENA = 15, GPS_ESPERA_MS = 25000, GPS_PRECISION_ACEPTABLE = 50; // var: sgsModoCoordenadas puede llamarse antes de esta línea
  var gpsEnCurso = window.sgsGpsEnCurso = window.sgsGpsEnCurso || {};
  window.capturarGPS = function(targetLatId, targetLngId, statusId) {
    const statusEl = document.getElementById(statusId);
    const latEl = document.getElementById(targetLatId), lngEl = document.getElementById(targetLngId);
    const aviso = (texto, tipo = '') => { if (statusEl) { statusEl.textContent = texto; statusEl.dataset.estado = tipo; } };
    if (!navigator.geolocation) return aviso('Este navegador no permite usar el GPS. Avise al administrador.', 'error');
    detenerGPS(targetLatId);
    let mejor = null;
    const hora = () => new Date().toLocaleTimeString('es-PE', {hour:'2-digit', minute:'2-digit', hour12:false});
    const poner = (pos) => {
      const lat = pos.coords.latitude.toFixed(7), lng = pos.coords.longitude.toFixed(7);
      if (latEl) { latEl.value = lat; latEl.dataset.origen = 'gps'; }
      if (lngEl) { lngEl.value = lng; lngEl.dataset.origen = 'gps'; }
      latEl?.dispatchEvent(new Event('input', {bubbles:true}));
      lngEl?.dispatchEvent(new Event('input', {bubbles:true}));
    };
    const terminar = () => {
      detenerGPS(targetLatId);
      if (!mejor) return aviso('No se pudo tomar la ubicación. Revisa que el GPS esté encendido y presiona "Actualizar ubicación".', 'error');
      const m = Math.round(mejor.coords.accuracy);
      aviso(m <= GPS_PRECISION_ACEPTABLE
        ? `Ubicación tomada a las ${hora()} con precisión de ±${m} m.`
        : `Ubicación tomada a las ${hora()}, pero con precisión baja (±${m} m). Sal a un lugar abierto, espera unos segundos y presiona "Actualizar ubicación".`,
        m <= GPS_PRECISION_ACEPTABLE ? 'ok' : 'baja');
    };
    aviso('Buscando tu ubicación… mantén el celular quieto unos segundos.', 'buscando');
    const id = navigator.geolocation.watchPosition((pos) => {
      if (!mejor || pos.coords.accuracy < mejor.coords.accuracy) {
        mejor = pos;
        poner(pos);
        aviso(`Afinando ubicación… precisión actual ±${Math.round(pos.coords.accuracy)} m.`, 'buscando');
      }
      if (pos.coords.accuracy <= GPS_PRECISION_BUENA) terminar();
    }, (err) => {
      if (err && err.code === 1) { detenerGPS(targetLatId); return aviso('El celular no dio permiso de ubicación. Actívalo y presiona "Actualizar ubicación".', 'error'); }
      if (!mejor && err && err.code === 3) terminar();
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
  // Google Maps) en cualquiera de los dos campos y se separan solas.
  document.addEventListener('input', (ev) => {
    const el = ev.target;
    if (!(el instanceof HTMLInputElement) || !['latitud', 'longitud'].includes(el.name)) return;
    if (ev.isTrusted) el.dataset.origen = 'manual';
    const m = el.value.match(/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/);
    if (!m) return;
    const form = el.form || el.closest('form') || document;
    const lat = form.querySelector('input[name="latitud"]'), lng = form.querySelector('input[name="longitud"]');
    if (!lat || !lng) return;
    lat.value = m[1]; lng.value = m[2];
    lat.dataset.origen = lng.dataset.origen = 'manual';
    lng.dispatchEvent(new Event('change', {bubbles:true}));
  });

  // Coordenadas según la modalidad (2026-10-15, a pedido del usuario): en TODAS se toma el GPS al iniciar y se
  // puede actualizar. La única diferencia: en la remota se pueden editar a mano (p. ej. las del cliente); en las
  // presenciales quedan solo por GPS. El administrador siempre puede corregirlas.
  function sgsModoCoordenadas(form, remota) {
    const lat = form?.querySelector('input[name="latitud"]'), lng = form?.querySelector('input[name="longitud"]');
    if (!lat || !lng) return;
    const status = document.getElementById(lat.id.replace('latitud', 'gps_status'));
    const editable = remota || esAdministradorActual;
    const veniaEscrita = lat.dataset.origen === 'manual';
    [lat, lng].forEach(c => { c.readOnly = !editable; c.title = editable ? '' : 'Se toma del GPS. Use "Actualizar ubicación".'; });
    lat.placeholder = remota ? 'Ej.: -8.139075' : '-12.046374';
    lng.placeholder = remota ? 'Ej.: -79.055686' : '-77.042689';
    if (remota && status && !status.dataset.estado) {
      status.textContent = 'Supervisión remota: se toma tu ubicación al iniciar, y puedes corregirla a mano o pegar las coordenadas del cliente (por ejemplo -8.139075, -79.055686).';
    }
    // De remota a presencial con coordenadas escritas a mano: se vuelven a tomar del GPS.
    if (!remota && !esAdministradorActual && veniaEscrita && !lat.disabled) {
      capturarGPS(lat.id, lng.id, lat.id.replace('latitud', 'gps_status'));
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
    return {
      id: Number(item.id),
      codigo: item.codigo_supervision,
      creado: new Date(String(item.fecha_registro || '').replace(' ', 'T')).getTime() || Date.now(),
      fecha: item.fecha_registro ? item.fecha_registro.slice(0,10) : todayISO(),
      departamento: item.departamento || 'LIMA', provincia: item.provincia || 'LIMA', distrito: item.distrito || 'LIMA',
      ubicacion: `${item.departamento || 'LIMA'} / ${item.distrito || 'LIMA'}`,
      direccion: item.direccion || '', // dirección del cliente (carga de cancelaciones, 2026-10-15)
      // Pendiente sin tomar (bolsa común): responsables = todos los supervisores directos de la zona.
      responsable: item.responsables_zona || item.supervisor_nombre || 'Supervisor WIN', supervisor_id: String(item.supervisor_id || ''),
      asignado_por: String(item.asignado_por || ''), fecha_asignacion: item.fecha_asignacion || '',
      es_asignacion_partner: Number(item.es_asignacion_partner || 0), fecha_limite_partner: item.fecha_limite_partner || '',
      plazo_partner_vencido: item.plazo_partner_vencido || 0, estado_atencion_partner: item.estado_atencion_partner || 'Pendiente',
      observacion_atencion_partner: item.observacion_atencion_partner || '', fecha_atencion_partner: item.fecha_atencion_partner || '',
      clasificacion: item.clasificacion_supervision || item.tipo_servicio || 'Instalación', derivacion: item.derivacion_orden || 'Autogestión',
      tipo_servicio: item.tipo_servicio || 'Regular', area_servicio: item.area_servicio || 'Instalación', sub_tipo_atencion: item.sub_tipo_atencion || '',
      tipo_supervision_inicial: item.tipo_supervision_inicial || 'En caliente', partner_nombre: item.nombre_partner || '—', partner_id: item.partner_id,
      rescate_estado: item.rescate_estado || '', rescate_inicio: item.rescate_inicio || '', rescate_fin: item.rescate_fin || '',
      rescate_responsable_id: String(item.rescate_responsable_id || ''), partner_rescatable: Number(item.partner_rescatable || 0),
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

  function getActiveColumns() {
    try {
      const saved = JSON.parse(localStorage.getItem('win_supervisiones_active_cols'));
      if (Array.isArray(saved) && saved.length > 0) return saved;
    } catch (_) {}
    return ALL_SUPERVISION_COLUMNS.filter(c => c.default).map(c => c.id);
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
      toggleNewBtn.disabled = Boolean(activeForCurrentSupervisor);
      toggleNewBtn.innerHTML = activeForCurrentSupervisor
        ? '<i class="bi bi-lock me-1"></i>Supervisión en proceso'
        : '<i class="bi bi-plus-lg me-1"></i>Nueva supervisión';
      toggleNewBtn.title = activeForCurrentSupervisor
        ? `Finalice la supervisión ${activeForCurrentSupervisor.ticket_codigo || activeForCurrentSupervisor.codigo_supervision} antes de crear otra.`
        : 'Crear una nueva supervisión';
    }

    const countValues = {todos:countTodos,iniciada:countIniciada,finalizada:countFinalizada,pendiente:countPendiente,rescate:Number(listCounts.rescate || 0),
      partner_pendiente:Number(listCounts.partner_pendiente || 0),partner_en_proceso:Number(listCounts.partner_en_proceso || 0),partner_vencida:Number(listCounts.partner_vencida || 0)};
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
            rowHtml += `<td><span class="badge-exec ${rec.estado}">${labelEstado}</span>${rec.es_asignacion_partner?`<br><small class="${rec.estado_atencion_partner==='Atendida'?'text-success':(Number(rec.plazo_partner_vencido)?'text-danger':'text-warning')}">${rec.estado_atencion_partner==='Atendida'?'Partner atendió':(Number(rec.plazo_partner_vencido)?'Partner no atendió · plazo vencido':'Partner pendiente · 24 h')}</small>`:''}${rec.partner_rescatable===1 && !rec.rescate_estado ? '<br><small class="text-success fw-bold"><i class="bi bi-life-preserver me-1"></i>Se puede rescatar</small>' : ''}</td>`;
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
        rec.partner_rescatable = Number(data.supervision.partner_rescatable || 0);
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
  // ---------- Rescate de pendientes (2026-10-13) ----------
  // Pendiente libre de la zona → "Tomar como rescate". En rescate → historial, avances y cierre (quien lo tomó o el
  // administrador) y reasignación (solo el administrador). Cerrado → resultado e historial de consulta.
  function renderRescate(rec, seguimientos) {
    const fmtFH = (v) => { if (!v) return '—'; const [d, h] = String(v).split(' '); return `${d.split('-').reverse().join('/')} ${(h || '').slice(0, 5)}`; };
    if (esSupervisorPartnerActual || esJefeOperacionesActual) return; // el partner solo propone (en su formulario)
    const esDueno = String(rec.rescate_responsable_id) === String(currentUserId);
    const puedeTomar = false; // 2026-10-13: el rescate se elige en "Resultado de la validación" del formulario
    const propuesta = seguimientos.filter(g => g.tipo === 'Propuesta del partner');
    let html = '';
    if (rec.partner_rescatable === 1 && !rec.rescate_estado && rec.estado === 'pendiente') {
      // Orden asignada a un partner que indicó que se puede rescatar: la pasa a rescate quien la asignó.
      const puedePasar = esSupervisorWinDirecto && String(rec.asignado_por) === String(currentUserId);
      html = `<div class="sgs-rescate card p-3 mb-3" id="rescatePanel">
        <h5 class="mb-1"><i class="bi bi-life-preserver me-2 text-primary"></i>El partner indica que se puede rescatar</h5>
        <p class="small text-muted mb-2">${escapeHtml(rec.partner_nombre)} · atendida el ${fmtFH(rec.fecha_atencion_partner)}</p>
        <div class="mb-2">${escapeHtml(rec.observacion_atencion_partner || '—')}</div>
        <div class="d-flex flex-wrap gap-2 mb-3">${propuesta.filter(g => g.foto).map((g, i) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-rescate-foto="${escapeHtml(g.foto)}"><i class="bi bi-image me-1"></i>Evidencia ${i + 1}</button>`).join('') || '<span class="small text-muted">Sin fotos.</span>'}</div>
        ${puedePasar ? `
        <label class="form-label" for="rescateComentarioInicio">Comentario (opcional)</label>
        <textarea class="form-control mb-2" id="rescateComentarioInicio" rows="2" maxlength="1000" placeholder="Ej.: se coordinará con el cliente"></textarea>
        <div class="text-end"><button type="button" class="btn btn-win" data-rescate="tomar"><i class="bi bi-life-preserver me-1"></i>Pasar a rescate</button></div>`
        : `<p class="small mb-0 text-muted">La decide el supervisor que la asignó${rec.asignado_por_nombre ? `: <strong>${escapeHtml(rec.asignado_por_nombre)}</strong>` : ''}.</p>`}
      </div>`;
    } else if (puedeTomar) {
      html = `<div class="sgs-rescate card p-3 mb-3" id="rescatePanel">
        <h5 class="mb-1"><i class="bi bi-life-preserver me-2 text-primary"></i>¿La va a rescatar?</h5>
        <p class="text-muted small mb-2">Tómela como rescate para darle seguimiento, sin plazo, hasta cerrarla. Sale de la lista de pendientes de los demás y no ocupa su supervisión en proceso.</p>
        <label class="form-label" for="rescateComentarioInicio">Comentario (opcional)</label>
        <textarea class="form-control mb-2" id="rescateComentarioInicio" rows="2" maxlength="1000" placeholder="Ej.: cliente pidió que lo llamen el lunes"></textarea>
        <div class="text-end"><button type="button" class="btn btn-win" data-rescate="tomar"><i class="bi bi-life-preserver me-1"></i>Tomar como rescate</button></div>
      </div>`;
    } else if (rec.rescate_estado) {
      const abierto = rec.rescate_estado === 'En rescate';
      const insignia = abierto ? 'bg-warning' : (rec.rescate_estado === 'Rescatado' ? 'bg-success' : 'bg-danger');
      const iconos = { 'Propuesta del partner': 'bi-megaphone', 'Inicio': 'bi-play-circle', 'Avance': 'bi-chat-left-text', 'Reasignación': 'bi-arrow-left-right', 'Cierre': 'bi-flag' };
      const historial = seguimientos.length ? seguimientos.map((g) => `<li>
          <i class="bi ${iconos[g.tipo] || 'bi-dot'}" aria-hidden="true"></i>
          <div><div class="small"><strong>${escapeHtml(g.tipo)}</strong> · ${fmtFH(g.fecha)} · ${escapeHtml(g.usuario || '—')}</div>
          ${g.comentario ? `<div>${escapeHtml(g.comentario)}</div>` : ''}
          ${g.foto ? `<button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-rescate-foto="${escapeHtml(g.foto)}"><i class="bi bi-image me-1"></i>Ver foto</button>` : ''}</div>
        </li>`).join('') : '<li class="text-muted small">Sin movimientos registrados.</li>';
      const puedeTrabajar = abierto && (esDueno || esAdministradorActual);
      html = `<div class="sgs-rescate card p-3 mb-3" id="rescatePanel">
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
            <button type="button" class="btn btn-win" data-rescate="cerrar" data-resultado="Rescatado"><i class="bi bi-check2-circle me-1"></i>Rescate</button>
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
    const panel = document.getElementById('rescatePanel');
    if (esAdministradorActual && document.getElementById('rescateReasignar')) {
      fetch('api/asistencia.php?action=list_filters').then(r => r.json()).then(d => {
        const sel = document.getElementById('rescateReasignar'); if (!sel) return;
        const dep = String(rec.departamento || '').toUpperCase();
        const lista = (d.supervisores || []).filter(u => String(u.id) !== String(rec.supervisor_id) && (u.zonas === null || (u.zonas || []).includes(dep)));
        sel.innerHTML = '<option value="">Seleccione un supervisor…</option>' + lista.map(u => `<option value="${Number(u.id)}">${escapeHtml(u.nombre_completo)}</option>`).join('');
      }).catch(() => {});
    }
    panel.addEventListener('click', async (ev) => {
      const fotoBtn = ev.target.closest('[data-rescate-foto]');
      if (fotoBtn) { window.sgsVerFoto ? window.sgsVerFoto(fotoBtn.dataset.rescateFoto) : window.open(fotoBtn.dataset.rescateFoto, '_blank', 'noopener'); return; }
      const btn = ev.target.closest('[data-rescate]');
      if (!btn) return;
      const accion = btn.dataset.rescate;
      const body = new FormData();
      body.append('supervision_id', rec.id);
      if (accion === 'tomar') body.append('comentario', document.getElementById('rescateComentarioInicio')?.value || '');
      if (accion === 'avance') {
        body.append('comentario', document.getElementById('rescateAvance')?.value || '');
        const foto = document.getElementById('rescateFoto')?.files?.[0]; if (foto) body.append('foto', foto);
      }
      if (accion === 'cerrar') {
        const comentario = (document.getElementById('rescateCierre')?.value || '').trim();
        if (!comentario) { showToast('Escriba el resultado final antes de cerrar el rescate.', 'error'); document.getElementById('rescateCierre')?.focus(); return; }
        if (!confirm(btn.dataset.resultado === 'Rescatado' ? '¿Cerrar como RESCATE (se rescató el servicio)? La orden quedará finalizada.' : '¿Cerrar como CANCELACIÓN CORRECTA (no se rescató)? La orden quedará finalizada.')) return;
        body.append('comentario', comentario); body.append('resultado', btn.dataset.resultado);
      }
      if (accion === 'reasignar') {
        const destino = document.getElementById('rescateReasignar')?.value;
        if (!destino) { showToast('Elija el supervisor que seguirá el rescate.', 'error'); return; }
        body.append('supervisor_id', destino);
      }
      btn.disabled = true;
      try {
        const resp = await fetch(`api/supervisiones.php?action=rescate_${accion}`, { method: 'POST', body });
        const res = await resp.json();
        showToast(res.message || 'Listo.', res.status === 'success' ? 'success' : 'error');
        if (res.status === 'success') {
          records = records.filter(r => r.id !== rec.id); // se vuelve a leer con su nuevo estado
          if (accion === 'cerrar' || accion === 'reasignar') { closeDetail(); loadRecords(); }
          else { await openDetail(rec.id); loadRecords(); }
        }
      } catch (e) { showToast('No se pudo completar la acción del rescate.', 'error'); }
      finally { btn.disabled = false; }
    });
  }

  function closeDetail(){
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
        <div class="panel-bitacora p-3 mb-3"><p class="section-subtitle-bitacora mt-0">Partner, personal técnico y vehículo</p><div class="grid-bitacora">
          <div class="field-bitacora"><label>Partner o contrata responsable</label><input value="${escapeHtml(rec.partner_nombre)}" disabled></div>
          ${rec.tipo_supervision_inicial === 'En caliente' ? `          <div class="field-bitacora"><label>Técnico líder</label><input value="${escapeHtml(rec.tecnico1_nombre||'Sin registrar')}" disabled></div>
          <div class="field-bitacora"><label>DNI del técnico líder</label><input value="${escapeHtml(rec.tecnico1_dni||'Sin registrar')}" disabled></div>
          ` : ''}
        </div></div>
        <div class="panel-bitacora p-3 mb-3"><div class="field-bitacora full"><label>Observación de la atención *</label><textarea name="observacion_atencion_partner" rows="5" ${attended||expired||esJefeOperacionesActual?'disabled':''}>${escapeHtml(rec.observacion_atencion_partner||'')}</textarea></div></div>
        <div class="panel-bitacora p-3 mb-3">
          <div class="grid-bitacora">
            <div class="field-bitacora"><label for="partner_rescatable">¿Se puede rescatar el servicio? *</label>
              <select id="partner_rescatable" name="partner_rescatable" ${attended||expired||esJefeOperacionesActual?'disabled':''}>
                <option value="0" ${Number(rec.partner_rescatable)===1?'':'selected'}>No</option>
                <option value="1" ${Number(rec.partner_rescatable)===1?'selected':''}>Sí, se puede rescatar</option>
              </select></div>
          </div>
          <div id="partner_rescate_evidencias" class="${attended||expired||esJefeOperacionesActual||Number(rec.partner_rescatable)!==1?'d-none':''}">
            <p class="small text-muted mt-2 mb-2"><i class="bi bi-life-preserver me-1"></i>Suba sus evidencias (al menos una foto). El supervisor de WIN que le asignó la orden decidirá si la pasa a rescate.</p>
            <div class="grid-bitacora">
              ${[1,2,3].map(i => `<div class="field-bitacora"><label for="foto_rescate_${i}">Evidencia ${i}${i===1?' *':''}</label><input type="file" class="form-control" id="foto_rescate_${i}" name="foto_rescate_${i}" accept="image/*"></div>`).join('')}
            </div>
          </div>
          ${attended && Number(rec.partner_rescatable)===1 ? '<p class="small mt-2 mb-0"><span class="badge bg-success">Indicó que se puede rescatar</span></p>' : ''}
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
    const t1List = listaTecnicos.filter(t => String(t.partner_id) === String(rec.partner_id) && (t.tipo_tecnico == 1 || !t.tipo_tecnico));
    t1List.forEach(t => {
      const sel = (t.nombre_tecnico === rec.tecnico1_nombre) ? 'selected' : '';
      tec1OptionsHtml += `<option value="${escapeHtml(t.nombre_tecnico)}" ${sel}>${escapeHtml(t.nombre_tecnico)}</option>`;
    });
    let tec2OptionsHtml = '<option value="">Sin técnico de apoyo (T2)</option>' + opcionesApoyo(rec.partner_id, rec.tecnico2_nombre);

    const seccionesChecklistEdicion = <?= json_encode($checklistSecciones,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const opcionesChecklistSeccion = seccion => seccion==='EPP y EPE' ? ['Sí','No','Para cambio','No revisado'] : (seccion==='Indumentaria' ? ['Sí','No','Mal estado','No revisado'] : (seccion==='Materiales y ferretería' ? ['Sí','No'] : ['Bueno','Regular','Malo','No tiene']));
    const claseOpcionChecklist = opcion => opcion.toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,'-');
    let checklistItemIndex = 0;
    const checklistEdicionHtml = Object.entries(seccionesChecklistEdicion).map(([seccion,criterios]) => `<section class="checklist-group"><h4 class="checklist-group-title"><i class="bi bi-tools me-2"></i>${escapeHtml(seccion)}</h4><div class="checklist-group-body">${criterios.map(criterio => { const idx=checklistItemIndex++; return `
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
            <div class="field-bitacora full sgs-recordar-cuadrilla">
              <input type="hidden" name="recordar_cuadrilla" value="0">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="edit_recordar_cuadrilla" name="recordar_cuadrilla" value="1" checked>
                <label class="form-check-label" for="edit_recordar_cuadrilla"><strong>Guardar el T2 y el vehículo</strong> (con su estado) como cuadrilla de este técnico líder al cerrar la supervisión: saldrán por defecto la próxima vez.</label>
              </div>
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
                  ${['Cancelación correcta', 'En proceso de rescate', 'Rescate'].map(o => `<option value="${o}" ${rec.estado_cancelacion === o ? 'selected' : ''}>${o}</option>`).join('')}
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
              <i class="bi bi-plus-circle me-1"></i>¿Agregar una nueva observación o falta con 3 fotos?
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
            <p style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--win-text-muted); margin-bottom:10px;"><i class="bi bi-camera me-1"></i>Evidencias Fotográficas (3 fotos obligatorias)</p>
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
              : `<button class="btn-bitacora ${rec.estado === 'pendiente' ? 'btn-orange-bitacora' : 'btn-ghost-bitacora'}" id="saveProgressBtn" type="button">${rec.estado === 'pendiente' ? 'Iniciar supervisión' : 'Guardar avance y volver al listado'}</button>`))
          }
        </div>
      </form>`;
  }

  function attachDetailHandlers(rec, forceAdminEdit = false){
    // Corrección por el administrador (2026-10-15): desde la vista de consulta abre el formulario completo editable.
    document.getElementById('btnCorregirSupervision')?.addEventListener('click', () => openDetail(rec.id, true));
    document.getElementById('btnCancelarCorreccion')?.addEventListener('click', () => openDetail(rec.id));
    if(esSupervisorPartnerActual){
      const form=document.getElementById('partner-supervision-form');
      const sendPartnerUpdate=async estado=>{
        if(!form)return;
        const data=new FormData(form);data.append('supervision_id',rec.id);
        try{const response=await fetch('api/supervisiones.php?action=partner_update',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:data});const result=await response.json();showToast(result.message,result.status==='success'?'success':'error');if(result.status==='success')closeDetail();}catch(e){showToast('No se pudo guardar la validación.','error');}
      };
      const selRescatable = document.getElementById('partner_rescatable');
      selRescatable?.addEventListener('change', () => document.getElementById('partner_rescate_evidencias')?.classList.toggle('d-none', selRescatable.value !== '1'));
      document.getElementById('partnerSaveProgress')?.addEventListener('click',()=>{
        if (selRescatable?.value === '1' && ![1,2,3].some(i => document.getElementById(`foto_rescate_${i}`)?.files?.length)) {
          showToast('Si el servicio se puede rescatar, suba al menos una foto de evidencia.', 'error');
          document.getElementById('foto_rescate_1')?.focus();
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
    document.getElementById('edit_partner')?.addEventListener('change', function() {
      [editTec1Nom,editTec2Nom].forEach((select,index) => {
        if (!select) return;
        if (index === 1) { select.innerHTML = '<option value="">Sin técnico de apoyo (T2)</option>' + opcionesApoyo(this.value, ''); select.dispatchEvent(new Event('change')); return; }
        select.innerHTML = '<option value="">Seleccione técnico</option>';
        listaTecnicos.filter(t => String(t.partner_id) === this.value && Number(t.tipo_tecnico || 1) === index+1)
          .forEach(t => select.add(new Option(t.nombre_tecnico,t.nombre_tecnico)));
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
        formDetalle.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(campo => campo.disabled = true);
        // "Resultado de la validación" lo decide el supervisor (2026-10-15: faltaba aquí y le salía bloqueado).
        ['edit_latitud','edit_longitud','edit_obs','edit_chk_obs','edit_resultado_cancelacion',
         'edit_foto_serv_1','edit_foto_serv_2','edit_foto_serv_3']
          .forEach(id => { const campo = document.getElementById(id); if (campo) campo.disabled = false; });
        editSecObservaciones?.querySelectorAll('input,select,textarea,button').forEach(campo => campo.disabled = false);
        document.querySelectorAll('.btn-gps-bitacora, #saveProgressBtn').forEach(boton => boton.disabled = false);
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
        editTec2Dni.value = match?.dni || '';
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
        'Rescate': 'El servicio se rescató: se finaliza como rescatada.',
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

  async function enviarFormularioEdicion(nuevoEstado, esInicioPendiente = false, abrirRescate = false){
    const form = document.getElementById('form-edit-detail');
    if(!form) return;
    if (nuevoEstado === 'Finalizada' && !validarCierreSupervision(form)) return;
    const formData = new FormData(form);
    if(!confirmarActualizacionDniTecnicos(formData,'edit'))return;
    formData.set('estado_supervision', nuevoEstado);
    if (abrirRescate) formData.set('abrir_rescate', '1');
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
        closeDetail();
      } else {
        showToast(data.message || 'Error al guardar.');
      }
    } catch(err) {
      showToast('Error de conexión al guardar.');
    }
  }

  function validarCierreSupervision(form, showError = true) {
    const valor = id => document.getElementById(id)?.value.trim() || '';
    const visibleHabilitado = el => el && !el.disabled && el.offsetParent !== null;
    const marcarFaltante = (el, mensaje) => {
      if (showError) {
        showToast(mensaje);
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el?.focus({ preventScroll: true });
        if (el?.reportValidity) el.reportValidity();
      }
      return false;
    };
    const remotaEdicion = esModalidadRemota('edit_tipo_sup');
    const noAplicaEnRemota = ['edit_tec1_nom','edit_tec1_dni','edit_brevete','edit_u_tipo','edit_u_placa','edit_u_estado','edit_latitud','edit_longitud'];
    const obligatoriosVisibles = [
      'edit_tipo_supervision','edit_sub_tipo_atencion','edit_derivacion','edit_tipo_servicio',
      'edit_tipo_sup','edit_partner','edit_ticket','edit_tec1_nom','edit_tec1_dni',
      'edit_brevete','edit_u_tipo','edit_u_placa','edit_u_estado','edit_latitud','edit_longitud',
      'edit_resultado_tecnico','edit_estado_servicio','edit_motivo_cancelacion'
      ,'edit_estado_cancelacion'
    ].filter(id => !(remotaEdicion && noAplicaEnRemota.includes(id))).map(id => document.getElementById(id));
    const faltanteGestion = obligatoriosVisibles.find(el => visibleHabilitado(el) && !String(el.value || '').trim());
    if (faltanteGestion) return marcarFaltante(faltanteGestion, 'Complete todos los campos solicitados para finalizar esta gestión.');

    const dniT1 = document.getElementById('edit_tec1_dni');
    if (visibleHabilitado(dniT1) && !/^\d{8}$/.test(valor('edit_tec1_dni'))) {
      return marcarFaltante(dniT1, 'El DNI del técnico líder debe tener 8 dígitos.');
    }

    const tieneFotoGuardada = document.getElementById('edit_tiene_foto_servicio')?.value === '1';
    const inputsFotoServicio = ['edit_foto_serv_1','edit_foto_serv_2','edit_foto_serv_3']
      .map(id => document.getElementById(id));
    const tieneFotoNueva = inputsFotoServicio.some(input => input?.files?.length);
    const esChecklistPuro = valor('edit_tipo_supervision') === 'Checklist';
    if (!esChecklistPuro && !tieneFotoGuardada && !tieneFotoNueva) {
      return marcarFaltante(inputsFotoServicio[0], remotaEdicion
        ? 'Suba la captura de la videollamada como foto de evidencia para finalizar la supervisión remota.'
        : 'Suba al menos una foto de evidencia para finalizar la supervisión.');
    }

    const chkObs = document.getElementById('edit_chk_obs');
    if (chkObs?.checked) {
      const camposObs = ['edit_obs_gravedad','edit_obs_cat','edit_obs_sub','edit_obs_det'].map(id => document.getElementById(id));
      const faltanteObs = camposObs.find(el => !String(el?.value || '').trim());
      if (faltanteObs) return marcarFaltante(faltanteObs._comboInput || faltanteObs, 'Complete todos los campos de la observación seleccionada.');
      for (let i = 1; i <= 3; i++) {
        const foto = document.getElementById(`edit_foto${i}`);
        if (!foto?.files?.length) return marcarFaltante(foto, 'Adjunte las 3 fotos de evidencia de la observación.');
      }
    }

    const chkChecklist = document.getElementById('edit_chk_checklist');
    if (chkChecklist?.checked) {
      const filas = [...form.querySelectorAll('#edit_sec_checklist .checklist-row')];
      const filaIncompleta = filas.find(fila => !fila.querySelector('input.checklist-estado:checked'));
      if (filaIncompleta) {
        return marcarFaltante(filaIncompleta.querySelector('input.checklist-estado'), 'Complete todos los estados del checklist técnico.');
      }
      const firmaChecklist = document.getElementById('edit_checklist_firma_data');
      if (firmaChecklist && !firmaChecklist.value) {
        return marcarFaltante(firmaChecklist, 'Dibuje la firma del supervisor para validar el checklist.');
      }
    }
    const esCancelacionValidada = Boolean(form.querySelector('input[name="es_cancelacion"][value="1"]'));
    if (esCancelacionValidada) {
      const resumenCancelacion = document.getElementById('edit_obs');
      if (!String(resumenCancelacion?.value || '').trim()) {
        return marcarFaltante(resumenCancelacion, 'Registre el resumen u observación de la validación antes de finalizar.');
      }
    }
    return true;
  }

  if(backBtn) backBtn.addEventListener('click', closeDetail);

  // ---------- VISTA 2: NAVEGACIÓN Y CREACIÓN EN PANTALLA DEDICADA ----------
  const newSectionOneIds = ['new_tipo_supervision','new_sub_tipo_atencion','new_derivacion','new_tipo_servicio','new_tipo_sup','new_dep','new_prov','new_dis'];

  function newSectionOneComplete(showError = false) {
    const missing = newSectionOneIds.map(id => document.getElementById(id)).find(field =>
      field && !field.disabled && (!String(field.value || '').trim() || !field.checkValidity())
    );
    if (missing && showError) {
      showToast('Complete toda la sección 1: Información general de la supervisión.');
      missing.reportValidity?.();
      missing.scrollIntoView({behavior:'smooth',block:'center'});
      missing.focus({preventScroll:true});
    }
    return !missing;
  }

  function newFormReadyToFinish(ignoreOrderState = false) {
    if (!newSectionOneComplete(false)) return false;
    const tipo = newTipoSupervision?.value || '';
    const tiposSinPedido = ['Incidencia / Casos atípicos','Proyecto de ordenamiento','Visita partner por capacitación','Conjunta PEXT','Checklist'];
    const tiposSinPartner = ['Incidencia / Casos atípicos'];
    const tiposSinCuadrilla = ['Incidencia / Casos atípicos','Visita partner por capacitación'];
    const modalidad = newTipoSupModalidad?.value || '';
    if (!tiposSinPedido.includes(tipo) && !newTicketCodigo?.value.trim()) return false;
    if (!tiposSinPartner.includes(tipo) && !String(newPartner?.value || '').trim()) return false;
    const remota = modalidad === 'Remota';
    const requiereCuadrilla = modalidad !== 'En frío' && !remota && !tiposSinCuadrilla.includes(tipo); // remota: solo el partner
    if (requiereCuadrilla) {
      if (!newTec1Nom?.value || !/^\d{8}$/.test(newTec1Dni?.value.trim() || '')) return false;
      if (!remota && (!document.getElementById('new_u_tipo')?.value || !document.getElementById('new_u_placa')?.value.trim() || !document.getElementById('new_u_estado')?.value)) return false;
    }
    const lat = Number(document.getElementById('new_latitud')?.value);
    const lng = Number(document.getElementById('new_longitud')?.value);
    if (!remota && (!Number.isFinite(lat) || lat < -90 || lat > 90 || !Number.isFinite(lng) || lng < -180 || lng > 180)) return false;
    if (['Avería','Reiterada','Garantía'].includes(tipo) && !newResultadoTecnico?.value) return false;
    if (tipo !== 'Checklist' && !document.getElementById('new_obs')?.value.trim()) return false;
    if (newChkObs?.checked) {
      const hallazgoCompleto = ['new_obs_gravedad','new_obs_cat','new_obs_sub','new_obs_det']
        .every(id => String(document.getElementById(id)?.value || '').trim());
      if (!hallazgoCompleto) return false;
      const fotosHallazgoCompletas = ['new_foto1','new_foto2','new_foto3']
        .every(id => document.getElementById(id)?.files?.length);
      if (!fotosHallazgoCompletas) return false;
    }
    const incluyeChecklist = tipo === 'Checklist' || Boolean(newChkChecklist?.checked);
    if (incluyeChecklist) {
      const filasChecklist = [...formInicio.querySelectorAll('#new_sec_checklist .checklist-row')];
      if (filasChecklist.some(fila => !fila.querySelector('input.checklist-estado:checked'))) return false;
      if (!document.getElementById('new_checklist_firma_data')?.value) return false;
      if (!document.getElementById('new_checklist_obs_general')?.value.trim()) return false;
    }
    const missingRequired = [...formInicio.querySelectorAll('[required]')].find(field =>
      !field.disabled && field.offsetParent !== null && !String(field.value || '').trim()
    );
    if (missingRequired) return false;
    const hasNewEvidence = ['new_foto_serv_1','new_foto_serv_2','new_foto_serv_3'].some(id => document.getElementById(id)?.files?.length);
    if (tipo !== 'Checklist' && !newHasSavedEvidence && !hasNewEvidence) return false;
    return true;
  }

  function updateNewWorkflowButton() {
    if (!startBtn || !startBtnLabel) return;
    const sectionReady = newSectionOneComplete(false);
    const tipo = newTipoSupervision?.value || '';
    const modalidad = newTipoSupModalidad?.value || '';
    const requisitosPreviosCompletos = newFormReadyToFinish(true);
    const finishReady = newFormReadyToFinish();
    startBtn.disabled = !sectionReady;
    startBtn.classList.toggle('btn-finish-bitacora', finishReady);
    startBtn.classList.toggle('btn-orange-bitacora', !finishReady);
    if (!sectionReady) {
      startBtn.dataset.action = 'blocked';
      startBtnLabel.textContent = 'Complete la sección 1';
      if (newWorkflowStatus) newWorkflowStatus.textContent = 'Complete la sección 1 para habilitar el inicio de la supervisión.';
    } else if (finishReady) {
      startBtn.dataset.action = 'finish';
      startBtnLabel.textContent = 'Finalizar supervisión';
      if (newWorkflowStatus) newWorkflowStatus.textContent = 'Formulario completo. Ya puede finalizar la supervisión.';
    } else if (newWorkflowState === 'draft') {
      startBtn.dataset.action = 'start';
      startBtnLabel.textContent = 'Guardar supervisión';
      if (newWorkflowStatus) newWorkflowStatus.textContent = 'Sección 1 completa. Puede iniciar la supervisión y continuar llenando el formulario.';
    } else {
      startBtn.dataset.action = 'progress';
      startBtnLabel.textContent = 'Guardar avance y volver al listado';
      if (newWorkflowStatus) newWorkflowStatus.textContent = 'Supervisión iniciada. Complete los datos restantes; el botón cambiará a “Finalizar supervisión”.';
    }
    updateReturnButtons();
  }

  function updateReturnButtons() {
    const canSaveAndReturn = newSectionOneComplete(false) && (newFormDirty || newWorkflowState === 'progress');
    [cancelNewBtn].forEach(button => {
      if (!button) return;
      button.style.display = canSaveAndReturn ? 'none' : '';
      button.innerHTML = '<i class="bi bi-arrow-left me-1"></i>Retroceder';
    });
  }

  function freezeNewSectionOne() {
    newSectionOneIds.map(id => document.getElementById(id)).forEach(field => { if (field) field.disabled = true; });
  }

  function resetNewWorkflowForm() {
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

  const markNewFormDirty = () => { newFormDirty = true; updateNewWorkflowButton(); };
  formInicio?.addEventListener('input', markNewFormDirty);
  formInicio?.addEventListener('change', markNewFormDirty);

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
    setValue('new_tec1_nom', rec.tecnico1_nombre);
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
    setSaved('new_tec2_nom', rec.tecnico2_nombre);
    setSaved('new_tec2_dni', rec.tecnico2_dni);
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

  async function openNewForm(){
    if (!(await verificarTurnoActivo())) return;
    // REGLA DE NEGOCIO: Ningún supervisor puede iniciar una supervisión si tiene una en proceso ("Iniciada")
    const activeRec = activeSupervisionMeta;
    if (activeRec) {
      const cod = activeRec.ticket_codigo ? activeRec.ticket_codigo : activeRec.codigo_supervision;
      showToast(`No puedes iniciar una nueva supervisión. Tienes la supervisión (${cod}) en proceso. Debes finalizarla primero.`);
      return;
    }

    updateFolioPreview();
    if (newWorkflowState === 'draft' && newPartner) newPartner.value = '';
    await Promise.all([cargarListaTecnicos(), cargarCatalogoObservaciones()]);
    [newTec1Nom,newTec2Nom].forEach(el => el?.dispatchEvent(new Event('change')));
    cargarUbigeoNew();
    viewList.classList.add('hidden');
    viewDetail.classList.remove('open');
    viewNew.classList.add('open');
    updateNewWorkflowButton();
    window.scrollTo({top:0, behavior:'smooth'});
    // La ubicación se toma sola al abrir (2026-10-12); el supervisor puede actualizarla con el botón.
    if (!String(document.getElementById('new_latitud')?.value || '').trim()) capturarGPS('new_latitud', 'new_longitud', 'new_gps_status');
  }

  function closeNewForm(){
    resetNewWorkflowForm();
    viewNew.classList.remove('open');
    viewDetail.classList.remove('open');
    viewList.classList.remove('hidden');
    loadRecords();
  }

  if(toggleNewBtn) toggleNewBtn.addEventListener('click', openNewForm);
  const returnFromNewForm = event => {
    closeNewForm();
  };
  if(cancelNewBtn) cancelNewBtn.addEventListener('click', returnFromNewForm);

  if(formInicio) {
    formInicio.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!newSectionOneComplete(true)) return;
      const action = startBtn?.dataset.action || 'start';
      if (action === 'blocked') return;
      const finishing = action === 'finish';
      const formData = new FormData(formInicio);
      // Los campos congelados no entran en FormData; se incluyen de forma explícita.
      newSectionOneIds.forEach(id => {
        const field = document.getElementById(id);
        if (field?.name && field.value !== '') formData.set(field.name, field.value);
      });
      if (finishing && !confirmarActualizacionDniTecnicos(formData,'new')) return;
      formData.append('action', 'create');
      formData.set('estado_supervision', finishing ? 'Finalizada' : 'En proceso');

      try {
        startBtn.disabled = true;
        showToast(finishing ? 'Finalizando supervisión...' : (newWorkflowState === 'draft' ? 'Guardando e iniciando supervisión...' : 'Guardando avance...'));
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
          resetNewWorkflowForm();
          closeNewForm();
          return;
        } else {
          showToast(data.message || 'No se pudo guardar la supervisión.');
        }
      } catch(err) {
        showToast('Error de conexión.');
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
          ? departments.filter(value => String(value).toLocaleUpperCase('es') === currentUserDepartment.toLocaleUpperCase('es'))
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
        const r = await fetch(`api/ubigeo.php?action=provincias&departamento=${encodeURIComponent(dep)}`);
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
</script>
