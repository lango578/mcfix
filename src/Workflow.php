<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 业务编排：把"玩家一句话"变成"已验证 + 已修复 + 已复验"的闭环。
 *
 *   submit()      玩家提交反馈（生成签名链接与诊断授权令牌）
 *   authorize()   校验链接令牌 + 工单 nonce
 *   verify()      执行诊断（自动验证），必要时自动触发修复
 *   applyFix()    执行修复配方（白名单 + 风控 + 审批闸门）
 *   reverify()    修复后回归验证，决定"已解决"还是"转人工"
 *   progress()    给玩家看的状态（严格脱敏）
 *   adminAction() 管理员的动作（批准 / 驳回 / 手动修 / 关闭）
 */
final class Workflow
{
    public const MAX_LISTED_EVENTS = 60;

    /**
     * 玩家提交反馈。
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $files $_FILES（可选，支持上传崩溃报告/日志文件）
     * @return array<string,mixed>
     */
    public static function submit(array $input, array $files = []): array
    {
        // 允许**不绑定服务器**：客户端崩溃（模组冲突、Java 版本、内存不足）
        // 和服务端没有关系，强制选一台服务器是白添的门槛 ——
        // 而且一台服务器都没配时，整张反馈表都用不了，连纯客户端问题都提交不上。
        //
        // 约定：server_id 为空字符串 = 纯客户端工单。
        // 后面 verify() 会跳过服务端诊断，只做日志分析。
        $serverId = trim((string) ($input['server_id'] ?? ''));
        $player = trim((string) ($input['player_name'] ?? ''));
        $server = $serverId !== '' ? Config::server($serverId) : [];

        if ($server === null) {
            return ['ok' => false, 'error' => '选择的服务器不存在，请刷新页面重试'];
        }

        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,16}$/u', $player)) {
            return ['ok' => false, 'error' => '游戏 ID 格式不正确（2-16 位字母、数字、下划线或中文）'];
        }

        $message = trim((string) ($input['message'] ?? ''));
        if (mb_strlen($message) < 4) {
            return ['ok' => false, 'error' => '请把遇到的问题描述得再具体一点（至少 4 个字）'];
        }
        $message = mb_substr($message, 0, 2000);

        // 客户端日志：可以粘贴文本，也可以上传文件（crash-report / latest.log）
        $collected = ClientLog::collect($input, $files);
        if (!$collected['ok'] && $collected['error'] !== '') {
            return ['ok' => false, 'error' => '日志读取失败：' . $collected['error']];
        }
        $hasLog = $collected['ok'] && trim($collected['text']) !== '';

        $category = (string) ($input['category'] ?? '');
        $autoGuess = $category === '' || $category === 'auto';
        if ($autoGuess) {
            $category = Catalog::guess($player . ' ' . $message);
        }
        $categoryDef = Catalog::category($category);
        if ($categoryDef === null) {
            $category = 'other';
            $categoryDef = Catalog::category('other');
        }

        // 带了日志就当客户端问题处理，除非玩家明确选了别的分类
        if ($hasLog && $autoGuess) {
            $category = 'client_problem';
            $categoryDef = Catalog::category('client_problem') ?? $categoryDef;
        }

        // 标题会进邮件头。这里在**源头**就把换行清掉 —— Mail::encodeHeader()
        // 里还有一道，但源头干净一些，也免得它出现在别的地方（事件、日志）。
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($input['subject'] ?? '')));
        if ($subject === '') {
            $subject = mb_substr($message, 0, 40);
        }

        // 玩家邮箱（选填）。填了才能收到回执和结果，所以格式不对要当场告诉他，
        // 而不是默默丢掉 —— 不然他会一直等一封永远不来的邮件。
        $playerEmail = trim((string) ($input['player_email'] ?? ''));
        if ($playerEmail !== '' && !TicketMail::isEmail($playerEmail)) {
            return ['ok' => false, 'error' => '邮箱格式看起来不对（也可以留空，不影响提交）'];
        }

        $now = now();
        $ticketNo = self::generateTicketNo($serverId);
        $nonce = bin2hex(random_bytes(8));

        $id = Db::insert('feedback', [
            'ticket_no'    => $ticketNo,
            'server_id'    => $serverId,
            'player_name'  => $player,
            'player_uuid'  => (string) ($input['player_uuid'] ?? '') ?: null,
            'contact'      => mb_substr((string) ($input['contact'] ?? ''), 0, 128) ?: null,
            'player_email' => $playerEmail !== '' ? mb_substr($playerEmail, 0, 200) : null,
            'category'     => $category,
            'raw_category' => $autoGuess ? 'auto' : $category,
            'subject'      => mb_substr($subject, 0, 200),
            'message'      => $message,
            'status'       => 'submitted',
            'severity'     => (string) ($categoryDef['default_severity'] ?? 'normal'),
            'ip_hash'      => client_ip_hash(),
            'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'token_nonce'  => $nonce,
            'client_log'   => $hasLog ? mb_substr($collected['text'], 0, ClientLog::MAX_BYTES) : null,
            'client_log_name'   => $hasLog ? ($collected['filename'] !== '' ? $collected['filename'] : null) : null,
            'client_log_source' => $hasLog ? $collected['source'] : null,
            'client_log_size'   => $hasLog ? strlen($collected['text']) : 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        record_event($id, 'player', 'feedback.submit', '玩家 ' . $player . ' 提交反馈：' . $subject, [
            'category'  => $category,
            'auto_guess'=> $autoGuess,
            'client_log'=> $hasLog ? [
                'source' => $collected['source'],
                'name'   => $collected['filename'],
                'size'   => strlen($collected['text']),
            ] : null,
        ]);

        if ($hasLog) {
            record_event($id, 'player', 'client.log_received', sprintf(
                '收到客户端日志（%s，%s）',
                $collected['source'] === 'file' ? ('文件 ' . $collected['filename']) : '粘贴文本',
                human_size((float) strlen($collected['text']))
            ));
        }

        // 通知（失败不影响主流程）
        try {
            Notifier::dispatch('feedback.new', [
                'feedback_id'    => $id,
                'ticket_no'      => $ticketNo,
                'server_id'      => $serverId,
                'player_name'    => $player,
                'category_label' => (string) ($categoryDef['label'] ?? ''),
                'problem'        => mb_substr($subject, 0, 120),
                'body'           => mb_substr($message, 0, 300),
                'has_client_log' => $hasLog,
            ], 'feedback.new:' . $id);
        } catch (\Throwable $e) {
            app_log('warn', '发送新反馈通知失败', ['error' => $e->getMessage()]);
        }

        $feedback = self::find($id);
        if ($feedback === null) {
            return ['ok' => false, 'error' => '写入工单失败，请稍后重试'];
        }

        // 玩家回执 + 管理员提醒（只入队，真正的发送交给 cron；失败也不影响提交）
        TicketMail::onSubmitted($feedback);

        return [
            'ok'          => true,
            'id'          => $id,
            'ticket_no'   => $ticketNo,
            'category'    => $category,
            'category_label' => (string) $categoryDef['label'],
            'share_url'   => Token::feedbackUrl($feedback),
            'token'       => Token::forFeedback($feedback),
            'verify_token'=> Token::forVerify($feedback),
            'expires_in'  => (int) Config::get('feedback.token_ttl', 86400),
            'has_client_log' => $hasLog,
            'email_sent'  => $playerEmail !== '' && TicketMail::enabled(),
            'next'        => 'diagnose',
        ];
    }

    /**
     * 校验反馈链接令牌，并确认工单还存在、nonce 未被轮换。
     *
     * @return array{ok:bool,error:string,feedback:array<string,mixed>|null}
     */
    public static function authorize(string $token): array
    {
        $parsed = Token::parse($token, 'f');
        if ($parsed === null) {
            return ['ok' => false, 'error' => '链接已失效或签名不正确，请让管理员重新生成反馈链接', 'feedback' => null];
        }

        // 服务器级公开链接（nonce = share）：不绑定工单，这里不做工单校验
        if ((string) $parsed['nonce'] === 'share') {
            $server = Config::server((string) $parsed['server_id']);
            if ($server === null) {
                return ['ok' => false, 'error' => '这条链接对应的服务器已被移除', 'feedback' => null];
            }

            return ['ok' => false, 'error' => '', 'feedback' => null, 'share' => $server];
        }

        $feedback = self::find((int) $parsed['id']);
        if ($feedback === null) {
            return ['ok' => false, 'error' => '这条反馈记录已经不存在了', 'feedback' => null];
        }

        $nonce = (string) ($feedback['token_nonce'] ?? '');
        if ($nonce !== '' && !hash_equals($nonce, (string) $parsed['nonce'])) {
            return ['ok' => false, 'error' => '这条链接已被管理员作废，请使用最新链接', 'feedback' => null];
        }

        if ((string) $feedback['server_id'] !== (string) $parsed['server_id']) {
            return ['ok' => false, 'error' => '链接与工单不匹配', 'feedback' => null];
        }

        return ['ok' => true, 'error' => '', 'feedback' => $feedback];
    }

    /**
     * 自动验证（诊断）。可以重复调用：同一工单每次调用都会刷新诊断结果。
     *
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>
     */
    public static function verify(array $feedback, bool $allowRemote = true, bool $autoFix = true): array
    {
        $id = (int) $feedback['id'];
        $lock = self::lock($id);
        if ($lock === false) {
            return ['ok' => false, 'error' => '这条工单正在处理中，请稍等几秒'];
        }

        try {
            Db::update('feedback', [
                'status'     => 'diagnosing',
                'updated_at' => now(),
            ], ['id' => $id]);

            record_event($id, 'system', 'diag.start', '开始自动验证', ['remote' => $allowRemote]);

            // 没有绑定服务器的工单（纯客户端报错）：跳过服务端诊断。
            //
            // 拿一个空的 server_id 去跑 Diagnosis::run() 只会得到
            // "服务器不存在或已在配置中移除"，那是一句对玩家毫无意义的话。
            // 这种工单真正要做的是客户端日志分析（下面那一段），
            // 而且**绝不能**产生任何服务端修复动作 —— 根本没有服务器可修。
            if (trim((string) ($feedback['server_id'] ?? '')) === '') {
                $diagnosis = [
                    'at'      => now(),
                    'ok'      => true,
                    'elapsed' => 0,
                    'checks'  => [],
                    'verdict' => [
                        'issue'             => 'client_only',
                        'severity'          => 'normal',
                        'title'             => '客户端报错（未绑定服务器）',
                        'detail'            => '这条反馈没有指定服务器，只做客户端日志分析。',
                        'source'            => 'summary',
                        'issues'            => [],
                        'suggestions'       => [],
                        'auto_fixable'      => false,
                        'requires_approval' => false,
                        'needs_manual'      => false,
                        'auto_fix_enabled'  => false,
                        'player_message'    => '收到你的日志了，正在分析。',
                        'operator_hint'     => '玩家没有指定服务器，只需要看客户端日志。',
                        'evaluated_at'      => now(),
                    ],
                    'server'  => ['id' => '', 'name' => '', 'host' => '', 'port' => 0],
                    'context' => [],
                ];
                $verdict = (array) $diagnosis['verdict'];
            } else {
                $diagnosis = Diagnosis::run($feedback, $allowRemote);
                $verdict = (array) ($diagnosis['verdict'] ?? []);
            }

            // ---- 客户端日志分析（如果玩家发了日志） ----
            $server = Config::server((string) $feedback['server_id']) ?? [];
            $clientAnalysis = self::analyzeClientLog($feedback, $server);

            if ($clientAnalysis !== null) {
                $diagnosis['client'] = $clientAnalysis;
                $verdict = self::mergeClientIntoVerdict($verdict, $clientAnalysis);
            }

            $fixPlan = [
                'issue'       => (string) ($verdict['issue'] ?? 'unknown'),
                'title'       => (string) ($verdict['title'] ?? ''),
                'severity'    => (string) ($verdict['severity'] ?? 'normal'),
                'suggestions' => (array) ($verdict['suggestions'] ?? []),
                'auto_fixable'=> !empty($verdict['auto_fixable']),
                'attempts'    => (int) $feedback['fix_attempts'],
                'max_attempts'=> (int) Config::get('automation.max_fix_attempts', 2),
            ];

            $status = !empty($verdict['needs_manual'])
                ? 'manual'
                : (!empty($verdict['auto_fixable']) ? 'diagnosed' : 'unresolved');

            Db::update('feedback', [
                'status'        => $status,
                'severity'      => (string) ($verdict['severity'] ?? 'normal'),
                'auto_fixable'  => !empty($verdict['auto_fixable']) ? 1 : 0,
                'diagnosis'     => json_encode($diagnosis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'verdict'       => json_encode($verdict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'fix_plan'      => json_encode($fixPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_analysis' => $clientAnalysis !== null
                    ? json_encode($clientAnalysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'client_issue_code' => $clientAnalysis !== null ? (string) ($clientAnalysis['primary_code'] ?? '') : null,
                'cross_check'   => $clientAnalysis !== null
                    ? json_encode((array) ($clientAnalysis['cross_check'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'updated_at'    => now(),
            ], ['id' => $id]);

            record_event($id, 'system', 'diag.done', '验证完成：' . (string) ($verdict['title'] ?? ''), [
                'issue'          => $verdict['issue'] ?? '',
                'severity'       => $verdict['severity'] ?? '',
                'auto_fixable'   => $verdict['auto_fixable'] ?? false,
                'requires_approval' => $verdict['requires_approval'] ?? false,
                'client_issue'   => $clientAnalysis['primary_code'] ?? null,
            ], !empty($verdict['auto_fixable']) ? 'info' : 'warn');

            $result = [
                'ok'        => true,
                'status'    => $status,
                'diagnosis' => $diagnosis,
                'verdict'   => $verdict,
                'client'    => $clientAnalysis,
                'fix'       => null,
            ];

            // 自动修复
            $suggestions = (array) ($verdict['suggestions'] ?? []);

            if ($autoFix && !empty($verdict['auto_fixable']) && $suggestions) {
                // 低风险动作：直接执行，玩家页面当场看到结果
                $result['fix'] = self::applyFix($feedback, (array) $suggestions[0]);
                $result['status'] = (string) (self::find($id)['status'] ?? $status);
            } elseif ($autoFix && !empty($verdict['requires_approval']) && $suggestions) {
                // 高风险动作：先入队，等管理员点"批准执行"；玩家看到"已提交管理员确认"
                $result['fix'] = self::applyFix($feedback, (array) $suggestions[0], true);
                $result['status'] = (string) (self::find($id)['status'] ?? $status);
            }

            // ---- 通知：修复完成 / 需要批准 / 转人工 / 纯客户端问题 ----
            self::notifyAfterVerify($feedback, $verdict, $clientAnalysis, $result);

            return $result;
        } finally {
            self::unlock($id);
        }
    }

    /**
     * 诊断结束后按情况发通知。
     *
     * 通知失败绝不影响验证流程，所以整体包在 try/catch 里。
     *
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $verdict
     * @param array<string,mixed>|null $client
     * @param array<string,mixed> $result
     */
    private static function notifyAfterVerify(array $feedback, array $verdict, ?array $client, array $result): void
    {
        try {
            $id = (int) $feedback['id'];
            $base = [
                'feedback_id'    => $id,
                'ticket_no'      => (string) $feedback['ticket_no'],
                'server_id'      => (string) $feedback['server_id'],
                'player_name'    => (string) $feedback['player_name'],
                'category_label' => (string) (Catalog::category((string) $feedback['category'])['label'] ?? ''),
                'problem'        => (string) ($verdict['title'] ?? ''),
                'headline'       => (string) ($verdict['player_message'] ?? ''),
            ];

            $fix = (array) ($result['fix'] ?? []);
            $fixed = !empty($fix['ok']);
            $awaiting = (string) ($fix['status'] ?? '') === 'awaiting_approval';
            $status = (string) ($result['status'] ?? '');

            // 客户端结论摘要
            $clientSummary = [];
            if ($client !== null) {
                $base['client_issue'] = (string) ($client['headline'] ?? '');
                foreach (array_slice((array) ($client['needs']['components'] ?? []), 0, 6) as $component) {
                    $clientSummary[] = (string) ($component['name'] ?? '');
                }
                $base['needs'] = $clientSummary;
            }

            if ($awaiting) {
                $base['action'] = (string) (Recipe::get((string) ($fix['recipe'] ?? ''))['label'] ?? '高风险修复动作');
                $base['result'] = '已入队，等待管理员在后台点「批准执行」';
                Notifier::dispatch('need_approval', $base, 'need_approval:' . $id . ':' . (string) ($fix['task_id'] ?? ''));

                return;
            }

            if ($fixed) {
                $base['action'] = (string) (Recipe::get((string) ($fix['recipe'] ?? ''))['label'] ?? '自动修复');
                $base['result'] = trim((string) ($fix['response']['output'] ?? '')) !== ''
                    ? mb_substr((string) $fix['response']['output'], 0, 200)
                    : '执行成功';
                $base['checks'] = self::briefChecks($verdict);
                Notifier::dispatch('auto_fixed', $base, 'auto_fixed:' . $id);

                return;
            }

            if ($status === 'unresolved' && !empty($fix) && empty($fix['ok'])) {
                $base['error'] = (string) ($fix['error'] ?? '执行失败');
                $base['action'] = (string) (Recipe::get((string) ($fix['recipe'] ?? ''))['label'] ?? '自动修复');
                Notifier::dispatch('fix.failed', $base, 'fix.failed:' . $id);

                return;
            }

            // 需要人工介入的问题
            if (in_array($status, ['manual', 'unresolved'], true)) {
                $base['result'] = (string) ($verdict['operator_hint'] ?? '需要人工判断');
                $base['checks'] = self::briefChecks($verdict);
                Notifier::dispatch('need_manual', $base, 'need_manual:' . $id);

                return;
            }

            // 有客户端结论、但服务端没有可执行动作 → 纯客户端问题
            if ($client !== null && empty($verdict['suggestions'])) {
                Notifier::dispatch('client.problem', $base, 'client.problem:' . $id);
            }
        } catch (\Throwable $e) {
            app_log('warn', '发送验证后通知失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 从 verdict 里抽几条最关键的检查项，给通知用。
     *
     * @param array<string,mixed> $verdict
     * @return array<int,array<string,string>>
     */
    private static function briefChecks(array $verdict): array
    {
        $out = [];
        foreach ((array) ($verdict['client']['issues'] ?? []) as $issue) {
            $out[] = [
                'status' => (string) ($issue['severity'] ?? '') === 'critical' ? 'fail' : 'warn',
                'label'  => (string) ($issue['title'] ?? ''),
                'text'   => mb_substr((string) ($issue['detail'] ?? $issue['cause'] ?? ''), 0, 90),
            ];
            if (count($out) >= 4) {
                return $out;
            }
        }

        foreach ((array) ($verdict['issues'] ?? []) as $issue) {
            $out[] = [
                'status' => (string) ($issue['severity'] ?? '') === 'critical' ? 'fail' : 'warn',
                'label'  => (string) ($issue['title'] ?? ''),
                'text'   => mb_substr((string) ($issue['detail'] ?? ''), 0, 90),
            ];
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * 分析工单里的客户端日志。
     *
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $server
     * @return array<string,mixed>|null 没有日志时返回 null
     */
    public static function analyzeClientLog(array $feedback, array $server = []): ?array
    {
        $raw = (string) ($feedback['client_log'] ?? '');
        if (trim($raw) === '') {
            return null;
        }

        $hints = [
            'client_version' => (string) ($feedback['client_version'] ?? ''),
            'mod_loader'     => (string) ($feedback['client_loader'] ?? ''),
            'launcher'       => (string) ($feedback['client_launcher'] ?? ''),
        ];

        $parsed = ClientLog::parse($raw, $hints);
        if (!$server) {
            $server = Config::server((string) $feedback['server_id']) ?? [];
        }

        $analysis = ClientAdvisor::evaluate($server, $parsed);
        $analysis['parsed'] = $parsed;
        $analysis['public'] = ClientLog::publicFacts($parsed);

        // 把识别到的问题写进 client_issues，后台可以按问题类型聚合
        $feedbackId = (int) ($feedback['id'] ?? 0);
        if ($feedbackId > 0) {
            Db::delete('client_issues', ['feedback_id' => $feedbackId]);
            foreach ((array) $analysis['issues'] as $issue) {
                Db::insert('client_issues', [
                    'feedback_id' => $feedbackId,
                    'server_id'   => (string) ($feedback['server_id'] ?? ''),
                    'player_name' => (string) ($feedback['player_name'] ?? ''),
                    'code'        => (string) $issue['code'],
                    'severity'    => (string) $issue['severity'],
                    'source'      => (string) ($issue['source'] ?? 'client'),
                    'detail'      => mb_substr((string) ($issue['detail'] ?? ''), 0, 500),
                    'extra'       => json_encode((array) ($issue['extra'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'mods'        => json_encode(array_slice((array) ($analysis['suspects'] ?? []), 0, 15), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'resolved'    => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        return $analysis;
    }

    /**
     * 把客户端分析结论合并进服务端 verdict。
     *
     * 规则：
     *   - 服务端本身是好的，但客户端日志指向服务端问题（白名单/封禁/缺 MOD/插件崩）→ 采用客户端结论，并接上服务端修复配方
     *   - 服务端真的挂了（server_offline 等）→ 保持服务端结论优先，客户端只是补充说明
     *   - 纯客户端问题（Java 版本、内存、显卡）→ 只补充说明，不改变服务端结论
     *
     * @param array<string,mixed> $verdict
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    /**
     * 把客户端结论并进服务端判定。
     *
     * 公开是为了让测试能直接验证一条安全属性：
     * **大模型给出的建议永远进不了 suggestions**（它只能写文字，不能指挥系统）。
     *
     * @param array<string,mixed> $verdict
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public static function mergeClientIntoVerdict(array $verdict, array $client): array
    {
        $verdict['client'] = [
            'headline'     => (string) ($client['headline'] ?? ''),
            'severity'     => (string) ($client['severity'] ?? 'normal'),
            'primary_code' => (string) ($client['primary_code'] ?? ''),
            'issues'       => array_map(static function (array $issue): array {
                return [
                    'code'     => (string) $issue['code'],
                    'title'    => (string) $issue['title'],
                    'severity' => (string) $issue['severity'],
                    'cause'    => (string) $issue['cause'],
                    'steps'    => array_values((array) $issue['steps']),
                    'detail'   => (string) ($issue['detail'] ?? ''),
                    'extra'    => array_values((array) ($issue['extra'] ?? [])),
                    'server_fix' => (array) ($issue['server_fix'] ?? []),
                ];
            }, (array) ($client['issues'] ?? [])),
            'facts'        => (array) ($client['facts'] ?? []),
            'needs'        => (array) ($client['needs'] ?? []),
            'suspects'     => (array) ($client['suspects'] ?? []),
            'cross_check'  => (array) ($client['cross_check'] ?? []),
        ];

        $serverIssues = ['server_offline', 'process_missing', 'port_not_listening', 'disk_full'];
        $serverBroken = in_array((string) ($verdict['issue'] ?? ''), $serverIssues, true);

        if ($serverBroken) {
            $verdict['player_message'] = '服务器当前有故障（' . (string) ($verdict['title'] ?? '') . '），'
                . '你发来的客户端日志显示：' . (string) ($client['headline'] ?? '') . ' 已一起转给管理员。';

            return $verdict;
        }

        $primary = (array) (($client['issues'][0] ?? []));
        if (!$primary) {
            return $verdict;
        }

        // 客户端结论优先：它能解释玩家为什么进不去
        $verdict['issue'] = (string) $primary['code'];
        $verdict['title'] = (string) $primary['title'];
        $verdict['detail'] = (string) $primary['cause'];
        $verdict['severity'] = (string) $primary['severity'];
        $verdict['source'] = 'client_log';
        $verdict['player_message'] = (string) ($client['headline'] ?? $verdict['player_message']);
        $verdict['operator_hint'] = "客户端日志结论：" . (string) $primary['title'] . "\n"
            . (string) $primary['cause'] . "\n"
            . ClientAdvisor::adminSummary($client);

        // 接上服务端能自动做的修复（白名单、解封、重启…）
        $plan = (array) ($primary['server_fix'] ?? []);

        // 服务端**自己**独立给出的配方清单。客户端主张的每一个动作，都必须在这份
        // 清单里出现过才算"被核实"。
        //
        // 为什么必须有这道闸门：客户端那一侧的全部证据都是**玩家上传的文本**，
        // 玩家想写什么就写什么。实测（见 CHANGELOG 1.12.2）：填任意名字 +
        // 一段含 "You are not whitelisted on this server!" 的日志，就能让
        // whitelist_add 排进执行队列且无需审批 —— 等于公开表单变成白名单旁路。
        // 服务端核实过的动作照旧自动执行；核实不到的只能等管理员点批准。
        //
        // 注意要在这里先取：下面会用客户端的清单覆盖 $verdict['suggestions']。
        $serverRecipes = [];
        foreach ((array) ($verdict['suggestions'] ?? []) as $serverSuggestion) {
            $serverRecipes[(string) ($serverSuggestion['code'] ?? '')] = true;
        }

        $suggestions = [];
        $uncorroborated = false;
        foreach ((array) ($plan['codes'] ?? []) as $index => $code) {
            $def = Recipe::get((string) $code);
            if ($def === null) {
                continue;
            }
            $params = [];
            foreach ((array) $def['params'] as $name => $type) {
                if ($name === 'player') {
                    $params['player'] = (string) ($client['player_name'] ?? '');
                } elseif ($name === 'reason') {
                    $params['reason'] = '玩家日志分析触发的自动修复';
                }
            }
            $check = Recipe::validateParams((string) $code, $params);
            if (!$check['ok']) {
                continue;
            }
            $corroborated = isset($serverRecipes[(string) $code]);
            if (!$corroborated) {
                $uncorroborated = true;
            }
            $suggestions[] = [
                'code'              => (string) $code,
                'label'             => (string) $def['label'],
                'description'       => (string) $def['description'],
                'risk'              => (string) $def['risk'],
                'exec'              => (string) $def['exec'],
                'params'            => $check['params'],
                // 服务端没核实到 → 强制走人工审批
                'requires_approval' => (bool) ($plan['needs_approval'] ?? false) || !$corroborated,
                'corroborated'      => $corroborated,
            ];
        }

        $verdict['suggestions'] = $suggestions;
        $verdict['requires_approval'] = (bool) ($plan['needs_approval'] ?? false) || $uncorroborated;
        $verdict['auto_fixable'] = !empty($plan['auto'])
            && $suggestions !== []
            && empty($plan['needs_approval'])
            && !$uncorroborated;

        // 纯客户端问题：没有服务端动作可做，但不该算"未解决"，而是"给出指引后结束"
        if (!$suggestions && empty($client['needs']['unavailable'])) {
            $verdict['needs_manual'] = false;
        }

        return $verdict;
    }

    /**
     * 按留存天数清理已归档工单里的**日志原文**。
     *
     * 为什么需要：崩溃报告里含玩家的 Windows 用户名（`C:\Users\<名字>\...`）、
     * 显卡型号、游戏 ID、服务器地址，属于个人信息。而在这之前**没有任何东西
     * 删过它** —— 全项目唯一的 DELETE 只作用于 client_issues，工单和日志永久保留。
     * 对比之下邮箱一直有清理机制（TicketMail::purgePlayerEmail），日志却没有，
     * 这个不对称是漏的。
     *
     * 只删原文，不删结论：client_log / client_log_name 置空，
     * 诊断结果、MOD 列表、client_analysis 全部保留 —— 留着有用的，删掉没必要的。
     *
     * 安全边界（有测试盯着）：
     *   - 只动**终态**工单（closed / resolved / rejected）。进行中的可能还要
     *     重新分析，原文删了就分析不了。
     *   - 早期的实现只认 status = 'closed'，注释写着"resolved 可能还要重新分析"。
     *     但没有任何东西会把 resolved 推成 closed，于是那些工单的日志**永远
     *     清不掉**。现在三个终态一视同仁 —— 它们都带 closed_at，都算已结束。
     *   - $days <= 0 表示关闭该功能，一条都不动。
     *   - 已经清过的（log_purged_at 非空）不重复处理。
     *
     * @return int 本次清理了几条
     */
    public static function purgeExpiredLogs(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $deadline = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        // closed_at 对老数据可能是空的（归档功能是后加的），用 updated_at 兜底
        $rows = Db::all(
            "SELECT id FROM feedback
              WHERE client_log IS NOT NULL
                AND status IN ('closed', 'resolved', 'rejected')
                AND log_purged_at IS NULL
                AND COALESCE(closed_at, updated_at) < :deadline",
            ['deadline' => $deadline]
        );

        $purged = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            Db::update('feedback', [
                'client_log'      => null,
                'client_log_name' => null,
                'log_purged_at'   => now(),
                'updated_at'      => now(),
            ], ['id' => $id]);

            // 记进事件流：玩家在工单页也能看到"原文已按留存设置清理"，
            // 而不是发现日志莫名其妙不见了。
            record_event($id, 'system', 'log.purged', sprintf(
                '按留存设置清理了客户端日志原文（归档满 %d 天）',
                $days
            ));

            $purged++;
        }

        return $purged;
    }

    /**
     * 执行一个修复配方。
     *
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed>
     */
    public static function applyFix(array $feedback, array $suggestion, bool $forceApproval = false): array
    {
        $id = (int) $feedback['id'];
        $serverId = (string) $feedback['server_id'];
        $server = Config::server($serverId);

        if ($server === null) {
            return ['ok' => false, 'error' => '服务器不存在'];
        }

        $code = (string) ($suggestion['code'] ?? '');
        $params = (array) ($suggestion['params'] ?? []);

        $def = Recipe::get($code);
        if ($def === null) {
            return ['ok' => false, 'error' => '未知修复配方：' . $code];
        }

        if (!Recipe::allowed($server, $code)) {
            record_event($id, 'system', 'fix.blocked', '配方被服务器配置禁用：' . $code, [], 'warn');

            return ['ok' => false, 'error' => '该修复动作在当前服务器上被禁用'];
        }

        $validate = Recipe::validateParams($code, $params);
        if (!$validate['ok']) {
            return ['ok' => false, 'error' => $validate['error']];
        }
        $params = $validate['params'];

        // 风控 1：自动修复次数上限
        $attempts = (int) $feedback['fix_attempts'];
        $maxAttempts = (int) Config::get('automation.max_fix_attempts', 2);
        if ($attempts >= $maxAttempts) {
            self::escalate($id, '自动修复尝试次数已达上限（' . $maxAttempts . ' 次），转人工处理');

            return ['ok' => false, 'error' => '自动修复次数已达上限，已转人工'];
        }

        // 风控 2：重启类动作的小时配额
        if (in_array($code, ['restart_server', 'backup_world'], true)) {
            $maxPerHour = (int) ($server['guard']['max_restarts_per_hour'] ?? 3);
            $recent = Task::recentCount($serverId, $code, 3600);
            if ($maxPerHour > 0 && $recent >= $maxPerHour) {
                self::escalate($id, '最近一小时已触发 ' . $recent . ' 次「' . $def['label'] . '」，超出配额，转人工处理');

                return ['ok' => false, 'error' => '该动作已达每小时配额上限，已转人工'];
            }
        }

        // 风控 3：熔断 —— 同服务器连续失败太多就降级为只诊断
        $circuit = (int) Config::get('automation.circuit_breaker_failures', 3);
        if ($circuit > 0 && self::consecutiveFailures($serverId) >= $circuit) {
            // 运维必须能一眼看出"这次为什么没修"。以前这里只写一条 escalate，
            // 玩家侧看到的是"计划了但没做、也不说为什么" —— 和静默失败长得一样。
            record_event(
                $id,
                'system',
                'fix.breaker',
                '自动修复已熔断，本次仅诊断（连续失败达 ' . $circuit . ' 次）',
                ['server' => $serverId, 'threshold' => $circuit],
                'warn'
            );
            self::escalate($id, '该服务器最近连续 ' . $circuit . ' 次自动修复失败，已熔断为仅诊断模式');

            // error 里带「已熔断」这个关键词，前端和玩家侧才能把它和普通失败区分开
            return [
                'ok'    => false,
                'error' => '自动修复已熔断（连续失败 ' . $circuit . ' 次），请管理员检查 MC 侧 Agent；'
                    . '确认恢复后可在后台服务器页点「解除熔断」立即恢复，'
                    . '或等待熔断窗口自动到期。',
                'breaker' => true,
            ];
        }

        $needsApproval = $forceApproval || Recipe::needsApproval($server, $code);

        Db::update('feedback', [
            'status'       => $needsApproval ? 'manual' : 'fixing',
            'fix_attempts' => $attempts + 1,
            'updated_at'   => now(),
        ], ['id' => $id]);

        record_event($id, 'system', 'fix.start', '尝试执行修复：' . $def['label'] . ($needsApproval ? '（需管理员批准）' : ''), [
            'recipe' => $code,
            'params' => $params,
            'exec'   => $def['exec'],
        ]);

        $result = Executor::repair($server, $code, $params, $id, $needsApproval);

        Db::update('feedback', [
            'fix_result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ], ['id' => $id]);

        // 需要批准：状态保持 manual，等管理员点"批准执行"
        if ($needsApproval && !empty($result['task_id'])) {
            record_event($id, 'system', 'fix.await_approval', '修复动作已入队，等待管理员批准', [
                'task_id' => $result['task_id'],
                'recipe'  => $code,
            ], 'warn');

            return array_merge($result, ['status' => 'awaiting_approval']);
        }

        if (empty($result['ok'])) {
            // Agent 还没回结果：保持 fixing，让 cron 继续跟进
            if ((string) ($result['status'] ?? '') === 'pending') {
                record_event($id, 'system', 'fix.pending', '修复任务已下发，等待 MC 侧执行', [
                    'task_id' => $result['task_id'] ?? 0,
                ]);

                return array_merge($result, ['status' => 'pending']);
            }

            record_event($id, 'system', 'fix.failed', '修复失败：' . (string) $result['error'], [
                'task_id' => $result['task_id'] ?? 0,
            ], 'error');

            $attemptsAfter = $attempts + 1;
            if ($attemptsAfter >= $maxAttempts) {
                self::escalate($id, '修复失败且已达尝试上限：' . (string) $result['error']);
            } else {
                Db::update('feedback', ['status' => 'unresolved', 'updated_at' => now()], ['id' => $id]);
            }

            return array_merge($result, ['status' => 'failed']);
        }

        record_event($id, 'system', 'fix.done', '修复动作执行成功：' . $def['label'] . (isset($result['response']['output']) && $result['response']['output'] !== '' ? '（' . $result['response']['output'] . '）' : ''), [
            'task_id' => $result['task_id'] ?? 0,
            'channel' => $result['executor'] ?? '',
        ]);

        // 回归验证
        $verified = self::reverify($feedback, $code);

        return array_merge($result, ['status' => $verified['status'], 'verify' => $verified]);
    }

    /**
     * 修复后的回归验证：等一会儿再诊断一次，问题消失才算真解决。
     *
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>
     */
    public static function reverify(array $feedback, string $recipe = ''): array
    {
        $id = (int) $feedback['id'];
        $server = Config::server((string) $feedback['server_id']);

        $delay = in_array($recipe, ['restart_server'], true)
            ? (int) Config::get('automation.verify_delay_restart', 25)
            : (int) Config::get('automation.verify_delay', 5);

        // 玩家还在页面上等，别让他干等 25 秒：超过 6 秒的等待交给 cron 异步复验
        $maxInlineWait = (int) Config::get('feedback.sync_wait_seconds', 8);
        if ($delay > $maxInlineWait) {
            Db::update('feedback', [
                'status'     => 'verifying',
                'updated_at' => now(),
            ], ['id' => $id]);
            record_event($id, 'system', 'verify.deferred', '修复已下发，等待 ' . $delay . ' 秒后自动复验（结果会更新在本页）');

            return ['ok' => true, 'deferred' => true, 'after' => $delay, 'status' => 'verifying'];
        }

        if ($delay > 0) {
            sleep(min($delay, $maxInlineWait));
        }

        Db::update('feedback', ['status' => 'verifying', 'updated_at' => now()], ['id' => $id]);
        $fresh = self::find($id);
        if ($fresh === null) {
            return ['ok' => false, 'error' => '工单不存在'];
        }

        $diagnosis = Diagnosis::run($fresh, true);
        $verdict = (array) ($diagnosis['verdict'] ?? []);
        $issueNow = (string) ($verdict['issue'] ?? '');
        $previousIssue = (string) (safe_json_decode((string) ($fresh['fix_plan'] ?? ''))['issue'] ?? '');
        $isSameIssue = $previousIssue !== '' && $issueNow === $previousIssue;
        $stillBroken = in_array($issueNow, ['server_offline', 'process_missing', 'port_not_listening'], true) || $isSameIssue;

        Db::update('feedback', [
            'diagnosis'    => json_encode($diagnosis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'verdict'      => json_encode($verdict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'   => now(),
        ], ['id' => $id]);

        if ($stillBroken) {
            if (!$isSameIssue) {
                // 问题变了：说明原问题已经解决（例如服务器起来了），只是又暴露了新问题
                // 只有"这一单确实被系统下发过修复"才算自动修复，否则是它自己好的
                self::resolve(
                    $id,
                    '原问题已消失（复验发现：' . (string) ($verdict['title'] ?? '') . '）',
                    'system',
                    (int) ($fresh['fix_attempts'] ?? 0) > 0
                );

                return ['ok' => true, 'resolved' => true, 'issue' => $issueNow, 'status' => 'closed'];
            }

            record_event($id, 'system', 'verify.failed', '复验仍检测到同样的问题：' . (string) ($verdict['title'] ?? ''), [
                'issue' => $issueNow,
            ], 'warn');

            $attempts = (int) ($fresh['fix_attempts'] ?? 0);
            $maxAttempts = (int) Config::get('automation.max_fix_attempts', 2);
            if ($attempts >= $maxAttempts) {
                self::escalate($id, '复验未通过，自动修复机会已用完，请人工介入');
            } else {
                Db::update('feedback', ['status' => 'unresolved', 'updated_at' => now()], ['id' => $id]);
            }

            return ['ok' => false, 'resolved' => false, 'issue' => $issueNow, 'status' => 'unresolved'];
        }

        self::resolve(
            $id,
            '复验通过：' . (string) ($verdict['title'] ?? '问题已消失'),
            'system',
            (int) ($fresh['fix_attempts'] ?? 0) > 0
        );

        return ['ok' => true, 'resolved' => true, 'issue' => (string) ($verdict['issue'] ?? ''), 'status' => 'closed'];
    }

    /**
     * 给玩家看的进度（脱敏）。
     *
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>
     */
    public static function progress(array $feedback): array
    {
        $id = (int) $feedback['id'];
        $verdict = safe_json_decode((string) ($feedback['verdict'] ?? ''));
        $diagnosis = safe_json_decode((string) ($feedback['diagnosis'] ?? ''));
        $fixResult = safe_json_decode((string) ($feedback['fix_result'] ?? ''));
        $fixPlan = safe_json_decode((string) ($feedback['fix_plan'] ?? ''));

        $tasks = [];
        foreach (Task::forFeedback($id, 10) as $task) {
            $tasks[] = [
                'recipe'     => (string) $task['recipe'],
                'action'     => (string) $task['action'],
                'status'     => (string) $task['status'],
                'created_at' => (string) $task['created_at'],
                'finished_at'=> (string) ($task['finished_at'] ?? ''),
            ];
        }

        $events = [];
        foreach (Db::all(
            'SELECT actor, action, level, message, created_at FROM events WHERE feedback_id = :id ORDER BY id DESC LIMIT ' . self::MAX_LISTED_EVENTS,
            ['id' => $id]
        ) as $row) {
            $action = (string) $row['action'];
            $message = (string) $row['message'];

            // progress() 是给**持有工单链接的玩家**看的，而这条链路以前把
            // 管理员备注的原文一起返回了。feedback.admin_note 那一列本来就不
            // 对玩家暴露，说明备注的定位就是内部的 —— 事件这条路径漏了。
            if ($action === 'feedback.note') {
                continue;
            }
            // 驳回理由同理：玩家该知道"被驳回了"，但不该看到内部原话
            if ($action === 'feedback.reject') {
                $message = '管理员驳回了这条反馈';
            }

            $events[] = [
                'actor'   => (string) $row['actor'],
                'action'  => $action,
                'level'   => (string) $row['level'],
                'message' => $message,
                'at'      => (string) $row['created_at'],
            ];
        }
        $events = array_reverse($events);

        // 纯客户端工单没有服务器，交给 helper 统一处理：空值时它给一句可读的
        // 占位文案，而不是让玩家在工单页和邮件里看到「服务器：」后面什么都没有。
        $serverName = ticket_server_name((string) $feedback['server_id']);

        return [
            'ok'          => true,
            'ticket_no'   => (string) $feedback['ticket_no'],
            'status'      => (string) $feedback['status'],
            'status_label'=> status_label((string) $feedback['status']),
            'tone'        => status_tone((string) $feedback['status']),
            'player'      => (string) $feedback['player_name'],
            'server_id'   => (string) $feedback['server_id'],
            'server_name' => $serverName,
            'category'    => (string) $feedback['category'],
            'subject'     => (string) $feedback['subject'],
            'auto_fixed'  => (int) $feedback['auto_fixed'] === 1,
            'fix_attempts'=> (int) $feedback['fix_attempts'],
            'created_at'  => (string) $feedback['created_at'],
            'updated_at'  => (string) $feedback['updated_at'],
            'headline'    => (string) ($verdict['player_message'] ?? '正在验证中……'),
            'problem'     => [
                'title'    => (string) ($verdict['title'] ?? ''),
                'severity' => (string) ($verdict['severity'] ?? ''),
                'issue'    => (string) ($verdict['issue'] ?? ''),
                /*
                 * 和下面的 playerChecks() 保持一致：玩家侧一律抹掉服务端绝对路径（V8）。
                 *
                 * 之前只有 checks 那条路做了脱敏，problem.detail 这条漏着 ——
                 * 同一份数据（同一个 verdict）两条路两种待遇，迟早会从没做的那条漏出去。
                 * 报告诚实标注过：没能构造出确定含绝对路径的 fail/warn 消息
                 * （带路径的多为 status=unknown，而 Verdict 不分类 unknown），
                 * 所以这是**防御缺口**，不是已确认的泄露。补上它成本极低。
                 */
                'detail'   => redact_paths((string) ($verdict['detail'] ?? '')),
            ],
            'checks'      => self::playerChecks($diagnosis),
            'fix'         => [
                'planned'   => (string) ($fixPlan['title'] ?? ''),
                'executed'  => (string) ($fixResult['executor'] ?? ''),
                // 修复输出同样面向玩家展示，走同一套路径脱敏
                'output'    => redact_paths((string) ($fixResult['response']['output'] ?? '')),
                'success'   => !empty($fixResult['ok']),
                'awaiting_approval' => (string) ($fixResult['status'] ?? '') === 'awaiting_approval',
            ],
            'resolution'  => (string) ($feedback['resolution_note'] ?? ''),
            'tasks'       => $tasks,
            'timeline'    => $events,
            'client'      => self::playerClientView($feedback),
            'can_reverify'=> in_array((string) $feedback['status'], ['resolved', 'unresolved', 'manual', 'closed', 'diagnosed'], true),
            'updated_at_human' => human_time((string) $feedback['updated_at']),
        ];
    }

    /**
     * 给玩家看的客户端分析结果。
     *
     * 只暴露"跟玩家自己有关"的东西：环境事实、结论、他要做的步骤、他要下载的文件。
     * 不外泄服务端 MOD 清单、内部路径、可疑包名。
     *
     * @param array<string,mixed> $feedback
     * @return array<string,mixed>|null
     */
    private static function playerClientView(array $feedback): ?array
    {
        $analysis = safe_json_decode((string) ($feedback['client_analysis'] ?? ''));
        if (!$analysis || empty($analysis['ok'])) {
            return null;
        }

        $needs = (array) ($analysis['needs'] ?? []);
        $components = [];
        foreach ((array) ($needs['components'] ?? []) as $component) {
            $components[] = [
                'name'      => (string) ($component['name'] ?? ''),
                'available' => !empty($component['available']),
                'download'  => $component['available'] ? (string) ($component['download'] ?? '') : '',
                'size'      => (int) ($component['size'] ?? 0),
                'size_text' => (int) ($component['size'] ?? 0) > 0 ? human_size((float) $component['size']) : '',
                'note'      => (string) ($component['note'] ?? ''),
                'search'    => (string) ($component['search'] ?? ''),
                'is_local'  => !empty($component['is_local']),
            ];
        }

        $issues = [];
        foreach ((array) ($analysis['issues'] ?? []) as $issue) {
            $issues[] = [
                'code'       => (string) $issue['code'],
                'title'      => (string) $issue['title'],
                'severity'   => (string) $issue['severity'],
                'cause'      => (string) $issue['cause'],
                'steps'      => array_values((array) $issue['steps']),
                'detail'     => (string) ($issue['detail'] ?? ''),
                'extra'      => array_values((array) ($issue['extra'] ?? [])),
                'server_fix' => [
                    'labels' => array_values((array) ($issue['server_fix']['labels'] ?? [])),
                    'auto'   => !empty($issue['server_fix']['auto']),
                    'needs_approval' => !empty($issue['server_fix']['needs_approval']),
                ],
            ];
        }

        return [
            'headline' => (string) ($analysis['headline'] ?? ''),
            'severity' => (string) ($analysis['severity'] ?? 'normal'),
            'issues'   => $issues,
            'facts'    => array_values((array) ($analysis['facts'] ?? [])),
            'components' => $components,
            'mods_total' => (int) ($analysis['public']['mod_count'] ?? 0),
            'cross'    => [
                'possible' => !empty($analysis['cross_check']['possible']),
                'extra'    => array_slice((array) ($analysis['cross_check']['client_extra'] ?? []), 0, 10),
                /*
                 * 「服务端有、你没有」的那份清单**不回传给玩家**（V18）。
                 *
                 * 这里装的每个字符串都是服务端 jar 的完整文件名。看着像"帮你排查
                 * 缺了什么"，实际是把服务器装了哪些模组逐字报给任何提交日志的人 ——
                 * 而日志章节名是提交者自己编的（`ClientLog` 只按 stripos 认「Mods」
                 * 这类词），所以随便写一个假 mod 名就能让**整份服务端清单**都进
                 * 「你缺少」这个列表。实测匿名一条请求即可拿到 11/11 全量清单，
                 * 精确到构建号（`LuckPerms-Bukkit-5.4.102.jar` 这种），等于一份
                 * 现成的 CVE 打点清单。
                 *
                 * 玩家真正需要的是结论（"你缺 3 个模组"），不是文件名单。
                 * 所以只给数量，名称一律不给。
                 */
                'missing'  => [],
                'missing_count' => count((array) ($analysis['cross_check']['client_missing'] ?? [])),
                'note'     => (string) ($analysis['cross_check']['note'] ?? ''),
            ],
            'log_name' => (string) ($feedback['client_log_name'] ?? ''),
            'log_kind' => (string) ($analysis['public']['kind_label'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $diagnosis
     * @return array<int,array<string,string>>
     */
    private static function playerChecks(array $diagnosis): array
    {
        $out = [];
        foreach ((array) ($diagnosis['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $out[] = [
                'label'  => (string) ($check['label'] ?? ''),
                'status' => (string) ($check['status'] ?? 'unknown'),
                // 玩家侧只留文件名。诊断消息里会带服务端真实路径
                // （Agent 报"日志文件不存在或不可读：/home/xxx/minecraft/logs/latest.log"），
                // 而持有工单链接的人就能看到。路径对玩家没用，还会暴露服务器目录结构。
                // 管理员侧走的是另一条路（diagnosis.checks[].message），仍是完整路径。
                'text'   => redact_paths((string) ($check['message'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * 管理员动作。
     *
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function adminAction(array $feedback, string $action, array $input = [], string $actor = 'admin'): array
    {
        $id = (int) $feedback['id'];

        switch ($action) {
            case 'verify':
                return self::verify($feedback, true, false);

            case 'autofix':
                $verdict = safe_json_decode((string) ($feedback['verdict'] ?? ''));
                $suggestions = (array) ($verdict['suggestions'] ?? []);
                if (!$suggestions) {
                    return ['ok' => false, 'error' => '当前诊断没有可执行的修复配方，请先重新验证'];
                }

                return self::applyFix($feedback, (array) $suggestions[0], true);

            case 'run_recipe':
                $code = (string) ($input['recipe'] ?? '');
                $server = Config::server((string) $feedback['server_id']);
                if ($server === null) {
                    return ['ok' => false, 'error' => '服务器不存在'];
                }
                $params = (array) ($input['params'] ?? []);
                if (!isset($params['player']) || $params['player'] === '') {
                    $params['player'] = (string) $feedback['player_name'];
                }

                return self::applyFix($feedback, ['code' => $code, 'params' => $params], true);

            case 'resolve':
                // 管理员手动标记：auto_fixed = false，别在玩家页面上冒充"系统自动修好了"
                self::resolve($id, (string) ($input['note'] ?? '管理员手动标记为已解决'), $actor, false);

                // resolve() 现在直接落到 closed（见那里的说明），所以这里回报 closed
                return ['ok' => true, 'status' => 'closed'];

            case 'reject':
                /*
                 * 驳回也是一个**终态**：管理员看过、判定不用处理，这件事就结束了。
                 *
                 * 原来只写 status = 'rejected'、不写 closed_at，于是它既不算
                 * "已结束"（归档永远跳过它，日志永久留着），在后台又容易被当成
                 * 还没处理完的工单。
                 *
                 * 现在的语义：rejected 与 closed 的区别只是"为什么结束"，
                 * 两者都是终态、都参与日志归档、都不计入"待处理"。
                 */
                Db::update('feedback', [
                    'status'          => 'rejected',
                    'closed_at'       => now(),
                    'resolution_note' => mb_substr((string) ($input['note'] ?? '管理员判定无需处理'), 0, 1000),
                    'updated_at'      => now(),
                ], ['id' => $id]);
                record_event($id, 'admin', 'feedback.reject', '管理员驳回工单：' . (string) ($input['note'] ?? ''), [], 'warn');

                self::settleMail($id, '管理员判定无需处理：' . (string) ($input['note'] ?? ''));

                return ['ok' => true, 'status' => 'rejected'];

            case 'close':
                Db::update('feedback', [
                    'status'     => 'closed',
                    'closed_at'  => now(),
                    'updated_at' => now(),
                ], ['id' => $id]);
                record_event($id, 'admin', 'feedback.close', '管理员关闭工单');

                // 关闭是"结束"的一种：至少要清掉邮箱
                self::settleMail($id, '工单已被管理员关闭');

                return ['ok' => true, 'status' => 'closed'];

            case 'reopen':
                Db::update('feedback', [
                    'status'     => 'submitted',
                    'closed_at'  => null,
                    'updated_at' => now(),
                ], ['id' => $id]);
                record_event($id, 'admin', 'feedback.reopen', '管理员重新打开工单');

                return ['ok' => true, 'status' => 'submitted'];

            case 'note':
                $note = mb_substr((string) ($input['note'] ?? ''), 0, 2000);
                Db::update('feedback', ['admin_note' => $note, 'updated_at' => now()], ['id' => $id]);
                record_event($id, 'admin', 'feedback.note', '管理员备注：' . mb_substr($note, 0, 200));

                return ['ok' => true];

            case 'rotate_link':
                $nonce = bin2hex(random_bytes(8));
                Db::update('feedback', ['token_nonce' => $nonce, 'updated_at' => now()], ['id' => $id]);
                $fresh = self::find($id);
                record_event($id, 'admin', 'feedback.rotate_link', '管理员重新生成反馈链接（旧链接已失效）');

                return [
                    'ok'        => true,
                    'share_url' => $fresh !== null ? Token::feedbackUrl($fresh) : '',
                ];

            default:
                return ['ok' => false, 'error' => '未知操作：' . $action];
        }
    }

    /**
     * 把工单标记为已解决。
     *
     * `auto_fixed` 是"系统自己修好的"这个说法的唯一依据，玩家页面上会显示
     * "系统已自动处理"。所以它不能无条件置 1：
     *   - 复验通过（系统发指令 → 复验问题消失）→ true
     *   - 复验时发现原来的问题自己没了（上一轮修复失败）→ true，问题确实消失了
     *   - 管理员手动点"标记已解决" → false，这是人工的功劳，不该冒充自动
     */
    public static function resolve(int $id, string $note, string $actor = 'system', bool $autoFixed = true): void
    {
        /*
         * ★ 「已解决」直接落到「已关闭」。
         *
         * 原来这里只写 status = 'resolved'，而**没有任何东西**会把 resolved
         * 转成 closed。后果不只是状态显示不对，而是一个实打实的隐私问题：
         *
         *   purgeExpiredLogs() 只清 status = 'closed' 的工单（它的注释写着
         *   "resolved 可能还要重新分析，原文删了就分析不了"）。既然永远没人
         *   把 resolved 推成 closed，这些工单的日志就**永远不会被清** ——
         *   而崩溃报告里含玩家的 Windows 用户名、显卡型号、游戏路径。
         *   玩家页面上却明写着"工单归档满 N 天后会自动清掉原文"。
         *
         * 所以"解决"和"关闭"合并成同一个终态：管理员点「标记已解决」就是
         * 这件事处理完了，不该再留一个既非进行中、又不会被归档的悬空状态。
         * closed_at 也要写上 —— 归档是按它算时间的（老数据用 updated_at 兜底）。
         */
        Db::update('feedback', [
            'status'          => 'closed',
            'closed_at'       => now(),
            'auto_fixed'      => $autoFixed ? 1 : 0,
            'resolution_note' => mb_substr($note, 0, 1000),
            'updated_at'      => now(),
        ], ['id' => $id]);

        record_event($id, $actor, 'feedback.resolved', $note);

        self::settleMail($id, $note);
    }

    /**
     * 工单有结论了：把结果发给玩家，并按隐私设置把邮箱换成掩码。
     *
     * 单独抽出来是因为"结束"有好几条路径（自动修好 / 转人工 / 管理员手动标记 /
     * 驳回 / 关闭），漏掉任何一条都会出现"玩家永远收不到结果"或者"邮箱没被清掉"。
     */
    private static function settleMail(int $id, string $reason = ''): void
    {
        try {
            $fresh = self::find($id);
            if ($fresh !== null) {
                TicketMail::onSettled($fresh, $reason);
            }
        } catch (\Throwable $e) {
            app_log('warn', '发送工单结果邮件失败', ['error' => $e->getMessage(), 'id' => $id]);
        }
    }

    public static function escalate(int $id, string $reason): void
    {
        Db::update('feedback', [
            'status'          => 'manual',
            'resolution_note' => mb_substr($reason, 0, 1000),
            'updated_at'      => now(),
        ], ['id' => $id]);

        record_event($id, 'system', 'feedback.escalate', '转人工处理：' . $reason, [], 'warn');

        // 转人工也算"这一轮有结论了"：玩家该知道"我们看了，需要人工介入"，
        // 而不是对着"正在验证中"一直等
        self::settleMail($id, '需要管理员人工处理：' . $reason);

        // 转人工时通知一下，别让工单静静躺在后台
        try {
            $feedback = self::find($id);
            if ($feedback !== null) {
                self::notifyManualEscalation($feedback, $reason);
            }
        } catch (\Throwable $e) {
            app_log('warn', '发送转人工通知失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $feedback
     */
    private static function notifyManualEscalation(array $feedback, string $reason): void
    {
        $verdict = safe_json_decode((string) ($feedback['verdict'] ?? ''));

        Notifier::dispatch('need_manual', [
            'feedback_id'    => (int) $feedback['id'],
            'ticket_no'      => (string) $feedback['ticket_no'],
            'server_id'      => (string) $feedback['server_id'],
            'player_name'    => (string) $feedback['player_name'],
            'category_label' => (string) (Catalog::category((string) $feedback['category'])['label'] ?? ''),
            'problem'        => (string) ($verdict['title'] ?? (string) $feedback['subject']),
            'result'         => $reason,
            'checks'         => self::briefChecks($verdict),
        ], 'escalate:' . (int) $feedback['id'] . ':' . substr(md5($reason), 0, 8));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM feedback WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByTicket(string $ticketNo): ?array
    {
        return Db::first('SELECT * FROM feedback WHERE ticket_no = :no', ['no' => $ticketNo]);
    }

    /**
     * 同服务器最近的自动修复失败次数（连续计），用于熔断。
     *
     * ★ 必须带时间窗。原来的查询把"上个月连爆 3 次"和"刚才连爆 3 次"一视同仁，
     * 而下面的 break 意味着计数永不衰减 —— 于是熔断一旦触发就**永久锁死**。
     *
     * 这是一个确定性死锁，四步可核对：
     *   1. 计数没有时间窗，只按 id 倒序取最近 N 条；
     *   2. 清零的前提是遇到一行"fix_attempts>0 且 fix_result.ok=true"的记录；
     *   3. 但熔断分支在写 fix_attempts / fix_result **之前**就 return 了，
     *      被熔断挡住的这次修复不写任何记录；
     *   4. 所以那条成功记录永远不可能产生 → 计数永远 >= 阈值。
     *
     * 恢复只能靠手工改库，或把阈值设成 0（等于整块关掉这项防护）。
     * 加时间窗之后，窗口内不再有失败就会被自动放行 —— 自愈。
     */
    public static function consecutiveFailures(string $serverId, int $lookback = 10): int
    {
        $window = (int) Config::get('automation.circuit_window_seconds', 3600);
        if ($window <= 0) {
            $window = 3600;
        }
        $since = gmdate('Y-m-d H:i:s', time() - $window);

        // 管理员显式"解除熔断"过的话，只统计解除时间点之后的新失败。
        // 这比单纯等窗口过期更快、也更符合管理员的意图（他确认已经修好了）。
        $manualReset = self::circuitResetAt($serverId);
        if ($manualReset !== '' && strcmp($manualReset, $since) > 0) {
            $since = $manualReset;
        }

        $rows = Db::all(
            'SELECT status, fix_attempts, fix_result FROM feedback
             WHERE server_id = :sid AND fix_attempts > 0 AND created_at > :since
             ORDER BY id DESC LIMIT ' . max(1, min(50, $lookback)),
            ['sid' => $serverId, 'since' => $since]
        );

        $count = 0;
        foreach ($rows as $row) {
            $result = safe_json_decode((string) ($row['fix_result'] ?? ''));
            $failed = !empty($result) && empty($result['ok']);
            if ($failed) {
                $count++;
                continue;
            }
            break;
        }

        return $count;
    }

    /**
     * 管理员上一次"解除熔断"这台服务器的时间点（没解除过就返回空串）。
     *
     * 存缓存文件而不是新建数据表：项目已经有 Cache 这套东西，而这条记录
     * 生命周期很短（窗口过期就没意义了），不值得为它改 schema。
     * 缓存丢失的代价也只是"退回按时间窗判断"，不影响安全性。
     */
    public static function circuitResetAt(string $serverId): string
    {
        if ($serverId === '') {
            return '';
        }

        $value = Cache::get('circuit-reset-' . $serverId, '');

        return is_string($value) ? $value : '';
    }

    /**
     * 显式解除某台服务器的熔断。
     *
     * 为什么需要：光有时间窗还不够 —— 管理员"已经下去把 Agent 修好了"之后
     * 应该能立刻恢复自动修复，而不是干等窗口走完再被旧失败拦一次。
     */
    public static function resetCircuitBreaker(string $serverId): bool
    {
        if ($serverId === '' || Config::server($serverId) === null) {
            return false;
        }

        // TTL 给窗口的两倍：只要比窗口长，这条记录就一定能覆盖到下一次判定。
        $window = (int) Config::get('automation.circuit_window_seconds', 3600);
        Cache::put('circuit-reset-' . $serverId, now(), max(60, $window * 2));
        record_event(null, 'admin', 'fix.breaker.reset', '管理员解除了自动修复熔断：' . $serverId);

        return true;
    }

    private static function generateTicketNo(string $serverId): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $serverId) ?: 'MC', 0, 3));
        for ($i = 0; $i < 8; $i++) {
            $no = $prefix . '-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            $exists = Db::scalar('SELECT COUNT(*) FROM feedback WHERE ticket_no = :no', ['no' => $no]);
            if ((int) $exists === 0) {
                return $no;
            }
        }

        return $prefix . '-' . bin2hex(random_bytes(5));
    }

    /**
     * 工单级串行锁，避免玩家连点导致重复诊断/重复修复。
     *
     * @return resource|false
     */
    private static function lock(int $feedbackId)
    {
        $dir = storage_path('locks');
        ensure_dir($dir);
        $path = $dir . '/feedback-' . $feedbackId . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * @param resource|false $handle
     */
    private static function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
