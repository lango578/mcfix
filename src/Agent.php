<?php

declare(strict_types=1);

namespace MCFix;

/**
 * MC 侧 Agent 的注册与心跳。
 *
 * Agent 每 10 秒来一次 /api/agent/poll，顺便汇报机器状态。
 * 面板靠这张表知道"MC 机器现在什么情况、还能不能自动修"。
 */
final class Agent
{
    public static function auth(string $serverId, string $token): bool
    {
        $server = Config::server($serverId);
        if ($server === null) {
            return false;
        }

        $expected = (string) ($server['agent_token'] ?? '');
        if ($expected === '') {
            return false;
        }

        // 公开仓库里印着的占位值等于没设。
        // config.example.php 里 ship 的是 'CHANGE_ME_AGENT_TOKEN'，照抄不改的话
        // ?r=api.agent.* 对任何人开放：读任务队列、伪造执行结果、
        // 往 storage/mods 里塞一个玩家随后会下载的 jar。
        if (Token::isPlaceholderSecret($expected)) {
            return false;
        }

        // 支持两种令牌：配置里写死的固定串，或 Token::agentToken() 生成的签名串
        if (hash_equals($expected, $token)) {
            return true;
        }

        $parsed = Token::parse($token, Token::SCOPE_AGENT);
        if ($parsed === null || (string) $parsed['data'][0] !== $serverId) {
            return false;
        }

        /*
         * ★ 签名令牌必须绑定"当前"固定 token（1.13.4 实测补上）。
         *
         * 少了这一条，后台的「重新生成」吊销不了签名令牌：实测换掉 agent_token 后，
         * 旧固定 token 立刻 401，签名令牌依旧 200，而它的 TTL 是 10 年 ——
         * 于是"泄露过的 Agent 凭据"只能靠轮换全局 hmac_secret 才能收回，
         * 而那会把全站所有链接一起废掉。绑定之后两者同生共死。
         */
        $bound = (string) ($parsed['data'][1] ?? '');

        return $bound !== '' && hash_equals(Token::agentBinding($expected), $bound);
    }

    /**
     * 面板侧**认识**的 Agent 上报字段（白名单）。
     *
     * 为什么要有这份名单：面板和 Agent 是两个各自演进的程序，字段全靠约定。
     * 约定破了不会有任何报错 —— 面板读一个 Agent 从不上报的键，`?? ''` 一兜就
     * 变成空值/0，页面上照样渲染。实测就是这么踩的两次：
     *   - `rcon`：面板读它决定"RCON 可用吗"，Agent 压根没这个字段 → 永远是 0；
     *   - `java`：Agent 把 PHP 版本填进了 java → 后台显示"Java 8.3.6"。
     * 两个都是"看起来有值、其实是假的"。
     */
    public const REPORT_KNOWN = [
        // 面板真的会读、并落库展示的
        'agent_version', 'hostname', 'mc_online', 'players', 'max_players', 'tps',
        'cpu', 'mem', 'disk_free', 'disk_total', 'motd', 'version', 'java',
        'rcon', 'load', 'uptime', 'log_size', 'mc_dir', 'mods', 'error',
        // 登记在册、但不参与判断的附加字段
        'php', 'at',
    ];

    /**
     * 面板真的会读的字段 —— 少了就是契约破了（后台会显示成空/0）。
     *
     * 注意这里**不再包含 world_size**：面板原来读 `$report['world_size']`，
     * 而 Agent 的心跳从来不上报它（存档大小是诊断检查那一路算的），
     * 所以那个键一直是死读取。契约检查把它揪出来了。
     */
    public const REPORT_REQUIRED = [
        'agent_version', 'hostname', 'mc_online', 'players', 'max_players', 'tps',
        'cpu', 'mem', 'disk_free', 'disk_total', 'motd', 'version', 'java',
        'rcon', 'load', 'uptime', 'log_size', 'mc_dir', 'mods',
    ];

