<?php
/**
 * MOD 下载入口。
 *
 * 路由：
 *   ?r=mod.download&f=<签名令牌>   下载一个文件（令牌绑定文件名，不能遍历目录）
 *
 * 为什么下载要签名令牌而不是直接用文件名：
 * 玩家手里的工单页面是公网可访问的，如果下载地址是 ?r=mod.download&f=xxx.jar，
 * 任何人都能猜文件名把服务器的 MOD 全下走。签名令牌绑定了文件名与有效期，
 * 只能下载"系统主动给他看的那个文件"。
 *
 * 这里没有"列目录"接口：管理员在「MOD 分发」页面直接读本地库，
 * 不需要再经一层 HTTP。（曾经有个 ?r=mod.list，但它永远返回 401 ——
 * 后台会话只在 admin.php 里初始化，走这个路由时根本没登录态。）
 */

declare(strict_types=1);

use MCFix\Config;
use MCFix\ModLibrary;

// 自己解析动作，保证这个控制器单独被 require 时也能工作
$modAction = $action ?? (string) ($_GET['action'] ?? 'download');
$file = param($_GET, 'f', param($_GET, 'file'));

if ($modAction === 'list') {
    // 保留一个明确的拒绝，别让它悄悄退化成"下载"分支
    json_fail('该接口已移除', 404);
}

if ($file === '') {
    if (expects_json()) {
        json_fail('缺少下载令牌', 400);
    }
    http_response_code(400);
    exit('缺少下载令牌');
}

$resolved = ModLibrary::resolveDownload($file);
if (!$resolved['ok']) {
    app_log('warn', 'MOD 下载失败', ['error' => $resolved['error'], 'ip' => client_ip()]);
    if (expects_json()) {
        json_fail($resolved['error'], 404);
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h3>下载链接已失效</h3><p>' . e($resolved['error']) . '</p>'
        . '<p>请回到反馈页面重新打开工单，页面上的下载按钮会自动刷新链接。</p>');

    return;
}

record_event(null, 'player', 'mod.download', '玩家下载了 ' . $resolved['filename'], [
    'ip' => client_ip_hash(),
]);

ModLibrary::stream($resolved['path'], $resolved['filename']);
exit;
