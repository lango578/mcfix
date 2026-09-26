<?php

declare(strict_types=1);

use MCFix\Config;
use MCFix\Db;
use MCFix\Rate;
use MCFix\Share;

if (!function_exists('e')) {
    /**
     * HTML 转义，视图里输出一切动态内容都走它。
     *
     * @param mixed $value
     */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('now')) {
    /** 当前 UTC 时间，数据库统一存这个格式 */
    function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('ts')) {
    /** 把数据库时间字符串转成时间戳 */
    function ts(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) strtotime($value . ' UTC');
    }
}

if (!function_exists('human_time')) {
    function human_time(?string $value): string
    {
        $t = ts($value);
        if ($t <= 0) {
            return '—';
        }

        $diff = time() - $t;
        if ($diff < 5) {
            return '刚刚';
        }
        if ($diff < 60) {
            return $diff . ' 秒前';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' 小时前';
        }
        if ($diff < 604800) {
            return floor($diff / 86400) . ' 天前';
        }

        return date('Y-m-d H:i', $t);
    }
}

if (!function_exists('human_size')) {
    function human_size(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $bytes < 10 && $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }
}

if (!function_exists('duration_text')) {
    function duration_text(float $seconds): string
    {
        if ($seconds < 1) {
            return '小于 1 秒';
        }
        if ($seconds < 60) {
            return round($seconds) . ' 秒';
        }
        $m = floor($seconds / 60);
        $s = (int) round($seconds - $m * 60);

        return $m . ' 分 ' . $s . ' 秒';
    }
}

