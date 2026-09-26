<?php
/**
 * 反馈提交表单（玩家侧第一步）。
 *
 * @var array<string,array<string,mixed>> $servers        可选的服务器（专属链接进来时只会有锁定的那一台）
 * @var array<string,array<string,mixed>> $categories
 * @var array<string,mixed> $prefill
 * @var string $notice
 * @var string $lockedServerId   专属链接锁定的服务器 id；空 = 无锁定，列出全部
 */
$prefill = $prefill ?? [];
$notice = $notice ?? '';
$lockedServerId = (string) ($lockedServerId ?? '');
$firstServer = '';
foreach ($servers as $sid => $srv) {
    $firstServer = (string) $sid;
    break;
}
$selected = (string) ($prefill['server_id'] ?? $firstServer);
?>
<section class="hero">
  <div class="hero-copy">
    <h1>服务器出问题了？<br><span class="grad">告诉我，我来验证并修好</span></h1>
    <p class="hero-sub">
      不需要加群、不需要等管理员在线。填写游戏 ID 和现象，系统会立刻连到服务端做一次真实检测，
      能在几十秒内自动修好的问题（白名单、误封、卡崩、插件报错）会当场处理，并把过程记录给你看。
    </p>
    <ul class="hero-points">
      <li><span>1</span> 自动验证：按 Minecraft 协议真连一次，不是猜</li>
      <li><span>2</span> 自动修复：白名单 / 解封 / 保存 / 重启，全都有风控</li>
      <li><span>3</span> 全程留痕：每一步都记录在工单里，可随时回看</li>
    </ul>
  </div>

  <div class="hero-badges">
    <?php foreach ($servers as $sid => $srv): ?>
      <?php /* 玩家侧只出「代号・名字」，**不能**显示 host:port ——
               地址是服务端连接信息，拿到链接的任何人都能看到，等于公开
               可直连地址（扫端口 / 打 DDoS / 绕过白名单直连都从这里开始）。 */ ?>
      <div class="badge-card">
        <div class="badge-name"><?= e(server_public_label((string) $sid)) ?></div>
        <span class="dot dot-idle" data-ping-server="<?= e($sid) ?>"></span>
        <span class="badge-state" data-ping-text="<?= e($sid) ?>">检测中…</span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($notice !== ''): ?>
  <div class="alert alert-warn"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!$servers): ?>
  <div class="alert alert-info">
    管理员还没有配置服务器。不过<b>客户端报错可以直接提交</b> ——
    模组冲突、Java 版本、内存不足这类问题跟服务器无关，传上崩溃报告就行。
  </div>
<?php endif; ?>

