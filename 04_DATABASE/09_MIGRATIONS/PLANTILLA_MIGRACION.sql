-- 04_DATABASE/09_MIGRATIONS/NNN_DESCRIPCION.sql   (copiar esta plantilla; migrar.php solo toma archivos NNN_*.sql)
-- Estado: PROPUESTA | APLICADA el AAAA-MM-DD
-- Qué hace y por qué: ...
-- Impacto en el sistema de origen: ... (la BD es compartida: buscar en SISTEMA-WIN-PROVINCIA el nombre de cada tabla/columna tocada)
-- Respaldar antes: php 07_SCRIPTS/respaldar_bd.php     Reversión: NNN_DESCRIPCION.rollback.sql
--
-- Reglas: idempotente (IF NOT EXISTS / IF EXISTS), sin DROP TABLE ni DELETE masivo, una sentencia por línea terminada en ';',
-- sin ';' dentro de textos. Tabla nueva = InnoDB + utf8mb4_unicode_ci + PK + FK con índice + FECHA_CREACION.

CREATE TABLE IF NOT EXISTS SGS_EJEMPLO (
    EJEMPLO_ID          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    NOMBRE              VARCHAR(100) NOT NULL,
    ACTIVO              TINYINT(1)   NOT NULL DEFAULT 1,
    USUARIO_ID          INT          NULL COMMENT 'Quién lo registró',
    FECHA_CREACION      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FECHA_ACTUALIZACION DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT PK_SGS_EJEMPLO PRIMARY KEY (EJEMPLO_ID),
    CONSTRAINT UQ_SGS_EJEMPLO_NOMBRE UNIQUE (NOMBRE),
    CONSTRAINT FK_SGS_EJEMPLO_USUARIO FOREIGN KEY (USUARIO_ID) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Para qué sirve la tabla';

-- Columna nueva en tabla existente (siempre NULL o con DEFAULT, para no romper los INSERT del sistema de origen):
-- ALTER TABLE supervisiones ADD COLUMN IF NOT EXISTS NUEVA_COLUMNA VARCHAR(50) NULL;
-- CREATE INDEX IF NOT EXISTS IX_SUPERVISIONES_NUEVA ON supervisiones (NUEVA_COLUMNA);
