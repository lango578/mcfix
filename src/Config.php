<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 配置容器：负责定位 config/config.php（或环境变量）并做基础校验。
 */
final class Config
{
    private const ENV_MAP = [
        'MCFIX_BASE_URL'  => 'app.base_url',
        'MCFIX_PASSWORD'  => 'app.admin_password',
        'MCFIX_SECRET'    => 'app.hmac_secret',
        'MCFIX_DB_PATH'   => 'db.path',
        'MCFIX_SERVERS'   => 'servers',
        'MCFIX_TIMEZONE'  => 'app.timezone',
    ];

    /** @var array<string,mixed> */
    private static $items = [];

    private static $root = '';

    private static $loaded = false;

    /**
     * 载入配置。返回是否找到配置文件（未找到时入口应跳转安装向导）。
     */
    public static function bootstrap(?string $root = null): bool
    {
        self::$root = $root ?? (defined('MCFIX_ROOT') ? MCFIX_ROOT : dirname(__DIR__));
        self::$loaded = true;

        $file = self::path();
        if (is_file($file)) {
            // 先让 opcache 检查一次时间戳。opcache 默认每 2 秒才 revalidate 一次，
            // 而我们会在后台改配置后立刻跳转读它 —— 不主动失效就会读到上一版。
            // force=false 表示"只有真的改了才失效"，没改时几乎零开销。
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, false);
            }

