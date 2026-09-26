<?php

declare(strict_types=1);

namespace MCFix\Panel;

/**
 * 自定义 HTTP 接口适配器。
 *
 * 用来对接那些"没有公开标准 API、但有个能发指令的 HTTP 接口"的租用面板
 * （简幻欢、雨云、各种自研面板的 Web 控制台），或者你自己写的转发脚本。
 *
 * 配置里用占位符描述怎么发请求，例如：
 *
 *   panel.type    = custom
 *   panel.api_url = http://127.0.0.1:8080/api/console
 *   panel.method  = POST
 *   panel.headers = {"Authorization":"Bearer xxx"}
 *   panel.body    = {"cmd":"{command}"}          # {command} 会被替换成实际游戏指令
 *   panel.power_url  = http://127.0.0.1:8080/api/power
 *   panel.power_body = {"action":"{action}"}      # {action} = start|stop|restart|kill
 *   panel.log_url    = http://127.0.0.1:8080/api/log?tail=2000
 *   panel.output_path = data.output               # 从 JSON 响应的哪个字段取回显（可留空）
 *
 * **安全说明**：这里发出去的永远是"游戏指令"或"开关机信号"，
 * 不会把玩家输入拼进 shell，也不支持任意文件写入。
 */
final class CustomPanel extends PanelAdapter
{
    public function label(): string
    {
        return '自定义接口';
    }

    public function capabilities(): array
    {
        $caps = [];
        if ($this->cfg('api_url') !== '') {
            $caps[] = 'console';
        }
        if ($this->cfg('power_url') !== '') {
            $caps[] = 'power';
        }
        if ($this->cfg('log_url') !== '') {
            $caps[] = 'read_log';
            $caps[] = 'stat_log';
        }
        if ($this->cfg('dir_url') !== '') {
            $caps[] = 'list_dir';
        }
        if ($this->cfg('file_url') !== '') {
            $caps[] = 'read_file';
        }

        return $caps;
    }

    public function test(): array
    {
        $caps = $this->capabilities();
        if (!$caps) {
            return [
                'ok'      => false,
                'message' => '至少要配置 api_url / power_url / log_url 中的一个',
                'detail'  => [],
            ];
        }

        $detail = ['capabilities' => $caps];
        $message = '自定义接口已配置，支持：' . implode('、', $caps);

        // 有日志接口就顺手读一下，验证真的能拿到内容
        if (in_array('read_log', $caps, true)) {
            $log = $this->readLog(4096);
            if (empty($log['ok'])) {
                return [
                    'ok'      => false,
                    'message' => '日志接口不通：' . $log['error'],
                    'detail'  => $detail + ['transcript' => $this->transcript()],
                ];
            }
            $message .= '；日志接口正常（拿到 ' . strlen($log['content']) . ' 字节）';
            $detail['log_sample'] = mb_substr($log['content'], -200);
        }

        return ['ok' => true, 'message' => $message, 'detail' => $detail + ['transcript' => $this->transcript()]];
    }

    public function sendCommand(string $command): array
    {
        $url = (string) $this->cfg('api_url');
        if ($url === '') {
            return $this->fail('未配置 api_url');
        }

        $response = $this->request($url, ['{command}' => $command]);
        if (!$response['ok']) {
            return $this->fail('指令下发失败：' . $response['error']);
        }

        $output = $this->extractOutput($response['body']);

        return $this->done($output !== '' ? $output : '已投递（接口未返回回显）');
    }

    /**
     * 只有**明确配置了 output_path** 才算"能拿回可解析的回显"。
     *
     * 为什么这么严：`extractOutput()` 在没配 output_path 时会去猜常见字段
     * （output/data/result/message/…）。猜出来的东西用来判断"指令发出去了吗"
     * 勉强可以，但拿它当 tps / list 的回显去解析就不行了 —— 字段名撞上
     * `message` 之类的，多半取回的是"success"这类状态文案而不是控制台输出。
     *
     * output_path 是管理员对着自己的接口写的明确声明："回显就在这个字段里"。
     * 有这句话，才允许把面板控制台用于需要解析输出的验证检查。
     */
    public function commandEcho(): bool
    {
        return trim((string) $this->cfg('output_path', '')) !== '';
    }

    public function power(string $action): array
    {
        $url = (string) $this->cfg('power_url');
        if ($url === '') {
            return $this->fail('未配置 power_url');
        }

        $map = ['start' => 'start', 'stop' => 'stop', 'restart' => 'restart', 'kill' => 'kill'];
        if (!isset($map[$action])) {
            return $this->fail('不支持的电源动作：' . $action);
        }

        $response = $this->request($url, ['{action}' => $map[$action]]);
        if (!$response['ok']) {
            return $this->fail($action . ' 失败：' . $response['error']);
        }

        return $this->done($this->extractOutput($response['body']) ?: ('已下发 ' . $action));
    }

