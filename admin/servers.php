<?php
/**
 * 后台：服务器与 Agent —— 配置 MC 服务器、查看 Agent 状态、复制部署命令。
 */

declare(strict_types=1);

use MCFix\Agent;
use MCFix\Catalog;
use MCFix\Config;
use MCFix\Db;
use MCFix\Executor;
use MCFix\Recipe;
use MCFix\Task;
use MCFix\Token;

// 后台列**所有**服务器，包含已停用的 —— 用 Config::servers() 的话，
// 一旦取消勾选「启用该服务器」，那台服就会从界面上消失、再也点不回来。
$servers = Config::allServers();
$seen = [];
foreach (Agent::all() as $row) {
    $seen[(string) $row['server_id']] = $row;
}
$editId = param($_GET, 'server_id');
$pendingByServer = [];
foreach (Db::all("SELECT server_id, COUNT(*) AS c FROM tasks WHERE status IN ('queued','awaiting_approval','running') GROUP BY server_id") as $row) {
    $pendingByServer[(string) $row['server_id']] = (int) $row['c'];
}
$redirect = \MCFix\ConsoleAuth::url('p=servers');
?>
<header class="page-head">
  <div>
    <h1>服务器与 Agent</h1>
    <p class="hint">这里的每一个开关都直接影响"玩家点一下能修好什么"。Agent 是跑在 MC 机器上的小程序。</p>
  </div>
</header>

<?php if (!$servers): ?>
  <div class="card empty-state">
    <h2>还没有配置服务器</h2>
    <p>先添加一台，之后才能生成反馈链接给玩家。</p>
  </div>
<?php endif; ?>

