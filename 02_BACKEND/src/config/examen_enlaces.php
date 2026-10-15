<?php
// 02_BACKEND/src/config/examen_enlaces.php - Links de examen para los técnicos (2026-10-15).
// Un link es único, lleva el nombre del técnico (solo para que se reconozca al enviarlo) y un código aleatorio de 48
// caracteres. En la base solo se guarda la huella SHA-256 del código: quien vea la tabla no puede armar links.
// Estados: activo (sin abrir) → abierto → usado (examen rendido); o vencido / anulado. Uno activo por técnico:
// al generar otro, el anterior se anula.
require_once __DIR__ . '/tecnicos_lideres.php'; // sgsPersonaLider

const SGS_EXAMEN_VIGENCIAS = [2 => '2 horas', 24 => '24 horas', 72 => '3 días'];

/** Nombre de la persona sin la nomenclatura ("P 4 DOMINION SGI JUAN PEREZ" → "JUAN PEREZ"); los T2 no la tienen. */
function sgsNombrePersonaTecnico(string $nombre, int $tipo): string {
    return $tipo === 1 ? sgsPersonaLider($nombre) : normalizeImportText($nombre);
}

/** "JUAN PÉREZ" → "juan-perez" (para el link). */
function sgsSlugNombre(string $nombre): string {
    $t = strtolower(normalizeImportText($nombre));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-') ?: 'tecnico';
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

/** Crea el link (anula el activo anterior del técnico). Devuelve ['id', 'codigo', 'vence_en']; el código solo existe aquí. */
function sgsGenerarEnlaceExamen(PDO $pdo, int $tecnicoId, int $horas, string $titulo, int $actorId): array {
    if (!isset(SGS_EXAMEN_VIGENCIAS[$horas])) $horas = 24;
    $codigo = bin2hex(random_bytes(24));
    $vence = date('Y-m-d H:i:s', time() + $horas * 3600);
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE examen_enlaces SET anulado_en = NOW(), anulado_por = :u
                WHERE tecnico_id = :t AND usado_en IS NULL AND anulado_en IS NULL AND vence_en > NOW()')
            ->execute([':u' => $actorId ?: null, ':t' => $tecnicoId]);
        $pdo->prepare('INSERT INTO examen_enlaces (tecnico_id, token_hash, titulo, generado_por, creado_en, vence_en) VALUES (:t, :h, :ti, :u, NOW(), :v)')
            ->execute([':t' => $tecnicoId, ':h' => hash('sha256', $codigo), ':ti' => $titulo, ':u' => $actorId ?: null, ':v' => $vence]);
        $id = (int)$pdo->lastInsertId();
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['id' => $id, 'codigo' => $codigo, 'vence_en' => $vence];
}

/** Link con el código, o null si el código no existe. Incluye el técnico. */
function sgsBuscarEnlaceExamen(PDO $pdo, string $codigo): ?array {
    if (!sgsCodigoExamenValido($codigo)) return null;
    $st = $pdo->prepare('SELECT e.*, t.nombre_tecnico, COALESCE(t.tipo_tecnico, 1) AS tipo_tecnico, p.nombre_partner
        FROM examen_enlaces e JOIN tecnicos t ON t.id = e.tecnico_id LEFT JOIN partners p ON p.id = t.partner_id WHERE e.token_hash = :h LIMIT 1');
    $st->execute([':h' => hash('sha256', $codigo)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Historial de links de un técnico (más recientes primero). */
function sgsHistorialEnlaces(PDO $pdo, int $tecnicoId): array {
    $st = $pdo->prepare("SELECT e.id, e.titulo, e.creado_en, e.vence_en, e.abierto_en, e.usado_en, e.anulado_en,
            TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS generado_por_nombre
        FROM examen_enlaces e LEFT JOIN usuarios u ON u.id = e.generado_por WHERE e.tecnico_id = :t ORDER BY e.creado_en DESC, e.id DESC LIMIT 30");
    $st->execute([':t' => $tecnicoId]);
    return array_map(static fn($e) => $e + ['estado' => sgsEstadoEnlace($e)], $st->fetchAll(PDO::FETCH_ASSOC));
}
