<?php
use ProjectHub\Support\Clock;

$active = 'projects';
require __DIR__ . '/_nav.php';
require __DIR__ . '/_reauth.php';
?>
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
