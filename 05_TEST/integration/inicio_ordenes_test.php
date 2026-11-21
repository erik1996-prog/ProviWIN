<?php
// 05_TEST/integration/inicio_ordenes_test.php - (2026-11-19) Inicio de órdenes, pasado de Python (C:\xampp\htdocs\inicio-ordenes) a PHP.
//   - Regla de primera atención (dashboard de recableados): Cumple, Seguimiento, Incumple, fuera de regla y pendiente en vivo.
//   - Órdenes en curso (desde cuándo) y cancelaciones del día con motivo, imputable y si estaba en campo.
//   - Grilla: página 1 con títulos, páginas 2+ como <tr> sueltos, Región / Zona en dos líneas y aviso claro si faltan columnas.
//   - Historial de Winbo (fecha en inglés con AM/PM) al formato del Excel.
//   - Telegram: tildes "Ã“" → "Ó", lista fija de facilidades técnicas y textos del formato aprobado.
//   - La API no responde sin sesión del SGS. Winbo y Telegram no se llaman.
// La comparación completa con la versión Python sobre un Excel real (0 diferencias en 5 días) se hizo aparte al pasarla.
// Uso (solo CLI): php 05_TEST/integration/inicio_ordenes_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/InicioOrdenesService.php';

$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Inicio de órdenes — $base\n";

$ev = static fn(string $hora, string $estado, string $extra = '') => "Fecha: 19/11/2026 $hora Estado: $estado Usuario: Prueba Observacion: $extra";
$fila = static fn(string $id, string $cuad, array $hist, string $estado = 'Iniciada') => ['ordenId' => $id, 'orden' => "OT$id", 'cuadrilla' => $cuad,
    'proveedor' => 'OLMA SGI', 'departamento' => 'JUNÍN', 'provincia' => 'HUANCAYO', 'zona' => 'EL TAMBO', 'estado' => $estado,
    'tipoTrabajo' => 'Instalación', 'fvisita' => '2026-11-19 08:00', 'historial' => implode(';', $hist)];
$ag = static fn(string $cuad) => "Fecha: 18/11/2026 18:00:00 Estado: Agendada Usuario: Prueba Observacion:  Cuadrilla Asignada: $cuad";
$filas = [
    $fila('1', 'C1 SGI', [$ag('C1 SGI'), $ev('07:40:00', 'En camino'), $ev('08:05:30', 'Iniciada')]),
    $fila('2', 'C2 SGI', [$ag('C2 SGI'), $ev('07:50:00', 'En camino'), $ev('08:08:00', 'Iniciada')]),
    $fila('3', 'C3 SGA', [$ag('C3 SGA'), $ev('07:55:00', 'En camino'), $ev('08:11:00', 'Iniciada')]),
    $fila('4', 'C4 TRASLADO', [$ev('07:45:00', 'Agendada'), $ev('07:58:00', 'En camino'), $ev('08:01:00', 'Iniciada')]),
    $fila('5', 'C5 SGI', [$ag('C5 SGI'), $ev('07:59:00', 'En camino')], 'En camino'),
    $fila('6', 'C6 SGI', [$ag('C6 SGI'), $ev('07:30:00', 'En camino'), $ev('09:00:00', 'Cancelada', 'Imputable: Partner. Motivo de cancelación: CTO O NAP SATURADA')], 'Cancelada'),
];
$t = sgsIoArmarTablero($filas, '2026-11-19', '2026-11-19 10:15:00');
$por = array_column($t['cuadrillas'], null, 'cuadrilla');
$check('Cumple: inicio 08:05:30', ($por['C1 SGI']['resultado'] ?? '') === 'Cumple', $por['C1 SGI'] ?? null);
$check('Seguimiento: inicio 08:08', ($por['C2 SGI']['resultado'] ?? '') === 'Seguimiento');
$check('Incumple: inicio 08:11', ($por['C3 SGA']['resultado'] ?? '') === 'Incumple');
$check('Agendada después de las 07:30: fuera de regla y fuera del universo', !isset($por['C4 TRASLADO']), array_keys($por));
$check('En camino sin iniciar: pendiente y atrasada después de 08:10', ($por['C5 SGI']['estado'] ?? '') === 'En camino' && $por['C5 SGI']['atrasada'] === true);
$check('Cancelada antes de iniciar: Sin iniciar', ($por['C6 SGI']['estado'] ?? '') === 'Sin iniciar' && $por['C6 SGI']['motivo'] === 'Reprogramada o cancelada antes de iniciar');
$check('Grupos: SGI, SGA y TRASLADO = SGA', sgsIoGrupo('C1 SGI') === 'SGI' && sgsIoGrupo('C3 SGA') === 'SGA' && sgsIoGrupo('C4 TRASLADO') === 'SGA' && sgsIoGrupo('X') === 'Otros');
$check('Departamento: HUANCAYO se muestra como JUNÍN', sgsIoDepartamento('HUANCAYO') === 'JUNÍN' && sgsIoDepartamento('Áncash') === 'ÁNCASH');
$curso = array_column($t['enCurso'], null, 'orden');
$check('En curso: iniciadas y en camino con su hora de entrada', count($t['enCurso']) === 5 && ($curso['OT5']['estado'] ?? '') === 'En camino'
    && ($curso['OT1']['desde'] ?? '') === '2026-11-19T08:05:30' && $curso['OT5']['primera'] === true, $t['enCurso']);
