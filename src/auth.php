<?php

/**
 * Authentication  for S3 compatible API (AWS SigV4 only)
 */
class Auth
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

    /**
     * Parses the raw QUERY_STRING (not $_GET, which collapses duplicate keys
     * and mangles "." to "_" in key names) into ordered [key, value] pairs
     * with the wire-format percent-encoding normalized.
     */
    public static function rawQueryPairs(): array
    {
        $raw = $_SERVER['QUERY_STRING'] ?? '';
        if ($raw === '') {
            return [];
        }

        $pairs = [];
        foreach (explode('&', $raw) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                $k = $part;
                $v = '';
            } else {
                $k = substr($part, 0, $eq);
                $v = substr($part, $eq + 1);
            }
            $pairs[] = [rawurldecode($k), rawurldecode($v)];
        }
        return $pairs;
    }

    private static function rawQueryParam(string $name): ?string
    {
        foreach (self::rawQueryPairs() as [$k, $v]) {
            if ($k === $name) {
                return $v;
            }
        }
        return null;
    }

    public static function authenticate(string $method, string $path, ?array $bucket = null): ?array
    {
        $authHeader = self::getAuthorizationHeader();
        $result = null;

        // 1. AWS SigV4 scheme (Header or Query)
        if (($authHeader && str_starts_with($authHeader, 'AWS4-HMAC-SHA256 ')) || self::rawQueryParam('X-Amz-Algorithm') !== null) {
            $result = self::verifySigV4($method, $path, $authHeader);
        }
        // 2. Anonymous access check for public bucket
        elseif (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            if ($bucket && isset($bucket['visibility']) && $bucket['visibility'] === 'public') {
                Log::setPrincipal('anonymous');
                return ['anonymous' => true, 'api_key' => null];
            }
        }

        if (!$result) {
            Response::sendS3Error(401, 'AccessDenied', 'Authentication required.');
        }

        // Enforce bucket tenancy restriction if key is scoped to a specific bucket_id
        if ($bucket && isset($result['api_key']['bucket_id']) && $result['api_key']['bucket_id'] !== null) {
            if ($result['api_key']['bucket_id'] !== $bucket['id']) {
                Response::sendS3Error(403, 'AccessDenied', 'Access denied to this bucket.');
            }
        }

        Log::setPrincipal($result['api_key']['access_key'] ?? null);

        return $result;
    }

    private static function getApiKey(string $accessKey): array
    {
        $apiKey = DB::fetchOne("SELECT * FROM `api_keys` WHERE `access_key` = ? AND `status` = 'active'", [$accessKey]);
        if (!$apiKey) {
            Response::sendS3Error(401, 'InvalidAccessKeyId', 'The Access Key Id you provided does not exist in our records.');
        }

        DB::execute("UPDATE `api_keys` SET `last_used_at` = NOW() WHERE `id` = ?", [$apiKey['id']]);

        return $apiKey;
    }

    private static function verifySigV4(string $method, string $path, ?string $authHeader): array
    {
        $isPresigned = self::rawQueryParam('X-Amz-Algorithm') === 'AWS4-HMAC-SHA256';

        if ($isPresigned) {
            $credential = self::rawQueryParam('X-Amz-Credential') ?? '';
            $amzDate = self::rawQueryParam('X-Amz-Date') ?? '';
            $signedHeadersParam = self::rawQueryParam('X-Amz-SignedHeaders') ?? '';
            $providedSignature = self::rawQueryParam('X-Amz-Signature') ?? '';
            $expires = (int) (self::rawQueryParam('X-Amz-Expires') ?? '0');
        } else {
            preg_match('/Credential=([^,]+)/', $authHeader, $mCred);
            preg_match('/SignedHeaders=([^,]+)/', $authHeader, $mHead);
            preg_match('/Signature=([^,]+)/', $authHeader, $mSig);

            $credential = trim($mCred[1] ?? '');
            $signedHeadersParam = trim($mHead[1] ?? '');
            $providedSignature = trim($mSig[1] ?? '');
            $amzDate = self::getHeader('x-amz-date') ?? self::getHeader('date') ?? '';
            $expires = null;
        }

        if (!$credential || substr_count($credential, '/') < 4) {
            Response::sendS3Error(401, 'AuthorizationHeaderMalformed', 'The authorization header is malformed.');
        }

        list($accessKey, $dateStamp, $region, $service) = explode('/', $credential, 5);

        if ($isPresigned) {
            $requestTimestamp = strtotime($amzDate);
            if ($requestTimestamp === false || $expires === null || $expires <= 0 || time() > ($requestTimestamp + $expires)) {
                Response::sendS3Error(403, 'AccessDenied', 'Request has expired.');
            }
        }

        $apiKey = self::getApiKey($accessKey);
        $secretKey = $apiKey['secret_key'];

        $signedHeaders = explode(';', strtolower($signedHeadersParam));
        sort($signedHeaders);

        // Canonical Request URI (full path sent over wire including base path)
        $rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        $pathSegments = array_map(
            fn ($segment) => str_replace('%7E', '~', rawurlencode(rawurldecode($segment))),
            explode('/', $rawPath)
        );
        $canonicalUri = implode('/', $pathSegments) ?: '/';

        // Canonical Query String, from the raw wire query string (avoids
        // PHP's $_GET key-mangling and duplicate-key collapsing)
        $pairs = [];
        foreach (self::rawQueryPairs() as [$k, $v]) {
            if ($k === 'X-Amz-Signature') {
                continue;
            }
            $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
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
            Response::sendS3Error(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }

        return ['anonymous' => false, 'api_key' => $apiKey];
    }
}