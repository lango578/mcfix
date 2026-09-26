<?php
/**
 * 引导文件：载入配置、注册自动加载、初始化运行时。
 * 任何入口（public/index.php、bin/mcfix.php、agent 脚本）都先 require 这个文件。
 *
 * @var string $__MCFIX_ROOT 项目根目录绝对路径
 */

declare(strict_types=1);

if (!defined('MCFIX_ROOT')) {
    define('MCFIX_ROOT', dirname(__DIR__));
}

if (!defined('MCFIX_VERSION')) {
    define('MCFIX_VERSION', '1.13.2');
}

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('MC 故障反馈系统需要 PHP 7.4 或更高版本，当前版本：' . PHP_VERSION);
}

foreach (['pdo', 'pdo_sqlite', 'json', 'mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        $msg = '缺少 PHP 扩展：' . $ext . '。请在宝塔【软件商店 → PHP 设置 → 安装扩展】中安装后重试。';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        exit('<h3>环境不满足</h3><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p>');
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'MCFix\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = MCFIX_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once MCFIX_ROOT . '/src/helpers.php';

use MCFix\Config;

Config::bootstrap();

date_default_timezone_set(Config::get('app.timezone', 'Asia/Shanghai'));

if (Config::get('app.debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
    ini_set('display_errors', '0');
}

// 会话按入口隔离：
//   玩家侧   → mcfix_sid，cookie 作用域为站点根
//   管理后台 → mcfix_console，cookie 作用域只覆盖后台那条私有路径，两边互不可见
// 这里保持中立，由各自的控制器显式初始化 —— 避免"访问一次反馈页就顺手创建了后台会话"。
if (PHP_SAPI !== 'cli' && !function_exists('mcfix_start_session')) {
    /**
     * 启动一个受路径约束的会话。
     *
     * @param string $name  会话名
     * @param string $scope cookie 作用路径（后台传它自己的私有路径，实现与玩家侧隔离）
     */
    function mcfix_start_session(string $name, string $scope = '/'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() === $name) {
                return;
            }
            // 已经开了另一个会话：先落盘再切，避免两个会话混在一起
            session_write_close();
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $scope,
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Lax',
        ]);
        session_name($name);
        session_start();
    }
}
