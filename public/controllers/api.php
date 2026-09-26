<?php
/**
 * 玩家侧 JSON 接口。
 *
 * 路由（?r=api.<action>）：
 *   bootstrap   取服务器列表与在线状态
 *   submit      提交反馈
 *   verify      自动验证（+ 按策略自动修复）
 *   progress    查询工单进度
 *   ping        轻量探测服务器在线状态
 *
 * 安全约定：
 *   - 所有写操作都要求 X-Requested-With: XMLHttpRequest（csrf 的第一道闸）
 *   - 验证接口使用的是一次性签名令牌，用过即废，且限流
 *   - 令牌里绑定了工单 id 与服务器 id，无法跨工单/跨服务器使用
 */

declare(strict_types=1);

use MCFix\Cache;
use MCFix\Catalog;
use MCFix\Config;
use MCFix\Executor;
use MCFix\ModLibrary;
use MCFix\ServerMods;
use MCFix\Share;
use MCFix\Task;
use MCFix\Token;
use MCFix\Workflow;

$action = $action ?? '';
$input = request_data();
$ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

// 玩家侧会话：显式用 mcfix_start_session() 起，才能带上 HttpOnly / SameSite=Lax / Secure。
// 之前是代码里随手写 $_SESSION 让 PHP 隐式创建一个默认会话（PHPSESSID，没有这些标记）。
// 它只用于"同一浏览器里记住自己刚提交过的工单"，除此之外不承载任何身份信息。
mcfix_start_session('mcfix_sid', \MCFix\ConsoleAuth::scope());

