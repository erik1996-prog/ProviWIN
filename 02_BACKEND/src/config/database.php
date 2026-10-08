<?php
// 02_BACKEND/src/config/database.php - Conexión Segura mediante PDO MySQL con Auto-Setup

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ];

        try {
            // 1. Intentar conectar a la base de datos directamente
            $port = defined('DB_PORT') ? DB_PORT : '3306';
            $dsn = "mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            $downMarker = defined('STORAGE_PATH') ? STORAGE_PATH . '/database-down.marker' : null;
            if ($downMarker && is_file($downMarker)) @unlink($downMarker);
            @self::$instance->exec("SET time_zone = '" . date('P') . "';");
            // Las migraciones DDL son costosas y bloquean tablas. En producción se
            // ejecutan explícitamente con scripts/migrate.php, nunca por petición.
            if (APP_AUTO_MIGRATE) {
                self::checkMigrateCatalog(self::$instance);
            }
            return self::$instance;
        } catch (PDOException $e) {
            self::$instance = null;
            // SGS comparte win_telecom_db: nunca crea la base ni carga esquemas.
            self::respondUnavailable($e);
        }
    }

    private static function respondUnavailable(PDOException $error): void {
        $marker = defined('STORAGE_PATH') ? STORAGE_PATH . '/database-down.marker' : null;
        $lastReport = $marker && is_file($marker) ? (int)@filemtime($marker) : 0;
        if (time() - $lastReport >= 60) {
            if ($marker) @touch($marker);
            if (function_exists('win_log_error')) {
                win_log_error('database_unavailable', $error->getMessage(), [
                    'driver_code' => (int)($error->errorInfo[1] ?? 0),
                ]);
            }
        }

        $acceptsJson = (defined('WIN_JSON_RESPONSE') && WIN_JSON_RESPONSE)
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if (ob_get_level() > 0) ob_clean();
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 15');
            header('Cache-Control: no-store, max-age=0');
            header('Content-Type: ' . ($acceptsJson ? 'application/json' : 'text/html') . '; charset=utf-8');
        }
        if ($acceptsJson) {
            echo json_encode([
                'status' => 'error',
                'message' => 'El servicio de datos no está disponible temporalmente. Intente nuevamente en unos segundos.',
                'request_id' => defined('WIN_REQUEST_ID') ? WIN_REQUEST_ID : null,
                'error_code' => function_exists('sgs_error_code') ? sgs_error_code() : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $requestId = function_exists('sgs_error_code') ? htmlspecialchars(sgs_error_code(), ENT_QUOTES, 'UTF-8') : '';
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Servicio temporalmente no disponible</title></head><body style="margin:0;background:#f4f6f9;color:#172033;font-family:Nunito,Segoe UI,sans-serif">'
            . '<main style="max-width:620px;margin:12vh auto;padding:28px"><section style="background:#fff;border:1px solid #e4e8ef;border-radius:16px;padding:32px;box-shadow:0 18px 50px rgba(19,31,55,.10)">'
            . '<div style="width:44px;height:44px;border-radius:12px;background:#fff1e9;color:#e9540b;display:grid;place-items:center;font-size:24px">!</div>'
            . '<h1 style="font-size:24px;margin:18px 0 8px">Estamos restableciendo la conexión</h1>'
            . '<p style="line-height:1.65;color:#596579;margin:0 0 22px">Sus datos no se perdieron. Espere unos segundos y vuelva a intentar.</p>'
            . '<a href="index.php" style="display:inline-block;background:#FF5A0A;color:#fff;text-decoration:none;font-weight:700;padding:11px 18px;border-radius:10px">Reintentar</a>'
            . ($requestId !== '' ? '<p style="font-size:12px;color:#8a94a6;margin:22px 0 0">Código de seguimiento: ' . $requestId . '</p>' : '')
            . '</section></main></body></html>';
        exit;
    }

    private static function checkMigrateCatalog(PDO $pdo): void {
        try {
            foreach ([
                "ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `respuesta_general_partner` TEXT NULL AFTER `firma_supervisor`",
                "ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `fecha_respuesta_partner` DATETIME NULL AFTER `respuesta_general_partner`",
                "ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `respondido_por_partner` INT NULL AFTER `fecha_respuesta_partner`",
                "ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `estado_respuesta` ENUM('Pendiente','En revisión','Levantamiento validado','Levantamiento rechazado') NOT NULL DEFAULT 'Pendiente' AFTER `fecha_respuesta_partner`",
                "ALTER TABLE `checklist_respuestas` ADD COLUMN IF NOT EXISTS `descargo_foto` VARCHAR(255) NULL AFTER `foto_evidencia`",
                "ALTER TABLE `checklist_respuestas` ADD COLUMN IF NOT EXISTS `descargo_comentario` VARCHAR(500) NULL AFTER `descargo_foto`"
                ,"ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `sustentacion_evaluacion` TEXT NULL AFTER `estado_respuesta`"
                ,"ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `fecha_evaluacion` DATETIME NULL AFTER `sustentacion_evaluacion`"
                ,"ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `evaluado_por` INT NULL AFTER `fecha_evaluacion`"
                ,"ALTER TABLE `checklists_tecnicos` ADD COLUMN IF NOT EXISTS `fecha_limite_respuesta` DATETIME NULL AFTER `fecha_registro`"
                ,"ALTER TABLE `checklists_tecnicos` MODIFY COLUMN `estado_respuesta` ENUM('Pendiente','En revisión','Validado','Penalizado','Reabierto') NOT NULL DEFAULT 'Pendiente'"
            ] as $checklistAlter) { $pdo->exec($checklistAlter); }
            $pdo->exec("CREATE TABLE IF NOT EXISTS `tickets_error` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`usuario_id` INT NULL,`tipo` VARCHAR(80) NOT NULL,`mensaje` VARCHAR(1000) NOT NULL,`detalle` TEXT NULL,`pagina` VARCHAR(500) NULL,`codigo_http` VARCHAR(10) NULL,`navegador` VARCHAR(500) NULL,`huella` CHAR(64) NOT NULL,`repeticiones` INT UNSIGNED NOT NULL DEFAULT 1,`estado` ENUM('Nuevo','En revisión','Resuelto','Descartado') NOT NULL DEFAULT 'Nuevo',`fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,`ultima_ocurrencia` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,`resuelto_por` INT NULL,`fecha_resolucion` DATETIME NULL,INDEX `idx_ticket_estado_fecha` (`estado`,`ultima_ocurrencia`),INDEX `idx_ticket_huella` (`huella`),CONSTRAINT `fk_ticket_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,CONSTRAINT `fk_ticket_admin` FOREIGN KEY (`resuelto_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `tipificaciones_cancelacion` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `descripcion` VARCHAR(200) NOT NULL UNIQUE,
                    `estado` TINYINT(1) NOT NULL DEFAULT 1,
                    `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                INSERT INTO `tipificaciones_cancelacion` (`descripcion`) VALUES
                    ('Error comercial coordenadas no coinciden'),
                    ('No hay postes de apoyo'),
                    ('No hay CTO')
                ON DUPLICATE KEY UPDATE `estado` = 1
            ");

            $stmt = $pdo->query("SHOW COLUMNS FROM subopciones_observacion LIKE 'gravedad'");
            $col = $stmt->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE `subopciones_observacion` ADD COLUMN `gravedad` ENUM('Media', 'Grave', 'Muy Grave') NOT NULL DEFAULT 'Grave'");
            }

            $stmtEstado = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'estado_supervision'");
            if (!$stmtEstado->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `estado_supervision` ENUM('Pendiente', 'Iniciada', 'En proceso', 'Finalizada') NOT NULL DEFAULT 'Iniciada'");
            } else {
                $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `estado_supervision` ENUM('Pendiente', 'Iniciada', 'En proceso', 'Finalizada') NOT NULL DEFAULT 'Iniciada'");
            }

            // Estados admitidos para la conservación del vehículo.
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `unidad_estado_conservacion` ENUM('Mal estado', 'Regular', 'Buen estado') NULL DEFAULT 'Buen estado'");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `cliente_nombre` VARCHAR(200) NULL");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `cliente_dni` VARCHAR(12) NULL");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `ticket_codigo` VARCHAR(50) NULL");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `partner_id` INT NULL");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `unidad_tipo` ENUM('Minivan', 'Auto', 'Moto', 'Otro') NULL");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `unidad_placa` VARCHAR(10) NULL");
            $stmtUnidadSoat = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'unidad_soat'");
            if (!$stmtUnidadSoat->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `unidad_soat` ENUM('Vigente', 'No vigente') NULL AFTER `unidad_placa`");
            }
            $stmtUnidadRevision = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'unidad_revision_tecnica'");
            if (!$stmtUnidadRevision->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `unidad_revision_tecnica` ENUM('Vigente', 'No vigente') NULL AFTER `unidad_soat`");
            }
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `clasificacion_supervision` VARCHAR(100) NOT NULL");
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `area_servicio` VARCHAR(100) NOT NULL");

            // Mantener los ENUM con UTF-8 real. Algunas instalaciones antiguas
            // quedaron con caracteres de consola (├│) dentro de sus opciones.
            $pdo->exec("ALTER TABLE `supervisiones` MODIFY COLUMN `derivacion_orden` ENUM('Autogestión', 'Mesa de supervisión', 'Líder', 'Coordinador', 'Gerente') NOT NULL");
            $pdo->exec("ALTER TABLE `observaciones_supervision` MODIFY COLUMN `estado_respuesta` ENUM('Pendiente', 'Respondido', 'En revisión', 'Penalidad Directa', 'Apelación Aprobada', 'Apelación Rechazada', 'Levantamiento validado', 'Levantamiento rechazado') DEFAULT 'Pendiente'");
            $pdo->exec("ALTER TABLE `observaciones_supervision` MODIFY COLUMN `gravedad` ENUM('Leve', 'Media', 'Grave', 'Muy Grave') NOT NULL DEFAULT 'Grave'");
            $pdo->exec("ALTER TABLE `observaciones_supervision` ADD COLUMN IF NOT EXISTS `respondido_por_partner` INT NULL AFTER `fecha_respuesta_partner`");
            $stmtPlazoObs = $pdo->query("SHOW COLUMNS FROM observaciones_supervision LIKE 'plazo_subsanacion_dias'");
            if (!$stmtPlazoObs->fetch()) {
                $pdo->exec("ALTER TABLE `observaciones_supervision` ADD COLUMN `plazo_subsanacion_dias` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `fecha_limite_respuesta`, ADD COLUMN `subsanacion_inmediata` TINYINT(1) NOT NULL DEFAULT 0 AFTER `plazo_subsanacion_dias`");
            }
            $stmtFotosDescargo = $pdo->query("SHOW COLUMNS FROM observaciones_supervision LIKE 'descargo_foto_1'");
            if (!$stmtFotosDescargo->fetch()) {
                $pdo->exec("ALTER TABLE `observaciones_supervision` ADD COLUMN `descargo_foto_1` VARCHAR(255) NULL AFTER `respuesta_partner`, ADD COLUMN `descargo_foto_2` VARCHAR(255) NULL AFTER `descargo_foto_1`, ADD COLUMN `descargo_foto_3` VARCHAR(255) NULL AFTER `descargo_foto_2`, ADD COLUMN `fecha_evaluacion` DATETIME NULL AFTER `sustentacion_evaluacion`, ADD COLUMN `evaluado_por` INT NULL AFTER `fecha_evaluacion`");
            }
            $stmtComentariosFoto = $pdo->query("SHOW COLUMNS FROM observaciones_supervision LIKE 'foto_comentario_1'");
            if (!$stmtComentariosFoto->fetch()) {
                $pdo->exec("ALTER TABLE `observaciones_supervision` ADD COLUMN `foto_comentario_1` VARCHAR(500) NULL AFTER `foto_1`, ADD COLUMN `foto_comentario_2` VARCHAR(500) NULL AFTER `foto_2`, ADD COLUMN `foto_comentario_3` VARCHAR(500) NULL AFTER `foto_3`, ADD COLUMN `descargo_comentario_1` VARCHAR(500) NULL AFTER `descargo_foto_1`, ADD COLUMN `descargo_comentario_2` VARCHAR(500) NULL AFTER `descargo_foto_2`, ADD COLUMN `descargo_comentario_3` VARCHAR(500) NULL AFTER `descargo_foto_3`");
            }
            $stmtComentariosServicio = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'foto_servicio_comentario_1'");
            if (!$stmtComentariosServicio->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `foto_servicio_comentario_1` VARCHAR(500) NULL AFTER `foto_servicio_1`, ADD COLUMN `foto_servicio_comentario_2` VARCHAR(500) NULL AFTER `foto_servicio_2`, ADD COLUMN `foto_servicio_comentario_3` VARCHAR(500) NULL AFTER `foto_servicio_3`");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `notificaciones` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, `usuario_id` INT NOT NULL,
                    `observacion_id` INT NULL, `titulo` VARCHAR(160) NOT NULL,
                    `mensaje` VARCHAR(500) NOT NULL, `url_destino` VARCHAR(255) NULL,
                    `leida` TINYINT(1) NOT NULL DEFAULT 0,
                    `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `fecha_lectura` DATETIME NULL,
                    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`observacion_id`) REFERENCES `observaciones_supervision`(`id`) ON DELETE CASCADE,
                    INDEX `idx_notificacion_usuario` (`usuario_id`, `leida`, `fecha_registro`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `flyers_programados` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `titulo` VARCHAR(180) NOT NULL,
                    `imagen_url` VARCHAR(255) NOT NULL,
                    `fecha_inicio` DATETIME NOT NULL,
                    `fecha_fin` DATETIME NOT NULL,
                    `activo` TINYINT(1) NOT NULL DEFAULT 1,
                    `creado_por` INT NULL,
                    `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`creado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
                    INDEX `idx_flyer_vigencia` (`activo`,`fecha_inicio`,`fecha_fin`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `examen_intentos` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `usuario_id` INT NOT NULL,
                    `foto_inicial` VARCHAR(255) NOT NULL,
                    `foto_incidencia` VARCHAR(255) NULL,
                    `tipo_incidencia` ENUM('Rostro ausente','Rostro diferente','Varias personas') NULL,
                    `estado` ENUM('En curso','Finalizado','Cerrado por identidad') NOT NULL DEFAULT 'En curso',
                    `puntaje` TINYINT UNSIGNED NULL,
                    `fecha_inicio` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `fecha_incidencia` DATETIME NULL,
                    `fecha_fin` DATETIME NULL,
                    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
                    INDEX `idx_examen_usuario_fecha` (`usuario_id`,`fecha_inicio`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmtFechaCanc = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'fecha_cancelacion'");
            if (!$stmtFechaCanc->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `fecha_cancelacion` DATE NULL AFTER `ticket_codigo`");
            }

            $stmtLat = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'latitud'");
            if (!$stmtLat->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `latitud` VARCHAR(50) NULL, ADD COLUMN `longitud` VARCHAR(50) NULL");
            }

            $pdo->exec("ALTER TABLE `supervisiones`
                ADD COLUMN IF NOT EXISTS `origen_importacion` TINYINT(1) NOT NULL DEFAULT 0 AFTER `es_cancelacion`,
                ADD COLUMN IF NOT EXISTS `observacion_previa` TEXT NULL AFTER `origen_importacion`,
                ADD COLUMN IF NOT EXISTS `estado_cancelacion` VARCHAR(60) NULL AFTER `observacion_previa`,
                ADD INDEX IF NOT EXISTS `idx_supervision_estado_cancelacion` (`es_cancelacion`,`estado_cancelacion`,`estado_supervision`)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `supervision_coordenadas_historial` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `supervision_id` INT NOT NULL,
                `latitud_anterior` VARCHAR(50) NULL, `longitud_anterior` VARCHAR(50) NULL,
                `latitud_nueva` VARCHAR(50) NULL, `longitud_nueva` VARCHAR(50) NULL,
                `cambiado_por` INT NULL, `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_coord_hist_supervision` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_coord_hist_usuario` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
                INDEX `idx_coord_hist_supervision_fecha` (`supervision_id`,`fecha_registro`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $stmtFotosServ = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'foto_servicio_1'");
            if (!$stmtFotosServ->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `foto_servicio_1` VARCHAR(255) NULL, ADD COLUMN `foto_servicio_2` VARCHAR(255) NULL, ADD COLUMN `foto_servicio_3` VARCHAR(255) NULL");
            }

            $stmtAsignadoPor = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'asignado_por'");
            if (!$stmtAsignadoPor->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `asignado_por` INT NULL AFTER `supervisor_id`, ADD COLUMN `fecha_asignacion` DATETIME NULL AFTER `asignado_por`, ADD CONSTRAINT `fk_supervision_asignador` FOREIGN KEY (`asignado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL, ADD INDEX `idx_supervision_asignador` (`asignado_por`)");
            }
            $stmtZonaAsignacion = $pdo->query("SHOW COLUMNS FROM supervisiones LIKE 'zona_asignacion'");
            if (!$stmtZonaAsignacion->fetch()) {
                $pdo->exec("ALTER TABLE `supervisiones` ADD COLUMN `zona_asignacion` VARCHAR(100) NULL AFTER `fecha_asignacion`, ADD INDEX `idx_supervision_zona_pendiente` (`zona_asignacion`,`partner_id`,`estado_supervision`)");
            }
            $pdo->exec("ALTER TABLE `supervisiones`
                ADD COLUMN IF NOT EXISTS `estado_atencion_partner` ENUM('Pendiente','Atendida') NOT NULL DEFAULT 'Pendiente' AFTER `fecha_asignacion`,
                ADD COLUMN IF NOT EXISTS `observacion_atencion_partner` TEXT NULL AFTER `estado_atencion_partner`,
                ADD COLUMN IF NOT EXISTS `fecha_atencion_partner` DATETIME NULL AFTER `observacion_atencion_partner`,
                ADD INDEX IF NOT EXISTS `idx_atencion_partner_plazo` (`supervisor_id`,`estado_atencion_partner`,`fecha_asignacion`)");

            $stmtAvatar = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'avatar'");
            if (!$stmtAvatar->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `avatar` VARCHAR(255) NULL");
            }

            // Migración de campos extendidos para usuarios
            $stmtNombres = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'nombres'");
            if (!$stmtNombres->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `nombres` VARCHAR(100) NULL AFTER `dni`");
            }

            $stmtApellidos = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'apellidos'");
            if (!$stmtApellidos->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `apellidos` VARCHAR(100) NULL AFTER `nombres`");
            }

            $stmtUsuarioCol = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'usuario'");
            if (!$stmtUsuarioCol->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `usuario` VARCHAR(50) NULL AFTER `apellidos`");
                // Intentar añadir índice único para usuario
                try {
                    $pdo->exec("ALTER TABLE `usuarios` ADD UNIQUE INDEX `uq_usuario` (`usuario`)");
                } catch (Throwable $t) {}
            }

            $stmtArea = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'area_trabajo'");
            if (!$stmtArea->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `area_trabajo` VARCHAR(100) NULL AFTER `rol`");
            }

            $stmtZona = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'zona'");
            if (!$stmtZona->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `zona` VARCHAR(100) DEFAULT 'Provincias' AFTER `area_trabajo`");
            }

            $stmtProcesos = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'procesos_copiados'");
            if (!$stmtProcesos->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `procesos_copiados` TEXT NULL AFTER `zona`");
            }

            $stmtObs = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'observacion'");
            if (!$stmtObs->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `observacion` TEXT NULL AFTER `procesos_copiados`");
            }
            $stmtTelefono = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telefono_personal'");
            if (!$stmtTelefono->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `telefono_personal` VARCHAR(20) NULL AFTER `email`, ADD COLUMN `telefono_corporativo` VARCHAR(20) NULL AFTER `telefono_personal`, ADD COLUMN `turno` VARCHAR(30) NULL AFTER `telefono_corporativo`, ADD COLUMN `modalidad` VARCHAR(30) NULL AFTER `turno`, ADD COLUMN `sede` VARCHAR(100) NULL AFTER `modalidad`");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS `usuario_departamentos_acceso` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `usuario_id` INT NOT NULL,
                `departamento` VARCHAR(100) NOT NULL,
                `activo` TINYINT(1) NOT NULL DEFAULT 1,
                `otorgado_por` INT NULL,
                `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_usuario_departamento_acceso` (`usuario_id`,`departamento`),
                KEY `idx_acceso_departamento_activo` (`departamento`,`activo`),
                CONSTRAINT `fk_acceso_departamento_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_acceso_departamento_otorgante` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $stmtCredential = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'password_admin_encrypted'");
            if (!$stmtCredential->fetch()) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `password_admin_encrypted` TEXT NULL AFTER `password_hash`");
            }

            // Garantizar la existencia del Partner "WIN DIRECTO"
            $stmtWinPartner = $pdo->query("SELECT id FROM partners WHERE nombre_partner = 'WIN DIRECTO' OR nombre_partner = 'WIN' LIMIT 1");
            $winPartnerId = $stmtWinPartner ? $stmtWinPartner->fetchColumn() : false;
            if (!$winPartnerId) {
                $pdo->exec("INSERT INTO partners (nombre_partner, ruc, estado) VALUES ('WIN DIRECTO', '20600000000', 1)");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `asistencia_eventos` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `asistencia_id` INT NOT NULL,
                    `tipo_evento` ENUM('almuerzo', 'reunion', 'capacitacion', 'campo') NOT NULL,
                    `inicio_evento` DATETIME NOT NULL,
                    `fin_evento` DATETIME NULL,
                    `duracion_minutos` INT DEFAULT 0,
                    `nota` VARCHAR(255) NULL,
                    `latitud` VARCHAR(50) NULL,
                    `longitud` VARCHAR(50) NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`asistencia_id`) REFERENCES `asistencia_supervisores`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Dependencias de los módulos de chat y cuadrillas en instalaciones existentes.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `mensajes_chat` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `emisor_id` INT NOT NULL,
                    `receptor_id` INT NULL,
                    `mensaje` TEXT NOT NULL,
                    `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`emisor_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`receptor_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
                    INDEX `idx_chat_conversacion` (`emisor_id`, `receptor_id`, `fecha_registro`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `recursos_documentos` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `titulo` VARCHAR(255) NOT NULL,
                    `tipo` ENUM('archivo', 'link') NOT NULL DEFAULT 'archivo',
                    `url_recurso` TEXT NOT NULL,
                    `nombre_archivo_original` VARCHAR(255) NULL,
                    `usuario_id` INT NOT NULL,
                    `fecha_subida` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `tecnicos` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `nombre_tecnico` VARCHAR(200) NOT NULL,
                    `dni` VARCHAR(12) NULL,
                    `tipo_tecnico` TINYINT NOT NULL DEFAULT 1,
                    `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `fecha_actualizacion` DATETIME NULL,
                    UNIQUE KEY `uq_tecnico_nombre` (`nombre_tecnico`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `checklists_tecnicos` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, `supervision_id` INT NULL,
                    `supervisor_id` INT NOT NULL, `tecnico_nombre` VARCHAR(200) NOT NULL,
                    `tecnico_dni` VARCHAR(12) NOT NULL, `unidad_tipo` VARCHAR(30) NOT NULL,
                    `unidad_placa` VARCHAR(15) NOT NULL, `brevete_poseedor` VARCHAR(200) NULL,
                    `observacion_general` TEXT NULL, `firma_supervisor` VARCHAR(255) NULL, `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
                    INDEX `idx_checklist_fecha` (`fecha_registro`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $stmtFirmaChecklist = $pdo->query("SHOW COLUMNS FROM checklists_tecnicos LIKE 'firma_supervisor'");
            if (!$stmtFirmaChecklist->fetch()) {
                $pdo->exec("ALTER TABLE `checklists_tecnicos` ADD COLUMN `firma_supervisor` VARCHAR(255) NULL AFTER `observacion_general`");
            }
            $stmtTecCel = $pdo->query("SHOW COLUMNS FROM tecnicos LIKE 'celular'");
            if (!$stmtTecCel->fetch()) {
                $pdo->exec("ALTER TABLE tecnicos ADD COLUMN celular VARCHAR(15) NULL AFTER tipo_tecnico, ADD COLUMN observacion TEXT NULL AFTER celular, ADD COLUMN estado_operativo ENUM('Habilitado','Inhabilitado','Blacklist') NOT NULL DEFAULT 'Habilitado' AFTER observacion");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `checklist_respuestas` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, `checklist_id` INT NOT NULL,
                    `criterio` VARCHAR(255) NOT NULL,
                    `estado` ENUM('Bueno','Regular','Malo','No tiene') NOT NULL,
                    `observacion` VARCHAR(500) NULL,
                    FOREIGN KEY (`checklist_id`) REFERENCES `checklists_tecnicos`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmtAsistEst = $pdo->query("SHOW COLUMNS FROM asistencia_supervisores LIKE 'estado_turno'");
            if ($stmtAsistEst->fetch()) {
                $pdo->exec("ALTER TABLE `asistencia_supervisores` MODIFY COLUMN `estado_turno` ENUM('En turno', 'Fuera de turno', 'En break', 'En capacitación', 'En almuerzo', 'En reunión', 'Vacaciones', 'Descanso Médico', 'Licencia Paternidad', 'Permiso Fallecimiento') DEFAULT 'En turno'");
            }

            $stmtAsistLat = $pdo->query("SHOW COLUMNS FROM asistencia_supervisores LIKE 'latitud_inicio'");
            if (!$stmtAsistLat->fetch()) {
                $pdo->exec("ALTER TABLE `asistencia_supervisores` ADD COLUMN `latitud_inicio` VARCHAR(50) NULL, ADD COLUMN `longitud_inicio` VARCHAR(50) NULL, ADD COLUMN `latitud_fin` VARCHAR(50) NULL, ADD COLUMN `longitud_fin` VARCHAR(50) NULL, ADD COLUMN `observaciones` TEXT NULL");
            }

            $stmtAsistUnique = $pdo->query("SHOW INDEX FROM asistencia_supervisores WHERE Key_name = 'uq_asistencia_supervisor_fecha'");
            if (!$stmtAsistUnique->fetch()) {
                $pdo->exec("ALTER TABLE `asistencia_supervisores` ADD UNIQUE KEY `uq_asistencia_supervisor_fecha` (`supervisor_id`, `fecha`)");
            }

            // Los datos maestros se administran mediante migraciones explícitas.
            // Nunca se eliminan registros como efecto secundario de abrir una conexión.
        } catch (Throwable $e) {
            // Silencioso
        }
    }
}
