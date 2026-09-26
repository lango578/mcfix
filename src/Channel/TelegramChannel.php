<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * Telegram Bot。
 *
 * 配置：
 *   bot_token = 找 @BotFather 要的 token
 *   chat_id   = 目标会话 ID（个人 / 群 / 频道都行，群 ID 一般是负数）
 *
 * 支持 MarkdownV2 太麻烦（转义规则苛刻），这里用 HTML 解析模式，稳定得多。
 */
final class TelegramChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram Bot';
    }

    public function requiredFields(): array
    {
        return ['bot_token' => 'Bot Token', 'chat_id' => 'Chat ID'];
    }

    public function send(array $message): array
    {
        $token = (string) $this->cfg('bot_token');
        $chatId = (string) $this->cfg('chat_id');

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

        $text = $this->toHtml($message);
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $this->clamp($text, 4000),
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (!empty($message['link'])) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [[['text' => '查看详情', 'url' => (string) $message['link']]]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->http('POST', $url, [], $payload);
        if (!$response['ok']) {
            return $this->err('请求 Telegram 失败：' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']));
        }

        $judge = $this->judgeJson($response['body']);
        if (!$judge['ok']) {
            $hint = strpos($judge['error'], 'chat not found') !== false
                ? '（提示：chat_id 不对，或者机器人还没被拉进那个群）'
                : '';

            return $this->err($judge['error'] . $hint, ['body' => mb_substr($response['body'], 0, 300)]);
        }

        return $this->ok(['status' => $response['status']]);
    }

    /**
     * markdown → Telegram 支持的 HTML 子集。
     *
     * @param array<string,mixed> $message
     */
    private function toHtml(array $message): string
    {
        $title = htmlspecialchars((string) ($message['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lines = explode("\n", $this->body($message));
        $out = ['<b>' . $title . '</b>', ''];

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $out[] = '';
                continue;
            }

            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $escaped) ?? $escaped;
            $escaped = preg_replace('/`(.+?)`/u', '<code>$1</code>', $escaped) ?? $escaped;

            if (strpos($line, '> ') === 0) {
                $out[] = '<blockquote>' . preg_replace('/^&gt;\s?/', '', $escaped) . '</blockquote>';
                continue;
            }
            if (preg_match('/^[-*]\s+/', $line)) {
                $out[] = '• ' . preg_replace('/^[-*]\s+/', '', $escaped);
                continue;
            }

            $out[] = $escaped;
        }

        return trim(implode("\n", $out));
    }
}
