-- Adds/normalizes persistent comments column to LONGTEXT.
-- Safe to run multiple times.

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documents'
      AND COLUMN_NAME = 'comments'
);

SET @ddl_add := IF(
    @column_exists = 0,
    'ALTER TABLE documents ADD COLUMN comments LONGTEXT NULL AFTER notes',
    'SELECT ''documents.comments already exists'' AS info'
);

PREPARE stmt FROM @ddl_add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl_modify := (
    'ALTER TABLE documents MODIFY COLUMN comments LONGTEXT NULL'
);

PREPARE stmt2 FROM @ddl_modify;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
