<?php
// 03_FRONTEND/src/pages/observaciones_partner.php - Bandeja de Observaciones, Flujo Partner y SLA 3 Días
Auth::requireLogin();
$user = Auth::getLoggedInUser();
$pdo = Database::getConnection();

// Ejecutar transición automática de SLA
$pdo->exec("
    UPDATE observaciones_supervision 
    SET estado_respuesta = 'Penalidad Directa' 
    WHERE estado_respuesta = 'Pendiente' AND fecha_limite_respuesta < NOW()
");

// Obtener observaciones filtradas según el rol del usuario
$where = [];
$params = [];

if ($user['rol'] === Auth::ROLE_PARTNER) {
    $where[] = "s.partner_id = :part_id";
    $params[':part_id'] = $user['partner_id'];
} else if ($user['rol'] === Auth::ROLE_SUPERVISOR) {
    $where[] = "s.supervisor_id = :sup_id";
    $params[':sup_id'] = $user['id'];
}

$whereStr = count($where) > 0 ? "WHERE " . implode(' AND ', $where) : "";

$sql = "
    SELECT o.*, s.codigo_supervision, s.cliente_nombre, s.ticket_codigo, p.nombre_partner,
           c.nombre_categoria, sub.descripcion_subopcion, sub.penalidad_monto, sub.accion_adicional, u.nombre_completo AS supervisor_nombre
    FROM observaciones_supervision o
    JOIN supervisiones s ON o.supervision_id = s.id
    JOIN partners p ON s.partner_id = p.id
    JOIN categorias_observacion c ON o.categoria_id = c.id
    JOIN subopciones_observacion sub ON o.subopcion_id = sub.id
    JOIN usuarios u ON s.supervisor_id = u.id
    {$whereStr}
    ORDER BY o.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$observaciones = $stmt->fetchAll();
?>
<div class="container-fluid p-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h3 class="fw-bold mb-1" style="color: var(--sgs-orange);"><i class="bi bi-shield-exclamation me-2"></i> Observaciones y plazos de respuesta</h3>
            <p class="text-secondary mb-0">Gestión de descargos de contratas con temporizador de 3 días y evaluación de penalidades.</p>
        </div>
    </div>

    <div class="card card-win p-3">
        <div class="table-responsive">
            <table class="table table-win align-middle mb-0">
                <thead>
                    <tr>
                        <th>Cód. Supervisión</th>
                        <th>Contrata / Partner</th>
                        <th>Categoría y gravedad</th>
                        <th>Detalle</th>
                        <th>Penalidad o acción</th>
                        <th>Plazo de subsanación</th>
                        <th>Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($observaciones) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No existen observaciones pendientes de atención.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($observaciones as $o): ?>
                            <?php
                                $badgeClass = 'bg-warning text-dark';
                                if ($o['estado_respuesta'] === 'Penalidad Directa') $badgeClass = 'bg-danger text-white';
                                else if ($o['estado_respuesta'] === 'Apelación Aprobada') $badgeClass = 'bg-success text-white';
                                else if ($o['estado_respuesta'] === 'Apelación Rechazada') $badgeClass = 'bg-secondary text-white';
                                else if ($o['estado_respuesta'] === 'Respondido') $badgeClass = 'bg-info text-dark';
                            ?>
                            <tr>
                                <td><strong class="text-warning"><?= htmlspecialchars($o['codigo_supervision']) ?></strong></td>
                                <td><?= htmlspecialchars($o['nombre_partner']) ?></td>
                                <td>
                                    <span class="badge bg-danger"><?= htmlspecialchars($o['gravedad']) ?></span><br>
                                    <small class="text-light"><?= htmlspecialchars($o['nombre_categoria']) ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($o['descripcion_subopcion']) ?></strong><br>
                                    <small class="text-secondary"><?= htmlspecialchars($o['detalle_observacion']) ?></small>
                                </td>
                                <td>
                                    <?php if ($o['penalidad_monto'] > 0): ?>
                                        <span class="badge bg-danger text-white fs-6">S/ <?= number_format($o['penalidad_monto'], 2) ?></span><br>
                                    <?php else: ?>
                                        <span class="badge bg-dark text-secondary">Sin monto</span><br>
                                    <?php endif; ?>
                                    <?php if (!empty($o['accion_adicional'])): ?>
                                        <small class="text-warning"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($o['accion_adicional']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($o['subsanacion_inmediata'])): ?><span class="badge bg-danger mb-1">Inmediata</span><br><?php else: ?><span class="badge bg-warning text-dark mb-1"><?= (int)$o['plazo_subsanacion_dias'] ?> día<?= (int)$o['plazo_subsanacion_dias']===1?'':'s' ?></span><br><?php endif; ?>
                                    <small class="font-monospace text-warning"><?= htmlspecialchars($o['fecha_limite_respuesta']) ?></small>
                                </td>
                                <td>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($o['estado_respuesta']) ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($user['rol'] === Auth::ROLE_PARTNER && $o['estado_respuesta'] === 'Pendiente'): ?>
                                        <button class="btn btn-sm btn-win" onclick="abrirModalRespuesta(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['descripcion_subopcion'])) ?>')">
                                            <i class="bi bi-reply-fill"></i> Responder
                                        </button>
                                    <?php elseif (Auth::hasRole([Auth::ROLE_SUPERVISOR, Auth::ROLE_ADMINISTRADOR, Auth::ROLE_COORDINADOR]) && $o['estado_respuesta'] === 'Respondido'): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="abrirModalEvaluar(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['respuesta_partner'])) ?>')">
                                            <i class="bi bi-check2-square"></i> Evaluar apelación
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="verObsDetalle(<?= htmlspecialchars(json_encode($o)) ?>)">
                                            <i class="bi bi-eye"></i> Ver
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Responder Partner -->
<div class="modal fade" id="modalResponderPartner" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-white border-light-subtle text-dark">
            <div class="modal-header border-bottom border-light-subtle">
                <h5 class="modal-title fw-bold" style="color: var(--sgs-orange);"><i class="bi bi-reply-fill me-2"></i> Responder descargo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="form-responder-partner">
                <div class="modal-body">
                    <input type="hidden" id="resp_observacion_id">
                    <p class="text-secondary mb-2" id="txt-obs-titulo"></p>
                    <div class="mb-3">
                        <label for="resp_texto_partner" class="form-label">Sustento / Descargo Técnico *</label>
                        <textarea class="form-control" id="resp_texto_partner" rows="4" placeholder="Describa la corrección efectuada o la justificación operativa..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-light-subtle">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-win">Enviar respuesta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Evaluar Supervisor -->
<div class="modal fade" id="modalEvaluarSupervisor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-white border-light-subtle text-dark">
            <div class="modal-header border-bottom border-light-subtle">
                <h5 class="modal-title fw-bold text-success"><i class="bi bi-check2-square me-2"></i> Evaluación de apelación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="form-evaluar-supervisor">
                <div class="modal-body">
                    <input type="hidden" id="eval_observacion_id">
                    <div class="p-3 bg-light rounded text-dark border border-light-subtle mb-3">
                        <strong style="color: var(--sgs-orange);">Sustento presentado por el Partner:</strong>
                        <p class="mb-0 mt-1 small" id="txt-partner-resp"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Dictamen del Supervisor *</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="eval_decision" id="rad-aprobar" value="aprobar" checked>
                            <label class="form-check-label text-success fw-bold" for="rad-aprobar">Aprobar Apelación (Anular penalidad)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="eval_decision" id="rad-rechazar" value="rechazar">
                            <label class="form-check-label text-danger fw-bold" for="rad-rechazar">Rechazar Apelación (Aplicar penalidad)</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="eval_sustento" class="form-label">Justificación de la Evaluación *</label>
                        <textarea class="form-control" id="eval_sustento" rows="3" placeholder="Ingrese el fundamento técnico de su decisión..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-light-subtle">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar evaluación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="03_FRONTEND/src/assets/js/observaciones.js"></script>
<script>
    function abrirModalRespuesta(id, titulo) {
        document.getElementById('resp_observacion_id').value = id;
        document.getElementById('txt-obs-titulo').textContent = 'Observación: ' + titulo;
        const modal = new bootstrap.Modal(document.getElementById('modalResponderPartner'));
        modal.show();
    }

    function abrirModalEvaluar(id, respuestaPartner) {
        document.getElementById('eval_observacion_id').value = id;
        document.getElementById('txt-partner-resp').textContent = respuestaPartner;
        const modal = new bootstrap.Modal(document.getElementById('modalEvaluarSupervisor'));
        modal.show();
    }

    function verObsDetalle(obsObj) {
        alert("Observación: " + obsObj.descripcion_subopcion + "\nDetalle: " + obsObj.detalle_observacion + "\nEstado: " + obsObj.estado_respuesta);
    }
</script>