<form id="feedback-form" class="card form-card" autocomplete="off"
      method="post" action="<?= e(url('?r=api&action=submit')) ?>" enctype="multipart/form-data">

  <div class="field">
    <label for="player_name">你的游戏 ID <em>*</em></label>
    <input id="player_name" name="player_name" type="text" maxlength="16" required
           placeholder="例如 Steve" value="<?= e($prefill['player_name'] ?? '') ?>">
    <p class="hint">用于自动验证"你"是否在白名单 / 是否被封禁，请填写进游戏时用的名字。</p>
  </div>

  <?php
  /*
   * 专属链接进来时把服务器锁在这台上。
   *
   * 仍然用下拉框（而不是一行静态文字），因为**「不涉及服务器」必须留着可选** ——
   * 玩家客户端崩了（模组冲突、Java 版本、内存不足）跟是哪台服无关，
   * 不该因为从专属链接进来就提交不了纯客户端问题。
   *
   * 所以锁定的含义是：**这台服以外的服务器不列出来**，但"不涉及服务器"照旧。
   * 前端这份只是让选择变窄；真正管用的是后端拿分享令牌复核（见 api.php）。
   */
  $lockedId = (string) ($lockedServerId ?? '');
  $isLocked = $lockedId !== '' && isset($servers[$lockedId]);
  ?>
  <div class="field">
    <label for="server_id">出问题的服务器</label>
    <select id="server_id" name="server_id"<?= $isLocked ? ' class="is-locked"' : '' ?>>
      <option value=""<?= $selected === '' ? ' selected' : '' ?>>不涉及服务器（纯客户端报错）</option>
      <?php if ($isLocked): ?>
        <option value="<?= e($lockedId) ?>"<?= $lockedId === $selected || $selected === '' ? ' selected' : '' ?>>
          <?= e(server_public_label($lockedId)) ?>
        </option>
      <?php else: ?>
        <?php foreach ($servers as $sid => $srv): ?>
          <?php /* 只出「代号・名字」。原来这里跟着印了 host:port，
                   下拉框一展开玩家就看到了服务端地址。 */ ?>
          <option value="<?= e($sid) ?>"<?= $sid === $selected ? ' selected' : '' ?>>
            <?= e(server_public_label((string) $sid)) ?>
          </option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>
    <?php if ($isLocked): ?>
      <p class="hint">
        这条链接是「<?= e(server_public_label($lockedId)) ?>」的专属入口，所以只列这一台。<br>
        这台服崩了 / 进不去 / 被踢 → 直接提交，系统会<b>真的连一次</b>做验证。<br>
        只有你自己客户端崩（模组冲突、Java 版本、内存不足）→ 选「不涉及服务器」，
        系统只分析你传上来的日志。
      </p>
    <?php else: ?>
      <p class="hint">
        服务器崩了 / 进不去 / 被踢 → 选那台服务器，系统会<b>真的连一次</b>做验证。<br>
        只有你自己客户端崩（模组冲突、Java 版本、内存不足）→ 选「不涉及服务器」，
        系统只分析你传上来的日志。
      </p>
    <?php endif; ?>
  </div>

  <div class="field">
    <label>问题类型 <em>*</em></label>
    <div class="chips" id="category-chips">
      <?php foreach ($categories as $key => $cat): ?>
        <label class="chip">
          <input type="radio" name="category" value="<?= e($key) ?>"<?= $key === 'cannot_join' ? ' checked' : '' ?>>
          <span class="chip-body">
            <span class="chip-icon"><?= e($cat['icon']) ?></span>
            <span class="chip-label"><?= e($cat['label']) ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="hint" id="category-hint"></p>
  </div>

  <div class="field">
    <label for="message">具体现象 <em>*</em></label>
    <textarea id="message" name="message" rows="4" maxlength="2000" required
              placeholder="越具体越好：什么时间开始、有没有报错文字、多少人受影响。例如「20:30 开始所有人都连不上，客户端提示 Connection refused」"></textarea>
    <p class="hint">如果知道报错原文，直接粘进来，日志分析会更准。</p>
  </div>

  <details class="field advanced log-box" id="log-box">
    <summary>
      <b>🧯 客户端报错？上传崩溃报告，系统自动分析并给出解决办法</b>
      <span class="summary-hint">推荐 · 30 秒搞定</span>
    </summary>

    <div class="alert alert-info small">
      报错、闪退、黑屏、进不去 —— 这些问题的原因有 80% 在客户端，光靠文字描述很难定位。
      把日志发上来，系统能直接读出<b>哪个 MOD 出问题、缺什么前置、Java 版本对不对、内存够不够</b>，
      并且能自动和服务端的 MOD 清单比对。
    </div>

    <div class="log-tabs">
      <div class="log-source">
        <h4>方式一：上传日志文件（最准）</h4>
        <p class="hint">
          每个启动器、每台电脑存日志的位置都不一样。按某一个启动器写死的路径，换了启动器就对不上，
          所以这里不给你固定路径。<b>用你启动器里的「打开游戏目录 / 打开文件夹」按钮</b>，比记路径靠谱得多。
        </p>
        <ol class="log-steps">
          <li>选中最上面你报错的那个整合包/实例，点「打开游戏目录」或「打开文件夹」</li>
          <li>进去先看 <code>crash-reports</code> 文件夹，把<b>最新的那个 .txt</b> 拖到下面（崩溃过才会有这个文件夹）</li>
          <li>没有 crash-reports，就找 <code>logs</code> 文件夹里的 <code>latest.log</code></li>
          <li>实在翻不到也不用较劲，用下面的「方式二」把报错窗口的文字复制过来，一样能分析</li>
        </ol>

        <details class="log-hints">
          <summary>不知道按钮在哪？按启动器对一下（对不上就用上面的通用办法）</summary>
          <div class="field-grid">
            <label>HMCL
              <span class="hint">左侧选中你的实例 → 右键 → 「打开文件夹」（或实例设置里找「游戏目录」）</span>
            </label>
            <label>PCL2
              <span class="hint">选中版本 → 左下角「打开版本文件夹」；日志在 <code>versions/版本名/logs</code></span>
            </label>
            <label>官方启动器
              <span class="hint">安装 → 选中版本 → 「打开文件夹」；日志在 <code>.minecraft/logs</code></span>
            </label>
          </div>
          <p class="hint">
            ❓ 待查证：各启动器的按钮叫法会随版本改，上面写的是常见位置。
            如果你的启动器里没有这些字样，直接找「打开文件夹 / 打开目录 / 浏览文件」这类按钮即可，
            它们指向的都是同一个地方。
          </p>
        </details>

        <label class="file-drop" id="file-drop">
          <input type="file" name="client_log_file" id="client_log_file" accept=".txt,.log,.json,text/plain">
          <span class="file-drop-body">
            <b>点击选择文件</b> 或把文件拖到这里
            <small>支持 .txt / .log / .json，单个文件最大 512 KB；系统只分析前后各 256 KB，超出请只贴最后 500 行</small>
          </span>
          <span class="file-chosen" id="file-chosen" hidden></span>
        </label>
      </div>

      <div class="log-source">
        <h4>方式二：直接粘贴报错内容</h4>
        <textarea id="client_log" name="client_log" rows="7" maxlength="200000"
                  placeholder="把报错窗口里的文字、或 latest.log 里最后一段贴进来即可。&#10;例如：&#10;java.lang.NoClassDefFoundError: dev/architectury/...&#10;    at com.example.mymod.MyMod.&lt;init&gt;(MyMod.java:42)"></textarea>
        <p class="hint">两种方式填一个就行；都填的话优先用上传的文件。</p>
      </div>
    </div>

    <details class="log-hints">
      <summary>可选：补充你的客户端环境（不知道就跳过）</summary>
      <div class="field-grid">
        <label>游戏版本
          <input name="client_version" placeholder="1.20.1">
        </label>
        <label>启动器
          <input name="client_launcher" placeholder="HMCL / PCL2 / 官方启动器">
        </label>
        <label>MOD 加载器
          <select name="client_loader">
            <option value="">不确定</option>
            <option value="Forge">Forge</option>
            <option value="NeoForge">NeoForge</option>
            <option value="Fabric">Fabric</option>
            <option value="Quilt">Quilt</option>
            <option value="OptiFine">OptiFine（原版+高清修复）</option>
            <option value="Vanilla">原版（无 MOD）</option>
          </select>
        </label>
      </div>
    </details>
  </details>

  <details class="field advanced"<?= !empty($prefill['player_email']) ? ' open' : '' ?>>
    <summary>可选：留个邮箱，修好了通知你</summary>
    <div class="field-row">
      <input name="player_email" type="email" maxlength="200" autocomplete="email"
             placeholder="you@example.com（选填）" value="<?= e($prefill['player_email'] ?? '') ?>">
    </div>
    <p class="hint">
      填了的话：提交成功发一封「已收到」，修好或转人工时再发一封结果。
      <b>工单结束后系统会把你的邮箱换成掩码</b>（比如 <code>ab***@qq.com</code>），不会长期留存。
      <br>
      你上传的日志也只会留一段时间：<b>工单归档满 <?= (int) \MCFix\Config::get('feedback.log_retention_days', 30) ?> 天后会自动清掉原文</b>（诊断结论会保留）。日志里的 Windows 用户名、显卡型号等属于个人信息，不该无限期留着。
    </p>
  </details>

  <details class="field advanced">
    <summary>可选：其它联系方式 / 补充信息</summary>
    <div class="field-row">
      <input name="contact" type="text" maxlength="128" placeholder="QQ / 其它（选填）">
    </div>
  </details>

  <div class="form-foot">
    <button type="submit" class="btn btn-primary" id="submit-btn">
      <span class="btn-text">提交并自动验证</span>
      <span class="btn-spin" aria-hidden="true"></span>
    </button>
    <span class="form-tip" id="submit-tip">提交后会立刻开始检测，通常 5~20 秒出结果</span>
  </div>

  <div class="alert alert-error" id="form-error" hidden></div>
</form>

<section class="card progress-card" id="progress-card" hidden>
  <header class="progress-head">
    <div>
      <span class="ticket-no" id="ticket-no">—</span>
      <h2 id="progress-headline">正在验证…</h2>
    </div>
    <span class="pill" id="status-pill">已提交</span>
  </header>

  <ol class="steps" id="steps">
    <li data-step="submitted"><b>提交反馈</b><small>已收到你的描述</small></li>
    <li data-step="diagnosing"><b>自动验证</b><small>连服务端 + 读日志 + 查白名单</small></li>
    <li data-step="fixing"><b>执行修复</b><small>按风控策略自动动手</small></li>
    <li data-step="verifying"><b>回归复验</b><small>确认问题真的消失</small></li>
    <li data-step="resolved"><b>完成</b><small>结果如下</small></li>
  </ol>

  <div id="client-panel" hidden></div>

  <div class="checks" id="checks"></div>

  <div class="timeline" id="timeline"></div>

  <footer class="progress-foot">
    <a class="btn btn-ghost" id="share-link" href="#" hidden>保存这个链接，随时查看进度</a>
    <button class="btn btn-ghost" id="reverify-btn" type="button" hidden>再验证一次</button>
  </footer>
</section>
