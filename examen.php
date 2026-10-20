<?php
// examen.php - Link de examen que recibe el técnico. Público: el código del link es la llave.
//   ?g=…  link GENERAL (2026-10-20): un link para un grupo; el técnico entra con su DNI → pages/examen_general.php
//   ?t=…  link individual (2026-10-15, ya no se generan; siguen sirviendo hasta vencer) → pages/examen_publico.php
// Lógica: 02_BACKEND/src/config/examen_enlaces.php
require_once __DIR__ . '/02_BACKEND/src/config/config.php';
require FRONTEND_PATH . '/pages/' . (isset($_GET['g']) ? 'examen_general.php' : 'examen_publico.php');
