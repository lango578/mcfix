<?php

declare(strict_types=1);

namespace MCFix;

/**
 * SMTP 发信客户端（零依赖，手写协议）。
 *
 * ---------------------------------------------------------------------------
 * 为什么必须要有这个，而不是靠 PHP 的 mail()：
 *
 *   mail() 做的事情是"把邮件交给本机的 MTA（Postfix 之类），由它直连收件方的
 *   25 端口投递"。这条路在 VPS 上基本走不通，两个原因：
 *
 *     1. **绝大多数服务商封了出网 25 端口**（防垃圾邮件）。25 不通 = 一封都发不出去，
 *        Postfix 只会把邮件堆在队列里，看起来"装好了"，实际永远发不出去。
 *     2. 就算 25 通，VPS 的 IP 没有 SPF / DKIM / 反向解析，
 *        QQ / 163 / Gmail 基本都会判垃圾邮件或者直接拒收。
 *
 *   而 465 / 587 这两个"提交端口"是通的 —— 它们是给你**登录自己的邮箱账号**
 *   再让服务商帮你转发用的。所以正确做法是：填一个真实邮箱 + 它的授权码，
 *   连 smtp.qq.com 这类服务器认证后投递。收件方看到的是 QQ 的服务器在发信，
 *   送达率、SPF、DKIM 全都是现成的。
 *
 * 所以这个类做的事：连服务器 → 加密 → 认证 → 投递，全程不用任何扩展。
 * ---------------------------------------------------------------------------
 */
final class Smtp
{
    /** 单步读写超时（秒） */
    private const STEP_TIMEOUT = 12;

    /**
     * SMTP 是否已配置完整。
     *
     * @param array<string,mixed> $settings
     */
    public static function configured(array $settings): bool
    {
        return !empty($settings['enabled'])
            && trim((string) ($settings['host'] ?? '')) !== ''
            && trim((string) ($settings['username'] ?? '')) !== ''
            && trim((string) ($settings['password'] ?? '')) !== '';
    }

