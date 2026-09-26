<?php
/**
 * MC 侧 Agent 通信接口（Agent → 面板 单向拉取，面板不需要能连到 MC 机器）。
 *
 * 路由：?r=api.agent.<action>
 *   poll    心跳 + 领取任务（长轮询；Agent 每 5~10 秒来一次）
 *   report  上报任务结果
 *   log     上报任务执行过程中的中间日志
 *
 * 鉴权：server_id + agent_token（配置里的固定串或 Token 生成的签名串）
 * 可选：agent_ip_allow（该服务器允许哪些来源 IP 领取任务，防令牌泄露后异地使用）
 */

declare(strict_types=1);

use MCFix\Agent;
use MCFix\Config;
use MCFix\ModLibrary;
use MCFix\Rate;
use MCFix\Recipe;
use MCFix\Task;
use MCFix\Workflow;

$input = request_data();

// 支持两种 URL 形态：
//   ?r=api.agent&action=poll      （Agent 默认用这个，写死也没关系）
//   ?r=api.agent.poll
$action = $action !== '' && $action !== 'agent' ? $action : param($input, 'action', param($_GET, 'action', 'poll'));
$action = trim((string) $action);
$action = ltrim($action, '.');

$serverId = param($input, 'server_id');
$token = param($input, 'token');

if ($serverId === '' || $token === '') {
    json_fail('缺少 server_id 或 token', 401);
}

if (!Agent::auth($serverId, $token)) {
    app_log('warn', 'Agent 鉴权失败', ['server_id' => $serverId, 'ip' => client_ip()]);
    usleep(300000); // 轻微延迟，降低暴力猜测速度
    json_fail('鉴权失败：server_id 与 token 不匹配', 401);
}

$server = Config::server($serverId);
if ($server === null) {
    json_fail('服务器未在面板配置或已禁用', 403);
}

// 来源 IP 白名单（可选）
$allow = (array) ($server['agent_ip_allow'] ?? []);
if ($allow) {
    $ip = client_ip();
    $ok = false;
    foreach ($allow as $pattern) {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            continue;
        }
        if ($pattern === $ip) {
            $ok = true;
            break;
        }
        if (strpos($pattern, '*') !== false) {
            $regex = '#^' . str_replace(['\*', '\.'], ['[0-9]{1,3}', '\.'], preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $ip)) {
                $ok = true;
                break;
            }
        }
    }
    if (!$ok) {
        app_log('warn', 'Agent 来源 IP 不在白名单', ['server_id' => $serverId, 'ip' => $ip]);
        json_fail('来源 IP ' . $ip . ' 不在该服务器的 agent_ip_allow 白名单里', 403);
    }
}

