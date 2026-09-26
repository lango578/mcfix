<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 诊断引擎（"自动验证"）。这是整个系统的第一半。
 *
 * 玩家说得不一定准：他说"进不去"，真实原因可能是白名单满了、被 ban 了、端口还没起来，
 * 也可能服务端活得好好的只是他自己客户端版本不对。诊断引擎负责把"玩家描述"变成"客观事实"：
 *
 *   1. 面板侧直连：Minecraft 协议握手（等价于玩家点"加入服务器"）、延迟、人数、版本
 *   2. 机器侧采集：进程 / 端口 / CPU / 内存 / 磁盘 / 日志（由 Agent 或 SSH 提供）
 *   3. RCON 侧查询：list / tps / whitelist list / banlist
 *   4. 汇总成 verdict：问题码、严重级别、是否可自动修复、修复配方候选
 *
 * 诊断是只读的，永远不改任何东西；要改东西必须走 Fixer（白名单配方 + 风控闸门）。
 */
final class Diagnosis
{
    /**
     * @param array<string,mixed> $feedback 必须含 server_id / category / player_name
     * @return array<string,mixed> 完整的 diagnosis 结构，可直接 json_encode 存库
     */
    public static function run(array $feedback, bool $allowRemote = true): array
    {
        $started = microtime(true);
        $serverId = (string) ($feedback['server_id'] ?? '');
        $category = (string) ($feedback['category'] ?? 'other');
        $player = (string) ($feedback['player_name'] ?? '');
        $server = Config::server($serverId);

        if ($server === null) {
            return [
                'at'      => now(),
                'ok'      => false,
                'error'   => '服务器不存在或已在配置中移除：' . $serverId,
                'checks'  => [],
                'verdict' => ['issue' => 'unknown_server', 'severity' => 'normal', 'player_message' => '这台服务器已经不在维护列表中，请联系管理员。'],
            ];
        }

        $categoryDef = Catalog::category($category) ?? Catalog::category('other');
        $checkCodes = array_values(array_unique(array_merge(
            (array) ($categoryDef['checks'] ?? []),
            (array) ($categoryDef['queue_checks'] ?? []),
            $player !== '' ? ['player_online'] : []
        )));

        $context = ['player' => $player, 'category' => $category];

        // 1. 面板本地能做的检查
        $local = Executor::localChecks($server, $checkCodes);
        $checks = [];
        foreach ($local['handled'] as $code => $result) {
            $checks[$code] = self::package($code, $result);
        }

        // 2. 远程检查
        $remoteCodes = $local['remote'];
        if ($remoteCodes && $allowRemote) {
            $dispatch = Executor::diagnose(
                $server,
                $remoteCodes,
                $context,
                isset($feedback['id']) ? (int) $feedback['id'] : null
            );

            if (!empty($dispatch['ok']) && !empty($dispatch['response']['checks'])) {
                foreach ((array) $dispatch['response']['checks'] as $code => $result) {
                    $checks[(string) $code] = self::package((string) $code, (array) $result);
                }
            } else {
                $reason = (string) ($dispatch['error'] ?? '远程执行器未返回数据');
                foreach ($remoteCodes as $code) {
                    if (isset($checks[$code])) {
                        continue;
                    }
                    $checks[$code] = self::package($code, [
                        'status'  => 'unknown',
                        'message' => '无法在 MC 机器上采集该项数据：' . $reason,
                        'data'    => ['executor' => $dispatch['executor'] ?? 'none', 'task_id' => $dispatch['task_id'] ?? 0],
                    ]);
                }
            }
        } elseif ($remoteCodes) {
            foreach ($remoteCodes as $code) {
                $checks[$code] = self::package($code, [
                    'status'  => 'unknown',
                    'message' => '本次跳过远程采集（仅做面板侧验证）',
                    'data'    => [],
                ]);
            }
        }

        // 3. RCON 相关检查（面板侧同步执行，最快最准）
        foreach ($checkCodes as $code) {
            if (isset($checks[$code])) {
                continue;
            }
            $rconCheck = self::runRconCheck($server, $code, $context);
            if ($rconCheck !== null) {
                $checks[$code] = self::package($code, $rconCheck);
            }
        }

        $verdict = Verdict::evaluate($server, $category, $checks, $context);

        // 规则库**完全没头绪**时（一条问题都没分类出来、也没有任何可执行动作），
        // 问一次大模型，让它从白名单里挑一个动作。
        //
        // 触发条件刻意收得很紧：只有在"规则库什么都没判断出来"时才走这里。
        // 规则库给出了结论（哪怕只是"没验证到异常"）都不打扰模型 ——
        // 既省钱，也避免模型去推翻一套确定性规则。
        //
        // augmentServerVerdict() 内部还要过限额、缓存、清单比对和完整闸门；
        // 任何一步不通过都原样返回，等于没发生。
        if ((string) $verdict['issue'] === 'no_problem_detected' && empty($verdict['suggestions'])) {
            $verdict = AiAdvisor::augmentServerVerdict($server, $category, $feedback, $checks, $verdict);
        }

        $elapsed = round(microtime(true) - $started, 2);
        app_log('info', '诊断完成', [
            'server'   => $serverId,
            'category' => $category,
            'issue'    => $verdict['issue'],
            'elapsed'  => $elapsed,
        ]);

        return [
            'at'         => now(),
            'ok'         => true,
            'elapsed'    => $elapsed,
            'checks'     => $checks,
            'verdict'    => $verdict,
            /*
             * 这里**不放 host / port**。
             *
             * 整份诊断结果会被 json_encode 存进 feedback.diagnosis 这一列，
             * 而 feedback 表是玩家工单的数据源 —— playerChecks() / progress()
             * 都从它取值。现在那两处只挑 verdict 和 checks 里的字段，所以
             * 还没有真的漏出去；但只要以后有人加一个"把诊断原文给玩家看"
             * 的入口，host/port 就会跟着一起出去。
             *
             * 玩家需要的是"是哪台服务器"，不是"连到哪个地址"，所以这里
             * 直接只留 id + 展示名。管理员要看地址，走后台的服务器配置页，
             * 那里本来就是权威来源。
             */
            'server'     => [
                'id'    => $serverId,
                'code'  => (string) ($server['code'] ?? ''),
                'name'  => (string) ($server['name'] ?? $serverId),
                'label' => server_public_label($serverId),
            ],
            'context'    => $context,
        ];
    }

