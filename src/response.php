<?php

/**
 * Response helper for S3 XML responses and stream responses
 */
class ResponseHelper
{
    public static function sendS3Error(int $httpStatus, string $code, string $message): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/xml; charset=utf-8');

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
        echo $xml;
        exit;
    }

    public static function sendHeadResponse(int $httpStatus, string $mimeType, int $size, string $checksum, array $metadata = []): void
    {
        http_response_code($httpStatus);
        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Content-Length: ' . $size);
        if ($checksum) {
            header('ETag: "' . $checksum . '"');
        }
        foreach ($metadata as $key => $val) {
            header('x-amz-meta-' . strtolower($key) . ': ' . $val);
        }
        exit;
    }

    public static function streamFile(string $filePath, string $mimeType, int $size, string $checksum, array $metadata = []): void
    {
        if (!file_exists($filePath)) {
            self::sendS3Error(404, 'NoSuchKey', 'The specified key does not exist.');
        }

        http_response_code(200);
        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Content-Length: ' . $size);
        if ($checksum) {
            header('ETag: "' . $checksum . '"');
        }
        foreach ($metadata as $key => $val) {
            header('x-amz-meta-' . strtolower($key) . ': ' . $val);
        }

        // Stream file directly to output buffer
        $fp = fopen($filePath, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                echo fread($fp, 8192);
                flush();
            }
            fclose($fp);
        }
        exit;
    }
}