    public function readLog(int $maxBytes = 262144): array
    {
        $url = (string) $this->cfg('log_url');
        if ($url === '') {
            return ['ok' => false, 'content' => '', 'size' => 0, 'mtime' => 0, 'error' => '未配置 log_url'];
        }

        $response = $this->request($url, [], 'GET');
        if (!$response['ok']) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'mtime' => 0, 'error' => '读日志失败：' . $response['error']];
        }

        // 优先从 JSON 字段里取，取不到就把整个响应体当日志
        $content = $this->extractOutput($response['body']);
        if ($content === '') {
            $content = $response['body'];
        }

        $size = strlen($content);
        if ($size > $maxBytes) {
            $content = substr($content, -$maxBytes);
        }

        return ['ok' => true, 'content' => $content, 'size' => $size, 'mtime' => time(), 'error' => ''];
    }

    public function listDir(string $path): array
    {
        $url = (string) $this->cfg('dir_url');
        if ($url === '') {
            return ['ok' => false, 'files' => [], 'error' => '未配置 dir_url'];
        }

        $response = $this->request($url, ['{path}' => $path], 'GET');
        if (!$response['ok']) {
            return ['ok' => false, 'files' => [], 'error' => '列目录失败：' . $response['error']];
        }

        $decoded = json_decode($response['body'], true);
        $items = $decoded;
        $pathHint = (string) $this->cfg('dir_path', '');
        if ($pathHint !== '' && is_array($decoded)) {
            foreach (explode('.', $pathHint) as $segment) {
                if (is_array($decoded) && isset($decoded[$segment])) {
                    $decoded = $decoded[$segment];
                }
            }
            $items = $decoded;
        }

        $files = [];
        foreach ((array) $items as $item) {
            if (is_string($item)) {
                $files[] = ['name' => $item, 'size' => 0, 'mtime' => 0, 'dir' => false];
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? $item['file'] ?? '');
            if ($name === '') {
                continue;
            }
            $files[] = [
                'name'  => $name,
                'size'  => (int) ($item['size'] ?? 0),
                'mtime' => (int) ($item['mtime'] ?? $item['time'] ?? 0),
                'dir'   => !empty($item['dir']) || !empty($item['isDir']),
            ];
        }

        return ['ok' => true, 'files' => $files, 'error' => ''];
    }

    public function readFile(string $path, int $maxBytes = 65536): array
    {
        $url = (string) $this->cfg('file_url');
        if ($url === '') {
            return ['ok' => false, 'content' => '', 'error' => '未配置 file_url'];
        }

        $response = $this->request($url, ['{path}' => $path], 'GET');
        if (!$response['ok']) {
            return ['ok' => false, 'content' => '', 'error' => '读文件失败：' . $response['error']];
        }

        $content = $this->extractOutput($response['body']);
        if ($content === '') {
            $content = $response['body'];
        }

        return ['ok' => true, 'content' => substr($content, 0, $maxBytes), 'error' => ''];
    }

    /**
     * 按配置发请求，替换占位符。
     *
     * @param array<string,string> $replacements
     * @return array{ok:bool,body:string,error:string,status:int}
     */
    private function request(string $url, array $replacements, string $defaultMethod = ''): array
    {
        $url = str_replace(array_keys($replacements), array_map('rawurlencode', array_values($replacements)), $url);

        $method = strtoupper($defaultMethod !== '' ? $defaultMethod : (string) $this->cfg('method', 'POST'));
        $headers = $this->parseHeaders((string) $this->cfg('headers', ''));

        $bodyTemplate = (string) $this->cfg('body_template', (string) $this->cfg('body', ''));
        $body = null;
        if ($bodyTemplate !== '' && $method !== 'GET') {
            $raw = str_replace(array_keys($replacements), array_values($replacements), $bodyTemplate);
            $raw = str_replace(['{command}', '{action}', '{path}'], '', $raw);
            // 先按 JSON 校验，不是 JSON 就原样发
            json_decode($raw, true);
            $body = trim($raw) === '' ? null : $raw;
            if ($body !== null && json_last_error() !== JSON_ERROR_NONE && json_decode($raw, true) === null) {
                $body = $raw;
            }
        }

        // URL 里也可能带占位符（GET 场景）
        foreach ($replacements as $placeholder => $value) {
            $url = str_replace(rawurlencode($placeholder), rawurlencode($value), $url);
            $url = str_replace($placeholder, rawurlencode($value), $url);
        }

        $response = $this->http($method, $url, $headers, $body);

        return [
            'ok'     => $response['ok'],
            'body'   => $response['body'],
            'error'  => $response['error'] !== '' ? $response['error'] : ('HTTP ' . $response['status']),
            'status' => (int) $response['status'],
        ];
    }

    /**
     * 从响应里取"回显"，支持 a.b.c 形式的路径。
     */
    private function extractOutput(string $body): string
    {
        $path = trim((string) $this->cfg('output_path', ''));
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        if ($path === '') {
            // 没配置就猜几个常见字段
            foreach (['output', 'data', 'result', 'message', 'msg', 'log', 'content'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }

            return '';
        }

        $node = $decoded;
        foreach (explode('.', $path) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }

            return '';
        }

        return is_string($node) ? $node : (is_scalar($node) ? (string) $node : '');
    }

    /**
     * 解析 headers 配置：支持 JSON 或 "K: V" 逐行的写法。
     *
     * @return array<string,string>
     */
    private function parseHeaders(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $headers = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key) && (is_string($value) || is_numeric($value))) {
                    $headers[$key] = (string) $value;
                }
            }

            return $headers;
        }

        $headers = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $headers[$key] = trim($value);
            }
        }

        return $headers;
    }
}
