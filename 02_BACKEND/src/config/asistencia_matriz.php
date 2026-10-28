<?php
// 02_BACKEND/src/config/asistencia_matriz.php - Matriz de asistencia mensual de los supervisores directos (2026-10-28).
// Una fila por supervisor y una columna por día, con su código. Se calcula con lo que ya registra el sistema, en este orden:
//   1. corrección del administrador (asistencia_ajustes)        5. inició turno ese día → A (asistencia)
//   2. desde su fecha de cese → C (cesado)                      6. domingo → D (descanso obligatorio)
//   3. antes de su alta en el sistema → vacío                   7. día pasado sin turno ni licencia → FT (falta)
//   4. licencia aprobada (Vacaciones y licencias) → su código   8. hoy o días futuros → vacío
// Son 4 consultas por mes (supervisores, turnos, licencias y correcciones), sin consultas por cada día ni por supervisor.
// La usan api/asistencia_matriz.php (pantalla, Excel y correcciones) y la prueba matriz_asistencia_test.

require_once __DIR__ . '/ausencias.php';

/** Código de la matriz → nombre (orden de la leyenda y de los totales). */
const SGS_ASISTENCIA_CODIGOS = [
    'A' => 'Asistencia', 'V' => 'Vacaciones', 'LSG' => 'Lic. sin goce de haber', 'LG' => 'Lic. con goce de haber',
    'DM' => 'Descanso médico', 'SUB' => 'Subsidio', 'LPF' => 'Licencia por fallecimiento', 'LP' => 'Licencia por paternidad',
    'D' => 'Descanso obligatorio', 'FT' => 'Día falta', 'C' => 'Cesado',
];

/** Tipo de Vacaciones y licencias → código. */
const SGS_ASISTENCIA_CODIGO_AUSENCIA = [
    'Vacaciones' => 'V', 'Licencia sin goce de haber' => 'LSG', 'Licencia con goce de haber' => 'LG', 'Descanso médico' => 'DM',
    'Subsidio' => 'SUB', 'Licencia por fallecimiento' => 'LPF', 'Licencia por paternidad' => 'LP',
];

/** Mes pedido (AAAA-MM) o el actual: [primer día, último día]. */
function sgsMesMatriz(string $mes): array {
    $ini = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) ? DateTimeImmutable::createFromFormat('!Y-m-d', "$mes-01") : new DateTimeImmutable('first day of this month');
    return [$ini->format('Y-m-d'), $ini->modify('last day of this month')->format('Y-m-d')];
}

/**
 * Supervisores directos que ve $viewer (los de su equipo si es un líder), activos o cesados en el mes o después.
 * $zona filtra por zona de trabajo ('' = todas) y $supervisorId por uno (0 = todos).
 */
function sgsSupervisoresMatriz(PDO $pdo, array $viewer, string $desde, string $zona, int $supervisorId): array {
    $st = $pdo->prepare("SELECT u.id, u.nombre_completo, u.dni, u.fecha_creacion, u.fecha_cese FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id
        WHERE u.rol = 'Supervisor' AND " . sgsDirectStaffSql() . " AND (u.activo = 1 OR u.fecha_cese >= :desde) ORDER BY u.nombre_completo");
    $st->execute([':desde' => $desde]);
    $lista = sgsSupervisoresVisibles($viewer, $st->fetchAll(PDO::FETCH_ASSOC));
    $datos = sgsPrecargarUsuarios(array_column($lista, 'id'));
    return array_values(array_filter($lista, static fn($s) => ($supervisorId === 0 || (int)$s['id'] === $supervisorId)
        && ($zona === '' || ($datos[(int)$s['id']]['zonas'] ?? null) === null || in_array($zona, $datos[(int)$s['id']]['zonas'], true))));
}

/** Matriz del mes: ['dias' => [[fecha, dia, semana]], 'filas' => [[id, nombre, dni, celdas[fecha => [codigo, ajuste]], totales]]]. */
function sgsMatrizAsistencia(PDO $pdo, array $viewer, string $mes, string $zona = '', int $supervisorId = 0): array {
    [$desde, $hasta] = sgsMesMatriz($mes);
    $hoy = date('Y-m-d');
    $semana = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
    $dias = [];
    for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
        $dias[] = ['fecha' => $d->format('Y-m-d'), 'dia' => (int)$d->format('j'), 'semana' => $semana[(int)$d->format('w')]];
    }
    $supervisores = sgsSupervisoresMatriz($pdo, $viewer, $desde, $zona, $supervisorId);
    $ids = array_map('intval', array_column($supervisores, 'id'));
    $turnos = $licencias = $ajustes = [];
    if ($ids) {
        $in = implode(',', $ids); // enteros
        $rango = [':d' => $desde, ':h' => $hasta];
        $st = $pdo->prepare("SELECT supervisor_id, fecha FROM asistencia_supervisores WHERE supervisor_id IN ($in) AND fecha BETWEEN :d AND :h");
        $st->execute($rango);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $turnos[(int)$t['supervisor_id']][$t['fecha']] = true;
        $st = $pdo->prepare("SELECT supervisor_id, tipo, fecha_inicio, fecha_fin FROM ausencias_supervisores
            WHERE supervisor_id IN ($in) AND estado = 'Aprobada' AND fecha_inicio <= :h AND fecha_fin >= :d");
        $st->execute($rango);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $licencias[(int)$a['supervisor_id']][] = $a;
        $st = $pdo->prepare("SELECT supervisor_id, fecha, codigo, comentario FROM asistencia_ajustes WHERE supervisor_id IN ($in) AND fecha BETWEEN :d AND :h");
        $st->execute($rango);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) $ajustes[(int)$j['supervisor_id']][$j['fecha']] = $j;
    }
    $filas = [];
    foreach ($supervisores as $s) {
        $id = (int)$s['id'];
        $alta = substr((string)$s['fecha_creacion'], 0, 10);
        $totales = array_fill_keys(array_keys(SGS_ASISTENCIA_CODIGOS), 0);
        $celdas = [];
        foreach ($dias as $d) {
            $f = $d['fecha'];
            $codigo = '';
            if (isset($ajustes[$id][$f])) $codigo = $ajustes[$id][$f]['codigo'];
            elseif ($s['fecha_cese'] && $f >= $s['fecha_cese']) $codigo = 'C';
            elseif ($alta && $f < $alta) $codigo = '';
            elseif ($lic = array_values(array_filter($licencias[$id] ?? [], static fn($a) => $a['fecha_inicio'] <= $f && $a['fecha_fin'] >= $f))) $codigo = SGS_ASISTENCIA_CODIGO_AUSENCIA[$lic[0]['tipo']] ?? '';
            elseif (isset($turnos[$id][$f])) $codigo = 'A';
            elseif ($d['semana'] === 'D') $codigo = 'D'; // domingo
            elseif ($f < $hoy) $codigo = 'FT';
            if ($codigo !== '') $totales[$codigo]++;
            $celdas[$f] = ['codigo' => $codigo, 'ajuste' => isset($ajustes[$id][$f]) ? ($ajustes[$id][$f]['comentario'] ?: 'Corregido') : null];
        }
        $filas[] = ['id' => $id, 'nombre' => $s['nombre_completo'], 'dni' => $s['dni'], 'celdas' => $celdas, 'totales' => $totales];
    }
    return ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias, 'filas' => $filas, 'codigos' => SGS_ASISTENCIA_CODIGOS];
}
