-- Adds readable auto-increment user number while keeping existing string id.
-- Run on your current database (dmslgu).

USE `dmslgu`;

ALTER TABLE `users`
  ADD COLUMN `user_no` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST;

