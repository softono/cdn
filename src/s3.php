<?php

/**
 * S3 Business Logic & Storage Helper
 */
class S3Helper
{
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function getStorageBaseDir(): string
    {
        $dir = env('STORAGE_DIR', __DIR__ . '/../storage');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return rtrim($dir, '/\\');
    }

    public static function getBucket(string $name): ?array
    {
        return DB::fetchOne("SELECT * FROM `buckets` WHERE `name` = ?", [$name]);
    }

    public static function listBuckets(): void
    {
        $buckets = DB::fetchAll("SELECT * FROM `buckets` ORDER BY `name` ASC");
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Owner><ID>storage</ID><DisplayName>storage</DisplayName></Owner>'
            . '<Buckets>';

        foreach ($buckets as $b) {
            $created = isset($b['created_at']) ? date('c', strtotime($b['created_at'])) : date('c');
            $xml .= '<Bucket>'
                . '<Name>' . htmlspecialchars($b['name'], ENT_XML1, 'UTF-8') . '</Name>'
                . '<CreationDate>' . $created . '</CreationDate>'
                . '</Bucket>';
        }

        $xml .= '</Buckets></ListAllMyBucketsResult>';
        ResponseHelper::sendXml(200, $xml);
    }

    public static function validateObjectKey(string $objectKey): void
    {
        if (trim($objectKey) === '' || str_contains($objectKey, '..') || str_starts_with($objectKey, '\\')) {
            ResponseHelper::sendS3Error(400, 'InvalidArgument', 'Invalid object key format or path traversal detected.');
        }
    }

    public static function createBucket(string $name): void
    {
        $name = strtolower(trim($name));
        $reserved = ['login', 'register', 'dashboard', 'buckets', 'objects', 'api-keys', 'admin', 'storage', 'helper', 'index.php'];
        if (in_array($name, $reserved, true)) {
            ResponseHelper::sendS3Error(400, 'InvalidBucketName', 'Bucket name is a reserved system name.');
        }

        if (!preg_match('/^[a-z0-9.\-]{3,63}$/', $name)) {
            ResponseHelper::sendS3Error(400, 'InvalidBucketName', 'The specified bucket name is not valid.');
        }

        $existing = self::getBucket($name);
        if ($existing) {
            ResponseHelper::sendS3Error(409, 'BucketAlreadyExists', 'The requested bucket name already exists.');
        }

        $uuid = self::generateUuid();
        DB::execute("INSERT INTO `buckets` (`uuid`, `name`, `visibility`, `created_at`, `updated_at`) VALUES (?, ?, 'private', NOW(), NOW())", [
            $uuid, $name
        ]);

        $bucketDir = self::getStorageBaseDir() . '/' . $name;
        if (!is_dir($bucketDir)) {
            mkdir($bucketDir, 0755, true);
        }

        http_response_code(200);
        header('Location: /' . $name);
        exit;
    }

    public static function deleteBucket(array $bucket): void
    {
        $countObj = DB::fetchOne("SELECT COUNT(*) as cnt FROM `objects` WHERE `bucket_id` = ?", [$bucket['id']]);
        if ($countObj && $countObj['cnt'] > 0) {
            ResponseHelper::sendS3Error(409, 'BucketNotEmpty', 'The bucket you tried to delete is not empty.');
        }

        DB::execute("DELETE FROM `buckets` WHERE `id` = ?", [$bucket['id']]);

        $bucketDir = self::getStorageBaseDir() . '/' . $bucket['name'];
        if (is_dir($bucketDir)) {
            @rmdir($bucketDir);
        }

        http_response_code(204);
        exit;
    }

    public static function listObjects(array $bucket, string $prefix = '', string $marker = '', int $maxKeys = 1000): void
    {
        $sql = "SELECT * FROM `objects` WHERE `bucket_id` = ?";
        $params = [$bucket['id']];

        if ($prefix !== '') {
            $sql .= " AND `object_key` LIKE ?";
            $params[] = $prefix . '%';
        }
        if ($marker !== '') {
            $sql .= " AND `object_key` > ?";
            $params[] = $marker;
        }

        $sql .= " ORDER BY `object_key` ASC LIMIT " . (int)$maxKeys;
        $objects = DB::fetchAll($sql, $params);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>' . htmlspecialchars($bucket['name'], ENT_XML1, 'UTF-8') . '</Name>'
            . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1, 'UTF-8') . '</Prefix>'
            . '<Marker>' . htmlspecialchars($marker, ENT_XML1, 'UTF-8') . '</Marker>'
            . '<MaxKeys>' . $maxKeys . '</MaxKeys>'
            . '<IsTruncated>false</IsTruncated>';

