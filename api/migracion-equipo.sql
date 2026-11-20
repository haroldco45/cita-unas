-- =====================================================================
-- Migración: equipo de trabajo (dueña + trabajadoras)
-- App de citas · Estudio de uñas · Vibras Positivas HM
--
-- Ejecutar UNA SOLA VEZ en phpMyAdmin (pestaña SQL), sobre la base que
-- ya tiene las citas. No borra nada: las citas que ya existen quedan
-- asignadas a la dueña.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '-05:00';

-- Quienes atienden: la dueña y sus trabajadoras
CREATE TABLE IF NOT EXISTS profesionales (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(40)  NOT NULL,
  rol       ENUM('duena','trabajadora') NOT NULL DEFAULT 'trabajadora',
  pin_hash  VARCHAR(255) NULL,                         -- solo trabajadoras; el PIN de la dueña sigue en ajustes
  dias      VARCHAR(20)  NOT NULL DEFAULT '0,1,2,3,4,5,6', -- días que trabaja (0 = domingo), dentro del horario del negocio
  activa    TINYINT(1)   NOT NULL DEFAULT 1,
  orden     SMALLINT     NOT NULL DEFAULT 0,
  creada    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Días puntuales en que una persona no viene (incapacidad, diligencia, vacaciones)
CREATE TABLE IF NOT EXISTS ausencias (
  profesional_id INT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  PRIMARY KEY (profesional_id, fecha),
  CONSTRAINT fk_aus_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La dueña queda como la primera profesional
INSERT INTO profesionales (id, nombre, rol, orden) VALUES (1, 'Dueña', 'duena', 1);

-- Cada cita queda con quién la atiende
ALTER TABLE citas
  ADD COLUMN profesional_id INT UNSIGNED NULL AFTER clienta_id,
  ADD KEY idx_fecha_prof (fecha, profesional_id, estado);
UPDATE citas SET profesional_id = 1 WHERE profesional_id IS NULL;

-- Cada sesión sabe si es de la dueña o de una trabajadora
ALTER TABLE sesiones
  ADD COLUMN rol ENUM('admin','trabajadora') NOT NULL DEFAULT 'admin',
  ADD COLUMN profesional_id INT UNSIGNED NULL;
