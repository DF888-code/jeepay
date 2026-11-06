<?php
/**
 * 「使用者 IP 來源」診斷：顯示這個連線實際收到的資訊與判斷結果（依已儲存的設定）。
 * 切換設定並儲存後，重新整理本頁即可確認結果是否為您自己的 IP。
 */
$d = $ipDiagnostic;
?>
<div class="card bg-body-tertiary mt-2" data-ip-diagnostic>
  <div class="card-body p-2 small">
    <div class="fw-semibold mb-1">目前連線診斷（依已儲存的設定）</div>
    <dl class="row mb-1">
      <dt class="col-5 fw-normal text-body-secondary">連線來源（REMOTE_ADDR）</dt><dd class="col-7 mb-1"><code><?= e((string)$d['remote']) ?></code></dd>
      <dt class="col-5 fw-normal text-body-secondary">來源是否為信任的代理</dt><dd class="col-7 mb-1" data-ip-trusted="<?= $d['trusted'] ? '1' : '0' ?>"><?= $d['mode'] === 'direct' ? '（直接連線模式，不判斷）' : ($d['trusted'] ? '是' : '否（因此不採用代理標頭）') ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">系統判斷的使用者 IP</dt><dd class="col-7 mb-1"><code data-ip-resolved><?= e((string)$d['ip']) ?></code></dd>
    </dl>
    <div class="text-body-secondary mb-1">收到的代理標頭：</div>
    <ul class="mb-1 ps-3">
      <?php foreach ($d['headers'] as $name => $v): ?>
        <li><code><?= e($name) ?></code>：<?= $v === null ? '<span class="text-body-secondary">（沒有）</span>' : '<code>' . e($v) . '</code>' ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="text-body-secondary">「系統判斷的使用者 IP」應該是您自己的對外 IP（可用 Google 搜尋「我的 IP」比對）。若顯示的是代理的 IP，代表設定不正確。</div>
  </div>
</div>
