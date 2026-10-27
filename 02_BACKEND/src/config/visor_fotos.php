<?php
// 02_BACKEND/src/config/visor_fotos.php - Visor de las imágenes publicadas en el grupo de Teams (2026-10-26).
// En el celular, al tocar la foto de un aviso de lluvia o el cuadro del corte, se abre foto.php?f=… (página pública y
// liviana, sin base de datos ni sesión) en vez del archivo suelto: fondo oscuro, título y la imagen, que se amplía con
// los dedos. El título va en un .txt al lado de la imagen (los .txt están bloqueados por web en .htaccess).
// Las imágenes y sus títulos se borran a los 90 días (lluvias.php y lluvias_corte.php).

/** Letra del enlace => carpeta de uploads/. */
const SGS_VISOR_CARPETAS = ['l' => 'lluvias_teams', 'c' => 'cortes_lluvia'];

/** Guarda el título que muestra el visor para la imagen $rutaImagen (archivo en disco). */
function sgsTituloDeImagen(string $rutaImagen, string $titulo): void {
    @file_put_contents(preg_replace('/\.\w+$/', '.txt', $rutaImagen), $titulo);
}

/** Enlace del visor para una imagen publicada en https://…/uploads/(lluvias_teams|cortes_lluvia)/; '' si no es una de ellas. */
function sgsEnlaceVisor(string $urlImagen): string {
    $carpetas = implode('|', SGS_VISOR_CARPETAS);
    if (!preg_match("#^(https://.+)/uploads/($carpetas)/(\d{4}-\d{2}-\d{2}_[0-9a-f]{32})\.\w+$#", $urlImagen, $m)) return '';
    return $m[1] . '/foto.php?f=' . array_search($m[2], SGS_VISOR_CARPETAS, true) . '-' . $m[3];
}
