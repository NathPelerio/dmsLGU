/*
 Navicat Premium Dump SQL

 Source Server         : Localhost
 Source Server Type    : MySQL
 Source Server Version : 100432 (10.4.32-MariaDB)
 Source Host           : localhost:3306
 Source Schema         : dmslgu

 Target Server Type    : MySQL
 Target Server Version : 100432 (10.4.32-MariaDB)
 File Encoding         : 65001

 Date: 12/03/2026 09:25:48
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for activity_logs
-- ----------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs`  (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NULL DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `ip_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`log_id`) USING BTREE,
  INDEX `user_id`(`user_id` ASC) USING BTREE,
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of activity_logs
-- ----------------------------

-- ----------------------------
-- Table structure for auth_rate_limits
-- ----------------------------
DROP TABLE IF EXISTS `auth_rate_limits`;
CREATE TABLE `auth_rate_limits`  (
  `rate_limit_id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `ip_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `attempts` int NULL DEFAULT 1,
  `last_attempt` timestamp NOT NULL DEFAULT current_timestamp,
  `blocked_until` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`rate_limit_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of auth_rate_limits
-- ----------------------------

-- ----------------------------
-- Table structure for department_processing
-- ----------------------------
DROP TABLE IF EXISTS `department_processing`;
CREATE TABLE `department_processing`  (
  `process_id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NULL DEFAULT NULL,
  `office_id` int NULL DEFAULT NULL,
  `control_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `received_by` int NULL DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `date_received` date NULL DEFAULT NULL,
  `date_completed` date NULL DEFAULT NULL,
  PRIMARY KEY (`process_id`) USING BTREE,
  INDEX `office_id`(`office_id` ASC) USING BTREE,
  INDEX `received_by`(`received_by` ASC) USING BTREE,
  INDEX `idx_department_processing_doc`(`document_id` ASC) USING BTREE,
  CONSTRAINT `department_processing_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `department_processing_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `department_processing_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of department_processing
-- ----------------------------

-- ----------------------------
-- Table structure for document_actions
-- ----------------------------
DROP TABLE IF EXISTS `document_actions`;
CREATE TABLE `document_actions`  (
  `action_id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NULL DEFAULT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `performed_by` int NULL DEFAULT NULL,
  `office_id` int NULL DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `action_date` timestamp NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`action_id`) USING BTREE,
  INDEX `performed_by`(`performed_by` ASC) USING BTREE,
  INDEX `office_id`(`office_id` ASC) USING BTREE,
  INDEX `idx_document_actions_doc`(`document_id` ASC) USING BTREE,
  CONSTRAINT `document_actions_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `document_actions_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `document_actions_ibfk_3` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of document_actions
-- ----------------------------

-- ----------------------------
-- Table structure for document_routes
-- ----------------------------
DROP TABLE IF EXISTS `document_routes`;
CREATE TABLE `document_routes`  (
  `route_id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NULL DEFAULT NULL,
  `from_user_id` int NULL DEFAULT NULL,
  `to_user_id` int NULL DEFAULT NULL,
  `to_office_id` int NULL DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `route_date` timestamp NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`route_id`) USING BTREE,
  INDEX `from_user_id`(`from_user_id` ASC) USING BTREE,
  INDEX `to_user_id`(`to_user_id` ASC) USING BTREE,
  INDEX `to_office_id`(`to_office_id` ASC) USING BTREE,
  INDEX `idx_document_routes_doc`(`document_id` ASC) USING BTREE,
  CONSTRAINT `document_routes_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `document_routes_ibfk_2` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `document_routes_ibfk_3` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `document_routes_ibfk_4` FOREIGN KEY (`to_office_id`) REFERENCES `offices` (`office_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of document_routes
-- ----------------------------
INSERT INTO `document_routes` VALUES (1, 2, 1, 2, 1, 'pending_department', 'ASAP!', '2026-03-12 01:04:27');
INSERT INTO `document_routes` VALUES (2, 2, 1, 2, 1, 'pending_department', 'dwdwadwawd', '2026-03-12 01:18:42');

-- ----------------------------
-- Table structure for documents
-- ----------------------------
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents`  (
  `document_id` int NOT NULL AUTO_INCREMENT,
  `tracking_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `file_size_bytes` bigint NULL DEFAULT NULL,
  `file_checksum_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `storage_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `public_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `date_received` date NULL DEFAULT NULL,
  `time_received` time NULL DEFAULT NULL,
  `received_by` int NULL DEFAULT NULL,
  `requestor_office_id` int NULL DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `is_outgoing` tinyint(1) NULL DEFAULT 0,
  `created_by` int NULL DEFAULT NULL,
  `time_out` time NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`) USING BTREE,
  UNIQUE INDEX `tracking_code`(`tracking_code` ASC) USING BTREE,
  INDEX `received_by`(`received_by` ASC) USING BTREE,
  INDEX `requestor_office_id`(`requestor_office_id` ASC) USING BTREE,
  INDEX `idx_documents_status`(`status` ASC) USING BTREE,
  INDEX `idx_documents_created_by`(`created_by` ASC) USING BTREE,
  INDEX `idx_documents_date_received`(`date_received` ASC) USING BTREE,
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`requestor_office_id`) REFERENCES `offices` (`office_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `documents_ibfk_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of documents
-- ----------------------------
INSERT INTO `documents` VALUES (1, 'dawdwd', 'wdwdwad', '', 'rentwise.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 23654, 'c02b084ec458b874a185da3355b0024f6e9d84a75f5216ccd2884204050fb2b9', 'storage/documents/20260312_005208_892302bcce_rentwise.docx', NULL, NULL, NULL, NULL, NULL, 'active', NULL, 0, NULL, NULL, '2026-03-12 00:52:08', NULL);
INSERT INTO `documents` VALUES (2, 'dwdwd', 'wdwdwad', '', 'rentwise.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 23654, 'c02b084ec458b874a185da3355b0024f6e9d84a75f5216ccd2884204050fb2b9', 'storage/documents/20260312_005430_ed79b19332_rentwise.docx', NULL, NULL, NULL, NULL, NULL, 'active', 'dwdwadwawd', 0, NULL, NULL, '2026-03-12 00:54:30', '2026-03-12 01:18:43');

-- ----------------------------
-- Table structure for notifications
-- ----------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications`  (
  `notification_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NULL DEFAULT NULL,
  `document_id` int NULL DEFAULT NULL,
  `notification_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `is_read` tinyint(1) NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`notification_id`) USING BTREE,
  INDEX `document_id`(`document_id` ASC) USING BTREE,
  INDEX `idx_notifications_user`(`user_id` ASC) USING BTREE,
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of notifications
-- ----------------------------
INSERT INTO `notifications` VALUES (1, 2, 2, 'document_route', 'Jean Pelerio routed a document to your office', '../department%20heads%20Side/department_documents.php?highlight=2', 0, '2026-03-12 01:04:27');
INSERT INTO `notifications` VALUES (2, 2, 2, 'document_route', 'Jean Pelerio routed a document to your office', '../department%20heads%20Side/department_documents.php?highlight=2', 1, '2026-03-12 01:18:43');

-- ----------------------------
-- Table structure for offices
-- ----------------------------
DROP TABLE IF EXISTS `offices`;
CREATE TABLE `offices`  (
  `office_id` int NOT NULL AUTO_INCREMENT,
  `office_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp,
  `office_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`office_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of offices
-- ----------------------------
INSERT INTO `offices` VALUES (1, 'Municipal Mayor\'s Office', '2026-03-12 01:00:13', 'MMO');

-- ----------------------------
-- Table structure for trusted_devices
-- ----------------------------
DROP TABLE IF EXISTS `trusted_devices`;
CREATE TABLE `trusted_devices`  (
  `device_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `device_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `ip_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_used` timestamp NOT NULL DEFAULT current_timestamp,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`device_id`) USING BTREE,
  UNIQUE INDEX `device_token`(`device_token` ASC) USING BTREE,
  INDEX `user_id`(`user_id` ASC) USING BTREE,
  CONSTRAINT `trusted_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of trusted_devices
-- ----------------------------

-- ----------------------------
-- Table structure for users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users`  (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `office_id` int NULL DEFAULT NULL,
  `photo_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `photo_mime` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `photo_size_bytes` bigint NULL DEFAULT NULL,
  `photo_checksum_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `signature_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `signature_mime` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `signature_size_bytes` bigint NULL DEFAULT NULL,
  `signature_checksum_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `stamp_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `stamp_mime` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `stamp_size_bytes` bigint NULL DEFAULT NULL,
  `stamp_checksum_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `stamp_width_pct` decimal(5, 2) NOT NULL DEFAULT 18.00,
  `stamp_x_pct` decimal(5, 2) NOT NULL DEFAULT 82.00,
  `stamp_y_pct` decimal(5, 2) NOT NULL DEFAULT 84.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`) USING BTREE,
  UNIQUE INDEX `email`(`email` ASC) USING BTREE,
  UNIQUE INDEX `uq_users_username`(`username` ASC) USING BTREE,
  INDEX `office_id`(`office_id` ASC) USING BTREE,
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of users
-- ----------------------------
INSERT INTO `users` VALUES (1, 'Jean Pelerio', 'jeanXnathan', 'superadmin@gmail.com', 'superadmin123', 'superadmin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18.00, 82.00, 84.00, '2026-03-12 08:45:18', NULL);
INSERT INTO `users` VALUES (2, 'Jik Gaelapon', 'adobo', 'albertacangan@gmail.com', '$2y$10$PSpOtUB37lJhA4y9lpZcA.xuFOU1083aOpjPclS31sYxQ1emolIUq', 'department_head', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 18.00, 82.00, 84.00, '2026-03-12 00:48:05', '2026-03-12 09:00:52');

SET FOREIGN_KEY_CHECKS = 1;
