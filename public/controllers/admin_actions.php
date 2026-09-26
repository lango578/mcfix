<?php
/**
 * Backend form-action handlers (split out of admin.php).
 *
 * Why a separate file: admin.php was 1341 lines mixing three concerns --
 *   1. entry point, session, CSRF, login
 *   2. action dispatch and page routing
 *   3. the implementations of ~10 actions (save settings, add/remove server,
 *      change password, notification channels, ...)
 * The third group was ~700 lines, and it was the main reason "change one
 * feature" meant "search through 1341 lines".
 *
 * After the split: routing lives in admin.php, action implementations live here.
 * No function name or signature changed, no call site changed -- this is a pure
 * move, behaviour is identical.
 *
 * WARNING: this file MUST be required by admin.php BEFORE any action is
 * dispatched. See the require right below the use block in admin.php.
 */

declare(strict_types=1);

use MCFix\Agent;
use MCFix\Brand;
use MCFix\Cache;
use MCFix\Config;
use MCFix\ConsoleAuth;
use MCFix\Db;
use MCFix\Rate;
use MCFix\Recipe;
use MCFix\Task;
use MCFix\Workflow;

/**
 * 单独保存日志原文留存天数。
 *
 * 为什么给它一个独立动作，而不是继续挂在「保存设置」大表单里：
 *
 *   1. 管理员两轮都找不到那个字段 —— 它原本是「玩家反馈」六项里的最后一项，
 *      而这是条会删玩家数据的设置，不该藏在那种位置。
 *   2. 更重要的：admin_save_settings() 是"整表单覆盖"语义，缺字段就取默认值。
 *      一个只带 log_retention_days 的局部提交会把别的字段一起重置
 *      （比如 admin.ip_allow 会被清成空数组 —— 那等于把后台白名单关掉）。
 *      独立动作只碰一个键，没有这个风险。
 */
function admin_save_log_retention(array $input): array
{
    if (!array_key_exists('log_retention_days', $input)) {
        return ['ok' => false, 'message' => '没有收到留存天数'];
    }

    $days = max(0, min(3650, int_param($input, 'log_retention_days', 30)));

    $items = (array) Config::get('', []);
    $items['feedback']['log_retention_days'] = $days;

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '写入 config/config.php 失败，请检查 config 目录权限'];
    }

    record_event(null, 'admin', 'log.retention', $days > 0
        ? sprintf('日志原文留存设置为 %d 天', $days)
        : '日志原文留存已关闭（0 = 不清理）');

    return [
        'ok'      => true,
        'message' => $days > 0
            ? sprintf('已保存：工单归档满 %d 天后自动清掉日志原文（结论保留）', $days)
            : '已保存：不清理日志原文（永久保留）',
    ];
}

/**
 * 保存通知总开关与事件订阅。
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string}
 */
function admin_save_notify(array $input): array
{
    $items = (array) Config::get('', []);
    $channels = (array) ($items['notify']['channels'] ?? []);
    if (!$channels) {
        // 页面里没有任何渠道时，只保存开关
        $items['notify']['enabled'] = bool_param($input, 'enabled', false);
        $items['notify']['burst_limit'] = max(1, min(20, int_param($input, 'burst_limit', 3)));
        if (!Config::save($items)) {
            return ['ok' => false, 'message' => '配置写入失败，请检查 config 目录权限'];
        }

        return ['ok' => true, 'message' => '已保存（还没有配置渠道，通知不会发出）'];
    }

    // 事件订阅：表单里是 sub_<channel>_<event> 的复选框
    $subscriptions = [];
    foreach ($channels as $key => $channelConfig) {
        $events = [];
        foreach (array_keys(\MCFix\Notifier::EVENTS) as $event) {
            if (bool_param($input, 'sub_' . $key . '_' . $event, false)) {
                $events[] = $event;
            }
        }
        $subscriptions[(string) $key] = $events;
    }

    $items['notify']['enabled'] = bool_param($input, 'enabled', false);
    $items['notify']['burst_limit'] = max(1, min(20, int_param($input, 'burst_limit', 3)));
    $items['notify']['subscriptions'] = $subscriptions;

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败，请检查 config 目录权限'];
    }

    record_event(null, 'admin', 'notify.save', '更新通知设置', [
        'channels' => array_keys($channels),
        'enabled'  => $items['notify']['enabled'],
    ]);

    return ['ok' => true, 'message' => '通知设置已保存'];
}

/**
 * 保存或更新一个通知渠道。
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string}
 */
