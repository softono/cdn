<?php

/**
 * Bucket-level S3 operations: CRUD, CORS, location.
 */
class S3Bucket
{
    public static function getBucket(string $name): ?array
    {
        return DB::fetchOne("SELECT * FROM `buckets` WHERE `name` = ?", [$name]);
    }

    public static function listBuckets(?array $apiKey = null): void
    {
        if ($apiKey && $apiKey['bucket_id'] !== null) {
            $buckets = DB::fetchAll("SELECT * FROM `buckets` WHERE `id` = ? ORDER BY `name` ASC", [$apiKey['bucket_id']]);
        } else {
            $buckets = DB::fetchAll("SELECT * FROM `buckets` ORDER BY `name` ASC");
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
. '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
    . '<Owner>
        <ID>storage</ID>
        <DisplayName>storage</DisplayName>
    </Owner>'
    . '<Buckets>';

        foreach ($buckets as $b) {
        $created = isset($b['created_at']) ? date('c', strtotime($b['created_at'])) : date('c');
        $xml .= '<Bucket>'
            . '<Name>' . htmlspecialchars($b['name'], ENT_XML1, 'UTF-8') . '</Name>'
            . '<CreationDate>' . $created . '</CreationDate>'
            . '</Bucket>';
        }

        $xml .= '</Buckets>
</ListAllMyBucketsResult>';
Response::sendXml(200, $xml);
}

public static function createBucket(string $name): void
{
$name = strtolower(trim($name));
$reserved = ['login', 'register', 'dashboard', 'buckets', 'objects', 'api-keys', 'admin', 'storage', 'src',
'index.php'];
if (in_array($name, $reserved, true)) {
Response::sendS3Error(400, 'InvalidBucketName', 'Bucket name is a reserved system name.');
}

if (!preg_match('/^[a-z0-9.\-]{3,63}$/', $name)) {
Response::sendS3Error(400, 'InvalidBucketName', 'The specified bucket name is not valid.');
}

$existing = self::getBucket($name);
if ($existing) {
Response::sendS3Error(409, 'BucketAlreadyExists', 'The requested bucket name already exists.');
}

$id = S3Common::generateId();
DB::execute("INSERT INTO `buckets` (`id`, `name`, `visibility`, `created_at`, `updated_at`) VALUES (?, ?, 'private',
NOW(), NOW())", [
$id, $name
]);

$bucketDir = S3Common::getStorageBaseDir() . '/' . $name;
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
Response::sendS3Error(409, 'BucketNotEmpty', 'The bucket you tried to delete is not empty.');
}

DB::execute("DELETE FROM `buckets` WHERE `id` = ?", [$bucket['id']]);

$bucketDir = S3Common::getStorageBaseDir() . '/' . $bucket['name'];
if (is_dir($bucketDir)) {
@rmdir($bucketDir);
}

http_response_code(204);
exit;
}

/**
* Emits CORS response headers if the request's Origin is present in the
* bucket's cors_origins list. No-op (and safe to call unconditionally)
* when the bucket has no CORS config or the request has no Origin.
*/
public static function applyCorsHeaders(array $bucket): void
{
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if (!$origin || empty($bucket['cors_origins'])) {
return;
}

$allowed = json_decode($bucket['cors_origins'], true);
if (!is_array($allowed)) {
return;
}

if (in_array($origin, $allowed, true) || in_array('*', $allowed, true)) {
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Expose-Headers: ETag, x-amz-request-id');
header('Access-Control-Max-Age: 3600');
}
}

public static function bucketLocation(array $bucket): void
{
$xml = '
<?xml version="1.0" encoding="UTF-8"?>' . "\n"
. '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></LocationConstraint>';
Response::sendXml(200, $xml);
}
}