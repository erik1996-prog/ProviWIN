<?php
// 02_BACKEND/src/config/vencimientos.php - Vencimientos de la cuadrilla (2026-10-15).
// SOAT, revisión técnica y extintor van con el VEHÍCULO (placa); el brevete con el TÉCNICO LÍDER. Cada actualización es
// una fila de documentos_vencimiento (fechas + foto obligatoria); la vigente es la última y las demás son su historial.
// Estado:  bueno → regular (extintor: pasada la mitad de su vigencia; SOAT y revisión técnica: últimos 30 días;
//          brevete: últimos 60 días) → malo (vencido). Sin fechas: "sin_registrar".
// La zona de un vehículo es la de la cuadrilla donde está hoy (técnico líder con ese vehiculo_id); si cambia de cuadrilla
// o de zona, sus fechas lo acompañan. Vencido en una supervisión → observación automática (falta del catálogo).
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/notifications.php';

const SGS_VENCIMIENTOS = [
    'SOAT' => ['sujeto' => 'vehiculo', 'aviso' => 30, 'falta' => 'VEHÍCULO NO TIENE SOAT', 'icono' => 'bi-shield-check'],
    'Revisión técnica' => ['sujeto' => 'vehiculo', 'aviso' => 30, 'falta' => 'VEHÍCULO CON REVISIÓN TÉCNICA VENCIDA', 'icono' => 'bi-clipboard-check'],
    'Extintor' => ['sujeto' => 'vehiculo', 'aviso' => 'mitad', 'falta' => 'NO CONTAR CON KIT DE EMERGENCIAS EXTINTOR', 'icono' => 'bi-fire'],
    'Brevete' => ['sujeto' => 'tecnico', 'aviso' => 60, 'falta' => 'NO PORTAR LA LICENCIA DE CONDUCIR VIGENTE', 'icono' => 'bi-person-vcard'],
];
const SGS_VENC_ETIQUETAS = ['bueno' => 'Vigente', 'regular' => 'Por vencer', 'malo' => 'Vencido', 'sin_registrar' => 'Sin registrar'];

/** Estado de un documento a la fecha $hoy (Y-m-d). Devuelve estado, días que faltan (negativo = vencido) y texto. */
function sgsEstadoVencimiento(string $tipo, ?string $inicio, ?string $vence, ?string $hoy = null): array {
    if (!$vence || !$inicio) return ['estado' => 'sin_registrar', 'dias' => null, 'texto' => 'Sin registrar'];
    $h = new DateTimeImmutable($hoy ?? date('Y-m-d'));
    $v = new DateTimeImmutable($vence);
    $i = new DateTimeImmutable($inicio);
    $dias = (int)$h->diff($v)->format('%r%a');
    if ($dias < 0) {
        $estado = 'malo';
    } else {
        $aviso = SGS_VENCIMIENTOS[$tipo]['aviso'] ?? 30;
        if ($aviso === 'mitad') {
            $total = max(1, (int)$i->diff($v)->format('%a'));
            $pasados = (int)$i->diff($h)->format('%r%a');
            $estado = $pasados * 2 >= $total ? 'regular' : 'bueno';
        } else {
            $estado = $dias <= (int)$aviso ? 'regular' : 'bueno';
        }
    }
    $texto = $dias < 0 ? 'Vencido hace ' . (-$dias) . ' día' . (-$dias === 1 ? '' : 's')
        : ($dias === 0 ? 'Vence hoy' : 'Vence en ' . $dias . ' día' . ($dias === 1 ? '' : 's'));
    return ['estado' => $estado, 'dias' => $dias, 'texto' => $texto];
}

/** Valor del checklist para el estado (los ítems de vencimiento se llenan solos). */
function sgsChecklistDesdeVencimiento(string $estado): string {
    return ['bueno' => 'Bueno', 'regular' => 'Regular', 'malo' => 'Malo'][$estado] ?? 'No revisado';
}