function admin_save_notify_channel(array $input): array
{
    $key = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', param($input, 'channel_key')) ?? '');
    $type = param($input, 'channel_type');
    $known = \MCFix\Channel\ChannelRegistry::types();

    if ($key === '') {
        $key = $type . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
    if (!isset($known[$type])) {
        return ['ok' => false, 'message' => '未知的渠道类型：' . $type];
    }

    // 收集所有表单里出现的字段（可能是任意类型段的字段，只取当前类型的）
    $fieldHints = \MCFix\Channel\ChannelRegistry::fieldHints();
    $config = [
        'type'       => $type,
        'enabled'    => bool_param($input, 'channel_enabled', false),
        'timeout'    => max(3, min(60, int_param($input, 'timeout', 10))),
    ];

    /*
     * 凭据字段在页面上是不回显的（V11），所以"提交过来是空"有两种含义：
     * 一种是"我不想改，保持原值"，一种是"我要清空"。
     *
     * 而下面第 170 行是**整包替换** $items['notify']['channels'][$key]，
     * 不是合并 —— 如果直接照抄空值，管理员改一下超时时间就会把密钥删掉，
     * 而界面上不会有任何提示（渠道从此静默失效）。
     *
     * 所以规则定成这样：
     *   勾了「清除」  → 真的删掉
     *   没勾且留空    → 沿用旧值
     *   填了新值      → 用新值
     * 和 settings.php 里 SMTP 密码 / AI Key 的处理保持同一套语义。
     */
    $secretFields = ['secret', 'token', 'send_key', 'bot_token', 'device_key'];
    $existingChannel = (array) (\MCFix\Notifier::config()['channels'][$key] ?? []);

    foreach ((array) ($fieldHints[$type] ?? []) as $field => $hint) {
        $isSecret = in_array($field, $secretFields, true);
        $value = param($input, 'cfg_' . $type . '_' . $field, '', 4000);

        if ($isSecret && bool_param($input, 'cfg_clear_' . $type . '_' . $field, false)) {
            // 显式清空：不写入该键（等于删除）
            continue;
        }
        if ($isSecret && $value === '') {
            // 留空 = 不改：沿用已保存的旧值，别把密钥抹掉
            if (isset($existingChannel[$field]) && (string) $existingChannel[$field] !== '') {
                $config[$field] = (string) $existingChannel[$field];
            }
            continue;
        }
        if ($value !== '') {
            $config[$field] = $value;
        }
    }

    // 有些字段是布尔语义
    if ($type === 'wecom' && isset($config['mentioned_all'])) {
        $config['mentioned_all'] = $config['mentioned_all'] === '1' ? '1' : '0';
    }

    $adapter = \MCFix\Channel\ChannelRegistry::make($type, $config);
    if ($adapter !== null) {
        $missing = $adapter->missingFields();
        if ($missing) {
            return ['ok' => false, 'message' => '还缺必填项：' . implode('、', $missing)];
        }
    }

    $items = (array) Config::get('', []);
    $items['notify']['channels'][$key] = $config;

    // 新渠道默认订阅"该通知的事件"
    if (!isset($items['notify']['subscriptions'][$key])) {
        $items['notify']['subscriptions'][$key] = [];
        foreach (\MCFix\Notifier::DEFAULT_EVENTS as $event => $on) {
            if ($on) {
                $items['notify']['subscriptions'][$key][] = $event;
            }
        }
    }

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败，请检查 config 目录权限'];
    }

    // 清掉实例缓存，让新配置立刻生效
    \MCFix\Notifier::adapter($key, true);

    record_event(null, 'admin', 'notify.channel', '保存通知渠道：' . $key . '（' . $type . '）', [
        'channel' => $key,
        'type'    => $type,
    ]);

    return ['ok' => true, 'message' => '渠道「' . $key . '」已保存。建议点一下「发送测试」验证能否真的收到。'];
}

/**
 * 新增服务器。
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string}
 */
function admin_add_server(array $input): array
{
    $name = param($input, 'name', '', 60);
    $id = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', param($input, 'server_id', '', 40)) ?? '');
    $host = param($input, 'host', '', 120);
    $port = int_param($input, 'port', 25565);
    $rcHost = param($input, 'rcon_host', $host, 120);
    $rcPort = int_param($input, 'rcon_port', 25575);
    $rcPass = param($input, 'rcon_password', '', 120);
    $executor = param($input, 'executor', 'agent');
    $agentToken = param($input, 'agent_token', '');
    $mcDir = param($input, 'mc_dir', '', 255);
    $guardType = param($input, 'guard_type', 'systemd');
    $guardService = param($input, 'guard_service', '', 80);
    $guardSession = param($input, 'guard_session', '', 80);
    $logPath = param($input, 'log_path', 'logs/latest.log', 255);

    if ($name === '' || $host === '') {
        return ['ok' => false, 'message' => '服务器名称与地址必填'];
    }
    if ($id === '') {
        $id = strtolower(preg_replace('/[^a-z0-9]/', '', $name) ?: '') ?: 'srv' . substr(bin2hex(random_bytes(2)), 0, 4);
        $id = substr($id, 0, 24);
    }
    if (Config::server($id) !== null) {
        return ['ok' => false, 'message' => '服务器 ID 已存在：' . $id];
    }
    if ($port < 1 || $port > 65535) {
        return ['ok' => false, 'message' => '端口不合法'];
    }
    if ($executor === 'agent' && $agentToken === '') {
        $agentToken = bin2hex(random_bytes(16));
    }

    $items = (array) Config::get('', []);
    $items['servers'][] = [
        'id'                => $id,
        'code'              => param($input, 'code', '', 12),
        'name'              => $name,
        'host'              => $host,
        'port'              => $port,
        'enabled'           => true,
        'require_mc_online' => true,
        'max_players'       => 100,
        'rcon'              => [
            'enabled'  => $rcPass !== '',
            'host'     => $rcHost,
            'port'     => $rcPort,
            'password' => $rcPass,
            'timeout'  => 3,
        ],
        'executor'    => $executor,
        'agent_token' => $agentToken,
        'agent_ip_allow' => array_slice(array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', (string) param($input, 'agent_ip_allow', '', 500)) ?: []
        ))), 0, 50),
        'mc_dir'      => $mcDir !== '' ? $mcDir : '/www/minecraft/server',
        'disk_path'   => $mcDir !== '' ? $mcDir : '/www/minecraft',
        'log_path'    => $logPath,
        'listen_port' => $port,
        'guard'       => [
            'type'        => $guardType,
            'service'     => $guardService,
            'session'     => $guardSession,
            'start_cmd'   => '',
            'stop_cmd'    => '',
            'restart_cmd' => '',
            'max_restarts_per_hour' => 3,
        ],
        'task_timeout' => 90,
        'recipes'      => [],
    ];

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败，请检查 config 目录写权限'];
    }

    record_event(null, 'admin', 'server.add', '新增服务器：' . $name . '（' . $host . ':' . $port . '）', ['id' => $id]);

    return ['ok' => true, 'message' => '服务器已添加。请把 Agent 部署到 MC 机器上（见「接入与排查」页）。'];
}

