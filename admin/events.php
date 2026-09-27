<?php
/**
 * 后台：操作日志（所有自动/人工动作的审计流水）。
 */

declare(strict_types=1);

use MCFix\Config;
use MCFix\Db;

$page = max(1, int_param($_GET, 'page', 1));
$perPage = 50;
$actor = param($_GET, 'actor');
$level = param($_GET, 'level');
$keyword = param($_GET, 'q', '', 60);

$where = [];
$params = [];
if ($actor !== '') {
    $where[] = 'actor = :actor';
    $params['actor'] = $actor;
}
if ($level !== '') {
    $where[] = 'level = :level';
    $params['level'] = $level;
}
if ($keyword !== '') {
    // LIKE 通配符要转义（V14）：不是注入（值有绑定），是语义问题 ——
    // 搜 `%` 等价于不筛选。ESCAPE 明确指定转义符。
    //
    // ★ 转义符刻意**不用反斜杠**：PHP 双引号串里写 ESCAPE '\\' 落到 SQL 就是
    // ESCAPE '\'。SQLite 认（它就是单个字符），MySQL 不认（'\' 是被反斜杠吃掉
    // 引号的半个字面量）→ 1064 语法错误，后台搜索页直接 500。'!' 两个引擎都认。
    $where[] = "(message LIKE :kw ESCAPE '!' OR action LIKE :kw ESCAPE '!')";
    $params['kw'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$total = (int) Db::scalar('SELECT COUNT(*) FROM events' . $whereSql, $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = Db::all(
    'SELECT * FROM events' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
    $params
);

$actorCounts = [];
foreach (Db::all('SELECT actor, COUNT(*) AS c FROM events GROUP BY actor ORDER BY c DESC LIMIT 8') as $row) {
    $actorCounts[(string) $row['actor']] = (int) $row['c'];
}

$queryString = static function (array $override = []) use ($actor, $level, $keyword): string {
    $base = array_filter(['actor' => $actor, 'level' => $level, 'q' => $keyword], static function ($v): bool {
        return $v !== '' && $v !== null;
    });

    return http_build_query(array_merge($base, $override));
};
?>
<header class="page-head">
  <div>
    <h1>操作日志</h1>
    <p class="hint">玩家提交、系统验证、自动修复、Agent 上报、管理员操作，全部记录在案（共 <?= (int) $total ?> 条）。</p>
  </div>
</header>

<div class="filter-bar">
  <div class="filter-chips">
    <a class="fchip <?= $actor === '' ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('p=events&' . $queryString(['actor' => '', 'page' => 1]))) ?>">全部</a>
    <?php foreach ($actorCounts as $key => $count): ?>
      <a class="fchip <?= $actor === $key ? 'is-active' : '' ?>" href="<?= e(\MCFix\ConsoleAuth::url('p=events&' . $queryString(['actor' => $key, 'page' => 1]))) ?>">
        <?= e($key) ?> <em><?= (int) $count ?></em>
      </a>
    <?php endforeach; ?>
  </div>
  <form class="filter-form" method="get" action="<?= e(url('')) ?>">
    <?php
    /*
     * 原来这里写死 <input name="r" value="admin.events">（F2）。
     * ?r=admin.events 走不到后台 —— index.php 对 $controller === 'admin' 一律
     * 返回 404，避免暴露后台位置。而 form 的 action 已经是后台真实地址了，
     * 这个字段只会把正确的 action 覆盖成会 404 的那个值。
     */
    ?>
    <select name="level" onchange="this.form.submit()">
      <option value="">全部级别</option>
      <?php foreach (['info' => '信息', 'warn' => '警告', 'error' => '错误'] as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $level === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($keyword) ?>" placeholder="搜索关键字">
    <button class="btn btn-ghost" type="submit">搜索</button>
  </form>
</div>

<div class="card table-card">
  <table class="table table-compact">
    <thead>
      <tr><th>时间</th><th>来源</th><th>动作</th><th>内容</th><th>工单</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr class="lv-<?= e((string) $row['level']) ?>">
          <td class="sub mono"><?= e(date('m-d H:i:s', ts((string) $row['created_at']))) ?></td>
          <td><span class="tag"><?= e((string) $row['actor']) ?></span></td>
          <td class="mono sub"><?= e((string) $row['action']) ?></td>
          <td><?= e((string) $row['message']) ?></td>
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
</div>

<?php render_pager($page, $pages, static function (int $p) use ($queryString): string {
    return \MCFix\ConsoleAuth::url('p=events&' . $queryString(['page' => $p]));
}); ?>
