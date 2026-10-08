<?php
// 07_SCRIPTS/generar_instalacion.php - Arma 04_DATABASE/00_INSTALACION desde la base actual (solo CLI, solo lectura de la BD).
// Resultado: 01_crear_base.sql, luego un script por tabla numerado según sus dependencias (una tabla va después de las
// tablas a las que apunta con clave foránea) y al final el ajuste de índices. Las tablas de catálogo llevan sus datos.
// Volver a ejecutarlo cuando cambie la estructura o los catálogos. Uso: php 07_SCRIPTS/generar_instalacion.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
$pdo = Database::getConnection();
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$dir = BASE_PATH . '/04_DATABASE/00_INSTALACION';
$hoy = date('Y-m-d');

// Tablas cuyo contenido es catálogo (el sistema no funciona sin ellos). El resto se crea vacío.
$catalogos = [
    'ubigeo' => 'departamentos, provincias y distritos de operación',
    'partners' => 'contratas (incluye WIN DIRECTO, necesario para el personal propio)',
    'partner_ubigeos' => 'cobertura de cada partner',
    'categorias_observacion' => 'categorías de observación',
    'subopciones_observacion' => 'tipos de observación con gravedad y penalidad',
    'catalogo_tipos_supervision' => 'tipos de supervisión',
    'catalogo_subtipos_supervision' => 'subtipos de supervisión',
    'tipificaciones_cancelacion' => 'motivos de cancelación',
    'sgs_migracion' => 'migraciones ya incluidas en esta estructura',
];

$st = $pdo->prepare("SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
$st->execute([':db' => $db]);
$comentarios = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$tablas = array_keys($comentarios);
$st = $pdo->prepare("SELECT DISTINCT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL AND REFERENCED_TABLE_NAME <> TABLE_NAME");
$st->execute([':db' => $db]);
$depende = array_fill_keys($tablas, []);
foreach ($st->fetchAll() as $r) $depende[$r['TABLE_NAME']][] = $r['REFERENCED_TABLE_NAME'];

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
    . "-- Generado por 07_SCRIPTS/generar_instalacion.php el $hoy desde $db. Solo para una base NUEVA: no borra nada.\n\n";

file_put_contents("$dir/01_crear_base.sql", $cab('01_crear_base.sql', 'crear la base de datos vacía.', 'nada')
    . "CREATE DATABASE IF NOT EXISTS win_telecom_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");

$n = 2; $lista = []; $esquema = [];
foreach ($orden as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    $create = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create);
    $esquema[] = "-- Tabla `$t`" . ($depende[$t] ? ' (depende de: ' . implode(', ', array_unique($depende[$t])) . ')' : '') . "\n$create;\n";
    $archivo = sprintf('%02d_tabla_%s.sql', $n, $t);
    $objetivo = "crear la tabla `$t`" . ($comentarios[$t] ? " ({$comentarios[$t]})" : '') . (isset($catalogos[$t]) ? " y cargar sus datos iniciales: {$catalogos[$t]}" : '') . '.';
    $sql = $cab($archivo, $objetivo, $depende[$t] ? implode(', ', array_unique($depende[$t])) : '01_crear_base.sql')
        . "USE win_telecom_db;\nSET NAMES utf8mb4;\n\n$create;\n";
    if (isset($catalogos[$t])) {
        $filas = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        if ($filas) {
            $cols = '`' . implode('`, `', array_keys($filas[0])) . '`';
            $sql .= "\n-- Datos iniciales (" . count($filas) . " registros). INSERT IGNORE: no duplica si ya existen.\n";
            foreach (array_chunk($filas, 100) as $bloque) {
                $valores = array_map(fn($f) => '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $f)) . ')', $bloque);
                $sql .= "INSERT IGNORE INTO `$t` ($cols) VALUES\n" . implode(",\n", $valores) . ";\n";
            }
        }
    }
    file_put_contents("$dir/$archivo", $sql);
    $lista[] = [$archivo, $t, isset($catalogos[$t])];
    $n++;
}

