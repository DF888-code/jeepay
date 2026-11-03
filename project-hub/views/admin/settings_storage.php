<?php
use ProjectHub\Controllers\AttachmentController;
use ProjectHub\Support\Clock;

$settingsTab = 'storage';
require __DIR__ . '/_settings_nav.php';
if (empty($elevated)) { return; } // 未完成身分驗證：只顯示驗證框，不輸出設定內容
$disabledAttr = empty($elevated) ? ' disabled' : '';
$connected = $connection !== null;
$labels = ['local' => '本機（InfinityFree）', 'google_drive' => 'Google Drive', 'r2' => 'Cloudflare R2'];
?>
<?php if (!$curl): ?>
  <div class="alert alert-danger">主機未啟用 cURL，無法使用 Google Drive。</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ($labels as $driver => $label): $u = $usage[$driver]; $pct = $quota[$driver] > 0 ? min(100, (int)round($u['bytes'] / ($quota[$driver] * 1048576) * 100)) : 0; ?>
    <div class="col-12 col-md-4">
      <div class="card shadow-sm h-100" data-usage="<?= e($driver) ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-baseline">
            <h2 class="h6 mb-1"><?= e($label) ?></h2>
            <?php if ($values['attachments.storage'] === $driver): ?><span class="badge text-bg-primary">新附件存這裡</span><?php endif; ?>
          </div>
          <div class="small text-body-secondary mb-2"><?= (int)$u['files'] ?> 個檔案，<?= e(AttachmentController::humanSize($u['bytes'])) ?> ／ 上限 <?= e(number_format($quota[$driver])) ?> MB</div>
          <div class="progress" role="progressbar" aria-label="<?= e($label) ?>使用量" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar progress-w-<?= (int)(round($pct / 5) * 5) ?><?= $pct >= 90 ? ' bg-danger' : '' ?>"></div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<p class="small text-body-secondary">可使用三種儲存位置：本機、Google Drive、Cloudflare R2。設定完成後，在本頁最下方的「新上傳附件的儲存位置」選擇要用哪一個；已上傳的檔案留在原處，不需搬移。</p>

