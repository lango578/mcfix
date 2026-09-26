<?php

declare(strict_types=1);

namespace MCFix\Channel;

use MCFix\Mail;

/**
 * 邮件通知渠道（把事件推到指定邮箱）。
 *
 * 实际发信走 src/Mail.php：配了 SMTP 就用 SMTP，没配就回退 PHP 的 mail()。
 *
 *   ⚠️ VPS 上请务必先配 SMTP（后台「系统设置 → 邮件通知 → 发送方式」）。
 *   绝大多数服务商封了出网 25 端口，mail() 那条路根本发不出去。
 *
 * 配置（本渠道）：
 *   to       = 收件人，多个用逗号分隔
 *   from     = 发件人地址（SMTP 模式下会被自动对齐到登录账号，QQ/163 要求一致）
 *   from_name= 发件人显示名
 *
 * 如果你只是想要"有新工单提醒我"和"给玩家发结果"，**不用建这个渠道** ——
 * 后台「系统设置 → 邮件通知」里勾一下就行，那个更省事。这个渠道是给
 * "某个事件要单独发到某个邮箱"这种场景用的。
 */
final class MailChannel extends ChannelAdapter
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return '邮件';
    }

    public function requiredFields(): array
    {
        return ['to' => '收件人邮箱'];
    }

    public function send(array $message): array
    {
        $to = (string) $this->cfg('to');
        if ($to === '') {
            return $this->err('未配置收件人');
        }

        $recipients = [];
        foreach (preg_split('/[,;\s]+/', $to) ?: [] as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (!$this->looksLikeEmail($address)) {
                return $this->err('收件人邮箱格式不对：' . $address);
            }
            $recipients[] = $address;
        }

        if (!$recipients) {
            return $this->err('没有有效的收件人');
        }

        $subject = (string) ($message['title'] ?? 'MCFix 通知');
        $html = $this->renderHtml($message);
        $text = $this->fullText($message);

        $result = Mail::send($recipients, $subject, $html, $text, [
            'from'      => (string) $this->cfg('from'),
            'from_name' => (string) $this->cfg('from_name', 'MCFix 故障反馈系统'),
        ]);

        if (empty($result['ok'])) {
            return $this->err('邮件发送失败：' . (string) $result['error'], ['transcript' => $result['transcript'] ?? []]);
        }

        return $this->ok(['recipients' => $recipients, 'transport' => $result['transport'] ?? 'mail']);
    }

    /**
     * 把 markdown 粗转换成一封像样的 HTML 邮件。
     *
     * @param array<string,mixed> $message
     */
    private function renderHtml(array $message): string
    {
        $title = htmlspecialchars((string) ($message['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lines = explode("\n", $this->body($message));
        $html = [];

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $html[] = '<div style="height:8px"></div>';
                continue;
            }

            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            // **粗体**
            $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
            // `代码`
            $escaped = preg_replace('/`(.+?)`/u', '<code style="background:#f2f4f7;padding:1px 5px;border-radius:4px">$1</code>', $escaped) ?? $escaped;
            // > 引用
            if (strpos($line, '> ') === 0) {
                $html[] = '<blockquote style="margin:6px 0;padding:8px 12px;border-left:3px solid #4ade80;background:#f7fdf9;color:#334">'
                    . preg_replace('/^&gt;\s?/', '', $escaped) . '</blockquote>';
                continue;
            }
            // - 列表
            if (preg_match('/^[-*]\s+/', $line)) {
                $html[] = '<div style="margin:3px 0 3px 12px">• ' . preg_replace('/^[-*]\s+/', '', $escaped) . '</div>';
                continue;
            }
            // 水平线 / 时间戳
            if (preg_match('/^_?(.{0,40})_$/', $line) && strpos($line, '20') !== false) {
                $html[] = '<div style="color:#8a94a6;font-size:12px;margin-top:10px">' . trim($escaped, '_') . '</div>';
                continue;
            }

            $html[] = '<div style="margin:4px 0">' . $escaped . '</div>';
        }

        $link = !empty($message['link'])
            ? '<p style="margin:18px 0 0"><a href="' . htmlspecialchars((string) $message['link'], ENT_QUOTES, 'UTF-8')
                . '" style="display:inline-block;padding:9px 18px;background:#22c55e;color:#fff;border-radius:8px;text-decoration:none">查看详情</a></p>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:22px;background:#f6f8fa;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#1f2933">'
            . '<div style="max-width:620px;margin:0 auto;background:#fff;border-radius:12px;padding:22px 24px;box-shadow:0 2px 12px rgba(16,24,40,.06)">'
            . '<h2 style="margin:0 0 14px;font-size:18px;color:#0f172a">' . $title . '</h2>'
            . implode("\n", $html)
            . $link
            . '</div>'
            . '<p style="text-align:center;color:#98a2b3;font-size:12px;margin:14px 0 0">MCFix 故障反馈与自动修复系统</p>'
            . '</body></html>';
    }
}