/**
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string}
 */
function admin_save_server(array $input): array
{
    $id = param($input, 'server_id');
    // 用 anyServer：已经停用的服务器也要能改配置、重新启用
    if (Config::anyServer($id) === null) {
        return ['ok' => false, 'message' => '服务器不存在：' . $id];
    }

    $items = (array) Config::get('', []);
    $found = false;
    foreach ($items['servers'] as $i => $server) {
        if ((string) ($server['id'] ?? '') !== $id) {
            continue;
        }
        $found = true;
        $items['servers'][$i] = array_merge($server, [
            'code'        => param($input, 'code', (string) ($server['code'] ?? ''), 12),
            'name'        => param($input, 'name', (string) $server['name'], 60),
            'host'        => param($input, 'host', (string) $server['host'], 120),
            'port'        => int_param($input, 'port', (int) $server['port']),
            'enabled'     => bool_param($input, 'enabled', true),
            'require_mc_online' => bool_param($input, 'require_mc_online', true),
            'max_players' => int_param($input, 'max_players', (int) ($server['max_players'] ?? 100)),
            'executor'    => param($input, 'executor', (string) ($server['executor'] ?? 'agent')),
            'mc_dir'      => param($input, 'mc_dir', (string) ($server['mc_dir'] ?? ''), 255),
            'disk_path'   => param($input, 'disk_path', (string) ($server['disk_path'] ?? ''), 255),
            'log_path'    => param($input, 'log_path', (string) ($server['log_path'] ?? 'logs/latest.log'), 255),
            'listen_port' => int_param($input, 'listen_port', (int) ($server['listen_port'] ?? $server['port'])),
            'task_timeout'=> int_param($input, 'task_timeout', (int) ($server['task_timeout'] ?? 90)),
            // Agent 来源 IP 白名单（每行一个，支持 1.2.3.4 或 1.2.3.*；留空 = 不限制）
            'agent_ip_allow' => array_slice(array_values(array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', (string) param($input, 'agent_ip_allow', '', 500)) ?: []
            ))), 0, 50),
            'rcon'        => [
                'enabled'  => bool_param($input, 'rcon_enabled', !empty($server['rcon']['enabled'])),
                'host'     => param($input, 'rcon_host', (string) ($server['rcon']['host'] ?? $server['host']), 120),
                'port'     => int_param($input, 'rcon_port', (int) ($server['rcon']['port'] ?? 25575)),
                'password' => param($input, 'rcon_password', (string) ($server['rcon']['password'] ?? ''), 120),
                'timeout'  => (int) ($server['rcon']['timeout'] ?? 3),
            ],
            'guard'       => array_merge((array) ($server['guard'] ?? []), [
                'type'                  => param($input, 'guard_type', (string) ($server['guard']['type'] ?? 'systemd')),
                'service'               => param($input, 'guard_service', (string) ($server['guard']['service'] ?? ''), 80),
                'session'               => param($input, 'guard_session', (string) ($server['guard']['session'] ?? ''), 80),
                'start_cmd'             => param($input, 'guard_start_cmd', (string) ($server['guard']['start_cmd'] ?? ''), 500),
                'stop_cmd'              => param($input, 'guard_stop_cmd', (string) ($server['guard']['stop_cmd'] ?? ''), 500),
                'restart_cmd'           => param($input, 'guard_restart_cmd', (string) ($server['guard']['restart_cmd'] ?? ''), 500),
                'max_restarts_per_hour' => int_param($input, 'max_restarts_per_hour', (int) ($server['guard']['max_restarts_per_hour'] ?? 3)),
            ]),
        ]);

        // 令牌：留空表示不改；填 "new" 表示重新生成
        $tokenInput = param($input, 'agent_token');
        if ($tokenInput === '__new__') {
            $items['servers'][$i]['agent_token'] = bin2hex(random_bytes(16));
        } elseif ($tokenInput !== '') {
            $items['servers'][$i]['agent_token'] = $tokenInput;
        }

        // 配方白名单
        $recipes = [];
        foreach (Recipe::all() as $code => $def) {
            $recipes[(string) $code] = bool_param($input, 'recipe_' . $code, true);
        }
        $items['servers'][$i]['recipes'] = $recipes;

        // 面板 API 配置（可与 Agent 叠加：诊断读日志优先走面板，重启优先走 Agent）
        $panelType = param($input, 'panel_type', 'none');
        if (in_array($panelType, ['none', 'mcsmanager', 'pterodactyl', 'bt', 'custom'], true)) {
            $items['servers'][$i]['panel'] = [
                'enabled'     => bool_param($input, 'panel_enabled', $panelType !== 'none') && $panelType !== 'none',
                'type'        => $panelType,
                'mode'        => param($input, 'panel_mode', 'client', 20),
                'api_url'     => param($input, 'panel_api_url', '', 255),
                'api_key'     => param($input, 'panel_api_key', '', 255),
                // Multicraft 是唯一需要「用户名 + 密钥」两个凭据的面板
                // （签名要用用户名，请求里也要带）。别的面板用不到这一项。
                'username'    => param($input, 'panel_username', (string) ($server['panel']['username'] ?? ''), 80),
                'daemon_id'   => param($input, 'panel_daemon_id', '', 80),
                'instance_id' => param($input, 'panel_instance_id', '', 80),
                'server_id'   => param($input, 'panel_server_id', '', 80),
                'process_id'  => param($input, 'panel_process_id', '', 80),
                'service'     => param($input, 'panel_service', '', 80),
                'mc_dir'      => param($input, 'panel_mc_dir', (string) ($server['mc_dir'] ?? ''), 255),
                'timeout'     => max(3, min(60, int_param($input, 'panel_timeout', 12))),
                // 默认校验 TLS；只有自签证书的面板才由管理员显式打开
                'insecure'    => bool_param($input, 'panel_insecure', false),
                // custom 适配器
                'method'      => param($input, 'panel_method', 'POST', 8),
                'headers'     => param($input, 'panel_headers', '', 1000),
                'body'        => param($input, 'panel_body', '', 1000),
                'output_path' => param($input, 'panel_output_path', '', 80),
                'power_url'   => param($input, 'panel_power_url', '', 255),
                'power_body'  => param($input, 'panel_power_body', '', 500),
                'log_url'     => param($input, 'panel_log_url', '', 255),
                'dir_url'     => param($input, 'panel_dir_url', '', 255),
                'dir_path'    => param($input, 'panel_dir_path', '', 80),
                'file_url'    => param($input, 'panel_file_url', '', 255),
                // 接口路径覆盖：每行 "名称=路径"，用来适配不同面板版本
                'endpoints'   => admin_parse_endpoints((string) param($input, 'panel_endpoints', '', 2000)),
            ];
        }

        break;
    }

    if (!$found) {
        return ['ok' => false, 'message' => '服务器不存在'];
    }

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败，请检查 config 目录权限'];
    }

    record_event(null, 'admin', 'server.save', '保存服务器配置：' . $id, ['id' => $id]);

    return ['ok' => true, 'message' => '服务器配置已保存'];
}

