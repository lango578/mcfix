<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * Discord Webhook。
 *
 * 配置：
 *   webhook = https://discord.com/api/webhooks/xxx/yyy
 *   username = 机器人显示名（可选）
 *   mention  = 需要 @ 的角色或用户 ID（可选，如 <@&123456>）
 */
final class DiscordChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'discord';
    }

    public function label(): string
    {
        return 'Discord Webhook';
    }

    public function requiredFields(): array
    {
        return ['webhook' => 'Webhook 地址'];
    }

    public function send(array $message): array
    {
        $url = (string) $this->cfg('webhook');
        if (strpos($url, 'http') !== 0) {
            return $this->err('Webhook 地址格式不对');
        }

        $level = (string) ($message['level'] ?? 'info');
        $color = $level === 'warn' ? 0xF87171 : ($level === 'ok' ? 0x4ADE80 : 0x60A5FA);

        $fields = [];
        if (!empty($message['server'])) {
            $fields[] = ['name' => '服务器', 'value' => mb_substr((string) $message['server'], 0, 200), 'inline' => true];
        }
        if (!empty($message['event'])) {
            $fields[] = ['name' => '事件', 'value' => '`' . (string) $message['event'] . '`', 'inline' => true];
        }

        $payload = [
            'username' => (string) $this->cfg('username', 'MCFix'),
            'embeds'   => [[
                'title'       => mb_substr((string) ($message['title'] ?? 'MCFix 通知'), 0, 250),
                'description' => $this->clamp($this->body($message), 3800),
                'color'       => $color,
                'url'         => (string) ($message['link'] ?? ''),
                'fields'      => $fields,
                'footer'      => ['text' => 'MCFix 故障反馈与自动修复系统'],
            ]],
        ];

        $mention = (string) $this->cfg('mention');
        if ($mention !== '' && $level === 'warn') {
            $payload['content'] = $mention;
        }

        $response = $this->http('POST', $url, [], $payload);
        if (!$response['ok']) {
            return $this->err('请求 Discord 失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        // Discord 成功返回 204；有错会返回 JSON
        if ($response['status'] === 204) {
            return $this->ok(['status' => 204]);
        }

        $decoded = json_decode($response['body'], true);
        if (is_array($decoded) && isset($decoded['code'])) {
            return $this->err('Discord 返回 code=' . (string) $decoded['code'] . '：' . (string) ($decoded['message'] ?? ''), [
                'body' => mb_substr($response['body'], 0, 300),
            ]);
        }

        return $this->ok(['status' => $response['status']]);
    }
}
