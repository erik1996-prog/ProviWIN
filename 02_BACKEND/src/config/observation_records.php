<?php

function observationRegisterFilters(array $source): array {
    return [
        'partner_id' => max(0, (int)($source['partner_id'] ?? 0)),
        'cuadrilla' => trim((string)($source['cuadrilla'] ?? '')),
        'departamento' => trim((string)($source['departamento'] ?? '')),
        'codigo' => mb_substr(trim((string)($source['codigo'] ?? '')), 0, 60),
        'fecha_desde' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($source['fecha_desde'] ?? '')) ? $source['fecha_desde'] : '',
        'fecha_hasta' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($source['fecha_hasta'] ?? '')) ? $source['fecha_hasta'] : ''
        ,'tipo_registro' => in_array((string)($source['tipo_registro'] ?? ''), ['Checklist','Campo','Hallazgo'], true) ? (string)$source['tipo_registro'] : ''
        ,'supervision_id' => max(0, (int)($source['supervision_id'] ?? 0))
        ,'estado' => in_array((string)($source['estado'] ?? ''), ['Pendiente','En revisión','Validado','Penalizado','Reabierto'], true) ? (string)$source['estado'] : ''
    ];
}

function observationRegisterScope(array $user): array {
    // Gestión (administrador, gerente, coordinador) ve todo; el resto, solo su alcance.
    $effectiveRole = (string)($user['rol'] ?? '');
    if (in_array($effectiveRole, [Auth::ROLE_ADMINISTRADOR, Auth::ROLE_GERENTE, Auth::ROLE_COORDINADOR], true)) {
        // Líder con zonas o área propias (2026-10-27; 2026-10-28 también el área): solo las observaciones de su entorno
        if (sgsAlcanceLimitado($user)) {
            $params = [];
            return [' AND ' . sgsAlcanceSupervisionSql($user, 'registros', $params, ':scope_alc'), $params];
        }
        return ['', []];
    }
    if (($user['rol'] ?? '') === Auth::ROLE_JEFE_OPERACIONES && !empty($user['partner_id'])) {
        // Jefe de operaciones (2026-10-12): todas las observaciones de su partner, en todas sus zonas.
        return [' AND registros.partner_id=:scope_partner', [':scope_partner' => (int)$user['partner_id']]];
    }
    if (empty($user['is_win_directo']) && !empty($user['partner_id'])) {
        // Regla de negocio (2026-10-04): el usuario de un partner ve las observaciones de SU partner
        // y de SU zona (departamento base + accesos adicionales), no solo las de sus órdenes.
        $params = [':scope_partner'=>(int)$user['partner_id']];
        $zone = sgsZoneSql(sgsZonaSql('registros'), (int)$user['id'], $params, ':scope_zona'); // zona: departamento o NORTE CHICO
        // 2026-10-28: y de su área de trabajo (Instalaciones o VT), si tiene una.
        return [' AND registros.partner_id=:scope_partner AND ' . $zone . ' AND ' . sgsAreaSql('registros', (int)$user['id']), $params];
    }
    return [' AND registros.creado_por=:scope_creator', [':scope_creator'=>(int)$user['id']]];
}