/**
 * @return array{ok:bool,message:string}
 */
function admin_delete_server(string $id): array
{
    $items = (array) Config::get('', []);
    $servers = (array) ($items['servers'] ?? []);
    $before = count($servers);
    $items['servers'] = array_values(array_filter($servers, static function ($server) use ($id): bool {
        return (string) ($server['id'] ?? '') !== $id;
    }));

    if (count($items['servers']) === $before) {
        return ['ok' => false, 'message' => '服务器不存在'];
    }

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败'];
    }

    record_event(null, 'admin', 'server.delete', '删除服务器：' . $id, ['id' => $id], 'warn');

    return ['ok' => true, 'message' => '服务器已删除（历史工单保留）'];
}

/**
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string}
 */
function admin_save_settings(array $input): array
{
    $items = (array) Config::get('', []);

    $items['app']['base_url'] = rtrim(param($input, 'base_url', (string) ($items['app']['base_url'] ?? '')), '/');
    $items['app']['name'] = param($input, 'site_name', (string) ($items['app']['name'] ?? 'MC 故障反馈中心'), 60);
    $items['app']['timezone'] = param($input, 'timezone', (string) ($items['app']['timezone'] ?? 'Asia/Shanghai'), 40);

    // 后台私有路径：只允许字母数字和短横线，并且必须够长（否则不如不用）
    $consolePath = strtolower((string) preg_replace('/[^a-zA-Z0-9\-]/', '', param($input, 'console_path', '', 80)));
    $pathChanged = false;
    if ($consolePath !== '' && strlen($consolePath) < 10) {
        return ['ok' => false, 'message' => '后台路径太短了（至少 10 个字符），容易被猜到'];
    }
    if ($consolePath !== '' && $consolePath !== (string) ($items['admin']['path'] ?? '')) {
        $items['admin']['path'] = $consolePath;
        $pathChanged = true;
        record_event(null, 'admin', 'console.path', '后台入口路径已更换（新路径前 4 位：' . substr($consolePath, 0, 4) . '****）', [], 'warn');
    }

    // IP 白名单。
    //
    // 和下面的域名一样必须判断"字段到底有没有提交"：`param($input, 'console_ip_allow', '')`
    // 在字段缺失时返回空串，于是 $ips 是空数组 —— 一次不带该字段的局部提交
    // （curl 调接口、脚本单独提交某几个字段）就会**把后台白名单静默清空**。
    // 那是在放松访问控制，而且是无声的。
    if (array_key_exists('console_ip_allow', $input)) {
        $ipRaw = param($input, 'console_ip_allow', '', 500);
        $ips = [];
        foreach (preg_split('/[,;\s]+/', $ipRaw) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '') {
                $ips[] = $item;
            }
        }
        $items['admin']['ip_allow'] = $ips;
    }

    // 域名分工（双域名模式）
    $normalizeHost = static function (string $raw): string {
        $parts = [];
        foreach (explode(',', $raw) as $one) {
            $one = strtolower(trim($one));
            // 允许粘贴完整 URL，这里把协议和路径剥掉
            $one = (string) preg_replace('#^https?://#', '', $one);
            $one = (string) preg_replace('#/.*$#', '', $one);
            $one = (string) preg_replace('#:\d+$#', '', $one);
            if ($one !== '' && preg_match('/^[a-z0-9\.\-]+$/', $one)) {
                $parts[] = $one;
            }
        }

        return implode(',', array_values(array_unique($parts)));
    };

    // 域名分工（双域名模式）。
    //
    // 这里必须判断"字段到底有没有提交"，不能直接拿默认空串去覆盖：
    // 只要有一次 POST 没带上这两个字段（比如用 curl 调接口、或者表单被
    // 某个脚本局部提交），domains 就会被清空 —— 而清空意味着双域名模式失效，
    // 后台域名会开始渲染玩家页面，管理员直接被关在门外。
    // 界面上表单一直是带这两个字段的，所以正常操作不受影响。
    if (array_key_exists('feedback_host', $input) || array_key_exists('console_host', $input)) {
        if (array_key_exists('feedback_host', $input)) {
            $items['domains']['feedback_host'] = $normalizeHost(param($input, 'feedback_host', '', 300));
        }
        if (array_key_exists('console_host', $input)) {
            $items['domains']['console_host'] = $normalizeHost(param($input, 'console_host', '', 300));
        }
    }

    $items['feedback']['token_ttl'] = max(600, int_param($input, 'token_ttl', (int) ($items['feedback']['token_ttl'] ?? 86400)));
    $items['feedback']['rate_limit_per_hour'] = max(1, int_param($input, 'rate_limit_per_hour', 5));
    $items['feedback']['verify_per_hour'] = max(1, int_param($input, 'verify_per_hour', 40));
    $items['feedback']['sync_wait_seconds'] = max(2, min(30, int_param($input, 'sync_wait_seconds', 8)));
    $items['feedback']['autoclose_days'] = max(1, int_param($input, 'autoclose_days', 7));

    // 日志原文留存天数（0 = 不清理）。
    //
    // 这里**必须**判断字段有没有提交，不能像上面几条那样"缺了就取默认值"：
    // 这个值决定要不要**删玩家数据**。一次不带该字段的局部提交（curl 调接口、
    // 脚本单独提交某几个字段）如果把它重置成默认的 30，就会在管理员毫不知情的
    // 情况下开始自动删日志 —— 静默的破坏性变更。缺字段时保持原值。
    if (array_key_exists('log_retention_days', $input)) {
        $items['feedback']['log_retention_days'] = max(0, min(3650, int_param($input, 'log_retention_days', 30)));
    }

    $items['automation']['auto_fix'] = bool_param($input, 'auto_fix', true);
    $items['automation']['require_approval_for_restart'] = bool_param($input, 'require_approval_for_restart', true);
    $items['automation']['max_fix_attempts'] = max(1, min(5, int_param($input, 'max_fix_attempts', 2)));
    $items['automation']['verify_delay'] = max(0, min(60, int_param($input, 'verify_delay', 5)));
    $items['automation']['verify_delay_restart'] = max(0, min(120, int_param($input, 'verify_delay_restart', 25)));
    $items['automation']['circuit_breaker_failures'] = max(0, int_param($input, 'circuit_breaker_failures', 3));

    // 邮件模块：玩家回执 + 管理员提醒
    $emailTo = trim((string) param($input, 'email_admin_to', '', 200));
    if ($emailTo !== '' && !\MCFix\TicketMail::isEmail($emailTo)) {
        return ['ok' => false, 'message' => '管理员邮箱格式不对：' . $emailTo];
    }
    $emailFrom = trim((string) param($input, 'email_from', '', 200));
    if ($emailFrom !== '' && !\MCFix\TicketMail::isEmail($emailFrom)) {
        return ['ok' => false, 'message' => '发件人邮箱格式不对：' . $emailFrom];
    }

    $emailEnabled = bool_param($input, 'email_enabled', false);
    $notifyAdmin = bool_param($input, 'email_notify_admin', false);
    if ($emailEnabled && $notifyAdmin && $emailTo === '') {
        return ['ok' => false, 'message' => '开了「给管理员发提醒」就必须填管理员邮箱'];
    }

    $items['email'] = [
        'enabled'       => $emailEnabled,
        'admin_to'      => $emailTo,
        'from'          => $emailFrom,
        'from_name'     => param($input, 'email_from_name', 'MC 故障反馈系统', 60),
        'notify_player' => bool_param($input, 'email_notify_player', true),
        'notify_admin'  => $notifyAdmin,
        'purge_email'   => bool_param($input, 'email_purge', true),
    ];

    // ---- SMTP：VPS 上发信的正确姿势（填邮箱账号 + 授权码）
    $smtpHost = trim((string) param($input, 'smtp_host', '', 200));
    $smtpUser = trim((string) param($input, 'smtp_username', '', 200));
    $smtpEnabled = bool_param($input, 'smtp_enabled', false);
    if ($smtpEnabled && ($smtpHost === '' || $smtpUser === '')) {
        return ['ok' => false, 'message' => '启用 SMTP 就必须填「SMTP 服务器」和「邮箱账号」'];
    }

    $smtpCrypto = (string) param($input, 'smtp_encryption', 'ssl', 10);
    if (!in_array($smtpCrypto, ['ssl', 'tls', 'none'], true)) {
        $smtpCrypto = 'ssl';
    }
    $smtpPort = int_param($input, 'smtp_port', $smtpCrypto === 'ssl' ? 465 : 587);
    if ($smtpPort < 1 || $smtpPort > 65535) {
        $smtpPort = $smtpCrypto === 'ssl' ? 465 : 587;
    }

    $smtpPassInput = (string) param($input, 'smtp_password', '', 300);
    $smtpPass = bool_param($input, 'smtp_password_clear', false)
        ? ''
        : ($smtpPassInput !== '' ? $smtpPassInput : (string) Config::get('email.smtp.password', ''));

    if ($smtpEnabled && $smtpPass === '') {
        return ['ok' => false, 'message' => '启用 SMTP 就必须填密码（QQ/163 邮箱这里填的是"授权码"，不是登录密码）'];
    }

    $items['email']['smtp'] = [
        'enabled'             => $smtpEnabled,
        'host'                => $smtpHost,
        'port'                => $smtpPort,
        'encryption'          => $smtpCrypto,
        'username'            => $smtpUser,
        'password'            => $smtpPass,
        'from'                => trim((string) param($input, 'smtp_from', '', 200)),
        // QQ / 163 要求发件地址 == 登录账号，默认强制对齐，省得用户被 550 拒了还找不到原因
        'force_from_username' => bool_param($input, 'smtp_force_from', true),
    ];

    // 大模型兜底（可选）。默认关 —— 系统本身不依赖任何大模型。
    $aiBase = trim((string) param($input, 'ai_base_url', '', 300));
    $aiModel = trim((string) param($input, 'ai_model', '', 120));
    $aiEnabled = bool_param($input, 'ai_enabled', false);
    if ($aiEnabled && ($aiBase === '' || $aiModel === '')) {
        return ['ok' => false, 'message' => '开了大模型兜底就必须填「接口地址」和「模型名」'];
    }
    if ($aiBase !== '' && !preg_match('#^https?://#i', $aiBase)) {
        return ['ok' => false, 'message' => '接口地址要以 http:// 或 https:// 开头'];
    }

    $aiKeyInput = trim((string) param($input, 'ai_api_key', '', 300));
    $items['ai'] = [
        'enabled'       => $aiEnabled,
        'base_url'      => $aiBase,
        // 密钥留空 = 不改。界面上不回显原值（避免密钥出现在 HTML 里），
        // 想清空就勾「清除已保存的密钥」。
        'api_key'       => bool_param($input, 'ai_api_key_clear', false)
            ? ''
            : ($aiKeyInput !== '' ? $aiKeyInput : (string) Config::get('ai.api_key', '')),
        'model'         => $aiModel,
        'timeout'       => max(3, min(30, int_param($input, 'ai_timeout', 6))),
        'max_tokens'    => max(200, min(2000, int_param($input, 'ai_max_tokens', 700))),
        'temperature'   => 0.2,
        'per_ip_hourly' => max(1, min(50, int_param($input, 'ai_per_ip_hourly', 5))),
    ];

    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败，请检查 config 目录权限'];
    }

    record_event(null, 'admin', 'settings.save', '更新系统设置');

    // 改了后台路径：旧路径上的会话 cookie 立刻失效，所以先把这次请求引到新地址
    if ($pathChanged) {
        $next = ConsoleAuth::url();
        if (expects_json()) {
            return ['ok' => true, 'message' => '设置已保存，后台路径已更换', 'redirect' => $next];
        }
        // 用 HTML 跳转而不是 302：表单提交后浏览器行为更可控
        header('Content-Type: text/html; charset=utf-8');
        $page = '<!DOCTYPE html><meta charset="utf-8"><title>后台路径已更换</title>';
        $page .= '<div style="font:15px/1.7 sans-serif;max-width:560px;margin:14vh auto;padding:0 20px">';
        $page .= '<h2 style="margin:0 0 10px">后台入口已更换</h2>';
        $page .= '<p style="color:#5b6673">出于安全考虑，这次会话不会自动带到新地址，需要重新输入一次口令。</p>';
        $page .= '<p style="margin-top:18px"><a href="' . e($next) . '" style="display:inline-block;'
            . 'padding:10px 20px;background:#22c55e;color:#fff;border-radius:9px;text-decoration:none">'
            . '前往新的后台地址</a></p>';
        $page .= '<p style="color:#98a2b3;font-size:13px;margin-top:16px">'
            . '建议立即把这个地址存进浏览器书签。旧地址从现在起返回 404。</p></div>';
        exit($page);
    }

    return ['ok' => true, 'message' => '设置已保存'];
}

