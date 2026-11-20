<?php
// 02_BACKEND/src/config/inicio_ordenes.php - "Inicio de órdenes" (2026-11-19, pedido del usuario): primera atención del día de cada
// cuadrilla de GESTIÓN PROVINCIA y órdenes En camino / Iniciadas ahora. Copia fiel de la versión Python que quedó como respaldo
// en C:\xampp\htdocs\inicio-ordenes (core/logica.py y core/seguidor.py); si cambia una regla allá, se cambia aquí.
//
// Regla "Primer inicio del día" (= dashboard de recableados, Recabelados/app.js: parseHistoryEvents, classifyFirstStartCandidate,
// parseFirstStartMatrix). Unidad: la jornada de una cuadrilla (cuadrilla × día); se toma su PRIMER "En camino" del día entre
// todas sus órdenes, atribuido a la cuadrilla asignada en ese momento del historial (no a la cuadrilla final de la OT).
//   1) En camino ≤ 08:00:00
//   2) Última agenda previa: del día anterior, o ≤ 07:30:00 del mismo día ("agendada en la madrugada": 00:00–07:30)
//   3) Iniciada el mismo día sin Agendada/Regestión/Cancelada/Anulada intermedia
//   4) Inicio ≤ 08:05:59 Cumple · 08:06:00–08:10:59 Seguimiento · ≥ 08:11:00 Incumple
// Universo del tablero: cuadrillas con órdenes de hoy agendadas hasta las 07:30 (o desde el día anterior).
//
// Las horas se manejan como texto 'aaaa-mm-dd hh:mm:ss' (hora de Perú): se comparan con strcmp y no hace falta DateTime.
// Lectura de Winbo, turnos por partes y envíos: services/InicioOrdenesService.php. Telegram: config/inicio_ordenes_telegram.php.

const SGS_IO_CAMINO_LIMITE = 8 * 3600;
const SGS_IO_AGENDA_LIMITE = 7 * 3600 + 30 * 60;
const SGS_IO_CUMPLE_MIN = 8 * 60 + 5;
const SGS_IO_SEGUIMIENTO_MIN = 8 * 60 + 10;
const SGS_IO_ESTADOS_CORTE = ['AGENDADA', 'REGESTION', 'CANCELADA', 'ANULADA'];
const SGS_IO_EVENTO_RE = '/Fecha:\s*(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})\s+Estado:\s*(.*?)\s+Usuario:([\s\S]*?)(?=;?\s*Fecha:\s*\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}\s+Estado:|$)/iu';
// Winbo pone en "Provincia" el departamento, salvo HUANCAYO (que es Junín).
const SGS_IO_DEPARTAMENTO_ALIAS = ['HUANCAYO' => 'JUNÍN', 'JUNIN' => 'JUNÍN', 'ANCASH' => 'ÁNCASH', 'NCASH' => 'ÁNCASH'];

/** str.strip() de Python: quita también espacios Unicode (como el espacio duro de Winbo). */
function sgsIoTrim(string $s): string { return (string)preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $s); }

/** cleanText() del dashboard: arregla palabras que Winbo entrega sin su letra con tilde. */
function sgsIoLimpiar($v): string {
    if ($v === null) return '';
    $s = sgsIoTrim((string)$v);
    foreach ([['/NCASH/u', 'ÁNCASH'], ['/REGESTIN/iu', 'REGESTIÓN'], ['/FINALIZACIN/iu', 'FINALIZACIÓN'], ['/CANCELACIN/iu', 'CANCELACIÓN'],
              ['/DEGRADACIN/u', 'DEGRADACIÓN'], ['/REUBICACIN/u', 'REUBICACIÓN']] as [$p, $r]) $s = (string)preg_replace($p, $r, $s);
    return $s;
}

/** Quita tildes y diéresis (como unicodedata NFD sin marcas): Á→A, Ñ→N, ü→u. */
function sgsIoSinTildes(string $s): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (['A' => 'ÀÁÂÃÄÅ', 'C' => 'Ç', 'E' => 'ÈÉÊË', 'I' => 'ÌÍÎÏ', 'N' => 'Ñ', 'O' => 'ÒÓÔÕÖ', 'U' => 'ÙÚÛÜ', 'Y' => 'Ý',
                  'a' => 'àáâãäå', 'c' => 'ç', 'e' => 'èéêë', 'i' => 'ìíîï', 'n' => 'ñ', 'o' => 'òóôõö', 'u' => 'ùúûü', 'y' => 'ýÿ'] as $base => $letras)
            foreach (mb_str_split($letras) as $l) $mapa[$l] = $base;
    }
    return strtr($s, $mapa);
}

