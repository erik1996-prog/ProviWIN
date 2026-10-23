<?php
// 02_BACKEND/src/routes/api/examenes.php - Exámenes de los técnicos (2026-10-20). Administración y supervisores directos
// (los mismos que ven Cuadrillas); el supervisor, solo con técnicos de su zona (o que aún no tienen zona).
// Link general: un link para los técnicos (T1 y T2) de las cuadrillas filtradas en Cuadrillas; cada uno entra con su DNI
// (examen.php?g=…). Solo se genera si todos tienen DNI. Lógica en config/examen_enlaces.php y config/examen_motor.php.
//   POST ?action=grupo_preparar  → lider_ids[] → cuántos técnicos entran, quiénes no tienen DNI y exámenes disponibles
//   POST ?action=grupo_generar   → lider_ids[], examen, horas, filtro → link para enviar (el código se muestra una sola vez)
//   GET  ?action=grupos          → links generados (los del usuario; la administración ve todos)
//   GET  ?action=grupo&id=N      → técnicos del link con su resultado
//   POST ?action=grupo_anular    → id del link
// Dashboard (pantalla Exámenes). Filtros: desde, hasta (fecha del link), partner_id, zona, examen.
//   GET  ?action=dashboard        → indicadores, aprobación por partner y zona, notas, temas más fallados y por día
//   GET  ?action=dashboard_tabla  → resultados por técnico, de 50 en 50 (estado, q, pagina)
//   GET  ?action=dashboard_excel  → los mismos resultados, todos, en Excel
// Los links individuales (2026-10-15, examen_enlaces) ya no se generan; los enviados siguen abriendo hasta vencer.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/examen_motor.php';
require_once __DIR__ . '/../../config/dashboard.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!Auth::puede(Auth::PERM_EXAMENES_VIEW, $usuario)) $responder(['status' => 'error', 'message' => 'Solo el personal administrativo y los supervisores directos ven los exámenes.'], 403);
$pdo = Database::getConnection();
$action = $_GET['action'] ?? '';
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$generadoPor = $esAdmin ? null : (int)$usuario['id']; // historial: el supervisor ve los suyos

