<?php
/**
 * 管理后台：独立入口 + 独立会话 + 页面分发 + 表单动作。
 *
 * 入口不是 ?r=admin，而是配置里的私有路径：
 *   /?r=<console_path>                     后台首页（工单列表）
 *   /?r=<console_path>.ticket&id=1         工单详情
 *   /?r=<console_path>.servers             服务器与 Agent
 *   /?r=<console_path>.client              客户端问题
 *   /?r=<console_path>.events              操作日志
 *   /?r=<console_path>.notify              通知设置
 *   /?r=<console_path>.settings            系统设置
 *   /?r=<console_path>.logout              退出
 *
 * 与玩家反馈界面的隔离措施见 src/ConsoleAuth.php：
 *   独立会话名、cookie 只作用于后台路径、独立 CSRF、IP 白名单、失败锁定、隐藏入口。
 */

declare(strict_types=1);

use MCFix\Agent;
use MCFix\Cache;
use MCFix\Catalog;
use MCFix\Config;
use MCFix\ConsoleAuth;
use MCFix\Db;
use MCFix\Executor;
use MCFix\Notifier;
use MCFix\Recipe;
use MCFix\Rate;
use MCFix\Task;
use MCFix\Token;
use MCFix\Workflow;
// Backend form-action implementations. MUST be loaded before any action is
// dispatched below: those functions live in admin_actions.php and PHP will not
// find them on its own.
// (Chinese docs live in admin_actions.php's own header.)
require __DIR__ . '/admin_actions.php';

$appName = (string) Config::get('app.name', 'MC 故障反馈中心');
$adminPage = $action !== '' ? $action : param($_GET, 'p', 'tickets');
$adminPage = (string) preg_replace('/[^a-z_]/', '', (string) $adminPage);
$input = request_data();

// --------------------------------------------------------------------- 入口与会话

// 后台自己的会话（cookie 作用域仅限后台路径，与玩家侧完全隔离）
ConsoleAuth::initSession();

if (!Config::ready()) {
    header('Location: ' . abs_url('?r=install'));
    exit;
}

// IP 白名单 + 失败锁定
$gate = ConsoleAuth::gate();
if (!$gate['ok']) {
    if ($gate['reason'] === 'ip') {
        app_log('warn', '后台访问被 IP 白名单拒绝', ['ip' => client_ip()]);
        render_message_page('403 Forbidden', '当前访问来源不在允许范围内。', 403);
    }

    render_message_page(
        '尝试次数过多',
        '请 ' . (int) ceil($gate['retry_after'] / 60) . ' 分钟后再试。',
        429
    );
}

$hash = (string) Config::get('app.admin_password', '');
$authed = ConsoleAuth::isAuthed();

// 退出。
//
// 必须 POST + CSRF：以前是 GET（<a href="?p=logout">），任何页面放一个
// <img src=".../?r=<后台路径>&p=logout"> 就能把管理员踢下线 —— 危害不大但没必要留着。
// 注意这里在 CSRF 检查之前，所以自己校验一次。
if ($adminPage === 'logout') {
    if (!is_post()) {
        // 老的 GET 链接（收藏夹、旧页面）不该 500，回后台首页即可
        header('Location: ' . ConsoleAuth::url());
        exit;
    }
    ConsoleAuth::checkCsrf($input);
    ConsoleAuth::logout();
    header('Location: ' . ConsoleAuth::url());
    exit;
}

// 没设密码 → 回安装向导重设
if ($hash === '') {
    header('Location: ' . abs_url('?r=install&step=2'));
    exit;
}

// 登录
$loginError = '';
if (!$authed && is_post() && param($input, 'admin_action') === 'login') {
    $attempt = ConsoleAuth::login((string) ($input['password'] ?? ''));
    if ($attempt['ok']) {
        header('Location: ' . ConsoleAuth::url());
        exit;
    }
    $loginError = $attempt['error'];
    record_event(null, 'admin', 'console.login_failed', '管理后台登录失败（' . client_ip() . '）', [], 'warn');
}

