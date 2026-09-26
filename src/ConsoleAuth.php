<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 管理后台的入口防护与会话隔离。
 *
 * 后台和玩家反馈界面是**两个独立入口**：
 *
 *   玩家侧   /?r=feedback&t=...          无会话、无状态，链接自带签名
 *   管理后台 /?r=<console_path>          独立会话名 + cookie 只作用于这条路径
 *
 * 这样做的实际意义：
 *   1. 玩家页面上没有任何指向后台的链接，普通玩家不知道后台存在，也不会误点
 *   2. 后台 cookie 的作用域被限制在它自己的路径上 —— 玩家侧的请求根本不携带后台会话
 *   3. 后台会话名与玩家会话名不同，"一个入口被污染"不会顺带影响另一个
 *   4. 后台路径本身是一段随机串，扫不到；再叠加 IP 白名单与失败锁定
 *
 * 注意：路径保密只是"减少暴露面"，真正拦住人的是密码强度 + 失败锁定 + IP 白名单。
 */
final class ConsoleAuth
{
    public const SESSION_NAME = 'mcfix_console';

    /** 登录失败锁定：15 分钟内 6 次 */
    private const MAX_ATTEMPTS = 6;

    private const LOCK_WINDOW = 900;

    /**
     * 后台入口路径（不含 ?r= 前缀）。
     *
     * 配置里是 admin.path；没配就按密钥派生一个稳定的默认值，
     * 这样即使管理员没注意，后台也不会落在 ?r=admin 这种一眼可猜的位置。
     */
    public static function path(): string
    {
        $configured = trim((string) Config::get('admin.path', ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $seed = (string) Config::get('app.hmac_secret', 'mcfix');
        /*
         * 用 Token 那份统一的占位符名单，别在这里自己维护"只认一个值"的子集。
         *
         * 「路径本身是随机串、扫不到」是 ConsoleAuth 的防护之一，但路径是
         * hmac_secret 的**纯函数** —— 不需要访问站点就能离线算出来：
         *
         *     'console-' . substr(hash('sha256', 'console-path|' . $seed), 0, 12)
         *
         * 所以只要 $seed 是印在公开仓库里的字面量，后台地址就是公开的。
         * 原来只特判了 'CHANGE_ME_TO_A_RANDOM_STRING' 一个值，而 Token 里那份
         * 名单有 6 个（'change-me'、'secret'、'changeme'、'CHANGE_ME_AGENT_TOKEN'…）。
         * 名单有两份，就一定会出现"这边加了那边忘了" —— 直接复用同一份。
         */
        if ($seed === '' || Token::isPlaceholderSecret($seed)) {
            $seed = (string) Config::get('app.base_url', 'mcfix');
        }

        return 'console-' . substr(hash('sha256', 'console-path|' . $seed), 0, 12);
    }

    /**
     * 后台的绝对地址。
     *
     * 两种形态：
     *   独立域名模式 → https://mc.example.com/           （干净，推荐）
     *   私有路径模式 → https://fankui.example.com/?r=<path>
     */
    public static function url(string $query = ''): string
    {
        if (self::hostMode()) {
            // query 里可能带前导 &（调用方历史写法），统一去掉
            return self::baseUrlForHost(self::host(), ltrim($query, '&'));
        }

        $base = '?r=' . rawurlencode(self::path());
        if ($query !== '') {
            $base .= '&' . ltrim($query, '&');
        }

        return abs_url($base);
    }

    /**
     * 给某个 host 拼出带协议与站点目录的根地址，并可选地附加查询串。
     *
     * query 传进来时**不要带前导 ?** —— 这里统一补。
     * （之前调用方带了 ?、这里又补一次，拼出了 "路径/??r=..." 这种坏链接。）
     *
     * 协议判定顺序（很重要）：
     *   1. 配置 app.base_url 里写的协议 —— 这是管理员明确声明的对外协议，
     *      CLI / cron 环境下没有 $_SERVER，只能靠它，否则会把 HTTPS 站点的链接生成成 HTTP。
     *   2. 当前请求的协议
     *   3. 默认 http
     */
    private static function baseUrlForHost(string $host, string $query = ''): string
    {
        $configured = (string) Config::get('app.base_url', '');
        $scheme = '';
        $dir = '';

        if ($configured !== '') {
            $parsed = (string) parse_url($configured, PHP_URL_SCHEME);
            if ($parsed === 'https' || $parsed === 'http') {
                $scheme = $parsed;
            }
            $dir = rtrim((string) parse_url($configured, PHP_URL_PATH), '/');
        }

        if ($scheme === '') {
            $scheme = is_https() ? 'https' : 'http';
        }

        $url = $scheme . '://' . $host . $dir . '/';

        $query = ltrim(trim($query), '?');
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    /**
     * 玩家反馈站的根地址。
     */
    public static function feedbackUrl(string $query = ''): string
    {
        if (self::hostMode()) {
            return self::baseUrlForHost(self::feedbackHost(), ltrim($query, '&'));
        }

        return abs_url($query);
    }

    /**
     * cookie 作用域：覆盖真实请求路径。
     */
    public static function scope(): string
    {
        // cookie 的 path 必须覆盖真实请求路径。
        // 本系统的后台地址形如 /index.php?r=<path>，请求路径其实是站点目录而不是 /<path>，
        // 所以这里返回站点基准目录；会话隔离靠"不同的会话名"实现，不靠 cookie path。
        //
        // 独立域名模式下两边本来就是不同的源，cookie 更不可能混淆。
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $dir === '' ? '/' : $dir . '/';
    }

    // ------------------------------------------------------------------ 域名分流

    /**
     * 后台域名（配置 domains.console_host，可逗号分隔多个别名）。
     */
    public static function host(): string
    {
        $raw = trim((string) Config::get('domains.console_host', ''));

        return $raw === '' ? '' : strtolower(trim(explode(',', $raw)[0]));
    }

    /**
     * 玩家反馈站域名。
     */
    public static function feedbackHost(): string
    {
        $raw = trim((string) Config::get('domains.feedback_host', ''));

        return $raw === '' ? '' : strtolower(trim(explode(',', $raw)[0]));
    }

    /**
     * 是否启用了"后台独立域名"模式。
     */
    public static function hostMode(): bool
    {
        return self::host() !== '';
    }

    /**
     * 当前请求的 Host（去掉端口）。
     */
    public static function requestHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $host = strtolower(trim($host));
        $pos = strpos($host, ':');
        if ($pos !== false) {
            $host = substr($host, 0, $pos);
        }

        return $host;
    }

    /**
     * 某个 host 是否属于后台域名（支持逗号分隔的别名）。
     */
    public static function isConsoleHost(string $host): bool
    {
        return self::hostInList((string) Config::get('domains.console_host', ''), $host);
    }

    /**
     * 某个 host 是否属于反馈站域名。
     */
    public static function isFeedbackHost(string $host): bool
    {
        return self::hostInList((string) Config::get('domains.feedback_host', ''), $host);
    }

    private static function hostInList(string $list, string $host): bool
    {
        $list = trim($list);
        if ($list === '' || $host === '') {
            return false;
        }

        foreach (explode(',', $list) as $alias) {
            if (strtolower(trim($alias)) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * 初始化后台会话（独立会话名 + 受限 cookie 路径）。
     */
    public static function initSession(): void
    {
        if (PHP_SAPI === 'cli' || !function_exists('mcfix_start_session')) {
            return;
        }

        mcfix_start_session(self::SESSION_NAME, self::scope());
    }

    /**
     * 当前请求是否命中后台入口。
     *
     * 支持两种写法：
     *   /?r=<path>          配置里的路径
     *   /<path>             直接路径访问（宝塔里可以加一条 rewrite，更干净）
     */
    public static function matches(string $route): bool
    {
        $route = trim($route, '/');

        if ($route === self::path()) {
            return true;
        }

        // 也允许在路径后面接 .page，例如 ?r=console-xxx.tickets
        return strpos($route, self::path() . '.') === 0;
    }

    /**
     * 从路由里取出后台内部的页面名（tickets / ticket / servers / ...）。
     */
    public static function pageFrom(string $route): string
    {
        $route = trim($route, '/');
        $path = self::path();

        if ($route === $path) {
            return '';
        }
        if (strpos($route, $path . '.') === 0) {
            return substr($route, strlen($path) + 1);
        }

        return '';
    }

    // ------------------------------------------------------------------ 访问控制

    /**
     * 访问前置检查（在会话初始化之后、任何页面渲染之前调用）。
     *
     * @return array{ok:bool,reason:string,retry_after:int}
     */
    public static function gate(): array
    {
        $allow = self::ipAllowlist();
        if ($allow !== [] && !self::ipMatches($allow)) {
            app_log('warn', '后台访问被 IP 白名单拒绝', [
                'ip'     => client_ip(),
                'path'   => self::path(),
            ]);

            return ['ok' => false, 'reason' => 'ip', 'retry_after' => 0];
        }

        // 登录失败锁定：**只按来源 IP**，不再用全局计数。
        //
        // 以前这里查的是全局 key（`lockKey('all')`），计数到 6 就把整个后台锁上 ——
        // 管理员自己也被关在门外，连登录表单都看不到。攻击者每 15 分钟重放 6 次
        // 就能永久锁死后台：这是拿可用性换安全，而且换错了方向。
        // 要防的是"某个来源正在爆破"，不是"全世界都在爆破"。
        //
        // 白名单里的地址不受锁：管理员万一被自己的自动化脚本试错了几次，
        // 还能从白名单入口进去。按 IP 锁已经足够防爆破（换个 IP 要从头再数）。
        $whitelisted = $allow !== [] && self::ipMatches($allow);
        if (!$whitelisted) {
            $locked = Rate::blockedFor(self::lockKey(client_ip_hash()), self::MAX_ATTEMPTS, self::LOCK_WINDOW);
            if ($locked > 0) {
                return ['ok' => false, 'reason' => 'locked', 'retry_after' => $locked];
            }
        }

        return ['ok' => true, 'reason' => '', 'retry_after' => 0];
    }

    /**
     * 后台会话的绝对有效期。
     *
     * 为什么需要：`sessionFingerprint()` 是**全局常量**（哈希 + 后台路径），
     * 对每个会话都一样 —— 它的作用是"改口令后所有会话失效"，但**不绑定单个会话**。
     * 于是被偷走的 cookie 可以一直用下去，除非管理员改口令。
     * `$_SESSION['console_since']` 一直在写、却从来没人读，等于没有过期时间。
     */
    private const SESSION_MAX_AGE = 604800;   // 7 天

    public static function isAuthed(): bool
    {
        $hash = (string) Config::get('app.admin_password', '');
        if ($hash === '') {
            return false;
        }

        if (empty($_SESSION['console_authed'])
            || !hash_equals(self::sessionFingerprint($hash), (string) ($_SESSION['console_fp'] ?? ''))) {
            return false;
        }

        // 绝对过期：到点一律重新登录。
        // 老会话（本次升级之前建立的）没有 console_since —— 不直接踢掉，
        // 补记当前时间即可，否则升级会让所有在线管理员被登出。
        $since = (int) ($_SESSION['console_since'] ?? 0);
        if ($since <= 0) {
            $_SESSION['console_since'] = time();

            return true;
        }
        if (time() - $since > self::SESSION_MAX_AGE) {
            self::logout();

            return false;
        }

        return true;
    }

    /**
     * 尝试登录。
     *
     * @return array{ok:bool,error:string,locked:int}
     */
    public static function login(string $password): array
    {
        $ipKey = self::lockKey(client_ip_hash());

        // 同样只按 IP 计。全局计数已去掉 —— 理由见 gate() 上面那段说明。
        $allow = self::ipAllowlist();
        $whitelisted = $allow !== [] && self::ipMatches($allow);

        if (!$whitelisted) {
            $locked = Rate::blockedFor($ipKey, self::MAX_ATTEMPTS, self::LOCK_WINDOW);
            if ($locked > 0) {
                return ['ok' => false, 'error' => '尝试次数过多，请 ' . ceil($locked / 60) . ' 分钟后再试', 'locked' => $locked];
            }
        }

        $hash = (string) Config::get('app.admin_password', '');
        if ($hash === '') {
            return ['ok' => false, 'error' => '后台口令尚未设置，请在服务器上执行 php bin/mcfix.php reset-password', 'locked' => 0];
        }

        if (!$whitelisted) {
            Rate::hit($ipKey, self::MAX_ATTEMPTS, self::LOCK_WINDOW);
        }

        if (!password_verify($password, $hash)) {
            app_log('warn', '后台登录失败', ['ip' => client_ip()]);

            return ['ok' => false, 'error' => '密码不正确', 'locked' => 0];
        }

        // 登录成功：换 session id 防会话固定，清掉失败计数
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Rate::clear($ipKey, self::LOCK_WINDOW);

        $_SESSION['console_authed'] = true;
        $_SESSION['console_fp'] = self::sessionFingerprint($hash);
        $_SESSION['console_since'] = time();
        $_SESSION['console_csrf'] = bin2hex(random_bytes(16));

        // 顺带把玩家侧可能存在的旧键清掉，避免历史版本残留
        unset($_SESSION['admin_authed'], $_SESSION['admin_hash']);

        record_event(null, 'admin', 'console.login', '管理后台登录成功（' . client_ip() . '）');

        return ['ok' => true, 'error' => '', 'locked' => 0];
    }

    public static function logout(): void
    {
        record_event(null, 'admin', 'console.logout', '管理后台退出登录（' . client_ip() . '）');
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * 会话指纹。改密码、换后台路径之后指纹变化，旧会话自动失效。
     *
     * 这里**必须**把后台路径也混进去：界面上明确告诉管理员"换了路径之后
     * 这次会话不会带到新地址，需要重新输一次口令"，而以前指纹只跟密码哈希
     * 有关 —— 换路径根本踢不掉任何已存在的会话，等于给了一个假的保证。
     * 管理员因为怀疑会话被盗而轮换路径时，那个会话其实还活着。
     */
    public static function sessionFingerprint(string $hash): string
    {
        return substr(hash('sha256', 'console|' . $hash . '|' . self::path()), 0, 20);
    }

    /**
     * 后台自己的 CSRF 令牌（与玩家侧分开，互不复用）。
     */
    public static function csrf(): string
    {
        if (empty($_SESSION['console_csrf'])) {
            $_SESSION['console_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['console_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrf()) . '">';
    }

    /**
     * @param array<string,mixed>|null $data
     */
    public static function checkCsrf(?array $data = null): void
    {
        $data = $data ?? request_data();
        $sent = param($data, '_csrf', param($_POST, '_csrf', ''));
        $expected = (string) ($_SESSION['console_csrf'] ?? '');

        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            if (expects_json()) {
                json_fail('会话已失效，请刷新后台页面后重试', 419);
            }
            http_response_code(419);
            header('Content-Type: text/html; charset=utf-8');
            exit('<h3>会话已失效</h3><p>请返回管理后台首页刷新后重试。</p>');
        }
    }

    /**
     * IP 白名单（配置 admin.ip_allow，支持单个 IP 与 192.168.1.* 通配）。
     *
     * @return string[]
     */
    public static function ipAllowlist(): array
    {
        $raw = Config::get('admin.ip_allow', []);

        if (is_string($raw)) {
            $raw = preg_split('/[,;\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $raw), static function ($item): bool {
            return is_string($item) && $item !== '';
        }));
    }

    /**
     * @param string[] $patterns
     */
    private static function ipMatches(array $patterns): bool
    {
        $ip = client_ip();

        foreach ($patterns as $pattern) {
            if ($pattern === $ip) {
                return true;
            }
            if (strpos($pattern, '*') !== false) {
                $regex = '#^' . str_replace(['\*', '\.'], ['\d{1,3}', '\.'], preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $ip)) {
                    return true;
                }
            }
            // 支持 CIDR
            if (strpos($pattern, '/') !== false) {
                if (self::ipInCidr($ip, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function lockKey(string $suffix): string
    {
        return 'console-login:' . $suffix;
    }
}
