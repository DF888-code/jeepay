<?php
/** @var string $content */
$siteName = $siteName ?? 'Project Hub';
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $siteName : $siteName;
$stage = $authStage ?? 'none';
?><!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand" href="/"><?= e($siteName) ?></a>
    <?php if ($stage === 'full' && !empty($currentUser)): ?>
      <nav class="nav" aria-label="主選單">
        <a href="/">專案</a>
        <a href="/account">帳號設定</a>
        <span class="nav-user"><?= e($currentUser['display_name']) ?></span>
        <form method="post" action="/logout" class="inline">
          <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
          <button type="submit" class="btn-link">登出</button>
        </form>
      </nav>
    <?php elseif ($stage === 'totp' || $stage === 'enroll'): ?>
      <nav class="nav">
        <form method="post" action="/logout" class="inline">
          <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
          <button type="submit" class="btn-link">取消登入</button>
        </form>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main class="container">
  <?php foreach (($flash ?? []) as $item): ?>
    <div class="alert alert-<?= e($item['type']) ?>" role="status"><?= e($item['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
