<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 工单邮件：玩家回执 + 管理员提醒。**和「通知渠道」是两回事**。
 *
 *   通知渠道（src/Notifier.php + src/Channel/）—— 群机器人那一套，给管理员/群里推事件
 *   本模块                                  —— 给**玩家**发回执和结果，顺带用邮件提醒管理员
 *
 * 为什么单独做一套、而不是让管理员去建一个 mail 渠道：
 *   建渠道要填收件人、要逐个勾事件订阅，而"玩家提交了 → 给他发一封收到了 → 修好再发一封"
 *   是每个服主都想要的行为。这里只要开关 + 一个管理员邮箱，其余全自动。
 *
 * 三条设计约束（都很重要）：
 *   1. **发信不阻塞玩家**。提交接口只入队，真正发送交给每分钟跑一次的 cron。
 *      mail() 在没装 MTA 的机器上会卡好几秒，不该让玩家等这个。
 *   2. **邮箱不长期留存**。玩家邮箱只为了"把结果告诉他"而存在：
 *      队列里的明文在发出去（或彻底失败）后立刻清掉，工单结束后库里也只留掩码。
 *   3. **发信失败绝不能影响主流程**。所有入口都是 try/catch + 记日志。
 */
final class TicketMail
{
    /** 入队后多久开始尝试（秒）—— 留一点余量让工单诊断先跑起来 */
    private const FIRST_DELAY = 20;

    /** 失败后的退避（秒），按尝试次数递增，最多重试 4 次 */
    private const RETRY_BACKOFF = [60, 180, 600, 1800];

    public const KIND_PLAYER_RECEIPT = 'player_receipt';
    public const KIND_PLAYER_RESULT  = 'player_result';
    public const KIND_ADMIN_NEW     = 'admin_new';

    // ------------------------------------------------------------------ 配置

    /**
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $raw = Config::get('email', []);

        return array_merge([
            'enabled'       => false,
            'admin_to'      => '',
            'from'          => '',
            'from_name'     => 'MC 故障反馈系统',
            'notify_player' => true,
            'notify_admin'  => true,
            'purge_email'   => true,
        ], is_array($raw) ? $raw : []);
    }

    public static function enabled(): bool
    {
        return !empty(self::settings()['enabled']);
    }

    /**
     * 发件人地址。留空就按站点域名拼一个 no-reply@。
     *
     * 为什么不直接写死 no-reply@localhost：很多收信方会把这类地址直接判垃圾邮件。
     */
    public static function fromAddress(): string
    {
        $configured = trim((string) self::settings()['from']);
        if ($configured !== '' && self::isEmail($configured)) {
            return $configured;
        }

        $host = (string) parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST);
        if ($host === '') {
            $host = 'localhost';
        }

