<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">尚未完成設定</h1>
    <p>請以 File Manager 編輯 <code>config/config.php</code>（可複製 <code>config/config.example.php</code> 後修改）。目前的問題：</p>
    <ul>
      <?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
    </ul>
    <h2 class="h6 mt-4">可直接使用的隨機值</h2>
    <p class="small text-body-secondary">以下數值每次重新整理都會不同，本系統不會保存它們。請各複製一組貼到設定檔中。</p>
    <div class="mb-3">
      <label for="sample-app-key" class="form-label">app_key</label>
      <input id="sample-app-key" readonly value="<?= e($sampleAppKey) ?>" class="form-control font-monospace">
    </div>
    <div class="mb-3">
      <label for="sample-install-token" class="form-label">install_token</label>
      <input id="sample-install-token" readonly value="<?= e($sampleInstallToken) ?>" class="form-control font-monospace">
    </div>
    <div class="alert alert-warning small mb-0">app_key 用來加密 2FA 金鑰，設定後請勿更改或遺失，否則所有使用者都必須重新綁定 2FA。</div>
  </div>
</div>
