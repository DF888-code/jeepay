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
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="fw-semibold">專案說明</span>
    <span class="d-flex flex-wrap align-items-center gap-2">
      <span class="small text-body-secondary">版本 <?= (int)$project['description_version'] ?> · <?= e(Clock::display($project['updated_at'], $timezone)) ?></span>
      <a class="btn btn-sm btn-outline-secondary" href="/projects/<?= (int)$project['id'] ?>/history">版本歷史</a>
      <?php if ($can['edit']): ?>
        <a class="btn btn-sm btn-outline-primary" href="/projects/<?= (int)$project['id'] ?>/description/edit">編輯說明</a>
      <?php endif; ?>
    </span>
  </div>
  <div class="card-body">
    <div class="text-break text-pre-wrap"><?= e($project['description']) ?></div>
  </div>
</section>

<section class="card shadow-sm mb-4" id="keywords">
  <div class="card-header fw-semibold">專案關鍵字</div>
  <div class="card-body">
    <?php if ($keywords === []): ?>
      <p class="small text-body-secondary mb-2">尚未設定關鍵字。</p>
    <?php else: ?>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($keywords as $k): ?>
          <span class="badge rounded-pill text-bg-light border d-inline-flex align-items-center gap-1 fs-6 fw-normal">
            <?= e($k['keyword']) ?>
            <?php if ($can['edit']): ?>
              <form method="post" action="/projects/<?= (int)$project['id'] ?>/keywords/<?= (int)$k['id'] ?>/delete" class="d-inline m-0">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="btn-close btn-close-sm keyword-remove" aria-label="移除關鍵字 <?= e($k['keyword']) ?>"></button>
              </form>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($can['edit']): ?>
      <form method="post" action="/projects/<?= (int)$project['id'] ?>/keywords" class="row g-2">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="col-8 col-md-4">
          <label for="new-keyword" class="visually-hidden">新增關鍵字</label>
          <input id="new-keyword" name="keyword" class="form-control form-control-sm" maxlength="100" placeholder="新增關鍵字" required>
        </div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-sm btn-outline-primary w-100">新增</button></div>
      </form>
    <?php endif; ?>
  </div>
</section>

<section class="card shadow-sm" id="notes">
  <div class="card-header fw-semibold">隨手記</div>
  <div class="card-body">
    <?php if ($can['edit'] && !$olderPage): ?>
      <form method="post" action="/projects/<?= (int)$project['id'] ?>/notes" class="mb-4" data-uploader data-upload-url="/api/projects/<?= (int)$project['id'] ?>/attachments">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="new-note" class="visually-hidden">新增隨手記</label>
        <textarea id="new-note" name="content" class="form-control mb-2" rows="3" maxlength="20000" placeholder="快速記下任何資訊：錯誤與解法、技術筆記、提醒…（可直接把截圖拖進來）"></textarea>
        <?php require __DIR__ . '/_uploader.php'; ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
          <span class="small text-body-secondary">作者會自動記錄為您本人。若包含密碼等敏感資訊，請確認此專案的授權對象。</span>
          <button type="submit" class="btn btn-primary btn-sm flex-shrink-0 text-nowrap">新增隨手記</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($notes === []): ?>
      <p class="small text-body-secondary mb-0"><?= $olderPage ? '沒有更早的隨手記。' : '尚無隨手記。' ?></p>
    <?php else: ?>
      <div class="vstack gap-3">
        <?php foreach ($notes as $n): $nid = (int)$n['id']; ?>
          <article class="border rounded p-3" id="note-<?= $nid ?>">
            <header class="d-flex flex-wrap justify-content-between gap-2 small text-body-secondary mb-2">
              <span><strong class="text-body"><?= e($n['author_name']) ?></strong> · <?= e(Clock::display($n['created_at'], $timezone)) ?>
                <?php if ($n['updated_at'] !== null): ?>（<?= e($n['editor_name'] ?? '') ?> 於 <?= e(Clock::display($n['updated_at'], $timezone)) ?> 修改）<?php endif; ?>
              </span>
              <span class="d-flex gap-2">
                <?php if ($n['can_edit']): ?>
                  <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#note-edit-<?= $nid ?>">修改</button>
                <?php endif; ?>
                <?php if ($n['can_delete']): ?>
                  <button type="button" class="btn btn-sm btn-link link-danger p-0" data-bs-toggle="modal" data-bs-target="#note-del-<?= $nid ?>">刪除</button>
                <?php endif; ?>
              </span>
            </header>
            <?php if (trim((string)$n['content']) !== ''): ?><div class="text-break text-pre-wrap"><?= e($n['content']) ?></div><?php endif; ?>
            <?php $items = $attachments[$nid] ?? []; if ($items !== []) { require __DIR__ . '/_attachments.php'; } ?>
            <?php if ($n['can_edit']): ?>
              <form method="post" action="/projects/<?= (int)$project['id'] ?>/notes/<?= $nid ?>/update" class="collapse mt-3" id="note-edit-<?= $nid ?>" data-uploader data-upload-url="/api/projects/<?= (int)$project['id'] ?>/attachments">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="note-content-<?= $nid ?>" class="visually-hidden">修改隨手記</label>
                <textarea id="note-content-<?= $nid ?>" name="content" class="form-control mb-2" rows="4" maxlength="20000"><?= e($n['content']) ?></textarea>
                <?php require __DIR__ . '/_uploader.php'; ?>
                <button type="submit" class="btn btn-sm btn-primary">儲存修改</button>
              </form>
            <?php endif; ?>
            <?php if ($n['can_delete']): ?>
              <div class="modal fade" id="note-del-<?= $nid ?>" tabindex="-1" aria-labelledby="note-del-<?= $nid ?>-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <form method="post" action="/projects/<?= (int)$project['id'] ?>/notes/<?= $nid ?>/delete" class="modal-content">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <div class="modal-header"><h2 class="modal-title h5" id="note-del-<?= $nid ?>-title">刪除隨手記</h2>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button></div>
                    <div class="modal-body">確定要刪除 <?= e($n['author_name']) ?> 於 <?= e(Clock::display($n['created_at'], $timezone)) ?> 寫的隨手記嗎？</div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                      <button type="submit" class="btn btn-danger">刪除</button>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between mt-3">
      <?php if ($olderPage): ?><a class="btn btn-sm btn-outline-secondary" href="/projects/<?= (int)$project['id'] ?>#notes">回到最新</a><?php else: ?><span></span><?php endif; ?>
      <?php if ($nextBefore !== null): ?>
        <a class="btn btn-sm btn-outline-secondary" href="/projects/<?= (int)$project['id'] ?>?before=<?= (int)$nextBefore ?>#notes">載入更早的隨手記</a>
      <?php endif; ?>
    </div>
  </div>
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

<div class="modal fade" id="image-viewer" tabindex="-1" aria-labelledby="image-viewer-title" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h6 text-truncate" id="image-viewer-title">圖片</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
      </div>
      <div class="modal-body text-center p-2">
        <img src="" alt="" class="img-fluid viewer-image" data-viewer-image>
      </div>
      <div class="modal-footer py-1">
        <a href="#" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" data-viewer-open>在新分頁開啟原圖</a>
      </div>
    </div>
  </div>
</div>
