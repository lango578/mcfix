<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 反馈链接的签名令牌。
 *
 * 设计目标：
 *  1. 玩家不需要注册、不需要验证码就能打开链接反馈；
 *  2. 链接 24 小时后自动失效，且无法被枚举；
 *  3. 链接可以按工单吊销（nonce 存在工单行里，管理后台"重新生成链接"即让旧链接作废）；
 *  4. 链接里带"本次诊断授权"，令牌用过一次就作废，避免被无限刷诊断；
 *  5. MC 机器上的 Agent 也能独立校验（共享 hmac_secret）。
 */
final class Token
{
    /** 主令牌：打开反馈页 / 查看工单 */
    public const SCOPE_SHARE = 'f';

    /** 诊断授权：允许对该工单执行一次"重新验证" */
    public const SCOPE_VERIFY = 'v';

    /** Agent 上报令牌 */
    public const SCOPE_AGENT = 'a';

    /** MOD 下载令牌（绑定文件名与有效期，不可枚举） */
    public const SCOPE_DOWNLOAD = 'd';

    /**
     * 生成反馈链接令牌。
     *
     * @param array<string,mixed> $feedback
     */
    public static function forFeedback(array $feedback, int $ttlSeconds = 0): string
    {
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : (int) Config::get('feedback.token_ttl', 86400);

        return self::sign(self::SCOPE_SHARE, [
            (int) $feedback['id'],
            (string) $feedback['server_id'],
            (string) ($feedback['token_nonce'] ?? ''),
            time() + $ttl,
        ]);
    }

    /**
     * 生成"页内一键验证"令牌，只在页面签名时下发。
     *
     * @param array<string,mixed> $feedback
     */
    public static function forVerify(array $feedback, int $ttlSeconds = 1800): string
    {
        return self::sign(self::SCOPE_VERIFY, [
            (int) $feedback['id'],
            (string) $feedback['server_id'],
            bin2hex(random_bytes(6)),
            time() + $ttlSeconds,
        ]);
    }

    /**
     * 服务器级公开链接令牌（见 Share 类）。nonce 固定，用来和工单链接区分。
     *
     * 注意：这类令牌用服务器自己的 share_secret 签名，因此"重新生成"能让旧链接立刻失效，
     * 而且不会影响 Agent 令牌与工单链接（它们用的是全局 hmac_secret）。
     */
    public static function forServerShare(string $serverId, string $nonce, int $ttlSeconds, string $secret = ''): string
    {
        return self::sign(self::SCOPE_SHARE, [0, $serverId, $nonce, time() + $ttlSeconds], $secret);
    }

    /**
     * 生成 Agent 令牌（后台"生成"按钮用）。
     */
    public static function agentToken(string $serverId, int $ttlSeconds = 0): string
    {
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : 315360000; // 10 年，等同于长期令牌

        return self::sign(self::SCOPE_AGENT, [$serverId, time() + $ttl]);
    }

    /**
     * 自定义用途令牌：给"下载 MOD"这类一次性链接用。
     * 约定 payload 的最后一项必须是过期时间戳。
     *
     * @param array<int,mixed> $data
     */
    public static function signCustom(string $scope, array $data, string $secret = ''): string
    {
        return self::sign($scope, $data, $secret);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function parseCustom(string $token, string $expectedScope): ?array
    {
        $parsed = self::parse($token, $expectedScope);
        if ($parsed === null) {
            return null;
        }
        $parsed['payload'] = (array) $parsed['data'];

        return $parsed;
    }

    /**
     * 校验并解包令牌。
     *
     * @param string $expectedScope 期望的用途（f / v / a），留空表示不限
     * @param string[] $extraSecrets 额外的候选密钥（例如服务器自己的 share_secret）
     * @return array<string,mixed>|null
     */
    public static function parse(string $token, string $expectedScope = '', array $extraSecrets = []): ?array
    {
        $token = trim($token);
        if ($token === '' || strpos($token, '.') === false) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$payloadB64, $signature] = $parts;
        $secrets = array_merge(self::candidateSecrets(), array_filter($extraSecrets, static function ($s): bool {
            return is_string($s) && $s !== '';
        }));

        $valid = false;
        foreach ($secrets as $secret) {
            if (hash_equals(self::hmac($payloadB64, $secret), $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return null;
        }

        $raw = self::b64urlDecode($payloadB64);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['s'], $decoded['d'], $decoded['t'])) {
            return null;
        }

        if ($expectedScope !== '' && $decoded['s'] !== $expectedScope) {
            return null;
        }

        if ((int) $decoded['t'] < time()) {
            return null;
        }

        return [
            'scope'     => (string) $decoded['s'],
            'data'      => (array) $decoded['d'],
            'expires'   => (int) $decoded['t'],
            'server_id' => isset($decoded['d'][1]) ? (string) $decoded['d'][1] : '',
            'id'        => isset($decoded['d'][0]) ? (int) $decoded['d'][0] : 0,
            'nonce'     => isset($decoded['d'][2]) ? (string) $decoded['d'][2] : '',
        ];
    }

