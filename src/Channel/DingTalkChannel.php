<?php

declare(strict_types=1);

namespace MCFix\Channel;

use MCFix\Mail;

/**
 * 钉钉群机器人。
 *
 * 配置：
 *   webhook  = https://oapi.dingtalk.com/robot/send?access_token=xxx
 *   secret   = 加签密钥（群机器人安全设置里选"加签"时填，强烈建议）
 *   at_mobile= 需要 @ 的手机号，逗号分隔（可选）
 *
 * 注意：钉钉对"关键词"安全设置有要求，默认标题里带"MCFix"字样，可以在群里把关键词设为 MCFix。
 */
final class DingTalkChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'dingtalk';
    }

    public function label(): string
    {
        return '钉钉群机器人';
    }

    public function requiredFields(): array
    {
        return ['webhook' => 'Webhook 地址'];
    }

    public function send(array $message): array
    {
        $webhook = (string) $this->cfg('webhook');
        if (strpos($webhook, 'http') !== 0) {
            return $this->err('Webhook 地址格式不对，应该以 http(s):// 开头');
        }

        $url = $webhook;

        // 加签：timestamp + HMAC-SHA256(secret)
        $secret = (string) $this->cfg('secret');
        if ($secret !== '') {
            $timestamp = (string) (int) (microtime(true) * 1000);
            $sign = rawurlencode(base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true)));
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'timestamp=' . $timestamp . '&sign=' . $sign;
        }

        $title = (string) ($message['title'] ?? 'MCFix 通知');
        $text = $this->fullText($message);

        $atMobiles = [];
        $atRaw = (string) $this->cfg('at_mobile');
        if ($atRaw !== '') {
            foreach (explode(',', $atRaw) as $mobile) {
                $mobile = trim($mobile);
                if ($mobile !== '') {
                    $atMobiles[] = $mobile;
                }
            }
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'title' => $title,
                'text'  => $this->clamp($text, 4000),
            ],
            'at' => [
                'atMobiles' => $atMobiles,
                'isAtAll'   => (bool) $this->cfg('at_all', false),
            ],
        ];

        $response = $this->http('POST', $url, [], $payload);
        if (!$response['ok']) {
            return $this->err('请求钉钉失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']), [
                'status' => $response['status'],
            ]);
        }

        $judge = $this->judgeJson($response['body']);
        if (!$judge['ok']) {
            $hint = '';
            if (strpos($judge['error'], '310000') !== false) {
                $hint = '（提示：钉钉机器人的"关键词"安全设置里需要包含 MCFix，或者改用"加签"方式）';
            }
            if (strpos($judge['error'], 'sign') !== false || strpos($judge['error'], '310') !== false) {
                $hint = $hint !== '' ? $hint : '（提示：检查加签密钥是否正确、服务器时间是否准确）';
            }

            return $this->err($judge['error'] . $hint, ['body' => mb_substr($response['body'], 0, 300)]);
        }

        return $this->ok(['status' => $response['status']]);
    }
}