function observationRecordsBaseQuery(array $user, array $filters): array {
    $sql = "SELECT registros.* FROM (
      SELECT o.id registro_id,o.id observacion_id,'Hallazgo' tipo_registro,s.id supervision_id,s.supervisor_id,s.asignado_por,o.creado_por,
        s.codigo_supervision,s.ticket_codigo,s.tecnico1_nombre cuadrilla,s.departamento,s.provincia,s.distrito,
        p.id partner_id,p.nombre_partner,u.nombre_completo supervisor_nombre,o.fecha_registro,
        COALESCE(sub.descripcion_subopcion,c.nombre_categoria,'Observación registrada') titulo,
        o.detalle_observacion comentario,o.gravedad estado_item,o.foto_1,o.foto_comentario_1,o.foto_2,o.foto_comentario_2,o.foto_3,o.foto_comentario_3,
        o.fecha_limite_respuesta,o.estado_respuesta,o.respuesta_partner,o.fecha_respuesta_partner,o.respondido_por_partner,ur.nombre_completo respondido_por_nombre,
        o.descargo_foto_1,o.descargo_comentario_1,o.descargo_foto_2,o.descargo_comentario_2,o.descargo_foto_3,o.descargo_comentario_3,o.sustentacion_evaluacion,o.fecha_evaluacion,
        NULL comentario_general,NULL firma_supervisor,s.clasificacion_supervision
      FROM observaciones_supervision o JOIN supervisiones s ON s.id=o.supervision_id
      LEFT JOIN partners p ON p.id=s.partner_id LEFT JOIN usuarios u ON u.id=s.supervisor_id LEFT JOIN usuarios ur ON ur.id=o.respondido_por_partner
      LEFT JOIN categorias_observacion c ON c.id=o.categoria_id LEFT JOIN subopciones_observacion sub ON sub.id=o.subopcion_id
      UNION ALL
      SELECT cr.id,NULL,'Checklist',s.id,s.supervisor_id,s.asignado_por,ct.supervisor_id,s.codigo_supervision,s.ticket_codigo,COALESCE(NULLIF(ct.tecnico_nombre,''),s.tecnico1_nombre),
        s.departamento,s.provincia,s.distrito,p.id,p.nombre_partner,u.nombre_completo,ct.fecha_registro,
        cr.criterio,COALESCE(cr.observacion,''),cr.estado,cr.foto_evidencia,NULL,NULL,NULL,NULL,NULL,
        COALESCE(ct.fecha_limite_respuesta, CASE WEEKDAY(ct.fecha_registro)
          WHEN 0 THEN DATE_ADD(ct.fecha_registro, INTERVAL 3 DAY)
          WHEN 1 THEN DATE_ADD(ct.fecha_registro, INTERVAL 3 DAY)
          WHEN 2 THEN DATE_ADD(ct.fecha_registro, INTERVAL 3 DAY)
          WHEN 3 THEN DATE_ADD(ct.fecha_registro, INTERVAL 4 DAY)
          WHEN 4 THEN DATE_ADD(ct.fecha_registro, INTERVAL 4 DAY)
          WHEN 5 THEN DATE_ADD(ct.fecha_registro, INTERVAL 4 DAY)
          ELSE DATE_ADD(ct.fecha_registro, INTERVAL 3 DAY)
        END),ct.estado_respuesta,ct.respuesta_general_partner,ct.fecha_respuesta_partner,ct.respondido_por_partner,ur.nombre_completo,cr.descargo_foto,cr.descargo_comentario,NULL,NULL,NULL,NULL,ct.sustentacion_evaluacion,ct.fecha_evaluacion,ct.observacion_general,ct.firma_supervisor,s.clasificacion_supervision
      FROM checklist_respuestas cr JOIN checklists_tecnicos ct ON ct.id=cr.checklist_id JOIN supervisiones s ON s.id=ct.supervision_id
      LEFT JOIN partners p ON p.id=s.partner_id LEFT JOIN usuarios u ON u.id=ct.supervisor_id LEFT JOIN usuarios ur ON ur.id=ct.respondido_por_partner
      WHERE (cr.observacion IS NOT NULL AND TRIM(cr.observacion)<>'') OR cr.foto_evidencia IS NOT NULL
    ) registros WHERE 1=1";
    [$scopeSql,$params] = observationRegisterScope($user);
    $sql .= $scopeSql;
    if ($filters['partner_id']) { $sql.=' AND registros.partner_id=:partner_id'; $params[':partner_id']=$filters['partner_id']; }
    if ($filters['cuadrilla']!=='') { $sql.=' AND registros.cuadrilla=:cuadrilla'; $params[':cuadrilla']=$filters['cuadrilla']; }
    if ($filters['departamento']!=='') { $sql.=' AND registros.departamento=:departamento'; $params[':departamento']=$filters['departamento']; }
    if ($filters['codigo']!=='') {
        // PDO usa prepares nativos: cada aparición necesita su propio marcador.
        // Reutilizar :codigo en ambos LIKE produce HY093 al aplicar el filtro.
        $sql.=' AND (registros.ticket_codigo LIKE :codigo_ticket OR registros.codigo_supervision LIKE :codigo_supervision)';
        $searchCode='%'.addcslashes($filters['codigo'],'%_\\').'%';
        $params[':codigo_ticket']=$searchCode;
        $params[':codigo_supervision']=$searchCode;
    }
    if ($filters['fecha_desde']!=='') { $sql.=' AND registros.fecha_registro>=:fecha_desde'; $params[':fecha_desde']=$filters['fecha_desde']; }
    if ($filters['fecha_hasta']!=='') { $sql.=' AND registros.fecha_registro<DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)'; $params[':fecha_hasta']=$filters['fecha_hasta']; }
    if ($filters['tipo_registro']==='Campo') {
        $sql.=" AND registros.tipo_registro = 'Hallazgo'";
    } elseif ($filters['tipo_registro']!=='') {
        $sql.=' AND registros.tipo_registro=:tipo_registro';
        $params[':tipo_registro']=$filters['tipo_registro'];
    }
    if ($filters['supervision_id']) { $sql.=' AND registros.supervision_id=:supervision_id'; $params[':supervision_id']=$filters['supervision_id']; }
    if ($filters['estado']!=='') {
        $stateMap = [
            'Pendiente' => ['Pendiente'],
            'En revisión' => ['En revisión'],
            'Validado' => ['Validado','Levantamiento validado','Apelación Aprobada'],
            'Penalizado' => ['Penalizado','Levantamiento rechazado','Penalidad Directa','Apelación Rechazada'],
            'Reabierto' => ['Reabierto'],
        ];
        $stateValues = $stateMap[$filters['estado']];
        $stateParams = [];
        foreach ($stateValues as $index => $stateValue) {
            $key = ':estado_respuesta_' . $index;
            $stateParams[] = $key;
            $params[$key] = $stateValue;
        }
        $sql .= ' AND registros.estado_respuesta IN (' . implode(',', $stateParams) . ')';
    }
    return [$sql, $params];
}