        foreach ($objects as $obj) {
            $lastMod = isset($obj['updated_at']) ? date('c', strtotime($obj['updated_at'])) : date('c');
            $etag = $obj['checksum'] ? '"' . $obj['checksum'] . '"' : '';

            $xml .= '<Contents>'
                . '<Key>' . htmlspecialchars($obj['object_key'], ENT_XML1, 'UTF-8') . '</Key>'
                . '<LastModified>' . $lastMod . '</LastModified>'
                . '<ETag>' . htmlspecialchars($etag, ENT_XML1, 'UTF-8') . '</ETag>'
                . '<Size>' . (int)$obj['size'] . '</Size>'
                . '<StorageClass>STANDARD</StorageClass>'
                . '</Contents>';
        }

        $xml .= '</ListBucketResult>';
        ResponseHelper::sendXml(200, $xml);
    }

    public static function putObject(array $bucket, string $objectKey): void
    {
        self::validateObjectKey($objectKey);

        $maxSize = (int)env('MAX_UPLOAD_SIZE', 536870912);
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $maxSize) {
            ResponseHelper::sendS3Error(413, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed object size.');
        }

        $input = fopen('php://input', 'rb');
        if (!$input) {
            ResponseHelper::sendS3Error(500, 'InternalError', 'Failed to read request body stream.');
        }

        $uuid = self::generateUuid();
        $ext = pathinfo($objectKey, PATHINFO_EXTENSION);
        $relativeDir = date('Y/m/d');
        $filename = $uuid . ($ext ? '.' . $ext : '');
        $relativeStoragePath = $relativeDir . '/' . $filename;

        $fullDir = self::getStorageBaseDir() . '/' . $bucket['name'] . '/' . $relativeDir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $fullPath = $fullDir . '/' . $filename;
        $output = fopen($fullPath, 'wb');
        if (!$output) {
            fclose($input);
            ResponseHelper::sendS3Error(500, 'InternalError', 'Failed to write object file.');
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
                @unlink($fullPath);
                ResponseHelper::sendS3Error(413, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed object size.');
            }

            hash_update($hashContext, $chunk);
            fwrite($output, $chunk);
        }

        fclose($input);
        fclose($output);

        $checksum = hash_final($hashContext);

        // Capture x-amz-meta-* headers
        $metadata = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_X_AMZ_META_')) {
                $metaKey = strtolower(substr($key, 16));
                $metadata[$metaKey] = $val;
            }
        }
        $metadataJson = !empty($metadata) ? json_encode($metadata) : null;

        $mimeType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';
        $originalFilename = basename($objectKey);

        // Check if object key already exists in this bucket
        $existing = DB::fetchOne("SELECT id, relative_storage_path FROM `objects` WHERE `bucket_id` = ? AND `object_key` = ?", [
            $bucket['id'], $objectKey
        ]);

        if ($existing) {
            // Delete old file if path differs
            $oldFile = self::getStorageBaseDir() . '/' . $bucket['name'] . '/' . $existing['relative_storage_path'];
            if (file_exists($oldFile) && $oldFile !== $fullPath) {
                @unlink($oldFile);
            }

            DB::execute("UPDATE `objects` SET `size` = ?, `checksum` = ?, `relative_storage_path` = ?, `mime_type` = ?, `metadata_json` = ?, `updated_at` = NOW() WHERE `id` = ?", [
                $size, $checksum, $relativeStoragePath, $mimeType, $metadataJson, $existing['id']
            ]);
        } else {
            DB::execute("INSERT INTO `objects` (`uuid`, `bucket_id`, `object_key`, `original_filename`, `mime_type`, `size`, `checksum`, `relative_storage_path`, `metadata_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
                $uuid, $bucket['id'], $objectKey, $originalFilename, $mimeType, $size, $checksum, $relativeStoragePath, $metadataJson
            ]);
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

        $srcObj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key` = ?", [$srcBucket['id'], $srcObjectKey]);
        if (!$srcObj) {
            ResponseHelper::sendS3Error(404, 'NoSuchKey', 'The source object key does not exist.');
        }

        $srcFile = self::getStorageBaseDir() . '/' . $srcBucket['name'] . '/' . $srcObj['relative_storage_path'];
        if (!file_exists($srcFile)) {
            ResponseHelper::sendS3Error(404, 'NoSuchKey', 'Source file content missing.');
        }

        $uuid = self::generateUuid();
        $ext = pathinfo($destObjectKey, PATHINFO_EXTENSION);
        $relativeDir = date('Y/m/d');
        $filename = $uuid . ($ext ? '.' . $ext : '');
        $relativeStoragePath = $relativeDir . '/' . $filename;

        $destDir = self::getStorageBaseDir() . '/' . $destBucket['name'] . '/' . $relativeDir;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $destFile = $destDir . '/' . $filename;
        if (!copy($srcFile, $destFile)) {
            ResponseHelper::sendS3Error(500, 'InternalError', 'Failed to copy object file.');
        }

        $originalFilename = basename($destObjectKey);
        $existing = DB::fetchOne("SELECT id FROM `objects` WHERE `bucket_id` = ? AND `object_key` = ?", [$destBucket['id'], $destObjectKey]);

        if ($existing) {
            DB::execute("UPDATE `objects` SET `size` = ?, `checksum` = ?, `relative_storage_path` = ?, `mime_type` = ?, `metadata_json` = ?, `updated_at` = NOW() WHERE `id` = ?", [
                $srcObj['size'], $srcObj['checksum'], $relativeStoragePath, $srcObj['mime_type'], $srcObj['metadata_json'], $existing['id']
            ]);
        } else {
            DB::execute("INSERT INTO `objects` (`uuid`, `bucket_id`, `object_key`, `original_filename`, `mime_type`, `size`, `checksum`, `relative_storage_path`, `metadata_json`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
                $uuid, $destBucket['id'], $destObjectKey, $originalFilename, $srcObj['mime_type'], $srcObj['size'], $srcObj['checksum'], $relativeStoragePath, $srcObj['metadata_json']
            ]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<CopyObjectResult>'
            . '<LastModified>' . date('c') . '</LastModified>'
            . '<ETag>"' . $srcObj['checksum'] . '"</ETag>'
            . '</CopyObjectResult>';

        ResponseHelper::sendXml(200, $xml);
    }

    public static function getObject(array $bucket, string $objectKey, bool $isHead = false): void
    {
        self::validateObjectKey($objectKey);

        $obj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key` = ?", [$bucket['id'], $objectKey]);
        if (!$obj) {
            ResponseHelper::sendS3Error(404, 'NoSuchKey', 'The specified key does not exist.');
        }

        $filePath = self::getStorageBaseDir() . '/' . $bucket['name'] . '/' . $obj['relative_storage_path'];
        $metadata = $obj['metadata_json'] ? (json_decode($obj['metadata_json'], true) ?: []) : [];

        if ($isHead) {
            ResponseHelper::sendHeadResponse(200, $obj['mime_type'] ?: 'application/octet-stream', (int)$obj['size'], $obj['checksum'] ?: '', $metadata);
        } else {
            ResponseHelper::streamFile($filePath, $obj['mime_type'] ?: 'application/octet-stream', (int)$obj['size'], $obj['checksum'] ?: '', $metadata);
        }
    }

    public static function deleteObject(array $bucket, string $objectKey): void
    {
        self::validateObjectKey($objectKey);

        $obj = DB::fetchOne("SELECT * FROM `objects` WHERE `bucket_id` = ? AND `object_key` = ?", [$bucket['id'], $objectKey]);
        if ($obj) {
            $filePath = self::getStorageBaseDir() . '/' . $bucket['name'] . '/' . $obj['relative_storage_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            DB::execute("DELETE FROM `objects` WHERE `id` = ?", [$obj['id']]);
        }

        http_response_code(204);
        exit;
    }
}
