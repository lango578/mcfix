<?php

declare(strict_types=1);

namespace MCFix\Panel;

/**
 * MCSManager 适配器（国内自建 / 面板服最常用的一种）。
 *
 * 需要的配置（后台「服务器与 Agent → 面板 API」里填）：
 *   panel.type       = mcsmanager
 *   panel.api_url    = 面板地址，例如 http://127.0.0.1:23333
 *   panel.api_key    = 面板【用户信息】里生成的 API 密钥
 *   panel.daemon_id  = 节点 / 远程服务的 UUID（文档里叫 daemonId）
 *   panel.instance_id= 实例 UUID（文档里叫 uuid）
 *   panel.mc_dir     = 实例根目录（面板文件管理里的路径，用于拼日志路径）
 *
 * 能力：下发游戏指令、开停重启、读日志、列目录（拿 MOD 清单）、读文件
 * —— 配好它之后**不需要装 Agent、也不需要开 RCON** 就能完成大部分诊断与修复。
 *
 * ---------------------------------------------------------------------------
 * 关于接口路径：MCSManager 这几年的版本里路径和参数名改过好几轮
 * （`remote_uuid` → `daemonId`、指令从 POST body 改成查询串、
 *  读文件从 `/api/files/download` 改成 `PUT /api/files/`）。
 * 所以这里的每个动作都**先按官方文档的写法发一次，失败再退回旧版写法**，
 * 而且路径全都走 `endpoint()`，管理员在后台「自定义接口参数」里就能覆盖，
 * 不用改代码。真正万能的办法是：浏览器 F12 → Network，点一下面板自己的操作，
 * 照着它发的请求填覆盖值。
 * ---------------------------------------------------------------------------
 */
final class McsManagerPanel extends PanelAdapter
{
    public function label(): string
    {
        return 'MCSManager';
    }

    public function capabilities(): array
    {
        $caps = [];
        if ($this->cfg('api_url') !== '' && $this->cfg('api_key') !== '') {
            if ($this->cfg('instance_id') !== '') {
                $caps[] = 'console';
                $caps[] = 'power';
                $caps[] = 'list_dir';
            }
            if ($this->cfg('daemon_id') !== '') {
                $caps[] = 'read_log';
                $caps[] = 'stat_log';
            }
            // 读单个文件走 PUT /api/files/，只需要实例 UUID（不需要节点 UUID）
            if ($this->cfg('instance_id') !== '') {
                $caps[] = 'read_file';
            }
        }

        return $caps;
    }

    public function test(): array
    {
        // 先试旧版就有的总览接口，不行再试文件状态（新版一定有）
        $attempts = [
            ['overview', '/api/overview'],
            ['files_status', '/api/files/status'],
        ];

        $errors = [];
        foreach ($attempts as [$name, $default]) {
            $url = $this->baseUrl($this->endpoint($name, $default), $this->instanceQuery());
            $result = $this->json('GET', $url, $this->headers());

            if ($result['ok'] && $this->isOk($result['data'])) {
                return [
                    'ok'      => true,
                    'message' => 'MCSManager 连接成功，支持：' . implode('、', $this->capabilities())
                        . (in_array('console', $this->capabilities(), true) ? '' : '（缺实例 UUID，无法下发指令）'),
                    'detail'  => [
                        'url'          => mask_secret_url($url),
                        'capabilities' => $this->capabilities(),
                        'transcript'   => $this->transcript(),
                    ],
                ];
            }

            $errors[] = $name . ' → ' . ($result['error'] !== '' ? $result['error'] : ('status=' . (string) ($result['data']['status'] ?? '?')));
        }

        return [
            'ok'      => false,
            'message' => 'MCSManager 接口连接失败：' . implode('；', $errors)
                . '。请检查面板地址、API 密钥，以及面板里是否允许了这个来源 IP。',
            'detail'  => ['transcript' => $this->transcript()],
        ];
    }

