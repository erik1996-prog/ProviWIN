<?php
// 07_SCRIPTS/generar_instalacion.php - Arma 04_DATABASE/01_INSTALACION desde la base actual (solo CLI, solo lectura de la BD).
// Resultado: 01_crear_base.sql y luego un script por tabla, numerado según sus dependencias (una tabla va después de las
// tablas a las que apunta con clave foránea). Solo entran las tablas que SGS usa ($tablasSgs). Las marcadas 'datos'
// llevan su contenido actual; las 'vacia' se crean sin registros (supervisiones, turnos y todo lo que se ingresa al usar
// el sistema). Si aparece una tabla sin clasificar, el script se detiene para que se decida dónde va.
// Volver a ejecutarlo cuando cambie la estructura o los datos base. Uso: php 07_SCRIPTS/generar_instalacion.php
// Los .sql generados contienen datos reales (usuarios, DNI): están excluidos de git (.gitignore).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
$pdo = Database::getConnection();
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$dir = BASE_PATH . '/04_DATABASE/01_INSTALACION';
$hoy = date('Y-m-d');

// Tablas de SGS (2026-10-08). 'datos' = se instala con su contenido; 'vacia' = solo estructura.
// La descripción de cada tabla es su comentario en la base (ALTER TABLE … COMMENT='…'): toda tabla debe tenerlo.
$tablasSgs = [
    // Seguridad y usuarios
    'usuarios' => 'datos',
    'usuario_permisos' => 'datos',
    'usuario_departamentos_acceso' => 'datos',
    'login_attempts' => 'vacia',
    // Catálogos y maestros
    'partners' => 'datos',
    'ubigeo' => 'datos',
    'categorias_observacion' => 'datos',
    'subopciones_observacion' => 'datos',
    'tipificaciones_cancelacion' => 'datos',
    'vehiculos' => 'datos',
    'tecnicos' => 'datos',
    // Ingresos de la operación: se instalan vacíos
    'supervisiones' => 'vacia',
    'supervision_estado_historial' => 'vacia',
    'rescate_seguimientos' => 'vacia',
    'supervision_coordenadas_historial' => 'vacia',
    'observaciones_supervision' => 'vacia',
    'checklists_tecnicos' => 'vacia',
    'checklist_respuestas' => 'vacia',
    'notificaciones' => 'vacia',
    'asistencia_supervisores' => 'vacia',
    'asistencia_eventos' => 'vacia',
    'lluvias_zona' => 'vacia',
    'examen_enlaces' => 'vacia',
    'examen_convocatorias' => 'vacia',
    'examen_convocatoria_tecnicos' => 'vacia',
    'examen_respuestas' => 'vacia',
    'documentos_vencimiento' => 'vacia',
    'sgs_migracion' => 'vacia',
];
// Filas de prueba que no viajan aunque la tabla lleve datos
$filtroFilas = [
    'tecnicos' => "nombre_tecnico NOT LIKE 'T_CNICO%DEMO%'",
    'usuarios' => "dni NOT LIKE '999999%'",
];

