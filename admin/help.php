<?php
/**
 * 后台：接入与排查 —— Agent 部署、计划任务、反馈链接、常见故障对照表。
 *
 * 这一页是给"照着做"用的：所有命令都可以直接复制，不含任何占位符以外的隐私信息。
 */

declare(strict_types=1);

use MCFix\Config;
use MCFix\Token;

$redirect = \MCFix\ConsoleAuth::url('p=help');
$baseUrl = rtrim((string) Config::get('app.base_url', ''), '/');
$serverList = Config::servers();

/*
 * 生成每台服务器的玩家反馈链接。
 *
 * ★ 这里**不能**写配置（V12）。
 *
 * 原来每次渲染都调 Share::ensureSecret() —— 它会 rotate() 并落盘。也就是说
 * 一个 GET 请求会修改 config/config.php。GET 按语义应当是只读的，而它会
 * 被浏览器预取、爬虫、`<img src>`、链接预览等**自动**触发；在"双域名 + 后台
 * 域名"的部署里，这些自动请求又会带上后台会话 cookie。
 *
 * 密钥缺失时的正确做法是"按派生值算链接，但不落盘"：
 * Share::link() 在 share_secret 为空时会用**这台服务器专属的派生密钥**
 * （全局密钥 + 服务器 id，见 Share::secretFor()）签名，链接照样能正常生成、
 * 玩家照样能用，而且管理员点「重新生成」落盘独立密钥之后，这条派生密钥签的
 * 链接立刻失效。
 *
 * 注意这里**不能**退回全局 hmac_secret 签名：Token::candidateSecrets() 永远
 * 把全局密钥算作候选，用它签出来的链接无论点多少次「重新生成」都作废不了。
 * 真正需要落盘的时机是管理员**显式**点「重新生成」——那才是一次有意的写操作。
 */
$links = [];
foreach ($serverList as $id => $server) {
    $links[(string) $id] = \MCFix\Share::link($server);
}
?>
<header class="page-head">
  <div>
    <h1>接入与排查</h1>
    <p class="hint">从零到能自动修，只需要三步：配服务器 → 部署 Agent → 发链接。</p>
  </div>
</header>

<section class="card">
  <h2>第一步：玩家反馈链接</h2>
    <p class="hint">
    这种链接不绑定具体工单，任何玩家打开都能提交反馈并触发自动验证。
    发到 QQ 群 / 公告 / 服务器 MOTD 里都可以。链接 30 天有效，点「重新生成」旧链接立刻失效。
  </p>
  <p class="hint">
    <b>每台服务器各有一条链接。</b>玩家用哪台的链接进来，表单就只列那一台 ——
    这样你可以把生存服的链接发生存服群、创造服的发创造服群，玩家不会报错台。
    提交时后端还会再核对一次，改表单也换不了台。
    「不涉及服务器（纯客户端报错）」不受影响，锁定时照样能选。
  </p>
  <p class="hint">默认链接只对「未启用白名单限制」的场景友好；如果担心被刷，可以把有效期设短一点，或在「系统设置」里调低每 IP 每小时提交上限。</p>
  <?php foreach ($links as $id => $share): ?>
    <div class="share-row">
      <div>
        <b><?= e(server_public_label((string) $id)) ?></b>
        <small>过期时间：<?= e(date('Y-m-d H:i', $share['expires'])) ?></small>
      </div>
      <input class="copy-field" readonly value="<?= e($share['url']) ?>" onclick="this.select()">
      <button class="btn btn-mini" type="button"
              onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='已复制'">复制</button>
      <form method="post" class="inline-form">
        <?= \MCFix\ConsoleAuth::csrfField() ?>
        <input type="hidden" name="admin_action" value="share_regenerate">
        <input type="hidden" name="server_id" value="<?= e((string) $id) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <button class="btn btn-mini" type="submit">重新生成</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$links): ?>
    <p class="hint">先去「服务器与 Agent」页添加一台服务器。</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>第二步：部署 MC 侧 Agent</h2>
  <p class="hint">
    Agent 是跑在 MC 机器上的一个小 PHP 脚本。它<b>主动</b>每 8 秒来面板领任务，
    所以你不需要在 MC 机器上开放任何端口、不需要公网 IP、不用改防火墙。
  </p>

  <div class="steps-list">
    <div class="step-item">
      <h3>1. 把 agent 目录上传到 MC 机器</h3>
      <p>从项目里把 <code>agent/mcfix-agent.php</code> 上传到 MC 机器的 <code>/opt/mcfix/</code>。</p>
      <pre class="raw-pre">mkdir -p /opt/mcfix
