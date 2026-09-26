<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 任务队列。所有"带权限的动作"都先落库成一条任务：
 *  - diag   : 采集诊断数据（进程、日志、磁盘、TPS）
 *  - run    : 执行白名单配方（加白名单、解封、重启……）
 *
 * 好处：
 *  1. 面板与 MC 机器解耦，MC 侧 Agent 自己来领任务（长轮询，不用开端口）；
 *  2. 谁在什么时候执行了什么，全都有据可查；
 *  3. 面板重启、Agent 掉线都不会丢任务，租约过期自动重排。
 */
final class Task
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    private const LEASE_SECONDS = 90;

    /**
     * 创建任务。
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $options requires_approval, actor, ttl
     */
    public static function create(
        string $serverId,
        string $action,
        string $recipe,
        array $params = [],
        ?int $feedbackId = null,
        array $options = []
    ): int {
        $requiresApproval = !empty($options['requires_approval']);
        $ttl = (int) ($options['ttl'] ?? 3600);
        if ($ttl <= 0) {
            $ttl = 3600;
        }

        $now = now();
        $id = Db::insert('tasks', [
            'feedback_id'       => $feedbackId,
            'server_id'         => $serverId,
            'recipe'            => $recipe,
            'action'            => $action,
            'params'            => $params ? json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'status'            => $requiresApproval ? self::STATUS_AWAITING_APPROVAL : self::STATUS_QUEUED,
            'requires_approval' => $requiresApproval ? 1 : 0,
            'attempts'          => 0,
            'created_at'        => $now,
            'queued_at'         => $requiresApproval ? null : $now,
            'expires_at'        => gmdate('Y-m-d H:i:s', time() + $ttl),
        ]);

        record_event(
            $feedbackId,
            'system',
            'task.create',
            '创建任务 ' . $recipe . '（' . ($requiresApproval ? '待管理员批准' : '已入队') . '）',
            ['task_id' => $id, 'server_id' => $serverId, 'action' => $action, 'params' => $params]
        );

        return $id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
    }

    /**
     * 管理员批准后从 awaiting_approval 流转到 queued。
     */
    public static function approve(int $id, string $actor = 'admin'): bool
    {
        $task = self::find($id);
        if ($task === null || $task['status'] !== self::STATUS_AWAITING_APPROVAL) {
            return false;
        }

        Db::update('tasks', [
            'status'      => self::STATUS_QUEUED,
            'queued_at'   => now(),
            'approved_by' => $actor,
        ], ['id' => $id]);

        record_event(
            $task['feedback_id'] !== null ? (int) $task['feedback_id'] : null,
            'admin',
            'task.approve',
            '批准执行任务 ' . $task['recipe'],
            ['task_id' => $id, 'actor' => $actor]
        );

        return true;
    }

    public static function reject(int $id, string $actor = 'admin', string $reason = ''): bool
    {
        $task = self::find($id);
        if ($task === null) {
            return false;
        }

        Db::update('tasks', [
            'status'      => self::STATUS_FAILED,
            'error'       => $reason !== '' ? $reason : '管理员已驳回',
            'approved_by' => $actor,
            'finished_at' => now(),
        ], ['id' => $id]);

        record_event(
            $task['feedback_id'] !== null ? (int) $task['feedback_id'] : null,
            'admin',
            'task.reject',
            '驳回任务 ' . $task['recipe'] . ($reason !== '' ? '：' . $reason : ''),
            ['task_id' => $id]
        );

        return true;
    }

    /**
     * Agent 领取任务（长轮询）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function lease(string $serverId, int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $now = time();
        $leaseToken = bin2hex(random_bytes(16));

        $rows = Db::all(
            'SELECT * FROM tasks WHERE server_id = :sid AND status = :st
             AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY id ASC LIMIT ' . $limit,
            ['sid' => $serverId, 'st' => self::STATUS_QUEUED, 'now' => gmdate('Y-m-d H:i:s', $now)]
        );

        $leased = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            // 乐观锁：只有当状态仍是 queued 时才认领成功，避免两个 Agent 抢同一条
            $affected = Db::exec(
                'UPDATE tasks SET status = :new, leased_at = :leased, started_at = :started,
                        lease_token = :token, attempts = attempts + 1
                 WHERE id = :id AND status = :old',
                [
                    'new'     => self::STATUS_RUNNING,
                    'leased'  => gmdate('Y-m-d H:i:s', $now),
                    'started' => gmdate('Y-m-d H:i:s', $now),
                    'token'   => $leaseToken,
                    'id'      => $id,
                    'old'     => self::STATUS_QUEUED,
                ]
            );
            if ($affected <= 0) {
                continue;
            }

            $row['status'] = self::STATUS_RUNNING;
            $row['lease_token'] = $leaseToken;
            $leased[] = $row;
        }

        return $leased;
    }

    /**
     * Agent 上报结果。
     *
     * @param array<string,mixed> $result
     */
    public static function report(int $id, string $leaseToken, bool $ok, array $result, string $error = ''): bool
    {
        $task = self::find($id);
        if ($task === null) {
            return false;
        }
        /*
         * 租约比较用 hash_equals（V10），不用 !==。
         *
         * !== 是短路比较：一旦比到不同的字节就立刻返回，比较耗时随"前缀匹配长度"
         * 变化。理论上可以据此逐字节猜出租约。这是个**时序侧信道**，不是可用的
         * 攻击路径（要反复测量、还有网络抖动），但租约是凭据，比较凭据时就该用
         * 常数时间函数 —— 代价为零，没理由不用。
         *
         * $leaseToken === '' 这条留着是给面板内部调用用的（Executor 内部 10 处
         * 调用都传空串，因为动作就是它自己发起的）。HTTP 那条路已经在
         * agent.php 里把空串堵死了，所以这个"空串即特权"的默认值当前不可达 ——
         * 但仍然是个危险默认：将来新增调用点漏传令牌就会踩到。
         */
        if ($leaseToken !== '' && !hash_equals((string) ($task['lease_token'] ?? ''), $leaseToken)) {
            return false;
        }
        if (in_array((string) $task['status'], [self::STATUS_DONE, self::STATUS_FAILED], true)) {
            return true; // 重复上报，幂等
        }

        Db::update('tasks', [
            'status'      => $ok ? self::STATUS_DONE : self::STATUS_FAILED,
            'result'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error'       => $ok ? null : mb_substr($error, 0, 800),
            'finished_at' => now(),
        ], ['id' => $id]);

        return true;
    }

    /**
     * 记录 Agent 在任务执行过程中的中间日志（长任务进度）。
     *
     * ★ 和 report() 一样必须校验租约（V9）。
     *
     * 原来这个函数不校验任何身份，只看任务存在与否 —— 于是同一台服务器上
     * 任何持有 agent token 的进程都能往**别人的**任务里插进度文字，而这段
     * 文字会显示在工单时间线上。report() 那条路早就堵了，紧邻的这条一直漏着：
     * 两个功能相邻、职责相似，防护却不对等，这种差异迟早被利用。
     *
     * @param string $leaseToken 领任务时拿到的租约；空串表示"面板内部调用"
     *                           （与 report() 保持一致：内部路径传空串跳过校验）
     * @param array<string,mixed> $payload
     * @return bool 租约是否匹配（不匹配返回 false，调用方据此回 409）
     */
    public static function progress(int $id, string $leaseToken, string $message, array $payload = []): bool
    {
        $task = self::find($id);
        if ($task === null) {
            return false;
        }
        // 与 report() 同一套判断：空串留给面板内部调用，远端必须带对租约。
        // 同样用 hash_equals 做常数时间比较（V10）。
        if ($leaseToken !== '' && !hash_equals((string) ($task['lease_token'] ?? ''), $leaseToken)) {
            return false;
        }

        // 已经结束的任务不该再被写进度（否则结果里会混入过期文字）
        if (in_array((string) $task['status'], [self::STATUS_DONE, self::STATUS_FAILED], true)) {
            return false;
        }

        $existing = safe_json_decode($task['result'] ?? null);
        $existing['progress'] = mb_substr($message, 0, 300);
        $existing['progress_at'] = now();
        if ($payload) {
            /*
             * ★ payload 必须限长（V9）。
             *
             * 它是 Agent 端自由填的字段，且原样落进 tasks.result —— 没有上限的话，
             * 一个 agent 就能把这一列撑成任意大小：SQLite 被拖慢、备份变大，
             * 而这里存的东西没有任何一份是必要的。
             *
             * 控制层（agent.php）也加了一道体积检查，但那是给 HTTP 调用方的；
             * 存储层自己收口更可靠 —— 将来新增调用点不会再漏。
             */
            $existing['progress_payload'] = mb_substr(
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0,
                2048
            );
        }

        Db::update('tasks', [
            'result' => json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id' => $id]);

        return true;
    }

    /**
     * 把执行结果整条任务读出来（含 result/error）。
     *
     * @return array<string,mixed>|null
     */
    public static function result(int $id): ?array
    {
        $task = self::find($id);
        if ($task === null) {
            return null;
        }
        $task['result_data'] = safe_json_decode($task['result'] ?? null);
        $task['params_data'] = safe_json_decode($task['params'] ?? null);

        return $task;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forFeedback(int $feedbackId, int $limit = 50): array
    {
        return Db::all(
            'SELECT * FROM tasks WHERE feedback_id = :fid ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            ['fid' => $feedbackId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function pending(string $serverId = '', int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if ($serverId !== '') {
            return Db::all(
                'SELECT * FROM tasks WHERE server_id = :sid AND status IN (:a, :b) ORDER BY id ASC LIMIT ' . $limit,
                ['sid' => $serverId, 'a' => self::STATUS_QUEUED, 'b' => self::STATUS_AWAITING_APPROVAL]
            );
        }

        return Db::all(
            'SELECT * FROM tasks WHERE status IN (:a, :b) ORDER BY id ASC LIMIT ' . $limit,
            ['a' => self::STATUS_QUEUED, 'b' => self::STATUS_AWAITING_APPROVAL]
        );
    }

    /**
     * 超时/掉线任务回收：running 超过租约且超过任务超时的标记失败，
     * 排队太久（Agent 不在线）的直接过期，避免工单永远卡在"正在修复"。
     */
    public static function reclaim(int $taskTimeoutSeconds = 120): int
    {
        $changed = 0;

        $stuck = Db::all(
            'SELECT * FROM tasks WHERE status = :st AND started_at IS NOT NULL',
            ['st' => self::STATUS_RUNNING]
        );
        foreach ($stuck as $task) {
            if (ts((string) $task['started_at']) < time() - max(30, $taskTimeoutSeconds)) {
                Db::update('tasks', [
                    'status'      => self::STATUS_FAILED,
                    'error'       => '执行超时：MC 侧未在时限内回报结果（Agent 可能离线）',
                    'finished_at' => now(),
                ], ['id' => (int) $task['id']]);
                record_event(
                    $task['feedback_id'] !== null ? (int) $task['feedback_id'] : null,
                    'system',
                    'task.timeout',
                    '任务超时失败：' . $task['recipe'],
                    ['task_id' => (int) $task['id']],
                    'warn'
                );
                $changed++;
            }
        }

        $expired = Db::all(
            'SELECT * FROM tasks WHERE status = :st AND expires_at IS NOT NULL AND expires_at < :now',
            ['st' => self::STATUS_QUEUED, 'now' => now()]
        );
        foreach ($expired as $task) {
            Db::update('tasks', [
                'status'      => self::STATUS_EXPIRED,
                'error'       => '任务过期：MC 侧 Agent 长时间未领取',
                'finished_at' => now(),
            ], ['id' => (int) $task['id']]);
            $changed++;
        }

        // 待批准的任务 2 小时没人管也过期
        $stale = Db::all(
            'SELECT * FROM tasks WHERE status = :st AND created_at < :deadline',
            ['st' => self::STATUS_AWAITING_APPROVAL, 'deadline' => gmdate('Y-m-d H:i:s', time() - 7200)]
        );
        foreach ($stale as $task) {
            Db::update('tasks', [
                'status'      => self::STATUS_EXPIRED,
                'error'       => '待批准超时，已自动过期',
                'finished_at' => now(),
            ], ['id' => (int) $task['id']]);
            $changed++;
        }

        return $changed;
    }

    /**
     * 等待任务结束（面板侧阻塞，最长 $timeout 秒）。Agent 模式靠轮询数据库。
     *
     * @return array<string,mixed>|null
     */
    public static function wait(int $id, float $timeout = 20.0, float $interval = 0.4): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $task = self::result($id);
            if ($task !== null && in_array((string) $task['status'], [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_EXPIRED], true)) {
                return $task;
            }
            if (microtime(true) >= $deadline) {
                return self::result($id);
            }
            usleep((int) ($interval * 1000000));
        }
    }

    public static function isFinished(?array $task): bool
    {
        return $task !== null
            && in_array((string) ($task['status'] ?? ''), [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_EXPIRED], true);
    }

    /**
     * Agent 最近执行过的同类动作次数，用于风控。
     */
    public static function recentCount(string $serverId, string $recipe, int $windowSeconds = 3600): int
    {
        $count = Db::scalar(
            'SELECT COUNT(*) FROM tasks WHERE server_id = :sid AND recipe = :r AND status = :st AND finished_at > :since',
            [
                'sid'   => $serverId,
                'r'     => $recipe,
                'st'    => self::STATUS_DONE,
                'since' => gmdate('Y-m-d H:i:s', time() - $windowSeconds),
            ]
        );

        return (int) $count;
    }
}