if (!$authed) {
    // 未登录：只渲染一个极简登录页，不暴露任何后台内部信息
    http_response_code(200);
    header('X-Robots-Tag: noindex, nofollow');
    render_layout([
        'title'    => '访问验证',
        'siteName' => $appName,
        'headNav'  => [],
    ], function () use ($appName, $loginError): void {
        ?>
        <div class="login-wrap">
          <form method="post" action="<?= e(ConsoleAuth::url()) ?>" class="card login-card">
            <h1>访问验证</h1>
            <p class="hint"><?= e($appName) ?></p>
            <input type="hidden" name="admin_action" value="login">
            <div class="field">
              <label for="password">访问口令</label>
              <input id="password" name="password" type="password" required autofocus
                     autocomplete="current-password" spellcheck="false">
            </div>
            <?php if ($loginError !== ''): ?>
              <div class="alert alert-error"><?= e($loginError) ?></div>
            <?php endif; ?>
            <button class="btn btn-primary btn-block" type="submit">进入</button>
            <p class="hint">连续输错会临时锁定。忘记口令请在服务器上执行
              <code>php bin/mcfix.php reset-password</code> 重设
              （安装向导不会重开，避免被人拿去改密码）。</p>
          </form>
        </div>
        <?php
    });
    exit;
}

