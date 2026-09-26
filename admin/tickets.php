<?php
/**
 * 后台：工单列表。
 */

declare(strict_types=1);

use MCFix\Config;
use MCFix\Db;

$status = param($_GET, 'status');
$serverId = param($_GET, 'server_id');
$keyword = param($_GET, 'q', '', 60);
$page = max(1, int_param($_GET, 'page', 1));
$perPage = 20;

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'status = :status';
    $params['status'] = $status;
}
if ($serverId !== '') {
    $where[] = 'server_id = :server_id';
    $params['server_id'] = $serverId;
}
if ($keyword !== '') {
    // LIKE 通配符要转义（V14）：值本身是绑定的、没有注入风险，
    // 但不转义的话搜 `%` 等价于不筛选、搜 `_` 会匹配任意单字符。
    // ESCAPE 明确指定转义符，不去赌各驱动的默认行为。
    //
    // ★ 转义符刻意**不用反斜杠**：PHP 双引号串里写 ESCAPE '\\' 落到 SQL 就是
    // ESCAPE '\'。SQLite 认（单个字符），MySQL 不认（1064 语法错误）→ 后台
    // 工单搜索直接 500。'!' 两个引擎都认。
    $where[] = "(player_name LIKE :kw ESCAPE '!' OR ticket_no LIKE :kw ESCAPE '!'"
        . " OR subject LIKE :kw ESCAPE '!' OR message LIKE :kw ESCAPE '!')";
    $params['kw'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$total = (int) Db::scalar('SELECT COUNT(*) FROM feedback' . $whereSql, $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$tickets = Db::all(
    'SELECT * FROM feedback' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
    $params
);

$statusBuckets = [];
foreach (Db::all('SELECT status, COUNT(*) AS c FROM feedback GROUP BY status') as $row) {
    $statusBuckets[(string) $row['status']] = (int) $row['c'];
}
$allCount = array_sum($statusBuckets);

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

$queryString = static function (array $override = []) use ($status, $serverId, $keyword): string {
    $base = array_filter([
        'status'    => $status,
        'server_id' => $serverId,
        'q'         => $keyword,
    ], static function ($v): bool {
        return $v !== '' && $v !== null;
    });

    return http_build_query(array_merge($base, $override));
};
?>
<header class="page-head">
  <div>
    <h1>工单</h1>
    <p class="hint">玩家反馈 → 自动验证 → 自动修复 → 复验，全过程都在这里。</p>
  </div>
  <div class="page-actions">
    <form method="post" class="inline-form">
      <?= \MCFix\ConsoleAuth::csrfField() ?>
      <input type="hidden" name="admin_action" value="run_maintenance">
      <input type="hidden" name="job" value="all">
      <input type="hidden" name="redirect" value="<?= e(\MCFix\ConsoleAuth::url()) ?>">
      <button class="btn btn-ghost" type="submit">立即跟进待复验工单</button>
    </form>
  </div>
</header>

<?php
/*
 * 这三个数字原来只出现在侧栏底部，手机上是折进汉堡菜单里的 —— 等于手机上根本看不到。
 * 它们属于"这个站现在什么状态"，本来就该在主页（工单页）上，不该藏进导航里。
 * 变量由 public/controllers/admin.php 的闭包提供（这里是 require 进来的，在作用域内）。
 */
$kpis = [
    ['待执行任务', $pendingTasks, $pendingTasks > 0 ? 'warn-text' : ''],
    ['待处理工单', $openTickets, $openTickets > 0 ? 'warn-text' : ''],
    ['Agent 在线', $agentOnline, $agentOnline > 0 ? 'ok' : 'bad'],
];
?>
<div class="kpi-grid kpi-grid-sm kpi-mobile-only">
  <?php foreach ($kpis as [$label, $value, $tone]): ?>
    <div class="kpi">
      <span><?= e($label) ?></span>
      <b class="<?= e($tone) ?>"><?= (int) $value ?></b>
    </div>
  <?php endforeach; ?>
</div>

<div class="filter-bar">
  <div class="filter-chips">
    <a class="fchip <?= $status === '' ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('' . $queryString(['status' => '', 'page' => 1]))) ?>">全部 <em><?= (int) $allCount ?></em></a>
    <?php foreach (['submitted', 'diagnosing', 'fixing', 'verifying', 'resolved', 'manual', 'unresolved', 'rejected', 'closed'] as $key): ?>
      <?php $count = $statusBuckets[$key] ?? 0; ?>
      <?php if ($count === 0 && $status !== $key) { continue; } ?>
      <a class="fchip <?= $status === $key ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('' . $queryString(['status' => $key, 'page' => 1]))) ?>">
        <?= e(status_label($key)) ?> <em><?= (int) $count ?></em>
      </a>
    <?php endforeach; ?>
  </div>

  <form class="filter-form" method="get" action="<?= e(url('')) ?>">
    <?php
    /*
     * 原来这里写死 <input name="r" value="admin">（F2）——
     * ?r=admin 正是 index.php 明确要 404 掉的那个路由（不暴露后台位置）。
     * 靠 form 的 action（后台真实地址）就行，这个字段必须去掉。
     */
    ?>
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <select name="server_id" onchange="this.form.submit()">
      <option value="">全部服务器</option>
      <?php foreach (Config::servers() as $sid => $srv): ?>
        <option value="<?= e($sid) ?>"<?= $sid === $serverId ? ' selected' : '' ?>><?= e($srv['name'] ?? $sid) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($keyword) ?>" placeholder="搜索玩家 / 工单号 / 关键词">
    <button class="btn btn-ghost" type="submit">搜索</button>
  </form>
</div>

<?php if (!$tickets): ?>
  <div class="card empty-state">
    <h2>暂时没有工单</h2>
    <p>把玩家反馈链接发到群里，玩家提交后这里就会出现记录。</p>
    <p class="hint">链接生成方式：进入某台服务器的「详情」，或直接访问 <code>?r=install</code> 完成配置后由 Agent 页面生成。</p>
  </div>
<?php else: ?>
<div class="card table-card">
  <table class="table">
    <thead>
      <tr>
        <th>工单</th>
        <th>服务器 / 玩家</th>
        <th>问题</th>
        <th>验证结论</th>
        <th>状态</th>
        <th>时间</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tickets as $t): ?>
        <?php
        $verdict = safe_json_decode((string) ($t['verdict'] ?? ''));
        $server = Config::server((string) $t['server_id']);
        ?>
        <tr>
          <td>
            <a class="mono strong" href="<?= e(\MCFix\ConsoleAuth::url('p=ticket&id=' . (int) $t['id'])) ?>"><?= e((string) $t['ticket_no']) ?></a>
            <div class="sub"><?= e(human_time((string) $t['created_at'])) ?></div>
          </td>
          <td>
            <div class="strong"><?= e((string) ($server['name'] ?? $t['server_id'])) ?></div>
            <div class="sub"><?= e((string) $t['player_name']) ?></div>
          </td>
          <td>
            <div><?= e(mb_substr((string) $t['subject'], 0, 40)) ?></div>
            <div class="sub"><?= e((string) ($verdict['title'] ?? '等待验证')) ?></div>
          </td>
          <td>
            <span class="sev"><?= $severityIcon((string) $t['severity']) ?> <?= e((string) ($verdict['issue'] ?? '—')) ?></span>
            <?php if ((int) $t['auto_fixable'] === 1): ?><span class="tag tag-fix">可自动修</span><?php endif; ?>
            <?php if ((int) $t['auto_fixed'] === 1): ?><span class="tag tag-ok">已自动修复</span><?php endif; ?>
            <?php if ((int) $t['fix_attempts'] > 0): ?><span class="tag">尝试 <?= (int) $t['fix_attempts'] ?> 次</span><?php endif; ?>
          </td>
          <td><span class="pill pill-<?= e(status_tone((string) $t['status'])) ?>"><?= e(status_label((string) $t['status'])) ?></span></td>
          <td class="sub"><?= e(human_time((string) $t['updated_at'])) ?></td>
          <td class="row-actions">
            <a class="btn btn-mini" href="<?= e(\MCFix\ConsoleAuth::url('p=ticket&id=' . (int) $t['id'])) ?>">处理</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_pager($page, $pages, static function (int $p) use ($queryString): string {
    return \MCFix\ConsoleAuth::url($queryString(['page' => $p]));
}); ?>
<?php endif; ?>
