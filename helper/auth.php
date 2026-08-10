<?php

/**
 * Authentication Helper for S3 compatible API
 */
class AuthHelper
{
    public static function getAuthorizationHeader(): ?string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    return trim($v);
                }
            }
        }
        return null;
    }

    public static function getHeader(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return trim($_SERVER[$key]);
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) {
                if (strtolower($k) === strtolower($name)) {
                    return trim($v);
                }
            }
        }
        return null;
    }

    public static function authenticate(string $method, string $path, ?array $bucket = null): ?array
    {
        $authHeader = self::getAuthorizationHeader();
        $result = null;

        // 1. HMAC v1 scheme: Authorization: HMAC {access_key}:{signature}
        if ($authHeader && str_starts_with($authHeader, 'HMAC ')) {
            $result = self::verifyHmacV1($method, $path, $authHeader);
        }
        // 2. AWS SigV4 scheme (Header or Query)
        elseif (($authHeader && str_starts_with($authHeader, 'AWS4-HMAC-SHA256 ')) || isset($_GET['X-Amz-Algorithm'])) {
            $result = self::verifySigV4($method, $path, $authHeader);
        }
        // 3. Pre-signed URL v1 scheme: ?AccessKey=...&Expires=...&Signature=...
        elseif (isset($_GET['AccessKey'], $_GET['Expires'], $_GET['Signature'])) {
            $result = self::verifyPresignedV1($method, $path);
        }
        // 4. Anonymous access check for public bucket
        elseif (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            if ($bucket && isset($bucket['visibility']) && $bucket['visibility'] === 'public') {
                return ['anonymous' => true, 'api_key' => null];
            }
        }

        if (!$result) {
            ResponseHelper::sendS3Error(401, 'AccessDenied', 'Authentication required.');
        }

        // Enforce bucket tenancy restriction if key is scoped to a specific bucket_id
        if ($bucket && isset($result['api_key']['bucket_id']) && $result['api_key']['bucket_id'] !== null) {
            if ((int)$result['api_key']['bucket_id'] !== (int)$bucket['id']) {
                ResponseHelper::sendS3Error(403, 'AccessDenied', 'Access denied to this bucket.');
            }
        }

        return $result;
    }

    private static function getApiKey(string $accessKey): ?array
    {
        $apiKey = DB::fetchOne("SELECT * FROM `api_keys` WHERE `access_key` = ? AND `status` = 'active'", [$accessKey]);
        if (!$apiKey) {
            ResponseHelper::sendS3Error(401, 'InvalidAccessKeyId', 'The Access Key Id you provided does not exist in our records.');
        }

        // Touch last_used_at
        DB::execute("UPDATE `api_keys` SET `last_used_at` = NOW() WHERE `id` = ?", [$apiKey['id']]);

        return $apiKey;
    }

    private static function verifyHmacV1(string $method, string $path, string $authHeader): array
    {
        $credential = trim(substr($authHeader, 5));
        if (!str_contains($credential, ':')) {
            ResponseHelper::sendS3Error(401, 'InvalidArgument', 'Malformed HMAC authorization header.');
        }

        list($accessKey, $providedSignature) = explode(':', $credential, 2);
        $apiKey = self::getApiKey($accessKey);

        $date = self::getHeader('Date');
        if (!$date) {
            ResponseHelper::sendS3Error(401, 'AccessDenied', 'Missing Date header.');
        }

        $requestTimestamp = strtotime($date);
        if ($requestTimestamp === false) {
            ResponseHelper::sendS3Error(401, 'AccessDenied', 'Unparseable Date header.');
        }

        $tolerance = (int) env('CLOCK_SKEW_TOLERANCE', 300);
        if (abs(time() - $requestTimestamp) > $tolerance) {
            ResponseHelper::sendS3Error(401, 'RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.');
        }

        $contentMd5 = self::getHeader('Content-MD5') ?? '';
        $canonicalString = implode("\n", [
            strtoupper($method),
            '/' . ltrim($path, '/'),
            $date,
            $contentMd5
        ]);

        $secretKey = self::resolveSecretKey($apiKey['secret_key']);
        $expectedSignature = base64_encode(hash_hmac('sha256', $canonicalString, $secretKey, true));

        if (!hash_equals($expectedSignature, $providedSignature)) {
            ResponseHelper::sendS3Error(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }

        return ['anonymous' => false, 'api_key' => $apiKey];
    }

    private static function verifyPresignedV1(string $method, string $path): array
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            ResponseHelper::sendS3Error(403, 'AccessDenied', 'Pre-signed URLs only support GET/HEAD.');
        }

        $accessKey = $_GET['AccessKey'];
        $expires = (int) $_GET['Expires'];
        $providedSignature = $_GET['Signature'];

        if ($expires <= 0 || time() > $expires) {
            ResponseHelper::sendS3Error(403, 'AccessDenied', 'Request has expired.');
        }

        $apiKey = self::getApiKey($accessKey);
        $canonicalString = implode("\n", [
            strtoupper($method),
            '/' . ltrim($path, '/'),
            (string) $expires
        ]);

        $secretKey = self::resolveSecretKey($apiKey['secret_key']);
        $expectedSignature = base64_encode(hash_hmac('sha256', $canonicalString, $secretKey, true));

        if (!hash_equals($expectedSignature, $providedSignature)) {
            ResponseHelper::sendS3Error(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }

        return ['anonymous' => false, 'api_key' => $apiKey];
    }

    private static function verifySigV4(string $method, string $path, ?string $authHeader): array
    {
        $isPresigned = isset($_GET['X-Amz-Algorithm']) && $_GET['X-Amz-Algorithm'] === 'AWS4-HMAC-SHA256';

        if ($isPresigned) {
            $credential = $_GET['X-Amz-Credential'] ?? '';
            $amzDate = $_GET['X-Amz-Date'] ?? '';
            $signedHeadersParam = $_GET['X-Amz-SignedHeaders'] ?? '';
            $providedSignature = $_GET['X-Amz-Signature'] ?? '';
        } else {
            preg_match('/Credential=([^,]+)/', $authHeader, $mCred);
            preg_match('/SignedHeaders=([^,]+)/', $authHeader, $mHead);
            preg_match('/Signature=([^,]+)/', $authHeader, $mSig);

            $credential = trim($mCred[1] ?? '');
            $signedHeadersParam = trim($mHead[1] ?? '');
            $providedSignature = trim($mSig[1] ?? '');
            $amzDate = self::getHeader('x-amz-date') ?? self::getHeader('date') ?? '';
        }

        if (!$credential || substr_count($credential, '/') < 4) {
            ResponseHelper::sendS3Error(401, 'AuthorizationHeaderMalformed', 'The authorization header is malformed.');
        }

        list($accessKey, $dateStamp, $region, $service) = explode('/', $credential, 5);
        $apiKey = self::getApiKey($accessKey);
        $secretKey = self::resolveSecretKey($apiKey['secret_key']);

        $signedHeaders = explode(';', strtolower($signedHeadersParam));
        sort($signedHeaders);

        // Canonical Request URI (full path sent over wire including base path)
        $rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        $pathSegments = array_map(
            fn ($segment) => str_replace('%7E', '~', rawurlencode(rawurldecode($segment))),
            explode('/', $rawPath)
        );
        $canonicalUri = implode('/', $pathSegments) ?: '/';
        
        // Canonical Query String
        $params = $_GET;
        unset($params['X-Amz-Signature']);
        $pairs = [];
        foreach ($params as $k => $v) {
            foreach ((array)$v as $val) {
                $pairs[] = rawurlencode($k) . '=' . rawurlencode($val);
            }
        }
        sort($pairs);
        $canonicalQueryString = implode('&', $pairs);

        // Canonical Headers
        $headerLines = [];
        foreach ($signedHeaders as $h) {
            $val = ($h === 'host') ? ($_SERVER['HTTP_HOST'] ?? 'localhost') : (self::getHeader($h) ?? '');
            $val = trim(preg_replace('/\s+/', ' ', $val));
            $headerLines[] = $h . ':' . $val;
        }
        $canonicalHeaders = implode("\n", $headerLines) . "\n";

        $payloadHash = $isPresigned ? 'UNSIGNED-PAYLOAD' : (self::getHeader('x-amz-content-sha256') ?? 'UNSIGNED-PAYLOAD');

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            implode(';', $signedHeaders),
            $payloadHash
        ]);

        $credentialScope = "$dateStamp/$region/$service/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        ]);

        // Signing Key derivation
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $expectedSignature = hash_hmac('sha256', $stringToSign, $kSigning);

        if (!hash_equals($expectedSignature, $providedSignature)) {
            ResponseHelper::sendS3Error(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }

        return ['anonymous' => false, 'api_key' => $apiKey];
    }

    private static function resolveSecretKey(string $secretKeyRaw): string
    {
        // If it's standard plaintext string, return as is
        if (!str_starts_with($secretKeyRaw, 'eyJ') && !str_starts_with($secretKeyRaw, 'aTox')) {
            return $secretKeyRaw;
        }

        // Handle base64 encoded json payload if legacy Laravel encrypted key
        $decoded = json_decode(base64_decode($secretKeyRaw), true);
        if (is_array($decoded) && isset($decoded['value'])) {
            // Decrypt with ENCRYPTION_KEY or return raw if not decryptable
            return $secretKeyRaw;
        }

        return $secretKeyRaw;
    }
}