    public static function feedbackUrl(array $feedback, int $ttlSeconds = 0): string
    {
        $query = '?r=feedback&t=' . self::forFeedback($feedback, $ttlSeconds);

        // 双域名模式下强制用反馈站域名 —— 玩家拿到的链接必须指向玩家侧，
        // 否则从后台复制出去的链接会落到后台域名上。
        return ConsoleAuth::feedbackUrl($query);
    }

    // ---------------------------------------------------------------- 内部实现

    /**
     * @param array<int,mixed> $data
     */
    private static function sign(string $scope, array $data, string $secret = ''): string
    {
        $payload = self::b64urlEncode((string) json_encode(
            ['s' => $scope, 'd' => $data, 't' => (int) ($data[count($data) - 1] ?? 0)],
            JSON_UNESCAPED_SLASHES
        ));

        return $payload . '.' . self::hmac($payload, $secret);
    }

    /**
     * 公开出现过的占位值 —— 用它们签名等于没签名。
     */
    private const PLACEHOLDER_SECRETS = [
        'mcfix-empty-secret',
        'CHANGE_ME_TO_A_RANDOM_STRING',
        'CHANGE_ME_AGENT_TOKEN',
        'change-me',
        'changeme',
        'secret',
    ];

    /**
     * 密钥能不能用。
     *
     * 为什么必须有这道判断：以前空密钥会退回到字面量 'mcfix-empty-secret'，
     * 那是公开可猜的 —— 用它签出来的令牌谁都能伪造，而 Agent 令牌能让攻击者
     * 读走任务队列、伪造执行结果。config.example.php 里的
     * 'CHANGE_ME_TO_A_RANDOM_STRING' 同样印在公开仓库里，照抄不改一样危险。
     *
     * 所以这里选择**失败关闭**：宁可让链接失效、管理员当场发现，
     * 也不能悄悄接受一个可伪造的令牌。
     */
    private static function isUsableSecret(string $secret): bool
    {
        $secret = trim($secret);

        return $secret !== '' && !self::isPlaceholderSecret($secret);
    }

    /**
     * 是不是"公开印在仓库里、等于没设"的占位值。
     *
     * 公开出来是因为 Agent 令牌也要用同一份名单 —— 它原来只拒绝空串，
     * 于是 config.example.php 里的 'CHANGE_ME_AGENT_TOKEN' 照抄不改就能通过。
     * 占位值名单只应该有一份，否则迟早出现"这边加了那边忘了"。
     */
    public static function isPlaceholderSecret(string $secret): bool
    {
        return in_array(trim($secret), self::PLACEHOLDER_SECRETS, true);
    }

    /**
     * 面板认可的所有密钥：全局 hmac_secret + 每台服务器的 share_secret。
     *
     * @return string[]
     */
    private static function candidateSecrets(): array
    {
        $secrets = [];

        $global = (string) Config::get('app.hmac_secret', '');
        if (self::isUsableSecret($global)) {
            $secrets[] = $global;
        }

        foreach (Config::servers() as $server) {
            $share = (string) ($server['share_secret'] ?? '');
            if (self::isUsableSecret($share)) {
                $secrets[] = $share;
            }
        }

        return array_values(array_unique($secrets));
    }

    private static function hmac(string $payloadB64, string $secret = ''): string
    {
        if ($secret === '') {
            $secret = (string) Config::get('app.hmac_secret', '');
        }
        if (!self::isUsableSecret($secret)) {
            // 返回空签名：签出来的令牌永远校验不过（fail closed），
            // 而不是用一个公开常量签出一个"看起来有效"的令牌。
            return '';
        }

        return self::b64urlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
    }

    public static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($padded, true);

        return is_string($raw) ? $raw : '';
    }
}