if (!function_exists('json_out')) {
    /**
     * 输出 JSON 并结束请求。
     *
     * @param array<string,mixed> $payload
     */
    function json_out(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('json_fail')) {
    function json_fail(string $message, int $status = 400, array $extra = []): void
    {
        json_out(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }
}

if (!function_exists('json_ok')) {
    function json_ok(array $data = []): void
    {
        json_out(array_merge(['ok' => true], $data));
    }
}

if (!function_exists('read_json_body')) {
    /**
     * 读原始 JSON 请求体（POST application/json）。
     * 有些宝塔环境会限制 php://input，这里做了长度保护。
     *
     * @return array<string,mixed>
     */
    function read_json_body(int $limit = 262144): array
    {
        $raw = file_get_contents('php://input', false, null, 0, $limit);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}

if (!function_exists('request_data')) {
    /**
     * 合并 JSON 体与表单体（JSON 优先）。
     *
     * @return array<string,mixed>
     */
    function request_data(): array
    {
        $body = read_json_body();
        if ($body) {
            return array_merge($_POST, $body);
        }

        return $_POST;
    }
}

if (!function_exists('param')) {
    /**
     * 取字符串参数，自动去空白 + 长度截断。
     *
     * @param array<string,mixed> $source
     */
    function param(array $source, string $key, string $default = '', int $maxLen = 255): string
    {
        if (!isset($source[$key]) || is_array($source[$key])) {
            return $default;
        }
        $value = trim((string) $source[$key]);
        if ($value === '') {
            return $default;
        }

        return mb_substr($value, 0, $maxLen);
    }
}

if (!function_exists('int_param')) {
    /**
     * @param array<string,mixed> $source
     */
    function int_param(array $source, string $key, int $default = 0): int
    {
        if (!isset($source[$key]) || is_array($source[$key])) {
            return $default;
        }

        return (int) $source[$key];
    }
}

if (!function_exists('bool_param')) {
    /**
     * @param array<string,mixed> $source
     */
    function bool_param(array $source, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $source) || is_array($source[$key])) {
            return $default;
        }
        $value = $source[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('client_ip')) {
    /**
     * 取真实客户端 IP。
     *
     * 转发头（X-Forwarded-For / X-Real-IP / CF-Connecting-IP）**只在自己配置的
     * trusted_proxies 里才采信**。这一点很关键：
     *   - 后台的 IP 白名单、登录锁定、提交限流全都建立在这个值上；
     *   - 请求头是客户端可以随便写的，如果无条件相信，攻击者加一行
     *     `X-Forwarded-For: 127.0.0.1` 就能绕过 IP 白名单，也能把限流变成每人一份。
     *
     * 之前的实现在这里放宽到"来源是内网地址就相信转发头"。宝塔的 Nginx 反代
     * 确实会让 REMOTE_ADDR 变成 127.0.0.1，但如果 nginx 不覆写而是**追加**
     * X-Forwarded-For（默认行为），那条放宽就等于把上面的防护全部作废。
     *
     * 取值规则：
     *   1. REMOTE_ADDR 不在 trusted_proxies 里 → 直接用它，忽略所有转发头；
     *   2. 在 trusted_proxies 里 → 从 XFF **最右侧**往回找第一个不在 trusted_proxies
     *      里的地址（右边是代理链上后加的，左边才是客户端能伪造的）；
     *   3. 都没有就退回 REMOTE_ADDR。
     *
     * 部署提示：宝塔的 Nginx 请显式覆写而不是追加，例如
     *   proxy_set_header X-Real-IP $remote_addr;
     *   proxy_set_header X-Forwarded-For $remote_addr;
     * 不确定的话就把 trusted_proxies 留空（默认值），让系统只看 REMOTE_ADDR ——
     * 这条路永远不会被伪造，代价只是"前面有代理时看到的是代理的地址"。
     */
    function client_ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '0.0.0.0';
        }

        // 默认空数组：显式配置了才采信转发头。
        //
        // 这里以前默认信任回环（['127.0.0.1','::1']），想法是"宝塔的 Nginx 反代
        // 会让 REMOTE_ADDR 变成 127.0.0.1，那时得读转发头才知道真实来源"。
        // 但很多部署形态下 REMOTE_ADDR 本来就恒为 127.0.0.1（PHP 内置服务器、
        // nginx 以 TCP 转发给 php-fpm、各种透明代理），此时**任何客户端**自填的
        // X-Forwarded-For 都会被当成真实来源。
        //
        // 实测确认的后果：IP 白名单被一个请求头绕过（攻击者不在白名单里也拿到
        // 后台登录页），并且登录失败锁定永不触发（每次换一个 XFF 就等于换一个
        // "身份"，可以无限爆破后台密码）。这两层防护都建立在这个函数上。
        //
        // 宁可默认保守：真在反代后面的部署，管理员显式填上代理地址即可，
        // 而且下面 client_ip_source() 会在后台把"当前取自转发头"标出来。
        $trusted = (array) Config::get('trusted_proxies', []);
        if (!in_array($remote, array_map('strval', $trusted), true)) {
            // 没走受信任的代理：以连接来源为准
            return $remote;
        }

        // 依次看几种转发头，取第一个能解析出结果的
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
            $raw = (string) ($_SERVER[$header] ?? '');
            if (trim($raw) === '') {
                continue;
            }

            $chain = array_values(array_filter(array_map('trim', explode(',', $raw)), static function (string $part): bool {
                return $part !== '';
            }));

            // 从右往左：跳过同样是代理的地址，第一个"非代理"就是客户端
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = $chain[$i];
                // 去掉 IPv6 的端口写法 [::1]:1234
                if (preg_match('/^\[(.+)\]:\d+$/', $candidate, $m)) {
                    $candidate = $m[1];
                }
                if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                    continue;
                }
                if (in_array($candidate, array_map('strval', $trusted), true)) {
                    continue;
                }

                return $candidate;
            }
        }

        return $remote;
    }
}

