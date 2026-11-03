<?php
// 05_TEST/integration/segmento_zav_test.php - (2026-11-03) Segmento del servicio por distrito: los 12 distritos ZAV de Lima
// (tabla ubigeo, columna segmento_servicio) dan ZAV; todos los demás, Regular. Sin tildes también sirve. Solo lee la base.
// Uso (solo CLI): php 05_TEST/integration/segmento_zav_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require_once BACKEND_PATH . '/config/zonas_peru.php';
$pdo = Database::getConnection();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Segmento del servicio por distrito\n";

$zav = ['BARRANCO', 'JESUS MARIA', 'LA MOLINA', 'LINCE', 'MAGDALENA DEL MAR', 'MIRAFLORES', 'PUEBLO LIBRE', 'SAN BORJA', 'SAN ISIDRO',
    'SAN MIGUEL', 'SANTIAGO DE SURCO', 'SURQUILLO'];
$malos = array_filter($zav, static fn($d) => sgsSegmentoServicio($pdo, 'LIMA', 'LIMA', $d) !== 'ZAV');
$check('los 12 distritos ZAV de Lima dan ZAV (también sin tildes: JESUS MARIA)', !$malos, array_values($malos));
$check('solo esos 12 están marcados ZAV en el catálogo', (int)$pdo->query("SELECT COUNT(*) FROM ubigeo WHERE segmento_servicio = 'ZAV'")->fetchColumn() === 12);
$check('un distrito de Lima que no es ZAV da Regular (ATE)', sgsSegmentoServicio($pdo, 'LIMA', 'LIMA', 'ATE') === 'Regular');
$otro = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento <> 'LIMA' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check('un distrito de otro departamento da Regular', !$otro || sgsSegmentoServicio($pdo, $otro['departamento'], $otro['provincia'], $otro['distrito']) === 'Regular', $otro);
$check('sin distrito o con una ubicación que no existe da Regular',
    sgsSegmentoServicio($pdo, 'LIMA', 'LIMA', '') === 'Regular' && sgsSegmentoServicio($pdo, 'LIMA', 'LIMA', 'NO EXISTE') === 'Regular');
$check('sgsValidarUbicacion devuelve el segmento del distrito', sgsValidarUbicacion($pdo, 'lima', 'lima', 'miraflores')['segmento'] === 'ZAV');

echo "\n$ok correctas, $fail con error.\n";
exit($fail ? 1 : 0);