        return 'no-reply@' . $host;
    }

    // ------------------------------------------------------------------ 入口

    /**
     * 玩家刚提交完反馈：给玩家发回执，给管理员发提醒。
     *
     * @param array<string,mixed> $feedback
     */
    public static function onSubmitted(array $feedback): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $settings = self::settings();

            if (!empty($settings['notify_player'])) {
                $to = self::playerAddress($feedback);
                if ($to !== '') {
                    $progress = self::safeProgress($feedback);
                    self::enqueue(
                        (int) $feedback['id'],
                        self::KIND_PLAYER_RECEIPT,
                        $to,
                        '【' . (string) $feedback['ticket_no'] . '】已经收到你的反馈，正在检测',
                        self::receiptText($feedback, $progress),
                        self::ticketLink($feedback)
                    );
                }
            }

            if (!empty($settings['notify_admin'])) {
                $to = trim((string) $settings['admin_to']);
                if ($to !== '') {
                    self::enqueue(
                        (int) $feedback['id'],
                        self::KIND_ADMIN_NEW,
                        $to,
                        '[新反馈] ' . (string) $feedback['player_name'] . '：' . mb_substr((string) $feedback['subject'], 0, 60),
                        self::adminNewText($feedback),
                        ConsoleAuth::url('p=ticket&id=' . (int) $feedback['id'])
                    );
                }
            }
        } catch (\Throwable $e) {
            app_log('warn', '入队提交邮件失败', ['error' => $e->getMessage(), 'id' => $feedback['id'] ?? 0]);
        }
    }

    /**
     * 工单有结论了：把结果发给玩家，然后按设置清理邮箱。
     *
     * @param array<string,mixed> $feedback
     */
    public static function onSettled(array $feedback, string $reason = ''): void
    {
        try {
            if (self::enabled() && !empty(self::settings()['notify_player'])) {
                $to = self::playerAddress($feedback);
                if ($to !== '') {
                    $progress = self::safeProgress($feedback);
                    self::enqueue(
                        (int) $feedback['id'],
                        self::KIND_PLAYER_RESULT,
                        $to,
                        '【' . (string) $feedback['ticket_no'] . '】' . mb_substr((string) ($progress['headline'] ?? '处理结果'), 0, 60),
                        self::resultText($feedback, $progress, $reason),
                        self::ticketLink($feedback)
                    );
                }
            }
        } catch (\Throwable $e) {
            app_log('warn', '入队结果邮件失败', ['error' => $e->getMessage(), 'id' => $feedback['id'] ?? 0]);
        }

        // 无论有没有发信，工单结束就该清邮箱 —— 这是隐私设置，不该被"发信失败"牵连
        if (!empty(self::settings()['purge_email'])) {
            self::purgePlayerEmail((int) $feedback['id'], $reason !== '' ? $reason : '工单已结束');
        }
    }

    /**
     * 把玩家邮箱换成掩码。
     *
     * 保留 domain 是有用的（"这个月的问题大多来自 QQ 邮箱用户"这类判断），
     * 本地部分只留前两位，足够辨认"是不是同一个人"又拼不回完整地址。
     */
    public static function purgePlayerEmail(int $feedbackId, string $why = ''): bool
    {
        $row = Db::first('SELECT player_email, email_purged_at, contact FROM feedback WHERE id = :id', ['id' => $feedbackId]);
        if ($row === null) {
            return false;
        }
        if (!empty($row['email_purged_at'])) {
            return false; // 已经清过了
        }

        $original = trim((string) ($row['player_email'] ?? ''));
        $contact = trim((string) ($row['contact'] ?? ''));

        // contact 是自由文本（"QQ / 邮箱"），里面也可能写着邮箱，一并抹掉
        $contactClean = $contact !== '' ? self::maskEmailsInText($contact) : '';
        $contactChanged = $contactClean !== $contact;

        if ($original === '' && !$contactChanged) {
            // 本来就没有邮箱：记一个时间戳，免得每次结算都来查一遍
            Db::update('feedback', ['email_purged_at' => now()], ['id' => $feedbackId]);

            return false;
        }

        Db::update('feedback', [
            'player_email'    => $original !== '' ? self::maskAddress($original) : null,
            'contact'         => $contactChanged ? $contactClean : ($contact !== '' ? $contact : null),
            'email_purged_at' => now(),
            'updated_at'      => now(),
        ], ['id' => $feedbackId]);

        record_event($feedbackId, 'system', 'email.purged', sprintf(
            '按隐私设置清理了玩家邮箱%s',
            $why !== '' ? '（' . $why . '）' : ''
        ));

        return true;
    }

    // ------------------------------------------------------------------ 队列

    /**
     * 入队一封邮件。
     *
     * @param array<string,mixed> $feedback
     */
    public static function enqueue(
        int $feedbackId,
        string $kind,
        string $to,
        string $subject,
        array $bodies,
        string $link = ''
    ): int {
        $to = trim($to);
        if (!self::isEmail($to)) {
            app_log('warn', '邮件收件人不合法，已跳过', ['kind' => $kind, 'to' => self::maskAddress($to)]);
            return 0;
        }

        $html = self::renderHtml((string) $bodies['text'], $link);

        return Db::insert('email_jobs', [
            'feedback_id' => $feedbackId > 0 ? $feedbackId : null,
            'kind'        => $kind,
            'to_addr'     => $to,
            'to_masked'   => self::maskAddress($to),
            'subject'     => mb_substr($subject, 0, 200),
            'body_html'   => $html,
            'body_text'   => (string) $bodies['text'],
            'status'      => 'queued',
            'attempts'    => 0,
            'next_try_at' => gmdate('Y-m-d H:i:s', time() + self::FIRST_DELAY),
            'created_at'  => now(),
        ]);
    }

    /**
     * 把到期的邮件发出去。cron 每分钟调一次。
     *
     * @return array{sent:int,failed:int,scanned:int}
     */
    public static function drain(int $limit = 8): array
    {
        if (!self::enabled()) {
            return ['sent' => 0, 'failed' => 0, 'scanned' => 0];
        }

        $now = now();
        $jobs = Db::all(
            "SELECT * FROM email_jobs
             WHERE status = 'queued' AND (next_try_at IS NULL OR next_try_at <= :now)
             ORDER BY id ASC LIMIT " . max(1, min(50, $limit)),
            ['now' => $now]
        );

        $sent = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $result = self::deliver($job);
            $jobId = (int) $job['id'];

            if (!empty($result['ok'])) {
                $sent++;
                // 发出去之后就不再需要明文地址和正文了
                Db::update('email_jobs', [
                    'status'      => 'sent',
                    'to_addr'     => null,
                    'body_html'   => null,
                    'body_text'   => null,
                    'attempts'    => (int) $job['attempts'] + 1,
                    'last_error'  => null,
                    'sent_at'     => now(),
                    'cleaned_at'  => now(),
                ], ['id' => $jobId]);
                continue;
            }

            $attempts = (int) $job['attempts'] + 1;
            $maxAttempts = count(self::RETRY_BACKOFF) + 1;

            if ($attempts >= $maxAttempts) {
                $failed++;
                Db::update('email_jobs', [
                    'status'     => 'failed',
                    'to_addr'    => null,   // 不会再重试了，明文没必要留着
                    'body_html'  => null,
                    'body_text'  => null,
                    'attempts'   => $attempts,
                    'last_error' => mb_substr((string) ($result['error'] ?? '发送失败'), 0, 500),
                    'cleaned_at' => now(),
                ], ['id' => $jobId]);

                record_event(
                    $job['feedback_id'] !== null ? (int) $job['feedback_id'] : null,
                    'system',
                    'email.failed',
                    sprintf('邮件发送失败（%s → %s）：%s', (string) $job['kind'], (string) $job['to_masked'], (string) ($result['error'] ?? '')),
                    ['job_id' => $jobId],
                    'warn'
                );
                continue;
            }

            $delay = self::RETRY_BACKOFF[min($attempts - 1, count(self::RETRY_BACKOFF) - 1)];
            Db::update('email_jobs', [
                'attempts'    => $attempts,
                'last_error'  => mb_substr((string) ($result['error'] ?? '发送失败'), 0, 500),
                'next_try_at' => gmdate('Y-m-d H:i:s', time() + $delay),
            ], ['id' => $jobId]);
        }

        return ['sent' => $sent, 'failed' => $failed, 'scanned' => count($jobs)];
    }

    /**
     * 真正发一封。
     *
     * @param array<string,mixed> $job
     * @return array{ok:bool,error:string}
     */
    private static function deliver(array $job): array
    {
        $to = trim((string) ($job['to_addr'] ?? ''));
        if ($to === '' || !self::isEmail($to)) {
            return ['ok' => false, 'error' => '收件人已清空或不合法，放弃发送'];
        }

        $settings = self::settings();

        $result = Mail::send(
            [$to],
            (string) $job['subject'],
            (string) $job['body_html'],
            (string) $job['body_text'],
            ['from' => self::fromAddress(), 'from_name' => (string) $settings['from_name']]
        );

        if (empty($result['ok'])) {
            $transport = (string) ($result['transport'] ?? 'mail');

            return ['ok' => false, 'error' => (string) ($result['error'] ?? '发送失败') . '（通路：' . $transport . '）'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * 发一封测试邮件（后台按钮用），走的是**同步**发送 —— 管理员点了就要立刻看到结果。
     *
     * @return array{ok:bool,message:string}
     */
    public static function sendTest(string $to): array
    {
        $to = trim($to);
        if (!self::isEmail($to)) {
            return ['ok' => false, 'message' => '邮箱格式不对：' . $to];
        }

        $settings = self::settings();
        $smtp = Mail::smtpSettings();
        $viaSmtp = \MCFix\Smtp::configured($smtp);

        $text = implode("\n", [
            '这是 MCFix 的一封测试邮件。',
            '',
            '你能看到它，说明邮件通路是通的。',
            '注意：投递出去不等于对方一定收得到 —— QQ / 163 可能把它判进垃圾箱。',
            '',
            '当前通路：' . ($viaSmtp
                ? 'SMTP（' . (string) $smtp['host'] . ':' . (int) $smtp['port'] . '）'
                : 'PHP mail()（本机 MTA，VPS 上通常走不通）'),
            '发件地址：' . self::fromAddress(),
            '站点：' . (string) Config::get('app.base_url', ''),
            '时间：' . now(),
        ]);

        $result = Mail::send(
            [$to],
            'MCFix 测试邮件',
            self::renderHtml($text, ConsoleAuth::url()),
            $text,
            ['from' => self::fromAddress(), 'from_name' => (string) $settings['from_name']]
        );

        if (empty($result['ok'])) {
            return [
                'ok'      => false,
                'message' => '发送失败（' . strtoupper((string) ($result['transport'] ?? 'mail')) . '）：'
                    . (string) ($result['error'] ?? '未知错误'),
            ];
        }

        return [
            'ok'      => true,
            'message' => '已通过 ' . strtoupper((string) ($result['transport'] ?? 'mail')) . ' 投递给 ' . $to
                . '。没收到的话先翻一下垃圾邮件箱。',
        ];
    }

    /**
     * 队列状态（后台展示用）。
     *
     * @return array{queued:int,sent:int,failed:int,last_error:string}
     */
    public static function stats(): array
    {
        $lastError = '';
        try {
            $lastError = (string) Db::scalar(
                "SELECT last_error FROM email_jobs WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 1"
            );
        } catch (\Throwable $e) {
            // 表还没建好时忽略
        }

        return [
            'queued'     => (int) Db::scalar("SELECT COUNT(*) FROM email_jobs WHERE status = 'queued'"),
            'sent'       => (int) Db::scalar("SELECT COUNT(*) FROM email_jobs WHERE status = 'sent'"),
            'failed'     => (int) Db::scalar("SELECT COUNT(*) FROM email_jobs WHERE status = 'failed'"),
            'last_error' => $lastError,
        ];
    }

    /**
     * 最近几封（后台展示用）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        return Db::all(
            'SELECT id, feedback_id, kind, to_masked, subject, status, attempts, last_error, created_at, sent_at
             FROM email_jobs ORDER BY id DESC LIMIT ' . max(1, min(50, $limit))
        );
    }

    // ------------------------------------------------------------------ 地址处理

    public static function isEmail(string $value): bool
    {
        return (bool) filter_var(trim($value), FILTER_VALIDATE_EMAIL);
    }

    /**
     * abcd@qq.com → ab***@qq.com
     */
    public static function maskAddress(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        if (mb_strlen($local) <= 2) {
            return mb_substr($local, 0, 1) . '***' . $domain;
        }

        return mb_substr($local, 0, 2) . '***' . $domain;
    }

    /**
     * 把一段自由文本里的邮箱都换成掩码（contact 字段可能是"QQ / 邮箱"混写）。
     */
    public static function maskEmailsInText(string $text): string
    {
        $result = preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            static function (array $m): string {
                return self::maskAddress($m[0]);
            },
            $text
        );

        return is_string($result) ? $result : $text;
    }

    /**
     * 取工单上的玩家邮箱；顺便兼容"只填了 contact、里面写着邮箱"的老工单。
     *
     * @param array<string,mixed> $feedback
     */
    private static function playerAddress(array $feedback): string
    {
        $direct = trim((string) ($feedback['player_email'] ?? ''));
        if ($direct !== '' && self::isEmail($direct)) {
            return $direct;
        }

        // 掩码过的地址不能再发信
        if (strpos($direct, '***') !== false) {
            return '';
        }

        $contact = trim((string) ($feedback['contact'] ?? ''));
        if ($contact !== '' && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $contact, $m)) {
            return $m[0];
        }

        return '';
    }

    // ------------------------------------------------------------------ 正文

    /**
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>
     */
    private static function safeProgress(array $feedback): array
    {
        try {
            return Workflow::progress($feedback);
        } catch (\Throwable $e) {
            app_log('warn', '生成邮件正文时取进度失败', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 工单的对外链接（玩家点开就能看进度）。
     *
     * @param array<string,mixed> $feedback
     */
    private static function ticketLink(array $feedback): string
    {
        try {
            return Token::feedbackUrl($feedback);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $progress
     * @return array{text:string}
     */
    private static function receiptText(array $feedback, array $progress): array
    {
        $server = (string) ($feedback['server_id'] ?? '');
        $serverName = ticket_server_name($server);

        $lines = [
            '你好 ' . (string) $feedback['player_name'] . '，',
            '',
            '你的问题已经收到了，工单号 **' . (string) $feedback['ticket_no'] . '**。',
            '服务器：' . $serverName,
            '你说的：' . mb_substr((string) $feedback['subject'], 0, 120),
            '',
            '接下来系统会真的连一次服务器做验证（大约 5~20 秒），不是为了安慰你而回这句话。',
            '能自动修的（白名单、误封、卡崩、插件报错）它会当场处理，处理完再复验一次，',
            '然后把结果再发一封邮件给你。',
            '',
            '如果你不想等邮件，点下面的按钮随时看实时进度。',
        ];

        $lines[] = '';
        $lines[] = '_本邮件由系统自动发出，回复它没人看得到。_';

        return ['text' => implode("\n", $lines)];
    }

    /**
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $progress
     * @return array{text:string}
     */
    private static function resultText(array $feedback, array $progress, string $reason): array
    {
        $headline = trim((string) ($progress['headline'] ?? ''));
        $problem = (string) ($progress['problem']['title'] ?? '');

        // progress() 在没有 verdict 时会回一句"正在验证中……"。
        // 这封邮件的主题是"有结果了"，再写"正在验证中"就很怪 —— 按状态换个说法。
        if ($headline === '' || strpos($headline, '正在验证') !== false) {
            $fallback = [
                'resolved' => '问题已经处理完成 ✅',
                'closed'   => '工单已关闭',
                'rejected' => '管理员看过之后判定不需要处理',
                'manual'   => '需要管理员人工看一下，已进入处理队列',
                'unresolved' => '这轮没能自动修好，已转人工处理',
            ];
            $headline = $fallback[(string) ($feedback['status'] ?? '')] ?? '这条工单有结论了';
        }

        $lines = [
            '你好 ' . (string) $feedback['player_name'] . '，',
            '',
            '工单 **' . (string) $feedback['ticket_no'] . '** 有结果了。',
            '',
            '**' . $headline . '**',
        ];

        if ($problem !== '') {
            $lines[] = '系统验证到的原因：' . $problem;
        }

        // 体检明细：只挑有内容的几行，邮件里别堆一大坨
        $checks = (array) ($progress['checks'] ?? []);
        if ($checks) {
            $lines[] = '';
            $lines[] = '体检结果：';
            $marks = ['pass' => '✓', 'warn' => '!', 'fail' => '✗'];
            foreach (array_slice($checks, 0, 8) as $check) {
                $status = (string) ($check['status'] ?? '');
                $lines[] = '- [' . ($marks[$status] ?? '-') . '] '
                    . (string) ($check['label'] ?? '') . '：' . (string) ($check['text'] ?? '');
            }
        }

        // 需要玩家自己在客户端做的事（换 Java、补 MOD、改设置之类）
        $steps = [];
        foreach ((array) ($progress['client']['issues'] ?? []) as $issue) {
            foreach (array_slice((array) ($issue['steps'] ?? []), 0, 4) as $step) {
                if (is_string($step) && trim($step) !== '') {
                    $steps[] = trim($step);
                }
            }
            if (count($steps) >= 8) {
                break;
            }
        }
        // 缺的 MOD：告诉他可以直接下载
        foreach ((array) ($progress['client']['components'] ?? []) as $component) {
            if (!empty($component['available']) && !empty($component['download'])) {
                $steps[] = '下载缺少的 ' . (string) $component['name'] . '（点邮件里的按钮进工单页面）';
            }
            if (count($steps) >= 10) {
                break;
            }
        }
        if ($steps) {
            $lines[] = '';
            $lines[] = '**你需要做的：**';
            foreach (array_slice($steps, 0, 10) as $step) {
                $lines[] = '- ' . $step;
            }
        }

        if ($reason !== '') {
            $lines[] = '';
            $lines[] = '备注：' . $reason;
        }

        $lines[] = '';
        $lines[] = '_系统不再保留你的完整邮箱地址，只留一个掩码用于统计。_';

        return ['text' => implode("\n", $lines)];
    }

    /**
     * @param array<string,mixed> $feedback
     * @return array{text:string}
     */
    private static function adminNewText(array $feedback): array
    {
        $serverId = (string) ($feedback['server_id'] ?? '');
        $serverName = ticket_server_name($serverId);
        $categoryDef = Catalog::category((string) ($feedback['category'] ?? '')) ?? [];

        $lines = [
            '有新反馈进来了。',
            '',
            '服务器：' . $serverName,
            '玩家：' . (string) $feedback['player_name'],
            '分类：' . (string) ($categoryDef['label'] ?? $feedback['category'] ?? ''),
            '工单号：' . (string) $feedback['ticket_no'],
            '提交时间：' . (string) $feedback['created_at'],
            '',
            '**玩家说的：**',
            '> ' . mb_substr((string) $feedback['message'], 0, 600),
        ];

        if (!empty($feedback['client_log_size'])) {
            $lines[] = '';
            $lines[] = '（附带了客户端日志，' . human_size((float) $feedback['client_log_size']) . '，分析结果在后台工单详情里）';
        }

        $lines[] = '';
        $lines[] = '后台工单：' . ConsoleAuth::url('p=ticket&id=' . (int) $feedback['id']);

        return ['text' => implode("\n", $lines)];
    }

    /**
     * 极简 HTML 版本（复用通知渠道那套排版思路，但这里是独立实现，避免两边耦合）。
     */
    private static function renderHtml(string $text, string $link): string
    {
        $html = [];
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $html[] = '<div style="height:8px"></div>';
                continue;
            }

            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;

            if (strpos($line, '> ') === 0) {
                $html[] = '<blockquote style="margin:6px 0;padding:8px 12px;border-left:3px solid #4ade80;background:#f7fdf9;color:#334">'
                    . preg_replace('/^&gt;\s?/', '', $escaped) . '</blockquote>';
                continue;
            }
            if (preg_match('/^[-*]\s+/', $line)) {
                $html[] = '<div style="margin:3px 0 3px 12px">• ' . preg_replace('/^[-*]\s+/', '', $escaped) . '</div>';
                continue;
            }
            // 注意这里匹配的是 $escaped 而不是原始行。
            // 用原始行的话，$m[1] 里就是玩家没转义过的原文，会直接被拼进 HTML 邮件
            // —— 玩家在描述里写一行 _<a href="钓鱼链接">…</a>_ 就能在管理员收到的
            // 邮件里渲染出任意 HTML。旁边的 MailChannel 一直用的是转义后的值，
            // 这里以前漏了。
            if (preg_match('/^_(.+)_$/', $escaped, $m)) {
                $html[] = '<div style="color:#8a94a6;font-size:12px;margin-top:10px">' . $m[1] . '</div>';
                continue;
            }
            // 裸链接（进度链接那一行）
            if (preg_match('#^https?://\S+$#', $line)) {
                $html[] = '<div style="margin:4px 0"><a href="' . $escaped . '" style="color:#0ea5e9">' . $escaped . '</a></div>';
                continue;
            }

            $html[] = '<div style="margin:4px 0">' . $escaped . '</div>';
        }

        $button = $link !== ''
            ? '<p style="margin:18px 0 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
                . '" style="display:inline-block;padding:9px 18px;background:#22c55e;color:#fff;border-radius:8px;text-decoration:none">查看详情</a></p>'
            : '';

        $site = (string) Config::get('app.name', 'MC 故障反馈系统');

        /*
         * 邮件 Logo：管理员在后台传过才显示，走 Content-ID 内嵌（不是远程地址），
         * 所以收件人不用点"显示图片"就能看到。见 Mail::buildMessage 的说明。
         */
        $logo = \MCFix\Brand::get('logo');
        $logoHtml = '';
        if ($logo !== null) {
            $logoHtml = '<div style="text-align:center;margin:0 0 16px">'
                . '<img src="cid:' . \MCFix\Mail::LOGO_CID . '" alt="" '
                . 'style="max-width:180px;max-height:64px;height:auto" '
                . 'width="' . (int) $logo['width'] . '" height="' . (int) $logo['height'] . '">'
                . '</div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:22px;background:#f6f8fa;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#1f2933">'
            . '<div style="max-width:620px;margin:0 auto;background:#fff;border-radius:12px;padding:22px 24px;box-shadow:0 2px 12px rgba(16,24,40,.06)">'
            . $logoHtml
            . implode("\n", $html)
            . $button
            . '</div>'
            . '<p style="text-align:center;color:#98a2b3;font-size:12px;margin:14px 0 0">' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</body></html>';
    }
}