// --------------------------------------------------------------------- 表单动作

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (is_post() && param($input, 'admin_action') !== 'login') {
    // 后台用自己的 CSRF 令牌（与玩家侧分开，互不复用）
    ConsoleAuth::checkCsrf($input);
    $adminAction = param($input, 'admin_action');
    $result = ['ok' => true, 'message' => ''];

    switch ($adminAction) {
        case 'ticket_action':
            $ticketId = int_param($input, 'id');
            $feedback = Workflow::find($ticketId);
            if ($feedback === null) {
                $result = ['ok' => false, 'message' => '工单不存在'];
                break;
            }
            $op = param($input, 'op');
            $out = Workflow::adminAction($feedback, $op, [
                'note'   => param($input, 'note', '', 2000),
                'recipe' => param($input, 'recipe'),
                'params' => ['player' => param($input, 'player')],
            ], 'admin');
            $result = [
                'ok'      => !empty($out['ok']),
                'message' => !empty($out['ok'])
                    ? '操作完成：' . $op . (!empty($out['status']) ? '（' . $out['status'] . '）' : '')
                    : (string) ($out['error'] ?? '操作失败'),
                'data'    => $out,
            ];
            break;

        case 'task_action':
            $taskId = int_param($input, 'task_id');
            $op = param($input, 'op');
            if ($op === 'approve') {
                $ok = Task::approve($taskId, 'admin');
                $task = Task::find($taskId);
                if ($ok && $task !== null && !empty($task['feedback_id'])) {
                    // 批准后立即尝试执行（本地/SSH 通路可以马上出结果；agent 通路会排队）
                    $feedback = Workflow::find((int) $task['feedback_id']);
                    $verdict = $feedback !== null ? safe_json_decode((string) ($feedback['verdict'] ?? '')) : [];
                    $suggestion = null;
                    foreach ((array) ($verdict['suggestions'] ?? []) as $item) {
                        if (($item['code'] ?? '') === (string) $task['recipe']) {
                            $suggestion = $item;
                            break;
                        }
                    }
                    if ($suggestion !== null && $feedback !== null) {
                        Workflow::applyFix($feedback, $suggestion, true);
                    }
                }
                $result = ['ok' => $ok, 'message' => $ok ? '已批准，任务进入执行队列' : '批准失败（任务状态可能已变化）'];
            } else {
                $ok = Task::reject($taskId, 'admin', param($input, 'note', '管理员驳回'));
                $result = ['ok' => $ok, 'message' => $ok ? '已驳回该任务' : '驳回失败'];
            }
            break;

        case 'save_server':
            $result = admin_save_server($input);
            break;

        case 'delete_server':
            $result = admin_delete_server(param($input, 'server_id'));
            break;

        case 'ai_test':
            // 大模型连通性测试
            $probe = \MCFix\AiAdvisor::test();
            record_event(null, 'admin', 'ai.test', '大模型测试：' . (string) $probe['message'], [], !empty($probe['ok']) ? 'info' : 'warn');
            $result = ['ok' => !empty($probe['ok']), 'message' => (string) $probe['message']];
            break;

        case 'ai_models':
            // 问一下这个接口到底支持哪些模型名。
            //
            // 模型名是最容易填错、又最难自查的一项：各家命名五花八门
            // （deepseek-flash / DeepSeek-V4-Flash / deepseek-chat…），
            // 填错了只会得到一个 400。OpenAI 兼容接口普遍实现了 GET /v1/models，
            // 直接问它比让管理员去翻文档靠谱。
            $probe = \MCFix\AiAdvisor::listModels();
            if (!empty($probe['ok'])) {
                // 存下来给设置页渲染成可复制的清单（一天有效，够管理员改完）
                \MCFix\Cache::put('ai:models', array_values((array) $probe['models']), 86400);
            }
            record_event(null, 'admin', 'ai.models', '获取模型列表：' . (string) $probe['message'], [], !empty($probe['ok']) ? 'info' : 'warn');
            $result = ['ok' => !empty($probe['ok']), 'message' => (string) $probe['message']];
            break;

        case 'email_test':
            // 发一封测试邮件（同步发，管理员点了要立刻看到结果）
            $result = \MCFix\TicketMail::sendTest((string) param($input, 'to'));
            // 事件表里**不留完整邮箱**：这是永久记录，而项目对玩家邮箱一直是掩码处理的
            // （见 TicketMail::purgePlayerEmail）。管理员刚填的地址在页面上看得到，
            // 不需要再往库里存一份明文。
            record_event(null, 'admin', 'email.test', '测试邮件：' . \MCFix\TicketMail::maskEmailsInText((string) $result['message']), [], !empty($result['ok']) ? 'info' : 'warn');
            break;

        case 'email_drain':
            // 手动催一下邮件队列（正常情况下 cron 每分钟会做）
            $drained = \MCFix\TicketMail::drain(20);
            $result = [
                'ok'      => true,
                'message' => sprintf('已处理 %d 封：成功 %d，失败 %d', $drained['scanned'], $drained['sent'], $drained['failed']),
            ];
            break;

        case 'save_settings':
            $result = admin_save_settings($input);
            break;

        case 'save_log_retention':
            // 独立的动作：只碰 feedback.log_retention_days 一个键。
            // 挂在 save_settings 里的话，一次局部提交会把别的字段一起重置
            // （admin_save_settings 是"整表单覆盖"语义）。
            $result = admin_save_log_retention($input);
            break;

        case 'run_maintenance':
            $result = admin_run_maintenance(param($input, 'job', 'all'));
            break;

        case 'notify_save':
        case 'notify_channel':
        case 'notify_test':
        case 'notify_delete':
        case 'notify_retry':
        case 'notify_digest':
            // 通知设置页用一个表单承载多种操作，按钮的 name 决定实际动作
            $realAction = $adminAction;
            foreach (['save_channel', 'test_channel', 'delete_channel'] as $button) {
                if (param($input, $button) !== '') {
                    $realAction = 'notify_' . str_replace('_channel', '', $button);
                    if ($button === 'save_channel') {
                        $realAction = 'notify_channel';
                    }
                    break;
                }
            }

            switch ($realAction) {
                case 'notify_channel':
                    $result = admin_save_notify_channel($input);
                    break;
                case 'notify_test':
                    $key = param($input, 'test_channel', param($input, 'channel_key'));
                    $probe = Notifier::test($key);
                    $result = ['ok' => !empty($probe['ok']), 'message' => (string) $probe['message']];
                    break;
                case 'notify_delete':
                    $key = param($input, 'delete_channel', param($input, 'channel_key'));
                    $items = (array) \MCFix\Config::get('', []);
                    $removed = false;
                    if (isset($items['notify']['channels'][$key])) {
                        unset($items['notify']['channels'][$key]);
                        // 订阅关系也一起清掉，避免留下孤儿配置
                        if (isset($items['notify']['subscriptions'][$key])) {
                            unset($items['notify']['subscriptions'][$key]);
                        }
                        $removed = \MCFix\Config::save($items);
                        \MCFix\Notifier::adapter($key, true);
                    }
                    record_event(null, 'admin', 'notify.delete', '删除通知渠道：' . $key, [], 'warn');
                    $result = ['ok' => $removed, 'message' => $removed ? '渠道已删除' : '渠道不存在或保存失败'];
                    break;
                case 'notify_retry':
                    $sent = Notifier::retryPending(3, 50);
                    $result = ['ok' => true, 'message' => $sent > 0 ? ('重试成功 ' . $sent . ' 条') : '没有可重试的通知（或仍然失败）'];
                    break;
                case 'notify_digest':
                    $out = Notifier::dailyDigest();
                    $ok = 0;
                    foreach ($out as $item) {
                        if (!empty($item['ok'])) {
                            $ok++;
                        }
                    }
                    $result = [
                        'ok'      => true,
                        'message' => $ok > 0 ? ('每日汇总已发送到 ' . $ok . ' 个渠道') : '汇总已生成，但没有渠道订阅 digest.daily 事件',
                    ];
                    break;
                default:
                    $result = admin_save_notify($input);
            }
            break;

        case 'panel_test':
            // 测试面板 API 连通性
            $serverId = param($input, 'server_id');
            $target = Config::server($serverId);
            if ($target === null) {
                $result = ['ok' => false, 'message' => '服务器不存在'];
                break;
            }
            $adapter = \MCFix\PanelRegistry::adapter($target);
            if ($adapter === null) {
                $result = ['ok' => false, 'message' => '该服务器未配置可用面板 API（先填 type / api_url / 密钥）'];
                break;
            }
            $probe = $adapter->test();
            /*
             * 审计表里只留"能力清单"，不要把 $probe['detail'] 整包写进去（V7）。
             *
             * detail 里带着面板返回的日志样本等内容；审计表不该成为日志的
             * 第二份副本 —— 既重复存储、又扩大敏感内容的留存范围。
             * 界面展示照旧用完整的 $probe['detail']（那是一次性响应），
             * 只有落库这份收窄。
             */
            record_event(null, 'admin', 'panel.test', sprintf(
                '测试面板 API（%s）：%s',
                $serverId,
                !empty($probe['ok']) ? '成功' : ('失败 — ' . (string) $probe['message'])
            ), ['capabilities' => (array) ($probe['detail']['capabilities'] ?? [])], !empty($probe['ok']) ? 'info' : 'warn');

            $result = [
                'ok'      => !empty($probe['ok']),
                'message' => (string) $probe['message'],
                'data'    => $probe['detail'] ?? [],
            ];
            break;

        case 'share_regenerate':
            $serverId = param($input, 'server_id');
            $rotated = \MCFix\Share::rotate($serverId);
            $result = ['ok' => !empty($rotated['ok']), 'message' => (string) $rotated['message']];
            break;

        case 'reset_circuit_breaker':
            /*
             * 解除某台服务器的"自动修复熔断"（V15）。
             *
             * 熔断的本意是保护：连续失败太多次就停手，别一直撞墙。
             * 但它以前是**单向**的 —— 计数没有时间窗、也没有解除入口，
             * 一旦触发就永久把自动修复关掉，管理员只能去改库或把阈值设成 0。
             * 现在有两个出口：等熔断窗口自动到期，或在这里立刻解除。
             */
            $serverId = param($input, 'server_id');
            $ok = \MCFix\Workflow::resetCircuitBreaker($serverId);
            $result = [
                'ok'      => $ok,
                'message' => $ok
                    ? '已解除「' . $serverId . '」的自动修复熔断，下一次反馈会重新尝试自动修复'
                    : '解除失败：服务器不存在或已被删除',
            ];
            break;

        case 'pull_mod':
            // 让 MC 侧 Agent 把某个 MOD 从服务端目录取回来
            $ticketId = int_param($input, 'id');
            $component = param($input, 'component', '', 120);
            $feedback = Workflow::find($ticketId);
            $targetServer = $feedback !== null ? Config::server((string) $feedback['server_id']) : null;

            if ($feedback === null || $targetServer === null) {
                $result = ['ok' => false, 'message' => '工单或服务器不存在'];
                break;
            }
            if ($component === '') {
                $result = ['ok' => false, 'message' => '缺少组件名'];
                break;
            }

            $pull = Executor::repair($targetServer, 'pull_mod', ['component' => $component], $ticketId, false);
            record_event($ticketId, 'admin', 'mod.pull', '管理员请求从服务端取回：' . $component, [
                'task_id' => $pull['task_id'] ?? 0,
                'ok'      => !empty($pull['ok']),
            ]);
            $result = [
                'ok'      => !empty($pull['ok']),
                'message' => !empty($pull['ok'])
                    ? '已取回并入库，玩家页面会出现下载按钮'
                    : '请求已下发（' . (string) ($pull['error'] ?? '等待 Agent 执行') . '）',
            ];
            break;

        case 'upload_mod':
            // 管理员手工上传 MOD 到文件库
            $uploaded = $_FILES['mod_file'] ?? null;
            if (!is_array($uploaded) || (int) ($uploaded['error'] ?? 4) !== UPLOAD_ERR_OK) {
                $result = ['ok' => false, 'message' => '没有收到文件，或上传失败（' . (string) ($uploaded['error'] ?? '4') . '）'];
                break;
            }
            $stored = \MCFix\ModLibrary::store((string) $uploaded['tmp_name'], (string) $uploaded['name'], [
                'source'   => 'manual',
                'added_by' => 'admin',
                'server_id'=> param($input, 'server_id'),
            ]);
            $result = [
                'ok'      => !empty($stored['ok']),
                'message' => !empty($stored['ok'])
                    ? '已入库：' . $stored['filename'] . '（' . human_size((float) $stored['size']) . '）'
                    : '入库失败：' . (string) $stored['error'],
            ];
            break;

        case 'delete_mod':
            $removed = \MCFix\ModLibrary::forget(param($input, 'file', '', 160));
            record_event(null, 'admin', 'mod.delete', '删除 MOD 库文件：' . param($input, 'file', '', 160), [], 'warn');
            $result = ['ok' => $removed, 'message' => $removed ? '文件已删除' : '文件不存在'];
            break;

        case 'client_issue_action':
            $issueId = int_param($input, 'issue_id');
            $op = param($input, 'op');
            $issue = Db::first('SELECT * FROM client_issues WHERE id = :id', ['id' => $issueId]);
            if ($issue === null) {
                $result = ['ok' => false, 'message' => '记录不存在'];
                break;
            }
            if ($op === 'resolve') {
                Db::update('client_issues', [
                    'resolved'   => 1,
                    'admin_note' => mb_substr(param($input, 'note', '管理员标记为已处理', 500), 0, 500),
                    'updated_at' => now(),
                ], ['id' => $issueId]);
                record_event(
                    !empty($issue['feedback_id']) ? (int) $issue['feedback_id'] : null,
                    'admin',
                    'client_issue.resolve',
                    '标记客户端问题已处理：' . (string) $issue['code']
                );
                $result = ['ok' => true, 'message' => '已标记为已处理'];
            } else {
                Db::update('client_issues', ['updated_at' => now()], ['id' => $issueId]);
                $result = ['ok' => true, 'message' => '已更新'];
            }
            break;

        case 'mod_request_all':
            // 把某条工单里所有"库里没有"的组件一次性下发给 Agent
            $ticketId = int_param($input, 'id');
            $feedback = Workflow::find($ticketId);
            $targetServer = $feedback !== null ? Config::server((string) $feedback['server_id']) : null;
            if ($feedback === null || $targetServer === null) {
                $result = ['ok' => false, 'message' => '工单或服务器不存在'];
                break;
            }
            $analysis = safe_json_decode((string) ($feedback['client_analysis'] ?? ''));
            $queued = 0;
            foreach ((array) ($analysis['needs']['components'] ?? []) as $component) {
                if (!empty($component['available'])) {
                    continue;
                }
                $pull = Executor::repair($targetServer, 'pull_mod', ['component' => (string) $component['name']], $ticketId, false);
                if (!empty($pull['task_id'])) {
                    $queued++;
                }
            }
            $result = [
                'ok'      => true,
                'message' => $queued > 0 ? ('已下发 ' . $queued . ' 个取回任务，Agent 会在几十秒内完成') : '没有需要取回的组件',
            ];
            break;

        case 'change_password':
            $result = admin_change_password($input);
            break;

        /*
         * 图标与邮件 Logo 的上传/清除。单独两个动作，不并进 save_settings ——
         * 那个表单是普通 POST，带文件必须用 multipart/form-data，
         * 混在一起会让"保存设置"变成一次可能失败的上传。
         */
        case 'brand_upload':
            $result = admin_brand_upload($input, $_FILES);
            break;

        case 'brand_clear':
            $result = admin_brand_clear($input);
            break;

        case 'add_server':
            $result = admin_add_server($input);
            break;

        default:
            $result = ['ok' => false, 'message' => '未知操作'];
    }

    $_SESSION['flash'] = [
        'type'    => !empty($result['ok']) ? 'ok' : 'error',
        'message' => (string) ($result['message'] ?? ''),
    ];

    $redirect = admin_safe_redirect(param($input, 'redirect', ''));
    header('Location: ' . $redirect);
    exit;
}

