<?php

declare(strict_types=1);

namespace MCFix\Panel;

/**
 * 宝塔面板适配器 —— 适用于「MC 直接跑在宝塔这台机器上」的场景。
 *
 * 宝塔没有"游戏指令"这类接口，它能做的是**进程管理**与**文件读取**，刚好补上面板最缺的两块：
 *   - 服务端进程在不在、启停重启
 *   - 读 latest.log（诊断日志用）、列 mods 目录（MOD 清单用）
 *
 * 游戏指令（白名单/解封）仍然走 RCON —— 所以宝塔 + RCON 是这台机器上的最佳组合。
 *
 * 配置：
 *   panel.type      = bt
 *   panel.api_url   = 宝塔地址，例如 https://127.0.0.1:8888
 *   panel.api_key   = 宝塔【设置 → API 接口】里的密钥
 *   panel.process_id= 进程守护管理器里那个 MC 项目的 ID（列表里能看?）
 *   panel.mc_dir    = MC 服务端目录
 *   panel.insecure  = true（宝塔面板一般是自签证书）
 */
final class BtPanel extends PanelAdapter
{
    public function label(): string
    {
        return '宝塔面板';
    }

    public function capabilities(): array
    {
        if ($this->cfg('api_url') === '' || $this->cfg('api_key') === '') {
            return [];
        }

        $caps = ['read_log', 'stat_log', 'read_file', 'list_dir'];
        if ($this->cfg('process_id') !== '' || $this->cfg('service') !== '') {
            $caps[] = 'power';
        }

        return $caps;
    }

    public function test(): array
    {
        // 先看系统状态（不需要额外参数，用来验证签名对不对）
        $result = $this->call('/system?action=GetSystemTotal');

        if (!$result['ok']) {
            return [
                'ok'      => false,
                'message' => '宝塔接口连接失败：' . $result['error'] . '（检查 api_key、api_url、以及是否把面板 IP 加进了 API 白名单）',
                'detail'  => [
                    // 宝塔的 api_url 可能是 /api/<32位密钥>/ 这种形状，
                    // 直接回显等于把密钥写进事件表的 payload 里。
                    'url'        => mask_secret_url((string) $this->cfg('api_url')),
                    'transcript' => $this->transcript(),
                ],
            ];
        }

        $data = (array) ($result['data'] ?? []);
        $version = (string) ($data['version'] ?? '');

        return [
            'ok'      => true,
            'message' => '宝塔接口连接成功' . ($version !== '' ? '（面板版本 ' . $version . '）' : '')
                . '，支持：' . implode('、', $this->capabilities())
                . (in_array('power', $this->capabilities(), true) ? '' : '；未配置进程 ID，无法启停'),
            'detail'  => [
                'capabilities' => $this->capabilities(),
                'process_id'   => (string) $this->cfg('process_id'),
                'transcript'   => $this->transcript(),
            ],
        ];
    }

    /**
     * 宝塔不支持游戏指令，这里明确拒绝，让上层落到 RCON 或 Agent。
     */
    public function sendCommand(string $command): array
    {
        return $this->fail('宝塔面板没有控制台接口，游戏指令请走 RCON（在 server.properties 里开启 enable-rcon）或部署 Agent');
    }

    public function power(string $action): array
    {
        $map = ['start' => 'start', 'stop' => 'stop', 'restart' => 'restart', 'kill' => 'stop'];
        if (!isset($map[$action])) {
            return $this->fail('不支持的电源动作：' . $action);
        }

        $processId = (string) $this->cfg('process_id');
        if ($processId === '') {
            return $this->fail('未配置宝塔进程守护管理器的项目 ID，无法启停');
        }

        // 进程守护管理器（project）优先：宝塔 7.x 之后 MC 一般用它托管
        $result = $this->call('/plugin?action=a&name=project&s=' . $map[$action] . '_project', [
            'project_id' => $processId,
        ]);

        if (!$result['ok']) {
            return $this->fail('宝塔 ' . $action . ' 失败：' . $result['error']);
        }

        return $this->done('已向宝塔下发布 ' . $action . '（进程守护管理器项目 ' . $processId . '）');
    }

