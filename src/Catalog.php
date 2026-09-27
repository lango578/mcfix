<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 玩家侧的问题分类目录。分类决定：
 *  - 前端表单里给玩家选什么
 *  - 后台自动跑哪几项验证
 *  - 允许自动执行哪些修复配方
 */
final class Catalog
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function categories(): array
    {
        return [
            'client_problem' => [
                'key'         => 'client_problem',
                'label'       => '客户端报错 / 崩游戏',
                'icon'        => '🧯',
                'hint'        => '进游戏时报错、闪退、黑屏、卡在加载界面 —— 可以上传崩溃报告，系统自动分析',
                'checks'      => ['mc_status', 'port', 'process', 'logs'],
                'queue_checks'   => ['player_online', 'whitelist_player', 'ban_player'],
                'allow_recipes'  => ['whitelist_add', 'unban_player', 'restart_server', 'reload_plugins', 'save_world'],
                'default_severity' => 'normal',
                'client_first'   => true,
            ],
            'cannot_join' => [
                'key'         => 'cannot_join',
                'label'       => '进不去服务器',
                'icon'        => '🚪',
                'hint'        => '提示白名单、被踢出、连接超时、版本不匹配等',
                'checks'      => ['mc_status', 'port', 'process', 'logs', 'whitelist'],
                'queue_checks'   => ['player_online', 'whitelist_player', 'ban_player'],
                'allow_recipes'  => ['whitelist_add', 'unban_player', 'restart_server'],
                'default_severity' => 'high',
            ],
            'server_down' => [
                'key'         => 'server_down',
                'label'       => '服务器掉线 / 崩了',
                'icon'        => '💥',
                'hint'        => '所有人都连不上，或者刚崩服重启过',
                'checks'      => ['mc_status', 'port', 'process', 'logs', 'disk'],
                'queue_checks'   => [],
                'allow_recipes'  => ['restart_server', 'save_world'],
                'default_severity' => 'critical',
            ],
            'lag' => [
                'key'         => 'lag',
                'label'       => '卡顿 / 掉帧 / 延迟高',
                'icon'        => '🐌',
                'hint'        => 'TPS 低、方块回弹、怪物不动、频繁卡顿',
                'checks'      => ['mc_status', 'process', 'process_info', 'logs', 'disk'],
                'queue_checks'   => ['tps'],
                'allow_recipes'  => ['save_world', 'restart_server'],
                'default_severity' => 'normal',
            ],
            'data_issue' => [
                'key'         => 'data_issue',
                'label'       => '存档 / 物品数据异常',
                'icon'        => '📦',
                'hint'        => '回档、建筑消失、物品丢失、区块错乱',
                'checks'      => ['process', 'logs', 'process_info', 'disk'],
                'queue_checks'   => ['tps', 'world_info'],
                'allow_recipes'  => ['save_world', 'backup_world'],
                'default_severity' => 'high',
            ],
            'permission' => [
                'key'         => 'permission',
                'label'       => '被封禁 / 被限制',
                'icon'        => '🔒',
                'hint'        => '被封号、禁言、白名单被移除，认为自己被误判',
                'checks'      => ['mc_status'],
                'queue_checks'   => ['ban_player', 'whitelist_player', 'player_online'],
                'allow_recipes'  => ['unban_player', 'unmute_player', 'whitelist_add'],
                'default_severity' => 'normal',
            ],
            'plugin_error' => [
                'key'         => 'plugin_error',
                'label'       => '插件 / 模组报错',
                'icon'        => '🧩',
                'hint'        => '提示插件异常、命令失效、功能报错',
                'checks'      => ['process', 'logs', 'process_info'],
                'queue_checks'   => ['plugin_errors'],
                'allow_recipes'  => ['reload_plugins', 'restart_server'],
                'default_severity' => 'normal',
            ],
            'player_report' => [
                'key'         => 'player_report',
                'label'       => '举报违规玩家',
                'icon'        => '🚨',
                'hint'        => '作弊、刷物品、恶意破坏 —— 需要管理员人工判断',
                'checks'      => ['mc_status'],
                'queue_checks'   => ['player_online'],
                'allow_recipes'  => [],
                'default_severity' => 'normal',
            ],
            'other' => [
                'key'         => 'other',
                'label'       => '其它问题',
                'icon'        => '💬',
                'hint'        => '说不清楚的，直接描述给管理员',
                'checks'      => ['mc_status', 'process', 'logs'],
                'queue_checks'   => ['tps'],
                'allow_recipes'  => [],
                'default_severity' => 'low',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function category(string $key): ?array
    {
        $all = self::categories();

        return $all[$key] ?? null;
    }

    /**
     * 关键词猜测分类：玩家只写了一段描述时，尽量自动归类。
     */
    public static function guess(string $text): string
    {
        $text = mb_strtolower($text);
        $rules = [
            // 客户端报错优先（这类描述里通常带具体异常名）
            'client_problem' => [
                'crash', '崩溃', '闪退', '报错', 'exception', 'mixin', 'mod', '模组', '整合包',
                'java', '内存', 'forge', 'fabric', 'neoforge', 'optifine', 'optifabric',
                '启动器', 'hmcl', 'pcl', '找不到', 'class', '退出', '黑屏', '卡在加载',
            ],
            'server_down' => ['崩服', '掉线', '连不上', 'offline', 'down', '宕机', '开不了'],
            'lag'         => ['卡顿', '延迟', 'lag', 'tps', '掉帧', '回弹'],
            'data_issue'  => ['回档', '丢', '消失', '存档', '物品不见', '区块'],
            'permission'  => ['封禁', 'ban', '禁言', 'mute', '白名单', 'whitelist', '误封'],
            'plugin_error'=> ['插件', 'plugin'],
            'player_report' => ['举报', '作弊', '外挂', '破坏', '偷'],
        ];

        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && mb_strpos($text, $keyword) !== false) {
                    return $category;
                }
            }
        }

        // "进不去" 这种模糊说法：默认按进服问题处理（诊断里会连服务端验证）
        foreach (['进不去', '进不了', '无法进入', '连接失败'] as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                return 'cannot_join';
            }
        }

        return 'other';
    }

    /**
     * 诊断用的检查项清单（由 Agent / SSH / 本地执行器实现）。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function checkSpecs(): array
    {
        return [
            'mc_status' => [
                'label'    => '玩家视角连通性（Minecraft 协议握手）',
                'needs'    => [],
                'remote'   => false,
                'desc'     => '面板直接用 Minecraft 协议连接服务器，等价于玩家点"加入服务器"',
            ],
            'port' => [
                'label'    => '端口监听状态',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '确认服务器端口真的有进程在监听',
            ],
            'process' => [
                'label'    => '服务端进程状态',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '通过 systemd / screen / tmux / 进程表确认服务端是否在跑',
            ],
            'process_info' => [
                'label'    => '进程资源占用（CPU / 内存）',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '读取 java 进程的 CPU 与 RSS，判断是否被拖垮',
            ],
            'disk' => [
                'label'    => '磁盘剩余空间',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '磁盘写满会让服务端直接崩掉或无法保存存档',
            ],
            'logs' => [
                'label'    => '服务端日志错误分析',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '扫描 latest.log 尾部的 FATAL / Exception / 崩溃特征',
            ],
            'tps' => [
                'label'    => 'TPS 与在线人数（RCON）',
                'needs'    => ['rcon'],
                'remote'   => false,
                'desc'     => '用 RCON 执行 tps / list 指令，最准确的负载指标',
            ],
            'player_online' => [
                'label'    => '玩家是否在线（RCON list）',
                'needs'    => ['rcon', 'player'],
                'remote'   => false,
                'desc'     => '确认反馈的玩家此刻在线，落点修复才有意义',
            ],
            'whitelist_player' => [
                'label'    => '玩家白名单状态（RCON whitelist list）',
                'needs'    => ['rcon', 'player'],
                'remote'   => false,
                'desc'     => '判断"进不去"是否因为不在白名单里',
            ],
            'ban_player' => [
                'label'    => '玩家封禁 / 禁言状态（RCON banlist、mute 记录）',
                'needs'    => ['rcon', 'player'],
                'remote'   => false,
                'desc'     => '判断是否被 ban 或禁言',
            ],
            'whitelist' => [
                'label'    => '白名单开关状态',
                'needs'    => ['rcon'],
                'remote'   => false,
                'desc'     => 'white-list 开启但没有配置好，会导致所有人都进不去',
            ],
            'plugin_errors' => [
                'label'    => '插件报错归集',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '统计日志里各插件的异常次数，定位罪魁祸首',
            ],
            'world_info' => [
                'label'    => '存档体积与最近修改时间',
                'needs'    => [],
                'remote'   => true,
                'desc'     => '存档是否还在正常写入，用于排查回档',
            ],
            'agent_info' => [
                'label'    => '执行器在线状态',
                'needs'    => [],
                'remote'   => false,
                'desc'     => 'MC 侧 Agent / SSH 通道是否可用，决定能不能自动修复',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function checkSpec(string $code): ?array
    {
        $specs = self::checkSpecs();

        return $specs[$code] ?? null;
    }
}
