<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 邮件发送。两条通路，按配置自动选：
 *
 *   smtp   —— 登录你自己的邮箱账号（QQ / 163 / Gmail / 企业邮）投递。
 *             **VPS 上应该用这条**：绝大多数服务商封了出网 25 端口，
 *             mail() 那条路根本走不通；而且 VPS 的 IP 没有 SPF/DKIM，
 *             直投基本会被判垃圾邮件。
 *
 *   mail() —— 交给本机 MTA（Postfix 之类）直连对方 25 端口投递。
 *             只适合"本机就是邮件服务器"或者"内网有 smarthost"的场景。
 *             家里/公司自建环境、25 端口没被封的情况下可以用。
 *
 * 配置在 config.php 的 email 段，后台「系统设置 → 邮件通知」里可视化填写。
 */
final class Mail
{
    /** 邮件正文里引用 Logo 用的 Content-ID（HTML 侧写 cid:mcfix-logo） */
    public const LOGO_CID = 'mcfix-logo';

    /**
     * 把管理员上传的邮件 Logo 读成内嵌图片。
     *
     * 没传过就返回空数组 —— 此时 buildMessage 走原来的 multipart/alternative，
     * 与加这个功能之前的行为完全一致。**不传 Logo 和没这个功能是一回事。**
     *
     * @return array<string,array{mime:string,data:string,name:string}>
     */
    private static function inlineImages(): array
    {
        $bytes = Brand::bytes('logo');

        return $bytes === null ? [] : [self::LOGO_CID => $bytes];
    }

    /**
     * 发送一封邮件（HTML + 纯文本双版本）。
     *
     * @param string[] $to
     * @param array<string,string> $options from / from_name / reply_to
     * @return array{ok:bool,transport:string,error:string,transcript:array<int,string>}
     */
    public static function send(array $to, string $subject, string $html, string $text = '', array $options = []): array
    {
        $to = array_values(array_filter(array_map('trim', $to)));
        if (!$to) {
            return ['ok' => false, 'transport' => 'none', 'error' => '没有收件人', 'transcript' => []];
        }

        $fromName = (string) ($options['from_name'] ?? (string) Config::get('app.name', 'MCFix'));
        $from = trim((string) ($options['from'] ?? ''));
        if ($from === '') {
            $host = (string) parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST);
            $from = 'no-reply@' . ($host !== '' ? $host : 'localhost');
        }

        // 邮件 Logo：管理员没传过就是空数组，行为与以前完全一致
        $inline = self::inlineImages();

        $message = self::buildMessage($to, $subject, $html, $text, $from, $fromName, (string) ($options['reply_to'] ?? ''), $inline);

        // ---- 优先 SMTP
        $smtp = self::smtpSettings();
        if (Smtp::configured($smtp)) {
            // QQ / 163 要求发件地址与登录账号一致，不一致时直接按账号来，
            // 否则会被 550 拒掉（用户看到的是"莫名其妙被拒"）
            $smtpFrom = trim((string) $smtp['from']) !== '' ? trim((string) $smtp['from']) : $from;
            if (!empty($smtp['force_from_username'])) {
                $smtpFrom = trim((string) $smtp['username']);
            }
            if ($smtpFrom !== $from) {
                $message = self::buildMessage($to, $subject, $html, $text, $smtpFrom, $fromName, (string) ($options['reply_to'] ?? ''), $inline);
            }

            $smtp['from'] = $smtpFrom;
            $result = Smtp::send($to, $subject, $message, $smtpFrom, $smtp);

            return [
                'ok'         => !empty($result['ok']),
                'transport'  => 'smtp',
                'error'      => (string) ($result['error'] ?? ''),
                'transcript' => (array) ($result['transcript'] ?? []),
            ];
        }