/** Últimas filas (vigentes) de documentos para vehículos y técnicos: [tipo|v{id}|t{id} => fila]. */
function sgsDocumentosVigentes(PDO $pdo, array $vehiculoIds, array $tecnicoIds): array {
    $vehiculoIds = array_values(array_unique(array_filter(array_map('intval', $vehiculoIds))));
    $tecnicoIds = array_values(array_unique(array_filter(array_map('intval', $tecnicoIds))));
    if (!$vehiculoIds && !$tecnicoIds) return [];
    $cond = [];
    if ($vehiculoIds) $cond[] = 'vehiculo_id IN (' . implode(',', $vehiculoIds) . ')';
    if ($tecnicoIds) $cond[] = 'tecnico_id IN (' . implode(',', $tecnicoIds) . ')';
    // Solo lo aplicado: lo registrado en una supervisión en proceso cuenta para la cuadrilla al finalizarla.
    $sql = "SELECT d.* FROM documentos_vencimiento d JOIN (SELECT MAX(id) AS id FROM documentos_vencimiento
            WHERE aplicado = 1 AND (" . implode(' OR ', $cond) . ") GROUP BY tipo, vehiculo_id, tecnico_id) m ON m.id = d.id";
    $salida = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $salida[$d['tipo'] . '|' . ($d['vehiculo_id'] ? 'v' . $d['vehiculo_id'] : 't' . $d['tecnico_id'])] = $d;
    }
    return $salida;
}

/** Fechas registradas en una supervisión que aún no se asignan a la cuadrilla (en proceso): [tipo => fila]. */
function sgsPendientesSupervision(PDO $pdo, int $supervisionId): array {
    $st = $pdo->prepare('SELECT * FROM documentos_vencimiento WHERE supervision_id = :s AND aplicado = 0 ORDER BY id');
    $st->execute([':s' => $supervisionId]);
    $salida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $salida[$d['tipo']] = $d;
    return $salida;
}

/**
 * Los cuatro documentos de una cuadrilla (técnico líder + vehículo), con estado e historial. Con $supervisionId, lo
 * registrado en esa supervisión (aún pendiente) tiene prioridad: es lo que el supervisor vio en la visita.
 */
function sgsVencimientosCuadrilla(PDO $pdo, int $liderId, ?int $vehiculoId, bool $conHistorial = true, ?int $supervisionId = null): array {
    $vig = sgsDocumentosVigentes($pdo, [$vehiculoId], [$liderId]);
    $pendientes = $supervisionId ? sgsPendientesSupervision($pdo, $supervisionId) : [];
    $items = [];
    foreach (SGS_VENCIMIENTOS as $tipo => $cfg) {
        $sujetoId = $cfg['sujeto'] === 'vehiculo' ? $vehiculoId : $liderId;
        $d = $pendientes[$tipo] ?? ($sujetoId ? ($vig[$tipo . '|' . ($cfg['sujeto'] === 'vehiculo' ? 'v' : 't') . $sujetoId] ?? null) : null);
        $e = sgsEstadoVencimiento($tipo, $d['fecha_inicio'] ?? null, $d['fecha_vencimiento'] ?? null);
        $item = ['tipo' => $tipo, 'sujeto' => $cfg['sujeto'], 'sujeto_id' => $sujetoId, 'icono' => $cfg['icono'],
            'fecha_inicio' => $d['fecha_inicio'] ?? null, 'fecha_vencimiento' => $d['fecha_vencimiento'] ?? null, 'foto' => $d['foto'] ?? null,
            'pendiente' => isset($pendientes[$tipo]), 'checklist' => sgsChecklistDesdeVencimiento($e['estado'])] + $e;
        if ($conHistorial) $item['historial'] = $sujetoId ? sgsHistorialVencimiento($pdo, $tipo, $cfg['sujeto'], (int)$sujetoId) : [];
        $items[] = $item;
    }
    return $items;
}