    public function sendCommand(string $command): array
    {
        $instance = (string) $this->cfg('instance_id');
        if ($instance === '') {
            return $this->fail('未配置 MCSManager 实例 UUID');
        }

        // 主线：新版文档 —— GET /api/protected_instance/command，指令在查询串里
        $url = $this->baseUrl($this->endpoint('command', '/api/protected_instance/command'), $this->instanceQuery() + [
            'command' => $command,
        ]);
        $result = $this->json('GET', $url, $this->headers());
        if ($result['ok'] && $this->isOk($result['data'])) {
            return $this->done('已投递到 MCSManager 控制台（面板接口不回显，请看控制台确认）');
        }

        // 回退：旧版 —— POST /api/instance/command，指令在 body 里
        $legacyUrl = $this->baseUrl($this->endpoint('command_legacy', '/api/instance/command'), $this->instanceQuery(['remote_uuid' => true]));
        $legacy = $this->json('POST', $legacyUrl, $this->headers(), ['command' => $command]);
        if ($legacy['ok'] && $this->isOk($legacy['data'])) {
            return $this->done('已投递到 MCSManager 控制台（走的是旧版接口；面板接口不回显，请看控制台确认）');
        }

        return $this->fail('MCSManager 指令下发失败：' . (string) ($result['error'] ?: $legacy['error']));
    }

    public function power(string $action): array
    {
        $instance = (string) $this->cfg('instance_id');
        if ($instance === '') {
            return $this->fail('未配置 MCSManager 实例 UUID');
        }

        // 新版是一堆具名路由：open / stop / restart / kill
        $map = ['start' => 'open', 'stop' => 'stop', 'restart' => 'restart', 'kill' => 'kill'];
        if (!isset($map[$action])) {
            return $this->fail('不支持的电源动作：' . $action);
        }

        $url = $this->baseUrl($this->endpoint('power_' . $map[$action], '/api/protected_instance/' . $map[$action]), $this->instanceQuery());
        $result = $this->json('GET', $url, $this->headers());
        if ($result['ok'] && $this->isOk($result['data'])) {
            return $this->done('已向 MCSManager 下发 ' . $action);
        }

        // 回退：旧版是 POST /api/instance + body {"action": "..."}
        $legacyUrl = $this->baseUrl($this->endpoint('power', '/api/instance'), $this->instanceQuery(['remote_uuid' => true]));
        $legacy = $this->json('POST', $legacyUrl, $this->headers(), ['action' => $map[$action] === 'open' ? 'start' : $map[$action]]);
        if ($legacy['ok'] && $this->isOk($legacy['data'])) {
            return $this->done('已向 MCSManager 下发 ' . $action . '（走的是旧版接口）');
        }

        return $this->fail('MCSManager ' . $action . ' 失败：' . (string) ($result['error'] ?: $legacy['error']));
    }

