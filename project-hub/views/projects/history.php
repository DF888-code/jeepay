<?php use ProjectHub\Support\Clock; $pid = (int)$project['id']; ?>
<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item"><a href="/">專案</a></li>
    <li class="breadcrumb-item text-truncate"><a href="/projects/<?= $pid ?>"><?= e($project['name']) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">版本歷史</li>
  </ol>
</nav>
<h1 class="h4 mb-2">專案說明版本歷史</h1>
<p class="small text-body-secondary">每次儲存都會保留修改前與修改後的完整內容。歷史紀錄僅供檢視，無法修改或刪除。</p>

<div class="vstack gap-3">
  <?php foreach ($history as $h): $v = (int)$h['version_after']; ?>
    <section class="card shadow-sm">
      <div class="card-header d-flex flex-wrap justify-content-between gap-2">
        <span class="fw-semibold">版本 <?= $v ?><?= $h['version_before'] === null ? '（建立專案）' : '' ?></span>
        <span class="small text-body-secondary"><?= e($h['changed_by_name']) ?> · <?= e(Clock::display($h['changed_at'], $timezone, 'Y-m-d H:i:s')) ?></span>
      </div>
      <div class="card-body">
        <?php if ($h['version_before'] !== null): ?>
          <div class="row g-3">
            <div class="col-12 col-lg-6">
              <div class="small text-body-secondary mb-1">修改前（版本 <?= (int)$h['version_before'] ?>）</div>
              <div class="text-break text-pre-wrap small border rounded p-2 bg-body-tertiary"><?= e($h['content_before']) ?></div>
            </div>
            <div class="col-12 col-lg-6">
              <div class="small text-body-secondary mb-1">修改後（版本 <?= $v ?>）</div>
              <div class="text-break text-pre-wrap small border rounded p-2"><?= e($h['content_after']) ?></div>
            </div>
          </div>
        <?php else: ?>
          <div class="text-break text-pre-wrap small border rounded p-2"><?= e($h['content_after']) ?></div>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<a class="btn btn-outline-secondary mt-3" href="/projects/<?= $pid ?>">回到專案</a>