    public function readLog(int $maxBytes = 262144): array
    {
        $path = $this->remotePath($this->logPath());
        $result = $this->call('/files?action=GetFileBody', ['path' => $path]);

        if (!$result['ok']) {
            return [
                'ok'      => false,
                'content' => '',
                'size'    => 0,
                'mtime'   => 0,
                'error'   => '读取日志失败：' . $result['error'],
            ];
        }

        $content = (string) ($result['data']['data'] ?? $result['data']['body'] ?? '');
        $size = strlen($content);
        if ($size > $maxBytes) {
            $content = substr($content, -$maxBytes);
        }

        return ['ok' => true, 'content' => $content, 'size' => $size, 'mtime' => time(), 'error' => ''];
    }

    public function statLog(): array
    {
        $path = $this->remotePath($this->logPath());
        $dir = dirname($path);
        $result = $this->call('/files?action=GetDir', ['path' => $dir, 'p' => '1', 'showRow' => '200']);

        if (!$result['ok']) {
            return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => '列目录失败：' . $result['error']];
        }

        $target = basename($path);
        foreach ((array) ($result['data'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string) ($entry['name'] ?? '') === $target) {
                return [
                    'ok'    => true,
                    'size'  => (int) ($entry['size'] ?? 0),
                    'mtime' => (int) ($entry['mtime'] ?? 0),
                    'error' => '',
                ];
            }
        }

        return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => '日志文件不存在：' . $path];
    }

    public function listDir(string $path): array
    {
        $full = $this->remotePath($path);
        $result = $this->call('/files?action=GetDir', ['path' => $full, 'p' => '1', 'showRow' => '500']);

        if (!$result['ok']) {
            return ['ok' => false, 'files' => [], 'error' => '列目录失败：' . $result['error']];
        }

        $files = [];
        foreach ((array) ($result['data'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $files[] = [
                'name'  => $name,
                'size'  => (int) ($entry['size'] ?? 0),
                'mtime' => (int) ($entry['mtime'] ?? 0),
                'dir'   => strpos((string) ($entry['type'] ?? ''), 'dir') !== false || (string) ($entry['type'] ?? '') === '0',
            ];
        }

        return ['ok' => true, 'files' => $files, 'error' => ''];
    }

    public function readFile(string $path, int $maxBytes = 65536): array
    {
        $result = $this->call('/files?action=GetFileBody', ['path' => $this->remotePath($path)]);
        if (!$result['ok']) {
            return ['ok' => false, 'content' => '', 'error' => '读文件失败：' . $result['error']];
        }

        $content = (string) ($result['data']['data'] ?? $result['data']['body'] ?? '');

        return ['ok' => true, 'content' => substr($content, 0, $maxBytes), 'error' => ''];
    }

    /**
     * 调一个宝塔接口（自动签名）。
     *
     * 签名规则（宝塔官方）：
     *   request_token = md5(时间戳 . md5(api_key))
     *   request_time  = 时间戳
     *   然后对"时间戳 + md5(参数串)"取 md5 作为签名
     * 参数为空的接口用一串固定的占位串。
     *
     * @param array<string,string> $params
     * @return array{ok:bool,data:array<string,mixed>,error:string,status:int}
     */
    private function call(string $path, array $params = []): array
    {
        $apiKey = (string) $this->cfg('api_key');
        if ($apiKey === '') {
            return ['ok' => false, 'data' => [], 'error' => '未配置宝塔 API 密钥', 'status' => 0];
        }

        $now = time();
        $token = md5($now . md5($apiKey));
        $params['request_time'] = (string) $now;
        $params['request_token'] = $token;
        $signSource = $now . md5(http_build_query($params));
        $params['request_sign'] = md5($signSource);

        $url = rtrim((string) $this->cfg('api_url'), '/') . $path;
        $result = $this->json('POST', $url, ['X-Requested-With' => 'XMLHttpRequest'], $params);

        if (!$result['ok']) {
            return $result;
        }

        // 宝塔把错误放在 data 里
        $data = (array) $result['data'];
        if (isset($data['status']) && $data['status'] === false) {
            return [
                'ok'     => false,
                'data'   => $data,
                'error'  => (string) ($data['msg'] ?? $data['message'] ?? '宝塔返回失败'),
                'status' => (int) $result['status'],
            ];
        }

        return ['ok' => true, 'data' => $data, 'error' => '', 'status' => (int) $result['status']];
    }
}