<?php foreach ($servers as $id => $server): ?>
  <?php
  $agent = $seen[$id] ?? null;
  $online = $agent !== null && !empty($agent['last_seen']) && (time() - ts((string) $agent['last_seen'])) < 120;
  $extra = (array) ($agent['extra_data'] ?? []);
  $recipesOn = 0;
  foreach (Recipe::all() as $code => $def) {
      if (Recipe::allowed($server, (string) $code)) {
          $recipesOn++;
      }
  }
  $isEditing = $editId === $id;

  /*
   * 熔断状态（V15）：连续失败达阈值后系统会停手，只诊断不修复。
   * 这个状态必须在后台**看得见**，否则管理员只会看到"工单一直是待处理"，
   * 完全不知道是系统主动停下了 —— 那种症状和"功能坏了"无法区分。
   */
  $circuitThreshold = (int) Config::get('automation.circuit_breaker_failures', 3);
  // 必须显式转成字符串：$id 是数组键，纯数字的 server id 在 PHP 里会变成 int 键，
  // 而这里 declare(strict_types=1)，int 传给 string 形参会直接 TypeError（整页 500）。
  $circuitFailures = \MCFix\Workflow::consecutiveFailures((string) $id);
  $circuitTripped = $circuitThreshold > 0 && $circuitFailures >= $circuitThreshold;
  ?>
  <section class="card server-card">
    <header class="server-head">
      <div>
        <h2>
          <?= e((string) $server['name']) ?>
          <span class="dot <?= $online ? 'dot-ok' : 'dot-bad' ?>"></span>
          <small><?= e((string) ($server['host'] ?? '') . ':' . (int) ($server['port'] ?? 0)) ?></small>
        </h2>
        <div class="tag-row">
          <span class="tag">ID: <?= e($id) ?></span>
          <?php if (empty($server['enabled'])): ?>
            <span class="tag tag-warn">已停用（玩家看不到，也不会被诊断）</span>
          <?php endif; ?>
          <span class="tag">执行器：<?= e((string) ($server['executor'] ?? 'none')) ?></span>
          <span class="tag">RCON：<?= !empty($server['rcon']['enabled']) ? '已开启' : '未开启' ?></span>
          <?php $panelInfo = \MCFix\PanelRegistry::summary($server); ?>
          <?php if ($panelInfo['configured']): ?>
            <span class="tag tag-ok">面板：<?= e($panelInfo['label']) ?>（<?= count($panelInfo['capabilities']) ?> 项能力）</span>
          <?php else: ?>
            <span class="tag">面板 API：未配置</span>
          <?php endif; ?>
          <span class="tag">守护：<?= e((string) ($server['guard']['type'] ?? 'systemd')) ?></span>
          <span class="tag">允许配方：<?= (int) $recipesOn ?>/<?= count(Recipe::all()) ?></span>
          <?php if ($circuitTripped): ?>
            <span class="tag tag-bad" title="连续自动修复失败达阈值，系统已暂停自动修复，只做诊断">
              自动修复已熔断（连续失败 <?= (int) $circuitFailures ?> 次）
            </span>
          <?php endif; ?>
          <?php if (!empty($pendingByServer[$id])): ?>
            <span class="tag tag-warn">待执行任务 <?= (int) $pendingByServer[$id] ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="page-actions">
        <?php if ($circuitTripped): ?>
          <form method="post" class="inline-form">
            <?= \MCFix\ConsoleAuth::csrfField() ?>
            <input type="hidden" name="admin_action" value="reset_circuit_breaker">
            <input type="hidden" name="server_id" value="<?= e($id) ?>">
            <input type="hidden" name="redirect" value="<?= e($redirect . '&server_id=' . urlencode($id)) ?>">
            <button class="btn btn-mini" type="submit"
                    title="确认 MC 侧已修好后点这里，立刻恢复自动修复（否则也会在熔断窗口到期后自动恢复）">
              解除熔断
            </button>
          </form>
        <?php endif; ?>
        <?php if (\MCFix\PanelRegistry::adapter($server) !== null): ?>
          <form method="post" class="inline-form">
            <?= \MCFix\ConsoleAuth::csrfField() ?>
            <input type="hidden" name="admin_action" value="panel_test">
            <input type="hidden" name="server_id" value="<?= e($id) ?>">
            <input type="hidden" name="redirect" value="<?= e($redirect . '&server_id=' . urlencode($id)) ?>">
            <button class="btn btn-mini" type="submit" title="验证面板接口是否连通">测试面板 API</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-mini" href="<?= e(\MCFix\ConsoleAuth::url('p=servers&server_id=' . urlencode($id))) ?>"><?= $isEditing ? '收起配置' : '配置' ?></a>
      </div>
    </header>

    <div class="server-body">
      <div class="server-status">
        <?php if ($agent === null): ?>
          <p class="warn-text">⚠️ MC 侧 Agent 从未上线。当前只能做「Minecraft 协议探测 + RCON」两类验证，重启/日志/进程类修复不可用。</p>
        <?php else: ?>
          <div class="meta-grid">
            <div><span>Agent</span><b class="<?= $online ? 'ok' : 'bad' ?>"><?= $online ? '在线' : '离线' ?></b></div>
            <div><span>版本</span><b><?= e((string) ($agent['agent_version'] ?? '—')) ?></b></div>
            <div><span>主机名</span><b><?= e((string) ($agent['hostname'] ?? '—')) ?></b></div>
            <div><span>最后心跳</span><b><?= e(human_time((string) $agent['last_seen'])) ?></b></div>
            <div><span>进程状态</span><b class="<?= !empty($agent['mc_online']) ? 'ok' : 'bad' ?>"><?= !empty($agent['mc_online']) ? '运行中' : '未运行' ?></b></div>
            <div><span>在线人数</span><b><?= (int) $agent['players'] ?>/<?= (int) $agent['max_players'] ?></b></div>
            <div><span>TPS</span><b><?= e((string) ($agent['tps'] !== null && $agent['tps'] !== '' ? $agent['tps'] : '—')) ?></b></div>
            <div><span>CPU / 内存</span><b><?= e((string) ($agent['process_cpu'] ?? '—')) ?>% / <?= e((string) ($agent['process_mem'] ?? '—')) ?></b></div>
            <div><span>磁盘剩余</span><b><?= e((string) ($agent['disk_free'] ?? '—')) ?> / <?= e((string) ($agent['disk_total'] ?? '—')) ?></b></div>
            <div><span>MOTD</span><b><?= e(mb_substr((string) ($extra['motd'] ?? '—'), 0, 60)) ?></b></div>
          </div>
          <?php if (!empty($agent['last_error'])): ?>
            <div class="alert alert-warn">Agent 最近报错：<?= e((string) $agent['last_error']) ?></div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ((string) ($server['executor'] ?? '') === 'agent'): ?>
          <details class="deploy-box">
            <summary>Agent 部署配置（点开复制）</summary>
            <p class="hint">在 MC 机器上保存为 <code>/opt/mcfix/config.php</code>（与 mcfix-agent.php 同目录），然后运行 <code>php mcfix-agent.php</code>。</p>
            <pre class="raw-pre"><?php
            // 整块下发 bootstrapConfig() 的结果，别再手抄一遍字段 ——
            // 手抄漏了 rcon，Agent 就"能连上但什么也修不了"。
            $agentConfig = array_merge(Agent::bootstrapConfig($server), [
                'interval'     => 8,
                'poll_timeout' => 20,
            ]);
            echo e("<?php\nreturn " . var_export($agentConfig, true) . ";\n");
            ?></pre>
            <p class="hint">也可以用签名令牌替代固定 token：
              <code><?= e(Token::agentToken($id)) ?></code>
            </p>
          </details>
        <?php endif; ?>
      </div>

      <?php if ($isEditing): ?>
        <form method="post" class="server-form">
          <?= \MCFix\ConsoleAuth::csrfField() ?>
          <input type="hidden" name="admin_action" value="save_server">
          <input type="hidden" name="server_id" value="<?= e($id) ?>">
          <input type="hidden" name="redirect" value="<?= e($redirect . '&server_id=' . urlencode($id)) ?>">

          <div class="field-grid">
            <label>代号<input name="code" value="<?= e((string) ($server['code'] ?? '')) ?>" placeholder="S1" maxlength="12"></label>
            <label>显示名<input name="name" value="<?= e((string) $server['name']) ?>"></label>
            <label>地址<input name="host" value="<?= e((string) $server['host']) ?>"></label>
            <label>端口<input name="port" type="number" value="<?= (int) $server['port'] ?>"></label>
            <label>MC 目录<input name="mc_dir" value="<?= e((string) ($server['mc_dir'] ?? '')) ?>" placeholder="/www/minecraft/server"></label>
            <label>日志路径<input name="log_path" value="<?= e((string) ($server['log_path'] ?? 'logs/latest.log')) ?>"></label>
            <label>磁盘检查路径<input name="disk_path" value="<?= e((string) ($server['disk_path'] ?? '')) ?>"></label>
            <label>执行器
              <select name="executor">
                <?php foreach (['agent' => 'agent（MC 侧小程序，推荐）', 'ssh' => 'ssh（面板 SSH 到 MC 机器）', 'local' => 'local（同一台机器）', 'none' => 'none（面板服选这个：用面板 API 修）'] as $key => $label): ?>
                  <option value="<?= e($key) ?>"<?= (string) ($server['executor'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>任务超时（秒）<input name="task_timeout" type="number" value="<?= (int) ($server['task_timeout'] ?? 90) ?>"></label>
            <label>最大人数<input name="max_players" type="number" value="<?= (int) ($server['max_players'] ?? 100) ?>"></label>
            <label>监听端口（可留空）<input name="listen_port" type="number" value="<?= (int) ($server['listen_port'] ?? $server['port']) ?>"></label>
          </div>

          <h3>RCON（关键：很多问题能秒修）</h3>
          <div class="field-grid">
            <label class="check-line"><input type="hidden" name="rcon_enabled" value="0"><input type="checkbox" name="rcon_enabled" value="1" <?= !empty($server['rcon']['enabled']) ? 'checked' : '' ?>> 启用 RCON</label>
            <label>RCON 地址<input name="rcon_host" value="<?= e((string) ($server['rcon']['host'] ?? $server['host'])) ?>"></label>
            <label>RCON 端口<input name="rcon_port" type="number" value="<?= (int) ($server['rcon']['port'] ?? 25575) ?>"></label>
            <label>RCON 密码<input name="rcon_password" value="<?= e((string) ($server['rcon']['password'] ?? '')) ?>"></label>
          </div>
          <p class="hint">在 MC 机器的 <code>server.properties</code> 里设置：
            <code>enable-rcon=true</code>、<code>rcon.port=25575</code>、<code>rcon.password=一段随机密码</code>，然后重启一次服务端。</p>

          <h3>进程守护（决定"重启"怎么执行）</h3>
          <div class="field-grid">
            <label>方式
              <select name="guard_type">
                <?php foreach (['systemd' => 'systemd 服务', 'screen' => 'screen 会话', 'tmux' => 'tmux 会话', 'panel' => '宝塔面板计划任务', 'custom' => '自定义命令', 'none' => '不自动重启'] as $key => $label): ?>
                  <option value="<?= e($key) ?>"<?= (string) ($server['guard']['type'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>systemd 单元名<input name="guard_service" value="<?= e((string) ($server['guard']['service'] ?? '')) ?>" placeholder="minecraft-survival"></label>
            <label>screen/tmux 会话名<input name="guard_session" value="<?= e((string) ($server['guard']['session'] ?? '')) ?>" placeholder="mc-survival"></label>
            <label>每小时最多自动重启<input name="max_restarts_per_hour" type="number" value="<?= (int) ($server['guard']['max_restarts_per_hour'] ?? 3) ?>"></label>
            <label>自定义启动命令<input name="guard_start_cmd" value="<?= e((string) ($server['guard']['start_cmd'] ?? '')) ?>" placeholder="cd /www/minecraft && ./start.sh"></label>
            <label>自定义停止命令<input name="guard_stop_cmd" value="<?= e((string) ($server['guard']['stop_cmd'] ?? '')) ?>" placeholder="留空=用 RCON stop"></label>
            <label>自定义重启命令<input name="guard_restart_cmd" value="<?= e((string) ($server['guard']['restart_cmd'] ?? '')) ?>" placeholder="留空=按上面的方式自动拼装"></label>
          </div>

          <?php
          $panelCfg = \MCFix\PanelRegistry::config($server);
          $panelSummary = \MCFix\PanelRegistry::summary($server);
          $panelType = (string) ($panelCfg['type'] ?? 'none');
          ?>
          <h3>面板 API（可选，装不了 Agent 时的救命通道）</h3>
          <p class="hint">
            配好面板接口后，<b>不装 Agent、不开 RCON</b> 也能读服务端日志做诊断；
            MCSManager / 翼龙 还能直接下发游戏指令和开关机。
            可以和 Agent 同时配：诊断读日志优先走面板，重启优先走 Agent。
          </p>
          <div class="field-grid">
            <label>面板类型
              <select name="panel_type">
                <?php foreach (\MCFix\PanelRegistry::types() as $key => $label): ?>
                  <option value="<?= e($key) ?>"<?= $panelType === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>面板地址<input name="panel_api_url" value="<?= e((string) ($panelCfg['api_url'] ?? '')) ?>" placeholder="http://127.0.0.1:23333 或 https://panel.example.com"></label>
            <label>API 密钥<input name="panel_api_key" value="<?= e((string) ($panelCfg['api_key'] ?? '')) ?>" placeholder="面板里生成的密钥"></label>
            <label>密钥模式（翼龙）
              <select name="panel_mode">
                <?php foreach (['client' => 'client（ptlc_，权限小）', 'application' => 'application（ptla_）'] as $key => $label): ?>
                  <option value="<?= e($key) ?>"<?= (string) ($panelCfg['mode'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>远程服务 UUID（MCSManager）<input name="panel_daemon_id" value="<?= e((string) ($panelCfg['daemon_id'] ?? '')) ?>"></label>
            <label>实例 UUID（MCSManager）<input name="panel_instance_id" value="<?= e((string) ($panelCfg['instance_id'] ?? '')) ?>"></label>
            <label>服务器短 ID（翼龙）／服务器 ID（Multicraft）<input name="panel_server_id" value="<?= e((string) ($panelCfg['server_id'] ?? '')) ?>" placeholder="翼龙是 xxxxxxxx，Multicraft 是数字"></label>
            <label>用户名（仅 Multicraft 需要）<input name="panel_username" value="<?= e((string) ($panelCfg['username'] ?? '')) ?>" placeholder="Multicraft 面板的登录用户名"></label>
            <label>进程 ID（宝塔守护管理器）<input name="panel_process_id" value="<?= e((string) ($panelCfg['process_id'] ?? '')) ?>"></label>
            <label>面板内的服务端目录<input name="panel_mc_dir" value="<?= e((string) ($panelCfg['mc_dir'] ?? ($server['mc_dir'] ?? ''))) ?>" placeholder="翼龙一般是 /home/container"></label>
            <label>接口超时（秒）<input name="panel_timeout" type="number" value="<?= (int) ($panelCfg['timeout'] ?? 12) ?>"></label>
          </div>

          <details class="manual-recipe">
            <summary>自定义接口参数（类型选「自定义 HTTP 接口」时填）</summary>
            <div class="field-grid">
              <label>指令接口 URL<input name="panel_log_url" value="<?= e((string) ($panelCfg['log_url'] ?? '')) ?>" placeholder="日志接口，诊断必需"></label>
              <label>开关机 URL<input name="panel_power_url" value="<?= e((string) ($panelCfg['power_url'] ?? '')) ?>"></label>
              <label>列目录 URL<input name="panel_dir_url" value="<?= e((string) ($panelCfg['dir_url'] ?? '')) ?>"></label>
              <label>列目录结果路径<input name="panel_dir_path" value="<?= e((string) ($panelCfg['dir_path'] ?? '')) ?>" placeholder="data.files"></label>
              <label>读文件 URL<input name="panel_file_url" value="<?= e((string) ($panelCfg['file_url'] ?? '')) ?>"></label>
              <label>请求方法<input name="panel_method" value="<?= e((string) ($panelCfg['method'] ?? 'POST')) ?>"></label>
              <label>请求体模板<input name="panel_body" value="<?= e((string) ($panelCfg['body'] ?? '')) ?>" placeholder="{&quot;cmd&quot;:&quot;{command}&quot;}"></label>
              <label>开关机请求体<input name="panel_power_body" value="<?= e((string) ($panelCfg['power_body'] ?? '')) ?>" placeholder="{&quot;action&quot;:&quot;{action}&quot;}"></label>
              <label>回显字段路径<input name="panel_output_path" value="<?= e((string) ($panelCfg['output_path'] ?? '')) ?>" placeholder="data.output"></label>
            </div>
            <label>自定义请求头（JSON 或每行 <code>Key: Value</code>）
              <textarea name="panel_headers" rows="3"><?= e((string) ($panelCfg['headers'] ?? '')) ?></textarea>
            </label>
            <p class="hint">占位符：<code>{command}</code> 游戏指令、<code>{action}</code> 开关机动作、<code>{path}</code> 文件路径。</p>
          </details>

          <?php
          // endpoints 在配置里是数组，这里用"每行 名称=路径"的写法让人能直接编辑
          $endpointLines = '';
          foreach ((array) ($panelCfg['endpoints'] ?? []) as $epName => $epPath) {
              if (is_string($epName) && is_string($epPath) && $epName !== '') {
                  $endpointLines .= $epName . '=' . $epPath . "\n";
              }
          }
          ?>
          <details class="manual-recipe">
            <summary>接口路径覆盖（面板版本对不上时才用）</summary>
            <label>每行一条 <code>名称=路径</code>
              <textarea name="panel_endpoints" rows="4" placeholder="command=/api/protected_instance/command&#10;list_dir=/api/files/list"><?= e(rtrim($endpointLines)) ?></textarea>
            </label>
            <p class="hint">
              面板版本不同，接口路径经常不一样。**判断方法**：浏览器 F12 → Network，
              在面板里做一次同样的操作，照着它发的 URL 填进来。
            </p>
            <p class="hint">
              可用的名称：<code>command</code>、<code>command_legacy</code>、<code>power</code>、
              <code>power_open</code>、<code>power_stop</code>、<code>power_restart</code>、<code>power_kill</code>、
              <code>outputlog</code>、<code>read_file</code>、<code>list_dir</code>、<code>download</code>、
              <code>server</code>（翼龙）、<code>resources</code>（翼龙）、<code>read_file</code>。
              留空 = 用适配器内置的默认路径。
            </p>
          </details>

          <div class="form-foot">
            <input type="hidden" name="panel_enabled" value="0">
            <label class="check-line"><input type="checkbox" name="panel_enabled" value="1" <?= !empty($panelCfg['enabled']) ? 'checked' : '' ?>> 启用面板 API</label>
            <span class="tag">当前能力：<?= $panelSummary['capabilities'] ? e(implode('、', array_map([\MCFix\PanelRegistry::class, 'capabilityLabel'], $panelSummary['capabilities']))) : '未启用' ?></span>
          </div>
          <input type="hidden" name="panel_insecure" value="0">
          <label class="check-line">
            <input type="checkbox" name="panel_insecure" value="1" <?= !empty($panelCfg['insecure']) ? 'checked' : '' ?>>
            跳过 HTTPS 证书校验（<b>只在面板用自签证书、且不勾就完全连不上时才勾</b>）
          </label>
          <p class="hint">
            不勾时系统会校验证书。勾上之后，面板 API 密钥、下发的游戏指令、读回来的日志
            都可能被网络中间人看到或篡改 —— 能换成受信任证书就尽量别勾。
          </p>

          <h3>Agent 令牌</h3>
          <div class="field-grid">
            <label>当前令牌
              <input value="<?= e(mb_substr((string) ($server['agent_token'] ?? ''), 0, 8)) ?>…（只显示前 8 位）" readonly onclick="this.select()">
            </label>
            <label>换成新令牌
              <input name="agent_token" value="" placeholder="留空 = 不修改；填 __new__ = 重新生成">
            </label>
          </div>
          <p class="hint">
            令牌只在生成时完整显示一次，找回请看 MC 机器上的 <code>/opt/mcfix/config.php</code>。
            填 <code>__new__</code> 会重新生成一串，改完记得同步更新那边的 <code>config.php</code>。
          </p>

          <h3>Agent 来源限制（可选）</h3>
          <label>只允许这些 IP 来领任务（每行一个，支持 <code>1.2.3.*</code>；留空 = 不限制）
            <textarea name="agent_ip_allow" rows="2" placeholder="203.0.113.10"><?= e(implode("\n", (array) ($server['agent_ip_allow'] ?? []))) ?></textarea>
          </label>
          <p class="hint">
            适合 MC 机器有固定公网 IP 的情况：万一令牌泄漏，别人从别的 IP 也用不了。
            <b>注意：家庭宽带/动态 IP 不要开</b>，换了 IP 之后 Agent 会直接 403 掉线。
          </p>

          <h3>允许自动执行的修复配方</h3>
          <p class="hint">
            取消勾选会真的禁用。注意：<b>服务端配置里"没出现"的配方 = 允许</b>，
            所以这里每一项都会显式写入 true/false。
          </p>
          <div class="recipe-grid">
            <?php foreach (Recipe::all() as $code => $def): ?>
              <label class="check-line">
                <input type="hidden" name="recipe_<?= e((string) $code) ?>" value="0">
                <input type="checkbox" name="recipe_<?= e((string) $code) ?>" value="1" <?= Recipe::allowed($server, (string) $code) ? 'checked' : '' ?>>
                <span><?= e((string) $def['label']) ?></span>
                <em class="risk risk-<?= e((string) $def['risk']) ?>"><?= e(Recipe::riskLabel($def)) ?></em>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="form-foot">
            <input type="hidden" name="enabled" value="0">
            <label class="check-line"><input type="checkbox" name="enabled" value="1" <?= !empty($server['enabled']) ? 'checked' : '' ?>> 启用该服务器</label>
            <input type="hidden" name="require_mc_online" value="0">
            <label class="check-line"><input type="checkbox" name="require_mc_online" value="1" <?= !empty($server['require_mc_online']) ? 'checked' : '' ?>> 要求服务器在线（维护期可关闭）</label>
            <button class="btn btn-primary" type="submit">保存配置</button>
            <button class="btn btn-mini btn-danger" type="submit" name="admin_action" value="delete_server"
                    onclick="return confirm('确定删除服务器 <?= e((string) $server['name']) ?>？历史工单会保留，但反馈链接将失效。')">删除服务器</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>

<section class="card">
  <h2>添加服务器</h2>
  <form method="post" class="server-form">
    <?= \MCFix\ConsoleAuth::csrfField() ?>
    <input type="hidden" name="admin_action" value="add_server">
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <div class="field-grid">
      <label>代号<input name="code" placeholder="S1（玩家看到的简称，可留空）" maxlength="12"></label>
      <label>显示名 *<input name="name" required placeholder="生存服 1.20.1"></label>
      <label>ID（留空自动生成）<input name="server_id" placeholder="survival"></label>
      <label>地址 *<input name="host" required placeholder="mc.example.com 或 10.0.0.21"></label>
      <label>端口 *<input name="port" type="number" value="25565" required></label>
      <label>MC 服务端目录<input name="mc_dir" placeholder="/www/minecraft/survival"></label>
      <label>日志路径<input name="log_path" value="logs/latest.log"></label>
      <label>RCON 地址<input name="rcon_host" placeholder="留空=同上"></label>
      <label>RCON 端口<input name="rcon_port" type="number" value="25575"></label>
      <label>RCON 密码<input name="rcon_password" placeholder="留空=不启用 RCON"></label>
      <label>执行器
        <select name="executor">
          <option value="agent">agent（推荐）</option>
          <option value="ssh">ssh</option>
          <option value="local">local</option>
          <option value="none">none（只诊断）</option>
        </select>
      </label>
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
      <button class="btn btn-primary" type="submit">添加服务器</button>
      <span class="hint">添加后系统会自动生成 Agent 令牌与部署配置。</span>
    </div>
  </form>
</section>
