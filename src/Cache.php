<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 极简 key-value 缓存（JSON 文件），用于缓存诊断结果、脉动数据等。
 * 不做复杂淘汰，cron 会清理过期文件。
 */
final class Cache
{
    /**
     * @param mixed $value
     */
    public static function put(string $key, $value, int $ttl = 300): void
    {
        $file = self::fileFor($key);
        $payload = (string) json_encode(
            ['e' => time() + max(1, $ttl), 'v' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (@file_put_contents($file, $payload, LOCK_EX) !== false) {
            @chmod($file, 0644);
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $file = self::fileFor($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['e'])) {
            return $default;
        }
        if ((int) $data['e'] < time()) {
            @unlink($file);

            return $default;
        }

        return $data['v'] ?? $default;
    }

    public static function forget(string $key): void
    {
        $file = self::fileFor($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function gc(int $limit = 500): int
    {
        $dir = storage_path('cache');
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $scanned = 0;
        foreach ((array) glob($dir . '/*.json') as $file) {
            if (++$scanned > $limit) {
                break;
            }
            if (!is_string($file) || !is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data) || (int) ($data['e'] ?? 0) < time()) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private static function fileFor(string $key): string
    {
        $dir = storage_path('cache');
        ensure_dir($dir);

        return $dir . '/' . substr(hash('sha256', $key), 0, 40) . '.json';
    }
}
