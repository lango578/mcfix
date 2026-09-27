<?php
/**
 * 安装向导：?r=install
 *
 * 三步走完就能用：
 *   1. 环境自检（PHP 版本、扩展、目录权限）
 *   2. 设置管理员密码 + 站点地址（生成签名密钥）
 *   3. （可选）添加第一台 MC 服务器 → 拿到 Agent 部署配置
 *
 * 只要 config/config.php 存在且密码已设置，本页就自动跳转到后台。
 */

declare(strict_types=1);

use MCFix\Agent;
use MCFix\Config;
use MCFix\Executor;
use MCFix\Share;

// ---------------------------------------------------------------- 安装门槛
//
// 这里的判断是整个系统最要紧的一道闸门，规则只有一条：
//
//   只要 config/config.php 已经存在，安装向导就永远不会再写「管理员密码」。
//
// 为什么不能用「admin_password 是不是空的」来判断？因为那样会留下一条完整的提权链：
// 攻击者只要等到（或诱导管理员制造出）admin_password 为空的配置，POST 一次
// ?r=install&step=2 就能自己设一个密码，并且当场被塞进一个已登录的后台会话，
// 从此拿到 RCON 口令、面板 API Key、Agent 令牌与全部修复能力。
// 忘记密码只能走命令行：php bin/mcfix.php reset-password
//
// 第 3 步（添加第一台 MC 服务器）是安装后的便利入口，保留，但要求已登录后台。
$hasConfig = Config::ready();
$hasAdmin = (string) Config::get('app.admin_password', '') !== '';

// 全程使用后台自己的会话（独立会话名 mcfix_console）。
// 全新安装时这里起的就是后台会话，所以第 2 步建立的登录态在第 3 步能直接读到，
// 安装完成后也不会有"装完了却是未登录状态"的断层。
if (PHP_SAPI !== 'cli') {
    \MCFix\ConsoleAuth::initSession();
}

/** 是否允许写管理员密码（只在「从未初始化」时为真） */
$canSetup = !$hasConfig;
/** 是否允许添加服务器（已初始化 + 密码已设置 + 已登录后台） */
$canAddServer = $hasConfig && $hasAdmin && \MCFix\ConsoleAuth::isAuthed();

$step = (int) param($_GET, 'step', $canSetup ? '1' : '3');
if ($step < 1 || $step > 3) {
    $step = 1;
}

// 第 2 步是唯一能写密码的地方：装过之后直接关门
if ($step === 2 && !$canSetup) {
    header('Location: ' . \MCFix\ConsoleAuth::url());
    exit;
}

// 其余步骤在装过之后必须已登录，否则回后台登录页
if ($step !== 2 && !$canSetup && !$canAddServer) {
    header('Location: ' . \MCFix\ConsoleAuth::url());
    exit;
}

$errors = [];
$notice = '';
$generated = null;

// ---------------------------------------------------------------- 环境自检

$checks = [
    [
        'label' => 'PHP 版本',
        'value' => PHP_VERSION,
        'ok'    => PHP_VERSION_ID >= 70400,
        'hint'  => '需要 7.4 或更高（宝塔推荐 8.0 / 8.1）',
    ],
    [
        'label' => 'PDO + SQLite',
        'value' => extension_loaded('pdo_sqlite') ? '已安装' : '缺失',
        'ok'    => extension_loaded('pdo_sqlite'),
        'hint'  => '宝塔 PHP 设置 → 安装扩展 → 勾选 pdo_sqlite / sqlite3',
    ],
    [
        'label' => 'mbstring',
        'value' => extension_loaded('mbstring') ? '已安装' : '缺失',
        'ok'    => extension_loaded('mbstring'),
        'hint'  => '用于中文字符处理',
    ],
    [
        'label' => 'openssl',
        'value' => extension_loaded('openssl') ? '已安装' : '缺失',
        'ok'    => extension_loaded('openssl'),
        'hint'  => '生成签名密钥、访问 HTTPS 面板需要',
    ],
    [
        'label' => 'JSON',
        'value' => extension_loaded('json') ? '已安装' : '缺失',
        'ok'    => extension_loaded('json'),
        'hint'  => '接口通信必需',
    ],
    [
        'label' => 'fsockopen / stream_socket_client',
        'value' => function_exists('stream_socket_client') ? '可用' : '不可用',
        'ok'    => function_exists('stream_socket_client'),
        'hint'  => '用于探测 MC 服务器与 RCON 通信',
    ],
    [
        'label' => 'proc_open（可选）',
        'value' => in_array('proc_open', \MCFix\Executor::disabledFunctions(), true) ? '被禁用' : '可用',
        'ok'    => !in_array('proc_open', \MCFix\Executor::disabledFunctions(), true),
        'hint'  => '只有 local / ssh 执行器才需要；用 agent 模式可以不开启。在宝塔「PHP 设置 → 禁用函数」里删掉 proc_open 即可',
        'soft'  => true,
    ],
    [
        'label' => 'storage 目录可写',
        'value' => is_writable(storage_path()) || @mkdir(storage_path('data'), 0755, true) ? '可写' : '不可写',
        'ok'    => is_writable(storage_path()) || is_dir(storage_path('data')),
        'hint'  => '宝塔里执行：chown -R www:www ' . MCFIX_ROOT . '/storage && chmod -R 775 ' . MCFIX_ROOT . '/storage',
    ],
    [
        'label' => 'config 目录可写',
        'value' => is_writable(MCFIX_ROOT . '/config') || @mkdir(MCFIX_ROOT . '/config', 0755, true) ? '可写' : '不可写',
        'ok'    => is_writable(MCFIX_ROOT . '/config') || is_dir(MCFIX_ROOT . '/config'),
        'hint'  => '安装向导要往这里写 config.php',
    ],
];