if (!function_exists('client_ip_source')) {
    /**
     * 这个请求的来源 IP 是**从哪来的** —— 'remote' 还是 'xff'。
     *
     * 为什么值得单独暴露：后台 IP 白名单、登录锁定、玩家限流全建立在 client_ip()
     * 上。它到底取的是连接来源还是转发头，决定了这个值能不能被伪造 ——
     * 而这完全取决于部署形态（Nginx 走 unix socket + fastcgi.conf 时，
     * REMOTE_ADDR 是真实客户端 IP；走 TCP 且没设 REMOTE_ADDR 时才会变成 127.0.0.1，
     * 那时才会采信 X-Forwarded-For）。把结论直接显示在后台，运维一眼能看出
     * 自己处在哪种情况，不用去猜。
     *
     * @return array{ip:string,source:string,note:string}
     */
    function client_ip_source(): array
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '0.0.0.0';
        }

        // 和 client_ip() 用同一个默认值。两边不一致的话，后台会显示"来自连接来源"
        // 而实际取值走的是转发头（或反过来），这种自检信息比没有更糟。
        $trusted = array_map('strval', (array) Config::get('trusted_proxies', []));
        $ip = client_ip();

        if (!in_array($remote, $trusted, true)) {
            return [
                'ip'     => $ip,
                'source' => 'remote',
                'note'   => '来自连接来源（REMOTE_ADDR），不可伪造',
            ];
        }

        if ($ip === $remote) {
            return [
                'ip'     => $ip,
                'source' => 'remote',
                'note'   => '连接来源在可信代理名单里，但本次没有可用的转发头，退回用 REMOTE_ADDR',
            ];
        }

        return [
            'ip'     => $ip,
            'source' => 'xff',
            'note'   => '来自 X-Forwarded-For（因为 REMOTE_ADDR=' . $remote . ' 在 trusted_proxies 里）。'
                . '请确认你的前端确实覆写了这个头，否则它可以被客户端伪造',
        ];
    }
}

