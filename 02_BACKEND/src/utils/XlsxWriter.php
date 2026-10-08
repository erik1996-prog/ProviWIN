<?php
// 02_BACKEND/src/utils/XlsxWriter.php - Genera un .xlsx simple (una hoja, encabezado con estilo, filtros y fila fija).
// No depende de ZipArchive (la extensión zip no está habilitada en el servidor): arma el paquete con PharData.

final class XlsxWriter
{
    /**
     * @param string[] $headers  Títulos de columna
     * @param array<int,array<int,string|int|float|null>> $rows
     * @param int[] $widths  Ancho de cada columna (caracteres)
     * @return string Contenido binario del archivo .xlsx
     */
    public static function build(string $sheetName, array $headers, array $rows, array $widths = []): string
    {
        $sheetName = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $sheetName), 0, 31) ?: 'Hoja1';
        $tmp = tempnam(sys_get_temp_dir(), 'sgsx');
        @unlink($tmp);
        $zipPath = $tmp . '.zip';
        try {
            $zip = new PharData($zipPath, 0, null, Phar::ZIP);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
                . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">' . self::esc("'" . str_replace("'", "''", $sheetName) . "'")
                . '!$A$1:$' . self::col(max(1, count($headers))) . '$' . (count($rows) + 1) . '</definedName></definedNames>'
                . '</workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>');
            // Estilo 1 = encabezado (negrita blanca sobre gris oscuro WIN); estilo 2 = celda con ajuste de texto
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF202731"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="2"><border/><border><left style="thin"><color rgb="FFDDE1E6"/></left><right style="thin"><color rgb="FFDDE1E6"/></right>'
                . '<top style="thin"><color rgb="FFDDE1E6"/></top><bottom style="thin"><color rgb="FFDDE1E6"/></bottom></border></borders>'
                . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
                . '<cellXfs count="3"><xf/>'
                . '<xf fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
                . '<xf borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
            if ($widths) {
                $xml .= '<cols>';
                foreach (array_values($widths) as $i => $w) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
                $xml .= '</cols>';
            }
            $xml .= '<sheetData>' . self::row(1, $headers, 1);
            foreach (array_values($rows) as $i => $r) $xml .= self::row($i + 2, array_values($r), 2);
            $xml .= '</sheetData><autoFilter ref="A1:' . self::col(max(1, count($headers))) . (count($rows) + 1) . '"/></worksheet>';
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->compressFiles(Phar::GZ);
            unset($zip);
            return (string)file_get_contents($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    private static function row(int $n, array $cells, int $style): string
    {
        $out = '<row r="' . $n . '">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i + 1) . $n;
            if ($v === null || $v === '') { $out .= '<c r="' . $ref . '" s="' . $style . '"/>'; continue; }
            if ((is_int($v) || is_float($v)) && is_finite((float)$v)) { $out .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>'; continue; }
            $out .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
        }
        return $out . '</row>';
    }

    private static function col(int $n): string
    {
        $s = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
        return $s;
    }

    private static function esc(string $v): string
    {
        // Quita caracteres de control no válidos en XML y limita al máximo de Excel por celda
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
        return htmlspecialchars(mb_substr($v, 0, 32000), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
