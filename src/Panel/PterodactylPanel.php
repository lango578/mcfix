<?php

declare(strict_types=1);

namespace MCFix\Panel;

/**
 * 翼龙 / Pterodactyl 适配器（含 Pelican 等同源面板）。
 *
 * 两种密钥二选一，能力不同：
 *
 *   application 模式（面板管理员密钥，前缀 ptla_）
 *     - 能开停重启、能下发指令、能读写文件、能看资源占用
 *     - 需要在面板里创建 Application API Key，并在该节点的权限里勾选服务器
 *
 *   client 模式（服务器子用户密钥，前缀 ptlc_）
 *     - 能开停重启、能下发指令、能读文件
 *     - 权限范围小得多，泄露风险低，优先用这个
 *
 * 配置：
 *   panel.type        = pterodactyl
 *   panel.mode        = application | client（默认 client）
 *   panel.api_url     = 面板地址，例如 https://panel.example.com
 *   panel.api_key     = ptla_... 或 ptlc_...
 *   panel.server_id   = 服务器短 ID（面板地址栏 /server/xxxxxxxx 里的那一段）
 *   panel.mc_dir      = 容器内工作目录，翼龙一般是 /home/container
 */
final class PterodactylPanel extends PanelAdapter
{
    public function label(): string
    {
        return $this->mode() === 'application' ? '翼龙面板（Application API）' : '翼龙面板（Client API）';
    }

    public function capabilities(): array
    {
        if ($this->cfg('api_url') === '' || $this->cfg('api_key') === '' || $this->cfg('server_id') === '') {
            return [];
        }

        return ['console', 'power', 'read_log', 'stat_log', 'read_file', 'list_dir', 'resources'];
    }

    public function test(): array
    {
        $endpoint = $this->endpointNamed('server', '');
        $result = $this->json('GET', $endpoint, $this->headers());

        if (!$result['ok']) {
            return [
                'ok'      => false,
                'message' => $this->label() . ' 连接失败：' . $result['error'],
                'detail'  => ['url' => mask_secret_url($endpoint), 'mode' => $this->mode(), 'transcript' => $this->transcript()],
            ];
        }

        $name = (string) ($result['data']['attributes']['name'] ?? $result['data']['attributes']['identifier'] ?? '');
        $state = (string) ($result['data']['attributes']['status'] ?? '');

        return [
            'ok'      => true,
            'message' => '翼龙面板连接成功' . ($name !== '' ? '：' . $name : '')
                . ($state !== '' ? '（当前状态 ' . $state . '）' : '')
                . '，支持：' . implode('、', $this->capabilities()),
            'detail'  => [
                'url'          => mask_secret_url($endpoint),
                'mode'         => $this->mode(),
                'capabilities' => $this->capabilities(),
                'transcript'   => $this->transcript(),
            ],
        ];
    }

    public function sendCommand(string $command): array
    {
        $endpoint = $this->endpointNamed('command', '/command');
        $result = $this->json('POST', $endpoint, $this->headers(), ['command' => $command]);

        if (!$result['ok']) {
            return $this->fail('翼龙指令下发失败：' . $result['error']);
        }

        // 翼龙同样不回显控制台输出；输出可以通过 readLog 拿到
        return $this->done('已投递到翼龙控制台（如需回显请用 readLog 或开启 RCON）');
    }

    public function power(string $action): array
    {
        $map = ['start' => 'start', 'stop' => 'stop', 'restart' => 'restart', 'kill' => 'kill'];
        if (!isset($map[$action])) {
            return $this->fail('不支持的电源动作：' . $action);
        }

        $endpoint = $this->endpointNamed('power', '/power');
        $result = $this->json('POST', $endpoint, $this->headers(), ['signal' => $map[$action]]);

        if (!$result['ok']) {
            return $this->fail('翼龙 ' . $action . ' 失败：' . $result['error']);
        }

        return $this->done('已向翼龙下发 ' . $action);
    }

    public function readLog(int $maxBytes = 262144): array
    {
        $path = $this->logPath();
        $endpoint = $this->endpointNamed('read_file', '/files/contents', ['file' => '/' . ltrim($path, '/')]);

        // 翼龙的文件接口一次返回整个文件，所以先问大小，太大就退化到 resources/取尾
        $sizeInfo = $this->statLog();
        if (!empty($sizeInfo['ok']) && $sizeInfo['size'] > $maxBytes * 4) {
            // 文件很大时不下载整个文件：改用控制台日志接口（如果有）
            $alt = $this->endpointNamed('console_log', '/logs');
            $altResult = $this->json('GET', $alt, $this->headers());
            if (!empty($altResult['ok'])) {
                $raw = $altResult['data']['attributes']['logs'] ?? null;
                if (is_string($raw) && $raw !== '') {
                    return ['ok' => true, 'content' => substr($raw, -$maxBytes), 'size' => strlen($raw), 'mtime' => time(), 'error' => ''];
                }
            }
        }

        $response = $this->http('GET', $endpoint, $this->headers());
        if (!$response['ok']) {
            return [
                'ok'      => false,
                'content' => '',
                'size'    => 0,
                'mtime'   => 0,
                'error'   => '读取日志失败：' . $response['error'],
            ];
        }

        $content = $response['body'];
        $size = strlen($content);
        if ($size > $maxBytes) {
            $content = substr($content, -$maxBytes);
        }

        return ['ok' => true, 'content' => $content, 'size' => $size, 'mtime' => time(), 'error' => ''];
    }

