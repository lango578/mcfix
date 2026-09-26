<?php
/**
 * 命令行工具：维护、诊断、生成链接、模拟提交。
 *
 * 用法（在项目根目录）：
 *   php bin/mcfix.php cron                     例行维护（宝塔计划任务每分钟）
 *   php bin/mcfix.php doctor                   配置与环境自检
 *   php bin/mcfix.php servers                  列出服务器与 Agent 状态
 *   php bin/mcfix.php link <server_id>         生成玩家公开反馈链接
 *   php bin/mcfix.php diagnose <server_id>     不建工单直接诊断一次
 *   php bin/mcfix.php tickets [status] [n]     查看最近的工单
 *   php bin/mcfix.php followup                 推进卡在"修复中"的工单
 *   php bin/mcfix.php test-feedback <server_id> <player> <描述>   模拟玩家提交并走完整闭环
 *   php bin/mcfix.php rotate-all               轮换所有服务器的公开链接密钥
 *   php bin/mcfix.php reset-password [新口令]   重设后台口令（忘记密码时用这个，不要动安装向导）
 *   php bin/mcfix.php panel [server_id]        体检面板 API 配置（面板服排障第一步）
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本脚本只能在命令行运行');
}

define('MCFIX_ROOT', dirname(__DIR__));

require MCFIX_ROOT . '/src/bootstrap.php';

use MCFix\Agent;
use MCFix\Cache;
use MCFix\Config;
use MCFix\ConsoleAuth;
use MCFix\Db;
use MCFix\Diagnosis;
use MCFix\Notifier;
use MCFix\PanelRegistry;
use MCFix\Rate;
use MCFix\Rcon;
use MCFix\Share;
use MCFix\Task;
use MCFix\TicketMail;
use MCFix\Workflow;

$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

$out = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$fail = static function (string $message): void {
    fwrite(STDERR, '✗ ' . $message . PHP_EOL);
    exit(1);
};

$out('MCFix v' . MCFIX_VERSION . ' · PHP ' . PHP_VERSION . ' · ' . Db::driver());

if (!Config::ready() && !in_array($command, ['help', 'doctor'], true)) {
    $fail('尚未安装：缺少 config/config.php，请先在浏览器访问 ?r=install 完成安装');
}

switch ($command) {
    // ---------------------------------------------------------------- 例行维护
    case 'cron':
        $reclaimed = Task::reclaim(180);
        $followed = 0;

        // 推进卡在修复中/待复验的工单
        $rows = Db::all(
            "SELECT * FROM feedback WHERE status IN ('fixing','verifying','diagnosed') AND updated_at < :deadline ORDER BY id ASC LIMIT 30",
            ['deadline' => gmdate('Y-m-d H:i:s', time() - 15)]
        );
        foreach ($rows as $feedback) {
            $pending = false;
            foreach (Task::forFeedback((int) $feedback['id'], 8) as $task) {
                if (in_array((string) $task['status'], ['queued', 'running'], true)) {
                    $pending = true;
                }
            }
            if ($pending && time() - ts((string) $feedback['updated_at']) < 300) {
                continue;
            }
            if ($pending) {
                Workflow::escalate((int) $feedback['id'], 'MC 侧执行器长时间无响应，已转人工（请检查 Agent 是否在运行）');
                $followed++;
                continue;
            }
            Workflow::reverify($feedback, '');
            $followed++;
        }

        // 清理
        $cache = Cache::gc();
        $rate = Rate::gc();

        // 邮件队列：玩家回执 / 结果 / 管理员提醒都在这里发出去（失败会退避重试）
        $mail = TicketMail::drain(8);

        // 归档
        //
        // 「标记已解决」现在会**立刻**落到 closed（见 Workflow::resolve 的说明），
        // 所以这条兜底主要管两件事：
        //   1. 老数据 —— 升级前留下的 status = 'resolved'（那时要等这条 cron 才归档）
        //   2. rejected —— 驳回同样是终态，但状态名单独保留，语义上不叫"关闭"
        // 收敛成 closed 之后 purgeExpiredLogs 才能按 closed_at 算留存期。
        $days = (int) Config::get('feedback.autoclose_days', 7);
        $closed = Db::exec(
            "UPDATE feedback SET status = 'closed', closed_at = :now, updated_at = :now
             WHERE status IN ('resolved', 'rejected') AND updated_at < :deadline",
            ['now' => now(), 'deadline' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );

        // 日志原文留存：归档再满 N 天后清掉原文（结论保留）。
        // 放在归档后面 —— 先归档、再按归档时间算留存期。
        $purgedLogs = Workflow::purgeExpiredLogs((int) Config::get('feedback.log_retention_days', 30));

        // 通知：重试失败的 + 每日汇总 + Agent 掉线告警
        $retried = Notifier::retryPending(3, 20);
        $digests = 0;
        $offline = 0;

        // 每天 09:00 之后的第一轮 cron 发一次汇总
        $digestFlag = 'digest-sent:' . gmdate('Ymd');
        if ((int) gmdate('G') >= 9 && Cache::get($digestFlag, false) === false) {
            Notifier::dailyDigest();
            Cache::put($digestFlag, true, 86400);
            $digests = 1;
        }

        // Agent 掉线告警：曾经上线过、现在超过 30 分钟没心跳
        foreach (Config::servers() as $serverId => $server) {
            if ((string) ($server['executor'] ?? '') !== 'agent') {
                continue;
            }
            $seen = Agent::seen((string) $serverId);
            if ($seen === null || empty($seen['last_seen'])) {
                continue;
            }
            $idle = time() - ts((string) $seen['last_seen']);
            if ($idle < 1800) {
                continue;
            }
            // 每 6 小时最多提醒一次
            $flag = 'agent-offline:' . $serverId;
            if (Cache::get($flag, false) !== false) {
                continue;
            }
            Cache::put($flag, true, 21600);

            Notifier::dispatch('agent.offline', [
                'server_id' => (string) $serverId,
                'problem'   => 'MC 侧 Agent 已离线 ' . duration_text((float) $idle),
                'result'    => '自动修复（重启、备份、MOD 取回）会排队等待；RCON 与面板 API 通路不受影响',
                'link'      => ConsoleAuth::url('p=servers&server_id=' . urlencode((string) $serverId)),
            ], 'agent.offline:' . $serverId . ':' . gmdate('YmdH'));
            $offline++;
        }

        $out(sprintf(
            '维护完成：回收任务 %d，推进工单 %d，归档 %d，清理日志原文 %d，清理缓存 %d / 限流 %d，通知重试 %d，汇总 %d，掉线告警 %d，邮件发出 %d（失败 %d）',
            $reclaimed,
            $followed,
            $closed,
            $purgedLogs,
            $cache,
            $rate,
            $retried,
            $digests,
            $offline,
            $mail['sent'],
            $mail['failed']
        ));
        exit(0);

    // ---------------------------------------------------------------- 自检
    case 'doctor':
        $problems = Config::diagnose();
        $out();
        $out('== 配置自检 ==');
        if (!$problems) {
            $out('✓ 全部通过');
        } else {
            foreach ($problems as $problem) {
                $out('✗ ' . $problem);
            }
        }

        $out();
        $out('== 运行环境 ==');
        $out('  PHP            ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
        $out('  数据库         ' . Db::driver());
        $stats = Db::stats();
        if (!empty($stats['path'])) {
            $out('  数据文件       ' . $stats['path'] . '（' . human_size((float) $stats['size']) . '）');
        }
        $out('  storage 可写   ' . (is_writable(storage_path()) ? '是' : '否 ✗'));
        $out('  proc_open      ' . (in_array('proc_open', \MCFix\Executor::disabledFunctions(), true) ? '被禁用（local/ssh 通路不可用）' : '可用'));

        $out();
        $out('== 服务器 ==');
        foreach (Config::servers() as $id => $server) {
            $online = Agent::isOnline((string) $id, 120);
            $out(sprintf(
                '  %-16s %-28s executor=%-6s rcon=%-3s agent=%s',
                (string) $id,
                (string) ($server['host'] . ':' . $server['port']),
                (string) ($server['executor'] ?? 'none'),
                !empty($server['rcon']['enabled']) ? 'on' : 'off',
                $online ? '在线' : '离线'
            ));

            // 面板 API 能力
            $panel = PanelRegistry::summary($server);
            if (!empty($panel['configured'])) {
                $labels = array_map([PanelRegistry::class, 'capabilityLabel'], $panel['capabilities']);
                $out('                   面板：' . $panel['label'] . ' → ' . implode('、', $labels));
            } elseif ($panel['type'] !== 'none') {
                $out('                   面板：配置不完整（type=' . $panel['type'] . '，缺少地址或密钥）');
            }

            // 通道能力小结：告诉管理员这台服务器现在到底能做什么
            $channels = [];
            if (Rcon::enabled($server)) {
                $channels[] = 'RCON';
            }
            if (!empty($panel['configured'])) {
                $channels[] = '面板API';
            }
            if (in_array((string) ($server['executor'] ?? ''), ['agent', 'ssh', 'local'], true)) {
                $channels[] = (string) $server['executor'];
            }
            $out('                   可用通道：' . ($channels ? implode(' + ', $channels) : '无（只能做 MC 协议探测）'));
        }
        if (!Config::servers()) {
            $out('  （还没有配置服务器）');
        }

        $out();
        $out('== 待处理 ==');
        $out('  开放工单   ' . (int) Db::scalar("SELECT COUNT(*) FROM feedback WHERE status NOT IN ('resolved','closed','rejected')"));
        $out('  待执行任务 ' . (int) Db::scalar("SELECT COUNT(*) FROM tasks WHERE status IN ('queued','awaiting_approval','running')"));
        $out('  待批准任务 ' . (int) Db::scalar("SELECT COUNT(*) FROM tasks WHERE status = 'awaiting_approval'"));

        exit($problems ? 1 : 0);

    // ---------------------------------------------------------------- 服务器
    case 'servers':
        foreach (Config::servers() as $id => $server) {
            $seen = Agent::seen((string) $id);
            $out('■ ' . (string) $server['name'] . '  [' . $id . ']');
            $out('  地址      ' . (string) $server['host'] . ':' . (int) $server['port']);
            $out('  执行器    ' . (string) ($server['executor'] ?? 'none'));
            $out('  RCON      ' . (!empty($server['rcon']['enabled']) ? '已开启 ' . (string) $server['rcon']['host'] . ':' . (int) $server['rcon']['port'] : '未开启'));
            $out('  MC 目录   ' . (string) ($server['mc_dir'] ?? '—'));
            $out('  守护      ' . (string) ($server['guard']['type'] ?? '—') . ' ' . (string) ($server['guard']['service'] ?? $server['guard']['session'] ?? ''));
            if ($seen === null) {
                $out('  Agent     从未上线 ✗');
            } else {
                $age = time() - ts((string) $seen['last_seen']);
                $out(sprintf(
                    '  Agent     %s（%s，v%s，%s）',
                    $age < 120 ? '在线' : '离线 ' . duration_text((float) $age),
                    (string) ($seen['hostname'] ?? ''),
                    (string) ($seen['agent_version'] ?? ''),
                    !empty($seen['mc_online']) ? '服务端运行中' : '服务端未运行'
                ));
            }
            $link = Share::link($server);
            $out('  反馈链接  ' . $link['url']);
            $out();
        }
        exit(0);

    // ---------------------------------------------------------------- 生成链接
    case 'link':
        $serverId = $args[0] ?? '';
        $server = Config::server($serverId);
        if ($server === null) {
            $fail('服务器不存在：' . $serverId);
        }
        // 首次生成时补一个 share_secret
        if (empty($server['share_secret'])) {
            Share::rotate($serverId);
            $server = Config::server($serverId) ?? $server;
        }
        $link = Share::link($server);
        $out('玩家反馈链接（有效期 30 天）：');
        $out($link['url']);
        exit(0);

    // ---------------------------------------------------------------- 直接诊断
    case 'diagnose':
        $serverId = $args[0] ?? '';
        $player = $args[1] ?? '';
        $category = $args[2] ?? 'cannot_join';
        $server = Config::server($serverId);
        if ($server === null) {
            $fail('服务器不存在：' . $serverId);
        }

        $started = microtime(true);
        $diagnosis = Diagnosis::run([
            'id'          => 0,
            'server_id'   => $serverId,
            'player_name' => $player,
            'category'    => $category,
        ], true);

        $verdict = (array) ($diagnosis['verdict'] ?? []);
        $out();
        $out('结论：' . (string) ($verdict['title'] ?? ''));
        $out('问题码：' . (string) ($verdict['issue'] ?? '') . '  严重级别：' . (string) ($verdict['severity'] ?? ''));
        $out('可自动修：' . (!empty($verdict['auto_fixable']) ? '是' : '否') . '   需批准：' . (!empty($verdict['requires_approval']) ? '是' : '否'));
        $out('耗时：' . round(microtime(true) - $started, 2) . ' 秒');
        $out();
        foreach ((array) ($diagnosis['checks'] ?? []) as $code => $check) {
            $icon = ['pass' => '✓', 'warn' => '!', 'fail' => '✗', 'skipped' => '-', 'unknown' => '?'][(string) ($check['status'] ?? 'unknown')] ?? '?';
            $out('  [' . $icon . '] ' . str_pad((string) $code, 18) . (string) ($check['message'] ?? ''));
        }
        if (!empty($verdict['suggestions'])) {
            $out();
            $out('可执行配方：');
            foreach ((array) $verdict['suggestions'] as $suggestion) {
                $out('  - ' . (string) $suggestion['label'] . '（' . (string) $suggestion['code'] . '，' . (string) $suggestion['risk'] . '）');
            }
        }
        exit(0);

    // ---------------------------------------------------------------- 工单列表
    case 'tickets':
        $status = $args[0] ?? '';
        $limit = (int) ($args[1] ?? 20);
        $sql = 'SELECT * FROM feedback';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit));

        foreach (Db::all($sql, $params) as $row) {
            $out(sprintf(
                '#%-5d %-18s %-14s %-10s %-12s %s',
                (int) $row['id'],
                (string) $row['ticket_no'],
                (string) $row['player_name'],
                (string) $row['status'],
                (string) $row['server_id'],
                mb_substr((string) $row['subject'], 0, 30)
            ));
        }
        exit(0);

    // ---------------------------------------------------------------- 推进工单
    case 'followup':
        $rows = Db::all("SELECT * FROM feedback WHERE status IN ('fixing','verifying') ORDER BY id ASC LIMIT 50");
        $count = 0;
        foreach ($rows as $feedback) {
            Workflow::reverify($feedback, '');
            $count++;
            $out('已复验 #' . (int) $feedback['id']);
        }
        $out('共处理 ' . $count . ' 条');
        exit(0);

    // ---------------------------------------------------------------- 模拟闭环
    case 'test-feedback':
        $serverId = $args[0] ?? '';
        $player = $args[1] ?? 'Steve';
        $message = $args[2] ?? '服务器进不去了，客户端提示连接失败';

        if (Config::server($serverId) === null) {
            $fail('服务器不存在：' . $serverId);
        }

        $submitted = Workflow::submit([
            'server_id'   => $serverId,
            'player_name' => $player,
            'category'    => 'auto',
            'message'     => $message,
        ]);

        if (empty($submitted['ok'])) {
            $fail('提交失败：' . (string) $submitted['error']);
        }

        $out('工单号：' . (string) $submitted['ticket_no']);
        $out('反馈链接：' . (string) $submitted['share_url']);
        $out();
        $out('开始自动验证…');

        $feedback = Workflow::find((int) $submitted['id']);
        if ($feedback === null) {
            $fail('工单读取失败');
        }

        $result = Workflow::verify($feedback, true, true);
        $fresh = Workflow::find((int) $submitted['id']);
        $progress = $fresh !== null ? Workflow::progress($fresh) : [];

        $out('状态：' . (string) ($progress['status_label'] ?? ''));
        $out('结论：' . (string) ($progress['problem']['title'] ?? ''));
        $out('给玩家的话：' . (string) ($progress['headline'] ?? ''));
        $out();
        foreach ((array) ($progress['checks'] ?? []) as $check) {
            $out('  [' . (string) $check['status'] . '] ' . (string) $check['label'] . ' — ' . (string) $check['text']);
        }
        if (!empty($result['fix'])) {
            $out();
            $out('修复：' . (!empty($result['fix']['ok']) ? '成功' : '未成功') . '（通道 ' . (string) ($result['fix']['executor'] ?? '') . '）' . (string) ($result['fix']['error'] ?? ''));
        }
        exit(0);

    // ---------------------------------------------------------------- 重设后台口令
    //
    // 这是唯一的密码找回通道。不改这一条的话，网页上就必须留一个「清空密码 → 重跑安装
    // 向导」的后门，而那个入口是无鉴权的，等同于把后台直接送给任何扫到它的人。
    case 'reset-password':
        $password = (string) ($args[0] ?? '');
        if ($password === '') {
            // 不带参数就交互式输入，避免口令落进 shell 历史和进程列表
            $out('请输入新的后台口令（至少 8 位，输入不回显）：');
            $password = mcfix_read_hidden();
            if ($password === '') {
                $fail('没有读到口令，已取消');
            }
            $out('再输一次：');
            if (mcfix_read_hidden() !== $password) {
                $fail('两次输入不一致，已取消');
            }
        }
        if (strlen($password) < 8) {
            $fail('口令至少 8 位');
        }

        $config = (array) Config::get('', []);
        $config['app'] = array_merge((array) ($config['app'] ?? []), [
            'admin_password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        if (!Config::save($config)) {
            $fail('写入 config/config.php 失败，请检查 config 目录权限');
        }

        // 改口令会让会话指纹变化，所有已登录的后台会话立刻失效
        $out('✓ 后台口令已更新，旧的后台登录状态已全部失效');
        $out('  后台入口路径：' . ConsoleAuth::path() . '（配置项 admin.path）');
        exit(0);

    // ---------------------------------------------------------------- 轮换密钥
    case 'rotate-all':
        foreach (Config::servers() as $id => $server) {
            $rotated = Share::rotate((string) $id);
            $out(($rotated['ok'] ? '✓ ' : '✗ ') . $id . '：' . $rotated['message']);
        }
        exit(0);

    // ---------------------------------------------------------------- 面板 API 体检
    case 'panel':
        $serverId = (string) ($args[0] ?? '');
        if ($serverId === '') {
            // 不传就列出所有配了面板的服务器
            $any = false;
            foreach (Config::servers() as $id => $server) {
                $summary = PanelRegistry::summary($server);
                if ($summary['type'] === 'none') {
                    continue;
                }
                $any = true;
                $out(sprintf(
                    '  %-16s %-22s %s',
                    (string) $id,
                    $summary['label'],
                    $summary['configured'] ? '配置完整' : '配置不完整'
                ));
            }
            if (!$any) {
                $out('  没有任何服务器配置了面板 API。');
            }
            $out();
            $out('用法：php bin/mcfix.php panel <server_id>');
            exit(0);
        }

        $server = Config::server($serverId);
        if ($server === null) {
            $fail('服务器不存在：' . $serverId);
        }

        $panelCfg = PanelRegistry::config($server);
        $summary = PanelRegistry::summary($server);
        $adapter = PanelRegistry::adapter($server);

        $out('面板类型：' . $summary['label']);
        // 地址本身可能就带密钥（自研面板常把 token 放在路径里），输出前统一抹一遍
        $out('接口地址：' . mask_secret_url((string) ($panelCfg['api_url'] ?? '（未填）')));
        $out('TLS 校验：' . (!empty($panelCfg['insecure']) ? '已关闭（跳过证书校验）' : '开启'));
        $out();

        if ($adapter === null) {
            $out('✗ 构造不出适配器。依次检查：');
            $out('  1. 面板类型是不是 none');
            $out('  2. api_url 与 api_key 是否都填了');
            if (in_array(PanelRegistry::type($server), ['mcsmanager'], true)) {
                $out('  3. MCSManager 需要 instance_id（下发指令）/ daemon_id（读日志）');
            }
            if (PanelRegistry::type($server) === 'pterodactyl') {
                $out('  3. 翼龙需要 server_id（面板地址栏 /server/<这一段>）');
            }
            exit(1);
        }

        $out('能力：' . ($summary['capabilities']
            ? implode('、', array_map([PanelRegistry::class, 'capabilityLabel'], $summary['capabilities']))
            : '（无 —— 参数还没填全）'));
        $out();
        $out('== 连通性测试 ==');

        $probe = $adapter->test();
        $out(($probe['ok'] ? '✓ ' : '✗ ') . (string) $probe['message']);

        // 把实际请求过的 URL 与响应片段打出来 —— 填错 ID 时这是最有用的线索
        $detail = (array) ($probe['detail'] ?? []);
        if (!empty($detail['url'])) {
            $out('  请求：' . (string) $detail['url']);
        }
        foreach ((array) ($detail['transcript'] ?? []) as $line) {
            $out('  · ' . (is_array($line) ? json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $line));
        }

        if (!empty($probe['ok'])) {
            $out();
            $out('== 读日志测试 ==');
            $log = $adapter->readLog(8192);
            if (!empty($log['ok'])) {
                $out('✓ 读到 ' . strlen((string) $log['content']) . ' 字节，尾部：');
                foreach (array_slice(preg_split('/\r?\n/', (string) $log['content']) ?: [], -3) as $line) {
                    $out('  | ' . mb_substr($line, 0, 140));
                }
            } else {
                $out('✗ ' . (string) $log['error']);
                $out('  → 检查「日志路径」是否相对服务端目录正确（默认 logs/latest.log）。');
            }
        }

        exit(!empty($probe['ok']) ? 0 : 1);

    case 'help':
    default:
        $out();
        $out('用法：php bin/mcfix.php <命令> [参数]');
        $out();
        $out('  cron                          例行维护（宝塔计划任务每分钟）');
        $out('  doctor                        配置与环境自检');
        $out('  servers                       列出服务器与 Agent 状态');
        $out('  link <server_id>              生成玩家公开反馈链接');
        $out('  diagnose <server_id> [玩家] [分类]   直接诊断一次，不建工单');
        $out('  tickets [状态] [数量]          查看工单');
        $out('  followup                      推进卡住的工单');
        $out('  test-feedback <server_id> <玩家> <描述>  模拟玩家提交，跑完整闭环');
        $out('  rotate-all                    轮换所有公开链接密钥');
        $out('  reset-password [新口令]        重设后台口令（忘记密码只能走这里）');
        $out('  panel [server_id]             体检面板 API：连通性、能力、实际发出的请求');
        $out();
        $out('分类可选：cannot_join / server_down / lag / data_issue / permission / plugin_error / player_report / other');
        $out();
        exit(0);
}
