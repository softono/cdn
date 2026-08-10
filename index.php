<?php

/**
 * Core PHP S3 Compatible Storage API Entry Point
 */

require_once __DIR__ . '/helper/env.php';
loadEnv(__DIR__ . '/.env');

require_once __DIR__ . '/helper/db.php';
require_once __DIR__ . '/helper/response.php';
require_once __DIR__ . '/helper/auth.php';
require_once __DIR__ . '/helper/s3.php';

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
// Route 1: Top-level Root (List Buckets)
// -------------------------------------------------------------
if (empty($segments)) {
    if ($method === 'GET') {
        AuthHelper::authenticate($method, $path);
        S3Helper::listBuckets();
    }
    ResponseHelper::sendS3Error(405, 'MethodNotAllowed', 'The specified method is not allowed.');
}

// -------------------------------------------------------------
// Route 2: Bucket Operations (1 Path Segment)
// GET  /{bucket} -> List Objects in Bucket
// PUT  /{bucket} -> Create Bucket
// DELETE /{bucket} -> Delete Bucket
// -------------------------------------------------------------
if (count($segments) === 1) {
    $bucketName = $segments[0];

    if ($method === 'PUT') {
        AuthHelper::authenticate($method, $path);
        S3Helper::createBucket($bucketName);
    }

    $bucket = S3Helper::getBucket($bucketName);
    if (!$bucket) {
        ResponseHelper::sendS3Error(404, 'NoSuchBucket', 'The specified bucket does not exist.');
    }

    if ($method === 'GET') {
        AuthHelper::authenticate($method, $path, $bucket);
        $prefix = $_GET['prefix'] ?? '';
        $marker = $_GET['marker'] ?? '';
        $maxKeys = (int)($_GET['max-keys'] ?? 1000);
        S3Helper::listObjects($bucket, $prefix, $marker, $maxKeys);
    }

    if ($method === 'DELETE') {
        AuthHelper::authenticate($method, $path, $bucket);
        S3Helper::deleteBucket($bucket);
    }

    ResponseHelper::sendS3Error(405, 'MethodNotAllowed', 'The specified method is not allowed.');
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

$bucket = S3Helper::getBucket($bucketName);
if (!$bucket) {
    ResponseHelper::sendS3Error(404, 'NoSuchBucket', 'The specified bucket does not exist.');
}

// Authenticate request
AuthHelper::authenticate($method, $path, $bucket);

if ($method === 'PUT') {
    $copySource = AuthHelper::getHeader('x-amz-copy-source');
    if ($copySource) {
        $copySource = '/' . ltrim($copySource, '/');
        $srcParts = array_values(array_filter(explode('/', $copySource)));
        if (count($srcParts) >= 2) {
            $srcBucketName = $srcParts[0];
            $srcObjectKey = implode('/', array_slice($srcParts, 1));
            $srcBucket = S3Helper::getBucket($srcBucketName);
            if (!$srcBucket) {
                ResponseHelper::sendS3Error(404, 'NoSuchBucket', 'The source bucket does not exist.');
            }
            S3Helper::copyObject($srcBucket, $srcObjectKey, $bucket, $objectKey);
        } else {
            ResponseHelper::sendS3Error(400, 'InvalidArgument', 'Invalid x-amz-copy-source header.');
        }
    } else {
        S3Helper::putObject($bucket, $objectKey);
    }
}

if ($method === 'GET') {
    S3Helper::getObject($bucket, $objectKey, false);
}

if ($method === 'HEAD') {
    S3Helper::getObject($bucket, $objectKey, true);
}

if ($method === 'DELETE') {
    S3Helper::deleteObject($bucket, $objectKey);
}

ResponseHelper::sendS3Error(405, 'MethodNotAllowed', 'The specified method is not allowed.');