/** normalizeBusinessValue() del dashboard: 'En camino' → 'EN CAMINO'. */
function sgsIoNormEstado($v): string {
    $s = sgsIoSinTildes(mb_strtoupper(sgsIoLimpiar($v), 'UTF-8'));
    return trim((string)preg_replace('/[^A-Z0-9]+/', ' ', $s));
}

/** normalizeHeader() del dashboard: 'Estado OT' → 'ESTADOOT'. */
function sgsIoNormEncabezado($v): string {
    return (string)preg_replace('/[^A-Z0-9]/', '', sgsIoSinTildes(mb_strtoupper(sgsIoTrim((string)$v), 'UTF-8')));
}

function sgsIoDepartamento($v): string {
    $d = str_replace('�', '', sgsIoSinTildes(mb_strtoupper(sgsIoTrim((string)$v), 'UTF-8')));
    $d = trim((string)preg_replace('/[^A-Z ]/', '', $d));
    return (SGS_IO_DEPARTAMENTO_ALIAS[$d] ?? $d) ?: 'Sin dato';
}

/** 'SGI' en el nombre = SGI; SGA o TRASLADO = SGA; si no, Otros. */
function sgsIoGrupo($cuadrilla): string {
    $c = mb_strtoupper((string)$cuadrilla, 'UTF-8');
    if (str_contains($c, 'SGI')) return 'SGI';
    if (str_contains($c, 'SGA') || str_contains($c, 'TRASLADO')) return 'SGA';
    return 'Otros';
}

/** Primera letra en mayúscula y el resto en minúscula (str.capitalize de Python). */
function sgsIoCapitalizar(string $s): string {
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1), 'UTF-8') . mb_strtolower(mb_substr($s, 1), 'UTF-8');
}

/** Eventos del historial ("Fecha: dd/mm/aaaa hh:mm:ss Estado: X Usuario: … Cuadrilla Asignada: Z;"), ordenados por hora. */
function sgsIoEventos($historial): array {
    $eventos = [];
    preg_match_all(SGS_IO_EVENTO_RE, (string)$historial, $ms, PREG_SET_ORDER);
    foreach ($ms as $m) {
        $cq = preg_match('/Cuadrilla Asignada:\s*([^;\r\n]+)/iu', $m[8], $c) ? sgsIoLimpiar($c[1]) : null;
        $eventos[] = ['at' => "$m[3]-$m[2]-$m[1] $m[4]:$m[5]:$m[6]", 'estado' => sgsIoNormEstado($m[7]), 'cuadrilla' => $cq];
    }
    usort($eventos, static fn($a, $b) => strcmp($a['at'], $b['at']));   // estable: en el mismo segundo conserva el orden
    return $eventos;
}

function sgsIoSeg(string $at): int { return (int)substr($at, 11, 2) * 3600 + (int)substr($at, 14, 2) * 60 + (int)substr($at, 17, 2); }
function sgsIoDia(string $at): string { return substr($at, 0, 10); }
function sgsIoIso(?string $at): ?string { return $at === null ? null : str_replace(' ', 'T', $at); }

function sgsIoResultadoInicio(string $inicio): string {
    $minuto = (int)substr($inicio, 11, 2) * 60 + (int)substr($inicio, 14, 2);
    return $minuto <= SGS_IO_CUMPLE_MIN ? 'Cumple' : ($minuto <= SGS_IO_SEGUIMIENTO_MIN ? 'Seguimiento' : 'Incumple');
}