function fetchObservationRecords(PDO $pdo, array $user, array $filters): array {
    [$sql, $params] = observationRecordsBaseQuery($user, $filters);
    $sql .= ' ORDER BY registros.fecha_registro DESC,registros.registro_id DESC';
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Pagina registros lógicos: cada hallazgo ocupa una posición y todas las filas
 * observadas de un mismo checklist viajan juntas. Así una página nunca deja un
 * modal de checklist incompleto.
 *
 * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,has_more:bool}
 */
function fetchObservationRecordsPage(PDO $pdo, array $user, array $filters, int $page = 1, int $perPage = 30): array {
    $page = max(1, $page);
    $perPage = min(100, max(10, $perPage));
    $offset = ($page - 1) * $perPage;
    [$baseSql, $params] = observationRecordsBaseQuery($user, $filters);
    $logicalKey = "CASE WHEN filtered.tipo_registro='Checklist'
        THEN CONCAT('C:',filtered.supervision_id)
        ELSE CONCAT('R:',filtered.registro_id) END";

    $countSql = "SELECT COUNT(*) FROM (
        SELECT {$logicalKey} logical_key
        FROM ({$baseSql}) filtered
        GROUP BY logical_key
    ) logical_records";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $keysSql = "SELECT {$logicalKey} logical_key,MAX(filtered.fecha_registro) logical_date,MAX(filtered.registro_id) logical_id
        FROM ({$baseSql}) filtered
        GROUP BY logical_key
        ORDER BY logical_date DESC,logical_id DESC
        LIMIT :records_limit OFFSET :records_offset";
    $keysStmt = $pdo->prepare($keysSql);
    foreach ($params as $key => $value) $keysStmt->bindValue($key, $value);
    $keysStmt->bindValue(':records_limit', $perPage, PDO::PARAM_INT);
    $keysStmt->bindValue(':records_offset', $offset, PDO::PARAM_INT);
    $keysStmt->execute();
    $keys = $keysStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$keys) {
        return ['data'=>[],'total'=>$total,'page'=>$page,'per_page'=>$perPage,'has_more'=>false];
    }

    $checklistIds = [];
    $recordIds = [];
    foreach ($keys as $key) {
        [$type, $id] = array_pad(explode(':', (string)$key, 2), 2, '0');
        if ($type === 'C') $checklistIds[] = (int)$id;
        elseif ($type === 'R') $recordIds[] = (int)$id;
    }

    $pageConditions = [];
    if ($checklistIds) {
        $placeholders = [];
        foreach ($checklistIds as $index => $id) {
            $placeholder = ':page_checklist_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $pageConditions[] = "(registros.tipo_registro='Checklist' AND registros.supervision_id IN (" . implode(',', $placeholders) . '))';
    }
    if ($recordIds) {
        $placeholders = [];
        foreach ($recordIds as $index => $id) {
            $placeholder = ':page_record_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $pageConditions[] = "(registros.tipo_registro<>'Checklist' AND registros.registro_id IN (" . implode(',', $placeholders) . '))';
    }

    $pageSql = $baseSql . ' AND (' . implode(' OR ', $pageConditions) . ')
        ORDER BY registros.fecha_registro DESC,registros.registro_id DESC';
    $pageStmt = $pdo->prepare($pageSql);
    $pageStmt->execute($params);
    $rows = $pageStmt->fetchAll();

    return [
        'data'=>$rows,
        'total'=>$total,
        'page'=>$page,
        'per_page'=>$perPage,
        'has_more'=>$offset + count($keys) < $total,
    ];
}
