<?php

/**
 * Shared helpers used by the S3 bucket/object/listing helpers.
 */
class S3Common
{
    public static function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function getStorageBaseDir(): string
    {
        $dir = env('STORAGE_DIR', __DIR__ . '/../../storage');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return rtrim($dir, '/\\');
    }
}