function sgsHistorialVencimiento(PDO $pdo, string $tipo, string $sujeto, int $sujetoId): array {
    $col = $sujeto === 'vehiculo' ? 'vehiculo_id' : 'tecnico_id';
    $st = $pdo->prepare("SELECT d.id, d.fecha_inicio, d.fecha_vencimiento, d.foto, d.origen, d.supervision_id, d.aplicado, d.departamento, d.registrado_en,
            l.nombre_tecnico AS cuadrilla, TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS registrado_por_nombre
        FROM documentos_vencimiento d LEFT JOIN tecnicos l ON l.id = d.lider_id LEFT JOIN usuarios u ON u.id = d.registrado_por
        WHERE d.tipo = :t AND d.$col = :id ORDER BY d.id DESC LIMIT 20");
    $st->execute([':t' => $tipo, ':id' => $sujetoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Registra fechas nuevas de un documento con su foto (obligatoria). $archivo es la entrada de $_FILES.
 * Guarda la cuadrilla y la zona del momento. $aplicado = false: registrado en una supervisión en proceso (cuenta para
 * la cuadrilla al finalizarla; si se vuelve a registrar en la misma supervisión, reemplaza al pendiente). Devuelve el id.
 */
function sgsRegistrarVencimiento(PDO $pdo, string $tipo, int $sujetoId, string $inicio, string $vence, ?array $archivo,
                                 string $origen, ?int $supervisionId, int $actorId, bool $aplicado = true): int {
    if (!isset(SGS_VENCIMIENTOS[$tipo])) throw new Exception('Documento no válido.');
    $sujeto = SGS_VENCIMIENTOS[$tipo]['sujeto'];
    $fi = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
    $fv = DateTimeImmutable::createFromFormat('!Y-m-d', $vence);
    if (!$fi || $fi->format('Y-m-d') !== $inicio) throw new Exception('Registre la fecha de inicio (emisión o última recarga).');
    if (!$fv || $fv->format('Y-m-d') !== $vence) throw new Exception('Registre la fecha de vencimiento.');
    if ($fv <= $fi) throw new Exception('La fecha de vencimiento debe ser posterior a la de inicio.');
    if ($fi > new DateTimeImmutable('today')) throw new Exception('La fecha de inicio no puede ser futura.');
    if ((int)$fi->diff($fv)->format('%y') >= 15) throw new Exception('Revise las fechas: la vigencia no puede pasar de 15 años.');
    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new Exception("Adjunte la foto del {$tipo} como evidencia.");
    if ($archivo['error'] !== UPLOAD_ERR_OK) throw new Exception('No se pudo recibir la foto. Inténtelo otra vez.');

    if ($sujeto === 'vehiculo') {
        $st = $pdo->prepare('SELECT v.id, l.id AS lider_id, l.departamento FROM vehiculos v
            LEFT JOIN tecnicos l ON l.vehiculo_id = v.id AND COALESCE(l.tipo_tecnico, 1) = 1 WHERE v.id = :id ORDER BY l.id LIMIT 1');
    } else {
        $st = $pdo->prepare('SELECT t.id, t.id AS lider_id, t.departamento FROM tecnicos t WHERE t.id = :id AND COALESCE(t.tipo_tecnico, 1) = 1');
    }
    $st->execute([':id' => $sujetoId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new Exception($sujeto === 'vehiculo' ? 'El vehículo ya no existe.' : 'El técnico líder ya no existe.');

    try {
        $valido = validateUploadedFile($archivo, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 8 * 1024 * 1024);
    } catch (RuntimeException $e) {
        throw new Exception('La foto del documento debe ser una imagen (JPG, PNG, WEBP) o un PDF de hasta 8 MB.');
    }
    $nombre = randomUploadName('venc_' . strtolower(str_replace([' ', 'é'], ['_', 'e'], $tipo)) . '_', $valido['extension']);
    if (!move_uploaded_file($archivo['tmp_name'], UPLOAD_DIR . $nombre)) throw new Exception('No se pudo guardar la foto del documento.');
    if (!$aplicado && $supervisionId) {
        // Una sola fecha pendiente por documento y supervisión: la nueva reemplaza a la anterior (y a su foto).
        $previo = sgsPendientesSupervision($pdo, $supervisionId)[$tipo] ?? null;
        if ($previo) {
            $pdo->prepare('DELETE FROM documentos_vencimiento WHERE id = :id')->execute([':id' => $previo['id']]);
            @unlink(SHARED_FILES_PATH . '/' . $previo['foto']);
        }
    }
    $pdo->prepare('INSERT INTO documentos_vencimiento (tipo, vehiculo_id, tecnico_id, fecha_inicio, fecha_vencimiento, foto, origen, supervision_id, aplicado, lider_id, departamento, registrado_por, registrado_en)
            VALUES (:t, :v, :tec, :fi, :fv, :foto, :o, :s, :a, :l, :d, :u, NOW())')
        ->execute([':t' => $tipo, ':v' => $sujeto === 'vehiculo' ? $sujetoId : null, ':tec' => $sujeto === 'tecnico' ? $sujetoId : null,
            ':fi' => $inicio, ':fv' => $vence, ':foto' => UPLOAD_URL . $nombre, ':o' => $origen === 'Supervisión' ? 'Supervisión' : 'Cuadrillas',
            ':s' => $supervisionId ?: null, ':a' => $aplicado ? 1 : 0, ':l' => $s['lider_id'] ?: null, ':d' => $s['departamento'] ?: null, ':u' => $actorId ?: null]);
    $id = (int)$pdo->lastInsertId();
    win_log_error('audit_expiry_registered', "Vencimiento registrado: {$tipo}", ['actor_id' => $actorId, 'documento_id' => $id, 'origen' => $origen]);
    return $id;
}

/**
 * Al FINALIZAR la supervisión, sus fechas pendientes pasan a la cuadrilla: al vehículo y al técnico líder con los que
 * terminó (por si cambiaron durante la visita), con la cuadrilla y zona de ese momento. Devuelve cuántas aplicó.
 */
function sgsAplicarPendientesSupervision(PDO $pdo, int $supervisionId, ?int $liderId, ?int $vehiculoId): int {
    $pend = sgsPendientesSupervision($pdo, $supervisionId);
    if (!$pend) return 0;
    $dep = null;
    if ($liderId) {
        $st = $pdo->prepare('SELECT departamento FROM tecnicos WHERE id = :id');
        $st->execute([':id' => $liderId]);
        $dep = $st->fetchColumn() ?: null;
    }
    $aplicadas = 0;
    $upd = $pdo->prepare('UPDATE documentos_vencimiento SET aplicado = 1, vehiculo_id = :v, tecnico_id = :t, lider_id = COALESCE(:l, lider_id), departamento = COALESCE(:d, departamento) WHERE id = :id');
    foreach ($pend as $tipo => $d) {
        $esVehiculo = SGS_VENCIMIENTOS[$tipo]['sujeto'] === 'vehiculo';
        $v = $esVehiculo ? ($vehiculoId ?: (int)$d['vehiculo_id'] ?: null) : null;
        $t = $esVehiculo ? null : ($liderId ?: (int)$d['tecnico_id'] ?: null);
        if (!$v && !$t) continue; // sin vehículo ni técnico al que asignarlo
        $upd->execute([':v' => $v, ':t' => $t, ':l' => $liderId, ':d' => $dep, ':id' => $d['id']]);
        $aplicadas++;
    }
    return $aplicadas;
}

/**
 * Observaciones automáticas de una supervisión: una por documento vencido del T1 o del vehículo, con la falta del catálogo.
 * Cuenta lo registrado en la misma supervisión (si renovó el SOAT en la visita, ya no está vencido).
 * No se repiten si la supervisión ya tiene esa falta automática. Devuelve cuántas creó.
 */
function sgsObservacionesPorVencimiento(PDO $pdo, int $supervisionId, ?int $partnerId, ?int $liderId, ?int $vehiculoId, int $actorId): int {
    if (!$liderId && !$vehiculoId) return 0;
    $creadas = 0;
    foreach (sgsVencimientosCuadrilla($pdo, (int)$liderId, $vehiculoId, false, $supervisionId) as $item) {
        if ($item['estado'] !== 'malo' || !$item['sujeto_id']) continue;
        $st = $pdo->prepare('SELECT id, categoria_id, gravedad FROM subopciones_observacion WHERE descripcion_subopcion LIKE :d ORDER BY id LIMIT 1');
        $st->execute([':d' => SGS_VENCIMIENTOS[$item['tipo']]['falta'] . '%']);
        $falta = $st->fetch(PDO::FETCH_ASSOC);
        if (!$falta) continue;
        $ya = $pdo->prepare("SELECT 1 FROM observaciones_supervision WHERE supervision_id = :s AND subopcion_id = :o AND detalle_observacion LIKE 'Automática:%' LIMIT 1");
        $ya->execute([':s' => $supervisionId, ':o' => $falta['id']]);
        if ($ya->fetchColumn()) continue;
        $quien = $item['sujeto'] === 'vehiculo' ? 'El vehículo' : 'El técnico líder';
        $detalle = "Automática: {$quien} tiene el {$item['tipo']} vencido desde el " . date('d/m/Y', strtotime($item['fecha_vencimiento'] . ' +1 day')) . " ({$item['texto']}).";
        $directa = $falta['gravedad'] === 'Muy Grave';
        $pdo->prepare("INSERT INTO observaciones_supervision (supervision_id, creado_por, gravedad, categoria_id, subopcion_id, detalle_observacion,
                fecha_registro, fecha_limite_respuesta, plazo_subsanacion_dias, subsanacion_inmediata, estado_respuesta)
                VALUES (:s, :u, :g, :c, :o, :d, NOW(), :lim, 3, 0, :e)")
            ->execute([':s' => $supervisionId, ':u' => $actorId ?: null, ':g' => $falta['gravedad'], ':c' => $falta['categoria_id'], ':o' => $falta['id'], ':d' => $detalle,
                ':lim' => $directa ? date('Y-m-d H:i:s') : observationBusinessDeadline(3), ':e' => $directa ? 'Penalidad Directa' : 'Pendiente']);
        if ($partnerId) notifyPartnerObservation($pdo, (int)$pdo->lastInsertId(), $supervisionId, (int)$partnerId, 3, false, $directa);
        $creadas++;
    }
    return $creadas;
}