$hardFail = false;
foreach ($checks as $check) {
    if (empty($check['ok']) && empty($check['soft'])) {
        $hardFail = true;
    }
}

// ---------------------------------------------------------------- 表单处理

if (is_post()) {
    $input = request_data();
    $formStep = int_param($input, 'step', 1);

    // 双保险：即使有人直接在 query string 里绕，POST 也要再过一次同样的闸门
    if ($formStep === 2 && !$canSetup) {
        app_log('warn', '拒绝已安装站点的安装向导写操作', ['ip' => client_ip()]);
        header('Location: ' . \MCFix\ConsoleAuth::url());
        exit;
    }
    if ($formStep === 3 && !$canAddServer) {
        app_log('warn', '拒绝未登录的安装向导加服务器操作', ['ip' => client_ip()]);
        header('Location: ' . \MCFix\ConsoleAuth::url());
        exit;
    }

    // 第 3 步会往 config/config.php 里写服务器（含 RCON 密码、Agent 令牌、
    // 自定义守护命令），是实打实的写操作。以前它只靠"已登录"这一条挡着，
    // 而这个项目对外声称"后台每个写操作都校验 CSRF" —— 这里就是那个例外。
    // 第 2 步不用查：那时还没有后台会话，闸门是"配置文件不存在"。
    if ($formStep === 3) {
        \MCFix\ConsoleAuth::checkCsrf($input);
    }

    if ($formStep === 2) {
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['confirm'] ?? '');
        $siteName = param($input, 'site_name', 'MC 故障反馈中心', 60);
        $baseUrl = rtrim(param($input, 'base_url', '', 200), '/');
        $timezone = param($input, 'timezone', 'Asia/Shanghai', 40);

        if (strlen($password) < 8) {
            $errors[] = '管理员密码至少 8 位';
        }
        if ($password !== $confirm) {
            $errors[] = '两次输入的密码不一致';
        }
        if ($baseUrl === '') {
            // 没填就按当前访问地址推断
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
            $baseUrl = $scheme . '://' . $host . $dir;
        }
        if (strpos($baseUrl, 'http') !== 0) {
            $errors[] = '站点地址必须是 http:// 或 https:// 开头的完整地址';
        }

        if (!$errors) {
            $existing = (array) Config::get('', []);
            $config = array_merge([
                'app' => [],
                'db'  => ['driver' => 'sqlite', 'path' => 'storage/data/mcfix.sqlite'],
                'servers' => [],
                'feedback' => [
                    'token_ttl'           => 86400,
                    'rate_limit_per_hour' => 5,
                    'verify_per_hour'     => 40,
                    'sync_wait_seconds'   => 8,
                    'autoclose_days'      => 7,
                ],
                'automation' => [
                    'auto_fix' => true,
                    'require_approval_for_restart' => true,
                    'max_fix_attempts' => 2,
                    'verify_delay' => 5,
                    'verify_delay_restart' => 25,
                    'circuit_breaker_failures' => 3,
                    // 熔断后的观察窗口（秒）。超过这个时间的旧失败不再计入，
                    // 所以熔断会自然恢复，不会永久锁死自动修复。
                    'circuit_window_seconds' => 3600,
                ],
                // 默认不信任任何代理：安装时无从判断前面有没有反代，
                // 而信任回环的默认值在"REMOTE_ADDR 本来就是 127.0.0.1"的部署上
                // 会让客户端自填的 X-Forwarded-For 变成有效来源，
                // 后台 IP 白名单和登录锁定都会失效。
                // 真在反代后面的话，装完到 config.php 里显式填上代理地址。
                'trusted_proxies' => [],
            ], $existing);

            $config['app'] = array_merge((array) ($config['app'] ?? []), [
                'name'           => $siteName,
                'base_url'       => $baseUrl,
                'admin_password' => password_hash($password, PASSWORD_DEFAULT),
                'hmac_secret'    => bin2hex(random_bytes(32)),
                'timezone'       => $timezone,
                'debug'          => false,
            ]);

            // 后台私有入口路径：每次安装随机生成，避免落在可猜的位置
            if (empty($config['admin']['path'])) {
                $config['admin']['path'] = 'console-' . bin2hex(random_bytes(8));
            }

            if (!Config::save($config)) {
                $errors[] = '写入 config/config.php 失败，请检查 config 目录权限';
            } else {
                // 安装完成即视为已登录：初始化后台自己的会话（独立会话名 + 私有 cookie 路径）
                \MCFix\ConsoleAuth::initSession();
                $_SESSION['console_authed'] = true;
                $_SESSION['console_fp'] = \MCFix\ConsoleAuth::sessionFingerprint((string) $config['app']['admin_password']);
                $_SESSION['console_csrf'] = bin2hex(random_bytes(16));
                $_SESSION['console_since'] = time();

                header('Location: ' . abs_url('?r=install&step=3'));
                exit;
            }
        }

        $step = 2;
    } elseif ($formStep === 3) {
        $serverId = param($input, 'server_id', '', 40);
        $name = param($input, 'name', '', 60);
        $host = param($input, 'host', '', 120);
        $port = int_param($input, 'port', 25565);
        $mcDir = param($input, 'mc_dir', '/www/minecraft/server', 255);
        $logPath = param($input, 'log_path', 'logs/latest.log', 255);
        $rcPass = param($input, 'rcon_password', '', 120);
        $guardType = param($input, 'guard_type', 'systemd');
        $guardService = param($input, 'guard_service', '', 80);

        if ($name === '' || $host === '') {
            $errors[] = '服务器名称与地址必填（也可以点「稍后再加」直接进后台）';
            $step = 3;
        } else {
            if ($serverId === '') {
                $serverId = strtolower(preg_replace('/[^a-z0-9]/', '', $name) ?: 'srv');
                $serverId = substr($serverId, 0, 24);
            }
            $config = (array) Config::get('', []);
            $exists = false;
            foreach ((array) ($config['servers'] ?? []) as $server) {
                if ((string) ($server['id'] ?? '') === $serverId) {
                    $exists = true;
                }
            }
            if ($exists) {
                $serverId .= '-' . substr(bin2hex(random_bytes(2)), 0, 3);
            }

            $agentToken = bin2hex(random_bytes(16));
            $config['servers'][] = [
                'id'                => $serverId,
                'name'              => $name,
                'host'              => $host,
                'port'              => $port,
                'enabled'           => true,
                'require_mc_online' => true,
                'max_players'       => 100,
                'share_secret'      => bin2hex(random_bytes(24)),
                'rcon'              => [
                    'enabled'  => $rcPass !== '',
                    'host'     => $host,
                    'port'     => 25575,
                    'password' => $rcPass,
                    'timeout'  => 3,
                ],
                'executor'    => 'agent',
                'agent_token' => $agentToken,
                'mc_dir'      => $mcDir,
                'disk_path'   => dirname($mcDir),
                'log_path'    => $logPath,
                'listen_port' => $port,
                'guard'       => [
                    'type'        => $guardType,
                    'service'     => $guardService,
                    'session'     => '',
                    'start_cmd'   => '',
                    'stop_cmd'    => '',
                    'restart_cmd' => '',
                    'max_restarts_per_hour' => 3,
                ],
                'task_timeout' => 90,
                'recipes'      => [],
            ];

            if (!Config::save($config)) {
                $errors[] = '写入配置失败，请检查 config 目录权限';
                $step = 3;
            } else {
                $server = Config::server($serverId);
                $generated = $server !== null ? [
                    'server' => $server,
                    'config' => Agent::bootstrapConfig($server),
                    'share'  => Share::link($server),
                ] : null;
                $notice = '服务器已添加，把下面的配置贴到 MC 机器上即可。';
            }
        }
    }
}