/** classifyFirstStartCandidate(). Con $ahora (tiempo real): sin inicio y con el día en curso, el motivo es "En camino, aún sin iniciar". */
function sgsIoClasificar(array $cand, ?string $ahora = null): array {
    $eventos = $cand['eventos']; $i = $cand['indice']; $fila = $cand['fila'];
    $camino = $eventos[$i]['at'];
    $agenda = null;
    for ($k = 0; $k < $i; $k++) if ($eventos[$k]['estado'] === 'AGENDADA') $agenda = $eventos[$k]['at'];
    $inicio = null; $interrumpida = false;
    for ($k = $i + 1, $n = count($eventos); $k < $n; $k++) {
        if ($eventos[$k]['estado'] === 'INICIADA') { $inicio = $eventos[$k]['at']; break; }
        if (in_array($eventos[$k]['estado'], SGS_IO_ESTADOS_CORTE, true)) { $interrumpida = true; break; }
    }
    $elegible = false; $resultado = ''; $motivo = '';
    if (sgsIoSeg($camino) > SGS_IO_CAMINO_LIMITE) $motivo = 'Primer En camino después de las 08:00';
    elseif (!$agenda) $motivo = 'Sin agenda previa identificable';
    elseif (sgsIoDia($agenda) === sgsIoDia($camino) && sgsIoSeg($agenda) > SGS_IO_AGENDA_LIMITE) $motivo = 'Agendada después de las 07:30';
    elseif ($interrumpida) $motivo = 'Reprogramada o cancelada antes de iniciar';
    elseif (!$inicio) $motivo = 'Sin inicio posterior';
    elseif (sgsIoDia($inicio) !== sgsIoDia($camino)) $motivo = 'Iniciada en otro día';
    else { $elegible = true; $resultado = sgsIoResultadoInicio($inicio); }
    $pendiente = $motivo === 'Sin inicio posterior' && $ahora !== null && sgsIoDia($ahora) === sgsIoDia($camino);
    return ['orden' => $fila['orden'], 'ordenId' => $fila['ordenId'], 'cuadrilla' => $cand['cuadrilla'], 'proveedor' => $cand['proveedor'],
            'provincia' => $fila['provincia'] ?: 'Sin dato', 'estadoActual' => $fila['estado'], 'fecha' => sgsIoDia($camino),
            'agendaIso' => sgsIoIso($agenda), 'caminoIso' => sgsIoIso($camino), 'inicioIso' => sgsIoIso($inicio),
            'elegible' => $elegible, 'resultado' => $resultado,
            'motivo' => $pendiente ? 'En camino, aún sin iniciar' : $motivo, 'pendiente' => $pendiente];
}

/** parseFirstStartMatrix(): por cuadrilla×día, el primer "En camino" (sin contar "En camino" repetidos seguidos). */
function sgsIoPrimerosCaminos(array $filas): array {
    $provPorCuadrilla = [];
    foreach ($filas as $f) if ($f['cuadrilla'] !== '' && $f['proveedor'] !== '' && !isset($provPorCuadrilla[$f['cuadrilla']])) $provPorCuadrilla[$f['cuadrilla']] = $f['proveedor'];
    $primero = [];
    foreach ($filas as $f) {
        $eventos = $f['_eventos'];
        $asignada = null; $previo = null;
        foreach ($eventos as $i => $e) {
            if ($e['cuadrilla']) $asignada = $e['cuadrilla'];
            $repetido = $previo === 'EN CAMINO';
            $previo = $e['estado'];
            if ($e['estado'] !== 'EN CAMINO' || $repetido) continue;
            $cuad = $asignada ?: ($f['cuadrilla'] ?: 'Sin cuadrilla');
            $clave = $cuad . "\x1F" . sgsIoDia($e['at']);
            $actual = $primero[$clave] ?? null;
            if ($actual && strcmp($actual['eventos'][$actual['indice']]['at'], $e['at']) <= 0) continue;
            $primero[$clave] = ['eventos' => $eventos, 'indice' => $i, 'fila' => $f, 'cuadrilla' => $cuad, 'dia' => sgsIoDia($e['at']),
                                'proveedor' => ($provPorCuadrilla[$cuad] ?? '') ?: ($f['proveedor'] ?: 'Sin dato')];
        }
    }
    return $primero;
}

/** ¿La orden quedó agendada antes del corte para $dia? Última AGENDADA del día anterior o de 00:00–07:30 del mismo día. */
function sgsIoAgendadaParaHoy(array $eventos, string $dia): ?string {
    $a = null;
    foreach ($eventos as $e) if ($e['estado'] === 'AGENDADA' && strcmp(sgsIoDia($e['at']), $dia) <= 0) $a = $e['at'];
    if ($a === null) return null;
    return (strcmp(sgsIoDia($a), $dia) < 0 || sgsIoSeg($a) <= SGS_IO_AGENDA_LIMITE) ? $a : null;
}

