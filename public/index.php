<?php

/**
 * Core PHP S3 Compatible Storage API Entry Point
 */

// Production error handling
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once __DIR__ . '/../src/env.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../src/log.php';
Log::init();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/response.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/s3/common.php';
require_once __DIR__ . '/../src/s3/bucket.php';
require_once __DIR__ . '/../src/s3/objects.php';
require_once __DIR__ . '/../src/s3/listing.php';

// Parse Request Method and URI Path
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Determine root directory offset if running inside subfolder
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$scriptName = str_replace('\\', '/', $scriptName);
if ($scriptName !== '/' && $scriptName !== '.' && str_starts_with($requestUri, $scriptName)) {
    $path = substr($requestUri, strlen($scriptName));
} else {
    $path = $requestUri;
}

$path = '/' . trim($path, '/');
$segments = array_values(array_filter(explode('/', $path)));

// -------------------------------------------------------------
// CORS preflight: never authenticated (browsers never attach
// signatures/credentials to OPTIONS), so it's handled before routing.
// -------------------------------------------------------------
if ($method === 'OPTIONS') {
    if (empty($segments)) {
        http_response_code(204);
        exit;
    }

    $bucket = S3Bucket::getBucket($segments[0]);
    if (!$bucket) {
        Response::sendS3Error(404, 'NoSuchBucket', 'The specified bucket does not exist.');
    }

    S3Bucket::applyCorsHeaders($bucket);
    http_response_code(204);
    exit;
}

// -------------------------------------------------------------
// Route 1: Top-level Root (List Buckets)
// -------------------------------------------------------------
if (empty($segments)) {
    if ($method === 'GET') {
        $auth = Auth::authenticate($method, $path);
        S3Bucket::listBuckets($auth['api_key'] ?? null);
    }
    Response::sendS3Error(405, 'MethodNotAllowed', 'The specified method is not allowed.');
}

// -------------------------------------------------------------
// Route 2: Bucket Operations (1 Path Segment)
// GET    /{bucket}            -> List Objects in Bucket
// GET    /{bucket}?location   -> Bucket location
// HEAD   /{bucket}            -> Bucket existence/permission check
// PUT    /{bucket}            -> Create Bucket
// POST   /{bucket}?delete     -> Batch delete objects
// DELETE /{bucket}            -> Delete Bucket
// -------------------------------------------------------------
if (count($segments) === 1) {
    $bucketName = $segments[0];

    $isSubresourceRequest = isset($_GET['location']) || isset($_GET['acl']) || isset($_GET['versioning']) || isset($_GET['policy']) || isset($_GET['cors']);

    if ($method === 'PUT' && !$isSubresourceRequest) {
        Auth::authenticate($method, $path);
        S3Bucket::createBucket($bucketName);
    }

    $bucket = S3Bucket::getBucket($bucketName);
    if (!$bucket) {
        Response::sendS3Error(404, 'NoSuchBucket', 'The specified bucket does not exist.');
    }
    S3Bucket::applyCorsHeaders($bucket);

    if ($method === 'HEAD') {
        Auth::authenticate($method, $path, $bucket);
        http_response_code(200);
        exit;
    }

    if ($method === 'GET') {
        Auth::authenticate($method, $path, $bucket);
        if (isset($_GET['location'])) {
            S3Bucket::bucketLocation($bucket);
        } elseif (isset($_GET['acl']) || isset($_GET['versioning']) || isset($_GET['policy']) || isset($_GET['cors'])) {
            Response::sendS3Error(501, 'NotImplemented', 'This subresource is not implemented.');
        } else {
            S3Listing::listObjects($bucket, $_GET);
        }
    }

    if ($method === 'POST' && isset($_GET['delete'])) {
        Auth::authenticate($method, $path, $bucket);
        $keys = S3Object::parseDeleteKeys(file_get_contents('php://input'));
        S3Object::deleteObjects($bucket, $keys);
    }

    if ($method === 'DELETE') {
        Auth::authenticate($method, $path, $bucket);
        S3Bucket::deleteBucket($bucket);
    }

    Response::sendS3Error(405, 'MethodNotAllowed', 'The specified method is not allowed.');
}

// -------------------------------------------------------------
// Route 3: Object Operations (2+ Path Segments)
// GET    /{bucket}/{object} -> Download / Stream Object
// HEAD   /{bucket}/{object} -> Object Metadata
// PUT    /{bucket}/{object} -> Upload Object (or Copy if x-amz-copy-source header present)
// DELETE /{bucket}/{object} -> Delete Object
// -------------------------------------------------------------
$bucketName = $segments[0];
$objectKey = implode('/', array_slice($segments, 1));

$bucket = S3Bucket::getBucket($bucketName);
if (!$bucket) {
    Response::sendS3Error(404, 'NoSuchBucket', 'The specified bucket does not exist.');
}
S3Bucket::applyCorsHeaders($bucket);

// Authenticate request
$auth = Auth::authenticate($method, $path, $bucket);

if ($method === 'PUT') {
    $copySource = Auth::getHeader('x-amz-copy-source');
    if ($copySource) {
        $copySource = '/' . ltrim($copySource, '/');
        $srcParts = array_values(array_filter(explode('/', $copySource)));
        if (count($srcParts) >= 2) {
            $srcBucketName = $srcParts[0];
            $srcObjectKey = implode('/', array_slice($srcParts, 1));
            $srcBucket = S3Bucket::getBucket($srcBucketName);
            if (!$srcBucket) {
                Response::sendS3Error(404, 'NoSuchBucket', 'The source bucket does not exist.');
            }
            S3Object::copyObject($srcBucket, $srcObjectKey, $bucket, $objectKey);
        } else {
            Response::sendS3Error(400, 'InvalidArgument', 'Invalid x-amz-copy-source header.');
        }
    } else {
        S3Object::putObject($bucket, $objectKey);
    }
}

if ($method === 'GET') {
    S3Object::getObject($bucket, $objectKey, false, $auth['anonymous'] ?? false);
}

if ($method === 'HEAD') {
    S3Object::getObject($bucket, $objectKey, true, $auth['anonymous'] ?? false);
}

if ($method === 'DELETE') {
    S3Object::deleteObject($bucket, $objectKey);
}

Response::sendS3Error(405, 'MethodNotAllowed', 'The specified method is not allowed.');
