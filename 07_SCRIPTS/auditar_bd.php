<?php
// 07_SCRIPTS/auditar_bd.php - Revisa la base de datos contra el estándar (01_DOCUMENTACION/03_ARQUITECTURA/ESTANDAR_DESARROLLO.md).
// SOLO LECTURA: consulta information_schema, no modifica nada. Uso (solo CLI):
//   php 07_SCRIPTS/auditar_bd.php           informe en pantalla
//   php 07_SCRIPTS/auditar_bd.php --sql     además imprime el SQL sugerido para corregir (no lo ejecuta)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
$pdo = Database::getConnection();
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$conSql = in_array('--sql', $argv, true);
$q = function (string $sql) use ($pdo, $db): array { $st = $pdo->prepare($sql); $st->execute([':db' => $db]); return $st->fetchAll(PDO::FETCH_ASSOC); };

$hallazgos = []; $sugerido = [];
$add = function (string $nivel, string $regla, string $objeto, string $detalle) use (&$hallazgos) { $hallazgos[] = compact('nivel', 'regla', 'objeto', 'detalle'); };

// R1. Motor InnoDB (transacciones y claves foráneas)
foreach ($q("SELECT TABLE_NAME t, ENGINE e FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'") as $r)
    { $add('ALTO', 'R1 motor InnoDB', $r['t'], "usa {$r['e']}"); $sugerido[] = "ALTER TABLE `{$r['t']}` ENGINE=InnoDB;"; }

// R2. Juego de caracteres utf8mb4 (tildes, ñ y emojis sin pérdida)
foreach ($q("SELECT TABLE_NAME t, TABLE_COLLATION c FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_TYPE='BASE TABLE' AND TABLE_COLLATION NOT LIKE 'utf8mb4%'") as $r)
    { $add('MEDIO', 'R2 utf8mb4', $r['t'], "cotejamiento {$r['c']}"); $sugerido[] = "ALTER TABLE `{$r['t']}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; }
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c, CHARACTER_SET_NAME s FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME<>'utf8mb4'") as $r)
    $add('MEDIO', 'R2 utf8mb4', "{$r['t']}.{$r['c']}", "columna en {$r['s']}");

// R3. Toda tabla con clave primaria
foreach ($q("SELECT t.TABLE_NAME t FROM information_schema.TABLES t LEFT JOIN information_schema.TABLE_CONSTRAINTS c
    ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.CONSTRAINT_TYPE='PRIMARY KEY'
    WHERE t.TABLE_SCHEMA=:db AND t.TABLE_TYPE='BASE TABLE' AND c.CONSTRAINT_NAME IS NULL") as $r)
    $add('ALTO', 'R3 clave primaria', $r['t'], 'no tiene PRIMARY KEY');

// R4. Columnas *_id sin clave foránea (relación no garantizada por la BD)
$fk = [];
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND REFERENCED_TABLE_NAME IS NOT NULL") as $r) $fk["{$r['t']}.{$r['c']}"] = true;
$tablas = array_column($q("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_TYPE='BASE TABLE'"), 't');
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND COLUMN_NAME LIKE '%\\_id' AND COLUMN_KEY<>'PRI'") as $r)
    if (empty($fk["{$r['t']}.{$r['c']}"])) $add('BAJO', 'R4 clave foránea', "{$r['t']}.{$r['c']}", 'parece una relación pero no tiene FOREIGN KEY');

// R5. Columnas de relación o filtro sin índice. fecha_registro/estado solo cuentan en tablas grandes (>= 10 000 filas):
// en tablas chicas un índice no acelera nada y sí frena las escrituras.
$filas = array_column($q("SELECT TABLE_NAME t, TABLE_ROWS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db"), "n", "t");
$indexadas = [];
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=:db AND SEQ_IN_INDEX=1") as $r) $indexadas["{$r['t']}.{$r['c']}"] = true;
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db
    AND (COLUMN_NAME LIKE '%\\_id' OR COLUMN_NAME IN ('fecha_registro','estado','dni','fecha'))") as $r) {
    if (!empty($indexadas["{$r['t']}.{$r['c']}"]) || !in_array($r['t'], $tablas, true)) continue;
    if (in_array($r['c'], ['fecha_registro', 'estado'], true) && (int)($filas[$r['t']] ?? 0) < 10000) continue;
    $add('MEDIO', 'R5 índice', "{$r['t']}.{$r['c']}", 'se usa para unir o filtrar y no tiene índice');
    $sugerido[] = "CREATE INDEX `IX_{$r['t']}_{$r['c']}` ON `{$r['t']}` (`{$r['c']}`);";
}

// R6. Índices duplicados (mismas columnas iniciales): ocupan espacio y frenan escrituras
$idx = [];
foreach ($q("SELECT TABLE_NAME t, INDEX_NAME i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=:db GROUP BY TABLE_NAME, INDEX_NAME") as $r) $idx[$r['t']][$r['i']] = $r['cols'];
foreach ($idx as $t => $lista) foreach ($lista as $i => $cols) foreach ($lista as $j => $otras)
    if ($i !== $j && $i !== 'PRIMARY' && str_starts_with($otras . ',', $cols . ',') && ($cols !== $otras || strcmp($i, $j) > 0))
        { $add('BAJO', 'R6 índice duplicado', "$t.$i", "($cols) ya lo cubre $j ($otras)"); break; }

// R7. Columnas de auditoría en tablas de negocio
foreach ($tablas as $t) {
    if (preg_match('/^(sgs_migracion|schema_migrations|login_attempts|ubigeo)$/i', $t)) continue;
    $cols = array_column($q("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='" . str_replace("'", '', $t) . "'"), 'c');
    if (!array_intersect(array_map('strtolower', $cols), ['fecha_registro', 'created_at', 'fecha_creacion', 'fecha_aplicacion']))
        $add('BAJO', 'R7 auditoría', $t, 'no guarda la fecha de creación del registro');
}

// R8. Objetos programados (procedimientos, funciones, triggers, eventos): se documentan en 04_DATABASE
$rutinas = $q("SELECT ROUTINE_TYPE tipo, ROUTINE_NAME n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=:db");
$triggers = $q("SELECT TRIGGER_NAME n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=:db");

// Informe
$orden = ['ALTO' => 0, 'MEDIO' => 1, 'BAJO' => 2];
usort($hallazgos, fn($a, $b) => [$orden[$a['nivel']], $a['regla'], $a['objeto']] <=> [$orden[$b['nivel']], $b['regla'], $b['objeto']]);
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
echo "Auditoría de $db (MariaDB/MySQL $version) — " . count($tablas) . " tablas, " . count($fk) . " claves foráneas, "
    . count($rutinas) . " rutinas, " . count($triggers) . " triggers\n\n";
$porNivel = array_count_values(array_column($hallazgos, 'nivel'));
foreach (['ALTO', 'MEDIO', 'BAJO'] as $n) echo str_pad($n, 6) . ': ' . ($porNivel[$n] ?? 0) . "\n";
$regla = '';
foreach ($hallazgos as $h) {
    if ($h['regla'] !== $regla) { $regla = $h['regla']; echo "\n[{$h['nivel']}] {$h['regla']}\n"; }
    echo '  - ' . str_pad($h['objeto'], 48) . " {$h['detalle']}\n";
}
if ($conSql && $sugerido) {
    echo "\n-- SQL sugerido (REVISAR y respaldar antes; aplicar como migración en 04_DATABASE/09_MIGRATIONS):\n" . implode("\n", array_unique($sugerido)) . "\n";
}
exit(0);