    /**
     * 只跑"这类问题是否还存在"的最小验证集合。修复完成后用它做回归验证。
     *
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>
     */
    public static function reverify(array $feedback, bool $allowRemote = true): array
    {
        $fresh = self::run($feedback, $allowRemote);

        return $fresh;
    }

    /**
     * 把单项检查结果规范化成统一结构。
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function package(string $code, array $result): array
    {
        $spec = Catalog::checkSpec($code);
        $status = (string) ($result['status'] ?? 'unknown');
        if (!in_array($status, ['pass', 'warn', 'fail', 'skipped', 'unknown'], true)) {
            $status = 'unknown';
        }

        return [
            'code'    => $code,
            'label'   => (string) ($spec['label'] ?? $code),
            'desc'    => (string) ($spec['desc'] ?? ''),
            'status'  => $status,
            'message' => mb_substr((string) ($result['message'] ?? ''), 0, 400),
            'data'    => is_array($result['data'] ?? null) ? $result['data'] : [],
        ];
    }

    /**
     * 需要 RCON 的检查项，在面板侧同步执行。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private static function runRconCheck(array $server, string $code, array $context): ?array
    {
        $spec = Catalog::checkSpec($code);
        if ($spec === null || !in_array('rcon', (array) ($spec['needs'] ?? []), true)) {
            return null;
        }

        /*
         * 原来这里只判断 `Rcon::enabled()`，没开 RCON 就直接跳过 ——
         * 哪怕这台服的面板本来就能下发并返回指令回显。现在交给 CommandChannel
         * 统一选路：有 RCON 用 RCON；没有但面板能返回可解析回显，就用面板控制台。
         *
         * 注意 `fromPanelOnly()` 不是"有面板就用"：翼龙 / Multicraft / MCSManager
         * 的接口不返回控制台输出，用它们做这些检查会拿提示文字当回显去解析。
         * 那道闸门在 CommandChannel 里，这里不用重复判断。
         */
        $channel = CommandChannel::forServer($server);
        if ($channel === null) {
            return [
                'status'  => 'skipped',
                'message' => '该服务器未开启 RCON，这项验证跳过（建议开启，很多问题能秒修）',
                'data'    => [],
            ];
        }

