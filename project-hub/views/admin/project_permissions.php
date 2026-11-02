<?php
$active = 'projects';
require __DIR__ . '/_nav.php';
$pid = (int)$project['id'];
$disabledAttr = empty($elevated) ? ' disabled' : '';
$fields = ['can_view' => ['查看', 'g_view', 'view'], 'can_edit' => ['編輯', 'g_edit', 'edit'], 'can_delete' => ['刪除', 'g_delete', 'delete']];
?>
<nav aria-label="breadcrumb">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="/admin/projects">專案管理</a></li>
    <li class="breadcrumb-item active text-break" aria-current="page"><?= e($project['name']) ?></li>
  </ol>
</nav>
<h2 class="h5 mb-3">授權名單：<?= e($project['name']) ?></h2>
<?php require __DIR__ . '/_reauth.php'; ?>
<p class="small text-body-secondary">
  每一列的儲存按鈕只更新該員工在<strong>此專案</strong>的權限。專案權限不會授予任何全域權限：
  員工實際能做什麼，需要「全域權限」與「專案權限」同時允許，結果顯示在「實際效果」欄。
</p>

<?php if ($members === []): ?>
  <div class="card shadow-sm"><div class="card-body text-body-secondary">目前還沒有其他使用者。</div></div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="d-none d-md-flex row g-0 px-3 py-2 small text-body-secondary border-bottom">
      <div class="col-md-3">員工</div>
      <div class="col-md-1 text-center">查看</div>
      <div class="col-md-1 text-center">編輯</div>
      <div class="col-md-1 text-center">刪除</div>
      <div class="col-md-3">實際效果（全域 × 專案）</div>
      <div class="col-md-3 text-end">儲存</div>
    </div>
    <div class="list-group list-group-flush">
      <?php foreach ($members as $m): $uid = (int)$m['id']; ?>
        <form method="post" action="/admin/projects/<?= $pid ?>/permissions/<?= $uid ?>" class="list-group-item py-3" data-perm-row>
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
              <div class="fw-semibold"><?= e($m['display_name']) ?></div>
              <div class="small text-body-secondary"><?= e($m['username']) ?><?= $m['status'] !== 'active' ? ' · 已停用' : '' ?></div>
            </div>
            <?php foreach ($fields as $field => [$label, $g, $role]): $cid = "pp{$uid}-{$field}"; ?>
              <div class="col-4 col-md-1 text-md-center">
                <div class="form-check form-check-inline m-0 d-md-flex justify-content-md-center">
                  <input class="form-check-input" type="checkbox" id="<?= e($cid) ?>" name="<?= e($field) ?>" value="1" data-perm="<?= e($field) ?>" data-perm-role="<?= e($role) ?>"<?= (int)$m[$field] === 1 ? ' checked' : '' ?>>
                  <label class="form-check-label ms-1 d-md-none" for="<?= e($cid) ?>"><?= e($label) ?></label>
                  <label class="visually-hidden" for="<?= e($cid) ?>"><?= e($m['display_name']) ?>：<?= e($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="col-12 col-md-3 small">
              <?php
              // 顯示目前已儲存設定下的實際結果，提醒管理者全域權限不足的情況
              $effective = [];
              $missing = [];
              foreach ($fields as $field => [$label, $g]) {
                  if ((int)$m[$field] === 1 && (int)$m[$g] === 1 && (int)$m['can_view'] === 1 && (int)$m['g_view'] === 1) {
                      $effective[] = $label;
                  } elseif ((int)$m[$field] === 1 && (int)$m[$g] !== 1) {
                      $missing[] = $label;
                  }
              }
              ?>
              <?= $effective === [] ? '<span class="text-body-secondary">無法存取</span>' : '<span class="text-success-emphasis">可' . e(implode('、', $effective)) . '</span>' ?>
              <?php if ($missing !== []): ?>
                <div class="text-warning-emphasis">缺少全域「<?= e(implode('、', $missing)) ?>」</div>
              <?php endif; ?>
            </div>
            <div class="col-12 col-md-3 text-md-end">
              <span class="badge text-bg-warning me-2 d-none" data-dirty-badge>尚未儲存</span>
              <button type="submit" class="btn btn-sm btn-primary"<?= $disabledAttr ?>>儲存「<?= e($m['display_name']) ?>」</button>
            </div>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
