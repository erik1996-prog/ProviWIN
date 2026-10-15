<?php
// 02_BACKEND/src/config/excel_lector.php - Lectura de archivos Excel (.xlsx) sin librerías externas.
// Compartido por la carga de cancelaciones y la de técnicos líderes (2026-10-15).

// Mayúsculas y sin tildes. No se usa iconv(ASCII//TRANSLIT): en Windows convierte "Ó" en "'O" y los
// encabezados con tilde (CÓDIGO, GESTIÓN) o zonas (ÁNCASH, JUNÍN) dejaban de reconocerse.
function normalizeImportText(string $value): string {
    $value = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    return strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U', 'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U']);
}

/**
 * Quita los prefijos de las etiquetas XML (<x:row> → <row>). Excel guarda sin prefijo, pero otros programas (y la
 * plantilla que descarga el sistema) guardan con prefijo, y así no se encontraba ninguna fila (2026-10-12).
 */
function xmlSinPrefijos($xml) {
    if (!is_string($xml) || $xml === '') return $xml;
    return preg_replace('/<(\/?)[A-Za-z_][\w.-]*:(?=[A-Za-z_])/', '<$1', $xml);
}

function readXlsxRows(string $path): array {
    $sharedXml = false;
    $sheetXml = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('El archivo Excel no se pudo abrir.');
        $leer = static fn(string $nombre) => xmlSinPrefijos($zip->getFromName($nombre));
        $sharedXml = $leer('xl/sharedStrings.xml');
        $sheetXml = $leer(primeraHojaXlsx($leer));
        $zip->close();
    } else {
        $archive = new PharData($path);
        $leer = static fn(string $nombre) => isset($archive[$nombre]) ? xmlSinPrefijos($archive[$nombre]->getContent()) : false;
        $sharedXml = $leer('xl/sharedStrings.xml');
        $sheetXml = $leer(primeraHojaXlsx($leer));
    }
    $shared = [];
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si as $item) {
            $texts = [];
            if (isset($item->t)) $texts[] = (string)$item->t;
            foreach ($item->r as $run) $texts[] = (string)$run->t;
            $shared[] = implode('', $texts);
        }
    }
    if ($sheetXml === false) throw new RuntimeException('No se encontró la hoja de datos en el archivo Excel.');
    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    $maxColumnas = 200;   // las exportaciones traen muchas columnas; antes solo se leían 20 (A a T)
    $filaAnterior = 0;
    foreach ($xml->sheetData->row as $row) {
        // Algunos programas no numeran filas ni celdas: se usa la posición.
        $rowNumber = (int)$row['r'] ?: $filaAnterior + 1;
        $filaAnterior = $rowNumber;
        $values = array_fill(0, $maxColumnas, '');
        $posicion = -1;
        foreach ($row->c as $cell) {
            $posicion++;
            $ref = (string)$cell['r'];
            preg_match('/^[A-Z]+/', $ref, $match);
            $letters = $match[0] ?? '';
            if ($letters === '') {
                $column = $posicion;
            } else {
                $column = 0;
                for ($i = 0; $i < strlen($letters); $i++) {
                    $column = $column * 26 + (ord($letters[$i]) - 64);
                }
                $column--;
                $posicion = $column;
            }
            if ($column < 0 || $column >= $maxColumnas) continue;
            $type = (string)$cell['t'];
            if ($type === 's') $value = $shared[(int)$cell->v] ?? '';
            elseif ($type === 'inlineStr') $value = (string)$cell->is->t;
            else $value = (string)$cell->v;
            $values[$column] = trim((string)$value);
        }
        $rows[$rowNumber] = $values;
    }
    return $rows;
}

/** Ruta interna de la primera hoja del libro, según workbook.xml (no siempre es sheet1.xml). */
function primeraHojaXlsx(callable $leer): string {
    $workbook = $leer('xl/workbook.xml');
    $rels = $leer('xl/_rels/workbook.xml.rels');
    if ($workbook && $rels && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $m)
        && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*\bTarget="([^"]+)"/', $rels, $t)
        || ($workbook && $rels && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $m)
            && preg_match('/<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="' . preg_quote($m[1], '/') . '"/', $rels, $t))) {
        $destino = ltrim($t[1], '/');
        return str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
    }
    return 'xl/worksheets/sheet1.xml';
}

/**
 * Empaqueta archivos en un .zip estándar (deflate) sin ZipArchive ni PharData (2026-10-15): el PHP local no trae
 * ZipArchive, y los .xlsx armados con PharData no los abre Excel. Recibe ['ruta/interna' => contenido].
 */
function sgsEmpaquetarZip(array $archivos): string {
    $locales = ''; $central = ''; $n = 0;
    [$hora, $fecha] = [((int)date('H') << 11) | ((int)date('i') << 5) | intdiv((int)date('s'), 2),
        (((int)date('Y') - 1980) << 9) | ((int)date('n') << 5) | (int)date('j')];
    foreach ($archivos as $nombre => $contenido) {
        $datos = gzdeflate($contenido, 6);
        $crc = crc32($contenido);
        $comun = pack('vvvvvVVV', 20, 0x0800, 8, $hora, $fecha, $crc, strlen($datos), strlen($contenido)) . pack('v', strlen($nombre));
        $offset = strlen($locales);
        $locales .= pack('V', 0x04034b50) . $comun . pack('v', 0) . $nombre . $datos;
        $central .= pack('Vv', 0x02014b50, 20) . $comun . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $nombre;
        $n++;
    }
    return $locales . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($locales), 0);
}
