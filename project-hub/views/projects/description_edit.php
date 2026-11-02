<?php $pid = (int)$project['id']; ?>
<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item"><a href="/">專案</a></li>
    <li class="breadcrumb-item"><a href="/projects/<?= $pid ?>"><?= e($project['name']) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">編輯說明</li>
  </ol>
</nav>
<h1 class="h4 mb-3">編輯專案說明</h1>

<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if (!empty($conflict)): ?>
  <?php // 版本衝突：不覆蓋、不自動合併；同時顯示使用者未儲存的文字與目前最新內容，由使用者自行決定 ?>
  <div class="row g-4" data-description-draft="<?= $pid ?>" data-conflict="1">
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-semibold">您尚未儲存的內容</div>
        <div class="card-body">
          <label for="my-draft" class="visually-hidden">您尚未儲存的內容</label>
          <textarea id="my-draft" class="form-control font-monospace" rows="14" readonly data-draft-source><?= e($draft) ?></textarea>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-copy-target="#my-draft">複製我的內容</button>
          <div class="form-text">這份內容也會暫存在此瀏覽器中，重新載入編輯頁後仍可取回。</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-semibold">目前最新內容（版本 <?= (int)$latestVersion ?>）</div>
        <div class="card-body">
          <div class="text-break text-pre-wrap small border rounded p-2 bg-body-tertiary"><?= e($latest) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <a class="btn btn-primary" href="/projects/<?= $pid ?>/description/edit">重新載入最新版本後編輯</a>
    <a class="btn btn-outline-secondary" href="/projects/<?= $pid ?>">回到專案</a>
  </div>
<?php else: ?>
  <div class="alert alert-warning d-none" data-draft-restore role="status">
    <div class="fw-semibold mb-1">您先前有尚未儲存的內容</div>
    <textarea class="form-control font-monospace small mb-2" rows="6" readonly data-draft-text aria-label="先前未儲存的內容"></textarea>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-draft-copy>複製</button>
    <button type="button" class="btn btn-sm btn-outline-danger" data-draft-discard>捨棄這份內容</button>
  </div>
  <form method="post" action="/projects/<?= $pid ?>/description" data-description-draft="<?= $pid ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="version" value="<?= (int)$version ?>">
    <label for="description" class="form-label">專案說明（目前版本 <?= (int)$version ?>）</label>
    <textarea id="description" name="description" class="form-control mb-2" rows="16" required data-draft-source><?= e($draft) ?></textarea>
    <div class="form-text mb-3">儲存後會保留完整的修改前、修改後內容於版本歷史。若其他人在您編輯期間已先儲存，系統不會覆蓋對方的內容。</div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">儲存</button>
      <a class="btn btn-outline-secondary" href="/projects/<?= $pid ?>">取消</a>
    </div>
  </form>
<?php endif; ?>
