<h1 class="h3 mb-4">帳號設定</h1>
<div class="row g-4 align-items-start">
  <div class="col-12 col-lg-6">
    <section class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">基本資料</div>
      <div class="card-body">
        <dl class="kv">
          <dt>帳號</dt><dd><?= e($user['username']) ?></dd>
          <dt>顯示名稱</dt><dd><?= e($user['display_name']) ?></dd>
          <dt>身分</dt><dd><?= (int)$user['is_super_admin'] === 1 ? '最高權限管理者' : '一般使用者' ?></dd>
          <dt>上次登入</dt><dd><?= e(\ProjectHub\Support\Clock::display($user['last_login_at'], $timezone)) ?></dd>
        </dl>
      </div>
    </section>

    <section class="card shadow-sm">
      <div class="card-header fw-semibold">修改密碼</div>
      <div class="card-body">
        <form method="post" action="/account/password">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="mb-3">
            <label for="pw-current" class="form-label">目前密碼</label>
            <input id="pw-current" type="password" name="current_password" class="form-control" autocomplete="current-password" required>
          </div>
          <div class="mb-3">
            <label for="pw-new" class="form-label">新密碼</label>
            <input id="pw-new" type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required aria-describedby="pw-help">
            <div id="pw-help" class="form-text">至少 <?= (int)$minLength ?> 個字元。</div>
          </div>
          <div class="mb-3">
            <label for="pw-confirm" class="form-label">再次輸入新密碼</label>
            <input id="pw-confirm" type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required>
          </div>
          <button type="submit" class="btn btn-primary">更新密碼</button>
          <p class="form-text mb-0 mt-2">修改成功後，其他裝置上的登入狀態會立即失效。</p>
        </form>
      </div>
    </section>
  </div>

  <div class="col-12 col-lg-6">
    <section class="card shadow-sm">
      <div class="card-header fw-semibold">兩步驟驗證（2FA）</div>
      <div class="card-body">
        <p>
          狀態：
          <?php if ((int)$user['totp_enabled'] === 1): ?>
            <span class="badge text-bg-success">已綁定 Google Authenticator</span>
          <?php else: ?>
            <span class="badge text-bg-warning">未綁定</span>
          <?php endif; ?>
        </p>
        <h2 class="h6 mt-4">更換驗證器</h2>
        <p class="small text-body-secondary">需要目前的密碼與目前驗證器的驗證碼。完成新驗證器確認前，舊驗證器仍然有效。手機遺失無法取得驗證碼時，請聯絡最高權限管理者重設。</p>
        <form method="post" action="/account/2fa/change">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="mb-3">
            <label for="tf-password" class="form-label">目前密碼</label>
            <input id="tf-password" type="password" name="current_password" class="form-control" autocomplete="current-password" required>
          </div>
          <div class="mb-3">
            <label for="tf-code" class="form-label">目前驗證器的驗證碼</label>
            <input id="tf-code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
          </div>
          <button type="submit" class="btn btn-outline-primary">開始更換</button>
        </form>
      </div>
    </section>

    <section class="card shadow-sm mt-4">
      <div class="card-header fw-semibold">登入裝置</div>
      <div class="card-body">
        <p class="small text-body-secondary">懷疑帳號在其他電腦或手機上仍保持登入（例如公用電腦忘記登出）時，可立即登出其他所有裝置，本裝置保持登入。</p>
        <form method="post" action="/account/sessions/revoke-others" class="m-0">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button type="submit" class="btn btn-outline-danger">登出其他所有裝置</button>
        </form>
      </div>
    </section>

    <?php if (!empty($isSuperAdmin)): ?>
    <section class="card shadow-sm mt-4" data-recovery-settings>
      <div class="card-header fw-semibold">Email 救援設定</div>
      <div class="card-body">
        <p class="small text-body-secondary">忘記密碼或遺失驗證器時，開啟 <code>/recovery</code>（建議存成書籤；登入輸入錯誤後頁面上也會出現「申請帳號協助」連結），系統會把救援連結寄到這個 Email。請使用只有您能存取、且開啟兩步驟驗證的信箱。</p>
        <p class="mb-2">目前救援 Email：<strong><?= ($user['email'] ?? '') !== '' ? e($user['email']) : '尚未設定' ?></strong></p>
        <?php if (empty($baseUrlSet)): ?>
          <div class="alert alert-warning small py-2">config.php 尚未設定 <code>base_url</code>，為了安全，救援信不會寄出。</div>
        <?php endif; ?>
        <?php if (empty($mailEnabled)): ?>
          <div class="alert alert-warning small py-2">尚未設定寄信方式，救援信無法寄出。請到「管理後台 → 系統設定 → 寄信設定」設定並寄送測試信。</div>
        <?php endif; ?>
        <form method="post" action="/account/recovery-email" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="col-12 col-sm-7">
            <label for="rc-email" class="form-label small mb-1">新的救援 Email</label>
            <input id="rc-email" type="email" name="email" class="form-control" maxlength="254" autocomplete="email" required>
          </div>
          <div class="col-7 col-sm-3">
            <label for="rc-email-code" class="form-label small mb-1">驗證碼</label>
            <input id="rc-email-code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
          </div>
          <div class="col-5 col-sm-2">
            <button type="submit" class="btn btn-outline-primary w-100">變更</button>
          </div>
          <div class="col-12 form-text">每次變更都需要輸入驗證碼；變更後會通知新舊 Email，並讓尚未使用的救援連結失效。</div>
        </form>

        <h2 class="h6 mt-4">待處理的救援申請</h2>
        <?php if ($recoveryPending === []): ?>
          <p class="small text-body-secondary mb-0" data-recovery-pending="0">目前沒有。</p>
        <?php else: ?>
          <div class="alert alert-warning small" data-recovery-pending="<?= count($recoveryPending) ?>">
            有 <?= count($recoveryPending) ?> 個尚未使用的救援申請。若不是您本人申請，請立即取消，並確認救援信箱是否遭他人存取。
            <ul class="mb-0 mt-1">
              <?php foreach ($recoveryPending as $p): ?>
                <li><?= e(\ProjectHub\Controllers\RecoveryController::PURPOSE_LABELS[$p['purpose']] ?? $p['purpose']) ?>：<?= e(\ProjectHub\Support\Clock::display($p['created_at'], $timezone)) ?> 申請，IP <?= e((string)$p['request_ip']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <form method="post" action="/account/recovery/cancel" class="m-0" data-confirm="確定要取消所有救援申請嗎？信中的連結會立即失效。">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">取消所有救援申請</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
