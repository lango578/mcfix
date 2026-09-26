<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * Bark（iOS 推送，自建或官方都支持）。
 *
 * 配置：
 *   server_url = https://api.day.app 或你的自建地址
 *   device_key = App 里那串 key
 *   group      = 分组名（可选，通知会归到一组）
 *   sound      = 提示音（可选，如 alarm）
 */
final class BarkChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'bark';
    }

    public function label(): string
    {
        return 'Bark（iOS 推送）';
    }

    public function requiredFields(): array
    {
        return ['device_key' => 'Device Key'];
    }

    public function send(array $message): array
    {
        $base = rtrim((string) $this->cfg('server_url', 'https://api.day.app'), '/');
        $key = (string) $this->cfg('device_key');

        $payload = [
            'title' => (string) ($message['title'] ?? 'MCFix 通知'),
            'body'  => $this->clamp($this->body($message), 900),
        ];

        if (!empty($message['link'])) {
            $payload['url'] = (string) $message['link'];
        }
        if ($this->cfg('group') !== '') {
            $payload['group'] = (string) $this->cfg('group');
        }
        if ($this->cfg('sound') !== '') {
            $payload['sound'] = (string) $this->cfg('sound');
        }
        // 失败类消息用时效性级别，能穿透专注模式
        if ((string) ($message['level'] ?? '') === 'warn') {
            $payload['level'] = 'timeSensitive';
        }

        $url = $base . '/' . rawurlencode($key);
        $response = $this->http('POST', $url, [], $payload);

        if (!$response['ok']) {
            return $this->err('请求 Bark 失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (is_array($decoded) && isset($decoded['code']) && (int) $decoded['code'] !== 200) {
            return $this->err('Bark 返回 code=' . (int) $decoded['code'] . '：' . (string) ($decoded['message'] ?? ''));
        }

        return $this->ok(['status' => $response['status']]);
    }
}
