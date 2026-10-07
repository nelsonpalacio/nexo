ALTER TABLE usuarios MODIFY rol ENUM('ADMIN', 'COORDINADOR', 'INVESTIGADOR') NOT NULL DEFAULT 'INVESTIGADOR';
UPDATE usuarios SET rol = 'INVESTIGADOR' WHERE rol IS NULL OR rol = '';

SET @columna_contacto = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'visitas'
      AND COLUMN_NAME = 'contacto_funcionario'
);
SET @sql_contacto = IF(
    @columna_contacto = 0,
    'ALTER TABLE visitas ADD COLUMN contacto_funcionario VARCHAR(40) NULL AFTER cargo_funcionario',
    'SELECT 1'
);
PREPARE stmt_contacto FROM @sql_contacto;
EXECUTE stmt_contacto;
DEALLOCATE PREPARE stmt_contacto;


CREATE TABLE IF NOT EXISTS equipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    tipo ENUM('OPERATIVO', 'ADMINISTRATIVO') NOT NULL DEFAULT 'OPERATIVO',
    lider_id INT UNSIGNED NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipos_lider FOREIGN KEY (lider_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS equipo_usuarios (
    equipo_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    fecha_asignacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (equipo_id, usuario_id),
    CONSTRAINT fk_equipo_usuarios_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    CONSTRAINT fk_equipo_usuarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS capitales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado VARCHAR(100) NOT NULL UNIQUE,
    capital VARCHAR(100) NOT NULL,
    estado_activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @columna_gobernacion = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'capitales'
      AND COLUMN_NAME = 'gobernacion'
);
SET @sql_gobernacion = IF(
    @columna_gobernacion = 0,
    'ALTER TABLE capitales ADD COLUMN gobernacion VARCHAR(200) NULL AFTER capital',
    'SELECT 1'
);
PREPARE stmt_gobernacion FROM @sql_gobernacion;
EXECUTE stmt_gobernacion;
DEALLOCATE PREPARE stmt_gobernacion;

CREATE TABLE IF NOT EXISTS asignaciones_capitales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    capital_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NOT NULL,
    asignado_por INT UNSIGNED NOT NULL,
    fecha_asignacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_capital_usuario (capital_id, usuario_id),
    CONSTRAINT fk_asignaciones_capital FOREIGN KEY (capital_id) REFERENCES capitales(id) ON DELETE CASCADE,
    CONSTRAINT fk_asignaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_asignaciones_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    CONSTRAINT fk_asignaciones_autor FOREIGN KEY (asignado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @indice_estado_equipo = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'asignaciones_capitales'
      AND INDEX_NAME = 'uq_estado_equipo'
);
SET @sql_indice = IF(
    @indice_estado_equipo = 0,
    'ALTER TABLE asignaciones_capitales ADD UNIQUE KEY uq_estado_equipo (capital_id, equipo_id)',
    'SELECT 1'
);
PREPARE stmt_indice FROM @sql_indice;
EXECUTE stmt_indice;
DEALLOCATE PREPARE stmt_indice;

INSERT INTO equipos (nombre, tipo) SELECT 'Equipo 1', 'OPERATIVO' WHERE NOT EXISTS (SELECT 1 FROM equipos WHERE nombre = 'Equipo 1');
INSERT INTO equipos (nombre, tipo) SELECT 'Equipo 2', 'OPERATIVO' WHERE NOT EXISTS (SELECT 1 FROM equipos WHERE nombre = 'Equipo 2');
INSERT INTO equipos (nombre, tipo) SELECT 'Equipo 3', 'OPERATIVO' WHERE NOT EXISTS (SELECT 1 FROM equipos WHERE nombre = 'Equipo 3');
INSERT INTO equipos (nombre, tipo) SELECT 'Equipo 4 - Embajadas y consulados', 'ADMINISTRATIVO' WHERE NOT EXISTS (SELECT 1 FROM equipos WHERE nombre = 'Equipo 4 - Embajadas y consulados');

INSERT INTO capitales (estado, capital, gobernacion) VALUES
('Distrito Capital', 'Caracas', 'Gobierno del Distrito Capital'),
('La Guaira', 'La Guaira', 'Gobernación del Estado La Guaira'),
('Miranda', 'Los Teques', 'Gobernación del Estado Miranda'),
('Aragua', 'Maracay', 'Gobernación del Estado Aragua'),
('Carabobo', 'Valencia', 'Gobernación del Estado Carabobo'),
('Cojedes', 'San Carlos', 'Gobernación del Estado Cojedes'),
('Barinas', 'Barinas', 'Gobernación del Estado Barinas'),
('Apure', 'San Fernando de Apure', 'Gobernación del Estado Apure')
ON DUPLICATE KEY UPDATE
    capital = VALUES(capital),
    gobernacion = VALUES(gobernacion),
    estado_activo = 1;

