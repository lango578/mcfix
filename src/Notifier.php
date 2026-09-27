<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 通知引擎。
 *
 * 三件事：
 *   1. 事件分发 —— 业务代码只喊一句 Notifier::dispatch('fix.done', $payload)，不用管发到哪
 *   2. 多渠道并行 —— 钉钉 / 企业微信 / 飞书 / 邮件 / Bark / ntfy / Server酱 / Telegram / Discord / 通用 Webhook
 *   3. 可靠投递 —— 失败的进队列，cron 按退避重试；同一条通知不会重复发
 *
 * 设计取舍：
 *   - **通知失败绝不影响业务流程**。修复该成功的还是成功，通知只是附加动作
 *   - **每个渠道独立隔离**。钉钉挂了不影响邮件发出去
 *   - **有风暴保护**。同一服务器同一事件类型 10 分钟内最多 3 条，避免服务端反复重启时刷屏
 */
final class Notifier
{
    /** 支持的事件类型 => 界面显示名 */
    public const EVENTS = [
        'feedback.new'    => '收到新反馈',
        'diag.done'       => '验证完成（暂不通知，仅记录）',
        'auto_fixed'      => '自动修复成功 ✅',
        'fix.failed'      => '自动修复失败 ❌',
        'need_manual'     => '转人工处理 ⚠️',
        'need_approval'   => '有高风险动作待批准 🔐',
        'agent.offline'   => 'MC 侧 Agent 掉线 📴',
        'client.problem'  => '客户端问题（含可选补齐文件）💻',
        'digest.daily'    => '每日汇总 📊',
        'channel.test'    => '测试消息 🔔',
    ];

    /** 默认通知哪些事件（管理员可在后台改） */
    public const DEFAULT_EVENTS = [
        'auto_fixed'    => true,
        'fix.failed'    => true,
        'need_manual'   => true,
        'need_approval' => true,
        'agent.offline' => true,
        'client.problem'=> false,
        'feedback.new'  => false,
        'digest.daily'  => false,
    ];

    private static $channelsLoaded = null;

    /** @var array<string,Channel\ChannelAdapter>|null */
    private static $adapters = null;

    // ------------------------------------------------------------------ 事件分发

    /**
     * 分发一个事件给所有订阅了它的渠道。
     *
     * @param array<string,mixed> $payload 事件数据（见 buildMessage）
     * @param string $dedupeKey 去重键，同一把钥匙只发一次
     * @return array<int,array<string,mixed>> 每个渠道的结果
     */
    public static function dispatch(string $event, array $payload = [], string $dedupeKey = ''): array
    {
        $config = self::config();
        if (empty($config['enabled'])) {
            return [];
        }

        $subscriptions = self::subscriptionsFor($event);
        if (!$subscriptions) {
            return [];
        }

        $payload['event'] = $event;
        $payload['at'] = now();
        $dedupeKey = $dedupeKey !== '' ? $dedupeKey : self::defaultDedupeKey($event, $payload);

        // 去重：同一把钥匙 6 小时内只发一次
        if ($dedupeKey !== '' && Cache::get('notify-sent:' . $dedupeKey, false) !== false) {
            return [['skipped' => true, 'reason' => '重复通知已跳过', 'dedupe' => $dedupeKey]];
        }

        // 风暴保护：同一服务器同一事件 10 分钟最多 3 条
        $serverId = (string) ($payload['server_id'] ?? '');
        $stormKey = 'notify-storm:' . $event . ':' . ($serverId !== '' ? $serverId : 'global');
        $burst = (int) Cache::get($stormKey, 0);
        $limit = max(1, (int) ($config['burst_limit'] ?? 3));
        if ($burst >= $limit) {
            record_event(
                isset($payload['feedback_id']) ? (int) $payload['feedback_id'] : null,
                'system',
                'notify.storm',
                sprintf('通知风暴保护生效：%s 在 10 分钟内已发 %d 条，本条第暂停', $event, $burst),
                ['server_id' => $serverId],
                'warn'
            );

            return [['skipped' => true, 'reason' => '触发风暴保护']];
        }

        $message = self::buildMessage($event, $payload);
        $results = [];

        foreach ($subscriptions as $channelKey) {
            $adapter = self::adapter($channelKey);
            if ($adapter === null) {
                // 渠道被禁用、类型未知、或者必填项没填 —— 这里是"通知悄悄消失"的唯一入口，
                // 必须留下痕迹，否则管理员只会看到事件页上什么都没有，无从下手。
                $reason = self::adapterProblem($channelKey);
                $results[] = ['skipped' => true, 'channel' => $channelKey, 'reason' => $reason];
                app_log('warn', '通知渠道不可用，已跳过：' . $channelKey . '（' . $reason . '）', [
                    'event'   => $event,
                    'channel' => $channelKey,
                ]);
                record_event(
                    isset($payload['feedback_id']) ? (int) $payload['feedback_id'] : null,
                    'system',
                    'notify.skip',
                    '通知渠道不可用，已跳过：' . self::label($channelKey) . '（' . $reason . '）',
                    ['channel' => $channelKey, 'event' => $event],
                    'warn'
                );
                continue;
            }

            $results[] = self::deliver($adapter, $event, $message, $payload, $dedupeKey);
        }

        // 记数：无论成败都算一次触发，避免风暴保护失效
        Cache::put($stormKey, $burst + 1, 600);
        if ($dedupeKey !== '') {
            Cache::put('notify-sent:' . $dedupeKey, true, 21600);
        }

        return $results;
    }

