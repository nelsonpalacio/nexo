SET @columna_categoria_archivos = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'archivos'
      AND COLUMN_NAME = 'categoria'
);
SET @sql_categoria_archivos = IF(
    @columna_categoria_archivos = 0,
    'ALTER TABLE archivos ADD COLUMN categoria VARCHAR(32) NOT NULL DEFAULT ''OTROS'' AFTER tipo_mime',
    'SELECT 1'
);
PREPARE stmt_categoria_archivos FROM @sql_categoria_archivos;
EXECUTE stmt_categoria_archivos;
DEALLOCATE PREPARE stmt_categoria_archivos;
