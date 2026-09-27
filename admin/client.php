<?php
/**
 * 后台：客户端问题视图。
 *
 * 这一页回答三个管理问题：
 *   1. 最近玩家都在报什么错？（按问题码聚合，一眼看出是"大家都没装前置"还是"某个 MOD 炸了"）
 *   2. 哪些问题我们其实能自动解决，但没解决掉？（服务端有对应配方却被禁用/需批准）
 *   3. 玩家要的文件我们库里有没有？（缺的直接一键从服务端取回）
 */

declare(strict_types=1);

use MCFix\ClientIssue;
use MCFix\Config;
use MCFix\Db;
use MCFix\ModLibrary;

$days = max(1, min(90, int_param($_GET, 'days', 14)));
$serverFilter = param($_GET, 'server_id');
$onlyOpen = bool_param($_GET, 'open', false);
$since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
$redirect = \MCFix\ConsoleAuth::url('p=client&days=' . $days . ($serverFilter !== '' ? '&server_id=' . urlencode($serverFilter) : ''));

// ---------------------------------------------------------------- 聚合统计
$where = ['created_at > :since'];
$params = ['since' => $since];
if ($serverFilter !== '') {
    $where[] = 'server_id = :server_id';
    $params['server_id'] = $serverFilter;
}
if ($onlyOpen) {
    $where[] = 'resolved = 0';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$stats = Db::all(
    'SELECT code, COUNT(*) AS hits, COUNT(DISTINCT player_name) AS players, COUNT(DISTINCT server_id) AS servers,
            MAX(created_at) AS last_at, SUM(CASE WHEN resolved = 0 THEN 1 ELSE 0 END) AS open_count
     FROM client_issues' . $whereSql . '
     GROUP BY code ORDER BY hits DESC LIMIT 40',
    $params
);

$totalIssues = 0;
foreach ($stats as $row) {
    $totalIssues += (int) $row['hits'];
}

$analysedTickets = (int) Db::scalar(
    'SELECT COUNT(*) FROM feedback WHERE client_analysis IS NOT NULL AND created_at > :since',
    ['since' => $since]
);

/*
 * 「已处理」计数（F1）。
 *
 * 原来写的是 $whereSql . ' AND resolved = 1' —— 而 $whereSql 在勾了
 * 「只看未处理」时**本来就含** resolved = 0，拼起来变成
 * `WHERE ... resolved = 0 AND resolved = 1` → 恒为 0 行。
 * 也就是说这个数字在任何筛选组合下都是 0，永远不动。
 *
 * 而且语义上它也不该跟着筛选走：这是"已处理多少条"的**统计**，
 * 勾了"只看未处理"不代表已处理数要变成 0。所以这里单独构造条件，
 * 只沿用时间窗和服务器筛选，不带 resolved 过滤。
 */
$resolvedWhere = ['created_at > :since'];
$resolvedParams = ['since' => $since];
if ($serverFilter !== '') {
    $resolvedWhere[] = 'server_id = :server_id';
    $resolvedParams['server_id'] = $serverFilter;
}
$resolvedCount = (int) Db::scalar(
    'SELECT COUNT(*) FROM client_issues WHERE ' . implode(' AND ', $resolvedWhere) . ' AND resolved = 1',
    $resolvedParams
);

// ---------------------------------------------------------------- 明细列表
$listWhere = $where;
$listParams = $params;
$codeFilter = param($_GET, 'code');
if ($codeFilter !== '') {
    $listWhere[] = 'code = :code';
    $listParams['code'] = $codeFilter;
}

$rows = Db::all(
    'SELECT * FROM client_issues WHERE ' . implode(' AND ', $listWhere) . ' ORDER BY id DESC LIMIT 60',
    $listParams
);

$issueOptions = ClientIssue::options();
$modFiles = ModLibrary::listAll();
$serverMods = [];
foreach (Config::servers() as $id => $server) {
    $serverMods[(string) $id] = \MCFix\ServerMods::forServer($server);
}

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
    <h1>客户端问题</h1>
    <p class="hint">
      玩家上传崩溃报告后，系统自动解析出的问题都在这里。共分析 <b><?= (int) $analysedTickets ?></b> 条工单，
      识别出 <b><?= (int) $totalIssues ?></b> 个问题点（最近 <?= (int) $days ?> 天）。
    </p>
  </div>
  <div class="page-actions">
    <form method="get" class="filter-form" action="<?= e(url('')) ?>">
      <?php
      /*
       * 这里原来是一个写死的 <input name="r" value="admin.client">（F2）。
       *
       * 它有两个毛病：一是 ?r=admin.client 会被 index.php 的 `if ($controller === 'admin')
       * render_404()` 直接 404 —— 后台从来不是 ?r=admin 这种对外路由；二是它会把
       * form 的 action 覆盖掉。表单带上正确的 action（后台真实地址）就够，
       * 不需要这个字段。
       */
      ?>
      <select name="days" onchange="this.form.submit()">
        <?php foreach ([1 => '今天', 7 => '近 7 天', 14 => '近 14 天', 30 => '近 30 天', 90 => '近 90 天'] as $key => $label): ?>
          <option value="<?= (int) $key ?>"<?= $days === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="server_id" onchange="this.form.submit()">
        <option value="">全部服务器</option>
        <?php foreach (Config::servers() as $sid => $srv): ?>
          <option value="<?= e($sid) ?>"<?= $sid === $serverFilter ? ' selected' : '' ?>><?= e((string) ($srv['name'] ?? $sid)) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="check-line" style="margin:0">
        <input type="checkbox" name="open" value="1" <?= $onlyOpen ? 'checked' : '' ?> onchange="this.form.submit()"> 只看未处理
      </label>
    </form>
  </div>
</header>

<?php if (!$stats): ?>
  <div class="card empty-state">
    <h2>这段时间没有客户端问题记录</h2>
    <p>把玩家反馈链接发出去，玩家上传崩溃报告后这里就会自动汇总。</p>
    <p class="hint">如果玩家已经在提交日志但这里没数据，检查一下提交时是不是走了"粘贴文本"但没有实质内容。</p>
  </div>
<?php else: ?>

<div class="kpi-grid">
  <div class="kpi"><span>问题点总数</span><b><?= (int) $totalIssues ?></b><small>最近 <?= (int) $days ?> 天</small></div>
  <div class="kpi"><span>已处理</span><b class="ok"><?= (int) $resolvedCount ?></b><small>管理员标记</small></div>
  <div class="kpi"><span>未处理</span><b class="<?= ($totalIssues - $resolvedCount) > 0 ? 'bad' : 'ok' ?>"><?= (int) ($totalIssues - $resolvedCount) ?></b><small>建议逐条过一遍</small></div>
  <div class="kpi"><span>MOD 库文件</span><b><?= count($modFiles) ?></b><small>可直接发给玩家</small></div>
</div>

<section class="card table-card">
  <table class="table">
    <thead>
      <tr>
        <th>问题类型</th>
        <th>次数</th>
        <th>涉及玩家</th>
        <th>最近发生</th>
        <th>未处理</th>
        <th>系统能做什么</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stats as $row): ?>
        <?php
        $code = (string) $row['code'];
        $def = ClientIssue::get($code);
        $recipes = (array) ($def['recipes'] ?? []);
        ?>
        <tr>
          <td>
            <div class="strong"><?= $severityIcon((string) ($def['severity'] ?? 'normal')) ?> <?= e((string) ($def['title'] ?? $code)) ?></div>
            <div class="sub mono"><?= e($code) ?></div>
          </td>
          <td><b><?= (int) $row['hits'] ?></b></td>
          <td><?= (int) $row['players'] ?> 人 / <?= (int) $row['servers'] ?> 服</td>
          <td class="sub"><?= e(human_time((string) $row['last_at'])) ?></td>
          <td>
            <?php $openCount = (int) $row['open_count']; ?>
            <span class="pill pill-<?= $openCount > 0 ? 'warn' : 'ok' ?>"><?= $openCount ?></span>
          </td>
          <td>
            <?php if ($recipes): ?>
              <span class="tag tag-fix">服务端可执行：<?= e(implode('、', array_map(static function (string $r): string {
                  return (string) (\MCFix\Recipe::get($r)['label'] ?? $r);
              }, $recipes))) ?></span>
            <?php else: ?>
              <span class="tag">只能给玩家指引</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="btn btn-mini" href="<?= e(\MCFix\ConsoleAuth::url('p=client&code=' . urlencode($code) . '&days=' . $days . ($serverFilter !== '' ? '&server_id=' . urlencode($serverFilter) : ''))) ?>">看明细</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($codeFilter !== ''): ?>
  <section class="card">
    <h2>「<?= e((string) ($issueOptions[$codeFilter] ?? $codeFilter)) ?>」明细</h2>
    <?php $def = ClientIssue::get($codeFilter); ?>
    <?php if ($def): ?>
      <div class="verdict-box">
        <h3><?= e((string) $def['title']) ?></h3>
        <p><?= e((string) $def['cause']) ?></p>
        <details class="raw" open>
          <summary>系统给玩家的解决步骤</summary>
          <ol class="problem-list">
            <?php foreach ((array) $def['steps'] as $step): ?>
              <li><?= e((string) $step) ?></li>
            <?php endforeach; ?>
          </ol>
        </details>
      </div>
    <?php endif; ?>

    <table class="table table-compact">
      <thead><tr><th>时间</th><th>玩家</th><th>服务器</th><th>线索</th><th>可疑 MOD</th><th>状态</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php $extra = safe_json_decode((string) ($row['extra'] ?? '')); $mods = safe_json_decode((string) ($row['mods'] ?? '')); ?>
          <tr>
            <td class="sub mono"><?= e(date('m-d H:i', ts((string) $row['created_at']))) ?></td>
            <td><?= e((string) $row['player_name']) ?></td>
            <td class="sub"><?= e((string) (Config::server((string) $row['server_id'])['name'] ?? $row['server_id'])) ?></td>
            <td class="sub"><?= e(mb_substr((string) $row['detail'], 0, 120)) ?></td>
            <td class="sub"><?= e(implode('、', array_slice((array) $mods, 0, 4))) ?></td>
            <td>
              <span class="pill pill-<?= (int) $row['resolved'] === 1 ? 'ok' : 'warn' ?>">
                <?= (int) $row['resolved'] === 1 ? '已处理' : '未处理' ?>
              </span>
            </td>
            <td class="row-actions">
              <?php if (!empty($row['feedback_id'])): ?>
                <a class="btn btn-mini" href="<?= e(\MCFix\ConsoleAuth::url('p=ticket&id=' . (int) $row['feedback_id'])) ?>">工单</a>
              <?php endif; ?>
              <?php if ((int) $row['resolved'] === 0): ?>
                <form method="post" class="inline-form">
                  <?= \MCFix\ConsoleAuth::csrfField() ?>
                  <input type="hidden" name="admin_action" value="client_issue_action">
                  <input type="hidden" name="op" value="resolve">
                  <input type="hidden" name="issue_id" value="<?= (int) $row['id'] ?>">
                  <input type="hidden" name="redirect" value="<?= e($redirect . '&code=' . urlencode($codeFilter)) ?>">
                  <button class="btn btn-mini" type="submit">标记已处理</button>
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
  <h2>MOD 文件库</h2>
  <p class="hint">
    玩家缺 MOD 时，如果库里有文件，页面会直接给他下载按钮（版本一定对，比他自己去搜靠谱）。
    三个来源：管理员手工上传、玩家点「从服务器取回」触发 Agent 自动送来、后台一键批量取回。
  </p>

  <form method="post" class="inline-form" enctype="multipart/form-data">
    <?= \MCFix\ConsoleAuth::csrfField() ?>
    <input type="hidden" name="admin_action" value="upload_mod">
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <input type="file" name="mod_file" accept=".jar" required>
    <button class="btn btn-mini btn-primary" type="submit">上传到文件库</button>
  </form>

  <?php if ($modFiles): ?>
    <table class="table table-compact">
      <thead><tr><th>文件名</th><th>大小</th><th>来源</th><th>入库时间</th><th>SHA256</th><th></th></tr></thead>
      <tbody>
        <?php foreach (array_slice($modFiles, 0, 30) as $file): ?>
          <tr>
            <td class="mono"><?= e((string) $file['original']) ?></td>
            <td><?= e(human_size((float) ($file['size'] ?? 0))) ?></td>
            <td><span class="tag"><?= e((string) ($file['source'] ?? '')) ?></span></td>
            <td class="sub"><?= e(human_time((string) ($file['added_at'] ?? ''))) ?></td>
            <td class="mono sub"><?= e(substr((string) ($file['sha256'] ?? ''), 0, 12)) ?></td>
            <td class="row-actions">
              <form method="post" class="inline-form" onsubmit="return confirm('确定删除这个文件？')">
                <?= \MCFix\ConsoleAuth::csrfField() ?>
                <input type="hidden" name="admin_action" value="delete_mod">
                <input type="hidden" name="file" value="<?= e((string) $file['file']) ?>">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                <button class="btn btn-mini btn-danger" type="submit">删除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="hint">文件库还是空的。最省事的办法：让玩家在工单页面点「从服务器取回」，Agent 会自动把文件传上来。</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>服务端 MOD 清单状态</h2>
  <p class="hint">有了这份清单，系统才能做"客户端多装/少装"的比对。Agent 每次心跳都会上报一次。</p>
  <table class="table table-compact">
    <thead><tr><th>服务器</th><th>清单状态</th><th>文件数</th><th>上报时间</th></tr></thead>
    <tbody>
      <?php foreach (Config::servers() as $sid => $srv): ?>
        <?php $info = $serverMods[(string) $sid] ?? []; ?>
        <tr>
          <td><?= e((string) ($srv['name'] ?? $sid)) ?></td>
          <td>
            <?php if (!empty($info['available'])): ?>
              <span class="pill pill-ok">已上报</span>
            <?php else: ?>
              <span class="pill pill-warn">未上报</span>
              <span class="sub">Agent 需 v1.3+ 且 mc_dir 下有 mods 目录</span>
            <?php endif; ?>
          </td>
          <td><?= (int) ($info['count'] ?? 0) ?></td>
          <td class="sub"><?= !empty($info['generated_at']) ? e(human_time(date('Y-m-d H:i:s', (int) strtotime((string) $info['generated_at'])))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php endif; ?>