// 顶层兜底异常：没有它的话，任何未捕获的错误（比如 PDO 磁盘满、日志里有非法 UTF-8
// 导致 json_encode 失败）都会让 PHP 直接吐出半截响应 —— 浏览器拿到的是 HTTP 200 + 空 body，
// 玩家只看到"提交失败"，而服务端什么线索都没留下。
try {
    switch ($action) {
    case 'bootstrap':
        $servers = [];
        foreach (Config::servers() as $id => $server) {
            /*
             * 这是**匿名可达**的接口（玩家反馈页一打开就调它），所以只回
             * 展示用的 id / code / name。
             *
             * 曾经这里还回 host 和 port，等于把 MC 服务端的连接地址
             * 主动发给任何打开反馈页的人：扫端口、打 DDoS、绕过白名单直连
             * 都从"拿到地址"开始。前端其实只用 id 和 name，那两个字段
             * 从来没人读 —— 属于白白泄露。
             */
            $servers[] = [
                'id'   => (string) $id,
                'code' => (string) ($server['code'] ?? ''),
                'name' => (string) ($server['name'] ?? $id),
                'label'=> server_public_label((string) $id),
            ];
        }

        $categories = [];
        foreach (Catalog::categories() as $cat) {
            $categories[] = [
                'key'   => (string) $cat['key'],
                'label' => (string) $cat['label'],
                'icon'  => (string) $cat['icon'],
                'hint'  => (string) $cat['hint'],
            ];
        }

        json_ok([
            'servers'    => $servers,
            'categories' => $categories,
            'token_ttl'  => (int) Config::get('feedback.token_ttl', 86400),
            'sync_wait'  => (int) Config::get('feedback.sync_wait_seconds', 8),
        ]);
        break;

    case 'ping':
        $ids = (array) ($input['servers'] ?? ($_GET['servers'] ?? []));
        $out = [];
        foreach (Config::servers() as $id => $server) {
            if ($ids && !in_array((string) $id, array_map('strval', $ids), true)) {
                continue;
            }
            $cached = Cache::get('ping:' . $id);
            if (!is_array($cached)) {
                $cached = Executor::checkMcStatus($server);
                Cache::put('ping:' . $id, $cached, 20);
            }
            /*
             * 不回 message。它是 Executor::checkMcStatus() 拼的原文，失败时
             * 形如「按 Minecraft 协议连接失败：<stream_socket_client 的 errstr>」，
             * 而 PHP 的 socket 错误里**带着连接目标**（主机名或 IP:端口）。
             * 这个接口是匿名的，等于把服务端地址喂给任何会看响应体的人。
             * 前端本来就只读 online / players / max 三个字段，去掉它没有影响。
             */
            $out[(string) $id] = [
                'online'  => ($cached['status'] ?? '') === 'pass',
                'players' => (int) ($cached['data']['players'] ?? 0),
                'max'     => (int) ($cached['data']['max_players'] ?? 0),
            ];
        }
        json_ok(['servers' => $out]);
        break;

    case 'submit':
        if (!$ajax) {
            json_fail('缺少 X-Requested-With 请求头', 403);
        }
        require_post();
        // 限流按 client_ip + IP 段 组合，避免同一出口 IP（学校/网吧/CDN 回源）互相误伤
        $ipKey = client_ip_hash()
            . ':' . substr(hash('sha256', implode('.', array_slice(explode('.', client_ip() . '.0.0.0.0'), 0, 3))), 0, 12);
        rate_guard('submit:' . $ipKey, (int) Config::get('feedback.rate_limit_per_hour', 5), 3600);

        // 客户端环境信息（玩家手填，用于日志解析不出来时兜底）
        $input = array_merge($input, [
            'client_version'  => param($input, 'client_version', '', 40),
            'client_loader'   => param($input, 'client_loader', '', 40),
            'client_launcher' => param($input, 'client_launcher', '', 40),
        ]);

        /*
         * 专属链接锁定服务器。
         *
         * 判定逻辑在 resolve_locked_server_id() 里（src/helpers.php），那里能直接
         * 单测；这里只负责把两个来源凑齐交给它：
         *
         *   - 表单回传的 share_token —— HMAC 签名的分享令牌，玩家伪造不了；
         *   - session 里的 share_locked_server —— 访问反馈页时写下的。
         *
         * 为什么两个都要：单靠令牌挡不住"故意不带令牌"的绕法 —— 拿着 A 服链接的人
         * 手工构一个不带 share_token 的 POST、server_id 写成 B 服，后端就没有任何
         * 依据区分他和"从首页手选 B 服"的正常玩家。session 补上这个依据。
         *
         * 函数内部还保证了两件事：空值（「不涉及服务器」）永远放行，以及锁定的服
         * 已经不存在时不当成锁定。
         */
        $input['server_id'] = resolve_locked_server_id(
            (string) ($input['server_id'] ?? ''),
            (string) param($input, 'share_token', '', 4096),
            (string) ($_SESSION['share_locked_server'] ?? '')
        );

        $result = Workflow::submit($input, $_FILES);
        if (empty($result['ok'])) {
            json_fail((string) $result['error']);
        }

        // 记住自己刚提交的工单，方便后续查询进度
        $_SESSION['my_feedback'][] = (int) $result['id'];
        $_SESSION['my_feedback'] = array_slice(array_unique($_SESSION['my_feedback']), -20);

        // 记住这次填的邮箱，下次打开表单自动带上（只存在他自己的会话里，不进数据库）
        $email = trim((string) param($input, 'player_email', '', 200));
        if ($email !== '' && \MCFix\TicketMail::isEmail($email)) {
            $_SESSION['my_email'] = $email;
        } elseif ($email === '') {
            unset($_SESSION['my_email']);
        }

        json_ok([
            'id'          => $result['id'],
            'ticket_no'   => $result['ticket_no'],
            'category'    => $result['category'],
            'category_label' => $result['category_label'],
            'share_url'   => $result['share_url'],
            'verify_token'=> $result['verify_token'],
            'expires_in'  => $result['expires_in'],
            'has_client_log' => !empty($result['has_client_log']),
            'email_sent'  => !empty($result['email_sent']),
        ]);
        break;

    case 'mod_request':
        // 玩家页面点"从服务器取回这个文件"：让 MC 侧的 Agent 把文件传上来
        if (!$ajax) {
            json_fail('缺少 X-Requested-With 请求头', 403);
        }
        require_post();
        rate_guard('modreq:' . client_ip_hash(), 20, 3600);

        $token = param($input, 'token');
        $component = param($input, 'component', '', 120);

        $authorized = Workflow::authorize($token);
        $feedback = $authorized['feedback'];
        if ($feedback === null) {
            json_fail('链接无效或已过期，请刷新页面重试', 401);
        }

        if ($component === '') {
            json_fail('缺少要取回的文件名');
        }

        $analysis = safe_json_decode((string) ($feedback['client_analysis'] ?? ''));
        if (!$analysis) {
            json_fail('这条工单还没有客户端分析结果，请先重新验证一次', 409);
        }

        // 只允许取回"确实认定玩家缺的东西"。
        //
        // ★ 这里必须**精确匹配**，不能做子串。原因：needs.components 里的名字是从
        //   玩家上传的日志里解析出来的 —— 玩家写什么就是什么。配上双向子串匹配，
        //   一句 "Mixin apply for mod ab failed" 就能造出一个 2 字符的名字 "ab"，
        //   然后匹配到服务端上任意一个名字里含 "ab" 的 jar 并下载走。
        //   名字由玩家控制 + 子串匹配 = 任意文件下载器。
        //
        // ★ 更强的约束：如果服务端自己的 mod 清单可用，只认交叉比对里
        //   "服务端有、客户端没有" 的那些名字 —— 那种名字来自**服务端清单**，
        //   不是玩家随手编的。没有这份清单时才退回用分析结果里的名字。
        $allowed = [];
        foreach ((array) ($analysis['needs']['components'] ?? []) as $entry) {
            $allowed[] = (string) ($entry['name'] ?? '');
        }
        foreach ((array) ($analysis['suspects'] ?? []) as $entry) {
            $allowed[] = (string) $entry;
        }

        $cross = (array) ($analysis['cross_check'] ?? []);
        $serverSide = [];
        /*
         * 注意：cross_check 里的键是 possible（"这次比对做成了没有"），
         * 不是 available —— available 属于隔壁的 server_mods。
         *
         * 原来写的是 available，而这个键在 cross_check 里**从来不存在**，
         * 于是条件是恒假的、这段"只认服务端清单里的名字"的限制从未生效过，
         * $pool 永远退回 $allowed（= 玩家日志解析出来的名字）。
         *
         * 危害被下面第 241 行的精确匹配挡住了（不是子串匹配），所以这不算
         * 现成的洞；但它是一道静默失效的防线 —— 谁也不想在别处再踩一次。
         */
        if (!empty($cross['possible'])) {
            foreach ((array) ($cross['client_missing'] ?? []) as $entry) {
                $serverSide[] = (string) (is_array($entry) ? ($entry['name'] ?? '') : $entry);
            }
        }
        $pool = $serverSide !== [] ? $serverSide : $allowed;

        // 名字太短没有意义（2 字符能匹配上的东西太多了）
        $matched = null;
        foreach ($pool as $name) {
            $name = trim($name);
            if (mb_strlen($name) < 4) {
                continue;
            }
            if (strcasecmp($name, $component) === 0) {
                $matched = $name;
                break;
            }
        }
        if ($matched === null) {
            json_fail('这个文件不在本次问题的清单里，无法取回');
        }

        $server = Config::server((string) $feedback['server_id']);
        if ($server === null) {
            json_fail('服务器配置不存在', 404);
        }
        if ((string) ($server['executor'] ?? 'none') !== 'agent') {
            json_fail('该服务器未启用 Agent，无法自动取回文件，请联系管理员手工提供');
        }

        // 已经取回过就直接给链接
        $resolved = ModLibrary::resolve($matched, ServerMods::forServer($server));
        if (!empty($resolved['found'])) {
            json_ok([
                'state'    => 'ready',
                'component'=> $matched,
                'download' => (string) $resolved['download'],
                'filename' => (string) $resolved['filename'],
                'size_text'=> (int) $resolved['size'] > 0 ? human_size((float) $resolved['size']) : '',
            ]);
        }

        // 同一工单同一个文件不要重复排队
        foreach (Task::forFeedback((int) $feedback['id'], 20) as $task) {
            $params = safe_json_decode((string) ($task['params'] ?? ''));
            if ((string) $task['recipe'] === 'pull_mod'
                && (string) ($params['component'] ?? '') === $matched
                && in_array((string) $task['status'], ['queued', 'running'], true)) {
                json_ok(['state' => 'pending', 'component' => $matched, 'message' => '已经在向服务器取文件了，请稍等十几秒后刷新']);
            }
        }

        $created = Executor::repair($server, 'pull_mod', ['component' => $matched], (int) $feedback['id']);
        record_event((int) $feedback['id'], 'player', 'mod.request', '玩家请求取回文件：' . $matched, [
            'task_id' => $created['task_id'] ?? 0,
        ]);

        json_ok([
            'state'     => 'pending',
            'component' => $matched,
            'task_id'   => (int) ($created['task_id'] ?? 0),
            'message'   => !empty($created['ok'])
                ? '文件已取回，正在入库'
                : '已向服务器发起请求，Agent 上线后会自动把文件送过来',
        ]);
        break;

    case 'verify':
        if (!$ajax) {
            json_fail('缺少 X-Requested-With 请求头', 403);
        }
        require_post();
        rate_guard('verify', (int) Config::get('feedback.verify_per_hour', 40), 3600);

        $token = param($input, 'token');
        $parsed = Token::parse($token, 'v');
        if ($parsed === null) {
            json_fail('验证令牌已过期，请刷新页面后重试', 401);
        }

        $nonce = (string) $parsed['nonce'];
        if (Cache::get('verify-used:' . $nonce, false) !== false) {
            json_fail('这次验证已经执行过了，请刷新页面重新发起', 409);
        }
        Cache::put('verify-used:' . $nonce, true, max(600, $parsed['expires'] - time()));

        $feedback = Workflow::find((int) $parsed['id']);
        if ($feedback === null) {
            json_fail('工单不存在', 404);
        }
        $_SESSION['my_feedback'][] = (int) $feedback['id'];

        $result = Workflow::verify($feedback, true, true);
        if (empty($result['ok'])) {
            json_fail((string) $result['error'], 409);
        }

        $fresh = Workflow::find((int) $feedback['id']);
        if ($fresh === null) {
            json_fail('工单状态异常', 500);
        }

        $progress = Workflow::progress($fresh);
        $progress['verify_token'] = Token::forVerify($fresh);
        $progress['diagnosis'] = [
            'title'      => (string) ($result['diagnosis']['verdict']['title'] ?? ''),
            'detail'     => (string) ($result['diagnosis']['verdict']['detail'] ?? ''),
            'severity'   => (string) ($result['diagnosis']['verdict']['severity'] ?? ''),
            'issue'      => (string) ($result['diagnosis']['verdict']['issue'] ?? ''),
            'auto_fixable' => !empty($result['diagnosis']['verdict']['auto_fixable']),
            'operator_hint' => (string) ($result['diagnosis']['verdict']['operator_hint'] ?? ''),
        ];

        json_ok($progress);
        break;

    case 'progress':
        $token = param($input, 'token', param($_GET, 'token'));
        $feedback = null;

        if ($token !== '') {
            $authorized = Workflow::authorize($token);
            $feedback = $authorized['feedback'];
        } elseif (!empty($_SESSION['my_feedback'])) {
            $ids = (array) $_SESSION['my_feedback'];
            $feedback = Workflow::find((int) end($ids));
        }

        if ($feedback === null) {
            json_fail('链接无效或已过期，请让管理员重新生成反馈链接', 401);
        }

        $progress = Workflow::progress($feedback);
        $progress['verify_token'] = Token::forVerify($feedback);
        json_ok($progress);
        break;

    case 'tasks':
        // 玩家侧：查看自己工单关联的任务队列（含脱敏后的执行结果）
        $token = param($input, 'token', param($_GET, 'token'));
        $authorized = Workflow::authorize($token);
        if ($authorized['feedback'] === null) {
            json_fail('链接无效或已过期', 401);
        }
        $tasks = [];
        foreach (Task::forFeedback((int) $authorized['feedback']['id'], 20) as $task) {
            $tasks[] = [
                'id'         => (int) $task['id'],
                'recipe'     => (string) $task['recipe'],
                'action'     => (string) $task['action'],
                'status'     => (string) $task['status'],
                'created_at' => (string) $task['created_at'],
                'finished_at'=> (string) ($task['finished_at'] ?? ''),
                'error'      => (string) ($task['error'] ?? ''),
            ];
        }
        json_ok(['tasks' => $tasks]);
        break;

    default:
        json_fail('未知接口：' . $action, 404);
    }
} catch (\Throwable $e) {
    app_log('error', '玩家接口异常：' . $e->getMessage(), [
        'action' => $action,
        'file'   => $e->getFile() . ':' . $e->getLine(),
        'ip'     => client_ip(),
    ]);

    // 调试模式下把真实原因带出来，生产环境只给一句人话
    $extra = (bool) Config::get('app.debug', false) ? '（' . $e->getMessage() . '）' : '';
    json_fail('服务器内部错误，请稍后再试' . $extra, 500);
}