<section class="card shadow-sm mb-3" aria-labelledby="r2-title" data-r2-settings>
  <div class="card-body">
    <h2 class="h5" id="r2-title">Cloudflare R2</h2>
    <div class="alert <?= $r2Configured ? 'alert-success' : 'alert-secondary' ?> small" data-r2-status="<?= $r2Configured ? 'configured' : 'missing' ?>">
      <?= $r2Configured ? '已填寫 R2 設定。若尚未測試，請按「測試連線」確認。' : '尚未設定。' ?>
      免費額度：每月 10 GB 儲存空間、下載流量免費。
    </div>
    <details class="mb-3"<?= $r2Configured ? '' : ' open' ?>>
      <summary>設定步驟（已申請 Cloudflare 帳號後）</summary>
      <ol class="small mt-2">
        <li>登入 Cloudflare 後台，左側選「R2 物件儲存」（R2 Object Storage）。第一次使用需要先啟用 R2（會要求綁定付款方式，免費額度內不收費）。</li>
        <li>按「建立 bucket」（Create bucket），名稱例如 <code>project-hub-attachments</code>，位置選「自動」。<br>
          <strong>不要開啟「公開存取」或 r2.dev 網址</strong>：檔案必須只能經由本平台檢查權限後開啟。</li>
        <li>回到 R2 總覽頁，右側可看到「帳戶 ID」（Account ID，32 個英數字），複製下來。</li>
        <li>在 R2 總覽頁選「管理 API 權杖」（Manage API tokens）→「建立 API 權杖」：
          <ul>
            <li>權限選「物件讀取和寫入」（Object Read &amp; Write）；</li>
            <li>範圍選「僅套用至特定 bucket」，只勾選剛建立的 bucket；</li>
            <li>建立後會顯示 <strong>Access Key ID</strong> 與 <strong>Secret Access Key</strong>，Secret 只會顯示這一次，請立即複製。</li>
          </ul>
        </li>
        <li>把帳戶 ID、bucket 名稱與兩把金鑰填到下方並儲存，再按「測試連線」。</li>
        <li>測試成功後，在本頁最下方把「新上傳附件的儲存位置」改為 Cloudflare R2。</li>
      </ol>
      <p class="small text-body-secondary mb-0">金鑰以加密方式保存，儲存後不會再顯示。API 權杖只開放單一 bucket 的物件讀寫，即使外洩也無法存取帳號內其他資料。</p>
    </details>
    <form method="post" action="/admin/settings/storage/r2" class="row g-2 align-items-start">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="col-12 col-lg-6">
        <?php $key = 'storage.r2_account_id'; $def = $defs[$key]; require __DIR__ . '/_setting_field.php'; ?>
      </div>
      <div class="col-12 col-lg-6">
        <?php $key = 'storage.r2_bucket'; $def = $defs[$key]; require __DIR__ . '/_setting_field.php'; ?>
      </div>
      <?php foreach (['r2_access_key_id' => 'Access Key ID', 'r2_secret_access_key' => 'Secret Access Key'] as $field => $label): ?>
        <div class="col-12 col-lg-6">
          <label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label>
          <input type="password" class="form-control<?= isset($errors[$field]) ? ' is-invalid' : '' ?>" id="<?= e($field) ?>" name="<?= e($field) ?>" autocomplete="new-password" maxlength="200"
                 placeholder="<?= $r2KeysSet[$field] ? '已設定（留空表示不變更）' : '尚未設定' ?>">
          <div class="<?= isset($errors[$field]) ? 'invalid-feedback d-block' : 'form-text' ?>"><?= e($errors[$field] ?? '加密保存，儲存後不會再顯示於畫面上。') ?></div>
        </div>
      <?php endforeach; ?>
      <div class="col-12">
        <button type="submit" class="btn btn-outline-primary">儲存 R2 設定</button>
      </div>
    </form>
    <form method="post" action="/admin/settings/storage/r2/test" class="mt-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-primary"<?= $r2Configured ? '' : ' disabled' ?>>測試連線</button>
      <span class="small text-body-secondary ms-2">會寫入、讀回並刪除一個測試檔。</span>
    </form>
  </div>
</section>

<section class="card shadow-sm mb-3" aria-labelledby="onedrive-title">
  <div class="card-body">
    <h2 class="h5" id="onedrive-title">OneDrive（Outlook）</h2>
    <p class="small mb-0">尚未支援。Microsoft 免費帳號的雲端空間只有 5 GB，而且與 Outlook 郵件附件共用；若有 Microsoft 365 付費訂閱（1 TB）再評估加入。</p>
  </div>
</section>

