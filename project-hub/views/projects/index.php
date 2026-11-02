<?php use ProjectHub\Support\Clock; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h1 class="h3 mb-0">專案</h1>
  <?php if ($canCreate): ?>
    <a href="/projects/new" class="btn btn-primary">建立專案</a>
  <?php endif; ?>
</div>
<p class="small text-body-secondary">只會列出您有權查看的專案。全站搜尋（含簡繁互搜）將於後續版本加入。</p>

<?php if ($projects === []): ?>
  <div class="card shadow-sm">
    <div class="card-body text-body-secondary">
      目前沒有可查看的專案。<?= $canCreate ? '可以按右上角「建立專案」新增。' : '若需要存取專案，請聯絡最高權限管理者授權。' ?>
    </div>
  </div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="d-none d-md-flex row g-0 px-3 py-2 small text-body-secondary border-bottom">
      <div class="col-md-4">專案名稱</div>
      <div class="col-md-5">說明</div>
      <div class="col-md-3 text-end">最後更新</div>
    </div>
    <div class="list-group list-group-flush">
      <?php foreach ($projects as $p): ?>
        <a href="/projects/<?= (int)$p['id'] ?>" class="list-group-item list-group-item-action py-3">
          <div class="row g-1 align-items-center">
            <div class="col-12 col-md-4 fw-semibold text-break"><?= e($p['name']) ?></div>
            <div class="col-12 col-md-5 small text-body-secondary text-truncate"><?= e(mb_substr((string)$p['description'], 0, 120)) ?></div>
            <div class="col-12 col-md-3 small text-body-secondary text-md-end"><?= e(Clock::display($p['updated_at'], $timezone)) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="分頁">
      <ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="/?page=<?= $i ?>"><?= $i ?></a></li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>
