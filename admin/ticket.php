<?php
/**
 * 后台：工单详情 —— 诊断结果、修复任务、时间线、管理员操作。
 */

declare(strict_types=1);

use MCFix\Catalog;
use MCFix\Config;
use MCFix\Db;
use MCFix\Recipe;
use MCFix\Task;
use MCFix\Token;
use MCFix\Workflow;

$ticketId = int_param($_GET, 'id');
$feedback = Workflow::find($ticketId);

if ($feedback === null) {
    echo '<div class="card empty-state"><h2>工单不存在</h2><p>可能已经被清理。</p>'
        . '<a class="btn btn-ghost" href="' . e(\MCFix\ConsoleAuth::url()) . '">返回工单列表</a></div>';

    return;
}

$serverId = (string) $feedback['server_id'];
$server = Config::server($serverId);
$diagnosis = safe_json_decode((string) ($feedback['diagnosis'] ?? ''));
$verdict = safe_json_decode((string) ($feedback['verdict'] ?? ''));
$fixPlan = safe_json_decode((string) ($feedback['fix_plan'] ?? ''));
$fixResult = safe_json_decode((string) ($feedback['fix_result'] ?? ''));
$tasks = Task::forFeedback($ticketId, 20);
$events = Db::all('SELECT * FROM events WHERE feedback_id = :id ORDER BY id DESC LIMIT 80', ['id' => $ticketId]);
$shareUrl = Token::feedbackUrl($feedback);
$redirect = \MCFix\ConsoleAuth::url('p=ticket&id=' . $ticketId);

$clientAnalysis = safe_json_decode((string) ($feedback['client_analysis'] ?? ''));
$clientLog = (string) ($feedback['client_log'] ?? '');
$serverModsInfo = Config::server($serverId) !== null ? \MCFix\ServerMods::forServer((array) $server) : [];

$statusTone = status_tone((string) $feedback['status']);
$severityIcon = static function (string $severity): string {
    switch ($severity) {
        case 'critical':
            return '🔴';
        case 'high':
            return '🟠';
        case 'normal':
            return '🟡';
        default:
            return '⚪';
    }
};
?>
<header class="page-head">
  <div>
    <a class="back-link" href="<?= e(\MCFix\ConsoleAuth::url()) ?>">← 返回工单列表</a>
    <h1>
      <span class="mono"><?= e((string) $feedback['ticket_no']) ?></span>
      <span class="pill pill-<?= e($statusTone) ?>"><?= e(status_label((string) $feedback['status'])) ?></span>
    </h1>
    <p class="hint">
      <?= e((string) ($server['name'] ?? $serverId)) ?> ·
      玩家 <b><?= e((string) $feedback['player_name']) ?></b> ·
      <?= e(human_time((string) $feedback['created_at'])) ?> 提交 ·
      最后更新 <?= e(human_time((string) $feedback['updated_at'])) ?>
    </p>
  </div>
  <div class="page-actions">
    <form method="post" class="inline-form">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="ticket_action">
      <input type="hidden" name="id" value="<?= $ticketId ?>">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <button class="btn btn-primary" type="submit" name="op" value="verify">重新验证</button>
      <?php if (!empty($verdict['suggestions'])): ?>
        <button class="btn btn-ghost" type="submit" name="op" value="autofix">执行建议修复</button>
      <?php endif; ?>
    </form>
  </div>
</header>