<section class="card shadow-sm mb-3" aria-labelledby="drive-title">
  <div class="card-body">
    <h2 class="h5" id="drive-title">Google Drive</h2>
    <?php if ($connected): ?>
      <div class="alert alert-success small" data-drive-status="connected">
        已連結 <strong><?= e($connection['email']) ?></strong>（<?= e(Clock::display($connection['connected_at'] ?? null, $timezone)) ?>）。
        檔案存放在該帳號的資料夾「<?= e(\ProjectHub\Attachment\GoogleDrive::FOLDER_NAME) ?>」，檔名為隨機代碼，請勿手動移動、改名或刪除。
      </div>
      <form method="post" action="/admin/settings/storage/google/disconnect" class="mb-3"
            data-confirm="確定要中斷連結 Google 帳號嗎？&#10;&#10;存放在 Google Drive 的 <?= (int)$usage['google_drive']['files'] ?> 個附件不會被刪除，但在重新連結同一個帳號前，所有人都無法開啟這些附件。&#10;新上傳的附件會改存本機。">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm"<?= $disabledAttr ?>>中斷連結</button>
      </form>
    <?php else: ?>
      <div class="alert alert-secondary small" data-drive-status="disconnected">
        尚未連結。<?php if (is_array($previous) && !empty($previous['email'])): ?>先前連結的帳號為 <strong><?= e($previous['email']) ?></strong>，請用同一個帳號重新連結，既有附件才能讀取。<?php endif; ?>
      </div>
    <?php endif; ?>

    <details class="mb-3"<?= $connected ? '' : ' open' ?>>
      <summary>設定步驟（第一次使用）</summary>
      <ol class="small mt-2">
        <li>到 <span class="user-select-all">https://console.cloud.google.com/</span> 建立專案，啟用「Google Drive API」。</li>
        <li>「OAuth 同意畫面」選擇「外部」，加入範圍 <code>.../auth/drive.file</code>，並把要連結的 Google 帳號加入「測試使用者」。<br>
          <span class="text-body-secondary">注意：發布狀態為「測試中」時，Google 的授權 7 天後會失效，需重新連結；改為「已發布」可避免（drive.file 不需要 Google 審查）。</span></li>
        <li>「憑證 → 建立憑證 → OAuth 用戶端 ID」，類型選「網頁應用程式」，在「已授權的重新導向 URI」貼上：<br>
          <code class="user-select-all" data-redirect-uri><?= e($redirectUri) ?></code></li>
        <li>把用戶端 ID 與用戶端密鑰填到下方並儲存，再按「連結 Google 帳號」。</li>
      </ol>
      <p class="small text-body-secondary mb-0">本平台只要求 <code>drive.file</code> 權限：只能存取本平台自己建立的檔案，看不到該帳號原有的任何檔案。</p>
    </details>

    <form method="post" action="/admin/settings/storage/google" class="row g-2 align-items-start">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="col-12 col-lg-6">
        <?php $key = 'storage.google_client_id'; $def = $defs[$key]; require __DIR__ . '/_setting_field.php'; ?>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="google-client-secret">Google OAuth 用戶端密鑰</label>
        <input type="password" class="form-control<?= isset($errors['google_client_secret']) ? ' is-invalid' : '' ?>" id="google-client-secret" name="google_client_secret" autocomplete="new-password" maxlength="1000"
               placeholder="<?= $clientSecretSet ? '已設定（留空表示不變更）' : '尚未設定' ?>"<?= $disabledAttr ?>>
        <div class="<?= isset($errors['google_client_secret']) ? 'invalid-feedback d-block' : 'form-text' ?>"><?= e($errors['google_client_secret'] ?? '加密保存，儲存後不會再顯示於畫面上。') ?></div>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-outline-primary"<?= $disabledAttr ?>>儲存用戶端</button>
        <?php if (!empty($elevated) && $values['storage.google_client_id'] !== '' && $clientSecretSet && $curl): ?>
          <a class="btn btn-primary" href="/admin/settings/storage/google/connect" data-drive-connect data-turbo="false"><?= $connected ? '重新連結 Google 帳號' : '連結 Google 帳號' ?></a>
        <?php else: ?>
          <button type="button" class="btn btn-primary" disabled><?= $connected ? '重新連結 Google 帳號' : '連結 Google 帳號' ?></button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</section>

<?php if ($errors !== [] && (isset($errors['attachments.storage']) || isset($errors['attachments.drive_quota_mb']))): ?>
  <div class="alert alert-danger" role="alert">設定尚未儲存，請修正後再送出。</div>
<?php endif; ?>
<form method="post" action="/admin/settings/storage" class="card shadow-sm">
  <div class="card-body">
    <h2 class="h5 mb-3">儲存位置</h2>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php foreach (['attachments.storage', 'attachments.drive_quota_mb', 'attachments.r2_quota_mb'] as $key) { $def = $defs[$key]; require __DIR__ . '/_setting_field.php'; } ?>
    <p class="small text-body-secondary">本機的容量與檔案數上限在「一般設定 → 附件」調整。不論存在哪裡，檔案都只能經由本平台檢查權限後開啟，撤銷專案權限後立即無法存取。</p>
    <button type="submit" class="btn btn-primary"<?= $disabledAttr ?>>儲存</button>
  </div>
</form>
