<?php

declare(strict_types=1);

namespace MCFix\Panel;

/**
 * Multicraft 适配器。
 *
 * 谁在用：Apex Hosting，以及一部分 BisectHosting 服务器
 * （BisectHosting 同时有 Multicraft 和 Pterodactyl 面板，见 deploy/主机商接入索引.md）。
 *
 * ------------------------------------------------------------------ 协议
 *
 * 端点：POST {api_url}（通常是 https://<面板域名>/api.php），表单编码。
 *
 * 认证**不是**把 key 原样发过去，而是算一个签名：
 *
 *     message   = 把所有参数按顺序拼成 "键名+值"（键也要拼，无分隔符）
 *     签名       = hash_hmac('sha256', message, api_key)
 *
 * 拼进签名的参数包含业务参数，以及 `_MulticraftAPIMethod` 和 `_MulticraftAPIUser`，
 * 但**不包含** `_MulticraftAPIKey` 本身（它是算出来的）。
 *
 * 注意：网上流传的一个 2013 年单文件客户端用的是
 * `md5(apikey + method + user + 只有值)` —— **那是错的或已过期**。
 * 这里按现行实现（multicraft-py 的 core/base.py）来：拼接含键名，摘要用 HMAC-SHA256。
 *
 * 响应：{"success": bool, "errors": [...], "data": {...}}
 *
 * ------------------------------------------------------------------ 能力边界
 *
 * Multicraft 的 API **没有文件接口**，所以做不到：
 *   - list_dir  列 mods 目录      → MOD 清单比对用不了
 *   - read_file 读任意文件        → mods.toml 读不了
 *   - pull_mod  取回模组文件      → 玩家下载不了
 *
 * 但修复动作是完整的：下发指令 + 开关机 + 读日志 + 看资源。
 * 能力矩阵里如实声明，不假装支持。
 */
final class MulticraftPanel extends PanelAdapter
{
    public function label(): string
    {
        return 'Multicraft 面板';
    }

    public function capabilities(): array
    {
        // 三个凭据缺一不可：地址、用户名、密钥
        if ($this->apiUrl() === '' || $this->username() === '' || $this->apiKey() === '') {
            return [];
        }

        return ['console', 'power', 'read_log', 'stat_log', 'resources'];
    }

    // ---------------------------------------------------------------- 核心动作

    public function sendCommand(string $command): array
    {
        $serverId = $this->serverId();
        if ($serverId === '') {
            return $this->fail('没填「服务器 ID」—— Multicraft 的面板地址里能看到，或问主机商');
        }

        // 注意参数名：这个动作要 server_id，其余动作要 id
        return $this->action('sendConsoleCommand', ['server_id' => $serverId, 'command' => $command], '已下发指令');
    }

    public function power(string $action): array
    {
        $serverId = $this->serverId();
        if ($serverId === '') {
            return $this->fail('没填「服务器 ID」');
        }

        $map = [
            'start'   => 'startServer',
            'stop'    => 'stopServer',
            'restart' => 'restartServer',
            'kill'    => 'killServer',
        ];
        $method = $map[$action] ?? '';
        if ($method === '') {
            return $this->fail('不支持的操作：' . $action);
        }

        return $this->action($method, ['id' => $serverId], '已执行 ' . $action);
    }

    public function readLog(int $maxBytes = 262144): array
    {
        $serverId = $this->serverId();
        if ($serverId === '') {
            return ['ok' => false, 'content' => '', 'size' => 0, 'mtime' => 0, 'error' => '没填「服务器 ID」'];
        }

        $res = $this->call('getServerLog', ['id' => $serverId]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'mtime' => 0, 'error' => (string) $res['error']];
        }

        // 返回形如 [{"line": "..."}, ...]
        $lines = [];
        foreach ((array) $res['data'] as $row) {
            if (is_array($row) && isset($row['line'])) {
                $lines[] = (string) $row['line'];
            } elseif (is_string($row)) {
                $lines[] = $row;
            }
        }

        $content = implode("\n", $lines);
        if (strlen($content) > $maxBytes) {
            $content = substr($content, -$maxBytes);
        }

