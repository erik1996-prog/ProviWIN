<?php
// Se ejecuta dentro de la transacción de la supervisión, después de autorizarla.
// $actualizarCuadrilla (2026-10-15): la supervisión se CIERRA y el supervisor dejó marcada "Guardar el T2 y el vehículo".
// Solo entonces cambia la ficha del técnico líder: su T2 habitual, su vehículo y el tipo/estado del vehículo (un vehículo
// reparado pasa a "Buen estado", un cambio de vehículo queda registrado). Mientras la supervisión está en proceso, o con
// la casilla desmarcada, la supervisión registra su personal pero la ficha no cambia.
// Los T2 rotan: se aceptan de cualquier partner; al cerrar pasan al partner y a la cuadrilla de esta supervisión.
function guardarPersonalSupervision(PDO $pdo, int $supervisionId, ?int $partnerId, array $tecnicos, array $vehiculo, bool $actualizarCuadrilla = true): void {
    $ids = [null, null];
    foreach ($tecnicos as $index => $tecnico) {
        $nombre = trim((string)($tecnico['nombre'] ?? ''));
        if ($nombre === '') continue;
        $tipo = $index + 1;
        $dni = trim((string)($tecnico['dni'] ?? ''));
        if (!preg_match('/^\d{8}$/', $dni)) throw new RuntimeException("Ingrese un DNI válido para el técnico {$tipo}.");
        // T1 = técnico líder, del partner de la supervisión. T2 = técnico de apoyo (grupo distinto, sin nomenclatura):
        // rota entre cuadrillas y puede venir de otro partner.
        if ($tipo === 1) {
            $stmt = $pdo->prepare('SELECT id,dni,usuario_id,partner_id FROM tecnicos WHERE nombre_tecnico=:nombre AND COALESCE(tipo_tecnico,1)=1 AND partner_id=:partner FOR UPDATE');
            $stmt->execute([':nombre'=>$nombre, ':partner'=>$partnerId]);
        } else {
            $stmt = $pdo->prepare('SELECT id,dni,usuario_id,partner_id FROM tecnicos WHERE nombre_tecnico=:nombre AND tipo_tecnico=2 FOR UPDATE');
            $stmt->execute([':nombre'=>$nombre]);
        }
        $maestro = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$maestro) {
            $otroTipo = $pdo->prepare('SELECT 1 FROM tecnicos WHERE nombre_tecnico=:nombre LIMIT 1');
            $otroTipo->execute([':nombre'=>$nombre]);
            if ($otroTipo->fetchColumn()) {
                throw new RuntimeException($tipo === 1 ? 'El T1 debe ser un técnico líder de este partner.' : 'El T2 debe ser un técnico de apoyo; el elegido es técnico líder.');
            }
        }
        if (!$maestro || (!empty($tecnico['id']) && (int)$tecnico['id'] !== (int)$maestro['id'])) {
            throw new RuntimeException($tipo === 1 ? 'El técnico 1 no pertenece al partner seleccionado. Selecciónelo nuevamente.' : 'El técnico de apoyo (T2) no está registrado. Selecciónelo nuevamente.');
        }
        if ($tipo === 2 && $ids[0] === (int)$maestro['id']) throw new RuntimeException('El técnico de apoyo (T2) no puede ser el mismo técnico líder (T1).');
        $ids[$index] = (int)$maestro['id'];
        if ($maestro['dni'] !== $dni) {
            // Una cuenta futura conserva su identidad; una corrección vinculada requiere administración.
            if ($maestro['usuario_id'] !== null) throw new RuntimeException('Este técnico ya tiene una cuenta de usuario. Solicite al administrador la corrección de su DNI.');
            $duplicate = $pdo->prepare('SELECT id FROM tecnicos WHERE dni=:dni AND id<>:id');
            $duplicate->execute([':dni'=>$dni, ':id'=>$maestro['id']]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('El DNI ya está registrado para otro técnico.');
            $pdo->prepare('UPDATE tecnicos SET dni=:dni WHERE id=:id')->execute([':dni'=>$dni, ':id'=>$maestro['id']]);
        }
    }
    $vehiculoId = null;
    $placa = strtoupper(trim((string)($vehiculo['placa'] ?? '')));
    if ($placa !== '') {
        if (!$ids[0]) throw new RuntimeException('Seleccione al Técnico 1 antes de registrar el vehículo.');
        if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,9}$/', $placa)) throw new RuntimeException('Registre una placa válida de hasta 10 caracteres.');
        // Otra placa identifica otro vehículo: nunca renombrar el vehículo anterior ni su historial.
        $estado = in_array($vehiculo['estado'] ?? '', ['Mal estado','Regular','Buen estado'], true) ? $vehiculo['estado'] : null;
        $pdo->prepare('INSERT INTO vehiculos(placa,tipo,estado_conservacion,soat,revision_tecnica) VALUES(:placa,:tipo,:estado,:soat,:revision)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')
            ->execute([':placa'=>$placa, ':tipo'=>$vehiculo['tipo'] ?: null, ':estado'=>$estado, ':soat'=>$vehiculo['soat'] ?: null, ':revision'=>$vehiculo['revision'] ?: null]);
        $query = $pdo->prepare('SELECT id FROM vehiculos WHERE placa=:placa');
        $query->execute([':placa'=>$placa]);
        $vehiculoId = (int)$query->fetchColumn();
        if ($actualizarCuadrilla) {
            // Tipo y estado: lo último registrado al cerrar (p. ej. reparado → "Buen estado").
            $pdo->prepare('UPDATE vehiculos SET tipo=COALESCE(:tipo,tipo), estado_conservacion=COALESCE(:estado,estado_conservacion) WHERE id=:id')
                ->execute([':tipo'=>$vehiculo['tipo'] ?: null, ':estado'=>$estado, ':id'=>$vehiculoId]);
            // Un vehículo es de una sola cuadrilla: si lo tenía otro líder, se le quita.
            $pdo->prepare('UPDATE tecnicos SET vehiculo_id=NULL WHERE vehiculo_id=:vehiculo AND id<>:id')->execute([':vehiculo'=>$vehiculoId, ':id'=>$ids[0]]);
            $pdo->prepare('UPDATE tecnicos SET vehiculo_id=:vehiculo WHERE id=:id')->execute([':vehiculo'=>$vehiculoId, ':id'=>$ids[0]]);
        }
    }
    // Técnico de apoyo habitual del líder (cuadrilla). Si el T2 estaba en otra cuadrilla, sale de ella y pasa a este partner.
    if ($actualizarCuadrilla && $ids[0] && $ids[1]) {
        $pdo->prepare('UPDATE tecnicos SET apoyo_id=NULL WHERE apoyo_id=:apoyo AND id<>:id')->execute([':apoyo'=>$ids[1], ':id'=>$ids[0]]);
        $pdo->prepare('UPDATE tecnicos SET apoyo_id=:apoyo WHERE id=:id')->execute([':apoyo'=>$ids[1], ':id'=>$ids[0]]);
        if ($partnerId) $pdo->prepare('UPDATE tecnicos SET partner_id=:partner, fecha_actualizacion=NOW() WHERE id=:id AND NOT (partner_id <=> :partner2)')
            ->execute([':partner'=>$partnerId, ':partner2'=>$partnerId, ':id'=>$ids[1]]);
    }
    // Zona de la cuadrilla (2026-10-24): el T2 rota, queda en el departamento y provincia de su última supervisión cerrada;
    // el T1 la toma solo si no tenía (su zona la administra Cuadrillas).
    // 2026-10-29: un técnico sin zona toma la de la supervisión desde que se guarda (aún en proceso), no solo al cerrarla.
    if ($ids[0] || $ids[1]) {
        $zona = $pdo->prepare('SELECT departamento, provincia FROM supervisiones WHERE id = :id');
        $zona->execute([':id' => $supervisionId]);
        $zona = $zona->fetch(PDO::FETCH_ASSOC) ?: [];
        $dep = trim((string)($zona['departamento'] ?? '')); $prov = trim((string)($zona['provincia'] ?? '')) ?: null;
        $sinZona = $actualizarCuadrilla ? '' : " AND COALESCE(departamento,'')=''";
        if ($dep !== '' && $ids[1]) $pdo->prepare("UPDATE tecnicos SET departamento=:dep, provincia=:prov WHERE id=:id{$sinZona}")->execute([':dep'=>$dep, ':prov'=>$prov, ':id'=>$ids[1]]);
        if ($dep !== '' && $ids[0]) $pdo->prepare("UPDATE tecnicos SET departamento=:dep, provincia=:prov WHERE id=:id AND COALESCE(departamento,'')=''")->execute([':dep'=>$dep, ':prov'=>$prov, ':id'=>$ids[0]]);
    }
    $pdo->prepare('UPDATE supervisiones SET tecnico1_id=:t1,tecnico2_id=:t2,vehiculo_id=:vehiculo WHERE id=:id')
        ->execute([':t1'=>$ids[0], ':t2'=>$ids[1], ':vehiculo'=>$vehiculoId, ':id'=>$supervisionId]);
}

function estadoOrdenPresencial(string $modalidad, string $tipo, ?string $estado, bool $finalizar): ?string {
    return null;
}
