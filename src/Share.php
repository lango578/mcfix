<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 服务器级公开反馈链接（不绑定具体工单）。
 *
 * 与工单链接的区别：
 *   工单链接（Token::SCOPE_SHARE + 工单 nonce）—— 一条反馈一个链接，打开就看到这条工单的状态；
 *   服务器链接（本类，nonce = share）—— 一台服务器一条链接，发到群里，任何玩家打开都能提交新反馈。
 *
 * 这类链接用每台服务器独立的 share_secret 签名：
 *   - 后台点「重新生成」→ 换一把 share_secret → 旧链接立刻失效；
 *   - 不影响 Agent 令牌和工单链接（它们用全局 hmac_secret）。
 *
 * ★ "每台服务器独立"这件事必须真的成立，否则上面那句承诺是假的。
 *
 * 服务器还没落盘 share_secret 时（后台新增的服务器、从 config.example.php
 * 抄来的配置都可能是这种），**不能**退回全局 hmac_secret 签名：
 * Token::candidateSecrets() 永远把全局密钥算作候选，那么用全局密钥签出来的
 * 链接无论点多少次「重新生成」都换不掉 —— 新 share_secret 只是多了一个候选，
 * 旧链接照样验得过。所以这里改成按服务器 id **派生**一把：
 * 它只在这台服务器没有独立密钥时才是验签候选（见 parse()），
 * 一旦 rotate() 落盘，旧链接立刻失效。
 */
final class Share
{
    private const NONCE = 'share';

    /**
     * @param array<string,mixed> $server
     * @return array{url:string,expires:int,token:string}
     */
    public static function link(array $server, int $ttlDays = 30): array
    {
        $ttl = max(1, $ttlDays) * 86400;
        $token = self::token((string) $server['id'], $ttl);

        return [
            // 双域名模式下强制指向反馈站域名，避免把玩家链接生成到后台域名上
            'url'     => ConsoleAuth::feedbackUrl('?r=feedback&t=' . $token),
            'token'   => $token,
            'expires' => time() + $ttl,
        ];
    }

    /**
     * 确保服务器有独立的 share_secret（没有就生成并落盘）。
     *
     * 后台渲染「接入与排查」页时会调用，这样管理员第一次看到链接时密钥就已经存在，
     * 之后点「重新生成」才能真正让旧链接失效。
     */
    public static function ensureSecret(string $serverId): string
    {
        $server = Config::server($serverId);
        if ($server === null) {
            return '';
        }

        $existing = (string) ($server['share_secret'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        $rotated = self::rotate($serverId);

        return $rotated['ok'] ? $rotated['secret'] : '';
    }

    public static function token(string $serverId, int $ttlSeconds): string
    {
        return Token::forServerShare($serverId, self::NONCE, $ttlSeconds, self::secretFor($serverId));
    }

    /**
     * 这台服务器签名公开链接用的密钥：有独立 share_secret 就用它，否则派生一把。
     *
     * 派生而不是退回全局密钥的原因见类注释 —— 用全局密钥签出来的链接无法被
     * 「重新生成」作废。
     */
    public static function secretFor(string $serverId): string
    {
        $server = Config::server($serverId);
        $stored = $server !== null ? trim((string) ($server['share_secret'] ?? '')) : '';

        return $stored !== '' ? $stored : self::derivedSecret($serverId);
    }

    /**
     * 由全局密钥 + 服务器 id 派生出来的密钥。
     *
     * 全局密钥本身不可用时返回空串：Token::hmac() 收到空密钥会签出空签名，
     * 令牌永远验不过（失败关闭）。这里绝不能"照常派生"——那等于拿一个空密钥
     * 派生出一把看起来正常、实则人人可算的密钥，把失败关闭悄悄变成可伪造。
     */
    private static function derivedSecret(string $serverId): string
    {
        $global = trim((string) Config::get('app.hmac_secret', ''));
        if ($global === '' || Token::isPlaceholderSecret($global)) {
            return '';
        }

        return hash_hmac('sha256', 'mcfix-share/' . $serverId, $global);
    }

    /**
     * 验签时要额外认的派生密钥：只包含那些**还没有**独立 share_secret 的服务器。
     *
     * 这一步正是"重新生成能让旧链接失效"的机关：rotate() 落盘之后，这台服务器
     * 从候选名单里消失，用派生密钥签的旧链接就再也验不过了。
     *
     * @return string[]
     */
    private static function linkCandidates(): array
    {
        $candidates = [];
        foreach (Config::servers() as $server) {
            if (trim((string) ($server['share_secret'] ?? '')) !== '') {
                continue;
            }
            $derived = self::derivedSecret((string) ($server['id'] ?? ''));
            if ($derived !== '') {
                $candidates[] = $derived;
            }
        }

        return $candidates;
    }

    /**
     * 轮换某台服务器的 share_secret（旧公开链接立即失效）。
     *
     * @return array{ok:bool,secret:string,message:string}
     */
    public static function rotate(string $serverId): array
    {
        $items = (array) Config::get('', []);
        $found = false;
        $secret = bin2hex(random_bytes(24));

        foreach ((array) ($items['servers'] ?? []) as $i => $server) {
            if ((string) ($server['id'] ?? '') !== $serverId) {
                continue;
            }
            $items['servers'][$i]['share_secret'] = $secret;
            $found = true;
            break;
        }

        if (!$found) {
            return ['ok' => false, 'secret' => '', 'message' => '服务器不存在'];
        }

        if (!Config::save($items)) {
            return ['ok' => false, 'secret' => '', 'message' => '配置写入失败，请检查 config 目录权限'];
        }

        record_event(null, 'admin', 'share.rotate', '轮换公开反馈链接密钥：' . $serverId);

        return ['ok' => true, 'secret' => $secret, 'message' => '已重新生成公开反馈链接，旧链接立即失效'];
    }

    /**
     * 判断令牌是否为服务器级公开链接。
     *
     * @return array<string,mixed>|null
     */
    public static function parse(string $token): ?array
    {
        // 额外候选：给"还没落盘独立密钥"的服务器留的派生密钥。
        // 载荷里的 server_id 还没验签，所以这里不按 id 挑，而是把当前所有
        // 缺密钥的服务器的派生值都当候选 —— 验签本身仍然是 HMAC，签不过就是 null。
        $parsed = Token::parse($token, 'f', self::linkCandidates());
        if ($parsed === null || (string) $parsed['nonce'] !== self::NONCE) {
            return null;
        }

        $server = Config::server((string) $parsed['server_id']);
        if ($server === null) {
            return null;
        }

        return [
            'server'    => $server,
            'server_id' => (string) $parsed['server_id'],
            'expires'   => (int) $parsed['expires'],
        ];
    }
}
