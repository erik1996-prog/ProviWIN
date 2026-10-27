<?php
// 07_SCRIPTS/corte_lluvias.php - Corte de lluvias en el grupo de Teams (2026-10-23): lluvias de los últimos 7 días
// (hasta ayer antes de las 12:00; desde las 12:00 incluye hoy).
// Los horarios salen solos con las visitas al sistema (index.php, 2026-10-26). El cron es opcional, para que salgan a la hora
// exacta aunque nadie esté usando el sistema: en cPanel → Cron Jobs, una sola tarea cada 15 minutos que envía los horarios configurados en
// Indicadores › Clima › "Programar envíos" (tabla lluvias_cortes_programados). Si no toca enviar, no hace nada.
//   */15 * * * *  php ~/public_html/ProviWIN/07_SCRIPTS/corte_lluvias.php --programados --url=https://dominio/ProviWIN
// Con --url la imagen va por enlace (nítida y ampliable). Usa el grupo de 09_CONFIG/teams_lluvias.local.txt.
// Envío inmediato (pruebas): php 07_SCRIPTS/corte_lluvias.php [--url=https://…] [--hasta=AAAA-MM-DD]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
require BACKEND_PATH . '/config/lluvias_corte.php';
$op = getopt('', ['url::', 'hasta::', 'programados']);
$url = preg_match('#^https://\S+$#', (string)($op['url'] ?? '')) ? $op['url'] : null;
$pdo = Database::getConnection();
if (isset($op['programados'])) {
    $hechos = sgsEnviarCortesProgramados($pdo, $url);
    foreach ($hechos as $hora => $ok) echo date('Y-m-d H:i:s') . " · corte de las $hora: " . ($ok ? 'publicado en Teams' : 'no se publicó (se reintenta en 15 min)') . "\n";
    exit(in_array(false, $hechos, true) ? 1 : 0);
}
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($op['hasta'] ?? '')) ? $op['hasta'] : sgsHastaDelCorte();
$ok = sgsPublicarCorteLluvias($pdo, $hasta, $url);
echo date('Y-m-d H:i:s') . " · corte de lluvias al $hasta: " . ($ok ? 'publicado en Teams' : 'no se publicó (revise 09_CONFIG/teams_lluvias.local.txt y 08_LOGS)') . "\n";
exit($ok ? 0 : 1);