        // Multicraft 不给文件大小和修改时间，只能拿内容长度顶上 ——
        // 上层需要的是"日志有没有在动"，长度变化就够判断了。
        return [
            'ok'      => true,
            'content' => $content,
            'size'    => strlen($content),
            'mtime'   => time(),
            'error'   => '',
        ];
    }

    public function resources(): array
    {
        $serverId = $this->serverId();
        if ($serverId === '') {
            return ['ok' => false, 'error' => '没填「服务器 ID」'];
        }

        $res = $this->call('getServerResources', ['id' => $serverId]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string) $res['error']];
        }

        $data = (array) $res['data'];

        // Multicraft 的字段名随版本有出入，能认几个认几个
        $pick = static function (array $src, array $keys): float {
            foreach ($keys as $k) {
                if (isset($src[$k]) && is_numeric($src[$k])) {
                    return (float) $src[$k];
                }
            }

            return 0.0;
        };

        return [
            'ok'    => true,
            'error' => '',
            'data'  => [
                'cpu'        => $pick($data, ['cpu', 'cpu_usage', 'cpuUsage']),
                'memory'     => $pick($data, ['memory', 'memory_usage', 'memoryUsage']),
                'memory_max' => $pick($data, ['memory_max', 'memoryLimit', 'memory_limit']),
                'disk'       => $pick($data, ['disk', 'disk_usage', 'diskUsage']),
                'players'    => (int) $pick($data, ['players', 'player_count']),
                'max_players'=> (int) $pick($data, ['max_players', 'maxPlayers']),
            ],
        ];
    }

    /**
     * 自检：用 getCurrentUser 验证用户名 + 密钥能不能过。
     * 这个动作不需要任何参数，所以能把"凭据不对"和"服务器 ID 不对"分开报。
     */
    public function test(): array
    {
        $res = $this->call('getCurrentUser');

        if (empty($res['ok'])) {
            return [
                'ok'      => false,
                'message' => 'Multicraft 连接失败：' . (string) $res['error'],
                'detail'  => [
                    'url'        => mask_secret_url($this->apiUrl()),
                    'transcript' => $this->transcript(),
                ],
            ];
        }

        $user = (array) ($res['data']['User'] ?? []);
        $name = (string) ($user['name'] ?? $this->username());
        $caps = $this->capabilities();

        return [
            'ok'      => true,
            'message' => 'Multicraft 连接成功'
                . ($name !== '' ? '：' . $name : '')
                . '，支持：' . implode('、', $caps)
                . '（**没有文件接口**，所以读目录/取模组用不了，但下发指令和开关机都正常）',
            'detail'  => [
                'url'          => mask_secret_url($this->apiUrl()),
                'capabilities' => $caps,
                'transcript'   => $this->transcript(),
            ],
        ];
    }

    // ---------------------------------------------------------------- 内部

    /**
     * 发一个动作，把结果翻译成统一格式。
     *
     * @param array<string,mixed> $params
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    private function action(string $method, array $params, string $okText): array
    {
        $res = $this->call($method, $params);
        if (empty($res['ok'])) {
            return $this->fail((string) $res['error'], (float) $res['ms']);
        }

        return $this->done($okText, (float) $res['ms']);
    }

    /**
     * 真正发请求：算签名 → POST 表单 → 解析 {success, errors, data}。
     *
     * @param array<string,mixed> $params
     * @return array{ok:bool,data:mixed,error:string,ms:float}
     */
    private function call(string $method, array $params = []): array
    {
        if ($this->apiUrl() === '') {
            return ['ok' => false, 'data' => [], 'error' => '没填「面板地址」', 'ms' => 0.0];
        }
        if ($this->username() === '' || $this->apiKey() === '') {
            return ['ok' => false, 'data' => [], 'error' => '没填「Multicraft 用户名」或「API 密钥」', 'ms' => 0.0];
        }

        // 顺序有意义：签名按参数顺序拼接，且业务参数在 _Multicraft* 之前
        $params = $this->reduce($params);
        $params['_MulticraftAPIMethod'] = $method;
        $params['_MulticraftAPIUser']   = $this->username();
        $params['_MulticraftAPIKey']    = $this->signature($params);

        $body = http_build_query($params, '', '&', PHP_QUERY_RFC1738);

        $response = $this->http(
            'POST',
            $this->apiUrl(),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $body
        );

        $ms = (float) $response['ms'];

        if (empty($response['ok'])) {
            $hint = (int) $response['status'] > 0 ? '（HTTP ' . (int) $response['status'] . '）' : '';
            $snippet = mb_substr(trim((string) $response['body']), 0, 200);

            return [
                'ok'    => false,
                'data'  => [],
                'error' => (string) $response['error'] . $hint . ($snippet !== '' ? '：' . $snippet : ''),
                'ms'    => $ms,
            ];
        }

        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            return [
                'ok'    => false,
                'data'  => [],
                'error' => '返回的不是 JSON（地址多半填错了，要填到 api.php）：'
                    . mb_substr(trim((string) $response['body']), 0, 160),
                'ms'    => $ms,
            ];
        }

        if (empty($decoded['success'])) {
            $errors = (array) ($decoded['errors'] ?? []);
            $first = $errors ? str_replace('&quot;', '"', (string) reset($errors)) : '面板未说明原因';

            return ['ok' => false, 'data' => [], 'error' => $first, 'ms' => $ms];
        }

        return ['ok' => true, 'data' => $decoded['data'] ?? [], 'error' => '', 'ms' => $ms];
    }

    /**
     * 参数里的数组要转成 JSON 字符串再参与签名 —— 和官方客户端一致。
     *
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    private function reduce(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $out[(string) $key] = is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value;
        }

        return $out;
    }

    /**
     * 拼签名串：把每个参数的**键名和值**依次接起来，键名也要拼。
     *
     * 这是整个适配器最容易写错的一处 —— 少了键名、或换了摘要算法，都会认证失败，
     * 而面板只会回一句含糊的错误。
     *
     * @param array<string,string> $params
     */
    private function signature(array $params): string
    {
        $message = '';
        foreach ($params as $key => $value) {
            $message .= $key . $value;
        }

        return hash_hmac('sha256', $message, $this->apiKey());
    }

    private function apiUrl(): string
    {
        return trim((string) $this->cfg('api_url', ''));
    }

    private function apiKey(): string
    {
        return trim((string) $this->cfg('api_key', ''));
    }

    /**
     * Multicraft 的用户名。和密钥一样是必需凭据：签名要用它，请求里也要带。
     */
    private function username(): string
    {
        return trim((string) $this->cfg('username', ''));
    }

    /**
     * 服务器 ID。Multicraft 用数字 ID，不是 UUID。
     */
    private function serverId(): string
    {
        return trim((string) $this->cfg('server_id', ''));
    }
}