switch ($action) {
    case 'poll':
        // 领取任务也限流，但额度给足（10 秒一次轮询 = 360 次/小时）
        $state = Rate::hit('agent:' . $serverId, 900, 3600);
        if (!$state['allowed']) {
            json_fail('轮询过于频繁，请把间隔调整到 5 秒以上', 429);
        }

        $report = (array) ($input['report'] ?? []);
        if ($report) {
            Agent::heartbeat($serverId, $report);
        }

        $tasks = Task::lease($serverId, 3);
        $out = [];
        foreach ($tasks as $task) {
            $out[] = [
                'id'          => (int) $task['id'],
                'action'      => (string) $task['action'],
                'recipe'      => (string) $task['recipe'],
                'params'      => safe_json_decode((string) ($task['params'] ?? '')),
                'lease_token' => (string) $task['lease_token'],
                'feedback_id' => $task['feedback_id'] !== null ? (int) $task['feedback_id'] : 0,
            ];
        }

        if ($out) {
            record_event(null, 'agent', 'agent.lease', 'Agent 领取了 ' . count($out) . ' 个任务', [
                'server_id' => $serverId,
                'tasks'     => array_map(static function (array $t): array {
                    return ['id' => $t['id'], 'recipe' => $t['recipe'], 'action' => $t['action']];
                }, $out),
            ]);
        }

        // 顺带下发"允许执行的配方清单"和守护配置，Agent 可以据此自检
        $recipes = [];
        foreach (Recipe::all() as $code => $def) {
            if (($def['exec'] ?? '') !== 'agent' && ($def['exec'] ?? '') !== 'both') {
                continue;
            }
            if (!Recipe::allowed($server, (string) $code)) {
                continue;
            }
            $recipes[] = (string) $code;
        }

        json_ok([
            'tasks'     => $out,
            'interval'  => 8,
            'server'    => [
                'id'       => $serverId,
                'name'     => (string) ($server['name'] ?? $serverId),
                'mc_dir'   => (string) ($server['mc_dir'] ?? ''),
                'log_path' => (string) ($server['log_path'] ?? 'logs/latest.log'),
                'listen_port' => (int) ($server['listen_port'] ?? ($server['port'] ?? 25565)),
                'disk_path'   => (string) ($server['disk_path'] ?? ($server['mc_dir'] ?? '/')),
                'guard'    => (array) ($server['guard'] ?? []),
                'task_timeout' => (int) ($server['task_timeout'] ?? 90),
            ],
            'recipes'   => $recipes,
            'server_time' => now(),
        ]);
        break;

    case 'report':
        $taskId = int_param($input, 'task_id');
        $leaseToken = param($input, 'lease_token');
        $ok = bool_param($input, 'ok');
        $result = (array) ($input['result'] ?? []);
        $error = param($input, 'error', '', 800);

        if ($taskId <= 0) {
            json_fail('缺少 task_id', 400);
        }

        // 租约令牌必填。
        //
        // Task::report() 里 `&& $leaseToken !== ''` 那一半是留给**面板自己**用的
        // （Executor 内部 10 处调用都传空串，因为动作就是它自己发起的）。
        // 但 Agent 是远端调用方：允许它传空串，等于它可以把本服务器上
        // **任何**任务（包括另一个进程正在跑、租约还没到期的）标成成功或失败，
        // 并改写 tasks.result。这里把远端这条路堵死，面板内部路径不受影响。
        if ($leaseToken === '') {
            json_fail('缺少租约令牌（请先领取任务再上报）', 409);
        }

        $task = Task::find($taskId);
        if ($task === null || (string) $task['server_id'] !== $serverId) {
            json_fail('任务不存在或不属于该服务器', 404);
        }

        if (!Task::report($taskId, $leaseToken, $ok, $result, $error)) {
            json_fail('租约校验失败（任务可能已被回收，请忽略）', 409);
        }

        $feedbackId = $task['feedback_id'] !== null ? (int) $task['feedback_id'] : null;
        record_event($feedbackId, 'agent', 'task.report', sprintf(
            'MC 侧回报任务 #%d %s：%s',
            $taskId,
            (string) $task['recipe'],
            $ok ? '成功' : '失败 — ' . $error
        ), [
            'task_id' => $taskId,
            'ok'      => $ok,
            'result'  => $result,
        ], $ok ? 'info' : 'error');

        // 任务完成后如果是修复类，触发一次异步复验
        if ($ok && $feedbackId !== null && (string) $task['action'] === 'run') {
            $feedback = Workflow::find($feedbackId);
            if ($feedback !== null && in_array((string) $feedback['status'], ['fixing', 'verifying'], true)) {
                Workflow::reverify($feedback, (string) $task['recipe']);
            }
        }

        json_ok(['accepted' => true]);
        break;

    case 'log':
        $taskId = int_param($input, 'task_id');
        $message = param($input, 'message', '', 300);
        $leaseToken = param($input, 'lease_token');
        $extra = (array) ($input['extra'] ?? []);
        if ($taskId <= 0 || $message === '') {
            json_fail('缺少参数', 400);
        }
        // 和 report 分支同样的租约校验：领了任务的人才能写进度（V9）。
        // 否则同一台服务器上任何持有 agent token 的进程都能往别人的任务里插话。
        if ($leaseToken === '') {
            json_fail('缺少租约令牌（请先领取任务再上报进度）', 409);
        }
        $task = Task::find($taskId);
        if ($task === null || (string) $task['server_id'] !== $serverId) {
            json_fail('任务不存在', 404);
        }
        // extra 不能无限大：它是 Agent 端自由填的字段，直接落库会让 tasks.result 膨胀
        if (strlen((string) json_encode($extra, JSON_UNESCAPED_UNICODE)) > 4000) {
            json_fail('extra 内容过大', 413);
        }
        if (!Task::progress($taskId, $leaseToken, $message, $extra)) {
            json_fail('租约校验失败（任务可能已被回收或已完成，请忽略）', 409);
        }
        json_ok(['accepted' => true]);
        break;

    case 'mod_upload':
        // Agent 把服务端的 MOD 文件送上来（玩家缺 MOD 时自动分发）
        $filename = param($input, 'filename', '', 160);
        $component = param($input, 'component', '', 120);
        $b64 = (string) ($input['content_b64'] ?? '');
        $claimedSha = param($input, 'sha256', '', 64);

        if ($filename === '' || $b64 === '') {
            json_fail('缺少 filename 或 content_b64', 400);
        }

        // 解码并做体积保护（base64 膨胀约 4/3）
        if (strlen($b64) > (int) (ModLibrary::MAX_FILE_BYTES * 1.4)) {
            json_fail('文件超过允许的大小', 413);
        }
        $bytes = base64_decode($b64, true);
        if (!is_string($bytes) || $bytes === '') {
            json_fail('base64 解码失败', 400);
        }
        if (strlen($bytes) > ModLibrary::MAX_FILE_BYTES) {
            json_fail('文件超过允许的大小', 413);
        }
        if ($claimedSha !== '' && !hash_equals(strtolower($claimedSha), hash('sha256', $bytes))) {
            json_fail('文件校验失败（sha256 不一致）', 400);
        }

        $stored = ModLibrary::storeBytes($bytes, $filename, [
            'server_id' => $serverId,
            'source'    => 'agent',
            'added_by'  => 'agent',
        ]);

        if (empty($stored['ok'])) {
            json_fail('入库失败：' . (string) $stored['error'], 400);
        }

        record_event(null, 'agent', 'mod.upload', sprintf(
            'Agent 取回 MOD：%s（%s），组件 %s',
            $filename,
            human_size((float) $stored['size']),
            $component !== '' ? $component : '未标注'
        ), [
            'server_id' => $serverId,
            'file'      => $stored['id'],
            'component' => $component,
        ]);

        json_ok([
            'id'       => $stored['id'],
            'filename' => $stored['filename'],
            'size'     => $stored['size'],
            'sha256'   => $stored['sha256'],
        ]);
        break;

    default:
        json_fail('未知 Agent 接口：' . $action, 404);
}
