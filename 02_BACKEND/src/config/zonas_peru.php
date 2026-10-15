<?php
// 02_BACKEND/src/config/zonas_peru.php - Zona de los técnicos (2026-10-15): de la provincia al departamento.
// El Excel de técnicos líderes trae la PROVINCIA; aquí se busca a qué departamento pertenece. Los 25 departamentos y
// sus 196 provincias (INEI), más algunas ciudades que se usan como zona aunque no son provincia (CHIMBOTE → ÁNCASH).
// El departamento se guarda con tilde y en mayúsculas, igual que en el resto del sistema (ÁNCASH, JUNÍN…).
require_once __DIR__ . '/excel_lector.php';

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
 * Devuelve ['provincia' => …, 'departamento' => …] o null si no se reconoce.
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
        foreach ($indice['prov'][$t] ?? [] as [$p, $d]) if ($d === $dep) return ['provincia' => $p, 'departamento' => $dep];
        return ['provincia' => null, 'departamento' => $dep];
    }
    if (isset($indice['prov'][$t]) && count($indice['prov'][$t]) === 1) {
        [$p, $d] = $indice['prov'][$t][0];
        return ['provincia' => $p, 'departamento' => $d];
    }
    foreach (sgsCiudadesZona() as $ciudad => [$p, $d]) if (normalizeImportText($ciudad) === $t) return ['provincia' => $p, 'departamento' => $d];
    if ($pdo) {
        // Distritos de operación (tabla ubigeo). Se corrige el departamento con la lista oficial por si la tabla tiene otro.
        foreach ($pdo->query('SELECT DISTINCT departamento, provincia, distrito FROM ubigeo')->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (normalizeImportText((string)$u['distrito']) !== $t) continue;
            $prov = normalizeImportText((string)$u['provincia']);
            if (isset($indice['prov'][$prov]) && count($indice['prov'][$prov]) === 1) {
                [$p, $d] = $indice['prov'][$prov][0];
                return ['provincia' => $p, 'departamento' => $d];
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
