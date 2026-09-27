<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * 通用 Webhook —— 对接钉钉/企微/飞书之外的任何系统。
 *
 * 适合：你自己的中转服务、n8n / Node-RED、企业内部的告警平台、短信网关、
 *       或者"想把通知落到自己数据库"的场景。
 *
 * 配置：
 *   url      = 目标地址
 *   method   = POST / PUT / GET（默认 POST）
 *   format   = json（默认）| form | text        —— 请求体格式
 *   headers  = 自定义请求头，JSON 或每行 Key: Value
 *   template = 自定义请求体模板，支持占位符：
 *                {title}    标题
 *                {text}     纯文本正文（已去掉 markdown 标记）
 *                {markdown} markdown 正文
 *                {link}     详情链接
 *                {event}    事件类型，如 auto_fixed
 *                {level}    info / ok / warn
 *             留空则用内置的 JSON 结构。
 */
final class WebhookChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return '通用 Webhook';
    }

    public function requiredFields(): array
    {
        return ['url' => '目标地址'];
    }

    /**
     * 把一个值转义成"能安全塞进模板"的形态。
     *
     * format=json 时用 json_encode 的转义（去掉它自己加的两个引号）——
     * 换行、制表符、控制字符都会变成 \n \t \u00xx，模板不会被撑破。
     * 其它格式维持原来的引号/反斜杠转义。
     *
     * 单独抽出来是为了能被测试直接调用：这条路径原来只做引号转义，
     * 结果玩家留言里一个普通换行就让管理员的自定义 webhook 发出非法 JSON（实测）。
     */
    public static function escapeForTemplate(string $value, string $format): string
    {
        if (strtolower($format) === 'json') {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) && strlen($encoded) >= 2 ? substr($encoded, 1, -1) : '';
        }

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public function send(array $message): array
    {
        $url = (string) $this->cfg('url');
        if (strpos($url, 'http') !== 0) {
            return $this->err('目标地址格式不对');
        }

        $method = strtoupper((string) $this->cfg('method', 'POST'));
        $format = (string) $this->cfg('format', 'json');
        $headers = $this->parseHeaders((string) $this->cfg('headers', ''));

        $vars = [
            '{title}'    => (string) ($message['title'] ?? ''),
            '{text}'     => (string) ($message['text'] ?? ''),
            '{markdown}' => (string) ($message['markdown'] ?? ''),
            '{link}'     => (string) ($message['link'] ?? ''),
            '{event}'    => (string) ($message['event'] ?? ''),
            '{level}'    => (string) ($message['level'] ?? 'info'),
            '{server}'   => (string) ($message['server'] ?? ''),
        ];

        $template = trim((string) $this->cfg('template', ''));
        if ($template !== '') {
            /*
             * ★ 模板替换要按"模板是什么格式"来转义（1.13.4 实测修掉）。
             *
             * 原来只做 str_replace(['\\','"'], ['\\\\','\\"'], $v) —— 那套只对
             * JSON 里的**引号/反斜杠**有效。而消息正文里带着玩家的原话，
             * 换行是家常便饭（"服务器卡了\n进不去"），JSON 字符串里裸换行非法：
             * 实测 admin 的模板 {"msg":"{title}\n{text}"} 配上一段带换行的留言，
             * 产出的 body json_decode 直接报 "Control character error"。
             * 管理员的自定义 webhook 会因此静默失效 —— 而且不需要恶意，
             * 正常玩家多行描述就会触发。
             */
            $body = str_replace(array_keys($vars), array_map(function ($v) use ($format): string {
                return self::escapeForTemplate((string) $v, $format);
            }, array_values($vars)), $template);
        } elseif ($format === 'text') {
            $body = $this->fullText($message);
        } elseif ($format === 'form') {
            $body = http_build_query([
                'title'    => (string) ($message['title'] ?? ''),
                'text'     => (string) ($message['text'] ?? ''),
                'markdown' => (string) ($message['markdown'] ?? ''),
                'link'     => (string) ($message['link'] ?? ''),
                'event'    => (string) ($message['event'] ?? ''),
                'level'    => (string) ($message['level'] ?? 'info'),
                'server'   => (string) ($message['server'] ?? ''),
            ]);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } else {
            $body = (string) json_encode([
                'title'    => (string) ($message['title'] ?? ''),
                'text'     => (string) ($message['text'] ?? ''),
                'markdown' => (string) ($message['markdown'] ?? ''),
                'link'     => (string) ($message['link'] ?? ''),
                'event'    => (string) ($message['event'] ?? ''),
                'level'    => (string) ($message['level'] ?? 'info'),
                'server'   => (string) ($message['server'] ?? ''),
                'at'       => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($method === 'GET') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query([
                'title' => (string) ($message['title'] ?? ''),
                'text'  => mb_substr((string) ($message['text'] ?? ''), 0, 1500),
                'level' => (string) ($message['level'] ?? 'info'),
            ]);
            $body = null;
        }

        $response = $this->http($method, $url, $headers, $body);
        if (!$response['ok']) {
            return $this->err('Webhook 请求失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']), [
                'status' => $response['status'],
                'body'   => mb_substr($response['body'], 0, 300),
            ]);
        }

        // 有些中转服务喜欢用 200 + {"code":1} 表示失败，这里宽松判断
        $judge = $this->judgeJson($response['body']);
        if (!$judge['ok']) {
            return $this->err($judge['error'], ['body' => mb_substr($response['body'], 0, 300)]);
        }

        return $this->ok(['status' => $response['status']]);
    }

    /**
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