/**
 * Tablero del día: una fila por cuadrilla con su PRIMERA atención (solo esa), la curva de inicios cada 15 minutos, las órdenes
 * en curso y las cancelaciones del día. $filas: órdenes con orden, ordenId, cuadrilla, proveedor, departamento, provincia, zona,
 * estado, tipoTrabajo, fvisita ('aaaa-mm-dd hh:mm') e historial. $ahora: 'aaaa-mm-dd hh:mm:ss'.
 */
function sgsIoArmarTablero(array $filas, string $dia, ?string $ahora = null): array {
    foreach ($filas as $k => $f) $filas[$k]['_eventos'] = sgsIoEventos($f['historial'] ?? '');
    $vivo = ($ahora !== null && sgsIoDia($ahora) === $dia) ? $ahora : null;

    // Regla del dashboard: primer "En camino" de cada cuadrilla en el día = su primera atención.
    $regla = [];
    foreach (sgsIoPrimerosCaminos($filas) as $cand) if ($cand['dia'] === $dia) $regla[$cand['cuadrilla']] = sgsIoClasificar($cand, $vivo);

    // Órdenes de hoy agendadas en la madrugada (00:00–07:30) o desde ayer, por cuadrilla.
    $agendadas = []; $prov = []; $loc = []; $porId = [];
    foreach ($filas as $f) {
        if ($f['ordenId'] !== '') $porId[$f['ordenId']] = $f;
        if ($f['cuadrilla'] !== '') {
            if ($f['proveedor'] !== '' && !isset($prov[$f['cuadrilla']])) $prov[$f['cuadrilla']] = $f['proveedor'];
            if ($f['provincia'] !== '' && !isset($loc[$f['cuadrilla']])) $loc[$f['cuadrilla']] = $f['provincia'];
        }
        if (substr((string)($f['fvisita'] ?? ''), 0, 10) !== $dia || $f['cuadrilla'] === '') continue;
        $ag = sgsIoAgendadaParaHoy($f['_eventos'], $dia);
        if ($ag) $agendadas[$f['cuadrilla']][] = ['orden' => $f['orden'], 'ordenId' => $f['ordenId'],
            'visita' => substr((string)$f['fvisita'], 11, 5), 'agenda' => substr($ag, 8, 2) . '/' . substr($ag, 5, 2) . ' ' . substr($ag, 11, 5)];
    }

    $tarde = $vivo !== null && strcmp(substr($vivo, 11, 8), '08:10:59') > 0;
    $nombres = array_keys($agendadas);
    foreach ($regla as $c => $r) if ($r['elegible'] && !isset($agendadas[$c])) $nombres[] = $c;
    $h = static fn(?string $iso) => ($iso !== null && strlen($iso) > 11) ? substr($iso, 11) : null;
    $lista = [];
    foreach ($nombres as $nombre) {
        $nombre = (string)$nombre;
        $rg = $regla[$nombre] ?? null;
        $ags = $agendadas[$nombre] ?? [];
        usort($ags, static fn($a, $b) => strcmp($a['visita'], $b['visita']));
        if ($rg && $rg['inicioIso'] && !str_starts_with($rg['motivo'], 'Reprogramada')) $estado = 'Inició';
        elseif ($rg && $rg['pendiente']) $estado = 'En camino';
        else $estado = 'Sin iniciar';
        // Ubicación: la de la orden de su 1ra atención (o de su 1ra orden agendada).
        $ref = $porId[$rg['ordenId'] ?? ''] ?? ($ags ? ($porId[$ags[0]['ordenId']] ?? []) : []);
        $lista[] = [
            'departamento' => ($ref['departamento'] ?? '') ?: 'Sin dato', 'zona' => ($ref['zona'] ?? '') ?: 'Sin dato',
            'cuadrilla' => $nombre, 'proveedor' => ($prov[$nombre] ?? '') ?: (($rg['proveedor'] ?? '') ?: 'Sin dato'),
            'grupo' => sgsIoGrupo($nombre),
            'provincia' => ($ref['provincia'] ?? '') ?: (($loc[$nombre] ?? '') ?: (($rg['provincia'] ?? '') ?: 'Sin dato')),
            'agendadas' => count($ags), 'primeraAgendada' => $ags[0] ?? null,
            'estado' => $estado, 'atrasada' => $tarde && $estado !== 'Inició',
            'orden' => $rg ? $rg['orden'] : ($ags[0]['orden'] ?? null),
            'agenda' => $rg ? $h($rg['agendaIso']) : null, 'agendaDia' => $rg ? substr((string)$rg['agendaIso'], 0, 10) : null,
            'camino' => $rg ? $h($rg['caminoIso']) : null, 'inicio' => $estado === 'Inició' ? $h($rg['inicioIso']) : null,
            'resultado' => $rg['resultado'] ?? '', 'motivo' => $rg['motivo'] ?? '', 'elegible' => (bool)($rg['elegible'] ?? false)];
    }
    usort($lista, static fn($a, $b) => strcmp($a['inicio'] ?? '99', $b['inicio'] ?? '99') ?: strcmp($a['camino'] ?? '99', $b['camino'] ?? '99') ?: strcmp($a['cuadrilla'], $b['cuadrilla']));

    // Avance del día: primeras atenciones iniciadas, acumuladas cada 15 minutos (06:00 a 12:00).
    $horas = array_values(array_filter(array_column($lista, 'inicio')));
    $curva = [];
    for ($m = 6 * 60; $m <= 12 * 60; $m += 15) {
        $corte = sprintf('%02d:%02d:59', intdiv($m, 60), $m % 60);
        $curva[] = ['hora' => substr($corte, 0, 5), 'iniciadas' => count(array_filter($horas, static fn($x) => strcmp($x, $corte) <= 0))];
    }
    return ['dia' => $dia, 'ahora' => $ahora !== null ? substr($ahora, 11, 8) : null, 'cuadrillas' => $lista, 'curva' => $curva,
            'ordenes' => count($filas), 'enCurso' => sgsIoOrdenesEnCurso($filas, $regla), 'cancelaciones' => sgsIoCancelacionesDelDia($filas, $dia)];
}

