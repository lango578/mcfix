<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 客户端问题判定器：把 ClientLog 解析出的"事实"变成"结论 + 可执行的解决步骤"。
 *
 * 和 Verdict（服务端判定）的分工：
 *   Verdict       —— 服务端哪里出问题了、要不要重启/加白名单
 *   ClientAdvisor —— 玩家自己电脑上哪里出问题了、他自己该点哪里
 *
 * 两类问题会交叉：玩家日志里的错误往往是**服务端造成的**（缺 MOD、插件崩、白名单）。
 * 所以这里会跟服务端清单做比对，能自动修的交给 Fixer，修不了的给出精确到"点哪个按钮"的步骤。
 */
final class ClientAdvisor
{
    /**
     * 主入口。
     *
     * @param array<string,mixed> $server  服务器配置
     * @param array<string,mixed> $parsed  ClientLog::parse() 的结果
     * @param array<string,mixed> $hints   玩家补充信息（可选）
     * @return array<string,mixed>
     */
    public static function evaluate(array $server, array $parsed, array $hints = []): array
    {
        $serverId = (string) ($server['id'] ?? '');
        $serverMods = ServerMods::forServer($server);
        $cross = ServerMods::crossCheck($serverMods, (array) ($parsed['mods'] ?? []));
        $suspects = self::suspectMods($parsed, $serverMods);

        $found = self::detect($server, $parsed, $cross);

        // 交叉比对发现的问题也要报出来
        if (!empty($cross['possible'])) {
            if (!empty($cross['client_extra'])) {
                $found[] = [
                    'code'   => 'modpack_conflict_server',
                    'source' => 'cross_check',
                    'detail' => '你的客户端比服务端多装了 ' . count($cross['client_extra']) . ' 个模组',
                    'extra'  => array_slice($cross['client_extra'], 0, 10),
                ];
            }
            if (!empty($cross['client_missing'])) {
                $found[] = [
                    'code'   => 'missing_client_mod',
                    'source' => 'cross_check',
                    'detail' => '你缺少服务端有的 ' . count($cross['client_missing']) . ' 个文件',
                    /*
                     * 只给数量，不给文件名（V18）。
                     * 这里是服务端 jar 的完整名单，逐字回传等于把"服务端装了哪些
                     * 模组、什么构建号"告诉任何提交日志的人 —— 而列表内容可以通过
                     * 自编日志章节来操纵，所以它能被用来**枚举全部**。
                     */
                    'extra'  => [],
                ];
            }
        }

        // 一个特征都没匹配到：先问问大模型（如果管理员开了），
        // 还不行才退回"补日志 / 转人工"
        $aiIssue = null;
        if (!$found) {
            $aiIssue = AiAdvisor::consult($server, $parsed, $cross);
            if ($aiIssue !== null) {
                $found[] = [
                    'code'     => (string) $aiIssue['code'],
                    'source'   => 'ai',
                    'detail'   => (string) ($aiIssue['detail'] ?? ''),
                    'extra'    => (array) ($aiIssue['extra'] ?? []),
                    // 模型自己写的 title / cause / steps 走 override，
                    // 不覆盖知识库版本（模型点出已知 code 时用的是知识库的）
                    'override' => $aiIssue,
                ];
            }
        }

        if (!$found) {
            // 只有"又短又少行"才算真的没信息。原来是 `||`：一份 40 行、
            // 1826 字节的崩溃报告会因为字节数没到 2000 被判成"日志内容太短，
            // 无法定位" —— 文案和证据自相矛盾。长但认不出来的日志归 unknown，
            // 它的文案是准确的（"没有匹配到已知的错误特征"）。
            $bytes = (int) ($parsed['bytes'] ?? 0);
            $lines = (int) ($parsed['lines'] ?? 0);
            $kind  = (string) ($parsed['kind'] ?? 'unknown');

            // 已经确认是崩溃报告的，说明玩家把报告交上来了 —— 哪怕它短，
            // 也不该回他"请把完整的崩溃报告补发一次"，他交的就是完整的。
            // 短日志判定只针对没法确认类型的文本片段（多半是从截图里抄了几行）。
            $short = $kind !== 'crash_report' && $bytes < 2000 && $lines < 15;
            $found[] = [
                'code'   => $short ? 'need_log' : 'unknown',
                'source' => 'fallback',
                'detail' => $short ? '日志内容太短，无法定位' : '日志里没有匹配到已知的错误特征',
                'extra'  => [],
            ];
        }

        // 整理成最终结构
        $issues = [];
        $seenCodes = [];
        foreach ($found as $item) {
            $code = (string) ($item['code'] ?? '');
            if ($code === '' || isset($seenCodes[$code])) {
                continue;
            }
            $seenCodes[$code] = true;
            $def = ClientIssue::get($code);
            if ($def === null) {
                continue;
            }

            // 大模型兜底时，标题/原因/步骤用模型给的（模板只是保底）
            $override = is_array($item['override'] ?? null) ? $item['override'] : [];

            $issues[] = [
                'code'       => $code,
                'title'      => (string) ($override['title'] ?? $def['title']),
                'severity'   => (string) ($override['severity'] ?? $def['severity']),
                'cause'      => (string) ($override['cause'] ?? $def['cause']),
                'steps'      => array_values((array) ($override['steps'] ?? $def['steps'])),
                'detail'     => mb_substr((string) ($item['detail'] ?? ''), 0, 400),
                'extra'      => array_values((array) ($item['extra'] ?? [])),
                'source'     => (string) ($item['source'] ?? 'signal'),
                // 注意：这里传的是**知识库那条**的 recipes，不是模型给的。
                // 模型碰不到执行链，这是有意的。
                'server_fix' => self::serverFixPlan($server, (array) $def['recipes']),
            ];
        }

        usort($issues, static function (array $a, array $b): int {
            return ClientIssue::severityRank((string) $a['severity']) <=> ClientIssue::severityRank((string) $b['severity']);
        });

        $primary = $issues[0];

        // 汇总玩家必需的东西
        $needs = self::collectNeeds($primary, $suspects, $serverMods, $cross);

        return [
            'ok'            => true,
            'evaluated_at'  => now(),
            'headline'      => self::headline($primary, $issues),
            'severity'      => (string) $primary['severity'],
            'primary_code'  => (string) $primary['code'],
            'issues'        => $issues,
            'needs'         => $needs,
            'facts'         => self::facts($parsed),
            // 后台要能一眼看出"这条结论是规则给的还是模型给的"
            'ai_used'       => $aiIssue !== null,
            'ai_code'       => $aiIssue !== null ? (string) ($aiIssue['matched_code'] ?? '') : '',
            'server_mods'   => [
                'available' => !empty($serverMods['available']),
                'count'     => (int) ($serverMods['count'] ?? 0),
                'generated' => (string) ($serverMods['generated_at'] ?? ''),
            ],
            'cross_check'   => $cross,
            'suspects'      => $suspects,
            'server_id'     => $serverId,
        ];
    }