if (!function_exists('client_ip_hash')) {
    function client_ip_hash(): string
    {
        return substr(hash_hmac('sha256', client_ip(), (string) Config::get('app.hmac_secret', 'mcfix')), 0, 32);
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('require_post')) {
    function require_post(): void
    {
        if (!is_post()) {
            json_fail('请使用 POST 请求', 405);
        }
    }
}

if (!function_exists('is_https')) {
    function is_https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}

if (!function_exists('abs_url')) {
    /**
     * 生成绝对地址；配置了 base_url 就用它，否则按当前请求推断。
     */
    function abs_url(string $path = ''): string
    {
        $base = rtrim((string) Config::get('app.base_url', ''), '/');
        if ($base === '') {
            $scheme = is_https() ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
            $base = $scheme . '://' . $host . $scriptDir;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return abs_url($path);
    }
}

if (!function_exists('asset')) {
    /**
     * 静态资源加时间戳，避免升级后浏览器缓存旧 CSS。
     */
    function asset(string $path): string
    {
        $file = MCFIX_ROOT . '/public/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : MCFIX_VERSION;

        return url($path) . '?v=' . $version;
    }
}

if (!function_exists('cli_php_path')) {
    /**
     * 猜一个能用来跑 CLI 脚本的 php 可执行文件。
     *
     * 为什么不能直接用 PHP_BINARY：宝塔（以及绝大多数面板）下网页请求跑在 PHP-FPM 里，
     * PHP_BINARY 是 /www/server/php/83/sbin/php-fpm，把它填进「计划任务」只会启动一个
     * FPM 守护进程，bin/mcfix.php 根本不会被执行。所以这里优先 PHP_BINDIR（同目录的 php 可执行文件），
     * 并显式排掉任何名字里带 fpm 的路径。
     */
    function cli_php_path(): string
    {
        $candidates = [];

        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidates[] = rtrim(str_replace('\\', '/', PHP_BINDIR), '/') . '/php';
        }
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(PHP_BINARY, 'fpm') === false) {
            $candidates[] = PHP_BINARY;
        }
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }

        // 宝塔的默认安装位置兜底（按版本从新到旧）
        foreach ([
            '/www/server/php/84/bin/php',
            '/www/server/php/83/bin/php',
            '/www/server/php/82/bin/php',
            '/www/server/php/81/bin/php',
            '/www/server/php/80/bin/php',
            '/www/server/php/74/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ] as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }

        // 实在找不到就让用户自己在 PATH 里解决，页面上的说明会提醒先 which php
        return 'php';
    }
}

if (!function_exists('redact_paths')) {
    /**
     * 抹掉绝对路径里的目录，只留文件名：`/home/xxx/minecraft/logs/latest.log` → `…/latest.log`
     *
     * 为什么玩家侧需要：诊断消息里会带服务端的真实路径
     * （例如「日志文件不存在或不可读：/home/xxx/minecraft/logs/latest.log」），
     * 而持有工单链接的玩家能看到这些消息（progress → playerChecks → 页面）。
     *
     * 路径对玩家本来就没用 —— 他既改不了服务端文件，也不该知道服务器上的
     * 用户名和目录结构。**管理员侧不经过这里**，看到的仍是完整路径。
     *
     * 注意 lookbehind：前面不能是 `:`、`/` 或字母数字，
     * 否则会把 `http://example.com/a/b` 这种 URL 也当成文件路径处理掉。
     */
    function redact_paths(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return (string) preg_replace(
            '#(?<![:/\w])(?:/[\w.\-]+)+/([\w.\-]+)#u',
            '…/$1',
            $text
        );
    }
}

if (!function_exists('ticket_server_name')) {
    /**
     * 工单的"服务器"显示名。
     *
     * 纯客户端工单（server_id 为空字符串）没有服务器，这里给一句可读的占位文案，
     * 而不是留空 —— 玩家邮箱和工单页上都会用到。
     *
     * 为什么值得单独一个函数：原来的写法是
     * `Config::server($id)['name'] ?? $id`，服务器为空时前半截取到 null、
     * `??` 接住后半截的空字符串，于是邮件里印出「服务器：」、页面上是一段空白。
     * 注意 `??` 自带 isset 语义，**不会**因此报 PHP 警告 —— 这不是"修警告"，
     * 是修显示，顺便把抄了三遍的取值逻辑收到一处。
     */
    function ticket_server_name(string $serverId): string
    {
        $serverId = trim($serverId);
        if ($serverId === '') {
            return '不涉及服务器（客户端问题）';
        }

        $server = Config::server($serverId);

        return $server !== null ? (string) ($server['name'] ?? $serverId) : $serverId;
    }
}

if (!function_exists('server_public_label')) {
    /**
     * 服务器对**玩家**展示的标签：代号 + 名字（例如「S1・生存服 1.20.1」）。
     *
     * 为什么必须走这个函数，而不是在模板里拼 `name`：
     * 玩家反馈页原来直接把 `host:port` 印给玩家看（下拉框和服务器卡片两处），
     * 等于把 MC 服务端的连接地址公开给所有拿到链接的人。这是信息泄露，
     * 不是显示问题 —— 拿到地址就能直接扫端口、打 DDoS、绕过白名单尝试直连。
     *
     * 所以玩家侧的展示一律走这里，只出代号和名字，**永远不出 host / port**。
     * 后台（管理员自己看的页面）仍然照常显示地址，那里需要它来核对配置。
     *
     * `code` 是新增的配置项，留空时退回用 `name`（老配置不改也能正常跑，
     * 只是玩家看到的就是纯名字，不会突然变成空白）。
     */
    function server_public_label(string $serverId): string
    {
        $serverId = trim($serverId);
        if ($serverId === '') {
            return '不涉及服务器（客户端问题）';
        }

        $server = Config::server($serverId);
        if ($server === null) {
            return $serverId;
        }

        $name = trim((string) ($server['name'] ?? ''));
        if ($name === '') {
            $name = $serverId;
        }

        $code = trim((string) ($server['code'] ?? ''));
        if ($code === '') {
            return $name;   // 没配代号就只出名字，不硬造一个
        }

        return $code . '・' . $name;
    }
}

if (!function_exists('mask_secret_url')) {
    /**
     * 把 URL 里的密钥抹掉，用于打日志、写事件、CLI 输出。
     *
     * 为什么要单独一个函数：面板 API 的密钥形态太多了 ——
     *   - MCSManager 放在查询串：?apikey=xxx
     *   - 宝塔放在查询串：?request_token=...&request_sign=...
     *   - Telegram 直接放在路径上：/bot<token>/sendMessage
     *   - 一部分自研面板放在路径第一段：/api/<32 位十六进制>/command
     *   - 翼龙放在 Authorization 头（不在这里，调用方也要注意）
     *
     * 这些字符串会被写进 `events` 表、`storage/logs` 和 CLI 输出，
     * 而「操作日志」是管理员的常规界面 —— 密钥必须在这里就断掉。
     */
    function mask_secret_url(string $url): string
    {
        // 1) 查询串里的密钥参数
        $url = preg_replace(
            '/((?:key|token|secret|sign|apikey|api_key|access_token|sendkey|password|passwd|pwd)=)([^&\s]{2})[^&\s]*/i',
            '$1$2***',
            $url
        ) ?? $url;

        // 2) Telegram 机器人令牌：/bot123456:AA.../ → /bot1234***
        $url = preg_replace('#(/bot)(\d{4,})[^/\s]*#i', '$1$2***', $url) ?? $url;

        // 3) 路径里单独一段很长的随机串（自研面板常见）
        $url = preg_replace_callback(
            '#(https?://[^/\s]+/)([A-Za-z0-9_\-]{24,})(?=/|\s|$)#i',
            static function (array $m): string {
                return $m[1] . substr($m[2], 0, 4) . '***';
            },
            $url
        ) ?? $url;

        return $url;
    }
}

if (!function_exists('expects_json')) {
    function expects_json(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requested = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return stripos($accept, 'application/json') !== false || strtolower($requested) === 'xmlhttprequest';
    }
}

if (!function_exists('rate_guard')) {
    /**
     * 限流；超限直接返回 JSON 错误并结束请求。
     */
    function rate_guard(string $bucket, int $limit, int $windowSeconds = 3600): void
    {
        $state = Rate::hit($bucket . ':' . client_ip_hash(), $limit, $windowSeconds);
        if (!$state['allowed']) {
            $minutes = max(1, (int) ceil($state['retry_after'] / 60));
            json_fail(
                "操作太频繁了，请 {$minutes} 分钟后再试",
                429,
                ['retry_after' => $state['retry_after']]
            );
        }
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        $base = MCFIX_ROOT . '/storage';
        if ($append === '') {
            return $base;
        }

        return $base . '/' . ltrim($append, '/');
    }
}

if (!function_exists('ensure_dir')) {
    function ensure_dir(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }
        if (@mkdir($path, 0755, true) || is_dir($path)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('render_layout')) {
    /**
     * 用布局包裹视图。
     *
     * 放在 helpers 里而不是 index.php，是因为后台入口在 index.php 的早期就被
     * require 了（早于 index.php 中段的函数定义）—— 否则后台登录页会报
     * "Call to undefined function render_layout()"。
     *
     * @param array<string,mixed> $vars
     * @param callable():void $content
     */
    function render_layout(array $vars, callable $content): void
    {
        $title = (string) ($vars['title'] ?? 'MC 服务器故障反馈中心');
        $siteName = (string) ($vars['siteName'] ?? 'MC 故障反馈中心');
        $headNav = is_array($vars['headNav'] ?? null) ? $vars['headNav'] : [];
        // 后台会传 admin-body，用来去掉玩家页那个窄栏宽度限制（见 app.css 里的 .admin-body）
        $bodyClass = trim((string) ($vars['bodyClass'] ?? ''));

        ob_start();
        $content();
        $content = (string) ob_get_clean();

        require MCFIX_ROOT . '/views/layout.php';
    }
}

if (!function_exists('render_pager')) {
    /**
     * 分页控件。
     *
     * 只画"首尾 + 当前页附近"这十来个链接，中间用省略号跳过 ——
     * 一开始的写法是 for ($i=1; $i<=$pages; $i++)，工单攒到几千条之后
     * 页面里就会有几千个 <a>，又慢又没法看。
     *
     * @param callable(int):string $urlFor 传入页码，返回该页地址
     */
    function render_pager(int $page, int $pages, callable $urlFor, int $window = 2): void
    {
        if ($pages <= 1) {
            return;
        }

        $page = max(1, min($page, $pages));

        // 需要显示的页码：首、尾、当前页前后 window 个
        $show = [1, $pages];
        for ($i = $page - $window; $i <= $page + $window; $i++) {
            if ($i >= 1 && $i <= $pages) {
                $show[] = $i;
            }
        }
        // 再加"上一页/下一页"，否则从第 1 页跳到第 3 页要点两次
        if ($page > 1) {
            $show[] = $page - 1;
        }
        if ($page < $pages) {
            $show[] = $page + 1;
        }

        $show = array_values(array_unique($show));
        sort($show);

        echo '<nav class="pager">';

        if ($page > 1) {
            echo '<a href="' . e($urlFor($page - 1)) . '" rel="prev">上一页</a>';
        }

        $prev = 0;
        foreach ($show as $i) {
            if ($prev !== 0 && $i > $prev + 1) {
                echo '<span class="pager-gap">…</span>';
            }
            if ($i === $page) {
                echo '<span class="is-current">' . $i . '</span>';
            } else {
                echo '<a href="' . e($urlFor($i)) . '">' . $i . '</a>';
            }
            $prev = $i;
        }

        if ($page < $pages) {
            echo '<a href="' . e($urlFor($page + 1)) . '" rel="next">下一页</a>';
        }

        echo '<span class="pager-total">共 ' . $pages . ' 页</span>';
        echo '</nav>';
    }
}

if (!function_exists('render_404')) {
    /**
     * 输出一个"什么都没透露"的 404。
     *
     * 后台入口是私有路径，被扫的时候要表现得和一个普通不存在的页面完全一样 ——
     * 不能有任何"这里是管理后台"的痕迹，否则等于告诉扫描器找对方向了。
     */
    function render_404(string $hint = ''): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        $extra = $hint !== ''
            ? '<p style="color:#8a94a6;font-size:13px">' . e($hint) . '</p>'
            : '';

        exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>404 Not Found</title></head>'
            . '<body style="margin:0;background:#0b0f14;color:#e7edf4;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,\'PingFang SC\',\'Microsoft YaHei\',sans-serif">'
            . '<div style="max-width:520px;margin:12vh auto;padding:0 20px">'
            . '<h2 style="margin:0 0 10px;font-size:22px">404 Not Found</h2>'
            . '<p style="color:#93a1b1;margin:0 0 14px">请求的页面不存在。</p>'
            . $extra
            . '<p style="margin-top:22px"><a href="' . e(abs_url('')) . '" style="color:#22d3ee">返回首页</a></p>'
            . '</div></body></html>');
    }
}

