<?php
// 07_SCRIPTS/generar_diagrama.php - Regenera el diagrama de relaciones 04_DATABASE/03_ESTRUCTURA/diagrama_relaciones.svg
// desde la base actual (solo lectura). Requiere Node.js (dibuja 07_SCRIPTS/diagrama_er.js). Uso: php 07_SCRIPTS/generar_diagrama.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
$pdo = Database::getConnection();
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
// Siempre filtrar por esta base: recorrer information_schema de todas las bases puede tumbar MariaDB 10.4
$q = function (string $sql) use ($pdo, $db) { $st = $pdo->prepare($sql); $st->execute([':db' => $db]); return $st->fetchAll(PDO::FETCH_ASSOC); };

// Claves foráneas: KEY_COLUMN_USAGE filtrado (sin JOIN con REFERENTIAL_CONSTRAINTS, que tumba MariaDB 10.4);
// la regla ON DELETE se lee de SHOW CREATE TABLE.
$relaciones = $q("SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc, CONSTRAINT_NAME k FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME");
$create = [];
foreach ($relaciones as &$r) {
    $create[$r['t']] ??= $pdo->query("SHOW CREATE TABLE `{$r['t']}`")->fetch(PDO::FETCH_NUM)[1];
    $r['d'] = preg_match('/CONSTRAINT `' . preg_quote($r['k'], '/') . '`[^
]*ON DELETE (CASCADE|SET NULL|RESTRICT|NO ACTION)/', $create[$r['t']], $m) ? $m[1] : 'RESTRICT';
    unset($r['k']);
}
unset($r);
$out = ['generado' => date('Y-m-d H:i'), 'tablas' => [], 'relaciones' => $relaciones];
foreach ($q("SELECT TABLE_NAME n, TABLE_COMMENT c FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
    $out['tablas'][$t['n']] = ['filas' => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['n']}`")->fetchColumn(), 'sgs' => true, 'comentario' => $t['c'], 'columnas' => []];
}
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME n, COLUMN_TYPE ty, IS_NULLABLE nu, COLUMN_KEY k, COLUMN_COMMENT c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION") as $c) {
    if (isset($out['tablas'][$c['t']])) $out['tablas'][$c['t']]['columnas'][] = [$c['n'], $c['ty'], $c['nu'] === 'YES', $c['k'], $c['c']];
}
$json = tempnam(sys_get_temp_dir(), 'sgsdiag');
file_put_contents($json, json_encode($out, JSON_UNESCAPED_UNICODE));
$svg = BASE_PATH . '/04_DATABASE/03_ESTRUCTURA/diagrama_relaciones.svg';
passthru('node ' . escapeshellarg(__DIR__ . '/diagrama_er.js') . ' ' . escapeshellarg($json) . ' ' . escapeshellarg($svg), $code);
@unlink($json);
if ($code !== 0) exit("No se pudo dibujar el diagrama (¿está instalado Node.js?).\n");
echo "Diagrama: 04_DATABASE/03_ESTRUCTURA/diagrama_relaciones.svg\n";
