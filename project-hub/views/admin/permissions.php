<?php
$active = 'permissions';
require __DIR__ . '/_nav.php';
require __DIR__ . '/_reauth.php';
if (empty($elevated)) { return; } // 未完成身分驗證：只顯示驗證框，不輸出管理內容
$disabledAttr = empty($elevated) ? ' disabled' : '';
?>
<p class="text-body-secondary small">
  每位員工的權限獨立設定，<strong>每一列的儲存按鈕只會更新該員工</strong>。未儲存的修改不會套用。
  實際能否操作某個專案，還需要該專案另外授權（雙重授權）。勾選「編輯」或「刪除」時會一併勾選「查看」。
</p>

<?php if ($users === []): ?>
  <div class="card shadow-sm"><div class="card-body text-body-secondary">目前還沒有其他使用者，請先到「使用者管理」新增。</div></div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="d-none d-md-flex row g-0 px-3 py-2 small text-body-secondary border-bottom">
      <div class="col-md-3">員工</div>
      <?php foreach ($fields as $label): ?><div class="col-md-1 text-center"><?= e($label) ?></div><?php endforeach; ?>
      <div class="col-md-5 text-end">儲存</div>
    </div>
    <div class="list-group list-group-flush">
      <?php foreach ($users as $u): $uid = (int)$u['id']; ?>
        <form method="post" action="/admin/permissions/<?= $uid ?>" class="list-group-item py-3 perm-row" data-perm-row>
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="row_version" value="<?= (int)$u['row_version'] ?>">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
              <div class="fw-semibold"><?= e($u['display_name']) ?></div>
              <div class="small text-body-secondary"><?= e($u['username']) ?><?= $u['status'] !== 'active' ? ' · 已停用' : '' ?></div>
            </div>
            <?php foreach ($fields as $field => $label): $cid = "p{$uid}-{$field}"; $role = ['can_view_projects' => 'view', 'can_edit_projects' => 'edit', 'can_delete_projects' => 'delete'][$field] ?? ''; ?>
              <div class="col-6 col-md-1 text-md-center">
                <div class="form-check form-check-inline m-0 d-md-flex justify-content-md-center">
                  <input class="form-check-input" type="checkbox" id="<?= e($cid) ?>" name="<?= e($field) ?>" value="1" data-perm="<?= e($field) ?>" data-perm-role="<?= e($role) ?>"<?= (int)$u[$field] === 1 ? ' checked' : '' ?>>
                  <label class="form-check-label ms-1 d-md-none" for="<?= e($cid) ?>"><?= e($label) ?></label>
                  <label class="visually-hidden" for="<?= e($cid) ?>"><?= e($u['display_name']) ?>：<?= e($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="col-12 col-md-5 text-md-end">
              <span class="badge text-bg-warning me-2 d-none" data-dirty-badge>尚未儲存</span>
              <button type="submit" class="btn btn-sm btn-primary"<?= $disabledAttr ?>>儲存「<?= e($u['display_name']) ?>」的權限</button>
            </div>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
