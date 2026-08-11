-- Core PHP S3 Compatible CDN Storage Database Schema
--
-- WARNING: this script DROPs and recreates all tables. It is meant for
-- initial setup only. Running it against a database that already has data
-- will permanently delete every bucket, api_keys, and objects row (object
-- bytes on disk are NOT removed and become orphaned). Do not run this as a
-- routine deploy/upgrade step.

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Table structure for buckets
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `buckets`;
CREATE TABLE `buckets` (
  `id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `visibility` enum('public','private') NOT NULL DEFAULT 'private',
  `cors_origins` json DEFAULT NULL COMMENT 'JSON array of allowed origins for CORS; NULL = CORS disabled',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buckets_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table structure for api_keys
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` char(36) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `bucket_id` char(36) DEFAULT NULL COMMENT 'NULL = unrestricted (all buckets)',
  `access_key` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `secret_key` text NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_access_key_unique` (`access_key`),
  KEY `api_keys_bucket_id_foreign` (`bucket_id`),
  CONSTRAINT `fk_api_keys_bucket` FOREIGN KEY (`bucket_id`) REFERENCES `buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table structure for objects
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `objects`;
CREATE TABLE `objects` (
  `id` char(36) NOT NULL,
  `bucket_id` char(36) NOT NULL,
  `object_key` varchar(1024) NOT NULL,
  `object_key_hash` binary(32) GENERATED ALWAYS AS (UNHEX(SHA2(`object_key`, 256))) STORED,
  `mime_type` varchar(255) DEFAULT NULL,
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `checksum` char(32) DEFAULT NULL,
  `relative_storage_path` varchar(255) NOT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bucket_object_hash_unique` (`bucket_id`, `object_key_hash`),
  KEY `bucket_object_prefix` (`bucket_id`, `object_key`(191)),
  CONSTRAINT `fk_objects_bucket` FOREIGN KEY (`bucket_id`) REFERENCES `buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