<div class="ticket-grid">
  <div class="ticket-main">

    <section class="card">
      <h2>玩家描述</h2>
      <div class="quote">
        <div class="quote-meta">
          分类：<?= e((string) (Catalog::category((string) $feedback['category'])['label'] ?? $feedback['category'])) ?>
          <?php if ((string) $feedback['raw_category'] === 'auto'): ?>（系统自动归类）<?php endif; ?>
        </div>
        <p><?= nl2br(e((string) $feedback['message'])) ?></p>
        <?php if (!empty($feedback['contact'])): ?>
          <div class="quote-meta">联系方式：<?= e((string) $feedback['contact']) ?></div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($clientAnalysis || $clientLog !== ''): ?>
      <section class="card">
        <h2>🧯 客户端日志分析</h2>

        <?php if ($clientAnalysis): ?>
          <p class="hint">
            <?= e((string) ($clientAnalysis['public']['kind_label'] ?? '')) ?>
            <?php if (!empty($feedback['client_log_name'])): ?>
              · 文件 <span class="mono"><?= e((string) $feedback['client_log_name']) ?></span>
            <?php endif; ?>
            · <?= e(human_size((float) ($feedback['client_log_size'] ?? 0))) ?>
            <?php if (!empty($feedback['client_log_source'])): ?>
              · 来源 <?= e((string) $feedback['client_log_source']) ?>
            <?php endif; ?>
          </p>

          <div class="verdict-box sev-<?= e((string) ($clientAnalysis['severity'] ?? 'normal')) ?>">
            <h3><?= e((string) ($clientAnalysis['headline'] ?? '')) ?></h3>
            <p><?= e(\MCFix\ClientAdvisor::adminSummary($clientAnalysis)) ?></p>
          </div>

          <?php foreach ((array) ($clientAnalysis['issues'] ?? []) as $issue): ?>
            <div class="client-issue sev-<?= e((string) $issue['severity']) ?>">
              <div class="client-issue-head">
                <span class="client-issue-badge"><?= e((string) $issue['code']) ?></span>
                <h3><?= e((string) $issue['title']) ?></h3>
                <span class="tag"><?= e((string) ($issue['source'] ?? '')) ?></span>
              </div>
              <p class="client-issue-cause"><?= e((string) $issue['cause']) ?></p>
              <?php if (!empty($issue['detail'])): ?>
                <p class="client-issue-detail">线索：<?= e((string) $issue['detail']) ?></p>
              <?php endif; ?>
              <?php if (!empty($issue['extra'])): ?>
                <div class="client-tags">
                  <?php foreach ((array) $issue['extra'] as $item): ?>
                    <span class="tag"><?= e((string) $item) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <details class="raw">
                <summary>玩家看到的解决步骤（<?= count((array) $issue['steps']) ?> 步）</summary>
                <ol class="problem-list">
                  <?php foreach ((array) $issue['steps'] as $step): ?>
                    <li><?= e((string) $step) ?></li>
                  <?php endforeach; ?>
                </ol>
              </details>
              <?php $serverFix = (array) ($issue['server_fix'] ?? []); ?>
              <?php if (!empty($serverFix['labels'])): ?>
                <div class="server-fix">
                  <span class="pill pill-<?= !empty($serverFix['auto']) ? 'ok' : (!empty($serverFix['needs_approval']) ? 'warn' : 'info') ?>">
                    <?= !empty($serverFix['auto']) ? '可自动执行' : (!empty($serverFix['needs_approval']) ? '需批准' : '需人工') ?>
                  </span>
                  <span class="server-fix-text">服务端动作：<?= e(implode('、', (array) $serverFix['labels'])) ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($serverFix['blocked'])): ?>
                <p class="hint bad">被配置禁用：<?= e(implode('、', (array) $serverFix['blocked'])) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if (!empty($clientAnalysis['suspects'])): ?>
            <h3>可疑 MOD</h3>
            <div class="client-tags">
              <?php foreach ((array) $clientAnalysis['suspects'] as $suspect): ?>
                <span class="tag tag-warn"><?= e((string) $suspect) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <h3>客户端环境</h3>
          <dl class="kv">
            <?php foreach ((array) ($clientAnalysis['facts'] ?? []) as $fact): ?>
              <dt><?= e((string) $fact['label']) ?></dt><dd><?= e((string) $fact['value']) ?></dd>
            <?php endforeach; ?>
          </dl>

          <?php $cross = (array) ($clientAnalysis['cross_check'] ?? []); ?>
          <details class="raw">
            <summary>与服务端 MOD 清单的比对</summary>
            <?php if (empty($serverModsInfo['available'])): ?>
              <p class="hint">服务端未上报 MOD 清单（Agent 需要更新到 v1.3+，且 mc_dir 下有 mods 目录）</p>
            <?php else: ?>
              <p class="hint">
                服务端共 <?= (int) $serverModsInfo['count'] ?> 个文件；
                客户端日志解析出 <?= (int) ($cross['client_count'] ?? 0) ?> 个 MOD；
                名称匹配 <?= (int) ($cross['matched'] ?? 0) ?> 个
              </p>
              <?php if (!empty($cross['client_extra'])): ?>
                <p class="bad">客户端多装（<?= count((array) $cross['client_extra']) ?>）：</p>
                <pre><?= e(implode("\n", (array) $cross['client_extra'])) ?></pre>
              <?php endif; ?>
              <?php if (!empty($cross['client_missing'])): ?>
                <p class="warn-text">客户端缺少（<?= count((array) $cross['client_missing']) ?>）：</p>
                <pre><?= e(implode("\n", (array) $cross['client_missing'])) ?></pre>
              <?php endif; ?>
              <?php if (empty($cross['client_extra']) && empty($cross['client_missing'])): ?>
                <p class="ok">两边 MOD 名称完全对得上，问题不在 MOD 缺失上</p>
              <?php endif; ?>
            <?php endif; ?>
          </details>

          <?php if (!empty($clientAnalysis['needs']['components'])): ?>
            <h3>玩家需要补齐的文件</h3>
            <table class="table table-compact">
              <thead><tr><th>组件</th><th>状态</th><th>操作</th></tr></thead>
              <tbody>
              <?php foreach ((array) $clientAnalysis['needs']['components'] as $component): ?>
                <tr>
                  <td class="mono"><?= e((string) $component['name']) ?></td>
                  <td>
                    <?php if (!empty($component['available'])): ?>
                      <span class="tag tag-ok">已入库<?= !empty($component['is_local']) ? '（本地）' : '' ?></span>
                      <?= e((string) ($component['note'] ?? '')) ?>
                    <?php else: ?>
                      <span class="tag tag-warn">库里没有</span>
                      <?= e((string) ($component['note'] ?? '')) ?>
                    <?php endif; ?>
                  </td>
                  <td class="row-actions">
                    <?php if (empty($component['available'])): ?>
                      <form method="post" class="inline-form">
                        <?= \MCFix\ConsoleAuth::csrfField() ?>
                        <input type="hidden" name="admin_action" value="pull_mod">
                        <input type="hidden" name="id" value="<?= $ticketId ?>">
                        <input type="hidden" name="component" value="<?= e((string) $component['name']) ?>">
                        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                        <button class="btn btn-mini" type="submit">让 Agent 取回</button>
                      </form>
                    <?php else: ?>
                      <a class="btn btn-mini" target="_blank" rel="noopener"
                         href="<?= e((string) $component['download']) ?>">下载链接</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <details class="raw">
            <summary>完整分析 JSON</summary>
            <pre class="raw-pre"><?= e((string) json_encode($clientAnalysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
          </details>
        <?php endif; ?>

        <?php if ($clientLog !== ''): ?>
          <details class="raw">
            <summary>玩家提交的原始日志（<?= e(human_size((float) strlen($clientLog))) ?>）</summary>
            <pre class="raw-pre"><?= e(mb_substr($clientLog, 0, 20000)) ?><?= strlen($clientLog) > 20000 ? "\n…（已截断）" : '' ?></pre>
          </details>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($verdict): ?>
      <section class="card">
        <h2>自动验证结论</h2>        <div class="verdict-box sev-<?= e((string) ($verdict['severity'] ?? 'normal')) ?>">
          <h3><?= $severityIcon((string) ($verdict['severity'] ?? 'normal')) ?> <?= e((string) ($verdict['title'] ?? '')) ?></h3>
          <p><?= e((string) ($verdict['detail'] ?? '')) ?></p>
          <ul class="verdict-flags">
            <li>问题码：<code><?= e((string) ($verdict['issue'] ?? '')) ?></code></li>
            <li>可自动修复：<b class="<?= !empty($verdict['auto_fixable']) ? 'ok' : 'bad' ?>"><?= !empty($verdict['auto_fixable']) ? '是' : '否' ?></b></li>
            <li>需要人工：<b><?= !empty($verdict['needs_manual']) ? '是' : '否' ?></b></li>
            <li>需管理员批准：<b><?= !empty($verdict['requires_approval']) ? '是' : '否' ?></b></li>
          </ul>
        </div>
        <p class="hint pre"><?= e((string) ($verdict['operator_hint'] ?? '')) ?></p>
      </section>

      <section class="card">
        <h2>验证明细</h2>
        <div class="checks">
          <?php foreach ((array) ($diagnosis['checks'] ?? []) as $code => $check): ?>
            <?php if (!is_array($check)) { continue; } ?>
            <div class="check check-<?= e((string) ($check['status'] ?? 'unknown')) ?>">
              <span class="check-dot"></span>
              <div class="check-body">
                <b><?= e((string) ($check['label'] ?? $code)) ?></b>
                <small><?= e((string) ($check['message'] ?? '')) ?></small>
                <?php if (!empty($check['data'])): ?>
                  <details class="raw">
                    <summary>原始数据</summary>
                    <pre><?= e((string) json_encode($check['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                  </details>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>修复动作</h2>

      <?php if (!empty($verdict['suggestions'])): ?>
        <p class="hint">系统根据验证结果推荐了以下修复动作（已在服务器白名单内）：</p>
        <?php foreach ((array) $verdict['suggestions'] as $suggestion): ?>
          <?php $def = Recipe::get((string) ($suggestion['code'] ?? '')); ?>
          <div class="recipe-row">
            <div>
              <b><?= e((string) ($suggestion['label'] ?? '')) ?></b>
              <small><?= e((string) ($suggestion['description'] ?? '')) ?></small>
              <div class="tag-row">
                <span class="tag">风险：<?= e(Recipe::riskLabel((array) $def)) ?></span>
                <span class="tag">通道：<?= e((string) ($suggestion['exec'] ?? '')) ?></span>
                <?php if (!empty($suggestion['requires_approval'])): ?><span class="tag tag-warn">需管理员批准</span><?php endif; ?>
                <?php foreach ((array) ($suggestion['params'] ?? []) as $pk => $pv): ?>
                  <span class="tag"><?= e((string) $pk) ?>=<?= e((string) $pv) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
            <form method="post" class="recipe-actions">
              <?= \MCFix\ConsoleAuth::csrfField() ?>
              <input type="hidden" name="admin_action" value="ticket_action">
              <input type="hidden" name="id" value="<?= $ticketId ?>">
              <input type="hidden" name="op" value="run_recipe">
              <input type="hidden" name="recipe" value="<?= e((string) $suggestion['code']) ?>">
              <input type="hidden" name="player" value="<?= e((string) ($suggestion['params']['player'] ?? '')) ?>">
              <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
              <button class="btn btn-mini btn-primary" type="submit">立即执行</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="hint">当前没有可自动执行的配方（可能问题需要人工判断，或该动作被服务器配置禁用）。</p>
      <?php endif; ?>

      <details class="manual-recipe">
        <summary>手动执行任意配方（高级）</summary>
        <form method="post" class="inline-form">
          <?= \MCFix\ConsoleAuth::csrfField() ?>
          <input type="hidden" name="admin_action" value="ticket_action">
          <input type="hidden" name="id" value="<?= $ticketId ?>">
          <input type="hidden" name="op" value="run_recipe">
          <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
          <select name="recipe">
            <?php foreach (Recipe::all() as $code => $def): ?>
              <option value="<?= e((string) $code) ?>"><?= e((string) $def['label']) ?>（<?= e(Recipe::riskLabel($def)) ?>）</option>
            <?php endforeach; ?>
          </select>
          <input name="player" placeholder="玩家 ID（需要时填）" value="<?= e((string) $feedback['player_name']) ?>">
          <button class="btn btn-ghost" type="submit">执行</button>
        </form>
      </details>
    </section>

    <?php if ($tasks): ?>
      <section class="card">
        <h2>任务队列</h2>
        <table class="table table-compact">
          <thead>
            <tr><th>#</th><th>动作</th><th>状态</th><th>结果 / 错误</th><th>时间</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($tasks as $task): ?>
              <?php $taskResult = safe_json_decode((string) ($task['result'] ?? '')); ?>
              <tr>
                <td class="mono"><?= (int) $task['id'] ?></td>
                <td>
                  <b><?= e((string) $task['recipe']) ?></b>
                  <div class="sub"><?= e((string) $task['action']) ?> · <?= e((string) ($task['params'] ?? '')) ?></div>
                </td>
                <td>
                  <span class="pill pill-<?= e(status_tone((string) $task['status'])) ?>"><?= e(status_label((string) $task['status'])) ?></span>
                </td>
                <td class="sub">
                  <?php if (!empty($task['error'])): ?>
                    <span class="bad"><?= e((string) $task['error']) ?></span>
                  <?php elseif (!empty($taskResult['output'])): ?>
                    <span class="ok"><?= e(mb_substr((string) $taskResult['output'], 0, 160)) ?></span>
                  <?php elseif (!empty($taskResult['progress'])): ?>
                    <?= e((string) $taskResult['progress']) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="sub"><?= e(human_time((string) $task['created_at'])) ?></td>
                <td class="row-actions">
                  <?php if ((string) $task['status'] === Task::STATUS_AWAITING_APPROVAL): ?>
                    <form method="post" class="inline-form">
                      <?= \MCFix\ConsoleAuth::csrfField() ?>
                      <input type="hidden" name="admin_action" value="task_action">
                      <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                      <button class="btn btn-mini btn-primary" type="submit" name="op" value="approve">批准执行</button>
                      <button class="btn btn-mini" type="submit" name="op" value="reject">驳回</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>处理时间线</h2>
      <div class="timeline">
        <?php foreach (array_reverse($events) as $event): ?>
          <div class="tl-item tl-<?= e((string) $event['level']) ?>">
            <span class="tl-time"><?= e(date('m-d H:i:s', ts((string) $event['created_at']))) ?></span>
            <span class="tl-actor"><?= e((string) $event['actor']) ?></span>
            <span class="tl-msg"><?= e((string) $event['message']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <aside class="ticket-side">
    <section class="card">
      <h2>管理员操作</h2>
      <form method="post" class="stack-form">
        <?= \MCFix\ConsoleAuth::csrfField() ?>
        <input type="hidden" name="admin_action" value="ticket_action">
        <input type="hidden" name="id" value="<?= $ticketId ?>">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <textarea name="note" rows="3" placeholder="处理备注（可选）"></textarea>
        <div class="btn-row">
          <button class="btn btn-mini" type="submit" name="op" value="note">保存备注</button>
          <button class="btn btn-mini" type="submit" name="op" value="resolve">标记已解决</button>
          <button class="btn btn-mini" type="submit" name="op" value="reject">驳回工单</button>
          <button class="btn btn-mini" type="submit" name="op" value="reopen">重新打开</button>
          <button class="btn btn-mini" type="submit" name="op" value="close">关闭</button>
          <button class="btn btn-mini" type="submit" name="op" value="rotate_link">重置反馈链接</button>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>玩家反馈链接</h2>
      <p class="hint">把这条链接发给玩家，他随时能看到进度。重置后旧链接立刻失效。</p>
      <input class="copy-field" readonly value="<?= e($shareUrl) ?>" onclick="this.select()">
      <button class="btn btn-mini" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='已复制'">复制链接</button>
    </section>

    <section class="card">
      <h2>工单元数据</h2>
      <dl class="kv">
        <dt>工单 ID</dt><dd><?= $ticketId ?></dd>
        <dt>服务器</dt><dd><?= e($serverId) ?></dd>
        <dt>分类</dt><dd><?= e((string) $feedback['category']) ?></dd>
        <dt>严重级别</dt><dd><?= e((string) $feedback['severity']) ?></dd>
        <dt>修复尝试</dt><dd><?= (int) $feedback['fix_attempts'] ?> 次</dd>
        <dt>来源 IP 哈希</dt><dd class="mono"><?= e(mb_substr((string) $feedback['ip_hash'], 0, 12)) ?></dd>
        <dt>User-Agent</dt><dd class="sub"><?= e(mb_substr((string) $feedback['user_agent'], 0, 80)) ?></dd>
      </dl>
      <?php if (!empty($feedback['resolution_note'])): ?>
        <div class="alert alert-info"><?= e((string) $feedback['resolution_note']) ?></div>
      <?php endif; ?>
      <?php if (!empty($feedback['admin_note'])): ?>
        <div class="alert alert-warn">备注：<?= e((string) $feedback['admin_note']) ?></div>
      <?php endif; ?>
    </section>

    <?php if ($fixResult): ?>
      <section class="card">
        <h2>最近一次修复结果</h2>
        <dl class="kv">
          <dt>通道</dt><dd><?= e((string) ($fixResult['executor'] ?? '')) ?></dd>
          <dt>成功</dt><dd class="<?= !empty($fixResult['ok']) ? 'ok' : 'bad' ?>"><?= !empty($fixResult['ok']) ? '是' : '否' ?></dd>
          <dt>任务</dt><dd>#<?= (int) ($fixResult['task_id'] ?? 0) ?></dd>
        </dl>
        <pre class="raw-pre"><?= e((string) json_encode($fixResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
      </section>
    <?php endif; ?>
  </aside>
</div>
