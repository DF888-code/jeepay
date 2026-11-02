<?php use ProjectHub\Support\Clock; ?>
<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item"><a href="/">專案</a></li>
    <li class="breadcrumb-item active text-truncate" aria-current="page"><?= e($project['name']) ?></li>
  </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
  <h1 class="h3 mb-0 text-break"><?= e($project['name']) ?></h1>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($can['edit']): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rename-form">改名</button>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/admin/projects/<?= (int)$project['id'] ?>/permissions">授權名單</a>
    <?php endif; ?>
    <?php if ($can['delete']): ?>
      <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-modal">刪除</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($can['edit']): ?>
  <div class="collapse mb-4" id="rename-form">
    <form method="post" action="/projects/<?= (int)$project['id'] ?>/rename" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="col-12 col-md-8">
        <label for="rename-name" class="visually-hidden">新的專案名稱</label>
        <input id="rename-name" name="name" class="form-control" maxlength="200" value="<?= e($project['name']) ?>" required>
      </div>
      <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-primary w-100">儲存名稱</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<section class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex justify-content-between">
    <span>專案說明</span>
    <span class="small text-body-secondary fw-normal">版本 <?= (int)$project['description_version'] ?> · <?= e(Clock::display($project['updated_at'], $timezone)) ?></span>
  </div>
  <div class="card-body">
    <div class="text-break text-pre-wrap"><?= e($project['description']) ?></div>
  </div>
</section>

<section class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">專案關鍵字</div>
  <div class="card-body small text-body-secondary">關鍵字功能將於下一個版本（階段 6）開放。</div>
</section>

<section class="card shadow-sm">
  <div class="card-header fw-semibold">隨手記</div>
  <div class="card-body small text-body-secondary">隨手記（含圖片與附件）將於下一個版本（階段 6）開放；說明編輯與版本歷史也會一併提供。</div>
</section>

<?php if ($can['delete']): ?>
  <div class="modal fade" id="delete-modal" tabindex="-1" aria-labelledby="delete-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" action="/projects/<?= (int)$project['id'] ?>/delete" class="modal-content">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="modal-header">
          <h2 class="modal-title h5" id="delete-title">刪除專案</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
        </div>
        <div class="modal-body">
          <p>刪除後所有人都看不到這個專案。資料會保留在回收區，需要時可由最高權限管理者還原。</p>
          <label for="confirm-name" class="form-label">請輸入專案名稱「<?= e($project['name']) ?>」以確認：</label>
          <input id="confirm-name" name="confirm_name" class="form-control" required autocomplete="off">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-danger">刪除</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
