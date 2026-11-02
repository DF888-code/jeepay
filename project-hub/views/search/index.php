<?php
use ProjectHub\Support\Clock;

/** 將 [前段, 符合段, 後段] 輸出為已跳脫的 HTML，符合段以 <mark> 標示。 */
$snippet = static fn(array $s): string => e($s[0]) . ($s[1] !== '' ? '<mark>' . e($s[1]) . '</mark>' : '') . e($s[2]);
?>
<h1 class="h3 mb-3">搜尋</h1>
<form method="get" action="/search" class="row g-2 mb-3" role="search">
  <div class="col-12 col-md-8 col-lg-6">
    <label for="search-q" class="visually-hidden">搜尋專案名稱、說明、關鍵字與隨手記</label>
    <input id="search-q" type="search" name="q" class="form-control" value="<?= e($query) ?>" maxlength="100" placeholder="搜尋專案名稱、說明、關鍵字、隨手記（繁簡皆可）" autofocus>
  </div>
  <div class="col-12 col-md-auto"><button type="submit" class="btn btn-primary w-100">搜尋</button></div>
</form>
<p class="small text-body-secondary">支援繁體、簡體與兩岸用語互搜（例如「資料庫」也能找到「数据库」）。只會搜尋您有權查看的專案。</p>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php elseif ($result !== null): ?>
  <p class="mb-3">找到 <strong><?= (int)$result['total'] ?></strong> 個專案</p>
  <?php if ($result['items'] === []): ?>
    <div class="card shadow-sm"><div class="card-body text-body-secondary">沒有符合「<?= e($query) ?>」的專案。</div></div>
  <?php else: ?>
    <div class="vstack gap-3">
      <?php foreach ($result['items'] as $item): $pid = (int)$item['id']; ?>
        <article class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
              <h2 class="h5 mb-0 text-break"><a href="/projects/<?= $pid ?>"><?= e($item['name']) ?></a></h2>
              <span class="small text-body-secondary"><?= e(Clock::display($item['updated_at'], $timezone)) ?></span>
            </div>
            <div class="d-flex flex-wrap gap-1 mb-2 small">
              <?php if ($item['matched']['name']): ?><span class="badge text-bg-primary">名稱</span><?php endif; ?>
              <?php if ($item['matched']['description']): ?><span class="badge text-bg-info">說明</span><?php endif; ?>
              <?php if ($item['keywords'] !== []): ?><span class="badge text-bg-success">關鍵字</span><?php endif; ?>
              <?php if ($item['notes']['count'] > 0): ?><span class="badge text-bg-warning">隨手記 <?= (int)$item['notes']['count'] ?> 則</span><?php endif; ?>
            </div>
            <?php if ($item['description_snippet'] !== null): ?>
              <p class="small mb-2 text-break"><?= $snippet($item['description_snippet']) ?></p>
            <?php endif; ?>
            <?php if ($item['keywords'] !== []): ?>
              <div class="d-flex flex-wrap gap-1 mb-2">
                <?php foreach ($item['keywords'] as $k): ?><span class="badge rounded-pill text-bg-light border fw-normal"><?= e($k) ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php foreach ($item['notes']['hints'] as $h): ?>
              <a class="d-block small border-start border-3 border-warning ps-2 mb-1 text-body text-decoration-none text-break" href="/projects/<?= $pid ?>#note-<?= (int)$h['id'] ?>">
                <span class="text-body-secondary"><?= e($h['author']) ?> · <?= e(Clock::display($h['created_at'], $timezone)) ?>：</span><?= $snippet($h['snippet']) ?>
              </a>
            <?php endforeach; ?>
            <?php if ($item['notes']['count'] > count($item['notes']['hints'])): ?>
              <div class="small text-body-secondary">另有 <?= (int)$item['notes']['count'] - count($item['notes']['hints']) ?> 則符合的隨手記</div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (($pages ?? 1) > 1): ?>
      <nav class="mt-3" aria-label="分頁">
        <ul class="pagination justify-content-center flex-wrap">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="/search?q=<?= e(rawurlencode($query)) ?>&amp;page=<?= $i ?>"><?= $i ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
