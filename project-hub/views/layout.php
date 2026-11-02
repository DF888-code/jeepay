<?php
/** @var string $content */
$siteName = $siteName ?? '專案管理平台';
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $siteName : $siteName;
$stage = $authStage ?? 'none';
// 樣板的訊息類型對應 Bootstrap 的 alert 顏色
$alertClass = ['success' => 'success', 'warning' => 'warning', 'error' => 'danger'];
?><!doctype html>
<html lang="zh-Hant" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?></title>
<script src="/assets/theme.js"></script>
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.8/bootstrap.min.css">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-body-tertiary">
<nav class="navbar navbar-expand-md bg-body border-bottom">
  <div class="container">
    <a class="navbar-brand fw-semibold" href="/"><?= e($siteName) ?></a>
    <?php if ($stage === 'full' && !empty($currentUser)): ?>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="切換選單">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav me-auto">
          <li class="nav-item"><a class="nav-link" href="/">專案</a></li>
          <li class="nav-item"><a class="nav-link" href="/account">帳號設定</a></li>
          <?php if ((int)($currentUser['is_super_admin'] ?? 0) === 1): ?>
            <li class="nav-item"><a class="nav-link" href="/admin">管理後台</a></li>
          <?php endif; ?>
        </ul>
        <div class="d-flex align-items-center gap-3">
          <span class="navbar-text small"><?= e($currentUser['display_name']) ?></span>
          <form method="post" action="/logout" class="m-0">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">登出</button>
          </form>
        </div>
      </div>
    <?php elseif (in_array($stage, ['totp', 'enroll', 'password_change'], true)): ?>
      <form method="post" action="/logout" class="m-0">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm">取消登入</button>
      </form>
    <?php endif; ?>
  </div>
</nav>
<main class="container py-4">
  <?php foreach (($flash ?? []) as $item): ?>
    <div class="alert alert-<?= e($alertClass[$item['type']] ?? 'info') ?>" role="status"><?= e($item['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<?php if (!empty($appVersion) && $stage === 'full'): ?>
<footer class="container pb-4 small text-body-secondary">版本 v<?= e($appVersion) ?></footer>
<?php endif; ?>
<script src="/assets/vendor/bootstrap-5.3.8/bootstrap.bundle.min.js"></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