/**
 * 解析「接口路径覆盖」表单：每行一条 `名称=路径`。
 *
 * 为什么要有这个：面板版本一多，内置的接口路径难免对不上，
 * 逼用户改代码是不合理的。给他一个能自己填的地方，
 * 再配合「F12 看面板自己发什么请求」这个办法，绝大多数版本差异都能自救。
 *
 * @return array<string,string>
 */
function admin_parse_endpoints(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$name, $path] = explode('=', $line, 2);
        $name = trim($name);
        $path = trim($path);
        // 只收名字合法、路径以 / 开头的，避免把奇怪的东西写进配置
        if ($name === '' || $path === '' || $path[0] !== '/') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $name)) {
            continue;
        }
        if (count($out) >= 30) {
            break;
        }
        $out[$name] = mb_substr($path, 0, 200);
    }

    return $out;
}

/**
 * 只接受"回到站内"的跳转目标。
 *
 * 表单里的 redirect 字段是渲染时写进 HTML 的，但 POST 参数终究是外部输入；
 * 直接塞进 Location 头就是一个开放重定向（可以用来钓鱼：
 * https://你的后台/?r=... 跳到攻击者的假登录页）。
 * 规则收得很紧：相对路径、且不能是 //host 或 /\host 这种协议相对写法。
 */
