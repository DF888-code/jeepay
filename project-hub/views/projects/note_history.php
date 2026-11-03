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
  每次修改都會保留修改前與修改後的完整內容；刪除時保留刪除當下的內容。紀錄僅供檢視，無法修改或刪除。
  此功能上線前的修改沒有保存內容。
  <?php if ($note !== null): ?><a href="/projects/<?= $pid ?>/notes/history">查看此專案的所有紀錄</a><?php endif; ?>
</p>
<?php if ($note !== null): ?>
  <p class="small mb-3">作者：<?= e($note['author_name']) ?>，建立於 <?= e(Clock::display($note['created_at'], $timezone, 'Y-m-d H:i')) ?><?= $note['deleted_at'] !== null ? '（已刪除）' : '' ?></p>
<?php endif; ?>

<?php if ($revisions === []): ?>
  <div class="card shadow-sm"><div class="card-body text-body-secondary" data-revision-count="0">目前沒有修改或刪除紀錄。</div></div>
<?php else: ?>
  <div class="vstack gap-3" data-revision-count="<?= count($revisions) ?>">
    <?php foreach ($revisions as $r): $deleted = $r['action'] === 'delete'; ?>
      <section class="card shadow-sm" data-revision="<?= e($r['action']) ?>">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2">
          <span class="fw-semibold">
            <?= $deleted ? '<span class="badge text-bg-danger me-1">刪除</span>' : '<span class="badge text-bg-secondary me-1">修改</span>' ?>
            <?php if ($note === null): ?><a href="/projects/<?= $pid ?>/notes/history?note=<?= (int)$r['note_id'] ?>">隨手記 #<?= (int)$r['note_id'] ?></a>（作者 <?= e($r['author_name']) ?>）<?php endif; ?>
          </span>
          <span class="small text-body-secondary"><?= e($r['changed_by_name']) ?> · <?= e(Clock::display($r['changed_at'], $timezone, 'Y-m-d H:i:s')) ?></span>
        </div>
        <div class="card-body">
          <?php if ($deleted): ?>
            <div class="small text-body-secondary mb-1">刪除前的內容</div>
            <div class="text-break text-pre-wrap small border rounded p-2 bg-body-tertiary"><?= e($r['content_before']) ?></div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-12 col-lg-6">
                <div class="small text-body-secondary mb-1">修改前</div>
                <div class="text-break text-pre-wrap small border rounded p-2 bg-body-tertiary"><?= e($r['content_before']) ?></div>
              </div>
              <div class="col-12 col-lg-6">
                <div class="small text-body-secondary mb-1">修改後</div>
                <div class="text-break text-pre-wrap small border rounded p-2"><?= e((string)$r['content_after']) ?></div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
  <?php if ($next !== null): ?>
    <div class="text-center mt-3"><a class="btn btn-outline-secondary" href="<?= e($base . 'before=' . (int)$next) ?>">更早的紀錄</a></div>
  <?php endif; ?>
<?php endif; ?>
