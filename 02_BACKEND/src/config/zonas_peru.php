<?php
// 02_BACKEND/src/config/zonas_peru.php - Zona de los técnicos (2026-10-15): de la provincia al departamento.
// El Excel de técnicos líderes trae la PROVINCIA; aquí se busca a qué departamento pertenece. Los 25 departamentos y
// sus 196 provincias (INEI), más algunas ciudades que se usan como zona aunque no son provincia (CHIMBOTE → ÁNCASH).
// El departamento se guarda con tilde y en mayúsculas, igual que en el resto del sistema (ÁNCASH, JUNÍN…).
require_once __DIR__ . '/excel_lector.php';
require_once __DIR__ . '/territorial.php'; // sgsZonaDeUbicacion: Huaura, Huaral y Barranca son la zona NORTE CHICO

/** Departamento => provincias. */
function sgsProvinciasPorDepartamento(): array {
    return [
        'AMAZONAS' => ['CHACHAPOYAS', 'BAGUA', 'BONGARÁ', 'CONDORCANQUI', 'LUYA', 'RODRÍGUEZ DE MENDOZA', 'UTCUBAMBA'],
        'ÁNCASH' => ['HUARAZ', 'AIJA', 'ANTONIO RAIMONDI', 'ASUNCIÓN', 'BOLOGNESI', 'CARHUAZ', 'CARLOS FERMÍN FITZCARRALD', 'CASMA', 'CORONGO',
            'HUARI', 'HUARMEY', 'HUAYLAS', 'MARISCAL LUZURIAGA', 'OCROS', 'PALLASCA', 'POMABAMBA', 'RECUAY', 'SANTA', 'SIHUAS', 'YUNGAY'],
        'APURÍMAC' => ['ABANCAY', 'ANDAHUAYLAS', 'ANTABAMBA', 'AYMARAES', 'COTABAMBAS', 'CHINCHEROS', 'GRAU'],
        'AREQUIPA' => ['AREQUIPA', 'CAMANÁ', 'CARAVELÍ', 'CASTILLA', 'CAYLLOMA', 'CONDESUYOS', 'ISLAY', 'LA UNIÓN'],
        'AYACUCHO' => ['HUAMANGA', 'CANGALLO', 'HUANCA SANCOS', 'HUANTA', 'LA MAR', 'LUCANAS', 'PARINACOCHAS', 'PÁUCAR DEL SARA SARA', 'SUCRE',
            'VÍCTOR FAJARDO', 'VILCAS HUAMÁN'],
        'CAJAMARCA' => ['CAJAMARCA', 'CAJABAMBA', 'CELENDÍN', 'CHOTA', 'CONTUMAZÁ', 'CUTERVO', 'HUALGAYOC', 'JAÉN', 'SAN IGNACIO', 'SAN MARCOS',
            'SAN MIGUEL', 'SAN PABLO', 'SANTA CRUZ'],
        'CALLAO' => ['CALLAO'],
        'CUSCO' => ['CUSCO', 'ACOMAYO', 'ANTA', 'CALCA', 'CANAS', 'CANCHIS', 'CHUMBIVILCAS', 'ESPINAR', 'LA CONVENCIÓN', 'PARURO', 'PAUCARTAMBO',
            'QUISPICANCHI', 'URUBAMBA'],
        'HUANCAVELICA' => ['HUANCAVELICA', 'ACOBAMBA', 'ANGARAES', 'CASTROVIRREYNA', 'CHURCAMPA', 'HUAYTARÁ', 'TAYACAJA'],
        'HUÁNUCO' => ['HUÁNUCO', 'AMBO', 'DOS DE MAYO', 'HUACAYBAMBA', 'HUAMALÍES', 'LEONCIO PRADO', 'MARAÑÓN', 'PACHITEA', 'PUERTO INCA',
            'LAURICOCHA', 'YAROWILCA'],
        'ICA' => ['ICA', 'CHINCHA', 'NASCA', 'PALPA', 'PISCO'],
        'JUNÍN' => ['HUANCAYO', 'CONCEPCIÓN', 'CHANCHAMAYO', 'JAUJA', 'JUNÍN', 'SATIPO', 'TARMA', 'YAULI', 'CHUPACA'],
        'LA LIBERTAD' => ['TRUJILLO', 'ASCOPE', 'BOLÍVAR', 'CHEPÉN', 'JULCÁN', 'OTUZCO', 'PACASMAYO', 'PATAZ', 'SÁNCHEZ CARRIÓN', 'SANTIAGO DE CHUCO',
            'GRAN CHIMÚ', 'VIRÚ'],
        'LAMBAYEQUE' => ['CHICLAYO', 'FERREÑAFE', 'LAMBAYEQUE'],
        'LIMA' => ['LIMA', 'BARRANCA', 'CAJATAMBO', 'CANTA', 'CAÑETE', 'HUARAL', 'HUAROCHIRÍ', 'HUAURA', 'OYÓN', 'YAUYOS'],
        'LORETO' => ['MAYNAS', 'ALTO AMAZONAS', 'LORETO', 'MARISCAL RAMÓN CASTILLA', 'REQUENA', 'UCAYALI', 'DATEM DEL MARAÑÓN', 'PUTUMAYO'],
        'MADRE DE DIOS' => ['TAMBOPATA', 'MANU', 'TAHUAMANU'],
        'MOQUEGUA' => ['MARISCAL NIETO', 'GENERAL SÁNCHEZ CERRO', 'ILO'],
        'PASCO' => ['PASCO', 'DANIEL ALCIDES CARRIÓN', 'OXAPAMPA'],
        'PIURA' => ['PIURA', 'AYABACA', 'HUANCABAMBA', 'MORROPÓN', 'PAITA', 'SULLANA', 'TALARA', 'SECHURA'],
        'PUNO' => ['PUNO', 'AZÁNGARO', 'CARABAYA', 'CHUCUITO', 'EL COLLAO', 'HUANCANÉ', 'LAMPA', 'MELGAR', 'MOHO', 'SAN ANTONIO DE PUTINA',
            'SAN ROMÁN', 'SANDIA', 'YUNGUYO'],
        'SAN MARTÍN' => ['MOYOBAMBA', 'BELLAVISTA', 'EL DORADO', 'HUALLAGA', 'LAMAS', 'MARISCAL CÁCERES', 'PICOTA', 'RIOJA', 'SAN MARTÍN', 'TOCACHE'],
        'TACNA' => ['TACNA', 'CANDARAVE', 'JORGE BASADRE', 'TARATA'],
        'TUMBES' => ['TUMBES', 'CONTRALMIRANTE VILLAR', 'ZARUMILLA'],
        'UCAYALI' => ['CORONEL PORTILLO', 'ATALAYA', 'PADRE ABAD', 'PURÚS'],
    ];
}

