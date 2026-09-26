<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 服务端 MOD 清单的读取与比对。
 *
 * Agent 会扫描 MC 目录下的 mods / plugins 目录，把文件名（以及从 jar 里读出的 mod id）报给面板。
 * 有了它，玩家发来的客户端日志就能做**交叉比对**：
 *   - 客户端有、服务端没有 → 玩家多装了（最常见，尤其功能类 MOD）
 *   - 服务端有、客户端没有 → 玩家少了，会被服务端踢出去
 *
 * 比对的是**归一化后的名字**（去掉版本号、下划线、大小写），因为
 * "journeymap-1.20.1-5.9.18-forge.jar" 和 "journeymap-1.20.1-5.9.7-forge.jar"
 * 是同一个 MOD 的不同版本，不能因为它们不等就报"多装/少装"。
 */
final class ServerMods
{
    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function forServer(array $server): array
    {
        $seen = Agent::seen((string) ($server['id'] ?? ''));

        return self::normalize((array) ($seen['extra_data']['mods'] ?? []));
    }

    /**
     * 把 Agent 上报的清单归一化成 {mods: {...}, plugins: {...}, generated_at, source}
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {        $out = [
            'available'    => false,
            'mods'         => [],
            'plugins'      => [],
            'count'        => 0,
            'generated_at' => (string) ($raw['generated_at'] ?? ''),
            'source'       => (string) ($raw['source'] ?? 'agent'),
        ];

        foreach (['mods', 'plugins'] as $group) {
            $list = $raw[$group] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $entry) {
                $file = '';
                $id = '';
                if (is_string($entry)) {
                    $file = $entry;
                } elseif (is_array($entry)) {
                    $file = (string) ($entry['file'] ?? $entry['name'] ?? '');
                    $id = (string) ($entry['id'] ?? '');
                }
                if ($file === '' && $id === '') {
                    continue;
                }

                $key = self::modKey($id !== '' ? $id : $file);
                if ($key === '') {
                    continue;
                }

                $out[$group][$key] = [
                    'file'   => $file,
                    'id'     => $id,
                    'normal' => $key,
                ];
            }
        }

        $out['count'] = count($out['mods']) + count($out['plugins']);
        $out['available'] = $out['count'] > 0;

        return $out;
    }

    /**
     * 把 MOD 名归一化成可比较的键。
     *
     * "journeymap-1.20.1-5.9.18-forge.jar" → "journeymap"
     * "Fabric API 0.92.2+1.20.1"            → "fabricapi"
     *
     * @param mixed $name
     */
    public static function modKey($name): string
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return '';
        }

        // 去掉扩展名
        $name = preg_replace('/\.(jar|zip|disabled|old|bak)$/i', '', $name) ?? $name;
        // 去掉 mc 版本段与版本号段
        $name = preg_replace('/[-_\.\s]+(?:mc)?1\.\d{1,2}(?:\.\d{1,2})?.*$/', '', $name) ?? $name;
        $name = preg_replace('/[-_\.\s]+v?\d+[\d\.\+]*.*$/', '', $name) ?? $name;
        // 去掉常见加载器后缀
        $name = preg_replace('/[-_\s]+(forge|fabric|neoforge|quilt|common|universal|api|mod)$/i', '', $name) ?? $name;
        // 只保留字母数字
        $name = preg_replace('/[^a-z0-9]/', '', $name) ?? $name;

        // 常见别名归一
        $aliases = [
            'fabricapi'   => 'fabricapi',
            'architectury' => 'architectury',
            'clothconfig' => 'clothconfig',
            'kotlinforforge' => 'kotlinforforge',
            'geckolib'    => 'geckolib',
            'curios'      => 'curios',
            'luckperms'   => 'luckperms',
            'essentialsx' => 'essentialsx',
        ];

        return $aliases[$name] ?? $name;
    }

    /**
     * 客户端 MOD 列表 vs 服务端清单。
     *
     * @param array<string,mixed> $serverMods  self::forServer() 的结果
     * @param array<int,array<string,mixed>> $clientMods ClientLog 解析出的 mods
     * @return array<string,mixed>
     */
    public static function crossCheck(array $serverMods, array $clientMods): array
    {
        $result = [
            'possible'     => false,
            /*
             * available 表示"服务端清单到底有没有"（区别于 possible = "这次比对做成了吗"）。
             *
             * 补这个键是因为调用方猜过它：api.php 里写的是 !empty($cross['available'])，
             * 而 cross_check 当时只返回 possible —— 猜错了，那道限制就静默失效。
             * 现在两个键都在，别再让调用方靠猜。
             */
            'available'    => !empty($serverMods['available']),
            'client_count' => count($clientMods),
            'server_count' => (int) ($serverMods['count'] ?? 0),
            'client_extra' => [],
            'client_missing' => [],
            'matched'      => 0,
            'note'         => '',
        ];

        if (empty($serverMods['available'])) {
            $result['note'] = '服务端尚未上报 MOD 清单，无法比对（Agent 升级后会自动上报）';

            return $result;
        }

        if (!$clientMods) {
            $result['note'] = '日志里没有解析出客户端 MOD 列表';

            return $result;
        }

        $result['possible'] = true;
        $serverKeys = [];
        foreach (['mods', 'plugins'] as $group) {
            foreach ((array) ($serverMods[$group] ?? []) as $key => $info) {
                $serverKeys[(string) $key] = (string) ($info['file'] ?? $key);
            }
        }

        $clientKeys = [];
        foreach ($clientMods as $mod) {
            $name = (string) ($mod['name'] ?? '');
            $version = (string) ($mod['version'] ?? '');
            $key = self::modKey($name);
            if ($key === '') {
                continue;
            }
            $clientKeys[$key] = ['name' => $name, 'version' => $version];
        }

        foreach ($clientKeys as $key => $info) {
            if (isset($serverKeys[$key])) {
                $result['matched']++;
                continue;
            }
            $result['client_extra'][] = $info['name'] . ($info['version'] !== '' ? ' ' . $info['version'] : '');
        }

        foreach ($serverKeys as $key => $file) {
            if (isset($clientKeys[$key])) {
                continue;
            }
            // 服务端专属的东西不该出现在客户端日志里，反之亦然；只提示真正可能缺的
            $result['client_missing'][] = $file;
        }

        $result['client_extra'] = array_slice(array_values(array_unique($result['client_extra'])), 0, 25);
        $result['client_missing'] = array_slice(array_values(array_unique($result['client_missing'])), 0, 25);

        return $result;
    }

    /**
     * 从服务端清单里挑出与玩家日志中"可疑包名"可能对应的项。
     *
     * @param array<string,mixed> $serverMods
     * @param string[] $suspectPackages
     * @return string[]
     */
    public static function matchSuspects(array $serverMods, array $suspectPackages): array
    {
        if (empty($serverMods['available']) || !$suspectPackages) {
            return [];
        }

        $matched = [];
        foreach ((array) ($serverMods['mods'] ?? []) as $info) {
            $file = strtolower((string) ($info['file'] ?? ''));
            foreach ($suspectPackages as $package) {
                $package = strtolower((string) $package);
                // 取包名的第一段做粗略匹配，例如 dev.architectury → architectury
                $first = explode('.', $package)[0] ?? '';
                $segments = explode('.', $package);
                foreach ($segments as $segment) {
                    if (strlen($segment) >= 4 && strpos($file, $segment) !== false) {
                        $matched[] = (string) ($info['file'] ?? '');

                        continue 3;
                    }
                }
                if ($first !== '' && strlen($first) >= 4 && strpos($file, $first) !== false) {
                    $matched[] = (string) ($info['file'] ?? '');
                }
            }
        }

        return array_slice(array_values(array_unique($matched)), 0, 15);
    }
}