// 第三步默认展示信息
if ($step === 3 && $generated === null) {
    $servers = Config::servers();
    if ($servers) {
        $first = reset($servers);
        if (is_array($first)) {
            $generated = [
                'server' => $first,
                'config' => Agent::bootstrapConfig($first),
                'share'  => Share::link($first),
            ];
        }
    }
}

render_layout([
    'title'    => '安装向导 · MC 故障反馈系统',
    'siteName' => (string) (Config::get('app.name') ?: 'MC 故障反馈中心'),
    'headNav'  => [],
], function () use ($step, $checks, $hardFail, $errors, $notice, $generated): void {
    ?>
    <div class="install">
      <header class="install-head">
        <h1>⛏ MC 故障反馈与自动修复系统</h1>
        <p class="hint">
          玩家点一个链接提交问题 → 系统真的连一次服务器做验证 → 能修的（白名单、误封、卡崩、插件报错）立刻自动修好 → 复验后把结果写回工单。
        </p>
        <ol class="install-steps">
          <li class="<?= $step === 1 ? 'is-active' : ($step > 1 ? 'is-done' : '') ?>">1 环境自检</li>
          <li class="<?= $step === 2 ? 'is-active' : ($step > 2 ? 'is-done' : '') ?>">2 管理员与站点</li>
          <li class="<?= $step === 3 ? 'is-active' : '' ?>">3 接入 MC 服务器</li>
        </ol>
      </header>

      <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endforeach; ?>
      <?php if ($notice !== ''): ?>
        <div class="alert alert-info"><?= e($notice) ?></div>
      <?php endif; ?>

      <?php if ($step === 1): ?>
        <section class="card">
          <h2>环境自检</h2>
          <table class="table table-compact">
            <thead><tr><th>项目</th><th>状态</th><th>说明</th></tr></thead>
            <tbody>
              <?php foreach ($checks as $check): ?>
                <tr>
                  <td><?= e((string) $check['label']) ?></td>
                  <td>
                    <span class="pill pill-<?= !empty($check['ok']) ? 'ok' : (!empty($check['soft']) ? 'warn' : 'bad') ?>">
                      <?= e((string) $check['value']) ?>
                    </span>
                  </td>
                  <td class="sub"><?= e((string) $check['hint']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if ($hardFail): ?>
            <div class="alert alert-error">有必须解决的项未通过（红色部分），请先在宝塔里处理完再继续。</div>
            <a class="btn btn-ghost" href="<?= e(abs_url('?r=install&step=1')) ?>">重新检测</a>
          <?php else: ?>
            <div class="form-foot">
              <a class="btn btn-primary" href="<?= e(abs_url('?r=install&step=2')) ?>">下一步：设置管理员密码</a>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($step === 2): ?>
        <form method="post" class="card" autocomplete="off">
          <h2>管理员与站点</h2>
          <input type="hidden" name="step" value="2">

          <div class="field">
            <label for="password">管理员密码 <em>*</em></label>
            <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password"
                   placeholder="至少 8 位，别用 12345678">
          </div>
          <div class="field">
            <label for="confirm">再输一次 <em>*</em></label>
            <input id="confirm" name="confirm" type="password" required minlength="8" autocomplete="new-password">
          </div>
          <div class="field">
            <label for="site_name">站点名称</label>
            <input id="site_name" name="site_name" value="MC 故障反馈中心" maxlength="60">
          </div>
          <div class="field">
            <label for="base_url">站点对外地址</label>
            <input id="base_url" name="base_url" placeholder="留空自动识别，例如 https://mc.example.com">
            <p class="hint">玩家收到的反馈链接基于这个地址生成。换域名后可以在后台「系统设置」里改。</p>
          </div>
          <div class="field">
            <label for="timezone">时区</label>
            <input id="timezone" name="timezone" value="Asia/Shanghai">
          </div>

          <div class="form-foot">
            <button class="btn btn-primary" type="submit">保存并继续</button>
            <span class="hint">提交后会自动生成数据签名密钥，用于反馈链接与 Agent 令牌。</span>
          </div>
        </form>

      <?php else: ?>
        <?php if ($generated !== null): ?>
          <?php $server = (array) $generated['server']; ?>
          <section class="card">
            <h2>已完成 ✅ 接下来把 Agent 装到 MC 机器上</h2>
            <p class="hint">
              系统已经能用了：<a href="<?= e(\MCFix\ConsoleAuth::url()) ?>">进入管理后台</a> ·
              <a href="<?= e((string) $generated['share']['url']) ?>" target="_blank" rel="noopener">看看玩家看到的页面</a>
            </p>

            <div class="alert alert-warn">
              <b>先把这两条链接分别存好 —— 它们是两个独立入口：</b>
              <div style="margin-top:10px">
                <div><b>① 玩家反馈链接</b>（发到群里，任何人都能打开）</div>
                <input class="copy-field" readonly value="<?= e((string) $generated['share']['url']) ?>" onclick="this.select()">
                <div style="margin-top:12px"><b>② 管理后台地址</b>（只给你自己，别外传，建议存进书签）</div>
                <input class="copy-field" readonly value="<?= e(\MCFix\ConsoleAuth::url()) ?>" onclick="this.select()">
              </div>
              <p class="hint" style="margin-top:10px">
                后台入口是一段随机路径，玩家页面上没有任何指向它的链接，两边的登录状态也互相隔离。
                忘了这个地址就去 <code>config/config.php</code> 里搜 <code>'path' =&gt;</code>。
              </p>
            </div>

            <h3>1. MC 机器上的 Agent 配置（保存为 /opt/mcfix/config.php）</h3>
            <pre class="raw-pre"><?php
            // 直接下发布 bootstrapConfig() 的完整结果，不再手抄字段 ——
            // 手抄漏掉 rcon 的话，Agent 能连上但一个配方也修不了。
            $agentConfig = array_merge((array) $generated['config'], [
                'interval'     => 8,
                'poll_timeout' => 20,
            ]);
            echo e("<?php\nreturn " . var_export($agentConfig, true) . ";\n");
            ?></pre>

            <h3>3. 在 MC 机器上执行</h3>
            <pre class="raw-pre">mkdir -p /opt/mcfix
# 把项目里的 agent/mcfix-agent.php 上传到 /opt/mcfix/
# 把上面的配置保存为 /opt/mcfix/config.php
php /opt/mcfix/mcfix-agent.php --check     # 自检
php /opt/mcfix/mcfix-agent.php --once      # 跑一轮看看能不能连上面板</pre>

            <h3>4. 常驻运行</h3>
            <pre class="raw-pre">cat > /etc/systemd/system/mcfix-agent.service <<'EOF'
[Unit]
Description=MC Fix Agent
After=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/php /opt/mcfix/mcfix-agent.php
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload && systemctl enable --now mcfix-agent</pre>

            <h3>5. 面板侧计划任务（宝塔 → 计划任务 → Shell 脚本，每分钟）</h3>
            <p class="hint">
              下面这条先用 <code>which php</code> 确认路径再填进宝塔 —— 网页里拿到的
              <code>PHP_BINARY</code> 是 php-fpm，不能拿来跑命令行脚本。
            </p>
            <pre class="raw-pre">cd <?= e(MCFIX_ROOT) ?> && <?= e(cli_php_path()) ?> bin/mcfix.php cron</pre>

            <div class="form-foot">
              <a class="btn btn-primary" href="<?= e(\MCFix\ConsoleAuth::url('p=servers')) ?>">去后台查看 Agent 状态</a>
              <a class="btn btn-ghost" href="<?= e(\MCFix\ConsoleAuth::url('p=help')) ?>">详细接入文档</a>
            </div>
          </section>
        <?php else: ?>
          <form method="post" class="card">
            <h2>添加第一台 MC 服务器</h2>
            <p class="hint">也可以点下面的「稍后再加」，先直接进后台。</p>
            <?= \MCFix\ConsoleAuth::csrfField() ?>
            <input type="hidden" name="step" value="3">

            <div class="field-grid">
              <label>显示名 *<input name="name" placeholder="生存服 1.20.1" required></label>
              <label>服务器ID（留空自动）<input name="server_id" placeholder="survival"></label>
              <label>地址 *<input name="host" placeholder="mc.example.com 或 10.0.0.21" required></label>
              <label>端口<input name="port" type="number" value="25565"></label>
              <label>MC 服务端目录<input name="mc_dir" value="/www/minecraft/server"></label>
              <label>日志路径<input name="log_path" value="logs/latest.log"></label>
              <label>RCON 密码（可留空）<input name="rcon_password" placeholder="强烈建议填，能秒修白名单/解封"></label>
              <label>守护方式
                <select name="guard_type">
                  <option value="systemd">systemd</option>
                  <option value="screen">screen</option>
                  <option value="tmux">tmux</option>
                  <option value="panel">宝塔计划任务</option>
                  <option value="none">不自动重启</option>
                </select>
              </label>
              <label>systemd 单元名 / 会话名<input name="guard_service" placeholder="minecraft-survival"></label>
            </div>

            <div class="form-foot">
              <button class="btn btn-primary" type="submit">添加并生成 Agent 配置</button>
              <a class="btn btn-ghost" href="<?= e(\MCFix\ConsoleAuth::url()) ?>">稍后再加</a>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
});
