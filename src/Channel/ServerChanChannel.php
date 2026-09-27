<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * Server 酱（微信推送）。
 *
 * 配置：
 *   send_key = SCT... 或 sctp... （Server 酱 Turbo 版是 sctp 开头）
 *
 * 兼容两个版本：
 *   Turbo 版：https://sctapi.ftqq.com/<key>.send   参数 title / desp
 *   旧 版   ：https://sc.ftqq.com/<key>.send       参数 text / desp
 */
final class ServerChanChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'serverchan';
    }

    public function label(): string
    {
        return 'Server 酱（微信）';
    }

    public function requiredFields(): array
    {
        return ['send_key' => 'SendKey'];
    }

    public function send(array $message): array
    {
        $key = (string) $this->cfg('send_key');
        $title = (string) ($message['title'] ?? 'MCFix 通知');
        $desp = $this->clamp($this->body($message) . (!empty($message['link']) ? "\n\n[查看详情](" . $message['link'] . ')' : ''), 30000);

        $isTurbo = stripos($key, 'sctp') === 0 || stripos($key, 'SCT') === 0;
        $host = $isTurbo ? 'https://sctapi.ftqq.com' : 'https://sc.ftqq.com';
        $url = $host . '/' . rawurlencode($key) . '.send?';

        $payload = $isTurbo
            ? ['title' => $title, 'desp' => $desp]
            : ['text' => $title, 'desp' => $desp];

        $response = $this->http('POST', $url, [], $payload);
        if (!$response['ok']) {
            return $this->err('请求 Server 酱失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (is_array($decoded)) {
            $code = $decoded['code'] ?? $decoded['errno'] ?? 0;
            if ((int) $code !== 0) {
                return $this->err('Server 酱返回 code=' . (int) $code . '：' . (string) ($decoded['message'] ?? $decoded['errmsg'] ?? ''), [
                    'body' => mb_substr($response['body'], 0, 300),
                ]);
            }
        }

        return $this->ok(['status' => $response['status'], 'version' => $isTurbo ? 'turbo' : 'v2']);
    }
}