/**
 * @param array<string,mixed> $input
 * @return array{ok:bool,message:string}
 */
/**
 * 上传站点图标（favicon）或邮件 Logo。
 *
 * 两个槽位共用一套校验，见 src/Brand.php。这里只负责：认槽位、转发、写审计。
 *
 * @param array<string,mixed> $input
 * @param array<string,mixed> $files $_FILES
 */
function admin_brand_upload(array $input, array $files): array
{
    $slot = (string) ($input['brand_slot'] ?? '');
    if ($slot !== 'favicon' && $slot !== 'logo') {
        return ['ok' => false, 'message' => '未知的图片槽位'];
    }

    $field = $slot === 'favicon' ? 'favicon_file' : 'logo_file';
    $result = Brand::store($slot, $files[$field] ?? null);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => (string) ($result['error'] ?? '上传失败')];
    }

    $info = Brand::get($slot);
    $label = $slot === 'favicon' ? '站点图标' : '邮件 Logo';
    record_event(
        null,
        'admin',
        'console.brand_upload',
        $label . '已更新（' . ($info['file'] ?? '') . '，'
            . (int) round(((int) ($info['bytes'] ?? 0)) / 1024) . ' KB）'
    );

    return [
        'ok'      => true,
        'message' => $label . '已更新（' . (int) ($info['width'] ?? 0) . '×' . (int) ($info['height'] ?? 0)
            . '）。浏览器可能还缓存着旧图标，强制刷新（Ctrl/Cmd+Shift+R）就能看到。',
    ];
}