$st = $pdo->prepare("SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'");
$st->execute([':db' => $db]);
$comentarioDe = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$enBase = array_keys($comentarioDe);
$sinDescripcion = array_filter(array_keys($tablasSgs), fn($t) => isset($comentarioDe[$t]) && trim($comentarioDe[$t]) === '');
if ($sinDescripcion) exit('Tablas sin descripción (agregar con ALTER TABLE … COMMENT=' . chr(39) . '…' . chr(39) . '): ' . implode(', ', $sinDescripcion) . "
");
$faltan = array_diff(array_keys($tablasSgs), $enBase);
if ($faltan) exit('Estas tablas de SGS no existen en la base: ' . implode(', ', $faltan) . "\n");
$tablas = array_keys($tablasSgs);

$st = $pdo->prepare("SELECT DISTINCT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL AND REFERENCED_TABLE_NAME <> TABLE_NAME");
$st->execute([':db' => $db]);
$depende = array_fill_keys($tablas, []);
foreach ($st->fetchAll() as $r) {
    if (!isset($depende[$r['TABLE_NAME']])) continue;
    if (!isset($tablasSgs[$r['REFERENCED_TABLE_NAME']])) exit("{$r['TABLE_NAME']} depende de {$r['REFERENCED_TABLE_NAME']}, que no está en la lista de SGS.\n");
    $depende[$r['TABLE_NAME']][] = $r['REFERENCED_TABLE_NAME'];
}

// Orden por dependencias (alfabético dentro de cada nivel, para que sea estable)
$orden = []; $pendientes = $tablas;
while ($pendientes) {
    $listas = array_values(array_filter($pendientes, fn($t) => !array_diff($depende[$t], $orden)));
    if (!$listas) exit('Dependencia circular entre: ' . implode(', ', $pendientes) . "\n");
    sort($listas);
    $orden = array_merge($orden, $listas);
    $pendientes = array_diff($pendientes, $listas);
}

// Limpia la carpeta (solo archivos .sql generados) y escribe de nuevo
if (!is_dir($dir)) mkdir($dir, 0775, true);
foreach (glob("$dir/[0-9][0-9]_*.sql") as $f) unlink($f);

$cab = fn(string $archivo, string $objetivo, string $dep) => "-- $archivo\n-- Objetivo: $objetivo\n-- Depende de: $dep\n"
    . "-- Generado por 07_SCRIPTS/generar_instalacion.php el $hoy desde $db. Solo para una base NUEVA: no borra nada.\n"
    . "-- Sin USE: se ejecuta sobre la base seleccionada en phpMyAdmin, o la indicada en consola (mysql -u usuario -p NOMBRE_BASE < archivo.sql).\n\n";

file_put_contents("$dir/01_crear_base.sql", $cab('01_crear_base.sql', 'crear la base de datos vacía (en un hosting se crea desde el panel y este paso se omite).', 'nada')
    . "CREATE DATABASE IF NOT EXISTS win_telecom_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");

$n = 2; $lista = []; $esquema = [];
foreach ($orden as $t) {
    $tipo = $tablasSgs[$t];
    $descripcion = $comentarioDe[$t];
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    $create = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create);
    $esquema[] = "-- Tabla `$t`: $descripcion" . ($depende[$t] ? ' (depende de: ' . implode(', ', array_unique($depende[$t])) . ')' : '') . "\n$create;\n";
    $archivo = sprintf('%02d_tabla_%s.sql', $n, $t);
    $filas = [];
    if ($tipo === 'datos') {
        $filas = $pdo->query("SELECT * FROM `$t`" . (isset($filtroFilas[$t]) ? " WHERE {$filtroFilas[$t]}" : ''))->fetchAll(PDO::FETCH_ASSOC);
    }
    $objetivo = "crear la tabla `$t` ($descripcion)" . ($tipo === 'datos' ? ' y cargar sus ' . count($filas) . ' registros actuales' : ', vacía') . '.';
    $sql = $cab($archivo, $objetivo, $depende[$t] ? implode(', ', array_unique($depende[$t])) : '01_crear_base.sql')
        . "SET NAMES utf8mb4;\n\n$create;\n";
    if ($filas) {
        $cols = '`' . implode('`, `', array_keys($filas[0])) . '`';
        $sql .= "\n-- Datos (" . count($filas) . " registros). INSERT IGNORE: no duplica si ya existen.\n";
        foreach (array_chunk($filas, 100) as $bloque) {
            $valores = array_map(fn($f) => '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $f)) . ')', $bloque);
            $sql .= "INSERT IGNORE INTO `$t` ($cols) VALUES\n" . implode(",\n", $valores) . ";\n";
        }
    }
    file_put_contents("$dir/$archivo", $sql);
    $lista[] = [$archivo, $t, $tipo === 'datos' ? count($filas) . ' registros' : 'vacía', $descripcion];
    $n++;
}

