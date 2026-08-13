<?php

/**
 * CLI Command to export files from an S3 bucket to a local directory.
 *
 * Usage:
 *   php export.php bucket_name absolute_path_of_destination_folder [source_folder] [date_format]
 *
 * Date Format Options:
 *   "" (default)  - Removes any YYYY/MM/DD/ or YYYY/MM/ date folders from the exported relative path.
 *   "YYYY/MM"     - Inserts YYYY/MM/ folder into the exported path based on object creation date.
 *   "YYYY/MM/DD"  - Inserts YYYY/MM/DD/ folder into the exported path based on object creation date.
 *
 * Examples:
 *   php export.php example_bucket /www/wwwroot/example.com/public/upload/profile profile
 *   php export.php example_bucket /www/wwwroot/example.com/public/upload profile YYYY/MM/DD
 *   php export.php example_bucket /www/wwwroot/example.com/public/upload / YYYY/MM
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
    echo "  php export.php <bucket_name> <absolute_path_of_destination_folder> [source_folder] [date_format]\n\n";
    echo "Date Format Options:\n";
    echo "  \"\" (default) - Remove YYYY/MM/DD/ or YYYY/MM/ date folders from path\n";
    echo "  \"YYYY/MM\"    - Include YYYY/MM/ date folder\n";
    echo "  \"YYYY/MM/DD\" - Include YYYY/MM/DD/ date folder\n\n";
    echo "Examples:\n";
    echo "  php export.php example_bucket /www/wwwroot/example.com/public/upload/profile profile\n";
    echo "  php export.php example_bucket /www/wwwroot/example.com/public/upload profile YYYY/MM/DD\n";
    echo "  php export.php example_bucket /www/wwwroot/example.com/public/upload / YYYY/MM\n";
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

function stripDateFromPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    // Remove /YYYY/MM/DD/ or /YYYY/MM/ or leading YYYY/MM/DD/ or YYYY/MM/
    $clean = preg_replace('#(^|/)(\d{4}/\d{2}/\d{2}|\d{4}/\d{2})(/|$)#', '$1', $path);
    $clean = preg_replace('#/+#', '/', $clean);
    return ltrim($clean, '/');
}

function buildExportRelativePath(string $objectKey, string $sourceFolder, string $createdAt, string $dateFormat): string
{
    $key = ltrim(str_replace('\\', '/', $objectKey), '/');

    // Calculate path relative to sourceFolder inside bucket
    if ($sourceFolder !== '') {
        if (str_starts_with($key, $sourceFolder . '/')) {
            $relKey = substr($key, strlen($sourceFolder) + 1);
        } elseif ($key === $sourceFolder) {
            $relKey = basename($key);
        } else {
            $relKey = $key;
        }
    } else {
        $relKey = $key;
    }

    // Always strip existing date structure first
    $cleanKey = stripDateFromPath($relKey);

    if ($dateFormat === '') {
        return $cleanKey;
    }

    $timestamp = strtotime($createdAt) ?: time();
    $dateFolder = date($dateFormat === 'YYYY/MM/DD' ? 'Y/m/d' : 'Y/m', $timestamp);

    $dir = dirname($cleanKey);
    $file = basename($cleanKey);

    if ($dir === '.' || $dir === '' || $dir === '/') {
        return $dateFolder . '/' . $file;
    }

    return $dir . '/' . $dateFolder . '/' . $file;
}

// 1. Parse CLI Arguments
if ($argc < 3) {
    echo "Error: Missing required arguments.\n\n";
    printUsage();
    exit(1);
}

$bucketName = trim($argv[1]);
$destFolderInput = trim($argv[2]);

$sourceFolderInput = '/';
$dateFormat = '';

// Parse optional args & flags
for ($i = 3; $i < $argc; $i++) {
    $arg = trim($argv[$i]);
    if (str_starts_with($arg, '--date-format=')) {
        $dateFormat = trim(substr($arg, 14), '"\'');
    } elseif (str_starts_with($arg, '--date=')) {
        $dateFormat = trim(substr($arg, 7), '"\'');
    } elseif (in_array(strtoupper($arg), ['YYYY/MM/DD', 'YYYY/MM', 'NONE', 'OFF', 'REMOVE', 'DEFAULT'], true)) {
        $dateFormat = strtoupper($arg);
    } else {
        if ($i === 3) {
            $sourceFolderInput = $arg;
        } elseif ($i === 4 && $dateFormat === '') {
            $dateFormat = $arg;
        }
    }
}

// Normalize dateFormat
$dateFormatUpper = strtoupper(trim($dateFormat, '"\' '));
if ($dateFormatUpper === 'YYYY/MM/DD') {
    $dateFormat = 'YYYY/MM/DD';
} elseif ($dateFormatUpper === 'YYYY/MM') {
    $dateFormat = 'YYYY/MM';
} else {
    $dateFormat = '';
}

// 2. Prepare Local Destination Directory
if (!is_dir($destFolderInput)) {
    if (!mkdir($destFolderInput, 0755, true) && !is_dir($destFolderInput)) {
        fwrite(STDERR, "Error: Could not create destination directory '{$destFolderInput}'.\n");
        exit(1);
    }
}
$realDestDir = realpath($destFolderInput);
if ($realDestDir === false || !is_dir($realDestDir)) {
    fwrite(STDERR, "Error: Destination directory '{$destFolderInput}' is invalid or not writable.\n");
    exit(1);
}

// 3. Normalize source folder
if ($sourceFolderInput === '/' || $sourceFolderInput === '\\') {
    $sourceFolder = '';
} else {
    $sourceFolder = trim(str_replace('\\', '/', $sourceFolderInput), '/');
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

// 5. Query Objects to Export
try {
    if ($sourceFolder !== '') {
        $objects = DB::fetchAll(
            "SELECT * FROM `objects` WHERE `bucket_id` = ? AND (`object_key` = ? OR `object_key` LIKE ?) ORDER BY `created_at` ASC",
            [$bucket['id'], $sourceFolder, $sourceFolder . '/%']
        );
    } else {
        $objects = DB::fetchAll(
            "SELECT * FROM `objects` WHERE `bucket_id` = ? ORDER BY `created_at` ASC",
            [$bucket['id']]
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to query objects: " . $e->getMessage() . "\n");
    exit(1);
}

$totalObjects = count($objects);
echo "Found {$totalObjects} object(s) in bucket '{$bucketName}'" . ($sourceFolder !== '' ? " under '{$sourceFolder}'" : "") . ".\n";
echo "Exporting to local directory '{$realDestDir}' [Date Format: " . ($dateFormat !== '' ? $dateFormat : 'Removed (Default)') . "]...\n";
echo "--------------------------------------------------\n";

$storageBaseDir = S3Common::getStorageBaseDir();
$bucketStorageDir = $storageBaseDir . '/' . $bucket['name'];

$exportedCount = 0;
$skippedCount = 0;
$failedCount = 0;

foreach ($objects as $obj) {
    $srcDiskFile = $bucketStorageDir . '/' . $obj['relative_storage_path'];
    if (!file_exists($srcDiskFile)) {
        echo "[ERROR] Missing physical file for object: {$obj['object_key']}\n";
        $failedCount++;
        continue;
    }

    $finalRelPath = buildExportRelativePath($obj['object_key'], $sourceFolder, $obj['created_at'], $dateFormat);
    $destDiskPath = $realDestDir . '/' . $finalRelPath;
    $destDiskDir = dirname($destDiskPath);

    if (!is_dir($destDiskDir)) {
        if (!mkdir($destDiskDir, 0755, true) && !is_dir($destDiskDir)) {
            echo "[ERROR] Failed to create directory {$destDiskDir}\n";
            $failedCount++;
            continue;
        }
    }

    // Check if local file is already up to date
    if (file_exists($destDiskPath) && filesize($destDiskPath) === (int)$obj['size'] && md5_file($destDiskPath) === $obj['checksum']) {
        echo "[SKIPPED] Up to date: {$finalRelPath}\n";
        $skippedCount++;
        continue;
    }

    if (!copy($srcDiskFile, $destDiskPath)) {
        echo "[ERROR] Failed to export object {$obj['object_key']} to {$destDiskPath}\n";
        $failedCount++;
        continue;
    }

    echo "[EXPORTED] {$obj['object_key']} => {$finalRelPath} (" . formatBytes((int)$obj['size']) . ")\n";
    $exportedCount++;
}

echo "--------------------------------------------------\n";
echo "Export Complete!\n";
echo "Total: {$totalObjects} | Exported: {$exportedCount} | Skipped: {$skippedCount} | Failed: {$failedCount}\n";