    /**
     * 特征 → 问题码。顺序有意义：越靠前的越具体，先匹配到的赢。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $cross
     * @return array<int,array<string,mixed>>
     */
    private static function detect(array $server, array $parsed, array $cross): array
    {
        $signals = array_flip((array) ($parsed['signals'] ?? []));
        $meta = (array) ($parsed['meta'] ?? []);
        $has = static function (string $key) use ($signals): bool {
            return isset($signals[$key]);
        };

        $found = [];
        $add = static function (string $code, string $detail, array $extra = [], string $source = 'signal') use (&$found): void {
            $found[] = ['code' => $code, 'detail' => $detail, 'extra' => $extra, 'source' => $source];
        };

        $missingClasses = (array) ($parsed['missing_classes'] ?? []);
        $packages = (array) ($parsed['suspect_packages'] ?? []);
        $exceptions = (array) ($parsed['exceptions'] ?? []);
        $problematic = (array) ($parsed['problematic'] ?? []);

        // ---------------------------------------------------------- 运行环境（最优先，因为最好修）
        if ($has('oom')) {
            $add('oom', '日志里出现了 OutOfMemoryError' . (isset($meta['memory']) ? '，当前内存设置：' . $meta['memory'] : ''));
        }

        if ($has('java_version')) {
            $javaHint = '';
            if (preg_match('/UnsupportedClassVersionError.*?(\d{2,3})/', implode("\n", (array) ($parsed['stack'] ?? [])), $m)) {
                $need = (int) $m[1] - 44;
                $javaHint = '，你需要 Java ' . $need;
            }
            $add('java_version', '客户端 Java 版本不满足要求' . $javaHint . (isset($meta['java']) ? '（当前 ' . $meta['java'] . '）' : ''));
        }

        if ($has('graphics_driver') && ($has('render_error') || !empty($problematic))) {
            $add('graphics_driver', '崩溃点在 OpenGL / 渲染初始化阶段');
        }

        // 显卡驱动 / OpenGL 上下文初始化失败：这一条单独出现也足以定论，
        // 不需要再等 render_error 或 Problematic frame。
        // 原来它必须搭上后两者之一才生效，于是"Failed to create window"
        // 这种明摆着的驱动问题反倒什么结论都不给。
        if ($has('gpu_init_failed')) {
            $add('graphics_driver', 'OpenGL 上下文 / 显卡驱动初始化失败');
        }

        // ---------------------------------------------------------- 加载器与版本
        if ($has('loader_mismatch')) {
            $code = 'loader_mismatch';
            $raw = mb_strtolower(implode(' ', (array) ($meta['loader_raw'] ?? [])));
            if (strpos($raw, 'neoforge') !== false) {
                $code = 'neoforge_mismatch';
            } elseif (strpos($raw, 'forge') !== false) {
                $code = 'forge_mismatch';
            } elseif (strpos($raw, 'fabric') !== false) {
                $code = 'fabric_loader_mismatch';
            }
            $add($code, '日志里出现了加载器版本校验相关的报错');
        }

        if ($has('version_mismatch')) {
            $add('version_mismatch', '服务端与客户端版本不一致' . (isset($meta['minecraft_version']) ? '（你这边是 ' . $meta['minecraft_version'] . '）' : ''));
        }

        // ---------------------------------------------------------- MOD 相关
        if ($has('missing_dep')) {
            $deps = self::extractDependencies((array) ($parsed['stack'] ?? []));
            $add('missing_dependency', '某个 MOD 的前置库缺失', $deps ? array_slice($deps, 0, 10) : []);
        }

        if ($has('mixin_failed')) {
            $mods = self::extractModNamesFromMixins((array) ($parsed['stack'] ?? []));
            $add('mixin_failed', 'Mixin 注入失败', array_slice($mods, 0, 8));
        }

        if ($has('mod_incompatible')) {
            $add('mod_incompatible', '检测到 MOD 之间的兼容性冲突');
        }

        if ($has('mod_load_error')) {
            $mods = self::extractModNamesFromStack((array) ($parsed['stack'] ?? []));
            $add('mod_load_error', '某个 MOD 在加载阶段抛出异常', array_slice($mods, 0, 8));
        }

        if ($has('class_not_found')) {
            $short = [];
            foreach (array_slice($missingClasses, 0, 6) as $class) {
                $short[] = $class;
            }
            $add('class_not_found', '运行期找不到某个类', $short);
        }

        if ($has('method_not_found')) {
            $add('method_not_found', 'MOD 与游戏版本不匹配（方法签名对不上）');
        }

        // ---------------------------------------------------------- 网络
        foreach (['connection_refused', 'unknown_host', 'timed_out', 'reset_by_peer', 'ssl_error', 'upstream_error'] as $networkSignal) {
            if ($has($networkSignal)) {
                $add($networkSignal, self::networkDetail($networkSignal));
            }
        }

        // 登录会话失效 / 正版验证没过。这个信号一直都在扫，但 detect() 里
        // 从来没有对应分支，于是它是个死信号 —— 特征认出来了，照样兜底。
        if ($has('auth_failed')) {
            $add('auth_failed', '登录会话失效或正版验证没通过');
        }

        // ---------------------------------------------------------- 渲染 / 数据包
        // 光影与渲染类模组的痕迹。用 normal 严重度：它通常是"帮凶"，
        // 让更具体的结论（方法签名对不上、类找不到）排在前面当主结论。
        if ($has('ccompat_error')) {
            $add('ccompat_error', '日志里出现了光影 / 渲染类模组的痕迹');
        }

        if ($has('datapack_error')) {
            $add('datapack_error', '日志里出现了数据包加载失败');
        }

        // ---------------------------------------------------------- 服务端原因
        //
        // 措辞刻意克制：这几条的依据是"日志里出现了某种措辞"，不等价于
        // "服务端确实对你做了这件事"。以前写的是"服务端**明确提示**你不在白名单里"，
        // 把一句文本匹配讲成了断言 —— 实测里那条日志其实只是在提"白名单"三个字。
        // 玩家会照着这个结论去做没用的事，所以宁可说"日志里出现了…"。
        if ($has('whitelist_kick')) {
            $add('not_whitelisted', '日志里出现了「不在白名单」的提示', [], 'server_side');
        }
        if ($has('banned_kick')) {
            $add('banned', '日志里出现了「账号被封禁」的提示', [], 'server_side');
        }
        if ($has('server_full_kick')) {
            $add('server_full', '日志里出现了「服务器人数已满」的提示', [], 'server_side');
        }
        if ($has('server_side_only') || ($has('plugin_error') && $has('server_side_only'))) {
            $add('server_side_error', '报错来自服务端的插件 / 服务端 MOD', [], 'server_side');
        } elseif ($has('plugin_error')) {
            $add('plugin_error', '日志里出现了服务端插件异常', [], 'server_side');
        }
        if ($has('chunk_corrupt')) {
            $add('chunk_corrupt', '日志里出现了坏区块 / 区域文件异常', [], 'server_side');
        }

        // ---------------------------------------------------------- 兜底：客户端崩溃
        if (!$found && ($has('crash_client') || $has('exit_code_1') || $problematic)) {
            $mods = array_merge(
                self::extractModNamesFromStack((array) ($parsed['stack'] ?? [])),
                self::extractModNamesFromMixins((array) ($parsed['stack'] ?? []))
            );
            $add('client_crash', '客户端进程异常退出', array_slice(array_values(array_unique($mods)), 0, 8));
        }

        if ($has('affected_level')) {
            $add('affected_level', '崩溃报告包含 Affected level 段落，问题与存档 / 区块相关');
        }

        if ($has('render_error') && !$has('graphics_driver')) {
            $add('render_error', '崩溃发生在渲染线程');
        }

        // 异常类型补充说明（让 detail 更有信息量）
        foreach ($found as $i => $item) {
            if (!empty($exceptions) && $item['detail'] === '') {
                $found[$i]['detail'] = '主要异常：' . implode('、', array_slice(array_keys($exceptions), 0, 3));
            }
        }

        return $found;
    }

