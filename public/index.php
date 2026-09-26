<?php
/**
 * MC 服务器故障反馈与自动修复系统 —— 唯一入口。
 *
 * 宝塔部署时把「网站 → 运行目录」指向本目录（public），所有源码、配置、数据都在上一级，
 * 因此别人用浏览器访问不到 config.php、SQLite 库与日志。
 *
 * 路由规则（都用 query string，不需要伪静态，宝塔里不用改任何 rewrite）：
 *   /                      玩家反馈首页
 *   ?r=feedback&t=TOKEN    带令牌的反馈入口（服务器级公开链接 / 工单链接）
 *   ?r=install             安装向导
 *   ?r=admin[.page]        管理后台
 *   ?r=api.<action>        玩家侧 JSON 接口
 *   ?r=api.agent.<action>  MC 侧 Agent 接口
 *   ?r=health              健康检查（给监控用，不含敏感信息）
 */

declare(strict_types=1);

define('MCFIX_ROOT', dirname(__DIR__));

require MCFIX_ROOT . '/src/bootstrap.php';

use MCFix\Config;

// ---------------------------------------------------------------- 路由解析

$rawRoute = '';
if (isset($_GET['r'])) {
    $rawRoute = (string) $_GET['r'];
} elseif (isset($_SERVER['PATH_INFO'])) {
    $rawRoute = ltrim((string) $_SERVER['PATH_INFO'], '/');
}

$rawRoute = trim(str_replace('..', '', $rawRoute), " \t\n\r\0\x0B/");
$parts = array_values(array_filter(explode('.', $rawRoute), static function (string $p): bool {
    return $p !== '';
}));

$controller = $parts[0] ?? 'feedback';
/*
 * 动作名要按 '.' **全部**切下来，不能只取 $parts[1]（F3）。
 *
 * 原来只取 $parts[1]，于是 ?r=api.agent.report 里的 'report' 被丢掉，
 * $action 只剩 'agent'，下面第 163-164 行再把它剥成空串 → agent.php 落到
 * 默认动作 poll。而 poll 恰好也是默认值，所以"看起来能用"，
 * 其余动作（report / log / mod_upload…）就全部静默失效 ——
 * 必须写成 ?r=api&action=report 才行，但 api.php 的注释明明写的是
 * ?r=api.<action>。
 *
 * 拼回 'agent.report' 之后，第 164 行的 substr(…, strlen('agent.')) 正好
 * 把它还原成 'report'，与既有逻辑严丝合缝。
 */
$action = implode('.', array_slice($parts, 1));

// ---------------------------------------------------------------- 入口分流
//
// 支持两种部署形态：
//
//   ① 双域名（推荐）：后台走独立域名，玩家侧走反馈域名
//        https://mc.example.com/         → 后台
//        https://fankui.example.com/     → 玩家反馈
//      同源策略天然隔离，后台域名还能单独限制来源 IP。
//
//   ② 单域名 + 私有路径（向后兼容）：
//        https://fankui.example.com/?r=console-xxxx  → 后台
//
// 判定顺序：先看 Host 是不是后台域名 → 再看路径是不是后台私有路径。
$requestHost = \MCFix\ConsoleAuth::requestHost();
$consoleHostMode = Config::ready() && \MCFix\ConsoleAuth::hostMode();

if ($consoleHostMode && \MCFix\ConsoleAuth::isConsoleHost($requestHost)) {
    // 后台域名上的任何路径都进后台；页面名用 ?p=xxx 指定
    $action = param($_GET, 'p', '');
    require MCFIX_ROOT . '/public/controllers/admin.php';
    exit;
}

if (Config::ready() && $rawRoute !== '' && \MCFix\ConsoleAuth::matches($rawRoute)) {
    // 私有路径模式：在反馈域名上命中后台路径
    if ($consoleHostMode && \MCFix\ConsoleAuth::isFeedbackHost($requestHost)) {
        // 已切到双域名，反馈域名上不再暴露后台入口
        render_404();
    }

    $action = \MCFix\ConsoleAuth::pageFrom($rawRoute);
    require MCFIX_ROOT . '/public/controllers/admin.php';
    exit;
}

