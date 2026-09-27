<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 判定器：把一堆客观检查结果，归纳成"到底是什么问题 + 能不能自动修 + 用什么配方修"。
 *
 * 单独拆出来是因为它是整个系统最需要被审计、被调整的部分：
 * 管理员想改"什么情况算严重"，只改这个文件即可。
 */
final class Verdict
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $checks
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function evaluate(array $server, string $category, array $checks, array $context = []): array
    {
        $player = (string) ($context['player'] ?? '');
        $issues = [];

        foreach ($checks as $code => $check) {
            $issue = self::classify((string) $code, $check, $player, $checks);
            if ($issue !== null) {
                $issues[] = self::guardAgainstSelfContradiction($checks, $issue);
            }
        }

        // 严重度排序
        $rank = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        usort($issues, static function (array $a, array $b) use ($rank): int {
            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        $primary = $issues[0] ?? [
            'code'     => 'no_problem_detected',
            'severity' => 'low',
            'title'    => '没有验证到服务器侧异常',
            'detail'   => '服务端各项指标正常，问题可能出在客户端（版本、模组、网络）或需要管理员人工判断。',
            'source'   => 'summary',
        ];

        $categoryDef = Catalog::category($category) ?? Catalog::category('other');
        $allowRecipes = (array) ($categoryDef['allow_recipes'] ?? []);

        $suggestions = self::suggestRecipes($server, $primary, $issues, $allowRecipes, $player);

        return self::assemble($server, $category, $primary, $issues, $suggestions);
    }

    /**
     * 由「问题 + 可执行动作」算出最终结论。
     *
     * **判定逻辑只有这一份。** 规则库那条路和大模型那条路都走这里：
     * auto_fixable、是否需要管理员审批、有没有可用通道、给玩家看的话，
     * 全部按同一套规则算。大模型做的事只是"换一个 primary 和一条 suggestion"，
     * 换不出更宽的权限。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $issues
     * @param array<int,array<string,mixed>> $suggestions
     * @return array<string,mixed>
     */
    public static function assemble(
        array $server,
        string $category,
        array $primary,
        array $issues,
        array $suggestions
    ): array {
        $requiredApproval = false;
        foreach ($suggestions as $suggestion) {
            if (!empty($suggestion['requires_approval'])) {
                $requiredApproval = true;
                break;
            }
        }

        $autoFixEnabled = (bool) Config::get('automation.auto_fix', true);
        $needsManual = self::needsManual($primary, $issues, $category);

        // 有没有任何一条"能把动作送出去"的通道：RCON、面板 API、Agent/SSH/local
        $executor = (string) ($server['executor'] ?? 'none');
        $hasChannel = $server !== []
            && ($executor !== 'none'
                || Rcon::enabled($server)
                || PanelRegistry::adapter($server) !== null);

        $autoFixable = !$needsManual
            && $suggestions !== []
            && $autoFixEnabled
            && $hasChannel
            && !$requiredApproval;

        $playerMessage = self::playerMessage($primary, $suggestions, $autoFixable, $requiredApproval, $needsManual);

        return [
            'issue'              => (string) $primary['code'],
            'severity'           => (string) $primary['severity'],
            'title'              => (string) $primary['title'],
            'detail'             => (string) $primary['detail'],
            'source'             => (string) $primary['source'],
            'issues'             => $issues,
            'suggestions'        => $suggestions,
            'auto_fixable'       => $autoFixable,
            'requires_approval'  => $requiredApproval,
            'needs_manual'       => $needsManual,
            'auto_fix_enabled'   => $autoFixEnabled,
            'player_message'     => $playerMessage,
            'operator_hint'      => self::operatorHint($primary, $issues, $server),
            'evaluated_at'       => now(),
        ];
    }

    /**
     * 单条检查 → 问题描述。
     *
     * @param array<string,mixed> $check
     * @param array<string,mixed> $checks 本轮全部检查结果（用来做交叉印证）
     * @return array<string,mixed>|null
     */
    private static function classify(string $code, array $check, string $player, array $checks = []): ?array
    {
        $status = (string) ($check['status'] ?? 'unknown');
        $data = is_array($check['data'] ?? null) ? $check['data'] : [];
        $message = (string) ($check['message'] ?? '');

        if ($status === 'skipped') {
            return null;
        }

        switch ($code) {
            case 'mc_status':
                if ($status === 'fail') {
                    // 先找反证：MC 协议连不上，不等于服务端真的挂了。
                    // 面板服很常见 —— MCFix 所在的机器到 MC 端口这一跳不通
                    // （只对外开了 SRV 域名、端口只放行游戏节点、或者干脆填错了地址），
                    // 但面板读数和进程状态都说明服务在跑。
                    // 这种情况下判"服务端离线"会误导管理员去重启一台好机器。
                    $alive = self::evidenceServerAlive($checks);
                    if ($alive !== '') {
                        return self::issue(
                            'mc_unreachable',
                            'high',
                            '本机连不上 MC 端口，但服务端看起来仍在运行',
                            $message . '。不过' . $alive . '，所以更像是网络或地址配置的问题，'
                                . '不是服务端挂了。请检查：host/port 是否与玩家填写的一致、'
                                . '该端口是否对面板服务器放行、域名是否只配了 SRV 记录。',
                            $code,
                            $data,
                            []
                        );
                    }

                    return self::issue('server_offline', 'critical', '服务端当前处于离线状态', $message, $code, $data, ['restart_server']);
                }
                if (!empty($data['max_players']) && !empty($data['players']) && (int) $data['players'] >= (int) $data['max_players']) {
                    return self::issue('server_full', 'high', '服务器已满员', sprintf('当前 %d/%d 人，达到上限后新玩家会被拒绝', $data['players'], $data['max_players']), $code, $data, []);
                }

                return null;

            case 'port':
                if ($status === 'fail') {
                    return self::issue('port_not_listening', 'critical', '服务端端口没有监听', $message, $code, $data, ['restart_server']);
                }

                return null;

            case 'process':
                if ($status === 'fail') {
                    /*
                     * 和 mc_status 一样先找反证（V19）。
                     *
                     * 进程表里看不到 MC 进程，**不等于**服务端真的没在跑。面板托管、
                     * 容器内运行、MC 在另一台机器、或者用 local 执行器而本机不直接
                     * 跑 MC —— 这些情况进程表里都找不到，但服务好好的。
                     *
                     * 这个是实测频率最高的一条误报：15 个分类里 13 个会走到这里，
                     * 其中 11 个同时还报着"服务器在线"。误报成 critical 还挂上
                     * restart_server，等于把管理员引去重启一台健康的服务器。
                     *
                     * 找不到反证时才维持原判（那时确实可能是真挂了）。
                     */
                    $alive = self::evidenceServerAlive($checks);
                    if ($alive !== '') {
                        return self::issue(
                            'process_unverifiable',
                            'warn',
                            '本机看不到 MC 进程，但服务端看起来仍在运行',
                            $message . '。不过' . $alive
                                . '，所以进程检查多半是部署形态导致的误判'
                                . '（面板托管 / 容器内运行 / MC 在另一台机器），不是服务端挂了。',
                            $code,
                            $data,
                            []
                        );
                    }

                    return self::issue('process_missing', 'critical', 'MC 服务端进程不存在', $message, $code, $data, ['restart_server']);
                }
                if ($status === 'warn') {
                    return self::issue('process_unstable', 'high', '服务端进程状态异常', $message, $code, $data, ['restart_server']);
                }

                return null;

            case 'disk':
                if ($status === 'fail') {
                    return self::issue('disk_full', 'critical', '磁盘空间不足', $message, $code, $data, ['backup_world', 'restart_server']);
                }
                if ($status === 'warn') {
                    return self::issue('disk_low', 'high', '磁盘即将写满', $message, $code, $data, ['backup_world']);
                }

                return null;

            case 'logs':
                if ($status === 'fail') {
                    $pattern = (string) ($data['top_pattern'] ?? '');
                    $recipes = [];
                    if ($pattern !== '') {
                        if (stripos($pattern, 'OutOfMemory') !== false || stripos($pattern, 'GC overhead') !== false) {
                            $recipes[] = 'restart_server';
                        }
                        if (stripos($pattern, 'Address already in use') !== false) {
                            $recipes[] = 'restart_server';
                        }
                    }
                    if (!$recipes) {
                        $recipes = ['save_world', 'restart_server'];
                    }

                    return self::issue('log_crash', 'high', '服务端日志里有崩溃 / 致命错误', $message, $code, $data, $recipes);
                }
                if ($status === 'warn') {
                    return self::issue('log_error', 'normal', '服务端日志里有异常', $message, $code, $data, ['save_world']);
                }

                return null;

            case 'tps':
                if ($status === 'fail') {
                    return self::issue('lag_severe', 'high', '服务器严重卡顿', $message, $code, $data, ['save_world', 'restart_server']);
                }
                if ($status === 'warn' && (isset($data['tps']) ? (float) $data['tps'] < 19.0 : true)) {
                    return self::issue('lag_mild', 'normal', '服务器有卡顿', $message, $code, $data, ['save_world']);
                }

                return null;

            case 'plugin_errors':
                if ($status === 'fail' || $status === 'warn') {
                    return self::issue('plugin_error', 'normal', '插件持续报错', $message, $code, $data, ['reload_plugins', 'restart_server']);
                }

                return null;

            case 'whitelist_player':
                if ($status === 'fail') {
                    return self::issue('not_whitelisted', 'high', $player !== '' ? $player . ' 不在白名单里' : '玩家不在白名单里', $message, $code, $data, ['whitelist_add']);
                }

                return null;

            case 'whitelist':
                if ($status === 'fail') {
                    return self::issue('whitelist_empty', 'high', '白名单开启但名单为空', $message, $code, $data, []);
                }
                if ($status === 'warn') {
                    return self::issue('whitelist_off', 'low', '白名单处于关闭状态', $message, $code, $data, []);
                }

                return null;

            case 'ban_player':
                if ($status === 'fail') {
                    return self::issue('player_banned', 'high', $player !== '' ? $player . ' 已被封禁' : '玩家已被封禁', $message, $code, $data, ['unban_player']);
                }

                return null;

            case 'player_online':
                // 在线与否本身不是故障，仅作为上下文
                return null;

            case 'process_info':
            case 'world_info':
            case 'agent_info':
                if ($status === 'warn' && $code === 'agent_info') {
                    return self::issue('agent_offline', 'low', 'MC 侧执行器不可用', $message, $code, $data, []);
                }

                return null;

            default:
                if ($status === 'fail') {
                    return self::issue('check_failed_' . $code, 'normal', (string) ($check['label'] ?? $code) . ' 检查未通过', $message, $code, $data, []);
                }

                return null;
        }
    }

    /**
     * 反证兜底：一个"服务端挂了"级别的结论，不能和"玩家能连上"同时成立。
     *
     * 为什么要有这一层，而不只是在上面的 case 里各写一遍反证：
     * `mc_status` 早就写了反证（还带注释说明为什么），但 `process` 和 `port`
     * 没写 —— 结果就是同一个"进程看不到"的事实，走 mc_status 那条路会被降级，
     * 走 process 那条路却直接判 critical。这种"各写一遍"必然漏。
     *
     * 所以把不变式提到汇总层：不管哪个 case 产出了"服务端不存在/离线"这种结论，
     * 只要同一批检查里有"服务端活着"的客观证据，就统一降级为待确认、
     * 并清掉它的修复建议（尤其是 restart_server —— 那正是"误导管理员去重启
     * 一台好机器"的来源）。
     *
     * @param array<string,mixed> $checks
     * @param array<string,mixed> $issue
     * @return array<string,mixed>
     */
    private static function guardAgainstSelfContradiction(array $checks, array $issue): array
    {
        // 只针对"断言服务端不存在/没在跑"这类结论。别的 critical（磁盘满、玩家被封）
        // 跟"服务端在不在线"不矛盾，不该被这里削掉。
        $contradictable = ['process_missing', 'port_not_listening', 'server_offline'];
        if (!in_array((string) ($issue['code'] ?? ''), $contradictable, true)) {
            return $issue;
        }

        $alive = self::evidenceServerAlive($checks);
        if ($alive === '') {
            return $issue;
        }

        $issue['severity'] = 'warn';
        $issue['title'] = (string) ($issue['title'] ?? '') . '（但有反证，已降级）';
        $issue['detail'] = mb_substr(
            (string) ($issue['detail'] ?? '') . '。不过' . $alive
                . '，两项结论互相矛盾，按"待确认"处理，请不要据此重启服务端。',
            0,
            400
        );
        // 清掉修复动作：矛盾的结论不该触发任何自动或人工的写操作
        $issue['recipe_hints'] = [];

        return $issue;
    }

    /**
     * 找"服务端其实在运行"的反证。
     *
     * 只采信**来自机器或面板的客观读数**，不采信日志时间戳 ——
     * 面板接口读日志时返回的 mtime 是"我们读它的时间"，拿它当证据会自欺欺人。
     *
     * @param array<string,mixed> $checks
     * @return string 有证据时返回一句人话，没有就返回空串
     */
    private static function evidenceServerAlive(array $checks): string
    {
        // 面板的资源接口（翼龙有）说进程在跑
        $processInfo = $checks['process_info'] ?? null;
        if (is_array($processInfo) && (string) ($processInfo['status'] ?? '') === 'pass') {
            $message = trim((string) ($processInfo['message'] ?? ''));

            return $message !== '' ? '面板读到的状态是「' . $message . '」' : '面板显示进程正在运行';
        }

        // MC 机器上的执行器（Agent / SSH / local）确认进程存在
        $process = $checks['process'] ?? null;
        if (is_array($process) && (string) ($process['status'] ?? '') === 'pass') {
            return 'MC 机器上确认服务端进程存在';
        }

        /*
         * ★ MC 协议握手成功 = 服务端一定在跑。
         *
         * 这一条是后补的，但它其实比上面两条都硬：能完成 Minecraft 协议握手并
         * 报出在线人数和版本，说明服务端进程活着、监听正常、还在正常处理请求 ——
         * 这是"服务端在运行"最直接的证据，比进程表读数更贴近"玩家能不能玩"。
         *
         * 之前漏了它，导致一个很常见的组合被误判：
         *   mc_status = pass（玩家连得上，响应里写着"在线 3/100 人"）
         *   process   = fail（本机进程表里看不到 MC 进程）
         * 于是判"MC 服务端进程不存在 / critical"并建议**重启**，同一份响应里
         * 又自相矛盾地写着"在线" —— 管理员会被引去重启一台好机器。
         *
         * 为什么 process 看不到进程却是在跑：面板托管、容器内运行、MC 在另一台
         * 机器、或用 local 执行器而本机不直接跑 MC —— 进程表里都没有。
         */
        $mcStatus = $checks['mc_status'] ?? null;
        if (is_array($mcStatus) && (string) ($mcStatus['status'] ?? '') === 'pass') {
            $message = trim((string) ($mcStatus['message'] ?? ''));

            return $message !== '' ? 'MC 协议握手成功（' . $message . '）' : 'MC 协议握手成功，服务端在响应';
        }

        // 注意：这里**故意**不采信"端口探测通过"。
        // 端口通、协议握手失败，既可能是"地址填错"，也可能是"服务端半死不活"，
        // 两者混淆会把真正该重启的情况放过。宁可在这种模糊场景下判"离线"让人来看。
        return '';
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $recipes
     * @return array<string,mixed>
     */
    private static function issue(string $code, string $severity, string $title, string $detail, string $source, array $data, array $recipes): array
    {
        return [
            'code'          => $code,
            'severity'      => $severity,
            'title'         => $title,
            'detail'        => mb_substr($detail, 0, 400),
            'source'        => $source,
            'data'          => $data,
            'recipe_hints'  => $recipes,
        ];
    }

    /**
     * 根据问题 + 分类，挑出可执行的配方（再做一次白名单与状态校验）。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $issues
     * @param string[] $allowRecipes
     * @return array<int,array<string,mixed>>
     */
    private static function suggestRecipes(array $server, array $primary, array $issues, array $allowRecipes, string $player): array
    {
        $candidates = [];

        foreach ((array) ($primary['recipe_hints'] ?? []) as $hint) {
            $candidates[] = (string) $hint;
        }
        foreach ($issues as $issue) {
            foreach ((array) ($issue['recipe_hints'] ?? []) as $hint) {
                $candidates[] = (string) $hint;
            }
        }

        $candidates = array_values(array_unique($candidates));
        $suggestions = [];

        foreach ($candidates as $code) {
            $suggestion = self::buildSuggestion($server, (string) $code, $allowRecipes, $player);
            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * 单个配方 → 一条可执行建议。**所有校验都集中在这里。**
     *
     * 为什么单独抽出来：规则库和大模型两条路都必须过同样的闸门。
     * 如果给大模型另写一份校验，早晚会漏掉一条（比如忘了查 Recipe::allowed），
     * 那"模型只能挑不能造"的保证就名存实亡了。
     *
     * 参数来源是硬约束：只认 player（取自工单）和 reason（固定文案）。
     * 出现任何别的参数名就直接放弃这个动作 —— 宁可不动手，也不让模型或
     * 外部数据往命令模板里塞东西。
     *
     * @param array<string,mixed> $server
     * @param string[] $allowRecipes 分类限制；空数组表示该类不限制
     * @return array<string,mixed>|null
     */
    private static function buildSuggestion(array $server, string $code, array $allowRecipes, string $player): ?array
    {
        $def = Recipe::get($code);
        if ($def === null) {
            return null;
        }
        // 分类限制：这一类的反馈不允许的动作不做（例如"举报玩家"类不允许任何自动动作）
        if ($allowRecipes && !in_array($code, $allowRecipes, true)) {
            return null;
        }
        if (!Recipe::allowed($server, $code)) {
            return null;
        }

        $params = [];
        $missing = '';
        foreach ((array) $def['params'] as $name => $type) {
            if ($name === 'player') {
                if ($player === '') {
                    $missing = '缺少玩家 ID，无法自动执行';
                    break;
                }
                $params['player'] = $player;
            } elseif ($name === 'reason') {
                $params['reason'] = '由玩家反馈触发的自动修复';
            } else {
                $missing = '参数来源不明：' . $name;
                break;
            }
        }
        if ($missing !== '') {
            return null;
        }

        $validate = Recipe::validateParams($code, $params);
        if (!$validate['ok']) {
            return null;
        }

        return [
            'code'              => $code,
            'label'             => (string) $def['label'],
            'description'       => (string) $def['description'],
            'risk'              => (string) $def['risk'],
            'exec'              => (string) $def['exec'],
            'params'            => $validate['params'],
            'requires_approval' => Recipe::needsApproval($server, $code),
        ];
    }

    /**
     * 大模型从清单里挑了某一个配方之后，用它生成建议。
     *
     * 注意：这里会**重新跑一遍** buildSuggestion 的全部校验，
     * 不因为"是模型挑的"而跳过任何一条。
     *
     * @param array<string,mixed> $server
     * @param string[] $allowRecipes
     * @return array<string,mixed>|null
     */
    public static function suggestOne(array $server, string $code, string $player, array $allowRecipes = []): ?array
    {
        return self::buildSuggestion($server, $code, $allowRecipes, $player);
    }

    /**
     * 当前这台服务器上、**可以交给大模型去挑**的配方清单。
     *
     * 已经过滤掉：不在白名单、当前服务器不允许执行、参数来源不明的。
     * 调用方再叠加自己的策略（例如排除掉有安全影响或破坏性的动作）。
     *
     * @param array<string,mixed> $server
     * @param string[] $allowRecipes
     * @return array<string,array<string,string>>
     */
    public static function selectableRecipes(array $server, string $player, array $allowRecipes = []): array
    {
        $out = [];

        foreach (Recipe::all() as $code => $def) {
            $code = (string) $code;
            if (self::buildSuggestion($server, $code, $allowRecipes, $player) === null) {
                continue;
            }

            $out[$code] = [
                'label'       => (string) $def['label'],
                'description' => (string) $def['description'],
                'risk'        => (string) $def['risk'],
            ];
        }

        return $out;
    }

    /**
     * 有些问题天生必须人工判断（举报、玩家数据丢失、回档），不能自动动手。
     *
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $issues
     */
    private static function needsManual(array $primary, array $issues, string $category): bool
    {
        if ($category === 'player_report') {
            return true;
        }

        $manualIssues = ['no_problem_detected', 'world_corrupt', 'data_issue', 'unknown_server', 'server_full', 'mc_unreachable'];
        foreach (array_merge([$primary], $issues) as $issue) {
            if (in_array((string) ($issue['code'] ?? ''), $manualIssues, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $suggestions
     */
    private static function playerMessage(array $primary, array $suggestions, bool $autoFixable, bool $requiresApproval, bool $needsManual): string
    {
        $title = (string) $primary['title'];

        if ($autoFixable && $suggestions) {
            $labels = array_map(static function (array $s): string {
                return (string) $s['label'];
            }, $suggestions);

            return '验证到了：' . $title . '。系统正在自动执行「' . implode('、', $labels) . '」，完成后会自动复验并把结果写在这里。';
        }

        if ($requiresApproval && $suggestions) {
            return '验证到了：' . $title . '。对应的修复动作风险较高（' . (string) $suggestions[0]['label'] . '），已提交管理员确认，批准后会自动执行。';
        }

        if ($needsManual) {
            return '验证结果：' . $title . '。这类问题需要管理员人工处理，你的反馈已经进入处理队列。';
        }

        if ($suggestions) {
            return '验证到了：' . $title . '。可执行的修复动作：' . (string) $suggestions[0]['label'] . '（自动修复当前被管理员关闭，已通知处理）。';
        }

        return '验证结果：' . $title . '。目前没有可自动执行的修复动作，已转管理员人工处理。';
    }

    /**
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $issues
     * @param array<string,mixed> $server
     */
    private static function operatorHint(array $primary, array $issues, array $server): string
    {
        $parts = [];
        foreach ($issues as $issue) {
            $parts[] = '[' . $issue['severity'] . '] ' . $issue['title'] . '：' . $issue['detail'];
        }
        if (!$parts) {
            $parts[] = '面板侧与机器侧检查都正常，建议向玩家确认客户端版本 / 模组 / 网络情况。';
        }

        $executor = (string) ($server['executor'] ?? 'none');
        if ($executor === 'none') {
            $parts[] = '该服务器未配置 Agent/SSH 执行器，只能做只读诊断。';
        }

        return implode("\n", $parts);
    }
}