// Instalación completa en UN solo archivo: todas las tablas en orden + sus datos (para subir la base de una vez)
$completa = "-- 00_instalacion_completa.sql\n-- Base de SGS completa en un solo archivo: " . count($lista) . " tablas en orden de dependencias, con los datos actuales\n"
    . "-- de usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades; vacías las de ingresos (supervisiones, turnos…).\n"
    . "-- Generado por 07_SCRIPTS/generar_instalacion.php el $hoy desde $db.\n"
    . "-- Uso: crear la base vacía (en el hosting, desde el panel), seleccionarla en phpMyAdmin → Importar este archivo.\n"
    . "--      Consola: mysql -u usuario -p NOMBRE_BASE < 00_instalacion_completa.sql\n"
    . "-- Solo para una base NUEVA. Después, cada cambio se aplica con los scripts de 04_DATABASE/02_CAMBIOS.\n--\n"
    . "-- CONTENIDO (en orden de creación: cada tabla va después de las que necesita)\n"
    . implode('', array_map(fn($k, $l) => sprintf("-- %2d. %-34s %s %s\n", $k + 1, $l[1], $l[2] . str_repeat(' ', max(0, 14 - mb_strlen($l[2]))), $l[3]), array_keys($lista), $lista))
    . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
foreach ($lista as $l) {
    $cuerpo = preg_replace('/^(--[^\n]*\n)+\n?/', '', file_get_contents("$dir/{$l[0]}")); // sin la cabecera de cada archivo
    $completa .= "-- ============ {$l[1]}: {$l[3]} ============\n" . str_replace("SET NAMES utf8mb4;\n\n", '', $cuerpo) . "\n";
}
$completa .= "SET FOREIGN_KEY_CHECKS = 1;\n-- Fin de la instalación\n";
file_put_contents("$dir/00_instalacion_completa.sql", $completa);

// Diccionario de datos (documentación): cada tabla con su descripción, columnas y relaciones, tomado de la base
$st = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, COLUMN_COMMENT
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION");
$st->execute([':db' => $db]);
$columnasDe = [];
foreach ($st->fetchAll() as $c) $columnasDe[$c['TABLE_NAME']][] = $c;
$st = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL");
$st->execute([':db' => $db]);
$apuntaA = [];
foreach ($st->fetchAll() as $r) $apuntaA[$r['TABLE_NAME']][$r['COLUMN_NAME']] = $r['REFERENCED_TABLE_NAME'];
$md = "# Diccionario de datos — SGS\n\n"
    . "Generado por `07_SCRIPTS/generar_instalacion.php` el $hoy desde la base. **No editar a mano**: la descripción de cada tabla es su comentario en la base.\n"
    . "Diagrama de relaciones: `04_DATABASE/03_ESTRUCTURA/diagrama_relaciones.svg`.\n\n"
    . "| # | Tabla | Para qué sirve | En la instalación |\n|---|---|---|---|\n";
foreach ($lista as $k => $l) $md .= '| ' . ($k + 1) . " | [`{$l[1]}`](#{$l[1]}) | {$l[3]} | {$l[2]} |\n";
foreach ($lista as $l) {
    $t = $l[1];
    $md .= "\n### `$t`\n{$l[3]}" . ($depende[$t] ? ' · Depende de: ' . implode(', ', array_map(fn($x) => "`$x`", array_unique($depende[$t]))) : '') . "\n\n"
        . "| Columna | Tipo | Nulo | Clave | Descripción |\n|---|---|---|---|---|\n";
    foreach ($columnasDe[$t] ?? [] as $c) {
        $clave = $c['COLUMN_KEY'] === 'PRI' ? 'PK' : (isset($apuntaA[$t][$c['COLUMN_NAME']]) ? 'FK → `' . $apuntaA[$t][$c['COLUMN_NAME']] . '`' : ($c['COLUMN_KEY'] === 'UNI' ? 'Única' : ''));
        $md .= "| `{$c['COLUMN_NAME']}` | {$c['COLUMN_TYPE']} | " . ($c['IS_NULLABLE'] === 'YES' ? 'sí' : 'no') . " | $clave | "
            . str_replace('|', '/', (string)$c['COLUMN_COMMENT']) . " |\n";
    }
}
file_put_contents(BASE_PATH . '/01_DOCUMENTACION/04_BASE_DATOS/DICCIONARIO_DATOS.md', $md);