    /**
     * 检查一次心跳上报的字段契约（纯函数，能直接测）。
     *
     * @param array<string,mixed> $report
     * @return array{ok:bool,unknown:string[],missing:string[],signature:string}
     */
    public static function checkReportContract(array $report): array
    {
        $keys = array_map('strval', array_keys($report));
        $unknown = array_values(array_diff($keys, self::REPORT_KNOWN));
        $missing = array_values(array_diff(self::REPORT_REQUIRED, $keys));
        sort($unknown);
        sort($missing);

        return [
            'ok'        => $unknown === [] && $missing === [],
            'unknown'   => $unknown,
            'missing'   => $missing,
            // 用来去重：内容没变就不要再记一条事件（心跳 8 秒一次）
            'signature' => md5(implode(',', $unknown) . '|' . implode(',', $missing)),
        ];
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function heartbeat(string $serverId, array $report): void
    {
        $now = now();
        $extra = [
            'motd'        => (string) ($report['motd'] ?? ''),
            'version'     => (string) ($report['version'] ?? ''),
            'java'        => (string) ($report['java'] ?? ''),
            'php'         => (string) ($report['php'] ?? ''),
            'load'        => (string) ($report['load'] ?? ''),
            'uptime'      => (string) ($report['uptime'] ?? ''),
            'rcon'        => !empty($report['rcon']) ? 1 : 0,
            'log_size'    => (string) ($report['log_size'] ?? ''),
            // 原来这里读 $report['world_size']，而 Agent 心跳从不上报它 ——
            // 死读取，永远是空串（存档大小是诊断检查那一路算的）。
            'pending_dir' => (string) ($report['mc_dir'] ?? ''),
        ];

        /*
         * 字段契约检查：Agent 报了我不认识的键、或少报了我必须读的键，
         * 就在这里记一条 warn（内容不变时不重复记 —— 心跳 8 秒一次，否则刷屏）。
         */
        $contract = self::checkReportContract($report);
        $extra['contract'] = [
            'ok'      => $contract['ok'],
            'unknown' => $contract['unknown'],
            'missing' => $contract['missing'],
        ];
        if (!$contract['ok']) {
            $sigKey = 'agent-contract:' . $serverId;
            if ((string) Cache::get($sigKey, '') !== $contract['signature']) {
                Cache::put($sigKey, $contract['signature'], 86400);
                record_event(null, 'agent', 'agent.contract', sprintf(
                    'Agent（%s）上报的字段和面板对不上：多出 [%s]；缺少 [%s]',
                    $serverId,
                    $contract['unknown'] === [] ? '无' : implode('、', $contract['unknown']),
                    $contract['missing'] === [] ? '无' : implode('、', $contract['missing'])
                ), $contract, 'warn');
                app_log('warn', 'Agent 上报字段契约不一致', [
                    'server_id' => $serverId,
                    'unknown'   => $contract['unknown'],
                    'missing'   => $contract['missing'],
                ]);
            }
        }

        // 服务端 MOD 清单：用于和玩家客户端日志做交叉比对
        if (!empty($report['mods']) && is_array($report['mods'])) {
            $extra['mods'] = ServerMods::normalize($report['mods']);
        }

        $data = [
            'agent_version' => mb_substr((string) ($report['agent_version'] ?? '未知'), 0, 32),
            'hostname'      => mb_substr((string) ($report['hostname'] ?? ''), 0, 128),
            'mc_online'     => !empty($report['mc_online']) ? 1 : 0,
            'players'       => (int) ($report['players'] ?? 0),
            'max_players'   => (int) ($report['max_players'] ?? 0),
            'tps'           => mb_substr((string) ($report['tps'] ?? ''), 0, 16),
            'process_cpu'   => mb_substr((string) ($report['cpu'] ?? ''), 0, 16),
            'process_mem'   => mb_substr((string) ($report['mem'] ?? ''), 0, 24),
            'disk_free'     => mb_substr((string) ($report['disk_free'] ?? ''), 0, 24),
            'disk_total'    => mb_substr((string) ($report['disk_total'] ?? ''), 0, 24),
            'extra'         => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_seen'     => $now,
            'last_error'    => mb_substr((string) ($report['error'] ?? ''), 0, 800),
        ];

        $existing = self::seen($serverId);
        if ($existing === null) {
            Db::insert('agent_seen', array_merge($data, [
                'server_id'  => $serverId,
                'first_seen' => $now,
            ]));

            record_event(null, 'system', 'agent.online', 'MC 侧 Agent 首次上线：' . (string) ($report['hostname'] ?? $serverId), [
                'server_id' => $serverId,
                'version'   => $report['agent_version'] ?? '',
            ]);
        } else {
            Db::update('agent_seen', $data, ['server_id' => $serverId]);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function seen(string $serverId): ?array
    {
        $row = Db::first('SELECT * FROM agent_seen WHERE server_id = :sid', ['sid' => $serverId]);
        if ($row === null) {
            return null;
        }
        $row['extra_data'] = safe_json_decode($row['extra'] ?? null);

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        $rows = Db::all('SELECT * FROM agent_seen ORDER BY last_seen DESC');
        foreach ($rows as $i => $row) {
            $rows[$i]['extra_data'] = safe_json_decode($row['extra'] ?? null);
        }

        return $rows;
    }

    public static function onlineCount(int $maxAge = 60): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM agent_seen WHERE last_seen > :since',
            ['since' => gmdate('Y-m-d H:i:s', time() - $maxAge)]
        );
    }

    public static function isOnline(string $serverId, int $maxAge = 60): bool
    {
        $seen = self::seen($serverId);
        if ($seen === null || empty($seen['last_seen'])) {
            return false;
        }

        return (time() - ts((string) $seen['last_seen'])) <= $maxAge;
    }

    /**
     * 决定这次 Agent 请求该走哪个动作。
     *
     * Agent 侧只配一个 panel_url（`?r=api.agent.poll`），poll / report / log /
     * mod_upload 四个动作全靠请求体里的 `action` 区分。URL 只负责把请求送进
     * agent.php —— 所以**请求体优先**。
     *
     * 抽成纯函数是为了能直接测：这个判断写错时的症状极其隐蔽 ——
     * report 被打进 poll 分支，poll 会把它当普通轮询照样回 ok:true，
     * 于是 Agent 那边不报警，站点侧任务永远停在 running。
     *
     * @param string $urlAction 路由里解析出来的动作（?r=api.agent.<x> 的 <x>）
     * @param array<string,mixed> $input 请求体
     */
    public static function resolveAction(string $urlAction, array $input): string
    {
        $action = ltrim(trim($urlAction), '.');

        $body = $input['action'] ?? '';
        if (is_string($body)) {
            $body = ltrim(trim($body), '.');
            // 也容忍把 "agent.report" 整个塞进 action 的写法
            if (strncmp($body, 'agent.', 6) === 0) {
                $body = substr($body, 6);
            }
            if ($body !== '') {
                $action = $body;
            }
        }

        return $action;
    }

    /**
     * 生成给 Agent 用的配置文件片段，后台"一键复制"用。
     *
     * 这里必须把 Agent 真正会用到的字段全带上 —— 少一个字段，管理员就会部署出一个
     * "能连上但什么也修不了"的 Agent，而且线上很难看出来是哪一步漏了。
     * 尤其是 rcon：缺了它，Agent 侧的白名单/解封/踢人全部只能失败。
     *
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function bootstrapConfig(array $server): array
    {
        $guard = (array) ($server['guard'] ?? []);
        $rcon = (array) ($server['rcon'] ?? []);

        return [
            // 面板地址一律走「玩家反馈站」那个域名：Agent 接口和玩家接口同源，
            // 后台域名（console_host）上不暴露 Agent 接口。
            'panel_url'   => ConsoleAuth::feedbackUrl('?r=api.agent.poll'),
            'server_id'   => (string) $server['id'],
            'token'       => (string) ($server['agent_token'] ?? ''),
            'mc_dir'      => (string) ($server['mc_dir'] ?? '/www/minecraft/server'),
            'log_path'    => (string) ($server['log_path'] ?? 'logs/latest.log'),
            'listen_port' => (int) ($server['listen_port'] ?? $server['port'] ?? 25565),
            'disk_path'   => (string) ($server['disk_path'] ?? ''),
            'rcon'        => [
                'enabled'  => !empty($rcon['enabled']) && (string) ($rcon['password'] ?? '') !== '',
                'host'     => (string) ($rcon['host'] ?? $server['host'] ?? '127.0.0.1'),
                'port'     => (int) ($rcon['port'] ?? 25575),
                'password' => (string) ($rcon['password'] ?? ''),
                'timeout'  => (int) ($rcon['timeout'] ?? 3),
            ],
            'guard'       => [
                'type'        => (string) ($guard['type'] ?? 'systemd'),
                'service'     => (string) ($guard['service'] ?? ''),
                'session'     => (string) ($guard['session'] ?? ''),
                // 自定义守护方式要有命令才跑得起来，之前这两个字段没下发过
                'start_cmd'   => (string) ($guard['start_cmd'] ?? ''),
                'stop_cmd'    => (string) ($guard['stop_cmd'] ?? ''),
                'restart_cmd' => (string) ($guard['restart_cmd'] ?? ''),
                'max_restarts_per_hour' => (int) ($guard['max_restarts_per_hour'] ?? 3),
            ],
        ];
    }
}