/**
 * 清除某个槽位的图片，退回内置默认。
 *
 * @param array<string,mixed> $input
 */
function admin_brand_clear(array $input): array
{
    $slot = (string) ($input['brand_slot'] ?? '');
    if ($slot !== 'favicon' && $slot !== 'logo') {
        return ['ok' => false, 'message' => '未知的图片槽位'];
    }

    Brand::remove($slot);

    $label = $slot === 'favicon' ? '站点图标' : '邮件 Logo';
    record_event(null, 'admin', 'console.brand_clear', $label . '已清除，退回默认');

    return ['ok' => true, 'message' => $label . '已清除'];
}

/**
 * 上传邮件 Logo 的测试发信入口在别处（email_test），这里只管图片本身。
 *
 * 为什么图标和 Logo 不做成"填一个图片 URL"：
 * 远程图片在邮件里默认被拦（收件人看到灰框），站点图标用远程地址也会
 * 多一次外部请求 —— 而且用户多半没有图床。上传是最省事的路径。
 */

/**
 * @param array<string,mixed> $input
 */
function admin_change_password(array $input): array
{
    $current = (string) ($input['current_password'] ?? '');
    $next = (string) ($input['new_password'] ?? '');
    $confirm = (string) ($input['confirm_password'] ?? '');

    $hash = (string) Config::get('app.admin_password', '');
    if (!password_verify($current, $hash)) {
        return ['ok' => false, 'message' => '当前密码不正确'];
    }
    if (strlen($next) < 8) {
        return ['ok' => false, 'message' => '新密码至少 8 位'];
    }
    if ($next !== $confirm) {
        return ['ok' => false, 'message' => '两次输入的新密码不一致'];
    }

    $items = (array) Config::get('', []);
    $items['app']['admin_password'] = password_hash($next, PASSWORD_DEFAULT);
    if (!Config::save($items)) {
        return ['ok' => false, 'message' => '配置写入失败'];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    // 指纹随密码哈希变化 —— 其它已登录的设备会立刻掉线，这是有意的
    $_SESSION['console_fp'] = ConsoleAuth::sessionFingerprint((string) $items['app']['admin_password']);
    $_SESSION['console_csrf'] = bin2hex(random_bytes(16));

    record_event(null, 'admin', 'console.password_change', '管理员修改了后台登录口令');

    return ['ok' => true, 'message' => '口令已更新，其它设备上的后台会话已失效'];
}

/**
 * 手动跑一次维护任务（等价于 cron 执行的东西）。
 *
 * @return array{ok:bool,message:string}
 */
function admin_run_maintenance(string $job): array
{
    $done = [];
    $now = time();

    if (in_array($job, ['all', 'reclaim'], true)) {
        $n = Task::reclaim(180);
        $done[] = '回收僵尸任务 ' . $n . ' 条';
    }

    if (in_array($job, ['all', 'followup'], true)) {
        $n = admin_followup_pending_fixes();
        $done[] = '跟进待复验工单 ' . $n . ' 条';
    }

    if (in_array($job, ['all', 'cleanup'], true)) {
        $a = Cache::gc();
        $b = Rate::gc();
        $done[] = '清理缓存 ' . $a . ' 个 / 限流文件 ' . $b . ' 个';
    }

    if (in_array($job, ['all', 'email'], true)) {
        $m = \MCFix\TicketMail::drain(20);
        $done[] = '发出邮件 ' . $m['sent'] . ' 封（失败 ' . $m['failed'] . '）';
    }

    if (in_array($job, ['all', 'autoclose'], true)) {
        // 兜底归档：老数据里可能还留着 status = 'resolved'（升级前要等 cron 才收敛），
        // rejected 也一并收敛（驳回是终态，但状态名保留）。见 bin/mcfix.php 里的同款说明。
        $days = (int) Config::get('feedback.autoclose_days', 7);
        $n = Db::exec(
            "UPDATE feedback SET status = 'closed', closed_at = :now, updated_at = :now
             WHERE status IN ('resolved', 'rejected') AND updated_at < :deadline",
            ['now' => now(), 'deadline' => gmdate('Y-m-d H:i:s', $now - $days * 86400)]
        );
        $done[] = '归档终态工单 ' . $n . ' 条';
    }

    // 日志原文留存：cron 每分钟也会跑一次，这里只是让管理员能立刻看到效果
    if (in_array($job, ['all', 'logretention'], true)) {
        $retention = (int) Config::get('feedback.log_retention_days', 30);
        if ($retention <= 0) {
            $done[] = '日志原文留存已关闭（0 = 不清理）';
        } else {
            $n = Workflow::purgeExpiredLogs($retention);
            $done[] = $n > 0 ? ('清理日志原文 ' . $n . ' 条') : '没有到期可清理的日志原文';
        }
    }

    if (in_array($job, ['all', 'pingcache'], true)) {        // 之前这里只 Cache::forget('ping:survival')，是作者自己服务器的硬编码 ID。
        // 改成把所有服务器的在线探测缓存都清掉 —— 调试时"我明明启动了它还显示离线"就是缓存没过期。
        $swept = 0;
        foreach (array_keys(Config::servers()) as $sid) {
            foreach (['ping:' . $sid, 'stat:' . $sid, 'log:' . $sid] as $key) {
                Cache::forget($key);
            }
            $swept++;
        }
        $done[] = '清理 ' . $swept . ' 台服务器的在线探测缓存';
    }

    record_event(null, 'admin', 'maintenance.run', '手动执行维护任务（' . $job . '）：' . implode('，', $done));

    return ['ok' => true, 'message' => '维护完成：' . implode('，', $done)];
}

/**
 * 找出卡在 fixing / verifying 的工单，尝试推进（Agent 掉线后也能自愈）。
 */
function admin_followup_pending_fixes(): int
{
    $rows = Db::all(
        "SELECT * FROM feedback WHERE status IN ('fixing','verifying','diagnosed') AND updated_at < :deadline ORDER BY id ASC LIMIT 20",
        ['deadline' => gmdate('Y-m-d H:i:s', time() - 10)]
    );

    $count = 0;
    foreach ($rows as $feedback) {
        $tasks = Task::forFeedback((int) $feedback['id'], 5);
        $pending = false;
        $failed = false;
        foreach ($tasks as $task) {
            if (in_array((string) $task['status'], ['queued', 'running'], true)) {
                $pending = true;
            }
            if ((string) $task['status'] === 'failed') {
                $failed = true;
            }
        }

        if ($pending && time() - ts((string) $feedback['updated_at']) < 300) {
            continue; // 还在跑，给足 5 分钟
        }

        if ($pending) {
            // 5 分钟还没动静：判 Agent 不在线，转人工
            Workflow::escalate((int) $feedback['id'], 'MC 侧执行器长时间没有响应，已转人工处理（请检查 Agent 是否在运行）');
            $count++;
            continue;
        }

        // 没有待执行任务了：做一次复验
        Workflow::reverify($feedback, '');
        $count++;
    }

    return $count;
}
