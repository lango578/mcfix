<?php
/**
 * 后台：系统设置（自动化风控 + 站点 + 自检 + 维护）。
 */

declare(strict_types=1);

use MCFix\Cache;
use MCFix\Config;
use MCFix\Db;
use MCFix\Rate;
use MCFix\Task;

$problems = Config::diagnose();
$dbStats = Db::stats();
$redirect = \MCFix\ConsoleAuth::url('p=settings');

$counts = [
    'feedback' => (int) Db::scalar('SELECT COUNT(*) FROM feedback'),
    'tasks'    => (int) Db::scalar('SELECT COUNT(*) FROM tasks'),
    'events'   => (int) Db::scalar('SELECT COUNT(*) FROM events'),
];
?>
<header class="page-head">
  <div>
    <h1>系统设置</h1>
    <p class="hint">这里决定"自动修复的胆量"：自动化越激进，玩家体验越好，风险也越高。</p>
  </div>
</header>

<?php if ($problems): ?>
  <div class="card">
    <h2>⚠️ 自检发现 <?= count($problems) ?> 个问题</h2>
    <ul class="problem-list">
      <?php foreach ($problems as $problem): ?>
        <li><?= e($problem) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php else: ?>
  <div class="alert alert-info">✅ 配置自检通过。站点、密钥、数据库目录都正常。</div>
<?php endif; ?>

