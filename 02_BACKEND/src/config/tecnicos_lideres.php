<?php
// 02_BACKEND/src/config/tecnicos_lideres.php - Carga de técnicos líderes (T1) por Excel (2026-10-15).
// El archivo trae dos columnas: técnico líder (con su nomenclatura, p. ej. "P 4 DOMINION SGI JUAN PEREZ") y partner.
//   - Ya existe con el mismo nombre        → sin cambios (si estaba inhabilitado, se reactiva; si cambió de partner, se actualiza).
//   - Misma persona con otra nomenclatura  → se actualiza el nombre (la nomenclatura cambia al inicio, la persona no).
//   - No existe                            → se agrega como técnico líder habilitado.
//   - Habilitado en el sistema y ausente del archivo → se inhabilita (no se borra: conserva su historial).
// Lista negra no se toca. Primero se muestra la vista previa; se aplica solo cuando el administrador confirma.
// Zona (2026-10-15): tercera columna opcional PROVINCIA. Se busca su departamento (config/zonas_peru.php) y se guardan los
// dos; si el técnico ya tenía otra zona, se actualiza (los técnicos cambian de zona). Sin provincia, la zona no se toca.
require_once __DIR__ . '/excel_lector.php';
require_once __DIR__ . '/zonas_peru.php';

/** Nombre tal como se guarda: mayúsculas y un solo espacio. */
function sgsNombreLider(string $nombre): string {
    return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $nombre)), 'UTF-8');
}

/**
 * La persona detrás de la nomenclatura, para reconocerla aunque cambie el inicio:
 * "P 4 DOMINION SGI JUAN PEREZ" y "P 12 OLMA SGA JUAN PEREZ" → "JUAN PEREZ".
 */
function sgsPersonaLider(string $nombre): string {
    $n = normalizeImportText($nombre);
    $sinPrefijo = preg_replace('/^P\s*\d+\s+(?:.*?\s)?(?:SG[IA]\s+)+/u', '', $n, 1, $cuantos);
    if (!$cuantos) $sinPrefijo = preg_replace('/^P\s*\d+\s+/u', '', $n);
    return trim($sinPrefijo) !== '' ? trim($sinPrefijo) : $n;
}

/** Filas del Excel → [['fila' => n, 'nombre' => …, 'partner' => …, 'provincia' => …]]. Reconoce encabezados o toma las tres primeras columnas. */
function sgsLeerExcelLideres(string $ruta): array {
    $filas = readXlsxRows($ruta);
    $colNombre = 0; $colPartner = 1; $colZona = 2; $inicio = null;
    foreach (array_slice($filas, 0, 10, true) as $num => $valores) {
        $nombre = $partner = $zona = null;
        foreach ($valores as $c => $v) {
            $t = normalizeImportText((string)$v);
            if ($t === '') continue;
            if ($partner === null && preg_match('/PARTNER|PROVEEDOR|PROVEEDEOR|EMPRESA|CONTRATA/', $t)) $partner = $c;
            elseif ($zona === null && preg_match('/PROVINCIA|ZONA|DEPARTAMENTO|SEDE|CIUDAD|UBICACION/', $t)) $zona = $c;
            elseif ($nombre === null && preg_match('/TECNICO|CUADRILLA|NOMBRE|LIDER|T1/', $t)) $nombre = $c;
        }
        if ($nombre !== null && $partner !== null) { $colNombre = $nombre; $colPartner = $partner; $colZona = $zona; $inicio = $num; break; }
    }
    $resultado = [];
    foreach ($filas as $num => $valores) {
        if ($inicio !== null && $num <= $inicio) continue;
        $a = trim((string)($valores[$colNombre] ?? '')); $b = trim((string)($valores[$colPartner] ?? ''));
        if ($a === '' && $b === '') continue;
        // Sin encabezados y con las columnas al revés: la nomenclatura ("P 3 …") identifica al técnico.
        if ($inicio === null && preg_match('/^P\s*\d+\s/i', $b) && !preg_match('/^P\s*\d+\s/i', $a)) [$a, $b] = [$b, $a];
        // Una primera fila de títulos que no se reconoció.
        if ($inicio === null && !$resultado && preg_match('/TECNICO|CUADRILLA|NOMBRE|LIDER/', normalizeImportText($a))) continue;
        $z = $colZona !== null ? trim((string)($valores[$colZona] ?? '')) : '';
        $resultado[] = ['fila' => (int)$num, 'nombre' => sgsNombreLider($a), 'partner' => trim($b), 'provincia' => $z];
    }
    return $resultado;
}