/**
 * Órdenes que AHORA están En camino o Iniciadas (Revisión cuenta como Iniciada). 'desde' = hora en que entraron a ese estado;
 * la duración la cuenta la pantalla en vivo. Al finalizar, cancelar o reprogramar salen solas de la lista.
 */
function sgsIoOrdenesEnCurso(array $filas, array $regla): array {
    $primera = [];
    foreach ($regla as $r) if ($r['ordenId']) $primera[$r['ordenId']] = true;
    $out = [];
    foreach ($filas as $f) {
        $ev = $f['_eventos'];
        if (!$ev) continue;
        $ult = end($ev)['estado'];
        if (!in_array($ult, ['EN CAMINO', 'INICIADA', 'REVISION'], true)) continue;
        $estado = $ult === 'EN CAMINO' ? 'En camino' : 'Iniciada';
        $objetivo = $ult === 'EN CAMINO' ? 'EN CAMINO' : 'INICIADA';
        $desde = end($ev)['at'];
        foreach (array_reverse($ev) as $e) if ($e['estado'] === $objetivo) { $desde = $e['at']; break; }
        $camino = null;
        foreach (array_reverse($ev) as $e) if ($e['estado'] === 'EN CAMINO' && strcmp($e['at'], $desde) <= 0) { $camino = $e['at']; break; }
        $asignada = null;
        foreach (array_reverse($ev) as $e) if ($e['cuadrilla']) { $asignada = $e['cuadrilla']; break; }
        $cuad = $f['cuadrilla'] ?: ($asignada ?: 'Sin cuadrilla');
        $out[] = ['ordenId' => $f['ordenId'], 'orden' => $f['orden'], 'cuadrilla' => $cuad, 'proveedor' => $f['proveedor'] ?: 'Sin dato',
                  'grupo' => sgsIoGrupo($cuad), 'provincia' => $f['provincia'] ?: 'Sin dato', 'tipoTrabajo' => $f['tipoTrabajo'] ?? '',
                  'departamento' => ($f['departamento'] ?? '') ?: 'Sin dato', 'zona' => ($f['zona'] ?? '') ?: 'Sin dato',
                  'visita' => substr((string)($f['fvisita'] ?? ''), 11, 5), 'estado' => $estado, 'revision' => $ult === 'REVISION',
                  'desde' => sgsIoIso($desde), 'camino' => ($camino && $estado === 'Iniciada') ? substr($camino, 11, 8) : null,
                  'primera' => isset($primera[$f['ordenId']])];
    }
    return $out;
}