<div class="settings-grid">
  <form method="post" class="card">
    <?= \MCFix\ConsoleAuth::csrfField() ?>
    <input type="hidden" name="admin_action" value="save_settings">
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

    <h2>自动化与风控</h2>
    <!--
      每个复选框前面都放一个同名的 value="0" 隐藏字段。
      为什么必须这样：浏览器**不会**提交没有勾选的复选框，如果后端用的是
      bool_param($input, 'x', true)，那么"字段缺失"就会被当成默认值 true ——
      结果就是这些开关**根本关不掉**（勾选状态永远回到打开）。
      隐藏字段先提交、复选框后提交，PHP 取最后一个，于是：
        勾选 → 1；不勾 → 0。行为才和界面上看到的一致。
    -->
    <label class="check-line">
      <input type="hidden" name="auto_fix" value="0">
      <input type="checkbox" name="auto_fix" value="1" <?= Config::get('automation.auto_fix', true) ? 'checked' : '' ?>>
      <span>允许自动执行修复</span>
      <em class="hint">关闭后系统只验证不改动，全部转人工</em>
    </label>
    <label class="check-line">
      <input type="hidden" name="require_approval_for_restart" value="0">
      <input type="checkbox" name="require_approval_for_restart" value="1" <?= Config::get('automation.require_approval_for_restart', true) ? 'checked' : '' ?>>
      <span>重启服务端需要管理员批准</span>
      <em class="hint">想让玩家点一下就自动重启，就把这个关掉；关之前请确认重启配额</em>
    </label>

    <div class="field-grid">
      <label>单工单最大自动修复次数<input type="number" name="max_fix_attempts" value="<?= (int) Config::get('automation.max_fix_attempts', 2) ?>" min="1" max="5"></label>
      <label>修复后复验等待（秒）<input type="number" name="verify_delay" value="<?= (int) Config::get('automation.verify_delay', 5) ?>" min="0" max="60"></label>
      <label>重启类复验等待（秒）<input type="number" name="verify_delay_restart" value="<?= (int) Config::get('automation.verify_delay_restart', 25) ?>" min="0" max="120"></label>
      <label>连续失败熔断阈值<input type="number" name="circuit_breaker_failures" value="<?= (int) Config::get('automation.circuit_breaker_failures', 3) ?>" min="0" max="20"></label>
    </div>

    <h2>玩家反馈</h2>
    <div class="field-grid">
      <label>链接有效期（秒）<input type="number" name="token_ttl" value="<?= (int) Config::get('feedback.token_ttl', 86400) ?>" min="600"></label>
      <label>每 IP 每小时提交上限<input type="number" name="rate_limit_per_hour" value="<?= (int) Config::get('feedback.rate_limit_per_hour', 5) ?>" min="1"></label>
      <label>每 IP 每小时验证上限<input type="number" name="verify_per_hour" value="<?= (int) Config::get('feedback.verify_per_hour', 40) ?>" min="1"></label>
      <label>页内同步等待（秒）<input type="number" name="sync_wait_seconds" value="<?= (int) Config::get('feedback.sync_wait_seconds', 8) ?>" min="2" max="30"></label>
      <label>已解决自动归档（天）<input type="number" name="autoclose_days" value="<?= (int) Config::get('feedback.autoclose_days', 7) ?>" min="1"></label>
    </div>
    <p class="hint">
      日志原文的留存天数不在这里 —— 它有自己的卡片（右下角「<b>日志留存</b>」），
      因为那是一条会和玩家数据打交道的设置，藏在六项的最后一项里太容易找不到。
    </p>

    <h2>站点</h2>
    <div class="field-grid">
      <label>站点名称<input name="site_name" value="<?= e((string) Config::get('app.name', 'MC 故障反馈中心')) ?>"></label>
      <label>反馈站地址（玩家链接前缀）<input name="base_url" value="<?= e((string) Config::get('app.base_url')) ?>" placeholder="https://fankui.example.com"></label>
      <label>时区<input name="timezone" value="<?= e((string) Config::get('app.timezone', 'Asia/Shanghai')) ?>"></label>
    </div>

    <h2>域名分工（推荐：后台独立域名）</h2>
    <p class="hint">
      把后台放在<b>另一个域名</b>上，比放在同域名的私有路径里更彻底：同源策略天然隔离，
      后台域名还能单独限制来源 IP。两个域名指向同一个站点目录即可。
    </p>
    <div class="field-grid">
      <label>玩家反馈站域名
        <input name="feedback_host" value="<?= e((string) Config::get('domains.feedback_host', '')) ?>" placeholder="fankui.example.com">
      </label>
      <label>管理后台域名
        <input name="console_host" value="<?= e((string) Config::get('domains.console_host', '')) ?>" placeholder="mc.example.com">
      </label>
    </div>
    <p class="hint">
      多个别名用逗号分隔，例如 <code>fankui.example.com,www.fankui.example.com</code>。<br>
      <b>后台域名留空</b>时退回"单域名 + 私有路径"模式（就是下面那个入口）。
    </p>

    <h2>后台入口与访问控制</h2>
    <p class="hint">
      <?php if (\MCFix\ConsoleAuth::hostMode()): ?>
        当前是<b>双域名模式</b>：后台在 <code><?= e(\MCFix\ConsoleAuth::host()) ?></code>，
        下面这条私有路径只在"后台域名留空"时才会用到。
      <?php else: ?>
        当前是<b>单域名模式</b>：后台靠下面这条私有路径隐藏，玩家页面上没有任何指向它的链接。
      <?php endif; ?>
    </p>
    <div class="field-grid">
      <label>后台私有路径（双域名模式下可忽略）
        <input name="console_path" value="<?= e(\MCFix\ConsoleAuth::path()) ?>">
      </label>
      <label>IP 白名单（留空=不限制）
        <input name="console_ip_allow" value="<?= e(implode(', ', \MCFix\ConsoleAuth::ipAllowlist())) ?>"
               placeholder="例如 1.2.3.4, 192.168.1.*, 10.0.0.0/8">
      </label>
    </div>
    <p class="hint">
      <?php if (\MCFix\ConsoleAuth::hostMode()): ?>
        后台地址：<code><?= e(\MCFix\ConsoleAuth::url()) ?></code><br>
      <?php else: ?>
        后台地址：<code><?= e(\MCFix\ConsoleAuth::url()) ?></code>（把它收藏起来，别外传）<br>
      <?php endif; ?>
      支持单个 IP、<code>192.168.1.*</code> 通配、<code>10.0.0.0/8</code> 这种 CIDR。
      <?php if (!\MCFix\ConsoleAuth::ipAllowlist()): ?>
        <br><b>建议填上 IP 白名单</b>：这样即使地址泄露，别人也进不来。
      <?php endif; ?>
    </p>

    <h2>邮件通知</h2>
    <p class="hint">
      这一块是<b>给玩家发回执和结果</b>用的，顺便用邮件提醒你自己有新工单。
      和你自己建的「通知渠道」是两回事 —— 那边适合钉钉 / 企微群机器人，这边只管邮件，开一个开关就能用。
    </p>

    <?php $mailCfg = \MCFix\TicketMail::settings(); ?>
    <?php $smtpCfg = \MCFix\Mail::smtpSettings(); ?>
    <?php $smtpOn = \MCFix\Smtp::configured($smtpCfg); ?>

    <!--
      SMTP 放在最前面，因为它是 VPS 上唯一能真正发出去的办法。
      服务器出网 25 端口基本都被服务商封了，靠 mail() + Postfix 直投是走不通的。
    -->
    <h3>发送方式</h3>
    <label class="check-line">
      <input type="hidden" name="smtp_enabled" value="0">
      <input type="checkbox" name="smtp_enabled" value="1" <?= !empty($smtpCfg['enabled']) ? 'checked' : '' ?>>
      <span><b>用 SMTP 发信（推荐）</b>：登录你自己的邮箱账号投递</span>
      <em class="hint">
        绝大多数 VPS 服务商封了出网 25 端口，靠 PHP 的 mail() 根本发不出去。
        SMTP 走的是 465 / 587 端口，填一个真实邮箱 + 授权码就能发。
      </em>
    </label>

    <div class="field-grid">
      <label>SMTP 服务器
        <input name="smtp_host" value="<?= e((string) $smtpCfg['host']) ?>" placeholder="smtp.qq.com">
      </label>
      <label>端口
        <input name="smtp_port" type="number" value="<?= (int) $smtpCfg['port'] ?>" placeholder="465">
      </label>
      <label>加密方式
        <select name="smtp_encryption">
          <?php foreach (['ssl' => 'SSL（465，推荐）', 'tls' => 'STARTTLS（587）', 'none' => '不加密（不推荐）'] as $k => $v): ?>
            <option value="<?= e($k) ?>"<?= (string) $smtpCfg['encryption'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>邮箱账号
        <input name="smtp_username" value="<?= e((string) $smtpCfg['username']) ?>" placeholder="youremail@qq.com">
      </label>
      <label>密码 / 授权码
        <input name="smtp_password" type="password" autocomplete="new-password"
               placeholder="<?= (string) $smtpCfg['password'] !== '' ? '已配置，留空表示不改' : 'QQ 邮箱这里填 16 位授权码' ?>">
      </label>
      <label>发件地址（留空 = 用上面的账号）
        <input name="smtp_from" value="<?= e((string) $smtpCfg['from']) ?>" placeholder="留空即可">
      </label>
    </div>
    <label class="check-line">
      <input type="hidden" name="smtp_force_from" value="0">
      <input type="checkbox" name="smtp_force_from" value="1" <?= !empty($smtpCfg['force_from_username']) ? 'checked' : '' ?>>
      <span>强制发件地址 = 登录账号</span>
      <em class="hint">QQ / 163 要求这两者一致，否则会被 550 拒收。建议保持勾选</em>
    </label>
    <?php if ((string) $smtpCfg['password'] !== ''): ?>
      <label class="check-line">
        <input type="hidden" name="smtp_password_clear" value="0">
        <input type="checkbox" name="smtp_password_clear" value="1">
        <span>清除已保存的授权码</span>
      </label>
    <?php endif; ?>

    <details class="manual-recipe">
      <summary>常用邮箱怎么填（点开看）</summary>
      <table class="table table-compact">
        <thead><tr><th>邮箱</th><th>SMTP 服务器</th><th>端口 / 加密</th><th>密码填什么</th></tr></thead>
        <tbody>
          <tr><td>QQ 邮箱</td><td><code>smtp.qq.com</code></td><td>465 / SSL</td><td><b>16 位授权码</b>（不是 QQ 密码）</td></tr>
          <tr><td>QQ 企业邮</td><td><code>smtp.exmail.qq.com</code></td><td>465 / SSL</td><td>邮箱登录密码</td></tr>
          <tr><td>163 / 126</td><td><code>smtp.163.com</code></td><td>465 / SSL</td><td><b>授权码</b></td></tr>
          <tr><td>Gmail</td><td><code>smtp.gmail.com</code></td><td>587 / STARTTLS</td><td><b>应用专用密码</b></td></tr>
          <tr><td>阿里云邮</td><td><code>smtp.mxhichina.com</code></td><td>465 / SSL</td><td>邮箱登录密码</td></tr>
        </tbody>
      </table>
      <p class="hint">
        <b>怎么拿 QQ 邮箱的授权码：</b>网页版 QQ 邮箱 → 设置 → 账户 →
        找到「IMAP/SMTP服务」→ 开启 → 按提示发一条短信 → 得到 16 位授权码。
        这串码只显示一次，复制下来填到上面。
      </p>
      <p class="hint">
        填完先<b>保存</b>，再点右边「测试邮件」发一封验证。失败时它会告诉你服务器原话
        （比如 <code>535</code> = 授权码不对、<code>550</code> = 发件地址和账号不一致）。
      </p>
    </details>

    <h3>收件人与开关</h3>
    <label class="check-line">
      <input type="hidden" name="email_enabled" value="0">
      <input type="checkbox" name="email_enabled" value="1" <?= !empty($mailCfg['enabled']) ? 'checked' : '' ?>>
      <span>启用邮件</span>
      <em class="hint">关掉之后一封都不发，工单流程不受影响</em>
    </label>

    <div class="field-grid">
      <label>管理员邮箱（收新工单提醒）
        <input name="email_admin_to" type="email" value="<?= e((string) $mailCfg['admin_to']) ?>" placeholder="you@example.com">
      </label>
      <label>发件人地址（留空自动用 no-reply@你的域名）
        <input name="email_from" type="email" value="<?= e((string) $mailCfg['from']) ?>" placeholder="noreply@你的域名">
      </label>
      <label>发件人显示名
        <input name="email_from_name" value="<?= e((string) $mailCfg['from_name']) ?>" maxlength="60">
      </label>
    </div>

    <label class="check-line">
      <input type="hidden" name="email_notify_admin" value="0">
      <input type="checkbox" name="email_notify_admin" value="1" <?= !empty($mailCfg['notify_admin']) ? 'checked' : '' ?>>
      <span>玩家提交新工单时，给上面的管理员邮箱发一封</span>
    </label>
    <label class="check-line">
      <input type="hidden" name="email_notify_player" value="0">
      <input type="checkbox" name="email_notify_player" value="1" <?= !empty($mailCfg['notify_player']) ? 'checked' : '' ?>>
      <span>给玩家发「已收到」和「处理结果」</span>
      <em class="hint">需要玩家在反馈页填了邮箱</em>
    </label>
    <label class="check-line">
      <input type="hidden" name="email_purge" value="0">
      <input type="checkbox" name="email_purge" value="1" <?= !empty($mailCfg['purge_email']) ? 'checked' : '' ?>>
      <span>工单结束后把玩家邮箱换成掩码（<code>ab***@qq.com</code>）</span>
      <em class="hint">建议保持开启；邮件队列里的明文地址发出去后也会立刻清掉</em>
    </label>

    <p class="hint">
      宝塔上如果发不出去，到【软件商店】装一个 Postfix。
      另外：<b>能投出去不等于对方一定收得到</b> —— 建议把发件人配成你自己域名的地址并加 SPF 记录，
      否则 QQ / 163 很容易判成垃圾邮件。
    </p>

    <div class="form-foot">
      <button class="btn btn-primary" type="submit">保存设置</button>
    </div>

    <!--
      注意：这一段**不能**提前写表单结束标签。
      admin_save_settings() 是按「一次 POST 里的全部字段」重建整个配置的，
      字段缺失就按默认值写回。所以「自动化与风控 / 邮件 / 大模型」必须在同一个
      表单里提交，否则只提交其中一段会把另外几段重置掉
      （改大模型 → 邮件被清空；改邮件 → 大模型被关掉）。
      整页唯一的表单结束标签在本段末尾、右侧分栏之前。
    -->

    <h2>大模型兜底（可选）</h2>
    <p class="hint">
      <b>这个系统本身不依赖任何大模型</b> —— 诊断和修复的判断全部来自
      37 条客户端问题规则 + 51 条日志特征正则，不开这里也能正常跑。
    </p>
    <p class="hint">
      开了之后只多一件事：<b>规则库没命中时</b>（以前直接转人工）多问一次模型，
      让玩家拿到一个人话结论。命中规则的情况根本不会走模型，所以又快又省钱。
    </p>

    <?php $aiCfg = \MCFix\AiAdvisor::settings(); ?>
    <label class="check-line">
      <input type="hidden" name="ai_enabled" value="0">
      <input type="checkbox" name="ai_enabled" value="1" <?= !empty($aiCfg['enabled']) ? 'checked' : '' ?>>
      <span>规则库没命中时，问一次大模型</span>
    </label>

    <div class="field-grid">
      <label>接口地址
        <input name="ai_base_url" value="<?= e((string) $aiCfg['base_url']) ?>" placeholder="https://api.deepseek.com">
      </label>
      <label>模型名
        <input name="ai_model" value="<?= e((string) $aiCfg['model']) ?>" placeholder="deepseek-chat">
      </label>
      <label>API 密钥
        <input name="ai_api_key" type="password" autocomplete="new-password"
               placeholder="<?= (string) $aiCfg['api_key'] !== '' ? '已配置，留空表示不改' : 'sk-...' ?>">
      </label>
      <label>超时（秒）<input name="ai_timeout" type="number" min="3" max="30" value="<?= (int) $aiCfg['timeout'] ?>"></label>
      <label>每来源每小时上限<input name="ai_per_ip_hourly" type="number" min="1" max="50" value="<?= (int) $aiCfg['per_ip_hourly'] ?>"></label>
      <label>单次最多 tokens<input name="ai_max_tokens" type="number" min="200" max="2000" value="<?= (int) $aiCfg['max_tokens'] ?>"></label>
    </div>
    <?php if ((string) $aiCfg['api_key'] !== ''): ?>
      <label class="check-line">
        <input type="hidden" name="ai_api_key_clear" value="0">
        <input type="checkbox" name="ai_api_key_clear" value="1">
        <span>清除已保存的密钥</span>
      </label>
    <?php endif; ?>

    <p class="hint">
      用的是 OpenAI 兼容接口（<code>/v1/chat/completions</code>），下面这些填法都能用：<br>
      DeepSeek <code>https://api.deepseek.com</code> · 通义 <code>https://dashscope.aliyuncs.com/compatible-mode/v1</code> ·
      硅基流动 <code>https://api.siliconflow.cn/v1</code> · 本地 Ollama <code>http://127.0.0.1:11434/v1</code>
    </p>
    <p class="hint">
      <b>发出去之前会自动脱敏</b>：玩家名、IP、绝对路径、启动器令牌都会被替换成占位符。<br>
      <b>模型只出结论，不出命令</b>：它给的修复建议永远不会被执行，只有它点出的「已知问题」
      才会走我们自己的知识库（含我们审过的修复配方）。<br>
      <b>超时就降级</b>：拿不到结果照旧转人工，不会卡住工单。结果按日志特征缓存 7 天，同一类报错只问一次。
    </p>

    <div class="form-foot">
      <button class="btn btn-primary" type="submit">保存设置</button>
    </div>
  </form>

  <div class="settings-side">
    <?php
    /*
     * 图标与邮件 Logo。
     *
     * 为什么单独成卡、单独成表单：它们是**文件上传**，必须 multipart/form-data，
     * 而左边那个大表单是普通 POST —— 把上传混进去，一次保存要么失败要么把
     * 其它设置一起提交两遍。而且这两件事跟"改配置"节奏不同：传一次就不动了。
     */
    $brandFavicon = \MCFix\Brand::get('favicon');
    $brandLogo = \MCFix\Brand::get('logo');
    ?>
    <section class="card">
      <h2>站点图标</h2>
      <p class="hint">浏览器标签页上的那个小图标。没设置时用内置的 🧱。</p>
      <div class="brand-preview">
        <?php if ($brandFavicon !== null): ?>
          <img src="<?= e($brandFavicon['url']) ?>" alt="当前图标">
          <span class="brand-meta"><?= e($brandFavicon['file']) ?> ·
            <?= (int) $brandFavicon['width'] ?>×<?= (int) $brandFavicon['height'] ?> ·
            <?= (int) round($brandFavicon['bytes'] / 1024) ?> KB</span>
        <?php else: ?>
          <span class="brand-preview-empty">🧱</span>
          <span class="brand-meta">内置默认（不是图片，是内联 SVG）</span>
        <?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= \MCFix\ConsoleAuth::csrfField() ?>
        <input type="hidden" name="admin_action" value="brand_upload">
        <input type="hidden" name="brand_slot" value="favicon">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <input type="file" name="favicon_file" accept="image/png,image/jpeg,image/gif,image/webp" required>
        <div class="form-foot">
          <button class="btn btn-primary" type="submit">上传图标</button>
        </div>
      </form>
      <?php if ($brandFavicon !== null): ?>
        <form method="post">
          <?= \MCFix\ConsoleAuth::csrfField() ?>
          <input type="hidden" name="admin_action" value="brand_clear">
          <input type="hidden" name="brand_slot" value="favicon">
          <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
          <button class="btn btn-ghost btn-mini" type="submit">清除，退回默认图标</button>
        </form>
      <?php endif; ?>
      <p class="hint">
        PNG / JPG / GIF / WebP，单张最大 256 KB、边长 2048px 以内。<br>
        <b>不支持 SVG</b> —— SVG 可以内嵌 &lt;script&gt;，而它会被同源地发出去，
        等于给上传口开了一个存储型 XSS。
      </p>
    </section>

    <section class="card">
      <h2>邮件 Logo</h2>
      <p class="hint">
        发信时显示在邮件正文顶部的图。设了就带，没设邮件里就没有这块。
      </p>
      <div class="brand-preview">
        <?php if ($brandLogo !== null): ?>
          <img src="<?= e($brandLogo['url']) ?>" alt="当前 Logo">
          <span class="brand-meta"><?= e($brandLogo['file']) ?> ·
            <?= (int) $brandLogo['width'] ?>×<?= (int) $brandLogo['height'] ?> ·
            <?= (int) round($brandLogo['bytes'] / 1024) ?> KB</span>
        <?php else: ?>
          <span class="brand-preview-empty">未设置</span>
          <span class="brand-meta">邮件的 From 名字仍在，只是没有图</span>
        <?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= \MCFix\ConsoleAuth::csrfField() ?>
        <input type="hidden" name="admin_action" value="brand_upload">
        <input type="hidden" name="brand_slot" value="logo">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp" required>
        <div class="form-foot">
          <button class="btn btn-primary" type="submit">上传 Logo</button>
        </div>
      </form>
      <?php if ($brandLogo !== null): ?>
        <form method="post">
          <?= \MCFix\ConsoleAuth::csrfField() ?>
          <input type="hidden" name="admin_action" value="brand_clear">
          <input type="hidden" name="brand_slot" value="logo">
          <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
          <button class="btn btn-ghost btn-mini" type="submit">清除 Logo</button>
        </form>
      <?php endif; ?>
      <p class="hint">
        这张图是<b>内嵌进邮件</b>的（Content-ID），不是远程地址 —— 所以收件人
        <b>不用点「显示图片」</b>就能看到。代价是每封邮件会大一点（图片体积）。<br>
        建议做成横长条（例如 360×80），显示时最多 180px 宽。上传后到
        「测试邮件」发一封给自己看效果。
      </p>
      <p class="hint">
        <b>说清楚一件事</b>：这张图是<b>邮件正文里</b>的 Logo。收件箱列表里
        发件人旁边那个头像，图片是客户端按发件地址自己决定的（Gmail 看 Google
        账号、QQ 邮箱看 QQ 号），<b>发信方控制不了</b> —— 除非去申请 BIMI
        （需要付费的 VMC 证书 + DMARC 强制策略）。
      </p>
    </section>

    <?php $aiStats = \MCFix\AiAdvisor::stats(); ?>
    <form method="post" class="card">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="ai_test">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <h2>测试大模型</h2>
      <p class="hint">
        状态：<b class="<?= $aiStats['enabled'] ? 'ok' : '' ?>"><?= $aiStats['enabled'] ? '已启用' : '未启用' ?></b>
        · 本小时已用 <b><?= (int) $aiStats['used_this_hour'] ?></b>/<?= (int) $aiStats['hourly_limit'] ?>
        · 累计调用 <?= (int) $aiStats['calls'] ?> 次
      </p>
      <div class="stack-form">
        <button class="btn btn-ghost" type="submit">测一下连通性</button>
      </div>
      <?php if ((string) $aiStats['last_message'] !== ''): ?>
        <p class="hint">最近一次：<?= e(mb_substr((string) $aiStats['last_message'], 0, 180)) ?></p>
      <?php endif; ?>
      <p class="hint">先保存设置再点这里 —— 测试用的是已保存的配置。</p>
    </form>

    <?php
    // 可用模型清单：模型名最容易填错又最难自查，所以单独做一个按钮去问接口。
    // 结果缓存在 Cache 里（见 admin.php 的 ai_models 动作）。
    $modelList = (array) \MCFix\Cache::get('ai:models', []);
    $modelNow  = (string) Config::get('ai.model', '');
    ?>
    <form method="post" class="card">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="ai_models">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <h2>可用模型</h2>
      <p class="hint">
        问一下接口支持哪些模型名（<code>GET /models</code>）。
        名字填错是这里最常见的 400，与其翻文档不如直接问它。
      </p>
      <div class="stack-form">
        <button class="btn btn-ghost" type="submit">获取可用模型</button>
      </div>
      <?php if ($modelList !== []): ?>
        <p class="hint">接口支持以下 <?= count($modelList) ?> 个，点一下复制：</p>
        <ul class="model-list">
          <?php foreach ($modelList as $one): ?>
            <?php $isCurrent = ((string) $one === $modelNow); ?>
            <li>
              <code><?= e((string) $one) ?></code>
              <?php if ($isCurrent): ?>
                <span class="ok">← 当前填的就是它</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($modelNow !== '' && !in_array($modelNow, array_map('strval', $modelList), true)): ?>
          <p class="hint">
            <b class="bad">当前填的「<?= e($modelNow) ?>」不在上面这份列表里</b> ——
            多半就是它导致请求返回 400。把「模型名」改成列表里的某一个再测。
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </form>

    <?php $mailStats = \MCFix\TicketMail::stats(); ?>
    <form method="post" class="card">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="email_test">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <h2>测试邮件</h2>
      <p class="hint">
        队列：待发 <b><?= (int) $mailStats['queued'] ?></b> ·
        已发 <?= (int) $mailStats['sent'] ?> ·
        失败 <b class="<?= (int) $mailStats['failed'] > 0 ? 'bad' : '' ?>"><?= (int) $mailStats['failed'] ?></b>
      </p>
      <div class="stack-form">
        <input name="to" type="email" placeholder="收件邮箱" required>
        <button class="btn btn-ghost" type="submit">立刻发一封</button>
      </div>
      <?php if ((string) $mailStats['last_error'] !== ''): ?>
        <p class="hint bad">最近一次失败：<?= e(mb_substr((string) $mailStats['last_error'], 0, 200)) ?></p>
      <?php endif; ?>
    </form>

    <form method="post" class="card">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="change_password">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <h2>修改管理密码</h2>
      <div class="stack-form">
        <input type="password" name="current_password" placeholder="当前密码" required autocomplete="current-password">
        <input type="password" name="new_password" placeholder="新密码（至少 8 位）" required autocomplete="new-password">
        <input type="password" name="confirm_password" placeholder="再输一次新密码" required autocomplete="new-password">
        <button class="btn btn-ghost" type="submit">更新密码</button>
      </div>
    </form>

    <section class="card">
      <h2>维护工具</h2>
      <p class="hint">等价于计划任务里跑的例行维护，可以在这里手动触发。</p>
      <div class="btn-row">
        <form method="post" class="inline-form">
          <?= \MCFix\ConsoleAuth::csrfField() ?>
          <input type="hidden" name="admin_action" value="run_maintenance">
          <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
          <button class="btn btn-mini" type="submit" name="job" value="reclaim">回收僵尸任务</button>
          <button class="btn btn-mini" type="submit" name="job" value="followup">跟进待复验工单</button>
          <button class="btn btn-mini" type="submit" name="job" value="cleanup">清理缓存与限流</button>
          <button class="btn btn-mini" type="submit" name="job" value="email">催发邮件队列</button>
          <button class="btn btn-mini" type="submit" name="job" value="pingcache">清掉在线探测缓存</button>
          <button class="btn btn-mini" type="submit" name="job" value="autoclose">归档已解决工单</button>
          <button class="btn btn-mini" type="submit" name="job" value="logretention">清理日志原文</button>
          <button class="btn btn-mini btn-primary" type="submit" name="job" value="all">全部执行</button>
        </form>
      </div>
    </section>

    <section class="card" id="log-retention">
      <?php
      // 让这个设置"看得见效果"：库里现在有多少条还留着原文、多少条已经清理过。
      // 只显示数字，不涉及内容。
      $logKept   = (int) Db::scalar('SELECT COUNT(*) FROM feedback WHERE client_log IS NOT NULL');
      $logPurged = (int) Db::scalar('SELECT COUNT(*) FROM feedback WHERE log_purged_at IS NOT NULL');
      $retDays   = (int) Config::get('feedback.log_retention_days', 30);
      ?>
      <h2>日志留存</h2>
      <p class="hint">
        玩家上传的崩溃报告里有 <b>Windows 用户名</b>（<code>C:\Users\&lt;名字&gt;\...</code>）、
        显卡型号、游戏 ID 等个人信息，不该无限期留着。
        <b>工单归档满下面这个天数后自动清空原文</b>（诊断结论、MOD 列表会保留）。
      </p>

      <form method="post" class="stack-form">
        <?= \MCFix\ConsoleAuth::csrfField() ?>
        <input type="hidden" name="admin_action" value="save_log_retention">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <div class="field" style="margin-bottom:0">
          <label for="log_retention_days">归档满多少天后清掉日志原文（天）</label>
          <input id="log_retention_days" type="number" name="log_retention_days"
                 value="<?= $retDays ?>" min="0" max="3650">
          <p class="hint">填 <code>0</code> = 不清理（永久保留）。</p>
        </div>
        <button class="btn btn-primary" type="submit">保存留存天数</button>
      </form>

      <?php if ($retDays <= 0): ?>
        <p class="hint"><b class="warn-text">当前是「不清理」，原文会一直保留。</b></p>
      <?php endif; ?>
      <p class="hint">
        当前库里：<b><?= $logKept ?></b> 条还留着日志原文，已按设置清理过 <b><?= $logPurged ?></b> 条。
        计划任务每分钟会自动清理一次。
      </p>

      <form method="post" class="stack-form">
        <?= \MCFix\ConsoleAuth::csrfField() ?>
        <input type="hidden" name="admin_action" value="run_maintenance">
        <input type="hidden" name="job" value="logretention">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <button class="btn btn-ghost btn-mini" type="submit">立即清理一次</button>
      </form>
    </section>

    <section class="card">
      <h2>运行信息</h2>
      <dl class="kv">
        <dt>版本</dt><dd>v<?= e(MCFIX_VERSION) ?></dd>
        <dt>PHP</dt><dd><?= e(PHP_VERSION) ?>（<?= e(PHP_SAPI) ?>）</dd>
        <dt>数据库</dt><dd><?= e((string) $dbStats['driver']) ?> <?= $dbStats['size'] > 0 ? e(human_size((float) $dbStats['size'])) : '' ?></dd>
        <dt>数据文件</dt><dd class="mono sub"><?= e((string) ($dbStats['path'] ?? '—')) ?></dd>
        <dt>工单 / 任务 / 日志</dt><dd><?= (int) $counts['feedback'] ?> / <?= (int) $counts['tasks'] ?> / <?= (int) $counts['events'] ?></dd>
        <dt>proc_open</dt><dd><?= in_array('proc_open', \MCFix\Executor::disabledFunctions(), true) ? '<span class="bad">被禁用（local/ssh 通路不可用）</span>' : '<span class="ok">可用</span>' ?></dd>
        <?php $ipInfo = client_ip_source(); ?>
        <dt>来源 IP</dt>
        <dd>
          <span class="mono"><?= e($ipInfo['ip']) ?></span>
          <?php if ($ipInfo['source'] === 'xff'): ?>
            <span class="bad">来自转发头</span>
          <?php else: ?>
            <span class="ok">来自连接来源</span>
          <?php endif; ?>
          <br><small class="hint"><?= e($ipInfo['note']) ?></small>
        </dd>
        <dt>时区</dt><dd><?= e(date_default_timezone_get()) ?>（现在 <?= e(date('Y-m-d H:i:s')) ?>）</dd>
      </dl>
    </section>

    <section class="card">
      <h2>数据库路径</h2>
      <p class="hint">迁移/备份：直接复制这个文件即可。宝塔计划任务里可以加一条每日备份。</p>
      <input class="copy-field" readonly value="<?= e((string) ($dbStats['path'] ?? '')) ?>" onclick="this.select()">
    </section>
  </div>
</div>
