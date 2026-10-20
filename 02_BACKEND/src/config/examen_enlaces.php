<?php
// 02_BACKEND/src/config/examen_enlaces.php - Links de examen para los técnicos.
// El código del link es aleatorio (48 caracteres); en la base solo se guarda su huella SHA-256: quien vea la tabla no puede
// armar links. Link individual (2026-10-15, examen_enlaces): ya no se generan; los enviados siguen abriendo hasta vencer
// (estados activo → abierto → usado, o vencido / anulado). Link general (2026-10-20): ver más abajo.
require_once __DIR__ . '/tecnicos_lideres.php'; // sgsPersonaLider

const SGS_EXAMEN_VIGENCIAS = [2 => '2 horas', 24 => '24 horas', 72 => '3 días'];

/** Nombre de la persona sin la nomenclatura ("P 4 DOMINION SGI JUAN PEREZ" → "JUAN PEREZ"); los T2 no la tienen. */
function sgsNombrePersonaTecnico(string $nombre, int $tipo): string {
    return $tipo === 1 ? sgsPersonaLider($nombre) : normalizeImportText($nombre);
}

function sgsCodigoExamenValido(string $codigo): bool {
    return (bool)preg_match('/^[a-f0-9]{48}$/', $codigo);
}

function sgsEstadoEnlace(array $e, ?int $ahora = null): string {
    $ahora ??= time();
    if (!empty($e['usado_en'])) return 'usado';
    if (!empty($e['anulado_en'])) return 'anulado';
    if (strtotime((string)$e['vence_en']) <= $ahora) return 'vencido';
    return !empty($e['abierto_en']) ? 'abierto' : 'activo';
}

/** Dirección del sistema vista por el navegador ("https://dominio/ProviWIN"), desde cualquier archivo de api/ o la raíz. */
function sgsUrlSistema(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $ruta = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if (basename($ruta) === 'api') $ruta = dirname($ruta);
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($ruta, '/');
}