if (!function_exists('render_message_page')) {
    /**
     * 输出一个极简提示页（403 / 429 之类），不带站点导航，避免暴露后台存在。
     */
    function render_message_page(string $title, string $message, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . '</title></head>'
            . '<body style="margin:0;background:#0b0f14;color:#e7edf4;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,\'PingFang SC\',\'Microsoft YaHei\',sans-serif">'
            . '<div style="max-width:520px;margin:12vh auto;padding:0 20px">'
            . '<h2 style="margin:0 0 10px;font-size:22px">' . e($title) . '</h2>'
            . '<p style="color:#93a1b1;margin:0">' . e($message) . '</p>'
            . '</div></body></html>');
    }
}

if (!function_exists('redact_secrets')) {
    /**
     * 抹掉文本里的**凭据形态**：API 密钥、Bearer 令牌、长随机串。
     *
     * 和 redact_paths()/AiAdvisor::redact() 的分工：
     *   - redact_paths()  —— 给玩家看之前抹掉服务端路径
     *   - 本函数          —— 只抹凭据，**保留 IP 和邮箱**
     *
     * 为什么保留 IP：日志里"哪个 IP 被白名单拒了""哪个 IP 登录失败"是排查时最有用
     * 的一条信息，抹掉日志就没法用了。所以这里只保证"密钥永远不进日志"，
     * 不做全面脱敏 —— 全面脱敏应该发生在调用点（比如 TicketMail 自己会用
     * maskAddress 处理邮箱）。
     *
     * 为什么需要一个统一的定义：上游服务经常把密钥原样回显在报错里
     * （"Invalid API key: sk-..."），这类文本会被一路带进 app 日志和 events 表。
     * 靠每个调用点自觉是守不住的，所以在写日志这一层兜一道。
     */
    function redact_secrets(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // OpenAI 风格：sk-xxx / sk-proj-xxx
        $text = (string) preg_replace('#\bsk-[A-Za-z0-9_\-]{8,}#', '<key>', $text);
        // GitHub：ghp_ / gho_ / ghu_ / ghs_ / github_pat_
        $text = (string) preg_replace('#\b(?:ghp_|gho_|ghu_|ghs_|github_pat_)[A-Za-z0-9_]{10,}#', '<key>', $text);
        // 显式的 Bearer / api_key= / apikey: 后面跟的东西
        $text = (string) preg_replace('#(?i)\b(bearer)\s+[A-Za-z0-9._\-]{12,}#', '$1 <key>', $text);
        $text = (string) preg_replace('#(?i)\b(api[_-]?key|access[_-]?token|secret)["\']?\s*[:=]\s*["\']?[A-Za-z0-9._\-]{12,}#', '$1=<key>', $text);
        // 很长的纯随机串（>=40 位十六进制/base64url）：本项目的 HMAC 令牌就是这种形态
        $text = (string) preg_replace('#\b[A-Za-z0-9_\-]{40,}\b#', '<token>', $text);

        return $text;
    }
}