// Esquema completo en un solo archivo (para revisar o importar de una vez; mismo orden, sin datos)
file_put_contents(BASE_PATH . '/04_DATABASE/03_ESTRUCTURA/esquema_completo.sql',
    "-- 04_DATABASE/03_ESTRUCTURA/esquema_completo.sql\n-- Estructura de SGS: " . count($tablas) . " tablas en orden de dependencias, sin datos ni DROP.\n"
    . "-- Generado por 07_SCRIPTS/generar_instalacion.php el $hoy. No editar a mano: los cambios van como migración en 02_CAMBIOS.\n\n"
    . "SET NAMES utf8mb4;\n\n" . implode("\n", $esquema));

// LEEME con el orden
$filasMd = implode("\n", array_map(fn($l) => "| `{$l[0]}` | `{$l[1]}`: {$l[3]} | {$l[2]} |", $lista));
file_put_contents("$dir/LEEME.md", "# Instalación de la base de datos desde cero\n\n"
    . "**Lo más simple: `00_instalacion_completa.sql`** trae todas las tablas y sus datos en un solo archivo: seleccionar la base en phpMyAdmin → Importar ese archivo, y listo.

"
    . "O, si se prefiere por partes, ejecutar **en orden de número**, uno por uno: phpMyAdmin → seleccionar la base → pestaña **Importar**, o por consola:\n"
    . "`mysql -u usuario -p NOMBRE_BASE < 02_tabla_xxx.sql`\n\n"
    . "Los scripts **no fijan el nombre de la base**. `01_crear_base.sql` solo hace falta si la base no existe; en un hosting la base se crea desde el panel y ese paso se omite.\n"
    . "Cada tabla va después de las tablas de las que depende, por eso el orden importa. Cada archivo indica en su cabecera de cuáles depende.\n\n"
    . "Se instalan **" . count($tablas) . " tablas**: con sus datos actuales (usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades) "
    . "y **vacías** las de ingresos (supervisiones, observaciones, checklists, turnos, notificaciones).\n\n"
    . "| Archivo | Tabla | Datos |\n|---|---|---|\n| `01_crear_base.sql` | base vacía | — |\n$filasMd\n\n"
    . "Si la base no trae ningún administrador, crear el primero (no es SQL), desde la carpeta del sistema:\n"
    . "`php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> \"<Nombres>\" \"<Apellidos>\"`\n\n"
    . "**Verificar:** `php 07_SCRIPTS/auditar_bd.php` → " . count($tablas) . " tablas y ALTO: 0.\n\n"
    . "**Importante:** solo para una base **nueva**. En una base con datos, los cambios se aplican con `php 07_SCRIPTS/migrar.php`.\n"
    . "Los `.sql` de esta carpeta llevan datos reales (usuarios, DNI) y no se suben a git. Se regeneran con `php 07_SCRIPTS/generar_instalacion.php`.\n");

echo "Generados en 04_DATABASE/01_INSTALACION: 01_crear_base.sql + " . count($lista) . " tablas ("
    . count(array_filter($lista, fn($l) => $l[2] !== 'vacía')) . " con datos, " . count(array_filter($lista, fn($l) => $l[2] === 'vacía')) . " vacías).\n";
$fuera = array_diff($enBase, $tablas);
if ($fuera) echo "No se instalan (no las usa SGS): " . implode(', ', $fuera) . "\n";
