<?php
use ProjectHub\Support\Clock;

$active = 'errors';
require __DIR__ . '/_nav.php';
?>
<p class="small text-body-secondary">使用者看到「系統發生錯誤」時，畫面上會有一組追蹤代碼。輸入代碼可查詢詳細原因；回報問題時請一併提供這裡的內容。紀錄只保存最近 3 個月，不含網址參數、表單內容與 Cookie。</p>
<form method="get" action="/admin/errors" class="row g-2 align-items-end mb-3">
  <div class="col-8 col-md-4">
    <label for="err-id" class="form-label small mb-1">追蹤代碼</label>
    <input id="err-id" name="id" class="form-control" value="<?= e($traceId) ?>" maxlength="12" placeholder="例如 5d80b170d638" autocomplete="off">
  </div>
  <div class="col-4 col-md-2"><button type="submit" class="btn btn-primary w-100">查詢</button></div>
  <?php if ($traceId !== ''): ?><div class="col-12 col-md-auto"><a href="/admin/errors" class="small">顯示全部</a></div><?php endif; ?>
</form>
<?php if ($invalidId): ?><div class="alert alert-warning small">追蹤代碼是 12 個英數字（0–9、a–f）。</div><?php endif; ?>

<?php if ($errors === []): ?>
  <div class="card shadow-sm"><div class="card-body text-body-secondary" data-error-count="0">
    <?= $traceId !== '' ? '找不到此追蹤代碼的紀錄（可能發生在此功能上線前，或已超過 3 個月）。' : '目前沒有錯誤紀錄。' ?>
  </div></div>
<?php else: ?>
  <div class="list-group shadow-sm" data-error-count="<?= count($errors) ?>">
    <?php foreach ($errors as $err): ?>
      <div class="list-group-item py-3">
        <div class="d-flex flex-wrap justify-content-between gap-2 small text-body-secondary">
          <span><?= e(Clock::display((string)($err['time'] ?? ''), $timezone, 'Y-m-d H:i:s')) ?> · <code><?= e((string)($err['id'] ?? '')) ?></code></span>
          <span><?= e((string)($err['method'] ?? '')) ?> <?= e((string)($err['path'] ?? '')) ?> · PHP <?= e((string)($err['php'] ?? '')) ?></span>
        </div>
        <div class="fw-semibold mt-1 text-break"><?= e((string)($err['type'] ?? '')) ?>：<?= e((string)($err['message'] ?? '')) ?></div>
        <div class="small text-break"><code><?= e((string)($err['at'] ?? '')) ?></code></div>
        <?php if (!empty($err['trace'])): ?>
          <details class="small mt-1">
            <summary>呼叫堆疊</summary>
            <pre class="small bg-body-tertiary border rounded p-2 mt-1 mb-0 mail-transcript"><?= e(implode("\n", (array)$err['trace'])) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