    /**
     * 给一个渠道发测试消息。
     *
     * @return array{ok:bool,message:string,detail:array<string,mixed>}
     */
    public static function test(string $channelKey): array
    {
        $adapter = self::adapter($channelKey);
        if ($adapter === null) {
            return ['ok' => false, 'message' => '渠道不存在或配置不完整：' . $channelKey, 'detail' => []];
        }

        $message = self::buildMessage('channel.test', [
            'channel'   => $adapter->label(),
            'server_id' => '',
            'title'     => '这是一条测试消息',
            'body'      => "MCFix 通知通道自检\n如果你看到这条消息，说明配置正确。",
        ]);

        $result = $adapter->send($message);

        record_event(null, 'admin', 'notify.test', sprintf(
            '测试通知渠道 %s：%s',
            $adapter->label(),
            !empty($result['ok']) ? '成功' : ('失败 — ' . (string) $result['error'])
        ), ['channel' => $channelKey], !empty($result['ok']) ? 'info' : 'warn');

        return [
            'ok'      => !empty($result['ok']),
            'message' => !empty($result['ok'])
                ? '测试消息已发送，请检查 ' . $adapter->label() . ' 有没有收到'
                : '发送失败：' . (string) $result['error'],
            'detail'  => $result,
        ];
    }

