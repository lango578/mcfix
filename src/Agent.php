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

        return $parsed !== null && (string) $parsed['data'][0] === $serverId;
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
            'load'        => (string) ($report['load'] ?? ''),
            'uptime'      => (string) ($report['uptime'] ?? ''),
            'rcon'        => !empty($report['rcon']) ? 1 : 0,
            'log_size'    => (string) ($report['log_size'] ?? ''),
            'world_size'  => (string) ($report['world_size'] ?? ''),
            'pending_dir' => (string) ($report['mc_dir'] ?? ''),
        ];

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
