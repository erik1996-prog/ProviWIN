<?php
// Se ejecuta dentro de la transacción de la supervisión, después de autorizarla.
function guardarPersonalSupervision(PDO $pdo, int $supervisionId, ?int $partnerId, array $tecnicos, array $vehiculo): void {
    $ids = [null, null];
    foreach ($tecnicos as $index => $tecnico) {
        $nombre = trim((string)($tecnico['nombre'] ?? ''));
        if ($nombre === '') continue;
        $tipo = $index + 1;
        $dni = trim((string)($tecnico['dni'] ?? ''));
        if (!preg_match('/^\d{8}$/', $dni)) throw new RuntimeException("Ingrese un DNI válido para el técnico {$tipo}.");
        // T1 = técnico líder; T2 = técnico de apoyo (grupo distinto, sin nomenclatura, rota entre cuadrillas). Ambos del mismo partner.
        $stmt = $pdo->prepare('SELECT id,dni,usuario_id FROM tecnicos WHERE nombre_tecnico=:nombre AND COALESCE(tipo_tecnico,1)=:tipo AND partner_id=:partner FOR UPDATE');
        $stmt->execute([':nombre'=>$nombre, ':tipo'=>$tipo, ':partner'=>$partnerId]);
        $maestro = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$maestro) {
            $otroTipo = $pdo->prepare('SELECT 1 FROM tecnicos WHERE nombre_tecnico=:nombre AND partner_id=:partner LIMIT 1');
            $otroTipo->execute([':nombre'=>$nombre, ':partner'=>$partnerId]);
            if ($otroTipo->fetchColumn()) {
                throw new RuntimeException($tipo === 1 ? 'El T1 debe ser un técnico líder; el elegido es técnico de apoyo.' : 'El T2 debe ser un técnico de apoyo; el elegido es técnico líder.');
            }
        }
        if (!$maestro || (!empty($tecnico['id']) && (int)$tecnico['id'] !== (int)$maestro['id'])) {
            throw new RuntimeException("El técnico {$tipo} no pertenece al partner seleccionado. Selecciónelo nuevamente.");
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
        $pdo->prepare('INSERT INTO vehiculos(placa,tipo,soat,revision_tecnica) VALUES(:placa,:tipo,:soat,:revision) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')
            ->execute([':placa'=>$placa, ':tipo'=>$vehiculo['tipo'] ?: null, ':soat'=>$vehiculo['soat'] ?: null, ':revision'=>$vehiculo['revision'] ?: null]);
        $query = $pdo->prepare('SELECT id FROM vehiculos WHERE placa=:placa');
        $query->execute([':placa'=>$placa]);
        $vehiculoId = (int)$query->fetchColumn();
        $pdo->prepare('UPDATE tecnicos SET vehiculo_id=:vehiculo WHERE id=:id')->execute([':vehiculo'=>$vehiculoId, ':id'=>$ids[0]]);
    }
    $pdo->prepare('UPDATE supervisiones SET tecnico1_id=:t1,tecnico2_id=:t2,vehiculo_id=:vehiculo WHERE id=:id')
        ->execute([':t1'=>$ids[0], ':t2'=>$ids[1], ':vehiculo'=>$vehiculoId, ':id'=>$supervisionId]);
}

function estadoOrdenPresencial(string $modalidad, string $tipo, ?string $estado, bool $finalizar): ?string {
    return null;
}
