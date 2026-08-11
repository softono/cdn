<?php

/**
 * Object-level S3 operations: CRUD, copy, batch delete.
 */
class S3Object
{
    private const SAFE_ANONYMOUS_MIME_PREFIXES = ['image/', 'video/', 'audio/', 'text/plain'];
    private const SAFE_ANONYMOUS_MIME_EXACT = ['application/pdf'];

    public static function validateObjectKey(string $objectKey): void
    {
        if (trim($objectKey) === '' || str_contains($objectKey, '..') || str_starts_with($objectKey, '\\')) {
            Response::sendS3Error(400, 'InvalidArgument', 'Invalid object key format or path traversal detected.');
        }
        if (strlen($objectKey) > 1024) {
            Response::sendS3Error(400, 'KeyTooLongError', 'The specified key is too long.');
        }
    }

    /**
     * Determines the Content-Type / Content-Disposition an anonymous (public
     * bucket) reader is allowed to see. Keeps declared types outside a safe
     * allowlist from being served as they were uploaded, which prevents an
     * uploaded HTML/SVG file from executing as a stored XSS payload on this
     * origin.
     */
    private static function isSafeAnonymousMime(string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));
        if (in_array($mimeType, self::SAFE_ANONYMOUS_MIME_EXACT, true)) {
            return true;
        }
        foreach (self::SAFE_ANONYMOUS_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function findObjectForUpdate(string $bucketId, string $objectKey): ?array
    {
        return DB::fetchOne(
            "SELECT `id`, `relative_storage_path` FROM `objects` WHERE `bucket_id` = ? AND `object_key_hash` = UNHEX(SHA2(?, 256)) FOR UPDATE",
            [$bucketId, $objectKey]
        );
    }

    public static function putObject(array $bucket, string $objectKey): void
    {
        self::validateObjectKey($objectKey);

        $maxSize = (int)env('MAX_UPLOAD_SIZE', 536870912);
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $maxSize) {
            Response::sendS3Error(413, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed object size.');
        }

        $input = fopen('php://input', 'rb');
        if (!$input) {
            Response::sendS3Error(500, 'InternalError', 'Failed to read request body stream.');
        }

        $fileUuid = S3Common::generateId();
        $ext = pathinfo($objectKey, PATHINFO_EXTENSION);
        $relativeDir = date('Y/m/d');
        $filename = $fileUuid . ($ext ? '.' . $ext : '');
        $relativeStoragePath = $relativeDir . '/' . $filename;

        $baseDir = S3Common::getStorageBaseDir();
        $tmpDir = $baseDir . '/' . $bucket['name'] . '/.tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir . '/' . $fileUuid . '.tmp';

        $output = fopen($tmpPath, 'wb');
        if (!$output) {
            fclose($input);
            Response::sendS3Error(500, 'InternalError', 'Failed to write object file.');
        }

        $hashContext = hash_init('md5');
        $size = 0;

        while (!feof($input)) {
            $chunk = fread($input, 1048576); // 1 MB chunks
            if ($chunk === false) break;
            $len = strlen($chunk);
            $size += $len;

            if ($size > $maxSize) {
                fclose($input);
                fclose($output);
                @unlink($tmpPath);
                Response::sendS3Error(413, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed object size.');
            }

            hash_update($hashContext, $chunk);
            fwrite($output, $chunk);
        }

        fclose($input);
        fflush($output);
        fclose($output);

        $checksum = hash_final($hashContext);

        $metadata = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_X_AMZ_META_')) {
                $metaKey = strtolower(substr($key, 16));
                $metadata[$metaKey] = $val;
            }
        }
        $metadataJson = !empty($metadata) ? json_encode($metadata) : null;
        $mimeType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';

        $destDir = $baseDir . '/' . $bucket['name'] . '/' . $relativeDir;
        $destPath = $destDir . '/' . $filename;

        $pdo = DB::getConnection();
        $renamed = false;
        $oldPath = null;

        try {
            $pdo->beginTransaction();

            $existing = self::findObjectForUpdate($bucket['id'], $objectKey);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            if (!rename($tmpPath, $destPath)) {
                throw new RuntimeException('Failed to move uploaded object into place.');
            }
            $renamed = true;

            if ($existing) {
                if ($existing['relative_storage_path'] !== $relativeStoragePath) {
                    $oldPath = $baseDir . '/' . $bucket['name'] . '/' . $existing['relative_storage_path'];
                }
                DB::execute("UPDATE `objects` SET `size` = ?, `checksum` = ?, `relative_storage_path` = ?, `mime_type` = ?, `metadata_json` = ?, `updated_at` = NOW() WHERE `id` = ?", [
                    $size, $checksum, $relativeStoragePath, $mimeType, $metadataJson, $existing['id']
                ]);
            } else {
                $id = S3Common::generateId();
                DB::execute("INSERT INTO `objects` (`id`, `bucket_id`, `object_key`, `mime_type`, `size`, `checksum`, `relative_storage_path`, `metadata_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
                    $id, $bucket['id'], $objectKey, $mimeType, $size, $checksum, $relativeStoragePath, $metadataJson
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($renamed ? $destPath : $tmpPath);
            throw $e;
        }

        if ($oldPath !== null && file_exists($oldPath)) {
            @unlink($oldPath);
        }

        http_response_code(200);
        header('Content-Length: 0');
        header('ETag: "' . $checksum . '"');
        exit;
    }

    public static function copyObject(array $srcBucket, string $srcObjectKey, array $destBucket, string $destObjectKey): void
    {
        self::validateObjectKey($srcObjectKey);
        self::validateObjectKey($destObjectKey);

        $baseDir = S3Common::getStorageBaseDir();

        $srcObj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key_hash` = UNHEX(SHA2(?, 256))", [$srcBucket['id'], $srcObjectKey]);
        if (!$srcObj) {
            Response::sendS3Error(404, 'NoSuchKey', 'The source object key does not exist.');
        }

        $srcFile = $baseDir . '/' . $srcBucket['name'] . '/' . $srcObj['relative_storage_path'];
        if (!file_exists($srcFile)) {
            Response::sendS3Error(404, 'NoSuchKey', 'Source file content missing.');
        }

        $fileUuid = S3Common::generateId();
        $ext = pathinfo($destObjectKey, PATHINFO_EXTENSION);
        $relativeDir = date('Y/m/d');
        $filename = $fileUuid . ($ext ? '.' . $ext : '');
        $relativeStoragePath = $relativeDir . '/' . $filename;

        $tmpDir = $baseDir . '/' . $destBucket['name'] . '/.tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir . '/' . $fileUuid . '.tmp';

        if (!copy($srcFile, $tmpPath)) {
            Response::sendS3Error(500, 'InternalError', 'Failed to copy object file.');
        }

        $destDir = $baseDir . '/' . $destBucket['name'] . '/' . $relativeDir;
        $destPath = $destDir . '/' . $filename;

        $pdo = DB::getConnection();
        $renamed = false;
        $oldPath = null;

        try {
            $pdo->beginTransaction();

            $existing = self::findObjectForUpdate($destBucket['id'], $destObjectKey);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            if (!rename($tmpPath, $destPath)) {
                throw new RuntimeException('Failed to move copied object into place.');
            }
            $renamed = true;

            if ($existing) {
                if ($existing['relative_storage_path'] !== $relativeStoragePath) {
                    $oldPath = $baseDir . '/' . $destBucket['name'] . '/' . $existing['relative_storage_path'];
                }
                DB::execute("UPDATE `objects` SET `size` = ?, `checksum` = ?, `relative_storage_path` = ?, `mime_type` = ?, `metadata_json` = ?, `updated_at` = NOW() WHERE `id` = ?", [
                    $srcObj['size'], $srcObj['checksum'], $relativeStoragePath, $srcObj['mime_type'], $srcObj['metadata_json'], $existing['id']
                ]);
            } else {
                $id = S3Common::generateId();
                DB::execute("INSERT INTO `objects` (`id`, `bucket_id`, `object_key`, `mime_type`, `size`, `checksum`, `relative_storage_path`, `metadata_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
                    $id, $destBucket['id'], $destObjectKey, $srcObj['mime_type'], $srcObj['size'], $srcObj['checksum'], $relativeStoragePath, $srcObj['metadata_json']
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($renamed ? $destPath : $tmpPath);
            throw $e;
        }

        if ($oldPath !== null && file_exists($oldPath)) {
            @unlink($oldPath);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
. '<CopyObjectResult>'
    . '<LastModified>' . date('c') . '</LastModified>'
    . '<ETag>"' . $srcObj['checksum'] . '"</ETag>'
    . '</CopyObjectResult>';

Response::sendXml(200, $xml);
}

public static function getObject(array $bucket, string $objectKey, bool $isHead = false, bool $isAnonymous = false):
void
{
self::validateObjectKey($objectKey);

$obj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key_hash` = UNHEX(SHA2(?, 256))",
[$bucket['id'], $objectKey]);
if (!$obj) {
Response::sendS3Error(404, 'NoSuchKey', 'The specified key does not exist.');
}

$filePath = S3Common::getStorageBaseDir() . '/' . $bucket['name'] . '/' . $obj['relative_storage_path'];
$metadata = $obj['metadata_json'] ? (json_decode($obj['metadata_json'], true) ?: []) : [];

$mimeType = $obj['mime_type'] ?: 'application/octet-stream';
$forceDownload = false;
if ($isAnonymous && !self::isSafeAnonymousMime($mimeType)) {
$mimeType = 'application/octet-stream';
$forceDownload = true;
}

$lastModified = strtotime($obj['updated_at']) ?: time();
$cacheControl = $bucket['visibility'] === 'public' ? 'public, max-age=31536000, immutable' : 'private, no-store';

if ($isHead) {
Response::sendHeadResponse(200, $mimeType, (int)$obj['size'], $obj['checksum'] ?: '', $metadata, $lastModified,
$cacheControl, $forceDownload);
} else {
Response::streamFile($filePath, $mimeType, (int)$obj['size'], $obj['checksum'] ?: '', $metadata, $lastModified,
$cacheControl, $forceDownload);
}
}

public static function deleteObject(array $bucket, string $objectKey): void
{
self::validateObjectKey($objectKey);

$obj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key_hash` = UNHEX(SHA2(?, 256))",
[$bucket['id'], $objectKey]);
if ($obj) {
DB::execute("DELETE FROM `objects` WHERE `id` = ?", [$obj['id']]);
$filePath = S3Common::getStorageBaseDir() . '/' . $bucket['name'] . '/' . $obj['relative_storage_path'];
if (file_exists($filePath)) {
@unlink($filePath);
}
}

http_response_code(204);
exit;
}

/**
* Batch delete for POST /{bucket}?delete. $keys is a list of object
* keys parsed from the request's <Delete><Object>
        <Key> XML.
            */
            public static function deleteObjects(array $bucket, array $keys): void
            {
            $baseDir = S3Common::getStorageBaseDir();
            $deleted = [];
            $errors = [];

            foreach ($keys as $objectKey) {
            if (trim($objectKey) === '' || str_contains($objectKey, '..') || str_starts_with($objectKey, '\\') ||
            strlen($objectKey) > 1024) {
            $errors[] = ['key' => $objectKey, 'code' => 'InvalidArgument', 'message' => 'Invalid object key format or
            path traversal detected.'];
            continue;
            }

            $obj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key_hash` = UNHEX(SHA2(?,
            256))", [$bucket['id'], $objectKey]);
            if ($obj) {
            DB::execute("DELETE FROM `objects` WHERE `id` = ?", [$obj['id']]);
            $filePath = $baseDir . '/' . $bucket['name'] . '/' . $obj['relative_storage_path'];
            if (file_exists($filePath)) {
            @unlink($filePath);
            }
            }
            $deleted[] = $objectKey;
            }

            $xml = '
            <?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
                foreach ($deleted as $key) {
                $xml .= '<Deleted>
                    <Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key>
                </Deleted>';
                }
                foreach ($errors as $err) {
                $xml .= '<Error>'
                    . '<Key>' . htmlspecialchars($err['key'], ENT_XML1, 'UTF-8') . '</Key>'
                    . '<Code>' . htmlspecialchars($err['code'], ENT_XML1, 'UTF-8') . '</Code>'
                    . '<Message>' . htmlspecialchars($err['message'], ENT_XML1, 'UTF-8') . '</Message>'
                    . '</Error>';
                }
                $xml .= '</DeleteResult>';

            Response::sendXml(200, $xml);
            }

            /**
            * Parses the <Delete><Object>
                    <Key>...</Key>
                </Object>...</Delete> body
            * of a POST /{bucket}?delete request into a flat list of object keys.
            */
            public static function parseDeleteKeys(string $xmlBody): array
            {
            $prevSetting = libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xmlBody);
            libxml_use_internal_errors($prevSetting);

            if ($doc === false) {
            Response::sendS3Error(400, 'MalformedXML', 'The XML you provided was not well-formed.');
            }

            $keys = [];
            foreach ($doc->Object as $obj) {
            $keys[] = (string)$obj->Key;
            }
            return $keys;
            }
            }