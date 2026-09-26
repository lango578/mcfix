<?php

declare(strict_types=1);

namespace MCFix;

use MCFix\Panel\PanelAdapter;
use MCFix\PanelRegistry;

/**
 * 执行器：把"诊断"和"修复"两类任务送到 MC 机器上执行。
 *
 * 四条通路，按能力自动降级（每个服务器在配置里选主执行器，面板 API 可叠加）：
 *
 *   rcon   —— 面板直连 MC 控制台。发游戏指令最可靠（有回显），需要服务端开 enable-rcon
 *   panel  —— 面板 API（MCSManager / 翼龙 / 宝塔 / 自定义）。
 *              不装 Agent 也能读日志、发指令、开关机；宝塔还能读文件
 *   agent  —— MC 机器上的小程序主动领任务。能力最全（进程、磁盘、日志、MOD 清单、重启），
 *              且不需要任何入站端口
 *   ssh    —— 面板机器能 SSH 到 MC 机器时使用
 *   local  —— MC 和面板在同一台机器
 *   none   —— 只做面板能直接完成的部分（Minecraft 协议 + RCON + 面板 API 只读）
 */
final class Executor
{
    /**
     * 汇总本地能直接完成的诊断（不需要 MC 机器配合）。
     *
     * 返回两部分：
     *   handled —— 面板已经得出结果的检查项
     *   remote  —— 需要 MC 机器采集的检查项（交给 Agent / SSH / local 执行器）
     *
     * 通道优先级（按能力，不按配置顺序）：
     *   1. 面板 API 能读日志 → 日志类检查直接在这里做完（不装 Agent 也能诊断）
     *   2. 面板侧直接连 MC 协议 / RCON → 连通性、白名单、封禁、TPS
     *   3. 其余（进程、端口、磁盘、CPU）→ 交给 Agent
     *
     * @param array<string,mixed> $server
     * @param string[] $checkCodes
     * @return array{handled:array<string,mixed>,remote:array<int,string>}
     */
    public static function localChecks(array $server, array $checkCodes): array
    {
        $handled = [];
        $remote = [];
        $mode = (string) ($server['executor'] ?? 'agent');
        $panel = PanelRegistry::adapter($server);
        $panelUsed = false;

        foreach ($checkCodes as $code) {
            $spec = Catalog::checkSpec($code);
            if ($spec === null) {
                $handled[$code] = [
                    'status'  => 'skipped',
                    'message' => '未知检查项，已跳过',
                    'data'    => [],
                ];
                continue;
            }

            // 面板 API 优先处理日志类检查
            if ($panel !== null && in_array($code, ['logs', 'plugin_errors'], true) && in_array('read_log', $panel->capabilities(), true)) {
                $handled[$code] = self::panelLogCheck($server, $panel, $code);
                $panelUsed = true;
                continue;
            }

            if (!empty($spec['remote'])) {
                $remote[] = $code;
                continue;
            }

            // RCON 类检查：没开 RCON 就直接说明原因，而不是报"服务器有问题"
            if (in_array('rcon', (array) ($spec['needs'] ?? []), true) && !Rcon::enabled($server)) {
                /*
                 * 这句提示原来写的是"（可考虑用面板控制台通道）"—— 但它没说的前提是：
                 * 这项检查要**解析指令回显**才能得出结论，而翼龙 / Multicraft /
                 * MCSManager 的接口只管把指令投递进去、不返回控制台输出
                 * （见各适配器 sendCommand 里的说明）。真正能用的只有
                 * CustomPanel，且必须在配置里写明 output_path。
                 *
                 * 所以这里区分两种情况，别让管理员照着一句含糊的提示白折腾：
                 * 能用的面板直接说清要用它；其余情况给出真正可行的两条路。
                 */
                $canUsePanelConsole = $panel !== null && $panel->commandEcho();

                $handled[$code] = [
                    'status'  => 'skipped',
                    'message' => '未开启 RCON，无法用控制台指令验证'
                        . ($canUsePanelConsole
                            ? '（已改用面板控制台通道，因为该面板配置了 output_path）'
                            : '。可行做法：在 server.properties 打开 enable-rcon，或部署 Agent；'
                              . '若面板接口能返回控制台回显，也可用「自定义接口」并填写 output_path'),
                    'data'    => [],
                ];
                continue;
            }

            switch ($code) {
                case 'mc_status':
                    $handled[$code] = self::checkMcStatus($server);
                    break;
                case 'agent_info':
                    $handled[$code] = self::checkAgentOnline((string) $server['id']);
                    break;
                default:
                    // 既不是面板能做的、也不在远程清单里（例如执行器设为 none 时的 tps）
                    if (in_array($mode, ['agent', 'local', 'ssh'], true)) {
                        $remote[] = $code;
                    } else {
                        $handled[$code] = [
                            'status'  => 'skipped',
                            'message' => '该检查项需要 MC 机器上的执行器，当前服务器未配置',
                            'data'    => [],
                        ];
                    }
            }
        }

        // 面板把日志读完了、但 Agent 不在线时，用面板的资源接口补一层信息
        if ($panelUsed && $panel !== null && in_array('resources', $panel->capabilities(), true)) {
            $extra = self::panelResources($panel);
            if ($extra !== null) {
                $handled['process_info'] = $extra;
                $remote = array_values(array_diff($remote, ['process_info']));
            }
        }

        return ['handled' => $handled, 'remote' => array_values(array_unique($remote))];
    }

