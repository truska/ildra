-- Optional entry rate for approved externally recognised riders.
-- NULL means fall back to the corresponding ILDRA member rate.

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_scheme_rows'
       AND COLUMN_NAME = 'foreign_recognition_price') = 0,
    'ALTER TABLE pricing_scheme_rows ADD COLUMN foreign_recognition_price DECIMAL(10,2) NULL DEFAULT NULL AFTER price',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_pricing_rows'
       AND COLUMN_NAME = 'foreign_recognition_price') = 0,
    'ALTER TABLE event_pricing_rows ADD COLUMN foreign_recognition_price DECIMAL(10,2) NULL DEFAULT NULL AFTER price',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