/** Cada cambio de estado del historial: hora, estado, usuario y texto (observación + datos adicionales). */
function sgsIoSegmentos($historial): array {
    $out = [];
    foreach (explode(';', (string)$historial) as $parte) {
        if (preg_match('/Fecha:\s*(\d{2})\/(\d{2})\/(\d{4}) (\d{2}:\d{2}:\d{2})\s+Estado:\s*(.*?)\s+Usuario:\s*(.*?)\s+Observacion:\s*(.*)$/su', sgsIoTrim($parte), $m))
            $out[] = ['at' => "$m[3]-$m[2]-$m[1] $m[4]", 'estado' => sgsIoNormEstado($m[5]), 'usuario' => sgsIoTrim($m[6]), 'texto' => sgsIoTrim($m[7])];
    }
    return $out;
}

/**
 * Órdenes canceladas o anuladas HOY: hora, motivo, a quién se imputa (backoffice lo anota en la observación: "Imputable:
 * Cliente/Partner") y si la cuadrilla ya estaba en campo (iniciada o en camino).
 */
function sgsIoCancelacionesDelDia(array $filas, string $dia): array {
    require_once __DIR__ . '/inicio_ordenes_telegram.php';      // sgsIoArreglarTildes
    $out = [];
    foreach ($filas as $f) {
        $segs = sgsIoSegmentos($f['historial'] ?? '');
        foreach ($segs as $i => $sg) {
            if (!in_array($sg['estado'], ['CANCELADA', 'ANULADA'], true) || sgsIoDia($sg['at']) !== $dia) continue;
            $hay = preg_match('/Motivo de (?:cancelaci\S*|anulaci\S*):\s*(.+)$/isu', $sg['texto'], $m, PREG_OFFSET_CAPTURE);
            $motivo = $hay ? sgsIoArreglarTildes(sgsIoLimpiar($m[1][0])) : '';
            $obs = trim(sgsIoArreglarTildes(sgsIoLimpiar($hay ? substr($sg['texto'], 0, $m[0][1]) : $sg['texto'])), ' .');
            $previo = $i ? $segs[$i - 1]['estado'] : '';
            $cuad = $f['cuadrilla'] ?? '';
            $imp = preg_match('/Imputable\s*:\s*([A-Za-zÁÉÍÓÚÑáéíóúñ ]+)/u', $sg['texto'], $im) ? preg_split('/\s+/', trim($im[1]))[0] : '';
            $out[] = ['ordenId' => $f['ordenId'] ?? null, 'orden' => $f['orden'] ?? null, 'cuadrilla' => $cuad ?: 'Sin cuadrilla',
                      'proveedor' => ($f['proveedor'] ?? '') ?: 'Sin dato', 'grupo' => sgsIoGrupo($cuad),
                      'departamento' => ($f['departamento'] ?? '') ?: 'Sin dato', 'provincia' => ($f['provincia'] ?? '') ?: 'Sin dato',
                      'zona' => $f['zona'] ?? '', 'tipoTrabajo' => $f['tipoTrabajo'] ?? '',
                      'hora' => substr($sg['at'], 11, 5), 'tipo' => $sg['estado'] === 'ANULADA' ? 'Anulada' : 'Cancelada',
                      'motivo' => $motivo ?: 'Sin motivo', 'observacion' => mb_substr($obs, 0, 200),
                      'imputable' => $imp !== '' ? sgsIoCapitalizar($imp) : '',
                      'usuario' => $sg['usuario'], 'enCampo' => in_array($previo, ['INICIADA', 'EN CAMINO', 'REVISION'], true),
                      'estadoPrevio' => $previo !== '' ? sgsIoCapitalizar($previo) : ''];
        }
    }
    usort($out, static fn($a, $b) => strcmp($a['hora'], $b['hora']));
    return $out;
}
