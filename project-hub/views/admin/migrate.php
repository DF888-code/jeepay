<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">資料庫更新</h1>
    <?php if ($pending === []): ?>
      <p class="mb-3">資料庫已是最新版本。</p>
      <a class="btn btn-outline-primary" href="/admin/users">前往管理後台</a>
    <?php else: ?>
      <p>系統程式已更新，需要先套用下列資料庫更新，管理後台才能使用：</p>
      <ul>
        <?php foreach ($pending as $version): ?><li><code><?= e($version) ?></code></li><?php endforeach; ?>
      </ul>
      <p class="small text-body-secondary">更新只會新增資料表或欄位，不會刪除或修改既有資料。建議先用 phpMyAdmin 匯出資料庫備份。</p>
      <form method="post" action="/admin/migrate">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-primary w-100">套用資料庫更新</button>
      </form>
    <?php endif; ?>
  </div>
</div>
