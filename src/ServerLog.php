<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 服务端日志分析（面板侧与 Agent 侧共用同一套判定）。
 *
 * 之前这套逻辑只写在 Agent 里，结果"没装 Agent 但配了面板 API"的场景就读不了日志。
 * 抽出来之后：
 *   - Agent 读本地 latest.log → ServerLog::analyze()
 *   - 面板 API 读日志文件内容 → 同样走 ServerLog::analyze()
 * 两边结论完全一致，不会出现"同一个报错在两种通道下判定不同"的问题。
 */
final class ServerLog
{
    /**
     * 致命特征（命中即认为服务端有问题）
     */
    private const CRITICAL = [
        'OutOfMemoryError' => '/OutOfMemoryError|GC overhead limit exceeded/i',
        'CrashReport'      => '/This crash report has been saved|Preparing crash report|Minecraft has crashed/i',
        'AddressInUse'     => '/Address already in use|Failed to bind to port/i',
        'DiskFull'         => '/No space left on device|Read-only file system/i',
        'WorldCorrupt'     => '/Region file.*(mismatch|corrupt)|Chunk file at .* is missing/i',
    ];

    /**
     * 一般异常特征
     */
    private const ERRORS = [
        'Watchdog'      => '/A single server tick took|server has not responded|Watchdog Thread/i',
        'Exception'     => '/^\s*(java|net|io)\.[\w\.]*Exception|Caused by:/im',
        'PluginError'   => '/\[(Server thread\/ERROR|ERROR)\].*(Exception|error)|Could not load .* plugin|Error occurred while enabling/i',
        'ModError'      => '/Mod .* requires|Missing or unsupported mandatory dependencies|Incompatible mod set/i',
        'DataFixer'     => '/DataFixer|Failed to load chunk|Ignoring chunk/i',
        'AuthFailed'    => '/Failed to verify username|authentication servers are down|com\.mojang\.authlib/i',
    ];

    /**
     * 完整分析一段日志文本。
     *
     * @return array<string,mixed>
     */
    public static function analyze(string $content): array
    {
        $lines = self::tailLines($content, 800);
        $hits = [];
        $samples = [];
        $pluginCounter = [];
        $onlinePlayers = [];

        foreach ($lines as $line) {
            foreach (self::CRITICAL as $name => $regex) {
                if (preg_match($regex, $line)) {
                    $hits[$name] = ($hits[$name] ?? 0) + 1;
                    if (count($samples[$name] ?? []) < 3) {
                        $samples[$name][] = mb_substr(trim($line), 0, 220);
                    }
                }
            }
            foreach (self::ERRORS as $name => $regex) {
                if (preg_match($regex, $line)) {
                    $hits[$name] = ($hits[$name] ?? 0) + 1;
                    if (count($samples[$name] ?? []) < 3) {
                        $samples[$name][] = mb_substr(trim($line), 0, 220);
                    }
                }
            }

            // 插件报错归集
            if (preg_match('/ERROR|WARN|Exception|error/i', $line)
                && preg_match_all('/\[([A-Za-z0-9_\-]{2,24})\]/', $line, $tags)) {
                foreach ($tags[1] as $tag) {
                    if (in_array(strtolower($tag), ['server', 'main', 'init', 'minecraft', 'paper', 'spigot', 'bukkit', 'forge', 'fabric', 'user', 'thread'], true)) {
                        continue;
                    }
                    $pluginCounter[$tag] = ($pluginCounter[$tag] ?? 0) + 1;
                }
            }

            // 在线人数：Joined / Left 追踪
            if (preg_match('/([A-Za-z0-9_]{3,16}) joined the game/i', $line, $m)) {
                $onlinePlayers[$m[1]] = true;
            }
            if (preg_match('/([A-Za-z0-9_]{3,16}) left the game/i', $line, $m)) {
                unset($onlinePlayers[$m[1]]);
            }
        }

        arsort($hits);
        arsort($pluginCounter);

        $criticalHits = 0;
        $errorHits = 0;
        foreach ($hits as $name => $count) {
            if (isset(self::CRITICAL[$name])) {
                $criticalHits += (int) $count;
            }
            if (isset(self::ERRORS[$name])) {
                $errorHits += (int) $count;
            }
        }

        return [
            'lines'         => count($lines),
            'hits'          => $hits,
            'samples'       => $samples,
            'plugins'       => array_slice($pluginCounter, 0, 10, true),
            'critical_hits' => $criticalHits,
            'error_hits'    => $errorHits,
            'tps'           => self::tps($lines),
            'players'       => array_values(array_keys($onlinePlayers)),
            'server_ready'  => self::serverReady($lines),
            'last_line'     => $lines ? mb_substr((string) end($lines), 0, 200) : '',
        ];
    }