    /**
     * 重试队列里失败的通知（cron 调用）。
     */
    public static function retryPending(int $maxAttempts = 3, int $batch = 20): int
    {
        $rows = Db::all(
            'SELECT * FROM notifications WHERE status = :st AND attempts < :max ORDER BY id ASC LIMIT ' . max(1, min(100, $batch)),
            ['st' => 'failed', 'max' => $maxAttempts]
        );

        $sent = 0;
        foreach ($rows as $row) {
            $adapter = self::adapter((string) $row['channel']);
            if ($adapter === null) {
                Db::update('notifications', [
                    'status'     => 'abandoned',
                    'last_error' => '渠道已删除或配置不完整',
                    'updated_at' => now(),
                ], ['id' => (int) $row['id']]);
                continue;
            }

            $message = [
                'title'    => (string) $row['title'],
                'markdown' => (string) $row['body'],
                'text'     => (string) $row['body'],
                'link'     => (string) ($row['link'] ?? ''),
                'level'    => (string) ($row['level'] ?? 'info'),
            ];

            $result = $adapter->send($message);
            $attempts = (int) $row['attempts'] + 1;

            Db::update('notifications', [
                'status'     => !empty($result['ok']) ? 'sent' : ($attempts >= $maxAttempts ? 'abandoned' : 'failed'),
                'attempts'   => $attempts,
                'last_error' => !empty($result['ok']) ? null : mb_substr((string) $result['error'], 0, 500),
                'sent_at'    => !empty($result['ok']) ? now() : null,
                'updated_at' => now(),
            ], ['id' => (int) $row['id']]);

            if (!empty($result['ok'])) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 每日汇总：一整天干了什么。
     */
    public static function dailyDigest(): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 86400);

        $stats = [
            'new'       => (int) Db::scalar('SELECT COUNT(*) FROM feedback WHERE created_at > :s', ['s' => $since]),
            /*
             * "自动修复成功"不能用 status 名来数。
             *
             * 原来写的是 status = 'resolved'，但工单现在解决即关闭（落 closed），
             * 那个状态名已经不再出现了 —— 再按名字数，这一行会永远是 0，
             * 日报会静默地谎报"一条都没修好"。
             *
             * auto_fixed 才是这件事的**真凭实据**：它是系统自己下发修复并复验
             * 通过时才置 1 的，管理员手动点"标记已解决"明确置 0（见 Workflow::resolve）。
             * 所以按它来数，语义更准，也不会随状态名漂移。
             */
            'resolved'  => (int) Db::scalar('SELECT COUNT(*) FROM feedback WHERE auto_fixed = 1 AND updated_at > :s', ['s' => $since]),
            'manual'    => (int) Db::scalar("SELECT COUNT(*) FROM feedback WHERE status = 'manual' AND updated_at > :s", ['s' => $since]),
            'client'    => (int) Db::scalar('SELECT COUNT(*) FROM client_issues WHERE created_at > :s', ['s' => $since]),
            'fixes'     => (int) Db::scalar("SELECT COUNT(*) FROM tasks WHERE action = 'run' AND status = 'done' AND finished_at > :s", ['s' => $since]),
            'fix_fail'  => (int) Db::scalar("SELECT COUNT(*) FROM tasks WHERE action = 'run' AND status = 'failed' AND finished_at > :s", ['s' => $since]),
        ];

        $topClient = Db::all(
            'SELECT code, COUNT(*) AS c FROM client_issues WHERE created_at > :s GROUP BY code ORDER BY c DESC LIMIT 3',
            ['s' => $since]
        );

        $lines = [
            '**近 24 小时**',
            '',
            '- 新反馈：' . $stats['new'] . ' 条',
            '- 自动修复成功：' . $stats['resolved'] . ' 条',
            '- 转人工：' . $stats['manual'] . ' 条',
            '- 修复动作：成功 ' . $stats['fixes'] . ' / 失败 ' . $stats['fix_fail'],
            '- 客户端问题：' . $stats['client'] . ' 个',
        ];

        if ($topClient) {
            $lines[] = '';
            $lines[] = '**最常见的客户端问题**';
            foreach ($topClient as $row) {
                $def = ClientIssue::get((string) $row['code']);
                $lines[] = '- ' . (string) ($def['title'] ?? $row['code']) . '（' . (int) $row['c'] . ' 次）';
            }
        }

        $manual = Db::all(
            "SELECT ticket_no, player_name, subject FROM feedback WHERE status IN ('manual','unresolved') ORDER BY id DESC LIMIT 5"
        );
        if ($manual) {
            $lines[] = '';
            $lines[] = '**待处理**';
            foreach ($manual as $row) {
                $lines[] = '- ' . (string) $row['ticket_no'] . ' ' . (string) $row['player_name'] . '：' . mb_substr((string) $row['subject'], 0, 30);
            }
        }

        $payload = [
            'title'  => 'MCFix 每日汇总',
            'body'   => implode("\n", $lines),
            'level'  => 'info',
            'link'   => ConsoleAuth::url(),
            'stats'  => $stats,
        ];

        return self::dispatch('digest.daily', $payload, 'digest:' . gmdate('Ymd'));
    }

