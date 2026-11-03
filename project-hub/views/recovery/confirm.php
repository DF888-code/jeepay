<?php use ProjectHub\Support\Clock; ?>
<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">管理者救援</h1>
    <?php if ($state === 'cooling'): ?>
      <div class="alert alert-warning" role="status" data-recovery-state="cooling">
        為了安全，「<?= e($purposeLabel) ?>」的救援連結需要等待一段時間才能使用。<br>
        請在 <strong><?= e(Clock::display($usableAt, $timezone)) ?></strong> 之後重新開啟信中的連結（<?= e(Clock::display($expiresAt, $timezone)) ?> 前有效）。
      </div>
    <?php elseif ($state !== 'ok'): ?>
      <div class="alert alert-danger" role="alert" data-recovery-state="invalid">
        此救援連結無效、已使用過或已過期。若仍需要救援，請<a href="/recovery">重新申請</a>。
      </div>
    <?php else: ?>
      <p class="mb-3" data-recovery-state="ok">救援情境：<strong><?= e($purposeLabel) ?></strong></p>
      <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form data-turbo="false" method="post" action="/recovery/confirm">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?php if ($purpose === 'reset_password'): ?>
          <div class="mb-3">
            <label for="rc-code" class="form-label">驗證器上的驗證碼</label>
            <input id="rc-code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
          </div>
        <?php elseif ($purpose === 'reset_2fa'): ?>
          <div class="mb-3">
            <label for="rc-current" class="form-label">目前密碼</label>
            <input id="rc-current" type="password" name="current_password" class="form-control" autocomplete="current-password" required>
            <div class="form-text">確認後會清除原本的驗證器，下次登入時重新綁定 Google Authenticator。</div>
          </div>
        <?php endif; ?>
        <?php if ($purpose !== 'reset_2fa'): ?>
          <div class="mb-3">
            <label for="rc-new" class="form-label">新密碼</label>
            <input id="rc-new" type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required aria-describedby="rc-new-help">
            <div id="rc-new-help" class="form-text">至少 <?= (int)$minLength ?> 個字元。</div>
          </div>
          <div class="mb-3">
            <label for="rc-confirm" class="form-label">再次輸入新密碼</label>
            <input id="rc-confirm" type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required>
          </div>
        <?php endif; ?>
        <?php if ($purpose === 'reset_both'): ?>
          <p class="form-text">完成後原本的驗證器會被清除，請用新密碼登入並重新綁定 Google Authenticator。</p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100">確認救援</button>
      </form>
      <p class="small text-body-secondary mt-3 mb-0">完成後所有裝置的登入狀態都會失效，需要重新登入。</p>
    <?php endif; ?>
  </div>
</div>