$c = $t['cancelaciones'][0] ?? [];
$check('Cancelación: motivo, imputable y estaba en camino', ($c['motivo'] ?? '') === 'CTO O NAP SATURADA' && $c['imputable'] === 'Partner' && $c['enCampo'] === true && $c['estadoPrevio'] === 'En camino', $c);
$check('Curva de 06:00 a 12:00 cada 15 min', count($t['curva']) === 25 && $t['curva'][9]['hora'] === '08:15' && $t['curva'][9]['iniciadas'] === 3, $t['curva'][9] ?? null);

// Grilla
$cab = '<tr><th>Código De Seguimiento Cliente</th><th>Cuadrilla</th><th>Provincia</th><th>Localidad</th><th>Estado OT</th><th>Fecha Visita</th><th>Región / Zona</th><th>Proveedeor</th></tr>';
$tr = static fn(string $id) => "<tr onclick=\"abrirModal5(&quot;$id&quot;)\"><td>P$id</td><td>CUAD SGI</td><td>HUANCAYO</td><td>HUANCAYO</td><td>Iniciada</td><td>19/11/2026 08:00</td><td><p>CENTRO</p><p>El Tambo</p></td><td>OLMA</td></tr>";
[$f1, $c1] = InicioOrdenesService::filasGrilla("<table>$cab{$tr('101')}</table>", null);
$check('Grilla página 1: id, departamento, zona y fecha de visita', ($f1[0]['ordenId'] ?? '') === '101' && $f1[0]['departamento'] === 'JUNÍN'
    && $f1[0]['zona'] === 'EL TAMBO' && $f1[0]['region'] === 'CENTRO' && $f1[0]['fvisita'] === '2026-11-19 08:00', $f1[0] ?? null);
[$f2] = InicioOrdenesService::filasGrilla($tr('102') . $tr('103'), $c1);
$check('Grilla página 2+: filas <tr> sueltas con los títulos de la página 1', array_column($f2, 'ordenId') === ['102', '103'], $f2);
try { InicioOrdenesService::filasGrilla('<table><tr><th>Cuadrilla</th></tr>' . $tr('104') . '</table>', null); $msg = ''; }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
$check('Si faltan columnas lo dice claro', str_contains($msg, 'no trae las columnas') && str_contains($msg, 'Fecha Visita') && str_contains($msg, 'Columnas'), $msg);

// Historial de Winbo
$hist = (new ReflectionMethod(InicioOrdenesService::class, 'historial'));
$hist->setAccessible(true);
$h = $hist->invoke(null, ['html' => '<table><tr><th>Fecha</th><th>Estado</th><th>x</th><th>Usuario</th><th>Datos</th><th>Obs</th></tr>'
    . '<tr><td>11/19/2026 8:05:30 AM</td><td>Iniciada</td><td></td><td>Juan</td><td></td><td>ok; listo</td></tr>'
    . '<tr><td>11/19/2026 7:50:00 AM</td><td>En camino</td><td></td><td>Juan</td><td>Cuadrilla Asignada: C1</td><td></td></tr></table>']);