# 用宝塔文件管理器上传，或 scp：
# scp agent/mcfix-agent.php root@MC机器IP:/opt/mcfix/</pre>
    </div>

    <div class="step-item">
      <h3>2. 写入配置</h3>
      <p>在 <code>/opt/mcfix/config.php</code> 里填面板地址和令牌（令牌在上面的服务器卡片里能看到）。</p>
      <pre class="raw-pre"><?= e("<?php\nreturn [\n    // Agent 接口和玩家反馈接口同源，用玩家反馈站那个域名\n    'panel_url'  => '" . rtrim(\MCFix\ConsoleAuth::feedbackUrl(), '/') . "/?r=api.agent.poll',\n    'server_id'  => '你的服务器ID',\n    'token'      => '你的Agent令牌',\n    'mc_dir'     => '/www/minecraft/server',\n    'log_path'   => 'logs/latest.log',\n    'listen_port'=> 25565,\n    'disk_path'  => '/www/minecraft',\n    // 填了 RCON，Agent 自己也能执行白名单/解封/踢人这些指令\n    'rcon'       => [\n        'enabled'  => false,\n        'host'     => '127.0.0.1',\n        'port'     => 25575,\n        'password' => '',\n        'timeout'  => 3,\n    ],\n    'guard'      => [\n        'type'    => 'systemd',            // systemd | screen | tmux | panel\n        'service' => 'minecraft-survival', // systemd 单元名\n        'session' => 'mc-survival',        // screen/tmux 会话名\n        // type=panel（自定义命令）时才需要下面三行\n        'start_cmd' => '',\n        'stop_cmd'  => '',\n        'restart_cmd' => '',\n    ],\n    'interval'     => 8,\n    'poll_timeout' => 20,\n];\n") ?></pre>
      <p class="hint">
        更省事的办法：在「服务器与 Agent」页点开某台服务器，里面生成的配置块已经填好了
        上面所有字段，直接整段复制即可。
      </p>
    </div>

    <div class="step-item">
      <h3>3. 先手动跑一次，确认能连上面板</h3>
      <pre class="raw-pre">php /opt/mcfix/mcfix-agent.php --check      # 只做本地自检，不联网
php /opt/mcfix/mcfix-agent.php --once --verbose</pre>
      <p class="hint">
        跑完会打印一行 <code>MCFix Agent 退出</code>；如果连不上面板，会额外打印
        <code>轮询失败（连续 N 次）</code> 和具体原因。没有报错就是通了。
        然后回到这个页面，服务器卡片上会显示「Agent 在线」。
      </p>
    </div>

    <div class="step-item">
      <h3>4. 常驻运行（二选一）</h3>
      <p><b>方式 A：systemd（推荐）</b></p>
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

systemctl daemon-reload
systemctl enable --now mcfix-agent
systemctl status mcfix-agent</pre>

      <p><b>方式 B：宝塔计划任务（每分钟跑一次，不需要常驻）</b></p>
      <pre class="raw-pre">php /opt/mcfix/mcfix-agent.php --once</pre>
      <p class="hint">在宝塔【计划任务 → 添加任务 → Shell 脚本】里粘贴上面这行，周期选「每分钟」。
        这种模式下每次只领一次任务，延迟最多 1 分钟。</p>
    </div>

    <div class="step-item">
      <h3>5. 可选：开启 RCON（强烈建议）</h3>
      <p>RCON 让面板能直接执行游戏指令，很多问题（白名单、解封、踢人、保存世界）变成"秒修"，不需要 Agent 参与。</p>
      <pre class="raw-pre"># MC 机器上编辑 server.properties
enable-rcon=true
rcon.port=25575
rcon.password=换成一段够长的随机密码

# 重启一次服务端生效，然后在面板的服务器配置里填入同样的密码</pre>
      <p class="hint">安全提示：RCON 端口不要暴露到公网，只允许面板机器访问（MC 机器防火墙里放行面板 IP 即可）。</p>
    </div>
  </div>
</section>

<section class="card">
  <h2>第三步：配置计划任务（面板侧）</h2>
  <p class="hint">在宝塔【计划任务 → Shell 脚本】里加一条，每分钟执行，用于回收卡住的任务、异步复验、清理缓存。</p>
  <pre class="raw-pre">cd <?= e(MCFIX_ROOT) ?> && <?= e(cli_php_path()) ?> bin/mcfix.php cron</pre>
  <p class="hint">
    <b>必须用 php 命令行可执行文件</b>，不能用网页里拿到的 <code>PHP_BINARY</code> ——
    宝塔下面那个值是 <code>/www/server/php/83/sbin/php-fpm</code>，填进计划任务只会起一个 FPM 进程，
    脚本根本不会执行。先在 SSH 里跑一次 <code>which php</code> 确认路径
    （宝塔一般是 <code>/www/server/php/83/bin/php</code>）。
  </p>
  <pre class="raw-pre"># 也可以顺手加一条每日备份（.backup 会连 WAL 里的数据一起打包，
