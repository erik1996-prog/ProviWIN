<?php
// 07_SCRIPTS/cerrar_turnos.php - Cierra a las 23:59 los turnos que los supervisores no finalizaron.
// Programarlo a diario a las 23:59 (Programador de tareas de Windows o cron en Linux). El sistema además lo
// hace solo cuando alguien lo usa después de esa hora; este script garantiza la hora exacta aunque nadie entre.
// Uso: php 07_SCRIPTS/cerrar_turnos.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';
require BACKEND_PATH . '/config/turnos.php';
$n = sgsCerrarTurnosVencidos(Database::getConnection());
echo date('Y-m-d H:i:s') . " · turnos cerrados automáticamente: $n\n";