    /**
     * 发一封信。
     *
     * @param string[] $to
     * @param array<string,mixed> $settings host/port/encryption/username/password/from
     * @return array{ok:bool,error:string,transcript:array<int,string>}
     */
    public static function send(array $to, string $subject, string $message, string $from, array $settings): array
    {
        $transcript = [];
        $host = trim((string) ($settings['host'] ?? ''));
        $port = (int) ($settings['port'] ?? 465);
        $encryption = strtolower(trim((string) ($settings['encryption'] ?? 'ssl')));
        $username = trim((string) ($settings['username'] ?? ''));
        $password = (string) ($settings['password'] ?? '');

        $to = array_values(array_filter(array_map('trim', $to)));
        if (!$to) {
            return ['ok' => false, 'error' => '没有收件人', 'transcript' => []];
        }

        // 465 是"连上就加密"，587/25 是"先明文再 STARTTLS"
        if ($encryption === 'none') {
            $endpoint = 'tcp://' . $host . ':' . $port;
        } elseif ($port === 465 || $encryption === 'ssl') {
            $endpoint = 'ssl://' . $host . ':' . $port;
        } else {
            $endpoint = 'tcp://' . $host . ':' . $port;
        }

        $context = stream_context_create([
            'ssl' => [
                // 服务器证书要校验。自签证书的场景这里会失败并给出明确提示，
                // 而不是默默降级 —— 降级等于把授权码交给可能的中间人。
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($endpoint, $errno, $errstr, self::STEP_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            return [
                'ok'         => false,
                'error'      => sprintf(
                    '连不上 %s:%d（%s）。检查：主机名/端口填对了吗？这家服务商封了这个端口吗？'
                    . '（QQ 邮箱用 smtp.qq.com + 465，不要用 25）',
                    $host,
                    $port,
                    $errstr !== '' ? $errstr : ('errno ' . $errno)
                ),
                'transcript' => $transcript,
            ];
        }

        stream_set_timeout($fp, self::STEP_TIMEOUT);
        $transcript[] = '连接 ' . $endpoint;

        try {
            // ---- 问候
            $greeting = self::read($fp, $transcript);
            if ($greeting['code'] !== 220) {
                return self::fail($fp, $greeting, '服务器没有正常问候', $transcript);
            }

            $hostname = self::clientName();

            // ---- EHLO
            $ehlo = self::cmd($fp, 'EHLO ' . $hostname, $transcript);
            if ($ehlo['code'] !== 250) {
                // 有些老服务器只认 HELO
                $ehlo = self::cmd($fp, 'HELO ' . $hostname, $transcript);
                if ($ehlo['code'] !== 250) {
                    return self::fail($fp, $ehlo, 'EHLO/HELO 被拒绝', $transcript);
                }
            }

            // ---- STARTTLS（587 / 25 走这条）
            if ($encryption !== 'none' && $port !== 465 && $encryption !== 'ssl') {
                $starttls = self::cmd($fp, 'STARTTLS', $transcript);
                if ($starttls['code'] !== 220) {
                    return self::fail($fp, $starttls, '服务器不支持 STARTTLS，可以把加密方式改成「SSL（465）」试试', $transcript);
                }

                $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($ok !== true) {
                    return [
                        'ok'         => false,
                        'error'      => 'TLS 握手失败。可能是端口和加密方式对不上（465 用 SSL、587 用 STARTTLS），'
                            . '或者对方证书有问题。',
                        'transcript' => $transcript,
                    ];
                }
                $transcript[] = 'TLS 已建立';

                // 加密之后要再 EHLO 一次，这是协议要求
                $ehlo = self::cmd($fp, 'EHLO ' . $hostname, $transcript);
                if ($ehlo['code'] !== 250) {
                    return self::fail($fp, $ehlo, '加密后 EHLO 被拒绝', $transcript);
                }
            }

            // ---- 认证
            $auth = self::authenticate($fp, $username, $password, $transcript);
            if (!$auth['ok']) {
                return ['ok' => false, 'error' => (string) $auth['error'], 'transcript' => $transcript];
            }

            // ---- 信封
            $mailFrom = self::cmd($fp, 'MAIL FROM:<' . $from . '>', $transcript);
            if ($mailFrom['code'] !== 250) {
                return self::fail($fp, $mailFrom, '发件人被拒绝（QQ 邮箱要求发件地址和登录账号完全一致）', $transcript);
            }

            foreach ($to as $rcpt) {
                $r = self::cmd($fp, 'RCPT TO:<' . $rcpt . '>', $transcript);
                if ($r['code'] !== 250 && $r['code'] !== 251) {
                    return self::fail($fp, $r, '收件人被拒绝：' . $rcpt, $transcript);
                }
            }

            // ---- 正文
            $data = self::cmd($fp, 'DATA', $transcript);
            if ($data['code'] !== 354) {
                return self::fail($fp, $data, '服务器不接受投递', $transcript);
            }

            // 点号开头的行必须转义（否则会被当成结束标记）
            $stuffed = preg_replace('/^\./m', '..', $message) ?? $message;
            // 统一 CRLF，并以单独的 . 行结束
            $stuffed = str_replace(["\r\n", "\r"], "\n", $stuffed);
            $stuffed = str_replace("\n", "\r\n", $stuffed);

            self::write($fp, $stuffed . "\r\n.");
            $done = self::read($fp, $transcript);
            if ($done['code'] !== 250) {
                return self::fail($fp, $done, '投递被拒绝', $transcript);
            }

            self::cmd($fp, 'QUIT', $transcript);
            @fclose($fp);

            return ['ok' => true, 'error' => '', 'transcript' => $transcript];
        } catch (\Throwable $e) {
            @fclose($fp);

            return ['ok' => false, 'error' => 'SMTP 异常：' . $e->getMessage(), 'transcript' => $transcript];
        }
    }

    // ------------------------------------------------------------------ 认证

    /**
     * AUTH LOGIN 优先，不行再试 AUTH PLAIN。
     *
     * @return array{ok:bool,error:string}
     */
    private static function authenticate($fp, string $username, string $password, array &$transcript): array
    {
        $login = self::cmd($fp, 'AUTH LOGIN', $transcript);
        if ($login['code'] === 334) {
            $u = self::cmd($fp, base64_encode($username), $transcript);
            if ($u['code'] !== 334) {
                // 有的服务器用户名和密码在一步里要
                return ['ok' => false, 'error' => self::authError($u)];
            }
            $p = self::cmd($fp, base64_encode($password), $transcript);
            if ($p['code'] !== 235) {
                return ['ok' => false, 'error' => self::authError($p)];
            }

            return ['ok' => true, 'error' => ''];
        }

        // 回退：AUTH PLAIN
        $plain = self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), $transcript);
        if ($plain['code'] === 235) {
            return ['ok' => true, 'error' => ''];
        }

        return ['ok' => false, 'error' => self::authError($plain['code'] === 0 ? $login : $plain)];
    }

    /**
     * 把 SMTP 的错误码翻译成能直接照着做的话。
     *
     * 这一步很关键：535 在 QQ 邮箱上 99% 是因为填了登录密码而不是"授权码"，
     * 只回一句 "auth failed" 的话用户要查半天。
     *
     * @param array{code:int,lines:array<int,string>} $reply
     */
    private static function authError(array $reply): string
    {
        $detail = implode(' / ', array_slice($reply['lines'], 0, 3));

        // 注意用 switch 而不是 match —— 项目承诺支持 PHP 7.4
        switch ($reply['code']) {
            case 535:
            case 534:
                $hint = '认证失败。**QQ 邮箱 / 163 邮箱这里要填"授权码"，不是登录密码** ——'
                    . '去邮箱网页版的「设置 → 账户」里开启 SMTP 服务并生成授权码（16 位字母）。'
                    . '另外：发件地址必须和登录账号完全一致。';
                break;
            case 504:
            case 500:
                $hint = '服务器不认识这个认证方式，试试把端口换成 465（SSL）。';
                break;
            case 334:
                $hint = '认证过程中断，可能是用户名或授权码里有空格。';
                break;
            default:
                $hint = '认证失败（SMTP ' . $reply['code'] . '）。';
        }

        return $hint . ($detail !== '' ? '（服务器原话：' . $detail . '）' : '');
    }

    // ------------------------------------------------------------------ 协议

    /**
     * 发一条命令并读回复。
     *
     * @param array<int,string> $transcript
     * @return array{code:int,lines:array<int,string>}
     */
    private static function cmd($fp, string $command, array &$transcript): array
    {
        // 认证信息不进日志
        $loggable = preg_match('/^(AUTH|[\w+\/=]{8,})/i', $command) ? '(已隐藏)' : $command;
        $transcript[] = '→ ' . mb_substr($loggable, 0, 120);

        self::write($fp, $command);

        return self::read($fp, $transcript);
    }

    private static function write($fp, string $data): void
    {
        @fwrite($fp, $data . "\r\n");
    }

    /**
     * 读一条（可能是多行的）回复。
     *
     * SMTP 多行回复形如：
     *   250-mail.example.com
     *   250-AUTH LOGIN PLAIN
     *   250 OK
     * 只有 "250 " 后面跟空格的那一行才是结束。
     *
     * @param array<int,string> $transcript
     * @return array{code:int,lines:array<int,string>}
     */
    private static function read($fp, array &$transcript): array
    {
        $lines = [];
        $code = 0;

        for ($i = 0; $i < 40; $i++) {
            $line = @fgets($fp, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($fp);
                $reason = !empty($meta['timed_out']) ? '等待服务器响应超时' : '连接被对方关闭';

                return ['code' => 0, 'lines' => array_merge($lines, [$reason])];
            }

            $line = rtrim($line, "\r\n");
            $transcript[] = '← ' . mb_substr($line, 0, 160);

            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m)) {
                $code = (int) $m[1];
                $lines[] = $m[3];
                if ($m[2] === ' ') {
                    break;
                }
                continue;
            }

            // 不合规的续行，收下但不当结束
            $lines[] = $line;
        }

        return ['code' => $code, 'lines' => $lines];
    }

    /**
     * @param array{code:int,lines:array<int,string>} $reply
     * @param array<int,string> $transcript
     * @return array{ok:bool,error:string,transcript:array<int,string>}
     */
    private static function fail($fp, array $reply, string $what, array $transcript): array
    {
        @fclose($fp);
        $detail = implode(' / ', array_slice($reply['lines'], 0, 3));

        return [
            'ok'         => false,
            'error'      => $what . '（SMTP ' . $reply['code'] . ($detail !== '' ? '：' . $detail : '') . '）',
            'transcript' => $transcript,
        ];
    }

    /**
     * EHLO 里报的客户端名字。用域名比用 localhost 更容易过反垃圾检查。
     */
    private static function clientName(): string
    {
        $host = (string) parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST);
        if ($host !== '') {
            return $host;
        }

        $name = gethostname();

        return is_string($name) && $name !== '' ? $name : 'localhost';
    }
}
