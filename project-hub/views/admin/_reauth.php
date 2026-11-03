<?php if (!empty($elevated)): ?>
  <div class="alert alert-success py-2 small">已完成身分驗證，10 分鐘內可執行管理操作。</div>
<?php else: ?>
  <div class="card border-warning mb-4">
    <div class="card-body">
      <h2 class="h6">需要身分驗證</h2>
      <p class="small mb-2"><strong>請先完成身分驗證，才會顯示本頁的管理內容。</strong></p>
      <p class="small mb-3">建立帳號、停用／啟用、修改權限、還原專案前，請先輸入<strong>您自己</strong>驗證器上的驗證碼，驗證後 10 分鐘內有效。重設密碼與重設 2FA 則每次都需要在確認視窗另外輸入驗證碼。</p>
      <form method="post" action="/admin/reauth" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <div class="col-12 col-sm-auto">
          <label for="reauth-code" class="form-label small mb-1">驗證碼</label>
          <input id="reauth-code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
        </div>
        <div class="col-12 col-sm-auto">
          <button type="submit" class="btn btn-warning w-100">驗證</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
