<?php
// 03_FRONTEND/src/pages/foto_publica.php - Visor de una foto publicada en Teams (2026-10-26), se entra por foto.php?f=…
// f = letra de la carpeta (l = aviso de lluvia, c = corte) + "-" + nombre del archivo sin extensión.
// Solo muestra el título y la imagen; si ya no existe (se borran a los 90 días) lo dice. Estilos: assets/css/sgs-visor-foto.css
$raiz = dirname(__DIR__, 3);
require_once $raiz . '/02_BACKEND/src/config/visor_fotos.php';
header('X-Robots-Tag: noindex, nofollow');

$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$imagen = '';
$titulo = 'Foto no disponible';
if (preg_match('/^([a-z])-(\d{4}-\d{2}-\d{2}_[0-9a-f]{32})$/', (string)($_GET['f'] ?? ''), $m) && isset(SGS_VISOR_CARPETAS[$m[1]])) {
    $carpeta = 'uploads/' . SGS_VISOR_CARPETAS[$m[1]];
    foreach (['jpg', 'png', 'webp'] as $ext) {
        if (is_file("$raiz/$carpeta/{$m[2]}.$ext")) { $imagen = "$carpeta/{$m[2]}.$ext"; break; }
    }
    if ($imagen) $titulo = trim((string)@file_get_contents("$raiz/$carpeta/{$m[2]}.txt")) ?: ($m[1] === 'c' ? 'Corte de lluvias' : 'Foto de la lluvia');
}
if (!$imagen) http_response_code(404);
header('Cache-Control: ' . ($imagen ? 'public, max-age=3600' : 'no-store'));
$css = '03_FRONTEND/src/assets/css/sgs-visor-foto.css';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($titulo) ?> · SGS WIN</title>
<link rel="icon" type="image/svg+xml" href="03_FRONTEND/src/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;700&display=swap">
<link rel="stylesheet" href="<?= $css ?>?v=<?= (int)@filemtime("$raiz/$css") ?>">
</head>
<body class="sgs-visor">
<header class="sgs-visor-cabecera">
    <h1><?= $e($titulo) ?></h1>
    <p><?= $imagen ? 'Pellizque o toque dos veces para ampliar.' : 'La foto ya no está disponible: se guardan 90 días.' ?></p>
</header>
<?php if ($imagen): ?>
<main class="sgs-visor-foto"><img src="<?= $e($imagen) ?>" alt="<?= $e($titulo) ?>"></main>
<?php endif; ?>
<footer class="sgs-visor-pie">SGS · WIN</footer>
</body>
</html>
