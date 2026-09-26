<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 修复配方白名单。
 *
 * 安全核心：MC 侧 Agent 只认这些固定 code，**永远不接受面板下发的任意 shell 命令**。
 * 即使面板被攻破，攻击者也只能触发"重启服务器"这类已知动作，无法拿到 MC 机器的 shell。
 *
 * exec 字段含义：
 *   panel : 面板侧用 RCON 直接执行（秒级生效，不需要 Agent）
 *   agent : 必须由 MC 机器上的 Agent / SSH 执行
 *   both  : 面板优先，失败后落到 Agent
 */
final class Recipe
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            'whitelist_add' => [
                'code'        => 'whitelist_add',
                'label'       => '把玩家加入白名单',
                'exec'        => 'panel',
                'params'      => ['player' => 'name'],
                // 白名单是服务器的**准入闸门**，而 player_name 只是玩家在表单里
                // 自己填的一串字符 —— 系统没有任何办法证明填表的人就是那个账号
                // （没有登录、没有验证码、没有账号绑定）。
                //
                // 之前这里是 medium，而 needsApproval() 只对 high 生效，于是
                // 「匿名者提交一单 category=permission」就能让服务端真的执行
                // whitelist add <任意名字>。实测确认过：不需要令牌、不需要登录。
                //
                // 和 unban_player / clear_self_items 一样标 high —— 凡是能改变
                // 谁能进服的动作，都不能由匿名请求自动执行。代价是这类反馈要
                // 管理员点一下批准，比"服务器准入被陌生人改写"划算得多。
                'risk'        => 'high',
                'description' => '执行 whitelist add <玩家>，解决"服务器开了白名单但玩家不在名单里"',
            ],
            'unban_player' => [
                'code'        => 'unban_player',
                'label'       => '解除玩家封禁',
                'exec'        => 'panel',
                'params'      => ['player' => 'name'],
                'risk'        => 'high',
                'description' => '执行 pardon <玩家>，解封被 ban 的账号',
            ],
            'unmute_player' => [
                'code'        => 'unmute_player',
                'label'       => '解除玩家禁言',
                'exec'        => 'panel',
                'params'      => ['player' => 'name'],
                'risk'        => 'medium',
                'description' => '执行 unmute <玩家>（禁言是插件功能，需要服务端装了 Essentials 之类的插件）',
            ],
            'kick_player' => [
                'code'        => 'kick_player',
                'label'       => '踢出卡住的玩家',
                'exec'        => 'panel',
                'params'      => ['player' => 'name', 'reason' => 'text'],
                'risk'        => 'medium',
                'description' => '执行 kick <玩家> <原因>，用于玩家卡在加载界面、实体卡死等场景',
            ],
            'clear_self_items' => [
                'code'        => 'clear_self_items',
                'label'       => '清空玩家背包（谨慎）',
                'exec'        => 'panel',
                'params'      => ['player' => 'name'],
                'risk'        => 'high',
                'description' => '执行 clear <玩家>，仅在玩家自己确认背包数据损坏时使用',
            ],
            'save_world' => [
                'code'        => 'save_world',
                'label'       => '强制保存世界',
                'exec'        => 'panel',
                'params'      => [],
                'risk'        => 'low',
                'description' => '执行 save-all flush，把内存里的方块改动落盘，防止回档',
            ],
            'reload_plugins' => [
                'code'        => 'reload_plugins',
                'label'       => '重载插件配置',
                'exec'        => 'panel',
                'params'      => [],
                'risk'        => 'medium',
                'description' => '执行 reload confirm（Paper 系），让改过的配置文件生效',
            ],
            'restart_server' => [
                'code'        => 'restart_server',
                'label'       => '重启服务端',
                'exec'        => 'agent',
                'params'      => [],
                'risk'        => 'high',
                'description' => '先 save-all，再按 systemd / screen / tmux 方式重启，最后等待端口重新可连',
            ],
            'backup_world' => [
                'code'        => 'backup_world',
                'label'       => '备份存档',
                'exec'        => 'agent',
                'params'      => [],
                'risk'        => 'low',
                'description' => '在改动存档前先打包一份 world 目录，出事能回退',
            ],
            'monitor_server' => [
                'code'        => 'monitor_server',
                'label'       => '守护检查（只读）',
                'exec'        => 'agent',
                'params'      => [],
                'risk'        => 'low',
                // 诚实描述：这是一个谁都能手动点的只读体检，系统本身**不会**定时调它，
                // 也不会因为它的结果自动重启 —— 自动重启是 restart_server 的职责。
                'description' => '只读体检：检查进程、端口、磁盘，返回当前状态；不改任何东西，需要修复请执行其它配方',
            ],
            'pull_mod' => [
                'code'        => 'pull_mod',
                'label'       => '从服务端取回 MOD',
                'exec'        => 'agent',
                'params'      => ['component' => 'component'],
                'risk'        => 'low',
                'description' => '玩家缺少某个 MOD 时，Agent 在服务端 mods 目录里找到对应文件并上传到站点，玩家直接点链接下载',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $code): ?array
    {
        $all = self::all();

        return $all[$code] ?? null;
    }

    public static function exists(string $code): bool
    {
        return self::get($code) !== null;
    }

    /**
     * @param array<string,mixed> $recipe
     */
    public static function riskLabel(array $recipe): string
    {
        $map = ['low' => '低风险', 'medium' => '中风险', 'high' => '高风险'];

        return $map[(string) ($recipe['risk'] ?? 'medium')] ?? '中风险';
    }

    /**
     * 该服务器是否允许这个配方（servers[].recipes 白名单）。
     *
     * @param array<string,mixed> $server
     */
    public static function allowed(array $server, string $code): bool
    {
        $allow = $server['recipes'] ?? null;
        // 没配置该项视为"默认允许"，配置了 false 则明确禁止
        if (!is_array($allow)) {
            return true;
        }

        if (!array_key_exists($code, $allow)) {
            return true;
        }

        return (bool) $allow[$code];
    }

    /**
     * 校验并清洗参数。任何不符合规格的输入直接拒绝，不进入任务队列。
     *
     * @param array<string,mixed> $params
     * @return array{ok:bool,params:array<string,mixed>,error:string}
     */
    public static function validateParams(string $code, array $params): array
    {
        $recipe = self::get($code);
        if ($recipe === null) {
            return ['ok' => false, 'params' => [], 'error' => '未知的修复配方'];
        }

        $clean = [];
        $spec = (array) $recipe['params'];

        foreach ($spec as $name => $type) {
            $raw = $params[$name] ?? null;
            if ($raw === null) {
                return ['ok' => false, 'params' => [], 'error' => '缺少必需参数：' . $name];
            }
            $value = trim((string) $raw);

            if ($type === 'name') {
                // Minecraft 正版/离线 ID：字母数字下划线，3~16 位
                if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $value)) {
                    return ['ok' => false, 'params' => [], 'error' => '玩家 ID 格式不合法（3-16 位字母数字下划线）'];
                }
                $clean[$name] = $value;
                continue;
            }

            if ($type === 'text') {
                $value = preg_replace('/[^\P{C}\n]+/u', '', $value) ?? '';
                $value = str_replace(["\r", "\n"], ' ', $value);
                $clean[$name] = mb_substr($value, 0, 80);
                continue;
            }

            if ($type === 'component') {
                // MOD / 前置库的名字，可能是文件名，也可能是 mod id
                if (!preg_match('/^[\w\.\-\+\(\)\[\] ]{2,120}$/u', $value)) {
                    return ['ok' => false, 'params' => [], 'error' => '组件名格式不合法'];
                }
                $clean[$name] = $value;
                continue;
            }

            if ($type === 'enum') {
                $allowed = (array) ($recipe['enum'][$name] ?? []);
                if (!in_array($value, $allowed, true)) {
                    return ['ok' => false, 'params' => [], 'error' => '参数取值不在允许范围内：' . $name];
                }
                $clean[$name] = $value;
                continue;
            }
        }

        // 忽略未声明的参数，避免参数注入
        return ['ok' => true, 'params' => $clean, 'error' => ''];
    }

    /**
     * 该配方是否需要管理员二次确认（高风险 + 配置开关）。
     *
     * @param array<string,mixed> $server
     */
    public static function needsApproval(array $server, string $code): bool
    {
        $recipe = self::get($code);
        if ($recipe === null) {
            return true;
        }

        if (empty($recipe['risk']) || $recipe['risk'] !== 'high') {
            return false;
        }

        // 只有"重启服务端"这个高风险动作受配置开关控制，其它高风险动作永远要人工确认
        if ($code === 'restart_server') {
            return (bool) Config::get('automation.require_approval_for_restart', true);
        }

        return true;
    }

    /**
     * RCON 指令模板。
     *
     * @param array<string,mixed> $params
     */
    public static function rconCommand(string $code, array $params): string
    {
        switch ($code) {
            case 'whitelist_add':
                return 'whitelist add ' . $params['player'];
            case 'unban_player':
                return 'pardon ' . $params['player'];
            case 'unmute_player':
                // 原版没有禁言，这个指令由插件提供；绝大多数服务端用的是 unmute
                return 'unmute ' . $params['player'];
            case 'kick_player':
                return 'kick ' . $params['player'] . ' ' . ($params['reason'] !== '' ? $params['reason'] : '服务器维护中');
            case 'clear_self_items':
                return 'clear ' . $params['player'];
            case 'save_world':
                return 'save-all flush';
            case 'reload_plugins':
                return 'reload confirm';
            default:
                return '';
        }
    }
}