$check('Historial: del más viejo al más nuevo, con el formato del Excel', $h === 'Fecha: 19/11/2026 07:50:00 Estado: En camino Usuario: Juan Observacion:  Cuadrilla Asignada: C1;Fecha: 19/11/2026 08:05:30 Estado: Iniciada Usuario: Juan Observacion: ok, listo', $h);

// Telegram
$check('Tildes: "REPROGRAMACIÃ“N" → "REPROGRAMACIÓN"', sgsIoArreglarTildes('REPROGRAMACIÃ“N') === 'REPROGRAMACIÓN' && sgsIoArreglarTildes('CANCELACIÃ³N') === 'CANCELACIóN');
$check('Facilidades: lista fija, sin tildes y "empieza con"', sgsIoCategoriaFacilidad('Sin facilidades - NAP ductos obstruidos -VT') === 'Red, CTO o NAP'
    && sgsIoCategoriaFacilidad('ZONA PELIGROSA') === null && sgsIoCategoriaFacilidad('ZONA AGRESTE - INSTALACION EN ZONA ELEVADA') === 'Zona e infraestructura');
$ahora = new DateTimeImmutable('2026-11-19 14:30:00', new DateTimeZone('America/Lima'));
$al = sgsIoIniciadas2h([['estado' => 'Iniciada', 'desde' => '2026-11-19T11:00:00', 'proveedor' => 'OLMA SGI', 'cuadrilla' => 'C1 SGI', 'orden' => 'P1', 'zona' => 'EL TAMBO', 'provincia' => 'HUANCAYO', 'grupo' => 'SGI'],
                        ['estado' => 'Iniciada', 'desde' => '2026-11-19T12:15:00', 'proveedor' => 'OLMA SGI', 'cuadrilla' => 'C2 SGI', 'orden' => 'P2', 'zona' => '', 'provincia' => 'HUANCAYO', 'grupo' => 'SGI'],
                        ['estado' => 'Iniciada', 'desde' => '2026-11-19T13:30:00', 'proveedor' => 'OLMA SGI', 'cuadrilla' => 'C3 SGI', 'orden' => 'P3', 'zona' => '', 'provincia' => '', 'grupo' => 'SGI']], $ahora);
