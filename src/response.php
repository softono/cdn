<?php

/**
 * Response helper for S3 XML responses and stream responses
 */
class Response
{
    public static function sendS3Error(int $httpStatus, string $code, string $message): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
. '<Error>'
    . '<Code>' . htmlspecialchars($code, ENT_XML1, 'UTF-8') . '</Code>'
    . '<Message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</Message>'
    . '</Error>';

echo $xml;
exit;
}

public static function sendXml(int $httpStatus, string $xml): void
{
http_response_code($httpStatus);
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo $xml;
exit;
}

/**
* True if the request's conditional headers indicate the cached
* representation is still fresh and a 304 should be sent instead of
* the body. If-None-Match takes priority over If-Modified-Since, per
* RFC 7232.
*/
private static function isNotModified(string $etag, int $lastModified): bool
{
$ifNoneMatch = self::header('If-None-Match');
if ($ifNoneMatch !== null) {
foreach (array_map('trim', explode(',', $ifNoneMatch)) as $tag) {
if ($tag === '*' || $tag === $etag || $tag === 'W/' . $etag) {
return true;
}
}
return false;
}

$ims = self::header('If-Modified-Since');
if ($ims !== null) {
$imsTime = strtotime($ims);
if ($imsTime !== false && $lastModified <= $imsTime) { return true; } } return false; } private static function
    header(string $name): ?string { $key='HTTP_' . strtoupper(str_replace('-', '_' , $name)); return
    isset($_SERVER[$key]) ? trim($_SERVER[$key]) : null; } /** * Parses a single-range "Range" header against $size. *
    Returns null if there is no Range header (or a multi-range one we * don't support — served as a full 200),
    ['start','end'] for a valid * single range, or false for an unsatisfiable range (-> 416).
    */
    private static function parseRange(int $size): null|false|array
    {
    $rangeHeader = self::header('Range');
    if (!$rangeHeader || !str_starts_with($rangeHeader, 'bytes=')) {
    return null;
    }

    $spec = trim(substr($rangeHeader, 6));
    if (str_contains($spec, ',')) {
    return null; // multi-range not supported; fall back to full body
    }
    if (!preg_match('/^(\d*)-(\d*)$/', $spec, $m)) {
    return false;
    }

    [, $startStr, $endStr] = $m;
    if ($startStr === '' && $endStr === '') {
    return false;
    }

    if ($startStr === '') {
    $len = (int)$endStr;
    if ($len <= 0) { return false; } $start=max(0, $size - $len); $end=$size - 1; } else { $start=(int)$startStr;
        $end=$endStr==='' ? $size - 1 : (int)$endStr; } if ($size===0 || $start> $end || $start >= $size) {
        return false;
        }
        if ($end >= $size) {
        $end = $size - 1;
        }

        return ['start' => $start, 'end' => $end];
        }

        private static function sendCommonHeaders(string $mimeType, string $etag, int $lastModified, string
        $cacheControl, array $metadata, bool $forceDownload): void
        {
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        header('Cache-Control: ' . $cacheControl);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        if ($etag) {
        header('ETag: "' . $etag . '"');
        }
        if ($forceDownload) {
        header('Content-Disposition: attachment');
        }
        foreach ($metadata as $key => $val) {
        header('x-amz-meta-' . strtolower($key) . ': ' . $val);
        }
        }

        public static function sendHeadResponse(
        int $httpStatus,
        string $mimeType,
        int $size,
        string $checksum,
        array $metadata = [],
        int $lastModified = 0,
        string $cacheControl = 'private, no-store',
        bool $forceDownload = false
        ): void {
        $etag = $checksum;

        if ($etag && self::isNotModified($etag, $lastModified)) {
        http_response_code(304);
        header('X-Content-Type-Options: nosniff');
        header('ETag: "' . $etag . '"');
        header('Cache-Control: ' . $cacheControl);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        exit;
        }

        http_response_code($httpStatus);
        self::sendCommonHeaders($mimeType, $etag, $lastModified, $cacheControl, $metadata, $forceDownload);
        header('Content-Length: ' . $size);
        exit;
        }

        public static function streamFile(
        string $filePath,
        string $mimeType,
        int $size,
        string $checksum,
        array $metadata = [],
        int $lastModified = 0,
        string $cacheControl = 'private, no-store',
        bool $forceDownload = false
        ): void {
        if (!file_exists($filePath)) {
        self::sendS3Error(404, 'NoSuchKey', 'The specified key does not exist.');
        }

        $etag = $checksum;

        if ($etag && self::isNotModified($etag, $lastModified)) {
        http_response_code(304);
        header('X-Content-Type-Options: nosniff');
        header('ETag: "' . $etag . '"');
        header('Cache-Control: ' . $cacheControl);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        exit;
        }

        $range = self::parseRange($size);
        if ($range === false) {
        http_response_code(416);
        header('X-Content-Type-Options: nosniff');
        header('Content-Range: bytes */' . $size);
        exit;
        }

        // X-Sendfile only covers plain full-body 200s: Range requests are
        // handled by the PHP loop below so partial-content correctness
        // doesn't depend on the web server module being configured right.
        if ($range === null && filter_var(env('X_SENDFILE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
        http_response_code(200);
        self::sendCommonHeaders($mimeType, $etag, $lastModified, $cacheControl, $metadata, $forceDownload);
        header('Content-Length: ' . $size);
        header('X-Sendfile: ' . realpath($filePath));
        exit;
        }

        self::sendCommonHeaders($mimeType, $etag, $lastModified, $cacheControl, $metadata, $forceDownload);

        $fp = fopen($filePath, 'rb');
        if (!$fp) {
        self::sendS3Error(500, 'InternalError', 'Failed to open object file.');
        }

        if ($range !== null) {
        http_response_code(206);
        $length = $range['end'] - $range['start'] + 1;
        header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size);
        header('Content-Length: ' . $length);
        fseek($fp, $range['start']);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
        $read = ($remaining > 8192) ? 8192 : $remaining;
        $chunk = fread($fp, $read);
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
        }
        } else {
        http_response_code(200);
        header('Content-Length: ' . $size);
        while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
        }
        }

        fclose($fp);
        exit;
        }
        }