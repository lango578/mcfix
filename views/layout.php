<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark">
<title><?= e($title ?? 'MC 服务器故障反馈中心') ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
<?php /* 图标只有一个出处：Brand::faviconTag()。
         没上传过时它返回内置的方块 emoji（inline SVG，零请求），
         上传过则返回真正的 <link rel="icon">。不要在这里再写一行硬编码的，
         否则会出现两个 <link rel="icon">，浏览器用哪个是不确定的。 */ ?>
<?= \MCFix\Brand::faviconTag() ?>
</head>
<body class="theme-dark<?= !empty($bodyClass) ? ' ' . e((string) $bodyClass) : '' ?>">
<div class="bg-grid" aria-hidden="true"></div>
<div class="bg-glow" aria-hidden="true"></div>

<header class="site-head">
  <div class="wrap head-inner">
    <a class="brand" href="<?= e(url('')) ?>">
      <?php /* 标题左边的图标和浏览器标签页图标是同一张：Brand::markTag()。
               没上传过它返回内置的 ⛏。 */ ?>
      <span class="brand-mark"><?= \MCFix\Brand::markTag() ?></span>
      <span class="brand-text">
        <strong><?= e($siteName ?? 'MC 故障反馈中心') ?></strong>
        <small>发现问题 · 自动验证 · 自动修复</small>
      </span>
    </a>
    <nav class="head-nav">
      <?php if (!empty($headNav)): ?>
        <?php foreach ($headNav as $item): ?>
          <a href="<?= e($item['href']) ?>"<?= !empty($item['active']) ? ' class="is-active"' : '' ?>><?= e($item['label']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="wrap site-main">
<?= $content ?? '' ?>
</main>

<footer class="site-foot">
  <div class="wrap foot-inner">
    <span>MC 故障反馈与自动修复系统 v<?= e(MCFIX_VERSION) ?></span>
    <span class="foot-sep">·</span>
    <span>工单链接 <?= (int) round(((int) \MCFix\Config::get('feedback.token_ttl', 86400)) / 3600) ?> 小时有效 · 所有自动操作都会留痕</span>
  </div>
</footer>

<script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
