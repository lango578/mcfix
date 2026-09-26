<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 文件桶限流。不用数据库是为了避免刷接口时把 SQLite 写锁打满。
 * 桶文件按小时清理，支持多进程并发（flock）。
 */
final class Rate
{
    /**
     * 记一次并判断是否超限。
     *
     * @return array{allowed:bool,count:int,limit:int,retry_after:int}
     */
    public static function hit(string $key, int $limit, int $windowSeconds = 3600): array
    {
        $now = time();
        $file = self::fileFor($key, $windowSeconds);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // 拿不到文件锁时宁可放行，也不能把正常用户挡在门外
            return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'retry_after' => 0];
        }

        $count = 1;
        $resetAt = $now + $windowSeconds;

        if (flock($handle, LOCK_EX)) {
            $raw = stream_get_contents($handle);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($state) && isset($state['c'], $state['r']) && (int) $state['r'] > $now) {
                $count = (int) $state['c'] + 1;
                $resetAt = (int) $state['r'];
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['c' => $count, 'r' => $resetAt]));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return [
            'allowed'     => $count <= $limit,
            'count'       => $count,
            'limit'       => $limit,
            'retry_after' => max(1, $resetAt - $now),
        ];
    }

    /**
     * 只读查询当前计数，不递增。
     *
     * @return array{count:int,reset_at:int}
     */
    public static function peek(string $key, int $windowSeconds = 3600): array
    {
        $file = self::fileFor($key, $windowSeconds);
        if (!is_file($file)) {
            return ['count' => 0, 'reset_at' => 0];
        }
        $state = json_decode((string) @file_get_contents($file), true);
        if (!is_array($state)) {
            return ['count' => 0, 'reset_at' => 0];
        }

        return ['count' => (int) ($state['c'] ?? 0), 'reset_at' => (int) ($state['r'] ?? 0)];
    }

    /**
     * 登录失败等场景：命中多少次就锁多久。
     */
    public static function blockedFor(string $key, int $limit, int $windowSeconds = 900): int
    {
        $state = self::peek($key, $windowSeconds);
        if ($state['count'] < $limit) {
            return 0;
        }

        return max(0, $state['reset_at'] - time());
    }

    public static function clear(string $key, int $windowSeconds = 3600): void
    {
        $file = self::fileFor($key, $windowSeconds);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 清理过期桶文件，cron 调用。
     */
    public static function gc(int $olderThanSeconds = 7200): int
    {
        $dir = storage_path('ratelimit');
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $deadline = time() - $olderThanSeconds;
        foreach ((array) glob($dir . '/*.json') as $file) {
            if (is_string($file) && is_file($file) && (int) filemtime($file) < $deadline) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private static function fileFor(string $key, int $windowSeconds): string
    {
        $dir = storage_path('ratelimit');
        ensure_dir($dir);
        $window = max(60, $windowSeconds);
        $slot = (int) (floor(time() / $window) * $window);

        return $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '-' . $slot . '.json';
    }
}