/** Ciudades que se usan como zona sin ser provincia (o con otra forma de escribirse) => [provincia, departamento]. */
function sgsCiudadesZona(): array {
    return [
        'CHIMBOTE' => ['SANTA', 'ÁNCASH'], 'NUEVO CHIMBOTE' => ['SANTA', 'ÁNCASH'],
        'NAZCA' => ['NASCA', 'ICA'], 'PUCALLPA' => ['CORONEL PORTILLO', 'UCAYALI'], 'JULIACA' => ['SAN ROMÁN', 'PUNO'],
        'TARAPOTO' => ['SAN MARTÍN', 'SAN MARTÍN'], 'IQUITOS' => ['MAYNAS', 'LORETO'], 'PUERTO MALDONADO' => ['TAMBOPATA', 'MADRE DE DIOS'],
        'CERRO DE PASCO' => ['PASCO', 'PASCO'], 'LA OROYA' => ['YAULI', 'JUNÍN'], 'HUACHO' => ['HUAURA', 'LIMA'], 'MOLLENDO' => ['ISLAY', 'AREQUIPA'],
        'MOYOBAMBA' => ['MOYOBAMBA', 'SAN MARTÍN'], 'TINGO MARIA' => ['LEONCIO PRADO', 'HUÁNUCO'], 'BAGUA GRANDE' => ['UTCUBAMBA', 'AMAZONAS'],
    ];
}

/**
 * Zona a partir de lo que trae el Excel (provincia, ciudad o departamento).
 * Devuelve ['provincia' => …, 'departamento' => …] o null si no se reconoce. 'departamento' es la ZONA de trabajo
 * (2026-10-27): el departamento, salvo las provincias con zona propia (LIMA / HUAURA → NORTE CHICO).
 * Orden: departamento (UCAYALI es departamento y también provincia de Loreto: gana el departamento) → provincia →
 * ciudad conocida → distrito registrado en la tabla ubigeo (si se pasa $pdo).
 */
function sgsZonaDesdeProvincia(string $texto, ?PDO $pdo = null): ?array {
    $t = normalizeImportText($texto);
    $t = trim(preg_replace('/^(PROVINCIA|PROV\.?|DEPARTAMENTO|DPTO\.?|DEP\.?)\s+(DE\s+)?/u', '', $t));
    if ($t === '') return null;
    static $indice = null;
    if ($indice === null) {
        $indice = ['dep' => [], 'prov' => []];
        foreach (sgsProvinciasPorDepartamento() as $dep => $provincias) {
            $indice['dep'][normalizeImportText($dep)] = $dep;
            foreach ($provincias as $p) $indice['prov'][normalizeImportText($p)][] = [$p, $dep];
        }
    }
    if (isset($indice['dep'][$t])) {
        $dep = $indice['dep'][$t];
        // Departamento que también es provincia propia (PIURA, ICA…): se guarda la provincia; si no, solo el departamento.
        foreach ($indice['prov'][$t] ?? [] as [$p, $d]) if ($d === $dep) return ['provincia' => $p, 'departamento' => sgsZonaDeUbicacion($dep, $p)];
        return ['provincia' => null, 'departamento' => $dep];
    }
    if (isset($indice['prov'][$t]) && count($indice['prov'][$t]) === 1) {
        [$p, $d] = $indice['prov'][$t][0];
        return ['provincia' => $p, 'departamento' => sgsZonaDeUbicacion($d, $p)];
    }
    foreach (sgsCiudadesZona() as $ciudad => [$p, $d]) if (normalizeImportText($ciudad) === $t) return ['provincia' => $p, 'departamento' => sgsZonaDeUbicacion($d, $p)];
    if ($pdo) {
        // Distritos de operación (tabla ubigeo). Se corrige el departamento con la lista oficial por si la tabla tiene otro.
        foreach ($pdo->query('SELECT DISTINCT departamento, provincia, distrito FROM ubigeo')->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (normalizeImportText((string)$u['distrito']) !== $t) continue;
            $prov = normalizeImportText((string)$u['provincia']);
            if (isset($indice['prov'][$prov]) && count($indice['prov'][$prov]) === 1) {
                [$p, $d] = $indice['prov'][$prov][0];
                return ['provincia' => $p, 'departamento' => sgsZonaDeUbicacion($d, $p)];
            }
        }
    }
    return null;
}

