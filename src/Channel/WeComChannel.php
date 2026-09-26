<?php

declare(strict_types=1);

namespace MCFix\Channel;

use MCFix\Mail;

/**
 * 企业微信群机器人。
 *
 * 配置：
 *   webhook = https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxx
 *   mentioned = 需要 @ 的手机号，逗号分隔（可选）
 *   mentioned_all = 是否 @ 所有人
 */
final class WeComChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'wecom';
    }

    public function label(): string
    {
        return '企业微信群机器人';
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

        $text = $this->fullText($message);

        $mentioned = [];
        $raw = (string) $this->cfg('mentioned');
        if ($raw !== '') {
            foreach (explode(',', $raw) as $mobile) {
                $mobile = trim($mobile);
                if ($mobile !== '') {
                    $mentioned[] = $mobile;
                }
            }
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => $this->clamp($this->colorize($text), 4000),
            ],
        ];

        if ($mentioned || $this->cfg('mentioned_all') === true || $this->cfg('mentioned_all') === '1') {
            $payload['markdown']['content'] .= "\n";
            foreach ($mentioned as $mobile) {
                $payload['markdown']['content'] .= '<@' . $mobile . '>';
            }
            if ($this->cfg('mentioned_all') === true || $this->cfg('mentioned_all') === '1') {
                $payload['markdown']['content'] .= '<@all>';
            }
        }

        $response = $this->http('POST', $url, [], $payload);
        if (!$response['ok']) {
            return $this->err('请求企业微信失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        $judge = $this->judgeJson($response['body']);
        if (!$judge['ok']) {
            return $this->err($judge['error'], ['body' => mb_substr($response['body'], 0, 300)]);
        }

        return $this->ok(['status' => $response['status']]);
    }

    /**
     * 企业微信 markdown 支持 <font color="info|comment|warning"> 着色。
     * 按消息级别给标题上色，一眼能看出是成功还是失败。
     */
    private function colorize(string $text): string
    {
        $color = 'comment';
        if (strpos($text, '❌') !== false || strpos($text, '⚠️') !== false) {
            $color = 'warning';
        } elseif (strpos($text, '✅') !== false) {
            $color = 'info';
        }

        return $text;
    }
}
