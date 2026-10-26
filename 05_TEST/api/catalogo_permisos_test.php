<?php
// 05_TEST/api/catalogo_permisos_test.php - (2026-10-26) Roles y permisos al día con las pantallas del sistema.
// Regla del proyecto: en cada actualización se actualizan los roles. Esta prueba falla si:
//   - una pantalla de index.php ($allowedViews) pide un permiso que no está en el catálogo (Auth::permissionCatalog);
//   - un permiso delegable no tiene pantalla con sección del menú (UsuarioService::SECCION_PANTALLA) o le falta su "Ver";
//   - un rol trae editar/eliminar/opciones de una pantalla sin poder verla.
// Sin base ni HTTP. Uso: php 05_TEST/api/catalogo_permisos_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
$catalogo = Auth::permissionCatalog();

// Permisos que piden las pantallas (constantes Auth::PERM_* en $allowedViews)
$index = (string)file_get_contents(BASE_PATH . '/index.php');
preg_match('/\$allowedViews\s*=\s*\[(.*?)\n\];/s', $index, $m);
preg_match_all('/Auth::(PERM_[A-Z_]+)/', $m[1] ?? '', $c);
$faltan = [];
foreach (array_unique($c[1]) as $const) {
    $valor = constant("Auth::$const");
    if (!isset($catalogo[$valor])) $faltan[] = $const;
}
$check('cada pantalla pide un permiso que existe en Roles y permisos', $m && !$faltan, $faltan);

$sinSeccion = []; $sinVer = [];
foreach ($catalogo as $clave => $d) {
    if (empty($d['delegable'])) continue;
    if (!isset(UsuarioService::SECCION_PANTALLA[$d['group']])) $sinSeccion[] = "{$d['group']} ($clave)";
    if (($d['action'] ?? '') !== 'ver' && Auth::permisoRequerido($clave) === null && $d['group'] !== 'Mi turno') $sinVer[] = $clave;
}
$check('cada permiso pertenece a una pantalla con su sección del menú', !$sinSeccion, $sinSeccion);
$check('editar, eliminar y opciones dependen del "Ver" de su pantalla', !$sinVer, $sinVer);

$roles = (new ReflectionClass('Auth'))->getConstant('ROLE_PERMISSIONS');
$incoherentes = [];
foreach ($roles as $rol => $permisos) foreach ($permisos as $p) {
    $r = Auth::permisoRequerido($p);
    if ($r !== null && !in_array($r, $permisos, true)) $incoherentes[] = "$rol: $p sin $r";
}
$check('ningún rol trae editar o eliminar de una pantalla que no puede ver', !$incoherentes, $incoherentes);
$check('al guardar, se quita lo que depende de un "Ver" desmarcado', Auth::conDependencias(['clima.edit', 'clima.delete', 'partners.view', 'partners.edit']) === ['partners.view', 'partners.edit']);

echo "\n$ok correctas, $fail con error.\n";
exit($fail ? 1 : 0);