    /**
     * 把配方 code 列表转成"服务端能不能自动修"的计划。
     *
     * @param array<string,mixed> $server
     * @param string[] $codes
     * @return array<string,mixed>
     */
    private static function serverFixPlan(array $server, array $codes): array
    {
        $plan = ['codes' => [], 'labels' => [], 'auto' => false, 'needs_approval' => false, 'blocked' => []];

        // 没有绑定服务器的工单（纯客户端报错）：不存在任何"可执行的服务端动作"。
        //
        // 为什么必须在这拦：Recipe::allowed([]) 在"没配禁用名单"时返回 true，
        // 于是会给出"重启服务端"之类的建议，auto_fixable 也可能被算成 true。
        // 一路走到 applyFix() 才发现服务器不存在 —— 结果是记一次失败、
        // 白白消耗工单的修复次数，玩家看到一句莫名其妙的"服务器不存在"。
        // 从这里返回空计划，客户端分析和合并后的结论就都不会带上服务端动作。
        if (trim((string) ($server['id'] ?? '')) === '') {
            return $plan;
        }

        foreach ($codes as $code) {
            $def = Recipe::get((string) $code);
            if ($def === null) {
                continue;
            }
            if (!Recipe::allowed($server, (string) $code)) {
                $plan['blocked'][] = (string) $def['label'] . '（已被服务器配置禁用）';
                continue;
            }
            $plan['codes'][] = (string) $code;
            $plan['labels'][] = (string) $def['label'];
            if (Recipe::needsApproval($server, (string) $code)) {
                $plan['needs_approval'] = true;
            }
        }

        $executor = (string) ($server['executor'] ?? 'none');
        $plan['auto'] = $plan['codes'] !== []
            && !$plan['needs_approval']
            && (bool) Config::get('automation.auto_fix', true)
            && ($executor !== 'none' || Rcon::enabled($server) || PanelRegistry::adapter($server) !== null);

        return $plan;
    }

