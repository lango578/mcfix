<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * 飞书 / Lark 群机器人。
 *
 * 配置：
 *   webhook = https://open.feishu.cn/open-apis/bot/v2/hook/xxx
 *   secret  = 签名校验密钥（机器人"签名校验"开启时填）
 *
 * 飞书的签名比钉钉多一层：把 timestamp + "\n" + secret 作为"密钥"再做 HMAC-SHA256，
 * 结果 base64 编码后放进 body 的 sign 字段（不是放 URL）。
 */
final class FeishuChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'feishu';
    }

    public function label(): string
    {
        return '飞书 / Lark 群机器人';
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

        $title = (string) ($message['title'] ?? 'MCFix 通知');
        $payload = [
            'msg_type' => 'interactive',
            'card' => [
                'config' => ['wide_screen_mode' => true],
                'header' => [
                    'title' => ['tag' => 'plain_text', 'content' => mb_substr($title, 0, 100)],
                    'template' => $this->cardColor((string) ($message['level'] ?? 'info')),
                ],
                'elements' => [
                    ['tag' => 'div', 'text' => ['tag' => 'lark_md', 'content' => $this->clamp($this->body($message), 3000)]],
                ],
            ],
        ];

        if (!empty($message['link'])) {
            $payload['card']['elements'][] = [
                'tag' => 'action',
                'actions' => [[
                    'tag'  => 'button',
                    'text' => ['tag' => 'plain_text', 'content' => '查看详情'],
                    'type' => 'primary',
                    'url'  => (string) $message['link'],
                ]],
            ];
        }

        $secret = (string) $this->cfg('secret');
        if ($secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, '', true));
        }

        $response = $this->http('POST', $url, [], $payload);
        if (!$response['ok']) {
            return $this->err('请求飞书失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        $judge = $this->judgeJson($response['body']);
        if (!$judge['ok']) {
            return $this->err($judge['error'], ['body' => mb_substr($response['body'], 0, 300)]);
        }

        return $this->ok(['status' => $response['status']]);
    }

    private function cardColor(string $level): string
    {
        switch ($level) {
            case 'ok':
                return 'green';
            case 'warn':
                return 'red';
            default:
                return 'blue';
        }
    }
}
