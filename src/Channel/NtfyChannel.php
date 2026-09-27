<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * ntfy（开源推送，手机装 App 订阅一个 topic 即可，也可以自建服务端）。
 *
 * 配置：
 *   server_url = https://ntfy.sh 或你的自建地址
 *   topic      = 订阅的主题名（相当于密码，取个别人猜不到的）
 *   token      = 自建服务端开启鉴权时填（可选）
 *   priority   = 默认优先级 1-5（可选，失败类消息会自动提到 4）
 */
final class NtfyChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'ntfy';
    }

    public function label(): string
    {
        return 'ntfy（开源推送）';
    }

    public function requiredFields(): array
    {
        return ['topic' => 'Topic 主题名'];
    }

    public function send(array $message): array
    {
        $base = rtrim((string) $this->cfg('server_url', 'https://ntfy.sh'), '/');
        $topic = (string) $this->cfg('topic');

        $level = (string) ($message['level'] ?? 'info');
        $priority = (string) $this->cfg('priority', $level === 'warn' ? '4' : '3');

        $headers = [
            'Title'    => $this->asciiTitle((string) ($message['title'] ?? 'MCFix')),
            'Priority' => $priority,
            'Tags'     => $level === 'warn' ? 'warning' : ($level === 'ok' ? 'white_check_mark' : 'information_source'),
        ];

        $token = (string) $this->cfg('token');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if (!empty($message['link'])) {
            $headers['Click'] = (string) $message['link'];
        }

        // ntfy 用请求体当正文（支持 markdown，设 Markdown: yes）
        $headers['Markdown'] = 'yes';

        $body = $this->clamp($this->body($message), 3500);
        $response = $this->http('POST', $base . '/' . rawurlencode($topic), $headers, $body);

        if (!$response['ok']) {
            return $this->err('请求 ntfy 失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        return $this->ok(['status' => $response['status']]);
    }

    /**
     * ntfy 的 Title 头对非 ASCII 支持不好，中文会被截断，这里做个兜底。
     */
    private function asciiTitle(string $title): string
    {
        if (!preg_match('/[\x80-\xFF]/', $title)) {
            return $title;
        }

        return 'MCFix: ' . mb_substr(preg_replace('/[^\x20-\x7E]/', '', $title) ?? 'notification', 0, 80);
    }
}
