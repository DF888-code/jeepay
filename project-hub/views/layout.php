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
<?php
// 換頁不重新載入整頁（Hotwire Turbo）：
// - 共用的 CSS／JS 放在 head 只載入一次；網址帶版本號，部署新版時 data-turbo-track 會讓瀏覽器整頁重新載入；
// - turbo-prefetch=false：不在滑鼠移過連結時預先載入，避免使用者沒點的專案也被記錄成「進入專案」；
// - turbo-cache-control=no-cache：不保留頁面快照，避免「臨時密碼」等只顯示一次的資料在上一頁時重現；
// - csp-nonce：Turbo 的讀取進度條需要一段 <style>，以每次請求不同的 nonce 放行，CSP 仍不允許 unsafe-inline。
$assetVersion = rawurlencode((string)($appVersion ?? ''));
?>
<meta name="turbo-prefetch" content="false">
<meta name="turbo-cache-control" content="no-cache">
<?php if (!empty($cspNonce)): ?><meta name="csp-nonce" content="<?= e($cspNonce) ?>"><?php endif; ?>
<script src="/assets/theme.js?v=<?= $assetVersion ?>" data-turbo-track="reload"></script>
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.8/bootstrap.min.css" data-turbo-track="reload">
<link rel="stylesheet" href="/assets/app.css?v=<?= $assetVersion ?>" data-turbo-track="reload">
<script src="/assets/vendor/bootstrap-5.3.8/bootstrap.bundle.min.js" data-turbo-track="reload"></script>
<script src="/assets/app.js?v=<?= $assetVersion ?>" data-turbo-track="reload"></script>
<script src="/assets/vendor/turbo-8.0.23/turbo.js" data-turbo-track="reload"></script>
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
        <form method="get" action="/search" class="d-flex my-2 my-md-0 me-md-3" role="search">
          <label for="nav-search" class="visually-hidden">全站搜尋</label>
          <input id="nav-search" type="search" name="q" class="form-control form-control-sm" placeholder="搜尋（繁簡皆可）" maxlength="100">
        </form>
        <div class="d-flex align-items-center gap-3">
          <span class="navbar-text small"><?= e($currentUser['display_name']) ?></span>
          <form method="post" action="/logout" class="m-0" data-turbo="false">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">登出</button>
          </form>
        </div>
      </div>
    <?php elseif (in_array($stage, ['totp', 'enroll', 'password_change'], true)): ?>
      <form method="post" action="/logout" class="m-0" data-turbo="false">
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
<?php // 各頁專用的程式放在 body：Turbo 每次換到該頁都會重新執行，負責初始化該頁的元素 ?>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e($script) ?>?v=<?= $assetVersion ?>"></script>
<?php endforeach; ?>
</body>
</html>