if (!function_exists('redact_secrets_deep')) {
    /**
     * 对数组/标量递归套 redact_secrets()，给 app_log 的 context 用。
     *
     * @param mixed $value
     * @return mixed
     */
    function redact_secrets_deep($value)
    {
        if (is_string($value)) {
            return redact_secrets($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = redact_secrets_deep($v);
            }

            return $out;
        }

        return $value;
    }
}

if (!function_exists('app_log')) {
    /**
     * 应用日志，按天切分。宝塔面板可以直接在文件管理器里看。
     *
     * @param array<string,mixed> $context
     */
    function app_log(string $level, string $message, array $context = []): void
    {
        static $dirReady = false;
        $dir = storage_path('logs');
        if (!$dirReady) {
            ensure_dir($dir);
            $dirReady = true;
        }

        $line = sprintf(
            "[%s] %s %s %s\n",
            now(),
            strtoupper($level),
            // 兜一道：上游报错里常带着密钥回显，靠调用点自觉是守不住的
            redact_secrets($message),
            $context ? json_encode(redact_secrets_deep($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($dir . '/app-' . gmdate('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('record_event')) {
    /**
     * 写工单时间线。
     *
     * @param array<string,mixed> $payload
     */
    function record_event(
        ?int $feedbackId,
        string $actor,
        string $action,
        string $message = '',
        array $payload = [],
        string $level = 'info'
    ): void {
        static $available = null;
        if ($available === false) {
            return;
        }

        try {
            Db::insert('events', [
                'feedback_id' => $feedbackId,
                'actor'       => $actor,
                'action'      => $action,
                'level'       => $level,
                /*
                 * ★ 事件和日志**同一套脱敏标准**（V7）。
                 *
                 * 上面 redact_secrets 那段注释说得很清楚：「这些字符串会被写进
                 * events 表、storage/logs 和 CLI 输出，而『操作日志』是管理员的
                 * 常规界面 —— 密钥必须在这里就断掉。」
                 * 但 app_log() 做了脱敏，record_event() 没做 —— 同一个项目两套标准。
                 *
                 * 可达链路：面板适配器会把上游响应体塞进错误信息
                 * （PanelAdapter：'error' => $error . '：' . $snippet），
                 * 那段 body 里可能带面板 API 的凭据；一旦被记录成事件，
                 * 它就出现在玩家可见的时间线上。
                 *
                 * 脱敏是幂等的，重复调用无害，所以两层都过一遍。
                 */
                'message'     => mb_substr(redact_secrets($message), 0, 500),
                'payload'     => $payload
                    ? json_encode(redact_secrets_deep($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'created_at'  => now(),
            ]);
            $available = true;
        } catch (\Throwable $e) {
            $available = false;
            app_log('error', '写入事件失败', ['error' => $e->getMessage()]);
        }
    }
}

if (!function_exists('safe_json_decode')) {
    /**
     * @return array<string,mixed>
     */
    function safe_json_decode(?string $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}

if (!function_exists('status_label')) {
    function status_label(string $status): string
    {
        $map = [
            'submitted'   => '已提交',
            'diagnosing'  => '正在验证',
            'diagnosed'   => '验证完成',
            'fixing'      => '正在修复',
            'verifying'   => '正在复验',
            'resolved'    => '已解决',
            'manual'      => '待人工处理',
            'unresolved'  => '未解决',
            'rejected'    => '已驳回',
            'closed'      => '已关闭',
        ];

        return $map[$status] ?? $status;
    }
}

if (!function_exists('status_tone')) {
    function status_tone(string $status): string
    {
        $map = [
            'submitted'  => 'info',
            'diagnosing' => 'busy',
            'diagnosed'  => 'info',
            'fixing'     => 'busy',
            'verifying'  => 'busy',
            'resolved'   => 'ok',
            'manual'     => 'warn',
            'unresolved' => 'warn',
            'rejected'   => 'muted',
            'closed'     => 'muted',
        ];

        return $map[$status] ?? 'muted';
    }
}

if (!function_exists('resolve_locked_server_id')) {
    /**
     * 决定"这次提交到底该记在哪台服务器上"。
     *
     * 背景：玩家从某台服务器的专属分享链接进来时，表单被收窄成"只有那一台 +
     * 不涉及服务器"。但前端收窄只是不显示 —— 手工构一个 POST 就能改 server_id。
     * 所以必须在后端重新判定。这个函数就是那道判定，抽出来是为了能直接测：
     * 它不碰超全局变量、不写库，给什么返回什么。
     *
     * 判定来源按优先级：
     *   1. $shareToken 能解开 → 用令牌里那台（HMAC 签名，玩家伪造不了）；
     *   2. 否则用 $sessionLocked（访问反馈页时记下的）；
     *   3. 都没有 → 返回空字符串，表示"没有锁定"。
     *
     * 两条例外，都必须保留：
     *   - $postedServerId 为空 → 返回空。这是「不涉及服务器」的纯客户端工单，
     *     跟是哪台服无关，从专属链接进来的玩家同样要能报（模组冲突、Java 版本、
     *     内存不足）。锁定约束的是"哪台服"，不是"必须说成是这台服的问题"。
     *   - 锁定的那台服已经不存在（配置里删了）→ 当没有锁定，不把一个无效的
     *     id 写进工单。
     *
     * @param string               $postedServerId 表单里的 server_id
     * @param string               $shareToken     表单回传的分享令牌，可为空
     * @param string               $sessionLocked  session 里记下的锁定服务器，可为空
     * @param callable(string):bool|null $serverExists 判断某台服是否存在；默认查配置
     * @return string 最终应采用的 server_id；空字符串 = 不涉及服务器／无锁定
     */
    function resolve_locked_server_id(
        string $postedServerId,
        string $shareToken = '',
        string $sessionLocked = '',
        ?callable $serverExists = null
    ): string {
        if ($serverExists === null) {
            $serverExists = static function (string $id): bool {
                return Config::server($id) !== null;
            };
        }

        $lock = '';
        if (trim($shareToken) !== '') {
            $shared = Share::parse($shareToken);
            if ($shared !== null) {
                $lock = (string) $shared['server_id'];
            }
        }
        if ($lock === '') {
            $lock = trim($sessionLocked);
        }

        // 锁定的服已经不存在：当没有锁定。否则会把一个无效 id 写进工单，
        // Diagnosis 那边拿到空 server 数组，报错文案会很难解释。
        if ($lock !== '' && !$serverExists($lock)) {
            $lock = '';
        }

        $posted = trim($postedServerId);

        // 纯客户端工单：永远放行
        if ($posted === '') {
            return '';
        }

        // 有锁定且表单填的是另一台：改成锁定那台（不报错 —— 报错只会让玩家困惑）
        if ($lock !== '' && $posted !== $lock) {
            return $lock;
        }

        return $posted;
    }
}
