<?php

/**
 * CLI Command to import local files/folders into an S3 bucket.
 *
 * Usage:
 *   php import.php bucket_name absolute_path_of_source_folder [destination_folder]
 *
 * Examples:
 *   php import.php example_bucket /www/wwwroot/example.com/public/upload/profile profile
 *   php import.php example_bucket /www/wwwroot/example.com/public/upload
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../src/env.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/s3/common.php';

// Load environment configuration from root .env
loadEnv(__DIR__ . '/../.env');

function printUsage(): void
{
    echo "Usage:\n";
    echo "  php import.php <bucket_name> <absolute_path_of_source_folder> [destination_folder]\n\n";
    echo "Examples:\n";
    echo "  php import.php example_bucket /www/wwwroot/example.com/public/upload/profile profile\n";
    echo "  php import.php example_bucket /www/wwwroot/example.com/public/upload\n";
}

function getMimeType(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'bmp'  => 'image/bmp',
        'pdf'  => 'application/pdf',
        'mp4'  => 'video/mp4',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'zip'  => 'application/zip',
    ];

    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            @finfo_close($finfo);
            if ($mime && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }
    }

    return $mimes[$ext] ?? 'application/octet-stream';
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

// 1. Parse CLI Arguments
if ($argc < 3) {
    echo "Error: Missing required arguments.\n\n";
    printUsage();
    exit(1);
}

$bucketName = trim($argv[1]);
$sourceFolderInput = trim($argv[2]);
$destFolderInput = isset($argv[3]) ? trim($argv[3]) : '/';

// 2. Validate source path
$realSourcePath = realpath($sourceFolderInput);
if ($realSourcePath === false || (!is_dir($realSourcePath) && !is_file($realSourcePath))) {
    fwrite(STDERR, "Error: Source path '{$sourceFolderInput}' does not exist or is not accessible.\n");
    exit(1);
}

// 3. Normalize destination folder
if ($destFolderInput === '/' || $destFolderInput === '\\') {
    $destFolder = '';
} else {
    $destFolder = trim(str_replace('\\', '/', $destFolderInput), '/');
}

// 4. Validate Bucket
try {
    $bucket = DB::fetchOne("SELECT * FROM `buckets` WHERE `name` = ?", [$bucketName]);
    if (!$bucket) {
        fwrite(STDERR, "Error: Bucket '{$bucketName}' not found in database.\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection error: " . $e->getMessage() . "\n");
    exit(1);
}

// 5. Gather files to import
$files = [];
if (is_file($realSourcePath)) {
    $files[] = $realSourcePath;
} else {
    $dirIterator = new RecursiveDirectoryIterator($realSourcePath, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $files[] = $fileInfo->getPathname();
        }
    }
}

$totalFiles = count($files);
echo "Found {$totalFiles} file(s) to process.\n";
echo "Importing to bucket '{$bucketName}'" . ($destFolder !== '' ? " under destination folder '{$destFolder}'" : "") . "...\n";
echo "--------------------------------------------------\n";

$storageBaseDir = S3Common::getStorageBaseDir();
$bucketStorageDir = $storageBaseDir . '/' . $bucket['name'];

$importedCount = 0;
$skippedCount = 0;
$failedCount = 0;

$normalizedSourceDir = rtrim(str_replace('\\', '/', $realSourcePath), '/');

foreach ($files as $filePath) {
    $normalizedFilePath = str_replace('\\', '/', $filePath);

    if (is_dir($realSourcePath)) {
        if (str_starts_with($normalizedFilePath, $normalizedSourceDir . '/')) {
            $relativePath = substr($normalizedFilePath, strlen($normalizedSourceDir) + 1);
        } else {
            $relativePath = basename($normalizedFilePath);
        }
    } else {
        $relativePath = basename($normalizedFilePath);
    }

    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    if ($destFolder !== '') {
        $objectKey = $destFolder . '/' . $relativePath;
    } else {
        $objectKey = $relativePath;
    }
    $objectKey = ltrim(str_replace('\\', '/', $objectKey), '/');

    // Key format validation
    if (trim($objectKey) === '' || str_contains($objectKey, '..') || str_starts_with($objectKey, '\\') || strlen($objectKey) > 1024) {
        echo "[SKIP] Invalid object key: {$objectKey}\n";
        $skippedCount++;
        continue;
    }

    $size = filesize($filePath);
    $checksum = md5_file($filePath);
    $mimeType = getMimeType($filePath);

    // Check existing object in database
    $existing = DB::fetchOne(
        "SELECT `id`, `relative_storage_path`, `checksum`, `size` FROM `objects` WHERE `bucket_id` = ? AND `object_key_hash` = UNHEX(SHA2(?, 256))",
        [$bucket['id'], $objectKey]
    );

    if ($existing) {
        $existingDiskPath = $bucketStorageDir . '/' . $existing['relative_storage_path'];
        if ($existing['checksum'] === $checksum && (int)$existing['size'] === (int)$size && file_exists($existingDiskPath)) {
            echo "[SKIPPED] Up to date: {$objectKey}\n";
            $skippedCount++;
            continue;
        }
    }

    // Build relative storage path using standard S3 rules ({dir}/{YYYY}/{MM}/{DD}/{uuid}.ext)
    $fileUuid = S3Common::generateId();
    $relativeStoragePath = S3Common::buildRelativeStoragePath($objectKey, $fileUuid);
    $destDiskPath = $bucketStorageDir . '/' . $relativeStoragePath;
    $destDiskDir = dirname($destDiskPath);

    if (!is_dir($destDiskDir)) {
        if (!mkdir($destDiskDir, 0755, true) && !is_dir($destDiskDir)) {
            echo "[ERROR] Failed to create directory {$destDiskDir}\n";
            $failedCount++;
            continue;
        }
    }

    if (!copy($filePath, $destDiskPath)) {
        echo "[ERROR] Failed to copy file {$filePath}\n";
        $failedCount++;
        continue;
    }

    $pdo = DB::getConnection();
    $oldDiskPath = null;

    try {
        $pdo->beginTransaction();

        if ($existing) {
            if ($existing['relative_storage_path'] !== $relativeStoragePath) {
                $oldDiskPath = $bucketStorageDir . '/' . $existing['relative_storage_path'];
            }
            DB::execute(
                "UPDATE `objects` SET `size` = ?, `checksum` = ?, `relative_storage_path` = ?, `mime_type` = ?, `updated_at` = NOW() WHERE `id` = ?",
                [$size, $checksum, $relativeStoragePath, $mimeType, $existing['id']]
            );
        } else {
            $id = S3Common::generateId();
            DB::execute(
                "INSERT INTO `objects` (`id`, `bucket_id`, `object_key`, `mime_type`, `size`, `checksum`, `relative_storage_path`, `metadata_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$id, $bucket['id'], $objectKey, $mimeType, $size, $checksum, $relativeStoragePath, null]
            );
        }

        $pdo->commit();

        if ($oldDiskPath !== null && file_exists($oldDiskPath)) {
            @unlink($oldDiskPath);
        }

        echo "[IMPORTED] {$objectKey} (" . formatBytes($size) . ")\n";
        $importedCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($destDiskPath);
        echo "[ERROR] DB error for {$objectKey}: " . $e->getMessage() . "\n";
        $failedCount++;
    }
}

echo "--------------------------------------------------\n";
echo "Import Complete!\n";
echo "Total: {$totalFiles} | Imported: {$importedCount} | Skipped: {$skippedCount} | Failed: {$failedCount}\n";