    public function readLog(int $maxBytes = 262144): array
    {
        if ($this->cfg('instance_id') === '' && $this->cfg('daemon_id') === '') {
            return ['ok' => false, 'content' => '', 'size' => 0, 'mtime' => 0, 'error' => '未配置实例 UUID / 节点 UUID，无法读取日志'];
        }

        $errors = [];

        // 通道 1（新版推荐）：直接取实例的控制台输出，不需要知道文件路径
        $outUrl = $this->baseUrl($this->endpoint('outputlog', '/api/protected_instance/outputlog'), $this->instanceQuery() + [
            'size' => (string) $maxBytes,
        ]);
        $out = $this->json('GET', $outUrl, $this->headers());
        if ($out['ok'] && $this->isOk($out['data']) && isset($out['data']['data']) && is_string($out['data']['data']) && trim($out['data']['data']) !== '') {
            $content = $out['data']['data'];
            $this->note('日志读取成功，通道：protected_instance/outputlog');

            return ['ok' => true, 'content' => substr($content, -$maxBytes), 'size' => strlen($content), 'mtime' => time(), 'error' => ''];
        }
        $errors[] = 'outputlog：' . ($out['error'] !== '' ? $out['error'] : '没有拿到内容');

        // 通道 2（新版）：读文件内容 —— PUT /api/files/ + body {"target": ...}
        $file = $this->readFile($this->remotePath($this->logPath()), $maxBytes);
        if (!empty($file['ok']) && trim((string) $file['content']) !== '') {
            $this->note('日志读取成功，通道：files(读文件)');

            return ['ok' => true, 'content' => (string) $file['content'], 'size' => strlen((string) $file['content']), 'mtime' => time(), 'error' => ''];
        }
        $errors[] = 'files：' . (string) ($file['error'] ?? '空内容');

        // 通道 3（旧版）：/api/files/download 直接吐文件内容
        $dlUrl = $this->baseUrl($this->endpoint('download', '/api/files/download'), $this->daemonQuery() + [
            'file_name' => $this->remotePath($this->logPath()),
        ]);
        $dl = $this->http('GET', $dlUrl, $this->headers());
        if ($dl['ok'] && trim($dl['body']) !== '') {
            $body = $this->unwrap($dl['body']);
            if (trim($body) !== '') {
                $this->note('日志读取成功，通道：files/download(旧版)');

                return ['ok' => true, 'content' => substr($body, -$maxBytes), 'size' => strlen($body), 'mtime' => time(), 'error' => ''];
            }
        }
        $errors[] = 'download：' . ($dl['error'] !== '' ? $dl['error'] : '空响应');

        return [
            'ok'      => false,
            'content' => '',
            'size'    => 0,
            'mtime'   => 0,
            'error'   => '三个通道都没读到日志（' . implode('；', $errors) . '）。'
                . '可以在后台「自定义接口参数」里覆盖 outputlog / download 路径，'
                . '或者改用「读文件」的方式（面板版本不同路径会有差异）。',
        ];
    }

    public function statLog(): array
    {
        $dir = dirname($this->remotePath($this->logPath()));
        $list = $this->listDir($dir);
        if (empty($list['ok'])) {
            return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => (string) ($list['error'] ?? '列目录失败')];
        }

        $target = basename($this->logPath());
        foreach ((array) $list['files'] as $entry) {
            if ((string) ($entry['name'] ?? '') === $target) {
                return ['ok' => true, 'size' => (int) $entry['size'], 'mtime' => (int) $entry['mtime'], 'error' => ''];
            }
        }