// ?r=admin 从来不是对外入口：给一个不带任何信息的 404，避免暴露后台位置
if ($controller === 'admin') {
    render_404();
}

// 兼容 ?r=api&action=submit 以及直接访问 ?r=agent 的写法
if ($controller === 'agent') {
    $controller = 'api';
    $action = 'agent' . ($action !== '' ? '.' . $action : '');
}

// ?r=api 后面用 &action=xxx 指定动作（Agent 文档与外部调用常用这种写法）。
// 路由只从 ?r= 里拆出控制器，所以这里要回退读一次 action 参数 ——
// 否则 ?r=api&action=submit 会因为 $action 为空而落到 "未知接口"。
if ($controller === 'api' && $action === '') {
    $action = param($_GET, 'action', '');
}

// ?r=mod.download —— MOD 分发
if ($controller === 'mod') {
    $modAction = $action === '' ? param($_GET, 'action', 'download') : $action;
    $action = $modAction;
    $controller = 'mod';
}

// ---------------------------------------------------------------- 通用响应头

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

// ---------------------------------------------------------------- 健康检查
//
// 这个地址默认是对外的，所以匿名访问只给"活着没有"这一件事，外加版本号。
// 具体的自检结论（缺哪个扩展、哪个目录不可写、有几台服务器）属于站点内部信息，
// 只在请求来自本机时返回 —— 本机监控脚本用得到，外面的扫描器拿不到。
// 想远程看详细自检，请用后台的「系统设置 → 环境自检」或 php bin/mcfix.php doctor。
if ($controller === 'health') {
    $installed = Config::ready();
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $isLocal = in_array($remote, ['127.0.0.1', '::1'], true);

    $payload = [
        'ok'        => true,
        'app'       => 'mcfix',
        'version'   => MCFIX_VERSION,
        'time'      => now(),
        'installed' => $installed,
    ];

    if ($isLocal && $installed) {
        $problems = Config::diagnose();
        $payload['php'] = PHP_VERSION;
        $payload['servers'] = count(Config::servers());
        $payload['problems'] = $problems;
        $payload['ok'] = $problems === [];
        json_out($payload, $payload['ok'] ? 200 : 503);
    }

    json_out($payload, 200);
}

// ---------------------------------------------------------------- 视图渲染
//
// render_layout() 与 render_404() 都定义在 src/helpers.php 里 ——
// 后台入口在本文件早期就被 require，那时这里的函数定义还没执行到，所以要提前可用。

// ---------------------------------------------------------------- 未安装则引导

if (!Config::ready() && $controller !== 'install') {
    if ($controller === 'api') {
        json_fail('系统尚未完成安装，请管理员访问 ?r=install 完成初始化', 503);
    }
    header('Location: ' . abs_url('?r=install'));
    exit;
}

// ---------------------------------------------------------------- 分发

switch ($controller) {
    case 'api':
        // Agent 专用接口单独一个控制器（它们用 server_id + token 鉴权，不走玩家侧限流）
        if ($action === 'agent' || strpos($action, 'agent.') === 0) {
            $action = $action === 'agent' ? '' : substr($action, strlen('agent.'));
            require MCFIX_ROOT . '/public/controllers/agent.php';
            break;
        }
        require MCFIX_ROOT . '/public/controllers/api.php';
        break;

    case 'admin':
        require MCFIX_ROOT . '/public/controllers/admin.php';
        break;

    case 'install':
        require MCFIX_ROOT . '/public/controllers/install.php';
        break;

    case 'mod':
        require MCFIX_ROOT . '/public/controllers/mod.php';
        break;

    case 'feedback':
    default:
        require MCFIX_ROOT . '/public/controllers/feedback.php';
        break;
}