/** Busca el partner por nombre: exacto o, si no, el único que coincide en parte ("DATANTENNA" ↔ "DATANTENNA SGI"). */
function sgsPartnerPorNombre(array $partners, string $texto): ?array {
    $t = normalizeImportText($texto);
    if ($t === '') return null;
    foreach ($partners as $p) if (normalizeImportText($p['nombre_partner']) === $t) return $p;
    $parecidos = array_values(array_filter($partners, static function ($p) use ($t) {
        $n = normalizeImportText($p['nombre_partner']);
        return str_contains($n, $t) || str_contains($t, $n);
    }));
    return count($parecidos) === 1 ? $parecidos[0] : null;
}

/** "TRUJILLO · LA LIBERTAD" (solo el departamento si la provincia se llama igual o falta; — si no tiene zona). */
function sgsTextoZona(?string $provincia, ?string $departamento): string {
    $p = trim((string)$provincia); $d = trim((string)$departamento);
    if ($d === '') return '—';
    return $p !== '' && normalizeImportText($p) !== normalizeImportText($d) ? "$p · $d" : $d;
}

/** Calcula (y si $aplicar, ejecuta) los cambios. Devuelve el resumen para la vista previa. */
function sgsImportarLideres(PDO $pdo, array $filas, bool $aplicar, int $actorId): array {
    $partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1")->fetchAll(PDO::FETCH_ASSOC);
    $lideres = $pdo->query("SELECT t.id, t.nombre_tecnico, t.partner_id, t.estado_operativo, t.provincia, t.departamento, p.nombre_partner
        FROM tecnicos t LEFT JOIN partners p ON p.id = t.partner_id WHERE COALESCE(t.tipo_tecnico, 1) = 1")->fetchAll(PDO::FETCH_ASSOC);
    $otrosNombres = array_flip(array_map('sgsNombreLider', $pdo->query("SELECT nombre_tecnico FROM tecnicos WHERE tipo_tecnico = 2")->fetchAll(PDO::FETCH_COLUMN)));
    $porNombre = []; $porPersona = [];
    foreach ($lideres as $l) {
        $porNombre[sgsNombreLider($l['nombre_tecnico'])] = $l;
        $porPersona[sgsPersonaLider($l['nombre_tecnico'])][] = $l;
    }

    $r = ['nuevos' => [], 'actualizados' => [], 'reactivados' => [], 'desactivar' => [], 'lista_negra' => [], 'observadas' => [], 'sin_cambios' => 0, 'validas' => 0,
        'cambios_zona' => [], 'zona_no_reconocida' => [], 'por_departamento' => [], 'sin_zona' => 0];
    $zonas = []; // provincia normalizada => zona (o null), para no buscar dos veces
    $usados = []; $vistos = []; $acciones = [];
    $enArchivo = array_flip(array_column($filas, 'nombre'));
    foreach ($filas as $f) {
        $nombre = $f['nombre'];
        if ($nombre === '' || mb_strlen($nombre) < 5) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre ?: '—', 'motivo' => 'Falta el nombre del técnico.']; continue; }
        if (mb_strlen($nombre) > 255) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => mb_substr($nombre, 0, 60) . '…', 'motivo' => 'El nombre es demasiado largo.']; continue; }
        $partner = sgsPartnerPorNombre($partners, $f['partner']);
        // Sin partner en el archivo, pero el técnico ya existe con ese nombre: conserva el suyo (p. ej. uno sin partner).
        if (!$partner && $f['partner'] === '' && isset($porNombre[$nombre])) {
            $partner = ['id' => $porNombre[$nombre]['partner_id'], 'nombre_partner' => $porNombre[$nombre]['nombre_partner'] ?? '—'];
        }
        if (!$partner) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'motivo' => $f['partner'] === '' ? 'Falta el partner.' : "El partner \"{$f['partner']}\" no está registrado."]; continue; }
        if (isset($vistos[$nombre])) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'motivo' => "Repetido en el archivo (fila {$vistos[$nombre]})."]; continue; }
        $vistos[$nombre] = $f['fila'];
        // Zona de la fila: null = no trae provincia o no se reconoce (la zona del técnico no se toca).
        $zona = null; $prov = trim((string)($f['provincia'] ?? ''));
        if ($prov !== '') {
            $clave = normalizeImportText($prov);
            if (!array_key_exists($clave, $zonas)) $zonas[$clave] = sgsZonaDesdeProvincia($prov, $pdo);
            $zona = $zonas[$clave];
            if (!$zona) $r['zona_no_reconocida'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'provincia' => $prov];
        }
        if ($zona) $r['por_departamento'][$zona['departamento']] = ($r['por_departamento'][$zona['departamento']] ?? 0) + 1;
        else $r['sin_zona']++;

        $existente = $porNombre[$nombre] ?? null;
        if (!$existente) {
            // Misma persona con otra nomenclatura: si hay varias, se prefiere la del mismo partner.
            // No se toma a un técnico que viene en el archivo con su propio nombre (ese se reconoce en su fila).
            $libre = static fn($l) => !isset($usados[$l['id']]) && !isset($enArchivo[sgsNombreLider($l['nombre_tecnico'])]);
            $persona = sgsPersonaLider($nombre);
            $candidatos = array_values(array_filter($porPersona[$persona] ?? [], $libre));
            // Nomenclatura sin "SGI/SGA" (p. ej. "P 1 TRASLADO VISUAL DANY …"): la persona es el final del nombre.
            if (!$candidatos && substr_count($persona, ' ') >= 2) { // al menos 3 palabras, para no confundir nombres cortos
                foreach ($porPersona as $clave => $lista) {
                    if ($clave !== $persona && (str_ends_with(' ' . $clave, ' ' . $persona) || str_ends_with(' ' . $persona, ' ' . $clave)) && substr_count($clave, ' ') >= 2) {
                        foreach ($lista as $l) if ($libre($l)) $candidatos[] = $l;
                    }
                }
            }
            if (count($candidatos) > 1) $candidatos = array_values(array_filter($candidatos, static fn($l) => (int)$l['partner_id'] === (int)$partner['id'])) ?: $candidatos;
            if (count($candidatos) > 1) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'motivo' => 'Hay más de un técnico con ese nombre en el sistema; corríjalo a mano en Cuadrillas.']; continue; }
            $existente = $candidatos[0] ?? null;
        }
        if ($existente && isset($usados[$existente['id']])) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'motivo' => 'Corresponde al mismo técnico que otra fila del archivo.']; continue; }
        if (!$existente) {
            if (isset($otrosNombres[$nombre])) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'motivo' => 'Ese nombre ya está registrado como técnico de apoyo (T2).']; continue; }
            $r['nuevos'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'partner' => $partner['nombre_partner'], 'zona' => $zona ? sgsTextoZona($zona['provincia'], $zona['departamento']) : null];
            $acciones[] = ['insertar', $nombre, (int)$partner['id'], $zona];
            $r['validas']++;
            continue;
        }
        $usados[$existente['id']] = true;
        if ($existente['estado_operativo'] === 'Blacklist') {
            $r['lista_negra'][] = ['fila' => $f['fila'], 'nombre' => $existente['nombre_tecnico']];
            $r['validas']++;
            continue;
        }
        $renombrar = sgsNombreLider($existente['nombre_tecnico']) !== $nombre;
        $cambiaPartner = (int)$existente['partner_id'] !== (int)$partner['id'];
        $cambiaZona = $zona && (sgsDepartamentoOficial((string)$existente['departamento']) !== $zona['departamento']
            || normalizeImportText((string)$existente['provincia']) !== normalizeImportText((string)$zona['provincia']));
        if ($cambiaZona) $r['cambios_zona'][] = ['fila' => $f['fila'], 'nombre' => $nombre,
            'antes' => sgsTextoZona($existente['provincia'], $existente['departamento']), 'zona' => sgsTextoZona($zona['provincia'], $zona['departamento'])];
        if ($renombrar && isset($porNombre[$nombre])) { $r['observadas'][] = ['fila' => $f['fila'], 'nombre' => $nombre, 'motivo' => 'Ya existe otro técnico con ese nombre.']; continue; }
        $r['validas']++;
        if ($renombrar || $cambiaPartner) {
            $r['actualizados'][] = ['fila' => $f['fila'], 'antes' => $existente['nombre_tecnico'], 'nombre' => $nombre,
                'partner_antes' => $existente['nombre_partner'] ?? '—', 'partner' => $partner['nombre_partner']];
        }
        if ($existente['estado_operativo'] === 'Inhabilitado') $r['reactivados'][] = ['fila' => $f['fila'], 'nombre' => $nombre];
        if (!$renombrar && !$cambiaPartner && !$cambiaZona && $existente['estado_operativo'] === 'Habilitado') $r['sin_cambios']++;
        else $acciones[] = ['actualizar', (int)$existente['id'], $nombre, $partner['id'] ? (int)$partner['id'] : null, $zona];
    }
    foreach ($lideres as $l) {
        if (!isset($usados[$l['id']]) && $l['estado_operativo'] === 'Habilitado') {
            $r['desactivar'][] = ['nombre' => $l['nombre_tecnico'], 'partner' => $l['nombre_partner'] ?? '—'];
            $acciones[] = ['inhabilitar', (int)$l['id']];
        }
    }
    $habilitados = count(array_filter($lideres, static fn($l) => $l['estado_operativo'] === 'Habilitado'));
    $r['aviso'] = $habilitados > 10 && count($r['desactivar']) > $habilitados / 2
        ? 'Se inhabilitaría más de la mitad de los técnicos líderes habilitados. Revise que el archivo esté completo antes de aplicar.' : null;

    if ($aplicar) {
        if ($r['validas'] === 0) throw new RuntimeException('El archivo no tiene técnicos válidos: no se aplicó ningún cambio.');
        $propia = !$pdo->inTransaction(); // las pruebas la llaman dentro de una transacción que luego deshacen
        if ($propia) $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, tipo_tecnico, estado_operativo, partner_id, provincia, departamento, fecha_registro) VALUES (:n, 1, 'Habilitado', :p, :prov, :dep, NOW())");
            // Sin zona en la fila (:dep NULL) se conserva la que tenía.
            $upd = $pdo->prepare("UPDATE tecnicos SET nombre_tecnico = :n, partner_id = :p, estado_operativo = 'Habilitado',
                provincia = IF(:dep1 IS NULL, provincia, :prov), departamento = COALESCE(:dep2, departamento), fecha_actualizacion = NOW() WHERE id = :id");
            $off = $pdo->prepare("UPDATE tecnicos SET estado_operativo = 'Inhabilitado', fecha_actualizacion = NOW() WHERE id = :id AND estado_operativo = 'Habilitado'");
            foreach ($acciones as $a) {
                if ($a[0] === 'insertar') $ins->execute([':n' => $a[1], ':p' => $a[2], ':prov' => $a[3]['provincia'] ?? null, ':dep' => $a[3]['departamento'] ?? null]);
                elseif ($a[0] === 'actualizar') $upd->execute([':n' => $a[2], ':p' => $a[3], ':id' => $a[1],
                    ':prov' => $a[4]['provincia'] ?? null, ':dep1' => $a[4]['departamento'] ?? null, ':dep2' => $a[4]['departamento'] ?? null]);
                else $off->execute([':id' => $a[1]]);
            }
            if ($propia) $pdo->commit();
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        win_log_error('audit_leaders_import', 'Carga de técnicos líderes por Excel', ['actor_id' => $actorId,
            'nuevos' => count($r['nuevos']), 'actualizados' => count($r['actualizados']), 'reactivados' => count($r['reactivados']), 'inhabilitados' => count($r['desactivar']),
            'cambios_zona' => count($r['cambios_zona'])]);
    }
    return $r;
}

/** Libro .xlsx de una hoja con texto (encabezado en negrita). Devuelve el contenido binario. */
function sgsEscribirXlsx(array $filas, string $hoja = 'Hoja1'): string {
    $col = static function (int $n): string { $s = ''; for ($n++; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s; return $s; };
    $x = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $datos = '';
    foreach (array_values($filas) as $i => $fila) {
        $datos .= '<row r="' . ($i + 1) . '">';
        foreach (array_values($fila) as $j => $v) {
            $datos .= '<c r="' . $col($j) . ($i + 1) . '" t="inlineStr"' . ($i === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        $datos .= '</row>';
    }
    $archivos = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr($hoja, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="60" customWidth="1"/><col min="2" max="2" width="24" customWidth="1"/><col min="3" max="3" width="22" customWidth="1"/></cols><sheetData>' . $datos . '</sheetData></worksheet>',
    ];
    return sgsEmpaquetarZip($archivos); // zip propio: ZipArchive puede faltar y PharData genera archivos que Excel no abre
}