        return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => '日志文件不存在：' . $this->remotePath($this->logPath())];
    }

    public function listDir(string $path): array
    {
        if ($this->cfg('instance_id') === '' || $this->cfg('daemon_id') === '') {
            return ['ok' => false, 'files' => [], 'error' => '列目录需要同时配置实例 UUID 与节点 UUID'];
        }

        // 新版文档：page 从 0 开始
        $url = $this->baseUrl($this->endpoint('list_dir', '/api/files/list'), $this->instanceQuery() + [
            'target'    => $path === '' ? '/' : $path,
            'page'      => '0',
            'page_size' => '100',
        ]);
        $result = $this->json('GET', $url, $this->headers());

        if (!$result['ok'] || !$this->isOk($result['data'])) {
            return ['ok' => false, 'files' => [], 'error' => '列目录失败：' . ($result['error'] !== '' ? $result['error'] : ('status=' . (string) ($result['data']['status'] ?? '?')))];
        }

        // 响应形状在不同版本里挪过位置，这里都兜一下
        $items = $result['data']['data']['items']
            ?? $result['data']['items']
            ?? $result['data']['data']
            ?? [];

        $files = [];
        foreach ((array) $items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $files[] = [
                'name'  => $name,
                'size'  => (int) ($item['size'] ?? 0),
                'mtime' => $this->parseTime($item['time'] ?? 0),
                // 新版：0 = 文件夹，1 = 文件
                'dir'   => isset($item['type']) && (int) $item['type'] === 0,
            ];
        }

        return ['ok' => true, 'files' => $files, 'error' => ''];
    }

    public function readFile(string $path, int $maxBytes = 65536): array
    {
        if ($this->cfg('instance_id') === '') {
            return ['ok' => false, 'content' => '', 'error' => '未配置实例 UUID'];
        }

        // 新版：PUT /api/files/ + body {"target": "..."}（只给 target 就是读）
        $url = $this->baseUrl($this->endpoint('read_file', '/api/files/'), $this->instanceQuery());
        $response = $this->http('PUT', $url, $this->headers(), ['target' => $path]);

        if ($response['ok'] && trim($response['body']) !== '') {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded)) {
                if ($this->isOk($decoded) && isset($decoded['data']) && is_string($decoded['data'])) {
                    return ['ok' => true, 'content' => substr($decoded['data'], 0, $maxBytes), 'error' => ''];
                }

                return ['ok' => false, 'content' => '', 'error' => '读文件失败：' . (string) ($decoded['data'] ?? $decoded['status'] ?? '面板返回失败')];
            }

            // 不是 JSON：整段当文件内容（旧版 /api/files/download 的形态）
            return ['ok' => true, 'content' => substr($response['body'], 0, $maxBytes), 'error' => ''];
        }

        // 回退到旧版的下载接口
        $dlUrl = $this->baseUrl($this->endpoint('download', '/api/files/download'), $this->daemonQuery() + ['file_name' => $path]);
        $dl = $this->http('GET', $dlUrl, $this->headers());
        if ($dl['ok'] && trim($dl['body']) !== '') {
            return ['ok' => true, 'content' => substr($this->unwrap($dl['body']), 0, $maxBytes), 'error' => ''];
        }

        return ['ok' => false, 'content' => '', 'error' => '读文件失败：' . ($response['error'] !== '' ? $response['error'] : '面板没有返回内容')];
    }

    // ------------------------------------------------------------------ 内部工具

    /**
     * MCSManager 的接口都要带这两个头，文档里写明了"没另行指定时必需"。
     *
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept'           => 'application/json',
        ];
    }

    /**
     * 实例级查询参数。
     *
     * `daemonId` 是新版文档里的名字，`remote_uuid` 是 3.x/9.x 用的名字 ——
     * 两个都带上，多出来的参数会被面板忽略，但能让同一份配置兼容两代版本。
     *
     * @param array<string,bool> $extra 需要额外带上的旧版参数名
     * @return array<string,string>
     */
    private function instanceQuery(array $extra = []): array
    {
        $query = [
            'uuid'     => (string) $this->cfg('instance_id'),
            'daemonId' => (string) $this->cfg('daemon_id'),
        ];
        foreach ($extra as $name => $on) {
            if (!empty($on)) {
                $query[$name] = (string) $this->cfg('daemon_id');
            }
        }

        return $query;
    }

    /**
     * 只跟节点 UUID 相关的查询参数（文件下载这类接口按节点走）。
     *
     * @return array<string,string>
     */
    private function daemonQuery(): array
    {
        $daemon = (string) $this->cfg('daemon_id');

        return [
            'uuid'        => $daemon,
            'daemonId'    => $daemon,
            'remote_uuid' => $daemon,
        ];
    }

    /**
     * MCSManager 用 `{"status": 200, ...}` 表示成败，非 200 就是出错。
     *
     * @param array<string,mixed> $data
     */
    private function isOk(array $data): bool
    {
        if (!isset($data['status'])) {
            return true;
        }

        return (int) $data['status'] === 200;
    }

    /**
     * 有些接口把内容包在 {"status":200,"data":"..."} 里，先剥一层。
     */
    private function unwrap(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['data']) && is_string($decoded['data'])) {
            return $decoded['data'];
        }

        return $body;
    }

    /**
     * 面板返回的时间可能是 JS 的 Date 字符串，也可能是 unix 时间戳。
     *
     * @param mixed $value
     */
    private function parseTime($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts === false ? 0 : $ts;
        }

        return 0;
    }

    /**
     * 拼接接口地址并统一带上 apikey。
     *
     * @param array<string,string> $query
     */
    private function baseUrl(string $path, array $query = []): string
    {
        $base = rtrim((string) $this->cfg('api_url'), '/');
        $query['apikey'] = (string) $this->cfg('api_key');

        return $base . $path . '?' . http_build_query($query);
    }
}