    /**
     * 从日志里读 TPS（很多服务端/插件会打出来）。
     *
     * @param string[]|null $lines
     */
    public static function tps(?array $lines = null, string $content = ''): ?float
    {
        if ($lines === null) {
            $lines = self::tailLines($content, 800);
        }

        foreach (array_reverse($lines) as $line) {
            if (preg_match('/TPS[^0-9]{0,15}(\d{1,3}\.\d)/i', (string) $line, $m)) {
                $value = (float) $m[1];
                if ($value > 0 && $value <= 20.1) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * 日志里有没有"启动完成"的标志（判断服务端是否真的起来了）。
     *
     * @param string[]|null $lines
     */
    public static function serverReady(?array $lines = null, string $content = ''): bool
    {
        if ($lines === null) {
            $lines = self::tailLines($content, 800);
        }

        foreach (array_reverse($lines) as $line) {
            if (preg_match('/Done \([0-9.]+s\)! For help, type "help"/i', (string) $line)) {
                return true;
            }
            if (preg_match('/Starting minecraft server version|Preparing level/i', (string) $line)) {
                // 找到了启动中但没有 Done，说明还没起来
                return false;
            }
        }

        return false;
    }

    /**
     * 取日志尾部若干行。
     *
     * @return string[]
     */
    public static function tailLines(string $content, int $lines = 800): array
    {
        if (strlen($content) > 1048576) {
            $content = substr($content, -1048576);
        }

        $all = preg_split('/\r?\n/', $content) ?: [];
        $tail = array_slice($all, -$lines);

        return array_values(array_filter($tail, static function ($line): bool {
            return is_string($line) && trim($line) !== '';
        }));
    }

    /**
     * 把分析结果转成诊断引擎能用的检查结果。
     *
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $meta 额外信息：source（从哪里读的）、size、age
     * @return array{status:string,message:string,data:array<string,mixed>}
     */
    public static function toCheckResult(array $analysis, array $meta = []): array
    {
        $topPattern = '';
        foreach ((array) ($analysis['hits'] ?? []) as $name => $count) {
            $topPattern = (string) $name;
            break;
        }

        $data = [
            'source'      => (string) ($meta['source'] ?? 'unknown'),
            'path'        => (string) ($meta['path'] ?? ''),
            'size'        => human_size((float) ($meta['size'] ?? 0)),
            'age_seconds' => (int) ($meta['age'] ?? 0),
            'hits'        => (array) ($analysis['hits'] ?? []),
            'samples'     => (array) ($analysis['samples'] ?? []),
            'plugins'     => (array) ($analysis['plugins'] ?? []),
            'tps_from_log'=> $analysis['tps'] ?? null,
            'top_pattern' => $topPattern,
            'server_ready'=> !empty($analysis['server_ready']),
            'log_lines'   => (int) ($analysis['lines'] ?? 0),
        ];

        $critical = (int) ($analysis['critical_hits'] ?? 0);
        $errors = (int) ($analysis['error_hits'] ?? 0);
        $hitNames = implode('、', array_slice(array_keys((array) ($analysis['hits'] ?? [])), 0, 3));

        if ($critical > 0) {
            return [
                'status'  => 'fail',
                'message' => sprintf('日志里有 %d 处致命错误（%s）', $critical, $hitNames),
                'data'    => $data,
            ];
        }

        if ($errors > 0) {
            return [
                'status'  => 'warn',
                'message' => sprintf('日志里有 %d 处异常（%s）', $errors, $hitNames),
                'data'    => $data,
            ];
        }

        return [
            'status'  => 'pass',
            'message' => '最近 ' . (int) ($analysis['lines'] ?? 0) . ' 行日志没有发现异常',
            'data'    => $data,
        ];
    }
}
