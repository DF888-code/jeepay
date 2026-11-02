<?php
$settingsTab = 'mail';
require __DIR__ . '/_settings_nav.php';
$disabledAttr = empty($elevated) ? ' disabled' : '';
$sections = [
    '寄件者' => ['mail.driver', 'mail.from_address', 'mail.from_name'],
    'SMTP' => ['mail.smtp_host', 'mail.smtp_port', 'mail.smtp_encryption', 'mail.smtp_username'],
];
$secretField = static function (string $field, string $label, bool $isSet, ?string $error, string $disabledAttr): void { ?>
  <div class="mb-3">
    <label class="form-label" for="secret-<?= e($field) ?>"><?= e($label) ?></label>
    <input type="password" class="form-control<?= $error !== null ? ' is-invalid' : '' ?>" id="secret-<?= e($field) ?>" name="<?= e($field) ?>" autocomplete="new-password" maxlength="1000"
           placeholder="<?= $isSet ? '已設定（留空表示不變更）' : '尚未設定' ?>" aria-describedby="secret-<?= e($field) ?>-help"<?= $disabledAttr ?>>
    <div id="secret-<?= e($field) ?>-help" class="<?= $error !== null ? 'invalid-feedback d-block' : 'form-text' ?>"><?= e($error ?? '加密保存，儲存後不會再顯示於畫面上。') ?></div>
    <?php if ($isSet): ?>
      <div class="form-check mt-1">
        <input class="form-check-input" type="checkbox" id="clear-<?= e($field) ?>" name="clear_<?= e($field) ?>" value="1"<?= $disabledAttr ?>>
        <label class="form-check-label small" for="clear-<?= e($field) ?>">清除已儲存的<?= e($label) ?></label>
      </div>
    <?php endif; ?>
  </div>
<?php };
?>
<p class="small text-body-secondary">寄信用於階段 10 的「忘記密碼／2FA 遺失」Email 救援與安全通知。InfinityFree 實測可連線 SMTP 25／465／587 與 HTTPS 443（見 docs/01）。</p>
<?php if ($errors !== []): ?>
  <div class="alert alert-danger" role="alert">有 <?= count($errors) ?> 個欄位不正確，所有設定都尚未儲存，請修正後再送出。</div>
<?php endif; ?>
<form method="post" action="/admin/settings/mail">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="row g-3">
    <?php foreach ($sections as $title => $keys): ?>
      <div class="col-12 col-lg-6">
        <section class="card shadow-sm h-100">
          <div class="card-body">
            <h2 class="h5 mb-3"><?= e($title) ?></h2>
            <?php foreach ($keys as $key) { $def = $defs[$key]; require __DIR__ . '/_setting_field.php'; } ?>
            <?php if ($title === 'SMTP') { $secretField('smtp_password', 'SMTP 密碼', $secretsSet['smtp_password'], $errors['smtp_password'] ?? null, $disabledAttr); } ?>
          </div>
        </section>
      </div>
    <?php endforeach; ?>
    <div class="col-12 col-lg-6">
      <section class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h5 mb-3">Brevo HTTPS API</h2>
          <p class="small text-body-secondary">SMTP 被主機阻擋時的備用方式。到 Brevo 後台「SMTP &amp; API → API Keys」建立金鑰。</p>
          <?php $secretField('brevo_api_key', 'Brevo API 金鑰', $secretsSet['brevo_api_key'], $errors['brevo_api_key'] ?? null, $disabledAttr); ?>
        </div>
      </section>
    </div>
  </div>
  <div class="mt-3">
    <button type="submit" class="btn btn-primary"<?= $disabledAttr ?>>儲存寄信設定</button>
  </div>
</form>

<section class="card shadow-sm mt-4" aria-labelledby="mail-test-title">
  <div class="card-body">
    <h2 class="h5" id="mail-test-title">測試</h2>
    <p class="small mb-3">請先儲存設定再測試。測試信會寄到<strong>您自己的 Email</strong>（<?= $adminEmail !== '' ? e($adminEmail) : '尚未設定，請到使用者管理填寫' ?>）。</p>
    <div class="d-flex flex-wrap gap-2">
      <form method="post" action="/admin/settings/mail/test" class="m-0">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="mode" value="check">
        <button type="submit" class="btn btn-outline-secondary"<?= $disabledAttr ?>>測試連線</button>
      </form>
      <form method="post" action="/admin/settings/mail/test" class="m-0">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="mode" value="send">
        <button type="submit" class="btn btn-outline-primary"<?= $disabledAttr ?><?= $adminEmail === '' ? ' disabled' : '' ?>>寄送測試信</button>
      </form>
    </div>
    <?php if ($transcript !== []): ?>
      <details class="mt-3" open>
        <summary class="small">伺服器對話紀錄（帳號、密碼已遮蔽）</summary>
        <pre class="small bg-body-tertiary border rounded p-2 mt-2 mb-0 mail-transcript"><?= e(implode("\n", $transcript)) ?></pre>
      </details>
    <?php endif; ?>
  </div>
</section>
