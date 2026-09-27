<?php
/**
 * 后台：通知设置。
 *
 * 三块：
 *   1. 渠道 —— 钉钉/企微/飞书/邮件/Bark/ntfy/Server酱/Telegram/Discord/通用Webhook，每个都能单独测
 *   2. 订阅 —— 哪些事件发到哪些渠道（勾选表）
 *   3. 记录 —— 发出去了什么、有没有失败、失败能不能重试
 */

declare(strict_types=1);

use MCFix\Channel\ChannelRegistry;
use MCFix\Config;
use MCFix\Db;
use MCFix\Notifier;

$config = Notifier::config();
$channelConfigs = $config['channels'] ?? [];
if (!is_array($channelConfigs)) {
    $channelConfigs = [];
}
$subscriptions = $config['subscriptions'] ?? [];
if (!is_array($subscriptions)) {
    $subscriptions = [];
}

$types = ChannelRegistry::types();
$labels = ChannelRegistry::labels();
$fieldHints = ChannelRegistry::fieldHints();
$redirect = \MCFix\ConsoleAuth::url('p=notify');

$editing = param($_GET, 'channel', '');
$page = max(1, int_param($_GET, 'page', 1));
$perPage = 30;
$statusFilter = param($_GET, 'status');

// ---------------------------------------------------------------- 发送记录
$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
$total = (int) Db::scalar('SELECT COUNT(*) FROM notifications' . $whereSql, $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$records = Db::all(
    'SELECT * FROM notifications' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);

$failedCount = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE status = 'failed'");
$abandonedCount = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE status = 'abandoned'");
$sentCount = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE status = 'sent'");

$typeLabels = [
    'json'  => 'JSON（默认）',
    'form'  => '表单',
    'text'  => '纯文本',
];
?>
<header class="page-head">
  <div>
    <h1>通知设置</h1>
    <p class="hint">
      修复完成、需要批准、转人工 —— 这些节点都能推到你的群里或手机上。
      通知失败会进队列自动重试，<b>不会影响修复流程本身</b>。
    </p>
  </div>
  <div class="page-actions">
    <form method="post" class="inline-form">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="notify_digest">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <button class="btn btn-ghost" type="submit">立即发送每日汇总</button>
    </form>
    <form method="post" class="inline-form">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="notify_retry">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <button class="btn btn-ghost" type="submit">重试失败的通知<?= $failedCount > 0 ? '（' . $failedCount . '）' : '' ?></button>
    </form>
  </div>
</header>

<?php if (empty($config['enabled'])): ?>
  <div class="alert alert-warn">
    通知总开关当前是<b>关闭</b>的。保存下面的设置时勾选「启用通知」即可开启。
  </div>
<?php endif; ?>

<form method="post">
  <?= \MCFix\ConsoleAuth::csrfField() ?>
  <input type="hidden" name="admin_action" value="notify_save">
  <input type="hidden" name="redirect" value="<?= e($redirect . ($editing !== '' ? '&channel=' . urlencode($editing) : '')) ?>">

  <section class="card">
    <h2>总开关</h2>
    <div class="field-grid">
      <label class="check-line">
        <input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
        <span>启用通知</span>
      </label>
      <label>风暴保护（10 分钟内同一事件最多几条）
        <input type="number" name="burst_limit" value="<?= (int) ($config['burst_limit'] ?? 3) ?>" min="1" max="20">
      </label>
    </div>
    <p class="hint">
      风暴保护很必要：服务端反复重启时，如果不限流，你的群会被刷屏。超过阈值的通知会被跳过并记进操作日志。
    </p>
  </section>

  <section class="card">
    <h2>通知渠道</h2>
    <table class="table table-compact">
      <thead>
        <tr><th>渠道</th><th>类型</th><th>状态</th><th>操作</th></tr>
      </thead>
      <tbody>
        <?php foreach ($channelConfigs as $key => $channelConfig): ?>
          <?php
          if (!is_array($channelConfig)) {
              continue;
          }
          $type = (string) ($channelConfig['type'] ?? $key);
          $adapter = Notifier::adapter((string) $key);
          $missing = $adapter === null ? ChannelRegistry::missingFields($type, $channelConfig) : [];
          $enabled = !array_key_exists('enabled', $channelConfig) || (bool) $channelConfig['enabled'];
          ?>
          <tr>
            <td><b><?= e((string) ($labels[$type] ?? $type)) ?></b><div class="sub mono"><?= e((string) $key) ?></div></td>
            <td class="sub"><?= e($type) ?></td>
            <td>
              <?php if (!$enabled): ?>
                <span class="pill pill-muted">已停用</span>
              <?php elseif ($adapter !== null): ?>
                <span class="pill pill-ok">就绪</span>
              <?php else: ?>
                <span class="pill pill-warn">配置不完整</span>
                <div class="sub">缺：<?= e(implode('、', $missing)) ?></div>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <a class="btn btn-mini" href="<?= e(\MCFix\ConsoleAuth::url('p=notify&channel=' . urlencode((string) $key))) ?>">配置</a>
              <?php if ($adapter !== null): ?>
                <button class="btn btn-mini" type="submit" name="test_channel" value="<?= e((string) $key) ?>">发送测试</button>
              <?php endif; ?>
              <button class="btn btn-mini btn-danger" type="submit" name="delete_channel" value="<?= e((string) $key) ?>"
                      onclick="return confirm('删除渠道 <?= e((string) $key) ?>？')">删除</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$channelConfigs): ?>
          <tr><td colspan="4" class="sub">还没有配置任何渠道。在下面添加一个。</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <h2><?= $editing !== '' ? '编辑渠道：' . e((string) ($labels[(string) ($channelConfigs[$editing]['type'] ?? $editing)] ?? $editing)) : '添加渠道' ?></h2>

    <input type="hidden" name="editing_channel" value="<?= e($editing) ?>">

    <div class="field-grid">
      <label>渠道标识（英文短名，订阅时要用）
        <input name="channel_key" value="<?= e($editing !== '' ? $editing : '') ?>" placeholder="dingtalk-main" <?= $editing !== '' ? 'readonly' : '' ?>>
      </label>
      <label>渠道类型
        <select name="channel_type">
          <?php $editingType = (string) ($channelConfigs[$editing]['type'] ?? ''); ?>
          <?php foreach ($types as $type => $class): ?>
            <option value="<?= e((string) $type) ?>"<?= $editingType === $type ? ' selected' : '' ?>><?= e((string) ($labels[$type] ?? $type)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>接口超时（秒）<input type="number" name="timeout" value="<?= (int) ($channelConfigs[$editing]['timeout'] ?? 10) ?>" min="3" max="60"></label>
      <label class="check-line">
        <input type="checkbox" name="channel_enabled" value="1" <?= ($editing === '' || !array_key_exists('enabled', (array) ($channelConfigs[$editing] ?? [])) || !empty($channelConfigs[$editing]['enabled'])) ? 'checked' : '' ?>>
        <span>启用这个渠道</span>
      </label>
    </div>

    <h3>各渠道的配置项</h3>
    <p class="hint">按你选的类型填写对应的字段，其它留空即可。</p>

    <?php foreach ($fieldHints as $type => $fields): ?>
      <details class="manual-recipe" <?= $editingType === $type ? 'open' : '' ?>>
        <summary><?= e((string) ($labels[$type] ?? $type)) ?></summary>
        <div class="field-grid">
          <?php foreach ($fields as $field => $hint): ?>
            <?php
            $currentValue = (string) ($channelConfigs[$editing][$field] ?? '');
            $isLong = in_array($field, ['headers', 'template'], true);
            /*
             * 凭据字段不回显（V11）。
             *
             * 下面这些字段的值是密钥/令牌 —— 直接渲染进 HTML 等于把密钥抄进
             * 页面源码、浏览器缓存和"另存为"的文件里。SMTP 密码和 AI Key 早就是
             * 不回显的（settings.php 用「已配置，留空表示不改」的占位符），
             * 通知渠道这页当时漏了。
             */
            $secretFields = ['secret', 'token', 'send_key', 'bot_token', 'device_key'];
            $isSecret = in_array($field, $secretFields, true) && $currentValue !== '';
            ?>
            <label<?= $isLong ? ' style="grid-column:1/-1"' : '' ?>>
              <?= e($hint) ?>
              <?php if ($isSecret): ?>
                <input type="password" name="cfg_<?= e($type) ?>_<?= e($field) ?>" value=""
                       placeholder="已配置，留空表示不改" autocomplete="off">
                <span class="hint">
                  要清空请勾选：<input type="checkbox" name="cfg_clear_<?= e($type) ?>_<?= e($field) ?>" value="1"> 清除
                </span>
              <?php elseif ($isLong): ?>
                <textarea name="cfg_<?= e($type) ?>_<?= e($field) ?>" rows="3"><?= e($currentValue) ?></textarea>
              <?php else: ?>
                <input name="cfg_<?= e($type) ?>_<?= e($field) ?>" value="<?= e($currentValue) ?>">
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($type === 'webhook'): ?>
          <p class="hint">
            模板占位符：<code>{title}</code> <code>{text}</code> <code>{markdown}</code>
            <code>{link}</code> <code>{event}</code> <code>{level}</code> <code>{server}</code>
          </p>
          <p class="hint">
            例子：企业微信之外的短信网关 →
            <code>{"msg":"{title}\n{text}","to":"13800000000"}</code>
          </p>
        <?php endif; ?>
        <?php if ($type === 'mail'): ?>
          <p class="hint">
            宝塔上如果发不出去，到【软件商店】装一个 Postfix（或用 msmtp 配置 php.ini 的 <code>sendmail_path</code>）。
            部署指南里有详细步骤。
          </p>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>

    <div class="form-foot">
      <button class="btn btn-primary" type="submit" name="save_channel" value="1">保存这个渠道</button>
      <?php if ($editing !== ''): ?>
        <a class="btn btn-ghost" href="<?= e($redirect) ?>">取消编辑</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <h2>事件订阅</h2>
    <p class="hint">勾选「哪些事件」发到「哪些渠道」。不勾任何渠道的事件不会发通知。</p>

    <?php if (!$channelConfigs): ?>
      <p class="hint">先添加至少一个渠道，这里才会出现可勾选的列。</p>
    <?php else: ?>
      <table class="table table-compact">
        <thead>
          <tr>
            <th>事件</th>
            <?php foreach ($channelConfigs as $key => $channelConfig): ?>
              <?php if (!is_array($channelConfig)) { continue; } ?>
              <th><?= e((string) $key) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach (Notifier::EVENTS as $event => $eventLabel): ?>
            <tr>
              <td><?= e($eventLabel) ?><div class="sub mono"><?= e($event) ?></div></td>
              <?php foreach ($channelConfigs as $key => $channelConfig): ?>
                <?php
                if (!is_array($channelConfig)) {
                    continue;
                }
                $subscribed = isset($subscriptions[$key]) && is_array($subscriptions[$key])
                    && (in_array('*', $subscriptions[$key], true) || in_array($event, $subscriptions[$key], true));
                // 没有订阅配置时按默认规则显示
                if (!$subscriptions) {
                    $subscribed = !empty(Notifier::DEFAULT_EVENTS[$event]);
                }
                ?>
                <td>
                  <input type="checkbox" name="sub_<?= e((string) $key) ?>_<?= e($event) ?>" value="1" <?= $subscribed ? 'checked' : '' ?>>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint">
        推荐：<b>自动修复成功 ✅ / 自动修复失败 ❌ / 转人工 ⚠️ / 待批准 🔐</b> 推到群里；
        「收到新反馈」如果流量大就别开，否则会刷屏。
      </p>
    <?php endif; ?>

    <div class="form-foot">
      <button class="btn btn-primary" type="submit">保存全部设置</button>
    </div>
  </section>
</form>

<section class="card">
  <h2>发送记录</h2>
  <div class="filter-bar">
    <div class="filter-chips">
      <a class="fchip <?= $statusFilter === '' ? 'is-active' : '' ?>" href="<?= e($redirect) ?>">全部</a>
      <a class="fchip <?= $statusFilter === 'sent' ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('p=notify&status=sent')) ?>">成功 <em><?= (int) $sentCount ?></em></a>
      <a class="fchip <?= $statusFilter === 'failed' ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('p=notify&status=failed')) ?>">失败待重试 <em><?= (int) $failedCount ?></em></a>
      <a class="fchip <?= $statusFilter === 'abandoned' ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('p=notify&status=abandoned')) ?>">已放弃 <em><?= (int) $abandonedCount ?></em></a>
    </div>
  </div>

  <?php if (!$records): ?>
    <p class="hint">还没有通知记录。</p>
  <?php else: ?>
    <table class="table table-compact">
      <thead><tr><th>时间</th><th>渠道</th><th>事件</th><th>标题</th><th>状态</th><th>工单</th></tr></thead>
      <tbody>
        <?php foreach ($records as $row): ?>
          <tr class="lv-<?= (string) $row['status'] === 'sent' ? 'info' : 'warn' ?>">
            <td class="sub mono"><?= e(date('m-d H:i:s', ts((string) $row['created_at']))) ?></td>
            <td><span class="tag"><?= e((string) $row['channel']) ?></span></td>
            <td class="mono sub"><?= e((string) $row['event']) ?></td>
            <td>
              <?= e(mb_substr((string) $row['title'], 0, 60)) ?>
              <?php if (!empty($row['last_error'])): ?>
                <div class="sub bad"><?= e(mb_substr((string) $row['last_error'], 0, 160)) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $statusTone = ['sent' => 'ok', 'failed' => 'warn', 'abandoned' => 'bad'][(string) $row['status']] ?? 'muted';
              $statusText = ['sent' => '已送达', 'failed' => '待重试', 'abandoned' => '已放弃'][(string) $row['status']] ?? (string) $row['status'];
              ?>
              <span class="pill pill-<?= e($statusTone) ?>"><?= e($statusText) ?></span>
              <div class="sub">尝试 <?= (int) $row['attempts'] ?> 次</div>
            </td>
            <td>
              <?php if (!empty($row['feedback_id'])): ?>
                <a class="mono" href="<?= e(\MCFix\ConsoleAuth::url('p=ticket&id=' . (int) $row['feedback_id'])) ?>">#<?= (int) $row['feedback_id'] ?></a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php render_pager($page, $pages, static function (int $p) use ($statusFilter): string {
        return \MCFix\ConsoleAuth::url(
            'p=notify&page=' . $p . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '')
        );
    }); ?>
  <?php endif; ?>

  <p class="hint">
    失败的通知由计划任务自动重试（最多 3 次，每次间隔一分钟）。也可以点上面的「重试失败的通知」立刻重试。
    连续失败通常是配置问题：Webhook 地址错了、密钥过期、或者服务器出不了网。
  </p>
</section>