$tx = sgsIoArmarAlertas($al, 'SGI', ['2051695298' => 'Responsable'], $ahora);
$check('Alertas: solo las de 2 h o más', count($al) === 2);
$check('Alertas: formato aprobado', str_starts_with($tx, "📣 <a href=\"tg://user?id=2051695298\">Responsable</a>\nEstimados, buenas tardes.\nSe envía el reporte del <b>tiempo de atención</b> de las cuadrillas <b>SGI</b> para la alerta correspondiente.")
    && str_contains($tx, '📊 <b>Resumen:</b> 🔴 1 crítica · 🟡 1 en alerta') && str_contains($tx, '🔴 <b>CRÍTICO · 3 horas o más (1)</b>')
    && str_contains($tx, '🟡 <b>EN ALERTA · entre 2 y 3 horas (1)</b>') && str_contains($tx, "📍 <b><u>HUANCAYO</u></b> · 1 orden
1. <b>C1</b>
     #OTP1 · Iniciada hace <b>3 h 30 min</b> (desde las 11:00)")
    && str_ends_with($tx, 'Por favor, revisar estas órdenes y agilizar a las cuadrillas correspondientes.'), $tx);
$check('Cuadrilla sin el nombre del partner ni SGI/SGA', sgsIoCuadrillaSinPartner('P 7 ALFITELL SGI ANTONIO PARDO', 'ALFITELL') === 'P 7 ANTONIO PARDO' && sgsIoCuadrillaSinPartner('P 3 OLMA TRASLADO JUAN', 'OLMA') === 'P 3 TRASLADO JUAN');
$check('Grupo propio del partner: el encabezado no repite el partner', str_contains(sgsIoArmarAlertas([], '', [], $ahora), 'Se envía el reporte del <b>tiempo de atención</b> de las cuadrillas para la alerta correspondiente.'));
$check('Alertas sin órdenes: aviso en verde', str_contains(sgsIoArmarAlertas([], 'OLMA', [], $ahora), "las cuadrillas de <b>OLMA</b>") && str_contains(sgsIoArmarAlertas([], 'OLMA', [], $ahora), '✅ No hay órdenes iniciadas hace más de 2 horas.'));
$check('Grupos de Telegram en 09_CONFIG (dos de prueba, sin token)', count(sgsIoTelegramGrupos()) >= 1 && !preg_match('/\d{6,}:[A-Za-z0-9_-]{20,}/', (string)file_get_contents(SGS_IO_TELEGRAM_GRUPOS)));
$check('Cortes automáticos: 08:06 primera orden; 10:30, 12:30, 14:30, 16:30 y 18:30 alertas', InicioOrdenesService::CORTES === ['08:06' => 'primera', '10:30' => 'alertas', '12:30' => 'alertas', '14:30' => 'alertas', '16:30' => 'alertas', '18:30' => 'alertas']);
// Primera orden sin iniciar a las 08:05: en camino a las 08:05 y sin inicio hasta entonces (las que iniciaron después también).
$cq = static fn(string $cuad, ?string $camino, ?string $inicio, string $motivo = '') => ['cuadrilla' => $cuad, 'proveedor' => 'OLMA SGI', 'grupo' => sgsIoGrupo($cuad),
    'provincia' => 'PIURA', 'orden' => 'P', 'camino' => $camino, 'inicio' => $inicio, 'motivo' => $motivo];
$ps = sgsIoPrimeraSinIniciar([$cq('A SGI', '07:40:00', null), $cq('B SGI', '07:50:00', '08:04:00'), $cq('C SGI', '07:30:00', '08:09:00'),
    $cq('D SGI', null, null), $cq('E SGI', '08:07:00', null), $cq('F SGI', '07:45:00', null, 'Reprogramada o cancelada antes de iniciar'),
    $cq('G SGI', '07:55:00', null, 'Agendada después de las 07:30')]);
$check('Primera orden: solo en camino a las 08:05 sin iniciar (C inició 08:09, entra)', array_column($ps, 'cuadrilla') === ['C SGI', 'A SGI'], array_column($ps, 'cuadrilla'));
$tp = sgsIoArmarPrimera($ps, 'SGI', [], new DateTimeImmutable('2026-11-19 08:06:00', new DateTimeZone('America/Lima')));
$check('Primera orden: texto del reporte', str_contains($tp, 'Estimados, buenos días.') && str_contains($tp, 'cuadrillas <b>SGI</b> que aún no inician su <b>primera orden</b> o la iniciaron <b>después de las 08:05</b>.')
    && str_contains($tp, '📍 <b><u>PIURA</u></b> · 2 cuadrillas') && str_contains($tp, 'En camino desde las 07:30 · inició a las 08:09') && str_ends_with($tp, 'Para su seguimiento correspondiente.'), $tp);
// Un mensaje por partner y por SGI/SGA (con los grupos de prueba: uno SGI y uno SGA para todos los partners).
$o = static fn(string $prov, string $cuad, string $desde) => ['estado' => 'Iniciada', 'desde' => "2026-11-19T$desde", 'proveedor' => $prov,
    'cuadrilla' => $cuad, 'orden' => 'P', 'zona' => '', 'provincia' => '', 'grupo' => sgsIoGrupo($cuad)];
$ms = sgsIoMensajes(['enCurso' => [$o('OLMA SGI', 'A SGI', '11:00:00'), $o('OLMA SGA', 'B SGA', '11:00:00'), $o('ALFITELL SGI', 'C SGI', '10:00:00'),
                                   $o('ALFITELL SGI', 'D SGI', '12:00:00'), $o('OLMA SGI', 'E SGI', '14:00:00')]], 'alertas', $ahora);
$deGrupo = static fn(array $ms, string $g) => array_values(array_filter($ms, static fn($m) => str_contains($m['grupo'], $g)));
$alf = $deGrupo($ms, 'ALFITELL');
$check('Grupo ALFITELL: solo sus órdenes SGI, sin repetir su nombre y con sus encargados', array_column($alf, 'ordenes') === [2]
    && str_contains($alf[0]['texto'], 'las cuadrillas <b>SGI</b> para') && str_contains($alf[0]['texto'], 'tg://user?id=2005327267') && (string)$alf[0]['chat_id'] === '-1002953135395',
    array_column($alf, 'ordenes'));
$ms = $deGrupo($ms, 'Bot de pruebas');
$check('Un mensaje por partner: ALFITELL SGI, OLMA SGI y OLMA SGA', array_column($ms, 'partner') === ['ALFITELL SGI', 'OLMA SGI', 'OLMA SGA']
    && array_column($ms, 'ordenes') === [2, 1, 1] && str_contains($ms[0]['texto'], 'las cuadrillas de <b>ALFITELL SGI</b>'), array_column($ms, 'partner'));
$mp = $deGrupo(sgsIoMensajes(['cuadrillas' => [['camino' => null, 'inicio' => null, 'motivo' => '', 'proveedor' => 'ALFITELL SGI', 'grupo' => 'SGI']]], 'primera', $ahora), 'ALFITELL');
$check('Primera orden sin nadie en camino: "las órdenes del día se encuentran agendadas"', count($mp) === 1 && str_contains($mp[0]['texto'], 'se encuentran agendadas'), $mp[0]['texto'] ?? '');
$ms = $deGrupo(sgsIoMensajes(['enCurso' => []], 'alertas', $ahora), 'Bot de pruebas');
$check('Sin alertas: un solo mensaje ✅ para SGI y otro para SGA', array_column($ms, 'partner') === ['SGI', 'SGA'] && str_contains($ms[0]['texto'], '✅'), array_column($ms, 'partner'));
// Sin foto de fachada (en prueba): solo a los grupos con "fachada": true, con la lista ya revisada en Phoenix.
$fl = [array_merge($o('OLMA SGI', 'A SGI', '13:50:00'), ['m' => 40, 'partner' => 'OLMA', 'provincia' => 'PIURA']), $o('OLMA SGA', 'B SGA', '12:00:00') + ['m' => 150, 'partner' => 'OLMA']];
$marcados = array_values(array_filter(sgsIoTelegramGrupos(), static fn($g) => !empty($g['fachada'])));
if (!$marcados) {
    try { sgsIoMensajes(['enCurso' => []], 'fachada', $ahora, $fl); $sinEnvio = false; } catch (RuntimeException $x) { $sinEnvio = true; }
    $check('Fachada sin grupos marcados: no arma ningún mensaje (no se envía nada)', $sinEnvio && !sgsIoFachadaConfigurada());
} else {
    $mf = sgsIoMensajes(['enCurso' => []], 'fachada', $ahora, $fl);
    $check('Fachada: solo a los grupos marcados', array_diff(array_unique(array_column($mf, 'grupo')), array_column($marcados, 'nombre')) === [], array_column($mf, 'grupo'));
}
$tf = sgsIoArmarFachada([$fl[0]], 'SGI', [], $ahora);
$check('Fachada: texto del reporte', str_contains($tf, 'iniciadas sin foto de fachada') && str_contains($tf, '📍 <b><u>PIURA</u></b> · 1 orden')
    && str_contains($tf, 'Iniciada hace <b>40 min</b> (desde las 13:50)') && str_contains(sgsIoArmarFachada([$fl[1]], 'SGA', [], $ahora), 'Iniciada hace <b>2 h 30 min</b>')
    && str_contains(sgsIoArmarFachada($fl, 'SGA', [], $ahora), '2 órdenes sin foto'), $tf);
$check('Fachada sin órdenes: aviso en verde', str_contains(sgsIoArmarFachada([], 'SGI', [], $ahora), '✅ Todas las órdenes iniciadas ya tienen su foto de fachada.'));
$check('Fachada: cada hora a las :30 de 08:30 a 20:30 y no se envía a mano', count(InicioOrdenesService::CORTES_FACHADA) === 13 && InicioOrdenesService::CORTES_FACHADA[0] === '08:30'
    && InicioOrdenesService::CORTES_FACHADA[12] === '20:30' && !isset(SGS_IO_REPORTES['fachada']));
$check('Trabajo de cada turno acotado (sistema liviano)', InicioOrdenesService::TURNO_SEGUNDOS <= 20 && InicioOrdenesService::TURNO_SEGUNDOS_CORTE <= 45);
$check('Lectura de Winbo cada 30 minutos', InicioOrdenesService::VUELTA === 1800);

// Despertar liviano (pulso.json) y cortes: se respaldan y se devuelven los archivos reales. No se envía nada.
$dirIo = STORAGE_PATH . '/inicio_ordenes';
@mkdir($dirIo, 0700, true);
$respaldo = [];
foreach (['pulso.json', 'telegram.json'] as $a) $respaldo[$a] = @file_get_contents("$dirIo/$a");
try {
    $svc = new InicioOrdenesService();
    $toca = new ReflectionMethod($svc, 'toca'); $toca->setAccessible(true);
    $corte = new ReflectionMethod($svc, 'cortePendiente'); $corte->setAccessible(true);
    $pulso = static fn(array $p) => file_put_contents("$dirIo/pulso.json", json_encode($p + ['dia' => date('Y-m-d'), 'fase' => 'espera', 'error' => false, 'turno' => time()]));
    @unlink("$dirIo/pulso.json");
    $check('Sin pulso: toca leer', $toca->invoke($svc) === true);
    $pulso(['actualizado' => time() - 60]);
    $c = $corte->invoke($svc);
    $check('Leído hace 1 min: solo toca si hay un corte pendiente', $toca->invoke($svc) === ($c !== null), $c);
    $pulso(['actualizado' => time() - 1900]);
    $check('Leído hace más de 30 min: toca leer', $toca->invoke($svc) === true);
    $pulso(['actualizado' => time() - 60, 'fase' => 'historial']);
    $check('Lectura a medias: toca seguir', $toca->invoke($svc) === true);
    $pulso(['actualizado' => time() - 3000, 'error' => true, 'turno' => time() - 60]);
    $check('Con error: espera 5 min antes de reintentar', $toca->invoke($svc) === false);
    if ($c !== null) {
        file_put_contents("$dirIo/telegram.json", json_encode(['hora' => date('Y-m-d ') . $c['corte'], 'enviado' => '00:00']));
        $check("Corte {$c['corte']} ya enviado: no queda pendiente", $corte->invoke($svc) === null);
    }
} finally {
    foreach ($respaldo as $a => $txt) $txt === false ? @unlink("$dirIo/$a") : file_put_contents("$dirIo/$a", $txt);
}

// WhatsApp aparte (2026-11-20): reporte general, sin HTML, partido bajo el límite, config sin token.
$wc = sgsIoWhatsappConfig();
$check('WhatsApp: config propia (08:06 primera; 10:30, 12:30, 14:30, 16:30 y 18:30 alertas, como Telegram) y sin token en el archivo',
    $wc['numeros'] && !array_filter($wc['numeros'], static fn($x) => !preg_match('/^51\d{9}$/', $x)) && $wc['cortes'] === ['08:06' => 'primera', '10:30' => 'alertas', '12:30' => 'alertas', '14:30' => 'alertas', '16:30' => 'alertas', '18:30' => 'alertas']
    && array_keys(array_filter($wc['cortes'], static fn($r) => $r === 'alertas')) === array_keys(array_filter(InicioOrdenesService::CORTES, static fn($r) => $r === 'alertas'))
    && !preg_match('/inr_(live|test)_/', (string)file_get_contents(SGS_IO_WHATSAPP_CONFIG)), $wc);
$cw = static fn(string $prov, string $cuad, string $estado, ?string $ini, string $res, ?string $cam = null) => ['proveedor' => $prov, 'cuadrilla' => $cuad,
    'grupo' => sgsIoGrupo($cuad), 'provincia' => 'PIURA', 'estado' => $estado, 'inicio' => $ini, 'camino' => $cam, 'resultado' => $res, 'orden' => '3290001',
    'primeraAgendada' => ['visita' => '08:00', 'orden' => '3290001']];
$wm = sgsIoArmarPrimeraWa([$cw('OLMA', 'P 1 OLMA SGI JUAN', 'Inició', '07:50:00', 'Cumple'), $cw('ALFITELL', 'P 2 ALFITELL SGI ANA', 'En camino', null, '', '07:40:00'),
                           $cw('ALFITELL', 'P 3 ALFITELL SGI LUIS', 'Inició', '08:09:00', 'Incumple'), $cw('OLMA', 'P 4 OLMA SGI PEDRO', 'Inició', '09:00:00', '')],
                          new DateTimeImmutable('2026-11-20 08:06:00', new DateTimeZone('America/Lima')));
$check('WhatsApp primera: una línea por cuadrilla que no cumple, por partner, sin saludo, resumen ni colores', count($wm) === 1
    && str_starts_with($wm[0], "*Reporte general de primera atención*\nCorte 08:06 · 20/11/2026") && !str_contains($wm[0], 'Estimad') && !str_contains($wm[0], 'JUAN')
    && !str_contains($wm[0], 'PEDRO') && !str_contains($wm[0], 'Resumen') && !preg_match('/[🟢🟡🔴]/u', $wm[0])
    && str_contains($wm[0], "*ALFITELL*\nP 2 ALFITELL SGI ANA — Aún en camino desde las 07:40, no inicia\nP 3 ALFITELL SGI LUIS — Inició fuera de horario 08:09")
    && !str_contains($wm[0], '*OLMA*') && !preg_match('/<\/?[a-z]/', $wm[0]), $wm);
$oa = static fn($prov, $cuad, $desde) => ['estado' => 'Iniciada', 'desde' => "2026-11-20T$desde", 'proveedor' => $prov, 'cuadrilla' => $cuad, 'orden' => '3291140',
    'provincia' => 'CHICLAYO', 'grupo' => sgsIoGrupo($cuad)];
$wa = sgsIoArmarAlertasWa([$oa('OLMA SGI', 'P 1 OLMA SGI A', '06:55:00'), $oa('ALFITELL SGI', 'P 2 ALFITELL SGI B', '08:00:00'), $oa('ALFITELL SGI', 'P 3 ALFITELL SGI C', '10:00:00')],
                          new DateTimeImmutable('2026-11-20 10:30:00', new DateTimeZone('America/Lima')));
$check('WhatsApp alertas: todos los partners juntos, crítico y en alerta', count($wa) === 1 && str_contains($wa[0], '🔴 1 crítica · 🟡 1 en alerta')
    && str_contains($wa[0], '▪️ *OLMA* (1)') && str_contains($wa[0], 'P 2 B (SGI) · OT 3291140 · Chiclayo · *2 h 30 min* (desde 08:00)') && !str_contains($wa[0], ' C (SGI)'), $wa);
$muchas = [];
for ($i = 0; $i < 200; $i++) $muchas[] = $cw('P' . ($i % 7), "P $i X SGI NOMBRE APELLIDO APELLIDO", 'En camino', null, '', '07:40:00') + ['motivo' => ''];
$sinCamino = sgsIoArmarPrimeraWa([$cw('OLMA', 'P 9 OLMA SGI SIN SALIR', 'Sin iniciar', null, '')], new DateTimeImmutable('2026-11-20 08:06:00', new DateTimeZone('America/Lima')));
$check('WhatsApp primera: la que no puso En camino no aparece', !str_contains($sinCamino[0], 'SIN SALIR') && str_contains($sinCamino[0], 'iniciaron a tiempo'), $sinCamino);
$wm = sgsIoArmarPrimeraWa($muchas, new DateTimeImmutable('2026-11-20 08:06:00', new DateTimeZone('America/Lima')));
$check('WhatsApp: se parte en mensajes de menos de 4096 caracteres', count($wm) > 1 && max(array_map('mb_strlen', $wm)) < 4096 && str_starts_with($wm[1], '_Parte 2 de'),
    array_map('mb_strlen', $wm));

// API sin sesión
$ch = curl_init("$base/api/inicio_ordenes.php?action=tablero");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$check('API sin sesión del SGS: no responde datos', in_array($http, [401, 403], true), $http);

echo "\n$ok correctas, $fail con error\n";
exit($fail ? 1 : 0);
