<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 客户端日志的「大模型兜底」。
 *
 * 定位：**只在规则库没命中时才出场**。
 *
 *   ClientLog 解析出事实 → ClientAdvisor 拿 37 条知识库匹配
 *        ├─ 命中 → 用知识库的结论（快、免费、可复现、可审计）
 *        └─ 没命中（以前直接"转人工"）→ 问一次大模型，给玩家一个人话结论
 *
 * 五条不可妥协的边界：
 *
 *   1. **模型只出结论，不出命令。**
 *      它可以让玩家去装某个 MOD、去改内存设置；但**它给出的配方建议永远不会被执行**。
 *      唯一能让系统动手的路径是：模型点出了知识库里已有的 code，
 *      然后我们用**自己写的那条知识库**（含我们自己审过的 recipes）——模型碰不到执行链。
 *
 *   2. **发出去之前先脱敏。** 玩家名、IP、绝对路径、启动器用户名都会被替换掉。
 *      崩溃日志里这些东西到处都是，直接发出去等于把玩家信息送给第三方。
 *
 *   3. **有超时、会降级。** 拿不到结果就退回原来的"转人工"，绝不让模型卡住工单。
 *
 *   4. **按特征缓存。** 同一类报错只问一次，同一份日志重复提交不会反复花钱。
 *
 *   5. **有关闸和限额。** 每个 IP 每小时最多问几次，全局也有上限，防止被刷爆账单。
 *
 * 接口用的是 OpenAI 兼容的 `/v1/chat/completions` —— 所以 DeepSeek、OpenAI、通义、
 * 硅基流动、以及本地的 Ollama / vLLM / LM Studio 都是同一套配置，只换 base_url。
 */
final class AiAdvisor
{
    /** 知识库没命中时用的兜底 code（本身也写在知识库里，方便统一渲染） */
    public const FALLBACK_CODE = 'ai_suggestion';

    /** 同一份日志指纹的结果缓存 7 天 */
    private const CACHE_TTL = 604800;

    /** 全局每小时最多调用多少次（防账单失控） */
    private const GLOBAL_HOURLY_LIMIT = 60;

    /**
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $raw = Config::get('ai', []);

        return array_merge([
            'enabled'      => false,
            'base_url'     => '',
            'api_key'      => '',
            'model'        => '',
            // 默认 6 秒：这段等待是**玩家在页面上等着的**（诊断链路本来就 5~20 秒），
            // 再长体验就崩了。拿不到结果就退回"转人工"，不影响工单。
            'timeout'      => 6,
            'max_tokens'   => 700,
            'temperature'  => 0.2,
            'per_ip_hourly'=> 5,
        ], is_array($raw) ? $raw : []);
    }

    public static function enabled(): bool
    {
        $s = self::settings();

        return !empty($s['enabled'])
            && trim((string) $s['base_url']) !== ''
            && trim((string) $s['model']) !== '';
    }

    /**
     * 问一次模型。拿不到结果（没开 / 超时 / 报错 / 被限额）就返回 null，
     * 调用方照旧走"转人工"。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $parsed ClientLog::parse() 的结果
     * @param array<string,mixed> $cross  ServerMods::crossCheck() 的结果
     * @return array<string,mixed>|null 形如 ['code'=>..,'title'=>..,'cause'=>..,'steps'=>[..],'detail'=>..]
     */
    public static function consult(array $server, array $parsed, array $cross = []): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        $settings = self::settings();

        // 全局闸：宁可这次不答，也不能被刷爆账单
        $global = Rate::peek('ai:global');
        if ((int) $global['count'] >= self::GLOBAL_HOURLY_LIMIT) {
            app_log('warn', '大模型调用已达每小时上限，本次跳过', ['count' => $global['count']]);

            return null;
        }

        // 按 IP 闸
        $perIp = max(1, (int) $settings['per_ip_hourly']);
        $ipKey = 'ai:ip:' . client_ip_hash();
        if (Rate::blockedFor($ipKey, $perIp, 3600) > 0) {
            app_log('info', '该来源今天问大模型问得有点多，本次跳过');

            return null;
        }

        $fingerprint = self::fingerprint($parsed);
        $cacheKey = 'ai:' . $fingerprint;
        $cached = Cache::get($cacheKey, null);
        if (is_array($cached)) {
            return $cached['empty'] ?? false ? null : $cached;
        }

        Rate::hit($ipKey, $perIp, 3600);
        Rate::hit('ai:global', self::GLOBAL_HOURLY_LIMIT, 3600);

        $result = self::ask($server, $parsed, $cross, $settings);

        // 结果（包括"没结论"）都缓存：同一份日志不该重复问
        Cache::put($cacheKey, $result ?? ['empty' => true], self::CACHE_TTL);

