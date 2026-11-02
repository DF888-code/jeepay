<?php
use ProjectHub\Support\Clock;

$active = 'projects';
require __DIR__ . '/_nav.php';
require __DIR__ . '/_reauth.php';
?>
<?php if (!$searchIndex['current']): ?>
  <div class="alert alert-warning" data-search-rebuild>
    <div class="fw-semibold mb-1">搜尋索引需要重建</div>
    <p class="small mb-2">系統的搜尋規則已更新（加入繁簡與兩岸用語互搜），既有資料需要重新計算搜尋用的欄位，才能被新規則找到。重建只會更新搜尋用欄位，不會改動任何原文。</p>
    <form method="post" action="/admin/search-index/rebuild" class="d-flex flex-wrap align-items-center gap-2" data-rebuild-form>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-warning btn-sm"><?= $searchIndex['table'] === null ? '開始重建' : '繼續重建' ?></button>
      <span class="small" data-rebuild-status></span>
    </form>
  </div>
<?php elseif ($searchIndex['conflicts'] !== []): ?>
  <div class="alert alert-info small">
    <div class="fw-semibold">以下關鍵字在新規則下與同專案的其他關鍵字意思相同，系統未自動刪除，請自行決定是否移除：</div>
    <ul class="mb-0"><?php foreach ($searchIndex['conflicts'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<p class="small text-body-secondary">最高權限管理者可看到所有專案（含回收區）。點「授權名單」設定哪些員工可以查看、編輯、刪除該專案（第二層權限）。</p>

<?php if ($projects === []): ?>
  <div class="card shadow-sm"><div class="card-body text-body-secondary">目前還沒有專案，可到首頁「建立專案」。</div></div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="d-none d-lg-flex row g-0 px-3 py-2 small text-body-secondary border-bottom">
      <div class="col-lg-4">專案</div>
      <div class="col-lg-2">建立者</div>
      <div class="col-lg-2">可查看人數</div>
      <div class="col-lg-2">狀態</div>
      <div class="col-lg-2 text-end">操作</div>
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($projects as $p): $pid = (int)$p['id']; $deleted = $p['deleted_at'] !== null; ?>
        <li class="list-group-item py-3<?= $deleted ? ' bg-body-secondary' : '' ?>">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-lg-4 fw-semibold text-break">
              <?php if ($deleted): ?><?= e($p['name']) ?><?php else: ?><a href="/projects/<?= $pid ?>"><?= e($p['name']) ?></a><?php endif; ?>
            </div>
            <div class="col-6 col-lg-2 small"><span class="d-lg-none text-body-secondary">建立者：</span><?= e($p['creator_name']) ?></div>
            <div class="col-6 col-lg-2 small"><span class="d-lg-none text-body-secondary">可查看：</span><?= (int)$p['member_count'] ?> 人</div>
            <div class="col-12 col-lg-2 small">
              <?php if ($deleted): ?>
                <span class="badge text-bg-secondary">回收區</span> <?= e(Clock::display($p['deleted_at'], $timezone)) ?>
              <?php else: ?>
                <span class="badge text-bg-success">使用中</span>
              <?php endif; ?>
            </div>
            <div class="col-12 col-lg-2 text-lg-end">
              <?php if ($deleted): ?>
                <form method="post" action="/admin/projects/<?= $pid ?>/restore" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-success"<?= empty($elevated) ? ' disabled' : '' ?>>還原</button>
                </form>
              <?php else: ?>
                <a href="/admin/projects/<?= $pid ?>/permissions" class="btn btn-sm btn-outline-primary">授權名單</a>
              <?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
