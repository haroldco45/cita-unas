-- =====================================================================
-- App de citas · Estudio de uñas
-- Base de datos MySQL / MariaDB (Hostinger)
-- Desarrollada por Vibras Positivas HM — Derechos de Autor Reservados
--
-- Cómo usar: en hPanel > Bases de datos > phpMyAdmin, seleccionar la
-- base creada y pegar este archivo en la pestaña SQL.
-- Horas guardadas como minutos desde la medianoche (480 = 8:00 a. m.),
-- siempre en hora de Colombia (America/Bogota, UTC-5).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '-05:00';

-- Ajustes generales del negocio (nombre, slogan, WhatsApp, reglas, horario)
CREATE TABLE IF NOT EXISTS ajustes (
  clave       VARCHAR(40)  NOT NULL PRIMARY KEY,
  valor       TEXT         NOT NULL,
  actualizado DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de servicios (manos, pies, adicionales, combos)
CREATE TABLE IF NOT EXISTS servicios (
  id        VARCHAR(40)   NOT NULL PRIMARY KEY,
  nombre    VARCHAR(80)   NOT NULL,
  categoria VARCHAR(30)   NOT NULL DEFAULT 'Manos',
  duracion  SMALLINT UNSIGNED NOT NULL DEFAULT 30,      -- minutos
  precio    INT UNSIGNED  NULL,                          -- pesos; NULL = por definir
  activo    TINYINT(1)    NOT NULL DEFAULT 1,
  orden     SMALLINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Días en que no se atiende (vacaciones, festivos, diligencias)
CREATE TABLE IF NOT EXISTS bloqueos (
  fecha  DATE        NOT NULL PRIMARY KEY,
  motivo VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clientas: el WhatsApp es la llave (Ley 1581/2012: se guarda la fecha de autorización)
CREATE TABLE IF NOT EXISTS clientas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  telefono       CHAR(10)     NOT NULL UNIQUE,
  nombre         VARCHAR(60)  NOT NULL,
  autoriza_datos DATETIME     NULL,
  creada         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quienes atienden: la dueña y sus trabajadoras
CREATE TABLE IF NOT EXISTS profesionales (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(40)  NOT NULL,
  rol       ENUM('duena','trabajadora') NOT NULL DEFAULT 'trabajadora',
  pin_hash  VARCHAR(255) NULL,                         -- solo trabajadoras; el PIN de la dueña está en ajustes
  dias      VARCHAR(20)  NOT NULL DEFAULT '0,1,2,3,4,5,6', -- días que trabaja (0 = domingo), dentro del horario del negocio
  activa    TINYINT(1)   NOT NULL DEFAULT 1,
  orden     SMALLINT     NOT NULL DEFAULT 0,
  creada    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Días puntuales en que una persona no viene
CREATE TABLE IF NOT EXISTS ausencias (
  profesional_id INT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  PRIMARY KEY (profesional_id, fecha),
  CONSTRAINT fk_aus_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Citas (el histórico completo vive aquí; nunca se borran, se cambian de estado)
CREATE TABLE IF NOT EXISTS citas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo      CHAR(5)      NOT NULL UNIQUE,
  clienta_id  INT UNSIGNED NOT NULL,
  profesional_id INT UNSIGNED NULL,
  fecha       DATE         NOT NULL,
  inicio      SMALLINT UNSIGNED NOT NULL,                 -- minutos desde 00:00
  fin         SMALLINT UNSIGNED NOT NULL,
  duracion    SMALLINT UNSIGNED NOT NULL,
  total       INT UNSIGNED NULL,                          -- NULL si algún servicio no tenía precio
  estado      ENUM('pendiente','confirmada','atendida','noasistio','cancelada') NOT NULL DEFAULT 'pendiente',
  origen      ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
  nota        VARCHAR(240) NULL,
  cancelada_por ENUM('cliente','admin') NULL,
  creada      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fecha_estado (fecha, estado),
  KEY idx_clienta (clienta_id),
  KEY idx_fecha_prof (fecha, profesional_id, estado),
  CONSTRAINT fk_cita_clienta FOREIGN KEY (clienta_id) REFERENCES clientas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicios de cada cita (copia nombre, duración y precio del momento,
-- para que el histórico no cambie si mañana sube el precio)
CREATE TABLE IF NOT EXISTS cita_servicios (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cita_id     INT UNSIGNED NOT NULL,
  servicio_id VARCHAR(40)  NOT NULL,
  nombre      VARCHAR(80)  NOT NULL,
  duracion    SMALLINT UNSIGNED NOT NULL,
  precio      INT UNSIGNED NULL,
  KEY idx_cita (cita_id),
  CONSTRAINT fk_cs_cita FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones de administración (se guarda solo el hash del token)
CREATE TABLE IF NOT EXISTS sesiones (
  token_hash CHAR(64)  NOT NULL PRIMARY KEY,
  rol        ENUM('admin','trabajadora') NOT NULL DEFAULT 'admin',
  profesional_id INT UNSIGNED NULL,
  creada     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira     DATETIME  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Control de intentos (PIN y reservas) para frenar abusos
CREATE TABLE IF NOT EXISTS intentos (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tipo   VARCHAR(20)  NOT NULL,
  ip     VARCHAR(45)  NOT NULL,
  fecha  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tipo_ip (tipo, ip, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Datos iniciales (se pueden cambiar luego desde Administración)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO ajustes (clave, valor) VALUES
 ('negocio',       'Tu estudio de uñas'),
 ('slogan',        'Separa tu cita de manos y pies en un minuto.'),
 ('whatsapp',      ''),
 ('paso',          '30'),
 ('anticipacion',  '60'),
 ('diasVista',     '21'),
 ('horasCancelar', '3'),
 ('horario',       '{"0":{"on":false,"ini":540,"fin":780},"1":{"on":true,"ini":480,"fin":1080},"2":{"on":true,"ini":480,"fin":1080},"3":{"on":true,"ini":480,"fin":1080},"4":{"on":true,"ini":480,"fin":1080},"5":{"on":true,"ini":480,"fin":1080},"6":{"on":true,"ini":480,"fin":960}}'),
 ('almuerzo',      '{"on":true,"ini":720,"fin":780}');
INSERT IGNORE INTO profesionales (id, nombre, rol, orden) VALUES (1, 'Dueña', 'duena', 1);

-- El PIN de administración ('pin_hash') se guarda cifrado la primera vez que
-- la dueña entra con el 'pin_inicial' de config.php.

INSERT IGNORE INTO servicios (id, nombre, categoria, duracion, precio, activo, orden) VALUES
 ('mani-trad',   'Manicure tradicional',          'Manos',       45,  NULL, 1, 1),
 ('mani-semi',   'Manicure semipermanente',       'Manos',       60,  NULL, 1, 2),
 ('acrilicas',   'Uñas acrílicas (set completo)', 'Manos',       120, NULL, 1, 3),
 ('polygel',     'Uñas en polygel',               'Manos',       105, NULL, 1, 4),
 ('softgel',     'Soft gel / press on',           'Manos',       90,  NULL, 1, 5),
 ('relleno',     'Relleno o retoque de acrílico', 'Manos',       90,  NULL, 1, 6),
 ('spa-manos',   'Spa de manos',                  'Manos',       30,  NULL, 1, 7),
 ('pedi-trad',   'Pedicure tradicional',          'Pies',        60,  NULL, 1, 8),
 ('pedi-semi',   'Pedicure semipermanente',       'Pies',        75,  NULL, 1, 9),
 ('spa-pies',    'Spa de pies',                   'Pies',        45,  NULL, 1, 10),
 ('retiro-semi', 'Retiro de semipermanente',      'Adicionales', 20,  NULL, 1, 11),
 ('retiro-acri', 'Retiro de acrílico',            'Adicionales', 40,  NULL, 1, 12),
 ('decoracion',  'Decoración / nail art',         'Adicionales', 20,  NULL, 1, 13),
 ('esmaltado',   'Solo esmaltado',                'Adicionales', 20,  NULL, 1, 14);