# 直接 cp 数据库文件在 WAL 模式下可能拿到不一致的快照）
sqlite3 <?= e(\MCFix\Db::sqlitePath()) ?> ".backup '/www/backup/mcfix-$(date +\%F).sqlite'"</pre>
</section>

<section class="card">
  <h2>疾病对照表：什么现象对应什么修复</h2>
  <table class="table table-compact">
    <thead><tr><th>玩家说</th><th>系统会验证什么</th><th>可能自动修的动作</th><th>需要什么前提</th></tr></thead>
    <tbody>
      <tr><td>进不去服</td><td>MC 协议连通、白名单、封禁、端口、日志</td><td>加入白名单 / 解封 / 重启</td><td>RCON（加白名单、解封）；Agent（重启）</td></tr>
      <tr><td>服务器崩了</td><td>进程、端口、磁盘、崩溃日志</td><td>重启服务端</td><td>Agent + 守护方式配置正确</td></tr>
      <tr><td>卡顿 / 掉帧</td><td>TPS、CPU、内存、磁盘、日志</td><td>强制保存世界 / 重启</td><td>RCON（tps）+ Agent</td></tr>
      <tr><td>回档 / 丢物品</td><td>存档写入时间、日志异常、TPS</td><td>保存世界 + 备份存档</td><td>Agent（备份）</td></tr>
      <tr><td>被封 / 被禁言</td><td>banlist、白名单</td><td>解封 / 解除禁言</td><td>RCON（解封）；Agent（插件 unmute）</td></tr>
      <tr><td>插件报错</td><td>日志异常归集、进程状态</td><td>重载插件 / 重启</td><td>RCON 或 Agent</td></tr>
      <tr><td>举报玩家</td><td>仅收集信息</td><td>不自动处理（人工）</td><td>—</td></tr>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>排查手册</h2>
  <details open>
    <summary>Agent 一直显示离线</summary>
    <ul class="problem-list">
      <li>MC 机器能不能访问面板地址？<code>curl -I <?= e(rtrim(\MCFix\ConsoleAuth::feedbackUrl(), '/')) ?>/?r=api.agent.poll</code></li>
      <li>令牌对不对？服务器卡片里的令牌必须和 <code>/opt/mcfix/config.php</code> 完全一致</li>
      <li>看日志：<code>php /opt/mcfix/mcfix-agent.php --once --verbose</code>，或 <code>/opt/mcfix/mcfix-agent.log</code></li>
      <li>如果配置了 <code>agent_ip_allow</code>，确认 MC 机器的出口 IP 在名单里</li>
    </ul>
  </details>
  <details>
    <summary>修复任务一直"待执行"</summary>
    <ul class="problem-list">
      <li>Agent 是否在跑：<code>systemctl status mcfix-agent</code></li>
      <li>面板计划任务是否在跑：观察「操作日志」里有没有 cron 记录</li>
      <li>超过 3~5 分钟没执行，系统会自动转人工，不会一直卡着（回收任务每分钟跑一次）</li>
    </ul>
  </details>
  <details>
    <summary>重启服务器失败</summary>
    <ul class="problem-list">
      <li>确认「守护方式」配对了：systemd 要填单元名，screen/tmux 要填会话名</li>
      <li>在 MC 机器上手工执行一次：<code>systemctl restart minecraft-survival</code>，看是否报错</li>
      <li>如果服务不是 root 起的，Agent 需要用同样的用户运行</li>
      <li>每小时重启配额（默认 3 次）用完会拒绝，可在服务器配置里调整</li>
    </ul>
  </details>
  <details>
    <summary>RCON 连不上</summary>
    <ul class="problem-list">
      <li>确认已重启过服务端（改 server.properties 必须重启）</li>
      <li>端口是否被占用或防火墙拦截：<code>ss -lntp | grep 25575</code></li>
      <li>密码不能有空格，且要和服务端里完全一致</li>
      <li>Paper 系建议装 RCON 时把 <code>broadcast-rcon-to-ops=false</code>，避免刷屏</li>
    </ul>
  </details>
</section>