    // ------------------------------------------------------------------ 投递

    /**
     * @param array<string,mixed> $message
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function deliver(
        Channel\ChannelAdapter $adapter,
        string $event,
        array $message,
        array $payload,
        string $dedupeKey
    ): array {
        $channelKey = $adapter->key();

        try {
            $result = $adapter->send($message);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => '渠道异常：' . $e->getMessage(), 'detail' => []];
        }

        $ok = !empty($result['ok']);

        // 落库：成功的留痕，失败的进重试队列
        try {
            Db::insert('notifications', [
                'feedback_id' => isset($payload['feedback_id']) ? (int) $payload['feedback_id'] : null,
                'server_id'   => (string) ($payload['server_id'] ?? ''),
                'channel'     => $channelKey,
                'event'       => $event,
                'level'       => (string) ($message['level'] ?? 'info'),
                'title'       => mb_substr((string) $message['title'], 0, 200),
                'body'        => mb_substr((string) $message['markdown'], 0, 4000),
                'link'        => mb_substr((string) ($message['link'] ?? ''), 0, 500),
                'status'      => $ok ? 'sent' : 'failed',
                'attempts'    => 1,
                'last_error'  => $ok ? null : mb_substr((string) ($result['error'] ?? ''), 0, 500),
                'dedupe_key'  => $dedupeKey,
                'sent_at'     => $ok ? now() : null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            app_log('error', '写入通知记录失败', ['error' => $e->getMessage()]);
        }

        if (!$ok) {
            app_log('warn', '通知发送失败', [
                'channel' => $channelKey,
                'event'   => $event,
                'error'   => (string) ($result['error'] ?? ''),
            ]);
        }

        return [
            'channel' => $channelKey,
            'label'   => $adapter->label(),
            'ok'      => $ok,
            'error'   => (string) ($result['error'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------ 消息模板

    /**
     * 把事件数据渲染成各渠道能用的消息。
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function buildMessage(string $event, array $payload): array
    {
        $serverName = self::serverName((string) ($payload['server_id'] ?? ''));
        $ticket = (string) ($payload['ticket_no'] ?? '');
        $player = (string) ($payload['player_name'] ?? '');
        $link = (string) ($payload['link'] ?? '');
        if ($link === '' && isset($payload['feedback_id'])) {
            $link = ConsoleAuth::url('p=ticket&id=' . (int) $payload['feedback_id']);
        }

        $titles = [
            'feedback.new'   => '📥 新反馈',
            'diag.done'      => '🔍 验证完成',
            'auto_fixed'     => '✅ 已自动修复',
            'fix.failed'     => '❌ 自动修复失败',
            'need_manual'    => '⚠️ 需要人工处理',
            'need_approval'  => '🔐 有待批准的动作',
            'agent.offline'  => '📴 Agent 掉线',
            'client.problem' => '💻 客户端问题',
            'digest.daily'   => '📊 每日汇总',
            'channel.test'   => '🔔 测试消息',
        ];

        $level = 'info';
        if (in_array($event, ['fix.failed', 'need_manual', 'agent.offline'], true)) {
            $level = 'warn';
        }
        if (in_array($event, ['auto_fixed'], true)) {
            $level = 'ok';
        }

        $title = (string) ($payload['title'] ?? ($titles[$event] ?? 'MCFix 通知'));
        if ($serverName !== '' && !in_array($event, ['channel.test', 'digest.daily'], true)) {
            $title .= ' · ' . $serverName;
        }

        $lines = [];

        // 头一行：谁在哪台服报了什么
        $headline = trim(implode(' ', array_filter([
            $ticket !== '' ? '`' . $ticket . '`' : '',
            $player !== '' ? $player : '',
            (string) ($payload['category_label'] ?? ''),
        ])));
        if ($headline !== '') {
            $lines[] = '**' . $headline . '**';
        }

        if (!empty($payload['body'])) {
            $lines[] = (string) $payload['body'];
        }

        if (!empty($payload['headline'])) {
            $lines[] = '> ' . (string) $payload['headline'];
        }

        if (!empty($payload['problem'])) {
            $lines[] = '**问题**：' . (string) $payload['problem'];
        }

        if (!empty($payload['action'])) {
            $lines[] = '**执行动作**：' . (string) $payload['action'];
        }

        if (!empty($payload['result'])) {
            $lines[] = '**结果**：' . (string) $payload['result'];
        }

        if (!empty($payload['error'])) {
            $lines[] = '**错误**：' . (string) $payload['error'];
        }

        if (!empty($payload['client_issue'])) {
            $lines[] = '**客户端结论**：' . (string) $payload['client_issue'];
        }

        if (!empty($payload['needs']) && is_array($payload['needs'])) {
            $lines[] = '**需要补齐**：' . implode('、', array_slice($payload['needs'], 0, 8));
        }

        if (!empty($payload['checks']) && is_array($payload['checks'])) {
            $lines[] = '';
            foreach (array_slice($payload['checks'], 0, 6) as $check) {
                if (!is_array($check)) {
                    continue;
                }
                $icon = ['pass' => '✓', 'warn' => '!', 'fail' => '✗', 'skipped' => '-', 'unknown' => '?'][(string) ($check['status'] ?? 'unknown')] ?? '?';
                $lines[] = '- [' . $icon . '] ' . (string) ($check['label'] ?? '') . '：' . mb_substr((string) ($check['text'] ?? ''), 0, 90);
            }
        }

        $lines[] = '';
        $lines[] = '_' . date('Y-m-d H:i:s') . '_';

        $markdown = implode("\n", $lines);
        // 纯文本版：去掉 markdown 标记，给短信/不支持 md 的渠道
        $text = preg_replace('/\*\*|`|^>\s?/m', '', $markdown) ?? $markdown;
        $text = preg_replace('/^[_-]{3,}$/m', '', $text) ?? $text;

        return [
            'title'    => $title,
            'markdown' => $markdown,
            'text'     => trim($text),
            'link'     => $link,
            'level'    => $level,
            'event'    => $event,
            'server'   => $serverName,
        ];
    }

    // ------------------------------------------------------------------ 渠道管理

    /**
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        $config = Config::get('notify', []);
        if (!is_array($config)) {
            $config = [];
        }

        return array_merge([
            'enabled'       => false,
            'burst_limit'   => 3,
            'channels'      => [],
            'subscriptions' => [],
        ], $config);
    }

    /**
     * 事件订阅了哪些渠道。
     *
     * @return string[]
     */
    public static function subscriptionsFor(string $event): array
    {
        $config = self::config();
        $subscriptions = (array) ($config['subscriptions'] ?? []);

        // 没配置订阅关系时，用默认规则
        if (!$subscriptions) {
            $enabled = !empty(self::DEFAULT_EVENTS[$event]);
            if (!$enabled) {
                return [];
            }

            return array_keys(self::channelConfigs());
        }

        $channels = [];
        foreach ($subscriptions as $channelKey => $events) {
            if (!is_array($events)) {
                continue;
            }
            // '*' 表示订阅全部
            if (in_array('*', $events, true) || in_array($event, $events, true)) {
                $channels[] = (string) $channelKey;
            }
        }

        return $channels;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function channelConfigs(): array
    {
        if (self::$channelsLoaded !== null) {
            return self::$channelsLoaded;
        }

        $config = self::config();
        $channels = $config['channels'] ?? [];
        self::$channelsLoaded = is_array($channels) ? $channels : [];

        return self::$channelsLoaded;
    }

    public static function adapter(string $channelKey, bool $forceReload = false): ?Channel\ChannelAdapter
    {
        if ($forceReload) {
            self::$adapters = null;
            self::$channelsLoaded = null;
        }

        if (self::$adapters === null) {
            self::$adapters = [];
            foreach (Channel\ChannelRegistry::types() as $type => $class) {
                self::$adapters[$type] = null; // 占位，按需构造
            }
        }

        $configs = self::channelConfigs();
        if (!isset($configs[$channelKey]) || !is_array($configs[$channelKey])) {
            return null;
        }

        $channelConfig = $configs[$channelKey];
        if (array_key_exists('enabled', $channelConfig) && !$channelConfig['enabled']) {
            return null;
        }

        $type = (string) ($channelConfig['type'] ?? $channelKey);
        $adapter = Channel\ChannelRegistry::make($type, $channelConfig);
        if ($adapter === null) {
            return null;
        }

        $missing = $adapter->missingFields();
        if ($missing) {
            return null;
        }

        return $adapter;
    }

    /**
     * 说明某个渠道为什么用不了（给日志和事件页看的）。
     *
     * 和 adapter() 的判断顺序保持一致，这样"为什么被跳过"不会和"为什么没就绪"打架。
     */
    public static function adapterProblem(string $channelKey): string
    {
        $configs = self::channelConfigs();
        if (!isset($configs[$channelKey]) || !is_array($configs[$channelKey])) {
            return '配置里没有这个渠道';
        }
        $config = $configs[$channelKey];

        if (array_key_exists('enabled', $config) && !$config['enabled']) {
            return '渠道已关闭';
        }

        $type = (string) ($config['type'] ?? $channelKey);
        if (Channel\ChannelRegistry::make($type, $config) === null) {
            return '渠道类型未知：' . $type;
        }

        $missing = Channel\ChannelRegistry::missingFields($type, $config);
        if ($missing) {
            return '缺少必填项：' . implode('、', $missing);
        }

        return '渠道不可用';
    }

    /**
     * 渠道显示名（找不到就退回 key）。
     */
    public static function label(string $channelKey): string
    {
        $configs = self::channelConfigs();
        $config = isset($configs[$channelKey]) && is_array($configs[$channelKey]) ? $configs[$channelKey] : [];
        $type = (string) ($config['type'] ?? $channelKey);

        return (string) (Channel\ChannelRegistry::labels()[$type] ?? $channelKey);
    }

    /**
     * 所有渠道的状态（后台展示用）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function channelStatus(): array
    {
        $out = [];
        foreach (self::channelConfigs() as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            $adapter = self::adapter((string) $key);
            $type = (string) ($config['type'] ?? $key);
            $out[] = [
                'key'      => (string) $key,
                'type'     => $type,
                'label'    => $adapter !== null ? $adapter->label() : (string) (Channel\ChannelRegistry::labels()[$type] ?? $type),
                'enabled'  => !array_key_exists('enabled', $config) || (bool) $config['enabled'],
                'ready'    => $adapter !== null,
                'missing'  => $adapter === null
                    ? Channel\ChannelRegistry::missingFields($type, $config)
                    : [],
            ];
        }

        return $out;
    }

    private static function serverName(string $serverId): string
    {
        if ($serverId === '') {
            return '';
        }
        $server = Config::server($serverId);

        return $server !== null ? (string) ($server['name'] ?? $serverId) : $serverId;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function defaultDedupeKey(string $event, array $payload): string
    {
        $parts = [$event];

        foreach (['feedback_id', 'ticket_no', 'server_id', 'channel'] as $key) {
            if (!empty($payload[$key])) {
                $parts[] = $key . '=' . $payload[$key];
            }
        }

        // 没有业务主键的事件（例如测试）不去重
        return count($parts) > 1 ? implode('|', $parts) : '';
    }
}