        // ---- 回退 mail()
        return self::sendViaMailFunction($to, $message, $from);
    }

    /**
     * 邮件模块的 SMTP 配置。
     *
     * @return array<string,mixed>
     */
    public static function smtpSettings(): array
    {
        $email = Config::get('email', []);
        $smtp = is_array($email) && is_array($email['smtp'] ?? null) ? $email['smtp'] : [];

        return array_merge([
            'enabled'            => false,
            'host'               => '',
            'port'               => 465,
            'encryption'         => 'ssl',   // ssl(465) | tls(587 STARTTLS) | none
            'username'           => '',
            'password'           => '',
            'from'               => '',
            'force_from_username'=> true,
        ], $smtp);
    }

    /**
     * 组装 MIME 邮件正文（SMTP 和 mail() 共用同一份，避免两边格式不一致）。
     *
     * 带内嵌图片时结构会多包一层 multipart/related：
     *
     *   multipart/related
     *     ├─ multipart/alternative   ← 正文（纯文本 + HTML）
     *     └─ image/*  Content-ID: <mcfix-logo>   ← 邮件里的 Logo
     *
     * 为什么用 Content-ID 内嵌而不是放一个远程图片地址：
     * 绝大多数邮件客户端**默认不加载远程图片**（防跟踪），收件人看到的是一个
     * 灰色占位框，还得手动点"显示图片"。内嵌的图是邮件本体的一部分，
     * 不触发这个限制，打开就有。
     *
     * @param string[] $to
     * @param array<string,array{mime:string,data:string,name:string}> $inline 以 Content-ID 为键
     */
    public static function buildMessage(
        array $to,
        string $subject,
        string $html,
        string $text,
        string $from,
        string $fromName,
        string $replyTo = '',
        array $inline = []
    ): string {
        $altBoundary = 'mcfix-alt-' . bin2hex(random_bytes(8));

        $alternative = "--{$altBoundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text !== '' ? $text : strip_tags($html)), 76, "\r\n")
            . "\r\n--{$altBoundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . "\r\n--{$altBoundary}--";

        if (!$inline) {
            $headers = [
                'Date: ' . date('r'),
                'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
                'To: ' . implode(', ', $to),
                'Subject: ' . self::encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"',
                'X-Mailer: MCFix/' . MCFIX_VERSION,
            ];
            if ($replyTo !== '') {
                $headers[] = 'Reply-To: ' . $replyTo;
            }

            return implode("\r\n", $headers) . "\r\n\r\n" . $alternative;
        }

        $relBoundary = 'mcfix-rel-' . bin2hex(random_bytes(8));
        $body = "--{$relBoundary}\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n"
            . $alternative;

        foreach ($inline as $cid => $img) {
            $body .= "\r\n--{$relBoundary}\r\n"
                . 'Content-Type: ' . $img['mime'] . '; name="' . $img['name'] . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-ID: <' . $cid . ">\r\n"
                . 'Content-Disposition: inline; filename="' . $img['name'] . "\"\r\n\r\n"
                . chunk_split(base64_encode($img['data']), 76, "\r\n");
        }
        $body .= "\r\n--{$relBoundary}--";

        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; boundary="' . $relBoundary . '"',
            'X-Mailer: MCFix/' . MCFIX_VERSION,
        ];
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * mail() 通路。保留给"本机就是 MTA"的场景。
     *
     * @param string[] $to
     * @return array{ok:bool,transport:string,error:string,transcript:array<int,string>}
     */
    private static function sendViaMailFunction(array $to, string $message, string $from): array
    {
        $transcript = ['mail() → ' . implode(', ', $to) . '（from ' . $from . '）'];

        if (!function_exists('mail')) {
            return [
                'ok'         => false,
                'transport'  => 'mail',
                'error'      => 'PHP 的 mail() 不可用（可能被禁用）。建议改用 SMTP：填一个邮箱账号 + 授权码即可。',
                'transcript' => $transcript,
            ];
        }

        // 把完整 MIME 拆成"头"和"体"给 mail()（它自己不加 Date/To/Subject）
        $split = strpos($message, "\r\n\r\n");
        $headers = $split !== false ? substr($message, 0, $split) : $message;
        $body = $split !== false ? substr($message, $split + 4) : '';

        // Subject 单独传给 mail()，从头部摘掉，避免重复
        $subject = '';
        if (preg_match('/^Subject: (.*)$/m', $headers, $m)) {
            $subject = $m[1];
            $headers = (string) preg_replace('/^Subject: .*\r\n/m', '', $headers);
        }

        $sent = false;
        $error = '';
        try {
            $sent = @mail(implode(', ', $to), $subject, $body, $headers, '-f' . $from);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        if (!$sent) {
            try {
                $sent = @mail(implode(', ', $to), $subject, $body, $headers);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if (!$sent) {
            return [
                'ok'         => false,
                'transport'  => 'mail',
                'error'      => 'mail() 返回失败。'
                    . '注意：VPS 上这条路基本走不通 —— 绝大多数服务商封了出网 25 端口，'
                    . '装了 Postfix 也只会把邮件堆在队列里。'
                    . '**请改用 SMTP**：后台「系统设置 → 邮件通知」里填一个邮箱账号和它的授权码。'
                    . ($error !== '' ? '（' . $error . '）' : ''),
                'transcript' => $transcript,
            ];
        }

        $transcript[] = 'mail() 已投递（是否真的送达要看本机 MTA 的队列）';

        return ['ok' => true, 'transport' => 'mail', 'error' => '', 'transcript' => $transcript];
    }

    /**
     * 邮件头里出现中文时需要 MIME 编码，否则会被当成垃圾或乱码。
     *
     * 同时必须把裸换行清掉：值里只要有 CR/LF，外部输入就能往头块里插进
     * 任意一行（伪造 Reply-To、加自定义头、拆 MIME 结构……）。
     * 玩家提交的标题会一路流到这里，而提交接口是匿名可用的，
     * 所以这是匿名可达的邮件头注入。放在这个汇聚点修，所有头都受保护。
     */
    private static function encodeHeader(string $value): string
    {
        $value = trim((string) preg_replace('/[\r\n\t\x00]+/', ' ', $value));

        if (preg_match('/[\x80-\xFF]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
