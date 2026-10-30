<div class="card narrow">
  <h1>尚未完成設定</h1>
  <p>請以 File Manager 編輯 <code>config/config.php</code>（可複製 <code>config/config.example.php</code> 後修改）。目前的問題：</p>
  <ul>
    <?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
  </ul>
  <h2>可直接使用的隨機值</h2>
  <p class="small muted">以下數值每次重新整理都會不同，本系統不會保存它們。請各複製一組貼到設定檔中。</p>
  <label>app_key
    <input readonly value="<?= e($sampleAppKey) ?>" class="mono">
  </label>
  <label>install_token
    <input readonly value="<?= e($sampleInstallToken) ?>" class="mono">
  </label>
  <p class="small muted">app_key 用來加密 2FA 金鑰，設定後請勿更改或遺失，否則所有使用者都必須重新綁定 2FA。</p>
</div>