    /**
     * 玩家需要拿到的东西（要装的 MOD / 要下的文件）。
     *
     * 名字来源**只**取玩家自己日志/报错里提到过的（$primary['extra']、
     * $suspects），不取 $cross['client_missing'] —— 那份清单是服务端的文件名单，
     * 逐字回传等于让人枚举服务端装了哪些模组（V18）。$cross 保留在参数里是因为
     * 调用方语义上仍处在"交叉比对"这一步，且将来若要做"只给数量"的展示会用到它。
     *
     * 残留风险（写清楚，别当成已经堵死）：$suspects 里的包名会经
     * ServerMods::matchSuspects() 与服务端 jar 名单做**子串匹配**，
     * 命中时回传的是服务端那份**完整文件名**（含构建号）。所以能靠"猜名字 +
     * 一条伪造日志"逐个**确认**服务端装了什么，只是没法像 client_missing 那样
     * 一次拿到全量。有意的取舍：玩家要装的正是服务端那份文件，名字不归一到
     * 服务端的文件名，下载链接就无从给出。
     *
     * @param array<string,mixed> $primary
     * @param string[] $suspects
     * @param array<string,mixed> $serverMods
     * @param array<string,mixed> $cross   仅供将来参考，当前实现**不**从中取文件名
     * @return array<string,mixed>
     */
    private static function collectNeeds(array $primary, array $suspects, array $serverMods, array $cross): array
    {
        $names = [];

        /*
         * ★ 这里**不能**把 client_missing 直接当成"玩家要装的东西"（V18）。
         *
         * client_missing 的每一个字符串都是**服务端的 jar 文件名**（见
         * ServerMods::crossCheck 第 183-189 行：服务端清单里有、玩家日志里没有的
         * 全部进这个列表）。而"玩家日志里有什么"是提交者自己编的 —— 章节名走的是
         * stripos 匹配「Mods」这类词，随便造一节假 mod 列表，就能让服务端清单里
         * **每一个**文件都落进 client_missing。
         *
         * 于是接着走下面的 ModLibrary::resolve($name, $serverMods) 时，每个名字都
         * 在服务端清单里找到自己 → 全部标成 available=true 并带上下载地址 ——
         * 等于匿名请求就能枚举整份服务端模组清单（精确到构建号）。
         *
         * 所以只保留"玩家自己在日志/报错里确实提到过"的名字（$primary['extra']
         * 和 $suspects）。那才是他真正缺的东西，而且由他自己的日志决定，
         * 别人套不出服务端装了啥。
         */
        // 前置库 / Mixin 报错点出来的组件
        foreach ((array) ($primary['extra'] ?? []) as $item) {
            $item = trim((string) $item);
            if ($item !== '' && !preg_match('/^\d+$/', $item)) {
                $names[] = $item;
            }
        }
        foreach ($suspects as $item) {
            $names[] = (string) $item;
        }

        $names = array_values(array_unique(array_filter($names, static function ($n): bool {
            return is_string($n) && trim($n) !== '';
        })));

        $needs = [
            'components' => [],
            'have_local' => false,
            'unavailable'=> false,
        ];

        $limit = 0;
        foreach ($names as $name) {
            if ($limit++ >= 12) {
                break;
            }
            $resolved = ModLibrary::resolve($name, $serverMods);

            $entry = [
                'name'      => mb_substr($name, 0, 120),
                'available' => false,
                'download'  => '',
                'is_local'  => false,
                'size'      => 0,
                'sha256'    => '',
                'note'      => (string) ($resolved['note'] ?? ''),
                'search'    => 'https://modrinth.com/mods?q=' . rawurlencode(ModLibrary::modKey($name)),
            ];

            if (!empty($resolved['found'])) {
                $entry['available'] = true;
                $entry['is_local'] = (bool) ($resolved['is_local'] ?? false);
                $entry['size'] = (int) ($resolved['size'] ?? 0);
                $entry['sha256'] = (string) ($resolved['sha256'] ?? '');
                $entry['filename'] = (string) ($resolved['filename'] ?? '');
                if ($entry['is_local']) {
                    $needs['have_local'] = true;
                }
            }

            $needs['components'][] = $entry;
        }

        // 既不在本地库、服务端也没有 → 明确告诉管理员"需要我们把 MOD 放到服务器 mods 目录"
        foreach ($needs['components'] as $component) {
            if (!$component['available']) {
                $needs['unavailable'] = true;
                break;
            }
        }

        return $needs;
    }

