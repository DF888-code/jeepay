<?php $old = $old ?? []; ?>
<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">管理者救援</h1>
    <p class="small text-body-secondary">此功能只提供<strong>最高權限管理者</strong>使用，救援連結會寄到帳號設定的救援 Email。一般員工忘記密碼或遺失手機，請聯絡最高權限管理者重設。</p>
    <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/recovery">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-3">
        <label for="rc-account" class="form-label">帳號或救援 Email</label>
        <input id="rc-account" name="account" class="form-control" autocomplete="username" autocapitalize="none" maxlength="254" required autofocus>
      </div>
      <fieldset class="mb-4">
        <legend class="form-label fs-6">發生什麼情況？</legend>
        <?php foreach ($purposes as $value => $label): $id = 'rc-' . $value; ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="purpose" id="<?= e($id) ?>" value="<?= e($value) ?>"<?= ($old['purpose'] ?? 'reset_password') === $value ? ' checked' : '' ?> required>
            <label class="form-check-label" for="<?= e($id) ?>"><?= e($label) ?></label>
            <div class="form-text"><?= e(match ($value) {
                'reset_password' => '開啟信中連結後，輸入驗證器上的驗證碼，再設定新密碼。',
                'reset_2fa' => '開啟信中連結後，輸入目前密碼，系統會清除原本的驗證器，登入時重新綁定。',
                default => '只靠 Email 驗證，風險最高：連結需等待一段時間才能使用，並會寄出通知。',
            }) ?></div>
          </div>
        <?php endforeach; ?>
      </fieldset>
      <button type="submit" class="btn btn-primary w-100">寄送救援信</button>
    </form>
    <p class="small mt-3 mb-0"><a href="/login">回登入頁</a></p>
  </div>
</div>