        $player = (string) ($context['player'] ?? '');

        switch ($code) {
            case 'tps':
                return self::rconTps($channel, $server);
            case 'player_online':
                return self::rconPlayerOnline($channel, $player);
            case 'whitelist':
                return self::rconWhitelistState($channel);
            case 'whitelist_player':
                return self::rconWhitelistPlayer($channel, $player);
            case 'ban_player':
                return self::rconBanState($channel, $player);
            default:
                $channel->close();

                return null;
        }
    }

    /**
     * @param CommandChannel      $channel 由 runRconCheck 选好路（RCON 或面板控制台）
     * @param array<string,mixed> $server  只用来读 tps_command（不同服务端指令名不同）
     * @return array<string,mixed>
     */
    public static function rconTps(CommandChannel $channel, array $server): array
    {
        $list = $channel->command('list');
        if (!$list['ok']) {
            $channel->close();

            return ['status' => 'unknown', 'message' => '无法读取在线人数（' . $channel->label() . '）：' . $list['error'], 'data' => []];
        }

        $players = 0;
        $max = 0;
        if (preg_match('/There are (\d+) of a max(?: of)? (\d+)/i', $list['output'], $m)) {
            $players = (int) $m[1];
            $max = (int) $m[2];
        } elseif (preg_match('/(\d+)\s*\/\s*(\d+)/', $list['output'], $m)) {
            $players = (int) $m[1];
            $max = (int) $m[2];
        }

        $tpsCommand = self::pickTpsCommand($server);
        $tps = $channel->command($tpsCommand);
        $tpsValue = null;
        if ($tps['ok'] && preg_match('/(\d+\.\d+)\s*(?:,|$)/', $tps['output'], $m)) {
            $tpsValue = (float) $m[1];
        } elseif ($tps['ok'] && preg_match('/(\d+\.\d+)/', $tps['output'], $m)) {
            $tpsValue = (float) $m[1];
        }
        $channel->close();

        $data = [
            'players'  => $players,
            'max'      => $max,
            'raw_list' => $list['output'],
            'tps_raw'  => $tps['output'],
            'tps'      => $tpsValue,
            'command'  => $tpsCommand,
        ];

        if ($tpsValue === null) {
            return [
                'status'  => 'warn',
                'message' => sprintf('在线 %d 人；TPS 读数失败（该服务端可能不支持 %s 指令）', $players, $tpsCommand),
                'data'    => $data,
            ];
        }

        if ($tpsValue >= 19.0) {
            return ['status' => 'pass', 'message' => sprintf('TPS %.1f，负载正常，在线 %d 人', $tpsValue, $players), 'data' => $data];
        }
        if ($tpsValue >= 15.0) {
            return ['status' => 'warn', 'message' => sprintf('TPS %.1f，已经偏低，在线 %d 人', $tpsValue, $players), 'data' => $data];
        }

        return ['status' => 'fail', 'message' => sprintf('TPS 只有 %.1f，严重卡顿，在线 %d 人', $tpsValue, $players), 'data' => $data];
    }

    /**
     * @param CommandChannel $channel 由 runRconCheck 选好路（RCON 或面板控制台）
     * @return array<string,mixed>
     */
    public static function rconPlayerOnline(CommandChannel $channel, string $player): array
    {
        if ($player === '') {
            return ['status' => 'skipped', 'message' => '未提供玩家 ID，跳过在线检查', 'data' => []];
        }

        $list = $channel->command('list');
        $channel->close();

        if (!$list['ok']) {
            return ['status' => 'unknown', 'message' => '无法读取在线名单（' . $channel->label() . '）：' . $list['error'], 'data' => []];
        }

        $names = [];
        if (preg_match('/:\s*(.*)$/s', $list['output'], $m)) {
            foreach (explode(',', $m[1]) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $online = false;
        foreach ($names as $name) {
            if (strcasecmp($name, $player) === 0) {
                $online = true;
                break;
            }
        }

        return [
            'status'  => $online ? 'pass' : 'warn',
            'message' => $online
                ? sprintf('玩家 %s 此刻在线（共 %d 人在线）', $player, count($names))
                : sprintf('玩家 %s 此刻不在线（当前在线：%s）', $player, $names ? implode('、', array_slice($names, 0, 20)) : '无'),
            'data'    => ['players' => $names, 'online' => $online],
        ];
    }

    /**
     * @param CommandChannel $channel 由 runRconCheck 选好路（RCON 或面板控制台）
     * @return array<string,mixed>
     */
    public static function rconWhitelistState(CommandChannel $channel): array
    {
        $result = $channel->command('whitelist list');
        $channel->close();

        if (!$result['ok']) {
            return ['status' => 'unknown', 'message' => '无法读取白名单（' . $channel->label() . '）：' . $result['error'], 'data' => []];
        }

        $output = $result['output'];
        $lower = mb_strtolower($output);

        if (mb_strpos($lower, 'unknown') !== false || mb_strpos($lower, 'not') === 0) {
            return ['status' => 'warn', 'message' => '服务端未启用白名单（这正是"所有人都能进"或指令不存在的原因）', 'data' => ['raw' => $output]];
        }

        $count = 0;
        if (preg_match_all('/[A-Za-z0-9_]{3,16}/', $output, $m)) {
            $count = count($m[0]);
        }

        $data = ['raw' => $output, 'count' => $count];

        if ($count === 0) {
            return [
                'status'  => 'fail',
                'message' => '白名单已开启但名单里一个人都没有 —— 除了 OP，谁都进不来',
                'data'    => $data,
            ];
        }

        return ['status' => 'pass', 'message' => sprintf('白名单已启用，共 %d 个名额', $count), 'data' => $data];
    }

    /**
     * @param CommandChannel $channel 由 runRconCheck 选好路（RCON 或面板控制台）
     * @return array<string,mixed>
     */
    public static function rconWhitelistPlayer(CommandChannel $channel, string $player): array
    {
        if ($player === '') {
            return ['status' => 'skipped', 'message' => '未提供玩家 ID，跳过白名单检查', 'data' => []];
        }

        $result = $channel->command('whitelist list');
        $channel->close();

        if (!$result['ok']) {
            return ['status' => 'unknown', 'message' => '无法读取白名单（' . $channel->label() . '）：' . $result['error'], 'data' => []];
        }

        $output = $result['output'];
        $inList = stripos($output, $player) !== false;

        return [
            'status'  => $inList ? 'pass' : 'fail',
            'message' => $inList
                ? sprintf('%s 已经在白名单里，进不去的原因不在这里', $player)
                : sprintf('%s 不在白名单里 —— 这就是"进不去"的直接原因，可以自动加入', $player),
            'data'    => ['raw' => $output, 'in_whitelist' => $inList],
        ];
    }

    /**
     * @param CommandChannel $channel 由 runRconCheck 选好路（RCON 或面板控制台）
     * @return array<string,mixed>
     */
    public static function rconBanState(CommandChannel $channel, string $player): array
    {
        if ($player === '') {
            return ['status' => 'skipped', 'message' => '未提供玩家 ID，跳过封禁检查', 'data' => []];
        }

        $bans = $channel->command('banlist players');
        $channel->close();

        if (!$bans['ok']) {
            return ['status' => 'unknown', 'message' => '无法读取封禁名单（' . $channel->label() . '）：' . $bans['error'], 'data' => []];
        }

        $output = $bans['output'];
        $banned = stripos($output, $player) !== false;

        if ($banned) {
            return [
                'status'  => 'fail',
                'message' => sprintf('%s 处于封禁名单中，这就是进不去的原因', $player),
                'data'    => ['raw' => $output, 'banned' => true],
            ];
        }

        return [
            'status'  => 'pass',
            'message' => sprintf('%s 没有被封禁', $player),
            'data'    => ['raw' => $output, 'banned' => false],
        ];
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function pickTpsCommand(array $server): string
    {
        $configured = (string) ($server['tps_command'] ?? '');
        if ($configured !== '') {
            return $configured;
        }

        return 'tps';
    }

    /**
     * 给玩家看的"人话"摘要（不含任何敏感信息）。
     *
     * @param array<string,mixed> $diagnosis
     * @return array<int,array<string,string>>
     */
    public static function playerSummary(array $diagnosis): array
    {
        $lines = [];
        foreach ((array) ($diagnosis['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $lines[] = [
                'label'  => (string) ($check['label'] ?? $check['code'] ?? ''),
                'status' => (string) ($check['status'] ?? 'unknown'),
                'text'   => (string) ($check['message'] ?? ''),
            ];
        }

        return $lines;
    }
}