function admin_safe_redirect(string $target): string
{
    $target = trim($target);
    $home = ConsoleAuth::url();

    if ($target === '') {
        return $home;
    }

    // 绝对地址：只允许和本站同一个前缀
    if (stripos($target, 'http://') === 0 || stripos($target, 'https://') === 0) {
        return strpos($target, $home) === 0 ? $target : $home;
    }

    // 相对地址：必须以单个 / 开头，且不能是 // 或 /\ 开头（浏览器会当成别的站点）
    if ($target[0] !== '/' || strpos($target, '//') === 0 || strpos($target, '/\\') === 0) {
        return $home;
    }
    if (strpos($target, "\r") !== false || strpos($target, "\n") !== false) {
        return $home;
    }

    return $target;
}

// --------------------------------------------------------------------- 页面渲染

$nav = admin_nav($adminPage);
$agentOnline = Agent::onlineCount(120);
$openTickets = (int) Db::scalar("SELECT COUNT(*) FROM feedback WHERE status IN ('submitted','diagnosing','diagnosed','fixing','verifying','manual','unresolved')");
$pendingTasks = (int) Db::scalar("SELECT COUNT(*) FROM tasks WHERE status IN ('queued','awaiting_approval')");

render_layout([
    'title'    => $appName . ' · 管理后台',
    'siteName' => $appName,
    'bodyClass'=> 'admin-body',
    'headNav'  => [],
], function () use ($adminPage, $nav, $flash, $agentOnline, $openTickets, $pendingTasks, $appName): void {
    ?>
    <div class="admin">
      <?php /* 窄屏折叠菜单的开关。桌面端侧栏一直展开，这个 checkbox 只是
               为手机准备的；用 label 驱动而不是 JS —— 脚本挂了导航也还能用。 */ ?>
      <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="展开或收起菜单">
      <aside class="admin-side">
        <div class="side-head">
          <span class="brand-mark">⛏</span>
          <div>
            <strong>管理后台</strong>
            <small><?= e($appName) ?></small>
          </div>
          <div class="head-actions">
            <?php /* 退出登录在窄屏放到顶栏：菜单里只留导航按钮，不塞功能项。
                     桌面端用下面 side-foot 里那个，这个只在窄屏显示。 */ ?>
            <form method="post" action="<?= e(ConsoleAuth::url('p=logout')) ?>">
              <?= \MCFix\ConsoleAuth::csrfField() ?>
              <button type="submit" class="head-quit">退出</button>
            </form>
            <label for="nav-toggle" class="nav-burger"><i></i></label>
          </div>
        </div>

        <?php /* 折叠菜单里**只放导航按钮** —— 统计数字和链接属于页面内容，
                 不该塞进导航（统计已经移到工单页顶部）。 */ ?>
        <div class="side-collapse">
          <nav class="side-nav">
            <?php foreach ($nav as $key => $item): ?>
              <a href="<?= e($item['href']) ?>" class="<?= $item['active'] ? 'is-active' : '' ?>">
                <span class="nav-icon"><?= e($item['icon']) ?></span>
                <span><?= e($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                  <em class="nav-badge"><?= (int) $item['badge'] ?></em>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </nav>
        </div>

        <?php /* 桌面端侧栏底部的状态与链接 —— 与加入手机折叠菜单之前完全一致。
                 窄屏整块隐藏（见 app.css 的 .side-foot{display:none}），
                 那三个数字在手机上改由工单页顶部的 KPI 行承担。 */ ?>
        <div class="side-foot">
          <div class="side-stat">
            <span>Agent 在线</span>
            <b class="<?= $agentOnline > 0 ? 'ok' : 'bad' ?>"><?= (int) $agentOnline ?></b>
          </div>
          <div class="side-stat">
            <span>待处理工单</span>
            <b><?= (int) $openTickets ?></b>
          </div>
          <div class="side-stat">
            <span>待执行任务</span>
            <b><?= (int) $pendingTasks ?></b>
          </div>
          <a class="side-link" href="<?= e(url('')) ?>" target="_blank" rel="noopener">↗ 玩家反馈页</a>
          <form method="post" action="<?= e(ConsoleAuth::url('p=logout')) ?>" class="side-logout">
            <?= \MCFix\ConsoleAuth::csrfField() ?>
            <button type="submit" class="side-link side-link-btn">退出登录</button>
          </form>
        </div>
      </aside>

      <section class="admin-main">
        <?php if (is_array($flash) && !empty($flash['message'])): ?>
          <div class="alert alert-<?= $flash['type'] === 'ok' ? 'info' : 'error' ?>"><?= e((string) $flash['message']) ?></div>
        <?php endif; ?>

        <?php
        switch ($adminPage) {
            case 'ticket':
                require MCFIX_ROOT . '/admin/ticket.php';
                break;
            case 'servers':
                require MCFIX_ROOT . '/admin/servers.php';
                break;
            case 'client':
                require MCFIX_ROOT . '/admin/client.php';
                break;
            case 'events':
                require MCFIX_ROOT . '/admin/events.php';
                break;
            case 'settings':
                require MCFIX_ROOT . '/admin/settings.php';
                break;
            case 'notify':
                require MCFIX_ROOT . '/admin/notify.php';
                break;
            case 'help':
                require MCFIX_ROOT . '/admin/help.php';
                break;
            case 'tickets':
            default:
                require MCFIX_ROOT . '/admin/tickets.php';
                break;
        }
        ?>
      </section>
    </div>
    <?php
});

// --------------------------------------------------------------------- 辅助函数

/**
 * @return array<string,array<string,mixed>>
 */
function admin_nav(string $current): array
{
    $open = (int) Db::scalar("SELECT COUNT(*) FROM feedback WHERE status IN ('submitted','diagnosing','fixing','verifying')");
    $manual = (int) Db::scalar("SELECT COUNT(*) FROM feedback WHERE status IN ('manual','unresolved')");
    $pending = (int) Db::scalar("SELECT COUNT(*) FROM tasks WHERE status = 'awaiting_approval'");
    $clientOpen = (int) Db::scalar('SELECT COUNT(*) FROM client_issues WHERE resolved = 0');
    $failedNotify = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE status = 'failed'");

    return [
        'tickets'  => ['label' => '工单', 'icon' => '📋', 'href' => ConsoleAuth::url(), 'active' => in_array($current, ['tickets', 'ticket'], true), 'badge' => $open + $manual],
        'client'   => ['label' => '客户端问题', 'icon' => '🧯', 'href' => ConsoleAuth::url('p=client'), 'active' => $current === 'client', 'badge' => $clientOpen],
        'servers'  => ['label' => '服务器与 Agent', 'icon' => '🖥', 'href' => ConsoleAuth::url('p=servers'), 'active' => $current === 'servers', 'badge' => $pending],
        'events'   => ['label' => '操作日志', 'icon' => '🧾', 'href' => ConsoleAuth::url('p=events'), 'active' => $current === 'events', 'badge' => 0],
        'notify'   => ['label' => '通知设置', 'icon' => '🔔', 'href' => ConsoleAuth::url('p=notify'), 'active' => $current === 'notify', 'badge' => $failedNotify],
        'settings' => ['label' => '系统设置', 'icon' => '⚙️', 'href' => ConsoleAuth::url('p=settings'), 'active' => $current === 'settings', 'badge' => 0],
        'help'     => ['label' => '接入与排查', 'icon' => '📖', 'href' => ConsoleAuth::url('p=help'), 'active' => $current === 'help', 'badge' => 0],
    ];
}