    public function statLog(): array
    {
        $dir = dirname('/' . ltrim($this->logPath(), '/'));
        $endpoint = $this->endpointNamed('list_dir', '/files/list', ['directory' => $dir]);
        $result = $this->json('GET', $endpoint, $this->headers());

        if (!$result['ok']) {
            return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => '列目录失败：' . $result['error']];
        }

        $target = basename($this->logPath());
        $entries = $result['data']['data'] ?? [];
        foreach ((array) $entries as $entry) {
            $attributes = is_array($entry) ? ($entry['attributes'] ?? $entry) : [];
            if ((string) ($attributes['name'] ?? '') === $target) {
                return [
                    'ok'    => true,
                    'size'  => (int) ($attributes['size'] ?? 0),
                    'mtime' => (int) strtotime((string) ($attributes['modified_at'] ?? 'now')),
                    'error' => '',
                ];
            }
        }

        return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => '日志文件不存在：' . $this->logPath()];
    }

    public function listDir(string $path): array
    {
        $endpoint = $this->endpointNamed('list_dir', '/files/list', ['directory' => '/' . ltrim($path, '/')]);
        $result = $this->json('GET', $endpoint, $this->headers());

        if (!$result['ok']) {
            return ['ok' => false, 'files' => [], 'error' => '列目录失败：' . $result['error']];
        }

        $files = [];
        foreach ((array) ($result['data']['data'] ?? []) as $entry) {
            $attributes = is_array($entry) ? ($entry['attributes'] ?? $entry) : [];
            $name = (string) ($attributes['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $files[] = [
                'name'  => $name,
                'size'  => (int) ($attributes['size'] ?? 0),
                'mtime' => (int) strtotime((string) ($attributes['modified_at'] ?? 'now')),
                'dir'   => (string) ($attributes['mimetype'] ?? '') === 'inode/directory',
            ];
        }

        return ['ok' => true, 'files' => $files, 'error' => ''];
    }

    public function readFile(string $path, int $maxBytes = 65536): array
    {
        $endpoint = $this->endpointNamed('read_file', '/files/contents', ['file' => '/' . ltrim($path, '/')]);
        $response = $this->http('GET', $endpoint, $this->headers());

        if (!$response['ok']) {
            return ['ok' => false, 'content' => '', 'error' => '读文件失败：' . $response['error']];
        }

        return ['ok' => true, 'content' => substr($response['body'], 0, $maxBytes), 'error' => ''];
    }

    /**
     * 资源占用（CPU / 内存 / 磁盘），诊断时会用到。
     *
     * @return array<string,mixed>
     */
    public function resources(): array
    {
        $endpoint = $this->endpointNamed('resources', '/resources');
        $result = $this->json('GET', $endpoint, $this->headers());
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $attributes = (array) ($result['data']['attributes'] ?? []);
        $resources = (array) ($attributes['resources'] ?? []);

        return [
            'ok'         => true,
            'state'      => (string) ($attributes['current_state'] ?? ''),
            'cpu'        => round((float) ($resources['cpu_absolute'] ?? 0), 1),
            'mem_bytes'  => (int) ($resources['memory_bytes'] ?? 0),
            'disk_bytes' => (int) ($resources['disk_bytes'] ?? 0),
            'uptime_ms'  => (int) ($resources['uptime'] ?? 0),
            'error'      => '',
        ];
    }

    private function mode(): string
    {
        $mode = (string) $this->cfg('mode', 'client');

        return $mode === 'application' ? 'application' : 'client';
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . (string) $this->cfg('api_key'),
            'Accept'        => 'application/json',
        ];
    }

    /**
     * 拼出"某个服务器下的某个子路径"的完整地址。
     *
     * 这里**不能**叫 endpoint()：父类 PanelAdapter::endpoint(string $name, string $default)
     * 是"按名字取可覆盖的接口路径"，语义完全不同，同名会因为签名不兼容
     * 让 PHP 在加载这个类时直接致命错误 —— 而语法检查（php -l）查不出来。
     * 这个名字也顺便把两件事区分开：serverUri 负责拼地址，endpoint 负责查路径。
     *
     * @param array<string,string> $query
     */
    private function serverUri(string $suffix, array $query = []): string
    {
        $base = rtrim((string) $this->cfg('api_url'), '/');
        $serverId = (string) $this->cfg('server_id');

        $path = $this->mode() === 'application'
            ? '/api/application/servers/' . rawurlencode($serverId)
            : '/api/client/servers/' . rawurlencode($serverId);

        $url = $base . $path . $suffix;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * 名称化的端点，路径可在配置里覆盖（不同版本/分支如 Pelican 偶有差异）。
     *
     * @param array<string,string> $query
     */
    private function endpointNamed(string $name, string $default, array $query = []): string
    {
        return $this->serverUri(parent::endpoint($name, $default), $query);
    }
}