// Al final: migraciones de 09_MIGRATIONS que todavía no están en esta base, para que la base nueva quede al día
$aplicadas = $pdo->query('SELECT ARCHIVO FROM SGS_MIGRACION')->fetchAll(PDO::FETCH_COLUMN);
foreach (glob(BASE_PATH . '/04_DATABASE/09_MIGRATIONS/[0-9][0-9][0-9]_*.sql') as $m) {
    $nombre = basename($m);
    if (in_array($nombre, $aplicadas, true)) continue;
    $archivo = sprintf('%02d_migracion_%s', $n, strtolower($nombre));
    $cuerpo = trim(preg_replace('/^\s*--.*$\n?/m', '', file_get_contents($m)));
    file_put_contents("$dir/$archivo", $cab($archivo, "aplicar la migración $nombre y registrarla (migrar.php ya no la repetirá).", 'todas las tablas anteriores')
        . "USE win_telecom_db;\n\n$cuerpo\n\nINSERT IGNORE INTO sgs_migracion (ARCHIVO, FECHA_APLICACION) VALUES ('$nombre', NOW());\n");
    $lista[] = [$archivo, '', false];
    $n++;
}

// Esquema completo en un solo archivo (para revisar o importar de una vez; mismo orden, sin datos)
file_put_contents(BASE_PATH . '/04_DATABASE/01_SCHEMA/win_telecom_db_esquema.sql',
    "-- 04_DATABASE/01_SCHEMA/win_telecom_db_esquema.sql\n-- Estructura completa de $db: " . count($tablas) . " tablas en orden de dependencias, sin datos ni DROP.\n"
    . "-- Generado por 07_SCRIPTS/generar_instalacion.php el $hoy. No editar a mano: los cambios van como migración en 09_MIGRATIONS.\n\n"
    . "SET NAMES utf8mb4;\n\n" . implode("\n", $esquema));

// LEEME con el orden
$filasMd = implode("\n", array_map(fn($l) => "| `{$l[0]}` | " . ($l[1] ? "Tabla `{$l[1]}`" . ($l[2] ? ' + datos iniciales' : '') : 'Ajuste final') . ' |', $lista));
file_put_contents("$dir/LEEME.md", "# Instalación de la base de datos desde cero\n\n"
    . "Ejecutar **en orden de número**, uno por uno: phpMyAdmin → pestaña **Importar**, o por consola:\n"
    . "`C:\\xampp\\mysql\\bin\\mysql.exe -u root -p < 02_tabla_xxx.sql`\n\n"
    . "Cada tabla va después de las tablas de las que depende, por eso el orden importa. Cada archivo indica en su cabecera de cuáles depende.\n\n"
    . "| Archivo | Qué crea |\n|---|---|\n| `01_crear_base.sql` | La base `win_telecom_db` vacía |\n$filasMd\n\n"
    . "Último paso (no es SQL), desde la carpeta del sistema: crear el primer administrador.\n"
    . "`php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> \"<Nombres>\" \"<Apellidos>\"`\n"
    . "La contraseña temporal queda en `09_CONFIG/<usuario>.local.txt`.\n\n"
    . "**Verificar:** `php 07_SCRIPTS/auditar_bd.php` → " . count($tablas) . " tablas y ALTO: 0.\n\n"
    . "**Importante:** solo para una base **nueva**. En una base con datos, los cambios se aplican con `php 07_SCRIPTS/migrar.php`.\n"
    . "Si cambia la estructura o los catálogos: `php 07_SCRIPTS/generar_instalacion.php` vuelve a generar esta carpeta.\n");

echo "Generados en 04_DATABASE/00_INSTALACION: 01_crear_base.sql + " . count($lista) . " scripts (" . count($tablas) . " tablas).\n";
