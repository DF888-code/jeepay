<?php
use ProjectHub\Support\Clock;

$active = 'users';
require __DIR__ . '/_nav.php';
require __DIR__ . '/_reauth.php';
$disabledAttr = empty($elevated) ? ' disabled' : '';
?>
<?php if (!empty($oneTimePassword)): ?>
  <div class="alert alert-info">
    <div class="fw-semibold mb-1">「<?= e($oneTimePassword['name']) ?>」的臨時密碼（只顯示這一次）</div>
    <code class="fs-5 user-select-all"><?= e($oneTimePassword['password']) ?></code>
    <div class="small mt-2">請以安全方式（當面或私訊）交給本人。對方登入後必須先綁定 Google Authenticator，再把臨時密碼改成自己的密碼。離開本頁後將無法再次查看。</div>
  </div>
<?php endif; ?>

<section class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span>新增使用者</span>
  </div>
  <div class="card-body">
    <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/admin/users" class="row g-3 align-items-end" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="col-12 col-md-3">
        <label for="new-username" class="form-label">帳號</label>
        <input id="new-username" name="username" class="form-control" value="<?= e($old['username'] ?? '') ?>" autocapitalize="none" required>
      </div>
      <div class="col-12 col-md-3">
        <label for="new-display" class="form-label">顯示名稱</label>
        <input id="new-display" name="display_name" class="form-control" value="<?= e($old['display_name'] ?? '') ?>" required>
      </div>
      <div class="col-12 col-md-4">
        <label for="new-email" class="form-label">Email（選填）</label>
        <input id="new-email" type="email" name="email" class="form-control" value="<?= e($old['email'] ?? '') ?>">
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-primary w-100"<?= $disabledAttr ?>>建立</button>
      </div>
      <div class="col-12 form-text mt-1">系統會產生一組臨時密碼；新帳號預設沒有任何權限。</div>
    </form>
  </div>
</section>

<section class="card shadow-sm">
  <div class="card-header fw-semibold">使用者（<?= count($users) ?>）</div>
  <?php if ($users === []): ?>
    <div class="card-body text-body-secondary">目前還沒有其他使用者。</div>
  <?php else: ?>
    <div class="d-none d-lg-flex row g-0 px-3 py-2 small text-body-secondary border-bottom">
      <div class="col-lg-3">使用者</div>
      <div class="col-lg-1">狀態</div>
      <div class="col-lg-1">2FA</div>
      <div class="col-lg-2">上次登入</div>
      <div class="col-lg-5 text-end">操作</div>
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($users as $u): $uid = (int)$u['id']; $isActive = $u['status'] === 'active'; ?>
        <li class="list-group-item py-3">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-lg-3">
              <div class="fw-semibold"><?= e($u['display_name']) ?></div>
              <div class="small text-body-secondary"><?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></div>
            </div>
            <div class="col-6 col-lg-1">
              <span class="d-lg-none small text-body-secondary">狀態：</span>
              <?= $isActive ? '<span class="badge text-bg-success">啟用中</span>' : '<span class="badge text-bg-secondary">已停用</span>' ?>
            </div>
            <div class="col-6 col-lg-1">
              <span class="d-lg-none small text-body-secondary">2FA：</span>
              <?= (int)$u['totp_enabled'] === 1 ? '<span class="badge text-bg-success">已綁定</span>' : '<span class="badge text-bg-warning">待綁定</span>' ?>
            </div>
            <div class="col-12 col-lg-2 small">
              <span class="d-lg-none text-body-secondary">上次登入：</span><?= e(Clock::display($u['last_login_at'], $timezone)) ?>
            </div>
            <div class="col-12 col-lg-5 text-lg-end">
              <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#edit-<?= $uid ?>">編輯</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pw-<?= $uid ?>">重設密碼</button>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#tf-<?= $uid ?>">重設 2FA</button>
                <button type="button" class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?>" data-bs-toggle="modal" data-bs-target="#st-<?= $uid ?>"<?= $disabledAttr ?>><?= $isActive ? '停用' : '啟用' ?></button>
              </div>
            </div>
          </div>

          <div class="collapse mt-3" id="edit-<?= $uid ?>">
            <form method="post" action="/admin/users/<?= $uid ?>/profile" class="row g-2 align-items-end">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <div class="col-12 col-md-5">
                <label class="form-label small mb-1" for="dn-<?= $uid ?>">顯示名稱</label>
                <input id="dn-<?= $uid ?>" name="display_name" class="form-control form-control-sm" value="<?= e($u['display_name']) ?>" required>
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label small mb-1" for="em-<?= $uid ?>">Email</label>
                <input id="em-<?= $uid ?>" type="email" name="email" class="form-control form-control-sm" value="<?= e($u['email'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">儲存「<?= e($u['display_name']) ?>」</button>
              </div>
            </form>
          </div>

          <?php
          // 每個敏感操作都以確認視窗再送出，並清楚標示操作對象
          $modals = [
              'pw' => ['重設密碼', "/admin/users/{$uid}/reset-password", '系統會產生新的臨時密碼並登出對方所有裝置，對方下次登入後必須變更密碼。', 'btn-primary', '重設密碼', [], true],
              'tf' => ['重設 2FA', "/admin/users/{$uid}/reset-2fa", '用於員工遺失手機。解除後對方所有裝置會登出，下次登入必須重新掃描 QR Code，完成前無法使用系統。', 'btn-warning', '解除 2FA 綁定', [], true],
              'st' => [$isActive ? '停用帳號' : '啟用帳號', "/admin/users/{$uid}/status",
                  $isActive ? '停用後對方會立即被登出，且無法再登入。資料不會被刪除，可隨時重新啟用。' : '重新啟用後，對方可再次登入。',
                  $isActive ? 'btn-danger' : 'btn-success', $isActive ? '停用' : '啟用', ['status' => $isActive ? 'disabled' : 'active'], false],
          ];
          foreach ($modals as $prefix => [$mTitle, $mAction, $mText, $mBtnClass, $mBtn, $mHidden, $needsCode]): ?>
            <div class="modal fade" id="<?= $prefix ?>-<?= $uid ?>" tabindex="-1" aria-labelledby="<?= $prefix ?>-<?= $uid ?>-title" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <form method="post" action="<?= e($mAction) ?>" class="modal-content">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <?php foreach ($mHidden as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach; ?>
                  <div class="modal-header">
                    <h2 class="modal-title h5" id="<?= $prefix ?>-<?= $uid ?>-title"><?= e($mTitle) ?>：<?= e($u['display_name']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                  </div>
                  <div class="modal-body">
                    <p class="mb-0"><?= e($mText) ?></p>
                    <p class="small text-body-secondary mb-0 mt-2">操作對象：<?= e($u['display_name']) ?>（<?= e($u['username']) ?>）</p>
                    <?php if ($needsCode): ?>
                      <div class="mt-3">
                        <label for="<?= $prefix ?>-<?= $uid ?>-code" class="form-label">請輸入<strong>您自己</strong>驗證器上的驗證碼以確認</label>
                        <input id="<?= $prefix ?>-<?= $uid ?>-code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
                        <div class="form-text">此操作每次都需要輸入新的驗證碼。</div>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn <?= $mBtnClass ?>"><?= e($mBtn) ?></button>
                  </div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
