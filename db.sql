-- Core PHP S3 Compatible CDN Storage Database Schema

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Table structure for buckets
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `buckets`;
CREATE TABLE `buckets` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `visibility` enum('public','private') NOT NULL DEFAULT 'private',
  `storage_quota` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buckets_uuid_unique` (`uuid`),
  UNIQUE KEY `buckets_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table structure for api_keys
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `bucket_id` bigint(20) UNSIGNED DEFAULT NULL,
  `access_key` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `secret_key` text NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_access_key_unique` (`access_key`),
  KEY `api_keys_bucket_id_foreign` (`bucket_id`),
  CONSTRAINT `fk_api_keys_bucket` FOREIGN KEY (`bucket_id`) REFERENCES `buckets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table structure for objects
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `objects`;
CREATE TABLE `objects` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `bucket_id` bigint(20) UNSIGNED NOT NULL,
  `object_key` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `checksum` char(32) DEFAULT NULL,
  `relative_storage_path` varchar(255) NOT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `objects_uuid_unique` (`uuid`),
  UNIQUE KEY `bucket_object_unique` (`bucket_id`,`object_key`),
  CONSTRAINT `fk_objects_bucket` FOREIGN KEY (`bucket_id`) REFERENCES `buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