try {
    // ---------- Link general ----------
    $visibleZona = static fn(array $f): bool => $esAdmin || trim((string)($f['departamento'] ?? '')) === '' || sgsUserInZone((int)$usuario['id'], $f['departamento']);
    $lideres = static fn(): array => array_map('intval', (array)($_POST['lider_ids'] ?? []));
    $convocatoria = static function (int $id) use ($pdo, $generadoPor): array {
        $st = $pdo->prepare('SELECT * FROM examen_convocatorias WHERE id = :id');
        $st->execute([':id' => $id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new Exception('El link ya no existe.');
        if ($generadoPor && (int)$c['generado_por'] !== $generadoPor) throw new Exception('Solo quien generó el link (o la administración) puede verlo.');
        return $c;
    };

    if ($esPost && in_array($action, ['grupo_preparar', 'grupo_generar'], true) && !Auth::puede(Auth::PERM_EXAMENES_EDIT, $usuario)) $responder(['status' => 'error', 'message' => 'No tiene permiso para generar links de examen.'], 403);

    if ($esPost && $action === 'grupo_preparar') {
        [$tec, $sinDni, $excluidos] = sgsParticipantesExamen($pdo, $lideres(), $visibleZona);
        $responder(['status' => 'success', 'data' => [
            'cuadrillas' => count(array_unique(array_column($tec, 'lider_id'))), 'total' => count($tec),
            't1' => count(array_filter($tec, static fn($t) => $t['tipo'] === 1)), 't2' => count(array_filter($tec, static fn($t) => $t['tipo'] === 2)),
            'sin_dni' => $sinDni, 'excluidos' => $excluidos,
            'examenes' => array_values(array_map(static fn($b) => ['codigo' => $b['codigo'], 'titulo' => $b['titulo'], 'preguntas' => $b['preguntas_por_examen'],
                'minutos' => $b['minutos'], 'aprueba' => $b['aprueba']], sgsExamenesDisponibles())),
        ]]);
    }

    if ($esPost && $action === 'grupo_generar') {
        [$tec, $sinDni] = sgsParticipantesExamen($pdo, $lideres(), $visibleZona);
        if ($sinDni) {
            $responder(['status' => 'error', 'sin_dni' => $sinDni, 'message' => 'No se generó el link: ' . count($sinDni)
                . (count($sinDni) === 1 ? ' técnico no tiene DNI' : ' técnicos no tienen DNI') . '. Regístrelo en "Editar cuadrilla" y vuelva a intentar.'], 422);
        }
        // El banco de preguntas elegido define el título, las preguntas, el tiempo y la nota mínima.
        $banco = sgsExamenBanco((string)($_POST['examen'] ?? ''));
        if (!$banco) throw new Exception('Elija el examen de la lista.');
        $horas = (int)($_POST['horas'] ?? 24);
        $filtro = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['filtro'] ?? ''))), 0, 255);
        $c = sgsGenerarConvocatoria($pdo, $tec, $horas, $banco['titulo'], $filtro, (int)$usuario['id'], $banco['codigo']);
        $url = sgsUrlSistema() . '/examen.php?g=' . $c['codigo'];
        $vence = date('d/m/Y H:i', strtotime($c['vence_en']));
        $mensaje = "Hola, este es el link para rendir \"{$banco['titulo']}\" de WIN:\n{$url}\n\nIngresa con tu DNI. Son {$banco['preguntas_por_examen']} preguntas en {$banco['minutos']} minutos; tienes una sola oportunidad. Vence el {$vence}.";
        win_log_error('audit_exam_group_link_created', 'Link general de examen generado', ['actor_id' => (int)$usuario['id'], 'convocatoria_id' => $c['id'], 'tecnicos' => count($tec), 'horas' => $horas]);
        $responder(['status' => 'success', 'message' => 'Link generado.', 'data' => [
            'id' => $c['id'], 'url' => $url, 'mensaje' => $mensaje, 'vence' => $vence, 'total' => count($tec), 'historial' => sgsListarConvocatorias($pdo, $generadoPor),
        ]]);
    }

    if ($action === 'grupos') $responder(['status' => 'success', 'data' => sgsListarConvocatorias($pdo, $generadoPor)]);

    if ($action === 'grupo') {
        $c = $convocatoria((int)($_GET['id'] ?? 0));
        $responder(['status' => 'success', 'data' => ['titulo' => $c['titulo'], 'estado' => sgsEstadoConvocatoria($c), 'tecnicos' => sgsTecnicosConvocatoria($pdo, (int)$c['id'])]]);
    }

    if ($esPost && $action === 'grupo_anular') {
        if (!Auth::puede(Auth::PERM_EXAMENES_DELETE, $usuario)) $responder(['status' => 'error', 'message' => 'No tiene permiso para anular links de examen.'], 403);
        $c = $convocatoria((int)($_POST['id'] ?? 0));
        if (sgsEstadoConvocatoria($c) !== 'activo') throw new Exception('Ese link ya no está vigente.');
        $pdo->prepare('UPDATE examen_convocatorias SET anulado_en = NOW(), anulado_por = :u WHERE id = :id AND anulado_en IS NULL')
            ->execute([':u' => (int)$usuario['id'], ':id' => (int)$c['id']]);
        win_log_error('audit_exam_group_link_revoked', 'Link general de examen anulado', ['actor_id' => (int)$usuario['id'], 'convocatoria_id' => (int)$c['id']]);
        $responder(['status' => 'success', 'message' => 'Link anulado: ya nadie puede entrar con él.', 'data' => sgsListarConvocatorias($pdo, $generadoPor)]);
    }

    // ---------- Dashboard (piezas comunes de config/dashboard.php) ----------
    if (in_array($action, ['dashboard', 'dashboard_tabla', 'dashboard_excel'], true)) {
        $dep = "COALESCE(NULLIF(t.departamento, ''), l.departamento)"; // el T2 sin zona toma la de su líder
        [$where, $params] = sgsFiltrosDashboard(['fecha' => 'c.creado_en', 'partner' => 't.partner_id', 'zona' => $dep], $usuario, $esAdmin);
        if (($ex = (string)($_GET['examen'] ?? '')) !== '' && sgsExamenBanco($ex)) { $where[] = 'c.examen = :examen'; $params[':examen'] = $ex; }
        $consulta = sgsConsultaDashboard($pdo, "FROM examen_convocatoria_tecnicos ct JOIN examen_convocatorias c ON c.id = ct.convocatoria_id
            JOIN tecnicos t ON t.id = ct.tecnico_id LEFT JOIN tecnicos l ON l.apoyo_id = t.id AND COALESCE(l.tipo_tecnico, 1) = 1
            LEFT JOIN partners p ON p.id = t.partner_id", $where, $params);
        $estadoSql = "CASE WHEN ct.usado_en IS NOT NULL THEN IF(ct.aprobado = 1, 'aprobado', 'desaprobado') WHEN c.anulado_en IS NOT NULL THEN 'anulado'
            WHEN c.vence_en <= NOW() THEN 'no_rindio' WHEN ct.inicio_en IS NOT NULL THEN 'rindiendo' ELSE 'pendiente' END";
        $etiquetas = ['aprobado' => 'Aprobado', 'desaprobado' => 'No aprobado', 'pendiente' => 'Pendiente', 'rindiendo' => 'Rindiendo', 'no_rindio' => 'No rindió', 'anulado' => 'Link anulado'];
        $grupos = ['aprobado' => ['aprobado'], 'desaprobado' => ['desaprobado'], 'por_rendir' => ['pendiente', 'rindiendo'], 'no_rindio' => ['no_rindio']];

        if ($action === 'dashboard') {
            sgsCerrarExamenesVencidos($pdo); // califica los que dejaron el examen abierto y ya se les acabó el tiempo
            // GROUP BY por posición: "estado" también es una columna de partners y la base agruparía por esa.
            $cuentas = array_column($consulta("{$estadoSql} AS estado, COUNT(*) AS n", 'GROUP BY 1'), 'n', 'estado');
            $k = $consulta('COUNT(*) AS convocados, COALESCE(SUM(ct.usado_en IS NOT NULL), 0) AS rindieron, COALESCE(SUM(ct.aprobado = 1), 0) AS aprobados,
                ROUND(AVG(ct.nota)) AS nota_promedio, COALESCE(SUM(ct.nota < 50), 0) AS n1, COALESCE(SUM(ct.nota >= 50 AND ct.nota < 70), 0) AS n2,
                COALESCE(SUM(ct.nota >= 70 AND ct.nota < 90), 0) AS n3, COALESCE(SUM(ct.nota >= 90), 0) AS n4')[0];
            $aprobacion = static fn(string $expr): array => array_map(static fn($x) => ['nombre' => $x['nombre'], 'rindieron' => (int)$x['rindieron'],
                'aprobados' => (int)$x['aprobados'], 'pct' => sgsPorcentaje($x['aprobados'], $x['rindieron'])],
                $consulta("{$expr} AS nombre, COUNT(*) AS rindieron, SUM(ct.aprobado = 1) AS aprobados", 'AND ct.usado_en IS NOT NULL GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT 12'));
            $suma = static fn(array $estados): int => array_sum(array_map(static fn($e) => (int)($cuentas[$e] ?? 0), $estados));
            $rindieron = (int)$k['rindieron'];
            $responder(['status' => 'success', 'data' => [
                'kpis' => ['convocados' => (int)$k['convocados'], 'rindieron' => $rindieron, 'aprobados' => (int)$k['aprobados'],
                    'pct_aprobacion' => $rindieron ? sgsPorcentaje($k['aprobados'], $rindieron) : null,
                    'nota_promedio' => $k['nota_promedio'] !== null ? (int)$k['nota_promedio'] : null,
                    'por_rendir' => $suma($grupos['por_rendir']), 'no_rindieron' => $suma($grupos['no_rindio'])],
                'cuentas' => ['' => (int)$k['convocados']] + array_map($suma, $grupos),
                'por_partner' => $aprobacion("COALESCE(p.nombre_partner, 'Sin partner')"),
                'por_zona' => $aprobacion("COALESCE(NULLIF({$dep}, ''), 'Sin zona')"),
                'notas' => [['nombre' => '0 a 49 %', 'cantidad' => (int)$k['n1']], ['nombre' => '50 a 69 %', 'cantidad' => (int)$k['n2']],
                    ['nombre' => '70 a 89 %', 'cantidad' => (int)$k['n3']], ['nombre' => '90 a 100 %', 'cantidad' => (int)$k['n4']]],
                'temas' => array_map(static fn($x) => ['nombre' => $x['tema'], 'total' => (int)$x['total'], 'falladas' => (int)$x['falladas'], 'pct' => sgsPorcentaje($x['falladas'], $x['total'])],
                    $consulta('r.tema, COUNT(*) AS total, SUM(r.correcta = 0) AS falladas', 'GROUP BY r.tema ORDER BY SUM(r.correcta = 0) / COUNT(*) DESC LIMIT 12', [],
                        'JOIN examen_respuestas r ON r.participacion_id = ct.id')),
                'por_dia' => array_reverse(array_map(static fn($x) => ['fecha' => $x['fecha'], 'cantidad' => (int)$x['cantidad']],
                    $consulta('DATE(ct.usado_en) AS fecha, COUNT(*) AS cantidad', 'AND ct.usado_en IS NOT NULL GROUP BY 1 ORDER BY 1 DESC LIMIT 31'))),
                'etiquetas' => $etiquetas,
            ]]);
        }

        // Tabla (por páginas) y Excel (todo): mismo filtro de resultado y búsqueda.
        [$extra, $mas] = sgsFiltroTablaDashboard($estadoSql, $grupos, ['t.nombre_tecnico', 't.dni', 'p.nombre_partner']);
        $columnas = "ct.tipo, ct.usado_en, ct.nota, ct.correctas, ct.total_preguntas, ct.segundos, t.nombre_tecnico, t.dni, p.nombre_partner, {$dep} AS zona,
            c.titulo, c.creado_en, {$estadoSql} AS estado";
        $orden = 'COALESCE(ct.usado_en, c.creado_en) DESC, ct.id DESC';
        $fila = static fn(array $x): array => ['tecnico' => sgsNombrePersonaTecnico((string)$x['nombre_tecnico'], (int)$x['tipo']), 'nombre_tecnico' => $x['nombre_tecnico'],
            'tipo' => (int)$x['tipo'], 'dni' => $x['dni'], 'partner' => $x['nombre_partner'] ?: 'Sin partner', 'zona' => $x['zona'] ?: 'Sin zona',
            'examen' => $x['titulo'], 'link' => $x['creado_en'], 'rendido' => $x['usado_en'], 'nota' => $x['nota'] !== null ? (float)$x['nota'] : null,
            'correctas' => $x['correctas'] !== null ? (int)$x['correctas'] : null, 'total' => $x['total_preguntas'] !== null ? (int)$x['total_preguntas'] : null,
            'segundos' => $x['segundos'] !== null ? (int)$x['segundos'] : null, 'estado' => $x['estado']];

        if ($action === 'dashboard_tabla') {
            $responder(['status' => 'success', 'data' => sgsPaginaDashboard($consulta, $columnas, $extra, $mas, $orden, $fila) + ['etiquetas' => $etiquetas]]);
        }

        $hoja = [['TÉCNICO', 'TIPO', 'DNI', 'PARTNER', 'ZONA', 'EXAMEN', 'FECHA DEL LINK', 'FECHA RENDIDO', 'NOTA %', 'CORRECTAS', 'RESULTADO', 'TIEMPO']];
        foreach (array_map($fila, $consulta($columnas, "{$extra} ORDER BY {$orden} LIMIT 20000", $mas)) as $x) {
            $hoja[] = [$x['nombre_tecnico'], $x['tipo'] === 1 ? 'T1' : 'T2', $x['dni'] ?? '', $x['partner'], $x['zona'], $x['examen'],
                date('d/m/Y H:i', strtotime($x['link'])), $x['rendido'] ? date('d/m/Y H:i', strtotime($x['rendido'])) : '',
                $x['nota'] !== null ? (string)round($x['nota']) : '', $x['correctas'] !== null ? "{$x['correctas']} de {$x['total']}" : '',
                $etiquetas[$x['estado']], $x['segundos'] !== null ? sprintf('%d:%02d', intdiv($x['segundos'], 60), $x['segundos'] % 60) : ''];
        }
        sgsEnviarXlsx($hoja, 'Exámenes', 'examenes');
    }

    $responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
} catch (Exception $e) {
    $responder(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    win_log_error('exam_links_error', $e->getMessage(), ['action' => $action]);
    $responder(['status' => 'error', 'message' => 'No se pudo completar la acción.', 'code' => function_exists('sgs_error_code') ? sgs_error_code() : null], 500);
}
