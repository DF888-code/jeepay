<?php
use ProjectHub\Support\Clock;

$pid = (int)$project['id'];
$base = '/projects/' . $pid . '/notes/history' . ($note !== null ? '?note=' . (int)$note['id'] . '&' : '?');
?>
<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item"><a href="/">專案</a></li>
    <li class="breadcrumb-item text-truncate"><a href="/projects/<?= $pid ?>"><?= e($project['name']) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">隨手記修改紀錄</li>
  </ol>
</nav>
<h1 class="h4 mb-2"><?= $note !== null ? '隨手記 #' . (int)$note['id'] . ' 的修改紀錄' : '隨手記修改與刪除紀錄' ?></h1>
<p class="small text-body-secondary">
  每次修改都會保留修改前與修改後的完整內容；刪除時保留刪除當下的內容。紀錄僅供檢視，無法修改或刪除；只有作者本人與最高權限管理者看得到。
  此功能上線前的修改沒有保存內容。
  <?php if ($note !== null): ?><a href="/projects/<?= $pid ?>/notes/history">查看此專案的所有紀錄</a><?php endif; ?>
</p>
<?php if ($note !== null): ?>
  <p class="small mb-3">作者：<?= e($note['author_name']) ?>，建立於 <?= e(Clock::display($note['created_at'], $timezone, 'Y-m-d H:i')) ?><?= $note['deleted_at'] !== null ? '（已刪除）' : '' ?></p>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <p class="small text-body-secondary">只顯示您撰寫的隨手記的紀錄。</p>
<?php endif; ?>
<?php if ($revisions === []): ?>
  <div class="card shadow-sm"><div class="card-body text-body-secondary" data-revision-count="0">目前沒有修改或刪除紀錄。</div></div>
<?php else: ?>
  <?php // 一列一筆：修改前｜修改後｜時間／修改者；手機寬度不足時改為上下排列，並在每格上方顯示欄位名稱 ?>
  <div class="card shadow-sm" data-revision-count="<?= count($revisions) ?>">
    <div class="d-none d-lg-flex row g-0 px-3 py-2 small text-body-secondary border-bottom fw-semibold">
      <div class="col-lg-5 pe-3">修改前</div>
      <div class="col-lg-5 pe-3">修改後</div>
      <div class="col-lg-2">時間／修改者</div>
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($revisions as $r): $deleted = $r['action'] === 'delete'; ?>
        <li class="list-group-item py-3" data-revision="<?= e($r['action']) ?>">
          <div class="row g-2">
            <div class="col-12 col-lg-5 pe-lg-3">
              <div class="small text-body-secondary mb-1 d-lg-none">修改前</div>
              <div class="text-break text-pre-wrap small border rounded p-2 bg-body-tertiary rev-box"><?= e($r['content_before']) ?></div>
            </div>
            <div class="col-12 col-lg-5 pe-lg-3">
              <div class="small text-body-secondary mb-1 d-lg-none">修改後</div>
              <?php if ($deleted): ?>
                <div class="small border border-danger-subtle rounded p-2 text-danger rev-box">（已刪除）</div>
              <?php else: ?>
                <div class="text-break text-pre-wrap small border rounded p-2 rev-box"><?= e((string)$r['content_after']) ?></div>
              <?php endif; ?>
            </div>
            <div class="col-12 col-lg-2 small">
              <div class="mb-1"><?= $deleted ? '<span class="badge text-bg-danger">刪除</span>' : '<span class="badge text-bg-secondary">修改</span>' ?></div>
              <div><?= e(Clock::display($r['changed_at'], $timezone, 'Y-m-d H:i:s')) ?></div>
              <div class="text-body-secondary">修改者：<?= e($r['changed_by_name']) ?></div>
              <?php if ($note === null): ?>
                <div class="mt-1"><a href="/projects/<?= $pid ?>/notes/history?note=<?= (int)$r['note_id'] ?>">隨手記 #<?= (int)$r['note_id'] ?></a><span class="text-body-secondary">（作者 <?= e($r['author_name']) ?>）</span></div>
              <?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php if ($next !== null): ?>
    <div class="text-center mt-3"><a class="btn btn-outline-secondary" href="<?= e($base . 'before=' . (int)$next) ?>">更早的紀錄</a></div>
  <?php endif; ?>
<?php endif; ?>
