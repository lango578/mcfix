<?php

declare(strict_types=1);

namespace MCFix;

use MCFix\Panel\BtPanel;
use MCFix\Panel\CustomPanel;
use MCFix\Panel\McsManagerPanel;
use MCFix\Panel\MulticraftPanel;
use MCFix\Panel\PanelAdapter;
use MCFix\Panel\PterodactylPanel;

/**
 * 面板适配器注册表。
 *
 * 一个服务器可以同时配多种执行通道，系统按**能力**决定用哪条：
 *   诊断只读      优先面板 API（能读日志就行，不用装东西）→ Agent → 仅 RCON
 *   游戏指令      优先 RCON（有回显，最可靠）→ 面板控制台
 *   进程开关机    优先 Agent（最可控）→ 面板 API
 *
 * 这个"按能力降级"的设计是为了让同一份代码能同时吃下：
 *   - MCSManager / 翼龙这类面板服（不装 Agent 也能用大半功能）
 *   - 宝塔上直接跑 MC 的机器（面板读文件 + RCON 发指令）
 *   - 租用服（只有 Web 控制台，配 custom 适配器）
 *   - 自己管理的机器（装 Agent 拿到全部能力）
 */
final class PanelRegistry
{
    /**
     * @return array<string,string>
     */
    public static function types(): array
    {
        return [
            'none'        => '不使用面板 API',
            'mcsmanager'  => 'MCSManager',
            'pterodactyl' => '翼龙 / Pterodactyl（含 Pelican）',
            'multicraft'  => 'Multicraft（Apex Hosting 等，也见于部分 BisectHosting）',
            'bt'          => '宝塔面板（MC 跑在本机）',
            'custom'      => '自定义 HTTP 接口（租用服 / 自研面板）',
        ];
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function config(array $server): array
    {
        $panel = $server['panel'] ?? null;

        return is_array($panel) ? $panel : [];
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function type(array $server): string
    {
        $panel = self::config($server);
        $type = (string) ($panel['type'] ?? 'none');

        return isset(self::types()[$type]) ? $type : 'none';
    }

    /**
     * 构造适配器；未配置或配置不全时返回 null。
     *
     * @param array<string,mixed> $server
     */
    public static function adapter(array $server): ?PanelAdapter
    {
        $panel = self::config($server);
        $type = self::type($server);

        if ($type === 'none' || $panel === []) {
            return null;
        }

        // 开关：enabled 显式为 false 时不启用
        if (array_key_exists('enabled', $panel) && !$panel['enabled']) {
            return null;
        }

        $adapter = null;
        switch ($type) {
            case 'mcsmanager':
                $adapter = new McsManagerPanel($panel, $server);
                break;
            case 'pterodactyl':
                $adapter = new PterodactylPanel($panel, $server);
                break;
            case 'multicraft':
                $adapter = new MulticraftPanel($panel, $server);
                break;
            case 'bt':
                $adapter = new BtPanel($panel, $server);
                break;
            case 'custom':
                $adapter = new CustomPanel($panel, $server);
                break;
        }

        return ($adapter !== null && $adapter->capabilities() !== []) ? $adapter : null;
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function has(array $server, string $capacity): bool
    {
        $adapter = self::adapter($server);

        return $adapter !== null && in_array($capacity, $adapter->capabilities(), true);
    }

    /**
     * 给后台展示的能力摘要。
     *
     * @param array<string,mixed> $server
     * @return array{type:string,label:string,capabilities:array<int,string>,configured:bool}
     */
    public static function summary(array $server): array
    {
        $type = self::type($server);
        $adapter = self::adapter($server);

        return [
            'type'         => $type,
            'label'        => $type === 'none' ? '未配置' : (string) (self::types()[$type] ?? $type),
            'capabilities' => $adapter !== null ? $adapter->capabilities() : [],
            'configured'   => $adapter !== null,
        ];
    }

    /**
     * 把能力名翻译成中文，界面里用。
     */
    public static function capabilityLabel(string $capacity): string
    {
        $map = [
            'console'   => '下发游戏指令',
            'power'     => '开关机 / 重启',
            'read_log'  => '读取服务端日志',
            'stat_log'  => '日志文件信息',
            'read_file' => '读取文件',
            'list_dir'  => '列目录（MOD 清单）',
            'resources' => '资源占用',
        ];

        return $map[$capacity] ?? $capacity;
    }
}
