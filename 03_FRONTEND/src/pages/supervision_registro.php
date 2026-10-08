<?php
// 03_FRONTEND/src/pages/supervision_registro.php - Incluye la vista principal con despliegue automático del formulario
require_once __DIR__ . '/supervision_lista.php';
return;
?>
        <div class="wizard-steps">
            <div class="text-center">
                <div class="wizard-step active" id="step-indicator-1">1</div>
                <div class="wizard-title">Contexto y ubicación</div>
            </div>
            <div class="text-center">
                <div class="wizard-step" id="step-indicator-2">2</div>
                <div class="wizard-title">Código de pedido</div>
            </div>
            <div class="text-center">
                <div class="wizard-step" id="step-indicator-3">3</div>
                <div class="wizard-title">Cuadrilla y recursos</div>
            </div>
            <div class="text-center">
                <div class="wizard-step" id="step-indicator-4">4</div>
                <div class="wizard-title">Servicio y cancelación</div>
            </div>
        </div>

        <form id="form-supervision">
            <input type="hidden" name="action" value="create">
            
            <!-- PASO 1: DATOS GENERALES Y CONTEXTO GEOGRÁFICO -->
            <div class="wizard-content" id="step-content-1">
                <h5 class="fw-bold mb-3" style="color: var(--sgs-orange);"><i class="bi bi-geo-alt me-2"></i> PASO 1: Datos Generales y Clasificación</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="clasificacion_supervision" class="form-label fw-bold" style="color: var(--sgs-orange);">Clasificación de la Supervisión *</label>
                        <select class="form-select border-warning shadow-sm" id="clasificacion_supervision" name="clasificacion_supervision" required>
                            <option value="Incidencia">Incidencia</option>
                            <option value="Casos atípicos">Casos atípicos</option>
                            <option value="Proyecto de ordenamiento">Proyecto de ordenamiento</option>
                            <option value="Visita partner">Visita partner por capacitación</option>
                            <option value="Conjunta PEXT">Conjunta PEXT</option>
                            <option value="Checklist">Checklist</option>
                        </select>
                    </div>

                    <div class="col-md-6 d-none" id="box-tiene-datos-cliente">
                        <label for="tiene_datos_cliente" class="form-label text-warning fw-bold">¿Cuenta con datos del cliente? *</label>
                        <select class="form-select border-warning" id="tiene_datos_cliente" name="tiene_datos_cliente">
                            <option value="1">Sí, cuenta con datos de cliente</option>
                            <option value="0">No cuenta con datos de cliente</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="derivacion_orden" class="form-label">Derivación de la Orden *</label>
                        <select class="form-select" id="derivacion_orden" name="derivacion_orden" required>
                            <option value="Autogestión">Autogestión</option>
                            <option value="Mesa de supervisión">Mesa de supervisión</option>
                            <option value="Líder">Líder</option>
                            <option value="Coordinador">Coordinador</option>
                            <option value="Gerente">Gerente</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="tipo_servicio" class="form-label">Tipo de Servicio *</label>
                        <select class="form-select" id="tipo_servicio" name="tipo_servicio" required>
                            <option value="Regular">Regular</option>
                            <option value="ZAV">ZAV (Zona de Alto Valor)</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="departamento" class="form-label">Departamento *</label>
                        <select class="form-select" id="departamento" name="departamento" required>
                            <option value="">Cargando departamentos...</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="provincia" class="form-label">Provincia *</label>
                        <select class="form-select" id="provincia" name="provincia" required>
                            <option value="">Seleccione departamento primero</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="distrito" class="form-label">Distrito *</label>
                        <select class="form-select" id="distrito" name="distrito" required>
                            <option value="">Seleccione provincia primero</option>
                        </select>
                    </div>

                    <!-- Coordenadas GPS en Campo -->
                    <div class="col-12 mt-3">
                        <div class="p-3 border rounded bg-light border-warning shadow-sm">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <label class="form-label text-dark fw-bold mb-0"><i class="bi bi-geo-alt-fill me-1" style="color: var(--sgs-orange);"></i> Ubicación GPS del Celular (Supervisor)</label>
                                <button type="button" id="btn-reg-gps" class="btn btn-warning btn-sm text-dark fw-bold shadow-sm">
                                    <i class="bi bi-crosshair me-1"></i> Capturar GPS
                                </button>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" id="latitud" name="latitud" placeholder="Latitud (Ej: -12.046374)">
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" id="longitud" name="longitud" placeholder="Longitud (Ej: -77.042793)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 2: DATOS DEL CLIENTE Y OPERACIÓN -->
            <div class="wizard-content d-none" id="step-content-2">
                <h5 class="fw-bold mb-3" style="color: var(--sgs-orange);"><i class="bi bi-upc-scan me-2"></i> PASO 2: Código de Pedido</h5>
                <input type="hidden" id="cliente_nombre" name="cliente_nombre" value="">
                <input type="hidden" id="cliente_dni" name="cliente_dni" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="ticket_codigo" class="form-label">Código de Pedido *</label>
                        <input type="text" class="form-control" id="ticket_codigo" name="ticket_codigo" placeholder="Ej. 998877" required>
                    </div>
                    <div class="col-12 mt-3 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="text-muted small"><i class="bi bi-info-circle text-warning me-1"></i> ¡Con los datos del cliente listos, ya puedes iniciar la supervisión!</span>
                        <button type="submit" class="btn btn-warning fw-bold text-dark shadow-sm px-3">
                            <i class="bi bi-play-circle me-1"></i>Iniciar supervisión
                        </button>
                    </div>
                </div>
            </div>

            <!-- PASO 3: DATOS DE LA CUADRILLA Y RECURSOS (CAMPO) -->
            <div class="wizard-content d-none" id="step-content-3">
                <h5 class="fw-bold mb-3" style="color: var(--sgs-orange);"><i class="bi bi-truck me-2"></i> PASO 3: Datos de Cuadrilla y Recursos</h5>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label d-block">Tipo de Supervisión Inicial *</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="tipo_supervision_inicial" id="rad-caliente" value="En caliente" checked>
                            <label class="btn btn-outline-warning" for="rad-caliente"><i class="bi bi-fire me-1"></i> En Caliente (En Presente)</label>

                            <input type="radio" class="btn-check" name="tipo_supervision_inicial" id="rad-frio" value="En frío">
                            <label class="btn btn-outline-info" for="rad-frio"><i class="bi bi-snow me-1"></i> En Frío (Post Atención)</label>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="partner_id" class="form-label">Partner / Contrata *</label>
                        <select class="form-select" id="partner_id" name="partner_id" required>
                            <option value="">Seleccione contrata</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre_partner']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Sección Datos de Técnicos -->
                <div id="sec-tecnicos-cuadrilla" class="p-3 bg-light rounded border border-light-subtle mb-3">
                    <h6 class="text-dark fw-bold mb-3"><i class="bi bi-people me-2" style="color: var(--sgs-orange);"></i> Personal técnico de campo</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="tecnico1_nombre" class="form-label">Técnico 1 - Nombre Completo *</label>
                            <input type="text" class="form-control" id="tecnico1_nombre" name="tecnico1_nombre">
                        </div>
                        <div class="col-md-6">
                            <label for="tecnico1_dni" class="form-label">Técnico 1 - DNI *</label>
                            <input type="text" class="form-control" id="tecnico1_dni" name="tecnico1_dni" maxlength="8">
                        </div>
                        <div class="col-md-6">
                            <label for="tecnico2_nombre" class="form-label">Técnico 2 - Nombre (Opcional)</label>
                            <input type="text" class="form-control" id="tecnico2_nombre" name="tecnico2_nombre">
                        </div>
                        <div class="col-md-6">
                            <label for="tecnico2_dni" class="form-label">Técnico 2 - DNI (Opcional)</label>
                            <input type="text" class="form-control" id="tecnico2_dni" name="tecnico2_dni" maxlength="8">
                        </div>
                    </div>
                </div>

                <!-- Licencia de Conducir y Vehículo -->
                <div id="sec-datos-vehiculo" class="row g-3">
                    <div class="col-md-4">
                        <label for="brevete_poseedor" class="form-label">Licencia de Conducir (Brevete) *</label>
                        <select class="form-select" id="brevete_poseedor" name="brevete_poseedor" required>
                            <option value="Tecnico 1">Técnico 1 cuenta con brevete</option>
                            <option value="Tecnico 2">Técnico 2 cuenta con brevete</option>
                            <option value="Ninguno">Ninguno tiene brevete</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="unidad_tipo" class="form-label">Tipo de Unidad *</label>
                        <select class="form-select" id="unidad_tipo" name="unidad_tipo" required>
                            <option value="Minivan">Minivan</option>
                            <option value="Auto">Auto</option>
                            <option value="Moto">Moto</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="unidad_placa" class="form-label">Placa de Vehículo *</label>
                        <input type="text" class="form-control" id="unidad_placa" name="unidad_placa" placeholder="Ej. ABC-123" required>
                    </div>

                    <div class="col-md-3">
                        <label for="unidad_estado_conservacion" class="form-label">Estado de Conservación *</label>
                        <select class="form-select" id="unidad_estado_conservacion" name="unidad_estado_conservacion" required>
                            <option value="Buen estado">Buen estado</option>
                            <option value="Regular">Regular</option>
                            <option value="Mal estado">Mal estado</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- PASO 4: DATOS DEL SERVICIO REALIZADO Y MENÚS CONDICIONALES -->
            <div class="wizard-content d-none" id="step-content-4">
                <h5 class="fw-bold text-warning mb-3"><i class="bi bi-tools me-2"></i> PASO 4: Detalle del Servicio Realizado</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="clasificacion_supervision" class="form-label">Clasificación de la Supervisión *</label>
                        <select class="form-select" id="clasificacion_supervision" name="clasificacion_supervision" required>
                            <option value="Incidencia">Incidencia</option>
                            <option value="Verificación remota">Verificación remota</option>
                            <option value="Casos atípicos (con cliente)">Casos atípicos (con cliente)</option>
                            <option value="Casos atípicos (sin cliente)">Casos atípicos (sin cliente)</option>
                            <option value="Proyecto de ordenamiento">Proyecto de ordenamiento</option>
                            <option value="Visita al partner por capacitación">Visita al partner por capacitación</option>
                            <option value="Conjunta PEXT">Conjunta PEXT</option>
                            <option value="Checklist">Checklist</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="area_servicio" class="form-label">Área del Servicio *</label>
                        <select class="form-select" id="area_servicio" name="area_servicio" required>
                            <option value="">Seleccione área</option>
                            <option value="Instalación">Instalación</option>
                            <option value="Postventa">Postventa</option>
                            <option value="Avería">Avería</option>
                            <option value="Reiterada">Reiterada</option>
                            <option value="Garantía">Garantía</option>
                            <option value="Cancelación">Cancelación (Transversal)</option>
                        </select>
                    </div>

                    <!-- Submenú Desplegable Condicional -->
                    <div class="col-md-12 d-none" id="container-subtipo-atencion">
                        <label for="sub_tipo_atencion" class="form-label text-warning">Acción Específica Ejecutada *</label>
                        <select class="form-select border-warning" id="sub_tipo_atencion" name="sub_tipo_atencion">
                            <!-- Se puebla dinámicamente con JS -->
                        </select>
                    </div>

                    <!-- Resultado final de la orden, independiente del avance de supervisión -->
                    <div class="col-md-12" id="sec-estado-servicio-caliente">
                        <label class="form-label text-info">Estado del Servicio al Retirarse el Supervisor (En Caliente) *</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="estado_servicio_caliente" id="rad-est-proceso" value="Cancelada">
                            <label class="btn btn-outline-danger" for="rad-est-proceso">Orden cancelada</label>

                            <input type="radio" class="btn-check" name="estado_servicio_caliente" id="rad-est-culminado" value="Finalizada" checked>
                            <label class="btn btn-outline-success" for="rad-est-culminado">Orden finalizada</label>
                        </div>
                    </div>

                    <!-- Sección Especial Cancelación (Frío / Técnico Invalido) -->
                    <div class="col-md-12 d-none" id="sec-cancelacion-especial">
                        <div class="p-3 bg-dark rounded border border-warning">
                            <h6 class="text-warning fw-bold mb-2"><i class="bi bi-x-circle-fill me-2"></i> Validación de la cancelación</h6>
                            
                            <div class="row g-3" id="sec-validacion-tecnico-frio">
                                <div class="col-md-6">
                                    <label class="form-label">¿La tipificación de cancelación del técnico fue correcta?</label>
                                    <select class="form-select" name="tipificacion_tecnico_correcta">
                                        <option value="1">Sí, tipificó correctamente</option>
                                        <option value="0">No, tipificación errónea del técnico</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Tipificación corregida por el supervisor</label>
                                    <input type="text" class="form-control" name="tipificacion_corregida_supervisor" placeholder="Ej. Facilidades Técnicas Erróneas">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN A: OBSERVACIÓN SOBRE EL ESTADO Y ENTREGA DEL SERVICIO -->
                    <div class="col-md-12">
                        <div class="p-3 bg-light rounded border border-light-subtle">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="estado_supervision" class="form-label text-dark fw-bold">
                                        <i class="bi bi-flag-fill text-primary me-1"></i> Estado del Servicio / Orden *
                                    </label>
                                    <select class="form-select border-primary" id="estado_supervision" name="estado_supervision" required>
                                        <option value="Finalizada">Finalizada</option>
                                        <option value="Cancelada">Cancelada</option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-none" id="box_motivo_cancelacion_reg">
                                    <label for="tipificacion_cancelacion" class="form-label text-danger fw-bold">
                                        <i class="bi bi-x-circle-fill me-1"></i> Motivo de Cancelación *
                                    </label>
                                    <select class="form-select border-danger" id="tipificacion_cancelacion" name="tipificacion_cancelacion">
                                        <option value="">Seleccione motivo de cancelación</option>
                                        <option value="Error comercial coordenadas no coinciden">Error comercial coordenadas no coinciden</option>
                                        <option value="No hay postes de apoyo">No hay postes de apoyo</option>
                                        <option value="No hay CTO">No hay CTO</option>
                                    </select>
                                </div>
                            </div>

                            <label for="observacion_servicio" class="form-label text-dark fw-bold">
                                <i class="bi bi-info-circle-fill text-info me-2"></i> Observación sobre el Estado del Servicio (Entrega al Cliente)
                            </label>
                            <textarea class="form-control mb-3" id="observacion_servicio" name="observacion_servicio" rows="2" placeholder="Describa el estado final en el que se deja el servicio o los parámetros técnicos verificados..."></textarea>
                            
                            <!-- Carga de Hasta 3 Fotos de Evidencia del Servicio General -->
                            <div class="border-top pt-2">
                                <label class="form-label text-dark fw-semibold small mb-2">
                                    <i class="bi bi-camera-fill text-primary me-1"></i> Evidencias Fotográficas del Servicio General (Hasta 3 Fotos)
                                </label>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label for="foto_servicio_1" class="form-label text-secondary small mb-1">Foto del servicio 1</label>
                                        <input type="file" class="form-control form-control-sm input-foto-preview" id="foto_servicio_1" name="foto_servicio_1" accept="image/*" data-preview="preview-foto-serv-1">
                                        <div id="preview-foto-serv-1" class="mt-2 text-center d-none">
                                            <img class="img-thumbnail rounded shadow-sm" alt="Vista previa de la foto 1 del servicio" style="max-height: 200px; width: 100%; object-fit: contain; background-color: var(--sgs-ink);">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="foto_servicio_2" class="form-label text-secondary small mb-1">Foto del servicio 2</label>
                                        <input type="file" class="form-control form-control-sm input-foto-preview" id="foto_servicio_2" name="foto_servicio_2" accept="image/*" data-preview="preview-foto-serv-2">
                                        <div id="preview-foto-serv-2" class="mt-2 text-center d-none">
                                            <img class="img-thumbnail rounded shadow-sm" alt="Vista previa de la foto 2 del servicio" style="max-height: 200px; width: 100%; object-fit: contain; background-color: var(--sgs-ink);">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="foto_servicio_3" class="form-label text-secondary small mb-1">Foto del servicio 3</label>
                                        <input type="file" class="form-control form-control-sm input-foto-preview" id="foto_servicio_3" name="foto_servicio_3" accept="image/*" data-preview="preview-foto-serv-3">
                                        <div id="preview-foto-serv-3" class="mt-2 text-center d-none">
                                            <img class="img-thumbnail rounded shadow-sm" alt="Vista previa de la foto 3 del servicio" style="max-height: 200px; width: 100%; object-fit: contain; background-color: var(--sgs-ink);">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN B: REGISTRO DE INCUMPLIMIENTO / SANCIÓN A LEVANTAR POR EL PARTNER -->
                    <div class="col-md-12 mt-3">
                        <div class="p-3 bg-light rounded border border-warning shadow-sm">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="chk_incluir_observacion" name="chk_incluir_observacion" value="1">
                                <label class="form-check-label fw-bold text-dark fs-6" for="chk_incluir_observacion">
                                    <i class="bi bi-shield-exclamation text-warning me-2"></i> ¿Registrar Incumplimiento / Sanción a Levantar por el Partner (SLA 3 Días)?
                                </label>
                            </div>

                            <div id="sec-registro-observacion-integrada" class="d-none mt-3 pt-3 border-top border-warning-subtle">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="obs_gravedad" class="form-label">1. Nivel de Gravedad *</label>
                                        <select class="form-select border-warning" id="obs_gravedad" name="obs_gravedad">
                                            <option value="">Seleccione gravedad</option>
                                            <option value="Media">Media</option>
                                            <option value="Grave">Grave</option>
                                            <option value="Muy Grave">Muy Grave</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="obs_categoria_id" class="form-label">2. Motivo (Categoría) *</label>
                                        <select class="form-select border-warning" id="obs_categoria_id" name="obs_categoria_id">
                                            <option value="">Seleccione gravedad primero</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="obs_subopcion_id" class="form-label">3. Submotivo (Falta Específica) *</label>
                                        <select class="form-select border-warning" id="obs_subopcion_id" name="obs_subopcion_id">
                                            <option value="">Seleccione motivo primero</option>
                                        </select>
                                    </div>

                                    <div class="col-md-12">
                                        <label for="obs_detalle" class="form-label">Sustento / Detalle del Incumplimiento *</label>
                                        <textarea class="form-control" id="obs_detalle" name="obs_detalle" rows="3" placeholder="Describa la infracción cometida por la cuadrilla o el motivo de la sanción..."></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="plazo_subsanacion_dias" class="form-label">Plazo para regularizar *</label>
                                        <select class="form-select border-warning" id="plazo_subsanacion_dias" name="plazo_subsanacion_dias">
                                            <option value="1">1 día</option><option value="2">2 días</option><option value="3" selected>3 días (máximo)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <label class="form-check border border-danger-subtle rounded p-3 w-100 bg-danger-subtle"><input class="form-check-input" type="checkbox" name="subsanacion_inmediata" value="1"> <span class="fw-bold text-danger">Requiere subsanación inmediata</span></label>
                                    </div>

                                    <!-- Adjuntar Evidencias Fotográficas con Vista Previa Instantánea -->
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold text-dark"><i class="bi bi-camera me-1" style="color: var(--sgs-orange);"></i> Adjuntar Evidencias Fotográficas (Hasta 3 fotos)</label>
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <input type="file" class="form-control form-control-sm input-foto-preview" id="foto_1" name="foto_1" accept="image/*" data-preview="preview-foto-1">
                                                <small class="text-muted">Evidencia fotográfica 1</small>
                                                <div id="preview-foto-1" class="mt-2 text-center d-none">
                                                    <img class="img-thumbnail rounded shadow-sm" alt="Vista previa de la evidencia 1" style="max-height: 200px; width: 100%; object-fit: contain; background-color: var(--sgs-ink);">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <input type="file" class="form-control form-control-sm input-foto-preview" id="foto_2" name="foto_2" accept="image/*" data-preview="preview-foto-2">
                                                <small class="text-muted">Evidencia fotográfica 2</small>
                                                <div id="preview-foto-2" class="mt-2 text-center d-none">
                                                    <img class="img-thumbnail rounded shadow-sm" alt="Vista previa de la evidencia 2" style="max-height: 200px; width: 100%; object-fit: contain; background-color: var(--sgs-ink);">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <input type="file" class="form-control form-control-sm input-foto-preview" id="foto_3" name="foto_3" accept="image/*" data-preview="preview-foto-3">
                                                <small class="text-muted">Evidencia fotográfica 3</small>
                                                <div id="preview-foto-3" class="mt-2 text-center d-none">
                                                    <img class="img-thumbnail rounded shadow-sm" alt="Vista previa de la evidencia 3" style="max-height: 200px; width: 100%; object-fit: contain; background-color: var(--sgs-ink);">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Campos ocultos de estado y ID edición -->
            <input type="hidden" id="supervision_id_edit" name="supervision_id_edit" value="">
            <input type="hidden" id="estado_supervision" name="estado_supervision" value="Finalizada">

            <!-- Botones de Navegación del Formulario -->
            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top border-secondary">
                <button type="button" id="btn-prev-step" class="btn btn-outline-secondary" disabled>
                    <i class="bi bi-arrow-left me-1"></i> Anterior
                </button>

                <button type="button" id="btn-next-step" class="btn btn-win">
                    Siguiente <i class="bi bi-arrow-right ms-1"></i>
                </button>

                <div id="sec-submit-buttons" class="d-none">
                    <button type="button" id="btn-save-iniciada" class="btn btn-warning me-2 shadow-sm">
                        <i class="bi bi-play-circle me-1"></i> Guardar como "Iniciada"
                    </button>
                    <button type="submit" id="btn-submit-supervision" class="btn btn-success btn-lg shadow-sm">
                        <i class="bi bi-check-circle me-1"></i> Finalizar supervisión
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="03_FRONTEND/src/assets/js/supervision.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const urlParams = new URLSearchParams(window.location.search);
    const editId = urlParams.get('id');
    if (!editId) return;

    try {
        const res = await fetch(`api/supervisiones.php?action=detail&id=${editId}`);
        const data = await res.json();
        if (data.status === 'success' && data.supervision) {
            const s = data.supervision;
            document.getElementById('supervision_id_edit').value = s.id;
            if (s.derivacion_orden) document.getElementById('derivacion_orden').value = s.derivacion_orden;
            if (s.tipo_servicio) document.getElementById('tipo_servicio').value = s.tipo_servicio;
            if (s.cliente_nombre) document.getElementById('cliente_nombre').value = s.cliente_nombre;
            if (s.cliente_dni) document.getElementById('cliente_dni').value = s.cliente_dni;
            if (s.ticket_codigo) document.getElementById('ticket_codigo').value = s.ticket_codigo;
            if (s.partner_id) document.getElementById('partner_id').value = s.partner_id;
            if (s.tecnico1_nombre) document.getElementById('tecnico1_nombre').value = s.tecnico1_nombre;
            if (s.tecnico1_dni) document.getElementById('tecnico1_dni').value = s.tecnico1_dni;
            if (s.tecnico2_nombre) document.getElementById('tecnico2_nombre').value = s.tecnico2_nombre;
            if (s.tecnico2_dni) document.getElementById('tecnico2_dni').value = s.tecnico2_dni;
            if (s.unidad_placa) document.getElementById('unidad_placa').value = s.unidad_placa;
            if (s.area_servicio) document.getElementById('area_servicio').value = s.area_servicio;
            if (s.observacion_servicio) document.getElementById('observacion_servicio').value = s.observacion_servicio;
            
            showToast(`Cargada supervisión ${s.codigo_supervision} (Estado: ${s.estado_supervision}). Complete datos y proceda a cerrarla.`, 'info');
        }
    } catch (e) {
        console.error('Error al cargar supervisión para edición:', e);
    }
});
</script>
