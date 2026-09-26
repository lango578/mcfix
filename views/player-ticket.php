<?php
/**
 * 工单结果页（已结束的工单直接打开链接时走这里）。
 *
 * @var array<string,mixed> $progress
 * @var array<string,mixed> $feedback
 */
$tone = (string) ($progress['tone'] ?? 'muted');
?>
<section class="card progress-card is-static">
  <header class="progress-head">
    <div>
      <span class="ticket-no"><?= e($progress['ticket_no']) ?></span>
      <h2><?= e($progress['headline']) ?></h2>
    </div>
    <span class="pill pill-<?= e($tone) ?>"><?= e($progress['status_label']) ?></span>
  </header>

  <div class="meta-grid">
    <div><span>服务器</span><b><?= e($progress['server_name']) ?></b></div>
    <div><span>玩家</span><b><?= e($progress['player']) ?></b></div>
    <div><span>提交时间</span><b><?= e(human_time($progress['created_at'])) ?></b></div>
    <div><span>最后更新</span><b><?= e(human_time($progress['updated_at'])) ?></b></div>
  </div>

  <?php if (!empty($progress['problem']['title'])): ?>
    <div class="verdict-box">
      <h3><?= e($progress['problem']['title']) ?></h3>
      <p><?= e($progress['problem']['detail']) ?></p>
    </div>
  <?php endif; ?>

  <?php if (!empty($progress['client']['issues'])): ?>
    <?php $client = $progress['client']; ?>
    <div id="client-panel">
      <?php foreach ((array) $client['facts'] as $fact): ?>
        <?php if ($fact['label'] === '提交内容'): ?>
          <p class="hint">已分析：<?= e((string) $fact['value']) ?><?= !empty($client['log_name']) ? '（' . e((string) $client['log_name']) . '）' : '' ?></p>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php foreach ((array) $client['issues'] as $index => $issue): ?>
        <div class="client-issue sev-<?= e((string) $issue['severity']) ?>">
          <div class="client-issue-head">
            <span class="client-issue-badge"><?= $index === 0 ? '主要原因' : '相关问题' ?></span>
            <h3><?= e((string) $issue['title']) ?></h3>
          </div>
          <p class="client-issue-cause"><?= e((string) $issue['cause']) ?></p>
          <?php if (!empty($issue['detail'])): ?>
            <p class="client-issue-detail">日志线索：<?= e((string) $issue['detail']) ?></p>
          <?php endif; ?>
          <?php if (!empty($issue['extra'])): ?>
            <div class="client-tags">
              <?php foreach ((array) $issue['extra'] as $item): ?>
                <span class="tag"><?= e((string) $item) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($issue['steps'])): ?>
            <div class="client-steps">
              <b>你可以这样做：</b>
              <ol>
                <?php foreach ((array) $issue['steps'] as $step): ?>
                  <li><?= e((string) $step) ?></li>
                <?php endforeach; ?>
              </ol>
            </div>
          <?php endif; ?>
          <?php $serverFix = (array) ($issue['server_fix'] ?? []); ?>
          <?php if (!empty($serverFix['labels'])): ?>
            <div class="server-fix">
              <span class="pill pill-<?= !empty($serverFix['auto']) ? 'ok' : (!empty($serverFix['needs_approval']) ? 'warn' : 'info') ?>">
                <?= !empty($serverFix['auto']) ? '系统已自动处理' : (!empty($serverFix['needs_approval']) ? '已提交管理员确认' : '已通知管理员') ?>
              </span>
              <span class="server-fix-text">服务端动作：<?= e(implode('、', (array) $serverFix['labels'])) ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($client['components'])): ?>
        <div class="client-needs">
          <b>需要补齐的文件</b>
          <p class="hint">版本必须和服务端一致，直接从下面下载最稳。</p>
          <?php foreach ((array) $client['components'] as $component): ?>
            <div class="need-row">
              <div class="need-name">
                <?= e((string) $component['name']) ?>
                <?php if (!empty($component['size_text'])): ?><small><?= e((string) $component['size_text']) ?></small><?php endif; ?>
              </div>
              <?php if (!empty($component['available']) && !empty($component['download'])): ?>
                <a class="btn btn-mini btn-primary" href="<?= e((string) $component['download']) ?>">下载</a>
              <?php else: ?>
                <a class="btn btn-mini" href="<?= e((string) $component['search']) ?>" target="_blank" rel="noopener">去 Modrinth 搜</a>
              <?php endif; ?>
              <?php if (!empty($component['note'])): ?>
                <span class="need-note"><?= e((string) $component['note']) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php $cross = (array) ($client['cross'] ?? []); ?>
      <?php if (!empty($cross['extra']) || !empty($cross['missing'])): ?>
        <details class="cross-box">
          <summary>与服务端的 MOD 比对结果</summary>
          <?php if (!empty($cross['extra'])): ?>
            <p class="bad">你的客户端多装了这些（服务端没有）：</p>
            <div class="client-tags">
              <?php foreach ((array) $cross['extra'] as $item): ?>
                <span class="tag tag-warn"><?= e((string) $item) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($cross['missing'])): ?>
            <p class="warn-text">你缺少这些（服务端有）：</p>
            <div class="client-tags">
              <?php foreach ((array) $cross['missing'] as $item): ?>
                <span class="tag tag-fix"><?= e((string) $item) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($progress['checks'])): ?>
    <div class="checks">
      <?php foreach ($progress['checks'] as $check): ?>
        <div class="check check-<?= e($check['status']) ?>">
          <span class="check-dot"></span>
          <div>
            <b><?= e($check['label']) ?></b>
            <small><?= e($check['text']) ?></small>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($progress['resolution'])): ?>
    <div class="alert alert-info"><?= e($progress['resolution']) ?></div>
  <?php endif; ?>

  <?php if (!empty($progress['timeline'])): ?>
    <details class="timeline-wrap" open>
      <summary>处理过程（<?= count($progress['timeline']) ?> 步）</summary>
      <div class="timeline">
        <?php foreach ($progress['timeline'] as $event): ?>
          <div class="tl-item tl-<?= e($event['level']) ?>">
            <span class="tl-time"><?= e(date('m-d H:i:s', ts($event['at']))) ?></span>
            <span class="tl-msg"><?= e($event['message']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <footer class="progress-foot">
    <a class="btn btn-ghost" href="<?= e(url('')) ?>">再提交一条反馈</a>
  </footer>
</section>