    /**
     * 通过面板 API 读日志并分析（不需要装 Agent）。
     *
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    private static function panelLogCheck(array $server, \MCFix\Panel\PanelAdapter $panel, string $code): array
    {
        $log = $panel->readLog(524288);

        if (empty($log['ok'])) {
            return [
                'status'  => 'unknown',
                'message' => '面板读日志失败：' . (string) $log['error'],
                'data'    => ['source' => 'panel:' . PanelRegistry::type($server)],
            ];
        }

        $analysis = ServerLog::analyze((string) $log['content']);
        $age = (int) ($log['mtime'] ?? 0) > 0 ? max(0, time() - (int) $log['mtime']) : 0;

        if ($code === 'plugin_errors') {
            $plugins = (array) ($analysis['plugins'] ?? []);
            $total = array_sum($plugins);
            if ($total === 0) {
                return ['status' => 'pass', 'message' => '日志里没有归集到插件报错', 'data' => ['plugins' => []]];
            }
            $top = (string) array_key_first($plugins);

            return [
                'status'  => $total > 5 ? 'fail' : 'warn',
                'message' => sprintf('归集到 %d 条插件报错，最可疑的是 %s（%d 条）', $total, $top, (int) $plugins[$top]),
                'data'    => ['plugins' => $plugins, 'samples' => $analysis['samples'] ?? [], 'source' => 'panel'],
            ];
        }

        $result = ServerLog::toCheckResult($analysis, [
            'source' => 'panel:' . PanelRegistry::type($server),
            'path'   => (string) ($server['log_path'] ?? 'logs/latest.log'),
            'size'   => (int) ($log['size'] ?? 0),
            'age'    => $age,
        ]);

        // 日志长时间不更新 = 服务端可能卡死
        if ($age > 900 && $result['status'] === 'pass') {
            $result['status'] = 'warn';
            $result['message'] .= '；日志已 ' . round($age / 60) . ' 分钟没有更新';
            $result['data']['log_stale'] = true;
        }

        return $result;
    }

    /**
     * 面板的资源接口（翼龙有），补上 CPU / 内存 / 磁盘。
     *
     * @return array<string,mixed>|null
     */
    private static function panelResources(\MCFix\Panel\PanelAdapter $panel): ?array
    {
        // PanelAdapter 有默认实现（不支持的面板返回 ok=false），不用再探测方法是否存在
        /** @var array<string,mixed> $info */
        $info = $panel->resources();
        if (empty($info['ok'])) {
            return null;
        }

        $memMb = round(((int) ($info['mem_bytes'] ?? 0)) / 1048576, 1);
        $diskMb = round(((int) ($info['disk_bytes'] ?? 0)) / 1048576, 1);
        $state = (string) ($info['state'] ?? '');

        return [
            'status'  => in_array($state, ['running', 'starting'], true) ? 'pass' : 'warn',
            'message' => sprintf(
                '面板读数：状态 %s，CPU %.1f%%，内存 %.1f MB，磁盘占用 %.1f MB',
                $state !== '' ? $state : '未知',
                (float) ($info['cpu'] ?? 0),
                $memMb,
                $diskMb
            ),
            'data'    => [
                'source'     => 'panel-resources',
                'state'      => $state,
                'cpu'        => (float) ($info['cpu'] ?? 0),
                'mem_mb'     => $memMb,
                'disk_mb'    => $diskMb,
                'uptime_ms'  => (int) ($info['uptime_ms'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function checkMcStatus(array $server): array
    {
        $host = (string) ($server['host'] ?? '');
        $port = (int) ($server['port'] ?? 25565);
        $status = Net::mcStatus($host, $port, 4.0);

        if ($status['ok']) {
            return [
                'status'  => 'pass',
                'message' => sprintf(
                    '服务器在线：%d/%d 人，延迟 %.0fms%s',
                    $status['players'],
                    $status['max_players'] > 0 ? $status['max_players'] : (int) ($server['max_players'] ?? 0),
                    $status['latency_ms'],
                    $status['version'] !== '' ? '，版本 ' . $status['version'] : ''
                ),
                'data'    => $status,
            ];
        }

        return [
            'status'  => 'fail',
            'message' => '按 Minecraft 协议连接失败：' . $status['error'],
            'data'    => $status,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function checkAgentOnline(string $serverId): array
    {
        $seen = Agent::seen($serverId);
        if ($seen === null || empty($seen['last_seen'])) {
            return [
                'status'  => 'warn',
                'message' => 'MC 侧 Agent 从未上线，自动修复能力不可用（仍可走 RCON）',
                'data'    => [],
            ];
        }

        $age = time() - ts((string) $seen['last_seen']);
        if ($age > 120) {
            return [
                'status'  => 'warn',
                'message' => 'MC 侧 Agent 已离线 ' . duration_text($age) . '，自动修复可能排队等待',
                'data'    => $seen,
            ];
        }

        return [
            'status'  => 'pass',
            'message' => 'MC 侧 Agent 在线（' . human_time((string) $seen['last_seen']) . '心跳）',
            'data'    => $seen,
        ];
    }

    /**
     * 发起一次"远程诊断"任务。
     *
     * @param array<string,mixed> $server
     * @param string[] $checkCodes
     * @param array<string,mixed> $context 额外参数，如 player
     * @return array{ok:bool,executor:string,task_id:int,status:string,error:string,response:array<string,mixed>}
     */
    public static function diagnose(array $server, array $checkCodes, array $context = [], ?int $feedbackId = null): array
    {
        $checkCodes = array_values(array_unique(array_filter($checkCodes)));
        if (!$checkCodes) {
            return self::noop('diagnose');
        }

        $mode = (string) ($server['executor'] ?? 'agent');
        $params = ['checks' => $checkCodes, 'context' => $context];

        switch ($mode) {
            case 'local':
                return self::runLocal('diag', 'diagnose', $params, $server, $feedbackId);
            case 'ssh':
                return self::runSsh($server, 'diag', 'diagnose', $params, $feedbackId);
            case 'agent':
                return self::dispatchToAgent($server, 'diag', 'diagnose', $params, $feedbackId);
            case 'none':
            default:
                return self::noop('diagnose', $mode === 'none' ? '该服务器未配置执行器（只在面板侧诊断）' : '未知执行器');
        }
    }

    /**
     * 下发一条修复配方。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array{ok:bool,executor:string,task_id:int,status:string,error:string,response:array<string,mixed>}
     */
    public static function repair(array $server, string $recipe, array $params = [], ?int $feedbackId = null, bool $needsApproval = false): array
    {
        $def = Recipe::get($recipe);
        if ($def === null) {
            return ['ok' => false, 'executor' => 'none', 'task_id' => 0, 'status' => 'rejected', 'error' => '未知配方', 'response' => []];
        }

        // 这台服务器是否允许该配方（后台可以逐个关掉）。
        //
        // 为什么放在 Executor 这一层，而不是只靠调用方检查：它是公开入口，
        // 而已经有调用点漏掉了这道闸（玩家侧"取回 MOD"那条路只查了白名单和参数，
        // 没查服务器禁用名单）。放在这里，以后新增调用方也不会再漏。
        if (!Recipe::allowed($server, $recipe)) {
            return [
                'ok'       => false,
                'executor' => 'none',
                'task_id'  => 0,
                'status'   => 'rejected',
                'error'    => '这台服务器已禁用该修复动作：' . $recipe,
                'response' => [],
            ];
        }

        $validated = Recipe::validateParams($recipe, $params);
        if (!$validated['ok']) {
            return ['ok' => false, 'executor' => 'none', 'task_id' => 0, 'status' => 'rejected', 'error' => $validated['error'], 'response' => []];
        }
        $params = $validated['params'];

        // ---- 需要审批的动作：动手之前就拦下来 ----
        //
        // 这里必须放在**所有通道之前**。以前 $needsApproval 只传给了
        // dispatchToAgent()，于是 RCON / 面板控制台 / 面板电源 / local / ssh
        // 这五条通道全都无视它直接执行 —— 玩家提交一句"我被误封了"，
        // 走 RCON 的服务器上 `pardon <玩家>` 立刻发出去，而工单上还写着
        // "已提交管理员确认"、管理员的待批准列表里空空如也。
        //
        // 高危配方（unban_player / clear_self_items / restart_server）的审批
        // 不能只在一个通道上成立 —— 那等于没成立。
        if ($needsApproval) {
            $serverId = (string) ($server['id'] ?? '');
            if ($serverId === '') {
                return ['ok' => false, 'executor' => 'none', 'task_id' => 0, 'status' => 'rejected', 'error' => '服务器不存在', 'response' => []];
            }

            $taskId = Task::create($serverId, 'run', $recipe, $params, $feedbackId, [
                'requires_approval' => true,
                'ttl'               => (int) ($server['queue_ttl'] ?? 900),
            ]);

            return [
                'ok'       => true,
                'executor' => (string) ($server['executor'] ?? 'none'),
                'task_id'  => $taskId,
                'status'   => Task::STATUS_AWAITING_APPROVAL,
                'error'    => '',
                'response' => ['queued' => true, 'awaiting_approval' => true],
            ];
        }

        $mode = (string) ($server['executor'] ?? 'agent');
        $panel = PanelRegistry::adapter($server);

        // ---- 通道 1：面板侧 RCON（有回显，最可靠） ----
        if ($def['exec'] === 'panel' && Rcon::enabled($server) && $mode !== 'local') {
            $result = self::runRcon($server, $recipe, $params, $feedbackId);
            if ($result['ok']) {
                return $result;
            }
            // RCON 失败时，面板控制台 / Agent 通路还能兜底
            if ($mode !== 'agent' && $mode !== 'ssh' && $panel === null) {
                return $result;
            }
        }

        // ---- 通道 2：面板控制台（不用开 RCON 也能发游戏指令） ----
        if ($def['exec'] === 'panel' && $panel !== null && in_array('console', $panel->capabilities(), true)) {
            $result = self::runPanelConsole($server, $panel, $recipe, $params, $feedbackId);
            if ($result['ok']) {
                return $result;
            }
            if ($mode === 'none') {
                return $result;
            }
        }

        switch ($mode) {
            case 'local':
                return self::runLocal('run', $recipe, $params, $server, $feedbackId);
            case 'ssh':
                return self::runSsh($server, 'run', $recipe, $params, $feedbackId);
            case 'agent':
                return self::dispatchToAgent($server, 'run', $recipe, $params, $feedbackId, $needsApproval);
            case 'none':
            default:
                if ($def['exec'] === 'panel' && Rcon::enabled($server)) {
                    return self::runRcon($server, $recipe, $params, $feedbackId);
                }

                // 只有面板 API 可用时，尽量把能力用满
                if ($panel !== null) {
                    return self::panelFallback($server, $panel, $recipe, $params, $feedbackId);
                }

                return [
                    'ok'       => false,
                    'executor' => 'none',
                    'task_id'  => 0,
                    'status'   => 'unsupported',
                    'error'    => '该服务器既没配 Agent/SSH，也没有可用 RCON 或面板 API，无法执行「' . $def['label'] . '」',
                    'response' => [],
                ];
        }
    }

    /**
     * 通过面板控制台下发游戏指令（等价 RCON，不需要服务端开 enable-rcon）。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function runPanelConsole(
        array $server,
        \MCFix\Panel\PanelAdapter $panel,
        string $recipe,
        array $params,
        ?int $feedbackId = null
    ): array {
        $command = Recipe::rconCommand($recipe, $params);
        if ($command === '') {
            return ['ok' => false, 'executor' => 'panel-console', 'task_id' => 0, 'status' => 'failed', 'error' => '该配方没有控制台指令模板', 'response' => []];
        }

        $taskId = Task::create((string) $server['id'], 'run', $recipe, $params, $feedbackId);

        $pre = '';
        if ($recipe === 'restart_server') {
            $pre = $panel->sendCommand('save-all flush')['output'];
        }

        $result = $panel->sendCommand($command);
        $ok = !empty($result['ok']) && !$panel->looksFailed($result['output']);

        Task::report($taskId, '', $ok, [
            'channel'    => 'panel-console',
            'panel'      => PanelRegistry::type($server),
            'command'    => $command,
            'output'     => trim($result['output'] . ' ' . $pre),
            'latency_ms' => $result['ms'] ?? 0,
            'transcript' => $panel->transcript(),
        ], $ok ? '' : ((string) ($result['error'] ?? '') ?: '面板控制台返回异常'));

        record_event($feedbackId, 'system', 'fix.panel', sprintf(
            '%s 控制台执行 /%s → %s',
            $panel->label(),
            $command,
            $result['output'] !== '' ? $result['output'] : '(无回显)'
        ), ['task_id' => $taskId, 'ok' => $ok], $ok ? 'info' : 'warn');

        return [
            'ok'       => $ok,
            'executor' => 'panel-console',
            'task_id'  => $taskId,
            'status'   => $ok ? 'done' : 'failed',
            'error'    => $ok ? '' : ((string) ($result['error'] ?? '') ?: '面板控制台返回异常'),
            'response' => [
                'channel' => 'panel-console',
                'panel'   => PanelRegistry::type($server),
                'command' => $command,
                'output'  => trim($result['output'] . ' ' . $pre),
            ],
        ];
    }

    /**
     * 面板 API 兜底：能开关机就开关机，能发指令就发指令。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function panelFallback(
        array $server,
        \MCFix\Panel\PanelAdapter $panel,
        string $recipe,
        array $params,
        ?int $feedbackId = null
    ): array {
        $caps = $panel->capabilities();

        // 重启类：面板开关机
        if ($recipe === 'restart_server' && in_array('power', $caps, true)) {
            $taskId = Task::create((string) $server['id'], 'run', $recipe, $params, $feedbackId);

            // 先尽量保存世界（有控制台能力时）
            if (in_array('console', $caps, true)) {
                $panel->sendCommand('save-all flush');
            }

            $result = $panel->power('restart');
            $ok = !empty($result['ok']);

            $wait = [];
            if ($ok) {
                $wait = self::waitForServerUp($server, 100);
                $ok = !empty($wait['up']);
            }

            Task::report($taskId, '', $ok, [
                'channel'    => 'panel-power',
                'panel'      => PanelRegistry::type($server),
                'output'     => (string) ($result['output'] ?? ''),
                'transcript' => $panel->transcript(),
                'wait'       => $wait,
            ], $ok ? '' : ((string) ($result['error'] ?? '') ?: '面板重启后服务端未恢复'));

            record_event($feedbackId, 'system', 'fix.panel.power', $panel->label() . ' 执行重启' . ($wait ? '：' . (string) $wait['message'] : ''), [
                'task_id' => $taskId,
                'ok'      => $ok,
            ], $ok ? 'info' : 'warn');

            return [
                'ok'       => $ok,
                'executor' => 'panel-power',
                'task_id'  => $taskId,
                'status'   => $ok ? 'done' : 'failed',
                'error'    => $ok ? '' : ((string) ($result['error'] ?? '') ?: '面板重启失败'),
                'response' => [
                    'channel' => 'panel-power',
                    'panel'   => PanelRegistry::type($server),
                    'output'  => (string) ($result['output'] ?? ''),
                    'wait'    => $wait,
                ],
            ];
        }

        // 保存世界：能发指令就发
        if ($recipe === 'save_world' && in_array('console', $caps, true)) {
            return self::runPanelConsole($server, $panel, $recipe, $params, $feedbackId);
        }

        return [
            'ok'       => false,
            'executor' => 'panel',
            'task_id'  => 0,
            'status'   => 'unsupported',
            'error'    => $panel->label() . ' 不支持「' . (string) (Recipe::get($recipe)['label'] ?? $recipe) . '」'
                . '（该面板能力：' . implode('、', $caps) . '）。'
                . '可以开启 RCON，或在 MC 机器上部署 Agent。',
            'response' => [],
        ];
    }

    // ------------------------------------------------------------------ 各通路实现

    /**
     * 面板侧 RCON：同步执行，结果立刻可得。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function runRcon(array $server, string $recipe, array $params, ?int $feedbackId = null): array
    {
        $command = Recipe::rconCommand($recipe, $params);
        if ($command === '') {
            return ['ok' => false, 'executor' => 'rcon', 'task_id' => 0, 'status' => 'failed', 'error' => '该配方没有 RCON 指令模板', 'response' => []];
        }

        $taskId = Task::create((string) $server['id'], 'run', $recipe, $params, $feedbackId);
        $rcon = Rcon::fromServer($server);

        $waitForRestart = false;
        $preOutput = '';

        if ($recipe === 'restart_server') {
            $save = $rcon->command('save-all flush');
            $preOutput = $save['output'];
            $waitForRestart = true;
        }

        $result = $rcon->command($command);
        $transcript = $rcon->transcript();
        $rcon->close();

        $ok = $result['ok'] && !self::rconLooksLikeError($result['output']);

        // 重启：再等端口真的回来，否则工单会在"复验"阶段误判为"还是坏的"
        $waitInfo = [];
        if ($waitForRestart && $ok) {
            $waitInfo = self::waitForServerUp($server);
            $ok = !empty($waitInfo['up']);
        }

        Task::report($taskId, '', $ok, [
            'channel'    => 'rcon',
            'command'    => $command,
            'output'     => trim($result['output'] . ' ' . $preOutput),
            'latency_ms' => $result['ms'],
            'transcript' => $transcript,
            'wait'       => $waitInfo,
        ], $result['error'] !== ''
            ? $result['error']
            : ($ok ? '' : ($waitForRestart ? '重启指令已发送，但服务端未在时限内恢复监听' : '服务端返回异常：' . $result['output'])));

        record_event($feedbackId, 'system', 'fix.rcon', 'RCON 执行 /' . $command . ' → ' . ($result['output'] !== '' ? $result['output'] : '(无回显)'), [
            'task_id' => $taskId,
            'ok'      => $ok,
            'wait'    => $waitInfo,
        ], $ok ? 'info' : 'warn');

        return [
            'ok'       => $ok,
            'executor' => 'rcon',
            'task_id'  => $taskId,
            'status'   => $ok ? 'done' : 'failed',
            'error'    => $ok ? '' : ($result['error'] !== ''
                ? $result['error']
                : ($waitForRestart ? '服务端未在时限内恢复' : '指令返回异常')),
            'response' => [
                'channel'    => 'rcon',
                'command'    => $command,
                'output'     => trim($result['output'] . ' ' . $preOutput),
                'latency_ms' => $result['ms'],
                'wait'       => $waitInfo,
            ],
        ];
    }

    /**
     * 等 MC 服务端端口重新可连（restart 场景）。
     *
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function waitForServerUp(array $server, int $maxSeconds = 100): array
    {
        $host = (string) ($server['host'] ?? '');
        $port = (int) ($server['listen_port'] ?? ($server['port'] ?? 25565));
        $start = microtime(true);
        $attempts = 0;

        while (microtime(true) - $start < $maxSeconds) {
            $attempts++;
            usleep(1500000); // 1.5 秒一次，避免把刚启动的服务端探测崩
            $probe = Net::tcpPing($host, $port, 2.0);
            if ($probe['ok']) {
                return [
                    'up'       => true,
                    'waited'   => round(microtime(true) - $start, 1),
                    'attempts' => $attempts,
                    'message'  => '服务端已恢复，端口 ' . $port . ' 可连接',
                ];
            }
        }

        return [
            'up'       => false,
            'waited'   => round(microtime(true) - $start, 1),
            'attempts' => $attempts,
            'message'  => '等待 ' . $maxSeconds . ' 秒后仍无法连接 ' . $host . ':' . $port,
        ];
    }

    private static function rconLooksLikeError(string $output): bool
    {
        if ($output === '') {
            return false;
        }
        $lower = mb_strtolower($output);
        foreach (['unknown command', 'unknown or incomplete', 'incorrect argument', 'no player was found', 'that player does not exist', 'you do not have permission', 'error:'] as $needle) {
            if (mb_strpos($lower, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agent 通路：入队 + 等结果。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function dispatchToAgent(
        array $server,
        string $action,
        string $recipe,
        array $params,
        ?int $feedbackId,
        bool $needsApproval = false
    ): array {
        $serverId = (string) $server['id'];
        $queueTtl = (int) ($server['queue_ttl'] ?? 900);

        $taskId = Task::create($serverId, $action, $recipe, $params, $feedbackId, [
            'requires_approval' => $needsApproval,
            'ttl'               => $queueTtl,
        ]);

        if ($needsApproval) {
            return [
                'ok'       => true,
                'executor' => 'agent',
                'task_id'  => $taskId,
                'status'   => Task::STATUS_AWAITING_APPROVAL,
                'error'    => '',
                'response' => ['queued' => true, 'awaiting_approval' => true],
            ];
        }

        $wait = (float) ($server['dispatch_wait'] ?? 20);
        $task = Task::wait($taskId, $wait);
        $finished = Task::isFinished($task);
        $response = $task !== null ? (array) ($task['result_data'] ?? []) : [];
        $ok = $finished && (string) $task['status'] === Task::STATUS_DONE && !empty($response);

        if (!$finished) {
            // 还没执行完：交给 cron/后台继续跟进，工单先标"修复中"
            return [
                'ok'       => false,
                'executor' => 'agent',
                'task_id'  => $taskId,
                'status'   => 'pending',
                'error'    => 'MC 侧 Agent 尚未返回结果（已入队，稍后自动跟进）',
                'response' => ['queued' => true],
            ];
        }

        if (!$ok) {
            return [
                'ok'       => false,
                'executor' => 'agent',
                'task_id'  => $taskId,
                'status'   => (string) $task['status'],
                'error'    => (string) ($task['error'] ?? '') !== '' ? (string) $task['error'] : 'MC 侧执行失败',
                'response' => $response,
            ];
        }

        // 重启类：Agent 只负责拉起进程，这里再确认端口真的回来了
        $waitInfo = [];
        if ($recipe === 'restart_server') {
            $waitInfo = self::waitForServerUp($server, 100);
        }

        return [
            'ok'       => true,
            'executor' => 'agent',
            'task_id'  => $taskId,
            'status'   => 'done',
            'error'    => '',
            'response' => $waitInfo ? array_merge($response, ['wait' => $waitInfo]) : $response,
        ];
    }

    /**
     * 本地/SSH 通路：直接同步执行远端脚本。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function runLocal(string $action, string $recipe, array $params, array $server, ?int $feedbackId): array
    {
        $taskId = Task::create((string) $server['id'], $action, $recipe, $params, $feedbackId);
        $root = MCFIX_ROOT;

        return self::executeViaShell(
            $taskId,
            'local',
            /*
             * 用**数组**形式，不拼 shell 命令行。
             *
             * 原因：任务参数是一段 JSON，要塞进命令行就只能靠转义。而 PHP 在
             * Windows 上的 escapeshellarg() 走的是 POSIX 规则 —— cmd.exe 不支持
             * `\"`，所以它干脆把参数里**所有双引号删掉**。到 Agent 那边就变成
             *   { action : diag , recipe : diagnose , params :{ player : x }}
             * json_decode 必然失败，Agent 一律回"任务 JSON 解析失败"。
             *
             * 后果不只是"本地执行器不能用"：这个失败被降级成 unknown，而 Verdict
             * 对 unknown 不分类 → 对外表现为"没有验证到服务器侧异常 → 转人工"。
             * 也就是**故障被伪装成了正常结论**，管理员看不到任何报错。
             *
             * proc_open() 支持 argv 数组，直接绕开 shell 的引号规则，两个平台都对。
             */
            [
                self::phpBinaryPath(),
                $root . '/agent/mcfix-agent.php',
                '--local-task',
                (string) json_encode(['action' => $action, 'recipe' => $recipe, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '--root',
                $root,
            ],
            $server,
            $feedbackId
        );
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function runSsh(array $server, string $action, string $recipe, array $params, ?int $feedbackId): array
    {
        $taskId = Task::create((string) $server['id'], $action, $recipe, $params, $feedbackId);
        $ssh = is_array($server['ssh'] ?? null) ? $server['ssh'] : [];

        if (empty($ssh['host'])) {
            Task::report($taskId, '', false, [], '未配置 SSH 连接信息');
            return ['ok' => false, 'executor' => 'ssh', 'task_id' => $taskId, 'status' => 'failed', 'error' => '未配置 SSH 连接信息', 'response' => []];
        }

        $payload = (string) json_encode(['action' => $action, 'recipe' => $recipe, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $remoteAgent = (string) ($ssh['agent_path'] ?? '/opt/mcfix/mcfix-agent.php');

        /*
         * 远端命令用**单引号包裹 + 内部单引号转义**，而不是 escapeshellarg()。
         *
         * 和 runLocal() 同一个根因：这段 JSON 里全是双引号，而 Windows 上的
         * escapeshellarg() 会把它们全部删掉 → 远端 Agent 收到坏 JSON → 静默失败。
         * 这里的目标 shell 是**远端 Linux** 的 sh，不是本机的 cmd.exe，所以直接按
         * POSIX 单引号规则手写转义才是对的（单引号内除了 ' 本身没有特殊字符）。
         *
         * 注意：$remoteAgent / $root 仍然各自单独包一层，避免路径里的空格把参数截断。
         */
        $sq = static function (string $value): string {
            return "'" . str_replace("'", "'\\''", $value) . "'";
        };
        $remoteCmd = 'php ' . $sq($remoteAgent) . ' --local-task ' . $sq($payload)
            . ' --root ' . $sq(dirname($remoteAgent));

        $host = (string) $ssh['host'];
        $user = (string) ($ssh['user'] ?? 'root');
        $port = (int) ($ssh['port'] ?? 22);
        $key = (string) ($ssh['private_key'] ?? '');
        if ($key !== '' && strpos($key, '/') !== 0 && !preg_match('#^[A-Za-z]:#', $key)) {
            $key = MCFIX_ROOT . '/' . ltrim($key, '/');
        }

        $sshBin = self::findBinary(['ssh']);
        if ($sshBin === '') {
            Task::report($taskId, '', false, [], '系统里找不到 ssh 客户端');
            return ['ok' => false, 'executor' => 'ssh', 'task_id' => $taskId, 'status' => 'failed', 'error' => '系统里找不到 ssh 客户端', 'response' => []];
        }

        $cmd = escapeshellarg($sshBin)
            . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10'
            . ' -p ' . $port
            . ($key !== '' ? ' -i ' . escapeshellarg($key) : '')
            . ' ' . escapeshellarg($user . '@' . $host)
            . ' ' . escapeshellarg($remoteCmd);

        return self::executeViaShell($taskId, 'ssh', $cmd, $server, $feedbackId);
    }

    /**
     * 跑一条命令，把 stdout 当 JSON 解析，并回写任务结果。
     *
     * @param string|array<int,string> $command
     *        字符串 = 走 shell（MCFix 自己拼的命令，各处已转义）；
     *        数组   = proc_open 的 argv 形式，不经 shell —— 传 JSON 参数时必须用这种，
     *                 否则 Windows 上的 escapeshellarg() 会把 JSON 里的双引号删光。
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    private static function executeViaShell(int $taskId, string $channel, $command, array $server, ?int $feedbackId): array
    {
        $timeout = (int) ($server['task_timeout'] ?? 90);
        $start = microtime(true);
        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        if (!function_exists('proc_open') || in_array('proc_open', self::disabledFunctions(), true)) {
            $error = 'PHP 的 proc_open 被禁用，无法执行本地/SSH 通路（宝塔【PHP 设置 → 禁用函数】里删掉 proc_open 即可）';
            Task::report($taskId, '', false, [], $error);

            return ['ok' => false, 'executor' => $channel, 'task_id' => $taskId, 'status' => 'failed', 'error' => $error, 'response' => []];
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, null);

        if (!is_resource($process)) {
            // 数组形式不能直接拼字符串，会触发 "Array to string conversion" 警告
            $error = '命令启动失败：' . (is_array($command) ? implode(' ', $command) : $command);
            Task::report($taskId, '', false, [], $error);

            return ['ok' => false, 'executor' => $channel, 'task_id' => $taskId, 'status' => 'failed', 'error' => $error, 'response' => []];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $timeout;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $exitCode = -1;
                $stderr .= "\n执行超时（{$timeout}s）";
                break;
            }
            usleep(80000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $elapsed = round(microtime(true) - $start, 2);
        $decoded = self::extractJson($stdout);

        if ($exitCode !== 0 && $decoded === null) {
            $error = trim($stderr) !== '' ? mb_substr(trim($stderr), 0, 500) : '命令退出码 ' . $exitCode;
            Task::report($taskId, '', false, ['stdout' => mb_substr($stdout, 0, 2000), 'stderr' => mb_substr($stderr, 0, 2000)], $error);

            return ['ok' => false, 'executor' => $channel, 'task_id' => $taskId, 'status' => 'failed', 'error' => $error, 'response' => []];
        }

        $ok = $decoded !== null && !empty($decoded['ok']);
        $response = $decoded ?? ['raw' => mb_substr($stdout, 0, 2000)];
        $error = $ok ? '' : (string) ($decoded['error'] ?? (trim($stderr) !== '' ? trim($stderr) : '执行器未返回有效结果'));

        Task::report($taskId, '', $ok, array_merge($response, ['channel' => $channel, 'elapsed' => $elapsed]), $error);

        return [
            'ok'       => $ok,
            'executor' => $channel,
            'task_id'  => $taskId,
            'status'   => $ok ? 'done' : 'failed',
            'error'    => $error,
            'response' => $response,
        ];
    }

    /**
     * 从命令输出里抠出最后一个 JSON 对象（脚本可能先打印日志）。
     *
     * @return array<string,mixed>|null
     */
    private static function extractJson(string $stdout): ?array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return null;
        }

        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($stdout, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function noop(string $kind, string $reason = '无需远程执行'): array
    {
        return [
            'ok'       => false,
            'executor' => 'none',
            'task_id'  => 0,
            'status'   => 'skipped',
            'error'    => $reason,
            'response' => [],
        ];
    }

    public static function phpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && @is_file(PHP_BINARY)) {
            return escapeshellarg(PHP_BINARY);
        }
        $found = self::findBinary(['php', 'php8', 'php7']);

        return $found !== '' ? escapeshellarg($found) : 'php';
    }

    /**
     * PHP 解释器的**原始路径**（不做 shell 转义）。
     *
     * 为什么和 phpBinary() 分开：那个方法返回的是已经转义好的字符串，只适合拼进
     * shell 命令行。走 proc_open() 的数组形式时代码不经过 shell，再传一个带引号的
     * 路径过去，Windows 会去找一个名字里带引号的 exe，必然启动失败。
     */
    public static function phpBinaryPath(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && @is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }
        $found = self::findBinary(['php', 'php8', 'php7']);

        return $found !== '' ? $found : 'php';
    }

    /**
     * @param string[] $names
     */
    public static function findBinary(array $names): string
    {
        $paths = ['/usr/bin', '/usr/local/bin', '/bin', '/usr/sbin', '/opt/homebrew/bin'];
        foreach ($names as $name) {
            foreach ($paths as $path) {
                $candidate = $path . '/' . $name;
                if (@is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @return string[]
     */
    public static function disabledFunctions(): array
    {
        $raw = (string) ini_get('disable_functions');

        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