    /**
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $issues
     */
    private static function headline(array $primary, array $issues): string
    {
        $more = count($issues) > 1 ? '（另有 ' . (count($issues) - 1) . ' 个相关问题）' : '';

        return '检测到客户端问题：' . (string) $primary['title'] . $more;
    }

    /**
     * 给玩家看的"我这边是什么环境"。
     *
     * @param array<string,mixed> $parsed
     * @return array<int,array<string,string>>
     */
    private static function facts(array $parsed): array
    {
        $meta = (array) ($parsed['meta'] ?? []);
        $facts = [
            ['label' => '提交内容', 'value' => (string) ($parsed['kind_label'] ?? '')],
        ];

        $map = [
            'minecraft_version' => '游戏版本',
            'loader'            => '加载器',
            'loader_version'    => '加载器版本',
            'java'              => 'Java',
            'memory'            => '内存设置',
            'launcher'          => '启动器',
            'mod_count'         => 'MOD 数量',
            'os'                => '操作系统',
            'description'       => '崩溃描述',
        ];

        foreach ($map as $key => $label) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                $facts[] = ['label' => $label, 'value' => mb_substr($value, 0, 120)];
            }
        }

        $loader = trim((string) ($meta['loader'] ?? '') . ' ' . (string) ($meta['loader_version'] ?? ''));
        if (!empty($facts[1]) && $facts[1]['label'] === '加载器' && $loader !== '') {
            $facts[1]['value'] = $loader;
        }

        return $facts;
    }

    /**
     * 可疑 MOD：优先用服务端清单里真实存在的名字，其次用日志里的包名。
     *
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $serverMods
     * @return string[]
     */
    private static function suspectMods(array $parsed, array $serverMods): array
    {
        $names = array_merge(
            self::extractModNamesFromMixins((array) ($parsed['stack'] ?? [])),
            self::extractModNamesFromStack((array) ($parsed['stack'] ?? []))
        );

        // 用包名去服务端清单里找对应文件（更可信）
        $fromServer = ServerMods::matchSuspects($serverMods, (array) ($parsed['suspect_packages'] ?? []));
        foreach ($fromServer as $file) {
            $names[] = (string) $file;
        }

        return array_slice(array_values(array_unique(array_filter($names))), 0, 12);
    }

    /**
     * 从堆栈里捞 MOD 名：常见形态是 at com.example.mymod.SomeClass(...)
     *
     * @param string[] $stack
     * @return string[]
     */
    private static function extractModNamesFromStack(array $stack): array
    {
        $names = [];
        $skip = '/^(net\.minecraft|java|javax|sun|jdk|com\.mojang|org\.spongepowered|io\.netty|org\.apache|com\.google|it\.unimi|org\.lwjgl|net\.fabricmc\.loader|cpw\.mods\.modlauncher|org\.objectweb|net\.minecraftforge\.fml)/i';

        foreach ($stack as $line) {
            if (!preg_match('/\bat\s+([a-z][a-z0-9_]{1,24}(?:\.[a-z0-9_]{1,28}){1,5})\./i', $line, $m)) {
                continue;
            }
            $package = $m[1];
            if (preg_match($skip, $package)) {
                continue;
            }
            $segments = explode('.', $package);
            // 取最有辨识度的一段（通常第 2~3 段是项目名）
            $candidate = $segments[count($segments) > 2 ? count($segments) - 2 : 0];
            if (strlen($candidate) >= 3 && !in_array($candidate, ['common', 'core', 'api', 'util', 'client', 'server', 'impl', 'main', 'mod'], true)) {
                $names[] = $candidate;
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 8);
    }

    /**
     * 从 Mixin 报错里捞 MOD 名：Mixin apply for mod xxx failed。
     *
     * @param string[] $stack
     * @return string[]
     */
    private static function extractModNamesFromMixins(array $stack): array
    {
        $text = implode("\n", $stack);
        $names = [];

        foreach ([
            '/Mixin apply(?: for mod)?\s+([a-z0-9_\-\.]{2,64})\s+failed/i',
            '/mixins?\.([a-z0-9_\-]{2,64})\.json/i',
            '/from mod\s+([a-z0-9_\-\.]{2,64})/i',
            '/mod\s+([a-z0-9_\-\.]{2,64})\s+\(([^)]+)\)\s+failed/i',
        ] as $regex) {
            if (preg_match_all($regex, $text, $m)) {
                foreach ($m[1] as $name) {
                    $names[] = trim($name);
                }
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 8);
    }

    /**
     * 从 "requires X which is missing" 里抽出前置库名。
     *
     * @param string[] $stack
     * @return string[]
     */
    private static function extractDependencies(array $stack): array
    {
        $text = implode("\n", $stack);
        $deps = [];

        foreach ([
            '/requires\s+([a-z0-9_\-\. ]{2,48}?)\s+which is missing/i',
            '/Missing or unsupported mandatory dependencies?:?\s*(.+)$/im',
            '/Mod\s+([a-z0-9_\-]{2,48})\s+requires\s+([a-z0-9_\-]{2,48})/i',
            '/Missing dependency:?\s*([a-z0-9_\-\.]{2,48})/i',
        ] as $regex) {
            if (preg_match_all($regex, $text, $m)) {
                $group = count($m) > 2 ? 2 : 1;
                foreach ($m[$group] as $name) {
                    foreach (preg_split('/[,;、]/', (string) $name) ?: [] as $part) {
                        $part = trim($part);
                        if ($part !== '' && strlen($part) <= 60) {
                            $deps[] = $part;
                        }
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($deps)), 0, 10);
    }

    private static function networkDetail(string $signal): string
    {
        switch ($signal) {
            case 'connection_refused':
                return 'TCP 握手被拒绝，目标端口没有服务在监听';
            case 'unknown_host':
                return '域名无法解析成 IP 地址';
            case 'timed_out':
                return '连接建立后长时间没有数据返回';
            case 'reset_by_peer':
                return '连接被链路上的某一端强制中断';
            case 'ssl_error':
                return 'TLS 握手 / 证书校验失败';
            case 'upstream_error':
                return '网络包解析异常，连接被中断';
            default:
                return '网络层面出现异常';
        }
    }

    /**
     * 给管理员的单行摘要。
     *
     * @param array<string,mixed> $analysis
     */
    public static function adminSummary(array $analysis): string
    {
        if (empty($analysis['ok'])) {
            return '未分析';
        }

        $parts = [(string) ($analysis['headline'] ?? '')];
        $mods = (array) ($analysis['server_mods'] ?? []);
        if (empty($mods['available'])) {
            $parts[] = '（服务端 MOD 清单未上报，无法交叉比对）';
        } else {
            $cross = (array) ($analysis['cross_check'] ?? []);
            if (!empty($cross['possible'])) {
                $parts[] = sprintf(
                    '（比对：客户端 %d 个 / 服务端 %d 个，多装 %d，缺 %d）',
                    (int) $cross['client_count'],
                    (int) $cross['server_count'],
                    count((array) $cross['client_extra']),
                    count((array) $cross['client_missing'])
                );
            }
        }

        return implode('', $parts);
    }
}