        return $result;
    }

    // ============================================================ 服务器侧会诊
    //
    // 客户端那条路的边界是"模型只出结论、不出命令"（ClientAdvisor 用的是知识库的配方）。
    // 服务器这条路的边界稍微不同：模型**可以指一个动作**，但只能从我们给的清单里指，
    // 而且它指的编号要原封不动地再过一遍完整闸门（白名单 / 服务器是否允许 / 参数来源 / 审批）。
    //
    // 换句话说：模型在这里是一个"选择题答题者"，不是"下命令的人"。

    /** 服务器侧兜底结论的 issue 码 */
    public const SERVER_FALLBACK_CODE = 'ai_server_suggestion';

    /**
     * 大模型**不允许**挑的配方。
     *
     * 单独列出来是因为：清单里有些动作被挑错的后果不是"没修好"，而是"修坏了"
     * 或者"开了不该开的口子"。这两条都属于这一类。
     */
    private const FORBIDDEN_RECIPES = [
        // 安全影响：被封禁的玩家提交一张工单，就可能诱导模型把自己解封
        'unban_player',
        // 破坏性：清空玩家背包，误判等于直接毁掉玩家数据
        'clear_self_items',
    ];

    /**
     * 服务器侧规则库没给出任何结论时，让模型从白名单里挑一个动作。
     *
     * 返回全新的 verdict；任何一步不通过就原样返回传入的 verdict（等于没发生）。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $checks
     * @param array<string,mixed> $verdict
     * @return array<string,mixed>
     */
    public static function augmentServerVerdict(
        array $server,
        string $category,
        array $feedback,
        array $checks,
        array $verdict
    ): array {
        if (!self::enabled()) {
            return $verdict;
        }

        $player = (string) ($feedback['player_name'] ?? '');
        $categoryDef = Catalog::category($category) ?? Catalog::category('other');
        $allowRecipes = (array) ($categoryDef['allow_recipes'] ?? []);

        // 可选动作 = 这台服务器真能执行、参数来源明确的配方，再减去不让模型碰的
        $selectable = Verdict::selectableRecipes($server, $player, $allowRecipes);
        foreach (self::FORBIDDEN_RECIPES as $blocked) {
            unset($selectable[$blocked]);
        }

        // 一个动作都挑不了就别问了：省一次调用，也免得模型说一堆做不到的话
        if ($selectable === []) {
            return $verdict;
        }

        $pick = self::consultServer($server, $feedback, $checks, $selectable);
        if ($pick === null) {
            return $verdict;
        }

        $code = (string) ($pick['recipe'] ?? '');

        // ★★★ 整条链路最关键的一步：模型返回的编号必须真的在我们给它的清单里。
        //     不在就整条丢弃 —— 这样模型再怎么被提示注入，也造不出清单外的动作。
        if ($code === '' || !isset($selectable[$code])) {
            app_log('warn', '大模型挑了清单外的配方，已丢弃', [
                'picked'   => $code,
                'offered'  => array_keys($selectable),
            ]);

            return $verdict;
        }

        // 清单命中还不够，再走一遍完整闸门（白名单 / Recipe::allowed / 参数 / 审批）
        $suggestion = Verdict::suggestOne($server, $code, $player, $allowRecipes);
        if ($suggestion === null) {
            app_log('warn', '大模型挑的配方没通过校验，已丢弃', ['picked' => $code]);

            return $verdict;
        }

        $title = trim((string) ($pick['title'] ?? ''));
        $cause = trim((string) ($pick['cause'] ?? ''));
        $model = (string) self::settings()['model'];

        $primary = [
            'code'         => self::SERVER_FALLBACK_CODE,
            'severity'     => self::normalizeSeverity((string) ($pick['severity'] ?? 'normal')),
            'title'        => $title !== '' ? mb_substr($title, 0, 80) : (string) $suggestion['label'],
            'detail'       => mb_substr(
                $cause !== '' ? $cause : '规则库没有命中，由大模型在可执行动作里挑了一个。',
                0,
                400
            ),
            'source'       => 'ai',
            'recipe_hints' => [$code],
        ];

        // 最终判定交给 Verdict::assemble —— 和规则库那条路是同一段代码
        $out = Verdict::assemble($server, $category, $primary, [], [$suggestion]);
        $out['ai_server_used']  = true;
        $out['ai_server_code']  = $code;
        $out['ai_server_model'] = $model;

        record_event(
            isset($feedback['id']) ? (int) $feedback['id'] : null,
            'system',
            'ai.server_pick',
            sprintf('服务器侧规则库未命中，大模型挑了一个动作：%s', (string) $suggestion['label']),
            ['recipe' => $code, 'model' => $model],
            'warn'
        );

        return $out;
    }

    /**
     * 服务器侧的限额与缓存，然后发请求。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $checks
     * @param array<string,array<string,string>> $selectable 允许模型挑的配方
     * @return array<string,mixed>|null
     */
    private static function consultServer(
        array $server,
        array $feedback,
        array $checks,
        array $selectable
    ): ?array {
        $settings = self::settings();

        $global = Rate::peek('ai:global');
        if ((int) $global['count'] >= self::GLOBAL_HOURLY_LIMIT) {
            app_log('warn', '大模型调用已达每小时上限，服务器侧本次跳过', ['count' => $global['count']]);

            return null;
        }

        $perIp = max(1, (int) $settings['per_ip_hourly']);
        $ipKey = 'ai:ip:' . client_ip_hash();
        if (Rate::blockedFor($ipKey, $perIp, 3600) > 0) {
            app_log('info', '该来源问大模型问得有点多，服务器侧本次跳过');

            return null;
        }

        $facts = self::serverFacts($feedback, $checks);

        // 指纹只看"哪些检查不正常"和分类 —— 同一台机器同一类故障只问一次
        $fingerprint = hash('sha256', 'srv|'
            . (string) ($feedback['server_id'] ?? '') . '|'
            . (string) ($feedback['category'] ?? '') . '|'
            . json_encode(array_column($facts['failed'], 'code')));

        $cacheKey = 'ai:srv:' . $fingerprint;
        $cached = Cache::get($cacheKey, null);
        if (is_array($cached)) {
            return ($cached['empty'] ?? false) ? null : $cached;
        }

        Rate::hit($ipKey, $perIp, 3600);
        Rate::hit('ai:global', self::GLOBAL_HOURLY_LIMIT, 3600);

        $result = self::askServer($facts, $selectable, $settings);

        // "没结论"也缓存：同一台机器的同一类故障不该反复问
        Cache::put($cacheKey, $result ?? ['empty' => true], self::CACHE_TTL);

        return $result;
    }

    /**
     * 把诊断结果整理成给模型看的事实。
     *
     * 只给结论性的东西（哪一项不正常、原话是什么），不给命令、不给凭据。
     *
     * @param array<string,mixed> $feedback
     * @param array<string,mixed> $checks
     * @return array<string,mixed>
     */
    private static function serverFacts(array $feedback, array $checks): array
    {
        $failed = [];
        $passed = [];
        $unknown = [];

        foreach ($checks as $code => $check) {
            $code = (string) $code;
            $status = (string) ($check['status'] ?? 'unknown');

            if ($status === 'pass') {
                $passed[] = $code;
                continue;
            }

            $entry = [
                'code'    => $code,
                'status'  => $status,
                'message' => mb_substr(self::redact((string) ($check['message'] ?? '')), 0, 200),
            ];

            if ($status === 'fail' || $status === 'warn') {
                $failed[] = $entry;
            } else {
                $unknown[] = $code;
            }
        }

        return [
            'category' => (string) ($feedback['category'] ?? ''),
            'message'  => mb_substr(self::redact((string) ($feedback['message'] ?? '')), 0, 300),
            'player'   => (string) ($feedback['player_name'] ?? ''),
            'failed'   => $failed,
            'passed'   => $passed,
            'unknown'  => $unknown,
        ];
    }

    /**
     * @param array<string,mixed> $facts
     * @param array<string,array<string,string>> $selectable
     * @param array<string,mixed> $settings
     * @return array<string,mixed>|null
     */
    private static function askServer(array $facts, array $selectable, array $settings): ?array
    {
        $payload = [
            'model'       => (string) $settings['model'],
            'temperature' => (float) $settings['temperature'],
            'max_tokens'  => max(200, min(2000, (int) $settings['max_tokens'])),
            'messages'    => [
                ['role' => 'system', 'content' => self::serverSystemPrompt($selectable)],
                ['role' => 'user', 'content' => self::serverUserPrompt($facts)],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $url = self::endpoint((string) $settings['base_url']);

        $headers = ['Content-Type' => 'application/json'];
        $key = trim((string) $settings['api_key']);
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $timeout = max(3, min(30, (int) $settings['timeout']));
        $started = microtime(true);
        $response = self::http($url, $headers, $payload, $timeout);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (empty($response['ok'])) {
            app_log('warn', '大模型调用失败（服务器侧）', [
                'error' => (string) $response['error'],
                'ms'    => $ms,
                'url'   => mask_secret_url($url),
            ]);

            return null;
        }

        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            app_log('warn', '大模型返回的不是 JSON（服务器侧）', ['ms' => $ms]);

            return null;
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if (trim($content) === '') {
            app_log('warn', '大模型返回了空内容（服务器侧）', ['ms' => $ms]);

            return null;
        }

        return self::parseRecipeAnswer($content, $ms);
    }

    /**
     * 解析服务器侧的回答。
     *
     * 这里**不做**"编号是否可用"的判断 —— 那是调用方拿着清单去比对的事。
     * 这一层只负责把 JSON 拆干净。
     *
     * @return array<string,mixed>|null
     */
    private static function parseRecipeAnswer(string $content, int $ms): ?array
    {
        $json = json_decode(trim($content), true);

        if (!is_array($json) && preg_match('/\{.*\}/s', $content, $m)) {
            $json = json_decode($m[0], true);
        }
        if (!is_array($json)) {
            app_log('warn', '大模型服务器侧回答不是合法 JSON', ['head' => mb_substr($content, 0, 160)]);

            return null;
        }

        $recipe = trim((string) ($json['recipe'] ?? ''));

        return [
            'recipe'   => $recipe,
            'title'    => trim((string) ($json['title'] ?? '')),
            'cause'    => trim((string) ($json['cause'] ?? '')),
            'severity' => trim((string) ($json['severity'] ?? 'normal')),
            'ms'       => $ms,
        ];
    }

    /**
     * @param array<string,array<string,string>> $selectable
     */
    private static function serverSystemPrompt(array $selectable): string
    {
        $lines = [
            '你是一个 Minecraft 服务端故障诊断助手。玩家提交了问题，服务端已经采集了一轮指标，',
            '但规则库没有匹配到已知故障。现在需要你判断：**在下面这份动作清单里，哪一个最可能解决问题**。',
            '',
            '只输出一个 JSON 对象，不要任何解释文字：',
            '{',
            '  "recipe": "从下面清单里选一个编号；如果没有一个合适，填空字符串",',
            '  "title": "一句话结论（不超过 40 字，管理员能看懂）",',
            '  "severity": "low | normal | high | critical",',
            '  "cause": "你判断的依据，2~3 句，要引用上面给你的具体指标"',
            '}',
            '',
            '可选动作清单（recipe 只能从这里面选，不能自创）：',
        ];

        foreach ($selectable as $code => $def) {
            $lines[] = sprintf(
                '  - %s：%s（风险：%s）',
                $code,
                (string) $def['description'],
                (string) $def['risk']
            );
        }

        $lines[] = '';
        $lines[] = '硬性要求：';
        $lines[] = '1. recipe 必须是上面清单里的**原文编号**。写不出来就填空字符串。';
        $lines[] = '2. 不要输出任何 shell 命令、脚本、代码或文件路径。';
        $lines[] = '3. 不要建议清单之外的任何操作 —— 那些做不了，写了也没用。';
        $lines[] = '4. 指标不足以判断时，recipe 填空字符串，并在 cause 里说明还缺什么信息。';
        $lines[] = '   猜错一个会真的作用到玩家身上的动作，比说"判断不了"糟糕得多。';

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $facts
     */
    private static function serverUserPrompt(array $facts): string
    {
        $lines = ['服务端采集到的指标如下：', ''];

        $lines[] = '【玩家描述】';
        $lines[] = (string) ($facts['message'] !== '' ? $facts['message'] : '（没写）');
        $lines[] = '';

        $lines[] = '【不正常的项】';
        if (empty($facts['failed'])) {
            $lines[] = '（没有明显不正常的项）';
        } else {
            foreach ($facts['failed'] as $item) {
                $lines[] = sprintf(
                    '  - %s [%s] %s',
                    (string) $item['code'],
                    (string) $item['status'],
                    (string) $item['message']
                );
            }
        }
        $lines[] = '';

        $lines[] = '【正常的项】';
        $lines[] = $facts['passed'] ? implode('、', $facts['passed']) : '（无）';
        $lines[] = '';

        $lines[] = '【没能采集到的项】';
        $lines[] = $facts['unknown'] ? implode('、', $facts['unknown']) : '（无）';

        return implode("\n", $lines);
    }

    /**
     * 拼出 OpenAI 兼容的 chat/completions 地址。
     *
     * 允许直接填 https://api.deepseek.com 或 https://api.deepseek.com/v1。
     */
    private static function endpoint(string $baseUrl): string
    {
        $url = rtrim($baseUrl, '/');
        if (substr($url, -3) !== '/v1' && strpos($url, '/chat/completions') === false) {
            $url .= '/v1';
        }
        if (strpos($url, '/chat/completions') === false) {
            $url .= '/chat/completions';
        }

        return $url;
    }

    /**
     * 真正发请求 + 解析。
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $cross
     * @param array<string,mixed> $settings
     * @return array<string,mixed>|null
     */
    private static function ask(array $server, array $parsed, array $cross, array $settings): ?array
    {
        $payload = [
            'model'       => (string) $settings['model'],
            'temperature' => (float) $settings['temperature'],
            'max_tokens'  => max(200, min(2000, (int) $settings['max_tokens'])),
            'messages'    => [
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user', 'content' => self::userPrompt($parsed, $cross)],
            ],
            // 要求返回 JSON，省得解析一堆 markdown 包装
            'response_format' => ['type' => 'json_object'],
        ];

        $url = self::endpoint((string) $settings['base_url']);

        $headers = ['Content-Type' => 'application/json'];
        $key = trim((string) $settings['api_key']);
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $timeout = max(3, min(30, (int) $settings['timeout']));
        $started = microtime(true);
        $response = self::http($url, $headers, $payload, $timeout);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (empty($response['ok'])) {
            app_log('warn', '大模型调用失败', [
                'error' => (string) $response['error'],
                'ms'    => $ms,
                // 注意：这里绝不把 api_key 写进日志
                'url'   => mask_secret_url($url),
            ]);

            return null;
        }

        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            app_log('warn', '大模型返回的不是 JSON', ['ms' => $ms, 'head' => mb_substr((string) $response['body'], 0, 160)]);

            return null;
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if (trim($content) === '') {
            app_log('warn', '大模型返回了空内容', ['ms' => $ms]);

            return null;
        }

        $result = self::parseAnswer($content, $ms);
        if ($result === null) {
            return null;
        }

        record_event(null, 'system', 'ai.consult', sprintf(
            '规则库未命中，问了一次大模型（%s，%d ms）→ %s',
            (string) $settings['model'],
            $ms,
            (string) $result['title']
        ), [
            'matched_code' => (string) ($result['matched_code'] ?? ''),
            'model'        => (string) $settings['model'],
            'ms'           => $ms,
        ]);

        return $result;
    }

    /**
     * 把模型返回的内容解析成我们自己的结构。
     *
     * @return array<string,mixed>|null
     */
    private static function parseAnswer(string $content, int $ms): ?array
    {
        $json = json_decode(trim($content), true);

        // 有些服务即使要求了 json_object 也会套一层 ```json
        if (!is_array($json) && preg_match('/\{.*\}/s', $content, $m)) {
            $json = json_decode($m[0], true);
        }
        if (!is_array($json)) {
            app_log('warn', '大模型的回答不是合法 JSON', ['head' => mb_substr($content, 0, 160)]);

            return null;
        }

        $matchedCode = trim((string) ($json['matched_code'] ?? ''));
        $title = trim((string) ($json['title'] ?? ''));
        $cause = trim((string) ($json['cause'] ?? ''));
        $steps = [];
        foreach ((array) ($json['steps'] ?? []) as $step) {
            $step = trim((string) $step);
            if ($step !== '' && mb_strlen($step) <= 300) {
                $steps[] = $step;
            }
            if (count($steps) >= 8) {
                break;
            }
        }

        // 模型点出了知识库里已有的 code：**用我们自己的那条**（含我们自己审过的配方）
        if ($matchedCode !== '' && ClientIssue::get($matchedCode) !== null) {
            $def = ClientIssue::get($matchedCode);

            return [
                'code'         => $matchedCode,
                'matched_code' => $matchedCode,
                'title'        => (string) $def['title'],
                'severity'     => (string) $def['severity'],
                'cause'        => (string) $def['cause'],
                'steps'        => array_values((array) $def['steps']),
                // 模型自己的话只作为补充说明，不覆盖知识库
                'detail'       => mb_substr($cause !== '' ? $cause : '由知识库条目直接给出结论', 0, 400),
                'extra'        => [],
                'source'       => 'ai',
                'ms'           => $ms,
            ];
        }

        // 完全没见过的问题：采用模型自己的结论，但**不带任何配方**
        if ($title === '') {
            return null;
        }
        if (!$steps) {
            $steps = ['按下面的说明逐条排查；如果还是不行，把完整崩溃报告发给管理员'];
        }

        return [
            'code'         => self::FALLBACK_CODE,
            'matched_code' => '',
            'title'        => mb_substr($title, 0, 80),
            'severity'     => self::normalizeSeverity((string) ($json['severity'] ?? 'normal')),
            'cause'        => mb_substr($cause !== '' ? $cause : $title, 0, 400),
            'steps'        => $steps,
            'detail'       => mb_substr($cause !== '' ? $cause : '', 0, 400),
            'extra'        => [],
            'source'       => 'ai',
            'ms'           => $ms,
        ];
    }

    private static function normalizeSeverity(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, ['low', 'normal', 'high', 'critical'], true) ? $raw : 'normal';
    }

    // ------------------------------------------------------------------ 提示词

    private static function systemPrompt(): string
    {
        // 把知识库的 code 清单给模型，让它有机会"认领"成已知问题
        $codes = [];
        foreach (ClientIssue::all() as $code => $def) {
            $codes[] = $code . '（' . (string) ($def['title'] ?? '') . '）';
        }

        return implode("\n", [
            '你是一个 Minecraft 客户端崩溃日志分析助手。玩家把崩溃报告或 latest.log 提交上来了，',
            '但服务端的规则库没有匹配到已知特征，需要你判断。',
            '',
            '只输出一个 JSON 对象，不要任何解释文字，字段如下：',
            '{',
            '  "matched_code": "如果这个问题正好属于下面清单里的某一项，填它的 code；否则填空字符串",',
            '  "title": "一句话结论（不超过 40 字，玩家能看懂）",',
            '  "severity": "low | normal | high | critical",',
            '  "cause": "为什么会这样，2~3 句，说人话",',
            '  "steps": ["玩家自己可以做的第 1 步", "第 2 步", "..."]',
            '}',
            '',
            '已有问题清单（matched_code 只能从这里面选）：',
            implode('、', $codes),
            '',
            '硬性要求：',
            '1. steps 必须是玩家在自己电脑上能做的具体动作（改哪个设置、装哪个文件、去哪里找），',
            '   不要写"重装游戏""联系管理员"这种废话。最多 6 步。',
            '2. 不确定就老实说："日志信息不足，请提供完整的 crash-report 文件"。',
            '   编造一个看起来合理但错误的结论，比说不知道更糟。',
            '3. 不要输出任何要执行的命令、脚本或代码。',
            '4. 不要提服务器管理员的内部操作，只说玩家自己能做的。',
        ]);
    }

    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $cross
     */
    private static function userPrompt(array $parsed, array $cross): string
    {
        $meta = (array) ($parsed['meta'] ?? []);
        $lines = ['请分析这份日志：', ''];

        $lines[] = '【已识别出的环境信息】';
        foreach ([
            'minecraft_version' => '游戏版本',
            'loader'            => '模组加载器',
            'loader_version'    => '加载器版本',
            'java'              => 'Java',
            'memory'            => '内存设置',
            'launcher'          => '启动器',
            'os'                => '操作系统',
            'kind_label'        => '日志类型',
        ] as $key => $label) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $label . '：' . $value;
            }
        }

        $exceptions = (array) ($parsed['exceptions'] ?? []);
        if ($exceptions) {
            $lines[] = '主要异常：' . implode('、', array_slice(array_keys($exceptions), 0, 8));
        }

        $classes = (array) ($parsed['missing_classes'] ?? []);
        if ($classes) {
            $lines[] = '缺失的类：' . implode("\n  ", array_slice($classes, 0, 8));
        }

        $packages = (array) ($parsed['suspect_packages'] ?? []);
        if ($packages) {
            $lines[] = '可疑包名：' . implode('、', array_slice($packages, 0, 8));
        }

        $mods = (array) ($parsed['mods'] ?? []);
        if ($mods) {
            $names = [];
            foreach (array_slice($mods, 0, 40) as $mod) {
                $names[] = (string) ($mod['name'] ?? '');
            }
            $lines[] = '日志里提到的 MOD（前 40 个）：' . implode('、', array_filter($names));
        }

        if (!empty($cross['possible'])) {
            $lines[] = sprintf(
                '与服务端比对：客户端多装 %d 个、缺少 %d 个',
                count((array) ($cross['client_extra'] ?? [])),
                count((array) ($cross['client_missing'] ?? []))
            );
        }

        $lines[] = '';
        $lines[] = '【日志原文（已脱敏，去掉了玩家名/IP/路径）】';
        $lines[] = self::redact(self::excerpt($parsed));

        return implode("\n", $lines);
    }

    /**
     * 挑出最有信息量的片段发给模型，而不是把 512 KB 全塞进去。
     *
     * @param array<string,mixed> $parsed
     */
    private static function excerpt(array $parsed): string
    {
        $parts = [];

        // 堆栈和问题行最有价值
        foreach ((array) ($parsed['stack'] ?? []) as $line) {
            $parts[] = (string) $line;
        }
        foreach ((array) ($parsed['log_errors'] ?? []) as $entry) {
            if (is_array($entry)) {
                $parts[] = (string) ($entry['message'] ?? '');
            }
        }
        foreach ((array) ($parsed['problematic'] ?? []) as $line) {
            $parts[] = (string) $line;
        }
        // 原始日志的尾部（崩溃报告的开头信息通常在头部，这里两头都取）
        $raw = (string) ($parsed['raw_excerpt'] ?? '');

        $text = trim(implode("\n", array_filter($parts)));
        if ($text === '' && $raw !== '') {
            $text = $raw;
        }

        // 最多 8000 字符 ≈ 2~3k token，够定位了
        return mb_substr($text, 0, 8000);
    }

    // ------------------------------------------------------------------ 脱敏

    /**
     * 把日志里能识别到个人的东西替换掉。调用方拿到的就是可以发出去的文本。
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Windows / Linux 用户目录：C:\Users\张三\... / /home/zhangsan/...
        $text = preg_replace('#[A-Za-z]:\\\\Users\\\\[^\\\\\s]+#i', '<userdir>', $text) ?? $text;
        $text = preg_replace('#/home/[A-Za-z0-9._\-]+#', '/home/<user>', $text) ?? $text;
        $text = preg_replace('#/Users/[A-Za-z0-9._\-]+#', '/Users/<user>', $text) ?? $text;

        // 绝对路径里的其它个人信息（MC 目录名有时带玩家名），保留结构但去掉盘符根
        $text = preg_replace('#[A-Za-z]:\\\\[^\\\\\s:]{1,40}\\\\\.minecraft#i', '<mc>\\.minecraft', $text) ?? $text;

        // IPv4 / IPv6
        $text = preg_replace('#\b(?:\d{1,3}\.){3}\d{1,3}\b#', '<ip>', $text) ?? $text;
        $text = preg_replace('#\b(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}\b#i', '<ip6>', $text) ?? $text;

        // 邮箱
        $text = preg_replace('#[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}#', '<email>', $text) ?? $text;

        // 常见令牌形态（崩溃报告里偶尔会带上启动器的 accessToken）
        $text = preg_replace('#\b[A-Za-z0-9_\-]{80,}\b#', '<token>', $text) ?? $text;
        $text = preg_replace('#(accessToken|clientToken|session)["\']?\s*[:=]\s*["\']?[^"\'\s,}]{8,}#i', '$1=<token>', $text) ?? $text;

        // API 密钥 / 长令牌：统一走 redact_secrets()，别在两边各维护一套规则
        // （这个函数在 src/helpers.php，bootstrap 时已经加载）
        $text = function_exists('redact_secrets') ? redact_secrets($text) : $text;

        return $text;
    }

    /**
     * 日志指纹：同一类报错共用一条缓存。
     *
     * 只取"稳定的特征"（异常类型 + 前几行堆栈 + 版本号），
     * 不带玩家名、时间戳这类每次都变的东西 —— 否则缓存永远命中不了。
     *
     * @param array<string,mixed> $parsed
     */
    public static function fingerprint(array $parsed): string
    {
        $meta = (array) ($parsed['meta'] ?? []);
        $bits = [
            (string) ($parsed['kind'] ?? ''),
            (string) ($meta['minecraft_version'] ?? ''),
            (string) ($meta['loader'] ?? ''),
            (string) ($meta['java'] ?? ''),
            implode(',', array_slice(array_keys((array) ($parsed['exceptions'] ?? [])), 0, 4)),
        ];

        // 堆栈前 8 行去掉数字（行号会变）后作为特征
        foreach (array_slice((array) ($parsed['stack'] ?? []), 0, 8) as $line) {
            $bits[] = (string) preg_replace('/\d+/', '#', (string) $line);
        }
        foreach (array_slice((array) ($parsed['missing_classes'] ?? []), 0, 4) as $class) {
            $bits[] = (string) $class;
        }

        return 'v1-' . md5(implode("\n", $bits));
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * 一个极简的 JSON POST（不跟随跳转、默认校验证书）。
     *
     * 为什么不复用 PanelAdapter / ChannelAdapter 的 http()：
     * 那两个是实例方法、和面板/渠道配置绑在一起；这里需要一个独立、零状态的调用，
     * 免得为了发一次模型请求就去构造一个面板适配器。
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array{ok:bool,body:string,error:string,status:int}
     */
    private static function http(string $url, array $headers, array $payload, int $timeout): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            $headerLines = [];
            foreach ($headers as $k => $v) {
                $headerLines[] = $k . ': ' . $v;
            }
            $headerLines[] = 'Accept: application/json';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'mcfix/' . MCFIX_VERSION,
            ]);
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false) {
                return ['ok' => false, 'body' => '', 'error' => 'curl：' . $error, 'status' => 0];
            }

            return [
                'ok'     => $status >= 200 && $status < 300,
                'body'   => (string) $raw,
                'error'  => $status >= 200 && $status < 300 ? '' : self::describeHttpError($status, (string) $raw)['text'],
                'specific' => $status >= 200 && $status < 300 ? true : self::describeHttpError($status, (string) $raw)['specific'],
                'status' => $status,
            ];
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", $headerLines) . "\r\n",
                'content'         => $body,
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['ok' => false, 'body' => '', 'error' => '请求失败（stream）', 'status' => 0];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'body'   => (string) $raw,
            'error'  => $status >= 200 && $status < 300 ? '' : (self::describeHttpError($status, (string) $raw)['text']),
            'specific' => $status >= 200 && $status < 300 ? true : self::describeHttpError($status, (string) $raw)['specific'],
            'status' => $status,
        ];
    }

    /**
     * 把上游返回的错误翻译成一句能照着改的话。
     *
     * 为什么不能直接截断原始 body：OpenAI 兼容接口的错误体形如
     *   {"error":{"message":"The supported API model names are ...",
     *             "type":"invalid_request_error","code":"..."}}
     * 截到 200 字会把 message 和 type 都切成半截，管理员看到的是
     * `"type":"invalid_request_` 这种残缺片段 —— 明明上游已经写清楚了
     * 「模型名不对」，界面上却只剩一堆看不懂的 JSON 碎片。
     *
     * 能解析出 error.message 就优先用它，那里面通常已经写明
     * "模型名不对 / 密钥无效 / 余额不足"。
     *
     * @return array{text:string,specific:bool} specific=false 表示只拿到 HTTP 状态码，
     *         调用方可以据此再补一句通用排查提示（有具体原因时就别补了，那是噪音）。
     */
    private static function describeHttpError(int $status, string $body): array
    {
        $message = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $err = $decoded['error'] ?? null;
            if (is_array($err)) {
                $message = (string) ($err['message'] ?? '');
                if ($message === '') {
                    $message = (string) ($err['code'] ?? '');
                }
            } elseif (is_string($err)) {
                $message = $err;
            } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                $message = $decoded['message'];
            }
        }

        $message = trim((string) preg_replace('/\s+/u', ' ', $message));
        $specific = $message !== '';

        if (!$specific) {
            // 不是 JSON（网关的错误页、反向代理的 HTML 等）：截一段原文，但别太短，
            // 至少留够看清一句话的量。
            $message = trim((string) preg_replace('/\s+/u', ' ', strip_tags($body)));
            $message = mb_substr($message, 0, 300);
        } else {
            $message = mb_substr($message, 0, 400);
        }

        if ($message === '') {
            $message = '（上游没有返回内容）';
        }

        return [
            'text'     => 'HTTP ' . $status . '：' . self::redact($message),
            'specific' => $specific,
        ];
    }

    // ------------------------------------------------------------------ 自检

    /**
     * 后台「获取可用模型」按钮：问一下这个接口到底支持哪些模型名。
     *
     * 为什么值得单独做一个按钮：模型名是最容易填错、又最难自查的一项 ——
     * 各家中转/网关的命名五花八门（`deepseek-flash` / `DeepSeek-V4-Flash` /
     * `deepseek-chat`…），填错了只会得到一个 400。OpenAI 兼容接口普遍实现了
     * `GET /v1/models`，直接问它一句比让管理员去翻文档靠谱。
     *
     * @return array{ok:bool,message:string,models:string[]}
     */
    public static function listModels(): array
    {
        $settings = self::settings();
        $base = rtrim((string) $settings['base_url'], '/');
        if ($base === '') {
            return ['ok' => false, 'message' => '先填「接口地址」再点这里', 'models' => []];
        }

        // 和 test() 用同一套地址拼接规则，避免"测试能通、列表却 404"
        $url = $base;
        if (substr($url, -3) !== '/v1' && strpos($url, '/chat/completions') === false) {
            $url .= '/v1';
        }
        $url = (string) preg_replace('#/chat/completions/?$#', '', $url);
        $url .= '/models';

        $headers = ['Content-Type' => 'application/json'];
        $key = trim((string) $settings['api_key']);
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $response = self::httpGet($url, $headers, max(5, min(30, (int) $settings['timeout'])));
        if (empty($response['ok'])) {
            return [
                'ok'      => false,
                'message' => '拿不到模型列表：' . (string) $response['error']
                    . (empty($response['specific'])
                        ? '。有些网关不实现 /models，那就只能手填模型名。'
                        : ''),
                'models'  => [],
            ];
        }

        $decoded = json_decode((string) $response['body'], true);
        $ids = [];
        foreach ((array) ($decoded['data'] ?? []) as $row) {
            $id = trim((string) (is_array($row) ? ($row['id'] ?? '') : $row));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($ids === []) {
            return ['ok' => false, 'message' => '接口有响应，但里面没有模型清单（data[].id 是空的）', 'models' => []];
        }

        $current = trim((string) $settings['model']);
        $note = '';
        if ($current === '') {
            $note = '。当前还没填模型名，从下面挑一个';
        } elseif (in_array($current, $ids, true)) {
            $note = '。当前填的「' . $current . '」在列表里 ✓';
        } else {
            $note = '。⚠ 当前填的「' . $current . '」不在这份列表里 —— 多半就是它导致 400';
        }

        return [
            'ok'      => true,
            'message' => '这个接口支持 ' . count($ids) . ' 个模型' . $note,
            'models'  => $ids,
        ];
    }

    /**
     * GET 请求。和 http() 分开写是因为那边固定发 POST + JSON body。
     *
     * @param array<string,string> $headers
     * @return array{ok:bool,body:string,error:string,specific:bool,status:int}
     */
    private static function httpGet(string $url, array $headers, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headerLines = [];
            foreach ($headers as $k => $v) {
                $headerLines[] = $k . ': ' . $v;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $error = (string) curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false) {
                return ['ok' => false, 'body' => '', 'error' => 'curl：' . $error, 'specific' => false, 'status' => 0];
            }
            $described = self::describeHttpError($status, (string) $raw);

            return [
                'ok'       => $status >= 200 && $status < 300,
                'body'     => (string) $raw,
                'error'    => $status >= 200 && $status < 300 ? '' : $described['text'],
                'specific' => $status >= 200 && $status < 300 ? true : $described['specific'],
                'status'   => $status,
            ];
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", $headerLines) . "\r\n",
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['ok' => false, 'body' => '', 'error' => '请求失败（stream）', 'specific' => false, 'status' => 0];
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $described = self::describeHttpError($status, (string) $raw);

        return [
            'ok'       => $status >= 200 && $status < 300,
            'body'     => (string) $raw,
            'error'    => $status >= 200 && $status < 300 ? '' : $described['text'],
            'specific' => $status >= 200 && $status < 300 ? true : $described['specific'],
            'status'   => $status,
        ];
    }

    /**
     * 后台「测试」按钮：发一句话过去，验证地址/密钥/模型名对不对。
     *
     * @return array{ok:bool,message:string}
     */
    public static function test(): array
    {
        $settings = self::settings();
        if (trim((string) $settings['base_url']) === '' || trim((string) $settings['model']) === '') {
            return ['ok' => false, 'message' => '先把「接口地址」和「模型名」填上'];
        }

        $payload = [
            'model'       => (string) $settings['model'],
            'temperature' => 0,
            'max_tokens'  => 20,
            'messages'    => [
                ['role' => 'user', 'content' => '只回复两个字：收到'],
            ],
        ];

        $url = rtrim((string) $settings['base_url'], '/');
        if (substr($url, -3) !== '/v1' && strpos($url, '/chat/completions') === false) {
            $url .= '/v1';
        }
        if (strpos($url, '/chat/completions') === false) {
            $url .= '/chat/completions';
        }

        $headers = ['Content-Type' => 'application/json'];
        $key = trim((string) $settings['api_key']);
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $started = microtime(true);
        $response = self::http($url, $headers, $payload, max(5, min(30, (int) $settings['timeout'])));
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (empty($response['ok'])) {
            $message = '调用失败（' . $ms . ' ms）：' . (string) $response['error'];
            // 上游已经说清原因时不再补套话 —— 否则会把
            // 「模型名不对，支持的是 xxx」这种明确答案淹没在通用提示里。
            if (empty($response['specific'])) {
                $message .= '。检查接口地址是否带 /v1、密钥是否正确、模型名是否存在。';
            } else {
                $message .= '。可以点下面的「获取可用模型」看看这个接口到底支持哪些模型名。';
            }

            return ['ok' => false, 'message' => $message];
        }

        $decoded = json_decode((string) $response['body'], true);
        $answer = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        $usage = (array) ($decoded['usage'] ?? []);

        return [
            'ok'      => true,
            'message' => sprintf(
                '连通 ✓ %d ms，模型回了「%s」%s',
                $ms,
                mb_substr($answer, 0, 40),
                isset($usage['total_tokens']) ? ('，本次用了 ' . (int) $usage['total_tokens'] . ' tokens') : ''
            ),
        ];
    }

    /**
     * 后台展示用的状态。
     *
     * @return array<string,mixed>
     */
    public static function stats(): array
    {
        $global = Rate::peek('ai:global');

        return [
            'enabled'      => self::enabled(),
            'used_this_hour' => (int) $global['count'],
            'hourly_limit' => self::GLOBAL_HOURLY_LIMIT,
            'calls'        => (int) Db::scalar("SELECT COUNT(*) FROM events WHERE action = 'ai.consult'"),
            'last_message' => (string) Db::scalar("SELECT message FROM events WHERE action = 'ai.consult' ORDER BY id DESC LIMIT 1"),
        ];
    }
}