/** Link con el código, o null si el código no existe. Incluye el técnico. */
function sgsBuscarEnlaceExamen(PDO $pdo, string $codigo): ?array {
    if (!sgsCodigoExamenValido($codigo)) return null;
    $st = $pdo->prepare('SELECT e.*, t.nombre_tecnico, COALESCE(t.tipo_tecnico, 1) AS tipo_tecnico, p.nombre_partner
        FROM examen_enlaces e JOIN tecnicos t ON t.id = e.tecnico_id LEFT JOIN partners p ON p.id = t.partner_id WHERE e.token_hash = :h LIMIT 1');
    $st->execute([':h' => hash('sha256', $codigo)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}


// ---------------------------------------------------------------------------------------------------------------------
// Link GENERAL de examen (2026-10-20): un link para un grupo de técnicos (T1 y T2 de las cuadrillas filtradas en
// Cuadrillas). Cada técnico entra con su DNI; solo los del grupo y una vez cada uno. Tablas examen_convocatorias y
// examen_convocatoria_tecnicos. Solo se genera si todos tienen DNI. Reemplaza al link individual (examen_enlaces).
// ---------------------------------------------------------------------------------------------------------------------
const SGS_EXAMEN_INTENTOS_IP = 5;      // DNI equivocados por equipo y link antes de bloquear
const SGS_EXAMEN_INTENTOS_LINK = 40;   // DNI equivocados en total por link (protege si cambian de red)
const SGS_EXAMEN_BLOQUEO_MIN = 15;

function sgsEstadoConvocatoria(array $c, ?int $ahora = null): string {
    $ahora ??= time();
    if (!empty($c['anulado_en'])) return 'anulado';
    return strtotime((string)$c['vence_en']) <= $ahora ? 'vencido' : 'activo';
}

/**
 * Participantes del link a partir de los técnicos líderes elegidos: cada líder (T1) y su técnico de apoyo (T2).
 * Los de lista negra no rinden examen. $visible(fila) decide la zona del usuario. Devuelve [tecnicos, sin_dni, excluidos].
 */
function sgsParticipantesExamen(PDO $pdo, array $liderIds, callable $visible): array {
    $liderIds = array_values(array_unique(array_filter(array_map('intval', $liderIds))));
    if (!$liderIds) throw new Exception('No hay cuadrillas en el filtro.');
    if (count($liderIds) > 1000) throw new Exception('Demasiadas cuadrillas: filtre por partner o zona.');
    $ph = implode(',', array_fill(0, count($liderIds), '?'));
    $st = $pdo->prepare("SELECT t.id, t.nombre_tecnico, t.dni, t.estado_operativo, t.departamento, t.apoyo_id,
            a.id AS a_id, a.nombre_tecnico AS a_nombre, a.dni AS a_dni, a.estado_operativo AS a_estado, p.nombre_partner
        FROM tecnicos t LEFT JOIN tecnicos a ON a.id = t.apoyo_id LEFT JOIN partners p ON p.id = t.partner_id
        WHERE t.id IN ($ph) AND COALESCE(t.tipo_tecnico, 1) = 1 ORDER BY p.nombre_partner, t.nombre_tecnico");
    $st->execute($liderIds);
    $tecnicos = []; $sinDni = []; $excluidos = []; $vistos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (!$visible($f)) continue;
        $personas = [[(int)$f['id'], 1, $f['nombre_tecnico'], $f['dni'], $f['estado_operativo']]];
        if ($f['a_id']) $personas[] = [(int)$f['a_id'], 2, $f['a_nombre'], $f['a_dni'], $f['a_estado']];
        foreach ($personas as [$id, $tipo, $nombre, $dni, $estado]) {
            if (isset($vistos[$id])) continue;
            $vistos[$id] = true;
            $dato = ['id' => $id, 'tipo' => $tipo, 'nombre' => $nombre, 'persona' => sgsNombrePersonaTecnico((string)$nombre, $tipo),
                'cuadrilla' => $f['nombre_tecnico'], 'lider_id' => (int)$f['id'], 'partner' => $f['nombre_partner']];
            if ($estado === 'Blacklist') { $excluidos[] = $dato + ['motivo' => 'Lista negra']; continue; }
            if (!preg_match('/^\d{8}$/', trim((string)$dni))) $sinDni[] = $dato;
            $tecnicos[] = $dato;
        }
    }
    if (!$tecnicos) throw new Exception('No hay técnicos para el examen en este filtro.');
    return [$tecnicos, $sinDni, $excluidos];
}

/** Crea el link general. Devuelve ['id', 'codigo', 'vence_en']; el código solo existe aquí. */
function sgsGenerarConvocatoria(PDO $pdo, array $tecnicos, int $horas, string $titulo, string $filtro, int $actorId, string $examen): array {
    if (!isset(SGS_EXAMEN_VIGENCIAS[$horas])) $horas = 24;
    $codigo = bin2hex(random_bytes(24));
    $vence = date('Y-m-d H:i:s', time() + $horas * 3600);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO examen_convocatorias (token_hash, titulo, examen, filtro, generado_por, creado_en, vence_en) VALUES (:h, :ti, :ex, :f, :u, NOW(), :v)')
            ->execute([':h' => hash('sha256', $codigo), ':ti' => $titulo, ':ex' => $examen, ':f' => mb_substr($filtro, 0, 255) ?: null, ':u' => $actorId ?: null, ':v' => $vence]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO examen_convocatoria_tecnicos (convocatoria_id, tecnico_id, tipo) VALUES (:c, :t, :tipo)');
        foreach ($tecnicos as $t) $ins->execute([':c' => $id, ':t' => (int)$t['id'], ':tipo' => (int)$t['tipo']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['id' => $id, 'codigo' => $codigo, 'vence_en' => $vence];
}

function sgsBuscarConvocatoria(PDO $pdo, string $codigo): ?array {
    if (!sgsCodigoExamenValido($codigo)) return null;
    $st = $pdo->prepare('SELECT * FROM examen_convocatorias WHERE token_hash = :h LIMIT 1');
    $st->execute([':h' => hash('sha256', $codigo)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Técnico del link con ese DNI, o null. */
function sgsTecnicoConvocatoriaPorDni(PDO $pdo, int $convocatoriaId, string $dni): ?array {
    if (!preg_match('/^\d{8}$/', $dni)) return null;
    $st = $pdo->prepare('SELECT ct.id AS participacion_id, ct.tipo, ct.ingreso_en, ct.usado_en, t.id, t.nombre_tecnico, p.nombre_partner
        FROM examen_convocatoria_tecnicos ct JOIN tecnicos t ON t.id = ct.tecnico_id LEFT JOIN partners p ON p.id = t.partner_id
        WHERE ct.convocatoria_id = :c AND t.dni = :d LIMIT 1');
    $st->execute([':c' => $convocatoriaId, ':d' => $dni]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** IP del técnico (detrás de Cloudflare, la del visitante). Solo para limitar intentos. */
function sgsIpVisitante(): string {
    $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'desconocida';
}

/** Claves de intentos (por equipo y por link) en la tabla login_attempts. */
function sgsClavesIntentosExamen(int $convocatoriaId): array {
    return [hash('sha256', "sgs-examen|{$convocatoriaId}|" . sgsIpVisitante()), hash('sha256', "sgs-examen|{$convocatoriaId}")];
}

function sgsExamenBloqueado(PDO $pdo, int $convocatoriaId): bool {
    [$ip, $link] = sgsClavesIntentosExamen($convocatoriaId);
    $st = $pdo->prepare('SELECT 1 FROM login_attempts WHERE fingerprint IN (:a, :b) AND blocked_until > NOW() LIMIT 1');
    $st->execute([':a' => $ip, ':b' => $link]);
    return (bool)$st->fetchColumn();
}

/** Suma un DNI equivocado; bloquea por SGS_EXAMEN_BLOQUEO_MIN minutos al pasar el límite (la cuenta se reinicia cada 15 min). */
function sgsRegistrarIntentoExamen(PDO $pdo, int $convocatoriaId): void {
    $min = SGS_EXAMEN_BLOQUEO_MIN;
    foreach (array_combine(sgsClavesIntentosExamen($convocatoriaId), [SGS_EXAMEN_INTENTOS_IP, SGS_EXAMEN_INTENTOS_LINK]) as $clave => $limite) {
        $pdo->prepare("INSERT INTO login_attempts (fingerprint, attempts, first_attempt_at, last_attempt_at, blocked_until) VALUES (:f, 1, NOW(), NOW(), NULL)
            ON DUPLICATE KEY UPDATE attempts = IF(first_attempt_at < NOW() - INTERVAL {$min} MINUTE, 1, attempts + 1),
                first_attempt_at = IF(first_attempt_at < NOW() - INTERVAL {$min} MINUTE, NOW(), first_attempt_at), last_attempt_at = NOW()")
            ->execute([':f' => $clave]);
        $pdo->prepare("UPDATE login_attempts SET blocked_until = NOW() + INTERVAL {$min} MINUTE WHERE fingerprint = :f AND attempts >= :l")
            ->execute([':f' => $clave, ':l' => $limite]);
    }
}

function sgsLimpiarIntentosExamen(PDO $pdo, int $convocatoriaId): void {
    $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => sgsClavesIntentosExamen($convocatoriaId)[0]]);
}

/** Links generales (más recientes primero) con su avance. $generadoPor null = todos (administración). */
function sgsListarConvocatorias(PDO $pdo, ?int $generadoPor): array {
    $where = $generadoPor ? 'WHERE c.generado_por = :u' : '';
    $st = $pdo->prepare("SELECT c.id, c.titulo, c.filtro, c.creado_en, c.vence_en, c.anulado_en, c.generado_por,
            TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS generado_por_nombre,
            COUNT(ct.id) AS total, COALESCE(SUM(ct.ingreso_en IS NOT NULL), 0) AS ingresaron, COALESCE(SUM(ct.usado_en IS NOT NULL), 0) AS rindieron,
            COALESCE(SUM(ct.aprobado = 1), 0) AS aprobados
        FROM examen_convocatorias c LEFT JOIN usuarios u ON u.id = c.generado_por
        LEFT JOIN examen_convocatoria_tecnicos ct ON ct.convocatoria_id = c.id
        {$where} GROUP BY c.id ORDER BY c.creado_en DESC, c.id DESC LIMIT 30");
    $st->execute($generadoPor ? [':u' => $generadoPor] : []);
    return array_map(static fn($c) => $c + ['estado' => sgsEstadoConvocatoria($c)], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Técnicos de un link general con su ingreso. */
function sgsTecnicosConvocatoria(PDO $pdo, int $convocatoriaId): array {
    $st = $pdo->prepare('SELECT ct.tipo, ct.ingreso_en, ct.inicio_en, ct.usado_en, ct.nota, ct.aprobado, t.nombre_tecnico, t.dni, p.nombre_partner
        FROM examen_convocatoria_tecnicos ct JOIN tecnicos t ON t.id = ct.tecnico_id LEFT JOIN partners p ON p.id = t.partner_id
        WHERE ct.convocatoria_id = :c ORDER BY ct.ingreso_en IS NULL, p.nombre_partner, t.nombre_tecnico');
    $st->execute([':c' => $convocatoriaId]);
    return array_map(static fn($t) => $t + ['persona' => sgsNombrePersonaTecnico((string)$t['nombre_tecnico'], (int)$t['tipo'])], $st->fetchAll(PDO::FETCH_ASSOC));
}