            /** @var mixed $data */
            $data = require $file;
            if (is_array($data)) {
                self::$items = $data;
            }
        }

        // 环境变量覆盖（宝塔的"网站 → 配置文件"里也能加，方便容器化）
        foreach (self::ENV_MAP as $env => $key) {
            $raw = getenv($env);
            if ($raw === false || $raw === '') {
                continue;
            }
            $decoded = null;
            if ($key === 'servers') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    continue;
                }
            } else {
                $decoded = $raw;
            }
            self::set($key, $decoded);
        }

        return is_file($file);
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function path(): string
    {
        $env = getenv('MCFIX_CONFIG');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::$root . '/config/config.php';
    }

    public static function ready(): bool
    {
        return self::$loaded && is_file(self::path());
    }

    /**
     * 点号取配置：Config::get('servers') / Config::get('automation.auto_fix', true)
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if ($key === '') {
            return self::$items;
        }

        $node = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }

            return $default;
        }

        return $node;
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $node = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
    }

    /**
     * 所有启用的服务器，id 为键。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function servers(): array
    {
        $servers = self::get('servers', []);
        $out = [];
        if (!is_array($servers)) {
            return $out;
        }

        foreach ($servers as $server) {
            if (!is_array($server) || empty($server['id'])) {
                continue;
            }
            if (isset($server['enabled']) && $server['enabled'] === false) {
                continue;
            }
            $out[(string) $server['id']] = $server;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function server(string $id): ?array
    {
        $servers = self::servers();

        return $servers[$id] ?? null;
    }

    /**
     * 把配置写回 config/config.php（安装向导、后台改设置用）。
     *
     * @param array<string,mixed> $items
     */
    public static function save(array $items): bool
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $export = var_export($items, true);
        $body = "<?php\n"
            . "/**\n"
            . " * 本文件由 MC 故障反馈系统自动生成（" . gmdate('Y-m-d H:i:s') . " UTC）。\n"
            . " * 修正语法错误即可恢复；删除本文件会重新进入安装向导。\n"
            . " */\n\n"
            . "return " . $export . ";\n";

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);

        // 让新文件的属主跟着**配置目录**走，而不是跟着"谁在跑"。
        //
        // 为什么必须做：config.php 是被 php-fpm（以 www 运行）require 的。
        // 如果有人从命令行以 root 触发一次保存 —— root 的 crontab 里跑
        // `mcfix cron`、运维手敲一条 `php -r` 调后台动作、或者部署脚本里的自检 ——
        // rename 出来的文件就是 root:root 0640，php-fpm **读不到配置，整站立刻 500**。
        // 线上真踩过一次（1.12.9 的部署自检把整站打成 500）。
        //
        // 非 root 进程 chown 会失败，但那种情况下文件属主本来就是当前进程，
        // 而当前进程既然能写这个目录，也就能读，所以 @ 抑制掉即可。
        $dirOwner = @fileowner($dir);
        if ($dirOwner !== false) {
            @chown($tmp, $dirOwner);
        }
        $dirGroup = @filegroup($dir);
        if ($dirGroup !== false) {
            @chgrp($tmp, $dirGroup);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        self::$items = $items;

        // 关键：让 opcache 忘掉旧版本。
        //
        // config.php 是一个被 require 的 PHP 文件，所以它也在 opcache 的缓存里。
        // opcache 默认 revalidate_freq=2（两秒才检查一次文件有没有变），于是
        // "保存配置 → 下一个请求" 之间可能读到**上一次的内容** ——
        // 表现出来就是「刚添加完服务器，点配置却说服务器不存在」这种很邪门的问题。
        // 写完之后主动失效一次，跨进程也生效（opcache 是共享内存）。
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }

    /**
     * 所有服务器，**包含已停用的**，id 为键。
     *
     * 为什么要和 servers() 分开：servers() 过滤掉 enabled=false 的，那是给
     * 玩家侧和诊断逻辑用的（停用的服不该出现在反馈页上）。但后台必须能看到停用的服，
     * 否则「启用该服务器」这个复选框一旦取消勾选，那台服就从界面上彻底消失，
     * 再也点不回来了 —— 只能去改 config.php。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function allServers(): array
    {
        $servers = self::get('servers', []);
        $out = [];
        if (!is_array($servers)) {
            return $out;
        }

        foreach ($servers as $server) {
            if (!is_array($server) || empty($server['id'])) {
                continue;
            }
            $out[(string) $server['id']] = $server;
        }

        return $out;
    }

    /**
     * 按 id 取服务器，**不过滤 enabled** —— 后台改配置 / 重新启用时用这个。
     *
     * @return array<string,mixed>|null
     */
    public static function anyServer(string $id): ?array
    {
        $servers = self::allServers();

        return $servers[$id] ?? null;
    }

    /**
     * 配置自检，返回问题列表。后台首页与 CLI doctor 都会用。
     *
     * @return string[]
     */
    public static function diagnose(): array
    {
        $problems = [];

        if (!is_file(self::path())) {
            $problems[] = '尚未完成安装：缺少 config/config.php';

            return $problems;
        }

        $secret = (string) self::get('app.hmac_secret', '');
        if ($secret === '' || strpos($secret, 'CHANGE_ME') !== false || strlen($secret) < 16) {
            $problems[] = 'app.hmac_secret 未设置为足够长的随机字符串，反馈链接可被伪造';
        }

        /*
         * 后台入口路径检查（V3）。
         *
         * 后台地址不是"配置里的字面量"，就是 hmac_secret 的纯函数：
         *     'console-' . substr(hash('sha256', 'console-path|' . $seed), 0, 12)
         *
         * 所以只要两件事同时成立，攻击者**不用访问站点**就能离线算出后台地址：
         *   1. admin.path 没配（走派生逻辑），且
         *   2. hmac_secret 还是占位符 / 为空（$seed 退化成公开值）
         *
         * 这是"把随机路径当成防护层"的典型失效 —— 看起来是随机串，其实
         * 由公开字面量决定。ConsoleAuth 那边已经改用统一占位符名单（见该文件），
         * 这里补上自检，让管理员在后台就能看到这件事。
         */
        if (trim((string) self::get('admin.path', ''), '/') === '') {
            $seed = $secret !== '' && !Token::isPlaceholderSecret($secret)
                ? $secret
                : (string) self::get('app.base_url', '');
            if ($seed === '' || Token::isPlaceholderSecret($seed) || strpos($seed, 'CHANGE_ME') !== false) {
                $problems[] = '后台地址可被离线推算：admin.path 没配，而它是由 app.hmac_secret 派生的，'
                    . '当前 hmac_secret 还是占位符（或为空），任何人照着公开仓库的默认值就能算出后台地址。'
                    . '请把 app.hmac_secret 设成足够长的随机串，或直接显式配置 admin.path。';
            }
        }

        $password = (string) self::get('app.admin_password', '');
        if ($password === '') {
            $problems[] = '未设置后台密码，任何人都能进入管理后台';
        } elseif (strlen($password) < 30 && strpos($password, '$2y$') !== 0 && strpos($password, '$argon2') !== 0) {
            $problems[] = 'app.admin_password 不是 password_hash() 生成的哈希，建议重新设置密码';
        }

        $baseUrl = (string) self::get('app.base_url', '');
        if ($baseUrl === '' || strpos($baseUrl, 'http') !== 0) {
            $problems[] = 'app.base_url 未设置为完整 URL（例如 https://mc.example.com）';
        }

        if (!self::servers()) {
            $problems[] = '还没有配置任何服务器，玩家无从选择';
        }

        /*
         * 转发头信任检查（V2）。
         *
         * 这是唯一一处"配置本身会造成漏洞"的地方，所以值得在自检里点名：
         * 后台 IP 白名单和登录失败锁定都建立在 client_ip() 上，而它是否采信
         * X-Forwarded-For 完全取决于 trusted_proxies。
         *
         * 只要 REMOTE_ADDR 恒为 127.0.0.1（PHP 内置服务器、nginx 以 TCP 转发给
         * php-fpm、各种透明代理），而 trusted_proxies 里又有 127.0.0.1，那么
         * 任何客户端自填的 XFF 都会被当成真实来源 —— 白名单被一个请求头绕过，
         * 失败锁定因为"每次换一个 IP"而永不触发。
         */
        $trusted = array_map('strval', (array) self::get('trusted_proxies', []));
        if (in_array('127.0.0.1', $trusted, true) || in_array('::1', $trusted, true)) {
            $problems[] = 'trusted_proxies 里包含回环地址（127.0.0.1 / ::1）：'
                . '只有当你的反向代理**确实覆写**了 X-Forwarded-For 时才安全。'
                . '很多部署下 REMOTE_ADDR 本来就恒为 127.0.0.1，此时任意客户端'
                . '自填的 X-Forwarded-For 都会被当作真实来源，后台 IP 白名单与'
                . '登录失败锁定会同时失效。不确定就把它清空成 []。';
        }

        if (!empty(self::get('app.admin_password', ''))) {
            $source = client_ip_source();
            if ($source['source'] === 'xff') {
                $problems[] = '当前请求的来源 IP 是按 X-Forwarded-For 判定的（' . $source['ip'] . '）：'
                    . '请确认前端是覆写而不是追加该头，否则 IP 白名单与失败锁定可被伪造。';
            }
        }

        foreach (self::servers() as $id => $server) {
            $executor = (string) ($server['executor'] ?? 'none');
            if (in_array($executor, ['agent', 'ssh'], true) === false && $executor !== 'local' && $executor !== 'none') {
                $problems[] = "服务器 {$id} 的 executor 取值非法：{$executor}";
            }
            if ($executor === 'agent' && empty($server['agent_token'])) {
                $problems[] = "服务器 {$id} 使用 agent 执行器但没有配置 agent_token";
            }
            if (!empty($server['rcon']['enabled']) && empty($server['rcon']['password'])) {
                $problems[] = "服务器 {$id} 启用了 RCON 但没填密码";
            }
        }

        $dataDir = self::root() . '/storage/data';
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            $problems[] = 'storage/data 目录不存在且无法创建，请检查网站目录写权限';
        } elseif (!is_writable($dataDir)) {
            $problems[] = 'storage/data 目录不可写，宝塔里执行：chown -R www:www storage && chmod -R 775 storage';
        }

        return $problems;
    }
}
