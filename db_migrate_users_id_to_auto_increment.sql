-- Migrates users.id from random VARCHAR to BIGINT AUTO_INCREMENT.
-- Also updates all known related user FK columns.
-- Run this once on your active DB (default: dmslgu).

USE `dmslgu`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Keep old->new user id mapping
DROP TABLE IF EXISTS `_tmp_user_id_map`;
CREATE TABLE `_tmp_user_id_map` (
  `old_id` VARCHAR(64) NOT NULL,
  `new_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`old_id`),
  UNIQUE KEY `uq_new_id` (`new_id`)
) ENGINE=InnoDB;

-- If user_no exists, use it as new id order; otherwise generate sequence.
SET @has_user_no := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'user_no'
);

SET @sql_insert_map := IF(
  @has_user_no > 0,
  'INSERT INTO `_tmp_user_id_map` (`old_id`, `new_id`)
   SELECT CAST(`id` AS CHAR), CAST(`user_no` AS UNSIGNED)
   FROM `users`
   ORDER BY `user_no` ASC',
  'SET @row := 0'
);
PREPARE stmt_insert_map FROM @sql_insert_map;
EXECUTE stmt_insert_map;
DEALLOCATE PREPARE stmt_insert_map;

SET @sql_insert_seq := IF(
  @has_user_no > 0,
  'SELECT 1',
  'INSERT INTO `_tmp_user_id_map` (`old_id`, `new_id`)
   SELECT CAST(`id` AS CHAR), (@row := @row + 1)
   FROM `users`
   ORDER BY `created_at` ASC, `id` ASC'
);
PREPARE stmt_insert_seq FROM @sql_insert_seq;
EXECUTE stmt_insert_seq;
DEALLOCATE PREPARE stmt_insert_seq;

-- 2) Update users.id values to numeric text first
UPDATE `users` u
JOIN `_tmp_user_id_map` m ON CAST(u.`id` AS CHAR) = m.`old_id`
SET u.`id` = CAST(m.`new_id` AS CHAR);

-- 3) Update related columns values (still varchar at this stage)
UPDATE `offices` o
JOIN `_tmp_user_id_map` m ON CAST(o.`office_head_id` AS CHAR) = m.`old_id`
SET o.`office_head_id` = CAST(m.`new_id` AS CHAR);

UPDATE `documents` d
JOIN `_tmp_user_id_map` m ON CAST(d.`created_by` AS CHAR) = m.`old_id`
SET d.`created_by` = CAST(m.`new_id` AS CHAR);

UPDATE `sent_to_super_admin` s
JOIN `_tmp_user_id_map` m ON CAST(s.`sent_by_user_id` AS CHAR) = m.`old_id`
SET s.`sent_by_user_id` = CAST(m.`new_id` AS CHAR);

UPDATE `sent_to_admin` s
JOIN `_tmp_user_id_map` m ON CAST(s.`sent_by_user_id` AS CHAR) = m.`old_id`
SET s.`sent_by_user_id` = CAST(m.`new_id` AS CHAR);

UPDATE `sent_to_department_heads` s
JOIN `_tmp_user_id_map` m ON CAST(s.`office_head_id` AS CHAR) = m.`old_id`
SET s.`office_head_id` = CAST(m.`new_id` AS CHAR);

UPDATE `sent_to_department_heads` s
JOIN `_tmp_user_id_map` m ON CAST(s.`sent_by_user_id` AS CHAR) = m.`old_id`
SET s.`sent_by_user_id` = CAST(m.`new_id` AS CHAR);

UPDATE `document_history` h
JOIN `_tmp_user_id_map` m ON CAST(h.`user_id` AS CHAR) = m.`old_id`
SET h.`user_id` = CAST(m.`new_id` AS CHAR);

UPDATE `super_admin_notifications` n
JOIN `_tmp_user_id_map` m ON CAST(n.`sent_by_user_id` AS CHAR) = m.`old_id`
SET n.`sent_by_user_id` = CAST(m.`new_id` AS CHAR);

UPDATE `activity_logs` a
JOIN `_tmp_user_id_map` m ON CAST(a.`actor_id` AS CHAR) = m.`old_id`
SET a.`actor_id` = CAST(m.`new_id` AS CHAR);

-- 4) Clean empty-string references so type conversion won't fail
UPDATE `offices` SET `office_head_id` = NULL WHERE TRIM(COALESCE(`office_head_id`, '')) = '';
UPDATE `documents` SET `created_by` = NULL WHERE TRIM(COALESCE(`created_by`, '')) = '';
UPDATE `sent_to_super_admin` SET `sent_by_user_id` = NULL WHERE TRIM(COALESCE(`sent_by_user_id`, '')) = '';
UPDATE `sent_to_admin` SET `sent_by_user_id` = NULL WHERE TRIM(COALESCE(`sent_by_user_id`, '')) = '';
UPDATE `sent_to_department_heads` SET `office_head_id` = NULL WHERE TRIM(COALESCE(`office_head_id`, '')) = '';
UPDATE `sent_to_department_heads` SET `sent_by_user_id` = NULL WHERE TRIM(COALESCE(`sent_by_user_id`, '')) = '';
UPDATE `document_history` SET `user_id` = NULL WHERE TRIM(COALESCE(`user_id`, '')) = '';
UPDATE `super_admin_notifications` SET `sent_by_user_id` = NULL WHERE TRIM(COALESCE(`sent_by_user_id`, '')) = '';
UPDATE `activity_logs` SET `actor_id` = '' WHERE `actor_id` IS NULL;

-- 5) Drop known FKs that reference users.id (if present)
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'offices'
    AND CONSTRAINT_NAME = 'fk_offices_head_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `offices` DROP FOREIGN KEY `fk_offices_head_user`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'documents'
    AND CONSTRAINT_NAME = 'fk_documents_created_by_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `documents` DROP FOREIGN KEY `fk_documents_created_by_user`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sent_to_super_admin'
    AND CONSTRAINT_NAME = 'fk_sent_super_admin_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `sent_to_super_admin` DROP FOREIGN KEY `fk_sent_super_admin_user`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sent_to_admin'
    AND CONSTRAINT_NAME = 'fk_sent_admin_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `sent_to_admin` DROP FOREIGN KEY `fk_sent_admin_user`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sent_to_department_heads'
    AND CONSTRAINT_NAME = 'fk_sent_heads_office_head'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `sent_to_department_heads` DROP FOREIGN KEY `fk_sent_heads_office_head`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sent_to_department_heads'
    AND CONSTRAINT_NAME = 'fk_sent_heads_sender'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `sent_to_department_heads` DROP FOREIGN KEY `fk_sent_heads_sender`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_history'
    AND CONSTRAINT_NAME = 'fk_document_history_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `document_history` DROP FOREIGN KEY `fk_document_history_user`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'super_admin_notifications'
    AND CONSTRAINT_NAME = 'fk_super_admin_notifications_sender'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE `super_admin_notifications` DROP FOREIGN KEY `fk_super_admin_notifications_sender`', 'SELECT 1');
PREPARE stmt_fk FROM @sql; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

-- 6) Convert users.id and related columns to BIGINT
-- If user_no exists and is AUTO_INCREMENT, remove AUTO_INCREMENT first.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'user_no'
);
SET @sql := IF(@col_exists > 0, 'ALTER TABLE `users` MODIFY COLUMN `user_no` BIGINT UNSIGNED NOT NULL', 'SELECT 1');
PREPARE stmt_user_no_no_ai FROM @sql;
EXECUTE stmt_user_no_no_ai;
DEALLOCATE PREPARE stmt_user_no_no_ai;

-- Ensure users.id is indexed before setting AUTO_INCREMENT
SET @id_idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'id'
);
SET @sql := IF(@id_idx_exists > 0, 'SELECT 1', 'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_id_mig` (`id`)');
PREPARE stmt_id_idx FROM @sql;
EXECUTE stmt_id_idx;
DEALLOCATE PREPARE stmt_id_idx;

ALTER TABLE `users`
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `offices`
  MODIFY COLUMN `office_head_id` BIGINT UNSIGNED NULL;

ALTER TABLE `documents`
  MODIFY COLUMN `created_by` BIGINT UNSIGNED NULL;

ALTER TABLE `sent_to_super_admin`
  MODIFY COLUMN `sent_by_user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `sent_to_admin`
  MODIFY COLUMN `sent_by_user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `sent_to_department_heads`
  MODIFY COLUMN `office_head_id` BIGINT UNSIGNED NULL,
  MODIFY COLUMN `sent_by_user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `document_history`
  MODIFY COLUMN `user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `super_admin_notifications`
  MODIFY COLUMN `sent_by_user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `activity_logs`
  MODIFY COLUMN `actor_id` VARCHAR(64) NOT NULL DEFAULT '';

-- 7) Recreate FKs to users.id
ALTER TABLE `offices`
  ADD CONSTRAINT `fk_offices_head_user`
  FOREIGN KEY (`office_head_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_created_by_user`
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sent_to_super_admin`
  ADD CONSTRAINT `fk_sent_super_admin_user`
  FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sent_to_admin`
  ADD CONSTRAINT `fk_sent_admin_user`
  FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sent_to_department_heads`
  ADD CONSTRAINT `fk_sent_heads_office_head`
  FOREIGN KEY (`office_head_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sent_heads_sender`
  FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `document_history`
  ADD CONSTRAINT `fk_document_history_user`
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `super_admin_notifications`
  ADD CONSTRAINT `fk_super_admin_notifications_sender`
  FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 8) Drop helper columns/tables
SET @sql_drop_user_no := IF(
  @has_user_no > 0,
  'ALTER TABLE `users` DROP COLUMN `user_no`',
  'SELECT 1'
);
PREPARE stmt_drop_user_no FROM @sql_drop_user_no;
EXECUTE stmt_drop_user_no;
DEALLOCATE PREPARE stmt_drop_user_no;

DROP TABLE IF EXISTS `_tmp_user_id_map`;

SET FOREIGN_KEY_CHECKS = 1;
