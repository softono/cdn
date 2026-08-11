<?php

/**
 * Global error handling, request IDs, and access logging.
 *
 * Every response carries an x-amz-request-id header. Uncaught exceptions
 * and PHP errors are logged with full detail to a file and never echoed to
 * the client — the client only ever sees the request id, which is the
 * lookup key into the log.
 */
class Log
{
    public static string $requestId = '';
    private static float $startTime = 0;
    private static ?string $principal = null;

    public static function init(): void
    {
        self::$requestId = bin2hex(random_bytes(8));
        self::$startTime = microtime(true);

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'logAccess']);

        header('x-amz-request-id: ' . self::$requestId);
    }

    public static function setPrincipal(?string $principal): void
    {
        self::$principal = $principal;
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(Throwable $e): void
    {
        self::appendLog('error.log', sprintf(
            "[%s] request_id=%s %s: %s in %s:%d\n%s\n",
            date('c'),
            self::$requestId,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Error>'
            . '<Code>InternalError</Code>'
            . '<Message>We encountered an internal error. Please try again.</Message>'
            . '<RequestId>' . htmlspecialchars(self::$requestId, ENT_XML1, 'UTF-8') . '</RequestId>'
            . '</Error>';
    }

    public static function logAccess(): void
    {
        $status = http_response_code();
        $bytes = '-';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Length:') === 0) {
                $bytes = trim(substr($h, strlen('Content-Length:')));
                break;
            }
        }

        self::appendLog('access.log', sprintf(
            "%s request_id=%s method=%s path=%s key=%s status=%s bytes=%s duration_ms=%s\n",
            date('c'),
            self::$requestId,
            $_SERVER['REQUEST_METHOD'] ?? '-',
            $_SERVER['REQUEST_URI'] ?? '-',
            self::$principal ?? '-',
            $status !== false ? $status : '-',
            $bytes,
            round((microtime(true) - self::$startTime) * 1000, 2)
        ));
    }

    private static function appendLog(string $filename, string $line): void
    {
        $dir = rtrim(env('LOG_DIR', __DIR__ . '/../storage/logs'), '/\\');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/' . $filename, $line, FILE_APPEND | LOCK_EX);
    }
}