/** Departamento con la forma oficial ("Ancash" → "ÁNCASH"); si no es un departamento, lo devuelve en mayúsculas. */
function sgsDepartamentoOficial(string $texto): string {
    $t = normalizeImportText($texto);
    foreach (array_keys(sgsProvinciasPorDepartamento()) as $dep) if (normalizeImportText($dep) === $t) return $dep;
    return mb_strtoupper(trim($texto), 'UTF-8');
}

/**
 * Ubicación que llega de un archivo o de un formulario, contra el catálogo de ubicaciones de trabajo (tabla ubigeo, la
 * misma del formulario de supervisión) (2026-10-26). Compara sin tildes ni mayúsculas y devuelve los nombres tal como
 * están en el catálogo. Si algo no existe, 'error' dice qué y, si es de otro lugar del Perú, dónde está
 * ("HUANCAYO no es un departamento: es una provincia de JUNÍN"). Provincia y distrito vacíos no se revisan.
 * @return array{departamento: string, provincia: string, distrito: string, error: string}
 */
function sgsValidarUbicacion(PDO $pdo, string $departamento, string $provincia = '', string $distrito = ''): array {
    static $catalogo = null;
    $catalogo ??= $pdo->query('SELECT departamento, provincia, distrito FROM ubigeo')->fetchAll(PDO::FETCH_ASSOC);
    $n = static fn(string $v) => normalizeImportText($v);
    [$dep, $prov, $dist] = array_map(static fn($v) => mb_strtoupper(trim($v), 'UTF-8'), [$departamento, $provincia, $distrito]);
    $r = ['departamento' => $dep, 'provincia' => $prov, 'distrito' => $dist, 'error' => ''];
    $falla = static fn(string $msg) => ['error' => $msg] + $r;
    // Dónde está una provincia en el Perú (lista oficial), para orientar el mensaje
    $depDeProvincia = static function (string $p) use ($n): string {
        foreach (sgsProvinciasPorDepartamento() as $d => $lista) foreach ($lista as $x) if ($n($x) === $n($p)) return $d;
        return '';
    };

    if ($dep === '') return $falla('Falta el departamento.');
    $filas = array_values(array_filter($catalogo, static fn($u) => $n($u['departamento']) === $n($dep)));
    if (!$filas) {
        if (in_array($n($dep), array_map($n, array_keys(sgsProvinciasPorDepartamento())), true)) {
            return $falla("El departamento {$dep} no es una zona de trabajo del sistema.");
        }
        $donde = $depDeProvincia($dep);
        return $falla($donde !== '' ? "{$dep} no es un departamento: es una provincia de {$donde}." : "El departamento {$dep} no existe.");
    }
    $r['departamento'] = $filas[0]['departamento'];

    if ($prov !== '') {
        $deProv = array_values(array_filter($filas, static fn($u) => $n($u['provincia']) === $n($prov)));
        if (!$deProv) {
            $donde = $depDeProvincia($prov);
            if ($donde !== '' && $n($donde) !== $n($r['departamento'])) return $falla("La provincia {$prov} no es de {$r['departamento']}: es de {$donde}.");
            return $falla($donde !== '' ? "La provincia {$prov} ({$r['departamento']}) no está en las ubicaciones de trabajo del sistema."
                : "La provincia {$prov} no existe en {$r['departamento']}.");
        }
        $r['provincia'] = $deProv[0]['provincia'];
        $filas = $deProv;
    }

    if ($dist !== '') {
        $deDist = array_values(array_filter($filas, static fn($u) => $n($u['distrito']) === $n($dist)));
        if (!$deDist) {
            $otro = array_values(array_filter($catalogo, static fn($u) => $n($u['distrito']) === $n($dist)));
            $lugar = $prov !== '' ? "la provincia {$r['provincia']} ({$r['departamento']})" : $r['departamento'];
            return $falla($otro ? "El distrito {$dist} no es de {$lugar}: está en {$otro[0]['provincia']} ({$otro[0]['departamento']})."
                : "El distrito {$dist} no existe en {$lugar}.");
        }
        $r['distrito'] = $deDist[0]['distrito'];
        if ($prov === '') $r['provincia'] = $deDist[0]['provincia'];
    }
    return $r;
}
