<?php
/**
 * 顯示一則隨手記的附件：圖片直接顯示在文字下方（依寬度縮放、點擊放大），一般檔案列出名稱、格式、大小與下載。
 * 所有網址都只含附件 ID，每次請求都由伺服器重新檢查雙重授權。
 *
 * @var list<array<string, mixed>> $items
 * @var array<string, mixed> $n 隨手記
 */
use ProjectHub\Attachment\UploadInspector;
use ProjectHub\Controllers\AttachmentController;

$images = array_values(array_filter($items, static fn(array $a): bool => $a['kind'] === 'image'));
$files = array_values(array_filter($items, static fn(array $a): bool => $a['kind'] !== 'image'));
?>
<?php if ($images !== []): ?>
  <div class="row g-2 mt-2">
    <?php foreach ($images as $a): $aid = (int)$a['id']; ?>
      <div class="col-6 col-md-4 col-lg-3">
        <figure class="mb-0 position-relative">
          <a href="/attachments/<?= $aid ?>/original" target="_blank" rel="noopener" data-image-viewer data-full="/attachments/<?= $aid ?>/original" data-name="<?= e($a['original_name']) ?>">
            <img src="/attachments/<?= $aid ?>/preview" class="img-fluid rounded border note-image" loading="lazy" alt="<?= e($a['original_name']) ?>">
          </a>
          <?php if ($n['can_delete']): ?>
            <form method="post" action="/projects/<?= (int)$project['id'] ?>/attachments/<?= $aid ?>/delete" class="position-absolute top-0 end-0 m-1" data-confirm="確定要刪除圖片「<?= e($a['original_name']) ?>」嗎？">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <button type="submit" class="btn btn-sm btn-light border py-0 px-1 image-remove" aria-label="刪除圖片 <?= e($a['original_name']) ?>">×</button>
            </form>
          <?php endif; ?>
        </figure>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($files !== []): ?>
  <ul class="list-group mt-2">
    <?php foreach ($files as $a): $aid = (int)$a['id']; $ext = strtoupper(pathinfo((string)$a['original_name'], PATHINFO_EXTENSION)); ?>
      <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
        <span class="badge text-bg-secondary file-ext"><?= e($ext) ?></span>
        <span class="flex-grow-1 text-break">
          <span class="d-block"><?= e($a['original_name']) ?></span>
          <span class="small text-body-secondary"><?= e(UploadInspector::label((string)$a['mime_type'])) ?> · <?= e(AttachmentController::humanSize((int)$a['size_bytes'])) ?></span>
        </span>
        <a class="btn btn-sm btn-outline-primary" href="/attachments/<?= $aid ?>/download">下載</a>
        <?php if ($n['can_delete']): ?>
          <form method="post" action="/projects/<?= (int)$project['id'] ?>/attachments/<?= $aid ?>/delete" class="m-0" data-confirm="確定要刪除附件「<?= e($a['original_name']) ?>」嗎？">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">刪除</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
